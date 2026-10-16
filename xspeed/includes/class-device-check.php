<?php
/**
 * Does the site send phones different HTML?
 *
 * The answer decides whether Separate Mobile Cache is needed. Comparing two
 * fetches of a page cannot answer it reliably: rotating content (a random
 * testimonial, an ad), per-render ids and layouts that change on every load
 * all make the desktop and the phone page differ when the device has nothing
 * to do with it, and no number of extra fetches removes that doubt (#618 QA).
 *
 * So this asks the site instead. WordPress decides "is this a phone?" in
 * wp_is_mobile(), and its `wp_is_mobile` filter runs every time something
 * asks. A render in which nothing asked cannot have changed by device
 * through WordPress, so a difference in its HTML is noise.
 *
 * Two ways in:
 *  - compare(): two fetches of one page (desktop, phone), each marked with a
 *    token the render reports back on. The banner's check runs it on the home
 *    page.
 *  - Passive: every page WordPress renders anyway (a cache miss) notes
 *    whether it asked for the device. Pages that did are checked with
 *    compare() in the background, a few at a time and once each, so the
 *    whole site is covered without extra fetches for pages that never ask.
 *
 * Jetpack's device lookups (`jetpack_is_mobile()`, Device_Detection) run
 * filters too, and count as asking. A lookup made before this plugin loads
 * still counts: did_filter() keeps the count from the start of the request.
 *
 * Blind spot: code that reads the user-agent itself (the Mobile_Detect
 * library, WPtouch). When such code is loaded, compare() falls back to
 * comparing the HTML.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Device_Check {

	/** Query parameter that gives each probe fetch its own URL and token. */
	public const PARAM = 'xspeed_device_probe';

	/**
	 * Pages seen asking for the device: path => { seen, checked, differs }.
	 * Autoloaded: record() reads it on every uncached render that asked, and
	 * 50 short rows cost less than that extra query.
	 */
	public const PAGES_OPTION = 'xspeed_device_pages';

	/** Most pages kept. A theme that asks on every page fills this fast; 50 is enough to answer. */
	public const MAX_PAGES = 50;

	/** Background check of noted pages. */
	public const CRON_HOOK = 'xspeed_device_check_pages';

	/** Pages compared per background run: two fetches each. */
	public const PER_RUN = 3;

	/** Background tries for a page that gives no verdict before it is left unchecked. */
	public const MAX_TRIES = 3;

	/** Filters a device lookup runs: WordPress's, and Jetpack's two. */
	private const DEVICE_FILTERS = array( 'wp_is_mobile', 'pre_jetpack_is_mobile', 'jetpack_device_detection_get_info' );

	/** Seed for a probe render's random numbers, so both renders draw the same ones. */
	private const SEED = 595;

	/** Transient prefix for a probe fetch's token. */
	private const TOKEN_PREFIX = 'xspeed_dprobe_';

	/** Whether something asked for the device during this request (seen by the listener). */
	private static bool $asked = false;

	/** Device lookups made by this plugin itself, which are not the page asking. */
	private static int $own = 0;

	public static function boot(): void {
		// Last, so the value it sees is the one the caller gets.
		foreach ( self::DEVICE_FILTERS as $filter ) {
			add_filter( $filter, array( __CLASS__, 'note' ), PHP_INT_MAX );
		}
		add_action( 'shutdown', array( __CLASS__, 'record' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'check_pages' ) );
		// What the site does by device can change with its theme and
		// plugins; the notes start over and fill in again as pages render.
		foreach ( array( 'switch_theme', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'forget' ) );
		}
		// An edited or deleted page may no longer branch by device, and its
		// old verdict would keep the banner saying it does (#618 QA).
		add_action( 'save_post', array( __CLASS__, 'forget_post' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'forget_post' ) );
		// A probe render draws the same "random" content as its twin: a
		// random testimonial, shuffled posts or ORDER BY RAND() no longer
		// read as a device difference. Unseeded sources (random_int(), the
		// clock) are left to the second desktop fetch in compare().
		if ( '' !== self::probe_token() ) {
			mt_srand( self::SEED );
			add_filter( 'posts_orderby', array( __CLASS__, 'seeded_orderby' ), PHP_INT_MAX );
			// get_posts() turns query filters off, so posts_orderby never
			// sees the "random post" widget's query. pre_get_posts is an
			// action and runs either way (#618 QA).
			add_action( 'pre_get_posts', array( __CLASS__, 'seeded_rand_query' ), PHP_INT_MAX );
		}
	}

	/**
	 * `pre_get_posts` in a probe render: `orderby => rand` becomes RAND(seed),
	 * which WP_Query accepts as is.
	 *
	 * @param \WP_Query $query The query about to run.
	 */
	public static function seeded_rand_query( $query ): void {
		if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) ) {
			return;
		}
		$orderby = $query->get( 'orderby' );
		if ( is_string( $orderby ) && preg_match( '/\brand\b(?!\()/i', $orderby ) ) {
			$query->set( 'orderby', (string) preg_replace( '/\brand\b(?!\()/i', 'RAND(' . self::SEED . ')', $orderby ) );
		} elseif ( is_array( $orderby ) ) {
			$seeded = array();
			foreach ( $orderby as $key => $order ) {
				$seeded[ is_string( $key ) && 0 === strcasecmp( $key, 'rand' ) ? 'RAND(' . self::SEED . ')' : $key ] = $order;
			}
			$query->set( 'orderby', $seeded );
		}
	}

	/**
	 * Drop the note for a post's page.
	 *
	 * @param int $post_id Post saved or about to be deleted.
	 */
	public static function forget_post( $post_id ): void {
		if ( ! function_exists( 'get_permalink' ) ) {
			return;
		}
		$path  = self::published_path( $post_id );
		$pages = self::pages();
		if ( '' === $path || ! isset( $pages[ $path ] ) ) {
			return;
		}
		unset( $pages[ $path ] );
		update_option( self::PAGES_OPTION, $pages, true );
		delete_transient( 'xspeed_mobile_equality_probe' );
	}

	/**
	 * The path a post had while it was published. These hooks run after
	 * the status changed: a trashed post's permalink ends in `__trashed/`
	 * and a draft's is `?page_id=N`, and neither is the path that was
	 * noted. A copy marked as published gives the old address back.
	 *
	 * @param int|\WP_Post $post_id The post.
	 */
	private static function published_path( $post_id ): string {
		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		if ( ! is_object( $post ) ) {
			$link = get_permalink( (int) $post_id );
			return is_string( $link ) ? (string) wp_parse_url( $link, PHP_URL_PATH ) : '';
		}
		$post              = clone $post;
		$post->post_status = 'publish';
		$post->post_name   = (string) preg_replace( '/__trashed$/', '', (string) $post->post_name );
		$link              = get_permalink( $post );
		return is_string( $link ) ? (string) wp_parse_url( $link, PHP_URL_PATH ) : '';
	}

	/** `posts_orderby` in a probe render: RAND() gets the fixed seed. @param mixed $orderby */
	public static function seeded_orderby( $orderby ) {
		return is_string( $orderby ) ? (string) preg_replace( '/\bRAND\(\s*\)/i', 'RAND(' . self::SEED . ')', $orderby ) : $orderby;
	}

	/**
	 * This plugin's own device lookup (the cache key with Separate Mobile
	 * Cache on). It runs on every cached page and is not the page asking.
	 */
	public static function own_lookup(): bool {
		++self::$own;
		self::$own_now = true;
		try {
			return (bool) wp_is_mobile();
		} finally {
			self::$own_now = false;
		}
	}

	/** Inside own_lookup(). */
	private static bool $own_now = false;

	/** Whether this render asked for the device, early lookups included. */
	public static function asked(): bool {
		if ( function_exists( 'did_filter' ) ) {
			$count = 0;
			foreach ( self::DEVICE_FILTERS as $filter ) {
				$count += (int) did_filter( $filter );
			}
			return $count - self::$own > 0;
		}
		return self::$asked;
	}

	/**
	 * `wp_is_mobile` filter: remember that this render asked. Returns the
	 * value unchanged.
	 *
	 * A probe fetch reports straight away rather than at shutdown, so the
	 * probe can read it as soon as its request returns.
	 *
	 * @param mixed $is_mobile The answer wp_is_mobile() is about to return.
	 * @return mixed
	 */
	public static function note( $is_mobile ) {
		if ( ! self::$asked && ! self::$own_now ) {
			self::$asked = true;
			$token       = self::probe_token();
			if ( '' !== $token ) {
				set_transient( self::TOKEN_PREFIX . $token, 'asked', 10 * MINUTE_IN_SECONDS );
			}
		}
		return $is_mobile;
	}

	/**
	 * The token of a probe fetch this request is, or ''. Only a token the
	 * probe registered counts, so a visitor adding the parameter cannot
	 * write anything.
	 */
	private static function probe_token(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only match against a token this plugin issued.
		$token = isset( $_GET[ self::PARAM ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::PARAM ] ) ) : '';
		if ( '' === $token || ! function_exists( 'get_transient' ) ) {
			return '';
		}
		return false === get_transient( self::TOKEN_PREFIX . $token ) ? '' : $token;
	}

	/**
	 * Shutdown: note a page that asked for the device, for the background
	 * check. Only front-end HTML pages that rendered normally, only while the
	 * page cache is on (the only time the answer matters), only pages the
	 * cache would store, and never a probe fetch.
	 *
	 * Cart, checkout and my-account are never cached, so how they look on a
	 * phone cannot make a shared cached copy wrong. Noting them made a shop
	 * whose cart has a phone layout turn Separate Mobile Cache on, and give
	 * up its fastest cached pages for pages that are never cached (#618 QA).
	 *
	 * A probe fetch reports instead: 'asked', or 'rendered' when nothing
	 * asked. The probe reads 'pending' as "no report", never as "not asked".
	 */
	public static function record(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only.
		if ( isset( $_GET[ self::PARAM ] ) ) {
			$token = self::probe_token();
			if ( '' !== $token ) {
				set_transient( self::TOKEN_PREFIX . $token, self::asked() ? 'asked' : 'rendered', 10 * MINUTE_IN_SECONDS );
			}
			return;
		}
		if ( ! self::asked() || ! self::is_page_render() || ! self::cacheable() ) {
			return;
		}
		$opts = get_option( 'xspeed_options', array() );
		if ( empty( $opts['cache_enabled'] ) ) {
			return;
		}
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) strtok( esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), '?' ) : '';
		if ( '' === $path || '/' !== $path[0] ) {
			return;
		}
		$pages = self::pages();
		if ( isset( $pages[ $path ] ) || count( $pages ) >= self::MAX_PAGES ) {
			return;
		}
		$pages[ $path ] = array( 'seen' => time(), 'checked' => 0, 'differs' => null, 'tries' => 0 );
		update_option( self::PAGES_OPTION, $pages, true );
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
		}
	}

	/**
	 * Whether the page cache would store this render. Reads the verdict the
	 * cache already reached on this request; asking should_cache() again here
	 * would reset state the cache still needs to store the page.
	 */
	private static function cacheable(): bool {
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}
		return '' === Cache::bypass_reason();
	}

	/** A front-end GET that WordPress rendered as a normal HTML page. */
	private static function is_page_render(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			return false;
		}
		if ( is_user_logged_in() || is_feed() || is_404() ) {
			return false;
		}
		// False outside a web request: nothing to go by, so not a reason to skip.
		$code = function_exists( 'http_response_code' ) ? http_response_code() : false;
		return false === $code || 200 === (int) $code;
	}

	/** @return array<string,array{seen:int,checked:int,differs:?bool,tries?:int}> */
	public static function pages(): array {
		$pages = get_option( self::PAGES_OPTION, array() );
		return is_array( $pages ) ? $pages : array();
	}

	/** Start the notes over. */
	public static function forget(): void {
		delete_option( self::PAGES_OPTION );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_transient( 'xspeed_mobile_equality_probe' );
	}

	/**
	 * Cron: compare the noted pages not yet checked, a few per run, the
	 * least-tried first so a page that never answers does not hold up the
	 * rest.
	 */
	public static function check_pages(): void {
		$pages   = self::pending_first( self::pages() );
		$pending = array_keys( array_filter( $pages, static fn ( $p ) => empty( $p['checked'] ) ) );
		foreach ( array_slice( $pending, 0, self::PER_RUN ) as $path ) {
			$result = self::compare( self::page_url( $path ) );
			if ( ! empty( $result['gone'] ) ) {
				unset( $pages[ $path ] );
				continue;
			}
			if ( ! empty( $result['checked'] ) ) {
				$pages[ $path ]['checked'] = time();
				$pages[ $path ]['differs'] = ! $result['identical'];
				continue;
			}
			// No verdict (a cache answered, a fetch failed, the page changes
			// on every load): try again on a later run rather than store a
			// guess, up to MAX_TRIES. Then it keeps the verdict it had, or
			// none: a failed fetch never clears an earlier "differs".
			$pages[ $path ]['tries'] = (int) ( $pages[ $path ]['tries'] ?? 0 ) + 1;
			if ( $pages[ $path ]['tries'] >= self::MAX_TRIES ) {
				$pages[ $path ]['checked'] = time();
				$pages[ $path ]['differs'] = $pages[ $path ]['differs'] ?? null;
			}
		}
		update_option( self::PAGES_OPTION, $pages, true );
		$left = array_filter( $pages, static fn ( $p ) => empty( $p['checked'] ) );
		if ( ! empty( $left ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_HOOK );
		}
	}

	/**
	 * The address of a noted path. The path came from REQUEST_URI, so it
	 * already holds the site's folder on a subfolder install; home_url()
	 * would add the folder a second time.
	 */
	private static function page_url( string $path ): string {
		$home = (string) home_url( '/' );
		$host = (string) wp_parse_url( $home, PHP_URL_HOST );
		$port = wp_parse_url( $home, PHP_URL_PORT );
		return ( wp_parse_url( $home, PHP_URL_SCHEME ) ?: 'https' ) . '://' . $host . ( $port ? ':' . $port : '' ) . $path;
	}

	/**
	 * Pages ordered by tries, fewest first; the order they were noted in
	 * otherwise.
	 *
	 * @param array<string,array> $pages From pages().
	 * @return array<string,array>
	 */
	private static function pending_first( array $pages ): array {
		$order = array_flip( array_keys( $pages ) );
		uksort(
			$pages,
			static fn ( $a, $b ) => array( (int) ( $pages[ $a ]['tries'] ?? 0 ), $order[ $a ] ) <=> array( (int) ( $pages[ $b ]['tries'] ?? 0 ), $order[ $b ] )
		);
		return $pages;
	}

	/**
	 * Compare the pages marked as differing again, up to $limit now; the rest
	 * go back to the background check.
	 *
	 * A verdict was kept until the theme or a plugin changed, so a page fixed
	 * since went on being listed and "Check now" went on recommending the
	 * setting (#618 QA). A page with no verdict now (gone, or changing on
	 * every load) loses the old one rather than keep it.
	 */
	public static function recheck_differing( int $limit = self::PER_RUN ): void {
		$pages = self::pages();
		$stale = self::differing_pages();
		if ( empty( $stale ) ) {
			return;
		}
		foreach ( $stale as $i => $path ) {
			// Past the limit: keep the verdict, and leave the page to the
			// background check, so the list never drops a page unchecked.
			if ( $i >= $limit ) {
				$pages[ $path ] = array( 'checked' => 0, 'tries' => 0 ) + $pages[ $path ];
				continue;
			}
			$result = self::compare( self::page_url( $path ) );
			if ( ! empty( $result['gone'] ) ) {
				unset( $pages[ $path ] );
				continue;
			}
			if ( ! empty( $result['checked'] ) ) {
				$pages[ $path ] = array(
					'checked' => time(),
					'differs' => ! $result['identical'],
					'tries'   => 0,
				) + $pages[ $path ];
				continue;
			}
			// No verdict (a fetch failed, a cache answered): only a real
			// "identical" clears a page, so it stays listed and the
			// background check tries it again.
			$pages[ $path ] = array(
				'checked' => 0,
				'tries'   => (int) ( $pages[ $path ]['tries'] ?? 0 ) + 1,
			) + $pages[ $path ];
		}
		update_option( self::PAGES_OPTION, $pages, true );
		$left = array_filter( $pages, static fn ( $p ) => empty( $p['checked'] ) );
		if ( ! empty( $left ) && function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
		}
	}

	/** Paths the background check found serving phones different HTML. @return string[] */
	public static function differing_pages(): array {
		$pages = array_keys( array_filter( self::pages(), static fn ( $p ) => true === ( $p['differs'] ?? null ) ) );
		// Not the pages excluded from the cache since they were noted.
		$excluded = Settings_Manager::get( 'cache' )['excluded_urls'] ?? array();
		if ( ! is_array( $excluded ) || empty( $excluded ) ) {
			return $pages;
		}
		return array_values( array_filter( $pages, static fn ( $path ) => ! Glob_Matcher::any_match( $excluded, (string) $path ) ) );
	}

	/**
	 * Fetch $url as a desktop and as a phone and say whether the HTML
	 * differs by device.
	 *
	 * @return array{identical:bool, checked:bool, asked?:bool, gone?:bool, reason?:string, desktop_bytes?:int, mobile_bytes?:int}
	 */
	public static function compare( string $url ): array {
		$desktop = self::fetch( $url, self::DESKTOP_UA );
		$mobile  = self::fetch( $url, self::MOBILE_UA );

		// 404 or 410: the page no longer exists, so it no longer decides
		// the answer. Callers drop it rather than try it again.
		if ( 'gone' === $desktop || 'gone' === $mobile ) {
			return array( 'identical' => false, 'checked' => false, 'gone' => true, 'reason' => 'the page no longer exists' );
		}
		if ( false === $desktop || false === $mobile ) {
			return array( 'identical' => false, 'checked' => false, 'reason' => 'a cache answered the probe instead of the site' );
		}
		if ( null === $desktop || null === $mobile ) {
			return array( 'identical' => false, 'checked' => false, 'reason' => 'could not fetch the page twice' );
		}

		$asked  = 'asked' === $desktop['report'] || 'asked' === $mobile['report'];
		$result = array(
			'checked'       => true,
			'asked'         => $asked,
			'desktop_bytes' => strlen( $desktop['body'] ),
			'mobile_bytes'  => strlen( $mobile['body'] ),
		);

		// Both renders reported that nothing asked for the device, and
		// nothing that reads the user-agent itself is loaded: whatever
		// differs is noise. A render that did not report (its report is
		// not readable here) proves nothing, so its HTML is compared.
		if ( 'rendered' === $desktop['report'] && 'rendered' === $mobile['report'] && ! self::sniffer_loaded() ) {
			return array( 'identical' => true ) + $result;
		}

		// The page may branch by device, so compare the HTML. Values that
		// change on every render are masked first; a real difference
		// elsewhere in the same tag still counts.
		$desk = self::comparable( $desktop['body'] );
		if ( $desk === self::comparable( $mobile['body'] ) ) {
			$result['identical'] = true;
			return $result;
		}

		// They differ. A second fetch per device tells a device difference
		// from a page that changes on every load: each device's page must
		// match itself, or the difference proves nothing either way. Only
		// pages that may branch by device and differ get these two fetches.
		// Random draws a seed cannot fix (random_int(), wp_rand()) can still
		// repeat by chance on both devices; that is rarer with every fetch.
		foreach ( array( self::DESKTOP_UA => $desk, self::MOBILE_UA => self::comparable( $mobile['body'] ) ) as $ua => $first ) {
			$again = self::fetch( $url, $ua );
			if ( ! is_array( $again ) ) {
				return array( 'identical' => false, 'checked' => false, 'reason' => 'could not fetch the page again' );
			}
			if ( self::comparable( $again['body'] ) !== $first ) {
				return array( 'identical' => false, 'checked' => false, 'reason' => 'the page changes on every load' ) + $result;
			}
		}
		$result['identical'] = false;
		return $result;
	}

	/** Same desktop UA the probe always used. */
	private const DESKTOP_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

	/** iPhone Safari: what wp_is_mobile() and user-agent sniffers both read as a phone. */
	private const MOBILE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

	/**
	 * One probe fetch with its own token.
	 *
	 * @return array{body:string,report:string}|null|false|string Null when the
	 *         fetch failed; false when a cache answered instead of the site;
	 *         'gone' on a 404 or 410.
	 */
	private static function fetch( string $url, string $ua ) {
		$token = strtolower( wp_generate_password( 16, false ) );
		set_transient( self::TOKEN_PREFIX . $token, 'pending', 10 * MINUTE_IN_SECONDS );

		$is_local = function_exists( 'wp_get_environment_type' )
			&& in_array( wp_get_environment_type(), array( 'local', 'development' ), true );

		// Its own query token per fetch. Both fetches used to request the
		// same URL, so with the page cache on the phone fetch was served the
		// desktop copy. A parameter off the ignored-query list makes the
		// drop-in render the page fresh and store nothing. (#595)
		$resp = wp_remote_get(
			add_query_arg( self::PARAM, $token, $url ),
			array(
				'timeout'     => 5,
				'sslverify'   => ! $is_local,
				'redirection' => 2,
				'user-agent'  => $ua,
				// A real device UA by design, so only the header marks this
				// as ours to analytics and the hit ratio.
				'headers'     => Self_Traffic::headers( array( 'Cache-Control' => 'no-cache' ) ),
			)
		);
		$report = (string) self::report( $token );
		delete_transient( self::TOKEN_PREFIX . $token );

		if ( is_wp_error( $resp ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 404 === $code || 410 === $code ) {
			return 'gone';
		}
		// A timeout, a 5xx or a firewall block may pass: no verdict.
		if ( 200 !== $code ) {
			return null;
		}
		// Still a cached copy: something in front ignores the query (an edge
		// cache, or the token dropped on a redirect). Two copies of one cached
		// page prove nothing, and the render never reported back.
		if ( self::is_cache_hit( $resp ) ) {
			return false;
		}
		// A page that prints its own URL (a canonical link, og:url) would
		// otherwise differ by the token alone.
		return array(
			'body'   => str_replace( $token, '', (string) wp_remote_retrieve_body( $resp ) ),
			'report' => $report,
		);
	}

	/**
	 * What the render of a probe fetch reported for $token: 'asked',
	 * 'rendered', or 'pending' when no report can be read.
	 *
	 * This request still holds the 'pending' it wrote before the fetch, and
	 * the render's report was written by another request. With a persistent
	 * object cache (Redis, Memcached) the transient lives there: read it with
	 * `$force`, which skips this request's copy. Without one a transient is
	 * an option: drop the local copy so the read reaches the database.
	 */
	private static function report( string $token ) {
		$key = self::TOKEN_PREFIX . $token;
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			return wp_cache_get( $key, 'transient', true );
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( '_transient_' . $key, 'options' );
			wp_cache_delete( '_transient_timeout_' . $key, 'options' );
		}
		return get_transient( $key );
	}

	/**
	 * Whether a response came from a cache rather than from WordPress: this
	 * plugin's own `X-XSpeed-Cache: HIT`, or the HIT that Cloudflare, Varnish,
	 * Fastly and most CDNs report in `cf-cache-status`, `x-cache` or
	 * `x-proxy-cache`.
	 *
	 * @param array|\WP_Error $resp A wp_remote_get() response.
	 */
	private static function is_cache_hit( $resp ): bool {
		foreach ( array( 'x-xspeed-cache', 'cf-cache-status', 'x-cache', 'x-proxy-cache' ) as $name ) {
			$value = wp_remote_retrieve_header( $resp, $name );
			$value = is_array( $value ) ? implode( ',', $value ) : (string) $value;
			if ( preg_match( '#\bHIT\b#i', $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether code that tells phones apart by reading the user-agent itself
	 * is loaded. Such code never calls wp_is_mobile(), so a render that did
	 * not ask proves nothing on these sites.
	 */
	private static function sniffer_loaded(): bool {
		$loaded = class_exists( 'Mobile_Detect', false )
			|| class_exists( '\Detection\MobileDetect', false )
			|| function_exists( 'wptouch_is_mobile_theme_showing' );
		/**
		 * Whether code that reads the user-agent itself to tell phones apart
		 * is loaded. Return true for a theme or plugin that does so without
		 * calling wp_is_mobile(), so the device check compares the HTML.
		 *
		 * @param bool $loaded Whether a known one was found.
		 */
		return (bool) apply_filters( 'xspeed_device_sniffer_loaded', $loaded );
	}

	/**
	 * HTML ready to compare across two renders: Cache's noise normalizer,
	 * plus ids made of a name and a random run (`eael_quick_view_6abd…`),
	 * which the normalizer leaves because the run follows an underscore.
	 * Only the random run is masked; the rest of the tag still compares.
	 *
	 * A hex run needs a letter, or every post id would go too. A run of
	 * digits alone counts from 9 digits (`eael_quick_view_418808969691`, #618
	 * QA): no post or term id is that long.
	 */
	private static function comparable( string $html ): string {
		$html = Cache::normalize_html_for_diff( $html );
		$html = (string) preg_replace( '/(?<=[_-])(?=[a-f0-9]{8,}\b)[0-9]*[a-f][a-f0-9]*\b/i', 'X', $html );
		return (string) preg_replace( '/(?<=[_-])[0-9]{9,}\b/', 'X', $html );
	}
}
