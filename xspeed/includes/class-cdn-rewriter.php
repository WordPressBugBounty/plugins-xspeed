<?php
/**
 * Cdn_Rewriter — rewrites local-origin asset URLs to a user-supplied
 * CDN hostname (BunnyCDN, KeyCDN, Cloudflare R2 pull-zone, etc.).
 *
 * Assumes pull-zone CDN (CDN fetches from origin on demand); we never
 * upload anything. The user sets `cdn_url` to e.g. `cdn.example.com`
 * and we rewrite asset URLs from `https://example.com/wp-content/…`
 * to `https://cdn.example.com/wp-content/…`.
 *
 * Strategy: same buffer-pass approach as Lazy_Loader — regex over
 * specific tag attributes is ~10× faster than a full DOMDocument round
 * trip, and CDN rewriting is purely a string substitution on URLs that
 * point at the site origin. Out-of-origin URLs are left alone.
 *
 * Handled attributes: src, href, srcset, data-src, data-srcset, poster.
 * Honors extension whitelist + glob exclude patterns (reuses
 * Glob_Matcher so `*.pdf` / `/cart/*` work the same as elsewhere).
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Cdn_Rewriter {

	/** @var array|null */
	private static $opts = null;
	/** @var string|null */
	private static $home_host = null;
	/** @var string */
	private static $home_scheme = 'https';

	public const DEFAULT_EXTENSIONS = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico',
		'woff', 'woff2', 'ttf', 'otf', 'eot',
		'css', 'js',
		'mp4', 'webm', 'mp3', 'ogg',
	);

	public static function reset_state(): void {
		self::$opts        = null;
		self::$home_host   = null;
		self::$home_scheme = 'https';
	}

	/**
	 * Top-level HTML transform. Returns input unchanged if disabled or
	 * cdn_url is empty.
	 */
	public static function process_html( string $html ): string {
		if ( '' === $html ) {
			return $html;
		}
		$opts = self::opts();
		if ( empty( $opts['enabled'] ) || empty( $opts['cdn_url'] ) ) {
			return $html;
		}
		self::prime_origin();
		if ( self::is_dev_host() ) {
			return $html;
		}

		// Rewrite everything except the regions where a URL-shaped string is
		// content rather than a reference. <script> is the one with teeth:
		// inline JS that compares, signs or posts an asset path would see a
		// different origin, and anything it fetches at runtime silently
		// becomes cross-origin. <pre>/<code> are documentation — rewriting
		// them edits a tutorial's text — and <textarea> is user input.
		//
		// <style> is deliberately NOT protected: the url() pass exists to
		// rewrite inline stylesheets.
		//
		// Only the INNER TEXT is held back, not the opening tag — a
		// `<script src="…">` attribute is a genuine asset reference and must
		// still reach the CDN, while the JS between the tags must not. The
		// pattern therefore captures the body separately from the tags around
		// it.
		$parts = preg_split(
			'#(<(?:script|pre|code|textarea)\b[^>]*>)(.*?)(</(?:script|pre|code|textarea)\s*>)#is',
			$html,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		if ( ! is_array( $parts ) ) {
			// preg_split can fail on a pathological buffer (PCRE backtrack
			// limit). Rewriting everything is what we did before this guard
			// existed, so fall back to it rather than silently disabling the
			// CDN on one page.
			return self::rewrite_segment( $html, $opts );
		}

		// PREG_SPLIT_DELIM_CAPTURE yields, per match:
		//   [ text, open-tag, body, close-tag, text, … ]
		// so index % 4 === 2 is the protected body and everything else is
		// rewritable — including the open tag carrying src/href.
		foreach ( $parts as $i => $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( 2 === $i % 4 ) {
				// Protected body. One exception: JSON-escaped slashes
				// (`https:\/\/…`) are how wp_localize_script and the block
				// editor emit asset URLs, and they only ever appear inside an
				// inline script. That form is unambiguously a data payload
				// rather than code, so it is still rewritten — while plain JS
				// strings, which inline code may compare or sign, are not.
				$parts[ $i ] = self::rewrite_escaped_slash_urls( $part, $opts );
				continue;
			}
			$parts[ $i ] = self::rewrite_segment( $part, $opts );
		}

		return implode( '', $parts );
	}

	/**
	 * Apply every URL rewrite to one rewritable slice of the document.
	 *
	 * Split out of process_html() so the protected-region skipping above has
	 * something to call per segment; the passes themselves are unchanged.
	 */
	private static function rewrite_segment( string $html, array $opts ): string {
		// Rewrite src, href, poster, data-src.
		$html = preg_replace_callback(
			'#\b(src|href|poster|data-src)\s*=\s*([\'"])([^\'"]+)\2#i',
			static function ( $m ) use ( $opts ) {
				$rewritten = self::rewrite_url( $m[3], $opts );
				return $m[1] . '=' . $m[2] . $rewritten . $m[2];
			},
			$html
		);

		// Rewrite srcset / data-srcset (comma-separated `url 1x, url 2x`).
		$html = preg_replace_callback(
			'#\b(srcset|data-srcset)\s*=\s*([\'"])([^\'"]+)\2#i',
			static function ( $m ) use ( $opts ) {
				$rewritten = self::rewrite_srcset( $m[3], $opts );
				return $m[1] . '=' . $m[2] . $rewritten . $m[2];
			},
			$html
		);

		// CSS url() references — inline <style> blocks and style="" attributes.
		// Without this an inline background-image stays on the origin even
		// though its extension is in the include list.
		$html = preg_replace_callback(
			'#url\(\s*([\'"]?)([^\'")]+)\1\s*\)#i',
			static function ( $m ) use ( $opts ) {
				$rewritten = self::rewrite_url( $m[2], $opts );
				return 'url(' . $m[1] . $rewritten . $m[1] . ')';
			},
			$html
		);

		return self::rewrite_escaped_slash_urls( $html, $opts );
	}

	/**
	 * Rewrite JSON-escaped asset URLs (`http:\/\/site\/wp-content\/…`).
	 *
	 * These come from wp_localize_script and block-editor payloads — ordinary
	 * asset URLs that happen to live inside a JSON string, so without this
	 * they are the one category a whole-page pass would miss. Kept separate
	 * because it is also the only rewrite applied inside an inline <script>,
	 * where this escaped form marks a data payload rather than code.
	 */
	private static function rewrite_escaped_slash_urls( string $html, array $opts ): string {
		return (string) preg_replace_callback(
			'#https?:\\\\/\\\\/[^"\'\s\\\\]+(?:\\\\/[^"\'\s\\\\]+)*#i',
			static function ( $m ) use ( $opts ) {
				$plain     = str_replace( '\\/', '/', $m[0] );
				$rewritten = self::rewrite_url( $plain, $opts );
				if ( $rewritten === $plain ) {
					return $m[0];
				}
				return str_replace( '/', '\\/', $rewritten );
			},
			$html
		);
	}

	/**
	 * Public for tests + REST validation. Returns the rewritten URL or
	 * the input unchanged.
	 */
	public static function rewrite_url( string $url, array $opts ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return $url;
		}
		if ( null === self::$home_host ) {
			self::prime_origin();
		}
		// Skip data:, mailto:, tel:, javascript:, fragments, blob:.
		if ( preg_match( '#^(data|mailto|tel|javascript|blob|about):#i', $url ) ) {
			return $url;
		}
		if ( '#' === substr( $url, 0, 1 ) ) {
			return $url;
		}

		// Never rewrite on a local/dev host — the CDN has no origin to pull
		// from, so every rewritten asset would 404. Checked here as well as
		// in process_html() because rewrite_url() is also reached directly
		// via the wp_get_attachment_url filter.
		if ( self::is_dev_host() ) {
			return $url;
		}

		$abs = self::absolutize( $url );
		if ( null === $abs ) {
			return $url;
		}

		// Must be same origin.
		$parts = wp_parse_url( $abs );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}
		if ( strtolower( $parts['host'] ) !== self::$home_host ) {
			return $url;
		}

		$path = (string) ( $parts['path'] ?? '' );
		if ( '' === $path ) {
			return $url;
		}

		// Extension whitelist.
		$included = isset( $opts['included_extensions'] ) && is_array( $opts['included_extensions'] )
			? $opts['included_extensions']
			: self::DEFAULT_EXTENSIONS;
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' === $ext || ! in_array( $ext, array_map( 'strtolower', $included ), true ) ) {
			return $url;
		}

		// Excluded globs (reuse Glob_Matcher for *.pdf, /cart/*). Matched
		// against the FULL absolute URL as well as the bare path: matching
		// the path alone made it impossible to exclude by query string
		// (`*nocdn=1*`) or by host, which is exactly what someone reaches
		// for when one asset must stay on the origin.
		$excluded = isset( $opts['excluded_patterns'] ) && is_array( $opts['excluded_patterns'] )
			? $opts['excluded_patterns']
			: array();
		foreach ( $excluded as $pattern ) {
			if ( '' === $pattern ) {
				continue;
			}
			if ( ! class_exists( '\\XSpeed\\Glob_Matcher' ) ) {
				continue;
			}
			if ( Glob_Matcher::matches( $pattern, $path ) || Glob_Matcher::matches( $pattern, $abs ) ) {
				return $url;
			}
		}

		$cdn_host = self::normalize_host( (string) $opts['cdn_url'] );
		if ( '' === $cdn_host ) {
			return $url;
		}

		$query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return self::$home_scheme . '://' . $cdn_host . $path . $query . $fragment;
	}

	/**
	 * Rewrite each candidate URL inside an srcset descriptor list.
	 */
	public static function rewrite_srcset( string $srcset, array $opts ): string {
		$parts = preg_split( '#\s*,\s*#', trim( $srcset ) );
		if ( ! is_array( $parts ) ) {
			return $srcset;
		}
		$out = array();
		foreach ( $parts as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			// `<url> <descriptor>` — descriptor optional (1x, 2x, 800w).
			$split    = preg_split( '#\s+#', $candidate, 2 );
			$url      = $split[0];
			$descr    = isset( $split[1] ) ? ' ' . $split[1] : '';
			$rewritten = self::rewrite_url( $url, $opts );
			$out[]     = $rewritten . $descr;
		}
		return implode( ', ', $out );
	}

	/**
	 * Convert relative/scheme-relative URLs to absolute against the site
	 * origin. Returns null if we can't make sense of it.
	 */
	private static function absolutize( string $url ): ?string {
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return self::$home_scheme . ':' . $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			return self::$home_scheme . '://' . self::$home_host . $url;
		}
		// Bare relative paths like `images/x.png` — these would need a
		// base URL to resolve. The DOM rendering picked one already; we
		// can't reliably guess. Leave alone.
		return null;
	}

	/**
	 * Strip scheme + trailing slash from a user-entered CDN URL so the
	 * stored value is just a host (cdn.example.com). Tolerant of
	 * `https://cdn.example.com/`, `//cdn.example.com`, or bare host.
	 */
	public static function normalize_host( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$value = preg_replace( '#^https?://#i', '', $value );
		$value = preg_replace( '#^//#', '', $value );
		$value = rtrim( $value, '/' );
		return strtolower( $value );
	}

	/**
	 * Is this site running on a local/dev hostname?
	 *
	 * Rewriting to a CDN on `localhost` or `mysite.test` can only produce
	 * broken URLs — the CDN has nothing to pull from, so every asset 404s.
	 * A developer who leaves CDN settings switched on in a local copy of a
	 * production database would otherwise get a silently broken site with
	 * no clue why.
	 *
	 * Filterable for the rare setup where a dev-suffixed host really is
	 * publicly reachable behind a real CDN.
	 */
	public static function is_dev_host(): bool {
		if ( null === self::$home_host ) {
			self::prime_origin();
		}
		$host = (string) self::$home_host;

		// The suffixes the issue names, plus the loopback hosts. `.example` is
		// deliberately NOT here: it is the RFC 2606 documentation TLD, not a
		// local-development convention, and excluding it would be guesswork
		// about someone's real domain.
		$is_dev = ( 'localhost' === $host )
			|| ( '127.0.0.1' === $host )
			|| ( '::1' === $host )
			|| (bool) preg_match( '/\.(test|local|dev|localhost)$/i', $host );

		/**
		 * Whether to refuse CDN rewriting for this hostname.
		 *
		 * @param bool   $is_dev Whether the host looks local/dev.
		 * @param string $host   The site's hostname.
		 */
		return (bool) apply_filters( 'xspeed_cdn_is_dev_host', $is_dev, $host );
	}

	private static function prime_origin(): void {
		$home = function_exists( 'home_url' ) ? home_url() : '';
		$p    = wp_parse_url( $home );
		if ( is_array( $p ) && ! empty( $p['host'] ) ) {
			self::$home_host   = strtolower( $p['host'] );
			self::$home_scheme = isset( $p['scheme'] ) ? strtolower( $p['scheme'] ) : 'https';
		}
	}

	private static function opts(): array {
		if ( null === self::$opts ) {
			self::$opts = function_exists( 'get_option' )
				? (array) get_option( 'xspeed_module_cdn', array() )
				: array();
		}
		return self::$opts;
	}
}
