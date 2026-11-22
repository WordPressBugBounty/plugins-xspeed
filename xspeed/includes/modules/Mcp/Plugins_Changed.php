<?php
/**
 * Tell the Hub, within seconds, that a plugin on this site changed (#952).
 *
 * The Hub reads the full list with `xspeed plugins list`. Until this, a
 * plugin someone activated in wp-admin reached the Hub only on its daily
 * pass. Now each change queues the plugin's slug, and the request's shutdown
 * sends one POST to `/api/site/plugins-changed` with the slugs. The Hub
 * then reads the list again.
 *
 * Each request that changed plugins sends one POST, at shutdown, with that
 * request's slugs. A bulk action in wp-admin or `wp plugin update --all` is
 * one request, so it is one POST. Nothing waits for a later request or for
 * WP-Cron, so a change on an idle site is not held back, and two requests
 * running at once cannot overwrite each other's slugs. Limiting how often
 * a site may ping is the Hub's job. The POST does not wait for the Hub's
 * answer, and nothing is retried: a missed ping is caught by the Hub's
 * daily pass.
 *
 * Only slugs are sent. Never a licence key, never a package URL.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

defined( 'ABSPATH' ) || exit;

final class Plugins_Changed {

	/** Hub route. */
	public const PATH = '/api/site/plugins-changed';

	/**
	 * Left by builds of #952 that held changes back for a 10-second window:
	 * the waiting slugs, the window claim and the cron event that sent what
	 * waited. Nothing writes them now; clean_legacy() removes them.
	 */
	public const LEGACY_PENDING_OPTION = 'xspeed_hub_plugins_changed';
	public const LEGACY_LOCK_OPTION    = 'xspeed_hub_plugins_changed_lock';
	public const LEGACY_CRON_HOOK      = 'xspeed_hub_plugins_changed';

	/**
	 * Most slugs one POST carries. A request that changes more plugins than
	 * this is something like a migration; the Hub reads the whole list on
	 * any ping anyway.
	 */
	public const MAX_SLUGS = 200;

	/**
	 * Seconds the ping may take, by default. The POST is sent with
	 * `blocking` off, but not every transport returns at once, so a slow Hub
	 * could hold the request for the whole timeout (QA round 3 on #956).
	 */
	public const PING_TIMEOUT = 1.0;

	/** Bounds for the `xspeed_hub_ping_timeout` filter, in seconds. */
	public const PING_TIMEOUT_MIN = 0.5;
	public const PING_TIMEOUT_MAX = 3.0;

	/**
	 * Shutdown priority for the ping. Late, so the page and every other
	 * shutdown handler that prints have finished before the response is
	 * closed and the ping goes out.
	 */
	public const SHUTDOWN_PRIORITY = 1000;

	/**
	 * Events fired by the licensing library some plugins bundle. Each passes
	 * that library's manager object last; its `plugin_file` names the plugin.
	 */
	private const LICENSING_EVENTS = array(
		'wpdeveloper_licensing_activated',
		'wpdeveloper_licensing_deactivated',
		'wpdeveloper_licensing_checked',
		'wpdeveloper_licensing_status_checked',
	);

	/** @var array<string,true> Slugs queued in this request. */
	private static array $request = array();

	/** @var bool Whether the shutdown handler is registered for this request. */
	private static bool $shutdown_hooked = false;

	public static function boot(): void {
		add_action( 'activated_plugin', array( self::class, 'on_plugin_file' ), 10, 1 );
		add_action( 'deactivated_plugin', array( self::class, 'on_plugin_file' ), 10, 1 );
		add_action( 'deleted_plugin', array( self::class, 'on_deleted' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( self::class, 'on_upgrader' ), 10, 2 );
		foreach ( self::LICENSING_EVENTS as $event ) {
			add_action( $event, array( self::class, 'on_licensing_event' ), 10, 3 );
		}
		// A site that ran an earlier build may still have this event booked.
		add_action( self::LEGACY_CRON_HOOK, array( self::class, 'clean_legacy' ) );
	}

	/**
	 * activated_plugin / deactivated_plugin.
	 *
	 * @param mixed $plugin_file Plugin file.
	 */
	public static function on_plugin_file( $plugin_file ): void {
		if ( is_string( $plugin_file ) ) {
			self::queue( array( Plugin_Inventory::slug_for( $plugin_file ) ) );
		}
	}

	/**
	 * deleted_plugin. Core fires it after a failed delete too.
	 *
	 * @param mixed $plugin_file Plugin file.
	 * @param mixed $deleted     Whether the files were removed.
	 */
	public static function on_deleted( $plugin_file, $deleted = true ): void {
		if ( $deleted ) {
			self::on_plugin_file( $plugin_file );
		}
	}

	/**
	 * upgrader_process_complete, for plugin installs and updates.
	 *
	 * An update names its files in `plugin` or `plugins`. An install names
	 * nothing there, so the slug comes from the folder the upgrader wrote.
	 *
	 * @param mixed $upgrader   The upgrader instance.
	 * @param mixed $hook_extra Core's `type`, `action`, `plugin(s)` details.
	 */
	public static function on_upgrader( $upgrader, $hook_extra = array() ): void {
		if ( ! is_array( $hook_extra ) || 'plugin' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}
		$files = array();
		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$files = $hook_extra['plugins'];
		} elseif ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$files = array( $hook_extra['plugin'] );
		}

		$slugs = array();
		foreach ( $files as $file ) {
			if ( is_string( $file ) ) {
				$slugs[] = Plugin_Inventory::slug_for( $file );
			}
		}
		if ( array() === $slugs && is_object( $upgrader ) && isset( $upgrader->result ) && is_array( $upgrader->result ) ) {
			$folder = $upgrader->result['destination_name'] ?? '';
			if ( is_string( $folder ) && '' !== $folder ) {
				$slugs[] = sanitize_key( $folder );
			}
		}
		self::queue( $slugs );
	}

	/**
	 * A licensing library event. Only the manager's plugin file is read;
	 * the response and the key passed beside it are not touched.
	 *
	 * @param mixed ...$args Event arguments; the manager comes last.
	 */
	public static function on_licensing_event( ...$args ): void {
		for ( $i = count( $args ) - 1; $i >= 0; $i-- ) {
			$manager = $args[ $i ];
			if ( is_object( $manager ) && method_exists( $manager, 'get_args' ) ) {
				$file = $manager->get_args( 'plugin_file' );
				if ( is_string( $file ) && '' !== $file ) {
					self::on_plugin_file( self::relative_plugin_file( $file ) );
				}
				return;
			}
		}
	}

	/**
	 * Add slugs to this request's batch and make sure shutdown sends it.
	 *
	 * @param array<int,string> $slugs Plugin slugs.
	 */
	public static function queue( array $slugs ): void {
		if ( ! self::can_send() ) {
			return;
		}
		foreach ( $slugs as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' !== $slug ) {
				self::$request[ $slug ] = true;
			}
		}
		if ( array() !== self::$request && ! self::$shutdown_hooked ) {
			self::$shutdown_hooked = true;
			add_action( 'shutdown', array( self::class, 'flush' ), self::SHUTDOWN_PRIORITY );
		}
	}

	/**
	 * Send this request's slugs to the Hub.
	 *
	 * Runs on shutdown, once, in a request that changed plugins.
	 *
	 * @return bool True when a POST went out.
	 */
	public static function flush(): bool {
		$slugs         = array_keys( self::$request );
		self::$request = array();
		self::clean_legacy();

		if ( array() === $slugs || ! self::can_send() ) {
			return false;
		}
		// The page is done by now. Close the response first, so the person
		// who clicked Activate does not wait for the Hub.
		self::finish_response();
		Mcp_Hub::post_plugins_changed( array_slice( $slugs, 0, self::MAX_SLUGS ) );
		return true;
	}

	/**
	 * The ping's timeout in seconds: PING_TIMEOUT, or what the
	 * `xspeed_hub_ping_timeout` filter returns, kept between 0.5 and 3.
	 */
	public static function ping_timeout(): float {
		$timeout = apply_filters( 'xspeed_hub_ping_timeout', self::PING_TIMEOUT );
		$timeout = is_numeric( $timeout ) ? (float) $timeout : self::PING_TIMEOUT;
		return max( self::PING_TIMEOUT_MIN, min( self::PING_TIMEOUT_MAX, $timeout ) );
	}

	/**
	 * Send the response to the browser now and keep running, where the
	 * server allows it: PHP-FPM and LiteSpeed's PHP. Elsewhere, and under
	 * WP-CLI where there is no browser waiting, nothing happens and the
	 * short timeout is the only bound. Both functions may be disabled by a
	 * host, so each is checked before the call.
	 *
	 * @return string The function used, or '' when none was.
	 */
	private static function finish_response(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return '';
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
			return 'fastcgi_finish_request';
		}
		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
			return 'litespeed_finish_request';
		}
		return '';
	}

	/**
	 * The library stores the plugin's full path. A path outside the plugins
	 * folder gives no slug, rather than the name of a server folder.
	 *
	 * @param string $file Plugin file, full or relative.
	 */
	private static function relative_plugin_file( string $file ): string {
		$normal = str_replace( '\\', '/', $file );
		$is_abs = 0 === strpos( $normal, '/' ) || 1 === preg_match( '#^[A-Za-z]:/#', $normal );
		if ( ! $is_abs ) {
			return $file;
		}
		$base = function_exists( 'plugin_basename' ) ? (string) plugin_basename( $file ) : '';
		return ( '' === $base || trim( $normal, '/' ) === $base ) ? '' : $base;
	}

	/**
	 * Drop this request's batch. Called when the site stops being attached
	 * or loses its token, so a change made before that is not sent.
	 */
	public static function forget(): void {
		self::$request = array();
	}

	/**
	 * Remove what earlier builds of #952 left behind. Safe to run any
	 * number of times; it runs from flush(), from the old cron event if one
	 * is still booked, and on activation.
	 */
	public static function clean_legacy(): void {
		delete_option( self::LEGACY_PENDING_OPTION );
		delete_option( self::LEGACY_LOCK_OPTION );
		wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
	}

	/** Forget this request's batch. For tests. */
	public static function reset(): void {
		self::$request         = array();
		self::$shutdown_hooked = false;
	}

	/** Only a site with a token that an administrator attached to the Hub. */
	private static function can_send(): bool {
		return '' !== Mcp_Pairing::site_token() && Mcp_Hub::site_attached();
	}
}
