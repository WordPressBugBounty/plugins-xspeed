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
	public const DATA_VERSION = '1.1.6';

	private static $instance = null;

	/** @var Usage_Tracker|null */
	private $usage_tracker = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
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
		// admin only — nothing here needs to touch a front-end request.
		if ( is_admin() ) {
			add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 21 );
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

		// 1.1.2 — strip credential values recorded by earlier versions'
		// settings change annotations (they're served by the trend endpoints).
		Activity_Log::redact_legacy_secrets();

		// 1.1.4 — earlier versions cached a failed loopback as "gzip is not
		// active" for an hour, which showed up as a bogus server-config
		// warning. Drop the stale answer so the fixed probe re-runs instead
		// of the wrong verdict living on past the update (issue #18).
		delete_transient( 'xspeed_gzip_active' );

		// 1.1.6 — a `/?s=<term>` request used to write its results page into
		// the static tree under the *searched-from* path, which for the usual
		// query-form search is `/`. The web server then served that results
		// page as the homepage to every visitor. The write is fixed in
		// Cache::store_static(), but an entry poisoned before the update
		// outlives it: nothing purges on upgrade, and the static serve path
		// never revalidates. Clear the tree once. The flat cache is keyed
		// correctly and is deliberately left alone. (issue #191)
		Cache::purge_static_tree();

		update_option( 'xspeed_data_version', self::DATA_VERSION, false );
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
				'opt_in'  => true,
				'item_id' => defined( 'XSPEED_INSIGHTS_ITEM_ID' ) ? XSPEED_INSIGHTS_ITEM_ID : false,
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

	public static function deactivate() {
		// Drop-in + WP_CACHE constant are NOT touched here. WordPress
		// upgrades run as deactivate → wipe files → install → activate,
		// so removing those artifacts on every deactivate would silently
		// disable caching after each plugin update. uninstall.php
		// handles full teardown when the user actually removes the
		// plugin; auto_heal() restores state on the next admin_init if
		// the drop-in or WP_CACHE went missing for any other reason.
		Cache::purge_all();
		Minifier::purge_minified();
		Gzip::apply( false );

		Module_Registry::deactivate_all();
	}
}
