<?php
/**
 * Server_Caches — forward xSpeed's purges to a cache in front of PHP.
 *
 * xSpeed owns one cache. A LiteSpeed stack has two: ours, and LSCache holding
 * its own copy of the same URL at the server. Purging ours and stopping there
 * left the server still serving the page we had just invalidated — measured on
 * OpenLiteSpeed before this existed.
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
	 * There is deliberately no boot()/add_action here. `Cache` calls forward()
	 * directly, before it fires the public purge actions.
	 *
	 * As a listener this would be one callback among many, and WordPress stops
	 * dispatching an action's remaining callbacks when an earlier one throws —
	 * so an unrelated third-party listener's bug could silently skip our
	 * LiteSpeed forwarding, leaving the server serving stale HTML while xSpeed
	 * reported a successful purge. Shipped behaviour should not be hostage to
	 * that. Third parties still extend through `xspeed_purge_server_caches`
	 * below, which runs after we have done our own work.
	 */

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
