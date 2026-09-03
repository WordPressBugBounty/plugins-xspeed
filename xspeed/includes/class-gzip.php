<?php
/**
 * GZIP toggle.
 *
 * Apache + LiteSpeed: writes/removes a marker block in the site root
 * .htaccess. Other servers (nginx, IIS): the toggle stores the user's
 * preference but server config must be edited manually — the UI surfaces
 * a copy-pasteable snippet via Gzip::manual_snippet().
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

class Gzip {

	const MARKER = 'xSpeed GZIP';

	public static function apply( $enabled ) {
		if ( ! Server::supports_htaccess() ) {
			return false;
		}

		$htaccess = ABSPATH . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			return false;
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		// Build the block from the GZIP base (only when GZIP is on) plus any
		// rules add-ons append via the filter. An add-on (e.g. the Pro Brotli
		// module) may want its own directives even when GZIP itself is off —
		// so we run the filter regardless and write whatever it returns,
		// emptying the marker block only when there is genuinely nothing.
		$rules = self::apache_rules( (bool) $enabled );
		return (bool) insert_with_markers( $htaccess, self::MARKER, $rules );
	}

	/**
	 * Compression rules written inside the marker block.
	 *
	 * @param bool $gzip_enabled Whether to include the GZIP (mod_deflate)
	 *                           base rules. Add-on filter contributions are
	 *                           applied either way, so Brotli (or another
	 *                           encoder) can be served even with GZIP off.
	 * @return string[]
	 */
	public static function apache_rules( $gzip_enabled = true ) {
		$rules = $gzip_enabled ? array(
			'<IfModule mod_deflate.c>',
			'  AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript',
			'  AddOutputFilterByType DEFLATE application/javascript application/x-javascript application/json',
			'  AddOutputFilterByType DEFLATE application/xml application/xhtml+xml application/rss+xml',
			'  AddOutputFilterByType DEFLATE image/svg+xml font/ttf font/otf application/font-woff application/font-woff2',
			'</IfModule>',
		) : array();

		/**
		 * Filter the Apache compression rules written to .htaccess.
		 *
		 * The core engine emits GZIP (mod_deflate) when GZIP is enabled.
		 * Add-ons (xspeed-pro Brotli module) append their own
		 * <IfModule mod_brotli.c> block so Brotli is served where supported,
		 * GZIP otherwise. The base array is empty when GZIP is off, so an
		 * add-on can be the sole contributor. Listeners MUST return the full
		 * rules array (append, don't replace).
		 *
		 * @param string[] $rules        Lines written inside the xSpeed GZIP marker block.
		 * @param bool     $gzip_enabled Whether GZIP's own base rules are included.
		 */
		return (array) apply_filters( 'xspeed_compression_apache_rules', $rules, $gzip_enabled );
	}

	/**
	 * nginx config snippet for users to paste into their server block.
	 */
	public static function nginx_snippet() {
		$snippet = implode(
			"\n",
			array(
				'gzip on;',
				'gzip_vary on;',
				'gzip_min_length 1024;',
				'gzip_proxied any;',
				'gzip_comp_level 6;',
				'gzip_types',
				'  text/plain text/css text/xml text/javascript',
				'  application/javascript application/json application/xml',
				'  application/xml+rss application/xhtml+xml',
				'  image/svg+xml font/ttf font/otf application/font-woff application/font-woff2;',
			)
		);

		/**
		 * Filter the nginx compression snippet shown for manual config.
		 *
		 * Core emits the GZIP directives. Add-ons (xspeed-pro Brotli
		 * module) append an ngx_brotli block so users can paste Brotli +
		 * GZIP fallback in one go. Return the full snippet string.
		 *
		 * @param string $snippet The nginx directives block.
		 */
		return (string) apply_filters( 'xspeed_compression_nginx_snippet', $snippet );
	}

	/**
	 * Does the origin actually serve a GZIP-encoded homepage?
	 *
	 *   true  — proven: the response was gzipped.
	 *   false — proven otherwise: we reached the site and it wasn't.
	 *   null  — no verdict: the loopback never completed (firewalled,
	 *           TLS failure, timeout, WAF answering instead of the origin).
	 *
	 * The third state is the point (issue #18). The old signature folded
	 * "couldn't ask" into "answer is no" and cached that for an hour, which
	 * pinned a permanent "GZIP enabled but not active on the server" warning
	 * onto sites whose gzip was demonstrably fine — the loopback was the
	 * only broken thing. Only a *proven* false may nag the user. Mirrors the
	 * inconclusive/active split already used by the rewrite probe.
	 *
	 * A verdict is cached for an hour because the probe costs a full HTTP
	 * request to home_url() — too slow to run on every /status hit. A
	 * non-verdict is cached for a minute only, so a transient blip resolves
	 * itself on the next page load instead of sticking around.
	 *
	 * @return bool|null
	 */
	public static function probe_active() {
		$cached = get_transient( 'xspeed_gzip_active' );
		if ( '?' === $cached ) {
			return null;
		}
		if ( false !== $cached ) {
			return '1' === $cached;
		}

		$res = wp_remote_get(
			home_url( '/' ),
			array(
				'headers'   => array( 'Accept-Encoding' => 'gzip' ),
				// 3s wasn't enough to pull a full homepage on a busy shared
				// host, and every timeout used to read as "gzip is broken".
				'timeout'   => 5,
				// Loopback to our own hostname; staging boxes routinely have
				// a self-signed or mismatched cert. Same call the sibling
				// probes make (Browser_Cache::probe_headers_present()).
				'sslverify' => false,
				// Keep the payload exactly as it came off the wire, so the
				// gzip magic-byte fallback below still has something to
				// look at if a transport strips Content-Encoding after
				// decoding. See Cache_Benchmark::measure().
				'decompress' => false,
			)
		);

		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			set_transient( 'xspeed_gzip_active', '?', MINUTE_IN_SECONDS );
			return null;
		}

		// wp_remote_retrieve_header() hands back a STRING for a single
		// header but an ARRAY when it appears twice — routine behind a
		// CDN or proxy. The old is_string() test threw those away and
		// reported "not gzipped". (Same trap as FBS-82141.)
		$raw = wp_remote_retrieve_header( $res, 'content-encoding' );
		$enc = is_array( $raw ) ? implode( ', ', $raw ) : (string) $raw;

		$active = false !== stripos( $enc, 'gzip' );
		if ( ! $active ) {
			// Header absent — a transport that decodes and drops it would
			// look identical to a server that never compressed. The raw
			// body settles it: a gzip stream starts with 0x1f 0x8b.
			$body   = wp_remote_retrieve_body( $res );
			$active = is_string( $body ) && 0 === strncmp( $body, "\x1f\x8b", 2 );
		}

		set_transient( 'xspeed_gzip_active', $active ? '1' : '0', HOUR_IN_SECONDS );
		return $active;
	}
}
