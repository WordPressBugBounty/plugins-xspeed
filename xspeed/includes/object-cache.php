<?php
/**
 * XSPEED_OBJECT_CACHE_DROPIN
 *
 * xSpeed's self-contained persistent object cache drop-in.
 *
 * Supports Redis (phpredis extension, with a graceful no-op fall-through when
 * absent) and Memcached. Implements the full WordPress object-cache API as a
 * global WP_Object_Cache class + wp_cache_* functions.
 *
 * Design principles:
 *   - NEVER fatal the site. If the backend can't be reached, we degrade to a
 *     non-persistent in-request array cache. A misconfigured Redis must never
 *     take a site down — that's why connection_timeout defaults low.
 *   - Read config from constants written by xSpeed into wp-config.php
 *     (XSPEED_OC_*), falling back to the widely-used WP_REDIS_* conventions so
 *     existing setups keep working.
 *   - WP-compliant: groups, global groups, multisite blog-id prefixing,
 *     add/get/set/delete/incr/decr/replace, flush, get_multiple, add_multiple.
 *
 * This file is copied to wp-content/object-cache.php by xSpeed when the user
 * clicks "Enable Object Cache". It is loaded by WordPress very early
 * (wp-settings.php), before most of core — so it must be self-sufficient.
 *
 * @package XSpeed
 */

defined( 'ABSPATH' ) || exit;

// -----------------------------------------------------------------------------
// Config resolution. Prefer xSpeed's own XSPEED_OC_* constants; fall back to the
// de-facto WP_REDIS_* / $memcached_servers conventions so we interoperate.
// -----------------------------------------------------------------------------
if ( ! function_exists( 'xspeed_oc_config' ) ) {
	/**
	 * Resolve a single config value from constants with sane defaults.
	 */
	function xspeed_oc_config( $key, $default ) {
		// Keep this map in lockstep with the `constants` declared in
		// ObjectCacheModule::settings_schema(). The drop-in loads before
		// WordPress, so it cannot call Settings_Manager and the list genuinely
		// exists twice; ObjectCacheConstantParityTest asserts the two agree
		// field-for-field, because one rule in two files is how the last
		// ownership bug survived three review rounds. (#398)
		// The backend decides which host/port field to read, so it has to be
		// resolved the same way everything else is: constant first, then the
		// sidecar. Reading only the constant made a sidecar-configured
		// Memcached site read Redis's host and port.
		if ( defined( 'XSPEED_OC_BACKEND' ) ) {
			$backend = (string) constant( 'XSPEED_OC_BACKEND' );
		} else {
			$sc      = xspeed_oc_sidecar();
			$backend = isset( $sc['backend'] ) ? (string) $sc['backend'] : '';
		}
		$is_memcached = ( 'memcached' === $backend );

		$map = array(
			'backend'  => array( 'XSPEED_OC_BACKEND' ),
			// WP_REDIS_* are Redis's own conventions, so they only answer when
			// Redis is the backend. Consulting them on Memcached made a
			// Memcached site connect to the Redis host and port -- the same
			// cross-backend bleed that one shared XSPEED_OC_HOST/PORT pair
			// caused in the panel. (#398)
			// XSPEED_OC_HOST/PORT trail the Memcached names for BACKWARD
			// COMPATIBILITY: installs configured before the split have the
			// generic pair in their block, and dropping it would move them to
			// 127.0.0.1 on upgrade -- breaking a working cache. New writes use
			// XSPEED_OC_MC_*, so the fallback fades out on the next save.
			'host'     => $is_memcached
				? array( 'XSPEED_OC_MC_HOST', 'XSPEED_OC_HOST' )
				: array( 'XSPEED_OC_HOST', 'WP_REDIS_HOST' ),
			'port'     => $is_memcached
				? array( 'XSPEED_OC_MC_PORT', 'XSPEED_OC_PORT' )
				: array( 'XSPEED_OC_PORT', 'WP_REDIS_PORT' ),
			// WP_REDIS_PASSWORD trails the user names on purpose: in its array
			// form it carries the ACL username too, so a site that defines only
			// the password pair still authenticates as the right user.
			'user'     => array( 'XSPEED_OC_USER', 'WP_REDIS_USER', 'WP_REDIS_PASSWORD' ),
			'password' => array( 'XSPEED_OC_PASSWORD', 'WP_REDIS_PASSWORD' ),
			'database' => array( 'XSPEED_OC_DATABASE', 'WP_REDIS_DATABASE' ),
			'timeout'  => array( 'XSPEED_OC_TIMEOUT', 'WP_REDIS_TIMEOUT' ),
			'salt'     => array( 'XSPEED_OC_SALT', 'WP_REDIS_PREFIX', 'WP_CACHE_KEY_SALT' ),
			'persist'  => array( 'XSPEED_OC_PERSISTENT', 'WP_REDIS_PERSISTENT' ),
		);
		/*
		 * This drop-in's short keys mapped to the schema field names the
		 * sidecar stores. Redis and Memcached each name their own host/port
		 * field, so switching backend cannot make one read the other's value.
		 */
		$sidecar_map  = array(
			'backend'  => 'backend',
			'host'     => $is_memcached ? 'memcached_host' : 'redis_host',
			'port'     => $is_memcached ? 'memcached_port' : 'redis_port',
			'user'     => 'redis_user',
			'password' => 'redis_password',
			'database' => 'redis_database',
			'timeout'  => 'connection_timeout',
			'salt'     => 'key_prefix',
			'persist'  => 'persistent',
		);

		/*
		 * $memcached_servers is Memcached's convention the way WP_REDIS_* is
		 * Redis's -- W3TC and the Memcached Object Cache drop-in both read it,
		 * and hosts write it. Settings_Manager resolves it for the panel, so
		 * without it here the panel and Test connection reported the host's
		 * server while the drop-in quietly used 127.0.0.1 and cached nothing:
		 * the two-truths split this whole change exists to close, on the one
		 * backend where the convention IS a global. Ranked below our own
		 * constants, matching the schema's constants-then-global order. (#398)
		 */
		if ( $is_memcached && ( 'host' === $key || 'port' === $key ) ) {
			$ours = 'host' === $key
				? array( 'XSPEED_OC_MC_HOST', 'XSPEED_OC_HOST' )
				: array( 'XSPEED_OC_MC_PORT', 'XSPEED_OC_PORT' );
			$pinned = false;
			foreach ( $ours as $const ) {
				if ( defined( $const ) ) {
					$pinned = true;
					break;
				}
			}
			if ( ! $pinned && isset( $GLOBALS['memcached_servers'] ) ) {
				$pair = xspeed_oc_first_memcached_server( $GLOBALS['memcached_servers'] );
				$slot = 'host' === $key ? 0 : 1;
				if ( null !== $pair && null !== $pair[ $slot ] ) {
					return $pair[ $slot ];
				}
			}
		}

		if ( isset( $map[ $key ] ) ) {
			/*
			 * A host's define outranks one we wrote, whatever order this map
			 * lists them in -- the same rule Settings_Manager applies for the
			 * panel. Ours only mirrors the option row, so preferring it meant a
			 * rotated host credential was ignored until someone saved. Two
			 * passes rather than a reorder, because the map's order is still
			 * right for every other case (ours before the convention). (#398)
			 */
			/*
			 * Only a name that is not ours to begin with can be promoted. Our
			 * OWN legacy aliases must never be: XSPEED_OC_HOST/PORT trail the
			 * Memcached names for backward compatibility and, on a site that
			 * ran Redis first, hold the REDIS host. Promoting one because it
			 * happened to sit outside the current fence pointed a Memcached
			 * site at the Redis server -- while the panel, which gates those
			 * aliases on the backend (`constants_when`), still showed the right
			 * value. That is the panel/runtime split this change exists to
			 * close, reopened from the other side.
			 *
			 * WP_CACHE_KEY_SALT is NEVER promoted (#430). It is not a host's
			 * namespace declaration the way WP_REDIS_* is -- it is WordPress's
			 * OWN cache-uniqueness salt, present on almost every install and
			 * usually a random value. On xCloud the provisioner writes the
			 * correct namespace as XSPEED_OC_SALT AND WordPress carries its
			 * own random WP_CACHE_KEY_SALT beside it; promoting the latter over
			 * ours pointed every write outside the ACL namespace (NOPERM) while
			 * released code -- which had no promotion pass -- worked. So for the
			 * salt, our own define always wins over WP_CACHE_KEY_SALT; a genuine
			 * WP_REDIS_PREFIX still ranks by position like any other convention.
			 */
			$ours   = xspeed_oc_our_constants();
			$sorted = array();
			foreach ( $map[ $key ] as $const ) {
				if ( 0 === strpos( $const, 'XSPEED_OC_' ) ) {
					continue;
				}
				if ( 'WP_CACHE_KEY_SALT' === $const ) {
					continue;
				}
				if ( defined( $const ) && ! in_array( $const, $ours, true ) ) {
					$sorted[] = $const;
				}
			}
			foreach ( $map[ $key ] as $const ) {
				if ( ! in_array( $const, $sorted, true ) ) {
					$sorted[] = $const;
				}
			}

			foreach ( $sorted as $const ) {
				if ( ! defined( $const ) ) {
					continue;
				}
				$value = constant( $const );
				// WP_REDIS_PASSWORD only answers for `user` in its array form,
				// which carries the ACL username. As a plain string it is just
				// a password: skip it, or we would authenticate with the
				// password as the username.
				if ( 'user' === $key && 'WP_REDIS_PASSWORD' === $const && ! is_array( $value ) ) {
					continue;
				}
				return xspeed_oc_credential_part( $key, $value );
			}
		}

		/*
		 * No constant answered. On a host where wp-config.php is not writable
		 * the panel stores the configuration in a sidecar beside this drop-in
		 * instead, so consult it before falling back to the built-in default --
		 * otherwise every setting the user saved there would be ignored at
		 * runtime while the panel showed it as active. (#398)
		 */
		$sidecar = xspeed_oc_sidecar();
		$field   = isset( $sidecar_map[ $key ] ) ? $sidecar_map[ $key ] : null;
		if ( null !== $field && array_key_exists( $field, $sidecar ) ) {
			return xspeed_oc_credential_part( $key, $sidecar[ $field ] );
		}

		return $default;
	}
}

