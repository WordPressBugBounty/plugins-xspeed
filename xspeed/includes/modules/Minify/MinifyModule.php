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
			'description'  => __( 'Strip whitespace and rewrite enqueued CSS / JS.', 'xspeed' ),
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
				'description' => __( 'Strip whitespace and comments from HTML output, including inline <style> and <script> blocks. Safe on most themes. Applies to cached (logged-out) responses — view the page in a private window to see the result.', 'xspeed' ),
			),
			'minify_css'  => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Minify CSS', 'xspeed' ),
				'description' => __( 'Compress and rewrite enqueued local stylesheets. External CSS is left untouched.', 'xspeed' ),
			),
			'minify_js'   => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Minify JavaScript', 'xspeed' ),
				'description' => __( 'Compress enqueued local scripts. Disable if you hit script-loading conflicts on the frontend.', 'xspeed' ),
			),
			'defer_js' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Defer JavaScript', 'xspeed' ),
				'description' => __( 'Add defer="defer" to enqueued script tags so they execute after HTML parsing. jQuery + its hard dependencies are skipped automatically.', 'xspeed' ),
			),
			'delay_js' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Delay JavaScript Until Interaction', 'xspeed' ),
				'description' => __( 'Postpone script loading until the visitor scrolls, moves the mouse, taps, or presses a key. Drastically improves first paint on script-heavy pages; can break above-the-fold scripted UI — test before leaving on.', 'xspeed' ),
			),
			'async_css' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Load CSS Asynchronously', 'xspeed' ),
				'description' => __( 'Rewrite stylesheet link tags to load non-blocking via the print-then-all pattern. Pairs well with critical-CSS workflows; can cause a flash of unstyled content if the theme has no critical CSS.', 'xspeed' ),
			),
			'remove_query_strings' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Remove Asset Query Strings', 'xspeed' ),
				'description' => __( 'Strip ?ver=X.Y from enqueued CSS / JS URLs. Some CDN caches and proxies cache better when query strings are absent. Files under wp-content/uploads keep their version — page builders and consent plugins rewrite generated CSS there in place, and ?ver is what tells browsers to refetch it. Plugin, theme and core assets are still stripped: with Browser Cache on, an update reaches returning visitors only when their browser cache expires.', 'xspeed' ),
			),
			'defer_js_excluded' => array(
				'type'        => 'list',
				'default'     => array( 'jquery-core', 'jquery-migrate' ),
				'item_type'   => 'string',
				'label'       => __( 'Defer / Delay Exclusions', 'xspeed' ),
				'description' => __( 'Script handles OR URL substrings that skip defer + delay. Defaults exclude jQuery (most themes depend on it being available synchronously). One per line.', 'xspeed' ),
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
				'label'       => __( 'Delay Only These Scripts', 'xspeed' ),
				'description' => __( 'Script handles OR URL substrings. When non-empty, matching scripts are delayed, plus the known third-party tags xSpeed recognises on its own (analytics, tag managers, chat widgets, review embeds, error trackers) — so a heavy vendor script is postponed even when it is not listed here. Leave empty to delay all scripts (minus the exclusions above). Handles are the more reliable selector — a URL substring has to match the script\'s original URL, and minification rewrites that to a hashed cache path. One per line.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'delay_js' ),
			),
			'delay_js_timeout' => array(
				'type'        => 'int',
				'default'     => 8000,
				'min'         => 0,
				'max'         => 60000,
				'label'       => __( 'Delay Failsafe Timeout (ms)', 'xspeed' ),
				'unit'        => 'ms',
				'description' => __( 'Load delayed scripts automatically after this many milliseconds when the visitor never interacts. Set to 0 for interaction-only, with no timer: a timer that fires inside a lab tool\'s measurement window loads the "delayed" scripts anyway and inflates the reported TTI. Keep a non-zero value if a delayed script must eventually run for visitors who never scroll, tap, or type.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'delay_js' ),
			),
			'combine_css' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Combine CSS Files', 'xspeed' ),
				'description' => __( 'Concatenate enqueued local stylesheets into a single file (with @import and url(…) paths resolved). External CSS is left alone. Pairs poorly with HTTP/2 push — only enable on HTTP/1.1 hosts.', 'xspeed' ),
			),
			'combine_js' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Combine JavaScript Files', 'xspeed' ),
				'description' => __( 'Concatenate enqueued local scripts into a single file. External scripts + scripts marked async / deferred are left alone. Disable if you hit dependency-order issues; the combiner respects WordPress enqueue order but inline scripts attached via wp_add_inline_script can shift behavior.', 'xspeed' ),
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
