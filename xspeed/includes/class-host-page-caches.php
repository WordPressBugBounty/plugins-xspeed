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

	/** `cache_method` for nginx's FastCGI page cache, held on disk. */
	private const NH_FASTCGI = 'enable_fastcgi';

	/**
	 * `cache_method` for a page cache held in Redis, fronted by nginx's
	 * `srcache` module. GridPane offers it, as do EasyEngine and WordOps.
	 * (#573)
	 */
	private const NH_REDIS = 'enable_redis';

	/** Modes nginx_helper_page_cache() returns. */
	public const MODE_FASTCGI = 'fastcgi';
	public const MODE_REDIS   = 'redis';

	/**
	 * Nginx Helper's defaults for the keys read here. rtCamp's 2.4.1 and
	 * GridPane's fork 9.9.10 agree on them (`nginx_helper_default_settings()`).
	 * They apply when no option row is stored, which is how Nginx Helper runs
	 * until someone saves its settings page. (#573)
	 */
	private const NH_DEFAULTS = array(
		'enable_purge'      => 0,
		'cache_method'      => self::NH_FASTCGI,
		'purge_method'      => 'get_request',
		'redis_hostname'    => '127.0.0.1',
		'redis_port'        => '6379',
		'redis_prefix'      => 'nginx-cache:',
		'redis_unix_socket' => '',
	);

	/** Purger classes Nginx Helper builds for its Redis mode. */
	private const NH_REDIS_PURGERS = array( 'PhpRedis_Purger', 'Predis_Purger' );

	/** The purger class Nginx Helper builds for anything else. */
	private const NH_FASTCGI_PURGER = 'FastCGI_Purger';

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
		if ( null === self::nginx_helper_page_cache() ) {
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
	 * Which page cache Nginx Helper drives here: MODE_FASTCGI, MODE_REDIS,
	 * or null when the integration does not apply.
	 *
	 * Each fact below alone is a false positive:
	 *
	 * 1. `$GLOBALS['nginx_purger']`, the purger Nginx Helper built once it
	 *    finished booting. Its constants and option can outlive a deactivated
	 *    plugin, in which case the action has no listener and firing it is a
	 *    silent no-op we would still report as a purge.
	 * 2. The mode Nginx Helper itself runs in. The purger's class says it
	 *    outright, since `rt_nginx_helper_purge_all` calls that object's
	 *    purge_all(). For a purger class we do not know, the mode comes from
	 *    the settings Nginx Helper resolved (see nginx_helper_settings()).
	 *    Any method other than FastCGI or Redis is silence.
	 * 3. For FastCGI, `RT_WP_NGINX_HELPER_CACHE_PATH`: there is a directory
	 *    to purge, and Health names it.
	 *
	 *    Do NOT read the path as evidence the host configured anything. Nginx
	 *    Helper defines it itself whenever it is not already set, defaulting
	 *    to `/var/run/nginx-cache` through its own
	 *    `rt_wp_nginx_helper_cache_path` filter (its
	 *    `includes/class-nginx-helper.php`, in the constructor). So it is
	 *    defined on every install while the plugin is loaded. On a site where
	 *    the host never set a path, that default is what Nginx Helper would
	 *    unlink, so it is also what Health names; second-guessing another
	 *    plugin's configured path is not ours to do.
	 *
	 * The stored option row alone is not the mode. A host can configure
	 * Nginx Helper from wp-config.php constants only, and with no row at all
	 * Nginx Helper runs on its defaults, which are FastCGI. Reading only the
	 * row missed both on GridPane-style sites, so Purge All never reached a
	 * server cache Nginx Helper was purging itself. (#573)
	 *
	 * Redis mode used to be skipped on the grounds that its purge clears a key
	 * space xSpeed's object cache may own. It doesn't: Nginx Helper's Redis
	 * purge_all() deletes only keys under its own prefix, and xSpeed's
	 * object-cache flush is scoped to its own salt and never runs FLUSHDB. So
	 * skipping protected nothing and left the server's page cache stale on
	 * GridPane, EasyEngine and WordOps sites. (#573)
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
	public static function nginx_helper_page_cache(): ?string {
		$purger = $GLOBALS['nginx_purger'] ?? null;
		if ( ! is_object( $purger ) ) {
			return null;
		}
		foreach ( self::NH_REDIS_PURGERS as $class ) {
			if ( is_a( $purger, $class ) ) {
				return self::MODE_REDIS;
			}
		}
		$method = is_a( $purger, self::NH_FASTCGI_PURGER )
			? self::NH_FASTCGI
			: (string) self::nginx_helper_settings()['cache_method'];
		if ( self::NH_REDIS === $method ) {
			return self::MODE_REDIS;
		}
		if ( self::NH_FASTCGI !== $method || ! defined( 'RT_WP_NGINX_HELPER_CACHE_PATH' ) ) {
			return null;
		}
		$path = (string) constant( 'RT_WP_NGINX_HELPER_CACHE_PATH' );
		if ( '' === $path ) {
			return null;
		}
		// With nothing saved and no constant, Nginx Helper runs on its
		// defaults: a FastCGI purger aimed at /var/run/nginx-cache whether or
		// not nginx caches anything, as on an Apache site where the plugin is
		// merely active. Count that only when the directory is there.
		if ( ! self::nginx_helper_configured() && ! is_dir( $path ) ) {
			return null;
		}
		return self::MODE_FASTCGI;
	}

	/**
	 * Whether anyone configured Nginx Helper: a saved settings row, or one of
	 * the constants a host sets. `RT_WP_NGINX_HELPER_CACHE_PATH` is not one
	 * of them, since Nginx Helper defines it itself.
	 */
	private static function nginx_helper_configured(): bool {
		if ( is_array( get_site_option( self::NH_OPTION ) ) ) {
			return true;
		}
		foreach ( array( 'RT_WP_NGINX_HELPER_CACHE_METHOD', 'RT_WP_NGINX_HELPER_REDIS_PREFIX', 'RT_WP_NGINX_HELPER_REDIS_HOSTNAME', 'RT_WP_NGINX_HELPER_REDIS_UNIX_SOCKET', 'RT_WP_NGINX_HELPER_PURGE_METHOD' ) as $name ) {
			if ( defined( $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether Nginx Helper drives an nginx FastCGI cache, the mode whose
	 * per-URL purge xSpeed uses. Redis mode takes only whole-zone purges.
	 */
	public static function nginx_helper_is_fastcgi(): bool {
		return self::MODE_FASTCGI === self::nginx_helper_page_cache();
	}

	/**
	 * The cache directory Nginx Helper is pointed at in FastCGI mode, or null.
	 * Read-only — we never define the constant and never write the option.
	 */
	public static function nginx_helper_cache_path(): ?string {
		if ( self::MODE_FASTCGI !== self::nginx_helper_page_cache() ) {
			return null;
		}
		return (string) constant( 'RT_WP_NGINX_HELPER_CACHE_PATH' );
	}

	/**
	 * Where a Redis-mode page cache lives, for Health: the server (host:port,
	 * or the unix socket) and the key prefix Nginx Helper purges. Null outside
	 * Redis mode. Never the username or password.
	 *
	 * @return array{server:string, prefix:string}|null
	 */
	public static function nginx_helper_redis_target(): ?array {
		if ( self::MODE_REDIS !== self::nginx_helper_page_cache() ) {
			return null;
		}
		$settings = self::nginx_helper_settings();
		$socket   = (string) $settings['redis_unix_socket'];

		return array(
			'server' => '' !== $socket ? $socket : $settings['redis_hostname'] . ':' . $settings['redis_port'],
			'prefix' => (string) $settings['redis_prefix'],
		);
	}

	/**
	 * Nginx Helper's settings as Nginx Helper resolved them: the stored row
	 * over its defaults, with its wp-config.php constants applied. Its purger
	 * and its automatic purging both run from this, not from the raw row.
	 *
	 * `$GLOBALS['nginx_helper_admin']->options` holds exactly that, built in
	 * the admin class's constructor before the purger is chosen, in rtCamp's
	 * plugin and in GridPane's fork. When that object is missing we resolve
	 * the same way ourselves, following the fork, which accepts every
	 * constant rtCamp's does and a few more.
	 *
	 * @return array<string,mixed>
	 */
	private static function nginx_helper_settings(): array {
		$admin = $GLOBALS['nginx_helper_admin'] ?? null;
		if ( is_object( $admin ) && isset( $admin->options ) && is_array( $admin->options ) ) {
			return array_merge( self::NH_DEFAULTS, $admin->options );
		}

		$stored   = get_site_option( self::NH_OPTION );
		$settings = array_merge( self::NH_DEFAULTS, is_array( $stored ) ? $stored : array() );

		if ( defined( 'RT_WP_NGINX_HELPER_PURGE_METHOD' ) ) {
			$settings['purge_method'] = (string) constant( 'RT_WP_NGINX_HELPER_PURGE_METHOD' );
		}

		// GridPane's fork only. FastCGI by constant turns its own purging on
		// and ignores the Redis constants.
		if ( defined( 'RT_WP_NGINX_HELPER_CACHE_METHOD' ) ) {
			$settings['cache_method'] = (string) constant( 'RT_WP_NGINX_HELPER_CACHE_METHOD' );
			if ( self::NH_FASTCGI === $settings['cache_method'] ) {
				$settings['enable_purge'] = 1;
				return $settings;
			}
		}

		$redis_constants = array(
			'RT_WP_NGINX_HELPER_REDIS_HOSTNAME'    => 'redis_hostname',
			'RT_WP_NGINX_HELPER_REDIS_PORT'        => 'redis_port',
			'RT_WP_NGINX_HELPER_REDIS_PREFIX'      => 'redis_prefix',
			'RT_WP_NGINX_HELPER_REDIS_UNIX_SOCKET' => 'redis_unix_socket',
		);
		foreach ( $redis_constants as $name => $key ) {
			if ( defined( $name ) ) {
				$settings[ $key ] = (string) constant( $name );
			}
		}

		// The prefix plus a way to reach Redis switches Nginx Helper to Redis
		// mode with its own purging on, whatever the row says. Host and port
		// work in both plugins; the fork also accepts a unix socket instead,
		// which is how GridPane reaches its page-cache Redis.
		$has_prefix = defined( 'RT_WP_NGINX_HELPER_REDIS_PREFIX' );
		$by_tcp     = $has_prefix && defined( 'RT_WP_NGINX_HELPER_REDIS_HOSTNAME' ) && defined( 'RT_WP_NGINX_HELPER_REDIS_PORT' );
		$by_socket  = $has_prefix && '' !== (string) ( defined( 'RT_WP_NGINX_HELPER_REDIS_UNIX_SOCKET' ) ? constant( 'RT_WP_NGINX_HELPER_REDIS_UNIX_SOCKET' ) : '' );
		if ( $by_tcp || $by_socket ) {
			$settings['cache_method'] = self::NH_REDIS;
			$settings['enable_purge'] = 1;
		}

		return $settings;
	}

	/**
	 * Whether Nginx Helper purges changed pages by itself.
	 *
	 * Its `enable_purge` option is the one switch in front of all of its
	 * automatic purging: the post, comment and term hooks each return early
	 * without it. It defaults to off, so an install where the host never
	 * turned it on purges nothing when a post is published. Read from the
	 * resolved settings, because the Redis and cache-method constants turn
	 * it on without touching the row. Not part of the
	 * detection gate above; `Server_Caches` asks it only to decide whether a
	 * content purge can be left to Nginx Helper.
	 */
	public static function nginx_helper_purges_changes(): bool {
		return ! empty( self::nginx_helper_settings()['enable_purge'] );
	}

	/**
	 * The purge method Nginx Helper resolved (`unlink_files`, `get_request`,
	 * …), `get_request` by default. Health uses it to decide whether the permissions caveat
	 * applies; nothing gates on it.
	 */
	public static function nginx_helper_purge_method(): string {
		return (string) self::nginx_helper_settings()['purge_method'];
	}
}
