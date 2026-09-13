<?php
/**
 * Purge_Runner — clear every cache xSpeed owns and report, per store, what
 * was actually cleared.
 *
 * The one core function behind `wp xspeed purge`, the REST purge callback
 * and the MCP `purge_cache` tool, so the three cannot drift. Cache::purge_all()
 * remains the engine for the local files; this class is the layer that knows
 * which stores exist, which of them are switched on, and how to say so.
 *
 * The distinction that matters everywhere below is skipped vs failed. A store
 * that is not configured has nothing to clear, so the run still succeeded —
 * a CI script that fails because a site has no Cloudflare zone is a script
 * that gets disabled. A store that IS configured and refused the purge is a
 * failure, because the stale bytes are still being served.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Purge_Runner {

	/** A store that was cleared — `entries`/`bytes` say how much, and may be 0. */
	public const CLEARED = 'cleared';

	/** Nothing to clear: the store is off, unconfigured, or has no backend. */
	public const SKIPPED = 'skipped';

	/** The store is configured and the purge was refused or errored. */
	public const FAILED = 'failed';

	/** Built-in target slugs, in the order a full purge runs them. */
	public const TYPES = array( 'page', 'object', 'cloudflare', 'cdn' );

	/**
	 * Set for the duration of a run so CloudflareModule::on_xspeed_purge()
	 * can stand down: with auto_purge_on_update on, the `xspeed_after_purge_all`
	 * fired by the page step would hit the Cloudflare API a second time, and
	 * the second call's result — not the one we reported — would be the one
	 * written to the module's health record.
	 *
	 * @var array<int,string>|null
	 */
	private static $running_slugs = null;

	/** Whether a purge is currently being coordinated by this class. */
	public static function is_running(): bool {
		return null !== self::$running_slugs;
	}

	/**
	 * Whether the run in progress will purge a given store itself.
	 *
	 * The question a listener on `xspeed_after_purge_all` has to ask before
	 * standing down. `is_running()` alone is not enough: on `--type=page` the
	 * action still fires, but no edge target runs — a listener that stood
	 * down on `is_running()` would leave the edge stale AND unreported.
	 *
	 * @param string $slug Target slug, e.g. `cloudflare`.
	 */
	public static function covers( string $slug ): bool {
		return null !== self::$running_slugs && in_array( $slug, self::$running_slugs, true );
	}

	/**
	 * Purge the requested stores.
	 *
	 * @param array<int,string> $types Target slugs, or `all` for everything.
	 * @param string            $cause Who asked, for the purge log.
	 * @return array{ok:bool,cause:string,requested:array<int,string>,types:array<string,array{label:string,group:string,status:string,entries:int|null,bytes:int|null,reason:string}>}
	 */
	public static function run( array $types, string $cause = 'manual' ): array {
		$targets = self::targets();
		$slugs   = self::expand( $types, $targets );

		$report = array(
			'ok'        => true,
			'cause'     => $cause,
			'requested' => array_values( $slugs ),
			'types'     => array(),
		);

		$outer               = self::$running_slugs;
		self::$running_slugs = $slugs;
		try {
			foreach ( $slugs as $slug ) {
				$report['types'][ $slug ] = self::run_target( $slug, $targets[ $slug ], $cause );
				if ( self::FAILED === $report['types'][ $slug ]['status'] ) {
					$report['ok'] = false;
				}
			}
		} finally {
			// Restore rather than null out: a registered target that runs its
			// own purge would otherwise end the OUTER run's guard halfway
			// through, and every listener after it would fire twice.
			self::$running_slugs = $outer;
		}

		// Render caches belong to "Purge All" — the user saying they trust
		// nothing stored anywhere — and not to a scoped run. purge_all()
		// itself deliberately skips them because it also fires on every post
		// publish; the same reasoning applies to `--type=page`. Matching
		// Cache::purge_type( 'all' ), which is the other caller.
		if ( $slugs === array_keys( $targets ) ) {
			Cache::purge_render_caches( $cause );
		}

		return $report;
	}

	/**
	 * Run one target, turning anything it throws into a `failed` row.
	 *
	 * A store that fatals must not take the rest of the purge down with it:
	 * the whole point of the command is that one call clears everything, and
	 * an edge provider timing out is no reason to leave the page cache warm.
	 *
	 * @param string $slug   Target slug.
	 * @param array  $target Target definition from targets().
	 * @param string $cause  Who asked.
	 * @return array{label:string,group:string,status:string,entries:int|null,bytes:int|null,reason:string}
	 */
	private static function run_target( string $slug, array $target, string $cause ): array {
		$row = array(
			'label'   => (string) $target['label'],
			'group'   => (string) ( $target['group'] ?? 'other' ),
			'status'  => self::SKIPPED,
			'entries' => null,
			'bytes'   => null,
			'reason'  => '',
		);

		// `enabled` is optional in the filter contract — a store with nothing
		// to gate on just omits it. Reading the key unconditionally turned
		// that into an undefined-index warning AND a permanent skip, so the
		// callback never ran and the warning landed on STDOUT, where it
		// corrupts `--format=json`.
		$gate    = array_key_exists( 'enabled', $target ) ? $target['enabled'] : '__return_true';
		$enabled = is_callable( $gate ) ? call_user_func( $gate ) : $gate;
		if ( true !== $enabled ) {
			$row['reason'] = is_string( $enabled ) && '' !== $enabled
				? $enabled
				/* translators: %s: cache store name, e.g. "Cloudflare edge". */
				: sprintf( __( '%s is not enabled', 'xspeed' ), $target['label'] );
			return $row;
		}

		try {
			$result = call_user_func( $target['callback'], $cause );
		} catch ( \Throwable $e ) {
			$row['status'] = self::FAILED;
			$row['reason'] = $e->getMessage();
			return $row;
		}

		$result = is_array( $result ) ? $result : array();
		if ( array_key_exists( 'ok', $result ) && ! $result['ok'] ) {
			$row['status'] = self::FAILED;
			$row['reason'] = (string) ( $result['reason'] ?? __( 'the purge was refused', 'xspeed' ) );
			return $row;
		}
		if ( ! empty( $result['skipped'] ) ) {
			$row['reason'] = (string) ( $result['reason'] ?? '' );
			return $row;
		}

		$row['status']  = self::CLEARED;
		$row['entries'] = isset( $result['entries'] ) ? (int) $result['entries'] : null;
		$row['bytes']   = isset( $result['bytes'] ) ? (int) $result['bytes'] : null;
		$row['reason']  = (string) ( $result['reason'] ?? '' );

		return $row;
	}

	/**
	 * Resolve the requested type list to concrete target slugs, in run order.
	 *
	 * `cdn` is a GROUP, not a single target, so a Pro or third-party provider
	 * registered through `xspeed_purge_targets` is reachable by the same flag
	 * without the caller needing to know its slug.
	 *
	 * @param array<int,string> $types   Requested types.
	 * @param array             $targets Target definitions.
	 * @return array<int,string>
	 */
	private static function expand( array $types, array $targets ): array {
		$types = array_filter( array_map( 'strval', $types ) );
		if ( ! $types || in_array( 'all', $types, true ) ) {
			return array_keys( $targets );
		}

		$slugs = array();
		foreach ( $types as $type ) {
			if ( isset( $targets[ $type ] ) ) {
				$slugs[] = $type;
				continue;
			}
			// A group name: select every target in it.
			foreach ( $targets as $slug => $target ) {
				if ( $type === ( $target['group'] ?? 'other' ) ) {
					$slugs[] = $slug;
				}
			}
		}

		// array_keys() order, not request order, so `--type=object,page`
		// still sweeps the files before flushing the object cache.
		return array_values( array_intersect( array_keys( $targets ), array_unique( $slugs ) ) );
	}

	/**
	 * Every type name `--type` accepts: the target slugs plus their groups.
	 *
	 * @return array<int,string>
	 */
	public static function accepted_types(): array {
		$names = array( 'all' );
		foreach ( self::targets() as $slug => $target ) {
			$names[] = $slug;
			$names[] = (string) ( $target['group'] ?? 'other' );
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * The purgeable stores.
	 *
	 * Each entry is `label`, `group` (page|object|edge|cdn|other), `enabled`
	 * (a callable returning true, or a string saying why not) and `callback`
	 * (a callable taking the cause and returning
	 * `{entries?:int, bytes?:int, ok?:bool, skipped?:bool, reason?:string}`).
	 *
	 * @return array<string,array{label:string,group:string,enabled:callable,callback:callable}>
	 */
	public static function targets(): array {
		$targets = array(
			'page'       => array(
				// Named for the flag users reach for, but it is the whole
				// local sweep: flat HTML, the static tree, cached REST
				// responses and minified assets. Splitting those into four
				// line items would be four rows that always move together.
				'label'    => __( 'Page cache (HTML, static, REST, minified assets)', 'xspeed' ),
				'group'    => 'page',
				// Always attempted. Files outlive the setting that wrote them,
				// so a site that has just turned page caching OFF is exactly
				// the site with stale HTML still on disk — refusing to sweep
				// it would be the wrong answer to the only question the user
				// is asking. The sweep is idempotent and reports 0.
				'enabled'  => '__return_true',
				'callback' => array( self::class, 'purge_page' ),
			),
			'object'     => array(
				'label'    => __( 'Object cache', 'xspeed' ),
				'group'    => 'object',
				'enabled'  => array( self::class, 'object_enabled' ),
				'callback' => array( self::class, 'purge_object' ),
			),
			'cloudflare' => array(
				'label'    => __( 'Cloudflare edge', 'xspeed' ),
				'group'    => 'edge',
				'enabled'  => array( self::class, 'cloudflare_enabled' ),
				'callback' => array( self::class, 'purge_cloudflare' ),
			),
			'cdn'        => array(
				'label'    => __( 'CDN', 'xspeed' ),
				'group'    => 'cdn',
				// xSpeed's own CDN module rewrites asset URLs; it holds no
				// cache and has no purge API to call. It stays in the list so
				// `--type=cdn` answers the question rather than erroring on an
				// unknown type, and so a provider that DOES have a purge API
				// can register one through xspeed_purge_targets.
				'enabled'  => array( self::class, 'cdn_enabled' ),
				'callback' => '__return_empty_array',
			),
		);

		/**
		 * Register a purgeable store with `wp xspeed purge`.
		 *
		 * Add-ons hook this to be included in a full purge and reachable via
		 * `--type=<slug>`. Registering a target does NOT replace hooking
		 * `xspeed_after_purge_all` — that action still fires — it is how a
		 * store gets its own line in the report, with its own count and its
		 * own success or failure.
		 *
		 * @param array<string,array{label:string,group:string,enabled:callable,callback:callable}> $targets Store definitions keyed by slug.
		 */
		$targets = (array) apply_filters( 'xspeed_purge_targets', $targets );

		return array_filter(
			$targets,
			static function ( $target ) {
				return is_array( $target ) && isset( $target['label'], $target['callback'] ) && is_callable( $target['callback'] );
			}
		);
	}

	/**
	 * Local files: the flat tree, the static tree, REST responses, minified
	 * assets — and, through `xspeed_after_purge_all`, whatever modules clear
	 * alongside them.
	 *
	 * @param string $cause Who asked.
	 * @return array{entries:int,bytes:int}
	 */
	public static function purge_page( string $cause ): array {
		$removed = Cache::purge_local();
		$entries = (int) $removed['pages'] + (int) $removed['rest'] + (int) $removed['assets'];

		Cache::update_stats( array( 'last_purge' => time() ) );
		do_action( 'xspeed_after_purge_all', $cause );
		Cache_Inventory::invalidate();

		Activity_Log::record(
			'cache_purged',
			sprintf(
				/* translators: 1: what asked for the purge, 2: number of files removed. */
				__( 'Cache purged (%1$s) — %2$d file(s) removed', 'xspeed' ),
				$cause,
				$entries
			),
			Activity_Log::INFO
		);

		return array(
			'entries' => $entries,
			'bytes'   => (int) $removed['bytes'],
		);
	}

	/**
	 * Whether flushing the object cache would do anything.
	 *
	 * A degraded drop-in — installed, connected to nothing — is a skip and
	 * not a failure: there is no persisted data to clear, and the drop-in's
	 * own health is what `wp xspeed objcache status` is for.
	 *
	 * @return true|string
	 */
	public static function object_enabled() {
		if ( ! class_exists( '\\XSpeed\\Object_Cache' ) ) {
			return __( 'the object cache engine is unavailable', 'xspeed' );
		}
		$state = Object_Cache::detect();
		if ( empty( $state['wp_cache_active'] ) ) {
			return __( 'no persistent object cache drop-in is in use', 'xspeed' );
		}
		if ( ! empty( $state['degraded'] ) ) {
			return __( 'the drop-in is installed but is not persisting anything', 'xspeed' );
		}

		return true;
	}

	/**
	 * Flush the object cache.
	 *
	 * @param string $cause Who asked.
	 * @return array{ok:bool,entries:null,reason:string}
	 */
	public static function purge_object( string $cause ): array {
		$ok = Cache::flush_object_cache();
		if ( $ok ) {
			// Entries stay null: neither Redis nor Memcached reports how many
			// keys a FLUSHALL dropped, and inventing a number would be worse
			// than the honest blank.
			Activity_Log::record(
				'cache_purged',
				sprintf(
					/* translators: %s: what asked for the purge. */
					__( 'Purged object cache (%s)', 'xspeed' ),
					$cause
				),
				Activity_Log::INFO
			);
		}

		return array(
			'ok'      => $ok,
			'entries' => null,
			'reason'  => $ok ? '' : __( 'the backend refused the flush', 'xspeed' ),
		);
	}

	/**
	 * Whether the Cloudflare integration is configured well enough to purge.
	 *
	 * @return true|string
	 */
	public static function cloudflare_enabled() {
		$module = Module_Registry::get( 'cloudflare' );
		if ( ! $module || ! method_exists( $module, 'can_purge_edge' ) ) {
			return __( 'the Cloudflare module is not available', 'xspeed' );
		}

		return $module->can_purge_edge();
	}

	/**
	 * Purge the Cloudflare edge.
	 *
	 * @param string $cause Who asked.
	 * @return array{ok:bool,reason:string}
	 */
	public static function purge_cloudflare( string $cause ): array {
		$module = Module_Registry::get( 'cloudflare' );

		return $module->purge_edge( $cause );
	}

	/**
	 * Whether any CDN with a purge API is configured. See the `cdn` target.
	 *
	 * @return true|string
	 */
	public static function cdn_enabled() {
		return __( 'no CDN with a purge API is configured — xSpeed\'s CDN module rewrites asset URLs and holds no cache of its own', 'xspeed' );
	}
}
