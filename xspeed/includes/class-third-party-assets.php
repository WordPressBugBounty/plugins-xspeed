<?php
/**
 * Third_Party_Assets: mirrors allow-listed third-party CSS and JS onto the
 * site's own origin.
 *
 * A stylesheet or script on someone else's host is outside every lever xSpeed
 * has: it cannot be combined, cannot sit behind the CDN, cannot get a
 * far-future cache header, and the browser pays a fresh connection to reach
 * it. When it blocks rendering, that connection is the first paint. Mirroring
 * the file and pointing the tag at the copy removes all of it.
 *
 * The seam is the page HTML on the cache-write path. A tag whose URL has a
 * stored copy is rewritten at once. A tag with none is left alone and queued;
 * a cron event fetches the file later, so no visitor waits for a download.
 * When a copy lands, the page cache is cleared so the next build picks it up.
 *
 * Safety rules, none of them optional:
 *   - Only hosts on the allow-list are ever fetched. An empty list mirrors
 *     nothing. A redirect is followed only to another allow-listed host.
 *   - https only, no port, no credentials in the URL.
 *   - A size cap, a content-type check, and a refusal of anything that looks
 *     like an HTML error page.
 *   - A tag that carries `integrity` is mirrored only when the downloaded bytes
 *     match it, and keeps the attribute, so a swapped file is refused.
 *   - A failed refresh keeps the last good copy.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Third_Party_Assets {

	public const INDEX_OPTION = 'xspeed_tpa_index';
	public const FETCH_HOOK   = 'xspeed_tpa_fetch';
	public const REFRESH_HOOK = 'xspeed_tpa_refresh';

	/** Most assets one site may mirror. A page rarely loads more than a dozen. */
	public const MAX_ENTRIES = 200;

	/** A copy no page has used for this long is dropped. */
	public const UNUSED_DAYS = 60;

	/** Assets fetched per cron run, so one run never holds a request for long. */
	public const FETCH_BATCH = 3;

	/** Copies re-checked per refresh run. A follow-up run takes the rest. */
	public const REFRESH_BATCH = 5;

	/**
	 * Hosts that serve versioned static libraries. The list is data to match
	 * page tags against. The plugin never loads a file from these hosts on a
	 * page, and it contacts one only after an administrator turns the module on.
	 */
	// phpcs:disable PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- host names to match, not assets the plugin loads.
	public const DEFAULT_HOSTS = array(
		'cdnjs.cloudflare.com',
		'cdn.jsdelivr.net',
		'unpkg.com',
		'code.jquery.com',
		'ajax.googleapis.com',
		'use.fontawesome.com',
		'maxcdn.bootstrapcdn.com',
		'stackpath.bootstrapcdn.com',
		'cdn.datatables.net',
	);
	// phpcs:enable PluginCheck.CodeAnalysis.Offloading.OffloadedContent

	/** Query keys that only carry a version. Any other key may vary per request. */
	private const VERSION_QUERY_KEYS = array( 'ver', 'v', 'version' );

	/** Content types a stylesheet or script may be served as. */
	private const CONTENT_TYPES = array(
		'text/css',
		'text/javascript',
		'application/javascript',
		'application/x-javascript',
		'application/ecmascript',
	);

	/**
	 * Clean a user-entered host list: lower case, no scheme or path, a real
	 * hostname, no IP literal, no duplicates.
	 *
	 * @param array<int,mixed> $raw Entries as saved.
	 * @return string[]
	 */
	public static function normalize_hosts( array $raw ): array {
		$hosts = array();
		foreach ( $raw as $entry ) {
			$host = strtolower( trim( (string) $entry ) );
			if ( '' === $host ) {
				continue;
			}
			if ( false !== strpos( $host, '://' ) || 0 === strpos( $host, '//' ) ) {
				$parsed = wp_parse_url( 0 === strpos( $host, '//' ) ? 'https:' . $host : $host );
				$host   = is_array( $parsed ) && isset( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';
			}
			$host = rtrim( $host, '.' );
			if ( 1 !== preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host ) ) {
				continue;
			}
			$hosts[ $host ] = true;
		}
		return array_keys( $hosts );
	}

	/**
	 * Decide whether a tag URL is one this module may mirror, and normalize it.
	 *
	 * @param string   $raw   The href or src exactly as written in the page.
	 * @param string[] $hosts Allow-listed hosts, already normalized.
	 * @param bool     $css   Mirror stylesheets.
	 * @param bool     $js    Mirror scripts.
	 * @return array{url:string,ext:string,name:string}|null
	 */
	public static function candidate( string $raw, array $hosts, bool $css, bool $js ): ?array {
		$raw = trim( wp_specialchars_decode( $raw, ENT_QUOTES ) );
		if ( '' === $raw ) {
			return null;
		}
		if ( 0 === strpos( $raw, '//' ) ) {
			$raw = 'https:' . $raw;
		}
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return null;
		}
		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return null;
		}
		if ( isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( ! in_array( $host, $hosts, true ) ) {
			return null;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( 1 !== preg_match( '/\.(css|js)$/i', $path, $m ) ) {
			return null;
		}
		$ext = strtolower( $m[1] );
		if ( ( 'css' === $ext && ! $css ) || ( 'js' === $ext && ! $js ) ) {
			return null;
		}
		$query = '';
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$args = array();
			parse_str( (string) $parts['query'], $args );
			foreach ( array_keys( $args ) as $key ) {
				if ( ! in_array( strtolower( (string) $key ), self::VERSION_QUERY_KEYS, true ) ) {
					return null;
				}
			}
			$query = '?' . $parts['query'];
		}
		return array(
			'url'  => 'https://' . $host . $path . $query,
			'ext'  => $ext,
			'name' => basename( $path ),
		);
	}

	/** The index key for one source URL and its integrity value. */
	public static function key( string $url, string $integrity = '' ): string {
		return hash( 'sha1', $url . '|' . trim( $integrity ) );
	}

	/**
	 * File name for a stored copy: a short content hash, so a changed file is a
	 * new URL and the far-future cache header stays true, then the original
	 * name, so the extension and a readable hint survive.
	 */
	public static function local_name( string $content, string $name, string $ext ): string {
		$clean = (string) preg_replace( '/[^a-zA-Z0-9._-]/', '-', $name );
		$clean = substr( $clean, 0, 60 );
		if ( '.' . $ext !== strtolower( substr( $clean, -( strlen( $ext ) + 1 ) ) ) ) {
			$clean .= '.' . $ext;
		}
		return substr( hash( 'sha256', $content ), 0, 12 ) . '-' . $clean;
	}

	/**
	 * Prepare downloaded bytes for storage. A stylesheet gets its relative
	 * references pointed back at the original host, which changes the bytes. A
	 * script is stored as it came.
	 *
	 * @return array{0:string,1:bool} The bytes to store, and whether they differ
	 *                                from what was downloaded.
	 */
	public static function finalize_copy( string $body, string $ext, string $final_url ): array {
		if ( 'css' !== $ext ) {
			return array( $body, false );
		}
		$new = self::absolutize_css( $body, $final_url );
		return array( $new, $new !== $body );
	}

	/**
	 * Does a script work out its own location, or load files by a path relative
	 * to itself? Such a script breaks when copied to another directory, because
	 * the files it asks for are never in the page and so are never copied.
	 */
	public static function loads_relative_files( string $js ): bool {
		// A reference to the running script's own element or URL, in any spelling
		// (`document.currentScript`, `document["currentScript"]`, a destructured
		// `currentScript`), a dynamic `import(` even with a comment before the
		// bracket, the module's own URL, the usual bundler hooks, and the
		// older "the last script tag is me" idiom.
		return 1 === preg_match(
			'/currentScript|import\.meta|__webpack_require__\.p\b|webpackJsonp|webpackChunk|System\.import\s*\(|importScripts\s*\(|\bimport(?:\s|\/\*[\s\S]*?\*\/|\/\/[^\r\n]*(?:\r|\n|\xE2\x80[\xA8\xA9]))*\(|scriptDirectory|document\.scripts\b|getElementsByTagName\(\s*["\']script["\']\s*\)[\s\S]{0,120}?(?:\.src\b|\.length\b)/',
			$js
		);
	}

	/** `css` or `js` for a source URL, or an empty string. */
	public static function ext_of( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return 1 === preg_match( '/\.(css|js)$/i', $path, $m ) ? strtolower( $m[1] ) : '';
	}

	/**
	 * Point every relative reference inside a stylesheet back at the host it came
	 * from. The copy lives on another path, so `url(../webfonts/a.woff2)` would
	 * otherwise resolve against the site and 404.
	 */
	public static function absolutize_css( string $css, string $base_url ): string {
		$css = (string) preg_replace_callback(
			'/url\(\s*(["\']?)([^)"\']*?)\1\s*\)/i',
			function ( array $m ) use ( $base_url ): string {
				$abs = self::resolve_reference( $m[2], $base_url );
				return 'url(' . $m[1] . $abs . $m[1] . ')';
			},
			$css
		);
		return (string) preg_replace_callback(
			'/@import\s+(["\'])([^"\']+)\1/i',
			function ( array $m ) use ( $base_url ): string {
				return '@import ' . $m[1] . self::resolve_reference( $m[2], $base_url ) . $m[1];
			},
			$css
		);
	}

	/**
	 * Resolve one reference against a base URL. Absolute, data: and fragment
	 * references come back unchanged.
	 */
	public static function resolve_reference( string $ref, string $base_url ): string {
		$ref = trim( $ref );
		if ( '' === $ref || '#' === $ref[0] || 0 === stripos( $ref, 'data:' ) || 1 === preg_match( '#^[a-z][a-z0-9+.-]*:#i', $ref ) ) {
			return $ref;
		}
		if ( 0 === strpos( $ref, '//' ) ) {
			return 'https:' . $ref;
		}
		$base = wp_parse_url( $base_url );
		if ( ! is_array( $base ) || empty( $base['host'] ) ) {
			return $ref;
		}
		$origin = 'https://' . $base['host'];
		if ( '?' === $ref[0] ) {
			// A query-only reference replaces the base's query and keeps its path.
			return $origin . ( isset( $base['path'] ) ? (string) $base['path'] : '/' ) . $ref;
		}
		if ( '/' === $ref[0] ) {
			return $origin . $ref;
		}
		$dir      = isset( $base['path'] ) ? substr( (string) $base['path'], 0, (int) strrpos( (string) $base['path'], '/' ) + 1 ) : '/';
		$combined = $dir . $ref;
		$query    = '';
		$qpos     = strpos( $combined, '?' );
		if ( false !== $qpos ) {
			$query    = substr( $combined, $qpos );
			$combined = substr( $combined, 0, $qpos );
		}
		$out = array();
		foreach ( explode( '/', $combined ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $out );
			} elseif ( '.' !== $segment ) {
				$out[] = $segment;
			}
		}
		$path = implode( '/', $out );
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		return $origin . $path . $query;
	}

	/**
	 * Check downloaded bytes against a Subresource Integrity value. The strongest
	 * algorithm present decides, as in a browser. A value that holds no
	 * recognized token fails, so a malformed attribute never lets a file through.
	 */
	public static function integrity_matches( string $bytes, string $integrity ): bool {
		$integrity = trim( $integrity );
		if ( '' === $integrity ) {
			return true;
		}
		$ranks  = array(
			'sha256' => 1,
			'sha384' => 2,
			'sha512' => 3,
		);
		$best   = 0;
		$tokens = array();
		foreach ( preg_split( '/\s+/', $integrity ) as $token ) {
			if ( 1 !== preg_match( '/^(sha256|sha384|sha512)-([A-Za-z0-9+\/=_-]+)/', (string) $token, $m ) ) {
				continue;
			}
			$rank = $ranks[ $m[1] ];
			if ( $rank > $best ) {
				$best   = $rank;
				$tokens = array();
			}
			if ( $rank === $best ) {
				$tokens[] = array( $m[1], $m[2] );
			}
		}
		foreach ( $tokens as $pair ) {
			// Compare without padding and with the standard alphabet, so a
			// URL-safe or unpadded value still matches.
			$expected = rtrim( strtr( $pair[1], '-_', '+/' ), '=' );
			$actual   = rtrim( base64_encode( hash( $pair[0], $bytes, true ) ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a digest for comparison, not obfuscation.
			if ( hash_equals( $actual, $expected ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fetch one allow-listed asset. Never throws; a failure comes back as an
	 * error code the status report can show.
	 *
	 * Redirects are followed by hand so each hop is checked against the same
	 * rules as the first request.
	 *
	 * @param string   $url       A URL that already passed candidate().
	 * @param string[] $hosts     Allow-listed hosts.
	 * @param int      $max_bytes Size cap.
	 * @return array{ok:bool,body?:string,final_url?:string,error?:string}
	 */
	public static function fetch( string $url, array $hosts, int $max_bytes ): array {
		$current = $url;
		for ( $hop = 0; $hop <= 2; $hop++ ) {
			$c = self::candidate( $current, $hosts, true, true );
			if ( null === $c ) {
				return array(
					'ok'    => false,
					'error' => 'blocked_host',
				);
			}
			$response = wp_safe_remote_get(
				$c['url'],
				array(
					'timeout'             => 10,
					'redirection'         => 0,
					'limit_response_size' => $max_bytes + 1,
					'user-agent'          => 'xSpeed asset mirror',
					'headers'             => array( 'Accept' => 'text/css,application/javascript,*/*;q=0.1' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return array(
					'ok'    => false,
					'error' => (string) $response->get_error_code(),
				);
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				if ( '' === $location ) {
					return array(
						'ok'    => false,
						'error' => 'bad_redirect',
					);
				}
				$current = self::resolve_reference( $location, $c['url'] );
				continue;
			}
			if ( 200 !== $code ) {
				return array(
					'ok'    => false,
					'error' => 'http_' . $code,
				);
			}
			$type = strtolower( trim( (string) strtok( (string) wp_remote_retrieve_header( $response, 'content-type' ), ';' ) ) );
			if ( ! in_array( $type, self::CONTENT_TYPES, true ) ) {
				return array(
					'ok'    => false,
					'error' => 'content_type',
				);
			}
			$body = (string) wp_remote_retrieve_body( $response );
			if ( '' === trim( $body ) ) {
				return array(
					'ok'    => false,
					'error' => 'empty',
				);
			}
			if ( strlen( $body ) > $max_bytes ) {
				return array(
					'ok'    => false,
					'error' => 'too_large',
				);
			}
			if ( '<' === ltrim( $body )[0] ) {
				return array(
					'ok'    => false,
					'error' => 'looks_like_html',
				);
			}
			return array(
				'ok'        => true,
				'body'      => $body,
				'final_url' => $c['url'],
			);
		}
		return array(
			'ok'    => false,
			'error' => 'too_many_redirects',
		);
	}

	/**
	 * Rewrite mirrored assets in a page.
	 *
	 * Only <link rel="stylesheet"> and <script src> tags are touched. Comments,
	 * <noscript> blocks and inline script bodies pass through untouched, as does
	 * any tag marked data-no-optimize or data-xspeed-keep.
	 *
	 * @param string   $html    The page.
	 * @param string[] $hosts   Allow-listed hosts.
	 * @param bool     $css     Mirror stylesheets.
	 * @param bool     $js      Mirror scripts.
	 * @param callable $resolve fn( array $candidate, string $integrity ): string|array|null
	 *                          Returns the local URL when a copy is ready, or
	 *                          array( 'url' => ..., 'drop_integrity' => bool ).
	 *                          It is also where an unready asset gets queued.
	 */
	public static function rewrite_html( string $html, array $hosts, bool $css, bool $js, callable $resolve ): string {
		if ( ( ! $css && ! $js ) || array() === $hosts ) {
			return $html;
		}
		$parts = preg_split(
			'#(<!--.*?-->|<noscript\b.*?</noscript>|<script\b[^>]*\bsrc\s*=[^>]*>\s*</script>|<script\b[^>]*>.*?</script>|<link\b[^>]*>)#is',
			$html,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);
		if ( ! is_array( $parts ) ) {
			return $html;
		}
		foreach ( $parts as $i => $part ) {
			if ( 1 !== ( $i % 2 ) ) {
				continue;
			}
			$tag = (string) $part;
			if ( 0 === stripos( $tag, '<link' ) ) {
				$parts[ $i ] = self::rewrite_tag( $tag, 'href', $hosts, $css, $js, $resolve, true );
			} elseif ( 0 === stripos( $tag, '<script' )
				// A script with a source is its opening tag and an empty body. A
				// segment with more is an inline script whose body holds text
				// that only looks like a tag. xSpeed's own Delay JS moves `src`
				// to `data-xs-src` before this pass runs, so that name counts too.
				// Another plugin's `data-src` does not.
				&& 1 === preg_match( '/^<script\b[^>]*(?<![\w-])(?:data-xs-)?src\s*=[^>]*>\s*<\/script>\s*$/is', $tag )
			) {
				$parts[ $i ] = self::rewrite_tag( $tag, '(?:data-xs-)?src', $hosts, $css, $js, $resolve, false );
			}
		}
		return implode( '', $parts );
	}

	/**
	 * Rewrite one tag.
	 *
	 * @param string   $tag      One <link> or <script src> tag.
	 * @param string   $attr     A pattern for the attribute that holds the URL: `href`, or `(?:data-xs-)?src`.
	 * @param string[] $hosts    Allow-listed hosts.
	 * @param bool     $css      Mirror stylesheets.
	 * @param bool     $js       Mirror scripts.
	 * @param callable $resolve  See rewrite_html().
	 * @param bool     $is_link  Whether the tag is a <link>.
	 */
	private static function rewrite_tag( string $tag, string $attr, array $hosts, bool $css, bool $js, callable $resolve, bool $is_link ): string {
		if ( 1 === preg_match( '/\bdata-(no-optimize|xspeed-keep|xspeed-origin)\b/i', $tag ) ) {
			return $tag;
		}
		if ( $is_link ) {
			if ( 1 !== preg_match( '/(?<![\w-])rel\s*=\s*(["\']?)([^"\'>]*)\1/i', $tag, $rel ) || false === stripos( $rel[2], 'stylesheet' ) ) {
				return $tag;
			}
		} elseif ( 1 === preg_match( '/(?<![\w-])type\s*=\s*(["\']?)([^"\'>\s]*)\1/i', $tag, $type ) && ! in_array( strtolower( $type[2] ), array( '', 'text/javascript', 'application/javascript' ), true ) ) {
			// Modules and JSON blocks are not plain scripts.
			return $tag;
		}
		if ( 1 !== preg_match( '/(?<![\w-])(' . $attr . ')\s*=\s*(["\'])(.*?)\2/is', $tag, $url ) ) {
			return $tag;
		}
		$candidate = self::candidate( $url[3], $hosts, $css, $js );
		if ( null === $candidate ) {
			return $tag;
		}
		$integrity = 1 === preg_match( '/(?<![\w-])integrity\s*=\s*(["\'])(.*?)\1/is', $tag, $sri ) ? trim( $sri[2] ) : '';
		$resolved  = $resolve( $candidate, $integrity );
		// A resolver answers with a URL, or with a URL and a request to drop the
		// integrity attribute (the stored bytes no longer match the original).
		$drop  = is_array( $resolved ) && ! empty( $resolved['drop_integrity'] );
		$local = is_array( $resolved ) ? (string) ( $resolved['url'] ?? '' ) : (string) $resolved;
		if ( '' === $local ) {
			return $tag;
		}
		if ( $drop ) {
			$tag = (string) preg_replace( '/\s+integrity\s*=\s*(["\']).*?\1/is', '', $tag );
		}
		$quote = $url[2];
		$new   = $url[1] . '=' . $quote . esc_url( $local ) . $quote;
		// A callback, not a replacement string, so a `$` or `\` in a URL is
		// never read as a back-reference.
		$result = preg_replace_callback(
			'/(?<![\w-])(' . $attr . ')\s*=\s*(["\'])(.*?)\2/is',
			function () use ( $new ): string {
				return $new;
			},
			$tag,
			1
		);
		if ( ! is_string( $result ) ) {
			return $tag;
		}
		// Record where the copy came from, so a reader of the page source can
		// tell a mirrored file from a local one.
		// The attribute goes on the opening tag. A script tag carries its closing
		// tag in the same segment, so the end of the string is the wrong place.
		$origin = ' data-xspeed-origin="' . esc_attr( $candidate['url'] ) . '"';
		return (string) preg_replace_callback(
			'/^(<(?:link|script)\b[^>]*?)(\s*\/?>)/is',
			function ( array $m ) use ( $origin ): string {
				return $m[1] . $origin . $m[2];
			},
			$result,
			1
		);
	}

	/** Directory the copies live in. */
	public static function dir(): string {
		$upload = wp_upload_dir( null, false );
		return rtrim( (string) $upload['basedir'], '/' ) . '/xspeed/proxied';
	}

	/** URL of the directory the copies live in. */
	public static function base_url(): string {
		$upload = wp_upload_dir( null, false );
		$base   = rtrim( (string) $upload['baseurl'], '/' );
		// The uploads address stays http on some sites served over https, and
		// the browser blocks an http file on an https page as mixed content.
		// is_ssl() reads false behind a TLS-terminating proxy, so an https home
		// URL counts too. An https address is never turned back into http.
		if ( is_ssl() || 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			$base = (string) preg_replace( '#^http://#i', 'https://', $base );
		}
		return $base . '/xspeed/proxied';
	}

	/**
	 * The stored index. One record per source URL and integrity value.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function index(): array {
		$index = get_option( self::INDEX_OPTION, array() );
		return is_array( $index ) ? $index : array();
	}

	/** @param array<string,array<string,mixed>> $index The index to store. */
	private static function save_index( array $index ): void {
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Apply changes to the stored index without losing a record another request
	 * wrote in the meantime.
	 *
	 * The stored value is read past the options cache, because this process may
	 * have read the option long before (a download takes seconds) and another
	 * request may have queued a file since. The write is then a compare-and-swap:
	 * it applies only if the stored value is still the one it was built from, and
	 * otherwise the changes are laid over the newer value and tried again.
	 *
	 * @param array<string,array<string,mixed>> $changes Records to set, by key.
	 * @param string[]                          $removed Keys to drop.
	 * @return array<string,array<string,mixed>> The saved index.
	 */
	private static function merge_save( array $changes, array $removed ): array {
		global $wpdb;
		$latest = array();
		for ( $try = 0; $try < 5; ++$try ) {
			wp_cache_delete( self::INDEX_OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reads the stored index past the options cache, so a record another request queued meanwhile is not overwritten; the table name is core's.
			$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::INDEX_OPTION ) );
			$latest = null === $stored ? array() : maybe_unserialize( $stored );
			if ( ! is_array( $latest ) ) {
				$latest = array();
			}
			foreach ( $removed as $key ) {
				unset( $latest[ $key ] );
			}
			foreach ( $changes as $key => $rec ) {
				$latest[ $key ] = $rec;
			}
			if ( null === $stored ) {
				if ( add_option( self::INDEX_OPTION, $latest, '', false ) ) {
					return $latest;
				}
				continue;
			}
			$serialized = maybe_serialize( $latest );
			if ( $serialized === $stored ) {
				return $latest;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- compare-and-swap: applies only while no other request has changed the index since it was read above; the table name is core's.
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $serialized, self::INDEX_OPTION, $stored ) );
			if ( $updated ) {
				wp_cache_delete( self::INDEX_OPTION, 'options' );
				return $latest;
			}
		}
		// Five lost races in a row. Save anyway, so progress is not dropped.
		self::save_index( $latest );
		return $latest;
	}

	/**
	 * The local URL for an asset, or null when no usable copy exists. A tag with
	 * no copy is queued so the next cron run fetches it.
	 *
	 * @param string $url       The normalized source URL.
	 * @param string $integrity The tag's integrity value, or an empty string.
	 * @param int    $max_bytes The current size cap. A copy stored under a larger
	 *                          cap is not used, so lowering the cap takes effect.
	 *                          Zero means no cap.
	 * @return array{url:string,drop_integrity:bool}|null
	 */
	public static function local_url_or_queue( string $url, string $integrity, int $max_bytes = 0 ): ?array {
		$key   = self::key( $url, $integrity );
		$index = self::index();
		if ( isset( $index[ $key ] ) ) {
			$rec    = $index[ $key ];
			$status = (string) ( $rec['status'] ?? '' );
			if ( 'ok' === $status && ! empty( $rec['file'] ) && is_file( self::dir() . '/' . $rec['file'] ) ) {
				if ( $max_bytes > 0 && (int) ( $rec['size'] ?? 0 ) > $max_bytes ) {
					return null;
				}
				// Note that a page still uses this copy, at most once a day, so
				// the index is not written on every page build.
				if ( time() - (int) ( $rec['seen_at'] ?? 0 ) > DAY_IN_SECONDS ) {
					$rec['seen_at'] = time();
					self::merge_save( array( $key => $rec ), array() );
				}
				return array(
					'url'            => self::base_url() . '/' . rawurlencode( (string) $rec['file'] ),
					// The stored bytes differ from the original, so the original's
					// integrity value no longer describes them.
					'drop_integrity' => '' !== $integrity && ! empty( $rec['bytes_changed'] ),
				);
			}
			if ( 'pending' === $status ) {
				// Cron may have been cleared; make sure a run is queued.
				self::schedule_fetch();
				return null;
			}
			if ( 'ok' !== $status ) {
				return null;
			}
			// A copy whose file is gone. The record already holds its slot, so a
			// full index does not stop it being queued again.
			$rec['status']    = 'pending';
			$rec['file']      = '';
			$rec['failures']  = 0;
			$rec['queued_at'] = time();
			self::merge_save( array( $key => $rec ), array() );
			self::schedule_fetch();
			return null;
		}
		if ( count( $index ) >= self::MAX_ENTRIES ) {
			return null;
		}
		self::merge_save(
			array(
				$key => array(
					'url'       => $url,
					'integrity' => $integrity,
					'status'    => 'pending',
					'failures'  => 0,
					'queued_at' => time(),
					'seen_at'   => time(),
				),
			),
			array()
		);
		self::schedule_fetch();
		return null;
	}

	/** Queue a single fetch run soon, unless one is already queued. */
	public static function schedule_fetch( int $delay = 15 ): void {
		if ( ! wp_next_scheduled( self::FETCH_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::FETCH_HOOK );
		}
	}

	/** Queue the daily refresh. */
	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::REFRESH_HOOK );
		}
	}

	/** Queue a follow-up refresh run, when one batch did not finish the work. */
	public static function schedule_refresh_followup( int $delay = 60 ): void {
		if ( false === wp_next_scheduled( self::REFRESH_HOOK, array( 'followup' ) ) ) {
			wp_schedule_single_event( time() + $delay, self::REFRESH_HOOK, array( 'followup' ) );
		}
	}

	/** Drop every scheduled event. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::FETCH_HOOK );
		wp_clear_scheduled_hook( self::REFRESH_HOOK );
		wp_clear_scheduled_hook( self::REFRESH_HOOK, array( 'followup' ) );
	}

	/**
	 * Whether a record's type is switched on.
	 *
	 * @param array<string,mixed> $rec The record.
	 */
	private static function type_enabled( array $rec, bool $css, bool $js ): bool {
		$ext = self::ext_of( (string) ( $rec['url'] ?? '' ) );
		return ( 'css' === $ext && $css ) || ( 'js' === $ext && $js );
	}

	/**
	 * Fetch queued assets, a few per run.
	 *
	 * Records of a type that is switched off are left alone, so turning a switch
	 * off stops its downloads.
	 *
	 * @param string[] $hosts     Allow-listed hosts.
	 * @param int      $max_bytes Size cap.
	 * @param bool     $css       Stylesheets are switched on.
	 * @param bool     $js        Scripts are switched on.
	 * @param int      $limit     Most records to try in this call.
	 * @return int Copies stored.
	 */
	public static function run_pending( array $hosts, int $max_bytes, bool $css = true, bool $js = true, int $limit = self::FETCH_BATCH ): int {
		$now     = time();
		$done    = 0;
		$tried   = 0;
		$more    = false;
		$changes = array();
		foreach ( self::index() as $key => $rec ) {
			$status = (string) ( $rec['status'] ?? '' );
			$due    = 'pending' === $status || ( 'failed' === $status && (int) ( $rec['next_try'] ?? 0 ) <= $now );
			if ( ! $due || ! self::type_enabled( $rec, $css, $js ) ) {
				continue;
			}
			if ( $tried >= $limit ) {
				$more = true;
				continue;
			}
			++$tried;
			$changes[ $key ] = self::store_one( $rec, $hosts, $max_bytes );
			if ( 'ok' === $changes[ $key ]['status'] ) {
				++$done;
			}
		}
		if ( array() !== $changes ) {
			self::merge_save( $changes, array() );
		}
		if ( $done > 0 ) {
			Cache::purge_all( 'third-party-assets' );
		}
		if ( $more ) {
			self::schedule_fetch( 30 );
		}
		return $done;
	}

	/**
	 * Re-fetch stored copies older than the refresh interval, a few per call, and
	 * drop copies no page has used for a long time. A failed refresh keeps the
	 * last good copy and records why.
	 *
	 * @param string[] $hosts     Allow-listed hosts.
	 * @param int      $max_bytes Size cap.
	 * @param int      $days      Refresh interval.
	 * @param bool     $css       Stylesheets are switched on.
	 * @param bool     $js        Scripts are switched on.
	 * @param int      $limit     Most copies to re-check in this call.
	 * @return array{changed:int,more:bool} Copies whose content changed, and
	 *                                      whether more are still due.
	 */
	public static function refresh_due( array $hosts, int $max_bytes, int $days, bool $css = true, bool $js = true, int $limit = self::REFRESH_BATCH ): array {
		$cutoff  = time() - max( 1, $days ) * DAY_IN_SECONDS;
		$unused  = time() - self::UNUSED_DAYS * DAY_IN_SECONDS;
		$changed = 0;
		$tried   = 0;
		$more    = false;
		$changes = array();
		$removed = array();
		foreach ( self::index() as $key => $rec ) {
			$last_use = (int) ( $rec['seen_at'] ?? $rec['fetched_at'] ?? $rec['queued_at'] ?? 0 );
			if ( $last_use > 0 && $last_use < $unused ) {
				$removed[] = $key;
				continue;
			}
			if ( 'ok' !== ( $rec['status'] ?? '' ) || (int) ( $rec['checked_at'] ?? 0 ) > $cutoff || ! self::type_enabled( $rec, $css, $js ) ) {
				continue;
			}
			if ( $tried >= $limit ) {
				$more = true;
				continue;
			}
			++$tried;
			$before          = (string) ( $rec['file'] ?? '' );
			$changes[ $key ] = self::store_one( $rec, $hosts, $max_bytes );
			if ( (string) ( $changes[ $key ]['file'] ?? '' ) !== $before ) {
				++$changed;
			}
		}
		$saved = self::merge_save( $changes, $removed );
		self::sweep( $saved );
		if ( $changed > 0 ) {
			Cache::purge_all( 'third-party-assets' );
		}
		return array(
			'changed' => $changed,
			'more'    => $more,
		);
	}

	/**
	 * Fetch and store one asset, and return its updated record.
	 *
	 * @param array<string,mixed> $rec       The record.
	 * @param string[]            $hosts     Allow-listed hosts.
	 * @param int                 $max_bytes Size cap.
	 * @return array<string,mixed>
	 */
	private static function store_one( array $rec, array $hosts, int $max_bytes ): array {
		$url       = (string) ( $rec['url'] ?? '' );
		$integrity = (string) ( $rec['integrity'] ?? '' );
		$old_file  = 'ok' === ( $rec['status'] ?? '' ) ? (string) ( $rec['file'] ?? '' ) : '';
		$had_copy  = '' !== $old_file;
		$candidate = self::candidate( $url, $hosts, true, true );
		$now       = time();
		$fail      = function ( string $error ) use ( $rec, $had_copy, $now ): array {
			$failures         = (int) ( $rec['failures'] ?? 0 ) + 1;
			$rec['failures']  = $failures;
			$rec['error']     = $error;
			$rec['failed_at'] = $now;
			$rec['next_try']  = $now + min( 3 * DAY_IN_SECONDS, $failures * 6 * HOUR_IN_SECONDS );
			if ( $had_copy ) {
				// The last good copy keeps serving; only the report changes.
				$rec['checked_at'] = $now;
			} else {
				$rec['status'] = 'failed';
			}
			return $rec;
		};
		if ( null === $candidate ) {
			return $fail( 'blocked_host' );
		}
		$got = self::fetch( $candidate['url'], $hosts, $max_bytes );
		if ( ! $got['ok'] ) {
			return $fail( (string) ( $got['error'] ?? 'fetch_failed' ) );
		}
		$body = (string) $got['body'];
		if ( '' !== $integrity && ! self::integrity_matches( $body, $integrity ) ) {
			return $fail( 'integrity_mismatch' );
		}
		if ( 'js' === $candidate['ext'] && self::loads_relative_files( $body ) ) {
			if ( $had_copy ) {
				return $fail( 'loads_relative_files' );
			}
			// A decision, not a failure: this script stays on its host and is
			// not fetched again.
			$rec['status']     = 'skipped';
			$rec['error']      = 'loads_relative_files';
			$rec['checked_at'] = $now;
			return $rec;
		}
		list( $stored, $rewritten ) = self::finalize_copy( $body, $candidate['ext'], (string) $got['final_url'] );
		$name                       = self::local_name( $stored, $candidate['name'], $candidate['ext'] );
		if ( ! self::write_file( $name, $stored ) ) {
			return $fail( 'write_failed' );
		}
		return array(
			'url'           => $candidate['url'],
			'integrity'     => $integrity,
			'status'        => 'ok',
			'file'          => $name,
			// The file this one replaced stays for one more cycle, because pages
			// cached before the change may still name it. A check that finds the
			// content unchanged clears it, so old files do not pile up.
			'previous'      => $had_copy && $old_file !== $name ? $old_file : '',
			'size'          => strlen( $stored ),
			'bytes_changed' => $rewritten,
			'fetched_at'    => $now,
			'checked_at'    => $now,
			'seen_at'       => (int) ( $rec['seen_at'] ?? $rec['queued_at'] ?? $now ),
			'failures'      => 0,
		);
	}

	/** Write a copy atomically, so a reader never sees half a file. */
	private static function write_file( string $name, string $body ): bool {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$path = $dir . '/' . $name;
		$tmp  = $path . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- front end and cron have no WP_Filesystem credentials; the file is written to a temp name and renamed.
		if ( false === file_put_contents( $tmp, $body, LOCK_EX ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- atomic publish of a file this module just wrote; WP_Filesystem::move() is not atomic and needs admin credentials. A failure is reported through the return value.
		return @rename( $tmp, $path );
	}

	/**
	 * Delete files no record points at. A record's previous copy stays one more
	 * cycle, because pages cached before the change may still name it.
	 *
	 * @param array<string,array<string,mixed>> $index The index.
	 */
	public static function sweep( array $index ): void {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$keep = array();
		foreach ( $index as $rec ) {
			foreach ( array( 'file', 'previous' ) as $field ) {
				if ( ! empty( $rec[ $field ] ) ) {
					$keep[ (string) $rec[ $field ] ] = true;
				}
			}
		}
		$files = scandir( $dir );
		if ( ! is_array( $files ) ) {
			return;
		}
		foreach ( $files as $file ) {
			if ( '.' === $file || '..' === $file || isset( $keep[ $file ] ) ) {
				continue;
			}
			wp_delete_file( $dir . '/' . $file );
		}
	}

	/** Delete every copy and the index, and clear the page cache. */
	public static function clear(): int {
		$count = count( self::index() );
		delete_option( self::INDEX_OPTION );
		self::sweep( array() );
		if ( $count > 0 ) {
			Cache::purge_all( 'third-party-assets' );
		}
		return $count;
	}
}
