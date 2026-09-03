<?php
/**
 * Mcp_Activity_Log — audit trail of every MCP tool call.
 *
 * The site's whole AI story rests on the admin being able to answer
 * "what did the assistant actually do to my site?". Settings mutations
 * already land in the change log via Settings_Manager, but that only
 * covers writes to the schema — a purge, a benchmark, a preloader run,
 * or a read of the site's settings leaves no trace at all. This is the
 * record for all of them.
 *
 * Storage is an OPTION, not a transient, and deliberately so: an audit
 * trail that an object-cache flush can evaporate is not an audit trail.
 * Autoload is off (it's only read by the panel/REST/CLI) and the ring is
 * capped at MAX_ENTRIES so the row can't grow without bound.
 *
 * Entry shape:
 *   [ ts => int, tool => string, args => string, ok => bool,
 *     error => string, scope => 'read'|'write', channel => string ]
 *
 * `args` is a short human-readable summary, never the raw argument
 * array — tool arguments can carry long option blobs, and an audit row
 * needs to stay glanceable (and small). Values are truncated to
 * ARGS_MAX chars in total.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

defined( 'ABSPATH' ) || exit;

final class Mcp_Activity_Log {

	public const OPTION      = 'xspeed_mcp_activity';
	public const MAX_ENTRIES = 200;
	public const ARGS_MAX    = 200;

	/** Placeholder written in place of a credential-looking value. */
	public const REDACTED = '[redacted]';

	/**
	 * Argument names whose VALUE must never reach the log.
	 *
	 * The audit trail is read in the dashboard, included in support
	 * snapshots, and lives in a database row that gets copied into
	 * staging and backups. A tool call that sets a Cloudflare API token
	 * or a license key would otherwise write that secret verbatim into
	 * all three. Matching is a case-insensitive substring test on the
	 * argument NAME, so `api_key`, `gtmetrix_api_key`, and `apiKey` all
	 * hit the same rule — the row still records that the key was set,
	 * just not what it was set to.
	 *
	 * @var string[]
	 */
	private const SECRET_KEY_HINTS = array(
		'token',
		'secret',
		'password',
		'passwd',
		'_pass',
		'api_key',
		'apikey',
		'access_key',
		'private_key',
		'license',
		'credential',
		'auth',
		'nonce',
		'signature',
	);

	/**
	 * True when an argument name looks like it carries a credential.
	 *
	 * Deliberately generous: a false positive costs one unreadable audit
	 * value, a false negative writes a live secret to the database.
	 */
	public static function is_secret_key( string $key ): bool {
		$needle = strtolower( $key );

		// A bare `key` (as opposed to, say, `cache_key`) is almost always
		// a credential in this catalog's argument shapes.
		if ( 'key' === $needle ) {
			return true;
		}

		foreach ( self::SECRET_KEY_HINTS as $hint ) {
			if ( false !== strpos( $needle, $hint ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Condense a decoded argument array into one short, readable line.
	 *
	 * Pure — no WP, no I/O — so the truncation contract is unit-testable.
	 * Scalars render as `key=value`; arrays/objects collapse to a shape
	 * hint (`key=[3 items]`) because an audit row should say that a list
	 * was passed, not reproduce it. Values under a credential-looking key
	 * render as `key=[redacted]` — see SECRET_KEY_HINTS.
	 *
	 * @param array<string,mixed> $args Decoded tool arguments.
	 */
	public static function summarize_args( array $args ): string {
		if ( empty( $args ) ) {
			return '';
		}

		$parts = array();
		foreach ( $args as $key => $value ) {
			$key = (string) $key;

			if ( self::is_secret_key( $key ) ) {
				// Redact before any type branch. Arrays already collapse
				// to a count and leak nothing, but a secret under a
				// scalar key would otherwise be written verbatim.
				$rendered = self::REDACTED;
			} elseif ( is_bool( $value ) ) {
				$rendered = $value ? 'true' : 'false';
			} elseif ( is_scalar( $value ) || null === $value ) {
				$rendered = (string) $value;
				if ( strlen( $rendered ) > 60 ) {
					$rendered = substr( $rendered, 0, 57 ) . '...';
				}
			} elseif ( is_array( $value ) ) {
				$count    = count( $value );
				$rendered = sprintf( '[%d item%s]', $count, 1 === $count ? '' : 's' );
			} else {
				$rendered = '{object}';
			}

			$parts[] = $key . '=' . $rendered;
		}

		$summary = implode( ' ', $parts );

		return strlen( $summary ) > self::ARGS_MAX
			? substr( $summary, 0, self::ARGS_MAX - 3 ) . '...'
			: $summary;
	}

	/**
	 * Append one call to the ring. Never throws — an audit write must not
	 * be able to fail the tool call it is describing.
	 *
	 * @param array<string,mixed> $args    Decoded tool arguments.
	 * @param string              $error   Error message when the call failed.
	 * @param string              $scope   'read' or 'write'.
	 * @param string              $channel Transport that carried the call.
	 */
	public static function record(
		string $tool,
		array $args,
		bool $ok,
		string $error = '',
		string $scope = 'write',
		string $channel = 'mcp'
	): void {
		try {
			$entries = self::entries();

			array_unshift(
				$entries,
				array(
					'ts'      => time(),
					'tool'    => sanitize_key( $tool ),
					'args'    => self::summarize_args( $args ),
					'ok'      => $ok,
					'error'   => $ok ? '' : substr( $error, 0, 200 ),
					'scope'   => 'read' === $scope ? 'read' : 'write',
					'channel' => sanitize_key( $channel ),
				)
			);

			if ( count( $entries ) > self::MAX_ENTRIES ) {
				$entries = array_slice( $entries, 0, self::MAX_ENTRIES );
			}

			update_option( self::OPTION, $entries, false );
		} catch ( \Throwable $e ) {
			// An audit-trail failure must never surface as a tool failure.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'xSpeed MCP activity log write failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-gated diagnostic.
			}
		}
	}

	/**
	 * Newest-first entries. Defensive shape coercion so a hand-edited or
	 * partially-written option can never break the panel.
	 *
	 * @param int $limit Max entries to return; 0 means all.
	 * @return array<int,array{ts:int,tool:string,args:string,ok:bool,error:string,scope:string,channel:string}>
	 */
	public static function entries( int $limit = 0 ): array {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['ts'], $entry['tool'] ) ) {
				continue;
			}
			$out[] = array(
				'ts'      => (int) $entry['ts'],
				'tool'    => (string) $entry['tool'],
				'args'    => isset( $entry['args'] ) ? (string) $entry['args'] : '',
				'ok'      => ! empty( $entry['ok'] ),
				'error'   => isset( $entry['error'] ) ? (string) $entry['error'] : '',
				'scope'   => isset( $entry['scope'] ) && 'read' === $entry['scope'] ? 'read' : 'write',
				'channel' => isset( $entry['channel'] ) ? (string) $entry['channel'] : 'mcp',
			);
		}

		if ( $limit > 0 && count( $out ) > $limit ) {
			$out = array_slice( $out, 0, $limit );
		}

		return $out;
	}

	/**
	 * Roll-up for the panel header: total calls, failures, last call time,
	 * and the most-used tool. Computed from the ring rather than stored,
	 * so it can never disagree with the rows shown underneath it.
	 *
	 * @return array{total:int,failed:int,last_ts:int,top_tool:string}
	 */
	public static function summary(): array {
		$entries = self::entries();

		$failed = 0;
		$counts = array();
		foreach ( $entries as $entry ) {
			if ( ! $entry['ok'] ) {
				++$failed;
			}
			$tool            = $entry['tool'];
			$counts[ $tool ] = isset( $counts[ $tool ] ) ? $counts[ $tool ] + 1 : 1;
		}

		$top_tool = '';
		if ( ! empty( $counts ) ) {
			arsort( $counts );
			$top_tool = (string) array_key_first( $counts );
		}

		return array(
			'total'    => count( $entries ),
			'failed'   => $failed,
			'last_ts'  => empty( $entries ) ? 0 : $entries[0]['ts'],
			'top_tool' => $top_tool,
		);
	}

	/**
	 * Wipe the audit trail.
	 *
	 * Refused while an MCP tool call is being dispatched. The whole point
	 * of the trail is to answer "what did the assistant do to my site?",
	 * and `run_command("mcp activity", …, {"clear": true})` let the
	 * assistant answer that question with a blank page — an audit log the
	 * audited party can erase is not one. Clearing stays available to a
	 * human from the dashboard button and from real WP-CLI, neither of
	 * which runs inside a dispatch.
	 *
	 * @return bool True when the log was cleared, false when refused.
	 */
	public static function clear(): bool {
		if ( Mcp_Tools::in_dispatch() ) {
			self::record(
				'mcp_activity_clear',
				array(),
				false,
				'Refused: the audit trail cannot be cleared from an MCP tool call.',
				'write',
				'mcp'
			);
			return false;
		}

		delete_option( self::OPTION );
		return true;
	}
}
