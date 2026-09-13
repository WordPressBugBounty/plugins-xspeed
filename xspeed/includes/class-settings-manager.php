<?php
/**
 * Settings_Manager — per-module typed settings storage, validation, and
 * versioned migrations.
 *
 * Storage layout: one wp_option per module under the key
 * `xspeed_module_<slug>`. The option value is an associative array that
 * also carries a `_version` field (the module VERSION at the time of last
 * write) so migrations know what schema produced the stored data.
 *
 * The pre-Module v1 settings (the global cache_enabled / minify_* /
 * gzip_enabled / cache_expiry / excluded_urls) keep living in
 * `xspeed_options` under the existing Settings class — Settings_Manager
 * does not touch them. When v1 features are refactored into Modules,
 * they'll migrate from `xspeed_options` to their per-module options as
 * part of that PR.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Settings_Manager {

	public const OPTION_PREFIX = 'xspeed_module_';

	/**
	 * Marker prefixing a secret value that has been encrypted at rest. A stored
	 * value without this prefix is legacy plaintext (or empty) and is read back
	 * verbatim — so the encryption rollout is lazy and non-destructive.
	 */
	private const SECRET_CIPHER_PREFIX = 'xsenc:v1:';

	/**
	 * Bullet run embedded in a masked secret hint. Also the write-preserve
	 * sentinel: an incoming value containing it (or an empty string) is treated
	 * as "the client is echoing the mask, keep the stored secret" — so saving an
	 * unrelated field on the same panel never wipes the credential. (#115)
	 */
	public const SECRET_MASK_BULLETS = '••••';

	/**
	 * Read settings for a module slug. Returns defaults merged with stored
	 * values + the schema applied (unknown keys stripped). Always safe to
	 * call before activation — returns pure defaults if nothing is stored.
	 */
	public static function get( string $slug ): array {
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return array();
		}
		$schema   = $module->settings_schema();
		$defaults = self::defaults_from_schema( $schema );
		$stored   = get_option( self::option_key( $slug ), array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$merged = array_merge( $defaults, $stored );

		// Strip keys not in schema; coerce types to what the schema declares.
		$clean = array();
		foreach ( $schema as $key => $spec ) {
			// A field whose value is pinned by a wp-config.php constant reads
			// back from that constant, whatever the option row says. This is
			// the single resolution point every consumer -- module, REST, CLI,
			// MCP, and Pro via its own Settings_Manager::get() calls -- passes
			// through, so status, test and enable can never disagree with what
			// the drop-in actually connected to. (#398)
			$constant = self::effective_constant( $slug, $key, $spec );
			if ( null !== $constant ) {
				$clean[ $key ] = self::coerce( self::constant_value( $key, $constant, $spec ), $spec );
				continue;
			}
			/*
			 * A module whose runtime reads its config before WordPress loads
			 * may keep it OUTSIDE the option row -- the object cache writes a
			 * sidecar when wp-config.php is read-only. Ask for that value
			 * before falling back to the row, or the panel reports the stored
			 * settings while the drop-in runs on the sidecar's. (#398)
			 */
			$external = apply_filters( 'xspeed_setting_external_source', null, $slug, $key, $spec );
			if ( null !== $external ) {
				$clean[ $key ] = self::coerce( $external, $spec );
				continue;
			}

			$clean[ $key ] = array_key_exists( $key, $merged )
				? self::coerce( $merged[ $key ], $spec )
				: ( $spec['default'] ?? null );
		}

		// Carry through any out-of-schema keys the module explicitly preserves
		// (e.g. the REST-cache route `rules` array) so a schema-driven save
		// doesn't silently drop them. (FBS-82408)
		foreach ( $module->preserved_keys() as $key ) {
			if ( array_key_exists( $key, $stored ) ) {
				$clean[ $key ] = $stored[ $key ];
			}
		}

		return $clean;
	}

	/**
	 * The constant that pins a field's value, or null when none is defined.
	 *
	 * A field opts in by declaring `constants` in its schema spec -- an ordered
	 * list of constant names, most specific first (our own `XSPEED_*` before a
	 * community convention like `WP_REDIS_*`). The first DEFINED name wins.
	 *
	 * `defined()` is the whole test, deliberately: a constant defined as an
	 * empty string is an answer ("this server has no auth"), not an absence.
	 * Falling through to the DB there would silently re-introduce a credential
	 * the operator removed on purpose. (#398)
	 *
	 * @param array $spec Field spec from settings_schema().
	 * @return string|null Winning constant name, or null.
	 */
	public static function constant_source( array $spec ): ?string {
		// A field can also be pinned by a wp-config.php GLOBAL rather than a
		// constant -- $memcached_servers is the de-facto standard for Memcached
		// the way WP_REDIS_* is for Redis, and hosts write it. It is checked
		// first because a site that sets it means it; `global_source` names the
		// variable and the element to read. (#398)
		$global = self::global_source( $spec );
		if ( null !== $global ) {
			return $global;
		}

		$pair_only = (array) ( $spec['constants_pair_only'] ?? array() );
		foreach ( (array) ( $spec['constants'] ?? array() ) as $name ) {
			if ( ! is_string( $name ) || '' === $name || ! defined( $name ) ) {
				continue;
			}
			// Named in `constants_pair_only`, this constant answers for the
			// field ONLY in its array form, where it carries both halves of a
			// credential. A username field reading a plain-string
			// WP_REDIS_PASSWORD would otherwise authenticate with the password
			// as the username.
			if ( in_array( $name, $pair_only, true ) && ! is_array( constant( $name ) ) ) {
				continue;
			}
			/*
			 * A legacy name kept only for backward compatibility, valid while
			 * another constant holds a particular value. XSPEED_OC_PORT used to
			 * serve BOTH backends; Memcached fields still read it so an install
			 * configured before the split keeps working -- but only when
			 * Memcached is actually the backend, or a Redis site would show the
			 * Redis port in its Memcached field. (#398)
			 */
			$when = (array) ( $spec['constants_when'] ?? array() );
			if ( isset( $when[ $name ] ) ) {
				$gate = $when[ $name ];
				$on   = $gate['constant'] ?? '';
				/*
				 * Resolved through the same filter the rest of the module uses,
				 * not `defined()` alone: on a host where wp-config.php is
				 * read-only the backend lives in the sidecar and no constant is
				 * defined at all. Reading only the constant there rejected the
				 * legacy name, so the panel showed the default host while the
				 * drop-in -- which does consult the sidecar -- used the real
				 * one. Panel and runtime disagreeing is the bug this change
				 * exists to remove. (#398)
				 */
				$actual = defined( $on ) ? constant( $on ) : null;

				/**
				 * Filter: xspeed_constant_gate_value
				 *
				 * @param mixed  $actual Value of the gating constant, or null.
				 * @param string $on     Gating constant name.
				 */
				$actual = apply_filters( 'xspeed_constant_gate_value', $actual, $on );
				if ( $actual !== ( $gate['is'] ?? null ) ) {
					continue;
				}
			}
			return $name;
		}
		return null;
	}

	/**
	 * The wp-config.php GLOBAL pinning this field, as a display name, or null.
	 *
	 * Declared as `global_source => [ 'var' => 'memcached_servers', 'path' =>
	 * [ 0, 0 ] ]` — the variable name plus the path to the element this field
	 * reads. Returned in `$var` form ("$memcached_servers") because that is
	 * what the panel shows and what the reader types into a search box.
	 *
	 * Memcached has no constant convention the way Redis has WP_REDIS_*; the
	 * global IS the convention, used by W3TC and the Memcached Object Cache
	 * drop-in alike, and hosts write it. Without this the drop-in honoured it
	 * while the panel did not -- the same two-truths bug this change closes
	 * for Redis. (#398)
	 */
	public static function global_source( array $spec ): ?string {
		$decl = $spec['global_source'] ?? null;
		if ( ! is_array( $decl ) || empty( $decl['var'] ) ) {
			return null;
		}
		return null === self::global_value( $spec ) ? null : '$' . (string) $decl['var'];
	}

	/**
	 * The value a `global_source` field resolves to, or null when the global is
	 * absent or does not carry the declared path.
	 *
	 * @return mixed|null
	 */
	private static function global_value( array $spec ) {
		$decl = $spec['global_source'] ?? null;
		if ( ! is_array( $decl ) || empty( $decl['var'] ) ) {
			return null;
		}
		$var = (string) $decl['var'];
		if ( ! array_key_exists( $var, $GLOBALS ) ) {
			return null;
		}
		$value = $GLOBALS[ $var ];

		/*
		 * A `reader` names a function that knows the global's real shape, for a
		 * convention a fixed path cannot express. $memcached_servers ships in
		 * two forms -- a [host, port] pair and a "host:port" string under a
		 * `default` key -- and walking `[0][0]` reads the second as the whole
		 * string, or misses it entirely. The drop-in has to answer identically,
		 * so both call the same function rather than each carrying a walker.
		 * (#398)
		 */
		$reader = $decl['reader'] ?? null;
		if ( null !== $reader ) {
			if ( ! is_callable( $reader ) ) {
				return null;
			}
			$slot = (int) ( $decl['slot'] ?? 0 );
			$pair = $reader( $value );
			return is_array( $pair ) && isset( $pair[ $slot ] ) ? $pair[ $slot ] : null;
		}

		foreach ( (array) ( $decl['path'] ?? array() ) as $step ) {
			if ( ! is_array( $value ) || ! array_key_exists( $step, $value ) ) {
				return null;
			}
			$value = $value[ $step ];
		}
		// An array here means the path did not reach a scalar -- a malformed
		// $memcached_servers, say. Treat it as absent rather than coercing it
		// to the string "Array" the way the Redis password bug once did.
		return is_array( $value ) ? null : $value;
	}

	/**
	 * The constant that EFFECTIVELY pins a field, or null.
	 *
	 * `constant_source()` answers "is a constant defined for this field";
	 * this answers "does that constant still win", which is the question every
	 * caller actually has. They differ for one reason: an admin can
	 * deliberately override a pinned field (DESIGN.md §24.36), after which the
	 * constant is shadowed by our own and the field behaves normally again.
	 *
	 * Read/lock/refuse paths all go through here, so an override cannot be
	 * honoured in one place and ignored in another. (#398)
	 *
	 * @param string $slug Module slug.
	 * @param string $key  Field key.
	 * @param array  $spec Field spec from settings_schema().
	 */
	public static function effective_constant( string $slug, string $key, array $spec ): ?string {
		if ( self::is_overridden( $slug, $key ) ) {
			return null;
		}

		/*
		 * A HOST define outranks one we wrote ourselves, whatever order the
		 * schema lists them in. Ours is not a lock -- it is only where the
		 * drop-in can read the option row -- so letting it win meant a host
		 * that added or ROTATED a define after we had written ours was ignored
		 * for good, with nothing on screen to say so. The site kept using our
		 * stale snapshot until someone happened to save in the panel.
		 *
		 * Deliberate overrides are unaffected: is_overridden() has already
		 * returned above, which is the one case where an admin has asked for
		 * our value to win. (#398)
		 */
		$constant = self::constant_source( $spec );
		if ( null !== $constant && in_array( $constant, self::self_written_constants(), true ) ) {
			$foreign = self::foreign_constant( $slug, $key );
			if ( null !== $foreign ) {
				return $foreign;
			}
		}
		return $constant;
	}

	/**
	 * Option key holding the fields an admin deliberately took back from
	 * wp-config.php. Kept OUTSIDE the module's settings row so a schema-driven
	 * save can never drop it, and so it survives a module version migration.
	 */
	private const OVERRIDE_OPTION = 'xspeed_overridden_constants';

	/**
	 * True while update() is settling overrides at the end of a save.
	 *
	 * A save PROMOTES an overridden field: the typed value is written into the
	 * module's own constants and the override then lifts. That lift fires
	 * `xspeed_setting_override_changed` like any other, so a listener that
	 * rewrites config on a revert would run here too -- resolving the host's
	 * constant again and erasing the define the save had just written, undoing
	 * the edit with a success message on screen. Listeners check this to tell
	 * "handed back to the host" from "promoted into our own block". (#398)
	 *
	 * Keyed by module slug, never a single global: saving module A must not
	 * silence a revert that A's own save handler performs on module B. A bare
	 * flag would swallow it as a promotion and leave our define in place --
	 * the panel-revert no-op, reintroduced through a side door.
	 *
	 * @var array<string,bool>
	 */
	private static array $promoting = array();

	/** @see self::$promoting */
	public static function is_promoting( string $slug ): bool {
		return ! empty( self::$promoting[ $slug ] );
	}

	/**
	 * Has an admin deliberately overridden this constant-pinned field?
	 *
	 * Overriding is a considered act: the panel states that the host set the
	 * value and that overriding shadows their future changes, and only then
	 * unlocks the field (DESIGN.md §24.36). Once taken, the field behaves like
	 * any other -- editable, stored in the option row, and exempt from the
	 * enable() guard that otherwise protects a host's define. (#398)
	 */
	public static function is_overridden( string $slug, string $key ): bool {
		$all = get_option( self::OVERRIDE_OPTION, array() );
		if ( ! is_array( $all ) || ! in_array( $key, (array) ( $all[ $slug ] ?? array() ), true ) ) {
			return false;
		}

		// An override only means something while a FOREIGN constant is actually
		// pinning the field. Two ways the entry goes stale, both silent:
		//
		//  - The host removes their define. There is nothing left to override,
		//    and a lingering entry would disable the lock the moment they put
		//    it back -- the field would stay editable with nothing on screen
		//    saying why.
		//  - enable() writes our own XSPEED_OC_* copy of the admin's value. If
		//    that counted as "still overriding", Revert would hand the field to
		//    our own snapshot instead of back to the host, and the row would
		//    name a constant xSpeed wrote rather than the one being displaced.
		//
		// So the override is scoped to a foreign constant being present. (#398)
		return null !== self::foreign_constant( $slug, $key );
	}

	/**
	 * The constant pinning this field that xSpeed did not write itself.
	 *
	 * Our own `XSPEED_*` defines are ours to rewrite and never something an
	 * admin needs protecting from; only somebody else's define -- the host's
	 * `WP_REDIS_*` -- is what an override displaces. (#398)
	 */
	public static function foreign_constant( string $slug, string $key ): ?string {
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return null;
		}
		$spec = $module->settings_schema()[ $key ] ?? null;
		if ( ! is_array( $spec ) ) {
			return null;
		}
		$ours = self::self_written_constants();
		foreach ( (array) ( $spec['constants'] ?? array() ) as $name ) {
			/*
			 * Ownership is decided by WHERE the define sits, not by its name --
			 * the same rule our_constants() implements. Skipping every
			 * `XSPEED_*` by prefix got the hand-pasted case backwards: a user
			 * who pastes our snippet themselves owns those defines, and
			 * treating them as ours let wp_config_block() emit a SECOND define
			 * of the same constant inside our block. PHP then warns
			 * "already defined" on every request, the user's value wins because
			 * it came first, and the panel shows ours. (#398)
			 */
			if ( ! is_string( $name ) || in_array( $name, $ours, true ) ) {
				continue;
			}
			/*
			 * WP_CACHE_KEY_SALT is not a foreign authority to displace ours
			 * (#430). Unlike WP_REDIS_PREFIX, it is not a host declaration of
			 * the cache namespace -- it is WordPress's own cache-uniqueness
			 * salt, present on nearly every install and usually random. Treating
			 * it as one made effective_constant() prefer a random WordPress salt
			 * over the correct XSPEED_OC_SALT that xCloud writes beside it, so
			 * every write landed outside the ACL namespace (NOPERM). Mirrors the
			 * drop-in's salt resolution so panel and runtime agree.
			 */
			if ( 'WP_CACHE_KEY_SALT' === $name ) {
				continue;
			}
			$single = self::constant_source( array( 'constants' => array( $name ) ) + $spec );
			if ( null !== $single ) {
				return $single;
			}
		}
		return null;
	}

	/**
	 * Is this field listed in the override option, regardless of whether a
	 * constant is currently pinning it?
	 *
	 * `is_overridden()` asks the question callers usually mean -- "is this
	 * override doing anything right now" -- which is false once the constant
	 * it displaced is gone. This is the raw list membership, for the two places
	 * that manage the list itself.
	 */
	private static function has_override_entry( string $slug, string $key ): bool {
		$all = get_option( self::OVERRIDE_OPTION, array() );
		return is_array( $all ) && in_array( $key, (array) ( $all[ $slug ] ?? array() ), true );
	}

	/**
	 * Every overridden field for a module.
	 *
	 * @return string[]
	 */
	public static function overridden_keys( string $slug ): array {
		$all = get_option( self::OVERRIDE_OPTION, array() );
		if ( ! is_array( $all ) ) {
			return array();
		}
		return array_values( array_filter( (array) ( $all[ $slug ] ?? array() ), 'is_string' ) );
	}

	/**
	 * Take a pinned field back, or hand it to the constant again.
	 *
	 * Reverting deliberately leaves the stored value in place: the constant
	 * outranks it the moment the override lifts, so the row is inert, and
	 * keeping it means a second override does not start from a blank box.
	 *
	 * @param bool $on True to override, false to revert.
	 */
	public static function set_override( string $slug, string $key, bool $on ): void {
		$module = Module_Registry::get( $slug );
		if ( ! $module || ! array_key_exists( $key, $module->settings_schema() ) ) {
			return;
		}
		// Seed the option row from the value currently in force, BEFORE the
		// override lifts. Without this the field falls back to its schema
		// default the moment it unlocks -- so an admin who took the field over
		// to tweak the host would find it silently reset to 127.0.0.1, and a
		// save would write that. Take-over means "carry on from here", not
		// "start again". Skipped when a row already exists, so a second
		// override still resumes from what was last typed. (#398)
		if ( $on && ! self::has_override_entry( $slug, $key ) ) {
			$stored = get_option( self::option_key( $slug ), array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			if ( ! array_key_exists( $key, $stored ) ) {
				$spec     = $module->settings_schema()[ $key ];
				$constant = self::constant_source( $spec );
				if ( null !== $constant ) {
					$stored[ $key ] = self::coerce( self::constant_value( $key, $constant, $spec ), $spec );
					if ( 'secret' === ( $spec['type'] ?? '' ) ) {
						$stored[ $key ] = self::encrypt_for_storage( (string) $stored[ $key ] );
					}
					// Stamp the version this row was written against. Without it
					// run_migrations() sees a row with no `_version`, reads that
					// as 0.0.0, and replays EVERY migration over data that never
					// held a pre-migration shape -- a seed is not an upgrade.
					if ( ! isset( $stored['_version'] ) ) {
						$stored['_version'] = $module->version();
					}
					update_option( self::option_key( $slug ), $stored );
				}
			}
		}

		$all = get_option( self::OVERRIDE_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$keys = array_values( array_filter( (array) ( $all[ $slug ] ?? array() ), 'is_string' ) );

		if ( $on ) {
			if ( ! in_array( $key, $keys, true ) ) {
				$keys[] = $key;
			}
		} else {
			$keys = array_values( array_diff( $keys, array( $key ) ) );
		}

		if ( empty( $keys ) ) {
			unset( $all[ $slug ] );
		} else {
			$all[ $slug ] = $keys;
		}
		update_option( self::OVERRIDE_OPTION, $all );

		/**
		 * A field's ownership just changed hands.
		 *
		 * A module that writes its own constants into wp-config.php has to act
		 * here, or a revert is a dead end: Object_Cache's enable() emits the
		 * admin's overridden value as XSPEED_OC_*, which outranks the host's
		 * define, so dropping the override entry alone leaves the field pinned
		 * to our stale snapshot with no route back. The listener rewrites its
		 * block from the settings as they now resolve. (#398)
		 *
		 * @param string $slug Module slug.
		 * @param string $key  Field key.
		 * @param bool   $on   True when taken over, false when handed back.
		 */
		do_action( 'xspeed_setting_override_changed', $slug, $key, $on );
	}

	/**
	 * Hand a field back to the host's constant, completely.
	 *
	 * `set_override( ..., false )` only drops the override entry, which is
	 * enough when the constant that pinned the field is someone else's. It is
	 * NOT enough once we have written our own define: ours outranks the host's,
	 * so the field keeps our stale value, a later credential rotation is
	 * ignored for good, and the panel's "you can revert at any time" is a lie.
	 * The CLI already did the full hand-back inline; REST did not, so the
	 * button in the panel was a no-op on exactly the sites this matters on.
	 * One implementation, both callers. (#398)
	 *
	 * Returns the host constant the field was handed back to, or a WP_Error
	 * when there is nothing to hand it back TO -- reverting onto our own define
	 * would rewrite the same value into our own block and report success over a
	 * no-op, which is the behaviour this replaces.
	 *
	 * @return string|\WP_Error Foreign constant name, or an error.
	 */
	public static function revert( string $slug, string $key ) {
		$module = Module_Registry::get( $slug );
		if ( ! $module || ! array_key_exists( $key, $module->settings_schema() ) ) {
			return new \WP_Error(
				'xspeed_unknown_setting',
				sprintf(
					/* translators: %s: setting key. */
					__( 'Unknown setting: %s', 'xspeed' ),
					$key
				),
				array( 'status' => 400 )
			);
		}

		$foreign = self::foreign_constant( $slug, $key );
		if ( null === $foreign ) {
			return new \WP_Error(
				'xspeed_nothing_to_revert',
				sprintf(
					/* translators: %s: setting key. */
					__( '"%s" is not set in wp-config.php by your host, so there is nothing to revert to. Set it to the value you want instead.', 'xspeed' ),
					$key
				),
				array( 'status' => 409 )
			);
		}

		self::set_override( $slug, $key, false );

		/*
		 * Drop our stored value as well as the override entry. Leaving the row
		 * in place is what kept our define being re-emitted on the next block
		 * write, so the field never actually returned to the host. The
		 * `xspeed_setting_override_changed` action fired by set_override() is
		 * what rewrites the block from the settings as they now resolve.
		 */
		$row = get_option( self::option_key( $slug ), array() );
		if ( is_array( $row ) && array_key_exists( $key, $row ) ) {
			unset( $row[ $key ] );
			update_option( self::option_key( $slug ), $row );
		}

		return $foreign;
	}

	/**
	 * A constant's value as this field should read it.
	 *
	 * Almost always the constant verbatim. The exception is a constant that
	 * carries a credential PAIR in one define -- managed hosts provisioning
	 * Redis ACL users ship
	 *
	 *     define( 'WP_REDIS_PASSWORD', array( 'acl_user', 's3cret' ) );
	 *
	 * and casting that to string yields "Array" plus a notice, so the site
	 * authenticates with garbage. A field says which half it wants by
	 * declaring `constant_pair => 'user'|'password'`; the split is positional
	 * so an associative pair works too. Declared in the schema rather than
	 * keyed off field names, so the next module with a paired credential
	 * inherits it. (#398)
	 *
	 * @param string $key      Schema field key (for context in filters).
	 * @param string $constant Winning constant name.
	 * @param array  $spec     Field spec from settings_schema().
	 * @return mixed
	 */
	private static function constant_value( string $key, string $constant, array $spec ) {
		// A `$`-prefixed source is a wp-config global, not a constant.
		$value = 0 === strpos( $constant, '$' )
			? self::global_value( $spec )
			: constant( $constant );
		$part  = $spec['constant_pair'] ?? '';

		if ( ! is_array( $value ) || ( 'user' !== $part && 'password' !== $part ) ) {
			return $value;
		}

		// Only scalars: a nested array would stringify to "Array" plus a PHP
		// warning, so the site would authenticate with that literal. Dropping
		// non-scalars means a malformed define reads as absent rather than as
		// a wrong credential.
		$parts = array_values( array_filter( $value, 'is_scalar' ) );
		if ( 'user' === $part ) {
			// A one-element array is a password with no ACL user.
			return count( $parts ) > 1 ? (string) $parts[0] : '';
		}
		return (string) ( count( $parts ) > 1 ? $parts[1] : ( $parts[0] ?? '' ) );
	}

	/**
	 * Where each of a module's settings actually came from.
	 *
	 * Returns one entry per schema field:
	 *   [ 'source' => 'constant'|'db'|'default', 'constant' => ?string ]
	 *
	 * One map feeds every surface that has to be honest about provenance --
	 * the panel's read-only indicator, `wp xspeed objcache status`, and the
	 * refusal message when a write targets a pinned field -- so they cannot
	 * drift apart. (#398)
	 *
	 * @return array<string,array{source:string,constant:?string}>
	 */
	/**
	 * Constant names xSpeed wrote itself, as opposed to ones the host defined.
	 *
	 * Ownership is decided by WHERE the define sits -- inside our fenced block
	 * in wp-config.php, or anywhere else -- never by its name. A user who
	 * pastes our snippet by hand ends up with `XSPEED_OC_*` defines that are
	 * theirs, not ours, and must not be silently rewritten.
	 *
	 * Filterable so a module that owns constants can answer for itself without
	 * this class having to know about it.
	 *
	 * @return string[]
	 */
	/**
	 * The constant that BLOCKS writing this field, or null when it is writable.
	 *
	 * Same resolution as effective_constant(), minus the constants xSpeed
	 * wrote itself: those are this plugin's own storage, so a save rewrites
	 * them rather than being refused. Only a define somebody else put in
	 * wp-config.php makes a field read-only.
	 *
	 * Read paths keep using effective_constant() -- what the site RUNS on is
	 * the winning constant either way; this only answers "may the panel write
	 * here". (#398)
	 *
	 * @param array $spec Field spec from settings_schema().
	 */
	public static function write_blocking_constant( string $slug, string $key, array $spec ): ?string {
		$constant = self::effective_constant( $slug, $key, $spec );
		if ( null === $constant ) {
			return null;
		}
		return in_array( $constant, self::self_written_constants(), true ) ? null : $constant;
	}

	public static function self_written_constants(): array {
		$names = array();
		if ( class_exists( '\\XSpeed\\Object_Cache' ) ) {
			$names = \XSpeed\Object_Cache::our_constants();
		}

		/**
		 * Filter: xspeed_self_written_constants
		 *
		 * @param string[] $names Constant names xSpeed wrote into wp-config.php.
		 */
		return (array) apply_filters( 'xspeed_self_written_constants', $names );
	}

	public static function origins( string $slug ): array {
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return array();
		}
		$stored = get_option( self::option_key( $slug ), array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$origins = array();
		$ours    = self::self_written_constants();
		foreach ( $module->settings_schema() as $key => $spec ) {
			$constant = self::effective_constant( $slug, $key, $spec );
			/*
			 * A constant WE wrote is not a lock. It is this module's own
			 * storage -- wp-config.php is simply where the drop-in can read it
			 * before WordPress loads -- so the field stays editable and saving
			 * rewrites it.
			 *
			 * Reporting it as 'constant' is what made enabling the object cache
			 * lock its own settings screen, tell the user seven fields were
			 * "set in wp-config.php" when they had never opened that file, and
			 * leave the take-it-back control unable to unlock anything. (#398)
			 */
			if ( null !== $constant && in_array( $constant, $ours, true ) ) {
				$origins[ $key ] = array(
					'source'     => 'db',
					'constant'   => null,
					'overriding' => self::foreign_constant( $slug, $key ),
				);
				continue;
			}
			if ( null !== $constant ) {
				$origins[ $key ] = array(
					'source'     => 'constant',
					'constant'   => $constant,
					'overriding' => null,
				);
				continue;
			}
			// An overridden field reports its real source -- the option row --
			// but still names the constant it is shadowing, so the panel can
			// say WHAT is being overridden and offer to hand it back. Without
			// this the row is indistinguishable from a field no constant ever
			// touched. (#398)
			$origins[ $key ] = array(
				'source'     => array_key_exists( $key, $stored ) ? 'db' : 'default',
				'constant'   => null,
				'overriding' => self::foreign_constant( $slug, $key ),
			);
		}
		return $origins;
	}

	/**
	 * Field keys this module cannot persist because a constant pins them.
	 *
	 * @return string[]
	 */
	public static function locked_keys( string $slug ): array {
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return array();
		}
		$locked = array();
		foreach ( $module->settings_schema() as $key => $spec ) {
			if ( null !== self::write_blocking_constant( $slug, $key, $spec ) ) {
				$locked[] = $key;
			}
		}
		return $locked;
	}

	/**
	 * The subset of an incoming patch that targets constant-pinned fields.
	 *
	 * Callers use this to fail loudly. Writing such a key would persist a row
	 * that get() will never read back -- the worst outcome for an automation,
	 * which reports success and changes nothing. (#398)
	 *
	 * Only a key whose submitted value DIFFERS from the resolved one counts. The
	 * dashboard saves a panel by posting every field it rendered, so a pinned
	 * field rides along in the patch on every save; treating that echo as an
	 * attempted write would 409 the whole request and make the panel
	 * unsaveable on exactly the host-provisioned site this feature exists for.
	 * An echo of the effective value asks for no change, so it is allowed
	 * through and dropped harmlessly by update(). (#398)
	 *
	 * @param array<string,mixed> $input Proposed settings patch.
	 * @return array<string,string> Field key => winning constant name.
	 */
	public static function locked_in_input( string $slug, array $input ): array {
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return array();
		}
		$resolved = self::get( $slug );
		$public   = self::get_public( $slug );
		$hits     = array();
		foreach ( $module->settings_schema() as $key => $spec ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$constant = self::write_blocking_constant( $slug, $key, $spec );
			if ( null === $constant ) {
				continue;
			}

			// Compare against the coerced form, so 6379 and "6379" from a JSON
			// body agree, and against the MASKED form too: a secret's effective
			// value never leaves the server, so the client can only ever echo
			// the mask it was given.
			[ $coerced ] = self::validate_field( $input[ $key ], $spec );
			$submitted   = $input[ $key ];
			$echoes      = ( $coerced === ( $resolved[ $key ] ?? null ) )
				|| ( is_string( $submitted ) && $submitted === ( $public[ $key ] ?? null ) )
				|| ( self::is_secret_field( $key, $spec ) && is_string( $submitted ) && self::is_masked_secret( $submitted ) );

			if ( ! $echoes ) {
				$hits[ $key ] = $constant;
			}
		}
		return $hits;
	}

	/**
	 * Validate input against the module's schema, merge over stored values,
	 * and persist. Returns the final clean array. Unknown keys are stripped
	 * silently. Out-of-range / wrong-type values fall back to the previous
	 * stored value (or default).
	 */
	public static function update( string $slug, array $input ): array {
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return array();
		}
		$schema  = $module->settings_schema();
		$current = self::get( $slug );

		// An MCP agent must not silently rewrite credentials — repointing the
		// Cloudflare or object-cache backend at an attacker endpoint — unless the
		// connection was explicitly granted the `configure` scope. Strip secret
		// fields from an unprivileged MCP write here so every write path (the
		// update_settings tool AND run_command → CLI) is covered at one choke
		// point. The tool handler surfaces the refusal as a clear error. (#116)
		if ( self::mcp_write_blocked() ) {
			foreach ( $schema as $key => $spec ) {
				if ( self::is_secret_field( $key, $spec ) ) {
					unset( $input[ $key ] );
				}
			}
		}

		$clean = $current;
		foreach ( $schema as $key => $spec ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			// A constant pins this field, so the write cannot take effect. Drop
			// it rather than storing a row get() will never read. REST and CLI
			// call locked_in_input() first and refuse the request outright with
			// the constant's name; this is the choke-point backstop for any
			// caller that reaches update() directly. (#398)
			if ( null !== self::write_blocking_constant( $slug, $key, $spec ) ) {
				continue;
			}
			// A secret field whose incoming value is the masked placeholder means
			// the client is echoing back what get_public() sent, not setting a new
			// credential — keep the stored value so an unrelated save on the same
			// panel never wipes the key. An empty value is NOT a mask echo: it's a
			// deliberate clear and flows through to remove the credential. (#115)
			if ( self::is_secret_field( $key, $spec ) && self::is_masked_secret( (string) $input[ $key ] ) ) {
				continue;
			}
			[ $value, $valid ] = self::validate_field( $input[ $key ], $spec );
			if ( $valid ) {
				$clean[ $key ] = $value;
			}
			// Invalid → keep $current[$key]. We do not throw; REST layer can
			// add its own strict-mode validation that 400s on invalid input.
		}

		// Carry through out-of-schema keys the module explicitly preserves when
		// they arrive in the INPUT — not only when already stored. Otherwise a
		// caller that routes through update() to SET a preserved key (e.g. a
		// migration/profile writing `mobile_separate_review`) has it silently
		// stripped, because it isn't in $current yet. (FBS-83144)
		foreach ( $module->preserved_keys() as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = $input[ $key ];
			}
		}

		// Change annotation (issue #45): every real mutation — from the UI,
		// REST, CLI, or an MCP agent — lands in the activity log with the
		// old→new diff and its source channel, so the dashboard can tell the
		// causal story ("expiry raised → hit ratio climbed").
		//
		// $clean holds plaintext secrets (carried from $current, which get()
		// decrypts, or freshly validated). Log + diff BEFORE encrypting, so the
		// change annotation compares like-for-like (log_changes redacts secret
		// values by key anyway). The encrypted copy is persisted below. (#115)
		//
		// Pass the FULL schema, not just its keys — log_changes() needs each
		// field's `label` to write "Disable Dashicons on Frontend" instead of
		// `disable_dashicons_frontend`. The schema was already in scope here
		// and was simply being discarded. (#88)
		self::log_changes( $slug, $current, $clean, $schema );

		// Encrypt at rest ONLY fields explicitly typed `secret`. This must match
		// coerce(), which decrypts only for `type === 'secret'` — encrypting a
		// merely name-matched `string` field (a credential a module author typed
		// as string) would store ciphertext that the string coercer then hands
		// back verbatim, breaking the engine. Such fields are still masked and
		// write-preserved via the broader is_secret_field() (masking a plaintext
		// is always safe); they just aren't encrypted until retyped to `secret`.
		$stored = $clean;
		foreach ( $schema as $key => $spec ) {
			// A constant-pinned field never enters the option row. $clean carries
			// its resolved value so the engine and the activity diff see the real
			// config, but persisting it would copy a wp-config credential into the
			// database -- exactly what sourcing it from a constant avoids -- and
			// leave a stale row behind if the constant later changes. (#398)
			if ( null !== self::write_blocking_constant( $slug, $key, $spec ) ) {
				unset( $stored[ $key ] );
				continue;
			}
			if ( 'secret' === ( $spec['type'] ?? '' ) ) {
				$stored[ $key ] = self::encrypt_for_storage( (string) ( $stored[ $key ] ?? '' ) );
			}
		}
		$stored['_version'] = $module->version();
		update_option( self::option_key( $slug ), $stored );

		/**
		 * Settings for this module were just saved.
		 *
		 * A module whose runtime reads the config before WordPress loads --
		 * the object-cache drop-in does -- mirrors the saved values into its
		 * own store HERE, so the panel, the CLI and the runtime cannot
		 * disagree. Without this a save reported success while the drop-in
		 * kept using the previous value. (#398)
		 *
		 * @param string              $slug  Module slug.
		 * @param array<string,mixed> $clean The settings as persisted.
		 */
		do_action( 'xspeed_settings_saved', $slug, $clean );

		// An override is a single edit, not a new permanent home for the value.
		// The admin unlocked the field, typed, and saved; the value now belongs
		// in wp-config.php beside the one it displaced, and the field goes back
		// to being locked -- reading OUR constant instead of the host's.
		//
		// Lifting it here rather than leaving it standing is what keeps the
		// model honest. A standing override means the option row is the source
		// of truth for that field, and a row cannot be read by the drop-in,
		// which loads before WordPress. Promote to a constant and the panel,
		// the CLI and the runtime agree again. (#398)
		$settled = array();
		foreach ( array_keys( $input ) as $key ) {
			if ( isset( $schema[ $key ] ) && self::is_overridden( $slug, $key ) ) {
				$settled[ $key ] = $clean[ $key ] ?? null;
			}
		}
		if ( ! empty( $settled ) ) {
			/**
			 * Overridden fields were just saved and are about to re-lock.
			 *
			 * A module that owns constants in wp-config.php writes them HERE,
			 * while the values are still to hand -- once the override lifts,
			 * get() reads the displaced constant again and the typed value is
			 * gone. Passed explicitly for that reason rather than left to be
			 * re-read. (#398)
			 *
			 * @param string              $slug   Module slug.
			 * @param array<string,mixed> $values Field key => value just saved.
			 */
			do_action( 'xspeed_settings_promote_to_config', $slug, $settled );

			// Flagged so an override_changed listener can tell this lift --
			// which promotes the value into our own block -- from a genuine
			// hand-back to the host. See self::$promoting.
			self::$promoting[ $slug ] = true;
			try {
				foreach ( array_keys( $settled ) as $key ) {
					self::set_override( $slug, $key, false );
				}
			} finally {
				unset( self::$promoting[ $slug ] );
			}

			// Keep the saved values in the option row for THIS request. The
			// constant the module just wrote is in wp-config.php but not
			// defined in the running process -- constants are read at boot --
			// so get() would resolve back to the displaced value and hand the
			// caller a response showing the change had not happened. The row is
			// inert from the next request on, when the new constant loads and
			// outranks it. (#398)
			$row = get_option( self::option_key( $slug ), array() );
			if ( is_array( $row ) ) {
				$carry = $settled;
				foreach ( array_keys( $carry ) as $key ) {
					// Same encryption the main persist path applies -- this row
					// is short-lived but it is still the options table.
					if ( 'secret' === ( $schema[ $key ]['type'] ?? '' ) ) {
						$carry[ $key ] = self::encrypt_for_storage( (string) $carry[ $key ] );
					}
				}
				update_option( self::option_key( $slug ), array_merge( $row, $carry ) );
			}
		}

		// Return the PUBLIC view: real non-secret values, masked secrets. This
		// is the REST/CLI/MCP response, so it must never carry credentials. (#115)
		return self::get_public( $slug );
	}

	/**
	 * The public, safe-to-serialize view of a module's settings: identical to
	 * get() except every secret field is replaced by a masked hint (first/last
	 * few chars, never the middle). This is what the REST GET handler, the MCP
	 * read tools, and the dashboard bootstrap payload return — get() itself
	 * stays plaintext for the engine.
	 *
	 * @return array<string,mixed>
	 */
	/**
	 * Settings as stored, with schema defaults filled in and constants ignored.
	 *
	 * The read-back companion to get(): same shape, but it never lets a
	 * constant outrank the option row. See get_public()'s `$stored_only`.
	 *
	 * Public because any caller that has WRITTEN in this request needs it: our
	 * block has been rewritten but PHP cannot redefine the constants already
	 * loaded, so the resolved read still returns the pre-save value. The object
	 * cache's write probe rechecks against this for exactly that reason. (#398)
	 *
	 * @return array<string,mixed>
	 */
	public static function stored_with_defaults( string $slug ): array {
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return array();
		}
		$stored = get_option( self::option_key( $slug ), array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$out = array();
		foreach ( $module->settings_schema() as $key => $spec ) {
			if ( array_key_exists( $key, $stored ) ) {
				$out[ $key ] = self::coerce( $stored[ $key ], $spec );
				continue;
			}
			/*
			 * Not in the row. For a field a HOST constant pins that is the
			 * normal state -- update() strips those before writing -- and the
			 * schema default would be a lie (an empty password for a field the
			 * site authenticates with). Report the effective value there.
			 */
			$constant    = self::effective_constant( $slug, $key, $spec );
			$out[ $key ] = null !== $constant
				? self::constant_value( $key, $constant, $spec )
				: ( $spec['default'] ?? null );
		}

		// Same carry-through get() does, so a caller asking for the stored
		// state does not silently lose a module's out-of-schema keys.
		foreach ( $module->preserved_keys() as $key ) {
			if ( array_key_exists( $key, $stored ) ) {
				$out[ $key ] = $stored[ $key ];
			}
		}
		return $out;
	}

	public static function get_public( string $slug, bool $stored_only = false ): array {
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return array();
		}
		/*
		 * `$stored_only` answers "what did we just persist", not "what is the
		 * site running on". A caller that has written in THIS request needs it:
		 * the save also rewrites our wp-config block, but the constants for
		 * this request are already defined and PHP cannot redefine them, so the
		 * normal read would resolve the pre-write constant and report the save
		 * as a no-op. (#398)
		 */
		$settings = $stored_only ? self::stored_with_defaults( $slug ) : self::get( $slug );
		foreach ( $module->settings_schema() as $key => $spec ) {
			if ( self::is_secret_field( $key, $spec ) && array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = self::mask_secret_value( (string) $settings[ $key ] );
			}
		}
		return $settings;
	}

	/**
	 * Record changed schema fields as one activity event. No-op when
	 * nothing actually changed (idempotent re-saves stay silent).
	 *
	 * @param string $slug   Module slug.
	 * @param array  $before Settings before the write.
	 * @param array  $after  Settings after the write.
	 * @param array  $schema Full settings schema — used for each field's label.
	 */
	private static function log_changes( string $slug, array $before, array $after, array $schema ): void {
		if ( ! class_exists( '\\XSpeed\\Activity_Log' ) ) {
			return;
		}
		$diffs = array();
		foreach ( array_keys( $schema ) as $key ) {
			$old = $before[ $key ] ?? null;
			$new = $after[ $key ] ?? null;
			if ( $old === $new ) {
				continue;
			}

			// The schema already declares a human label for every field — the
			// same one rendered a few inches away on the settings screen. The
			// feed used the raw storage key instead, so users read
			// `disable_dashicons_frontend` rather than "Disable Dashicons on
			// Frontend". Fall back to the key when a schema has no label, so
			// an entry is never blank. (#88)
			$label = isset( $schema[ $key ]['label'] ) && is_string( $schema[ $key ]['label'] ) && '' !== $schema[ $key ]['label']
				? $schema[ $key ]['label']
				: $key;

			if ( self::is_redacted_key( $key ) ) {
				// Never record the value itself — the annotation is served to
				// the dashboard by the trend endpoints, so anything written
				// here is readable by any user who can load the dashboard.
				$diffs[] = sprintf( '%s changed', $label );
				continue;
			}
			$diffs[] = sprintf( '%s %s→%s', $label, self::describe_value( $old ), self::describe_value( $new ) );
		}
		if ( empty( $diffs ) ) {
			return;
		}
		Activity_Log::record(
			'settings_changed',
			sprintf( '%s: %s (via %s)', self::module_label( $slug ), implode( ', ', array_slice( $diffs, 0, 5 ) ), self::source_channel() )
		);
	}

	/**
	 * A module's display name for the activity feed, e.g. `gzip` →
	 * "Compression".
	 *
	 * Resolved through the module registry rather than a lookup table here,
	 * so Pro modules (feed-cache, search-cache, …) get their labels from the
	 * same path — Pro persists through this class and contributes no logging
	 * code of its own.
	 *
	 * Falls back to the raw slug when the module isn't registered or declares
	 * no label; an entry is never blank.
	 */
	private static function module_label( string $slug ): string {
		if ( ! class_exists( '\\XSpeed\\Module_Registry' ) ) {
			return $slug;
		}
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return $slug;
		}
		$meta = $module->ui_metadata();
		return ( isset( $meta['label'] ) && is_string( $meta['label'] ) && '' !== $meta['label'] )
			? $meta['label']
			: $slug;
	}

	/**
	 * Setting keys whose VALUE must never reach the activity log. The log is
	 * surfaced by the dashboard trend endpoints, so anything recorded here is
	 * readable by any user who can load the dashboard.
	 *
	 * Matched on the key name rather than the value, because a credential is
	 * indistinguishable from an ordinary string once it's been stringified.
	 * Pure — unit-tested.
	 *
	 * @param string $key Schema key, e.g. 'api_token'.
	 */
	public static function is_secret_key( string $key ): bool {
		// `license_key` is matched explicitly: the pattern requires `api_key`
		// rather than a bare `key` so that `key_prefix` (an ordinary,
		// useful-to-see setting) isn't swallowed, which left a real license
		// key printing in plaintext.
		return 1 === preg_match( '/(token|password|secret|api_key|license_key|passwd|private_key|credential)/i', $key );
	}

	/**
	 * Setting keys whose value is withheld from the activity feed.
	 *
	 * Secrets (above) plus infrastructure IDENTIFIERS. `redis_password` was
	 * correctly redacted while `redis_user`, `redis_host` and `key_prefix`
	 * were written out in full — and the feed is served to any user who can
	 * load the dashboard, not just admins (see the trend endpoints).
	 *
	 * A Redis hostname and username are most of a credential, and they
	 * describe internal infrastructure that has no business being readable by
	 * a subscriber. The feed's job — "this setting changed, when, and by
	 * whom" — is served without printing the value. (#88)
	 *
	 * Deliberately matched on the key NAME: once stringified, a hostname is
	 * indistinguishable from any other short string. Pure — unit-tested.
	 *
	 * @param string $key Schema key, e.g. 'redis_host'.
	 */
	public static function is_redacted_key( string $key ): bool {
		if ( self::is_secret_key( $key ) ) {
			return true;
		}

		// Deliberately an explicit list rather than a broad word match. A
		// pattern like /(host|user|prefix|port)/ also swallows
		// `bypass_user_agents`, `preconnect_hosts` and `excluded_urls` —
		// ordinary user-facing settings whose values are exactly what makes
		// the feed useful. Over-redacting is a quieter failure than leaking,
		// but it is still a failure.
		//
		// Scoped to connection details and account identifiers. A new backend
		// or provider setting must be added here consciously — see the
		// schema-coverage test that walks every registered module and fails
		// on an unreviewed key.
		$identifiers = array(
			// Object-cache backends.
			'redis_host',
			'redis_port',
			'redis_user',
			'redis_socket',
			'redis_database',
			'memcached_host',
			'memcached_port',
			'memcached_user',
			'key_prefix',
			// Cloudflare. The same reasoning that withholds redis_user /
			// redis_host applies at least as strongly here: an account email
			// plus a full Zone ID together identify the account and the exact
			// zone. api_token / api_key are already covered by
			// is_secret_key(); these two were the gap.
			'email',
			'zone_id',
		);

		/**
		 * Setting keys whose value is withheld from the activity feed.
		 *
		 * @param string[] $identifiers Keys to redact, on top of is_secret_key().
		 */
		$identifiers = (array) apply_filters( 'xspeed_activity_redacted_keys', $identifiers );

		return in_array( strtolower( $key ), array_map( 'strtolower', $identifiers ), true );
	}

	/**
	 * Whether a schema field holds credential material. A field is secret when
	 * it declares `type => 'secret'` (the explicit, preferred marker) OR its key
	 * name matches the credential pattern (is_secret_key) — the backstop that
	 * catches a credential a module author forgot to type, so a leak can't open
	 * just because a field was declared `string`.
	 *
	 * @param string $key  Schema field key.
	 * @param array  $spec Field spec from settings_schema().
	 */
	public static function is_secret_field( string $key, array $spec ): bool {
		return ( ( $spec['type'] ?? '' ) === 'secret' ) || self::is_secret_key( $key );
	}

	/**
	 * The subset of $input keys that are secret fields for this module's schema.
	 * Used by the MCP update_settings tool to name exactly which fields it
	 * refused. Returns [] for an unknown module.
	 *
	 * @param string              $slug  Module slug.
	 * @param array<string,mixed> $input Proposed settings patch.
	 * @return string[]
	 */
	public static function secret_keys_in( string $slug, array $input ): array {
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return array();
		}
		$schema = $module->settings_schema();
		$out    = array();
		foreach ( $input as $key => $value ) {
			if ( isset( $schema[ $key ] ) && self::is_secret_field( $key, $schema[ $key ] ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Classify an input payload against a module's schema WITHOUT writing
	 * anything: which keys would be applied, which are unknown, and which are
	 * in-schema but carry a value the validator rejects.
	 *
	 * update() walks the SCHEMA rather than the input, so a key with no schema
	 * entry is never iterated — never written, never mentioned. And an
	 * in-schema key whose value fails validation is dropped deliberately
	 * ("REST layer can add its own strict-mode validation"), which CLI and MCP
	 * never traverse. Both therefore reported success over a write that did
	 * not happen; the realistic case is an agent sending
	 * `cache_enabled` to the `cache` module — a no-op reported as done. (#206)
	 *
	 * Pure: no side effects, so callers can decide to refuse BEFORE writing.
	 * update()'s own signature is deliberately unchanged — a dozen callers
	 * depend on it returning the settings array.
	 *
	 * @param string              $slug  Module slug.
	 * @param array<string,mixed> $input Proposed values.
	 * @return array{applied:string[],unknown:string[],invalid:string[]}
	 */
	public static function inspect_input( string $slug, array $input ): array {
		$out = array(
			'applied' => array(),
			'unknown' => array(),
			'invalid' => array(),
			// Keys a wp-config.php constant pins, so update() will drop them.
			// Reported here rather than only in the REST/CLI guards, because
			// every other caller -- the MCP update_settings tool above all --
			// reaches update() directly and would otherwise report a success
			// over a write that changed nothing. (#398)
			'locked'  => array(),
		);

		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			// Unknown module: the caller reports that separately, and every key
			// is by definition unapplied.
			$out['unknown'] = array_keys( $input );
			return $out;
		}

		$schema    = $module->settings_schema();
		$preserved = $module->preserved_keys();

		foreach ( $input as $key => $value ) {
			// Out-of-schema keys a module explicitly preserves are written
			// verbatim by update(), so they count as applied, not unknown.
			if ( in_array( $key, $preserved, true ) ) {
				$out['applied'][] = $key;
				continue;
			}
			if ( ! isset( $schema[ $key ] ) ) {
				$out['unknown'][] = $key;
				continue;
			}
			$spec = $schema[ $key ];
			// A masked secret echo is a deliberate "keep what's stored", not a
			// failed write — update() skips it by design, so don't report it.
			if ( self::is_secret_field( $key, $spec ) && self::is_masked_secret( (string) $value ) ) {
				$out['applied'][] = $key;
				continue;
			}
			[ $coerced, $valid ] = self::validate_field( $value, $spec );
			// Pinned by a constant. An echo of the value already in effect asks
			// for no change, so it is not reported -- only a genuine attempt to
			// set something different.
			if ( null !== self::write_blocking_constant( $slug, $key, $spec ) ) {
				$resolved = self::get( $slug );
				if ( $coerced !== ( $resolved[ $key ] ?? null ) ) {
					$out['locked'][] = $key;
					continue;
				}
				$out['applied'][] = $key;
				continue;
			}
			if ( $valid ) {
				$out['applied'][] = $key;
			} else {
				$out['invalid'][] = $key;
			}
		}

		return $out;
	}

	/**
	 * Where a key the caller asked for actually lives, when it isn't in the
	 * module's schema. Turns "unknown key" into a pointer.
	 *
	 * `cache_enabled` is the case worth naming: it is deliberately outside the
	 * cache module's schema because it drives the drop-in install, so the most
	 * natural command an agent issues to turn caching on is a silent no-op.
	 * (#206)
	 *
	 * @param string $key Rejected input key.
	 * @return string Human-readable hint, or '' when there's nothing useful.
	 */
	public static function hint_for_unknown_key( string $key ): string {
		$hints = array(
			'cache_enabled' => 'page caching is not a module setting — it installs the drop-in. Use the dashboard toggle, the REST route /xspeed/v1/cache/toggle, or the MCP `toggle_cache` tool.',
			'gzip_enabled'  => 'this moved to the `gzip` module — try `--values=\'{"enabled":true}\'` against module `gzip`.',
		);
		return $hints[ $key ] ?? '';
	}

	/**
	 * Schema keys closest to a rejected key, so a typo gets a pointer rather
	 * than a bare refusal. Levenshtein over the schema, nearest three. (#206)
	 *
	 * @param string $slug Module slug.
	 * @param string $key  Rejected input key.
	 * @return string[] Suggested key names, nearest first.
	 */
	public static function did_you_mean( string $slug, string $key ): array {
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return array();
		}
		$scored = array();
		foreach ( array_keys( $module->settings_schema() ) as $candidate ) {
			$distance = levenshtein( $key, (string) $candidate );
			// Only near-misses: beyond a third of the key's length it's a
			// different word, and listing it would be noise.
			if ( $distance <= max( 3, (int) floor( strlen( $key ) / 3 ) ) ) {
				$scored[ (string) $candidate ] = $distance;
			}
		}
		asort( $scored );
		return array_slice( array_keys( $scored ), 0, 3 );
	}

	/**
	 * Masked hint for a stored secret: first 4 + bullets + last 4 (mirrors the
	 * support-snapshot license masking), or all-bullets for a short secret, or
	 * '' when unset. Enough to confirm "a key is saved, ending 4f2a" without
	 * disclosing it. Deterministic — unit-tested.
	 */
	public static function mask_secret_value( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( strlen( $value ) <= 8 ) {
			return str_repeat( '•', 8 );
		}
		return substr( $value, 0, 4 ) . self::SECRET_MASK_BULLETS . substr( $value, -4 );
	}

	/**
	 * Whether an incoming write value is the masked placeholder the client is
	 * echoing back, rather than a real new secret — i.e. it still carries the
	 * mask bullets. A genuine credential never contains the bullet run, so this
	 * can't swallow a real key. update() uses it to keep the stored secret.
	 *
	 * An EMPTY string is NOT a mask echo — it's a deliberate clear, so it flows
	 * through to storage and removes the credential. The dashboard always
	 * re-sends the masked hint (with bullets) on an unrelated save, never an
	 * empty string, so this still can't wipe a key by accident. (#115, QA B7)
	 */
	public static function is_masked_secret( string $value ): bool {
		return false !== strpos( $value, self::SECRET_MASK_BULLETS );
	}

	/**
	 * Encrypt a plaintext secret for storage. Idempotent: an already-encrypted
	 * value (carrying the marker) is returned unchanged, so module migrations
	 * can call this over existing rows without double-wrapping. Empty stays
	 * empty. Used by update() and by the per-module encrypt-on-upgrade
	 * migrations. (#115)
	 */
	public static function encrypt_for_storage( string $value ): string {
		if ( '' === $value || 0 === strpos( $value, self::SECRET_CIPHER_PREFIX ) ) {
			return $value;
		}
		return self::encrypt( $value );
	}

	/**
	 * 32-byte encryption key derived from this site's WordPress salts, so the
	 * ciphertext is bound to the install and never stored alongside the data.
	 * Rotating the salts makes existing secrets undecryptable — decrypt() then
	 * returns '' (treated as "unset", the user re-enters the key) rather than
	 * fataling. Uses AUTH_KEY + SECURE_AUTH_SALT, falling back to wp_salt().
	 */
	private static function secret_key(): string {
		$material = '';
		if ( defined( 'AUTH_KEY' ) ) {
			$material .= (string) AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_SALT' ) ) {
			$material .= (string) SECURE_AUTH_SALT;
		}
		if ( '' === $material && function_exists( 'wp_salt' ) ) {
			$material = (string) wp_salt( 'secure_auth' );
		}
		return sodium_crypto_generichash( 'xspeed-secret-v1|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Authenticated-encrypt a non-empty plaintext with libsodium's secretbox
	 * (XSalsa20-Poly1305). The random nonce is prepended to the ciphertext and
	 * the whole thing base64'd behind the version marker. libsodium ships in
	 * PHP core from 7.2 (our floor is 7.4); if it were somehow unavailable we
	 * store plaintext rather than fatal — masking on read still applies.
	 */
	private static function encrypt( string $plain ): string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return $plain;
		}
		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, self::secret_key() );
		} catch ( \Throwable $e ) {
			return $plain;
		}
		return self::SECRET_CIPHER_PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding for ciphertext, not obfuscation.
	}

	/**
	 * Reverse encrypt(). A value without the marker is legacy plaintext (or
	 * empty) and is returned as-is — the encryption rollout is lazy, so reads
	 * keep working before the first re-save. A marked value that fails to
	 * decrypt (salts rotated, row tampered) returns '' so the caller behaves as
	 * "no credential set", never a fatal.
	 */
	private static function decrypt( string $stored ): string {
		if ( 0 !== strpos( $stored, self::SECRET_CIPHER_PREFIX ) ) {
			return $stored;
		}
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, strlen( self::SECRET_CIPHER_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding our own ciphertext envelope.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::secret_key() );
		} catch ( \Throwable $e ) {
			return '';
		}
		return ( false === $plain ) ? '' : $plain;
	}

	/**
	 * Whether the current write is an MCP write that may NOT touch secret
	 * fields — i.e. it came in over MCP and the connection lacks the `configure`
	 * grant. Keeps credential writes off the default MCP surface (#116). Guarded
	 * by class_exists so Settings_Manager never hard-depends on the MCP module.
	 */
	private static function mcp_write_blocked(): bool {
		if ( ! class_exists( '\\XSpeed\\Modules\\Mcp\\Mcp_Tools' ) ) {
			return false;
		}
		return \XSpeed\Modules\Mcp\Mcp_Tools::in_dispatch()
			&& ! \XSpeed\Modules\Mcp\Mcp_Tools::can_configure();
	}

	/** Compact human form of a setting value for the change log. */
	private static function describe_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'on' : 'off';
		}
		if ( is_array( $value ) ) {
			return count( $value ) . ' item' . ( 1 === count( $value ) ? '' : 's' );
		}
		if ( null === $value ) {
			return '—';
		}
		$str = (string) $value;
		return strlen( $str ) > 40 ? substr( $str, 0, 39 ) . '…' : $str;
	}

	/**
	 * Which surface performed this write. MCP is detected via the tool
	 * dispatcher's in-flight flag; the dashboard UI writes through REST.
	 */
	private static function source_channel(): string {
		if ( class_exists( '\\XSpeed\\Modules\\Mcp\\Mcp_Tools' ) && \XSpeed\Modules\Mcp\Mcp_Tools::in_dispatch() ) {
			return 'mcp';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'dashboard';
		}
		return 'admin';
	}

	/**
	 * Run any pending schema migrations for a module. Called by
	 * Module_Registry before boot(). Idempotent — migrations only run once
	 * per version bump because we persist `_version` after each successful
	 * migration step.
	 */
	public static function run_migrations( Module $module ): void {
		$migrations = $module->migrations();
		if ( empty( $migrations ) ) {
			return;
		}
		$option_key = self::option_key( $module->slug() );
		$stored     = get_option( $option_key, null );
		if ( null === $stored ) {
			return; // fresh install — no data to migrate.
		}
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$from = isset( $stored['_version'] ) ? (string) $stored['_version'] : '0.0.0';

		// Sort migrations by version ascending.
		uksort(
			$migrations,
			static function ( $a, $b ) {
				return version_compare( (string) $a, (string) $b );
			}
		);

		$dirty = false;
		foreach ( $migrations as $target => $callable ) {
			$target = (string) $target;
			if ( version_compare( $from, $target, '>=' ) ) {
				continue;
			}
			$migrated = call_user_func( $callable, $stored );
			if ( is_array( $migrated ) ) {
				$stored             = $migrated;
				$stored['_version'] = $target;
				$from               = $target;
				$dirty              = true;
			}
		}

		if ( $dirty ) {
			update_option( $option_key, $stored );
		}
	}

	/**
	 * Coerce a stored value to the schema's declared type — used on read
	 * to defend against options edited by hand or imported across versions.
	 */
	private static function coerce( $value, array $spec ) {
		$type = $spec['type'] ?? 'string';
		switch ( $type ) {
			case 'bool':
				return (bool) $value;
			case 'int':
				$v = (int) $value;
				if ( isset( $spec['min'] ) ) {
					$v = max( (int) $spec['min'], $v );
				}
				if ( isset( $spec['max'] ) ) {
					$v = min( (int) $spec['max'], $v );
				}
				return $v;
			case 'enum':
				return in_array( $value, $spec['options'] ?? array(), true )
					? $value
					: ( $spec['default'] ?? null );
			case 'list':
				if ( ! is_array( $value ) ) {
					return $spec['default'] ?? array();
				}
				return array_values( array_filter( $value, 'is_scalar' ) );
			case 'url':
				// A deliberately-cleared URL must read back as empty, not snap
				// to the schema default — `?:` swallowed the empty string and
				// resurrected the default on every read. (#197)
				if ( '' === trim( (string) $value ) ) {
					return '';
				}
				$url = esc_url_raw( (string) $value );
				if ( ! empty( $spec['endpoint'] ) && ! self::is_endpoint_url( $url ) ) {
					// A hand-edited or pre-validation stored value that can't
					// be called reads back as empty, so consumers see "no
					// endpoint configured" instead of silently failing on it.
					return $spec['default'] ?? '';
				}
				return $url ?: ( $spec['default'] ?? '' );
			case 'media':
				// Media-library image URL. Empty is a valid "no image" state.
				// esc_url_raw alone lets through any safe URL (…/evil.txt,
				// non-images) which then renders as a broken <img>; require it
				// to look like an image and drop anything else to empty.
				$media = esc_url_raw( (string) $value );
				return ( '' === $media || self::is_image_url( $media ) ) ? $media : '';
			case 'secret':
				// A credential (API token, password, …). Stored encrypted at
				// rest (SECRET_CIPHER_PREFIX). Reading decrypts to plaintext so
				// the engine — Cloudflare purge, Redis auth — gets the real
				// value; the masking that keeps it out of REST/MCP/dashboard
				// payloads happens later, at the output boundary (get_public),
				// never here. Legacy unencrypted values pass straight through.
				return self::decrypt( (string) $value );
			case 'string':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Validate one field; returns [ coerced_value, was_valid ]. Distinct
	 * from coerce() because validate is strict (out-of-range int is
	 * INVALID) while coerce is forgiving (clamps to range).
	 */
	private static function validate_field( $value, array $spec ): array {
		$type = $spec['type'] ?? 'string';
		switch ( $type ) {
			case 'bool':
				// Strictly validate (don't blindly (bool)-cast). A plain cast
				// treated every non-empty string as true, so a client sending
				// the string "false" (or any junk text) silently ENABLED the
				// toggle. filter_var with FILTER_NULL_ON_FAILURE accepts the
				// real bool-ish forms (true/false, 1/0, "1"/"0", "true"/
				// "false", "yes"/"no", "on"/"off") and returns null for
				// anything else — which we report as invalid so the previous
				// stored value is kept, mirroring int/enum. (FBS-82158)
				$b = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if ( null === $b ) {
					return array( null, false );
				}
				return array( $b, true );
			case 'int':
				if ( ! is_numeric( $value ) ) {
					return array( null, false );
				}
				$v = (int) $value;
				if ( isset( $spec['min'] ) && $v < (int) $spec['min'] ) {
					return array( null, false );
				}
				if ( isset( $spec['max'] ) && $v > (int) $spec['max'] ) {
					return array( null, false );
				}
				return array( $v, true );
			case 'enum':
				$ok = in_array( $value, $spec['options'] ?? array(), true );
				return array( $ok ? $value : null, $ok );
			case 'list':
				if ( ! is_array( $value ) ) {
					return array( null, false );
				}
				$item_type = $spec['item_type'] ?? 'string';
				$out       = array();
				foreach ( $value as $item ) {
					// Skip non-scalar items (e.g. a nested array). Casting one
					// with (string) emits an "Array to string conversion"
					// warning and stores the garbage literal "Array" — coerce()
					// already filters these via is_scalar; mirror it here.
					// (FBS-82172 Bug 4)
					if ( ! is_scalar( $item ) ) {
						continue;
					}
					if ( 'url' === $item_type ) {
						$u = esc_url_raw( (string) $item );
						if ( $u ) {
							$out[] = $u;
						}
					} else {
						$out[] = sanitize_text_field( (string) $item );
					}
				}
				return array( $out, true );
			case 'url':
				// Empty is a valid "cleared" state, not invalid input — same
				// as `media` below. Reporting it invalid made the previous
				// stored value stick, so clearing a URL field appeared to
				// "come back" a moment later when the save echo landed. (#197)
				if ( '' === trim( (string) $value ) ) {
					return array( '', true );
				}
				$u = esc_url_raw( (string) $value );
				if ( ! empty( $spec['endpoint'] ) && ! self::is_endpoint_url( $u ) ) {
					return array( null, false );
				}
				return array( $u, (bool) $u );
			case 'media':
				// Empty (cleared logo) is valid; any non-empty value must be a
				// safe URL after esc_url_raw AND look like an image, so a
				// non-image URL (…/evil.txt) is rejected rather than stored to
				// render as a broken <img>.
				$m = esc_url_raw( (string) $value );
				if ( '' === (string) $value ) {
					return array( '', true );
				}
				$ok = '' !== $m && self::is_image_url( $m );
				return array( $ok ? $m : '', $ok );
			case 'secret':
				// Validated like a string; encryption is applied uniformly in
				// update() after this returns, so a secret carried over from the
				// current stored value gets encrypted the same way a freshly
				// entered one does. Masked placeholders never reach here — update()
				// filters them out before validating. (#115)
				return array( sanitize_text_field( (string) $value ), true );
			case 'string':
			default:
				return array( sanitize_text_field( (string) $value ), true );
		}
	}

	/**
	 * Whether a URL is something an HTTP client could actually call.
	 *
	 * `url` fields flagged `endpoint => true` in the schema hold URLs the
	 * plugin will POST to (a critical-CSS generator, an unused-CSS
	 * generator). esc_url_raw() alone is not enough for those:
	 * `nahid@wpdeveloper.com` — an email address pasted into the field —
	 * comes back as `http://nahid@wpdeveloper.com`, a syntactically valid
	 * URL whose userinfo is the whole address, and the feature then fails
	 * silently for as long as nobody rereads the field. Require a real
	 * http(s) scheme and a host, and refuse userinfo outright: no
	 * endpoint of ours authenticates that way, and accepting it is how
	 * that address survived in production. (xspeed-pro#77)
	 */
	public static function is_endpoint_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		if ( ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( '' === (string) ( $parts['host'] ?? '' ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a URL looks like an image — used to gate `media` fields so a
	 * non-image URL can't be stored and later rendered as a broken <img>
	 * (e.g. the white-label brand logo, FBS-82222). Tests the path extension
	 * against the known image types (query/fragment tolerated). Not a content
	 * check — a cheap, deterministic guard that pairs with the front-end
	 * onError fallback; the Media Library picker already yields conforming
	 * http(s) upload URLs. (data: URIs are stripped by esc_url_raw upstream,
	 * since `data` isn't an allowed protocol, so they never reach here.)
	 */
	private static function is_image_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url ) {
			return false;
		}
		// Drop the query string + fragment so ?ver=… / #frag don't defeat the
		// extension test (e.g. logo.webp?v=2). Plain string ops — no WP URL
		// parser dependency on this low-level coercion path.
		$path = (string) preg_replace( '/[?#].*$/', '', $url );
		return (bool) preg_match( '/\.(jpe?g|png|gif|svg|webp|avif|ico|bmp)$/i', $path );
	}

	private static function defaults_from_schema( array $schema ): array {
		$out = array();
		foreach ( $schema as $key => $spec ) {
			$out[ $key ] = $spec['default'] ?? null;
		}
		return $out;
	}

	private static function option_key( string $slug ): string {
		return self::OPTION_PREFIX . $slug;
	}
}
