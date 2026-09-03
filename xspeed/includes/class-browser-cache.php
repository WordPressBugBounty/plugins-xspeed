<?php
/**
 * Browser_Cache — writes/removes browser-cache directives in the site
 * root .htaccess so static assets get long Cache-Control + Expires
 * headers, and serves an nginx snippet for non-Apache hosts.
 *
 * Same shape as Gzip: marker block, insert_with_markers, snippet
 * fallback. Independent toggle so users can enable browser caching
 * without compression and vice versa.
 *
 * The default TTLs follow LiteSpeed/WP Rocket conventions:
 *   - Static assets (CSS/JS/fonts/images): 1 year + immutable.
 *   - HTML: 1 hour (so post edits go live the same day even if a CDN
 *     has cached the document).
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Browser_Cache {

	public const MARKER = 'xSpeed Browser Cache';

	public const DEFAULT_ASSET_TTL = 31536000; // 1 year
	public const DEFAULT_HTML_TTL  = 3600;     // 1 hour

	public static function apply( bool $enabled, array $opts = array() ): bool {
		if ( ! function_exists( 'XSpeed\\Server::supports_htaccess' ) && class_exists( '\\XSpeed\\Server' ) ) {
			if ( ! Server::supports_htaccess() ) {
				return false;
			}
		}
		$htaccess = ABSPATH . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			return false;
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		$rules = $enabled ? self::apache_rules( $opts ) : array();
		return (bool) insert_with_markers( $htaccess, self::MARKER, $rules );
	}

	/**
	 * Apache rule lines. mod_expires handles the Expires header; an
	 * inline mod_headers Cache-Control mirror lets us include
	 * `immutable` (mod_expires doesn't emit it).
	 *
	 * @return string[]
	 */
	public static function apache_rules( array $opts = array() ): array {
		$asset = (int) ( $opts['asset_ttl'] ?? self::DEFAULT_ASSET_TTL );
		$html  = (int) ( $opts['html_ttl'] ?? self::DEFAULT_HTML_TTL );
		if ( $asset < 0 ) {
			$asset = self::DEFAULT_ASSET_TTL;
		}
		if ( $html < 0 ) {
			$html = self::DEFAULT_HTML_TTL;
		}
		return array(
			'<IfModule mod_expires.c>',
			'  ExpiresActive On',
			'  ExpiresByType text/html "access plus ' . $html . ' seconds"',
			'  ExpiresByType text/css "access plus ' . $asset . ' seconds"',
			'  ExpiresByType text/javascript "access plus ' . $asset . ' seconds"',
			'  ExpiresByType application/javascript "access plus ' . $asset . ' seconds"',
			'  ExpiresByType application/json "access plus ' . $html . ' seconds"',
			'  ExpiresByType image/jpeg "access plus ' . $asset . ' seconds"',
			'  ExpiresByType image/png "access plus ' . $asset . ' seconds"',
			'  ExpiresByType image/webp "access plus ' . $asset . ' seconds"',
			'  ExpiresByType image/avif "access plus ' . $asset . ' seconds"',
			'  ExpiresByType image/gif "access plus ' . $asset . ' seconds"',
			'  ExpiresByType image/svg+xml "access plus ' . $asset . ' seconds"',
			'  ExpiresByType image/x-icon "access plus ' . $asset . ' seconds"',
			'  ExpiresByType font/woff2 "access plus ' . $asset . ' seconds"',
			'  ExpiresByType font/woff "access plus ' . $asset . ' seconds"',
			'  ExpiresByType font/ttf "access plus ' . $asset . ' seconds"',
			'  ExpiresByType font/otf "access plus ' . $asset . ' seconds"',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'  <FilesMatch "\.(css|js|jpg|jpeg|png|gif|webp|avif|svg|ico|woff2|woff|ttf|otf|eot|mp4|webm|mp3|ogg)$">',
			'    Header set Cache-Control "public, max-age=' . $asset . ', immutable"',
			'  </FilesMatch>',
			'  <FilesMatch "\.html$">',
			'    Header set Cache-Control "public, max-age=' . $html . '"',
			'  </FilesMatch>',
			'</IfModule>',
		);
	}

	/**
	 * nginx snippet — uses `expires` directive (the canonical nginx way)
	 * plus an `add_header` line for the immutable flag.
	 */
	/**
	 * Probe whether long-lived caching headers are actually reaching the
	 * browser. Picks a recognisable static asset (anything in
	 * `wp-includes/css/` is always served on a WP install) and HEADs it.
	 * Cached in a transient so this never adds latency to the dashboard.
	 *
	 * We test for the EFFECT, not for our own configuration (issue #329).
	 * The old check looked for `immutable` — the fingerprint *our* snippet
	 * writes — so any site whose headers come from another layer (an
	 * xCloud-generated vhost, a container nginx, a reverse proxy) was told
	 * "enabled but not active on the server" while demonstrably serving
	 * `Cache-Control: public, max-age=315360000` on every asset. That is a
	 * false alarm the operator cannot dismiss, and it pushed them toward
	 * pasting a snippet that would add a second, conflicting `location`
	 * block. A long `max-age`, an `Expires` date in the future, or
	 * `immutable` all mean the same thing to a browser, so all three count.
	 *
	 * Returns:
	 *   true  → caching headers present (ours or another layer's), no notice
	 *   false → proven absent: nothing is being sent, keep the notice up
	 *   null  → no verdict; the loopback never completed (firewalled, TLS
	 *           failure, timeout, WAF answering instead of the origin)
	 *
	 * The third state matters for the same reason it does on the GZIP probe
	 * (issue #18): folding "couldn't ask" into "the answer is no" pins a
	 * permanent warning onto sites where only the loopback is broken. Only a
	 * *proven* false may nag the user. A non-verdict is cached for a minute
	 * only, so a transient blip resolves itself on the next page load.
	 *
	 * @return bool|null
	 */
	public static function probe_headers_present() {
		$cached = get_transient( 'xspeed_browser_cache_probe' );
		if ( '?' === $cached ) {
			return null;
		}
		if ( null !== $cached && false !== $cached ) {
			return (bool) $cached;
		}

		$asset_url = includes_url( 'css/dashicons.min.css' );
		$resp      = wp_remote_head(
			$asset_url,
			array(
				'timeout'     => 3,
				'sslverify'   => false,
				'redirection' => 0,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			set_transient( 'xspeed_browser_cache_probe', '?', MINUTE_IN_SECONDS );
			return null;
		}
		// wp_remote_retrieve_header() returns a STRING for a single header
		// but an ARRAY when the header appears more than once (common behind
		// CDNs / proxies, or nginx with multiple add_header lines). Casting
		// an array with (string) emits an "Array to string conversion"
		// warning AND flattens to the literal "Array", so the immutable
		// check below silently false-negatives. Normalize array → string
		// first. (FBS-82141)
		$raw           = wp_remote_retrieve_header( $resp, 'cache-control' );
		$cache_control = is_array( $raw ) ? implode( ', ', $raw ) : (string) $raw;
		$raw_expires   = wp_remote_retrieve_header( $resp, 'expires' );
		$expires       = is_array( $raw_expires ) ? implode( ', ', $raw_expires ) : (string) $raw_expires;

		$active = self::headers_indicate_caching( $cache_control, $expires );
		set_transient( 'xspeed_browser_cache_probe', $active ? 1 : 0, 5 * MINUTE_IN_SECONDS );
		return $active;
	}

	/**
	 * Do these response headers tell a browser to cache the asset for a
	 * meaningful length of time?
	 *
	 * Any of three signals counts, because a browser honours all three
	 * equally and we must not privilege the one our own snippet happens to
	 * write (issue #329):
	 *
	 *   - `immutable`     — what our snippet adds
	 *   - a long `max-age` — what most host templates emit
	 *   - a future `Expires` — the older directive, still what nginx's
	 *     `expires` emits alongside `Cache-Control`
	 *
	 * An explicit no-store / no-cache / max-age=0 is a proven negative and
	 * wins over everything else: that is a server actively refusing to let
	 * the asset be cached, which is exactly the state worth warning about.
	 *
	 * The one-hour floor keeps WP core's own short defaults from reading as
	 * "browser caching is configured".
	 *
	 * Pure — unit-tested.
	 */
	public static function headers_indicate_caching( string $cache_control, string $expires = '' ): bool {
		if ( preg_match( '#\b(?:no-store|no-cache)\b#i', $cache_control ) ) {
			return false;
		}
		if ( preg_match( '#\bmax-age\s*=\s*(\d+)#i', $cache_control, $m ) ) {
			return (int) $m[1] >= HOUR_IN_SECONDS;
		}
		if ( false !== stripos( $cache_control, 'immutable' ) ) {
			return true;
		}
		if ( '' !== $expires ) {
			$ts = strtotime( $expires );
			// A past date (or the literal "0" some servers send) means
			// "already stale", not "cached".
			return false !== $ts && $ts > time() + HOUR_IN_SECONDS;
		}
		return false;
	}

	public static function nginx_snippet( array $opts = array() ): string {
		$asset = (int) ( $opts['asset_ttl'] ?? self::DEFAULT_ASSET_TTL );
		$html  = (int) ( $opts['html_ttl'] ?? self::DEFAULT_HTML_TTL );
		return implode(
			"\n",
			array(
				'location ~* \.(css|js|jpg|jpeg|png|gif|webp|avif|svg|ico|woff2|woff|ttf|otf|eot|mp4|webm|mp3|ogg)$ {',
				'    expires ' . $asset . 's;',
				'    add_header Cache-Control "public, max-age=' . $asset . ', immutable";',
				'}',
				'location ~* \.html$ {',
				'    expires ' . $html . 's;',
				'    add_header Cache-Control "public, max-age=' . $html . '";',
				'}',
			)
		);
	}
}
