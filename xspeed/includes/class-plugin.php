<?php
/**
 * Main plugin bootstrap.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * Data-schema version for one-time migrations, independent of the
	 * plugin version header. Bump when adding a step to maybe_upgrade().
	 */
	public const DATA_VERSION = '1.4.4';

	private static $instance = null;

	private bool $security_notice_shown = false;

	/** @var Usage_Tracker|null */
	private $usage_tracker = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		Edge_Hold_Health::boot();
		// v1 services that are NOT yet wrapped as Modules (Admin, Rest_Api,
		// Cache, Onboarding) are instantiated directly. Minifier is now
		// instantiated by MinifyModule::boot(); Gzip is purely static.
		new Admin();
		new Rest_Api();
		new Cache();
		new Rest_Cache();
		new Onboarding();

		// Optional deactivation feedback survey on the Plugins screen. Admin
		// context only (its hooks are admin_enqueue_scripts / admin_footer /
		// wp_ajax). Sends nothing unless the user clicks "Submit & Deactivate".
		if ( is_admin() ) {
			new Deactivation_Feedback();
		}

		// Opt-in usage analytics. Instantiating + init() only registers the
		// cron callback; NOTHING is collected or sent until the admin opts in
		// from the setup wizard (Onboarding wires the consent toggle to
		// Usage_Tracker::opt_in()). See class-usage-tracker.php privacy contract.
		$this->start_plugin_tracking();

		// Auto-heal the cache drop-in + WP_CACHE constant when state
		// drifts. Scoped to admin_init only: filesystem writes belong in
		// an authenticated admin context, never on anonymous front-end
		// requests. The user already opted in (cache_enabled=true) —
		// this is a consistency check, not a new install path. First
		// admin page load after a plugin upgrade restores the state;
		// front-end then serves from cache on the next request.
		add_action( 'admin_init', array( Cache::class, 'auto_heal' ) );
		// Records (never writes) when the drop-in copy that served this
		// request is older than the one this plugin ships. See
		// Cache::note_dropin_version(). (#396)
		add_action( 'init', array( Cache::class, 'note_dropin_version' ), 99 );
		// Cheap: returns immediately unless the stored schema version is
		// behind. Covers updates and multisite, where activate() never runs.
		add_action( 'admin_init', array( Score_Store::class, 'maybe_install' ) );

		// Secondary net: restore as soon as an update completes, for the
		// cases where activate() does not re-run (bulk updates, auto-updates,
		// some host updaters). Best-effort by nature — this callback is only
		// registered when we were loaded in the request performing the
		// update, which is not guaranteed while WE are the plugin being
		// replaced. The restore in activate() is the primary guarantee;
		// auto_heal() on admin_init remains the backstop.
		add_action( 'upgrader_process_complete', array( $this, 'maybe_restore_after_update' ), 10, 2 );

		// Per-post cache rules (Phase 3.4) — registers postmeta with
		// REST + meta box on edit screens.
		Cache_Meta_Box::boot();

		// Single-URL purge entry points — row actions, the edit-screen
		// button and the admin-post handler the admin-bar item also uses.
		Purge_Ui::boot();

		// While the page cache is off, every admin page says so (#594).
		Cache_Off_Notice::boot();

		// Phase 0 architecture — managers + Free modules. v1 services
		// (Cache/Minifier/Gzip) are NOT yet Modules; they'll be refactored
		// in a follow-up PR with parity tests.
		Conflict_Registry::boot();

		// Read-only page-cache ownership evidence, behind the same
		// activation/deactivation invalidation as the conflict matrix.
		Page_Cache_Detector::boot();

		// Integrations that clear OTHER plugins' caches of rendered output
		// (Elementor's element cache + generated CSS). Registers a listener
		// only; nothing runs until Cache::purge_render_caches() asks.
		Render_Caches::boot();
		// Forwarding needs no boot(): Cache::dispatch_purge_event() calls
		// Server_Caches::forward() directly so a throwing third-party listener
		// cannot skip it. Both built-in server-cache adapters live behind it —
		// LiteSpeed, and the nginx FastCGI cache reached through the host's
		// Nginx Helper install (Host_Page_Caches), which is why neither is
		// booted here. This boot() registers one listener only: the single
		// purge that runs after an import, which the nginx adapter's import
		// gate stands down in favour of.
		Server_Caches::boot();

		// Remembers the terms a post had before a save, so a narrow purge
		// can clear the category it left as well as the one it joined.
		Affected_Pages::boot();
		// Records which pages run a post list of their own (a page builder
		// grid, a related-posts block), so a narrow purge clears them too.
		Listing_Pages::boot();

		// Tell WP Statistics and Slimstat not to count the warmer, the
		// benchmark and the verifier as visitors. Record-time filters only;
		// see the class for why the tracking snippet itself is left alone.
		Self_Traffic::boot();

		// Register Free modules via the same action xspeed-pro uses, so
		// the bootstrap path is symmetric across tiers.
		add_action( 'xspeed_register_modules', array( $this, 'register_free_modules' ) );

		// Fire the registration action + boot the registry at a LATER
		// plugins_loaded priority so add-on plugins (xspeed-pro, in
		// alphabetical order so it runs at default priority 10 AFTER
		// us, but any add-on loaded at plugins_loaded(< 20)) have a
		// chance to register their `xspeed_register_modules` callback
		// before we fire the action.
		//
		// Bug history: previously this fired inline from init() at
		// priority 10. xspeed-pro's plugins_loaded(15) hook then added
		// its register_pro_modules callback AFTER the action had
		// already fired — Pro modules never appeared in the registry.
		// Caught by the ProStatus sentinel module's integration test.
		add_action( 'plugins_loaded', array( $this, 'fire_module_lifecycle' ), 20 );

		// One-time data migrations keyed on the stored version. Runs in
		// admin and in WP-Cron, never on a visitor's page view. Cron
		// matters for background auto-updates: they install the new code
		// in a cron run, and without this a security purge waited for an
		// administrator to open wp-admin while visitors kept getting the
		// old cached pages.
		if ( is_admin() || wp_doing_cron() ) {
			add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 21 );
		}
		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'security_edge_notice' ) );
			add_action( 'network_admin_notices', array( $this, 'security_edge_notice' ) );
			add_action( 'xspeed_admin_notices', array( $this, 'security_edge_notice' ) );
			add_action( 'admin_post_xspeed_confirm_security_edge_purge', array( $this, 'confirm_security_edge_purge' ) );
		}
	}

	/**
	 * Add the 1.3.6 tracking params to a saved ignored-params list.
	 *
	 * Only those names, and only when missing: anything else the site
	 * removed stays removed. A site with no saved list already reads the new
	 * defaults.
	 */
	public static function add_new_ignored_params(): void {
		$option = Settings_Manager::OPTION_PREFIX . 'cache';
		$stored = get_option( $option, array() );
		if ( ! is_array( $stored ) || ! is_array( $stored['ignored_query_params'] ?? null ) ) {
			return;
		}
		$list = $stored['ignored_query_params'];
		// An empty list is a choice: every query string bypasses the cache.
		if ( empty( $list ) ) {
			return;
		}
		$missing = array_values( array_diff( \XSpeed\Modules\Cache\CacheModule::TRACKING_PARAMS_1_3_6, $list ) );
		if ( empty( $missing ) ) {
			return;
		}
		$stored['ignored_query_params'] = array_merge( $list, $missing );
		update_option( $option, $stored );
		// The drop-in keeps its own copy of the list next to the cache.
		if ( defined( 'XSPEED_CACHE_DIR' ) ) {
			Cache::sync_query_allowlist();
		}
	}

	/**
	 * Run version-gated data migrations exactly once per upgrade.
	 *
	 * Keyed on `xspeed_data_version` rather than the plugin version header
	 * so a migration can be added without forcing a release bump.
	 */
	public function maybe_upgrade(): void {
		$current = (string) get_option( 'xspeed_data_version', '0' );
		if ( version_compare( $current, self::DATA_VERSION, '>=' ) ) {
			return;
		}

		// Each step runs once, for the version that needs it. They used to
		// run on every bump, which would purge every site's static tree again
		// for a migration that has nothing to do with it.

		// 1.1.2 — strip credential values recorded by earlier versions'
		// settings change annotations (they're served by the trend endpoints).
		if ( version_compare( $current, '1.1.2', '<' ) ) {
			Activity_Log::redact_legacy_secrets();
		}

		// 1.1.4 — earlier versions cached a failed loopback as "gzip is not
		// active" for an hour, which showed up as a bogus server-config
		// warning. Drop the stale answer so the fixed probe re-runs instead
		// of the wrong verdict living on past the update (issue #18).
		if ( version_compare( $current, '1.1.4', '<' ) ) {
			delete_transient( 'xspeed_gzip_active' );
		}

		// 1.1.6 — a `/?s=<term>` request used to write its results page into
		// the static tree under the *searched-from* path, which for the usual
		// query-form search is `/`. The web server then served that results
		// page as the homepage to every visitor. The write is fixed in
		// Cache::store_static(), but an entry poisoned before the update
		// outlives it: nothing purges on upgrade, and the static serve path
		// never revalidates. Clear the tree once. The flat cache is keyed
		// correctly and is deliberately left alone. (issue #191)
		if ( version_compare( $current, '1.1.6', '<' ) ) {
			$removed = Cache::purge_static_tree();

			/*
			 * And tell anything caching in front of us, because the poisoned
			 * entry is exactly the kind that reaches an edge: the web server
			 * served it straight off disk as the homepage, so a CDN in front had
			 * every opportunity to store it. Deleting our copy leaves that one
			 * untouched, and the edge's lifetime is the longer of the two.
			 *
			 * Announced on `init` rather than here. This runs at
			 * `plugins_loaded` 21, before an add-on has wired its purge
			 * listeners, so firing it inline would announce to an empty room.
			 * Priority 99 puts it after any reasonable `init` registration.
			 *
			 * The data version advances after all pending migrations complete.
			 *
			 * Through `Cache::announce_purge()` rather than a bare `do_action`,
			 * because the purge-event contract (#348) is more than the payload:
			 * it forwards to the server caches by direct call before the public
			 * action runs, and it isolates listeners so one throwing add-on
			 * cannot fatal the first admin request after an update. A raw
			 * publish here would skip both and hand listeners the pre-contract
			 * payload shape.
			 */
			add_action(
				'init',
				static function () use ( $removed ) {
					Cache::announce_purge( 'static cache repaired on upgrade', $removed );
				},
				99
			);
		}

		// 1.3.6 — new click and campaign IDs in the default ignored list.
		// A site that ever saved the Cache panel has its own copy of the list,
		// which the new defaults never reach, so add them to it.
		if ( version_compare( $current, '1.3.6', '<' ) ) {
			self::add_new_ignored_params();
		}

		// 1.4.3 — the lazy loader rewrote an autoplay video's src through a
		// regex replacement that read `$1` in the value as a backreference,
		// so crafted post content could render as live markup. The rewrite is
		// fixed in Lazy_Loader::defer_autoplay_source(), but a page cached
		// before the update still holds the old output, and nothing would
		// rebuild it. Purge everything once, including copies at an edge.
		// Unconditional: the setting may have been switched off since the
		// page was cached. Deferred to `init` for the reason the 1.1.6 step
		// gives.
		if ( version_compare( $current, '1.4.3', '<' ) ) {
			// Record the steps above as done before the purge is scheduled.
			// The purge can stay pending across requests, and the steps
			// above must not run again with it (the 1.1.6 sweep would clear
			// the static tree and announce on every retry).
			if ( version_compare( $current, '1.3.6', '<' ) ) {
				update_option( 'xspeed_data_version', '1.3.6', false );
			}
			add_action(
				'init',
				static function () {
					$network = is_multisite();
					if ( ! $network || ! get_site_option( 'xspeed_security_network_purged_1_4_3', false ) ) {
						$last = self::security_purge_attempt( $network );
						// A cache file this request cannot delete (say, one a
						// root cron wrote) keeps the sweep incomplete. Retrying
						// on every admin and admin-ajax request purged the
						// whole site, and the edge with it, each time, so a
						// failed attempt waits an hour before the next one.
						if ( $last > 0 && ( time() - $last ) < HOUR_IN_SECONDS ) {
							return;
						}
						self::set_security_purge_attempt( $network, time() );
						// Zones are marked on the first attempt only. A retry
						// that marked them again would undo an administrator's
						// confirmation that the zone was purged in Cloudflare.
						if ( 0 === $last ) {
							self::mark_security_edge_zones( $network );
						}
						$local_complete = false;
						Cache::purge_all( 'cached pages rebuilt after update', $network ? '*' : null, array(), $local_complete );
						if ( ! $local_complete ) {
							return;
						}
						self::set_security_purge_attempt( $network, 0 );
						if ( $network ) {
							update_site_option( 'xspeed_security_network_purged_1_4_3', true );
						}
					}
					// Keep completed security work separate from optional diagnostic retries.
					update_option( 'xspeed_data_version', '1.4.3', false );
					if ( ! Edge_Hold_Health::initialize() ) { return; }
					update_option( 'xspeed_data_version', self::DATA_VERSION, false );
				},
				99
			);
			return;
		}

		if ( ! Edge_Hold_Health::initialize() ) { return; }
		update_option( 'xspeed_data_version', self::DATA_VERSION, false );
	}

	/**
	 * When the 1.4.3 security purge last tried and could not finish, or 0.
	 * Stored per network on multisite, where the purge sweeps every site.
	 */
	private static function security_purge_attempt( bool $network ): int {
		return (int) ( $network
			? get_site_option( 'xspeed_security_purge_attempt_1_4_3', 0 )
			: get_option( 'xspeed_security_purge_attempt_1_4_3', 0 ) );
	}

	/** Record an attempt time, or clear it with 0 once the purge finishes. */
	private static function set_security_purge_attempt( bool $network, int $time ): void {
		if ( $network && $time ) {
			update_site_option( 'xspeed_security_purge_attempt_1_4_3', $time );
		} elseif ( $network ) {
			delete_site_option( 'xspeed_security_purge_attempt_1_4_3' );
		} elseif ( $time ) {
			update_option( 'xspeed_security_purge_attempt_1_4_3', $time, false );
		} else {
			delete_option( 'xspeed_security_purge_attempt_1_4_3' );
		}
	}

	/** Mark every configured Cloudflare zone as awaiting a full purge. */
	private static function mark_security_edge_zones( bool $network ): void {
		if ( ! $network ) {
			$cloudflare = get_option( 'xspeed_module_cloudflare', array() );
			if ( ! empty( $cloudflare['zone_id'] ) ) {
				self::mark_security_edge_pending( (string) $cloudflare['zone_id'] );
			}
			return;
		}
		// Other blogs' edge listeners are not booted in this request.
		// Mark their zones without sending requests they did not opt into.
		$offset = 0;
		do {
			$ids = get_sites( array( 'network_id' => get_current_network_id(), 'fields' => 'ids', 'number' => 100, 'offset' => $offset ) );
			foreach ( $ids as $id ) {
				$cloudflare = get_blog_option( $id, 'xspeed_module_cloudflare', array() );
				if ( ! empty( $cloudflare['zone_id'] ) ) {
					self::mark_security_edge_pending( (string) $cloudflare['zone_id'], (int) $id );
				}
			}
			$offset += 100;
		} while ( count( $ids ) === 100 );
	}

	/** Accept the single-zone marker written before multiple zones were tracked. */
	private static function security_edge_zones( $pending ): array {
		return array_values( array_unique( array_filter(
			is_array( $pending ) ? $pending : array( $pending ),
			static fn( $zone ) => is_string( $zone ) && '' !== $zone
		) ) );
	}

	/** A retry must retain a previously configured zone until its purge is confirmed. */
	private static function mark_security_edge_pending( string $zone, ?int $blog_id = null ): void {
		$pending = null === $blog_id
			? get_option( 'xspeed_security_edge_pending', array() )
			: get_blog_option( $blog_id, 'xspeed_security_edge_pending', array() );
		$zones = self::security_edge_zones( $pending );
		$zones[] = $zone;
		$zones = array_values( array_unique( $zones ) );
		if ( null === $blog_id ) {
			update_option( 'xspeed_security_edge_pending', $zones, false );
		} else {
			update_blog_option( $blog_id, 'xspeed_security_edge_pending', $zones );
		}
	}

	/** Clear only the zone whose full purge was confirmed on the current site. */
	public static function clear_security_edge_pending( string $zone ): void {
		$zones = self::security_edge_zones( get_option( 'xspeed_security_edge_pending', array() ) );
		if ( ! in_array( $zone, $zones, true ) ) {
			return;
		}
		$zones = array_values( array_diff( $zones, array( $zone ) ) );
		if ( empty( $zones ) ) {
			delete_option( 'xspeed_security_edge_pending' );
		} else {
			update_option( 'xspeed_security_edge_pending', $zones, false );
		}
	}

	/** Warn until a full edge purge is confirmed automatically or by an administrator. */
	public function security_edge_notice(): void {
		$zones = self::security_edge_zones( get_option( 'xspeed_security_edge_pending', array() ) );
		// The purge retries hourly when a cache file cannot be deleted. Say
		// so: until it finishes, pages cached before the update are still
		// served, and only fixing the file permissions ends the retries.
		$stuck = self::security_purge_attempt( is_multisite() ) > 0;
		if ( $this->security_notice_shown || ! current_user_can( 'manage_options' ) || ( empty( $zones ) && ! $stuck ) ) {
			return;
		}
		$this->security_notice_shown = true;
		if ( $stuck ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'After the security update, xSpeed could not delete every cached page, so some pages cached before the update may still be served. It tries again every hour. Check that the web server can delete files in wp-content/cache/xspeed and wp-content/cache/xspeed-static.', 'xspeed' );
			echo '</p></div>';
		}
		foreach ( $zones as $zone ) {
			echo '<div class="notice notice-warning"><p>';
			/* translators: %s: Cloudflare zone ID awaiting a full purge. */
			echo esc_html( sprintf( __( 'After the security update, a full Cloudflare purge has not been confirmed for zone %s. Purge everything for this zone through xSpeed or the Cloudflare dashboard. Automatic purge preferences have not been changed.', 'xspeed' ), $zone ) );
			echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="xspeed_confirm_security_edge_purge">';
			echo '<input type="hidden" name="xspeed_zone" value="' . esc_attr( $zone ) . '">';
			wp_nonce_field( 'xspeed_confirm_security_edge_purge_' . $zone );
			echo '<p><button type="submit" class="button">' . esc_html__( 'I have purged this zone in Cloudflare', 'xspeed' ) . '</button></p></form></div>';
		}
	}

	/** A changed zone or credential must not make an old warning impossible to resolve. */
	public function confirm_security_edge_purge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to confirm this purge.', 'xspeed' ), '', array( 'response' => 403 ) );
		}
		$zone = isset( $_POST['xspeed_zone'] ) && is_string( $_POST['xspeed_zone'] ) ? sanitize_text_field( wp_unslash( $_POST['xspeed_zone'] ) ) : '';
		$zones = self::security_edge_zones( get_option( 'xspeed_security_edge_pending', array() ) );
		if ( ! in_array( $zone, $zones, true ) ) {
			wp_die( esc_html__( 'This zone is not awaiting purge confirmation.', 'xspeed' ), '', array( 'response' => 400 ) );
		}
		// Binding the nonce to the pending zone prevents an old form clearing a newer warning.
		check_admin_referer( 'xspeed_confirm_security_edge_purge_' . $zone );
		self::clear_security_edge_pending( $zone );
		wp_safe_redirect( admin_url( 'admin.php?page=xspeed' ) );
		exit;
	}

	/**
	 * Phase 2 of plugin init: fire the registration action (collecting
	 * Free + Pro + any third-party modules hooked into
	 * `xspeed_register_modules`) and boot the registry.
	 *
	 * Runs at plugins_loaded(20) so every add-on that hooks at any
	 * priority < 20 has time to register first.
	 */
	public function fire_module_lifecycle(): void {
		$this->ensure_modules_registered();

		Module_Registry::boot_all();
	}

	/**
	 * Fire `xspeed_register_modules` if this request has not yet, hooking
	 * Free's own registration first when init() never got the chance.
	 *
	 * The activation request is the case that matters. activate_plugin()
	 * includes the plugin file long after `plugins_loaded` has fired, so the
	 * `plugins_loaded` callback init() would have added never runs, and
	 * neither does the add_action() inside it that puts register_free_modules
	 * on the action. Firing the action from activate() then registered
	 * nothing: Settings::conflict_safe_profile() composed from an empty
	 * registry, and a site with WP Super Cache came up with lazy-load,
	 * resource hints, font swapping and preloading switched on — only the
	 * four settings the registry-independent fallback names were held down
	 * (PR #295 review). Module_Registry::activate_all() has been a no-op on
	 * the same request for the same reason.
	 *
	 * On the activation request that means Free only: an add-on cannot have
	 * hooked yet, because xspeed-pro bails when Free's classes are absent and
	 * only hooks the action (at priority 20, from plugins_loaded(15)) once
	 * Free is active. On an ordinary request fire_module_lifecycle() reaches
	 * this at plugins_loaded(20) with every add-on already hooked. Do not
	 * call this from anything that can run in between: the action fires
	 * once, and an add-on that has not hooked yet stays unregistered for the
	 * whole request.
	 * did_action() keeps the action to one firing per request, so an
	 * activation that ran first does not make plugins_loaded(20) register
	 * every module a second time.
	 */
	public function ensure_modules_registered(): void {
		if ( did_action( 'xspeed_register_modules' ) ) {
			return;
		}

		/*
		 * register_free_modules() does an unconditional `new` on every module
		 * class. On an ordinary request xspeed.php's integrity check refuses
		 * to boot before that can fatal and explains itself in an admin
		 * notice; the activation hook is registered outside that check, so
		 * an install missing a module file (truncated zip, a security
		 * plugin's quarantine, a half-applied update) would fatal here with
		 * no notice and no active plugin. Same answer as boot: do nothing.
		 */
		if ( function_exists( 'xspeed_missing_core_classes' ) && ! empty( xspeed_missing_core_classes() ) ) {
			return;
		}

		if ( ! has_action( 'xspeed_register_modules', array( $this, 'register_free_modules' ) ) ) {
			add_action( 'xspeed_register_modules', array( $this, 'register_free_modules' ) );
		}

		/**
		 * Action: xspeed_register_modules
		 *
		 * Free modules register at priority 10; xspeed-pro at priority
		 * 20; site code can hook in between to inject custom modules.
		 * Fires exactly once per request.
		 */
		do_action( 'xspeed_register_modules' );
	}

	/**
	 * Register the Free Modules shipped in this plugin. Add new module
	 * registrations here. Pro plugin hooks the same action separately.
	 */
	public function register_free_modules(): void {
		Module_Registry::register( new \XSpeed\Modules\Cache\CacheModule() );
		Module_Registry::register( new \XSpeed\Modules\Health\HealthModule() );
		// Settings — owns no settings itself; it's the CLI/MCP surface over
		// Settings_Manager. Registering it is what makes `xspeed settings`
		// exist, which is what keeps the curated get_settings/update_settings
		// tools in the MCP catalog. (#149/#153)
		Module_Registry::register( new \XSpeed\Modules\Settings\SettingsModule() );
		// External performance scores (PSI / GTmetrix) — Free, off by
		// default. Rendered inside the Health host page's PageSpeed tab, so
		// it has no sidebar row of its own.
		Module_Registry::register( new \XSpeed\Modules\Score\ScoreModule() );
		Module_Registry::register( new \XSpeed\Modules\Preloader\PreloaderModule() );
		Module_Registry::register( new \XSpeed\Modules\Heartbeat\HeartbeatModule() );
		Module_Registry::register( new \XSpeed\Modules\Minify\MinifyModule() );
		Module_Registry::register( new \XSpeed\Modules\Gzip\GzipModule() );
		Module_Registry::register( new \XSpeed\Modules\Lazy\LazyModule() );
		Module_Registry::register( new \XSpeed\Modules\Bloat\BloatModule() );
		Module_Registry::register( new \XSpeed\Modules\Database\DatabaseModule() );
		Module_Registry::register( new \XSpeed\Modules\Cdn\CdnModule() );
		Module_Registry::register( new \XSpeed\Modules\Cloudflare\CloudflareModule() );
		Module_Registry::register( new \XSpeed\Modules\ObjectCache\ObjectCacheModule() );
		Module_Registry::register( new \XSpeed\Modules\BrowserCache\BrowserCacheModule() );
		// Advanced Cache — a Free container row that gathers the Pro
		// cache-coverage features (404 / search / feed / REST / rules /
		// maintenance) into one sidebar sub-item (FBS-83633).
		Module_Registry::register( new \XSpeed\Modules\CacheCoverage\CacheCoverageModule() );
		Module_Registry::register( new \XSpeed\Modules\Fonts\FontsModule() );
		Module_Registry::register( new \XSpeed\Modules\TurboRender\TurboRenderModule() );
		Module_Registry::register( new \XSpeed\Modules\ResourceHints\ResourceHintsModule() );
		// AI Privacy (GDPR off-switch) ships in Free even though every AI
		// *feature* is Pro — privacy is a right, not a paid tier. FEATURES.md
		// §AI row 6 mandates it. Without this registration the module was dead
		// code: no REST/settings surface, the promised off-switch unreachable
		// (FBS-83633 Bug 1). It carries its own cli_commands() so it satisfies
		// the CLI/MCP coverage guard once registered.
		Module_Registry::register( new \XSpeed\Modules\AIPrivacy\AIPrivacyModule() );
		// Migration moved Pro → Free: it's an acquisition/onboarding feature
		// (detect a competing caching plugin, import its settings, switch over),
		// so it must work without a Pro license. Agency-scale extras (profiles,
		// bulk multisite, host presets) remain Pro.
		Module_Registry::register( new \XSpeed\Modules\Migration\MigrationModule() );
		// Help & Support moved Pro → Free: a ticket link + read-only system
		// snapshot is onboarding/diagnostics, not a paid value-add, so every
		// user gets it. The snapshot degrades gracefully without Pro (Pro
		// version/license fields fall back to defaults via defined()/get_option).
		Module_Registry::register( new \XSpeed\Modules\Support\SupportModule() );
		// The dashboard control for usage-analytics consent. Consent used to
		// be collectable only in the wizard and withdrawable nowhere, while
		// the wizard and readme both promised a dashboard switch. (#437)
		Module_Registry::register( new \XSpeed\Modules\Privacy\PrivacyModule() );
		// MCP remote control (AI assistants) — Free. The plugin serves the
		// MCP protocol at the site's own /xspeed/mcp URL; the only gate is
		// the per-site connection token an admin mints via Connect. No
		// license, no hosted infra. See IMPLEMENTATION.md §17.
		Module_Registry::register( new \XSpeed\Modules\Mcp\McpModule() );
	}

	/**
	 * Boot the opt-in usage tracker. Registers the cron sender only; the send
	 * itself is consent-gated inside Usage_Tracker. The instance is held so the
	 * onboarding REST handler can flip consent via usage_tracker()->opt_in().
	 */
	public function start_plugin_tracking(): void {
		$this->usage_tracker = Usage_Tracker::get_instance(
			XSPEED_FILE,
			array(
				'opt_in'          => true,
				'email_marketing' => true,
				'item_id'         => defined( 'XSPEED_INSIGHTS_ITEM_ID' ) ? XSPEED_INSIGHTS_ITEM_ID : false,
			)
		);
		$this->usage_tracker->init();
	}

	/**
	 * The shared Usage_Tracker singleton (or null if tracking wasn't booted,
	 * e.g. on the activation hook before init() runs).
	 */
	public function usage_tracker(): ?Usage_Tracker {
		return $this->usage_tracker;
	}

	public static function activate() {
		Edge_Hold_Health::rotate();
		// Nothing below can see a module the registry does not hold — the
		// conflict-safe profile Settings::set_defaults() may pick is composed
		// from it, and activate_all() walks it. See ensure_modules_registered()
		// for why the registry is empty on this request without this call.
		self::instance()->ensure_modules_registered();

		$profile = Settings::set_defaults();

		// Score history table. Also called on admin_init (see init()) because
		// activation does not fire for a site added to a multisite network
		// later, nor after an update that ships a new schema version.
		Score_Store::maybe_install();

		if ( ! file_exists( XSPEED_CACHE_DIR ) ) {
			wp_mkdir_p( XSPEED_CACHE_DIR );
		}
		Cache::write_silence( XSPEED_CACHE_DIR );

		/*
		 * One exception to "caching is only ever enabled from the admin UI":
		 * a fresh install another plugin performed on the user's behalf.
		 *
		 * That plugin asked the user for site performance and installed us to
		 * provide it; making them go and find a second switch afterwards is
		 * a step nobody wants. It is also what the copy-vendored Setup::finish()
		 * did, so hosts migrating off it keep the behaviour they have.
		 *
		 * PROFILE_HOST_PAGE_CACHE is the whole condition, and it means three
		 * things at once: the install was genuinely fresh, nothing else owns
		 * the page cache, and another plugin claimed the install. Every other
		 * fresh install — including a user's own on a clear site — waits for
		 * the wizard. Every OTHER feature is off in this profile; the cache is
		 * the one thing a host may assume, because it is what it installed us
		 * for. And toggle() runs its own ownership transaction, so a
		 * competitor appearing between the two checks loses the race rather
		 * than being overwritten.
		 */
		if ( Settings::PROFILE_HOST_PAGE_CACHE === $profile ) {
			$state = Cache::toggle( true );
			if ( ! empty( $state['blocked'] ) ) {
				/*
				 * Refused after all — a competitor that appeared between the
				 * profile decision and the write, or a drop-in we could not
				 * install. The settings are identical either way (everything
				 * off), so the honest record is the one that does not claim a
				 * cache: conflict-safe is what the site actually got.
				 */
				$profile = Settings::PROFILE_CONFLICT_SAFE;
				update_option( 'xspeed_install_profile', $profile, false );
			}
		}

		/*
		 * Read the claim BEFORE spending it. consume_installed_by() records
		 * the installer only for a FRESH install — on a re-activation over
		 * settings that are already there it deletes the arming option and
		 * keeps nothing — so asking again afterwards answered "the user did
		 * it" about an install a host had just claimed, and the wizard opened
		 * over the host's own flow. The claim decides who to tell and whether
		 * to open the wizard; whether it is worth RECORDING is a separate
		 * question, and only the recording depends on the install being fresh.
		 */
		$installed_by = Settings::installed_by();

		// Spend the arming option now the profile is settled. It changes what
		// activation does, so it may not survive into the next one.
		Settings::consume_installed_by( $profile );

		// Caching is otherwise only ENABLED from the admin UI — see
		// Cache::toggle() and Rest_Api::toggle_cache(). A fresh install
		// therefore gets no drop-in and no wp-config.php edit here:
		// cache_enabled is unset, so the call below is a no-op.
		//
		// It is NOT a no-op during an upgrade. WordPress runs an update as
		// deactivate → wipe files → install → activate, which deletes
		// advanced-cache.php while cache_enabled stays true. Restoring it
		// here closes the window in which the site silently serves uncached
		// (auto_heal() alone only fires on the next wp-admin page load).
		Cache::restore_dropin_if_enabled();

		/*
		 * First-run wizard: flag a one-time redirect for the activating user.
		 * Suppressed for bulk activations / already-completed sites in
		 * Onboarding::maybe_redirect() — and here for an install another plugin
		 * performed, which has an onboarding flow of its own. The install runs
		 * over AJAX, so our redirect would fire on that admin's NEXT page load
		 * and pull them out of the middle of the host's wizard; finishing ours
		 * would then overwrite the deliberately all-off profile they never
		 * asked to change.
		 */
		if ( '' === $installed_by ) {
			Onboarding::flag_redirect();
		}

		// Propagate activation to every registered Module — registered by
		// ensure_modules_registered() at the top, not by plugins_loaded,
		// which fired before this file was even included.
		Module_Registry::activate_all();

		/**
		 * Fires at the end of activation, once the settings profile is decided.
		 *
		 * The other half of the host-plugin contract: a plugin that installed
		 * xSpeed for the user writes `xspeed_installed_by` before activating
		 * and listens here to find out how it came up. It carries no return
		 * value and nothing branches on it — a host that ignores it changes
		 * nothing about the install.
		 *
		 * @param string $installed_by Host slug, or '' when the user did it.
		 * @param string $profile      Settings::PROFILE_* — which profile a fresh
		 *                             install came up with, '' if not fresh.
		 */
		do_action( 'xspeed_activated', $installed_by, $profile );

		/*
		 * Announce the change to anything caching in front of us.
		 *
		 * Activation writes the default settings and activates modules, so
		 * every URL on the site starts returning different HTML. A CDN
		 * holding renders from before goes on serving them for their whole
		 * lifetime, and nothing told it.
		 *
		 * Cheap on a fresh install — there is nothing to sweep — and the
		 * case it exists for is reactivation on a site that has been running
		 * for months behind an edge.
		 */
		Cache::purge_all( 'plugin activated' );
	}

	/**
	 * Restore the cache drop-in right after THIS plugin is updated.
	 *
	 * Bound to upgrader_process_complete. Bulk updates, auto-updates and
	 * host-level updaters finish without re-running activate(), so this is
	 * the only hook that repairs the drop-in before the next wp-admin page
	 * load. Narrow by design: bails unless the completed action was a
	 * plugin update whose payload actually includes xspeed.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance (unused).
	 * @param array        $hook_extra Contextual data about the update.
	 * @return void
	 */
	public function maybe_restore_after_update( $upgrader, $hook_extra ) {
		unset( $upgrader );

		if ( ! is_array( $hook_extra ) ) {
			return;
		}
		if ( ! isset( $hook_extra['type'], $hook_extra['action'] ) ) {
			return;
		}
		if ( 'plugin' !== $hook_extra['type'] || 'update' !== $hook_extra['action'] ) {
			return;
		}

		// Single update uses 'plugin'; bulk uses 'plugins'.
		$updated = array();
		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$updated = $hook_extra['plugins'];
		} elseif ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$updated = array( $hook_extra['plugin'] );
		}

		$ours = plugin_basename( XSPEED_FILE );
		if ( ! in_array( $ours, $updated, true ) ) {
			return;
		}

		Cache::restore_dropin_if_enabled();
	}

	/**
	 * @param bool $network_wide Whether a network admin deactivated the
	 *                           plugin for every site (WordPress passes this
	 *                           to deactivation hooks).
	 */
	public static function deactivate( $network_wide = false ) {
		// Drop-in + WP_CACHE constant are NOT touched here. WordPress
		// upgrades run as deactivate → wipe files → install → activate,
		// so removing those artifacts on every deactivate would silently
		// disable caching after each plugin update. uninstall.php
		// handles full teardown when the user actually removes the
		// plugin; auto_heal() restores state on the next admin_init if
		// the drop-in or WP_CACHE went missing for any other reason.
		Cache::purge_all();
		// purge_all() is site-scoped and no longer touches min/, so clear it
		// here. On a network where only this site is deactivating, the other
		// sites still link those files: drop this site's manifests only.
		if ( is_multisite() && ! $network_wide ) {
			Minifier::purge_manifests( Asset_Manifest::blog_id() );
		} else {
			Minifier::purge_minified();
		}
		Gzip::apply( false );

		Module_Registry::deactivate_all();

		// The drop-in stays, but it must stop serving: with the plugin off,
		// nothing purges a page when its post changes (#597). The next
		// request that boots the plugin writes the flag again. Last, because
		// a module's deactivate() may reinstall the drop-in, and installing
		// writes the flag.
		Cache::clear_active_flag();
	}
}
