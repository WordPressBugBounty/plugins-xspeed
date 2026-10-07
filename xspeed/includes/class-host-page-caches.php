<?php
/**
 * Host_Page_Caches — forward a purge to a full-page cache that lives in the
 * WEB SERVER rather than in WordPress.
 *
 * The full rationale is on the class below. Kept short here on purpose:
 * Plugin Check reads only the first 50 lines of a file when looking for the
 * direct-access guard, so a long header docblock pushes the guard out of its
 * window and the file reports `missing_direct_file_access_protection` while
 * being perfectly well guarded.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * Forward a purge to a full-page cache that lives in the WEB SERVER rather
 * than in WordPress.
 *
 * Why this exists: `Cache::purge_all()` sweeps the trees xSpeed owns, under
 * `wp-content/cache/`. On a host that runs its own nginx FastCGI full-page
 * cache in front of PHP, that sweep reaches none of it — nginx keeps
 * answering from `/etc/nginx/cache/<site>` until its own `fastcgi_cache_valid`
 * window expires (an hour on a stock xCloud site). The observable bug is the
 * one every "purge did nothing" report describes: the admin edits a page,
 * clicks Purge All, xSpeed reports the files cleared, and the anonymous
 * visitor still gets yesterday's HTML.
 *
 * We do not talk to nginx ourselves, and we do not touch its cache directory.
 * The purge goes through **Nginx Helper** (rtCamp), which is the plugin the
 * host installs and configures alongside that cache — xCloud, for one, runs
 * `wp plugin install nginx-helper --activate`, writes its options and sets
 * `RT_WP_NGINX_HELPER_CACHE_PATH` when a site owner turns full-page caching
 * on. Nginx Helper owns the cache path, the key derivation and the options;
 * all of that is read-only to us, always.
 *
 * `rt_nginx_helper_purge_all` is an action Nginx Helper exposes for exactly
 * this — its own source comments it "expose action to allow other plugins to
 * purge the cache" (includes/class-nginx-helper.php) — so this is a supported
 * seam, not a reach into another plugin's internals.
 *
 * Detection is by constant and global only, never `is_plugin_active()` on a
 * path string: a renamed plugin folder must not silently turn the integration
 * off. Same rule as Render_Caches.
 *
 * **This class is the mechanism, not the policy.** It knows how to detect the
 * server cache and how to ask Nginx Helper to clear it. WHEN to ask is decided
 * one level up, by `Server_Caches::forward()`, from the `intent` and `scope`
 * on the purge-event contract — which is also where the reasoning for standing
 * down on a content purge is written. Nothing here listens to a hook.
 *
 * Two calls: purge_nginx_helper() clears the whole zone through the action
 * above, and purge_nginx_helper_urls() clears named pages through
 * `$GLOBALS['nginx_purger']->purge_url()`, a method on its purger object
 * rather than an action. That method checks `is_page()`/`is_single()` before
 * purging an AMP copy; both only warn when the global `$wp_query` does not
 * exist, and `wp-settings.php` creates it for every request, so WP-CLI, cron
 * and REST purges are quiet.
 *
 * **Multisite:** nginx keys one cache zone per *install*, not per subsite, so
 * a purge here clears every site on the network at the nginx layer. That is
 * accepted rather than worked around — a cold cache costs one slow request
 * per page, stale HTML costs a wrong page for the whole TTL.
 */
final class Host_Page_Caches {

	/**
	 * The site option Nginx Helper stores its configuration in. Network-wide
	 * on multisite, which is why it is read with `get_site_option()`.
	 */
	private const NH_OPTION = 'rt_wp_nginx_helper_options';

	/**
	 * The `cache_method` value that means "nginx FastCGI full-page cache".
	 * The other one Nginx Helper supports is `enable_redis`, which is a
	 * page cache in Redis fronted by nginx's `srcache` module — a different
	 * layer, not present on the hosts this integration targets, and not
	 * something a purge from here should reach for.
	 */
	private const NH_FASTCGI = 'enable_fastcgi';

