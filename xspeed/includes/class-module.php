<?php
/**
 * Module abstract base class.
 *
 * Every feature in xSpeed (Free or Pro) extends this class. The contract is
 * documented in IMPLEMENTATION.md §1.1. A Module is a self-contained unit
 * that declares its tier, settings schema, REST routes, UI panels, CLI
 * commands, conflicts, and lifecycle hooks in one place — so moving a
 * feature between Free and Pro is a `git mv` + flipping the TIER constant,
 * with no call-site changes.
 *
 * Concrete modules MUST:
 *   - Set the SLUG class constant.
 *   - Set the TIER class constant (TIER_FREE or TIER_PRO).
 *   - Set the VERSION class constant.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

abstract class Module {

	public const TIER_FREE = 'free';
	public const TIER_PRO  = 'pro';

	/**
	 * Concrete modules override these three constants.
	 */
	public const SLUG    = '';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	/**
	 * Other module slugs this module needs at boot. Resolved by
	 * Module_Registry via topological sort; missing deps fail loudly.
	 *
	 * @return string[]
	 */
	public function dependencies(): array {
		return array();
	}

	/**
	 * Typed settings schema. See Settings_Manager::validate() for the
	 * supported `type` values (bool, int, string, enum, list, url). Each
	 * field declares `default` and optional `min` / `max` / `options` /
	 * `item_type`. Storage key is `xspeed_module_<slug>`.
	 *
	 * A field may also declare `constants` -- an ordered list of wp-config.php
	 * constant names that pin its value, most specific first:
	 *
	 *     'redis_host' => array(
	 *         'type'      => 'string',
	 *         'default'   => '127.0.0.1',
	 *         'constants' => array( 'XSPEED_OC_HOST', 'WP_REDIS_HOST' ),
	 *     ),
	 *
	 * Resolution order is then: first DEFINED constant -> stored option ->
	 * `default`. A constant defined as an empty string still wins -- it is an
	 * answer, not an absence. A pinned field is never persisted, and writes
	 * targeting it are refused rather than silently dropped; see
	 * Settings_Manager::origins() / locked_in_input(). This lets a host
	 * (xCloud provisioning Redis) configure a module with no admin visit. (#398)
	 *
	 * @return array<string,array>
	 */
	public function settings_schema(): array {
		return array();
	}

	/**
	 * Settings this module keeps at their own defaults when xSpeed cannot own
	 * the page cache, even though they default to ON.
	 *
	 * Settings::conflict_safe_profile() switches off every bool so a
	 * site that already has a caching plugin gets an xSpeed that does nothing
	 * until asked. Two kinds of setting do not belong in that sweep: one where
	 * OFF is the wrong answer (a consent requirement), and one that cannot act
	 * at all while the feature above it is off, where writing false would
	 * suggest a decision nobody made.
	 *
	 * Naming a field here is a decision, not a default: the sweep covers every
	 * bool, so a field is only left alone because someone said so.
	 *
	 * @return string[] Field names from this module's settings_schema().
	 */
	public function conflict_safe_exempt(): array {
		return array();
	}

	/**
	 * Option keys a module stores OUTSIDE its settings_schema that must
	 * survive a schema-driven save. Settings_Manager rebuilds the option
	 * from the schema on get()/update(), which would otherwise drop these.
	 * Example: the REST-cache module keeps its route `rules` array here so a
	 * plain enabled/ttl save doesn't wipe the rules table. (FBS-82408)
	 *
	 * @return string[]
	 */
	public function preserved_keys(): array {
		return array();
	}

	/**
	 * Schema migrations keyed by target version. Each value is a callable
	 * that receives the stored options array and returns the migrated
	 * array. Migrations run in version order on first load after upgrade.
	 *
	 * @return array<string,callable>
	 */
	public function migrations(): array {
		return array();
	}

	/**
	 * REST routes the module owns. Paths are prefixed with
	 * `/xspeed/v1/<slug>/` by Rest_Manager; declare without the prefix.
	 * `permission_callback` is wrapped automatically with a final cap
	 * check + tier gate, so modules don't need to repeat that boilerplate
	 * — but they MUST still declare a sensible callback.
	 *
	 * Default impl returns the standard GET + POST pair for modules that
	 * declare a settings_schema. Modules that need extra endpoints can
	 * extend the array. Modules with truly custom REST should override
	 * entirely and skip parent::rest_routes().
	 *
	 * Per SETTINGS.md §5.1 every module's settings live at:
	 *   GET  /xspeed/v1/<slug>/   → current settings
	 *   POST /xspeed/v1/<slug>/   → partial patch, returns updated settings
	 *
	 * @return array[]
	 */
	public function rest_routes(): array {
		if ( empty( $this->settings_schema() ) ) {
			return array();
		}
		return array(
			array(
				'path'     => '/',
				'methods'  => 'GET',
				'callback' => array( $this, 'rest_get_settings' ),
			),
			array(
				'path'     => '/',
				'methods'  => 'POST',
				'callback' => array( $this, 'rest_update_settings' ),
				'feature'  => static::SLUG,
			),
			// Take a constant-pinned field back, or hand it to wp-config again.
			// On every module, because any field can declare `constants`. (#398)
			array(
				'path'     => '/override',
				'methods'  => 'POST',
				'callback' => array( $this, 'rest_set_override' ),
				'feature'  => static::SLUG,
			),
			// Per-field provenance on its own, for a panel that needs to
			// re-read it after an action that changes which fields are pinned
			// (enabling the object cache writes constants) without re-fetching
			// every module descriptor. (#398)
			array(
				'path'     => '/origins',
				'methods'  => 'GET',
				'callback' => array( $this, 'rest_get_origins' ),
			),
		);
	}

	/**
	 * Where each of this module's settings currently comes from.
	 */
	public function rest_get_origins( \WP_REST_Request $request ) {
		return rest_ensure_response( Settings_Manager::origins( static::SLUG ) );
	}

	/**
	 * Toggle a deliberate override of a constant-pinned field.
	 *
	 * Body: `{ "field": "redis_host", "override": true }`. Overriding does not
	 * itself set a value -- it unlocks the field, and the admin's next save
	 * writes it like any other setting. Reverting hands the field back to the
	 * constant, which resumes winning immediately.
	 */
	public function rest_set_override( \WP_REST_Request $request ) {
		if ( $this->is_license_locked() ) {
			return new \WP_Error(
				'xspeed_license_required',
				sprintf(
					/* translators: %s: module slug. */
					__( '"%s" is a Pro module and this site has no valid license, so its settings cannot be changed.', 'xspeed' ),
					static::SLUG
				),
				array( 'status' => 403 )
			);
		}

		$body  = (array) $request->get_json_params();
		$field = isset( $body['field'] ) ? (string) $body['field'] : '';
		$on    = ! empty( $body['override'] );

		$spec = $this->settings_schema()[ $field ] ?? null;
		if ( ! is_array( $spec ) ) {
			return new \WP_Error(
				'xspeed_unknown_setting',
				sprintf(
					/* translators: %s: setting key. */
					__( 'Unknown setting: %s', 'xspeed' ),
					$field
				),
				array( 'status' => 400 )
			);
		}

		// Overriding a field no constant pins is meaningless -- it is already
		// editable -- and would leave a stale entry that silently disables the
		// lock if a constant appeared later.
		if ( $on && null === Settings_Manager::constant_source( $spec ) ) {
			return new \WP_Error(
				'xspeed_setting_not_pinned',
				sprintf(
					/* translators: %s: setting key. */
					__( '"%s" is not defined in wp-config.php, so there is nothing to override.', 'xspeed' ),
					$field
				),
				array( 'status' => 409 )
			);
		}

		if ( $on ) {
			Settings_Manager::set_override( static::SLUG, $field, true );
		} else {
			/*
			 * The full hand-back, not just dropping the override entry. Once we
			 * have written our own define it outranks the host's, so clearing
			 * the entry alone left the field on our stale value with no route
			 * back -- the panel button did nothing while `wp xspeed objcache
			 * revert`, which did the extra work inline, worked. Both now go
			 * through Settings_Manager::revert(). (#398)
			 */
			$reverted = Settings_Manager::revert( static::SLUG, $field );
			if ( is_wp_error( $reverted ) ) {
				return $reverted;
			}
		}

		if ( class_exists( '\\XSpeed\\Activity_Log' ) ) {
			Activity_Log::record(
				$on ? 'setting_override_taken' : 'setting_override_reverted',
				sprintf(
					$on
						/* translators: 1: setting key, 2: module slug. */
						? __( 'Overrode "%1$s" on "%2$s" — the wp-config.php value no longer applies.', 'xspeed' )
						/* translators: 1: setting key, 2: module slug. */
						: __( 'Reverted "%1$s" on "%2$s" to the value set in wp-config.php.', 'xspeed' ),
					$field,
					static::SLUG
				),
				Activity_Log::INFO
			);
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'field'    => $field,
				'override' => $on,
				'settings' => Settings_Manager::get_public( static::SLUG ),
				'origins'  => Settings_Manager::origins( static::SLUG ),
			)
		);
	}

	/**
	 * Default GET handler — returns all settings (defaults + stored)
	 * coerced against the schema, with secret fields masked. Uses the public
	 * view (not get_settings()) so a credential never leaves in a REST payload;
	 * the engine reads real values through get_settings()/get_setting(). (#115)
	 * Modules can override but rarely need to.
	 */
	public function rest_get_settings( \WP_REST_Request $request ) {
		return rest_ensure_response( Settings_Manager::get_public( static::SLUG ) );
	}

	/**
	 * Default POST handler — validates the JSON body against the
	 * schema, persists, returns the post-update settings. Unknown keys
	 * are stripped by Settings_Manager.
	 */
	public function rest_update_settings( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		// Say so, rather than returning 200 over a write that didn't happen.
		// update_settings() enforces the gate on every surface; REST is the
		// one with an error channel, so it reports the reason. (#143)
		if ( $this->is_license_locked() ) {
			return new \WP_Error(
				'xspeed_license_required',
				sprintf(
					/* translators: %s: module slug. */
					__( '"%s" is a Pro module and this site has no valid license, so its settings cannot be changed.', 'xspeed' ),
					static::SLUG
				),
				array( 'status' => 403 )
			);
		}

		// A field pinned by a wp-config.php constant cannot be written. Saying
		// so beats a 200 over a write that silently did nothing -- and naming
		// the constant tells the caller where to go and change it. (#398)
		$locked = Settings_Manager::locked_in_input( static::SLUG, is_array( $params ) ? $params : array() );
		if ( ! empty( $locked ) ) {
			$pairs = array();
			foreach ( $locked as $field => $constant ) {
				$pairs[] = $field . ' (' . $constant . ')';
			}
			return new \WP_Error(
				'xspeed_setting_defined_in_wp_config',
				sprintf(
					/* translators: %s: comma-separated list of "field (CONSTANT_NAME)" pairs. */
					__( 'These settings are defined in wp-config.php and cannot be changed here: %s. Edit the constant, or remove it to manage the setting from this screen.', 'xspeed' ),
					implode( ', ', $pairs )
				),
				array(
					'status' => 409,
					'fields' => $locked,
				)
			);
		}

		return rest_ensure_response( $this->update_settings( $params ) );
	}

	/**
	 * Is this a Pro module whose settings are locked for want of a licence?
	 *
	 * Shared by the REST handler and the write guard so the two can never
	 * disagree about what is locked.
	 */
	final public function is_license_locked(): bool {
		// The licence module itself must stay writable — otherwise an expired
		// licence locks the user out of the very screen where a new key is
		// entered.
		if ( self::TIER_PRO !== $this->tier() || 'license' === static::SLUG ) {
			return false;
		}

		/**
		 * Filter: xspeed_pro_licensed
		 *
		 * Answered by xspeed-pro — the same filter it already answers when
		 * decorating module descriptors with the `locked` flag, so the write
		 * gate and the UI lock can't disagree.
		 *
		 * Defaults to true so a Free-only install (where nothing hooks this)
		 * is never gated by a question no one is present to answer.
		 *
		 * @param bool $licensed Whether Pro is licensed right now.
		 */
		return ! (bool) apply_filters( 'xspeed_pro_licensed', true );
	}

	/**
	 * UI panel declarations consumed by the React dashboard via the
	 * bootstrap payload. Each entry: [
	 *   'section'   => 'cache' | 'performance' | 'images' | ...,
	 *   'position'  => int,
	 *   'component' => 'HealthCard' | 'TogglesList' | 'StatGrid' | 'Custom',
	 *   'props'     => array,
	 * ]
	 *
	 * @return array[]
	 */
	public function ui_panels(): array {
		return array();
	}

	/**
	 * Sidebar / dashboard metadata. The React app uses these to render the
	 * module's nav entry. Override per module to set a friendly label and
	 * a lucide-react icon name (must be in the renderer's icon whitelist —
	 * see src/components/IconResolver.tsx).
	 *
	 * @return array{label:string,icon:string,description?:string,hidden?:bool}
	 */
	public function ui_metadata(): array {
		return array(
			'label' => ucfirst( str_replace( '_', ' ', static::SLUG ) ),
			'icon'  => 'Square',
		);
	}

	/**
	 * Dynamic in-panel notices (callouts) rendered above the schema form.
	 * Computed fresh on every dashboard load. Examples: nginx GZIP
	 * snippet when the server can't be auto-configured, "drop-in
	 * missing" warning when cache_enabled but no advanced-cache.php.
	 *
	 * Each entry: [
	 *   'tone'    => 'info' | 'warn' | 'danger' | 'success',
	 *   'title'   => 'Short heading.',
	 *   'body'    => 'One- or two-sentence explanation.',
	 *   'snippet' => 'Optional verbatim code snippet rendered in a
	 *                <pre> with a Copy button.',
	 * ]
	 *
	 * @return array[]
	 */
	public function ui_notices(): array {
		return array();
	}

	/**
	 * Is this module actually doing something right now?
	 *
	 * "On" is not one shape across the plugin. Most modules carry an
	 * `enabled` setting, but page caching lives in the GLOBAL option
	 * (`xspeed_options.cache_enabled`), Minify and Lazy are on when any of
	 * their individual flags is set, and MCP is on when it is connected.
	 * The sidebar's "N on" badge counted only the literal `enabled` key, so
	 * it under-reported: on a site with page caching, minification, lazy
	 * loading and MCP all running it read "Cache 2 / Optimization 1" and
	 * left the plugin's headline feature out of its own count. (#363)
	 *
	 * The default below keeps the historic behaviour for the modules that
	 * genuinely do store `enabled`. A module whose "on" means something
	 * else overrides this and answers for itself, which is what stops the
	 * count drifting again the next time a module changes shape.
	 *
	 * Three-state on purpose:
	 *   true  — on and doing work
	 *   false — off
	 *   null  — no meaningful on/off (a status panel like Health). Callers
	 *           must exclude these rather than counting them as off.
	 */
	public function is_active(): ?bool {
		$settings = $this->get_settings();
		return array_key_exists( 'enabled', $settings )
			? (bool) $settings['enabled']
			: null;
	}

	/**
	 * "On if any of my boolean flags is on" — the shape used by modules
	 * that have no master switch, only a set of independent toggles
	 * (Minify, Lazy, Bloat, Gzip).
	 *
	 * Derived from the module's OWN schema rather than a hardcoded key
	 * list, so adding a flag to a module cannot silently fall out of its
	 * active state the way a literal list would. Only `bool` fields count:
	 * an int like `eager_first_n` or a list like `excluded_images` is
	 * configuration for a feature, not evidence the feature is on.
	 *
	 * Returns null when the module declares no boolean flags at all, so a
	 * caller can exclude it rather than record a misleading false.
	 */
	final protected function any_bool_flag_on(): ?bool {
		$schema   = $this->settings_schema();
		$settings = $this->get_settings();

		$found = false;
		foreach ( $schema as $key => $spec ) {
			if ( 'bool' !== ( $spec['type'] ?? '' ) ) {
				continue;
			}
			$found = true;
			if ( ! empty( $settings[ $key ] ) ) {
				return true;
			}
		}

		return $found ? false : null;
	}

	/**
	 * Why is this module reported on or off? One short sentence for the (i)
	 * beside the status pill.
	 *
	 * "On" is not one shape (see is_active()), so without this the pill is a
	 * bare assertion the user cannot check. It is most opaque exactly where
	 * the rule is least obvious: Media Optimization reads "On" while its two
	 * most prominent switches, Lazy-load Images and Iframes, are both off --
	 * because three other flags are on. The reason names them.
	 *
	 * Computed server-side alongside is_active() so the explanation cannot
	 * drift from the verdict it explains. Returning null means "no reason to
	 * add" and the (i) is not rendered.
	 */
	public function active_reason(): ?string {
		// A module with its own `enabled` switch needs no explaining: the
		// pill and the switch say the same thing, and an (i) that only
		// restates the pill is noise on every one of those pages. Silence
		// here is what keeps the (i) meaningful where it does appear.
		if ( array_key_exists( 'enabled', $this->get_settings() ) ) {
			return null;
		}

		return $this->bool_flag_reason();
	}

	/**
	 * The reason text for a module whose "on" is "any of my flags is on".
	 *
	 * Names the specific settings that are on, using their schema labels, so
	 * the user can go and look at them rather than take the pill on trust.
	 * Shared by every flag-based module for one consistent sentence.
	 */
	final protected function bool_flag_reason(): ?string {
		$schema   = $this->settings_schema();
		$settings = $this->get_settings();

		$on = array();
		foreach ( $schema as $key => $spec ) {
			if ( 'bool' !== ( $spec['type'] ?? '' ) ) {
				continue;
			}
			if ( ! empty( $settings[ $key ] ) ) {
				$on[] = $spec['label'] ?? $key;
			}
		}

		// No boolean flags at all means the module has no on/off to explain
		// (a status panel like Health). Mirrors any_bool_flag_on() returning
		// null: no verdict, so no reason.
		if ( null === $this->any_bool_flag_on() ) {
			return null;
		}

		if ( empty( $on ) ) {
			return __( 'This module has no single on/off switch. It counts as on when any of its settings is on, and none currently is.', 'xspeed' );
		}

		return sprintf(
			/* translators: %s: comma-separated list of setting labels that are switched on. */
			__( 'This module has no single on/off switch. It counts as on because these settings are on: %s.', 'xspeed' ),
			implode( ', ', $on )
		);
	}

	/**
	 * WP-CLI command definitions. Each entry: [
	 *   'name'     => 'xspeed cache purge',
	 *   'callback' => callable,
	 *   'synopsis' => array, // wp-cli synopsis spec
	 * ]
	 *
	 * @return array[]
	 */
	public function cli_commands(): array {
		return array();
	}

	/**
	 * Nginx directives this module contributes to the unified server-block
	 * snippet rendered by Cache::full_nginx_server_block(). Returning a
	 * non-null string opts the module into the consolidated "paste this
	 * once into your nginx vhost" UX on the Cache panel.
	 *
	 * The returned string should be the bare directives only — no `server
	 * { }` wrapper, no comment header (the aggregator adds one). Empty
	 * string and null are both treated as "no contribution this render".
	 *
	 * Return null (default) when the module is disabled, its current
	 * settings make the directives a no-op, or the module doesn't have
	 * nginx-side directives at all.
	 */
	public function nginx_directives(): ?string {
		return null;
	}

	/**
	 * Conflict declarations for this module — which other plugins clash
	 * with which sub-feature. Each entry: [
	 *   'plugin'   => 'wp-rocket/wp-rocket.php',
	 *   'feature'  => 'page_cache',
	 *   'strategy' => 'refuse' | 'warn' | 'allow',
	 *   'reason'   => 'human-readable why',
	 * ]
	 *
	 * @return array[]
	 */
	public function conflicts(): array {
		return array();
	}

	/**
	 * Register WP hooks. Called by Module_Registry::boot_all() after
	 * dependencies are resolved. Modules should NOT register hooks in
	 * their constructors — only in boot() — so the registry can control
	 * load order.
	 */
	public function boot(): void {}

	/**
	 * One-time setup at plugin activation. Idempotent. Examples: create
	 * a custom table, write a silence guard, register a cron schedule.
	 */
	public function activate(): void {}

	/**
	 * Tear down at plugin deactivation. Reversible counterpart to
	 * activate(). MUST leave the site in a clean state — no orphaned
	 * cron jobs, no leftover drop-ins.
	 */
	public function deactivate(): void {}

	/**
	 * Convenience accessors. Modules read/write their own settings
	 * through these so the storage detail (one option per module under
	 * `xspeed_module_<slug>`) stays encapsulated.
	 */
	final public function get_setting( string $key, $default = null ) {
		$opts = Settings_Manager::get( static::SLUG );
		return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
	}

	/**
	 * Read one boolean flag WITHOUT building the settings schema.
	 *
	 * `get_setting()` routes through `Settings_Manager::get()`, which calls
	 * `settings_schema()` to know the defaults and types. That schema
	 * declares its `label` / `description` through `__()` — correct, they are
	 * UI copy a translator has to reach. But modules that read their own
	 * settings from `boot()` do so on `plugins_loaded`, before `init`, where
	 * text domains load. Building the schema there translates every label too
	 * early: WordPress 6.7+ emits `_load_textdomain_just_in_time` on each
	 * request, and the labels resolve against a domain that is not loaded
	 * yet, which silently defeats the translation.
	 *
	 * A boot-time gate only ever asks "is this feature switched on", so it
	 * needs the stored value, not the schema. This reads the module's option
	 * directly and casts. `$default` is what applies when the key was never
	 * written — pass the same value the schema declares as that field's
	 * default, or the two disagree on a fresh install.
	 *
	 * Use ONLY for a boot-time on/off check. Anything that needs coercion,
	 * schema defaults, or a non-boolean value must keep using
	 * `get_setting()` / `get_settings()`.
	 *
	 * @param string $key     Field name in this module's settings.
	 * @param bool   $default Value when the key has never been stored.
	 */
	final protected function flag_at_boot( string $key, bool $default = false ): bool {
		return (bool) $this->setting_at_boot( $key, $default );
	}

	/**
	 * Raw stored value for one setting, WITHOUT building the schema. The
	 * general form of `flag_at_boot()` — see that method for why boot-time
	 * reads must not touch `settings_schema()`.
	 *
	 * No type coercion is applied, so pass a `$default` of the type the
	 * caller expects and cast the result at the call site. Same rule: use
	 * ONLY from code that runs before `init`.
	 *
	 * @param string $key     Field name in this module's settings.
	 * @param mixed  $default Value when the key has never been stored.
	 * @return mixed
	 */
	final protected function setting_at_boot( string $key, $default = null ) {
		$stored = get_option( Settings_Manager::OPTION_PREFIX . static::SLUG, array() );
		if ( ! is_array( $stored ) || ! array_key_exists( $key, $stored ) ) {
			return $default;
		}
		return $stored[ $key ];
	}

	final public function get_settings(): array {
		return Settings_Manager::get( static::SLUG );
	}

	final public function update_settings( array $input ): array {
		$refusal = $this->license_write_refusal( $input );
		if ( null !== $refusal ) {
			return $refusal;
		}
		return Settings_Manager::update( static::SLUG, $input );
	}

	/**
	 * Enforce the Pro licence gate on writes, or null to allow the write.
	 *
	 * The dashboard renders a Pro module as `locked` without a valid licence
	 * and refuses to toggle it, but that flag is applied by the
	 * `xspeed_module_descriptor` filter — a decoration on the payload the UI
	 * reads. It never reached the write path, so `POST /xspeed/v1/<module>`
	 * with `{"enabled": true}` returned 200 and persisted, and CLI/MCP hit the
	 * same unguarded callbacks.
	 *
	 * That mattered because module availability is decided by
	 * `Tier_Registry::is_available()`, which asks only whether the Pro plugin
	 * is LOADED — never whether it is licensed. So a Pro module boots and runs
	 * its hooks regardless, and a persisted `enabled: true` genuinely turns the
	 * feature on. This was not cosmetic. (#143)
	 *
	 * Free never references Pro: it asks through `xspeed_pro_licensed`, the
	 * same filter Pro already answers for the descriptor. With no Pro plugin
	 * present nothing hooks it, the default `true` stands, and Free modules
	 * are unaffected either way.
	 *
	 * Reads stay open — the dashboard must still be able to GET settings to
	 * render the locked state at all.
	 *
	 * @param array $input Proposed setting values.
	 * @return array|null Current public settings when refused, else null.
	 */
	private function license_write_refusal( array $input ): ?array {
		if ( ! $this->is_license_locked() ) {
			return null;
		}

		Activity_Log::record(
			'license_write_refused',
			sprintf(
				/* translators: %s: module slug. */
				__( 'Refused a settings write to the Pro module "%s" — no valid license.', 'xspeed' ),
				static::SLUG
			),
			Activity_Log::WARN
		);

		// Return the unchanged public settings rather than throwing: callers
		// expect the module's settings back, and the dashboard already renders
		// this module as locked. REST surfaces the refusal explicitly in
		// rest_update_settings(), which has a WP_Error channel.
		return Settings_Manager::get_public( static::SLUG );
	}

	final public function slug(): string {
		return static::SLUG;
	}

	final public function tier(): string {
		return static::TIER;
	}

	final public function version(): string {
		return static::VERSION;
	}
}
