<?php
/**
 * Settings handling.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION_KEY = 'xspeed_options';

	/**
	 * Set by a plugin that installs xSpeed on a user's behalf, BEFORE it
	 * activates it.
	 *
	 * A plain option rather than a constant, a filter or a class: the host
	 * writes it at a moment when no xSpeed code has loaded and none can be
	 * relied on to exist, so `update_option( 'xspeed_installed_by', 'my-slug' )`
	 * is the whole contract. It survives into the activation request, which is
	 * where it is read.
	 */
	const INSTALLED_BY_OPTION = 'xspeed_installed_by';

	/**
	 * Where the slug lives once activation has consumed it.
	 *
	 * The option above is a one-shot TRIGGER, not a record: it changes what
	 * activation does, so leaving it on disk means the next activation is a
	 * host install too. A host whose install fails between writing it and
	 * activating us would otherwise arm a plain user activation to write
	 * advanced-cache.php and edit wp-config.php — for ever. Activation moves
	 * the value here and deletes the trigger, so a stale one is spent by the
	 * first activation that sees it rather than every one after.
	 */
	const INSTALLER_OPTION = 'xspeed_installer';

	/** Which profile a fresh install came up with. Read back by Host::status(). */
	const PROFILE_OPTION = 'xspeed_install_profile';

	/** Nothing was decided — the option already existed, so this is not a fresh install. */
	const PROFILE_NONE = '';
	/** A clear site the user installed themselves: the Balanced set. */
	const PROFILE_RECOMMENDED = 'recommended';
	/** Something else owns the page cache: everything off. */
	const PROFILE_CONFLICT_SAFE = 'conflict-safe';
	/** A clear site, installed by another plugin: everything off, page cache on. */
	const PROFILE_HOST_PAGE_CACHE = 'host-page-cache';

	public static function defaults() {
		// Migrated out of this legacy blob (now per-module storage):
		//   - minify_html / minify_css / minify_js → xspeed_module_minify
		//   - gzip_enabled                         → xspeed_module_gzip
		//   - cache_expiry / excluded_urls         → xspeed_module_cache
		// Still here (intentionally, drop-in lifecycle):
		//   - cache_enabled (Cache::toggle owns the .htaccess/wp-config edit)
		return array(
			'cache_enabled' => false,
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function update( array $input ) {
		$current = self::get();
		$clean   = $current;

		// Every former field is now in per-module storage:
		//   - minify_* → MinifyModule, gzip_enabled → GzipModule,
		//     cache_expiry / excluded_urls → CacheModule.
		// Only cache_enabled lives on here, owned by Cache::toggle's
		// drop-in lifecycle. All other writes are silently ignored to
		// keep duplicate sources from re-forming.
		if ( isset( $input['cache_enabled'] ) ) {
			$clean['cache_enabled'] = (bool) $input['cache_enabled'];
		}

		update_option( self::OPTION_KEY, $clean );
		return $clean;
	}

	/**
	 * Decide how a fresh install comes up.
	 *
	 * @return string One of the PROFILE_* constants. PROFILE_NONE means this
	 *                was not a fresh install and nothing was decided.
	 */
	public static function set_defaults(): string {
		if ( false !== get_option( self::OPTION_KEY ) ) {
			return self::PROFILE_NONE;
		}

		add_option( self::OPTION_KEY, self::defaults() );

		/*
		 * Only a genuinely fresh install reaches this branch — the option
		 * survives deactivation, so an upgrade (deactivate → wipe → install →
		 * activate) always finds it present.
		 *
		 * Three ways a fresh install can come up.
		 *
		 * Something else owns the page cache: everything off. The user
		 * installed xSpeed beside a cache plugin they are still using, and
		 * rewriting their markup on activation is not what they asked for.
		 *
		 * Nothing owns it and another plugin installed us: everything off
		 * EXCEPT page caching. That plugin asked the user for site speed and
		 * installed us to provide it, so the cache is the one thing it may
		 * assume — and nothing else, because the user never saw our settings
		 * and did not ask for lazy loading or minification. It is also what
		 * the copy-vendored Setup wrote by hand before every host install, so
		 * this is that behaviour moving to the side that owns the settings.
		 *
		 * Nothing owns it and the user installed us themselves: the Balanced
		 * set, and they choose page caching in the wizard.
		 */
		$state = self::page_cache_occupant_state();
		if ( null !== $state ) {
			$written = self::apply_conflict_safe_profile();

			/**
			 * Fires when a fresh install came up with everything switched off
			 * because something else owns the page cache.
			 *
			 * The host plugin that installed xSpeed uses this to tell the user
			 * what happened; nothing here renders a notice on its behalf.
			 *
			 * @param array  $written Module slug => the fields set to false.
			 * @param string $state   The detector's ownership state.
			 * @param string $host    Whoever claimed the install, or ''.
			 */
			do_action( 'xspeed_conflict_profile_applied', $written, $state, self::installed_by() );
			update_option( self::PROFILE_OPTION, self::PROFILE_CONFLICT_SAFE, false );
			return self::PROFILE_CONFLICT_SAFE;
		}

		if ( '' !== self::installed_by() ) {
			// The same sweep the occupied site gets — every Free bool off —
			// because "the user did not ask for it" is the same fact in both
			// cases. Only what happens to page caching differs, and that is
			// Plugin::activate()'s call, not a settings write.
			self::apply_conflict_safe_profile();
			update_option( self::PROFILE_OPTION, self::PROFILE_HOST_PAGE_CACHE, false );
			return self::PROFILE_HOST_PAGE_CACHE;
		}

		self::seed_recommended_modules();
		update_option( self::PROFILE_OPTION, self::PROFILE_RECOMMENDED, false );
		return self::PROFILE_RECOMMENDED;
	}

	/** Which profile the fresh install came up with, or '' if we never decided. */
	public static function install_profile(): string {
		$profile = get_option( self::PROFILE_OPTION, self::PROFILE_NONE );
		return is_string( $profile ) ? $profile : self::PROFILE_NONE;
	}

	/**
	 * Who installed xSpeed, or '' when the user did it themselves.
	 *
	 * A slug the host plugin chose — 'essential-addons', 'templately'. Not
	 * validated against a list: this is provenance for a message, never an
	 * authorization, and a host we have never heard of is still allowed to
	 * say who it is.
	 */
	public static function installed_by(): string {
		$recorded = get_option( self::INSTALLER_OPTION, '' );
		if ( is_string( $recorded ) && '' !== $recorded ) {
			return sanitize_key( $recorded );
		}
		$slug = get_option( self::INSTALLED_BY_OPTION, '' );
		return is_string( $slug ) ? sanitize_key( $slug ) : '';
	}

	/**
	 * Spend the trigger: record who installed us, and clear the arming option.
	 *
	 * Called once, by Plugin::activate(), after the profile is decided — the
	 * decision reads the trigger, so it cannot be cleared before then.
	 *
	 * @param string $profile The Settings::PROFILE_* this activation chose.
	 */
	public static function consume_installed_by( string $profile ): void {
		$slug = get_option( self::INSTALLED_BY_OPTION, '' );
		$slug = is_string( $slug ) ? sanitize_key( $slug ) : '';

		if ( '' !== $slug && self::PROFILE_NONE !== $profile ) {
			// A fresh install someone claimed. Worth keeping: it is what
			// Host::status() reports and what support reads to know whether a
			// site's settings were chosen by a person.
			update_option( self::INSTALLER_OPTION, $slug, false );
		}

		delete_option( self::INSTALLED_BY_OPTION );
	}

	/**
	 * Does something other than xSpeed own this site's page cache?
	 *
	 * Deliberately NOT `! can_acquire()`. That helper refuses on anything it
	 * cannot verify, which is right for a write that could destroy another
	 * plugin's drop-in — but wrong for choosing a settings profile. An
	 * unreadable wp-config.php would then hand an ordinary site an xSpeed with
	 * every optimisation off and nothing on screen to explain it.
	 *
	 * So this asks the narrower question: is there positive evidence of
	 * somebody else? `unavailable` is not evidence, and neither is a residual
	 * artifact from a plugin that is gone. The write paths keep failing closed
	 * on both.
	 *
	 * `unknown-occupied` counts only when a drop-in is actually there. The
	 * same state also covers `WP_CACHE` left true in wp-config.php with no
	 * drop-in at all — a line a removed cache plugin forgot — and nothing is
	 * serving cached pages then. Treating that as an occupant gave a clean
	 * site an xSpeed with every switch off while the wizard said "No other
	 * caching plugins detected" (PR #295 review). The write path still refuses
	 * it, because it cannot know what set the constant.
	 */
	private static function page_cache_occupant_state(): ?string {
		if ( ! class_exists( __NAMESPACE__ . '\\Page_Cache_Detector' ) ) {
			return null;
		}

		$report = Page_Cache_Detector::inspect();
		$state  = Page_Cache_Detector::classify( $report )['state'];

		if ( Page_Cache_Detector::STATE_UNKNOWN_OCCUPIED === $state ) {
			return ! empty( $report['dropin']['exists'] ) ? $state : null;
		}

		return in_array(
			$state,
			array(
				Page_Cache_Detector::STATE_FOREIGN_LIVE,
				Page_Cache_Detector::STATE_POSSIBLE_LIVE,
				Page_Cache_Detector::STATE_CONTESTED,
			),
			true
		) ? $state : null;
	}

	/**
	 * Settings a fresh install starts with, beyond each module's schema
	 * default. Mirrors the wizard's "Balanced" preset — the profile the
	 * product already labels "Recommended for most sites".
	 *
	 * Why this exists: the wizard is skippable, and a WP-CLI or bulk
	 * activation never shows it at all. Those users fell through to the raw
	 * schema defaults, which are more conservative than what we recommend to
	 * the very same site — so whether a site compressed its responses came
	 * down to whether someone clicked through a wizard. Measured on a real
	 * install: gzip off, browser-cache headers off, no minification, while
	 * lazy-load and resource hints (schema default `true`) were on.
	 *
	 * Deliberately excluded: `minify_js`, `defer_js`, `combine_css`,
	 * `combine_js`, `delay_js`. Each can break a theme, and a default that
	 * breaks the site is worse than a default that is merely slow. They stay
	 * opt-in via the wizard's Aggressive preset or the dashboard.
	 *
	 * Public because the portable Setup copy mirrors it: these four are
	 * written by activation rather than declared as schema defaults, so
	 * scanning the schemas alone misses the four settings a user is most
	 * likely to notice.
	 *
	 * @return array<string,array<string,bool>> module slug => settings
	 */
	public static function recommended_module_settings(): array {
		return array(
			// Compression — the single largest byte win, and inert until a
			// server actually supports it (GzipModule writes .htaccess only
			// where supports_htaccess() is true, and emits a snippet
			// otherwise).
			'gzip'          => array( 'gzip_enabled' => true ),
			// Far-future caching for static assets. Only ever affects
			// css/js/images/fonts; HTML keeps its own short TTL.
			'browser-cache' => array( 'enabled' => true ),
			// HTML + CSS minification. Both are whitespace/comment-level
			// and do not reorder or combine anything, so they carry none of
			// the cascade risk that combine_css does.
			'minify'        => array(
				'minify_html' => true,
				'minify_css'  => true,
			),
		);
	}

	/**
	 * The settings a site gets when something else owns the page cache.
	 *
	 * Refusing the page cache is only half of "install beside a caching plugin
	 * and do nothing". Two things put the other half back on:
	 *
	 * - recommended_module_settings() above, which activation writes TRUE;
	 * - an ABSENT option row, which is not off. Settings_Manager merges each
	 *   module's schema defaults, so `lazy` with no row reads back with four
	 *   switches ON, and resource-hints, fonts and preloader likewise.
	 *
	 * So this is composed from the live registry rather than a hand-kept list:
	 * every bool of every registered FREE module, minus what that module names
	 * in Module::conflict_safe_exempt(). Every bool, not only the ones
	 * defaulting on — a default that moves in a later release must not switch
	 * something on behind the user.
	 *
	 * Pro is out of scope: its modules sit behind a licence and their own
	 * enable switches, and writing rows for a plugin that may never be
	 * installed would record decisions on someone else's behalf.
	 *
	 * @return array<string,array<string,bool>> module slug => field => false
	 */
	public static function conflict_safe_profile(): array {
		$out = array();

		foreach ( self::registered_modules() as $slug => $module ) {
			if ( Module::TIER_FREE !== $module::TIER ) {
				continue;
			}

			$exempt = $module->conflict_safe_exempt();

			foreach ( $module->settings_schema() as $field => $spec ) {
				if ( 'bool' !== ( $spec['type'] ?? '' ) || in_array( $field, $exempt, true ) ) {
					continue;
				}
				$out[ $slug ][ $field ] = false;
			}
		}

		// The sweep above already covers these as ordinary bools. Naming them
		// again keeps the two lists in step if the recommended profile ever
		// grows a field no schema declares.
		foreach ( self::recommended_module_settings() as $slug => $values ) {
			foreach ( array_keys( $values ) as $field ) {
				$out[ $slug ][ $field ] = false;
			}
		}

		ksort( $out );
		foreach ( $out as $slug => $values ) {
			ksort( $values );
			$out[ $slug ] = $values;
		}

		return $out;
	}

	/**
	 * Write that profile, preserving every key it does not name.
	 *
	 * Merged rather than replaced: option rows outlive plugin deletion, so a
	 * site can still carry lazy-load exclusions or a preloader sitemap from an
	 * earlier install, and there is no reason to destroy those to turn
	 * switches off.
	 *
	 * @return array<string,array<string,bool>> What was written, by slug.
	 */
	public static function apply_conflict_safe_profile(): array {
		$written = array();

		foreach ( self::conflict_safe_profile() as $slug => $values ) {
			$option = 'xspeed_module_' . $slug;
			$stored = get_option( $option, null );
			$stored = is_array( $stored ) ? $stored : array();
			$next   = array_merge( $stored, $values );

			if ( $next === $stored ) {
				continue;
			}

			update_option( $option, $next, false );
			$written[ $slug ] = $values;
		}

		return $written;
	}

	/**
	 * Every registered module, registering them first when the registry is
	 * empty.
	 *
	 * On the activation request nothing has registered yet, and an empty
	 * registry would compose an empty profile — a silent pass that leaves
	 * every switch on. Firing `xspeed_register_modules` from here was not
	 * enough: init() never ran on that request, so Free's own callback was
	 * not on the action and the firing registered nothing. Plugin owns the
	 * repair — see Plugin::ensure_modules_registered().
	 *
	 * That repair fires the action once per request. Reaching this before
	 * plugins_loaded(20) from anywhere other than activation would register
	 * Free and lock every add-on out for the request; activation is the only
	 * caller, and it must stay that way.
	 *
	 * @return array<string,Module>
	 */
	private static function registered_modules(): array {
		$modules = Module_Registry::all();
		if ( ! empty( $modules ) ) {
			return $modules;
		}

		Plugin::instance()->ensure_modules_registered();

		return Module_Registry::all();
	}

	/**
	 * Write the recommended defaults for a fresh install, without ever
	 * overwriting a value the user has already chosen.
	 *
	 * Each key is written only when it is absent from stored settings, so
	 * this stays safe if it is ever reached on a site that has some — but
	 * not all — module options saved.
	 */
	private static function seed_recommended_modules(): void {
		foreach ( self::recommended_module_settings() as $slug => $values ) {
			$key    = 'xspeed_module_' . $slug;
			$stored = get_option( $key, null );
			$stored = is_array( $stored ) ? $stored : array();

			$next = $stored;
			foreach ( $values as $setting => $value ) {
				if ( array_key_exists( $setting, $stored ) || self::seed_is_refused( $slug, $setting ) ) {
					continue;
				}
				$next[ $setting ] = $value;
			}
			if ( $next !== $stored ) {
				update_option( $key, $next, false );
			}
		}
	}

	/**
	 * Would the dashboard refuse this setting right now?
	 *
	 * A recommended seed is a switch the user never touched, so it must not
	 * turn on what the conflict matrix would refuse to let them turn on: a
	 * fresh install beside Autoptimize seeded HTML and CSS minification while
	 * the same request could already say "Autoptimize is active and handles
	 * the same feature" (PR #295 review). Only settings with a feature key in
	 * the matrix are checked; compression and browser caching have none.
	 */
	private static function seed_is_refused( string $slug, string $setting ): bool {
		$keys = array(
			'minify' => array(
				'minify_html' => 'minify.html',
				'minify_css'  => 'minify.css',
			),
		);
		$feature = $keys[ $slug ][ $setting ] ?? null;
		if ( null === $feature || ! class_exists( __NAMESPACE__ . '\\Conflict_Registry' ) ) {
			return false;
		}
		return Conflict_Registry::STRATEGY_REFUSE === Conflict_Registry::strategy_for( $feature );
	}

	// sanitize_urls() removed — excluded_urls now owned by CacheModule
	// and validated by Settings_Manager's typed schema (list / item_type).
}