	/**
	 * Forward a full purge to the server-level cache, when there is one.
	 *
	 * No gate on the purge's reason here — `Server_Caches::forward()` has
	 * already decided this purge should reach the server layer. Detection is
	 * still checked, because it is a fact about the environment rather than
	 * about the purge, and it is only stable at purge time: Nginx Helper
	 * builds `$GLOBALS['nginx_purger']` from its own `plugins_loaded`
	 * callback, so anything asked earlier races its load order.
	 *
	 * Re-entrancy is handled upstream. A third-party listener on
	 * `rt_nginx_helper_purge_all` that calls back into `Cache::purge_all()`
	 * used to recurse here until PHP ran out of stack, which is what the
	 * latch this method once carried was for. The inner purge now re-enters
	 * `Cache::dispatch_purge_event()`, whose `$purge_events_in_flight` guard
	 * is still held by the outer one and returns before any adapter runs.
	 * Pinned by a test that recurses through `Cache::purge_all()` itself
	 * rather than through this method.
	 *
	 * @return bool Whether the purge was forwarded.
	 */
	public static function purge_nginx_helper(): bool {
		if ( ! self::nginx_helper_is_fastcgi() ) {
			return false;
		}

		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Nginx Helper's own public integration hook; an xspeed_-prefixed name would reach nothing.
			do_action( 'rt_nginx_helper_purge_all' );
		} catch ( \Throwable $e ) {
			/*
			 * The same third-party listeners the latch above exists for can
			 * also throw, and everything on this action is code we do not
			 * own. Letting it out would take down `Cache::purge_all()` — the
			 * admin presses Purge All, another plugin's mu-plugin fatals, and
			 * the failure reads as xSpeed's. The local sweep has already
			 * happened by the time we run, so swallowing this costs the
			 * server layer and nothing else. `Server_Caches::forward()`
			 * catches around this call for the same reason and logs it under
			 * WP_DEBUG; this inner catch is what turns a throw into an honest
			 * "not forwarded" return rather than a reported purge.
			 */
			return false;
		}

