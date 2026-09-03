<?php
/**
 * XSPEED_DROPIN
 * XSPEED_DROPIN_VERSION: 8
 * Drop-in cache loader. Serves cached HTML before WordPress fully boots.
 *
 * Bump XSPEED_DROPIN_VERSION whenever this file's serve logic changes so
 * Cache::ensure_dropin_current() reinstalls it on existing sites (the
 * "is it ours?" marker alone can't tell an old copy from a new one).
 * v2: read .meta on the fast path — replay 404 status + feed Content-Type
 *     and honor per-content TTL (FBS-82406, FBS-82407).
 * v3: conditional GET — emit Last-Modified + ETag, answer matching
 *     If-Modified-Since / If-None-Match with 304 (FBS-82407 #5).
 * v4: bail when the `.maintenance-active` sentinel is present so a page
 *     cached while live isn't served during maintenance (FBS-82409 B1).
 * v5: per-site cache buckets — entries moved from `cache/xspeed/<md5>.html`
 *     to `cache/xspeed/<host>[/<blog-path>]/<md5>.html` so a multisite
 *     purge can be scoped to one blog. An un-bumped drop-in would keep
 *     reading the old flat path, miss every entry and boot WordPress on
 *     every request (#6).
 * v6: serve tracking-param requests from the fast path — read the
 *     precompiled `ignored_query_params` allow-list instead of bailing on
 *     any query string (#13). An un-bumped drop-in keeps the old bail and
 *     campaign traffic keeps paying a full WordPress boot.
 * v7: the page TTL is baked in at install time from the `cache_expiry`
 *     setting instead of a hardcoded 86400, so the drop-in enforces the
 *     configured lifetime rather than a fixed 24h (#240).
 * v8: never serve an empty, stale, or short `.br` sibling — an uninflatable
 *     brotli stream renders as a blank page. THIS FILE IS A COPY made when
 *     caching was enabled, so without the bump an updated site keeps the old
 *     serve logic and never receives the fix (#286).
 *
 * IMPORTANT: This file is included by wp-settings.php BEFORE
 * wp-includes/formatting.php and wp-includes/load.php are loaded, so NO
 * WordPress functions (sanitize_text_field, wp_unslash, is_admin,
 * HOUR_IN_SECONDS, etc.) are available here. Use raw PHP only.
 *
 * @package XSpeed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only handle plain GET requests.
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Drop-in runs before wp-includes/formatting.php loads, so wp_unslash() and sanitize_text_field() are unavailable. Value is upper-cased and matched against the literal string 'GET'; never echoed, never executed.
$xspeed_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
if ( 'GET' !== $xspeed_method ) {
	return;
}

// Query-string requests. A tracking param contributes nothing to the
// response, and PHP already caches `/post?utm_source=x` under the same key
// as `/post` — but this file used to bail on ANY query string, so every
// visitor arriving from an email or ad campaign paid a full WordPress boot
// to be handed a file that was already on disk. On a marketing site that is
// most of the paid traffic taking the slowest path. (#13)
//
// We cannot read the option or call Glob_Matcher here (WordPress is not
// loaded), so Cache::sync_query_allowlist() precompiles the user's
// `ignored_query_params` into a regex next to the cache files. Every key
// must match it; one that doesn't means the response could genuinely vary,
// so we stand down and let PHP decide. A missing sidecar means the same —
// fail safe, never guess.
if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
	$xspeed_allow_file = WP_CONTENT_DIR . '/cache/xspeed/.ignored-query-params';
	if ( ! is_readable( $xspeed_allow_file ) ) {
		return;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- pre-WP drop-in; an unreadable sidecar degrades to "let PHP handle it".
	$xspeed_allow_re = trim( (string) @file_get_contents( $xspeed_allow_file ) );
	if ( '' === $xspeed_allow_re ) {
		return;
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Drop-in runs pre-WP. parse_str() urldecodes exactly as WordPress does; only KEYS are consumed, and only as preg_match() input — never echoed, never executed.
	parse_str( str_replace( "\0", '', (string) $_SERVER['QUERY_STRING'] ), $xspeed_qs_params );
	if ( empty( $xspeed_qs_params ) ) {
		return;
	}
	foreach ( array_keys( $xspeed_qs_params ) as $xspeed_qs_key ) {
		// Anchored: a param named `referrer` must not be waved through by
		// a `ref` entry. Mirrors Glob_Matcher's full-string semantics.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed baked pattern degrades to "let PHP handle it", never a warning per request.
		if ( 1 !== @preg_match( '#^' . $xspeed_allow_re . '$#', (string) $xspeed_qs_key ) ) {
			return;
		}
	}
}

// Honor explicit bypass header. xSpeed's own benchmark REST endpoint
// sends `X-XSpeed-Bypass: 1` so we can measure uncached TTFB for the
// before/after comparison on the dashboard. Harmless if a third party
// sends it — they just get an uncached response.
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Drop-in runs before WP loads. Value is only used as an isset() check + literal string comparison, never echoed.
if ( ! empty( $_SERVER['HTTP_X_XSPEED_BYPASS'] ) ) {
	return;
}

// Maintenance / coming-soon sentinel. The Pro Maintenance-Cache module writes
// `.maintenance-active` next to the cache files whenever the site enters
// maintenance / coming-soon mode, and removes it on recovery. The write-side
// veto alone can't stop a page cached while the site was live from being
// served here (this drop-in runs before WordPress loads), so we bail out and
// let WordPress render the maintenance / coming-soon screen instead of serving
// a stale real-site page. (FBS-82409 B1)
if ( file_exists( WP_CONTENT_DIR . '/cache/xspeed/.maintenance-active' ) ) {
	return;
}

if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
	return;
}

// Raw-PHP sanitization: strip null bytes only. This value is used for
// substring comparisons and as input to md5() — never echoed, never
// executed, never written to disk as data. Magic quotes was removed in
// PHP 5.4 and the plugin requires PHP 7.4+, so no unslashing is needed.
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Drop-in runs before wp_unslash()/sanitize_text_field() are loaded; null-byte strip is the strongest sanitizer available pre-WP-bootstrap. Value is only used for substring comparison and as md5() input.
$xspeed_request_uri = str_replace( "\0", '', (string) $_SERVER['REQUEST_URI'] );

// Skip admin / login requests.
if ( false !== strpos( $xspeed_request_uri, '/wp-admin' ) || false !== strpos( $xspeed_request_uri, '/wp-login' ) ) {
	return;
}

// Skip logged-in users and comment authors — never serve a cached page to
// someone who has a session cookie. Reading raw cookies; we only inspect
// names, not values.
if ( ! empty( $_COOKIE ) ) {
	foreach ( $_COOKIE as $xspeed_cookie_name => $xspeed_cookie_value ) {
		unset( $xspeed_cookie_value );
		$xspeed_cookie_name = (string) $xspeed_cookie_name;
		if ( 0 === strpos( $xspeed_cookie_name, 'wordpress_logged_in' )
			|| 0 === strpos( $xspeed_cookie_name, 'comment_author_' )
			|| 0 === strpos( $xspeed_cookie_name, 'wp-postpass_' )
			// The generic bypass cookie PHP sets whenever it decides a
			// visitor must not be served from cache (Server_Rules::
			// BYPASS_COOKIE). Covers repeat visitors even when the baked
			// rules below are stale.
			|| 'wordpress_no_cache' === $xspeed_cookie_name ) {
			return;
		}
	}
}

// The user's own excluded-cookie list, baked in at install time by
// Cache::install_dropin() (the token is replaced with an escaped regex
// built by Server_Rules). The drop-in runs before WordPress loads and so
// cannot read the settings itself; without this, every cart / membership
// / custom cookie rule applied only while a page was cold, and a warm
// page was served to exactly the visitors the settings excluded.
//
// An un-substituted token means the drop-in was copied straight from a
// source checkout — fall back to serving nothing from the fast path
// rather than treating the literal token as a pattern.
$xspeed_cookie_re = '@@XSPEED_COOKIE_RE@@';
if ( '@@' !== substr( $xspeed_cookie_re, 0, 2 ) && '' !== $xspeed_cookie_re && ! empty( $_COOKIE ) ) {
	foreach ( array_keys( $_COOKIE ) as $xspeed_cookie_name ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed baked pattern must degrade to "don't serve from cache", never warn on every request.
		if ( 1 === @preg_match( '#(' . $xspeed_cookie_re . ')#i', (string) $xspeed_cookie_name ) ) {
			return;
		}
	}
}

// Same for the user-agent bypass list. This is the rule the bypass cookie
// can never cover: a bot's very first request to a warm page never
// reaches PHP, so there is no earlier request in which to set a cookie.
$xspeed_ua_re = '@@XSPEED_UA_RE@@';
if ( '@@' !== substr( $xspeed_ua_re, 0, 2 ) && '' !== $xspeed_ua_re ) {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Drop-in runs pre-WP. Value is only matched against a baked, pre-escaped regex; never echoed or executed.
	$xspeed_ua_raw = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above; degrade to bypass rather than warn.
	if ( '' !== $xspeed_ua_raw && 1 === @preg_match( '#(' . $xspeed_ua_re . ')#i', $xspeed_ua_raw ) ) {
		return;
	}
}

// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Drop-in runs before wp_unslash()/sanitize_text_field() are loaded. Value is filtered through a strict allowlist regex below (letters, digits, dot, hyphen, colon) and only used as md5() input for the cache key.
$xspeed_host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : 'default';
$xspeed_host = str_replace( "\0", '', $xspeed_host );
// Restrict host to a safe charset (letters, digits, dot, hyphen, colon for port).
$xspeed_host = preg_replace( '/[^a-zA-Z0-9.\-:]/', '', $xspeed_host );

$xspeed_path_only  = strtok( $xspeed_request_uri, '?' );

// Device bucket — MUST mirror XSpeed\Cache::cache_key() exactly, or the key
// the drop-in computes won't match the file Cache::store() wrote, the HIT
// branch below never fires, and every request falls through to a full
// WordPress boot (defeating the whole point of the pre-WP drop-in).
//
// Cache::cache_key() appends '|m' / '|d' when the cache module's
// `mobile_separate` setting is on. The drop-in can't read WP options
// (it runs before WordPress loads), so Cache writes a zero-byte sidecar
// flag — `.mobile-separate` next to the cache files — whenever that setting
// is on, and removes it when off (see Cache::sync_mobile_flag()). We mirror
// the same UA token list wp_is_mobile() uses, the same one Cache's inline
// fallback detector uses.
$xspeed_device = '';
if ( file_exists( WP_CONTENT_DIR . '/cache/xspeed/.mobile-separate' ) ) {
	// Mirror core's wp_is_mobile() EXACTLY (which Cache::is_mobile_request()
	// defers to): check the Sec-CH-UA-Mobile client hint first, then fall
	// back to the same UA token list. Any divergence from the engine's
	// detection re-introduces the key mismatch this whole flag exists to
	// prevent.
	$xspeed_is_mobile = false;
	if ( isset( $_SERVER['HTTP_SEC_CH_UA_MOBILE'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Drop-in runs pre-WP. Value is compared against the literal '?1', never echoed or executed.
		$xspeed_is_mobile = ( '?1' === $_SERVER['HTTP_SEC_CH_UA_MOBILE'] );
	} else {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Drop-in runs before wp_unslash()/sanitize_text_field() load. Value is only matched against a literal token regex, never echoed or executed.
		$xspeed_ua        = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$xspeed_is_mobile = (bool) preg_match( '/(Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi)/i', $xspeed_ua );
	}
	$xspeed_device = $xspeed_is_mobile ? '|m' : '|d';
}

$xspeed_cache_key = md5( $xspeed_host . $xspeed_path_only . $xspeed_device );

// Per-site bucket. MUST mirror XSpeed\Cache::current_host_dir() exactly —
// same charset, same trimmed dots, same 'default' fallback, same multisite
// path prefix — or the drop-in looks in a directory Cache::store() never
// wrote to, every HIT misses, and every request falls through to a full
// WordPress boot.
//
// Note this is NOT $xspeed_host: the cache KEY keeps the colon of
// `host:port` (it only ever feeds md5()), while the DIRECTORY cannot —
// a colon is not portable in a path. (#6)
$xspeed_host_dir   = $xspeed_host;
$xspeed_host_colon = strpos( $xspeed_host_dir, ':' );
if ( false !== $xspeed_host_colon ) {
	$xspeed_host_dir = substr( $xspeed_host_dir, 0, $xspeed_host_colon );
}
$xspeed_host_dir = preg_replace( '/[^a-zA-Z0-9.\-]/', '', $xspeed_host_dir );
$xspeed_host_dir = preg_replace( '/\.{2,}/', '.', (string) $xspeed_host_dir );
$xspeed_host_dir = trim( (string) $xspeed_host_dir, '.-' );
if ( '' === $xspeed_host_dir ) {
	$xspeed_host_dir = 'default';
}

// Subdirectory multisite: every blog shares one host, so the host alone
// would put them all in one bucket and they would keep purging each other.
// We cannot call is_multisite()/get_blog_details() here (WordPress is not
// loaded), so Cache::sync_site_paths() persists the network's blog paths
// as `<raw-path>|<segment>` lines, longest first. Prefix-match the URI.
$xspeed_paths_file = WP_CONTENT_DIR . '/cache/xspeed/.site-paths';
if ( file_exists( $xspeed_paths_file ) ) {
	$xspeed_uri_trimmed = ltrim( (string) $xspeed_path_only, '/' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own sidecar; WP_Filesystem is not loaded pre-WP.
	$xspeed_paths_raw = (string) @file_get_contents( $xspeed_paths_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable sidecar just means "no prefix".
	foreach ( explode( "\n", $xspeed_paths_raw ) as $xspeed_path_line ) {
		$xspeed_sep = strpos( $xspeed_path_line, '|' );
		if ( false === $xspeed_sep ) {
			continue;
		}
		$xspeed_raw_path = substr( $xspeed_path_line, 0, $xspeed_sep );
		$xspeed_segment  = substr( $xspeed_path_line, $xspeed_sep + 1 );
		if ( '' === $xspeed_raw_path || '' === $xspeed_segment ) {
			continue;
		}
		if ( $xspeed_uri_trimmed === $xspeed_raw_path
			|| 0 === strpos( $xspeed_uri_trimmed, $xspeed_raw_path . '/' ) ) {
			$xspeed_host_dir .= '/' . $xspeed_segment;
			break;
		}
	}
}

$xspeed_cache_dir  = WP_CONTENT_DIR . '/cache/xspeed/' . $xspeed_host_dir . '/';
$xspeed_cache_file = $xspeed_cache_dir . $xspeed_cache_key . '.html';
$xspeed_meta_file  = $xspeed_cache_dir . $xspeed_cache_key . '.meta';

if ( file_exists( $xspeed_cache_file ) ) {
	// Read the .meta sidecar (status / content_type / ttl) the same way the
	// PHP HIT path does — the drop-in serves cached feeds and 404s too, so it
	// must replay their Content-Type / status and honor their per-content TTL.
	// Ordinary 200 text/html pages have no .meta (the common path stays fast).
	// (FBS-82406 soft-404, FBS-82407 feed content-type + TTL)
	$xspeed_meta = array();
	if ( file_exists( $xspeed_meta_file ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- pre-WP drop-in; one tiny JSON sidecar.
		$xspeed_meta_raw = file_get_contents( $xspeed_meta_file );
		if ( false !== $xspeed_meta_raw ) {
			$xspeed_decoded = json_decode( $xspeed_meta_raw, true );
			if ( is_array( $xspeed_decoded ) ) {
				$xspeed_meta = $xspeed_decoded;
			}
		}
	}

	// Per-content TTL from meta (e.g. feeds) falls back to the site's
	// configured cache_expiry, baked in at install time by
	// Cache::install_dropin() and re-baked on every settings save. The
	// drop-in runs before WordPress loads and so cannot read the option
	// itself; without this it applied a hardcoded 24h to every ordinary
	// page — write_meta() only writes a `ttl` sidecar when the value differs
	// from the page default, so ordinary pages carry no sidecar at all.
	// That served stale content under Conservative (12h) and refused the
	// fast path for 6 of 7 days under Aggressive (168h). (#240)
	//
	// An un-substituted token means the drop-in was copied straight from a
	// source checkout — fall back to the historical 24h literal rather than
	// treating the token as a number. HOUR_IN_SECONDS isn't defined yet.
	$xspeed_default_ttl = '@@XSPEED_DEFAULT_TTL@@';
	$xspeed_unbaked     = ( '@@' === substr( $xspeed_default_ttl, 0, 2 ) || (int) $xspeed_default_ttl < 1 );
	$xspeed_default_ttl = $xspeed_unbaked ? 86400 : (int) $xspeed_default_ttl;
	if ( $xspeed_unbaked ) {
		// Make the un-substituted state observable. Serving the 24h literal
		// silently is exactly how the original bug stayed invisible; a site
		// on this path is enforcing a lifetime nobody configured.
		header( 'X-XSpeed-Cache-TTL: default (unbaked)' );
	}

	$xspeed_ttl = ( isset( $xspeed_meta['ttl'] ) && (int) $xspeed_meta['ttl'] > 0 ) ? (int) $xspeed_meta['ttl'] : $xspeed_default_ttl;
	$xspeed_age = time() - filemtime( $xspeed_cache_file );
	if ( $xspeed_age < $xspeed_ttl ) {
		// PHP-served cache hit (the ~85ms fallback path). The nginx static
		// rewrite sends "HIT (nginx)" for the fast 5-15ms path; same header,
		// distinct value so you can tell which layer served the page.
		header( 'X-XSpeed-Cache: HIT (php)' );

		// Record the HIT for the dashboard hit-ratio. The drop-in runs
		// BEFORE WordPress loads, so it can't call Hit_Counter — instead
		// it appends one line to the same hits.log the nginx static path
		// uses, and Hit_Counter::collect_nginx_log_hits() drains + counts
		// both on the next dashboard load. Without this, every drop-in HIT
		// was served but never counted, so the hit ratio sat at 0.
		// Best-effort: a failed append must never break serving the page.
		//
		// Path is baked in at install time by Cache::install_dropin(), which
		// replaces the @@XSPEED_HITS_LOG@@ token on the next line with the
		// resolved absolute path (uploads/xspeed/hits.log — NOT the cache dir,
		// which gets deleted on purge/uninstall and would take nginx down,
		// FBS-82478). The default below is the fallback for an un-substituted
		// drop-in (e.g. run straight from a dev source checkout); the installed
		// copy always carries the absolute uploads path.
		$xspeed_hits_log = '@@XSPEED_HITS_LOG@@'; // replaced at install
		if ( '@@' === substr( $xspeed_hits_log, 0, 2 ) ) {
			$xspeed_hits_log = WP_CONTENT_DIR . '/uploads/xspeed/hits.log';
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- pre-WP drop-in; WP_Filesystem isn't loaded. One short line, append + lock; failures are non-fatal (the ratio just under-counts).
		@file_put_contents( $xspeed_hits_log, "hit\n", FILE_APPEND | LOCK_EX );

		// Replay the cached response's status + content-type from .meta, so a
		// cached 404 serves 404 (not a soft-404 200) and a cached feed serves
		// application/rss+xml (not text/html). (FBS-82406, FBS-82407)
		if ( ! empty( $xspeed_meta['status'] ) && function_exists( 'http_response_code' ) ) {
			http_response_code( (int) $xspeed_meta['status'] );
		}
		if ( ! empty( $xspeed_meta['content_type'] ) && is_string( $xspeed_meta['content_type'] ) ) {
			header( 'Content-Type: ' . $xspeed_meta['content_type'] );
		}

		// Conditional GET: Last-Modified + ETag from the cache file's mtime,
		// answer a matching If-Modified-Since / If-None-Match with 304 so
		// aggregators skip re-downloading an unchanged cached feed/page.
		// (FBS-82407 #5)
		$xspeed_mtime = (int) filemtime( $xspeed_cache_file );
		if ( $xspeed_mtime > 0 ) {
			$xspeed_lastmod = gmdate( 'D, d M Y H:i:s', $xspeed_mtime ) . ' GMT';
			$xspeed_etag    = '"' . md5( $xspeed_cache_file . '|' . $xspeed_mtime ) . '"';
			header( 'Last-Modified: ' . $xspeed_lastmod );
			header( 'ETag: ' . $xspeed_etag );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- pre-WP drop-in; values only compared to a server-generated etag / parsed as a date, never echoed or executed.
			$xspeed_inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- as above.
			$xspeed_ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? trim( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : '';
			if ( ( '' !== $xspeed_inm && false !== strpos( $xspeed_inm, $xspeed_etag ) )
				|| ( '' !== $xspeed_ims && false !== ( $xspeed_ims_ts = strtotime( $xspeed_ims ) ) && $xspeed_ims_ts >= $xspeed_mtime ) ) {
				if ( function_exists( 'http_response_code' ) ) {
					http_response_code( 304 );
				}
				exit;
			}
		}

		// Serve the precompressed Brotli sibling when the client accepts it
		// and the Pro Brotli module wrote <file>.br. MUST mirror
		// XSpeed\Cache::maybe_serve_brotli() on the non-drop-in serve path —
		// both decide on the same Accept-Encoding token match + sibling
		// existence, so the response is identical whichever path serves.
		// pre-WP: no sanitize_text_field()/wp_unslash(); the value is only
		// lowercased + regex-matched, never echoed.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- pre-WP drop-in; value is only lowercased + token-matched, never echoed or executed.
		$xspeed_accept_enc = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? strtolower( str_replace( "\0", '', (string) $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) : '';
		$xspeed_br_file    = $xspeed_cache_file . '.br';

		// Existence is NOT enough: an empty or stale sibling is unservable.
		// Mirrors XSpeed\Cache::brotli_sibling_is_usable(); inlined because
		// this file runs before WordPress and cannot call it.
		//
		// Deliberately NO size-ratio floor. Brotli's ratio is unbounded on
		// repetitive input — a ~1 MB page of table rows compresses to about
		// 0.04% — so a floor rejects genuinely good siblings and silently
		// serves the uncompressed page.
		//
		// Truncation is instead caught exactly, from the byte count the
		// writer recorded in `<file>.br.size` when it published the sibling.
		// A stream shorter than its own declared length cannot inflate; one
		// that matches was published whole. Where no record exists — a
		// sibling written before this version, which is precisely the
		// already-broken file sitting on a live site right now — the checks
		// below still apply and the atomic writer stops new ones appearing.
		$xspeed_br_ok = false;
		if ( is_readable( $xspeed_br_file ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- pre-WP drop-in; a stat failure means "don't serve it", handled by the size checks.
			$xspeed_br_size   = (int) @filesize( $xspeed_br_file );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
			$xspeed_html_size = (int) @filesize( $xspeed_cache_file );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
			$xspeed_br_mtime  = (int) @filemtime( $xspeed_br_file );

			// MUST mirror XSpeed\Cache::brotli_expected_size(); 0 means "no
			// record", never "zero bytes".
			$xspeed_br_expected = 0;
			$xspeed_br_sidecar  = $xspeed_br_file . '.size';
			if ( is_readable( $xspeed_br_sidecar ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- pre-WP drop-in; an unreadable sidecar means "unknown", handled by the cast.
				$xspeed_br_expected = (int) trim( (string) @file_get_contents( $xspeed_br_sidecar ) );
				if ( $xspeed_br_expected < 0 ) {
					$xspeed_br_expected = 0;
				}
			}

			$xspeed_br_ok = $xspeed_br_size > 0
				&& $xspeed_html_size > 0
				// Not stale: a sibling older than the page would serve the
				// previous revision under the current entry's ETag.
				&& ( $xspeed_br_mtime <= 0 || $xspeed_mtime <= 0 || $xspeed_br_mtime >= $xspeed_mtime )
				// Not truncated, where the writer left a length to check.
				&& ( $xspeed_br_expected <= 0 || $xspeed_br_size === $xspeed_br_expected );
		}

		if ( preg_match( '/(^|[\s,])br([\s,;]|$)/', $xspeed_accept_enc )
			&& $xspeed_br_ok ) {
			header( 'Content-Encoding: br' );
			header( 'Vary: Accept-Encoding', false );
			header_remove( 'Content-Length' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Drop-in runs before WP_Filesystem is available; readfile streams the precompressed sibling directly.
			readfile( $xspeed_br_file );
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Drop-in runs before WP_Filesystem is available; readfile is optimal for streaming a static cache file to the visitor.
		readfile( $xspeed_cache_file );
		exit;
	}
}
