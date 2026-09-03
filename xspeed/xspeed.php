<?php
/**
 * Plugin Name: xSpeed Cache
 * Description: Minimal, ultra-fast caching plugin for WordPress.
 * Version: 1.2.3
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Author: WPDeveloper
 * Author URI: https://wpdeveloper.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: xspeed
 *
 * @package XSpeed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'XSPEED_VERSION', '1.2.3' );
define( 'XSPEED_FILE', __FILE__ );
define( 'XSPEED_DIR', plugin_dir_path( __FILE__ ) );
define( 'XSPEED_URL', plugin_dir_url( __FILE__ ) );
define( 'XSPEED_CACHE_DIR', WP_CONTENT_DIR . '/cache/xspeed' );

/**
 * Static-cache tree. Files written here are served directly by the
 * web server via the rewrite block in .htaccess, bypassing PHP for
 * every cache hit. Layout: `xspeed-static/{host}{request_uri}/index.html`.
 * Separate from XSPEED_CACHE_DIR so the PHP drop-in's flat-hash cache
 * stays intact as a fallback for cookied / query-string / nginx hosts.
 */
define( 'XSPEED_CACHE_STATIC_DIR', WP_CONTENT_DIR . '/cache/xspeed-static' );

/**
 * Free-API version. xspeed-pro reads this to decide if it speaks the
 * current Module / Module_Registry / Settings_Manager / Rest_Manager
 * contract. Bump on any breaking change to those interfaces — never on
 * additive change. See IMPLEMENTATION.md §1 and class-tier-registry.php.
 */
define( 'XSPEED_API_VERSION', 1 );

/**
 * WP Insights project token for opt-in usage analytics (Usage_Tracker). Routes
 * this plugin's anonymous diagnostics to its project on send.wpinsight.com.
 * Overridable via wp-config.php for staging/QA. Nothing is ever sent unless the
 * admin explicitly opts in during the setup wizard — see class-usage-tracker.php.
 *
 * The WP Insights item_id for xSpeed — the project hash send.wpinsight.com
 * keys this plugin's telemetry on. The tracker only sends after explicit
 * user opt-in (require_optin is forced true); this just identifies the
 * project on the initial registration handshake.
 */
if ( ! defined( 'XSPEED_INSIGHTS_ITEM_ID' ) ) {
	define( 'XSPEED_INSIGHTS_ITEM_ID', 'd2268aeacaa69d9f6d2f' );
}

/**
 * Report an autoload path that is not on disk.
 *
 * PHP's own message names the CLASS and not the PATH, so a missing include
 * reads as a code bug in a file that shipped intact — which is exactly how
 * `Class "XSpeed\Migration" not found` was reported against 1.1.8 while the
 * published zip was byte-identical to the tag. Name the file instead.
 *
 * Logged without a WP_DEBUG gate, because the sites this has to reach are the
 * production ones that run with it off. Deduped per class per request: a miss
 * usually precedes a fatal, but not always — `class_exists()` on a missing
 * class is a graceful probe that returns false and carries on, and repeating
 * the line for it would fill the log rather than explain anything.
 *
 * @param string $class Class that could not be autoloaded.
 * @param string $path  Path the autoloader resolved it to.
 */
/**
 * Delete everything inside a cache tree, without loading a single class.
 *
 * Only ever called with XSPEED_CACHE_DIR / XSPEED_CACHE_STATIC_DIR, and it
 * refuses anything that is not a real directory under WP_CONTENT_DIR, so a
 * mangled constant cannot turn this into a recursive delete somewhere else.
 * Symlinks are unlinked, never followed.
 *
 * Leaves the root directory itself in place — same contract as
 * Cache::purge_all(), which this stands in for when the classes are gone.
 *
 * @param string $root Absolute path to a cache tree.
 */