if ( ! function_exists( 'xspeed_oc_first_memcached_server' ) ) {
	/**
	 * Host and port of the first server in a `$memcached_servers` global.
	 *
	 * Two shapes are in circulation and hosts write both:
	 *
	 *     array( array( 'host', 11211 ) )              // W3TC pair form
	 *     array( 'default' => array( 'host:11211' ) )  // Memcached Object Cache
	 *
	 * Supporting only the first left the second reading the whole "host:port"
	 * string as the hostname -- or missing entirely, since its bucket is keyed
	 * `default` rather than 0. Kept in one function because Settings_Manager
	 * has to answer identically or the panel and the runtime disagree, which is
	 * the bug this whole change closes. (#398)
	 *
	 * @param mixed $servers The global's value, unvalidated.
	 * @return array{0:?string,1:?int}|null Host and port, either possibly null.
	 */
	function xspeed_oc_first_memcached_server( $servers ) {
		if ( ! is_array( $servers ) || array() === $servers ) {
			return null;
		}

		// Either the 0th bucket or, for the keyed form, whichever comes first.
		$bucket = array_key_exists( 0, $servers ) ? $servers[0] : reset( $servers );

		/*
		 * A bucket is EITHER a [host, port] pair or a list of server entries.
		 * Telling them apart by shape, not by nesting depth: descending into
		 * array( 'mc.example', 11211 ) yields the host string and drops the
		 * port on the floor, which is the commonest form there is.
		 */
		$entry = $bucket;
		if ( is_array( $bucket ) && isset( $bucket[0] ) && is_array( $bucket[0] ) ) {
			$entry = $bucket[0];
		}

		// Pair form: [ host, port ].
		if ( is_array( $entry ) ) {
			$host = isset( $entry[0] ) && ! is_array( $entry[0] ) ? (string) $entry[0] : null;
			$port = isset( $entry[1] ) && ! is_array( $entry[1] ) ? (int) $entry[1] : null;
			// A single-element list, array( 'host:port' ), is the keyed form's
			// bucket rather than a pair -- fall through to the string parser.
			if ( null !== $host && null === $port && is_string( $entry[0] ) && false !== strpos( $entry[0], ':' ) ) {
				$entry = $entry[0];
			} else {
				return ( null === $host && null === $port ) ? null : array( $host, $port );
			}
		}

		if ( ! is_string( $entry ) || '' === $entry ) {
			return null;
		}

		// "host:port", or a bare host. A unix socket path has no port and can
		// contain no colon we should split on, so only split the LAST one and
		// only when what follows is numeric.
		$at = strrpos( $entry, ':' );
		if ( false !== $at && ctype_digit( substr( $entry, $at + 1 ) ) ) {
			return array( substr( $entry, 0, $at ), (int) substr( $entry, $at + 1 ) );
		}
		return array( $entry, null );
	}
}

