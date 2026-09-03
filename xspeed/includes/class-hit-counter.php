<?php
/**
 * Hit_Counter — rolling 24h hits + misses for cache requests.
 *
 * Storage: one transient `xspeed_hit_buffer` containing a list of up to
 * 24 hourly buckets. Each bucket: [hour_start_ts, hits, misses]. Bucket
 * keyed by floor(time()/3600); old buckets drop off when we push a new
 * hour. Transient TTL set to 25 hours so an idle site doesn't lose its
 * history immediately after going quiet.
 *
 * Writes happen on every cached HIT and every MISS (Cache.php records
 * via the static record_* methods). We absorb the cost in an in-process
 * static accumulator that flushes to the transient once per request via
 * register_shutdown_function, so the served-from-disk hot path pays
 * nothing.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Hit_Counter {

	public const TRANSIENT_KEY = 'xspeed_hit_buffer';
	public const TTL           = 90000; // 25h
	public const MAX_BUCKETS   = 24;

	/**
	 * Option key holding the bucket buffer.
	 *
	 * Why an OPTION, not a transient (fixed 2026-06-16): with a persistent
	 * object cache absent or misconfigured, `set_transient()` writes to the
	 * object cache ONLY (never the DB) when an external object cache is
	 * "in use" — even if that cache is non-persistent (e.g. xSpeed's own
	 * object-cache drop-in falling back to an in-request array because Redis
	 * isn't reachable). In that state every recorded hit/miss was written to
	 * a per-request cache and discarded at request end, so the dashboard
	 * hit-ratio read 0 (or a meaningless 100% off one drained log line).
	 * Options always persist to wp_options, so the counter survives across
	 * requests regardless of the object-cache backend. read_buffer() also
	 * busts the options-group cache entry before reading so a stale
	 * in-request copy from a non-persistent cache can't shadow the DB value.
	 */
	public const OPT_KEY = 'xspeed_hit_buffer';

	/** Daily hit/miss aggregates (option, autoload off): 'Y-m-d' => {hits,misses}. */
	public const DAILY_OPT = 'xspeed_hit_daily';

	/** Days of daily history to retain (the trend UI reads 7/30). */
	public const DAILY_MAX_DAYS = 120;

	/**
	 * @var array<string,int> Pending increments keyed by metric
	 *                        ('hit'|'miss'|'excluded'). Flushed on shutdown.
	 *                        `excluded` = requests that reached the render path
	 *                        but must NOT count toward cache performance —
	 *                        404s and known-bot/scanner traffic (#118).
	 */
	private static $pending = array(
		'hit'      => 0,
		'miss'     => 0,
		'excluded' => 0,
	);

	/**
	 * @var bool Whether the shutdown flush is already registered.
	 */
	private static $shutdown_registered = false;

	public static function record_hit(): void {
		++self::$pending['hit'];
		self::ensure_shutdown_flush();
	}

	/**
	 * Record a request that reached the render path but must NOT count toward
	 * the hit ratio — a 404 or known-bot/scanner request. Kept as a separate
	 * line item ("you absorbed N scanner hits today") rather than polluting the
	 * cache-performance denominator, which a wave of `/wp-x7.php` 404s otherwise
	 * craters. Flushed inline like a miss so it's never lost. (#118)
	 */
	public static function record_excluded(): void {
		++self::$pending['excluded'];
		self::flush_pending();
	}

	/**
	 * Whether a User-Agent is a known bot / crawler / vulnerability scanner —
	 * its cache misses are cache-warming or hostile noise, not a signal of how
	 * the cache serves real visitors. Deliberately broad: matches the common
	 * crawler tokens plus the generic markers scanners and libraries carry.
	 * Pure + unit-tested. (#118)
	 */
	public static function is_bot_ua( string $ua ): bool {
		if ( '' === $ua ) {
			// No UA at all is overwhelmingly automated traffic, not a browser.
			return true;
		}
		return 1 === preg_match(
			'~(bot|crawl|spider|slurp|scan|curl|wget|python-requests|python-urllib|libwww|httpclient|go-http|okhttp|axios|node-fetch|headless|phantomjs|masscan|nikto|sqlmap|zgrab|semrush|ahrefs|mj12|dotbot|petalbot|bytespider|facebookexternalhit|preview|monitor|uptime|pingdom|gtmetrix|lighthouse|pagespeed)~i',
			$ua
		);
	}

	public static function record_miss(): void {
		++self::$pending['miss'];
		// Flush misses INLINE, not at shutdown. A MISS is recorded ONLY here
		// (HITs additionally have the durable hits.log drain as a backstop),
		// so if a miss flush is ever dropped the dashboard ratio skews toward
		// 100%. Flushing inline guarantees the miss is committed to the
		// options-backed buffer (see OPT_KEY) within this request, before any
		// shutdown-time object-cache teardown could interfere. Misses are
		// low-frequency (one per page per cache fill), so the inline write
		// cost is negligible; HITs stay deferred (high-volume).
		self::flush_pending();
	}

	/**
	 * Add `$count` HITs in one shot. Used by collect_nginx_log_hits()
	 * to attribute many HITs served directly by nginx (bypassing PHP)
	 * to the counter once we've drained the log file.
	 */
	public static function record_hits_batch( int $count ): void {
		if ( $count <= 0 ) {
			return;
		}
		self::$pending['hit'] += $count;
		self::ensure_shutdown_flush();
	}

	/**
	 * Drain the HITs log file at wp-content/cache/xspeed/hits.log. Two
	 * serve paths that can't call record_hit() inline append one line per
	 * HIT here: the nginx server-level rewrite block (see
	 * Cache::nginx_snippet(), serves without ever reaching PHP) and the
	 * advanced-cache.php drop-in (runs before WordPress loads, so
	 * Hit_Counter isn't available). This method reads the line count,
	 * truncates the file, and folds the count into Hit_Counter via
	 * record_hits_batch — so both uncountable-inline paths still show up
	 * in the dashboard hit-ratio on the next load.
	 *
	 * Returns the number of HITs collected (0 if the log is missing,
	 * empty, or the rewrite block isn't engaged).
	 *
	 * Concurrency: file is opened with LOCK_EX before the read/truncate
	 * round-trip so a concurrent nginx write can't lose entries. Nginx
	 * uses buffer=16k flush=10s on its access_log so writes are batched
	 * and the lock contention is negligible.
	 */
	public static function collect_nginx_log_hits(): int {
		// Lives under uploads/, not the cache dir — see Cache::hits_log_dir()
		// (FBS-82478: a cache-dir access_log can take nginx down on purge/
		// uninstall).
		$path = Cache::hits_log_path();
		if ( ! file_exists( $path ) ) {
			return 0;
		}
		if ( filesize( $path ) === 0 ) {
			return 0;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem doesn't model fopen+flock+ftruncate atomically; we need the lock to prevent nginx writes from being lost.
		$fp = @fopen( $path, 'r+' );
		if ( ! $fp ) {
			return 0;
		}
		// Non-blocking exclusive lock — if nginx is mid-write we just skip
		// this collection and try again on the next dashboard load.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- See fopen rationale.
		if ( ! @flock( $fp, LOCK_EX | LOCK_NB ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the flock'd fopen above; WP_Filesystem can't model flock.
			return 0;
		}
		$count = 0;
		while ( ( $line = fgets( $fp ) ) !== false ) {
			if ( '' !== rtrim( $line ) ) {
				++$count;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftruncate -- See fopen rationale.
		ftruncate( $fp, 0 );
		flock( $fp, LOCK_UN );
		fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the flock'd fopen above; WP_Filesystem can't model flock.

		if ( $count > 0 ) {
			self::record_hits_batch( $count );
			// Flush immediately — the next read of totals_24h() happens
			// inline in Cache::get_stats(), before register_shutdown_function
			// could fire. Without this, the dashboard sees stale numbers
			// and the just-drained HITs appear on the FOLLOWING refresh.
			self::flush_pending();
		}
		return $count;
	}

	/** Option key storing the last-scanned byte offset of the access log. */
	public const SERVER_LOG_OFFSET_OPT = 'xspeed_access_log_offset';

	/**
	 * Count Apache/LiteSpeed static-rewrite HITs by scanning the web
	 * server's access log.
	 *
	 * On Apache/LiteSpeed a cache HIT is served straight from the
	 * `xspeed-static/` tree by a `.htaccess` RewriteRule — the request
	 * never reaches PHP, so (unlike the nginx path, which logs to our own
	 * dedicated hits.log) there's no inline hook to call record_hit().
	 * Instead we read the server's own access log incrementally: every
	 * request whose logged path contains our static-cache dir was a HIT
	 * served below PHP.
	 *
	 * Incremental + safe:
	 *   - We remember a byte offset (SERVER_LOG_OFFSET_OPT) and only read
	 *     bytes appended since last time — O(new traffic), not O(log size).
	 *   - If the log shrank (rotation/truncation) we reset the offset to 0
	 *     and rescan from the top once, so a rotation never double-counts
	 *     or permanently desyncs.
	 *   - We never write to the log, only read; failure is silent.
	 *
	 * Returns 0 (and is a no-op) when no readable access log exists — the
	 * common managed-host case. The drop-in/PHP path still counts its own
	 * HITs, so hit-ratio degrades to "PHP-served hits only" rather than 0.
	 *
	 * @return int HITs folded in this call.
	 */
	public static function collect_server_log_hits(): int {
		// Apache only. nginx writes its own dedicated hits.log (drained by
		// collect_nginx_log_hits); LiteSpeed routes hits through the PHP
		// drop-in (which also appends to that hits.log) because its
		// .htaccess can't header/log a static serve — see
		// Cache::static_rewrite_allowed(). So Apache is the lone server that
		// serves static hits below PHP yet logs them to the SERVER's access
		// log, which is what we scan here.
		if ( Server::APACHE !== Server::type() ) {
			return 0;
		}

		$path = Server::access_log_path();
		if ( '' === $path ) {
			return 0;
		}

		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- log may vanish on rotation between checks.
		if ( false === $size ) {
			return 0;
		}

		$offset = (int) get_option( self::SERVER_LOG_OFFSET_OPT, 0 );
		if ( $offset > $size ) {
			// Log was rotated/truncated since last scan — start over so we
			// don't seek past EOF and miss the new file's lines.
			$offset = 0;
		}
		if ( $offset === $size ) {
			return 0; // Nothing new since last drain.
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- read-only incremental tail of an external log; WP_Filesystem can't fseek and would buffer the whole file through memory.
		$fp = @fopen( $path, 'r' );
		if ( ! $fp ) {
			return 0;
		}
		if ( $offset > 0 ) {
			fseek( $fp, $offset );
		}

		// The static-cache dir, as it appears in a logged request path. We
		// match on the request-target substring so the access-log format
		// (combined/common/custom) doesn't matter — every format includes
		// the request line.
		$needle = '/' . trim( str_replace( ABSPATH, '', XSPEED_CACHE_STATIC_DIR ), '/' );
		$count  = 0;
		while ( ( $line = fgets( $fp ) ) !== false ) {
			// Only count GET requests that landed on the static tree. The
			// "GET " + needle pairing avoids counting our own loopback
			// probe writes or unrelated dir listings.
			if ( false !== strpos( $line, $needle ) && false !== strpos( $line, 'GET ' ) ) {
				++$count;
			}
		}
		$new_offset = ftell( $fp );
		fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the read-only fopen above.

		// Persist the offset even when count is 0 so we don't re-scan the
		// same non-matching bytes every dashboard load.
		update_option( self::SERVER_LOG_OFFSET_OPT, (int) $new_offset, false );

		if ( $count > 0 ) {
			self::record_hits_batch( $count );
			self::flush_pending();
		}
		return $count;
	}

	/**
	 * Returns up to MAX_BUCKETS most-recent hourly buckets oldest →
	 * newest. Each bucket: [ts => unix hour-start, hits => int, misses
	 * => int ].
	 *
	 * @return array<int,array{ts:int,hits:int,misses:int}>
	 */
	/**
	 * Read the bucket buffer straight from the options table, busting any
	 * stale per-request object-cache copy first so a non-persistent cache
	 * can never shadow the committed DB value. See OPT_KEY docblock.
	 *
	 * @return mixed Raw stored value (array on success).
	 */
	private static function read_buffer() {
		// Drop the cached 'options' entry for our key so get_option() falls
		// through to the DB. Harmless on a persistent cache (it just reloads
		// from the DB once); essential on a non-persistent one.
		\wp_cache_delete( self::OPT_KEY, 'options' );
		return get_option( self::OPT_KEY, array() );
	}

	private static function write_buffer( array $buf ): void {
		// Autoload 'no' — the buffer is read only in admin/stats contexts, so
		// it must never inflate the frontend alloptions payload.
		if ( false === get_option( self::OPT_KEY, false ) ) {
			add_option( self::OPT_KEY, $buf, '', 'no' );
			return;
		}
		update_option( self::OPT_KEY, $buf );
	}

	public static function buckets(): array {
		$buf = self::read_buffer();
		if ( ! is_array( $buf ) ) {
			return array();
		}
		// Defensive — strip anything not shaped right.
		$out = array();
		foreach ( $buf as $b ) {
			if ( is_array( $b ) && isset( $b['ts'], $b['hits'], $b['misses'] ) ) {
				$out[] = array(
					'ts'       => (int) $b['ts'],
					'hits'     => (int) $b['hits'],
					'misses'   => (int) $b['misses'],
					// Older buckets (pre-#118) have no 'excluded' key — default 0.
					'excluded' => (int) ( $b['excluded'] ?? 0 ),
				);
			}
		}
		return $out;
	}

	/**
	 * Totals over the last 24h (sum across all buckets). `ratio` is computed
	 * over hits + real misses only; `excluded` (404s + bots) is reported
	 * alongside but kept OUT of the denominator so a scanner flood can't crater
	 * the number. (#118)
	 *
	 * @return array{hits:int,misses:int,excluded:int,ratio:float}
	 */
	public static function totals_24h(): array {
		$buckets  = self::buckets();
		$hits     = 0;
		$misses   = 0;
		$excluded = 0;
		foreach ( $buckets as $b ) {
			$hits     += $b['hits'];
			$misses   += $b['misses'];
			$excluded += $b['excluded'];
		}
		$total = $hits + $misses;
		return array(
			'hits'     => $hits,
			'misses'   => $misses,
			'excluded' => $excluded,
			'ratio'    => $total > 0 ? round( $hits / $total, 4 ) : 0.0,
		);
	}

	public static function reset(): void {
		delete_transient( self::TRANSIENT_KEY );
		// The bucket buffer lives in the OPT_KEY option (migrated off the
		// transient); reset() must clear it too, or record→reset leaves the
		// old hit/miss buckets behind and buckets() still reports them.
		delete_option( self::OPT_KEY );
		\wp_cache_delete( self::OPT_KEY, 'options' );
		delete_option( self::SERVER_LOG_OFFSET_OPT );
		delete_option( self::DAILY_OPT );
		self::$pending = array(
			'hit'      => 0,
			'miss'     => 0,
			'excluded' => 0,
		);
	}

	/**
	 * One-shot register on first record_* call this request.
	 */
	private static function ensure_shutdown_flush(): void {
		if ( self::$shutdown_registered ) {
			return;
		}
		self::$shutdown_registered = true;
		register_shutdown_function( array( __CLASS__, 'flush_pending' ) );
	}

	/**
	 * Flush in-process counters into the transient. Bucketed by current
	 * hour. New hour → append a bucket and drop the oldest if we exceed
	 * MAX_BUCKETS.
	 */
	public static function flush_pending(): void {
		$pending = self::$pending;
		if ( 0 === $pending['hit'] && 0 === $pending['miss'] && 0 === $pending['excluded'] ) {
			return;
		}
		self::$pending = array(
			'hit'      => 0,
			'miss'     => 0,
			'excluded' => 0,
		);

		$hour    = (int) ( time() - ( time() % 3600 ) );
		$buf     = self::buckets();
		$last    = end( $buf );
		$updated = false;

		if ( $last && $last['ts'] === $hour ) {
			$i                       = count( $buf ) - 1;
			$buf[ $i ]['hits']      += $pending['hit'];
			$buf[ $i ]['misses']    += $pending['miss'];
			$buf[ $i ]['excluded']  += $pending['excluded'];
			$updated                 = true;
		}

		if ( ! $updated ) {
			$buf[] = array(
				'ts'       => $hour,
				'hits'     => $pending['hit'],
				'misses'   => $pending['miss'],
				'excluded' => $pending['excluded'],
			);
			while ( count( $buf ) > self::MAX_BUCKETS ) {
				array_shift( $buf );
			}
		}

		self::write_buffer( $buf );
		self::bump_daily( $pending['hit'], $pending['miss'], $pending['excluded'] );
	}

	/**
	 * Fold the just-flushed counts into the persistent daily series. The
	 * hourly buckets expire after ~25h; this option is what makes 7/30-day
	 * hit-ratio trends possible (issue #44). Autoload off — it's only read
	 * by the dashboard/REST, never on the frontend hot path.
	 */
	private static function bump_daily( int $hits, int $misses, int $excluded = 0 ): void {
		if ( $hits <= 0 && $misses <= 0 && $excluded <= 0 ) {
			return;
		}
		$day    = gmdate( 'Y-m-d' );
		$series = get_option( self::DAILY_OPT, array() );
		if ( ! is_array( $series ) ) {
			$series = array();
		}
		if ( ! isset( $series[ $day ] ) || ! is_array( $series[ $day ] ) ) {
			$series[ $day ] = array(
				'hits'     => 0,
				'misses'   => 0,
				'excluded' => 0,
			);
		}
		$series[ $day ]['hits']     += $hits;
		$series[ $day ]['misses']   += $misses;
		$series[ $day ]['excluded']  = (int) ( $series[ $day ]['excluded'] ?? 0 ) + $excluded;
		if ( count( $series ) > self::DAILY_MAX_DAYS ) {
			ksort( $series );
			$series = array_slice( $series, -self::DAILY_MAX_DAYS, null, true );
		}
		update_option( self::DAILY_OPT, $series, false );
	}

	/**
	 * The stored daily hit/miss series, oldest→newest, at most $days rows.
	 *
	 * @return array<int,array{date:string,hits:int,misses:int,ratio:float}>
	 */
	public static function daily_series( int $days = 30 ): array {
		$series = get_option( self::DAILY_OPT, array() );
		if ( ! is_array( $series ) || empty( $series ) ) {
			return array();
		}
		ksort( $series );
		$series = array_slice( $series, -max( 1, $days ), null, true );
		$out    = array();
		foreach ( $series as $date => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$hits     = (int) ( $row['hits'] ?? 0 );
			$misses   = (int) ( $row['misses'] ?? 0 );
			$excluded = (int) ( $row['excluded'] ?? 0 );
			$total    = $hits + $misses;
			$out[]    = array(
				'date'     => (string) $date,
				'hits'     => $hits,
				'misses'   => $misses,
				'excluded' => $excluded,
				'ratio'    => $total > 0 ? round( $hits / $total, 4 ) : 0.0,
			);
		}
		return $out;
	}
}
