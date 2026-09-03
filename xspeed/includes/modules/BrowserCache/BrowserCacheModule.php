<?php
/**
 * Browser cache headers module — writes Cache-Control + Expires
 * directives to .htaccess (Apache/LiteSpeed) or surfaces an nginx
 * snippet for manual paste on other servers.
 *
 * Tier: Free per FEATURES.md (LiteSpeed parity — Browser Cache
 * settings are all in LS free).
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\BrowserCache;

defined( 'ABSPATH' ) || exit;

use XSpeed\Browser_Cache;
use XSpeed\Deep_Link;
use XSpeed\Module;
use XSpeed\Server;

final class BrowserCacheModule extends Module {

	public const SLUG    = 'browser-cache';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	public function ui_metadata(): array {
		return array(
			'label'       => 'Browser Cache',
			'icon'        => 'Clock',
			'description' => 'Tell browsers (and intermediate CDNs) how long to cache static assets and HTML.',
		);
	}

	public function settings_schema(): array {
		return array(
			'enabled' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => 'Enable browser cache headers',
				'description' => 'On Apache/LiteSpeed this writes Cache-Control + Expires rules into .htaccess. On nginx it just stores the settings — you paste the snippet into your server block manually.',
			),
			'asset_ttl' => array(
				'type'        => 'int',
				'default'     => Browser_Cache::DEFAULT_ASSET_TTL,
				'min'         => 0,
				'max'         => 31536000,
				'label'       => 'Static asset TTL (seconds)',
				'unit'        => 'seconds',
				'description' => 'Cache lifetime for CSS, JS, fonts, images. Defaults to 1 year + immutable (the industry-standard "fingerprinted assets never change" pattern).',
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'html_ttl' => array(
				'type'        => 'int',
				'default'     => Browser_Cache::DEFAULT_HTML_TTL,
				'min'         => 0,
				'max'         => 31536000,
				'label'       => 'HTML TTL (seconds)',
				'unit'        => 'seconds',
				'description' => 'Cache lifetime for the HTML document itself. Keep short (default 1h) so post edits roll out same-day.',
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
		);
	}

	public function boot(): void {
		add_action( 'update_option_xspeed_module_browser-cache', array( $this, 'on_settings_change' ), 10, 2 );
		add_action( 'add_option_xspeed_module_browser-cache', array( $this, 'on_settings_added' ), 10, 2 );
	}

	public function on_settings_change( $old, $new ): void {
		if ( ! is_array( $new ) ) {
			return;
		}
		Browser_Cache::apply( ! empty( $new['enabled'] ), $new );
		// Settings just changed — TTL values likely differ, so the
		// currently-cached "probe says headers active" answer is stale.
		// Clear the transient so the next dashboard load re-probes.
		delete_transient( 'xspeed_browser_cache_probe' );
	}

	public function on_settings_added( $name, $value ): void {
		if ( ! is_array( $value ) ) {
			return;
		}
		Browser_Cache::apply( ! empty( $value['enabled'] ), $value );
		delete_transient( 'xspeed_browser_cache_probe' );
	}

	public function ui_notices(): array {
		if ( ! class_exists( '\\XSpeed\\Server' ) || Server::supports_htaccess() ) {
			return array();
		}
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) ) {
			return array();
		}

		// Probe the live response for caching headers on a known static
		// asset. Suppress the notice unless the probe PROVES nothing is
		// being sent:
		//
		//   true  → headers present, from our snippet or from the host's own
		//           vhost — either way the feature's job is done (issue #329)
		//   null  → the loopback never completed, so we know nothing; a
		//           broken probe is not evidence of a broken server and must
		//           not raise a warning the operator cannot act on (issue #18)
		//   false → proven absent, fall through and show the notice
		if ( false !== Browser_Cache::probe_headers_present() ) {
			return array();
		}

		// nginx hosts can't auto-write Cache-Control / Expires headers, and the
		// live probe just confirmed they are NOT being served — so the feature
		// reads "enabled" in the dashboard while doing nothing. That is a warning,
		// not a passive info note (a token that a user missed on this exact
		// account, issue #117): escalate the tone and say plainly that the config
		// is configured-but-not-live until the snippet is pasted + nginx reloaded.
		// The directives go into the unified server-block snippet on the Cache
		// panel — point users there instead of duplicating the snippet here.
		return array(
			array(
				'tone'   => 'warn',
				'title'  => __( 'Browser cache is enabled but not active on the server', 'xspeed' ),
				'body'   => __( 'Browser-cache headers need to live in your nginx config, and the live response shows they are not being sent yet — so this is on in settings but doing nothing. Your settings are included in the unified server-block snippet on the Cache panel: paste it once into your nginx vhost (or container nginx config) and reload nginx.', 'xspeed' ),
				// Lands on the snippet itself rather than on the Cache panel,
				// where it is one collapsed section among several (issue #49).
				'action' => Deep_Link::action(
					__( 'Go to the snippet', 'xspeed' ),
					'cache',
					'nginx_snippet'
				),
			),
		);
	}

	public function deactivate(): void {
		Browser_Cache::apply( false );
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed browser-cache',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Print the Apache or nginx browser-cache snippet.',
				'ai_hint'   => 'Get the server config snippet that sets browser cache-control headers for static assets. Use when PageSpeed reports "serve static assets with an efficient cache policy", or when the user needs the rules to paste into Apache/nginx.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'flavor',
						'options'  => array( 'apache', 'nginx' ),
						'optional' => true,
					),
				),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$flavor = $args[0] ?? 'apache';
		$opts   = $this->get_settings();
		if ( 'nginx' === $flavor ) {
			\WP_CLI::log( Browser_Cache::nginx_snippet( $opts ) );
			return;
		}
		foreach ( Browser_Cache::apache_rules( $opts ) as $line ) {
			\WP_CLI::log( $line );
		}
	}

	/**
	 * Cache-Control / Expires directives for the unified nginx
	 * server-block snippet. Null when the module is disabled — no
	 * directives to install.
	 */
	public function nginx_directives(): ?string {
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) ) {
			return null;
		}
		return Browser_Cache::nginx_snippet( $opts );
	}
}