if ( ! function_exists( 'xspeed_oc_our_constants' ) ) {
	/**
	 * Constant names defined inside OUR fenced block in wp-config.php.
	 *
	 * Ownership is decided by WHERE a define sits, exactly as
	 * Object_Cache::our_constants() decides it for the admin half. The drop-in
	 * needs the same answer for the same reason the panel does: a define we
	 * wrote is only a mirror of the option row, so a HOST define has to outrank
	 * it. Without this the drop-in kept connecting to our stale snapshot after
	 * a host rotated its credentials, while the panel -- which does apply the
	 * rule -- showed the new one. Panel and runtime disagreeing is the whole
	 * bug this change exists to remove. (#398)
	 *
	 * @return string[]
	 */
	function xspeed_oc_our_constants() {
		static $names = null;
		if ( null !== $names ) {
			return $names;
		}
		$names = array();

		// ABSPATH is defined by wp-load.php before the drop-in is included.
		$path = defined( 'ABSPATH' ) ? ABSPATH . 'wp-config.php' : '';
		if ( '' === $path || ! is_readable( $path ) ) {
			// One level up is the standard "wp-config outside the root" layout.
			$alt  = defined( 'ABSPATH' ) ? dirname( ABSPATH ) . '/wp-config.php' : '';
			$path = ( '' !== $alt && is_readable( $alt ) ) ? $alt : '';
		}
		if ( '' === $path ) {
			return $names;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- runs before WordPress; WP_Filesystem does not exist yet.
		$config = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable wp-config just means "we own nothing".
		if ( '' === $config ) {
			return $names;
		}
		if ( ! preg_match( '/\/\* BEGIN xSpeed Object Cache \*\/(.*?)\/\* END xSpeed Object Cache \*\//s', $config, $m ) ) {
			return $names;
		}
		if ( preg_match_all( "/define\(\s*'([A-Z0-9_]+)'/", $m[1], $found ) ) {
			$names = $found[1];
		}
		return $names;
	}
}

if ( ! function_exists( 'xspeed_oc_sidecar' ) ) {
	/**
	 * Configuration written beside this drop-in when wp-config.php is
	 * read-only. Returns an empty array when there is none.
	 *
	 * This file IS wp-content/object-cache.php, so the sidecar sits in the
	 * same directory -- no constant needed to locate it, which matters because
	 * WP_CONTENT_DIR is not guaranteed to be defined this early.
	 *
	 * @return array<string,mixed>
	 */
	function xspeed_oc_sidecar() {
		static $data = null;
		if ( null !== $data ) {
			return $data;
		}
		$data = array();
		$path = __DIR__ . '/xspeed-object-cache.php';
		if ( ! is_readable( $path ) ) {
			return $data;
		}

		/*
		 * This runs before WordPress, so a parse error here is a white screen
		 * on every request rather than a degraded cache. The writer renames
		 * into place atomically, but a file truncated by something else -- a
		 * failed deploy, a partial restore -- must not take the site down, so
		 * the include is guarded and any failure degrades to "no sidecar".
		 */
		try {
			$loaded = @include $path; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a broken sidecar must not white-screen the site.
			if ( is_array( $loaded ) ) {
				$data = $loaded;
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- degrade to no sidecar.
			$data = array();
		}
		return $data;
	}
}

if ( ! function_exists( 'xspeed_oc_salt' ) ) {
	/**
	 * Resolve the salt that namespaces this site's keys.
	 *
	 * Normally the salt constant is written into wp-config.php on enable. When
	 * wp-config is NOT writable (some managed hosts) the drop-in still gets
	 * installed, so without a fallback every key would come out as
	 * `:{blog}:{group}:{key}` — identical on every install. Two sites sharing a
	 * Redis/Memcached server would then read each other's blog-details /
	 * blog-lookup entries and the second site would redirect to the first.
	 *
	 * Derived the same way Object_Cache::derive_salt() does, so a site keeps
	 * the same namespace whether the constant is present or not.
	 *
	 * @return string Non-empty salt.
	 */
	function xspeed_oc_salt() {
		$salt = (string) xspeed_oc_config( 'salt', '' );
		if ( '' !== $salt ) {
			return $salt;
		}

		global $table_prefix;

		$url = '';
		if ( defined( 'WP_HOME' ) ) {
			$url = (string) WP_HOME;
		} elseif ( defined( 'WP_SITEURL' ) ) {
			$url = (string) WP_SITEURL;
		}

		// WP_HOME / WP_SITEURL are OPTIONAL and absent from a stock
		// wp-config.php, so the URL is usually empty here — DB_NAME alone
		// would then be identical for two sites sharing one database and the
		// collision this salt exists to prevent would come straight back.
		// $table_prefix separates them: it is assigned in wp-config.php itself,
		// so it is already a global by the time the drop-in loads (well before
		// $wpdb exists). ABSPATH is added whenever no URL was available, since
		// two installs on one database necessarily live in different
		// directories.
		$parts = array(
			$url,
			defined( 'DB_NAME' ) ? (string) DB_NAME : '',
			isset( $table_prefix ) ? (string) $table_prefix : '',
		);
		if ( '' === $url ) {
			$parts[] = defined( 'ABSPATH' ) ? (string) ABSPATH : '';
		}

		$seed = implode( '|', $parts );
		if ( '' === trim( $seed, '|' ) ) {
			$seed = 'xspeed';
		}

		return 'xs' . substr( md5( $seed ), 0, 12 );
	}
}

if ( ! function_exists( 'xspeed_oc_credential_part' ) ) {
	/**
	 * Unpack the array form of a Redis credential constant.
	 *
	 * Managed hosts that provision Redis ACL users (xCloud, Cloudways) ship
	 * the pair in one define:
	 *
	 *     define( 'WP_REDIS_PASSWORD', array( 'acl_user', 's3cret' ) );
	 *
	 * Casting that to string yields "Array" and a notice, so the site would
	 * authenticate with garbage. Split it here, at the single point both
	 * `user` and `password` resolve through, rather than in each caller.
	 *
	 * @param string $key   Config key being resolved.
	 * @param mixed  $value Raw constant value.
	 * @return mixed
	 */
	function xspeed_oc_credential_part( $key, $value ) {
		if ( ! is_array( $value ) || ( 'user' !== $key && 'password' !== $key ) ) {
			return $value;
		}
		// Scalars only. A nested value would stringify to "Array" and emit a
		// warning on EVERY request from the drop-in -- before headers are sent,
		// on the code path whose whole design rule is never to break the site.
		$parts = array_values( array_filter( $value, 'is_scalar' ) );
		if ( 'user' === $key ) {
			// A one-element array is a password with no ACL user.
			return count( $parts ) > 1 ? (string) $parts[0] : '';
		}
		return (string) ( count( $parts ) > 1 ? $parts[1] : ( $parts[0] ?? '' ) );
	}
}

// -----------------------------------------------------------------------------
// WordPress object-cache API surface. Thin wrappers over the global instance.
// -----------------------------------------------------------------------------
if ( ! function_exists( 'wp_cache_init' ) ) {

	function wp_cache_init() {
		$GLOBALS['wp_object_cache'] = new XSpeed_Object_Cache();
	}

	function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
		return $GLOBALS['wp_object_cache']->add( $key, $data, $group, (int) $expire );
	}

	function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
		$out = array();
		foreach ( $data as $key => $value ) {
			$out[ $key ] = wp_cache_add( $key, $value, $group, $expire );
		}
		return $out;
	}

	function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
		return $GLOBALS['wp_object_cache']->replace( $key, $data, $group, (int) $expire );
	}

	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		return $GLOBALS['wp_object_cache']->set( $key, $data, $group, (int) $expire );
	}

	function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
		$out = array();
		foreach ( $data as $key => $value ) {
			$out[ $key ] = wp_cache_set( $key, $value, $group, $expire );
		}
		return $out;
	}

	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		return $GLOBALS['wp_object_cache']->get( $key, $group, $force, $found );
	}

	function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
		return $GLOBALS['wp_object_cache']->get_multiple( $keys, $group, $force );
	}

	function wp_cache_delete( $key, $group = '' ) {
		return $GLOBALS['wp_object_cache']->delete( $key, $group );
	}

	function wp_cache_delete_multiple( array $keys, $group = '' ) {
		$out = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = wp_cache_delete( $key, $group );
		}
		return $out;
	}

	function wp_cache_incr( $key, $offset = 1, $group = '' ) {
		return $GLOBALS['wp_object_cache']->incr( $key, (int) $offset, $group );
	}

	function wp_cache_decr( $key, $offset = 1, $group = '' ) {
		return $GLOBALS['wp_object_cache']->decr( $key, (int) $offset, $group );
	}

	function wp_cache_flush() {
		return $GLOBALS['wp_object_cache']->flush();
	}

	function wp_cache_flush_runtime() {
		return $GLOBALS['wp_object_cache']->flush_runtime();
	}

	function wp_cache_flush_group( $group ) {
		return $GLOBALS['wp_object_cache']->flush_group( $group );
	}

	function wp_cache_supports( $feature ) {
		return in_array( $feature, array( 'get_multiple', 'set_multiple', 'add_multiple', 'delete_multiple', 'flush_runtime', 'flush_group' ), true );
	}

	function wp_cache_close() {
		return $GLOBALS['wp_object_cache']->close();
	}

	function wp_cache_add_global_groups( $groups ) {
		$GLOBALS['wp_object_cache']->add_global_groups( $groups );
	}

	function wp_cache_add_non_persistent_groups( $groups ) {
		$GLOBALS['wp_object_cache']->add_non_persistent_groups( $groups );
	}

	function wp_cache_switch_to_blog( $blog_id ) {
		$GLOBALS['wp_object_cache']->switch_to_blog( (int) $blog_id );
	}

	function wp_cache_reset() {
		// Deprecated in core; kept for back-compat.
		return $GLOBALS['wp_object_cache']->flush_runtime();
	}
}

