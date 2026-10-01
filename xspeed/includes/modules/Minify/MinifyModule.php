<?php
/**
 * Minify module.
 *
 * Owns the minify_html / minify_css / minify_js settings. Engine work
 * still happens in XSpeed\Minifier (filters style_loader_src and
 * script_loader_src), but the Module is now the storage and editing
 * authority. Settings live in xspeed_module_minify; the legacy
 * xspeed_options blob is drained on first boot and on POST.
 *
 * Tier: Free. See SETTINGS.md for the contract this module satisfies.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Minify;

defined( 'ABSPATH' ) || exit;

use XSpeed\Minifier as LegacyMinifier;
use XSpeed\Minify_Filters;
use XSpeed\Module;
use XSpeed\Settings_Manager;

final class MinifyModule extends Module {

	public const SLUG    = 'minify';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.2.0';

	public function ui_metadata(): array {
		return array(
			'label'        => __( 'CSS & JavaScript', 'xspeed' ),
			'tab_label'    => __( 'Minify', 'xspeed' ), // its own tab on the CSS & JavaScript page
			'icon'         => 'Wand2',
			'description'  => __( 'Makes HTML, CSS and JavaScript files smaller and loads scripts later.', 'xspeed' ),
			'group'        => 'performance',
			// Host page: Minify (this module) / Critical CSS (Pro) / Unused
			// CSS (Pro) as tabs — the three CSS/JS optimizations live on one
			// page instead of three sidebar rows (FBS-83633).
			'custom_panel' => 'CssJsPanel',
		);
	}

	public function settings_schema(): array {
		return array(
			'minify_html' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Minify HTML', 'xspeed' ),
				// Names the logged-out caveat up front: minification runs on the
				// request that WRITES a cache entry, and should_cache() refuses
				// logged-in requests — so "view source while logged in" shows
				// un-minified HTML and reads as the feature being broken. (#2)
				'description' => __( 'Removes spaces and comments from your pages. Safe on most themes. Only logged-out visitors see it, so check in a private window.', 'xspeed' ),
			),
			'minify_css'  => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Minify CSS', 'xspeed' ),
				'description' => __( 'Makes your site\'s own CSS files smaller. CSS from other domains is left alone.', 'xspeed' ),
			),
			'minify_js'   => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Minify JavaScript', 'xspeed' ),
				'description' => __( 'Makes your site\'s own JavaScript files smaller. Turn off if a script on your site stops working.', 'xspeed' ),
			),
			'defer_js' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Defer JavaScript', 'xspeed' ),
				'description' => __( 'Runs scripts after the page has loaded, so content shows sooner. jQuery and scripts that need it are skipped.', 'xspeed' ),
			),
			'delay_js' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Delay JavaScript', 'xspeed' ),
				'description' => __( 'Scripts load only when the visitor scrolls, taps or types. Pages show much sooner, but test menus and sliders. Cookie consent banners still load first.', 'xspeed' ),
			),
			'async_css' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Load CSS without blocking', 'xspeed' ),
				'description' => __( 'On pages that have critical CSS, the page shows before the rest of its CSS finishes loading. Pages without critical CSS keep loading their CSS normally, because deferring it makes the page show unstyled and then jump.', 'xspeed' ),
			),
			'remove_query_strings' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Remove version from file URLs', 'xspeed' ),
				'description' => __( 'Removes ?ver= from CSS and JS links, which some CDNs cache better. After a plugin update, returning visitors may keep old files until their browser cache expires.', 'xspeed' ),
				'advanced'    => true,
			),
			'defer_js_excluded' => array(
				'type'        => 'list',
				'default'     => array( 'jquery-core', 'jquery-migrate' ),
				'item_type'   => 'string',
				'label'       => __( 'Scripts to never defer or delay', 'xspeed' ),
				'description' => __( 'Script handles or parts of script URLs, one per line. jQuery is listed because most themes need it early; cookie consent banners are skipped on their own.', 'xspeed' ),
				// Only relevant once defer OR delay is on — the exclusion list
				// governs both. Uses the `any` (OR) dependency form. (FBS-82227)
				'dependsOn'   => array(
					'any' => array(
						array( 'field' => 'defer_js' ),
						array( 'field' => 'delay_js' ),
					),
				),
			),
			'delay_js_targets' => array(
				'type'        => 'list',
				'default'     => array(),
				'item_type'   => 'string',
				'label'       => __( 'Delay only these scripts', 'xspeed' ),
				'description' => __( 'Script handles or parts of script URLs, one per line. Leave empty to delay all scripts; otherwise only these, plus known trackers and chat widgets, are delayed.', 'xspeed' ),
				'info_title'  => __( 'Consent banners and Delay JS', 'xspeed' ),
				'info'        => sprintf(
					/* translators: %s: comma-separated list of consent plugins, each followed by the word to type in parentheses. */
					__( 'These consent banners load straight away, even with Delay JS on: %s. To delay one on purpose, add the word in parentheses, or the script handle, to this list. An entry that only matches part of the address, such as /plugins/ or .js, never delays a banner, and an entry in Scripts to never defer or delay always wins.', 'xspeed' ),
					implode( ', ', Minify_Filters::consent_manager_labels() )
				),
				'dependsOn'   => array( 'field' => 'delay_js' ),
			),
			'delay_js_smart' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Smart delay', 'xspeed' ),
				'description' => __( 'Also delays scripts that code on the page depends on, which helps page builder sites most. The riskiest setting here, so test menus and sliders.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'delay_js' ),
			),
			'delay_js_timeout' => array(
				'type'        => 'int',
				'default'     => 8000,
				'min'         => 0,
				'max'         => 60000,
				'label'       => __( 'Delay timeout (ms)', 'xspeed' ),
				'unit'        => 'ms',
				'description' => __( 'Load delayed scripts after this long if the visitor does nothing. Set 0 to wait for a scroll, tap or key press only.', 'xspeed' ),
				'advanced'    => true,
				'dependsOn'   => array( 'field' => 'delay_js' ),
			),
			'combine_css' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Combine CSS files', 'xspeed' ),
				'description' => __( 'Joins your site\'s own CSS files into one. This helps only on old HTTP/1.1 servers, so leave it off on most hosts.', 'xspeed' ),
				'advanced'    => true,
			),
			'combine_js' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Combine JavaScript files', 'xspeed' ),
				'description' => __( 'Joins your site\'s own scripts into one file. Turn off if a script stops working after you enable it.', 'xspeed' ),
				'advanced'    => true,
			),
		);
	}

	/**
	 * 1.1.0: drain minify_html / minify_css / minify_js from the legacy
	 * `xspeed_options` blob into this module's per-module option, then
	 * delete the keys from the legacy blob so duplicate sources can't
	 * re-appear. Idempotent — re-running is a no-op once the keys are
	 * gone from xspeed_options.
	 */
	public function migrations(): array {
		return array(
			'1.1.0' => static function ( array $opts ): array {
				$legacy = get_option( 'xspeed_options', array() );
				if ( ! is_array( $legacy ) ) {
					return $opts;
				}
				$dirty = false;
				foreach ( array( 'minify_html', 'minify_css', 'minify_js' ) as $key ) {
					if ( array_key_exists( $key, $legacy ) ) {
						$opts[ $key ] = (bool) $legacy[ $key ];
						unset( $legacy[ $key ] );
						$dirty       = true;
					}
				}
				if ( $dirty ) {
					update_option( 'xspeed_options', $legacy );
				}
				return $opts;
			},
		);
	}

	/**
	 * Conflict declarations. Detected automatically by Conflict_Registry,
	 * but listing them here keeps the module self-documenting.
	 */
	public function conflicts(): array {
		return array(
			array(
				'plugin'   => 'autoptimize/autoptimize.php',
				'feature'  => 'minify.html',
				'strategy' => \XSpeed\Conflict_Registry::STRATEGY_REFUSE,
				'reason'   => 'Autoptimize is active and handles minification.',
			),
			array(
				'plugin'   => 'wp-rocket/wp-rocket.php',
				'feature'  => 'minify.html',
				'strategy' => \XSpeed\Conflict_Registry::STRATEGY_REFUSE,
				'reason'   => 'WP Rocket already handles minification.',
			),
		);
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed minify',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Inspect or purge xSpeed minify cache.',
				'ai_hint'   => 'Which CSS/JS optimizations are active (minify, combine, defer, delay, async)? Use for questions about render-blocking resources, unminified assets in PageSpeed, or when JavaScript broke after enabling optimizations.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'status', 'purge' ),
						'optional' => false,
					),
				),
			),
		);
	}

	/**
	 * On boot:
	 *   1. Seed our per-module option from the legacy blob if neither
	 *      our option nor the migration has run yet (covers the
	 *      already-installed-before-this-module-shipped path).
	 *   2. Instantiate the v1 Minifier engine; it now reads from
	 *      Settings_Manager::get('minify') via its updated read path.
	 */
	public function boot(): void {
		/*
		 * Deferred to `init`: both calls below read this module's settings,
		 * which builds settings_schema(), whose labels go through __().
		 * boot() runs on `plugins_loaded`, before `after_setup_theme` — the
		 * earliest point WordPress 6.7+ considers safe to translate — so doing
		 * it here fires _load_textdomain_just_in_time on every request and
		 * resolves those labels against an unloaded domain.
		 *
		 * Every filter LegacyMinifier registers fires after `init`, so running
		 * one hook later is equivalent.
		 */
		add_action( 'init', array( $this, 'boot_on_init' ) );
	}

	/**
	 * The real boot body — see boot() for why it runs on `init`.
	 */
	public function boot_on_init(): void {
		$this->seed_from_legacy_if_needed();
		new LegacyMinifier();
	}

	public function activate(): void {
		// Plugin activation hits all modules. Same seed logic — safe to
		// run more than once.
		$this->seed_from_legacy_if_needed();
	}

	/**
	 * Say so when HTML minification is switched on but suppressed.
	 *
	 * `Minifier::skip_reason()` was consulted only by `wp xspeed minify status`
	 * — the dashboard read "on" while `minify_html()` returned its input
	 * untouched, so the feature looked broken rather than paused. A field
	 * report showed a live site with `minify_html: on` and 3,856 indented lines
	 * delivered, and nothing anywhere explaining the contradiction. (#2)
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ui_notices(): array {
		$opts = $this->get_settings();
		if ( empty( $opts['minify_html'] ) ) {
			return array();
		}

		$reason = LegacyMinifier::skip_reason();
		if ( '' === $reason ) {
			return array();
		}

		// Two different causes, two different fixes — naming the wrong one
		// sends the user hunting in the wrong file.
		$body = 'wp_debug' === $reason
			? __( 'HTML minification is paused because WP_DEBUG is enabled in wp-config.php. Readable HTML is usually what you want while debugging, so xSpeed leaves the markup alone. Cached pages are served un-minified until WP_DEBUG is turned off.', 'xspeed' )
			: __( 'HTML minification is paused because a plugin or theme is returning true from the xspeed_skip_minify filter. Cached pages are served un-minified until that filter stops suppressing it.', 'xspeed' );

		return array(
			array(
				'tone'  => 'info',
				'title' => __( 'HTML minification is on but currently paused', 'xspeed' ),
				'body'  => $body,
			),
		);
	}

	private function seed_from_legacy_if_needed(): void {
		$existing = get_option( 'xspeed_module_minify', null );
		if ( null !== $existing ) {
			return;
		}
		$legacy = get_option( 'xspeed_options', array() );
		if ( ! is_array( $legacy ) ) {
			return;
		}
		$seed   = array( '_version' => self::VERSION );
		$dirty  = false;
		foreach ( array( 'minify_html', 'minify_css', 'minify_js' ) as $key ) {
			if ( array_key_exists( $key, $legacy ) ) {
				$seed[ $key ] = (bool) $legacy[ $key ];
				unset( $legacy[ $key ] );
				$dirty        = true;
			}
		}
		if ( $dirty ) {
			update_option( 'xspeed_module_minify', $seed );
			update_option( 'xspeed_options', $legacy );
		}
	}

	public function cli_handler( array $args, array $assoc ): void {
		$action = $args[0] ?? 'status';

		if ( 'status' === $action ) {
			$opts = Settings_Manager::get( self::SLUG );
			// A bare "on" is a lie when the skip guard is active: the
			// setting is stored, but Minifier::minify_html() returns its
			// input untouched and the delivered HTML is unchanged. Say so
			// on the same line, so the contradiction can never be read as
			// "minify is broken".
			$skip = \XSpeed\Minifier::skip_reason();
			$html_state = $opts['minify_html'] ? 'on' : 'off';
			if ( $opts['minify_html'] && '' !== $skip ) {
				$html_state .= ( 'wp_debug' === $skip )
					? ' (NOT APPLIED — WP_DEBUG is enabled; set WP_DEBUG to false to minify HTML)'
					: ' (NOT APPLIED — suppressed by the xspeed_skip_minify filter)';
			}
			\WP_CLI::log( sprintf( 'minify_html: %s', $html_state ) );
			\WP_CLI::log( sprintf( 'minify_css : %s', $opts['minify_css'] ? 'on' : 'off' ) );
			\WP_CLI::log( sprintf( 'minify_js  : %s', $opts['minify_js'] ? 'on' : 'off' ) );
			return;
		}

		if ( 'purge' === $action ) {
			LegacyMinifier::purge_minified();
			\WP_CLI::success( 'Minify cache purged.' );
			return;
		}

		\WP_CLI::error( "Unknown action: $action" );
	}

	/**
	 * Minify has no master switch -- it is on when any of minify_html /
	 * minify_css / minify_js / defer_js / delay_js / async_css /
	 * remove_query_strings is set. (#363)
	 */
	public function is_active(): ?bool {
		return $this->any_bool_flag_on();
	}
}
