<?php
/**
 * Cache garbage collection.
 *
 * Invalidation everywhere else in the plugin is event-driven: a post save, a
 * settings change, a theme switch, an explicit purge. A site that fires none
 * of those — a brochure site, a docs portal, a finished catalog — never
 * deletes anything. Expiry still works (both serve paths age-check before
 * using a file, so nobody is served a stale page), but the expired bodies sit
 * on disk forever and the admin's Cache Size figure only ever climbs.
 *
 * Minified assets are worse: the key is md5(source path | source mtime)
 * (Minifier::rewrite_asset), so every plugin or theme update mints a new
 * min/ file and orphans the old one permanently.
 *
 * This adds the missing time-driven collector — a daily `xspeed_gc` cron that
 * sweeps in three phases:
 *
 *   flat    wp-content/cache/xspeed/<md5>.html         per-entry TTL
 *   static  wp-content/cache/xspeed-static/**\/index.html   global TTL
 *   min     wp-content/cache/xspeed/min/**\/*.css|js   long max-age
 *
 * Deliberately NOT swept: `rest/*.json`. A REST entry's TTL is resolved per
 * request through the `xspeed_rest_cache_ttl` filter and is never written to
 * disk (Rest_Cache::ttl_for), so nothing on disk tells GC when one expired.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Cache_GC {

	/** Daily cron hook. */
	public const CRON_HOOK = 'xspeed_gc';

	/** Where the resume point between capped runs is stored. */
	public const CURSOR_OPTION = 'xspeed_gc_cursor';

	/** Candidate files examined per run before the sweep pauses. */
	public const DEFAULT_BUDGET = 5000;

	/** Sweep order. A run walks these in sequence until the budget is spent. */
	private const PHASES = array( 'flat', 'static', 'min' );

	/**
	 * Register the daily event if it isn't already scheduled.
	 *
	 * Called from both CacheModule::activate() (fresh installs) and
	 * CacheModule::boot() (sites that upgraded into this version and will
	 * never run the activation hook again).
	 */
	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// An hour out rather than immediately: activation already does
			// enough filesystem work, and nothing here is urgent.
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/** Drop the event. Called from CacheModule::deactivate(). */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * How long a minified asset may sit unused before collection.
	 *
	 * Deliberately long. These files are only rewritten when the source
	 * asset's mtime changes, so a live, still-referenced asset keeps its
	 * original mtime forever — a short max-age here would delete assets the
	 * current pages still link to. 30 days means a superseded file is
	 * collected roughly a month after the update that orphaned it.
	 *
	 * Age ALONE is not a liveness test, and this docblock used to claim it
	 * was safe because "a live one is regenerated (once) a month after it was
	 * built". That is wrong: regeneration only happens on a cache MISS, when
	 * PHP runs the enqueue pipeline. On a HIT PHP never boots, so nothing
	 * regenerates and the page keeps serving a dead link — the mitigation
	 * failed precisely on the well-cached sites it was meant to protect.
	 * `is_referenced()` is the actual guard; this max-age only decides when
	 * an UNREFERENCED file is collected. (#190)
	 *
	 * A filter returning <= 0 disables the min/ phase rather than deleting
	 * everything — "no max age" is the safer reading of an unset value.
	 */
	public static function asset_max_age(): int {
		/**
		 * Filter the max-age (seconds) for minified/combined assets.
		 *
		 * @param int $max_age Default 30 days.
		 */
		return (int) apply_filters( 'xspeed_gc_asset_max_age', 30 * DAY_IN_SECONDS );
	}

	/** Candidate files a single run may examine. */
	public static function budget(): int {
		/**
		 * Filter the per-run cap on files examined.
		 *
		 * The sweep stops once this many candidates have been looked at and
		 * resumes from the same point on the next run, so a site with
		 * hundreds of thousands of entries can't blow the cron timeout.
		 *
		 * @param int $budget Default 5000.
		 */
		return max( 1, (int) apply_filters( 'xspeed_gc_budget', self::DEFAULT_BUDGET ) );
	}

	/**
	 * Run one bounded sweep.
	 *
	 * @param string $cause Who asked, for the activity log.
	 * @return int Files removed (parents only; .meta/.br siblings are not
	 *             counted, matching purge_all()).
	 */
	public static function run( string $cause = 'scheduled' ): int {
		$budget  = self::budget();
		$cursor  = self::read_cursor();
		$removed = 0;

		// Rebuild the "which assets are still linked" index per run. Memoized
		// within a run (a sweep examines many files), but never across runs —
		// pages are written and purged between ticks, and a stale index would
		// either protect an orphan forever or, worse, fail to protect a live
		// asset. (#190)
		self::reset_reference_index();

		// Resolve the global TTL once — Settings_Manager::get() is cheap but
		// this runs per candidate otherwise.
		$opts        = Settings_Manager::get( 'cache' );
		$default_ttl = max( 1, (int) ( $opts['cache_expiry'] ?? \XSpeed\Modules\Cache\CacheModule::DEFAULT_EXPIRY_HOURS ) ) * HOUR_IN_SECONDS;
		$asset_ttl   = self::asset_max_age();
		$now         = time();

		// Start at the phase we paused in and carry on round the list. Each
		// completed phase resets the cursor and moves to the next; when the
		// last one completes we wrap back to the first, so the next run
		// starts a fresh cycle.
		$start = array_search( $cursor['phase'], self::PHASES, true );
		$start = false === $start ? 0 : (int) $start;
		$after = (string) $cursor['after'];

		for ( $i = $start; $i < count( self::PHASES ); $i++ ) {
			$phase = self::PHASES[ $i ];

			if ( 'min' === $phase && $asset_ttl <= 0 ) {
				$after = '';
				continue;
			}

			list( $phase_removed, $stopped_at ) = self::sweep_phase( $phase, $after, $budget, $now, $default_ttl, $asset_ttl );
			$removed += $phase_removed;

			if ( '' !== $stopped_at ) {
				// Budget spent mid-phase — remember where to pick up.
				self::write_cursor( $phase, $stopped_at );
				self::finish( $removed, $cause );
				return $removed;
			}

			// Phase complete. The static tree can now be pruned of the
			// directories the sweep emptied — safe only once the whole tree
			// has been walked, and bounded because it happens at most once
			// per full cycle.
			if ( 'static' === $phase && defined( 'XSPEED_CACHE_STATIC_DIR' ) ) {
				self::prune_empty_dirs( XSPEED_CACHE_STATIC_DIR );
			}

			$after = '';
		}

		// Full cycle done — rewind to the first phase.
		self::write_cursor( self::PHASES[0], '' );
		self::finish( $removed, $cause );
		return $removed;
	}

	/**
	 * Sweep one phase.
	 *
	 * @param string $phase       One of self::PHASES.
	 * @param string $after       Resume point (absolute path) or ''.
	 * @param int    $budget      Remaining candidate budget, decremented.
	 * @param int    $now         Run timestamp.
	 * @param int    $default_ttl Global page TTL in seconds.
	 * @param int    $asset_ttl   Minified-asset max-age in seconds.
	 * @return array{0:int,1:string} Removed count, and the path the sweep
	 *                               stopped at ('' when the phase finished).
	 */
	private static function sweep_phase( string $phase, string $after, int &$budget, int $now, int $default_ttl, int $asset_ttl ): array {
		$root = self::phase_root( $phase );
		if ( null === $root || ! is_dir( $root ) ) {
			return array( 0, '' );
		}

		$removed = 0;

		// Every phase descends now. The flat phase used to walk only the top
		// level, back when entries lived directly in XSPEED_CACHE_DIR — but
		// per-site buckets moved every entry one level down (or two, for a
		// subdirectory-multisite subsite), so a non-recursive walk stopped
		// seeing the only layout that exists and GC silently expired nothing.
		// On single sites too: their entries are bucketed under the host as
		// well. is_candidate() is what keeps min/ and rest/ out, so recursing
		// here does not pull them in. (QA B1 on #166)
		foreach ( self::files( $root, true ) as $path ) {
			// Cheap name test first: a non-candidate costs no stat and no
			// budget. Everything else in these directories (index.php,
			// .meta, .br, .mobile-separate, the hits log) is either a
			// sibling collected with its parent or must never be touched.
			if ( ! self::is_candidate( $phase, $path ) ) {
				continue;
			}
			// Skip everything already handled in an earlier run. String
			// compare only — self::files() yields in a stable sorted order.
			if ( '' !== $after && strcmp( $path, $after ) <= 0 ) {
				continue;
			}
			if ( $budget <= 0 ) {
				// Paused before examining $path. $after is the last candidate
				// we did examine, which is exactly where to resume.
				return array( $removed, $after );
			}
			--$budget;
			$after = $path;

			$max_age = 'min' === $phase ? $asset_ttl : self::page_max_age( $phase, $path, $default_ttl );
			if ( ! self::is_stale( $path, $now, $max_age ) ) {
				continue;
			}

			// An asset a live cached page still links to is NOT collectable,
			// however old it is. Age is a hint about orphanhood; this is the
			// fact. Without it GC deleted files every cached page pointed at
			// and left the pages in place, so the site served 200s full of
			// 404s. (#190)
			if ( 'min' === $phase && self::is_referenced( $path ) ) {
				continue;
			}

			self::delete_entry( $path );
			++$removed;
		}

		return array( $removed, '' );
	}

	/** Absolute root directory for a phase, or null when undefined. */
	private static function phase_root( string $phase ): ?string {
		switch ( $phase ) {
			case 'flat':
				return defined( 'XSPEED_CACHE_DIR' ) ? XSPEED_CACHE_DIR : null;
			case 'static':
				return defined( 'XSPEED_CACHE_STATIC_DIR' ) ? XSPEED_CACHE_STATIC_DIR : null;
			case 'min':
				return defined( 'XSPEED_CACHE_DIR' ) ? XSPEED_CACHE_DIR . '/min' : null;
		}
		return null;
	}

	/**
	 * Is this file one the given phase collects?
	 *
	 * The flat phase deliberately ignores subdirectories — min/ and rest/
	 * live under XSPEED_CACHE_DIR and have their own rules (or none).
	 */
	private static function is_candidate( string $phase, string $path ): bool {
		$name = basename( $path );
		switch ( $phase ) {
			case 'flat':
				/*
				 * Flat entries live in a per-site bucket since #6:
				 *
				 *   <cache>/<host>/<md5>.html            single site, main blog
				 *   <cache>/<host>/<prefix>/<md5>.html   subdirectory subsite
				 *
				 * Both depths must be accepted — the two-level form is where a
				 * subdirectory-multisite subsite's pages live, and accepting
				 * only one level left them uncollectable. The legacy top-level
				 * layout stays accepted so entries written before #6 still age
				 * out instead of lingering forever. (QA B1 on #166)
				 *
				 * Depth alone is not the guard against min/ and rest/: those
				 * are excluded by name, at either level, because the sweep now
				 * recurses and would otherwise treat their contents as pages.
				 */
				if ( '.html' !== substr( $name, -5 ) ) {
					return false;
				}
				$parent = dirname( $path );
				$depth1 = $parent === XSPEED_CACHE_DIR;
				$depth2 = dirname( $parent ) === XSPEED_CACHE_DIR;
				$depth3 = dirname( dirname( $parent ) ) === XSPEED_CACHE_DIR;
				if ( ! $depth1 && ! $depth2 && ! $depth3 ) {
					return false;
				}
				// Walk up to the cache root looking for a reserved directory,
				// so `min/` and `rest/` are excluded however deep we are.
				for ( $dir = $parent; strlen( $dir ) > strlen( XSPEED_CACHE_DIR ); $dir = dirname( $dir ) ) {
					if ( in_array( basename( $dir ), array( 'min', 'rest' ), true ) ) {
						return false;
					}
				}
				return true;
			case 'static':
				return 'index.html' === $name;
			case 'min':
				return '.css' === substr( $name, -4 ) || '.js' === substr( $name, -3 );
		}
		return false;
	}

	/**
	 * Effective max-age for a cached page, in seconds.
	 *
	 * Cache::is_expired() is the read-time gate and is deliberately NOT
	 * reused here: it resolves the per-post override from the *current*
	 * request (Cache_Rules::current_post_id() is null in cron) and runs the
	 * `xspeed_cache_max_age` filter, whose Pro listeners branch on
	 * is_404()/is_feed() of the request being served. Both are meaningless
	 * on a cron tick and would mis-age every entry.
	 *
	 * The authoritative per-entry value is the `ttl` written into the .meta
	 * sidecar at store time (Cache::write_meta), which is exactly the
	 * resolved max-age for that entry — that is what feeds and 404s carry.
	 * Entries with the default TTL write no sidecar, hence the fallback.
	 *
	 * The static tree never has a .meta: store_static() only runs for plain
	 * 200 text/html, so the global TTL is always correct there.
	 */
	private static function page_max_age( string $phase, string $path, int $default_ttl ): int {
		if ( 'flat' !== $phase ) {
			return $default_ttl;
		}
		// Read the sidecar NEXT TO THE FILE. Cache::read_meta() rebuilds the
		// path from the key via cache_meta_for(), which resolves against the
		// CURRENT request's site bucket — wrong for a cron sweep walking
		// every site's entries, and wrong for the legacy top-level layout.
		// The sidecar is always `<file>.meta`, so derive it directly. (#6)
		$meta_file = substr( $path, 0, -5 ) . '.meta';
		$ttl       = 0;
		if ( is_file( $meta_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own cache sidecar; WP_Filesystem needs admin credentials unavailable during cron.
			$raw = (string) @file_get_contents( $meta_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable sidecar just means "use the global TTL".
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) && isset( $decoded['ttl'] ) ) {
				$ttl = (int) $decoded['ttl'];
			}
		}
		return $ttl > 0 ? $ttl : $default_ttl;
	}

	/**
	 * Age test. A file that vanished between the scan and here (a concurrent
	 * purge, a parallel cron) is not stale — there is nothing to delete.
	 * A future mtime (clock skew, rsync -t from a fast host) reads as age 0,
	 * so it is kept rather than collected.
	 */
	private static function is_stale( string $path, int $now, int $max_age ): bool {
		if ( $max_age <= 0 ) {
			return false;
		}
		clearstatcache( true, $path );
		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- file may have been removed concurrently; false is handled below.
		if ( false === $mtime ) {
			return false;
		}
		return ( $now - (int) $mtime ) > $max_age;
	}

	/**
	 * Asset paths (relative to `min/`) that some cached page still links to.
	 *
	 * Built once per run and memoized: a sweep examines up to `budget()`
	 * files, and re-reading every cached page for each of them would turn a
	 * cheap cron tick into an O(assets x pages) crawl.
	 *
	 * Scans BOTH cache trees. The static tree is served by nginx without ever
	 * running PHP, so a page there can outlive any invalidation we do in PHP —
	 * missing it would leave exactly the 404s this fix exists to prevent, on
	 * the fastest path.
	 *
	 * @var array<string,true>|null
	 */
	private static $referenced = null;

	/** Forget the memo — the next run rebuilds it. */
	public static function reset_reference_index(): void {
		self::$referenced = null;
	}

	/**
	 * Is this asset linked from any cached page?
	 *
	 * @param string $path Absolute path to a file under `min/`.
	 */
	private static function is_referenced( string $path ): bool {
		if ( null === self::$referenced ) {
			self::$referenced = self::build_reference_index();
		}

		$min_root = self::phase_root( 'min' );
		if ( null === $min_root ) {
			return false;
		}
		// Compare on the path RELATIVE to min/, which is what a page's URL
		// carries — absolute paths differ between the cache dir and the URL.
		$rel = ltrim( str_replace( $min_root, '', $path ), '/' );

		return isset( self::$referenced[ $rel ] );
	}

	/**
	 * Read every cached page once and collect the assets they reference.
	 *
	 * @return array<string,true> Keys are paths relative to `min/`.
	 */
	private static function build_reference_index(): array {
		$found = array();

		foreach ( array( 'flat', 'static' ) as $phase ) {
			$root = self::phase_root( $phase );
			if ( null === $root || ! is_dir( $root ) ) {
				continue;
			}
			foreach ( self::files( $root, 'flat' !== $phase ) as $file ) {
				if ( '.html' !== substr( $file, -5 ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading our own cache file; WP_Filesystem is unavailable in cron context.
				$html = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a concurrent purge can unlink mid-walk; '' is handled.
				if ( '' === $html ) {
					continue;
				}
				if ( ! preg_match_all( '#/cache/xspeed/min/([^"\'\s?>]+\.(?:css|js))#', $html, $m ) ) {
					continue;
				}
				foreach ( $m[1] as $rel ) {
					$found[ $rel ] = true;
				}
			}
		}

		return $found;
	}

	/**
	 * Delete a cache entry and every sibling that only exists because of it,
	 * so the sweep never creates the orphans it is there to remove:
	 *
	 *   <md5>.html    → <md5>.meta, <md5>.html.br
	 *   index.html    → index.html.br
	 *   <key>.css/js  → (none)
	 */
	private static function delete_entry( string $path ): void {
		wp_delete_file( $path );

		$br = $path . '.br';
		if ( file_exists( $br ) ) {
			wp_delete_file( $br );
		}

		if ( '.html' === substr( $path, -5 ) ) {
			$meta = substr( $path, 0, -5 ) . '.meta';
			if ( file_exists( $meta ) ) {
				wp_delete_file( $meta );
			}
		}

		// Deleting an asset and invalidating the pages that embed it are ONE
		// operation, so the two caches can never disagree. is_referenced()
		// already keeps a linked asset alive, so this is the belt to that
		// braces: it covers the races the index cannot see — a page written
		// after the index was built, or a reference in a form the scan did
		// not match. Without it, any gap between the two caches shows up as a
		// 200 page full of 404s. (#190 AC2)
		$min_root = self::phase_root( 'min' );
		if ( null !== $min_root && 0 === strpos( $path, $min_root . '/' ) ) {
			self::purge_pages_referencing( ltrim( str_replace( $min_root, '', $path ), '/' ) );
		}
	}

	/**
	 * Remove every cached page that links to the given asset.
	 *
	 * Walks both trees: the static one is served by nginx without PHP, so a
	 * page left there keeps serving the dead link no matter what the flat
	 * cache says.
	 *
	 * @param string $rel Asset path relative to `min/`.
	 */
	private static function purge_pages_referencing( string $rel ): void {
		if ( '' === $rel ) {
			return;
		}

		foreach ( array( 'flat', 'static' ) as $phase ) {
			$root = self::phase_root( $phase );
			if ( null === $root || ! is_dir( $root ) ) {
				continue;
			}
			foreach ( self::files( $root, 'flat' !== $phase ) as $file ) {
				if ( '.html' !== substr( $file, -5 ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading our own cache file; WP_Filesystem is unavailable in cron context.
				$html = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- concurrent purge can unlink mid-walk.
				if ( '' === $html || false === strpos( $html, $rel ) ) {
					continue;
				}

				wp_delete_file( $file );
				foreach ( array( $file . '.br', substr( $file, 0, -5 ) . '.meta' ) as $sibling ) {
					if ( file_exists( $sibling ) ) {
						wp_delete_file( $sibling );
					}
				}
			}
		}
	}

	/**
	 * Yield every file under $dir, depth-first, in a stable order.
	 *
	 * Stable matters: the resume cursor is a path comparison, so two runs
	 * must agree on the sequence. scandir() sorts by default; the explicit
	 * recursion keeps directories and files interleaved in that same order.
	 *
	 * @param string $dir       Directory to walk.
	 * @param bool   $recursive Descend into subdirectories.
	 * @return \Generator<string>
	 */
	private static function files( string $dir, bool $recursive = true ): \Generator {
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable directory is not fatal; empty walk is the right answer.
		if ( false === $entries ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				if ( $recursive ) {
					yield from self::files( $path );
				}
				continue;
			}
			yield $path;
		}
	}

	/**
	 * Remove directories the sweep emptied, bottom-up. Returns true when
	 * $dir itself is now gone. The root is kept — nginx's access_log target
	 * and the silence file live beside it and callers assume it exists.
	 */
	private static function prune_empty_dirs( string $root ): void {
		$entries = @scandir( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see files().
		if ( false === $entries ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $root . '/' . $entry;
			if ( is_dir( $path ) ) {
				self::prune_empty_dirs( $path );
				// Best-effort: a non-empty directory simply refuses.
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- mirrors Cache::rmtree_html(); WP_Filesystem needs admin credentials unavailable on a cron tick.
				@rmdir( $path );
			}
		}
	}

	/** Persisted resume point: which phase, and the last path examined. */
	private static function read_cursor(): array {
		$stored = get_option( self::CURSOR_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$phase = isset( $stored['phase'] ) && in_array( $stored['phase'], self::PHASES, true )
			? (string) $stored['phase']
			: self::PHASES[0];

		return array(
			'phase' => $phase,
			'after' => isset( $stored['after'] ) && is_string( $stored['after'] ) ? $stored['after'] : '',
		);
	}

	private static function write_cursor( string $phase, string $after ): void {
		$value = array(
			'phase' => $phase,
			'after' => $after,
		);
		if ( false === get_option( self::CURSOR_OPTION, false ) ) {
			add_option( self::CURSOR_OPTION, $value, '', 'no' );
			return;
		}
		update_option( self::CURSOR_OPTION, $value );
	}

	/**
	 * Record the run so the Cache section can show it without SSH, and drop
	 * the memoized inventory when anything actually went away.
	 */
	private static function finish( int $removed, string $cause ): void {
		$stats = Cache::get_stats_option();
		Cache::update_stats(
			array(
				'last_gc'          => time(),
				'gc_removed'       => $removed,
				'gc_removed_total' => (int) ( $stats['gc_removed_total'] ?? 0 ) + $removed,
			)
		);

		if ( $removed < 1 ) {
			return;
		}

		Cache_Inventory::invalidate();

		Activity_Log::record(
			'cache_purged',
			sprintf(
				/* translators: 1: cause of the sweep, 2: number of files removed. */
				__( 'Cache garbage collection (%1$s) — %2$d expired file(s) removed', 'xspeed' ),
				$cause,
				$removed
			),
			Activity_Log::INFO
		);
	}
}
