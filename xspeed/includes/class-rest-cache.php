<?php
/**
 * REST response cache.
 *
 * The page cache (Cache) bails on REST_REQUEST, so REST responses are
 * never cached by it. This class adds a separate, opt-in REST cache for
 * headless / app backends that poll read-only routes:
 *
 *   - rest_pre_dispatch  → serve a fresh cached body (HIT), short-circuit.
 *   - rest_post_dispatch → store a cacheable response (MISS).
 *
 * Free never caches REST on its own — it stays inert until an add-on
 * (xspeed-pro REST cache) flips `xspeed_rest_cache_enabled` and supplies
 * per-route TTLs via `xspeed_rest_cache_ttl`. The class owns the
 * safety rules (GET-only, no auth context, 2xx, route allow-list) and
 * the storage; the add-on owns policy (which routes, how long).
 *
 * Storage: one JSON file per entry under XSPEED_CACHE_DIR/rest/, keyed
 * by md5(route + sorted query params). Purged by Cache::purge_all().
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

class Rest_Cache {

	/** Subdir of the cache dir holding REST entries. */
	const SUBDIR = 'rest';

	public function __construct() {
		// Late on pre_dispatch so permission/auth resolution that other
		// plugins do on earlier priorities has run; early enough to skip
		// the actual callback on a HIT. priority 8 < the default 10.
		add_filter( 'rest_pre_dispatch', array( $this, 'maybe_serve' ), 8, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'maybe_store' ), 10, 3 );
	}

	/**
	 * Master switch. Off by default — Free never caches REST until an
	 * add-on opts in. Also requires the page cache to be enabled (the
	 * REST cache is a facet of caching, not a separate product).
	 */
	public static function enabled(): bool {
		$opts = Settings::get();
		if ( empty( $opts['cache_enabled'] ) ) {
			return false;
		}
		/**
		 * Whether REST response caching is active.
		 *
		 * @param bool $enabled Default false.
		 */
		return (bool) apply_filters( 'xspeed_rest_cache_enabled', false );
	}

	/**
	 * Decide whether the current REST request may be cached. Conservative
	 * by design: read-only, anonymous, and not a route an add-on excluded.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool
	 */
	public static function is_cacheable( \WP_REST_Request $request ): bool {
		if ( ! self::enabled() ) {
			return false;
		}
		if ( 'GET' !== $request->get_method() ) {
			return false;
		}
		// Never cache an authenticated request — the response may be
		// user-specific. A logged-in cookie, an Authorization header, or
		// a REST nonce all signal "this could be private".
		if ( is_user_logged_in() ) {
			return false;
		}
		if ( '' !== (string) $request->get_header( 'authorization' ) ) {
			return false;
		}
		if ( '' !== (string) $request->get_header( 'x_wp_nonce' ) ) {
			return false;
		}

		$route = (string) $request->get_route();
		// Our own admin routes are never cacheable — they're privileged.
		if ( 0 === strpos( $route, '/xspeed/' ) ) {
			return false;
		}

		/**
		 * Final say on whether this REST route is cacheable. An add-on
		 * returning false excludes a route even if the rules above passed.
		 *
		 * @param bool             $cacheable Whether to cache this route.
		 * @param string           $route     The REST route.
		 * @param \WP_REST_Request $request   The request.
		 */
		return (bool) apply_filters( 'xspeed_rest_cache_is_cacheable', true, $route, $request );
	}

	/**
	 * TTL (seconds) for the current route. Default 0 = don't cache; an
	 * add-on resolves a per-route value via the filter. Without a
	 * listener the REST cache is effectively off even when enabled —
	 * which is the safe default.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return int Seconds; <= 0 means don't cache.
	 */
	public static function ttl_for( \WP_REST_Request $request ): int {
		/**
		 * Resolve the cache TTL in seconds for a REST route.
		 *
		 * @param int              $ttl     Default 0 (don't cache).
		 * @param string           $route   The REST route.
		 * @param \WP_REST_Request $request The request.
		 */
		return (int) apply_filters( 'xspeed_rest_cache_ttl', 0, (string) $request->get_route(), $request );
	}

	/**
	 * Filter: rest_pre_dispatch. Serve a fresh cached body for a
	 * cacheable request, short-circuiting the dispatch. Returns a
	 * WP_REST_Response on HIT, or the untouched $result on MISS.
	 *
	 * @param mixed             $result  Dispatch result (null to continue).
	 * @param \WP_REST_Server   $server  REST server.
	 * @param \WP_REST_Request  $request The request.
	 * @return mixed
	 */
	public function maybe_serve( $result, $server, $request ) {
		if ( null !== $result || ! ( $request instanceof \WP_REST_Request ) ) {
			return $result;
		}
		if ( ! self::is_cacheable( $request ) ) {
			return $result;
		}
		$ttl = self::ttl_for( $request );
		if ( $ttl <= 0 ) {
			return $result;
		}

		$file = self::file_for( $request );
		if ( ! file_exists( $file ) ) {
			return $result;
		}
		if ( ( time() - (int) filemtime( $file ) ) > $ttl ) {
			return $result; // expired — let it re-dispatch + restore.
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own cache dir; WP_Filesystem needs admin creds unavailable on a frontend REST hit.
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) || ! array_key_exists( 'body', $data ) ) {
			return $result;
		}

		Hit_Counter::record_hit();
		$response = new \WP_REST_Response( $data['body'], isset( $data['status'] ) ? (int) $data['status'] : 200 );
		$response->header( 'X-XSpeed-REST-Cache', 'HIT' );
		return $response;
	}

	/**
	 * Filter: rest_post_dispatch. Store a cacheable 2xx response.
	 *
	 * @param \WP_REST_Response $response The response.
	 * @param \WP_REST_Server   $server   REST server.
	 * @param \WP_REST_Request  $request  The request.
	 * @return \WP_REST_Response
	 */
	public function maybe_store( $response, $server, $request ) {
		if ( ! ( $response instanceof \WP_REST_Response ) || ! ( $request instanceof \WP_REST_Request ) ) {
			return $response;
		}
		// Already served from cache → nothing to do.
		$headers = $response->get_headers();
		if ( 'HIT' === ( $headers['X-XSpeed-REST-Cache'] ?? '' ) ) {
			return $response;
		}
		if ( ! self::is_cacheable( $request ) ) {
			return $response;
		}
		$ttl = self::ttl_for( $request );
		if ( $ttl <= 0 ) {
			return $response;
		}
		$status = (int) $response->get_status();
		if ( $status < 200 || $status >= 300 ) {
			return $response; // only cache success.
		}

		$dir = self::dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			Cache::write_silence( $dir );
		}

		$payload = wp_json_encode(
			array(
				'body'   => $response->get_data(),
				'status' => $status,
			)
		);
		if ( false !== $payload ) {
			Hit_Counter::record_miss();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- our own cache dir; WP_Filesystem needs admin creds unavailable on a frontend REST request.
			file_put_contents( self::file_for( $request ), $payload, LOCK_EX );
			$response->header( 'X-XSpeed-REST-Cache', 'MISS' );
		}
		return $response;
	}

	/** The REST cache directory. */
	public static function dir(): string {
		return XSPEED_CACHE_DIR . '/' . self::SUBDIR;
	}

	/**
	 * Cache file for a request — md5(route + sorted query params), so
	 * /wp/v2/posts?per_page=5 and ?per_page=10 are distinct but param
	 * order doesn't matter. POST body is irrelevant (GET-only).
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return string
	 */
	public static function file_for( \WP_REST_Request $request ): string {
		// Read the query string straight from the URL, not
		// $request->get_query_params() — WP coerces param types between
		// rest_pre_dispatch (raw "2") and rest_post_dispatch (sanitized
		// int 2), which would make the write key differ from the read key
		// and every HIT miss. The raw query string is identical in both
		// phases. Parse + sort it so param order doesn't fragment entries.
		$qs = '';
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$pos = strpos( $uri, '?' );
		if ( false !== $pos ) {
			parse_str( substr( $uri, $pos + 1 ), $parsed );
			// Drop WP's own routing param so /wp-json/foo and
			// /?rest_route=/foo share one entry.
			unset( $parsed['rest_route'] );
			ksort( $parsed );
			$qs = wp_json_encode( $parsed );
		}
		$key = md5( (string) $request->get_route() . '?' . $qs );
		return self::dir() . '/' . $key . '.json';
	}

	/**
	 * Delete every REST cache entry. Called by Cache::purge_all(). Returns
	 * the count removed.
	 */
	public static function purge(): int {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$files = glob( $dir . '/*.json' );
		if ( ! $files ) {
			return 0;
		}
		foreach ( $files as $f ) {
			wp_delete_file( $f );
		}
		return count( $files );
	}
}