if ( ! function_exists( 'xspeed_empty_cache_tree' ) ) {
	function xspeed_empty_cache_tree( $root ) {
		$real    = realpath( $root );
		$content = realpath( WP_CONTENT_DIR );
		if ( false === $real || false === $content || ! is_dir( $real ) ) {
			return;
		}
		// Must live under wp-content, and must not BE wp-content.
		if ( $real === $content || 0 !== strpos( $real, $content . DIRECTORY_SEPARATOR ) ) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isLink() || ! $item->isDir() ) {
				@unlink( $item->getPathname() );
				continue;
			}
			@rmdir( $item->getPathname() );
		}
	}
}

if ( ! function_exists( 'xspeed_log_autoload_miss' ) ) {
	function xspeed_log_autoload_miss( $class, $path ) {
		static $seen = array();
		if ( isset( $seen[ $class ] ) ) {
			return;
		}
		$seen[ $class ] = true;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Names the missing file behind an unresolvable class.
		error_log( sprintf( '[xspeed] cannot autoload %1$s: %2$s is missing from disk', $class, $path ) );
	}
}

spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'XSpeed\\' ) !== 0 ) {
			return;
		}
		// substr, not str_replace — str_replace strips EVERY occurrence of
		// the prefix, so a class whose sub-namespace repeats it resolves to
		// the wrong path instead of missing outright.
		$relative = substr( $class, strlen( 'XSpeed\\' ) );

		/*
		 * Module classes are composer PSR-4 (XSpeed\Modules\Cache\CacheModule
		 * → includes/modules/Cache/CacheModule.php), so they resolve against
		 * that layout rather than the class-<kebab>.php one below.
		 *
		 * Reaching this branch at all means composer already failed: it
		 * registers with `$loader->register( true )`, which PREPENDS, so it
		 * gets every class before this autoloader does. A miss here is
		 * therefore just as genuine as an engine miss, and just as worth
		 * naming — a quarantined includes/modules/Cache/CacheModule.php
		 * otherwise reproduces the original incident exactly, with a bare
		 * class name and no path.
		 */
		if ( strpos( $relative, 'Modules\\' ) === 0 ) {
			$module_file = XSPEED_DIR . 'includes/modules/'
				. str_replace( '\\', '/', substr( $relative, strlen( 'Modules\\' ) ) ) . '.php';
			if ( ! file_exists( $module_file ) ) {
				xspeed_log_autoload_miss( $class, $module_file );
			}
			return;
		}

		$file = XSPEED_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
			return;
		}

		// The class is one of ours and its file is gone from disk — a
		// half-applied update, a stale opcache or realpath cache, a security
		// plugin quarantining the file.
		xspeed_log_autoload_miss( $class, $file );
	}
);

if ( ! file_exists( XSPEED_DIR . 'vendor/autoload.php' ) ) {
	/*
	 * Composer's autoloader is what resolves every XSpeed\Modules\* class.
	 * Without it there is no version of this plugin that works: booting on
	 * would register hooks and then fatal on the first module touched, from
	 * whichever request got there first.
	 *
	 * So stop here. Log the path, tell an admin who can act on it, and
	 * return without registering anything. The plugin stays "active" and
	 * does nothing, which is recoverable by reinstalling; a fatal on a
	 * front-end request is not.
	 */
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Names the missing file that makes the plugin unloadable.
	error_log( '[xspeed] vendor/autoload.php is missing from disk; xSpeed cannot load and has stopped before registering hooks' );

	/*
	 * Deactivation still has to empty the caches, even in this state.
	 *
	 * Normally Plugin::deactivate() runs Cache::purge_all(). Bailing here
	 * skips that registration, and the consequence is not "no cleanup" — it
	 * is stale pages that never expire. The .htaccess block rewrites straight
	 * to xspeed-static/{host}{path}/index.html on a bare file-exists test,
	 * with no TTL available at the rewrite layer, so a deactivated plugin
	 * would keep serving frozen HTML indefinitely. Deactivating is the first
	 * thing an admin does when a plugin complains, so it has to work.
	 *
	 * Raw PHP on purpose: no class here is loadable, which is the whole
	 * reason we are in this branch.
	 */
	register_deactivation_hook(
		__FILE__,
		static function () {
			foreach ( array( XSPEED_CACHE_DIR, XSPEED_CACHE_STATIC_DIR ) as $root ) {
				xspeed_empty_cache_tree( $root );
			}
		}
	);

	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p><strong>xSpeed</strong> — '
				. esc_html__( 'is missing files and could not start, so no new pages are being cached. Pages already in the cache are still being served, and while the plugin is in this state nothing clears them automatically. Deactivating xSpeed empties the cache; reinstalling the plugin restores it. Deactivating alone will not repair the installation.', 'xspeed' )
				. '</p></div>';
		}
	);
	return;
}

