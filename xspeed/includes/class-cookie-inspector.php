<?php
/**
 * Cookie_Inspector — detects cache-poisoning Set-Cookie headers on
 * cacheable pages and names the plugin responsible.
 *
 * Why: a single plugin emitting Set-Cookie on every anonymous pageview
 * silently disables CDN/edge caching for the whole site (Cloudflare
 * returns BYPASS for any response carrying Set-Cookie). The user sees a
 * low hit ratio and a slow TTFB with no explanation. Live case: an
 * analytics session cookie (`ep_session_id`) forced cf-cache-status:
 * BYPASS on every HTML response of a Cloudflare-fronted site.
 *
 * Mechanics: fetch the home page once as an anonymous visitor (no
 * cookies sent), read the Set-Cookie response headers, and attribute
 * each cookie to its source plugin via a prefix map. Result is cached
 * in a transient so Health never pays for the HTTP round-trip on every
 * paint (same throttling pattern as Cache::probe_static_rewrite).
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Cookie_Inspector {

	const TRANSIENT = 'xspeed_cookie_probe';

	/**
	 * Known cookie-name prefixes → the plugin that sets them. Checked in
	 * order; first match wins. Extendable via the
	 * `xspeed_cookie_culprits` filter.
	 *
	 * WordPress core cookies are excluded upstream (wordpress_*, wp-*) —
	 * core only sets them on login/comment actions, not anonymous GETs.
	 *
	 * @return array<string,string> prefix => plugin label.
	 */
	public static function culprit_map(): array {
		$map = array(
			'ep_'                    => 'EmbedPress',
			'edd_'                   => 'Easy Digital Downloads',
			'woocommerce_'           => 'WooCommerce',
			'wp_woocommerce_session' => 'WooCommerce',
			'tk_ai'                  => 'Jetpack',
			'tinvwl_'                => 'TI WooCommerce Wishlist',
			'mailchimp_'             => 'Mailchimp',
			'pys_'                   => 'PixelYourSite',
			'mo_'                    => 'miniOrange',
			'wfwaf-'                 => 'Wordfence',
			'ssupp.'                 => 'Smartsupp Chat',
			'PHPSESSID'              => 'a PHP session (session_start() on the front end)',
		);
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'xspeed_cookie_culprits', $map );
			if ( is_array( $filtered ) ) {
				$map = $filtered;
			}
		}
		return $map;
	}

	/**
	 * Attribute a cookie name to its source plugin. Pure — unit-tested.
	 *
	 * @param string $cookie_name e.g. 'ep_session_id'.
	 * @return string|null Plugin label, or null when unknown.
	 */
	public static function attribute( string $cookie_name, ?array $map = null ): ?string {
		$map = null === $map ? self::culprit_map() : $map;
		foreach ( $map as $prefix => $label ) {
			if ( 0 === strpos( $cookie_name, (string) $prefix ) ) {
				return (string) $label;
			}
		}
		return null;
	}

	/**
	 * Cookie names that never poison edge caches and must not warn:
	 * WordPress core's own (only set on auth/comment actions) and the
	 * consent/test cookies CDNs are configured to ignore.
	 *
	 * @param string $cookie_name Cookie name.
	 */
	public static function is_ignorable( string $cookie_name ): bool {
		$ignorable_prefixes = array(
			'wordpress_',
			'wp-settings-',
			'wp_lang',
			'xspeed_',            // our own (e.g. theme cookie) — never persisted on anon GETs.
			'cookieyes-',
			'cky-',
			'moove_gdpr_',
			// Cloudflare's own edge cookies. Set by the CDN itself, not by
			// origin PHP, and explicitly ignored by its cache — flagging them
			// told the user to "fix the plugin setting __cf_bm" when there is
			// no plugin to fix, on every Cloudflare-fronted site.
			'__cf_bm',
			'__cflb',
			'__cfruid',
			'__cfwaitingroom',
			'cf_clearance',
		);
		foreach ( $ignorable_prefixes as $prefix ) {
			if ( 0 === strpos( $cookie_name, $prefix ) ) {
				return true;
			}
		}
		return 'wordpress_test_cookie' === $cookie_name;
	}

	/**
	 * Parse Set-Cookie header value(s) into offending cookie names with
	 * attribution. Pure — unit-tested.
	 *
	 * @param string[] $set_cookie_headers One raw Set-Cookie value each.
	 * @return array<int,array{name:string,plugin:?string}>
	 */
	public static function analyze( array $set_cookie_headers ): array {
		$out  = array();
		$seen = array();
		foreach ( self::split_folded( $set_cookie_headers ) as $header ) {
			$pair = explode( '=', trim( (string) $header ), 2 );
			$name = trim( $pair[0] );
			if ( '' === $name || isset( $seen[ $name ] ) || self::is_ignorable( $name ) ) {
				continue;
			}
			$seen[ $name ] = true;
			$out[]         = array(
				'name'   => $name,
				'plugin' => self::attribute( $name ),
			);
		}
		return $out;
	}

	/**
	 * Unfold comma-joined Set-Cookie headers into one entry per cookie.
	 *
	 * Some transports (and `wp_remote_retrieve_header` when a response
	 * carries several Set-Cookie lines) hand back a single comma-joined
	 * string. Splitting naively on "," would break `Expires=Wed, 09 Jun
	 * 2027 …`, so we only split on a comma that is followed by a
	 * `name=` pair — the start of the next cookie. Pure — unit-tested.
	 *
	 * @param string[] $headers Raw Set-Cookie values.
	 * @return string[] One cookie per element.
	 */
	public static function split_folded( array $headers ): array {
		$out = array();
		foreach ( $headers as $header ) {
			$header = trim( (string) $header );
			if ( '' === $header ) {
				continue;
			}
			// Split on ", " only when what follows looks like `token=`
			// (a cookie name is a token: no spaces, commas or equals).
			$parts = preg_split( '/,\s*(?=[A-Za-z0-9!#$%&\'*+\-.^_`|~]+\s*=)/', $header );
			if ( ! is_array( $parts ) ) {
				$out[] = $header;
				continue;
			}
			foreach ( $parts as $part ) {
				$part = trim( $part );
				if ( '' !== $part ) {
					$out[] = $part;
				}
			}
		}
		return $out;
	}

	/**
	 * Probe the home page as an anonymous visitor and report offending
	 * cookies. Throttled via transient (1 hour); pass $allow_probe=false
	 * to read the cached verdict only (admin bootstrap must never block).
	 *
	 * @return array{checked:bool,cookies:array<int,array{name:string,plugin:?string}>}
	 */
	/**
	 * Cron hook that refreshes the probe out of band.
	 */
	const CRON_HOOK = 'xspeed_cookie_probe_refresh';

	/**
	 * True for local/dev hostnames, where self-signed certificates are the
	 * norm and TLS verification would fail the probe outright. Everything
	 * else — i.e. every production site — gets a verified request.
	 *
	 * Pure — unit-tested.
	 *
	 * @param string $url Site URL.
	 */
	public static function is_local_host( string $url ): bool {
		// Plain parse_url keeps this pure and testable without a WP bootstrap;
		// the input is always our own home_url(), never user-supplied.
		$host = (string) ( parse_url( $url, PHP_URL_HOST ) ?? '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, no WP available.
		if ( '' === $host ) {
			return false;
		}
		$host = strtolower( $host );
		if ( 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host ) {
			return true;
		}
		foreach ( array( '.local', '.test', '.localhost', '.invalid', '.sb' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		// RFC1918 / link-local literals.
		return 1 === preg_match( '/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.)/', $host );
	}

	/**
	 * Cached verdict, scheduling a background refresh when it's cold.
	 *
	 * Health::checks() runs inside the REST request that paints the
	 * dashboard and inside the MCP get_health tool, so probing inline meant
	 * a user-visible request blocked on a second HTTP round-trip to our own
	 * site — up to the 5s timeout, and worse behind a slow edge or when the
	 * origin is rate-limiting itself. The first paint now reports
	 * `checked:false` (the UI simply omits the row) and the real verdict
	 * lands on the next load.
	 */
	public static function probe_cached(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		self::schedule_refresh();
		return array(
			'checked' => false,
			'cookies' => array(),
		);
	}

	/** Queue a one-off background refresh, unless one is already pending. */
	public static function schedule_refresh(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + 30, self::CRON_HOOK );
	}

	public static function probe( bool $allow_probe = false ): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! $allow_probe || ! function_exists( 'wp_remote_get' ) ) {
			return array(
				'checked' => false,
				'cookies' => array(),
			);
		}

		$res = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'   => 5,
				'headers'   => array( 'User-Agent' => 'xSpeed Health Probe/1.0' ),
				'cookies'   => array(), // anonymous — a logged-in probe would false-positive on auth cookies.
				// Verify TLS in production; relax only for local/dev hosts,
				// which routinely use self-signed certs. Blanket-disabling
				// it weakened a real request on every live site.
				'sslverify' => ! self::is_local_host( home_url( '/' ) ),
			)
		);

		if ( is_wp_error( $res ) ) {
			$result = array(
				'checked' => false,
				'cookies' => array(),
			);
			set_transient( self::TRANSIENT, $result, 5 * MINUTE_IN_SECONDS );
			return $result;
		}

		$raw = wp_remote_retrieve_header( $res, 'set-cookie' );
		if ( is_string( $raw ) ) {
			$raw = '' === $raw ? array() : array( $raw );
		} elseif ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$result = array(
			'checked' => true,
			'cookies' => self::analyze( $raw ),
		);
		set_transient( self::TRANSIENT, $result, HOUR_IN_SECONDS );
		return $result;
	}
}
