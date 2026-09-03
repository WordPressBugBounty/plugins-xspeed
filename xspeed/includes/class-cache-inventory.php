<?php
/**
 * Cache_Inventory — what is actually in the cache, and what it costs.
 *
 * The four stat cards on the dashboard answer "how much"; this answers
 * "which". A number like "412 cached pages" is only trustworthy if you
 * can open it and see the 412, and a size of "8.4 MB" is only actionable
 * if you can see what is taking the space.
 *
 * Two storage shapes have to be reconciled here:
 *
 *   flat   XSPEED_CACHE_DIR/<md5>.html          written by the PHP path
 *   static XSPEED_CACHE_STATIC_DIR/<host><uri>/index.html
 *                                                served by the web server
 *
 * The static tree encodes the URL in its path, so those entries are exact.
 * The flat tree is keyed by md5( host . uri . device . … ), which cannot be
 * reversed — so for those we read the first HEAD_BYTES of the body and pull
 * the canonical/og:url out of the markup. That resolves the overwhelming
 * majority of real pages, and an entry we cannot name is reported with a
 * null URL rather than a guess.
 *
 * Cost control: scanning is capped (SCAN_CAP) and the result memoized for
 * CACHE_TTL, because this runs on an admin click, not on a page render.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Cache_Inventory {

	/**
	 * Hard ceiling on entries examined in one pass. A site with 50k cached
	 * pages must not turn one dashboard click into a 50k-file stat storm;
	 * the response says `capped: true` so the UI can say so out loud
	 * instead of quietly showing a partial list as if it were the whole.
	 */
	public const SCAN_CAP = 750;

	/** Bytes of each flat entry read to look for a canonical URL. */
	public const HEAD_BYTES = 16384;

	/** Memoization window for a full scan, in seconds. */
	public const CACHE_TTL = 60;

	public const TRANSIENT_KEY = 'xspeed_cache_inventory';

	/** Activity-log event types that represent a purge. */
	private const PURGE_TYPES = array( 'cache_purged', 'cache_purge_url', 'cache_purged_url' );

	/**
	 * Resolve a static-tree file back to the URL that produced it.
	 *
	 * `<root>/example.com/blog/post/index.html` → `https://example.com/blog/post/`.
	 * Returns null for anything that isn't shaped like a static entry, so a
	 * stray file in the tree can't become a bogus row.
	 *
	 * Pure — no filesystem access, so the path logic is testable on its own.
	 */
	public static function url_from_static_path( string $file, string $root, bool $https = true ): ?string {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		$file = str_replace( '\\', '/', $file );

		if ( '' === $root || 0 !== strpos( $file, $root . '/' ) ) {
			return null;
		}
		if ( substr( $file, -11 ) !== '/index.html' ) {
			return null;
		}

		$rel = substr( $file, strlen( $root ) + 1, -11 );
		if ( '' === $rel ) {
			return null;
		}

		$segments = explode( '/', $rel );
		$host     = array_shift( $segments );
		// Same allowlist store_static() writes with — anything else is not
		// ours and must not be presented as a cached page.
		if ( null === $host || '' === $host || preg_match( '/[^a-zA-Z0-9.\-]/', $host ) ) {
			return null;
		}

		$path = '' === implode( '/', $segments ) ? '/' : '/' . implode( '/', $segments ) . '/';

		return ( $https ? 'https://' : 'http://' ) . $host . $path;
	}

	/**
	 * Pull a page URL out of the top of a cached document.
	 *
	 * Prefers `<link rel="canonical">` — WordPress emits it for singular
	 * views and it is what the site itself considers the page's address.
	 * Falls back to `og:url`. Returns null rather than guessing from, say,
	 * the first anchor in the body.
	 *
	 * Pure.
	 */
	public static function extract_url_from_html( string $head ): ?string {
		if ( preg_match( '#<link[^>]+rel=["\']canonical["\'][^>]*>#i', $head, $tag ) ) {
			if ( preg_match( '#href=["\']([^"\']+)["\']#i', $tag[0], $href ) ) {
				$url = html_entity_decode( trim( $href[1] ), ENT_QUOTES );
				if ( 0 === stripos( $url, 'http' ) ) {
					return $url;
				}
			}
		}
		if ( preg_match( '#<meta[^>]+property=["\']og:url["\'][^>]*>#i', $head, $tag ) ) {
			if ( preg_match( '#content=["\']([^"\']+)["\']#i', $tag[0], $content ) ) {
				$url = html_entity_decode( trim( $content[1] ), ENT_QUOTES );
				if ( 0 === stripos( $url, 'http' ) ) {
					return $url;
				}
			}
		}
		return null;
	}

	/**
	 * Merge the two storage shapes into one row per page.
	 *
	 * A page served by the web-server rewrite usually exists in BOTH trees.
	 * Listing it twice would make the drill-down disagree with the stat card
	 * it was opened from, so rows are keyed by URL when we know it: the
	 * newest mtime wins for "age", disk bytes add up, and `stored_in` records
	 * which copies exist.
	 *
	 * Entries whose URL is unknown can't be merged with anything — they stay
	 * distinct, keyed by their own path.
	 *
	 * Pure: takes already-collected rows, returns merged rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Raw rows from either tree.
	 * @return array<int,array<string,mixed>> Merged, newest-first.
	 */
	public static function merge_rows( array $rows ): array {
		$merged = array();

		foreach ( $rows as $row ) {
			$url = isset( $row['url'] ) && is_string( $row['url'] ) ? $row['url'] : null;
			$key = null !== $url ? 'u:' . $url : 'p:' . (string) ( $row['path'] ?? '' );

			if ( ! isset( $merged[ $key ] ) ) {
				$merged[ $key ] = $row;
				continue;
			}

			$existing                = $merged[ $key ];
			$existing['bytes']       = (int) $existing['bytes'] + (int) $row['bytes'];
			$existing['mtime']       = max( (int) $existing['mtime'], (int) $row['mtime'] );
			$existing['stored_in']   = array_values( array_unique( array_merge( (array) $existing['stored_in'], (array) $row['stored_in'] ) ) );
			$existing['compressed'] += (int) $row['compressed'];
			sort( $existing['stored_in'] );
			$merged[ $key ] = $existing;
		}

		$out = array_values( $merged );
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return (int) $b['mtime'] <=> (int) $a['mtime'];
			}
		);

		return $out;
	}

	/**
	 * The cached-pages drill-down.
	 *
	 * @param int  $limit  Rows returned.
	 * @param int  $offset Rows skipped.
	 * @param bool $fresh  Bypass the memoized scan.
	 * @return array{entries:array<int,array<string,mixed>>,total:int,capped:bool,generated:int}
	 */
	public static function entries( int $limit = 50, int $offset = 0, bool $fresh = false ): array {
		$rows = $fresh ? null : get_transient( self::TRANSIENT_KEY );
		if ( ! is_array( $rows ) || ! isset( $rows['entries'] ) ) {
			$rows = self::scan();
			set_transient( self::TRANSIENT_KEY, $rows, self::CACHE_TTL );
		}

		$all    = is_array( $rows['entries'] ) ? $rows['entries'] : array();
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );
		$now    = time();

		$page = array_slice( $all, $offset, $limit );
		foreach ( $page as $i => $entry ) {
			$page[ $i ]['age'] = max( 0, $now - (int) $entry['mtime'] );
		}

		return array(
			'entries'   => array_values( $page ),
			'total'     => count( $all ),
			'capped'    => (bool) ( $rows['capped'] ?? false ),
			'generated' => (int) ( $rows['generated'] ?? $now ),
		);
	}

	/**
	 * Walk both trees. Separated from entries() so the memoization and the
	 * pagination stay readable, and so a test can drive the walk directly.
	 *
	 * @return array{entries:array<int,array<string,mixed>>,capped:bool,generated:int}
	 */
	public static function scan(): array {
		$rows    = array();
		$scanned = 0;
		$capped  = false;

		// Flat tree — md5-keyed, URL recovered from the markup.
		// Flat entries live in per-site buckets since #6
		// (XSPEED_CACHE_DIR/<host>/<md5>.html); the legacy top-level layout
		// is still globbed so pre-#6 entries remain visible until they age out.
		$flat = defined( 'XSPEED_CACHE_DIR' )
			? array_merge(
				(array) glob( XSPEED_CACHE_DIR . '/*.html' ),
				(array) glob( XSPEED_CACHE_DIR . '/*/*.html' )
			)
			: array();
		$flat = array_values(
			array_filter(
				$flat,
				static function ( $f ) {
					// min/ and rest/ are separate buckets, not page entries.
					return ! in_array( basename( dirname( (string) $f ) ), array( 'min', 'rest', 'combined' ), true );
				}
			)
		);
		foreach ( $flat as $file ) {
			if ( $scanned >= self::SCAN_CAP ) {
				$capped = true;
				break;
			}
			++$scanned;

			$size = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A file purged between glob() and stat() is expected, not exceptional.
			$rows[] = array(
				'url'        => self::read_url( $file ),
				'path'       => $file,
				'key'        => basename( $file, '.html' ),
				'bytes'      => $size,
				'compressed' => self::sibling_size( $file . '.br' ),
				'mtime'      => (int) @filemtime( $file ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Same race as filesize() above.
				'stored_in'  => array( 'flat' ),
			);
		}

		// Static tree — URL is the path, so no read is needed at all.
		if ( defined( 'XSPEED_CACHE_STATIC_DIR' ) && is_dir( XSPEED_CACHE_STATIC_DIR ) ) {
			$https = ! function_exists( 'home_url' ) || 0 === stripos( (string) home_url(), 'https://' );
			self::walk_static( XSPEED_CACHE_STATIC_DIR, XSPEED_CACHE_STATIC_DIR, $https, $rows, $scanned, $capped );
		}

		return array(
			'entries'   => self::merge_rows( $rows ),
			'capped'    => $capped,
			'generated' => time(),
		);
	}

	/**
	 * Recursive walk of the static tree, mirroring Cache::rmtree_html()'s
	 * traversal so the two can't disagree about what counts as an entry.
	 *
	 * @param array<int,array<string,mixed>> $rows    Collected rows, by reference.
	 * @param int                            $scanned Files examined so far, by reference.
	 * @param bool                           $capped  Set when SCAN_CAP is hit, by reference.
	 */
	private static function walk_static( string $dir, string $root, bool $https, array &$rows, int &$scanned, bool &$capped ): void {
		if ( $capped ) {
			return;
		}
		$entries = @scandir( $dir, SCANDIR_SORT_NONE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable subdir yields no rows; it must not fatal the drill-down.
		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;

			if ( is_dir( $path ) ) {
				self::walk_static( $path, $root, $https, $rows, $scanned, $capped );
				if ( $capped ) {
					return;
				}
				continue;
			}
			if ( 'index.html' !== $entry ) {
				continue;
			}
			if ( $scanned >= self::SCAN_CAP ) {
				$capped = true;
				return;
			}
			++$scanned;

			$rows[] = array(
				'url'        => self::url_from_static_path( $path, $root, $https ),
				'path'       => $path,
				'key'        => '',
				'bytes'      => (int) @filesize( $path ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Purge race, see scan().
				'compressed' => self::sibling_size( $path . '.br' ),
				'mtime'      => (int) @filemtime( $path ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Purge race, see scan().
				'stored_in'  => array( 'static' ),
			);
		}
	}

	/** Size of a precompressed sibling, or 0 when there isn't one. */
	private static function sibling_size( string $path ): int {
		if ( ! is_readable( $path ) ) {
			return 0;
		}
		return (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Purge race, see scan().
	}

	/**
	 * Read just enough of a cached document to find its canonical URL.
	 *
	 * Deliberately a partial read: WP_Filesystem::get_contents() would pull
	 * whole pages into memory, and at SCAN_CAP entries the difference is
	 * megabytes of pointless I/O for data we discard immediately.
	 */
	private static function read_url( string $file ): ?string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Partial read (16KB of head markup); WP_Filesystem has no offset/length API and would read entire pages.
		$head = @file_get_contents( $file, false, null, 0, self::HEAD_BYTES );
		if ( ! is_string( $head ) || '' === $head ) {
			return null;
		}
		return self::extract_url_from_html( $head );
	}

	/**
	 * Where the cache's disk usage actually goes.
	 *
	 * Buckets are what a user can act on — pages, their precompressed
	 * siblings, the REST response cache, minified assets — not what the
	 * code happens to write. `compressed_bytes` is real measured bytes from
	 * `.br` siblings, never an estimate: a made-up compression ratio on a
	 * dashboard is worse than no number.
	 *
	 * @return array<string,mixed>
	 */
	public static function size_breakdown(): array {
		$buckets = array(
			'pages_flat'    => array(
				'label' => __( 'Cached pages (PHP)', 'xspeed' ),
				'bytes' => 0,
				'files' => 0,
			),
			'pages_static'  => array(
				'label' => __( 'Cached pages (server-served)', 'xspeed' ),
				'bytes' => 0,
				'files' => 0,
			),
			'precompressed' => array(
				'label' => __( 'Precompressed copies', 'xspeed' ),
				'bytes' => 0,
				'files' => 0,
			),
			'metadata'      => array(
				'label' => __( 'Per-entry metadata', 'xspeed' ),
				'bytes' => 0,
				'files' => 0,
			),
			'rest'          => array(
				'label' => __( 'REST responses', 'xspeed' ),
				'bytes' => 0,
				'files' => 0,
			),
			'assets'        => array(
				'label' => __( 'Minified CSS/JS', 'xspeed' ),
				'bytes' => 0,
				'files' => 0,
			),
		);

		if ( defined( 'XSPEED_CACHE_DIR' ) ) {
			// Both layouts: per-site buckets (#6) and pre-#6 top level.
			self::add_glob( $buckets['pages_flat'], XSPEED_CACHE_DIR . '/*.html' );
			self::add_glob( $buckets['metadata'], XSPEED_CACHE_DIR . '/*.meta' );
			self::add_glob( $buckets['precompressed'], XSPEED_CACHE_DIR . '/*.br' );
			self::add_glob( $buckets['pages_flat'], XSPEED_CACHE_DIR . '/*/*.html' );
			self::add_glob( $buckets['metadata'], XSPEED_CACHE_DIR . '/*/*.meta' );
			self::add_glob( $buckets['precompressed'], XSPEED_CACHE_DIR . '/*/*.br' );
			self::add_glob( $buckets['rest'], XSPEED_CACHE_DIR . '/rest/*.json' );
			self::add_glob( $buckets['assets'], XSPEED_CACHE_DIR . '/min/*.css' );
			self::add_glob( $buckets['assets'], XSPEED_CACHE_DIR . '/min/*.js' );
			self::add_glob( $buckets['assets'], XSPEED_CACHE_DIR . '/min/combined/*.css' );
			self::add_glob( $buckets['assets'], XSPEED_CACHE_DIR . '/min/combined/*.js' );
		}

		if ( defined( 'XSPEED_CACHE_STATIC_DIR' ) && is_dir( XSPEED_CACHE_STATIC_DIR ) ) {
			$static = self::measure_static( XSPEED_CACHE_STATIC_DIR );
			$buckets['pages_static']['bytes']  = $static['html_bytes'];
			$buckets['pages_static']['files']  = $static['html_files'];
			$buckets['precompressed']['bytes'] += $static['br_bytes'];
			$buckets['precompressed']['files'] += $static['br_files'];
		}

		$total_bytes = 0;
		$total_files = 0;
		$out         = array();
		foreach ( $buckets as $key => $bucket ) {
			$total_bytes += $bucket['bytes'];
			$total_files += $bucket['files'];
			$out[]        = array(
				'key'   => $key,
				'label' => $bucket['label'],
				'bytes' => $bucket['bytes'],
				'files' => $bucket['files'],
			);
		}

		return array(
			'buckets'          => $out,
			'total_bytes'      => $total_bytes,
			'total_files'      => $total_files,
			// What a visitor actually downloads for the pages that have a
			// precompressed copy. Pages without one are served compressed by
			// the web server at request time, which we cannot measure from
			// here — so this is a floor, and the UI labels it as one.
			'compressed_bytes' => $buckets['precompressed']['bytes'],
			'pages'            => $buckets['pages_flat']['files'] + $buckets['pages_static']['files'],
		);
	}

	/**
	 * Accumulate a glob into a bucket.
	 *
	 * @param array{label:string,bytes:int,files:int} $bucket By reference.
	 */
	private static function add_glob( array &$bucket, string $pattern ): void {
		foreach ( (array) glob( $pattern ) as $file ) {
			if ( ! is_string( $file ) ) {
				continue;
			}
			$bucket['bytes'] += (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Purge race, see scan().
			++$bucket['files'];
		}
	}

	/**
	 * Total the static tree without collecting per-file rows.
	 *
	 * @return array{html_bytes:int,html_files:int,br_bytes:int,br_files:int}
	 */
	private static function measure_static( string $dir ): array {
		$totals  = array(
			'html_bytes' => 0,
			'html_files' => 0,
			'br_bytes'   => 0,
			'br_files'   => 0,
		);
		$entries = @scandir( $dir, SCANDIR_SORT_NONE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable subdir contributes nothing rather than fataling.
		if ( false === $entries ) {
			return $totals;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$sub = self::measure_static( $path );
				foreach ( $totals as $k => $v ) {
					$totals[ $k ] = $v + $sub[ $k ];
				}
				continue;
			}
			if ( 'index.html' === $entry ) {
				$totals['html_bytes'] += (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Purge race, see scan().
				++$totals['html_files'];
			} elseif ( substr( $entry, -3 ) === '.br' ) {
				$totals['br_bytes'] += (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Purge race, see scan().
				++$totals['br_files'];
			}
		}

		return $totals;
	}

	/**
	 * Filter the activity log down to purge events.
	 *
	 * The log already carries the cause in its message ("post saved",
	 * "settings change", "manual"), which is the whole point of the
	 * drill-down: "Last purge — 4h ago" is trivia until you can see that it
	 * was a post save rather than something clearing the cache every hour.
	 *
	 * Pure with respect to the entries passed in, so the type filter is
	 * testable without a WordPress transient.
	 *
	 * @param array<int,array<string,mixed>> $entries Activity_Log::entries() output.
	 * @return array<int,array<string,mixed>>
	 */
	public static function filter_purges( array $entries, int $limit = 25 ): array {
		$out = array();
		foreach ( $entries as $entry ) {
			$type = isset( $entry['type'] ) ? (string) $entry['type'] : '';
			if ( ! in_array( $type, self::PURGE_TYPES, true ) ) {
				continue;
			}
			$out[] = array(
				'ts'       => (int) ( $entry['ts'] ?? 0 ),
				'type'     => $type,
				'message'  => (string) ( $entry['message'] ?? '' ),
				'severity' => (string) ( $entry['severity'] ?? 'info' ),
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The last-purge drill-down.
	 *
	 * `last_gc` / `gc_removed` / `gc_removed_total` come from the daily
	 * `xspeed_gc` sweep (Cache_GC) so the collector is verifiable from the
	 * dashboard instead of over SSH.
	 *
	 * @return array{events:array<int,array<string,mixed>>,last_purge:int,last_gc:int,gc_removed:int,gc_removed_total:int,gc_next_run:int}
	 */
	public static function purge_log( int $limit = 25 ): array {
		$limit  = max( 1, min( 50, $limit ) );
		$events = self::filter_purges( Activity_Log::entries(), $limit );
		$stats  = get_option( 'xspeed_stats', array() );
		$stats  = is_array( $stats ) ? $stats : array();

		return array(
			'events'           => $events,
			'last_purge'       => (int) ( $stats['last_purge'] ?? 0 ),
			'last_gc'          => (int) ( $stats['last_gc'] ?? 0 ),
			'gc_removed'       => (int) ( $stats['gc_removed'] ?? 0 ),
			'gc_removed_total' => (int) ( $stats['gc_removed_total'] ?? 0 ),
			'gc_next_run'      => (int) wp_next_scheduled( Cache_GC::CRON_HOOK ),
		);
	}

	/** Drop the memoized scan — called after a purge so the list can't lie. */
	public static function invalidate(): void {
		delete_transient( self::TRANSIENT_KEY );
	}
}
