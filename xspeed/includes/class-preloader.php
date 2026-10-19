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

	/**
	 * Seconds without a sign of life before a running crawl counts as
	 * stalled. A tick books the next one 10 seconds out, and a running batch
	 * stamps `last_tick_at` at least once a minute (see tick()), so only a
	 * crawl whose tick chain broke goes this long without one.
	 */
	public const STALL_AFTER    = 600;
	/**
	 * User-agent for every request the preloader makes.
	 *
	 * Deliberately contains no substring from the 7G/8G bad-bot lists. The
	 * previous value, "xSpeed-Preloader/1.0", matched the `loader` token in
	 * the alphabetical slice `(linkscan|linkwalker|loader|lwp-download|...)`
	 * — a match on "Pre*loader*" — so nginx ports of 8G answered every warm
	 * with 403 and newly published posts were never warmed. Upstream 8G
	 * v1.5 has since dropped `loader`, but forks and vendored copies (xCloud
	 * among them) still ship the older slice, so the name has to stay clear
	 * of it. "Warmer" matches nothing in either list. (#481)
	 *
	 * Read through user_agent() rather than using this constant directly, so
	 * the `xspeed_preloader_user_agent` filter applies.
	 */
	public const USER_AGENT     = 'xSpeed-Warmer/1.0 (+cache warmer; admin-initiated)';
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
	 * The user-agent every preloader request sends.
	 *
	 * Filterable because the blocking rule lives on the server, not here: a
	 * host with its own bad-bot list can clear a warm without patching the
	 * plugin or waiting for a release. An empty filter return is ignored —
	 * sending no UA gets a request blocked at least as often. (#481)
	 */
	public static function user_agent(): string {
		/**
		 * Filter the preloader's user-agent string.
		 *
		 * @param string $user_agent Default self::USER_AGENT.
		 */
		$ua = apply_filters( 'xspeed_preloader_user_agent', self::USER_AGENT );

		return ( is_string( $ua ) && '' !== trim( $ua ) ) ? trim( $ua ) : self::USER_AGENT;
	}

	/**
	 * A real phone browser's user-agent, which the warmer's own is appended
	 * to for the phone copy.
	 *
	 * A real phone string rather than the warmer's UA plus a client hint:
	 * a theme or plugin that reads the user-agent itself (Mobile_Detect and
	 * the like) instead of calling wp_is_mobile() would otherwise render its
	 * desktop HTML into the phone copy. The warmer's name stays at the end so
	 * the request is still recognisable in access logs and still matches no
	 * bad-bot rule. (#596)
	 */
	public const MOBILE_UA_PREFIX = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

	/** The user-agent the phone warm sends. */
	public static function mobile_user_agent(): string {
		$default = self::MOBILE_UA_PREFIX . ' ' . self::user_agent();
		/**
		 * Filter the user-agent the preloader sends when it warms the phone
		 * copy of a page (Separate Mobile Cache on).
		 *
		 * It must still read as a phone: the cache files the response by the
		 * same test as wp_is_mobile(). An empty return is ignored.
		 *
		 * @param string $user_agent Default: an iPhone Safari string followed by the warmer's user-agent.
		 */
		$ua = apply_filters( 'xspeed_preloader_mobile_user_agent', $default );

		return ( is_string( $ua ) && '' !== trim( $ua ) ) ? trim( $ua ) : $default;
	}

	/**
	 * An Android tablet's Chrome user-agent, which the warmer's own is
	 * appended to for the tablet copy. It has no "Mobile" token, so the
	 * tablet pattern matches it, as it matches a real Android tablet.
	 */
	public const TABLET_UA_PREFIX = 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

	/** The user-agent the tablet warm sends. */
	public static function tablet_user_agent(): string {
		$default = self::TABLET_UA_PREFIX . ' ' . self::user_agent();
		/**
		 * Filter the user-agent the preloader sends when it warms the tablet
		 * copy of a page (Separate tablet cache on).
		 *
		 * It must still read as a tablet: the cache files the response by
		 * its tablet pattern (Cache_Devices::TABLET_UA_REGEX), which a
		 * client hint cannot override. An empty return is ignored.
		 *
		 * @param string $user_agent Default: an Android tablet Chrome string followed by the warmer's user-agent.
		 */
		$ua = apply_filters( 'xspeed_preloader_tablet_user_agent', $default );

		return ( is_string( $ua ) && '' !== trim( $ua ) ) ? trim( $ua ) : $default;
	}

	/**
	 * How many pages of a crawl, from the top of the queue, also get a
	 * tablet warm. 0 means every page.
	 */
	public static function tablet_page_limit(): int {
		/**
		 * Filter how many pages of a crawl get their tablet copy warmed.
		 *
		 * The queue is in sitemap order, so a limit of N warms the tablet
		 * copy of the first N pages and leaves the rest to fill on their
		 * first tablet visit. Single-page warms (a post published, a
		 * comment approved) always include the tablet copy. 0, the default,
		 * means every page; a negative value counts as 0.
		 *
		 * @param int $limit Default 0.
		 */
		return max( 0, (int) apply_filters( 'xspeed_preloader_tablet_pages', 0 ) );
	}

	/**
	 * The copies of a page a warm fills: the desktop one, the phone one too
	 * when Separate Mobile Cache keeps one per device, and the tablet one
	 * last when Separate tablet cache is on as well. Before #596 every warm
	 * sent a desktop user-agent, so the first phone visitor to each page got
	 * an uncached render.
	 *
	 * @return string[] 'desktop', then 'mobile' and 'tablet' when they apply.
	 */
	public static function devices(): array {
		$cache   = Settings_Manager::get( 'cache' );
		$cache   = is_array( $cache ) ? $cache : array();
		$devices = Cache_Devices::split( $cache )
			? array_values( array_intersect( Cache_Devices::warm_order(), Cache_Devices::active( $cache ) ) )
			: array( Cache_Devices::DESKTOP );
		/**
		 * Filter which copies of a page the preloader warms.
		 *
		 * Return array( 'desktop' ) to skip the phone and tablet copies, for
		 * example on a host where the extra requests cost too much. Unknown
		 * values are dropped, and so is 'tablet' while tablets have no copy
		 * of their own; an empty list means desktop.
		 *
		 * @param string[] $devices 'desktop', plus 'mobile' when Separate Mobile Cache is on, plus 'tablet' when Separate tablet cache is on.
		 */
		$filtered = apply_filters( 'xspeed_preloader_devices', $devices );
		$allowed  = Cache_Devices::tablet_split( $cache )
			? Cache_Devices::warm_order()
			: array_values( array_diff( Cache_Devices::warm_order(), array( Cache_Devices::TABLET ) ) );
		$filtered = is_array( $filtered ) ? array_values( array_intersect( $allowed, $filtered ) ) : $devices;

		return empty( $filtered ) ? array( Cache_Devices::DESKTOP ) : $filtered;
	}

	/**
	 * Request arguments for one warm of one device copy.
	 *
	 * `Sec-CH-UA-Mobile` settles the device whatever the user-agent filters
	 * return: the cache checks that hint before the user-agent.
	 *
	 * @param array<string,string> $headers Extra request headers.
	 */
	private static function warm_args( string $device, array $headers = array() ): array {
		$headers['Sec-CH-UA-Mobile'] = Cache_Devices::MOBILE === $device ? '?1' : '?0';
		if ( Cache_Devices::MOBILE === $device ) {
			$user_agent = self::mobile_user_agent();
		} elseif ( Cache_Devices::TABLET === $device ) {
			$user_agent = self::tablet_user_agent();
		} else {
			$user_agent = self::user_agent();
		}
		return array(
			'timeout'    => self::REQUEST_TIMEOUT,
			'sslverify'  => false,
			'user-agent' => $user_agent,
			'headers'    => Self_Traffic::headers( $headers ),
			'blocking'   => true,
		);
	}

	/**
	 * Warm every copy of $url that devices() names.
	 *
	 * @param array<string,string> $headers     Extra request headers.
	 * @param bool                 $with_tablet False skips the tablet copy (past tablet_page_limit()).
	 * @return array{failures: array<int,array{device:string,error:string}>, body: string}
	 *         The failures, and the desktop response body ('' when it failed).
	 */
	private static function warm_devices( string $url, array $headers = array(), bool $with_tablet = true ): array {
		$failures = array();
		$body     = '';
		foreach ( self::devices() as $device ) {
			if ( ! $with_tablet && Cache_Devices::TABLET === $device ) {
				continue;
			}
			$args     = self::warm_args( $device, $headers );
			$response = wp_remote_get( $url, $args );
			if ( is_wp_error( $response ) ) {
				$failures[] = array( 'device' => $device, 'error' => $response->get_error_message() );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 400 ) {
				$failures[] = array( 'device' => $device, 'error' => self::failure_detail( $code, $args['user-agent'] ) );
				if ( self::is_firewall_block( $code ) ) {
					self::remember_firewall_block( $url, $code, $args['user-agent'] );
				}
				continue;
			}
			if ( 'desktop' === $device ) {
				$body = (string) wp_remote_retrieve_body( $response );
			}
		}
		// The notice goes only once every copy got through. A host that
		// blocks just the phone user-agent would otherwise have the notice
		// cleared by each desktop warm right after the phone warm set it.
		if ( empty( $failures ) ) {
			self::clear_firewall_block();
		}
		return array( 'failures' => $failures, 'body' => $body );
	}

	/** "phone: " or "tablet: " for a failure of that copy, so the log says which copy failed. */
	private static function device_prefix( string $device ): string {
		if ( Cache_Devices::MOBILE === $device ) {
			return 'phone: ';
		}
		return Cache_Devices::TABLET === $device ? 'tablet: ' : '';
	}

	/**
	 * Is this status code the signature of a firewall refusing our warmer?
	 *
	 * 403 and 406 are what bad-bot rules (7G/8G, mod_security, Wordfence)
	 * answer with. We only ever warm our OWN origin, and a page a visitor can
	 * load must be loadable by us too — so these codes mean the request was
	 * judged by its user-agent, not that the page is missing or broken. (#481)
	 */
	private static function is_firewall_block( int $code ): bool {
		return in_array( $code, array( 403, 406 ), true );
	}

	/**
	 * Explain a warm failure in terms the admin can act on.
	 *
	 * A bare "HTTP 403" sent people hunting a broken page; the page is fine,
	 * and the fix is a server rule, so the message has to name the cause and
	 * the exact UA to allow. (#481)
	 */
	private static function failure_detail( int $code, string $user_agent = '' ): string {
		if ( ! self::is_firewall_block( $code ) ) {
			return sprintf( 'HTTP %d', $code );
		}

		return sprintf(
			'HTTP %d — your server\'s firewall is blocking the xSpeed cache warmer by user-agent, so this page was not warmed. Allow the user-agent "%s" (on xCloud this is the 8G firewall\'s bad-bot rule), or change it with the xspeed_preloader_user_agent filter.',
			$code,
			'' !== $user_agent ? $user_agent : self::user_agent()
		);
	}

	/** Option holding the last firewall-shaped warm refusal. */
	public const FIREWALL_BLOCK_OPTION = 'xspeed_preloader_firewall_block';

	/**
	 * Record that the origin refused a warm by user-agent, for ui_notices().
	 *
	 * An option rather than a transient: the condition is a server rule that
	 * persists until someone changes it, and a notice that expired on its own
	 * would let a site go back to never warming, silently. Cleared by
	 * clear_firewall_block() on the first warm that succeeds. (#481)
	 */
	private static function remember_firewall_block( string $url, int $code, string $user_agent = '' ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}
		update_option(
			self::FIREWALL_BLOCK_OPTION,
			array(
				'url'        => $url,
				'code'       => $code,
				'user_agent' => '' !== $user_agent ? $user_agent : self::user_agent(),
				'ts'         => time(),
			),
			false
		);
	}

	/** Forget the firewall block once a warm gets through. */
	public static function clear_firewall_block(): void {
		if ( function_exists( 'delete_option' ) && self::firewall_block() ) {
			delete_option( self::FIREWALL_BLOCK_OPTION );
		}
	}

	/** The last firewall-shaped refusal, or null when there isn't one. */
	public static function firewall_block(): ?array {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}
		$block = get_option( self::FIREWALL_BLOCK_OPTION, null );

		return ( is_array( $block ) && ! empty( $block['code'] ) ) ? $block : null;
	}

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

	/**
	 * URLs left out of the current crawl, counted by reason: `post_type`
	 * (in the sitemap of an excluded post type), `excluded_url` (matches a
	 * cache exclusion), `filtered` (removed by the xspeed_preloader_queue or
	 * xspeed_preloader_skip_url filter) and `over_cap` (past the 5000-URL
	 * cap). Reported beside the queued total so a partial crawl never reads
	 * as "covered everything". (#239)
	 *
	 * @var array<string,int>
	 */
	private static $skipped = array();

	/** Largest number of URLs the database fallback will enumerate. */
	private const FALLBACK_LIMIT = 500;

	/** Largest queue a crawl will hold, to bound the state transient. */
	private const QUEUE_CAP = 5000;

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
			'last_tick_at'  => time(),
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
			'skipped'       => self::$skipped,
			// Which copies each URL gets, for the panel and the status
			// command. Each tick refreshes it: turning Separate Mobile
			// Cache on or off mid-crawl purges the cache, and the rest of
			// the crawl should fill the copies that now exist.
			'devices'       => self::devices(),
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
		$skipped_total = array_sum( self::$skipped );
		if ( $skipped_total > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of URLs left out of the crawl. */
				__( 'Skipped %d (excluded post types, cache exclusions, filters or the 5000-URL cap).', 'xspeed' ),
				$skipped_total
			);
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
			'running'      => false,
			'started_at'   => 0,
			'last_tick_at' => 0,
			'finished_at'  => 0,
			'queue'        => array(),
			'processed'    => 0,
			'total'        => 0,
			'last_url'     => '',
			'errors'       => array(),
			'skipped'      => array(),
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

		// Stamp the tick before the batch runs, so a slow batch is not
		// mistaken for a stalled crawl while it is still warming.
		$state['last_tick_at'] = time();
		set_transient( self::STATE_KEY, $state, self::STATE_TTL );

		$opts  = Settings_Manager::get( 'preloader' );
		$batch = max( 1, min( 50, (int) ( $opts['batch_size'] ?? 5 ) ) );

		/**
		 * Filter: xspeed_preload_batch_size
		 *
		 * How much one tick may warm, in the units the batch size setting
		 * counts. The setting is the site owner's
		 * intent; this is for anything that knows a lower ceiling applies —
		 * a CDN with a rate limit in front, say, which will answer a burst
		 * with a challenge and leave the queue looking warmed when it is not.
		 *
		 * Only ever lowers. A filter that raised it would let an add-on
		 * overrule a number the site owner chose, and the reason to reach for
		 * this is always that something cannot take the current rate.
		 *
		 * @param int $batch The batch this tick would otherwise use.
		 */
		$ceiling = (int) apply_filters( 'xspeed_preload_batch_size', $batch );
		if ( $ceiling > 0 && $ceiling < $batch ) {
			$batch = $ceiling;
		}

		// batch_size caps requests, not URLs, so the load on the origin per
		// tick is the same with the phone copy on: each URL costs one request
		// per device. A tick always warms at least one URL.
		$devices          = self::devices();
		$state['devices'] = $devices;
		$per_url          = count( $devices );
		$requests         = 0;
		while ( ! empty( $state['queue'] ) && ( 0 === $requests || $requests + $per_url <= $batch ) ) {
			$url = (string) array_shift( $state['queue'] );
			self::warm_url( $url, $state );
			$state['processed']++;
			$state['last_url'] = $url;
			$requests         += $per_url;

			// A heartbeat while the batch runs. One URL can take close to two
			// minutes (its page plus up to 20 remote image lookups), so a big
			// batch outlasted STALL_AFTER, and a status read then booked a
			// second tick for the same URLs. (#756)
			if ( time() - (int) $state['last_tick_at'] >= MINUTE_IN_SECONDS ) {
				$state['last_tick_at'] = time();
				set_transient( self::STATE_KEY, $state, self::STATE_TTL );
			}
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
		Cache_GC::expire_static_url( $url );
		$result = self::warm_devices( $url );
		foreach ( $result['failures'] as $failure ) {
			Activity_Log::record(
				'preloader_warm_failed',
				sprintf( 'Warm %s failed (%s): %s%s', $cause, $url, self::device_prefix( $failure['device'] ), $failure['error'] ),
				Activity_Log::WARN
			);
		}
		if ( ! empty( $result['failures'] ) ) {
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
		// The web server would answer with an expired static page as a HIT
		// and nothing would be rebuilt. (#645)
		Cache_GC::expire_static_url( $url );

		$limit  = self::tablet_page_limit();
		$result = self::warm_devices(
			$url,
			array( 'Accept' => 'text/html,application/xhtml+xml' ),
			0 === $limit || (int) ( $state['processed'] ?? 0 ) < $limit
		);
		foreach ( $result['failures'] as $failure ) {
			$state['errors'][] = array(
				'url'   => $url,
				'error' => self::device_prefix( $failure['device'] ) . $failure['error'],
				'ts'    => time(),
			);
		}
		// Cap retained errors so a broken sitemap doesn't blow the
		// transient size.
		$state['errors'] = array_slice( $state['errors'], -20 );
		if ( '' !== $result['body'] ) {
			self::warm_remote_dimensions( $result['body'] );
		}
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

		// Match any <img>, not only one carrying `src`. The URL worth warming
		// may live in a lazy attribute instead — which is the whole point of
		// #328 — and resolvable_image_url() below is what knows where to look.
		if ( ! preg_match_all( '#<img\b[^>]*>#i', $html, $m, PREG_SET_ORDER ) ) {
			return;
		}

		$home    = wp_parse_url( home_url(), PHP_URL_HOST );
		$targets = array();
		foreach ( $m as $tag ) {
			// Only tags MISSING a dimension are worth resolving — one that
			// already declares both needs nothing.
			// Same lookbehind as Lazy_Loader::ensure_dimensions(): a bare
			// `\bwidth=` also matches `data-width=`, so a slider carrying its
			// own metadata looked already-sized and was skipped from warming.
			// The two must agree, or the collector skips exactly the tags the
			// renderer still needs measured. (#333 review round 3, issue 2)
			if ( preg_match( '#(?<![-\w])width\s*=#i', $tag[0] ) && preg_match( '#(?<![-\w])height\s*=#i', $tag[0] ) ) {
				continue;
			}
			// Ask the same resolver the render path uses, rather than reading
			// `src` directly. A slider parks a spacer in `src` and the real
			// URL in `data-lazy`/`data-src`/`data-original`, so a collector
			// looking only at `src` warmed the SPACER and never the image —
			// leaving remotely-hosted slider images unresolvable at render
			// time, the exact markup #328 is about. (#333 review round 2,
			// issue 3)
			// `false`: do not let the resolver settle a name-refused URL by
			// MEASURING it. That is circular here — remote measurement is
			// gated until warm_dimensions() sets $warming, and this collector
			// is what feeds warm_dimensions(). Take the URL the tag offers and
			// let the warm pass decide. (#333 review round 3, issue 3)
			$src = Lazy_Loader::resolvable_image_url( $tag[0], false );
			if ( '' === $src || ! preg_match( '#^https?://#i', $src ) ) {
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
	 * nested indexes, drop excluded post types and paths, then let site code
	 * trim the list.
	 *
	 * @return string[]
	 */
	private static function resolve_queue( array $opts ): array {
		// Request-scoped statics: reset so a previous crawl in the same
		// process can't leak its verdict into this one.
		self::$last_sitemap_error = '';
		self::$last_sitemap_url   = '';
		self::$queue_source       = 'none';
		self::$skipped            = array();

		$sitemap = trim( (string) ( $opts['sitemap_url'] ?? '' ) );
		if ( '' === $sitemap ) {
			$sitemap = self::default_sitemap_url();
		}

		$skip_types = self::excluded_post_types( $opts );

		$urls = self::fetch_sitemap_urls( $sitemap, 0, $skip_types );
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
			$urls = self::fallback_urls( $skip_types );
			$from = empty( $urls ) ? 'none' : 'fallback';
		}

		$cache_opts = Settings_Manager::get( 'cache' );
		$excluded   = is_array( $cache_opts['excluded_urls'] ?? null ) ? $cache_opts['excluded_urls'] : array();
		if ( ! empty( $excluded ) ) {
			$before = count( $urls );
			$urls   = array_filter(
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
			self::count_skipped( 'excluded_url', $before - count( $urls ) );
		}

		$urls = array_values( array_unique( $urls ) );

		/**
		 * Filter the preloader's queue before the crawl starts.
		 *
		 * For integrations that know which URLs can never be served from the
		 * cache, such as gated lessons that an anonymous visitor only sees as
		 * a login prompt. Return the URLs to keep.
		 *
		 * @since 1.3.6
		 * @param string[] $urls   The URLs about to be queued.
		 * @param string   $source Where they came from: 'sitemap', 'fallback' or 'none'.
		 */
		$before = count( $urls );
		$urls   = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'xspeed_preloader_queue', $urls, $from ) ), 'strlen' ) );
		self::count_skipped( 'filtered', $before - count( $urls ) );

		$kept = array();
		foreach ( $urls as $url ) {
			/**
			 * Veto one URL from the preloader's queue.
			 *
			 * @since 1.3.6
			 * @param bool   $skip True to leave the URL out of the crawl.
			 * @param string $url  The URL.
			 */
			if ( true === (bool) apply_filters( 'xspeed_preloader_skip_url', false, $url ) ) {
				self::count_skipped( 'filtered', 1 );
				continue;
			}
			$kept[] = $url;
		}
		$urls = array_values( array_unique( $kept ) );

		// Cap to bound the transient size on huge sites, and say so.
		self::count_skipped( 'over_cap', count( $urls ) - self::QUEUE_CAP );
		$urls = array_slice( $urls, 0, self::QUEUE_CAP );

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
	 * Where WordPress core serves its sitemap index on this site.
	 *
	 * `/wp-sitemap.xml` exists only through a rewrite rule. With plain
	 * permalinks the index is `/?sitemap=index`, and `/wp-sitemap.xml` is the
	 * home page, so a crawl read a page of HTML, found no URLs and queued
	 * nothing. get_sitemap_url() gives the right form for either setting.
	 */
	private static function default_sitemap_url(): string {
		$url = function_exists( 'get_sitemap_url' ) ? get_sitemap_url( 'index' ) : false;
		return is_string( $url ) && '' !== $url ? $url : home_url( '/wp-sitemap.xml' );
	}

	/** Add to one skip reason's count. Nothing to add is a no-op. */
	private static function count_skipped( string $reason, int $n ): void {
		if ( $n > 0 ) {
			self::$skipped[ $reason ] = ( self::$skipped[ $reason ] ?? 0 ) + $n;
		}
	}

	/**
	 * The post types this crawl leaves out, from the `excluded_post_types`
	 * setting.
	 *
	 * @return string[]
	 */
	private static function excluded_post_types( array $opts ): array {
		$types = is_array( $opts['excluded_post_types'] ?? null ) ? $opts['excluded_post_types'] : array();
		$types = array_map( 'sanitize_key', array_map( 'strval', $types ) );
		return array_values( array_unique( array_filter( $types, 'strlen' ) ) );
	}

	/**
	 * The post type a child sitemap lists, if its address says so and that
	 * type is in $types.
	 *
	 * Recognises the WordPress core names (`wp-sitemap-posts-<type>-<n>.xml`,
	 * or `?sitemap=posts&sitemap-subtype=<type>` without pretty permalinks)
	 * and the `<type>-sitemap<n>.xml` form that Yoast SEO, Rank Math,
	 * SEOPress and All in One SEO use. A sitemap named any other way is not
	 * recognised, so its URLs are still queued.
	 *
	 * @param string   $url   Child sitemap URL from a sitemap index.
	 * @param string[] $types Excluded post types.
	 */
	private static function excluded_child_sitemap( string $url, array $types ): string {
		if ( empty( $types ) ) {
			return '';
		}

		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $q );
			if ( 'posts' === ( $q['sitemap'] ?? '' ) && isset( $q['sitemap-subtype'] ) ) {
				$type = sanitize_key( (string) $q['sitemap-subtype'] );
				return in_array( $type, $types, true ) ? $type : '';
			}
		}

		$file = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( preg_match( '/^wp-sitemap-posts-(.+)-\d+\.xml$/i', $file, $m )
			|| preg_match( '/^(.+)-sitemap\d*\.xml$/i', $file, $m ) ) {
			$type = sanitize_key( $m[1] );
			return in_array( $type, $types, true ) ? $type : '';
		}

		return '';
	}

	/**
	 * URLs the crawl warms whatever post types are excluded: the home page,
	 * and the posts page when the site has one. They are usually the most
	 * visited URLs on the site, and neither is what a post-type exclusion
	 * is for.
	 *
	 * @return string[]
	 */
	private static function always_warmed_urls(): array {
		$urls = array();
		$home = (string) home_url( '/' );
		if ( '' !== trim( $home, '/' ) ) {
			$urls[] = $home;
		}
		$posts_page = self::posts_page_id();
		if ( $posts_page > 0 ) {
			$link = get_permalink( $posts_page );
			if ( is_string( $link ) && '' !== $link ) {
				$urls[] = $link;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/** A URL compared without its scheme or trailing slash. */
	private static function url_key( string $url ): string {
		return strtolower( rtrim( (string) preg_replace( '#^https?://#i', '', $url ), '/' ) );
	}

	/**
	 * @param string[] $urls
	 * @return array<string,true> url_key() => true.
	 */
	private static function url_keys( array $urls ): array {
		$keys = array();
		foreach ( $urls as $url ) {
			$keys[ self::url_key( $url ) ] = true;
		}
		return $keys;
	}

	/** The posts page's ID when the front page is a static page, else 0. */
	private static function posts_page_id(): int {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return 0;
		}
		return (int) get_option( 'page_for_posts' );
	}

	/**
	 * Enumerate warmable URLs from the database, for sites whose sitemap
	 * can't be fetched. Deliberately modest in scope: the home page and the
	 * posts page (see always_warmed_urls()), then the most recently modified
	 * public posts across every public post type.
	 * Newest-first is the right bias — those are the URLs most likely to be
	 * requested and least likely to be warm already.
	 *
	 * Uses WP_Query rather than SQL so post-type registration, status
	 * handling and multisite switching all behave the way the rest of
	 * WordPress does.
	 *
	 * @param string[] $skip_types Post types to leave out.
	 * @return string[]
	 */
	private static function fallback_urls( array $skip_types = array() ): array {
		$urls = self::always_warmed_urls();

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
			foreach ( $skip_types as $skip ) {
				if ( ! isset( $types[ $skip ] ) ) {
					continue;
				}
				unset( $types[ $skip ] );
				// Counted, so the fallback reports what it left out just as
				// the sitemap path does. Published posts of that type: the
				// ones the query would otherwise have considered.
				$counts = function_exists( 'wp_count_posts' ) ? wp_count_posts( $skip ) : null;
				$kept   = 0;
				// The static front page and the posts page are warmed
				// anyway, so they are not skipped.
				foreach ( array( 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0, self::posts_page_id() ) as $id ) {
					if ( $id > 0 && get_post_type( $id ) === $skip && 'publish' === get_post_status( $id ) ) {
						++$kept;
					}
				}
				self::count_skipped( 'post_type', max( 0, (int) ( $counts->publish ?? 0 ) - $kept ) );
			}
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

		foreach ( (array) $query->posts as $post_id ) {
			$permalink = get_permalink( (int) $post_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$urls[] = $permalink;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * The `<loc>` values of a sitemap, as URLs.
	 *
	 * A sitemap is XML, so `&` is written `&amp;`. WordPress's own sitemap
	 * index does that on every site with plain permalinks
	 * (`?sitemap=posts&amp;sitemap-subtype=post`), and reading the text
	 * as-is fetched a child sitemap WordPress could not route, so the crawl
	 * queued only a handful of a site's pages. A value wrapped in CDATA is
	 * unwrapped. Characters outside ASCII are percent-encoded, the form a
	 * URL takes on the wire, so `caf&#xE9;` is not rejected as invalid.
	 * (#792)
	 *
	 * @param string $body Sitemap XML.
	 * @return string[]
	 */
	private static function sitemap_locs( string $body ): array {
		if ( ! preg_match_all( '#<loc>\s*(?:<!\[CDATA\[((?:(?!\]\]>|</loc>).)*)\]\]>|([^<]*))\s*</loc>#is', $body, $matches, PREG_SET_ORDER ) ) {
			return array();
		}
		$out = array();
		foreach ( $matches as $m ) {
			$value = isset( $m[2] ) && '' !== $m[2] ? html_entity_decode( $m[2], ENT_QUOTES | ENT_XML1, 'UTF-8' ) : (string) $m[1];
			$value = (string) preg_replace_callback(
				'/[^\x00-\x7F]/',
				static function ( array $c ): string {
					return rawurlencode( $c[0] );
				},
				trim( $value )
			);
			if ( '' !== $value && false !== filter_var( $value, FILTER_VALIDATE_URL ) ) {
				$out[] = $value;
			}
		}
		return $out;
	}

	/**
	 * Recursive sitemap parser. Depth-limited to 3 so a maliciously
	 * deep index can't stack-overflow.
	 *
	 * A child sitemap for an excluded post type is still read, so its URLs
	 * can be counted as skipped, but none of them are returned.
	 *
	 * @param string[] $skip_types Excluded post types.
	 */
	private static function fetch_sitemap_urls( string $sitemap_url, int $depth, array $skip_types = array() ): array {
		if ( $depth > 3 ) {
			return array();
		}
		$res = wp_remote_get(
			$sitemap_url,
			array(
				'timeout'    => self::REQUEST_TIMEOUT,
				'sslverify'  => false,
				'user-agent' => self::user_agent(),
				'headers'    => Self_Traffic::headers(),
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

		// A 200 that is not a sitemap (a theme's home page, a login wall) is a
		// failed fetch, not an empty site. Recorded like one, so the crawl
		// falls back to the site's content instead of queuing nothing.
		if ( 0 === $depth && false === stripos( $body, '<urlset' ) && false === stripos( $body, '<sitemapindex' ) ) {
			self::$last_sitemap_error = sprintf(
				/* translators: %s: sitemap URL. */
				__( 'The address %s did not return a sitemap.', 'xspeed' ),
				$sitemap_url
			);
			self::$last_sitemap_url = (string) $sitemap_url;
			return array();
		}

		$urls = array();
		// Sitemap index → recurse.
		if ( false !== strpos( $body, '<sitemapindex' ) ) {
			foreach ( self::sitemap_locs( $body ) as $child ) {
				$child_urls = self::fetch_sitemap_urls( $child, $depth + 1, $skip_types );
				if ( '' !== self::excluded_child_sitemap( $child, $skip_types ) ) {
					// The home page and the posts page stay in the crawl
					// whatever is excluded. Core lists a latest-posts home
					// page in the page sitemap, so excluding Pages used to
					// drop the home page with them.
					$always = self::url_keys( self::always_warmed_urls() );
					$kept   = array_values(
						array_filter(
							$child_urls,
							static function ( $u ) use ( $always ) {
								return isset( $always[ self::url_key( (string) $u ) ] );
							}
						)
					);
					self::count_skipped( 'post_type', count( $child_urls ) - count( $kept ) );
					$urls = array_merge( $urls, $kept );
					continue;
				}
				$urls = array_merge( $urls, $child_urls );
			}
			return $urls;
		}
		// URL set → collect.
		foreach ( self::sitemap_locs( $body ) as $u ) {
			$urls[] = $u;
		}
		return $urls;
	}

	/**
	 * Apply the user's schedule choice. Called on settings change.
	 * Manual = no cron schedule (user must hit "Start now" to crawl).
	 */
	public static function apply_schedule( string $schedule ): void {
		$wanted = in_array( $schedule, array( 'hourly', 'daily', 'weekly' ), true ) ? $schedule : '';

		// Every Preloader save lands here. Rebooking an unchanged schedule
		// moved the crawl to the time of the save, so a batch-size edit at
		// 23:52 turned a 21:48 daily crawl into a 23:53 one. (#757)
		if ( $wanted === (string) wp_get_schedule( 'xspeed_preloader_recurring' ) ) {
			return;
		}

		wp_clear_scheduled_hook( 'xspeed_preloader_recurring' );
		if ( '' !== $wanted ) {
			wp_schedule_event( time() + 60, $wanted, 'xspeed_preloader_recurring' );
		}
	}

	/**
	 * Recurring schedule hook handler — fires per the user's chosen
	 * cadence and kicks off a fresh crawl unless one is already running.
	 */
	public static function recurring_kickoff(): void {
		$state = self::status();
		if ( $state['running'] ) {
			if ( ! self::is_stalled( $state ) ) {
				Activity_Log::record(
					'preloader_skipped',
					sprintf(
						/* translators: 1: URLs warmed so far, 2: URLs in the crawl. */
						__( 'Scheduled preload skipped: a crawl is still running (%1$d/%2$d URLs warmed).', 'xspeed' ),
						$state['processed'],
						$state['total']
					),
					Activity_Log::INFO
				);
				return;
			}
			// The queue may be hours old by now, so start over rather than
			// finish it.
			Activity_Log::record(
				'preloader_restarted',
				sprintf(
					/* translators: 1: URLs warmed, 2: URLs in the crawl. */
					__( 'Preloader restarted a crawl that had stopped moving (%1$d/%2$d URLs warmed).', 'xspeed' ),
					$state['processed'],
					$state['total']
				),
				Activity_Log::WARN
			);
		}
		self::start();
	}

	/**
	 * A crawl is stalled when it says `running`, has URLs left, has no tick
	 * booked, and has not ticked for STALL_AFTER seconds.
	 *
	 * Each tick books the next one, and WP-Cron removes an event before it
	 * runs the callback. A tick that comes due while a plugin update has
	 * the callback unregistered is used up, and the chain ends with the
	 * state still `running`. That blocked every scheduled crawl for up to
	 * STATE_TTL. (#756)
	 */
	public static function is_stalled( array $state ): bool {
		if ( empty( $state['running'] ) || empty( $state['queue'] ) ) {
			return false;
		}
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return false;
		}
		$last = max( (int) ( $state['last_tick_at'] ?? 0 ), (int) ( $state['started_at'] ?? 0 ) );
		return time() - $last >= self::STALL_AFTER;
	}

	/**
	 * Book a tick for a stalled crawl so it finishes. Status reads call
	 * this, so opening the Preloader panel or asking for status is enough
	 * to get a stuck crawl moving again.
	 *
	 * @return bool True when a tick was booked.
	 */
	public static function resume_if_stalled(): bool {
		$state = self::status();
		if ( ! self::is_stalled( $state ) ) {
			return false;
		}
		// A second status read in the same moment loses WP-Cron's duplicate
		// check, and a refused booking resumed nothing.
		if ( true !== wp_schedule_single_event( time(), self::CRON_HOOK ) ) {
			return false;
		}
		Activity_Log::record(
			'preloader_resumed',
			sprintf(
				/* translators: 1: URLs warmed, 2: URLs in the crawl. */
				__( 'Preloader resumed a crawl that had stopped moving (%1$d/%2$d URLs warmed).', 'xspeed' ),
				$state['processed'],
				$state['total']
			),
			Activity_Log::WARN
		);
		return true;
	}
}
