<?php
/**
 * First-run onboarding wizard.
 *
 * Owns:
 *   - The hidden submenu page (`xspeed-onboarding`) and its single root <div>.
 *   - The activation-triggered redirect to that page.
 *   - The completion flag.
 *   - The environment-check payload + REST routes (`/onboarding/apply`,
 *     `/onboarding/complete`).
 *
 * Behavior contract is documented in DESIGN.md §25.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

class Onboarding {

	const PAGE_SLUG       = 'xspeed-onboarding';
	const OPT_REDIRECT    = 'xspeed_redirect_to_onboarding';
	const OPT_COMPLETE    = 'xspeed_onboarding_complete';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Mark the next admin_init to redirect this user to the wizard. Called
	 * from Plugin::activate(). Idempotent.
	 */
	public static function flag_redirect() {
		update_option( self::OPT_REDIRECT, 1, false );
	}

	public static function is_complete() {
		return (bool) get_option( self::OPT_COMPLETE, 0 );
	}

	public function register_menu() {
		// Visible "Setup Wizard" submenu under xSpeed. Stays in the
		// menu even after onboarding is complete so users can re-run
		// the wizard any time (a fresh run sets
		// xspeed_onboarding_complete = false again on completion).
		// Use the white-label brand in the page <title> so an agency's
		// rebrand carries through to the wizard tab/title, not just the
		// dashboard. (FBS white-label-onboarding)
		$brand = Admin::branding();
		/* translators: %s: brand name (xSpeed by default, or the white-label name). */
		$page_title = sprintf( __( '%s Setup Wizard', 'xspeed' ), $brand['name'] );
		add_submenu_page(
			Admin::PAGE_SLUG,
			$page_title,
			__( 'Setup Wizard', 'xspeed' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function render() {
		// Reuse the dashboard's mount ID so the Tailwind `important` selector
		// keeps working (utilities are scoped to `#xspeed-app`). The host
		// class strips the dashboard-only fixed-height/flex shell so the
		// wizard can lay out as a centered card. See src/styles.css.
		$dark = 'dark' === Admin::user_theme() ? ' dark' : '';
		printf(
			'<div id="xspeed-app" class="xspeed-root xspeed-onboarding-host%s"></div>',
			esc_attr( $dark )
		);
	}

	/**
	 * Send the user to the wizard on the first admin_init after activation.
	 * Skipped for AJAX/REST/cron, bulk-activate flows, and when the wizard
	 * has already been completed.
	 */
	public function maybe_redirect() {
		if ( ! get_option( self::OPT_REDIRECT ) ) {
			return;
		}
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision; we don't process form data here.
		if ( isset( $_GET['activate-multi'] ) ) {
			delete_option( self::OPT_REDIRECT );
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( self::is_complete() ) {
			delete_option( self::OPT_REDIRECT );
			return;
		}

		delete_option( self::OPT_REDIRECT );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public function enqueue( $hook ) {
		unset( $hook );
		// Gate on the page SLUG, not the admin hook suffix. WordPress derives
		// the submenu hook from the *sanitized parent menu title*, so when the
		// White-Label module renames the menu (e.g. "AcmeSpeed") the hook
		// becomes "acmespeed_page_xspeed-onboarding" and any check built on
		// Admin::PAGE_SLUG ("xspeed_page_…") silently stops matching — the
		// wizard bundle then never enqueues and the page renders blank.
		// The ?page= slug is brand-independent, so match on that instead.
		// (FBS-82222)
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen gate, no state change.
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		$asset_js  = XSPEED_DIR . 'assets/admin.js';
		$asset_css = XSPEED_DIR . 'assets/admin.css';

		if ( file_exists( $asset_js ) ) {
			// filemtime() cache-busts on every rebuild (matches Admin::enqueue)
			// so a stable version between releases never serves a stale bundle
			// on the wizard — the connected/login state always reflects the
			// current build.
			wp_enqueue_script(
				'xspeed-admin',
				XSPEED_URL . 'assets/admin.js',
				array( 'wp-api-fetch', 'wp-i18n' ),
				XSPEED_VERSION . '.' . filemtime( $asset_js ),
				true
			);
			// Load .mo translations into window.wp.i18n so the wizard's React
			// __() calls resolve (mirrors Admin::enqueue). Without this the
			// onboarding strings render untranslated even when a locale exists.
			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations( 'xspeed-admin', 'xspeed', XSPEED_DIR . 'languages' );
			}
		}
		// Redesign v2 tokens + fonts (see Admin::enqueue) — the wizard shares
		// them so it matches the dashboard identity.
		$theme_css = XSPEED_DIR . 'assets/theme.css';
		if ( file_exists( $theme_css ) ) {
			wp_enqueue_style(
				'xspeed-theme',
				XSPEED_URL . 'assets/theme.css',
				array(),
				XSPEED_VERSION . '.' . filemtime( $theme_css )
			);
		}
		if ( file_exists( $asset_css ) ) {
			wp_enqueue_style(
				'xspeed-admin',
				XSPEED_URL . 'assets/admin.css',
				array( 'xspeed-theme' ),
				XSPEED_VERSION . '.' . filemtime( $asset_css )
			);
		}

		// Same type-preserving path as the dashboard — see
		// Admin::print_config(). wp_localize_script() would stringify every
		// scalar in this payload too. (#105)
		Admin::print_config(
			'XSpeedConfig',
			array(
				'mode'        => 'onboarding',
				'restUrl'     => esc_url_raw( rest_url( Rest_Api::NAMESPACE_V1 ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'version'     => XSPEED_VERSION,
				// White-label branding must reach the wizard too — without it
				// the onboarding chrome shows the default "xSpeed" even when an
				// agency has rebranded. (FBS white-label-onboarding)
				'branding'    => Admin::branding(),
				'dashboardUrl' => admin_url( 'admin.php?page=' . Admin::PAGE_SLUG ),
				'bootstrap'   => array(
					'settings' => Settings::get(),
					// Live values for every wizard toggle, so re-running the
					// wizard reflects the site instead of overwriting it.
					'current'  => self::current_choices(),
					'env'      => self::env_payload(),
					// xSpeed Hub connection snapshot for the wizard's first
					// "Connect your account" step. Guarded so core onboarding
					// never hard-depends on the MCP module — if it's absent the
					// step falls back to its own /mcp/hub fetch (and simply shows
					// the not-connected invite). See DESIGN.md §24.x.
					'hub'      => self::hub_payload(),
				),
			)
		);
	}

	/**
	 * The site's CURRENT values for every toggle the wizard can write,
	 * in the same shape as the wizard's OnboardingChoices.
	 *
	 * The wizard used to seed its toggles from hard-coded preset constants,
	 * so re-running it on a configured site showed a fiction: options the
	 * admin had deliberately switched on rendered as off, and Apply wrote
	 * that fiction back, silently undoing their configuration. Nothing
	 * warned them, and the completion screen still reported success.
	 *
	 * The wizard couldn't have done better on its own — the bootstrap only
	 * carried Settings::get(), which is just cache_enabled. Every other
	 * toggle lives in a per-module option the payload never included, so
	 * this method is what makes "show the site as it actually is" possible.
	 *
	 * Pure read. Mirrors the keys apply() writes, so the two stay in step.
	 *
	 * @return array<string,bool|int>
	 */
	public static function current_choices() {
		$minify   = Settings_Manager::get( 'minify' );
		$gzip     = Settings_Manager::get( 'gzip' );
		$cache    = Settings_Manager::get( 'cache' );
		$lazy     = Settings_Manager::get( 'lazy' );
		$browser  = Settings_Manager::get( 'browser-cache' );
		$hints    = Settings_Manager::get( 'resource-hints' );
		$settings = Settings::get();

		return array(
			'cache_enabled'  => ! empty( $settings['cache_enabled'] ),
			'minify_html'    => ! empty( $minify['minify_html'] ),
			'minify_css'     => ! empty( $minify['minify_css'] ),
			'minify_js'      => ! empty( $minify['minify_js'] ),
			'defer_js'       => ! empty( $minify['defer_js'] ),
			'gzip_enabled'   => ! empty( $gzip['gzip_enabled'] ),
			'lazy_images'    => ! empty( $lazy['lazy_images'] ),
			'browser_cache'  => ! empty( $browser['enabled'] ),
			'resource_hints' => ! empty( $hints['enabled'] ),
			'cache_expiry'   => isset( $cache['cache_expiry'] ) ? absint( $cache['cache_expiry'] ) : \XSpeed\Modules\Cache\CacheModule::DEFAULT_EXPIRY_HOURS,
		);
	}

	/**
	 * Environment snapshot rendered as Step 1's health rows. Pure read —
	 * never writes to disk, never makes outbound requests.
	 */
	public static function env_payload() {
		// Single source of truth for environment checks lives in
		// XSpeed\Health. Both the Onboarding wizard and the Health
		// module's dashboard panel consume from there.
		return Health::env_payload();
	}

	/**
	 * xSpeed Hub connection snapshot for the wizard's first step. Identical
	 * shape to the MCP panel's `GET /mcp/hub` response so the Connect step and
	 * the panel's Hub card share one contract. Returns null when the MCP module
	 * is unavailable — the step then renders its own not-connected invite and
	 * re-fetches live from /mcp/hub if the route exists.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function hub_payload() {
		if ( ! class_exists( '\XSpeed\Modules\Mcp\Mcp_Hub' ) ) {
			return null;
		}
		$status = \XSpeed\Modules\Mcp\Mcp_Hub::public_status();
		// Override attach_url so the Hub returns the user to the WIZARD (mid-flow)
		// after they approve — not the default dashboard. The `xspeed_connected`
		// marker lets the wizard show the connected state + auto-advance on
		// return. Requires the Hub to honor return_url; if it doesn't yet, the
		// tab-return reconcile in useHubConnect is the graceful fallback.
		$return = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&xspeed_connected=1' );
		$status['attach_url'] = \XSpeed\Modules\Mcp\Mcp_Hub::attach_url( $return );
		return $status;
	}

	public function register_routes() {
		register_rest_route(
			Rest_Api::NAMESPACE_V1,
			'/onboarding/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
		register_rest_route(
			Rest_Api::NAMESPACE_V1,
			'/onboarding/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'complete' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
		register_rest_route(
			Rest_Api::NAMESPACE_V1,
			'/onboarding/reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reset' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
	}

	public function permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Apply the wizard's selected settings + flip the cache drop-in on if
	 * requested. Single REST round-trip so the wizard never lands in a
	 * half-applied state.
	 */
	public function apply( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$want_cache = ! empty( $params['cache_enabled'] );

		// Per-module settings go through Settings_Manager (the schema-
		// validated authority for each module). Legacy Settings::update
		// is reserved for fields still in xspeed_options (cache_expiry,
		// excluded_urls) until the Cache module migration lands.
		Settings_Manager::update(
			'minify',
			array(
				'minify_html' => ! empty( $params['minify_html'] ),
				'minify_css'  => ! empty( $params['minify_css'] ),
				'minify_js'   => ! empty( $params['minify_js'] ),
				'defer_js'    => ! empty( $params['defer_js'] ),
			)
		);
		Settings_Manager::update(
			'gzip',
			array(
				'gzip_enabled' => ! empty( $params['gzip_enabled'] ),
			)
		);
		Settings_Manager::update(
			'cache',
			array(
				'cache_expiry' => isset( $params['cache_expiry'] ) ? absint( $params['cache_expiry'] ) : 24,
			)
		);
		// New optional modules surfaced in the wizard — keys are
		// only updated when present in the payload so legacy
		// onboarding-complete sites aren't disturbed.
		if ( array_key_exists( 'lazy_images', $params ) ) {
			Settings_Manager::update(
				'lazy',
				array( 'lazy_images' => ! empty( $params['lazy_images'] ) )
			);
		}
		if ( array_key_exists( 'browser_cache', $params ) ) {
			Settings_Manager::update(
				'browser-cache',
				array( 'enabled' => ! empty( $params['browser_cache'] ) )
			);
		}
		if ( array_key_exists( 'resource_hints', $params ) ) {
			Settings_Manager::update(
				'resource-hints',
				array( 'enabled' => ! empty( $params['resource_hints'] ) )
			);
		}

		// Opt-in usage analytics. Only acted on when the key is present in the
		// payload (legacy onboarding-complete sites are left untouched). The
		// consent toggle defaults OFF in the wizard, so the common path is
		// usage_tracking=false → tracker stays dormant, no outbound HTTP.
		if ( array_key_exists( 'usage_tracking', $params ) ) {
			$tracker = Plugin::instance()->usage_tracker();
			if ( $tracker ) {
				$tracker->opt_in( ! empty( $params['usage_tracking'] ) );
			}
		}

		$install_state = Cache::toggle( $want_cache );

		// Recompute the unified nginx block AFTER cache_enabled is persisted.
		// Cache::toggle() computes it inline, before the Settings::update()
		// above writes cache_enabled — so the block in $install_state reflects
		// the PRE-apply state (CacheModule::nginx_directives() gates on
		// cache_enabled). Regenerate so the wizard's Done step shows the
		// snippet for the configuration the user just applied. Mirrors the
		// same fix in Rest_Api::toggle_cache().
		$install_state['nginx_server_block'] = Cache::full_nginx_server_block();

		return rest_ensure_response(
			array(
				'settings'      => Settings::get(),
				'install_state' => $install_state,
			)
		);
	}

	public function complete() {
		update_option( self::OPT_COMPLETE, 1, false );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function reset() {
		delete_option( self::OPT_COMPLETE );
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
