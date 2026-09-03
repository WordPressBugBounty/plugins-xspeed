<?php
/**
 * GZIP module.
 *
 * Owns the gzip_enabled setting. On flip → writes / removes Apache /
 * LiteSpeed `.htaccess` rules via the static XSpeed\Gzip helper. On
 * nginx (or any server xSpeed can't auto-configure), the panel shows
 * the manual snippet via ui_notices().
 *
 * Tier: Free. See SETTINGS.md for the contract this module satisfies.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Gzip;

defined( 'ABSPATH' ) || exit;

use XSpeed\Deep_Link;
use XSpeed\Gzip as LegacyGzip;
use XSpeed\Module;
use XSpeed\Server;
use XSpeed\Settings_Manager;

final class GzipModule extends Module {

	public const SLUG    = 'gzip';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.1.0';

	public function ui_metadata(): array {
		return array(
			'label'        => 'Compression',
			'tab_label'    => 'GZIP', // its own tab on the Compression page
			'icon'         => 'Layers',
			'description'  => 'Compress responses to reduce transfer size.',
			// Host panel merges GZIP (this module) + Brotli (Pro) into one
			// page — they are one decision with a fallback chain, not two
			// sidebar rows (FBS-83633). The panel renders this module's own
			// schema form plus a Brotli Pro section.
			'custom_panel' => 'CompressionPanel',
		);
	}

	public function settings_schema(): array {
		return array(
			'gzip_enabled' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => 'Enable GZIP Compression',
				// Server-conditional. The old copy said "On nginx the snippet
				// below must be added to your server config" — unconditionally,
				// and there is no snippet below: the Compression page is a tab
				// strip plus this toggle, and NginxServerBlock only mounts under
				// the Cache panel. So Apache/LiteSpeed users read dead text
				// about a server they aren't on, and nginx users got a promise
				// the page couldn't keep. The nginx half now lives in
				// ui_notices(), which can link to where the snippet actually is.
				'description' => self::gzip_description(),
			),
		);
	}

	/**
	 * Copy for the GZIP toggle, matched to the server we're actually on.
	 *
	 * Schema descriptions have no server-conditional rendering, so the choice
	 * has to happen here rather than in the panel.
	 */
	private static function gzip_description(): string {
		if ( class_exists( '\\XSpeed\\Server' ) && Server::supports_htaccess() ) {
			return __( 'Compress responses before sending them. We write the .htaccess rules automatically on this server.', 'xspeed' );
		}
		if ( class_exists( '\\XSpeed\\Server' ) && Server::NGINX === Server::type() ) {
			return __( 'Compress responses before sending them. nginx cannot be configured from WordPress, so the directives ship in the unified server-block snippet — see the notice below.', 'xspeed' );
		}
		return __( 'Compress responses before sending them. On this server the directives have to be added to your server config by hand — see the notice below.', 'xspeed' );
	}

	/**
	 * 1.1.0: drain gzip_enabled from the legacy xspeed_options blob into
	 * this module's option. Idempotent — a re-run with the legacy key
	 * already gone is a no-op.
	 */
	public function migrations(): array {
		return array(
			'1.1.0' => static function ( array $opts ): array {
				$legacy = get_option( 'xspeed_options', array() );
				if ( ! is_array( $legacy ) || ! array_key_exists( 'gzip_enabled', $legacy ) ) {
					return $opts;
				}
				$opts['gzip_enabled'] = (bool) $legacy['gzip_enabled'];
				unset( $legacy['gzip_enabled'] );
				update_option( 'xspeed_options', $legacy );
				return $opts;
			},
		);
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed gzip',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Show GZIP status (server type, active, mode).',
				'ai_hint'   => 'Is text compression (GZIP/Brotli) actually working on this site? Answers "why are my HTML/CSS/JS transfers so large" and whether the server is compressing at all. Reports the detected server, whether compression is active, and how it is applied.',
				'synopsis'  => array(),
			),
		);
	}

	/**
	 * Conditional notice published to the module's panel via
	 * Module::ui_notices(). When gzip is enabled and the server can't
	 * auto-configure (nginx / unknown / IIS), we surface a copy-able
	 * snippet so the user can wire it up.
	 */
	public function ui_notices(): array {
		$opts = Settings_Manager::get( self::SLUG );
		if ( empty( $opts['gzip_enabled'] ) ) {
			return array();
		}

		// Probe the live response — works regardless of server type. If gzip
		// is actually being served, nothing is wrong. Mirrors BrowserCache's
		// probe_headers_present() pattern.
		//
		// Tri-state: true = proven serving, false = proven not serving,
		// null = the loopback never reached the origin, which is not evidence
		// of anything. Telling someone their server is misconfigured because
		// *we* couldn't call it is the bug behind issue #18. (#18)
		$serving = LegacyGzip::probe_active();

		// nginx gets a notice EITHER WAY, because on nginx this notice is the
		// only route from the Compression page to the snippet — the panel body
		// is a tab strip plus one toggle, and NginxServerBlock mounts under
		// the Cache panel alone. Suppressing it on a correctly-configured box
		// left that user with no way to reach the directives at all (say, to
		// re-paste them after an nginx upgrade). Working sites get a calm
		// "here's where it lives"; broken ones get the warning. (#87)
		//
		// An unreachable probe (null) takes the calm wording too: we cannot
		// prove gzip is missing, so the notice points at the snippet without
		// claiming the server is misconfigured. Only a proven-false probe
		// warns. (#18)
		if ( Server::NGINX === Server::type() ) {
			$proven_missing = ( false === $serving );
			return array(
				array(
					'tone'   => $proven_missing ? 'warn' : 'info',
					'title'  => $proven_missing
						? __( 'GZIP requires server config on nginx', 'xspeed' )
						: __( 'GZIP is being served by nginx', 'xspeed' ),
					'body'   => $proven_missing
						? __( 'xSpeed can only auto-configure GZIP on Apache and LiteSpeed (via .htaccess). The directives are included in the unified server-block snippet on the Cache panel — copy + paste it once into your nginx vhost (or container nginx config) and reload nginx.', 'xspeed' )
						: __( 'Responses are compressed. xSpeed cannot configure nginx from WordPress, so these directives live in the unified server-block snippet on the Cache panel — that is where to re-copy them if your server config is ever rebuilt.', 'xspeed' ),
					// Lands on the snippet itself and auto-expands it, rather
					// than on the Cache page where it is one collapsed section
					// among several. Same call BrowserCacheModule makes.
					'action' => Deep_Link::action(
						__( 'Go to the snippet', 'xspeed' ),
						'cache',
						'nginx_snippet'
					),
				),
			);
		}

		// Non-nginx: stay quiet unless the probe positively proved gzip is
		// missing. `null` (unreachable) must not produce a notice. (#18)
		if ( false !== $serving ) {
			return array();
		}
		// Probe says NOT active and gzip is enabled — surface the right
		// notice per server topology.
		if ( 'auto' === Server::gzip_mode() ) {
			// Apache/LiteSpeed but probe failed — .htaccess write must
			// have been blocked, or another plugin is overriding. Tell
			// the user something is wrong, no snippet (we can't fix it
			// without their server access).
			return array(
				array(
					'tone'  => 'warn',
					'title' => __( 'GZIP enabled but not active on the server', 'xspeed' ),
					'body'  => __( 'xSpeed wrote the .htaccess rules but the response still isn\'t gzipped. Your server may have AllowOverride disabled, another caching plugin overriding, or mod_deflate missing. Ask your host to enable GZIP on Apache.', 'xspeed' ),
				),
			);
		}
		// IIS / unknown server fallback — keep the legacy "paste this"
		// notice until a non-nginx unified-snippet surface ships. (nginx
		// returned above, whether or not gzip is currently being served.)
		return array(
			array(
				'tone'    => 'warn',
				'title'   => __( 'GZIP requires server config on this server', 'xspeed' ),
				'body'    => __( 'xSpeed can only auto-configure GZIP on Apache and LiteSpeed (via .htaccess). Paste the snippet below into your server config and reload.', 'xspeed' ),
				'snippet' => LegacyGzip::nginx_snippet(),
			),
		);
	}

	/**
	 * Module booting: seed per-module option from legacy if needed,
	 * then register the change hook that flips Apache / LiteSpeed
	 * .htaccess rules whenever gzip_enabled is toggled. This handler
	 * fires on writes to xspeed_module_gzip (not the legacy blob).
	 */
	public function boot(): void {
		$this->seed_from_legacy_if_needed();
		add_action( 'update_option_xspeed_module_gzip', array( __CLASS__, 'on_settings_change' ), 10, 2 );
		add_action( 'add_option_xspeed_module_gzip', array( __CLASS__, 'on_settings_added' ), 10, 2 );
	}

	/**
	 * Detect the gzip_enabled flip on write and apply / remove the
	 * .htaccess rules. Idempotent — re-running with the same state is a
	 * no-op inside LegacyGzip::apply().
	 */
	public static function on_settings_change( $old, $new ): void {
		$old_gzip = is_array( $old ) ? ! empty( $old['gzip_enabled'] ) : false;
		$new_gzip = is_array( $new ) ? ! empty( $new['gzip_enabled'] ) : false;
		if ( $old_gzip !== $new_gzip ) {
			LegacyGzip::apply( $new_gzip );
			// Settings just changed — current "probe says active" answer
			// is stale. Drop the transient so the next dashboard load
			// re-checks the live response.
			delete_transient( 'xspeed_gzip_active' );
		}
	}

	/**
	 * The very first write (option doesn't exist yet) hits add_option
	 * instead of update_option. Treat it as old=false → new=current.
	 */
	public static function on_settings_added( $name, $value ): void {
		$enabled = is_array( $value ) ? ! empty( $value['gzip_enabled'] ) : false;
		if ( $enabled ) {
			LegacyGzip::apply( true );
		}
	}

	public function activate(): void {
		$this->seed_from_legacy_if_needed();
	}

	private function seed_from_legacy_if_needed(): void {
		if ( null !== get_option( 'xspeed_module_gzip', null ) ) {
			return;
		}
		$legacy = get_option( 'xspeed_options', array() );
		if ( ! is_array( $legacy ) || ! array_key_exists( 'gzip_enabled', $legacy ) ) {
			return;
		}
		update_option(
			'xspeed_module_gzip',
			array(
				'_version'     => self::VERSION,
				'gzip_enabled' => (bool) $legacy['gzip_enabled'],
			)
		);
		unset( $legacy['gzip_enabled'] );
		update_option( 'xspeed_options', $legacy );
	}

	public function cli_handler( array $args, array $assoc ): void {
		$opts = Settings_Manager::get( self::SLUG );
		\WP_CLI::log( 'enabled       ' . ( $opts['gzip_enabled'] ? 'true' : 'false' ) );
		\WP_CLI::log( 'server        ' . Server::type() );
		\WP_CLI::log( 'mode          ' . Server::gzip_mode() );
		$active = LegacyGzip::probe_active();
		\WP_CLI::log( 'active        ' . ( null === $active ? 'unknown (loopback probe failed)' : ( $active ? 'true' : 'false' ) ) );
		\WP_CLI::log( 'nginx_snippet ' . LegacyGzip::nginx_snippet() );
	}

	/**
	 * GZIP directives for the unified nginx server-block snippet. Null
	 * when the module is disabled.
	 */
	public function nginx_directives(): ?string {
		$opts = Settings_Manager::get( self::SLUG );
		if ( empty( $opts['gzip_enabled'] ) ) {
			return null;
		}
		$snippet = LegacyGzip::nginx_snippet();
		return is_string( $snippet ) && '' !== $snippet ? $snippet : null;
	}
}