require_once XSPEED_DIR . 'vendor/autoload.php';
require_once XSPEED_DIR . 'includes/wp-cache-constant.php';

register_activation_hook( __FILE__, array( 'XSpeed\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'XSpeed\\Plugin', 'deactivate' ) );

/**
 * Core classes that boot touches unconditionally. If any is missing the
 * plugin directory is incomplete, and booting would fatal — on the front end
 * too, since Plugin::init() runs on `plugins_loaded` for every request.
 *
 * Guarding each call site individually does not scale and misses the next
 * one, so the integrity check lives here: refuse to boot, say why, and leave
 * WordPress usable so the site owner can actually fix it. A cached page can
 * mask this on the homepage while every uncached request 500s, which is
 * exactly the failure that is hardest to diagnose from the outside.
 *
 * @return string[] Class names that could not be loaded.
 */
function xspeed_missing_core_classes() {
	$missing = array();
	foreach ( array( 'Plugin', 'Cache', 'Cache_GC', 'Settings', 'Settings_Manager', 'Module_Registry' ) as $name ) {
		if ( ! class_exists( '\\XSpeed\\' . $name ) ) {
			$missing[] = 'XSpeed\\' . $name;
		}
	}

	/*
	 * Module classes matter just as much: register_free_modules() does an
	 * unconditional `new` on every one, from this same `plugins_loaded`
	 * hook, so a single missing file under includes/modules/ fatals every
	 * request exactly like a missing engine class does.
	 *
	 * Read the list out of class-plugin.php's own `new \XSpeed\Modules\…()`
	 * calls rather than hand-keeping it here, so a module added or removed
	 * there cannot leave this check silently out of date.
	 */
	$plugin_file = XSPEED_DIR . 'includes/class-plugin.php';
	$source      = is_readable( $plugin_file ) ? @file_get_contents( $plugin_file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Runs before WP_Filesystem exists, and reads a plugin file by absolute path.
	if ( false !== $source && preg_match_all( '/new\s+(\\\\XSpeed\\\\Modules\\\\[A-Za-z0-9_\\\\]+)\s*\(/', $source, $m ) ) {
		foreach ( array_unique( $m[1] ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = ltrim( $class, '\\' );
			}
		}
	}

	return $missing;
}

add_action(
	'plugins_loaded',
	function () {
		$missing = xspeed_missing_core_classes();
		if ( ! empty( $missing ) ) {
			add_action(
				'admin_notices',
				function () use ( $missing ) {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-error"><p><strong>' .
						esc_html__( 'xSpeed Cache could not start.', 'xspeed' ) . '</strong> ' .
						esc_html__( 'Some of its files are missing, so it stopped rather than take the site down with it. No new pages are being cached, but pages already in the cache are still being served and nothing clears them while the plugin is in this state. Re-install or re-upload the plugin to restore it, then purge the cache.', 'xspeed' ) .
						'</p><p><code>' . esc_html( implode( ', ', $missing ) ) . '</code></p></div>';
				}
			);
			return;
		}
		\XSpeed\Plugin::instance()->init();
	}
);
