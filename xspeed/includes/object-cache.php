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
				: array( 'XSPEED_OC_HOST', 'WP_REDIS_PATH', 'WP_REDIS_HOST' ),
			'port'     => $is_memcached
				? array( 'XSPEED_OC_MC_PORT', 'XSPEED_OC_PORT' )
				: array( 'XSPEED_OC_PORT', 'WP_REDIS_PORT' ),
			// WP_REDIS_PASSWORD trails the user names on purpose: in its array
			// form it carries the ACL username too, so a site that defines only
			// the password pair still authenticates as the right user.
			'user'     => array( 'XSPEED_OC_USER', 'WP_REDIS_USERNAME', 'WP_REDIS_USER', 'WP_REDIS_PASSWORD' ),
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
				// WP_REDIS_PATH names a socket only when WP_REDIS_SCHEME is
				// `unix` in any case, the same gate the schema declares in
				// `constants_when`. Redis Object Cache uses strcasecmp(). (#566)
				if ( 'WP_REDIS_PATH' === $const && ! ( defined( 'WP_REDIS_SCHEME' ) && is_string( constant( 'WP_REDIS_SCHEME' ) ) && 0 === strcasecmp( constant( 'WP_REDIS_SCHEME' ), 'unix' ) ) ) {
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

if ( ! function_exists( 'xspeed_oc_redis_socket' ) ) {
	/**
	 * The unix socket path a Redis host names, or '' for a network host.
	 *
	 * A copy of \XSpeed\Redis_Client::socket_path(), which this drop-in cannot
	 * call when phpredis is the client: the class is only loaded for the
	 * built-in one. ObjectCacheSocketTest runs both over the same inputs, so
	 * the panel's Test connection and the runtime agree on what a socket is.
	 * (#566)
	 *
	 * @param string $host Configured Redis host.
	 * @return string Socket path, or ''.
	 */
	function xspeed_oc_redis_socket( $host ) {
		$host = trim( (string) $host );
		if ( 0 === stripos( $host, 'unix:' ) ) {
			// unix:///path and unix:/path both name /path.
			$host = '/' . ltrim( substr( $host, 5 ), '/' );
		}
		return ( '' !== $host && '/' === $host[0] && '/' !== $host ) ? $host : '';
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
		return $GLOBALS['wp_object_cache']->add_multiple( $data, $group, (int) $expire );
	}

	function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
		return $GLOBALS['wp_object_cache']->replace( $key, $data, $group, (int) $expire );
	}

	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		return $GLOBALS['wp_object_cache']->set( $key, $data, $group, (int) $expire );
	}

	function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
		return $GLOBALS['wp_object_cache']->set_multiple( $data, $group, (int) $expire );
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
		return $GLOBALS['wp_object_cache']->delete_multiple( $keys, $group );
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

		/** Option holding the generation floor (see generation_floor()). */
		const GENERATION_OPTION = 'xspeed_oc_generation';

		/**
		 * Suffix of the Redis generation counter, `{salt}:generation`. No key
		 * shape a flush, sweep or cleanup deletes can match it: those all
		 * need a digit or `g` plus digits after `{salt}:`.
		 */
		const REDIS_COUNTER_SUFFIX = ':generation';

		/** Cron hook that deletes Redis keys from earlier generations. */
		const RECLAIM_HOOK = 'xspeed_oc_reclaim';

		/**
		 * @var int|null Namespace generation, read once per request and
		 * re-read whenever Redis reports that a flush moved it. Null until
		 * first read. On Memcached, 1 uses the original key shape.
		 */
		private $generation = null;

		/**
		 * @var bool True when Redis would not store the counter this request
		 *           seeded, so the generation is one no other request uses.
		 */
		private $generation_private = false;

		/** @var bool True once this request has queued the reclaim event. */
		private $reclaim_queued = false;

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
						// phpredis extension (preferred). It opens a unix
						// socket only for a bare path with a port below 1;
						// with any real port it tries TCP to the path. (#566)
						$this->client = 'phpredis';
						$redis        = new Redis();
						$socket       = xspeed_oc_redis_socket( $host );
						if ( '' !== $socket ) {
							$host = $socket;
							$port = 0;
						}
						$ok = $persist
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

		/**
		 * This request's id for a key, `{salt}:{blog}:{group}:{key}`. The
		 * runtime cache is keyed by it; the backend key adds the generation
		 * (see bid()), so a generation change seen mid-request leaves values
		 * of non-persistent groups in place.
		 */
		private function full_key( $key, $group ) {
			$group  = $this->group( $group );
			$prefix = isset( $this->global_groups[ $group ] ) ? 0 : $this->blog_prefix;
			return $this->salt . ':' . $prefix . ':' . $group . ':' . $key;
		}

		/**
		 * The backend key for a runtime id.
		 *
		 * Redis: `{salt}:g{n}:{blog}:{group}:{key}` for every generation, so
		 * a flush only has to move the counter (#801). The `g` keeps these
		 * apart from the flat `{salt}:{blog}:` keys earlier versions wrote,
		 * and from a neighbour whose salt ends in `:<digits>`.
		 *
		 * Memcached: generation 1 keeps the historical shape, later ones are
		 * `{salt}.g{n}:...`.
		 *
		 * @param string $id Runtime id from full_key().
		 * @return string
		 */
		private function bid( $id ) {
			$gen  = $this->generation();
			$rest = (string) substr( $id, strlen( $this->salt ) );
			if ( 'redis' === $this->backend ) {
				return $this->salt . ':g' . $gen . $rest;
			}
			return 1 === $gen ? $id : $this->salt . '.g' . $gen . $rest;
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
					$stored  = $this->backend_add( $id, $payload, (int) $expire );
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
						// Preserve the bundled client's rejection of null payloads.
						if ( null === $payload && 'builtin' === $this->client ) {
							throw new \TypeError( 'Redis payload must be a string' );
						}
						$cmd     = $expire > 0
							? array( 'SETEX', $this->bid( $id ), (string) (int) $expire, $payload )
							: array( 'SET', $this->bid( $id ), $payload );
						$replies = $this->redis_write( array( $cmd ), array( $id ) );
						$stored  = is_array( $replies ) && true === $replies[0];
					} else {
						$stored = (bool) $this->conn->set( $this->bid( $id ), $payload, (int) $expire );
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
					if ( 'redis' === $this->backend ) {
						$raw = $this->redis_read( array( $id ) );
						$raw = $raw[ $id ] ?? false;
					} else {
						$raw = $this->conn->get( $this->bid( $id ) );
					}
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

		/**
		 * Read several keys: the ones already in this request's cache are
		 * served locally, the rest come back in one MGET / getMulti instead
		 * of a round trip each. WordPress primes posts, meta and terms this
		 * way, so a page asks for hundreds of keys in a handful of calls.
		 * (#800)
		 */
		public function get_multiple( $keys, $group = 'default', $force = false ) {
			$out     = array();
			$missing = array(); // full id => caller's key.

			foreach ( (array) $keys as $key ) {
				$id = $this->full_key( $key, $group );
				if ( ! $force && isset( $this->cache[ $id ] ) ) {
					++$this->cache_hits;
					$val         = $this->cache[ $id ];
					$out[ $key ] = is_object( $val ) ? clone $val : $val;
					continue;
				}
				$out[ $key ]    = false;
				$missing[ $id ] = $key;
			}

			if ( ! empty( $missing ) && $this->is_persistent_group( $group ) ) {
				try {
					$raw = $this->backend_get_multi( array_keys( $missing ) );
				} catch ( \Throwable $e ) {
					$raw = array(); // Backend trouble reads as misses.
				}
				foreach ( $raw as $id => $value ) {
					if ( ! isset( $missing[ $id ] ) || false === $value || null === $value ) {
						continue;
					}
					$val                = maybe_unserialize( $value );
					$this->cache[ $id ] = $val;
					++$this->cache_hits;
					$out[ $missing[ $id ] ] = is_object( $val ) ? clone $val : $val;
					unset( $missing[ $id ] );
				}
			}

			$this->cache_misses += count( $missing );
			return $out;
		}

		/**
		 * Store several values with one pipelined round trip on Redis (one
		 * setMulti on ext-memcached). The runtime cache takes every value.
		 * A key the backend refused is reported false and evicted so no
		 * reader gets the previous value.
		 */
		public function set_multiple( array $data, $group = 'default', $expire = 0 ) {
			if ( ! $this->is_persistent_group( $group ) ) {
				$out = array();
				foreach ( $data as $key => $value ) {
					$out[ $key ] = $this->set( $key, $value, $group, $expire );
				}
				return $out;
			}

			$out         = array();
			$payloads    = array();
			$caller_keys = array();
			foreach ( $data as $key => $value ) {
				// Preserve the client's single-key handling of null payloads.
				if ( null === $value && 'redis' === $this->backend ) {
					$out[ $key ] = $this->set( $key, $value, $group, $expire );
					continue;
				}
				$id = $this->full_key( $key, $group );
				if ( is_object( $value ) ) {
					$value = clone $value;
				}
				$this->cache[ $id ] = $value;
				$payloads[ $id ]    = maybe_serialize( $value );
				$caller_keys[ $id ] = $key;
				$out[ $key ]        = true;
			}

			if ( empty( $payloads ) ) {
				return $out;
			}

			try {
				$failed = $this->backend_set_multi( $payloads, (int) $expire );
			} catch ( \Throwable $e ) {
				$failed = $this->backend_set_individual( $payloads, (int) $expire );
			}
			if ( ! empty( $failed ) ) {
				foreach ( $failed as $id ) {
					$out[ $caller_keys[ $id ] ] = false;
				}
				// See set(): the backend may still hold the previous value.
				foreach ( $failed as $id ) {
					try {
						$this->backend_delete( $id );
					} catch ( \Throwable $e ) {
						break; // Backend fully down, so reads fail too.
					}
				}
				$this->degraded = true;
			}
			return $out;
		}

		/**
		 * Add several values: SET NX for each key, pipelined into one round
		 * trip on Redis. A key already present, in this request or in the
		 * backend, is left alone and reported false, exactly as add() does.
		 */
		public function add_multiple( array $data, $group = 'default', $expire = 0 ) {
			$out = array();
			if ( wp_suspend_cache_addition() ) {
				foreach ( $data as $key => $unused ) {
					$out[ $key ] = false;
				}
				return $out;
			}
			if ( 'redis' !== $this->backend || ! $this->conn || ! $this->is_persistent_group( $group ) ) {
				foreach ( $data as $key => $value ) {
					$out[ $key ] = $this->add( $key, $value, $group, $expire );
				}
				return $out;
			}

			$pending = array(); // full id => array( key, value ).
			foreach ( $data as $key => $value ) {
				if ( null === $value ) {
					$out[ $key ] = $this->add( $key, $value, $group, $expire );
					continue;
				}
				$id = $this->full_key( $key, $group );
				if ( isset( $this->cache[ $id ] ) || isset( $pending[ $id ] ) ) {
					$out[ $key ] = false;
					continue;
				}
				if ( is_object( $value ) ) {
					$value = clone $value;
				}
				$pending[ $id ] = array( $key, $value );
				$out[ $key ]    = false;
			}
			if ( empty( $pending ) ) {
				return $out;
			}

			$commands = array();
			foreach ( $pending as $id => $entry ) {
				$commands[ $id ] = maybe_serialize( $entry[1] );
			}
			try {
				$stored = $this->redis_add_multi( $commands, (int) $expire );
			} catch ( \Throwable $e ) {
				$stored = null;
			}
			if ( null === $stored ) {
				// The pipeline failed as a whole: fall back to add() per key,
				// which has its own runtime-only path for a dead backend.
				foreach ( $pending as $entry ) {
					$out[ $entry[0] ] = $this->add( $entry[0], $entry[1], $group, $expire );
				}
				return $out;
			}
			foreach ( $pending as $id => $entry ) {
				if ( ! empty( $stored[ $id ] ) ) {
					$this->cache[ $id ] = $entry[1];
					$out[ $entry[0] ]   = true;
				}
			}
			return $out;
		}

		/**
		 * Delete several keys, pipelined into one round trip on Redis.
		 */
		public function delete_multiple( array $keys, $group = 'default' ) {
			if ( count( $keys ) < 2 || 'redis' !== $this->backend || ! $this->conn || ! $this->is_persistent_group( $group ) ) {
				$out = array();
				foreach ( $keys as $key ) {
					$out[ $key ] = $this->delete( $key, $group );
				}
				return $out;
			}

			$ids = array();
			foreach ( $keys as $key ) {
				$id = $this->full_key( $key, $group );
				unset( $this->cache[ $id ] );
				$ids[ $id ] = $key;
			}
			$out = array();
			try {
				$replies = $this->redis_delete_multi( array_keys( $ids ) );
			} catch ( \Throwable $e ) {
				$replies = false;
			}
			$i = 0;
			foreach ( $ids as $key ) {
				// A dead backend reports true, like delete() does.
				$out[ $key ] = is_array( $replies ) ? (bool) ( $replies[ $i ] ?? false ) : true;
				++$i;
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

		/** @var string Normalized failure code for the last flush in this request. */
		private $flush_failure = '';

		public function flush_failure_reason(): string {
			return $this->flush_failure ?: 'failed';
		}

		private function record_flush_failure( string $message ): void {
			$this->flush_failure = preg_match( '/NOPERM|permission(?:s)?[ -]denied/i', $message ) ? 'permission_denied' : 'failed';
		}

		public function flush() {
			$this->flush_failure = '';
			$this->cache = array();
			if ( $this->persistent && $this->conn ) {
				try {
					if ( method_exists( $this->conn, 'clearLastError' ) ) {
						$this->conn->clearLastError();
					}
					$ok = (bool) $this->backend_flush();
					if ( ! $ok && '' === $this->flush_failure && method_exists( $this->conn, 'getLastError' ) ) {
						$this->record_flush_failure( (string) $this->conn->getLastError() );
					}
					return $ok;
				} catch ( \Throwable $e ) {
					$this->record_flush_failure( $e->getMessage() );
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
				$replies = $this->redis_write( array( array( 'DEL', $this->bid( $id ) ) ), array( $id ) );
				return is_array( $replies ) ? $replies[0] : false;
			}
			return $this->conn->delete( $this->bid( $id ) );
		}

		/**
		 * Atomic add: store only when the key is absent. phpredis has no
		 * add() method, so Redis takes SET with the NX option; calling add()
		 * on phpredis threw, and the catch in add() fell through to a plain
		 * set() that overwrote whatever another process had stored.
		 */
		private function backend_add( $id, $payload, $expire ) {
			if ( 'redis' === $this->backend ) {
				if ( null === $payload && 'builtin' === $this->client ) {
					throw new \TypeError( 'Redis payload must be a string' );
				}
				$replies = $this->redis_write( array( $this->redis_set_nx_command( $this->bid( $id ), $payload, $expire ) ), array( $id ), true );
				return is_array( $replies ) && true === $replies[0];
			}
			return (bool) $this->conn->add( $this->bid( $id ), $payload, (int) $expire );
		}

		/**
		 * Read several ids in one round trip where the client can.
		 *
		 * @param string[] $ids Runtime ids.
		 * @return array<string,mixed> Raw values keyed by runtime id; misses
		 *                             may be absent or false.
		 */
		private function backend_get_multi( array $ids ) {
			if ( 'redis' === $this->backend ) {
				return $this->redis_read( $ids );
			}
			$map = array();
			foreach ( $ids as $id ) {
				$map[ $this->bid( $id ) ] = $id;
			}
			if ( 'ext-memcached' === $this->client ) {
				$vals = $this->conn->getMulti( array_keys( $map ) );
			} elseif ( 'builtin-memcached' === $this->client && method_exists( $this->conn, 'get_multi' ) ) {
				$vals = $this->conn->get_multi( array_keys( $map ) );
			} else {
				$vals = array();
				foreach ( $map as $bid => $unused ) {
					$vals[ $bid ] = $this->conn->get( $bid );
				}
			}
			$out = array();
			foreach ( is_array( $vals ) ? $vals : array() as $bid => $value ) {
				if ( isset( $map[ $bid ] ) ) {
					$out[ $map[ $bid ] ] = $value;
				}
			}
			return $out;
		}

		/**
		 * Store several payloads, batched where the client can.
		 *
		 * @param array<string,string> $payloads Serialized values keyed by runtime id.
		 * @param int                  $expire   TTL in seconds, 0 for none.
		 * @return string[] Runtime ids the backend did not store.
		 */
		private function backend_set_multi( array $payloads, $expire ) {
			$failed = array();

			if ( 'redis' === $this->backend ) {
				$commands = array();
				foreach ( $payloads as $id => $payload ) {
					$commands[] = $expire > 0
						? array( 'SETEX', $this->bid( $id ), (string) $expire, $payload )
						: array( 'SET', $this->bid( $id ), $payload );
				}
				$replies = $this->redis_write( $commands, array_keys( $payloads ) );
				$i       = 0;
				foreach ( $payloads as $id => $unused ) {
					if ( ! is_array( $replies ) || true !== $replies[ $i ] ) {
						$failed[] = $id;
					}
					++$i;
				}
				return $failed;
			}

			$map = array();
			foreach ( $payloads as $id => $payload ) {
				$map[ $this->bid( $id ) ] = $id;
			}
			if ( 'ext-memcached' === $this->client && $this->conn->setMulti( array_combine( array_keys( $map ), array_values( $payloads ) ), $expire ) ) {
				return array();
			}

			// No batch primitive, or setMulti refused something (an item over
			// the size limit fails the whole call): store one by one to learn
			// which keys did not make it.
			return $this->backend_set_individual( $payloads, $expire );
		}

		/** Isolate payload errors so one refused value cannot erase its neighbors. */
		private function backend_set_individual( array $payloads, $expire ) {
			$failed = array();
			$disconnected = false;
			foreach ( $payloads as $id => $payload ) {
				if ( $disconnected ) {
					$failed[] = $id;
					continue;
				}
				try {
					if ( 'redis' === $this->backend ) {
						$cmd = $expire > 0
							? array( 'SETEX', $this->bid( $id ), (string) $expire, $payload )
							: array( 'SET', $this->bid( $id ), $payload );
						$replies = $this->redis_write( array( $cmd ), array( $id ) );
						$ok = is_array( $replies ) && true === $replies[0];
						$disconnected = false === $replies;
					} else {
						$ok = $this->conn->set( $this->bid( $id ), $payload, $expire );
					}
				} catch ( \Throwable $e ) {
					$ok = false;
					// Only payload errors leave the connection safe to retry.
					$disconnected = ! $e instanceof \TypeError && ! $e instanceof \ValueError;
				}
				if ( ! $ok ) {
					$failed[] = $id;
				}
			}
			return $failed;
		}

		/**
		 * SET NX for several ids in one Redis round trip.
		 *
		 * @param array<string,string> $payloads Serialized values keyed by runtime id.
		 * @param int                  $expire   TTL in seconds, 0 for none.
		 * @return array<string,bool>|null Stored flag per runtime id, or null
		 *                                 when the pipeline failed.
		 */
		private function redis_add_multi( array $payloads, $expire ) {
			$commands = array();
			foreach ( $payloads as $id => $payload ) {
				$commands[] = $this->redis_set_nx_command( $this->bid( $id ), $payload, $expire );
			}
			$replies = $this->redis_write( $commands, array_keys( $payloads ), true );
			if ( ! is_array( $replies ) ) {
				return null;
			}
			$stored = array();
			$i      = 0;
			foreach ( $payloads as $id => $unused ) {
				$stored[ $id ] = true === $replies[ $i ];
				++$i;
			}
			return $stored;
		}

		/**
		 * DEL several ids in one Redis round trip, one reply per id.
		 *
		 * @param string[] $ids Runtime ids.
		 * @return array|false Per-id delete counts, or false on failure.
		 */
		private function redis_delete_multi( array $ids ) {
			$commands = array();
			foreach ( $ids as $id ) {
				$commands[] = array( 'DEL', $this->bid( $id ) );
			}
			return $this->redis_write( $commands, $ids );
		}

		private function backend_incr( $id, $offset ) {
			if ( 'redis' === $this->backend ) {
				$replies = $this->redis_write( array( array( 'INCRBY', $this->bid( $id ), (string) $offset ) ), array( $id ), true );
				return is_array( $replies ) ? $replies[0] : false;
			}
			return 'builtin-memcached' === $this->client
				? $this->conn->incr( $this->bid( $id ), $offset )
				: $this->conn->increment( $this->bid( $id ), $offset );
		}

		private function backend_decr( $id, $offset ) {
			if ( 'redis' === $this->backend ) {
				$replies = $this->redis_write( array( array( 'DECRBY', $this->bid( $id ), (string) $offset ) ), array( $id ), true );
				return is_array( $replies ) ? $replies[0] : false;
			}
			return 'builtin-memcached' === $this->client
				? $this->conn->decr( $this->bid( $id ), $offset )
				: $this->conn->decrement( $this->bid( $id ), $offset );
		}

		// --- Redis generation checks -----------------------------------
		// A Redis flush only moves the generation counter (#801), so a
		// request that resolved its generation before another request
		// flushed would keep reading and writing the dead one. Every read
		// and write therefore carries a read of the counter in the same
		// round trip, and the request follows the counter when it moved.

		/**
		 * Read ids with one MGET that also fetches the generation counter.
		 * When the counter moved, the request adopts it and reads again
		 * from the new generation.
		 *
		 * @param string[] $ids Runtime ids.
		 * @return array<string,mixed> Raw values keyed by runtime id.
		 */
		private function redis_read( array $ids ) {
			$ids  = array_values( $ids );
			$vals = $this->redis_mget( $ids, true );
			if ( ! is_array( $vals ) ) {
				return array();
			}
			$counter = array_pop( $vals );
			if ( $this->generation_moved( $counter ) ) {
				$vals = $this->redis_mget( $ids, false );
				if ( ! is_array( $vals ) ) {
					return array();
				}
			}
			return array_combine( $ids, $vals );
		}

		/**
		 * MGET the backend keys for $ids, optionally followed by the counter.
		 *
		 * @return array|false Values in order, false on failure.
		 */
		private function redis_mget( array $ids, $with_counter ) {
			$keys = array();
			foreach ( $ids as $id ) {
				$keys[] = $this->bid( $id );
			}
			if ( $with_counter ) {
				$keys[] = $this->generation_key();
			}
			if ( method_exists( $this->conn, 'mget' ) ) {
				$vals = $this->conn->mget( $keys );
			} else {
				$vals = array();
				foreach ( $keys as $key ) {
					$vals[] = $this->conn->get( $key );
				}
			}
			return is_array( $vals ) && count( $vals ) === count( $keys ) ? array_values( $vals ) : false;
		}

		/**
		 * Run Redis write commands in one round trip, followed by a read of
		 * the generation counter.
		 *
		 * When the counter shows that another request flushed after this one
		 * resolved its generation, the commands wrote to the dead generation.
		 *
		 * A plain write (SET, DEL) is not repeated in the new generation: this
		 * request's value may come from a read made before the flush, and a
		 * third request may already have cached an older database value
		 * there. Those keys are deleted in the new generation instead, so the
		 * next read goes to the database.
		 *
		 * An atomic write (SET NX, INCRBY, DECRBY) is run again in the new
		 * generation and its replies replace the dead generation's. Deleting
		 * there would drop a lock or counter another request holds, and the
		 * dead generation's SET NX reply would report a lock nobody can see.
		 * Atomic commands must be one per id, in the order of $ids.
		 *
		 * @param array    $commands Commands as array( NAME, key, ...args ).
		 * @param string[] $ids      Runtime ids the commands touch.
		 * @param bool     $atomic   True for SET NX, INCRBY and DECRBY.
		 * @return array|false Normalised replies, one per command, or false
		 *                     when the round trip failed.
		 */
		private function redis_write( array $commands, array $ids, $atomic = false ) {
			$ids = array_values( $ids );
			for ( $attempt = 1; ; ++$attempt ) {
				$commands[] = array( 'GET', $this->generation_key() );
				$replies    = $this->redis_pipeline( $commands );
				array_pop( $commands );
				if ( ! is_array( $replies ) ) {
					return false;
				}
				$counter = array_pop( $replies );
				if ( empty( $ids ) || ! $this->generation_moved( $counter ) ) {
					return $replies;
				}
				if ( ! $atomic ) {
					$deletes = array();
					foreach ( $ids as $id ) {
						$deletes[] = array( 'DEL', $this->bid( $id ) );
					}
					try {
						$deleted = $this->redis_pipeline( $deletes );
					} catch ( \Throwable $e ) {
						// A fallback write would revive values read before the flush.
						return false;
					}
					if ( ! is_array( $deleted ) || count( $deleted ) !== count( $ids ) ) {
						return false;
					}
					foreach ( $deleted as $result ) {
						if ( ! is_int( $result ) || $result < 0 ) {
							return false;
						}
					}
					return $replies;
				}
				if ( $attempt >= 3 ) {
					// Flushes keep landing between attempts: report nothing
					// stored rather than a result from a dead generation.
					return array_fill( 0, count( $replies ), false );
				}
				foreach ( $commands as $i => $cmd ) {
					$commands[ $i ][1] = $this->bid( $ids[ $i ] );
				}
			}
		}

		/**
		 * Follow a counter value read from Redis.
		 *
		 * @param mixed $value Counter reply.
		 * @return bool True when the generation changed.
		 */
		private function adopt_generation( $value ) {
			if ( ! $this->is_generation_value( $value ) || (int) $value === $this->generation ) {
				return false;
			}
			$this->generation         = (int) $value;
			$this->generation_private = false;
			return true;
		}

		/**
		 * Check a counter reply fetched beside a read or write, and switch to
		 * the counter's generation when it differs from this request's.
		 *
		 * A missing counter (evicted, or deleted by a disable) proves
		 * nothing: another request may have flushed before it went, and the
		 * retired generation's values stay in Redis until the reclaim. So the
		 * request resolves the counter again, which seeds a new one above
		 * every earlier generation. When Redis would not store this request's
		 * seed, the generation is already one nobody else writes to and
		 * there is nothing to check.
		 *
		 * @param mixed $value Counter reply.
		 * @return bool True when the generation changed.
		 */
		private function generation_moved( $value ) {
			if ( $this->is_generation_value( $value ) ) {
				return $this->adopt_generation( $value );
			}
			if ( $this->generation_private ) {
				return false;
			}
			$fresh = $this->resolve_redis_generation();
			if ( $fresh === $this->generation ) {
				return false;
			}
			$this->generation = $fresh;
			return true;
		}

		/**
		 * Read the counter now and follow it, for an operation that must act
		 * on the current generation rather than the one this request saw
		 * first.
		 *
		 * @return int The current generation.
		 */
		private function refresh_generation() {
			$gen = $this->generation();
			try {
				$this->generation_moved( $this->conn->get( $this->generation_key() ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return null === $this->generation ? $gen : $this->generation;
		}

		/** @param mixed $value */
		private function is_generation_value( $value ) {
			return ( is_int( $value ) && $value > 0 ) || ( is_string( $value ) && '' !== $value && ctype_digit( $value ) && (int) $value > 0 );
		}

		/** SET NX command, with a TTL when $expire is positive. */
		private function redis_set_nx_command( $key, $payload, $expire ) {
			$cmd = array( 'SET', $key, $payload, 'NX' );
			if ( $expire > 0 ) {
				$cmd[] = 'EX';
				$cmd[] = (string) (int) $expire;
			}
			return $cmd;
		}

		/**
		 * Send commands to Redis in one round trip on either client and
		 * normalise the replies: true/false for SET and SETEX, an int (or
		 * false on an error reply) for DEL, INCRBY and DECRBY, and the value
		 * or false for GET.
		 *
		 * @param array $commands Commands as array( NAME, key, ...args ).
		 * @return array|false Replies in order, or false when the round trip failed.
		 */
		private function redis_pipeline( array $commands ) {
			$commands = array_values( $commands );
			if ( 'phpredis' === $this->client ) {
				$pipe = $this->conn->multi( \Redis::PIPELINE );
				foreach ( $commands as $cmd ) {
					$this->phpredis_queue( $pipe, $cmd );
				}
				$raw = $pipe->exec();
			} elseif ( method_exists( $this->conn, 'pipeline' ) ) {
				$raw = $this->conn->pipeline( $commands );
			} else {
				$raw = array();
				foreach ( $commands as $cmd ) {
					$raw[] = $this->redis_single( $cmd );
				}
			}
			if ( ! is_array( $raw ) || count( $raw ) !== count( $commands ) ) {
				return false;
			}
			$out = array();
			foreach ( $commands as $i => $cmd ) {
				$r = $raw[ $i ];
				switch ( $cmd[0] ) {
					case 'SET':
					case 'SETEX':
						$out[] = true === $r || 'OK' === $r || '+OK' === $r;
						break;
					case 'GET':
						$out[] = null === $r ? false : $r;
						break;
					default:
						$out[] = is_int( $r ) || ( is_string( $r ) && is_numeric( $r ) ) ? (int) $r : false;
				}
			}
			return $out;
		}

		/** Queue one command on a phpredis pipeline. */
		private function phpredis_queue( $pipe, array $cmd ) {
			switch ( $cmd[0] ) {
				case 'SET':
					if ( isset( $cmd[3] ) ) {
						$opts = isset( $cmd[5] ) ? array( 'nx', 'ex' => (int) $cmd[5] ) : array( 'nx' );
						$pipe->set( $cmd[1], $cmd[2], $opts );
					} else {
						$pipe->set( $cmd[1], $cmd[2] );
					}
					break;
				case 'SETEX':
					$pipe->setex( $cmd[1], (int) $cmd[2], $cmd[3] );
					break;
				case 'DEL':
					$pipe->del( $cmd[1] );
					break;
				case 'INCRBY':
					$pipe->incrBy( $cmd[1], (int) $cmd[2] );
					break;
				case 'DECRBY':
					$pipe->decrBy( $cmd[1], (int) $cmd[2] );
					break;
				default:
					$pipe->get( $cmd[1] );
			}
		}

		/** Run one command on a client that cannot pipeline. */
		private function redis_single( array $cmd ) {
			switch ( $cmd[0] ) {
				case 'SET':
					return isset( $cmd[3] )
						? $this->conn->add( $cmd[1], $cmd[2], isset( $cmd[5] ) ? (int) $cmd[5] : 0 )
						: $this->conn->set( $cmd[1], $cmd[2] );
				case 'SETEX':
					return $this->conn->setex( $cmd[1], (int) $cmd[2], $cmd[3] );
				case 'DEL':
					return $this->conn->del( $cmd[1] );
				case 'INCRBY':
					return $this->conn->incrBy( $cmd[1], (int) $cmd[2] );
				case 'DECRBY':
					return $this->conn->decrBy( $cmd[1], (int) $cmd[2] );
				default:
					return $this->conn->get( $cmd[1] );
			}
		}

		/**
		 * Flush persistent values.
		 *
		 * Redis advances the generation counter, so every key of the old
		 * generation becomes unreachable in one command and a cron event
		 * deletes them later (#801). Memcached advances its own counter the
		 * same way and lets LRU drop the old keys.
		 *
		 * @return bool False when the flush could not be carried out.
		 */
		private function backend_flush() {
			if ( 'redis' === $this->backend ) {
				// An empty salt cannot limit the flush to this site. (FBS-83119)
				if ( '' === $this->salt ) {
					return false;
				}
				return $this->redis_flush();
			}

			// Memcached cannot enumerate keys. Advance this site's namespace
			// so its values become unreachable without a server-wide flush.
			return $this->bump_generation();
		}

		/**
		 * Advance the Redis generation counter.
		 *
		 * INCRBY keeps concurrent flushes from overwriting each other. When
		 * the counter came back at or below this request's generation, it was
		 * evicted and recreated, so it is raised past both this request's
		 * generation and the time-based seed. When Redis refuses the
		 * increment (out of memory under noeviction, NOPERM, an error), the
		 * flush deletes this site's keys instead and reports whether that
		 * worked.
		 *
		 * @return bool
		 */
		private function redis_flush() {
			$key     = $this->generation_key();
			$current = $this->generation();
			$new     = $this->redis_incr( $key, 1 );

			if ( false !== $new && $new <= $current ) {
				$target = max( $current + 1, $this->generation_seed() );
				$new    = $this->redis_incr( $key, $target - $new );
				if ( false === $new || $new <= $current ) {
					// A counter that cannot be moved past this generation
					// would hand other requests a generation that is still
					// live. Remove it; the next reader seeds a fresh one.
					try {
						$this->conn->del( $key );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
					$new = false;
				}
			}

			if ( false === $new ) {
				return $this->redis_flush_by_delete();
			}

			$this->generation         = $new;
			$this->generation_private = false;
			$this->persist_generation_floor( $new );
			$this->queue_reclaim();
			return true;
		}

		/**
		 * Delete every key this site owns in every generation, for when the
		 * counter cannot be moved. Covers the current `{salt}:g{n}:{blog}:`
		 * shape, the flat `{salt}:{blog}:` shape of 1.4.4 and earlier, and the
		 * `{salt}|g{n}:` keys and `{salt}.xspeed-oc-gen` counter of the build
		 * QA tested before #813. The lookaheads keep a neighbouring salt such
		 * as `{salt}:3` or `{salt}:g5:3` out of the match. The counter itself
		 * is kept.
		 *
		 * @return bool True only when every matching key was deleted.
		 */
		private function redis_flush_by_delete() {
			$salt = preg_quote( $this->salt, '/' );
			return -1 !== $this->delete_redis_pattern(
				$this->escape_glob( $this->salt ) . '*',
				'/\A' . $salt . '(?::g[0-9]+:[0-9]+:(?!g[0-9]+:[0-9]+:|generation\z)|:[0-9]+:(?!g[0-9]+:[0-9]+:|generation\z)|\|g[0-9]+:[0-9]+:|\.xspeed-oc-gen\z)/'
			);
		}

		/**
		 * INCRBY on the connected Redis client.
		 *
		 * @return int|false The new value, or false when Redis refused.
		 */
		private function redis_incr( $key, $by ) {
			try {
				$new = $this->redis_single( array( 'INCRBY', $key, (string) (int) $by ) );
			} catch ( \Throwable $e ) {
				return false;
			}
			return is_int( $new ) || ( is_string( $new ) && is_numeric( $new ) ) ? (int) $new : false;
		}

		/**
		 * Value for a new Redis counter: the current time in milliseconds,
		 * and never below the database floor. Either guard alone stops an
		 * evicted counter from coming back at a generation an earlier flush
		 * already retired: the floor holds even if the clock went back, and
		 * the clock holds even if a floor write was lost.
		 *
		 * @return int
		 */
		private function generation_seed() {
			return max( $this->generation_floor() + 1, (int) floor( microtime( true ) * 1000 ) );
		}

		/**
		 * Read or create the Redis generation counter for this request.
		 *
		 * A missing counter (never created, evicted, deleted by a disable) is
		 * seeded with SET NX, so concurrent requests agree on one value. The
		 * database floor is read only here and on a flush, so a normal request
		 * adds no query.
		 *
		 * @return int
		 */
		private function resolve_redis_generation() {
			$key = $this->generation_key();
			try {
				$this->generation_private = false;
				$val                      = $this->conn->get( $key );
				if ( $this->is_generation_value( $val ) ) {
					return (int) $val;
				}
				$seed    = $this->generation_seed();
				$replies = $this->redis_pipeline(
					array(
						array( 'SET', $key, (string) $seed, 'NX' ),
						array( 'GET', $key ),
					)
				);
				if ( is_array( $replies ) && $this->is_generation_value( $replies[1] ) ) {
					return (int) $replies[1];
				}
				$this->generation_private = true;
				return $seed;
			} catch ( \Throwable $e ) {
				// Unreadable counter: a fresh generation nobody else uses
				// misses every read, which is safe, where guessing an old one
				// could serve values a flush retired.
				$this->generation_private = true;
				return $this->generation_seed();
			}
		}

		/**
		 * Schedule the reclaim event at the end of the request. Once per
		 * request, however many times it flushed.
		 */
		private function queue_reclaim() {
			if ( $this->reclaim_queued || ! function_exists( 'add_action' ) ) {
				return;
			}
			$this->reclaim_queued = true;
			add_action( 'shutdown', array( $this, 'schedule_reclaim' ) );
		}

		/**
		 * Schedule the plugin's reclaim of old Redis generations, unless one
		 * is already pending. The event carries no arguments, so
		 * wp_next_scheduled() sees an event queued by any earlier flush.
		 *
		 * @return void
		 */
		public function schedule_reclaim() {
			if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
				return;
			}
			// Disabling Object Cache flushes, then removes this file in the same request.
			if ( defined( 'WP_CONTENT_DIR' ) && ! file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
				return;
			}
			try {
				if ( ! wp_next_scheduled( self::RECLAIM_HOOK ) ) {
					wp_schedule_single_event( time() + 30, self::RECLAIM_HOOK );
				}
			} catch ( \Throwable $e ) {
				// The keys stay unreachable either way; the next flush retries.
				unset( $e );
			}
		}

		/**
		 * Advance this site's namespace generation, invalidating every key
		 * built from it in Memcached.
		 *
		 * @return bool
		 */
		private function bump_generation() {
			$key     = $this->generation_key();
			$current = $this->generation();
			$new     = $this->increment_counter( $key, 1 );

			// Memcached refuses to increment a counter that does not exist,
			// either never seeded or evicted under LRU. Seed it with add(),
			// which only succeeds when the key is still absent; losing that
			// race means another request created it, so increment again.
			if ( false === $new ) {
				try {
					// A string: the bundled client types this parameter.
					$seeded = $this->conn->add( $key, (string) ( $current + 1 ), 0 );
				} catch ( \Throwable $e ) {
					$seeded = false;
				}
				$new = $seeded ? $current + 1 : $this->increment_counter( $key, 1 );
			}

			// A re-seeded counter may be below the database floor. Increment
			// by the difference so a concurrent flush is never overwritten.
			if ( false !== $new && $new <= $current ) {
				$new = $this->increment_counter( $key, $current + 1 - $new );
			}

			if ( false === $new || $new <= $current ) {
				return false;
			}

			$this->generation = (int) $new;
			$this->persist_generation_floor( $this->generation );
			return true;
		}

		/**
		 * Atomically add to a counter on whichever client is connected.
		 *
		 * @param string $key Counter key.
		 * @param int    $by  Amount to add.
		 * @return int|false The new value, or false when the counter write fails.
		 */
		private function increment_counter( $key, $by ) {
			try {
				if ( 'builtin-memcached' === $this->client ) {
					$new = $this->conn->incr( $key, $by );
				} else {
					$new = $this->conn->increment( $key, $by );
				}
			} catch ( \Throwable $e ) {
				return false;
			}
			return is_numeric( $new ) ? (int) $new : false;
		}

		/**
		 * The counter key holding this site's namespace generation. Salted, so
		 * each site owns its own counter on a shared daemon.
		 *
		 * @return string
		 */
		private function generation_key() {
			if ( 'redis' === $this->backend ) {
				return $this->salt . self::REDIS_COUNTER_SUFFIX;
			}
			// Memcached keeps the key it has always used, so an upgrade does
			// not lose the generation a site is on.
			return $this->salt . '.xspeed-oc-gen';
		}

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

		/**
		 * The lowest generation this site may use, read from the database.
		 *
		 * The counter lives in the cache it namespaces, and either backend
		 * can evict it; the database cannot, so this is what stops an
		 * eviction from rewinding the namespace and resurrecting keys a Purge
		 * already cleared.
		 *
		 * Read straight through $wpdb rather than get_option(), because the
		 * options API routes through this very cache and would recurse. Returns
		 * 1 whenever the database is not available yet; the drop-in loads
		 * before $wpdb exists, and on those early requests nothing has been
		 * flushed anyway.
		 *
		 * @return int
		 */
		private function generation_floor() {
			if ( null !== $this->generation_floor ) {
				return $this->generation_floor;
			}

			$this->generation_floor = 1;

			if ( ! isset( $GLOBALS['wpdb'] ) ) {
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
		 * The upsert only ever raises the stored value: two flushes finishing
		 * out of order must not leave the lower generation as the floor.
		 *
		 * @param int $generation The generation just written.
		 * @return void
		 */
		private function persist_generation_floor( $generation ) {
			if ( ! isset( $GLOBALS['wpdb'] ) ) {
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
						 ON DUPLICATE KEY UPDATE option_value = IF( CAST( option_value AS UNSIGNED ) < CAST( VALUES(option_value) AS UNSIGNED ), VALUES(option_value), option_value )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is derived from $wpdb, never user input.
						self::GENERATION_OPTION,
						(string) (int) $generation
					)
				);
				$this->generation_floor = max( (int) $this->generation_floor, (int) $generation );
			} catch ( \Throwable $e ) {
				// Best effort: the cached counter still carries the flush for
				// as long as it survives, and a re-seeded Redis counter starts
				// from the clock.
				return;
			}
		}

		/**
		 * Read this site's namespace generation, once per request.
		 *
		 * @return int
		 */
		private function generation() {
			if ( null !== $this->generation ) {
				return $this->generation;
			}

			if ( 'redis' === $this->backend ) {
				$this->generation = $this->persistent && $this->conn ? $this->resolve_redis_generation() : 1;
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
		 * Delete matching Redis keys, or return -1 if any scan or deletion fails.
		 * Literal glob segments must pass through escape_glob(). (FBS-83119)
		 */
		private function delete_redis_pattern( $pattern, $filter ) {
			if ( 'redis' !== $this->backend || ! $this->conn ) {
				return -1;
			}
			try {
				if ( method_exists( $this->conn, 'delete_by_pattern' ) ) {
					$deleted = $this->conn->delete_by_pattern( $pattern, 500, $filter );
					return false === $deleted ? -1 : $deleted;
				}
				if ( $this->conn instanceof \Redis ) {
					$deleted = 0;
					$cursor  = '0';
					do {
						// scan() conflates an empty page with failure. The raw
						// reply keeps the cursor and keys separate in both cases.
						$reply = $this->conn->rawCommand( 'SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '500' );
						if ( ! is_array( $reply ) || count( $reply ) !== 2 || ! is_string( $reply[0] ) || ! ctype_digit( $reply[0] ) || ! is_array( $reply[1] ) ) {
							return -1;
						}
						foreach ( $reply[1] as $key ) {
							if ( ! is_string( $key ) ) {
								return -1;
							}
						}
						$cursor = $reply[0];
						$keys   = preg_grep( $filter, $reply[1] );
						if ( ! empty( $keys ) ) {
							$keys = array_values( $keys );
							$n    = $this->conn->unlink( $keys );
							// Redis before 4.0 has no UNLINK; any other error is a failure.
							if ( false === $n && 0 === strpos( (string) $this->conn->getLastError(), 'ERR unknown command' ) ) {
								$this->conn->clearLastError();
								$n = $this->conn->del( $keys );
							}
							if ( false === $n ) {
								return -1;
							}
							$deleted += $n;
						}
					} while ( '0' !== $cursor );
					return $deleted;
				}
			} catch ( \Throwable $e ) {
				$this->record_flush_failure( $e->getMessage() );
				return -1;
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
			// salt:g{gen}:{prefix}:{group}:{key}, so match
			// salt:g{gen}:*:{group}:* to cover both blog-prefixed and global
			// groups for this site's namespace.
			// (FBS-83119)
			// Only the current generation holds live values; older ones are
			// already unreachable and the reclaim event deletes them. The
			// counter is read again first, since another request may have
			// flushed since this one resolved it. A flush landing during the
			// scan retires the whole generation, this group included.
			if ( $this->is_persistent_group( $group ) && 'redis' === $this->backend && '' !== $this->salt && $this->conn ) {
				try {
					$gen = $this->refresh_generation();
					return -1 !== $this->delete_redis_pattern(
						$this->escape_glob( $this->salt ) . ':g' . $gen . ':*:' . $this->escape_glob( $group ) . ':*',
						'/\A' . preg_quote( $this->salt, '/' ) . ':g' . $gen . ':[0-9]+:' . preg_quote( $group, '/' ) . ':/'
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