// -----------------------------------------------------------------------------
// The cache implementation.
// -----------------------------------------------------------------------------
if ( ! class_exists( 'XSpeed_Object_Cache' ) ) {

	class XSpeed_Object_Cache {

		/** @var array In-request cache (always populated; also the fallback store). */
		private $cache = array();

		/** @var \Redis|\Memcached|null Persistent backend handle, or null when degraded. */
		private $conn = null;

		/** @var string redis|memcached */
		private $backend = 'redis';

		/** @var string Concrete client driving a Redis backend: phpredis|builtin. */
		private $client = 'phpredis';

		/** @var bool True once a persistent backend is connected. */
		private $persistent = false;

		/**
		 * @var bool True when the drop-in is active but could NOT connect a
		 * persistent backend, so it's silently serving a non-persistent
		 * in-request cache. Surfaced so the dashboard can report "degraded"
		 * instead of implying object caching is healthy. (FBS-82210)
		 */
		public $degraded = false;

		/** @var string Key salt / prefix. */
		private $salt = '';

		/** Option holding the Memcached generation floor (see generation_floor()). */
		const GENERATION_OPTION = 'xspeed_oc_generation';

		/**
		 * @var int|null This site's namespace generation, resolved lazily once
		 * per request. Advancing it is how Memcached flushes only this site's
		 * keys — the daemon offers no way to enumerate or scope a real flush.
		 * Null until first read; 1 means the original, unsuffixed key shape.
		 */
		private $generation = null;

		/**
		 * @var int|null Lowest generation this site may use, mirrored in the
		 * database so an LRU eviction of the cached counter cannot rewind the
		 * namespace. Null until first read.
		 */
		private $generation_floor = null;

		/** @var int Current blog id (multisite prefixing). */
		private $blog_prefix = 0;

		/** @var bool */
		private $multisite = false;

		/** @var array<string,bool> Groups shared across the whole network. */
		private $global_groups = array();

		/** @var array<string,bool> Groups that must never hit the persistent store. */
		private $non_persistent_groups = array();

		/** @var int Cache hits this request. */
		public $cache_hits = 0;

		/** @var int Cache misses this request. */
		public $cache_misses = 0;

		public function __construct() {
			$this->multisite   = function_exists( 'is_multisite' ) && is_multisite();
			$this->blog_prefix = $this->multisite ? (int) get_current_blog_id() : 0;
			$this->salt        = xspeed_oc_salt();
			$this->backend     = strtolower( (string) xspeed_oc_config( 'backend', 'redis' ) );

			// Non-persistent groups. We deliberately DO persist `options`
			// (incl. the autoloaded `alloptions` blob), `comment`, and
			// `counts` — these are the highest-volume, highest-hit groups,
			// and excluding them was why the persistent cache stored only a
			// fraction of the keys a mature object cache (e.g. Redis Object
			// Cache) does. Redis Object Cache persists all of them by
			// default; matching that is the whole point of the feature.
			//
			// The historical "can't deactivate a plugin" bug (FBS-82210) was
			// a stale `alloptions` being read back after a plugin write. That
			// is NOT solved by refusing to persist options — a correct cache
			// solves it by invalidating on write, which WordPress core already
			// does: update_option()/add_option()/delete_option() each call
			// wp_cache_delete( 'alloptions', 'options' ). Our delete()
			// propagates to the backend for every persistent group (see
			// delete()), so the stale blob is removed the moment WP writes an
			// option — deactivation stays correct WITH options persisted.
			//
			// `plugins` and `themes` remain non-persistent: they're tiny,
			// rebuilt cheaply per request, and never worth a round trip.
			$this->add_non_persistent_groups(
				array( 'plugins', 'themes' )
			);

			$this->connect();
		}

		// --- Connection -----------------------------------------------------

		private function connect() {
			$timeout = (float) xspeed_oc_config( 'timeout', 1 );
			try {
				if ( 'memcached' === $this->backend ) {
					$host = (string) xspeed_oc_config( 'host', '127.0.0.1' );
					$port = (int) xspeed_oc_config( 'port', 11211 );

					if ( class_exists( 'Memcached' ) ) {
						// ext/memcached (preferred).
						$this->client = 'ext-memcached';
						$mc           = new Memcached();
						$mc->addServer( $host, $port );
						$mc->setOption( Memcached::OPT_CONNECT_TIMEOUT, (int) ( $timeout * 1000 ) );
						$stats = @$mc->getStats();
						if ( is_array( $stats ) && ! empty( $stats ) ) {
							$this->conn       = $mc;
							$this->persistent = true;
						}
					} elseif ( $this->load_builtin_memcached() ) {
						// xSpeed's own pure-PHP Memcached client.
						$this->client = 'builtin-memcached';
						$mc           = new \XSpeed\Memcached_Client( $host, $port, $timeout );
						if ( $mc->connect() && false !== $mc->version() ) {
							$this->conn       = $mc;
							$this->persistent = true;
						}
					}
				} else {
					$this->backend = 'redis';
					$host          = (string) xspeed_oc_config( 'host', '127.0.0.1' );
					$port          = (int) xspeed_oc_config( 'port', 6379 );
					$user          = (string) xspeed_oc_config( 'user', '' );
					$pass          = (string) xspeed_oc_config( 'password', '' );
					$db            = (int) xspeed_oc_config( 'database', 0 );
					$persist       = (bool) xspeed_oc_config( 'persist', false );

					if ( class_exists( 'Redis' ) ) {
						// phpredis extension (preferred).
						$this->client = 'phpredis';
						$redis        = new Redis();
						$ok           = $persist
							? @$redis->pconnect( $host, $port, $timeout )
							: @$redis->connect( $host, $port, $timeout );
						if ( $ok ) {
							// Redis 6+ ACL: ['user'=>..,'pass'=>..] when a username
							// is configured; legacy password-only otherwise.
							if ( '' !== $user ) {
								@$redis->auth( array( 'user' => $user, 'pass' => $pass ) );
							} elseif ( '' !== $pass ) {
								@$redis->auth( $pass );
							}
							if ( $db > 0 ) {
								@$redis->select( $db );
							}
							if ( '+PONG' === @$redis->ping() || true === @$redis->ping() ) {
								$this->conn       = $redis;
								$this->persistent = true;
							}
						}
					} elseif ( $this->load_builtin_client() ) {
						// xSpeed's own pure-PHP client (no extension, no library).
						$this->client = 'builtin';
						$rc           = new \XSpeed\Redis_Client( $host, $port, (float) $timeout, $persist );
						if ( $rc->connect() ) {
							if ( '' !== $pass || '' !== $user ) {
								$rc->auth( $pass, $user );
							}
							if ( $db > 0 ) {
								$rc->select( $db );
							}
							$pong = $rc->ping();
							if ( is_string( $pong ) && false !== stripos( $pong, 'PONG' ) ) {
								$this->conn       = $rc;
								$this->persistent = true;
							}
						}
					}
				}
			} catch ( \Throwable $e ) {
				// Any failure → stay in non-persistent mode. Never fatal.
				$this->conn       = null;
				$this->persistent = false;
			}

			// Connecting to an unreachable/unresolvable backend (e.g.
			// `Redis::pconnect()` → "getaddrinfo for redis failed", or
			// `stream_socket_client()` in our builtin clients) emits a PHP
			// warning. We `@`-suppress those above and degrade gracefully to a
			// non-persistent cache — but the warning still lingers in
			// `error_get_last()`. WP reads that at `admin_body_class` time and
			// tags every admin page `php-error`, which renders an empty banner
			// above the admin menu even though nothing is actually broken.
			//
			// Clear it so a degraded-but-handled backend doesn't masquerade as
			// a site error — but ONLY when the lingering error is OUR connect
			// warning. We never blindly wipe the slot: matching on the
			// originating file (this drop-in, or our bundled socket clients)
			// guarantees we can't swallow an unrelated warning that happened to
			// land in error_get_last() first. This does NOT touch the error
			// LOG — if WP_DEBUG_LOG is on, PHP already wrote the warning to
			// debug.log before this runs, and the explicit "NOT persisting"
			// diagnostic below is the signal meant for humans.
			if ( function_exists( 'error_clear_last' ) ) {
				$last = error_get_last();
				if ( is_array( $last ) && isset( $last['file'] ) ) {
					$file = $last['file'];
					if ( __FILE__ === $file
						|| false !== strpos( $file, 'class-redis-client.php' )
						|| false !== strpos( $file, 'class-memcached-client.php' )
					) {
						error_clear_last();
					}
				}
			}

			// The drop-in is installed (we're running), so if we didn't manage
			// to connect a persistent backend, object caching is effectively
			// doing nothing — writes succeed but evaporate at request end.
			// Flag it so detect()/the dashboard can report "degraded" instead
			// of a false-healthy state, and log once per request so the failure
			// is diagnosable rather than silent. (FBS-82210)
			if ( ! $this->persistent ) {
				$this->degraded = true;
				$should_log     = function_exists( 'apply_filters' )
					? apply_filters( 'xspeed_object_cache_log_degraded', true )
					: true;
				// Diagnostic only, and only when debug logging is on — keeps
				// the production error log quiet (Plugin Check flags an
				// unconditional error_log()).
				if ( $should_log && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated degraded-state diagnostic.
					error_log( sprintf(
						'[xSpeed] Object cache drop-in active but NOT persisting: could not connect a %s backend (client: %s). Serving a non-persistent in-request cache. Check the backend host/port and that the extension OR xSpeed\'s bundled client is loadable.',
						$this->backend,
						$this->client
					) );
				}
			}
		}

		/**
		 * Whether a persistent backend is actually connected. False means the
		 * drop-in is degraded (non-persistent) — see $this->degraded.
		 */
		public function is_persistent() {
			return (bool) $this->persistent;
		}

		/** Concrete client in use: phpredis|builtin|ext-memcached|builtin-memcached|''. */
		public function client_name() {
			return $this->persistent ? (string) $this->client : '';
		}

		/**
		 * Load xSpeed's own Redis_Client on demand. The drop-in runs before
		 * the plugin's autoloader, so we require the class file directly from
		 * the plugin. Returns true once \XSpeed\Redis_Client is available.
		 */
		private function load_builtin_client() {
			return $this->load_builtin( '\\XSpeed\\Redis_Client', 'class-redis-client.php' );
		}

		/**
		 * Robustly locate + require one of xSpeed's bundled, extension-free
		 * clients. This is the linchpin of the "no extension to install"
		 * promise: on a host without phpredis/ext-memcached, the drop-in MUST
		 * be able to load this file or it silently degrades to a non-persistent
		 * cache (writes return true but never reach the backend). (FBS-82210)
		 *
		 * The original implementation only tried WP_PLUGIN_DIR — which fails
		 * when the plugin dir is symlinked, when WP_PLUGIN_DIR points somewhere
		 * unexpected, or when the constant isn't defined yet at drop-in load
		 * time. We add a __DIR__-relative candidate: the drop-in lives in
		 * wp-content/, and the plugin sits at wp-content/plugins/xspeed/includes/,
		 * so we can resolve the client relative to our own location regardless
		 * of how the plugin is mounted. realpath() also resolves symlinks.
		 *
		 * @param string $class    Fully-qualified class name to check for.
		 * @param string $filename Client file under the plugin's includes/ dir.
		 * @return bool True once the class is available.
		 */
		private function load_builtin( $class, $filename ) {
			if ( class_exists( $class ) ) {
				return true;
			}

			$candidates = array();
			if ( defined( 'WP_PLUGIN_DIR' ) ) {
				$candidates[] = WP_PLUGIN_DIR . '/xspeed/includes/' . $filename;
			}
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				$candidates[] = WP_CONTENT_DIR . '/plugins/xspeed/includes/' . $filename;
				$candidates[] = WP_CONTENT_DIR . '/mu-plugins/xspeed/includes/' . $filename;
			}
			// __DIR__-relative: this file is wp-content/object-cache.php, so the
			// plugin is a sibling under plugins/xspeed/ — survives symlinks and
			// odd WP_PLUGIN_DIR values the candidates above don't.
			$candidates[] = __DIR__ . '/plugins/xspeed/includes/' . $filename;

			foreach ( $candidates as $path ) {
				if ( ! $path ) {
					continue;
				}
				$real = @realpath( $path );
				$path = false !== $real ? $real : $path;
				if ( file_exists( $path ) ) {
					require_once $path;
					if ( class_exists( $class ) ) {
						return true;
					}
				}
			}

			return class_exists( $class );
		}

		// --- Key helpers ----------------------------------------------------

		private function group( $group ) {
			return '' === (string) $group ? 'default' : (string) $group;
		}

		private function full_key( $key, $group ) {
			$group  = $this->group( $group );
			$prefix = isset( $this->global_groups[ $group ] ) ? 0 : $this->blog_prefix;
			$gen    = $this->generation();
			// Generation 1 keeps the historical key shape, so Redis (which
			// flushes by pattern and never advances the generation) is
			// byte-identical to before and existing entries stay readable.
			$ns = 1 === $gen ? $this->salt : $this->salt . '.g' . $gen;
			return $ns . ':' . $prefix . ':' . $group . ':' . $key;
		}

		private function is_persistent_group( $group ) {
			return $this->persistent && ! isset( $this->non_persistent_groups[ $this->group( $group ) ] );
		}

		// --- Core ops -------------------------------------------------------

		public function add( $key, $data, $group = 'default', $expire = 0 ) {
			if ( wp_suspend_cache_addition() ) {
				return false;
			}
			$id = $this->full_key( $key, $group );
			// Present in THIS request's runtime cache → already added.
			if ( isset( $this->cache[ $id ] ) ) {
				return false;
			}

			// For persistent groups, add() must fail if the key exists in the
			// BACKEND too — not just this request's runtime array. Use the
			// backend's atomic add (Redis SET NX / memcached add) so two
			// processes racing to add the same key behave correctly and the
			// existing value is never clobbered. Falling back to the runtime
			// check alone (the old behaviour) let process B overwrite a key
			// process A had already stored. (FBS-82111 Bug 2)
			if ( $this->is_persistent_group( $group ) && $this->conn ) {
				try {
					if ( is_object( $data ) ) {
						$data = clone $data;
					}
					$payload = maybe_serialize( $data );
					$stored  = $this->conn->add( $id, $payload, (int) $expire );
					if ( ! $stored ) {
						return false; // key already exists in the backend.
					}
					$this->cache[ $id ] = $data;
					return true;
				} catch ( \Throwable $e ) {
					// Backend hiccup — fall through to the runtime-only path so
					// add() still works against the in-request array cache.
				}
			}

			return $this->set( $key, $data, $group, $expire );
		}

		public function replace( $key, $data, $group = 'default', $expire = 0 ) {
			$id = $this->full_key( $key, $group );
			if ( ! isset( $this->cache[ $id ] ) && false === $this->get( $key, $group ) ) {
				return false;
			}
			return $this->set( $key, $data, $group, $expire );
		}

		public function set( $key, $data, $group = 'default', $expire = 0 ) {
			$id = $this->full_key( $key, $group );
			if ( is_object( $data ) ) {
				$data = clone $data;
			}
			$this->cache[ $id ] = $data;

			if ( $this->is_persistent_group( $group ) ) {
				$stored = false;
				$threw  = false;
				try {
					$payload = maybe_serialize( $data );
					if ( 'redis' === $this->backend ) {
						$stored = $expire > 0
							? (bool) $this->conn->setex( $id, (int) $expire, $payload )
							: (bool) $this->conn->set( $id, $payload );
					} else {
						$stored = (bool) $this->conn->set( $id, $payload, (int) $expire );
					}
				} catch ( \Throwable $e ) {
					$threw = true;
				}
				if ( ! $stored ) {
					// The backend may still hold the PREVIOUS value for this
					// key (classic: memcached rejecting an alloptions blob
					// over its item-size limit). The DB now has the new value;
					// leaving the old one here would serve stale data to every
					// later request — e.g. a settings change confirmed over
					// REST/MCP that the dashboard never shows. Evict so
					// readers fall back to the database.
					try {
						$this->backend_delete( $id );
					} catch ( \Throwable $e ) {
						// Backend fully down → reads fail too, so no staleness.
					}
				}
				// Return value: the runtime cache always accepted the value, and
				// WP core's contract for wp_cache_set() is "was it cached",
				// which a persistent-backend refusal doesn't falsify — the
				// value is live for this request and the DB holds the truth
				// for later ones (we evicted the stale copy above). Some
				// callers treat false as "the write was lost" and retry or
				// bail, so report success and surface backend trouble through
				// the degraded flag instead.
				//
				// Exception: a THROWN backend is a hard failure we still
				// report as cached for the same reason — the runtime cache
				// holds it.
				if ( ! $stored ) {
					$this->degraded = true;
				}
				return true;
			}
			return true;
		}

		public function get( $key, $group = 'default', $force = false, &$found = null ) {
			$id = $this->full_key( $key, $group );

			if ( ! $force && isset( $this->cache[ $id ] ) ) {
				$found = true;
				++$this->cache_hits;
				$val = $this->cache[ $id ];
				return is_object( $val ) ? clone $val : $val;
			}

			if ( $this->is_persistent_group( $group ) ) {
				try {
					$raw = $this->conn->get( $id );
					if ( false !== $raw && null !== $raw ) {
						$val                = maybe_unserialize( $raw );
						$this->cache[ $id ] = $val;
						$found              = true;
						++$this->cache_hits;
						return is_object( $val ) ? clone $val : $val;
					}
				} catch ( \Throwable $e ) {
					// fall through to miss
				}
			}

			$found = false;
			++$this->cache_misses;
			return false;
		}

		public function get_multiple( $keys, $group = 'default', $force = false ) {
			$out = array();
			foreach ( (array) $keys as $key ) {
				$out[ $key ] = $this->get( $key, $group, $force );
			}
			return $out;
		}

		public function delete( $key, $group = 'default' ) {
			$id = $this->full_key( $key, $group );
			unset( $this->cache[ $id ] );
			if ( $this->is_persistent_group( $group ) ) {
				try {
					return (bool) $this->backend_delete( $id );
				} catch ( \Throwable $e ) {
					return true;
				}
			}
			return true;
		}

		public function incr( $key, $offset = 1, $group = 'default' ) {
			$id     = $this->full_key( $key, $group );
			$offset = max( 0, (int) $offset );
			if ( $this->is_persistent_group( $group ) ) {
				try {
					$new = $this->backend_incr( $id, $offset );
					if ( false !== $new ) {
						$this->cache[ $id ] = (int) $new;
						return (int) $new;
					}
				} catch ( \Throwable $e ) {
					// fall through
				}
			}
			$val                = isset( $this->cache[ $id ] ) ? (int) $this->cache[ $id ] : 0;
			$val                = max( 0, $val + $offset );
			$this->cache[ $id ] = $val;
			return $val;
		}

		public function decr( $key, $offset = 1, $group = 'default' ) {
			$id     = $this->full_key( $key, $group );
			$offset = max( 0, (int) $offset );
			if ( $this->is_persistent_group( $group ) ) {
				try {
					$new = $this->backend_decr( $id, $offset );
					if ( false !== $new ) {
						$new                = max( 0, (int) $new );
						$this->cache[ $id ] = $new;
						return $new;
					}
				} catch ( \Throwable $e ) {
					// fall through
				}
			}
			$val                = isset( $this->cache[ $id ] ) ? (int) $this->cache[ $id ] : 0;
			$val                = max( 0, $val - $offset );
			$this->cache[ $id ] = $val;
			return $val;
		}

		public function flush() {
			$this->cache = array();
			if ( $this->persistent ) {
				try {
					return (bool) $this->backend_flush( );
				} catch ( \Throwable $e ) {
					return false;
				}
			}
			return true;
		}

		// --- Backend dispatch ------------------------------------------
		// Normalises method-name differences across the four client kinds:
		// phpredis + our Redis_Client (redis backend), ext/memcached + our
		// Memcached_Client (memcached backend).

		private function backend_delete( $id ) {
			if ( 'redis' === $this->backend ) {
				return $this->conn->del( $id );
			}
			return $this->conn->delete( $id );
		}

		private function backend_incr( $id, $offset ) {
			if ( 'redis' === $this->backend ) {
				return $this->conn->incrBy( $id, $offset );
			}
			return 'builtin-memcached' === $this->client
				? $this->conn->incr( $id, $offset )
				: $this->conn->increment( $id, $offset );
		}

		private function backend_decr( $id, $offset ) {
			if ( 'redis' === $this->backend ) {
				return $this->conn->decrBy( $id, $offset );
			}
			return 'builtin-memcached' === $this->client
				? $this->conn->decr( $id, $offset )
				: $this->conn->decrement( $id, $offset );
		}

		private function backend_flush() {
			if ( 'redis' === $this->backend ) {
				// Scope the flush to THIS site's namespace (salt:*) instead of
				// FLUSHDB, which would wipe the entire Redis database —
				// including other sites / apps sharing the same DB index.
				// (FBS-83119)
				//
				// There is deliberately NO empty-salt fallback to FLUSHDB.
				// xspeed_oc_salt() always returns a non-empty value, but a
				// drop-in left over from an older version can still be the
				// object loaded for the request that runs the upgrade
				// migration — and that is exactly when a global flush would
				// destroy a neighbouring site's cache. An unsalted pattern is
				// scoped to nothing, so we bail rather than widen the blast
				// radius.
				if ( '' === $this->salt ) {
					return false;
				}
				return $this->delete_redis_pattern( $this->escape_glob( $this->salt ) . ':*' ) >= 0;
			}

			// Memcached has no key enumeration, so flush_all() / flush() are
			// unavoidably SERVER-WIDE — they wipe every other site and app on
			// the same daemon. Bump this site's namespace generation instead:
			// every key is built through it (see full_key()), so incrementing
			// it orphans this site's entries and leaves everyone else's alone.
			// The orphans expire on their own under Memcached's LRU.
			return $this->bump_generation();
		}

		/**
		 * Advance this site's namespace generation, invalidating every key
		 * built from it. Used as the Memcached flush primitive.
		 *
		 * @return bool
		 */
		private function bump_generation() {
			$key = $this->generation_key();
			$new = null;

			try {
				$new = 'builtin-memcached' === $this->client
					? $this->conn->incr( $key, 1 )
					: $this->conn->increment( $key, 1 );
			} catch ( \Throwable $e ) {
				$new = false;
			}

			// increment() fails when the counter does not exist — either it was
			// never seeded, or the daemon evicted it under LRU. Either way,
			// resuming from the CURRENT generation is what matters: seeding
			// back to a low number could land on a generation this site used
			// before and resurrect the keys this flush is meant to clear.
			// Jumping forward from the generation we resolved for this request
			// keeps the namespace monotonic across an eviction.
			if ( false === $new || null === $new ) {
				$next = $this->generation() + 1;
				try {
					// Store as a string: the bundled Memcached_Client types
					// this parameter `string`, and Memcached stores scalars as
					// strings regardless. (This file has no strict_types — it
					// must load standalone — so an int would be coerced rather
					// than rejected, but passing the right type keeps the two
					// clients behaving identically.)
					$this->conn->set( $key, (string) $next, 0 );
					$new = $next;
				} catch ( \Throwable $e ) {
					return false;
				}
			}

			$this->generation = (int) $new;
			$this->persist_generation_floor( $this->generation );
			return true;
		}

		/**
		 * The counter key holding this site's namespace generation. Salted, so
		 * each site owns its own counter on a shared daemon.
		 *
		 * @return string
		 */
		private function generation_key() {
			// Deliberately OUTSIDE the `{salt}:` namespace: Redis flushes by
			// deleting everything matching `{salt}:*`, which would otherwise
			// sweep away this invalidation marker if a site were reconfigured
			// from memcached to redis and back.
			return $this->salt . '.xspeed-oc-gen';
		}

		/**
		 * The lowest generation this site may use, read from the database.
		 *
		 * Memcached can evict the counter at any time; the database cannot, so
		 * this is what stops an eviction from silently rewinding the namespace
		 * and resurrecting keys a Purge already cleared.
		 *
		 * Read straight through $wpdb rather than get_option(), because the
		 * options API routes through this very cache and would recurse. Returns
		 * 1 whenever the database is not available yet — the drop-in loads
		 * before $wpdb exists, and on those early requests nothing has been
		 * flushed anyway.
		 *
		 * @return int
		 */
		/**
		 * The options table holding the generation floor, or '' when the
		 * database cannot be queried yet.
		 *
		 * Always the NETWORK-wide table. On multisite $wpdb->options points at
		 * the current blog's table, but the salt and the generation have no
		 * blog component — they namespace the whole install, global groups
		 * included. Storing the floor per blog would let a purge on blog 2 go
		 * unseen by blog 1, so an eviction there would rewind the namespace and
		 * resurrect the shared blog-details / blog-lookup entries that are the
		 * original bug.
		 *
		 * @param object $wpdb The database handle.
		 * @return string Table name, or '' when unusable.
		 */
		private function generation_table( $wpdb ) {
			if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
				return '';
			}

			// During wp-admin/install.php and `wp core install` the drop-in is
			// already live while the options table does not exist yet. A query
			// then emits a database error that our try/catch cannot suppress,
			// because get_var() reports rather than throws.
			if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
				return '';
			}

			$base = isset( $wpdb->base_prefix ) ? (string) $wpdb->base_prefix : '';
			if ( '' !== $base ) {
				return $base . 'options';
			}

			return isset( $wpdb->options ) ? (string) $wpdb->options : '';
		}

		private function generation_floor() {
			if ( null !== $this->generation_floor ) {
				return $this->generation_floor;
			}

			$this->generation_floor = 1;

			if ( 'memcached' !== $this->backend || ! isset( $GLOBALS['wpdb'] ) ) {
				return $this->generation_floor;
			}

			$wpdb  = $GLOBALS['wpdb'];
			$table = $this->generation_table( $wpdb );
			if ( '' === $table ) {
				return $this->generation_floor;
			}

			try {
				$val = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is derived from $wpdb, never user input.
						self::GENERATION_OPTION
					)
				);
				if ( is_numeric( $val ) && (int) $val > 1 ) {
					$this->generation_floor = (int) $val;
				}
			} catch ( \Throwable $e ) {
				$this->generation_floor = 1;
			}

			return $this->generation_floor;
		}

		/**
		 * Persist the generation as the new floor, so an eviction of the cached
		 * counter cannot rewind past it.
		 *
		 * @param int $generation The generation just written.
		 * @return void
		 */
		private function persist_generation_floor( $generation ) {
			if ( 'memcached' !== $this->backend || ! isset( $GLOBALS['wpdb'] ) ) {
				return;
			}

			$wpdb  = $GLOBALS['wpdb'];
			$table = $this->generation_table( $wpdb );
			if ( '' === $table || ! method_exists( $wpdb, 'query' ) ) {
				return;
			}

			try {
				// Upsert without the options API, which would recurse through
				// this cache. autoload='no' keeps it out of alloptions.
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$table} (option_name, option_value, autoload)
						 VALUES (%s, %s, 'no')
						 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is derived from $wpdb, never user input.
						self::GENERATION_OPTION,
						(string) (int) $generation
					)
				);
				$this->generation_floor = (int) $generation;
			} catch ( \Throwable $e ) {
				// Best effort: the cached counter still carries the flush for
				// as long as it survives.
				return;
			}
		}

		/**
		 * Read this site's namespace generation, once per request.
		 *
		 * Only meaningful for Memcached, where flushing works by advancing the
		 * generation rather than deleting keys. Redis deletes by pattern, so it
		 * stays on generation 1 and its key shape is unchanged.
		 *
		 * @return int
		 */
		private function generation() {
			if ( null !== $this->generation ) {
				return $this->generation;
			}

			// The floor comes from the database, because the counter lives in
			// the cache it namespaces and Memcached can evict it under LRU.
			// Falling back to generation 1 after an eviction would re-expose
			// the keys an earlier Purge cleared, so the DB copy — which cannot
			// be evicted — is what the generation may never drop below.
			$this->generation = $this->generation_floor();

			if ( 'memcached' === $this->backend && $this->persistent ) {
				try {
					$val = $this->conn->get( $this->generation_key() );
					if ( is_numeric( $val ) && (int) $val > $this->generation ) {
						$this->generation = (int) $val;
					}
				} catch ( \Throwable $e ) {
					// Unreadable counter — the floor still applies, so a
					// previous flush is never undone.
					$this->generation = max( 1, $this->generation );
				}
			}

			return $this->generation;
		}

		/**
		 * Escape Redis glob metacharacters so a literal string matches only
		 * itself inside a SCAN MATCH pattern.
		 *
		 * The salt and group name are interpolated into the flush patterns
		 * below, and neither is guaranteed to be glob-safe: an explicit Cache
		 * Key Prefix is whatever the user typed, and a host-pinned
		 * WP_CACHE_KEY_SALT is whatever the host wrote (WordPress builds these
		 * from the site URL, so punctuation is normal). Left unescaped, `*`,
		 * `?` and `[...]` are wildcards — `wp_[dev]site_:*` matches a
		 * NEIGHBOURING site's `wp_dsite_:*` keys, so purging one site deletes
		 * another site's cache. A bare `*` prefix matches everything and
		 * empties the whole Redis DB, which is the exact damage the scoped
		 * flush exists to prevent.
		 *
		 * Redis's stringmatchlen() treats `\` as the escape character, so
		 * backslash-prefixing each metacharacter makes it literal. `\` itself
		 * is escaped first, or escaping the others would be undone.
		 *
		 * @param string $literal Text to match literally.
		 * @return string Glob-safe form of $literal.
		 */
		private function escape_glob( $literal ) {
			return str_replace(
				array( '\\', '*', '?', '[', ']' ),
				array( '\\\\', '\\*', '\\?', '\\[', '\\]' ),
				(string) $literal
			);
		}

		/**
		 * Delete every Redis key matching $pattern across both client kinds
		 * (phpredis native scan + our pure-PHP Redis_Client). Returns the
		 * count deleted, or -1 if the backend isn't redis. SCAN-based so it
		 * never blocks the server the way KEYS would. (FBS-83119)
		 *
		 * Callers MUST pass any literal segment through escape_glob() — this
		 * receives a finished pattern and cannot tell wildcard from data.
		 */
		private function delete_redis_pattern( $pattern ) {
			if ( 'redis' !== $this->backend || ! $this->conn ) {
				return -1;
			}
			// Our pure-PHP client.
			if ( method_exists( $this->conn, 'delete_by_pattern' ) ) {
				return $this->conn->delete_by_pattern( $pattern );
			}
			// phpredis: iterate the SCAN cursor (setOption SCAN_RETRY keeps it
			// simple — scan() returns false when the cursor is exhausted).
			if ( $this->conn instanceof \Redis ) {
				$deleted = 0;
				$it      = null;
				if ( defined( '\Redis::SCAN_RETRY' ) ) {
					$this->conn->setOption( \Redis::OPT_SCAN, \Redis::SCAN_RETRY );
				}
				do {
					$keys = $this->conn->scan( $it, $pattern, 500 );
					if ( is_array( $keys ) && ! empty( $keys ) ) {
						$deleted += (int) $this->conn->del( $keys );
					}
				} while ( $it > 0 );
				return $deleted;
			}
			return -1;
		}

		/**
		 * Load xSpeed's own Memcached_Client (pure-PHP) on demand, the same
		 * way load_builtin_client() loads the Redis one.
		 */
		private function load_builtin_memcached() {
			return $this->load_builtin( '\\XSpeed\\Memcached_Client', 'class-memcached-client.php' );
		}

		public function flush_runtime() {
			$this->cache = array();
			return true;
		}

		public function flush_group( $group ) {
			$group = $this->group( $group );

			// Runtime copy first — drop every in-request entry for this group.
			$needle = $this->full_key( '', $group );
			foreach ( array_keys( $this->cache ) as $id ) {
				if ( 0 === strpos( $id, $needle ) ) {
					unset( $this->cache[ $id ] );
				}
			}

			// Persistent store: actually evict the group's keys from Redis so a
			// targeted invalidation (core or third-party calling
			// wp_cache_flush_group) stops serving stale data — previously this
			// was a runtime-only no-op against the backend. The key layout is
			// salt:{prefix}:{group}:{key}, so match salt:*:{group}:* to cover
			// both blog-prefixed and global groups for this site's namespace.
			// (FBS-83119)
			if ( $this->is_persistent_group( $group ) && 'redis' === $this->backend && '' !== $this->salt ) {
				try {
					$this->delete_redis_pattern(
						$this->escape_glob( $this->salt ) . ':*:' . $this->escape_glob( $group ) . ':*'
					);
				} catch ( \Throwable $e ) {
					return false;
				}
			}
			return true;
		}

		public function close() {
			if ( $this->persistent && $this->conn ) {
				try {
					if ( 'redis' === $this->backend ) {
						$this->conn->close();
					} else {
						$this->conn->quit();
					}
				} catch ( \Throwable $e ) {
					// ignore
				}
			}
			return true;
		}

		// --- Group config ---------------------------------------------------

		public function add_global_groups( $groups ) {
			foreach ( (array) $groups as $g ) {
				$this->global_groups[ $g ] = true;
			}
		}

		public function add_non_persistent_groups( $groups ) {
			foreach ( (array) $groups as $g ) {
				$this->non_persistent_groups[ $g ] = true;
			}
		}

		public function switch_to_blog( $blog_id ) {
			$this->blog_prefix = $this->multisite ? (int) $blog_id : 0;
		}

		/** @return array{backend:string,persistent:bool,hits:int,misses:int} */
		public function stats() {
			return array(
				'backend'    => $this->backend,
				'persistent' => $this->persistent,
				'hits'       => $this->cache_hits,
				'misses'     => $this->cache_misses,
			);
		}
	}
}
