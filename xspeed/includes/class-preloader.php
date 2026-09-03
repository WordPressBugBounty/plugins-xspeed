<?php
/**
 * Preloader — sitemap-driven cache warmer.
 *
 * On `start()`:
 *   1. Fetches the configured sitemap (or auto-detects /wp-sitemap.xml).
 *   2. Recursively follows nested sitemap indexes.
 *   3. Filters URLs against the Cache module's excluded_urls list.
 *   4. Queues the result in a transient.
 *   5. Schedules the next WP-Cron tick.
 *
 * Each `tick()` processes up to `batch_size` URLs from the queue via
 * `wp_remote_get()` (short timeout, sslverify off for local dev tolerance,
 * a UA that flags itself so site owners can spot crawler traffic in
 * access logs). Cache::should_cache() picks up the GET → writes the cache
 * file on miss. The next visitor sees a HIT.
 *
 * State (transient `xspeed_preloader_state`):
 *   { running, started_at, finished_at, queue, processed, total,
 *     last_url, errors[] }
 *
 * Configuration comes from PreloaderModule's per-module option
 * (xspeed_module_preloader) via Settings_Manager.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Preloader {

	public const STATE_KEY      = 'xspeed_preloader_state';
	public const STATE_TTL      = 86400; // 24h — long enough for slow crawls.
	public const CRON_HOOK      = 'xspeed_preloader_tick';
	public const USER_AGENT     = 'xSpeed-Preloader/1.0 (+cache warmer; admin-initiated)';
	public const REQUEST_TIMEOUT = 8;

	/**
	 * Default cap on NEW remote images resolved per warmed page.
	 *
	 * A crawl warms the cache; it is not a licence to hit third-party hosts
	 * hundreds of times for one page.
	 *
	 * The cap counts only images whose dimensions are not already known, and
	 * results persist between runs — so each crawl advances through a heavily
	 * embedded page rather than re-picking the same first N. That is what
	 * makes a cap safe here: without the skip it would strand everything past
	 * the limit permanently, because images appear in the same DOM order
	 * every time.
	 *
	 * 20 is a starting point, not a measurement. Sites that embed more can
	 * raise it via `xspeed_preloader_remote_dimension_limit`.
	 */
	private const REMOTE_DIMENSION_LIMIT = 20;

	/**
	 * How many new remote images one warmed page may resolve.
	 */
	private static function remote_dimension_limit(): int {
		/**
		 * Filter the per-page cap on remote dimension lookups.
		 *
		 * @param int $limit Default 20. Values below 1 disable the lookup.
		 */
		return (int) apply_filters( 'xspeed_preloader_remote_dimension_limit', self::REMOTE_DIMENSION_LIMIT );
	}

	/**
	 * Why the top-level sitemap fetch failed on this request, or '' when it
	 * succeeded. Set by fetch_sitemap_urls(), read by resolve_queue() — the
	 * reason has to survive the return of an empty array, which is exactly
	 * what it could not do before. Request-scoped; never persisted. (#142)
	 *
	 * @var string
	 */
	private static $last_sitemap_error = '';

	/**
	 * The sitemap URL the last error refers to. Kept beside the message so
	 * an error entry can carry a real `url` field like every other one,
	 * rather than repeating the URL already inside the message text.
	 */
	private static $last_sitemap_url = '';

	/**
	 * How the queue for the current crawl was built — 'sitemap', 'fallback'
	 * (enumerated from the database because the sitemap was unreachable), or
	 * 'none'. Surfaced in the state so the panel, REST and CLI can each say
	 * what actually happened instead of reporting a bare zero. (#142)
	 *
	 * @var string
	 */
	private static $queue_source = 'none';

	/** Largest number of URLs the database fallback will enumerate. */
	private const FALLBACK_LIMIT = 500;

	/**
	 * Kick off a fresh crawl. Returns the initial state.
	 */
	public static function start(): array {
		$opts = Settings_Manager::get( 'preloader' );
		$urls = self::resolve_queue( $opts );

		// A crawl that queued nothing because the sitemap was unreachable is a
		// FAILURE, and every layer above needs to be able to say so. It used
		// to be indistinguishable from success: errors stayed empty, the REST
		// route returned 200, and the CLI printed a green Success. (#142)
		$sitemap_error = self::$last_sitemap_error;
		$errors        = array();
		if ( '' !== $sitemap_error && empty( $urls ) ) {
			// Same {url, error, ts} shape every other entry uses. A bare
			// string here fataled `wp xspeed preloader status`, which
			// destructures `$e['url']` over the list — and took the MCP
			// `get_preloader_status` tool down with it, so an agent asking
			// why the preload failed got "Cannot access offset of type
			// string on string" instead of the reason this code records.
			// `url` is the sitemap because that is what failed. (QA F1)
			$errors[] = array(
				'url'   => self::$last_sitemap_url,
				'error' => $sitemap_error,
				'ts'    => time(),
			);
		}

		$state = array(
			'running'       => ! empty( $urls ),
			'started_at'    => time(),
			'finished_at'   => empty( $urls ) ? time() : 0,
			'queue'         => array_values( $urls ),
			'processed'     => 0,
			'total'         => count( $urls ),
			'last_url'      => '',
			'errors'        => $errors,
			// Consumers render on these: the panel needs to distinguish
			// "not started" from "ran and found nothing", and to tell the
			// user when the queue came from the fallback rather than the
			// sitemap they configured.
			'source'        => self::$queue_source,
			'sitemap_error' => $sitemap_error,
		);
		set_transient( self::STATE_KEY, $state, self::STATE_TTL );

		if ( '' !== $sitemap_error && 'fallback' === self::$queue_source ) {
			$message  = sprintf(
				/* translators: 1: number of URLs, 2: the sitemap failure detail. */
				__( 'Preloader queued %1$d URLs from the site content — %2$s', 'xspeed' ),
				$state['total'],
				$sitemap_error
			);
			$severity = Activity_Log::WARN;
		} elseif ( '' !== $sitemap_error ) {
			$message  = sprintf(
				/* translators: %s: the sitemap failure detail. */
				__( 'Preloader could not start — %s', 'xspeed' ),
				$sitemap_error
			);
			$severity = Activity_Log::WARN;
		} else {
			$message  = sprintf(
				/* translators: 1: number of URLs, 2: plural suffix. */
				__( 'Preloader queued %1$d URL%2$s for warming.', 'xspeed' ),
				$state['total'],
				1 === $state['total'] ? '' : 's'
			);
			$severity = $state['total'] > 0 ? Activity_Log::INFO : Activity_Log::WARN;
		}
		Activity_Log::record( 'preloader_started', $message, $severity );

		// Schedule the first tick ~5 seconds out so the kick-off REST call
		// returns instantly; wp_schedule_single_event covers the
		// "process the queue ASAP" path without a heavy synchronous loop.
		if ( $state['running'] ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}

		return $state;
	}

	/**
	 * Cancel an in-flight crawl. Idempotent.
	 */
	public static function stop(): array {
		$state = self::status();
		if ( $state['running'] ) {
			Activity_Log::record(
				'preloader_stopped',
				sprintf( 'Preloader stopped (%d/%d URLs warmed).', $state['processed'], $state['total'] ),
				Activity_Log::INFO
			);
		}

		// Clear scheduled ticks.
		wp_clear_scheduled_hook( self::CRON_HOOK );

		$state['running']     = false;
		$state['finished_at'] = time();
		$state['queue']       = array();
		set_transient( self::STATE_KEY, $state, self::STATE_TTL );
		return $state;
	}

	public static function status(): array {
		$raw = get_transient( self::STATE_KEY );
		if ( ! is_array( $raw ) ) {
			return self::empty_state();
		}
		return wp_parse_args( $raw, self::empty_state() );
	}

	private static function empty_state(): array {
		return array(
			'running'     => false,
			'started_at'  => 0,
			'finished_at' => 0,
			'queue'       => array(),
			'processed'   => 0,
			'total'       => 0,
			'last_url'    => '',
			'errors'      => array(),
		);
	}

	/**
	 * Tick handler — pulls up to batch_size URLs off the queue, warms
	 * each, persists state, and reschedules itself until the queue is
	 * empty. Called via the xspeed_preloader_tick action.
	 */
	public static function tick(): void {
		$state = self::status();
		if ( ! $state['running'] || empty( $state['queue'] ) ) {
			if ( $state['running'] ) {
				self::mark_complete( $state );
			}
			return;
		}

		$opts  = Settings_Manager::get( 'preloader' );
		$batch = max( 1, min( 50, (int) ( $opts['batch_size'] ?? 5 ) ) );

		$processed_this_tick = 0;
		while ( $processed_this_tick < $batch && ! empty( $state['queue'] ) ) {
			$url = array_shift( $state['queue'] );
			self::warm_url( $url, $state );
			$state['processed']++;
			$state['last_url'] = $url;
			$processed_this_tick++;
		}

		if ( empty( $state['queue'] ) ) {
			self::mark_complete( $state );
			return;
		}

		// More to do — persist + reschedule. Slight delay to avoid
		// hammering the origin with parallel batches.
		set_transient( self::STATE_KEY, $state, self::STATE_TTL );
		wp_schedule_single_event( time() + 10, self::CRON_HOOK );
	}

	/**
	 * Fire a single warm request for one URL with no queue / no cron
	 * (the "content warmer" path: new post published → warm its URL
	 * immediately). Records an activity event so the user can see in
	 * the Health log that warming happened.
	 *
	 * Best-effort and non-blocking-feeling — uses a short timeout so a
	 * dead origin can't hang the calling request. Returns true if the
	 * fetch completed with a non-error status, false otherwise.
	 */
	public static function warm_one( string $url, string $cause = 'manual' ): bool {
		if ( '' === $url ) {
			return false;
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => self::REQUEST_TIMEOUT,
				'sslverify'  => false,
				'user-agent' => self::USER_AGENT,
				'blocking'   => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			Activity_Log::record(
				'preloader_warm_failed',
				sprintf( 'Warm %s failed (%s): %s', $cause, $url, $response->get_error_message() ),
				Activity_Log::WARN
			);
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			Activity_Log::record(
				'preloader_warm_failed',
				sprintf( 'Warm %s failed (%s): HTTP %d', $cause, $url, $code ),
				Activity_Log::WARN
			);
			return false;
		}
		Activity_Log::record(
			'preloader_warmed_one',
			sprintf( 'Warmed %s (%s)', $url, $cause ),
			Activity_Log::INFO
		);
		return true;
	}

	private static function warm_url( string $url, array &$state ): void {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => self::REQUEST_TIMEOUT,
				'sslverify'  => false,
				'user-agent' => self::USER_AGENT,
				'headers'    => array(
					'Accept' => 'text/html,application/xhtml+xml',
				),
				'blocking'   => true,
			)
		);
		if ( is_wp_error( $response ) ) {
			$state['errors'][] = array(
				'url'   => $url,
				'error' => $response->get_error_message(),
				'ts'    => time(),
			);
			// Cap retained errors so a broken sitemap doesn't blow the
			// transient size.
			$state['errors'] = array_slice( $state['errors'], -20 );
			return;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$state['errors'][] = array(
				'url'   => $url,
				'error' => sprintf( 'HTTP %d', $code ),
				'ts'    => time(),
			);
			$state['errors'] = array_slice( $state['errors'], -20 );
			return;
		}

		self::warm_remote_dimensions( (string) wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Resolve dimensions for externally hosted images found on a warmed page.
	 *
	 * The crawl already has the HTML in hand, so harvesting image URLs from it
	 * costs nothing extra — and this is the one place where paying for a
	 * remote lookup is free of consequence, because no visitor is waiting.
	 *
	 * An image on another domain has no local file to measure, so the front
	 * end skips it and the page ships without width/height — which is layout
	 * shift, on precisely the sites least able to fix it by hand (a CDN, a
	 * sister site, a shared asset host). Warming here means the NEXT render
	 * finds the dimensions in cache and stamps them, with the visitor paying
	 * nothing.
	 *
	 * Deliberately bounded per page: a crawl should not turn into a scraper
	 * for a page embedding hundreds of third-party images.
	 *
	 * @param string $html The warmed page's HTML.
	 */
	private static function warm_remote_dimensions( string $html ): void {
		if ( '' === $html || ! class_exists( '\XSpeed\Lazy_Loader' ) ) {
			return;
		}

		$opts = Settings_Manager::get( 'lazy' );
		if ( empty( $opts['add_missing_dimensions'] ) ) {
			return;
		}

		if ( ! preg_match_all( '#<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\'][^>]*>#i', $html, $m, PREG_SET_ORDER ) ) {
			return;
		}

		$home    = wp_parse_url( home_url(), PHP_URL_HOST );
		$targets = array();
		foreach ( $m as $tag ) {
			// Only tags MISSING a dimension are worth resolving — one that
			// already declares both needs nothing.
			if ( preg_match( '#\bwidth\s*=#i', $tag[0] ) && preg_match( '#\bheight\s*=#i', $tag[0] ) ) {
				continue;
			}
			$src = $tag[1];
			if ( ! preg_match( '#^https?://#i', $src ) ) {
				continue;
			}
			$host = wp_parse_url( $src, PHP_URL_HOST );
			if ( ! $host || $host === $home ) {
				continue; // Local images already resolve from disk.
			}
			// Already resolved (or already known unresolvable) — looking it up
			// again costs a request and teaches us nothing. Skipping it is
			// also what makes the cap below advance: images appear in the same
			// DOM order every crawl, so a collector that did not skip would
			// re-pick the same first N for ever and never reach the rest.
			if ( Lazy_Loader::dimensions_known( $src ) ) {
				continue;
			}
			$targets[ $src ] = true;
			if ( count( $targets ) >= self::remote_dimension_limit() ) {
				break;
			}
		}

		if ( $targets ) {
			Lazy_Loader::warm_dimensions( array_keys( $targets ) );
		}
	}

	private static function mark_complete( array $state ): void {
		$state['running']     = false;
		$state['finished_at'] = time();
		$state['queue']       = array();
		set_transient( self::STATE_KEY, $state, self::STATE_TTL );

		Activity_Log::record(
			'preloader_completed',
			sprintf(
				'Preloader finished — %d/%d URLs warmed, %d error%s.',
				$state['processed'],
				$state['total'],
				count( $state['errors'] ),
				1 === count( $state['errors'] ) ? '' : 's'
			),
			empty( $state['errors'] ) ? Activity_Log::SUCCESS : Activity_Log::WARN
		);
	}

	/**
	 * Build the URL queue for a fresh crawl: parse the sitemap, follow
	 * nested indexes, drop excluded paths.
	 *
	 * @return string[]
	 */
	private static function resolve_queue( array $opts ): array {
		// Request-scoped statics: reset so a previous crawl in the same
		// process can't leak its verdict into this one.
		self::$last_sitemap_error = '';
		self::$last_sitemap_url   = '';
		self::$queue_source       = 'none';

		$sitemap = trim( (string) ( $opts['sitemap_url'] ?? '' ) );
		if ( '' === $sitemap ) {
			$sitemap = home_url( '/wp-sitemap.xml' );
		}

		$urls = self::fetch_sitemap_urls( $sitemap, 0 );
		$from = empty( $urls ) ? 'none' : 'sitemap';

		/*
		 * A missing sitemap must not disable the feature. Two very common
		 * setups produce one with no misconfiguration by the user:
		 * `blog_public = 0` (WordPress disables /wp-sitemap.xml outright,
		 * standard on staging and pre-launch sites), and an SEO plugin
		 * filtering `wp_sitemaps_enabled` to false while serving its own
		 * sitemap at a path we were never told about.
		 *
		 * Enumerate warmable URLs straight from the database instead. Only
		 * on a genuine fetch FAILURE — a sitemap that is reachable and
		 * legitimately empty is a real answer, and silently crawling
		 * something else would be worse than doing nothing. (#142)
		 */
		if ( empty( $urls ) && '' !== self::$last_sitemap_error ) {
			$urls = self::fallback_urls();
			$from = empty( $urls ) ? 'none' : 'fallback';
		}

		$cache_opts = Settings_Manager::get( 'cache' );
		$excluded   = is_array( $cache_opts['excluded_urls'] ?? null ) ? $cache_opts['excluded_urls'] : array();
		if ( ! empty( $excluded ) ) {
			$urls = array_filter(
				$urls,
				static function ( $u ) use ( $excluded ) {
					$path = (string) wp_parse_url( $u, PHP_URL_PATH );
					foreach ( $excluded as $needle ) {
						if ( '' !== $needle && false !== strpos( $path, (string) $needle ) ) {
							return false;
						}
					}
					return true;
				}
			);
		}

		// Dedup + cap at 5000 to bound the transient size on huge sites.
		$urls = array_values( array_unique( $urls ) );
		$urls = array_slice( $urls, 0, 5000 );

		/*
		 * Commit the verdict only now, AFTER the exclusion filter — the
		 * source describes what we ACTUALLY queued, not what we hoped to.
		 * Setting it earlier let a queue that the exclusions stripped to
		 * nothing still claim `fallback`, so the panel announced "Warmed
		 * from site content" over 0 URLs, and `crawlFailed` (which needs
		 * source !== 'fallback' at total 0) could never become true.
		 * One assignment fixes both. (QA F2/F3 on #155)
		 */
		self::$queue_source = empty( $urls ) ? 'none' : $from;

		return $urls;
	}

	/**
	 * Enumerate warmable URLs from the database, for sites whose sitemap
	 * can't be fetched. Deliberately modest in scope: the home page, then
	 * the most recently modified public posts across every public post type.
	 * Newest-first is the right bias — those are the URLs most likely to be
	 * requested and least likely to be warm already.
	 *
	 * Uses WP_Query rather than SQL so post-type registration, status
	 * handling and multisite switching all behave the way the rest of
	 * WordPress does.
	 *
	 * @return string[]
	 */
	private static function fallback_urls(): array {
		$urls = array();
		$home = (string) home_url( '/' );
		if ( '' !== trim( $home, '/' ) ) {
			$urls[] = $home;
		}

		$types = get_post_types(
			array(
				'public'             => true,
				'publicly_queryable' => true,
			)
		);
		// `page` is public but not publicly_queryable, so the query above
		// misses it — and pages are exactly what a warm cache wants most.
		// Only add it when the site has post types at all: an empty list
		// means there is nothing to enumerate, and constructing a WP_Query
		// for it would be wasted work.
		if ( ! empty( $types ) ) {
			$types['page'] = 'page';
			unset( $types['attachment'] );
		}

		if ( empty( $types ) || ! class_exists( '\WP_Query' ) ) {
			return $urls;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => array_values( $types ),
				'post_status'            => 'publish',
				'posts_per_page'         => self::FALLBACK_LIMIT,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'fields'                 => 'ids',
			)
		);

		foreach ( $query->posts as $post_id ) {
			$permalink = get_permalink( (int) $post_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$urls[] = $permalink;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Recursive sitemap parser. Depth-limited to 3 so a maliciously
	 * deep index can't stack-overflow.
	 */
	private static function fetch_sitemap_urls( string $sitemap_url, int $depth ): array {
		if ( $depth > 3 ) {
			return array();
		}
		$res = wp_remote_get(
			$sitemap_url,
			array(
				'timeout'    => self::REQUEST_TIMEOUT,
				'sslverify'  => false,
				'user-agent' => self::USER_AGENT,
			)
		);
		if ( is_wp_error( $res ) ) {
			// Record WHY, don't just vanish. "Unreachable" and "valid but
			// empty" both used to collapse into an empty array here, which is
			// what made a sitemap-less site look like a successful crawl of
			// zero URLs. Only the top-level fetch is recorded: a nested index
			// failing is a partial result, not a dead crawl. (#142)
			if ( 0 === $depth ) {
				self::$last_sitemap_error = sprintf(
					/* translators: 1: sitemap URL, 2: error detail. */
					__( 'Could not fetch the sitemap at %1$s — %2$s', 'xspeed' ),
					$sitemap_url,
					$res->get_error_message()
				);
				self::$last_sitemap_url = (string) $sitemap_url;
			}
			return array();
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 400 ) {
			if ( 0 === $depth ) {
				self::$last_sitemap_error = sprintf(
					/* translators: 1: sitemap URL, 2: HTTP status code. */
					__( 'Could not fetch the sitemap at %1$s — the server returned HTTP %2$d.', 'xspeed' ),
					$sitemap_url,
					$code
				);
				self::$last_sitemap_url = (string) $sitemap_url;
			}
			return array();
		}
		$body = (string) wp_remote_retrieve_body( $res );
		if ( '' === $body ) {
			return array();
		}

		$urls = array();
		// Sitemap index → recurse.
		if ( false !== strpos( $body, '<sitemapindex' ) ) {
			if ( preg_match_all( '#<loc>([^<]+)</loc>#i', $body, $matches ) ) {
				foreach ( $matches[1] as $child ) {
					$urls = array_merge( $urls, self::fetch_sitemap_urls( trim( $child ), $depth + 1 ) );
				}
			}
			return $urls;
		}
		// URL set → collect.
		if ( preg_match_all( '#<loc>([^<]+)</loc>#i', $body, $matches ) ) {
			foreach ( $matches[1] as $u ) {
				$u = trim( $u );
				if ( '' !== $u && false !== filter_var( $u, FILTER_VALIDATE_URL ) ) {
					$urls[] = $u;
				}
			}
		}
		return $urls;
	}

	/**
	 * Apply the user's schedule choice. Called on settings change.
	 * Manual = no cron schedule (user must hit "Start now" to crawl).
	 */
	public static function apply_schedule( string $schedule ): void {
		wp_clear_scheduled_hook( 'xspeed_preloader_recurring' );
		if ( in_array( $schedule, array( 'hourly', 'daily', 'weekly' ), true ) ) {
			if ( ! wp_next_scheduled( 'xspeed_preloader_recurring' ) ) {
				wp_schedule_event( time() + 60, $schedule, 'xspeed_preloader_recurring' );
			}
		}
	}

	/**
	 * Recurring schedule hook handler — fires per the user's chosen
	 * cadence and kicks off a fresh crawl unless one is already running.
	 */
	public static function recurring_kickoff(): void {
		$state = self::status();
		if ( $state['running'] ) {
			return;
		}
		self::start();
	}
}
