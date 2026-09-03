<?php
/**
 * Admin menu + asset enqueue.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

class Admin {

	const PAGE_SLUG = 'xspeed';

	const THEME_COOKIE = 'xspeed_theme';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_menu_styles' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'admin_body_class' ) );
		// Add a "Settings" shortcut to the plugin's row on the Plugins screen,
		// deep-linking straight to the xSpeed dashboard. (FBS-83234)
		add_filter( 'plugin_action_links_' . plugin_basename( XSPEED_FILE ), array( __CLASS__, 'plugin_action_links' ) );
		// Strip third-party admin notices on our screens only. Fires in
		// `in_admin_header` (after `get_current_screen()` is populated
		// but before notices render) so the screen check is reliable.
		add_action( 'in_admin_header', array( __CLASS__, 'suppress_foreign_notices' ), 0 );
	}

	/**
	 * Remove every third-party admin notice on xSpeed admin screens so the
	 * dashboard stays visually clean. Scoped via `is_plugin_page()` — runs
	 * nowhere else. Our own notices stay rendered: register them on the
	 * dedicated `xspeed_admin_notices` action below, which fires after
	 * this suppression and is wired to all three core notice hooks.
	 *
	 * Hooks cleared: `admin_notices`, `all_admin_notices`,
	 * `user_admin_notices`, `network_admin_notices`. WordPress's own
	 * settings-saved / updated messages are emitted via `settings_errors()`
	 * and printed inline by `options.php` — they are NOT on these hooks
	 * and are unaffected.
	 *
	 * @return void
	 */
	public static function suppress_foreign_notices() {
		if ( ! self::is_plugin_page() ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );

		// Re-route the four standard notice hooks to a single namespaced
		// action so xSpeed (and any deliberate extender that opts in)
		// keeps a place to emit notices after the strip.
		$relay = static function () {
			/**
			 * Fires in place of WP's `admin_notices` family on xSpeed
			 * admin screens. Use this instead of `admin_notices` when
			 * you want a notice to survive xSpeed's third-party
			 * suppression.
			 *
			 * @since 1.0.3
			 */
			do_action( 'xspeed_admin_notices' );
		};
		add_action( 'admin_notices',         $relay );
		add_action( 'all_admin_notices',     $relay );
		add_action( 'user_admin_notices',    $relay );
		add_action( 'network_admin_notices', $relay );
	}

	/**
	 * Server-side theme detection from the cookie written by useTheme. Used
	 * to emit the `.dark` class on the React mount node and the
	 * `xspeed-dark` class on `<body>` during the initial render — kills the
	 * light→dark flash that happens when JS adds those classes after the
	 * page has already painted.
	 *
	 * @return string 'dark' | 'light'
	 */
	public static function user_theme() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only string compare; value is sanitized via sanitize_key() on the next line before any use.
		$raw = isset( $_COOKIE[ self::THEME_COOKIE ] ) ? wp_unslash( $_COOKIE[ self::THEME_COOKIE ] ) : '';
		return 'dark' === sanitize_key( $raw ) ? 'dark' : 'light';
	}

	public static function is_plugin_page() {
		// Prefer the ?page= slug: it's brand-independent. WordPress derives
		// the submenu screen base from the *sanitized parent menu title*, so
		// once White-Label renames the menu the base becomes e.g.
		// "acmespeed_page_xspeed-onboarding" and any check anchored on
		// self::PAGE_SLUG ("xspeed_page_…") silently stops matching — which
		// dropped the xspeed-page / xspeed-dark body classes on the wizard and
		// left it unstyled. Match the slug instead. (FBS-82222)
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen gate.
		if ( self::PAGE_SLUG === $page || Onboarding::PAGE_SLUG === $page ) {
			return true;
		}

		// Fallback for contexts where $_GET['page'] isn't set but the screen is
		// available. The toplevel base is slug-based (stable); the submenu base
		// is title-derived, so match on its slug SUFFIX rather than the prefix.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		$base = (string) $screen->base;
		return 0 === strpos( $base, 'toplevel_page_' . self::PAGE_SLUG )
			|| (bool) preg_match( '/_page_' . preg_quote( self::PAGE_SLUG, '/' ) . '($|-)/', $base );
	}

	public static function admin_body_class( $classes ) {
		if ( ! self::is_plugin_page() ) {
			return $classes;
		}
		$classes .= ' xspeed-page';
		// Dashboard-only marker (NOT the onboarding wizard, which shares
		// `xspeed-page` + the same admin.css but must scroll as a normal
		// centered card). Layout rules that reshape the WP admin chrome — the
		// sticky content column that pins the fixed-height dashboard while a
		// tall admin menu scrolls — are scoped to `.xspeed-dashboard` so they
		// never touch the wizard. Keyed on the ?page= slug (brand-independent).
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen gate.
		if ( self::PAGE_SLUG === $page ) {
			$classes .= ' xspeed-dashboard';
		}
		if ( 'dark' === self::user_theme() ) {
			$classes .= ' xspeed-dark';
		}
		return $classes;
	}

	/**
	 * Prepend a "Settings" link to the plugin's action links on the Plugins
	 * screen, deep-linking to the xSpeed dashboard. Only users who can reach
	 * the settings page (`manage_options`, the same cap the menu uses) see it.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public static function plugin_action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'xspeed' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	public function register_menu() {
		$brand = self::branding();
		// Use the white-label logo for the admin menu icon when set, so the
		// rebrand carries to the menu mark too — not just the title. Falls
		// back to the built-in xSpeed SVG. (FBS-82222)
		$menu_icon = ! empty( $brand['logo_svg'] ) ? $brand['logo_svg'] : self::menu_icon();
		// Menu label + page <title> both read the resolved brand name
		// ("xSpeed Cache" by default; a white-label brand is used verbatim).
		// Positioned just after Appearance
		// (WP core slot 60) — up with the site-management group, not buried
		// down by Settings and not pinned to the very top.
		$menu_label = self::menu_label( $brand );
		add_menu_page(
			$brand['name'],
			$menu_label,
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' ),
			$menu_icon,
			61
		);

		// Drop WP's auto-generated duplicate first submenu (which
		// inherits the toplevel "xSpeed" title). The group deep-links
		// below replace it — keeping it would render a redundant
		// "xSpeed" / "Dashboard" row that just re-links to the same
		// page as the toplevel entry.
		global $submenu;
		// $submenu may not yet be populated for this slug; the
		// `remove_submenu_page` call covers either case.
		remove_submenu_page( self::PAGE_SLUG, self::PAGE_SLUG );

		// Deep-link submenus — each points to the same dashboard page
		// with a section hash so React's App.tsx routing lands the
		// user inside the right module. WordPress's add_submenu_page
		// strips the hash, so we inject directly into $submenu where
		// it survives intact (the same trick Yoast / WooCommerce use
		// for their per-area shortcuts).
		global $submenu;
		$deep_links = self::deep_link_items();
		foreach ( $deep_links as $hash => $label ) {
			$submenu[ self::PAGE_SLUG ][] = array(
				$label,
				'manage_options',
				'admin.php?page=' . self::PAGE_SLUG . '#' . $hash,
			);
		}
	}

	/**
	 * Section deep-links rendered under the xSpeed menu.
	 *
	 * This is deliberately a SHORT LIST, not a mirror of React's
	 * SIDEBAR_GROUPS. It used to mirror all eight groups 1:1, which made the
	 * WP admin rail a second, competing copy of the in-app sidebar — the same
	 * map rendered twice, one of them permanently expanded and pushing every
	 * other plugin's menu down the page.
	 *
	 * What stays are the entry points a user navigates to from OUTSIDE the
	 * app: the dashboard itself, the two areas people arrive at with a
	 * specific errand (AI & agents to connect an assistant, Settings), and the
	 * wizard. Everything else — Cache, Optimization, Network, Health &
	 * insights, Tools — is one click away in the app's own sidebar, which is
	 * where in-app navigation belongs.
	 *
	 * So adding a group to React's SIDEBAR_GROUPS no longer means adding it
	 * here. The two lists are intentionally different lengths now.
	 *
	 * Setup Wizard is NOT in this array — it's a real submenu page registered
	 * by Onboarding::register_menu() on `admin_menu` at priority 20, so it
	 * lands after these entries.
	 *
	 * @return array<string,string> map of route hash → menu label.
	 */
	private static function deep_link_items() {
		// Keys are route hashes (`/<group-id>`); the submenu loop prepends '#'.
		// React (nav.ts parseRoute) resolves `#/<group-id>` to that landing;
		// legacy `#slug` links still redirect, so old bookmarks keep working —
		// including bookmarks to the groups no longer listed here.
		return array(
			'/overview'  => __( 'Overview', 'xspeed' ),
			'/ai-agents' => __( 'AI & agents', 'xspeed' ),
			'/settings'  => __( 'Settings', 'xspeed' ),
		);
	}

	/**
	 * Pluggable branding for the dashboard chrome. xspeed-pro's
	 * White-Label module hooks `xspeed_branding` to override these
	 * values from saved settings.
	 *
	 * @return array{name:string,footer_credit:?string,hide_help_links:bool,logo_svg:?string}
	 * @since 1.5.0
	 */
	public static function branding() {
		$defaults = array(
			'name'            => 'xSpeed Cache',
			'footer_credit'   => null, // null = show the default WPDeveloper credit.
			'hide_help_links' => false,
			'logo_svg'        => null, // null = use the built-in brand mark.
		);
		$out = apply_filters( 'xspeed_branding', $defaults );
		if ( ! is_array( $out ) ) {
			return $defaults;
		}
		return array_merge( $defaults, $out );
	}

	/**
	 * Label for the WordPress admin sidebar menu entry — the resolved brand
	 * name verbatim ("xSpeed Cache" by default, or the white-label name).
	 *
	 * @param array{name:string} $brand Resolved branding array.
	 * @return string
	 */
	private static function menu_label( array $brand ) {
		return isset( $brand['name'] ) ? (string) $brand['name'] : 'xSpeed Cache';
	}

	/**
	 * URL of the SVG menu icon — the official xSpeed brand mark. Uses
	 * fill="currentColor" which renders black in <img> context; the inline
	 * style below recolors it via CSS filter for the WP admin menu states.
	 */
	private static function menu_icon() {
		return XSPEED_URL . 'assets/icon.svg';
	}

	public function render() {
		$dark = 'dark' === self::user_theme() ? ' dark' : '';
		// Pre-mount skeleton: the React bundle executes a beat after the page
		// paints, so without this the mount div is an empty dark void while it
		// loads. We render the xSpeed brand mark (pulsing) straight into the
		// mount node in PHP; createRoot().render() REPLACES these children the
		// instant React boots, so the skeleton disappears with no JS wiring.
		// The mark is the bundled icon.svg; `dark:invert` (scoped in
		// styles.css) keeps the currentColor mark visible on the dark shell.
		printf(
			'<div id="xspeed-app" class="xspeed-root%1$s"><div class="xspeed-boot" role="status" aria-label="%2$s"><img class="xspeed-boot-mark" src="%3$s" alt="%4$s" width="48" height="48" /><span class="screen-reader-text">%5$s</span></div></div>',
			esc_attr( $dark ),
			esc_attr__( 'Loading', 'xspeed' ),
			esc_url( XSPEED_URL . 'assets/icon.svg' ),
			esc_attr__( 'xSpeed', 'xspeed' ),
			esc_html__( 'Loading…', 'xspeed' )
		);
	}

	/**
	 * Enqueue the stylesheet that recolors the menu icon to match the WP
	 * admin color scheme. Loads on every admin page (not just the plugin's
	 * page) because the menu icon is visible site-wide.
	 */
	public function enqueue_menu_styles() {
		wp_enqueue_style(
			'xspeed-menu-icon',
			XSPEED_URL . 'assets/menu-icon.css',
			array(),
			XSPEED_VERSION
		);

		// menu-icon.css recolors the built-in mark (fill=currentColor) to white
		// via a brightness/invert filter. A white-label logo is a real image
		// (often colored), so cancel the filter for it — otherwise the agency
		// logo renders as a white silhouette. (FBS-82222)
		$brand = self::branding();
		if ( ! empty( $brand['logo_svg'] ) ) {
			wp_add_inline_style(
				'xspeed-menu-icon',
				'#toplevel_page_' . self::PAGE_SLUG . ' .wp-menu-image img{filter:none;opacity:1}'
			);
		}
	}

	public function enqueue( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$asset_js  = XSPEED_DIR . 'assets/admin.js';
		$asset_css = XSPEED_DIR . 'assets/admin.css';

		// Needed for the media-library picker used by schema 'media' fields
		// (e.g. the white-label brand logo). Loads window.wp.media.
		wp_enqueue_media();

		if ( file_exists( $asset_js ) ) {
			// filemtime() cache-busts on every rebuild so a stable
			// VERSION constant never serves stale JS through browser
			// caches.
			wp_enqueue_script(
				'xspeed-admin',
				XSPEED_URL . 'assets/admin.js',
				array( 'wp-api-fetch', 'wp-i18n' ),
				XSPEED_VERSION . '.' . filemtime( $asset_js ),
				true
			);
			// Loads .mo files for the 'xspeed' text-domain into
			// window.wp.i18n so the React `__()` helper resolves.
			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations(
					'xspeed-admin',
					'xspeed',
					XSPEED_DIR . 'languages'
				);
			}
		}

		// Redesign v2 design tokens + self-hosted fonts. Hand-written (not
		// Vite-bundled) so the @font-face url('./fonts/…') resolve relative to
		// assets/. admin.css depends on it so the CSS vars are defined first.
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

		/**
		 * Fires after the Free dashboard bundle is enqueued, before its
		 * config is localized. Pro hooks this to enqueue its own bundle
		 * with `xspeed-admin` as a dependency, so its panel
		 * registrations run after `window.XSpeedPro` is installed by
		 * Free's main.tsx.
		 *
		 * @since 1.5.0
		 */
		do_action( 'xspeed_admin_enqueue', $hook );

		// NOT wp_localize_script(). WP_Scripts::localize() casts every scalar
		// to a string (wp-includes/class-wp-scripts.php: `(string) $value`),
		// so an int arrives in JS as "21600" and a bool as "1" or "".
		//
		// That silently broke the Pro prewarm scheduler: it guards with
		// `typeof gmtOffset === 'number'`, which a string fails, so the site's
		// UTC offset was treated as 0 and every one-off warm was scheduled
		// against UTC instead of site time — hours late on any non-UTC site,
		// under a label that confidently read "Site time (UTC)".
		//
		// wp_add_inline_script() with wp_json_encode() preserves types, so
		// numbers stay numbers and booleans stay booleans. Worth doing beyond
		// the one field: every future numeric or boolean config value would
		// hit the same trap. (#105)
		self::print_config(
			'XSpeedConfig',
			array(
				'restUrl'   => esc_url_raw( rest_url( Rest_Api::NAMESPACE_V1 ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'version'   => XSPEED_VERSION,
				'branding'  => self::branding(),
				// Site's UTC offset in seconds — so datetime pickers (e.g. the
				// Pro prewarm scheduler) align with WP's own post-scheduling,
				// which is site-time, not the admin's browser-local time.
				'gmtOffset' => (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ),
				// 'pro' when xspeed-pro is active + speaks our API
				// version (see Tier_Registry); 'free' otherwise.
				// 'trial' reserved for future license-server work.
				'tier'      => class_exists( '\\XSpeed\\Tier_Registry' ) && Tier_Registry::pro_active() ? 'pro' : 'free',
				// Three-state Pro status so gated UI can show the RIGHT message:
				//   'not_installed' — Pro plugin not active → "Upgrade to Pro"
				//   'unlicensed'    — Pro active, no valid license → "Activate license"
				//   'active'        — Pro active + licensed → (modules unlocked)
				// Free can't read Pro's license directly (Free never references
				// Pro), so Pro filters this via `xspeed_pro_state`. Default:
				// not_installed when Pro is absent; 'active' when Pro is present
				// (Pro downgrades to 'unlicensed' when its license isn't valid).
				'proState'  => self::pro_state(),
				// Setup Wizard URL, surfaced in the sidebar profile popover.
				'wizardUrl' => admin_url( 'admin.php?page=' . Onboarding::PAGE_SLUG ),
				'bootstrap' => self::bootstrap_payload(),
			)
		);
	}

	/**
	 * Emit a JS global for the admin bundle with types intact.
	 *
	 * The type-preserving replacement for wp_localize_script(), which
	 * stringifies every scalar. Attached to the `xspeed-admin` handle as a
	 * `before` script so it is defined by the time the bundle executes —
	 * exactly the ordering guarantee localize gave us.
	 *
	 * Shared by the dashboard and the onboarding wizard so neither can drift
	 * back to the stringifying path.
	 *
	 * @param string $var_name JS global to define.
	 * @param array  $data     Payload; encoded with wp_json_encode().
	 */
	public static function print_config( string $var_name, array $data ): void {
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			// Never emit a broken assignment — the bundle reads this global
			// on mount and a syntax error here blanks the whole screen.
			$json = '{}';
		}
		wp_add_inline_script(
			'xspeed-admin',
			'var ' . $var_name . ' = ' . $json . ';',
			'before'
		);
	}

	/**
	 * Pre-rendered settings + status payload, baked into the page so the
	 * React app can mount with real values instead of showing a loading state
	 * while it waits for /settings and /status REST calls.
	 */
	/**
	 * Three-state Pro status for the gated UI ('not_installed' | 'unlicensed'
	 * | 'active'). Free cannot read Pro's license (it never references Pro),
	 * so the authoritative value comes from the `xspeed_pro_state` filter that
	 * xspeed-pro hooks. The default here only distinguishes installed vs not —
	 * when Pro is active, Pro itself downgrades the value to 'unlicensed' if
	 * its license isn't valid.
	 *
	 * @return string
	 */
	private static function pro_state(): string {
		$pro_present = class_exists( '\\XSpeed\\Tier_Registry' ) && Tier_Registry::pro_active();
		$default     = $pro_present ? 'active' : 'not_installed';

		/**
		 * Filter: xspeed_pro_state
		 *
		 * Lets the Pro plugin report its real license state so Free's gated
		 * panels can show "Activate license" (Pro installed, unlicensed) vs
		 * "Upgrade to Pro" (Pro not installed).
		 *
		 * @param string $state One of 'not_installed' | 'unlicensed' | 'active'.
		 */
		$state = (string) apply_filters( 'xspeed_pro_state', $default );

		return in_array( $state, array( 'not_installed', 'unlicensed', 'active' ), true ) ? $state : $default;
	}

	private static function bootstrap_payload() {
		$opts  = Settings::get();
		$stats = Cache::get_stats();
		// Static-rewrite probe state shipped to the React side so the
		// dashboard can show a persistent banner when nginx/Apache
		// hasn't been wired to bypass PHP yet. Cache-ONLY here (no $allow_probe
		// arg) so the dashboard bootstrap never makes the loopback HTTP probe
		// — that could add seconds to every admin page load on hosts that
		// stall self-requests. The Health tab runs the live probe on demand;
		// here we just surface whatever it last cached. (FBS-82142)
		$server_type     = Server::type();
		// LiteSpeed serves hits via the PHP drop-in by design (its .htaccess
		// can't add the HIT header or log a static hit), so the static-rewrite
		// probe is N/A there — surfacing it would pop the "PHP fallback" nag
		// for a setup working as designed. Only nginx + Apache probe.
		$rewrite_capable = ( $server_type === Server::NGINX || $server_type === Server::APACHE );
		$rewrite_probe   = null;
		if ( $opts['cache_enabled'] && $rewrite_capable ) {
			$probe         = Cache::probe_static_rewrite();
			$rewrite_probe = array(
				'active'       => (bool) ( $probe['active'] ?? false ),
				'server_type'  => $server_type,
				'snippet'      => Cache::nginx_snippet(), // null on non-nginx hosts
				'topology'     => Server::rewrite_topology(),
				// A reverse proxy / CDN in front (X-Forwarded-* present) usually
				// means the request-terminating nginx isn't user-editable on this
				// host — the banner uses this to switch to honest messaging
				// instead of dangling a snippet the user can't apply.
				'behind_proxy' => Server::is_behind_proxy(),
			);
		}

		return array(
			'settings' => $opts,
			'status'   => array(
				'enabled' => (bool) $opts['cache_enabled'],
				'stats'   => $stats,
				'server'  => array(
					'type'          => $server_type,
					'gzip_mode'     => Server::gzip_mode(),
					'gzip_active'   => Gzip::probe_active(),
					'nginx_snippet' => Gzip::nginx_snippet(),
				),
				'rewrite_probe' => $rewrite_probe,
				// Separate Mobile Cache visibility (FBS-83145) — mirrors the
				// /status block so the dashboard callout renders on first paint
				// without waiting for a status re-fetch.
				'mobile_separate' => array(
					'enabled'      => (bool) ( $opts['cache_enabled'] ? ( Settings_Manager::get( 'cache' )['mobile_separate'] ?? false ) : false ),
					'blocking'     => $rewrite_capable && 'mobile_separate' === Cache::static_rewrite_block_reason(),
					'needs_review' => Cache::mobile_separate_needs_review(),
				),
				// One consolidated nginx server-block snippet aggregating
				// every enabled module's directives (Cache static-rewrite,
				// BrowserCache headers, GZIP, …). Null on non-nginx hosts
				// or when no module contributes directives. Replaces the
				// per-module "paste this snippet" notices.
				'nginx_server_block' => Cache::full_nginx_server_block(),
			),
			// Registered Modules (Free + Pro). The React app discovers them
			// here and renders one sidebar item + one panel per module that
			// declares a settings schema. Hidden modules are filtered.
			'modules'  => self::modules_payload(),
			// xSpeed Hub connection snapshot so the Account panel + header chip
			// render the correct connected/not-connected state on FIRST PAINT —
			// no fetch, no "not connected → connected" flash. Null when the MCP
			// module is unavailable (the panel then falls back to /mcp/hub).
			'hub'      => self::hub_payload(),
		);
	}

	/**
	 * xSpeed Hub connection snapshot for the dashboard bootstrap. Guarded so the
	 * dashboard never hard-depends on the MCP module. Same shape as GET /mcp/hub.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function hub_payload() {
		if ( ! class_exists( '\XSpeed\Modules\Mcp\Mcp_Hub' ) ) {
			return null;
		}
		return \XSpeed\Modules\Mcp\Mcp_Hub::public_status();
	}

	/**
	 * Serialize every available Module for the React dashboard. Each entry
	 * carries enough to render: identity (slug + tier), UI metadata (label,
	 * icon, optional description), current settings, and the typed schema
	 * the panel uses to render controls.
	 *
	 * Modules that declare `hidden => true` in ui_metadata (e.g., engine
	 * modules with no user-facing settings) are skipped.
	 */
	public static function modules_payload() {
		if ( ! class_exists( 'XSpeed\\Module_Registry' ) ) {
			return array();
		}
		$out = array();
		foreach ( Module_Registry::available() as $slug => $module ) {
			$meta = $module->ui_metadata();
			if ( ! empty( $meta['hidden'] ) ) {
				continue;
			}
			$schema       = $module->settings_schema();
			$custom_panel = $meta['custom_panel'] ?? null;
			// Skip only when the module has neither a schema nor a custom
			// panel — i.e., truly nothing to render in the dashboard.
			if ( empty( $schema ) && empty( $custom_panel ) ) {
				continue;
			}
			$settings = Settings_Manager::get_public( $slug );

			$entry = array(
				'slug'         => $slug,
				'tier'         => $module->tier(),
				'version'      => $module->version(),
				// Promoted from inside `settings` so the payload is
				// self-describing. Consumers kept tripping on this — the Hub
				// rendered every module "Inactive" until it learned to look
				// inside the settings bag. `settings.enabled` is kept below
				// for back-compat; this is the same value, not a second
				// source of truth. Modules with no `enabled` key (status
				// panels like Health) report null rather than a misleading
				// false. (#146)
				'enabled'      => array_key_exists( 'enabled', $settings )
					? (bool) $settings['enabled']
					: null,
				'label'        => $meta['label'] ?? ucfirst( $slug ),
				'icon'         => $meta['icon'] ?? 'Square',
				'description'  => $meta['description'] ?? '',
				// Short label for the module's own tab when it hosts a tabbed
				// page (FBS-83633). Only set on host modules.
				'tab_label'    => $meta['tab_label'] ?? null,
				// Public view: real values except secret fields, which are masked.
				// The dashboard bundle localizes this into page HTML, so a raw
				// credential here would be readable from view-source. (#115)
				'settings'     => $settings,
				'schema'       => $schema,
				'notices'      => $module->ui_notices(),
				'custom_panel' => $meta['custom_panel'] ?? null,
			);

			/**
			 * Last-mile descriptor filter. Lets Pro (or third-party
			 * extensions) override any field before the module is
			 * shipped to React. Primary use: xspeed-pro hooks this to
			 * swap `custom_panel` to LicenseLockedPanel for Pro modules
			 * when the license is invalid, so unlocked modules stay
			 * visible in the sidebar (good upsell UX) but the panel
			 * shows an activation prompt instead of the real surface.
			 */
			$out[] = apply_filters( 'xspeed_module_descriptor', $entry, $module );
		}
		return $out;
	}
}
