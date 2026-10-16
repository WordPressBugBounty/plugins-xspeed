<?php
/**
 * XSPEED_DROPIN
 * XSPEED_DROPIN_VERSION: 20
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
 * v9: emit the edge/CDN headers baked in from `xspeed_edge_cache_headers`
 *     on a HIT. Without the bump an existing site keeps a drop-in with no
 *     placeholder to bake into, so an add-on's CDN headers appear on every
 *     serve path except this one.
 * v10: emit `X-XSpeed-Built` — the served file's mtime — so a CDN that has
 *     just purged can tell whether the origin answered with newer HTML or
 *     with the same page again. Without the bump an existing site's drop-in
 *     stays silent and every purge through it reads as unverifiable.
 * v11: a page whose edge answer differs from the site-wide one carries its
 *      own pairs in the `.meta` sidecar, and they REPLACE the baked set.
 *      Without the bump an existing drop-in keeps sending the baked pairs
 *      for that page, lifetime and all.
 * v12: `XSPEED_EDGE_PROVIDER=off` empties the edge pairs here too, before
 *      they are sent, so the emergency switch does not wait for a re-bake.
 *      `X-XSpeed-Built` still goes out: it is a diagnostic, not an
 *      instruction to the edge.
 * v13: a merge of two lines of history that had both used 9 and 10. On `dev`
 *      they were: v9 carries the baked edge-header answer so a hold set for a
 *      page reaches the paths that run without PHP, and v10 keeps bots,
 *      scanners, cached 404s and xSpeed's own requests (by UA or the
 *      X-XSpeed-Self header) out of hits.log. Everything in v9 to v12 above
 *      and both of those are in this file. The bump is what makes a site on
 *      either line rewrite its drop-in.
 * v14: the key path lowercases percent-escapes and escapes non-ASCII bytes,
 *      the spelling Cache::normalize_path() now hashes, so `%E7`, `%e7` and
 *      raw UTF-8 spellings of a non-ASCII slug share one key with the entry
 *      PHP wrote. Before, PHP keyed a sanitize_text_field() copy that had
 *      lost every escape, so these pages were never a HIT here. Without the
 *      bump an existing site keeps computing the old key for them.
 * v15: two changes, one bump.
 *      - A page with a lifetime of its own (the sidecar `ttl`: a nonce cap,
 *        a per-post expiry) has every edge lifetime in its pairs cut to what
 *        the copy has left. Without the bump an existing drop-in keeps
 *        telling the edge to hold a nonce page for the site's whole lifetime.
 *      - A HIT served for a URL carrying an ignored param (`?utm_source=…`)
 *        sends the baked `query-variant` hold instead of the edge lifetime,
 *        so an edge keeps only URLs a purge can name. A query string of `0`
 *        is no longer read as no query string. Without the bump an existing
 *        drop-in keeps telling the edge to store every variant.
 * v16: serve only while xSpeed runs on this site, at this path. A database
 *      restore that leaves the plugin inactive never runs deactivation, so
 *      this file kept serving the old site's pages for URLs that now held
 *      different ones (#597).
 * v17: a request whose Accept header names Markdown is never handed the
 *      cached HTML. Without the bump an existing drop-in keeps serving the
 *      page to it. (#693)
 * v18: with the opt-in `.cache-markdown` flag present, such a request is
 *      served from its own `|md` entry instead of falling through, and the
 *      HIT carries `Vary: Accept`.
 * v19: tablets can get a copy of their own (`.tablet-separate` next to the
 *      cache files), picked by the tablet pattern before the phone test.
 *      Without the bump an existing drop-in keeps filing tablets under the
 *      phone or desktop copy and never finds the tablet entry PHP wrote.
 * v20: an edge that keeps a copy per device (baked from Cache). A
 *      trusted request gets the copy Cloudflare keys it under, and any other
 *      request gets the mobile-split hold back. Without the bump an existing
 *      drop-in has no hold to put back once the bake lifts it.
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

// Serve only while xSpeed runs on this site, at this path (#597).
//
// This file, WP_CACHE and the cache folder all stay on disk when a database
// restore leaves the plugin inactive: deactivation never runs, so nothing
// removes them, and this file has no database to ask. The plugin keeps a
// flag next to the cache whose name carries ABSPATH, and writes it again on
// any request where it runs. A copy of the site at another path has no flag
// until the plugin runs there.
//
// The flag alone would survive a database-only restore, because the files
// are unchanged. So on every request that reaches WordPress, check after
// plugins load whether xSpeed did, and remove the flag when it did not. A
// restored site stops serving old pages from the first request that is not
// served from here: an admin login, a POST, or any page not in the cache.
// When xSpeed runs again, it empties the cache before it writes the flag,
// so the old pages do not come back either.
// wp-settings.php re-initializes hooks added by this file, so add_action()
// is supported here.
$xspeed_active_flag = WP_CONTENT_DIR . '/cache/xspeed/.active-' . md5( ABSPATH );
if ( function_exists( 'add_action' ) ) {
	add_action(
		'plugins_loaded',
		static function () use ( $xspeed_active_flag ) {
			if ( defined( 'XSPEED_VERSION' ) ) {
				return;
			}
			// Plugins are not loaded while WordPress installs or upgrades its
			// database, and `wp --skip-plugins` skips them on purpose. None of
			// those says the plugin is off.
			if ( ( function_exists( 'wp_installing' ) && wp_installing() ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return;
			}
			// On a network, xSpeed may run on some sites and not on others,
			// and all of them share this file and this flag.
			if ( function_exists( 'is_multisite' ) && is_multisite() ) {
				return;
			}
			if ( ! file_exists( $xspeed_active_flag ) ) {
				return;
			}
			// Some tools unload plugins on chosen pages by filtering
			// active_plugins. xSpeed is still on there, so the flag stays:
			// removing it would send every cached page through WordPress
			// until the next request that loads xSpeed. The flag holds the
			// plugin's basename. Read the stored list without the option
			// filters, which are what those tools hook.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- pre-WP drop-in's own marker.
			$basename = trim( (string) @file_get_contents( $xspeed_active_flag ) );
			global $wpdb;
			if ( '' !== $basename && isset( $wpdb ) && is_object( $wpdb ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the option API runs the very filters this must not see.
				$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins' ) );
				$active = is_string( $stored ) ? maybe_unserialize( $stored ) : null;
				if ( is_array( $active ) && in_array( $basename, $active, true ) ) {
					return;
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- pre-WP drop-in's own marker; a failed unlink just leaves serving as it was.
			@unlink( $xspeed_active_flag );
			// Nothing purged the cache while xSpeed was off, so it may hold
			// pages from before a restore. This marker tells the plugin to
			// empty the cache before it writes the flag again.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.PHP.NoSilencedErrors.Discouraged -- pre-WP drop-in's own marker.
			@touch( dirname( $xspeed_active_flag ) . '/.stale-' . md5( ABSPATH ) );
		},
		0
	);
}
if ( ! file_exists( $xspeed_active_flag ) ) {
	return;
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
//
// A key that matches is one the cache key leaves out, so the URL it makes is
// one no purge names. `$xspeed_qs_unpurged` records that for the edge hold
// further down, the same test Cache::query_carries_unpurged_param() makes.
//
// An empty string test rather than empty(): a query string of `0` is still a
// query string, and empty() let `/post?0` through as if it were `/post`.
$xspeed_qs_unpurged = false;
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Drop-in runs pre-WP. Only tested for emptiness here; parsed for its keys below.
if ( isset( $_SERVER['QUERY_STRING'] ) && '' !== (string) $_SERVER['QUERY_STRING'] ) {
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
		$xspeed_qs_unpurged = true;
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

// A client negotiating a Markdown copy of the page (SEOPress Pro answers
// `Accept: text/markdown` on the same URL) must not be handed the cached
// HTML. Mirrors Cache::NON_HTML_ACCEPT_TYPES; the filter on that list
// cannot run here. (#693)
//
// When the site opted into caching Markdown (Cache::sync_markdown_flag()
// keeps `.cache-markdown` beside the cache files), the request is served
// from its own entry instead: Cache::cache_key() appends `|md` for it, and
// so does the key below. Without the flag it falls through to WordPress.
$xspeed_variant = '';
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Drop-in runs before WP loads. Value is only substring-tested, never echoed.
if ( isset( $_SERVER['HTTP_ACCEPT'] ) && false !== stripos( (string) $_SERVER['HTTP_ACCEPT'], 'markdown' ) ) {
	if ( ! file_exists( WP_CONTENT_DIR . '/cache/xspeed/.cache-markdown' ) ) {
		return;
	}
	$xspeed_variant = '|md';
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

$xspeed_path_only = (string) strtok( $xspeed_request_uri, '?' );

// Path spelling. MUST mirror XSpeed\Cache::normalize_path() exactly.
// WordPress links carry lower-case escapes and browsers send upper-case ones,
// so escapes are lowercased, and a byte outside printable ASCII is escaped the
// same way. Nothing is decoded. Without this a non-ASCII slug requested as
// `%E7…` or as raw UTF-8 hashes to a different key than the one
// Cache::store() wrote. A plain ASCII path passes through unchanged. The
// site-path match below sees this spelling too; blog paths are plain ASCII.
$xspeed_path_only = (string) preg_replace_callback(
	'/%[0-9a-fA-F]{2}|[^\x21-\x7E]/',
	static function ( $xspeed_m ) {
		return '%' === $xspeed_m[0][0] ? strtolower( $xspeed_m[0] ) : sprintf( '%%%02x', ord( $xspeed_m[0] ) );
	},
	$xspeed_path_only
);

// Device bucket — MUST mirror XSpeed\Cache::cache_key() exactly, or the key
// the drop-in computes won't match the file Cache::store() wrote, the HIT
// branch below never fires, and every request falls through to a full
// WordPress boot (defeating the whole point of the pre-WP drop-in).
//
// Cache::cache_key() appends a device suffix when the cache module's
// `mobile_separate` setting is on. The drop-in can't read WP options
// (it runs before WordPress loads), so Cache writes a zero-byte sidecar
// flag — `.mobile-separate` next to the cache files — whenever that setting
// is on, and removes it when off (see Cache::sync_mobile_flag()).
//
// With Separate tablet cache on, Cache also writes `.tablet-separate`, and
// tablets get the tablet copy. They are told apart before the phone test,
// because Chrome on an Android tablet sends Sec-CH-UA-Mobile: ?0.
//
// Every device pattern and the suffix of each copy are baked in by
// Cache::install_dropin() from XSpeed\Cache_Devices, the one list of device
// copies, so this file holds no copy of either.
$xspeed_device_rules = '@@XSPEED_DEVICE_RULES@@';
$xspeed_device       = '';

// The drop-in's copy of Cache_Devices::from_user_agent(), with one more
// switch: $tablets false skips the tablet pattern, as PHP does while tablets
// have no copy of their own. Edge rules are Cloudflare's patterns alone.
// DevicesParityTest runs this block against the class.
// @xspeed-device-of-start
$xspeed_device_of = static function ( string $ua, $hint, bool $edge, bool $tablets ) use ( $xspeed_device_rules ): string {
	if ( $edge ) {
		if ( preg_match( $xspeed_device_rules['edge_tablet_re'], $ua ) ) {
			return 'tablet';
		}
		return preg_match( $xspeed_device_rules['edge_mobile_re'], $ua ) ? 'mobile' : 'desktop';
	}
	if ( $tablets && '' !== $ua && preg_match( $xspeed_device_rules['tablet_re'], $ua ) ) {
		return 'tablet';
	}
	if ( null !== $hint ) {
		return '?1' === $hint ? 'mobile' : 'desktop';
	}
	return preg_match( $xspeed_device_rules['mobile_re'], $ua ) ? 'mobile' : 'desktop';
};
// @xspeed-device-of-end

// An edge that keeps a copy per Cloudflare device type, baked in by
// Cache::install_dropin() from Cache::edge_device_dropin(). An empty array
// (or an un-substituted token) means it does not, and nothing below changes.
//
// A request is trusted when it carries `CF-Device-Type` and a CF-shaped
// `CF-Ray`, and no recent request has shown the edge ignoring device type
// (the evidence file). Its copy then follows the user-agent by Cloudflare's
// rules, never the header's value, so a forged header cannot put one
// device's page in another's copy. MUST mirror Cache::edge_device_trusted()
// and Cache::edge_device_bucket().
$xspeed_edge_device   = '@@XSPEED_EDGE_DEVICE@@';
$xspeed_edge_keyed    = is_array( $xspeed_edge_device ) && array() !== $xspeed_edge_device;
$xspeed_edge_trusted  = false;
$xspeed_edge_unkeyed  = false;
$xspeed_edge_evidence = '';
if ( $xspeed_edge_keyed ) {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- pre-WP drop-in; only matched against a baked pattern, never echoed.
	$xspeed_edge_ray = isset( $_SERVER['HTTP_CF_RAY'] ) ? (string) $_SERVER['HTTP_CF_RAY'] : '';
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- pre-WP drop-in; only tested for being non-empty, never read for its value.
	$xspeed_edge_type     = isset( $_SERVER['HTTP_CF_DEVICE_TYPE'] ) ? trim( (string) $_SERVER['HTTP_CF_DEVICE_TYPE'] ) : '';
	$xspeed_edge_ray_ok   = (bool) preg_match( $xspeed_edge_device['cf_ray'], $xspeed_edge_ray );
	$xspeed_edge_evidence = (string) $xspeed_edge_device['unkeyed_flag'];
	$xspeed_edge_fresh    = file_exists( $xspeed_edge_evidence ) && time() - (int) filemtime( $xspeed_edge_evidence ) < (int) $xspeed_edge_device['unkeyed_for'];
	$xspeed_edge_trusted  = $xspeed_edge_ray_ok && '' !== $xspeed_edge_type && ! $xspeed_edge_fresh;
	// Through Cloudflare without the header: evidence the edge is not keying.
	// Recorded on a HIT below, the request a keyed edge would have keyed.
	$xspeed_edge_unkeyed = $xspeed_edge_ray_ok && '' === $xspeed_edge_type;
}

if ( file_exists( WP_CONTENT_DIR . '/cache/xspeed/.mobile-separate' ) ) {
	if ( ! is_array( $xspeed_device_rules ) ) {
		// A copy made straight from a source checkout has no rules to pick a
		// device with. Leave the request to WordPress rather than guess.
		return;
	}
	// The phone test mirrors core's wp_is_mobile() EXACTLY (which
	// Cache::is_mobile_request() defers to): the Sec-CH-UA-Mobile client hint
	// first, then the same UA token list. Any divergence from the engine's
	// detection re-introduces the key mismatch this whole flag exists to
	// prevent.
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Drop-in runs before wp_unslash()/sanitize_text_field() load. Value is only matched against baked patterns, never echoed or executed.
	$xspeed_ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Drop-in runs pre-WP. Value is compared against the literal '?1', never echoed or executed.
	$xspeed_hint   = isset( $_SERVER['HTTP_SEC_CH_UA_MOBILE'] ) ? (string) $_SERVER['HTTP_SEC_CH_UA_MOBILE'] : null;
	if ( $xspeed_edge_trusted ) {
		$xspeed_device = $xspeed_edge_device['bucket'][ $xspeed_device_of( $xspeed_ua, null, true, false ) ];
	} else {
		$xspeed_device = $xspeed_device_rules['suffix'][ $xspeed_device_of(
			$xspeed_ua,
			$xspeed_hint,
			false,
			file_exists( WP_CONTENT_DIR . '/cache/xspeed/' . $xspeed_device_rules['tablet_flag'] )
		) ];
	}
} else {
	// No device copies on disk: nothing to trust, and nothing to lift.
	$xspeed_edge_trusted = false;
}

$xspeed_cache_key = md5( $xspeed_host . $xspeed_path_only . $xspeed_device . $xspeed_variant );

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

		// Edge/CDN headers decided by Cache::edge_headers_for(). No filter
		// can run here — plugins are not loaded — so Cache::install_dropin()
		// bakes the resolved pairs into the literal below and re-bakes them
		// on every cache settings save.
		//
		// An un-substituted placeholder means this file was copied straight
		// from a source checkout: it stays a string, is_array() rejects it,
		// and the HIT is served with no edge headers rather than a fatal.
		$xspeed_edge_headers = '@@XSPEED_EDGE_HEADERS@@';

		// A page whose answer differs from the site-wide one carries its own
		// pairs in the sidecar. It REPLACES the baked set rather than adding
		// to it: the two describe the same response, and merging would leave
		// the baked lifetime in place beside the hold meant to overrule it.
		if ( isset( $xspeed_meta['edge_headers'] ) && is_array( $xspeed_meta['edge_headers'] ) ) {
			$xspeed_edge_headers = $xspeed_meta['edge_headers'];
		}

		// A URL carrying an ignored param gets the hold baked for it instead.
		// This file serves `/post?utm_source=x` from the entry stored for
		// `/post`, but an edge keys on the full URL, so it would store each
		// variant as its own copy, and a purge of `/post` never reaches them.
		// Holding the variants keeps the edge to URLs a purge can name. This
		// file keeps serving them; only the edge copy is refused.
		//
		// It replaces the sidecar too, since that carries a lifetime. The
		// page's `Cache-Tag` is kept, as Cache::edge_headers_for() keeps it on
		// every hold. An empty literal means the hold would say the same as
		// the plain answer, and nothing changes. An un-substituted token
		// stays a string and is ignored.
		$xspeed_query_hold = '@@XSPEED_EDGE_QUERY_HOLD@@';
		if ( $xspeed_qs_unpurged && is_array( $xspeed_query_hold ) && array() !== $xspeed_query_hold ) {
			$xspeed_query_tag = ( is_array( $xspeed_edge_headers ) && isset( $xspeed_edge_headers['Cache-Tag'] ) )
				? $xspeed_edge_headers['Cache-Tag']
				: null;
			$xspeed_edge_headers = $xspeed_query_hold;
			if ( null !== $xspeed_query_tag ) {
				$xspeed_edge_headers['Cache-Tag'] = $xspeed_query_tag;
			}
		}

		// An edge that keeps a copy per device: the baked answer above lifts
		// the mobile-split hold, so it is put back on any request that is not
		// trusted. Applied last, over a sidecar and a query hold, as the baked
		// hold did before. The page's `Cache-Tag` is kept.
		if ( $xspeed_edge_keyed && ! $xspeed_edge_trusted ) {
			if ( is_array( $xspeed_edge_device['hold'] ) && array() !== $xspeed_edge_device['hold'] ) {
				$xspeed_device_tag = ( is_array( $xspeed_edge_headers ) && isset( $xspeed_edge_headers['Cache-Tag'] ) )
					? $xspeed_edge_headers['Cache-Tag']
					: null;
				$xspeed_edge_headers = $xspeed_edge_device['hold'];
				if ( null !== $xspeed_device_tag ) {
					$xspeed_edge_headers['Cache-Tag'] = $xspeed_device_tag;
				}
			}
			// Refreshed at most once a minute. Only its mtime is read. Best-effort:
			// a failed write only means the evidence is not recorded this time.
			if ( $xspeed_edge_unkeyed && ( ! file_exists( $xspeed_edge_evidence ) || time() - (int) filemtime( $xspeed_edge_evidence ) >= 60 ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- pre-WP drop-in; WP_Filesystem isn't loaded. An empty marker in the cache dir, which Cache reads too.
				@file_put_contents( $xspeed_edge_evidence, '' );
			}
		}

		// The one setting this file reads for itself. Everything else about
		// the edge answer is baked, because re-deriving it here would mean
		// loading options before WordPress exists. `off` is the exception
		// because it is the emergency switch: when something is wrong in
		// production at three in the morning, waiting for a re-bake is not an
		// answer. Any other value is a pin, and a pin is already baked in.
		if ( defined( 'XSPEED_EDGE_PROVIDER' ) && 'off' === strtolower( (string) XSPEED_EDGE_PROVIDER ) ) {
			$xspeed_edge_headers = array();
		}

		// A page with a lifetime of its own (a nonce it carries, a per-post
		// expiry: the sidecar `ttl`) may not be kept at the edge past it. A
		// lifetime in the pairs is the site's, so it is cut to what this copy
		// has left. Without the cut a page capped to its nonce went to the edge
		// with the site's lifetime and served a dead nonce until the next purge.
		//
		// The two patterns copy Cache::EDGE_LIFETIME_HEADER and
		// Cache::EDGE_LIFETIME_DIRECTIVE, and the cut copies
		// Cache::cap_edge_lifetime(): the class is not loaded yet.
		if ( is_array( $xspeed_edge_headers ) && isset( $xspeed_meta['ttl'] ) && (int) $xspeed_meta['ttl'] > 0 ) {
			$xspeed_left = max( 0, $xspeed_ttl - $xspeed_age );
			foreach ( $xspeed_edge_headers as $xspeed_edge_name => $xspeed_edge_value ) {
				if ( ! preg_match( '/(?:^|-)control$/i', (string) $xspeed_edge_name ) ) {
					continue;
				}
				$xspeed_capped = preg_replace_callback(
					'/(?<![\w-])(max-age|s-maxage)\s*=\s*"?(\d+)"?/i',
					static function ( array $m ) use ( $xspeed_left ): string {
						return $m[1] . '=' . min( (int) $m[2], $xspeed_left );
					},
					(string) $xspeed_edge_value
				);
				if ( is_string( $xspeed_capped ) ) {
					$xspeed_edge_headers[ $xspeed_edge_name ] = $xspeed_capped;
				}
			}
		}

		if ( is_array( $xspeed_edge_headers ) ) {
			foreach ( $xspeed_edge_headers as $xspeed_edge_name => $xspeed_edge_value ) {
				header( $xspeed_edge_name . ': ' . $xspeed_edge_value );
			}
		}

		// When this page's HTML was generated, so a CDN that just asked for a
		// purge can check whether the origin actually served something newer.
		// The cache file's mtime is that moment: store_static() writes the
		// file at the end of the render it came from.
		//
		// Emitted AFTER the baked pairs above and therefore replacing any
		// build stamp among them. A baked value is the time the DROP-IN was
		// installed, identical on every page for as long as it stays
		// installed, so it would answer "yes, freshly built" to every purge
		// check forever. Cache::edge_headers_for() already drops it from the
		// bake; this ordering means a drop-in installed by an older version
		// cannot lie either.
		header( 'X-XSpeed-Built: ' . (int) filemtime( $xspeed_cache_file ) );

		// The Markdown entry and the HTML one answer the same URL, so a
		// cache downstream has to key on Accept to keep them apart.
		if ( '' !== $xspeed_variant ) {
			header( 'Vary: Accept', false );
		}

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
		// Don't count a bot, a scanner, a 404 or one of xSpeed's own
		// requests as a visitor hit. It has to be decided HERE: the log line
		// is just "hit" with no user agent, so Hit_Counter batch-counts these
		// lines blind and nothing downstream can reclassify one. The UA
		// pattern is baked in at install time from
		// Hit_Counter::excluded_ua_regex() (the drop-in runs before
		// WordPress, so it cannot ask). xSpeed's own requests also carry the
		// X-XSpeed-Self header (Self_Traffic::HEADER), which is what catches
		// a warmer renamed to a real browser's UA without dropping real
		// visitors on that browser. An empty UA counts as automated, like
		// is_bot_ua(''). A cached 404 is excluded on the PHP path too.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- pre-WP drop-in; only matched against a baked pattern, never echoed or stored.
		$xspeed_hit_ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$xspeed_hit_ex = '@@XSPEED_HIT_EXCLUDE_RE@@';
		$xspeed_self   = '' === $xspeed_hit_ua
			|| ! empty( $_SERVER['HTTP_X_XSPEED_SELF'] )
			|| ( isset( $xspeed_meta['status'] ) && 404 === (int) $xspeed_meta['status'] );
		if ( ! $xspeed_self && '@@' !== substr( $xspeed_hit_ex, 0, 2 ) && '' !== $xspeed_hit_ex ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a pattern this file did not compose is not worth a warning on every hit.
			$xspeed_self = 1 === @preg_match( '#(' . $xspeed_hit_ex . ')#i', $xspeed_hit_ua );
		}
		if ( ! $xspeed_self ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- pre-WP drop-in; WP_Filesystem isn't loaded. One short line, append + lock; failures are non-fatal (the ratio just under-counts).
			@file_put_contents( $xspeed_hits_log, "hit\n", FILE_APPEND | LOCK_EX );
		}

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
