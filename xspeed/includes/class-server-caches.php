<?php
/**
 * Server_Caches — forward xSpeed's purges to a cache in front of PHP.
 *
 * xSpeed owns one cache. A LiteSpeed stack has two: ours, and LSCache holding
 * its own copy of the same URL at the server. Purging ours and stopping there
 * left the server still serving the page we had just invalidated — measured on
 * OpenLiteSpeed before this existed. Two adapters ship: LiteSpeed, and the
 * nginx FastCGI cache reached through the Nginx Helper plugin.
 *
 * Each adapter decides for itself which purges are worth forwarding, from the
 * `intent` and `scope` on the context. They do not answer alike, and the
 * reasoning for each lives on the adapter — see `forward_nginx_helper()`,
 * which stands down on a content purge where `forward_litespeed()` does not.
 *
 * This is the counterpart to Render_Caches. That one clears caches of RENDERED
 * OUTPUT owned by page builders; this one clears caches of whole RESPONSES
 * owned by the web server. Both are integrations with software we do not ship,
 * and both hang off a public seam so a site can add its own.
 *
 * Nothing here touches another plugin's files or runs a shell command. Each
 * integration calls the documented public API of the plugin it integrates
 * with, and detects that plugin by class or constant rather than by path — a
 * renamed plugin folder must not silently disable the integration.
 *
 * Tier: Free. xSpeed's tiering rule (FEATURES.md) is that anything LiteSpeed
 * Cache ships free, xSpeed ships free — and their purge API is free. Gating
 * this would mean an unlicensed site keeps serving stale HTML from LSCache,
 * which is a correctness bug, not a paid feature.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Server_Caches {

	/*
	 * Forwarding itself is deliberately not a listener. `Cache` calls
	 * forward() directly, before it fires the public purge actions.
	 *
	 * As a listener this would be one callback among many, and WordPress stops
	 * dispatching an action's remaining callbacks when an earlier one throws —
	 * so an unrelated third-party listener's bug could silently skip our
	 * LiteSpeed forwarding, leaving the server serving stale HTML while xSpeed
	 * reported a successful purge. Shipped behaviour should not be hostage to
	 * that. Third parties still extend through `xspeed_purge_server_caches`
	 * below, which runs after we have done our own work.
	 *
	 * Both built-in adapters are reached only from forward(). Neither
	 * registers a hook of its own, so this is the single place that decides
	 * whether a given purge reaches a server cache.
	 *
	 * boot() below is the one exception, and it registers nothing that
	 * forwards — only the end-of-import purge that forward_nginx_helper()'s
	 * import gate depends on.
	 */

	/**
	 * Register the end-of-import purge.
	 *
	 * `forward_nginx_helper()` stands down for the length of an import: a
	 * WXR run fires hundreds of individually-justified purges, and clearing
	 * the whole nginx zone once per imported post is the waste that gate
	 * exists to stop. That trade is only correct if a single purge follows
	 * the import — otherwise the install finishes with nginx still serving
	 * every pre-import page for the rest of its TTL, which is worse than the
	 * waste. This is that purge, and nothing else issues it.
	 *
	 * `import_end` is WordPress's own signal, fired by the WXR importer and
	 * by every importer that follows its lead. An importer that fires
	 * `import_start` and then dies without `import_end` leaves the zone
	 * stale — the same outcome as not having the gate, so no worse than
	 * before, and not worth a `shutdown` fallback that would fire a full
	 * purge on every request that ever touched an importer.
	 */
	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'import_end', array( __CLASS__, 'purge_after_import' ) );
	}

	/**
	 * Clear everything once, now that the import is done.
	 *
	 * `complete` intent, which is what `Cache::purge_all()` announces by
	 * default — and the one intent the import gate lets through, so this
	 * reaches the server layer even though `did_action( 'import_start' )` is
	 * still true for the rest of the request.
	 */
	public static function purge_after_import(): void {
		if ( ! class_exists( __NAMESPACE__ . '\\Cache' ) ) {
			return;
		}
		Cache::purge_all( 'import finished' );
	}

	/**
	 * Forward one purge to every server cache we recognise.
	 *
	 * The public context carries the action an adapter should take:
	 * `urls` purges only the listed response URLs, `site` purges this site's
	 * response cache, and `network` represents a deliberate whole-tree sweep.
	 * Older callers that omit `scope` retain the original url/null behaviour.
	 *
	 * @param array<string,mixed> $context See `xspeed_after_purge_url`.
	 */
	public static function forward( $context ): void {
		if ( ! is_array( $context ) ) {
			return;
		}

		// A broken built-in adapter must not suppress the public seam. The local
		// purge already succeeded, and another adapter may still clear the edge.
		try {
			self::forward_litespeed( $context );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- built-in integration failure after local invalidation.
				error_log( '[xspeed] LiteSpeed response purge failed: ' . $e->getMessage() );
			}
		}

		// Its own try, for the same reason the LiteSpeed one has its own: two
		// server caches can be in front of one site, and a bad day for one
		// adapter must not leave the other serving stale HTML.
		try {
			self::forward_nginx_helper( $context );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- built-in integration failure after local invalidation.
				error_log( '[xspeed] nginx FastCGI response purge failed: ' . $e->getMessage() );
			}
		}

		/**
		 * Fires so a site can invalidate a server cache xSpeed does not know.
		 *
		 * Same context as the event that triggered it. Use this rather than
		 * subscribing to `xspeed_after_purge_url` directly when you want to
		 * run only after the built-in integrations have had their turn.
		 *
		 * @since 1.2.3
		 *
		 * @param array $context Bounded purge context.
		 */
		// Use WordPress' dispatcher so current_action(), did_action(), the `all`
		// hook and observability tools retain native semantics. Cache wraps each
		// callback one level down so a throwing adapter cannot cancel the ones
		// queued behind it.
		Cache::do_action_isolated( 'xspeed_purge_server_caches', $context );
	}

	/**
	 * LiteSpeed Cache: URL purges plus its response-cache-only full seam.
	 *
	 * URL purges use LiteSpeed's documented `litespeed_purge_url` action. A
	 * site-wide response invalidation calls the public
	 * `LiteSpeed\Purge::purge_all_lscache()` seam added in 7.7. Older releases
	 * expose only the broad purge-all API, so full forwarding deliberately
	 * stands down there. Do not use `litespeed_purge_all`: in 7.9 that also
	 * deletes LiteSpeed
	 * CSS/JS, local-resource, object and opcode caches and may purge its
	 * Cloudflare integration. xSpeed only owns the response invalidation.
	 *
	 * Detected by constant, not plugin path. `LSCWP_V` is defined by the
	 * plugin bootstrap and survives a renamed folder.
	 *
	 * Forwards on every intent, including `content` — unlike the nginx
	 * adapter, which stands down there. See `forward_nginx_helper()` for why
	 * the two differ.
	 *
	 * @param array<string,mixed> $context Public purge context.
	 */
	private static function forward_litespeed( array $context ): void {
		if ( ! defined( 'LSCWP_V' ) ) {
			return;
		}

		$url   = isset( $context['url'] ) && is_string( $context['url'] ) ? $context['url'] : '';
		$host  = isset( $context['host'] ) && is_string( $context['host'] ) ? $context['host'] : '';
		$scope = isset( $context['scope'] ) && is_string( $context['scope'] )
			? $context['scope']
			: ( '' !== $url ? 'urls' : 'site' );

		if ( 'none' === $scope ) {
			return;
		}

		if ( 'site' === $scope || 'network' === $scope ) {
			// A full purge scoped to ANOTHER site — Multisite::purge_site()
			// runs inside switch_to_blog(), so the request's LSCache is not
			// that site's — must not flush ours. `'*'` is the deliberate
			// whole-tree sweep and does mean everything. An empty host is the
			// single-site case, where the purge is ours by definition.
			if ( '' !== $host && '*' !== $host && ! self::host_is_this_site( $host ) ) {
				return;
			}
			if ( is_callable( array( '\\LiteSpeed\\Purge', 'purge_all_lscache' ) ) ) {
				// LiteSpeed normally prefixes `*` with the current blog ID. A
				// network response contract needs the raw `*` tag. This is the same
				// official switch used by its Empty Entire Cache path and does not
				// invoke its CSS/JS, object or opcode purgers.
				if ( 'network' === $scope && ! defined( 'LSWCP_EMPTYCACHE' ) ) {
					define( 'LSWCP_EMPTYCACHE', true );
				}
				\LiteSpeed\Purge::purge_all_lscache( 'xSpeed response invalidation' );
			}
			return;
		}

		$urls = array();
		if ( isset( $context['urls'] ) && is_array( $context['urls'] ) ) {
			$urls = $context['urls'];
		} elseif ( '' !== $url ) {
			$urls = array( $url );
		}
		$targets = array();
		foreach ( array_unique( array_filter( $urls, 'is_string' ) ) as $target_url ) {
			$targets = array_merge( $targets, self::litespeed_targets_for_url( $target_url ) );
		}
		foreach ( array_values( array_unique( $targets ) ) as $target ) {
			do_action( 'litespeed_purge_url', $target );
		}
	}

	/**
	 * nginx FastCGI full-page cache, through the Nginx Helper plugin.
	 *
	 * Forwards on every intent, and on `content` only when Nginx Helper is not
	 * purging for itself or `xspeed_nginx_helper_defer_content_purge` says
	 * to. That asymmetry with `forward_litespeed()`, which forwards on all of
	 * them, is deliberate.
	 *
	 * The two server caches are not alike in what a purge costs. LSCache is
	 * per-site and tag-based: a site purge bumps one tag for one blog. The
	 * nginx FastCGI zone is ONE directory per WordPress install, and clearing
	 * it is a recursive unlink of every cached page — on multisite, of every
	 * site on the network. So the blast radius of forwarding is an order of
	 * magnitude apart for the same event.
	 *
	 * The other half is that we are not the only one purging. Nginx Helper
	 * hooks `transition_post_status`, `before_delete_post` and the comment
	 * hooks itself and purges only the URLs the edit touched (the post, the
	 * homepage, the post's archives), behind its own `enable_purge` option and
	 * an import guard. Its term hooks purge the homepage alone, which is why
	 * a renamed or deleted term is `presentation` and still forwards. On a content
	 * purge it has already done the narrow, correct thing. Forwarding on top
	 * of that replaced targeted purging with a whole-install wipe at the same
	 * frequency: publishing one post cleared every cached page on the site,
	 * and an import cost one full wipe per post. (QA #444.)
	 *
	 * That argument only holds while Nginx Helper's `enable_purge` is on. It
	 * defaults to off, and with it off Nginx Helper purges nothing on a
	 * content edit. Standing down there left the edited post stale at the
	 * server for the whole TTL, where before this adapter existed it was
	 * cleared. So a content purge forwards when Nginx Helper is not purging
	 * for itself. (QA #448)
	 *
	 * The trade that stays: Nginx Helper purges the post, the homepage and
	 * the post's archives. An ordinary page that lists recent posts is none of
	 * those, and keeps its old list until the server TTL expires. The
	 * `xspeed_nginx_helper_defer_content_purge` filter returns to clearing
	 * the whole zone on every content purge outside an import, for a site that
	 * needs those pages current.
	 *
	 * `presentation` and `complete` still forward, because neither of those is
	 * something Nginx Helper covers. It has no hook for `switch_theme`,
	 * `activated_plugin` or `wp_update_nav_menu`, and no notion of a settings
	 * write or a core update — and each of those changes the markup of every
	 * page, not a listed few. An unrecognised intent forwards too: a purge
	 * whose reason we do not know is likelier to need the server layer than
	 * not, and a redundant purge costs a cold cache while a skipped one costs
	 * wrong HTML for the whole TTL.
	 *
	 * Whether LiteSpeed should also stand down on `content` is a fair question
	 * and was deliberately not revisited here — it has no targeted self-purge
	 * to fall back on, so standing it down would leave LSCache stale where
	 * nginx is merely over-cleared.
	 *
	 * @param array<string,mixed> $context Public purge context.
	 */
	private static function forward_nginx_helper( array $context ): void {
		// Guarded rather than assumed: Free is upgraded as a unit, but a
		// half-copied update can leave this file newer than that one.
		if ( ! class_exists( __NAMESPACE__ . '\\Host_Page_Caches' ) ) {
			return;
		}

		$url   = isset( $context['url'] ) && is_string( $context['url'] ) ? $context['url'] : '';
		$scope = isset( $context['scope'] ) && is_string( $context['scope'] )
			? $context['scope']
			: ( '' !== $url ? 'urls' : 'site' );

		// `urls` is a per-URL purge, which this integration does not do yet —
		// see Host_Page_Caches. Standing down is the honest answer: the
		// alternative, treating a one-page purge as a reason to clear the
		// whole install, is the bug this method exists to fix.
		if ( 'urls' === $scope || 'none' === $scope ) {
			return;
		}

		$intent = isset( $context['intent'] ) && is_string( $context['intent'] ) && '' !== $context['intent']
			? $context['intent']
			: 'complete';

		// Nothing to decide on a site with no nginx zone, so the filter below
		// is only asked when there is one.
		if ( ! Host_Page_Caches::nginx_helper_is_fastcgi() ) {
			return;
		}

		if ( 'content' === $intent ) {
			/**
			 * Whether a content purge (a post saved, a comment approved, a
			 * term added) is left to Nginx Helper instead of clearing the
			 * whole nginx cache.
			 *
			 * Defaults to true when Nginx Helper's automatic purging is on,
			 * since it has already purged the post, the homepage and the
			 * post's archives. Return false to clear the whole zone instead,
			 * for a site whose pages list posts somewhere Nginx Helper does
			 * not purge.
			 *
			 * @param bool                $defer   Whether to leave it to Nginx Helper.
			 * @param array<string,mixed> $context Public purge context.
			 */
			$defer = (bool) apply_filters(
				'xspeed_nginx_helper_defer_content_purge',
				Host_Page_Caches::nginx_helper_purges_changes(),
				$context
			);
			if ( $defer ) {
				return;
			}
		}

		// An import is a long run of legitimate purges that each individually
		// justify a forward — new terms, new menu items — and together clear
		// the install's cache hundreds of times for one operation. Nginx
		// Helper stands its own purging down for exactly this (its
		// `is_import_request()`), and a single purge after the import is both
		// cheaper and more correct. An explicit `complete` still goes through:
		// an operator who presses Purge All mid-import means it.
		if ( 'complete' !== $intent && self::is_importing() ) {
			return;
		}

		// No host check, deliberately — the mirror of the one in
		// forward_litespeed(). There, a purge aimed at another blog must not
		// flush THIS request's LSCache, because LSCache is per-site. nginx
		// keys one zone per install, so the other blog's cached pages live in
		// the same directory as ours: skipping on a foreign host would leave
		// the pages the purge was actually for still being served. Pro's
		// Multisite::purge_site() runs inside switch_to_blog() and reaches
		// here with that blog's host.
		Host_Page_Caches::purge_nginx_helper();
	}

	/**
	 * Whether WordPress is importing content right now.
	 */
	private static function is_importing(): bool {
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return true;
		}

		// The WXR importer defines WP_IMPORTING, but not every importer does;
		// `import_start` is the signal the others share.
		return function_exists( 'did_action' ) && did_action( 'import_start' ) > 0;
	}

	/**
	 * Build LiteSpeed targets for one same-site URL.
	 *
	 * @return string[]
	 */
	private static function litespeed_targets_for_url( string $url ): array {
		// Only this site's own URLs. `purge_url()` supports cross-site purges
		// (multisite, WP-CLI, cron), and LSCache is per-site: reducing another
		// site's URL to a path would have this site's LiteSpeed purge its OWN
		// /page/ — the wrong entry gone, the intended one still stale, and a
		// success reported for both. The other site's server cache is not
		// addressable from here, so we stand down and leave it to a
		// network-aware listener on `xspeed_purge_server_caches`. (QA review)
		if ( ! self::is_this_site( $url ) ) {
			return array();
		}
		// Both trailing-slash forms. Our own sweep purges `/about` and
		// `/about/` because the cache key preserves whichever the request
		// used, and LiteSpeed tags them separately for the same reason — so
		// forwarding only the canonical form can leave the other a HIT. Root
		// stays a single '/'. (QA review; plausible rather than reproduced —
		// LSCache dedupes identical tags, so the cost of being wrong is one
		// redundant purge.)
		return self::slash_forms( self::site_relative( $url ) );
	}

	/**
	 * A relative target in both trailing-slash forms, deduplicated.
	 *
	 * @return string[]
	 */
	private static function slash_forms( string $relative ): array {
		$query = '';
		$path  = $relative;
		$split = strpos( $relative, '?' );
		if ( false !== $split ) {
			$path  = substr( $relative, 0, $split );
			$query = substr( $relative, $split );
		}
		if ( '/' === $path || '' === $path ) {
			return array( $relative );
		}
		$bare = rtrim( $path, '/' );
		// Keep the exact spelling too. `/path///` can be a distinct server key.
		return array_values( array_unique( array( $path . $query, $bare . $query, $bare . '/' . $query ) ) );
	}

	/**
	 * Is this URL served by the site we are running as?
	 *
	 * Host and port, because a site on a non-standard port is a different
	 * origin. Unknown either way means no — a purge sent to the wrong cache is
	 * worse than one not sent at all.
	 */
	private static function is_this_site( string $url ): bool {
		if ( ! self::host_is_this_site( self::host_of( $url ) ) ) {
			return false;
		}

		// On a subdirectory network, equal hosts do not mean equal blogs.
		if ( function_exists( 'is_multisite' ) && is_multisite()
			&& function_exists( 'get_blog_details' ) && function_exists( 'get_current_blog_id' )
		) {
			$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- bounded URL ownership lookup.
			if ( ! is_array( $parts ) ) {
				return false;
			}
			$host     = isset( $parts['host'] ) ? (string) $parts['host'] : '';
			$path     = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
			$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
			for ( $take = min( count( $segments ), 2 ); $take >= 0; --$take ) {
				$candidate = 0 === $take ? '/' : '/' . implode( '/', array_slice( $segments, 0, $take ) ) . '/';
				$details   = get_blog_details( array( 'domain' => $host, 'path' => $candidate ), false );
				if ( $details && isset( $details->blog_id ) ) {
					return (int) $details->blog_id === (int) get_current_blog_id();
				}
			}
		}

		return true;
	}

	/** Compare a host[:port] against the running site's. */
	private static function host_is_this_site( string $host ): bool {
		if ( ! function_exists( 'home_url' ) ) {
			return false;
		}
		$ours = self::host_of( (string) home_url( '/' ) );
		return '' !== $ours && '' !== $host && $ours === strtolower( $host );
	}

	/**
	 * host[:port] of a URL, lowercased; '' when it has none.
	 *
	 * A port that is the default for the scheme is dropped, because it is not
	 * part of the origin: `https://site.com:443/p/` and `https://site.com/p/`
	 * are the same page, and RFC 3986 6.2.3 says so. Comparing them as raw
	 * strings made `:443` look like a different site, so the purge stood down
	 * and LiteSpeed was told nothing at all — while the caller was told the
	 * page "was already cold". The page kept serving the old copy until its
	 * TTL ran out.
	 *
	 * Reachable from `wp xspeed cache purge-url`, the MCP `purge_url` tool,
	 * and any plugin passing a canonical URL that spells out the port. The
	 * reverse direction was worse: a site whose own `home_url()` carries
	 * `:443` — normal behind a proxy — matched none of its own URLs, so no
	 * per-page purge ever reached the server cache, silently, site-wide.
	 *
	 * A NON-default port is still kept: `site.com:8443` genuinely is a
	 * different origin from `site.com`, and collapsing those would send one
	 * site's purge to another's cache. (QA #348)
	 */
	private static function host_of( string $url ): string {
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- host only.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( (string) $parts['host'] );
		if ( empty( $parts['port'] ) ) {
			return $host;
		}
		$port   = (int) $parts['port'];
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			return $host;
		}
		return $host . ':' . $port;
	}

	/**
	 * Reduce an absolute URL to the site-relative path LiteSpeed keys on.
	 *
	 * LiteSpeed does this itself in `Utility::make_relative()`, by stripping a
	 * `LSCWP_DOMAIN` built with `HTTP_URL_STRIP_ALL` — which strips the PORT.
	 * On a site served from a non-standard port, `http://host:8244/page/` has
	 * `http://host` removed and becomes `:8244/page/`, which is not a valid
	 * URI tag, so the purge silently matches nothing and the server keeps
	 * serving the page. Measured on OpenLiteSpeed 1.8.2 with LiteSpeed Cache
	 * 7.9: an absolute URL left the entry a HIT, the same purge sent as a path
	 * turned it into a MISS.
	 *
	 * Sending the path sidesteps their parsing entirely and is what they
	 * ultimately hash, so it is correct on standard ports too — this is not a
	 * workaround we would want to remove once they fix it.
	 *
	 * Query strings are preserved: LiteSpeed tags them separately, and a purge
	 * for `/shop/` should not silently claim to have cleared `/shop/?page=2`.
	 */
	private static function site_relative( string $url ): string {
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- path extraction only.
		if ( ! is_array( $parts ) ) {
			return $url;
		}
		// An absolute origin with no path is the homepage. LiteSpeed expects
		// '/', never the original absolute URL. Preserve a root query below.
		$path     = isset( $parts['path'] ) && '' !== (string) $parts['path'] ? (string) $parts['path'] : '/';
		$relative = '/' . ltrim( $path, '/' );
		// isset(), not empty(): a query of "0" is a real, distinct cache entry
		// and empty() calls it falsy, so `/shop/?0` would be sent as `/shop/`
		// and leave the entry the caller named stale.
		if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) {
			$relative .= '?' . $parts['query'];
		}
		return $relative;
	}
}