		return true;
	}

	/**
	 * Purge named pages from the nginx FastCGI cache through Nginx Helper.
	 *
	 * Its purger's `purge_url()` is the same call Nginx Helper makes for its
	 * own post purges. Feeds are not added (`$feed = false`): xSpeed names the
	 * feeds it means. If the purger cannot take a URL, the whole zone goes
	 * instead, because a page left stale is worse than a cold cache. The
	 * same happens when the calls run past `$seconds`: with the `get_request`
	 * method each one is a blocking HTTP request, and a slow purge endpoint
	 * would otherwise hold the request for the whole list.
	 *
	 * @param string[] $urls    Absolute URLs on this site.
	 * @param float    $seconds Time allowed for the per-URL calls; 0 for no limit.
	 */
	public static function purge_nginx_helper_urls( array $urls, float $seconds = 0.0 ): bool {
		if ( array() === $urls || ! self::nginx_helper_is_fastcgi() ) {
			return false;
		}
		$purger = $GLOBALS['nginx_purger'] ?? null;
		if ( ! is_object( $purger ) || ! method_exists( $purger, 'purge_url' ) ) {
			return self::purge_nginx_helper();
		}
		$started = microtime( true );
		$left    = count( $urls );
		try {
			foreach ( $urls as $url ) {
				$purger->purge_url( (string) $url, false );
				--$left;
				if ( $left > 0 && $seconds > 0 && microtime( true ) - $started > $seconds ) {
					return self::purge_nginx_helper();
				}
			}
		} catch ( \Throwable $e ) {
			return self::purge_nginx_helper();
		}
		return true;
	}

	/**
	 * Whether Nginx Helper is present AND configured against an nginx FastCGI
	 * cache.
	 *
	 * Three separate facts, because each one alone is a false positive:
	 *
	 * 1. `RT_WP_NGINX_HELPER_CACHE_PATH` — there is a directory to purge, and
	 *    we know which one, because Health names it.
	 *
	 *    Do NOT read this as evidence the host configured anything. Nginx
	 *    Helper defines the constant itself whenever it is not already set,
	 *    defaulting to `/var/run/nginx-cache` through its own
	 *    `rt_wp_nginx_helper_cache_path` filter (its
	 *    `includes/class-nginx-helper.php`, in the constructor). So it is
	 *    defined on every install and cannot be missing while the plugin is
	 *    loaded — the only way past this check is a WordPress older than the
	 *    plugin's minimum, where it returns before the `define`.
	 *
	 *    On a site where the host never set a path, that default is what
	 *    Nginx Helper would unlink, so it is also what we forward a purge
	 *    against and what Health names. That directory usually does not
	 *    exist, making its `purge_all()` a no-op we would report as a purge.
	 *    Left alone deliberately: it is exactly what the admin gets from
	 *    Nginx Helper's own Purge All button, and second-guessing another
	 *    plugin's configured path is not ours to do.
	 * 2. `$GLOBALS['nginx_purger']` — the plugin finished booting and built a
	 *    purger. The constant can be defined in `wp-config.php` by a host
	 *    whose site owner then deactivated the plugin, in which case the
	 *    action has no listener and firing it is a silent no-op we would
	 *    still report as a purge.
	 * 3. `cache_method === 'enable_fastcgi'` — it is the page cache we mean.
	 *    On `enable_redis` the same action clears a Redis key space that
	 *    xSpeed's own object-cache flush may already own.
	 *
	 * Note what is deliberately NOT in the gate: `enable_purge`. That option
	 * governs Nginx Helper's own AUTOMATIC purging — its post-save, comment
	 * and term hooks each check it — and does not reach `purge_all()`, which
	 * runs whatever it is set to. An admin who switched automatic purging off
	 * has said "don't purge behind my back"; they have not said "ignore me
	 * when I press Purge All". Reading it as the latter would leave an
	 * explicit, operator-initiated purge silently short of the layer actually
	 * serving the page, which is the exact failure this integration exists to
	 * fix. `nginx_helper_purges_changes()` does read it, only to decide
	 * whether a content purge can be left to Nginx Helper.
	 */
	public static function nginx_helper_is_fastcgi(): bool {
		return null !== self::nginx_helper_cache_path();
	}

	/**
	 * The cache directory Nginx Helper is pointed at, or null when the
	 * integration does not apply. Read-only — we never define the constant
	 * and never write the option.
	 *
	 * Health reports the path; the gate only cares whether there is one.
	 */
	public static function nginx_helper_cache_path(): ?string {
		if ( ! defined( 'RT_WP_NGINX_HELPER_CACHE_PATH' ) ) {
			return null;
		}
		$path = (string) constant( 'RT_WP_NGINX_HELPER_CACHE_PATH' );
		if ( '' === $path ) {
			return null;
		}
		if ( ! isset( $GLOBALS['nginx_purger'] ) || ! is_object( $GLOBALS['nginx_purger'] ) ) {
			return null;
		}
		$options = get_site_option( self::NH_OPTION );
		if ( ! is_array( $options ) || self::NH_FASTCGI !== ( $options['cache_method'] ?? '' ) ) {
			return null;
		}
		return $path;
	}

	/**
	 * Whether Nginx Helper purges changed pages by itself.
	 *
	 * Its `enable_purge` option is the one switch in front of all of its
	 * automatic purging: the post, comment and term hooks each return early
	 * without it. It defaults to off, so an install where the host never
	 * turned it on purges nothing when a post is published. Not part of the
	 * detection gate above; `Server_Caches` asks it only to decide whether a
	 * content purge can be left to Nginx Helper.
	 */
	public static function nginx_helper_purges_changes(): bool {
		$options = get_site_option( self::NH_OPTION );
		return is_array( $options ) && ! empty( $options['enable_purge'] );
	}

	/**
	 * The configured purge method (`unlink_files`, `get_request`, …), or ''
	 * when unset. Health uses it to decide whether the permissions caveat
	 * applies; nothing gates on it.
	 */
	public static function nginx_helper_purge_method(): string {
		$options = get_site_option( self::NH_OPTION );
		return is_array( $options ) ? (string) ( $options['purge_method'] ?? '' ) : '';
	}
}
