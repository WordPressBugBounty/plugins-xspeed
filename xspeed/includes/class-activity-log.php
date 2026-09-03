<?php
/**
 * Activity_Log — capped, append-only log of cache lifecycle events.
 *
 * Used by the Health module's dashboard panel + (later phases) by audit
 * surfaces. Storage: one transient `xspeed_activity_log` containing at
 * most MAX_ENTRIES events, ordered newest-first. Transient TTL is long
 * (30 days) so we don't lose history during quiet periods, but the size
 * cap keeps it bounded.
 *
 * Event shape:
 *   [ ts => int, type => string, message => string, severity => 'info' |
 *     'warn' | 'error' | 'success' ]
 *
 * Callers (from Cache.php and elsewhere):
 *   Activity_Log::record('cache_purged', 'Cache purged (settings change)');
 *   Activity_Log::record('cache_enabled_event', 'Cache enabled', 'success');
 *   Activity_Log::record('conflict_detected', 'WP Rocket activated', 'warn');
 *
 * Event type ids are snake_case — sanitize_key (called on the way in)
 * strips dots, so dotted ids would collapse silently.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Activity_Log {

	/**
	 * Storage key. An OPTION, not a transient — with a persistent object
	 * cache a transient lives in Redis/Memcached, and `Cache::purge_all()`
	 * calls `wp_cache_flush()` before it records the purge. The log was
	 * therefore erased by the very event it exists to record: on any
	 * Redis/Memcached site "Last purge" could never show more than the one
	 * row written after the flush. A history a flush can evaporate is not a
	 * history. Autoload is off — the log is read in admin contexts only.
	 */
	public const OPTION_KEY = 'xspeed_activity_log';

	/** Legacy transient the log used to live in; drained once on read. */
	public const TRANSIENT_KEY = 'xspeed_activity_log';

	public const TTL         = 2592000; // 30 days — legacy transient only.
	public const MAX_ENTRIES = 50;

	public const INFO    = 'info';
	public const WARN    = 'warn';
	public const ERROR   = 'error';
	public const SUCCESS = 'success';

	public static function record( string $type, string $message, string $severity = self::INFO ): void {
		$entries = self::entries();
		array_unshift(
			$entries,
			array(
				'ts'       => time(),
				'type'     => sanitize_key( $type ),
				'message'  => $message, // caller must produce already-safe text.
				'severity' => in_array( $severity, array( self::INFO, self::WARN, self::ERROR, self::SUCCESS ), true ) ? $severity : self::INFO,
			)
		);
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, 0, self::MAX_ENTRIES );
		}
		self::store( $entries );
	}

	/**
	 * Persist the log with autoload disabled, so it never joins
	 * `wp_load_alloptions()` on frontend requests.
	 *
	 * @param array<int,array<string,mixed>> $entries Newest-first entries.
	 */
	private static function store( array $entries ): void {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, $entries, '', 'no' );
			return;
		}
		update_option( self::OPTION_KEY, $entries );
	}

	/**
	 * Newest-first entries (up to MAX_ENTRIES). Defensive shape coercion
	 * so a malformed transient never breaks the dashboard.
	 *
	 * @return array<int,array{ts:int,type:string,message:string,severity:string}>
	 */
	public static function entries(): array {
		$raw = get_option( self::OPTION_KEY, null );

		// One-time migration off the old transient. Done lazily on read so
		// no upgrade routine has to run first, and so history written by a
		// previous version isn't thrown away.
		if ( ! is_array( $raw ) ) {
			$legacy = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $legacy ) ) {
				self::store( $legacy );
				delete_transient( self::TRANSIENT_KEY );
				$raw = $legacy;
			}
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $e ) {
			if ( is_array( $e ) && isset( $e['ts'], $e['type'], $e['message'] ) ) {
				$out[] = array(
					'ts'       => (int) $e['ts'],
					'type'     => (string) $e['type'],
					'message'  => (string) $e['message'],
					'severity' => isset( $e['severity'] ) ? (string) $e['severity'] : self::INFO,
				);
			}
		}
		return $out;
	}

	public static function clear(): void {
		delete_option( self::OPTION_KEY );
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * One-time scrub of secret values recorded by earlier versions.
	 *
	 * Settings change annotations used to include the raw value of every
	 * changed field, secrets included, and the dashboard trend endpoints
	 * serve those annotations. Redacting new writes isn't enough — the log
	 * is a 30-day transient, so entries written before the upgrade would
	 * keep exposing credentials until they aged out.
	 *
	 * Rewrites any `<secret_key> old→new` fragment to `<secret_key> changed`,
	 * preserving the rest of the entry so the causal history survives.
	 */
	public static function redact_legacy_secrets(): void {
		$entries = self::entries();
		if ( empty( $entries ) ) {
			return;
		}

		$changed = false;
		foreach ( $entries as $i => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['message'] ) ) {
				continue;
			}
			$message = (string) $entry['message'];
			// `key value→value` where key is secret-ish. Values never contain
			// a comma (describe_value truncates at 40 chars), so the fragment
			// ends at the next comma or the trailing " (via <channel>)".
			$scrubbed = preg_replace_callback(
				'/([a-z0-9_]*(?:token|password|secret|api_key|passwd|private_key|credential)[a-z0-9_]*)\s+[^,]*?→[^,]*?(?=,|\s+\(via|$)/i',
				static function ( $m ) {
					return $m[1] . ' changed';
				},
				$message
			);
			if ( null !== $scrubbed && $scrubbed !== $message ) {
				$entries[ $i ]['message'] = $scrubbed;
				$changed                  = true;
			}
		}

		if ( $changed ) {
			self::store( $entries );
		}
	}
}
