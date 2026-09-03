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
		$map = array(
			'backend'  => array( 'XSPEED_OC_BACKEND' ),
			'host'     => array( 'XSPEED_OC_HOST', 'WP_REDIS_HOST' ),
			'port'     => array( 'XSPEED_OC_PORT', 'WP_REDIS_PORT' ),
			'user'     => array( 'XSPEED_OC_USER', 'WP_REDIS_USER' ),
			'password' => array( 'XSPEED_OC_PASSWORD', 'WP_REDIS_PASSWORD' ),
			'database' => array( 'XSPEED_OC_DATABASE', 'WP_REDIS_DATABASE' ),
			'timeout'  => array( 'XSPEED_OC_TIMEOUT', 'WP_REDIS_TIMEOUT' ),
			'salt'     => array( 'XSPEED_OC_SALT', 'WP_CACHE_KEY_SALT' ),
			'persist'  => array( 'XSPEED_OC_PERSISTENT', 'WP_REDIS_PERSISTENT' ),
		);
		if ( isset( $map[ $key ] ) ) {
			foreach ( $map[ $key ] as $const ) {
				if ( defined( $const ) ) {
					return constant( $const );
				}
			}
		}
		return $default;
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
			$this->salt        = (string) xspeed_oc_config( 'salt', '' );
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
			return $this->salt . ':' . $prefix . ':' . $group . ':' . $key;
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
				// FLUSHDB, which would wipe the entire Redis database — including
				// other sites / apps sharing the same DB index. Falls back to
				// FLUSHDB only when no salt is configured (single-tenant) so
				// behavior is unchanged on a dedicated Redis. (FBS-83119)
				if ( '' === $this->salt ) {
					return $this->conn->flushDB();
				}
				return $this->delete_redis_pattern( $this->salt . ':*' ) >= 0;
			}
			return 'builtin-memcached' === $this->client
				? $this->conn->flush_all()
				: $this->conn->flush();
		}

		/**
		 * Delete every Redis key matching $pattern across both client kinds
		 * (phpredis native scan + our pure-PHP Redis_Client). Returns the
		 * count deleted, or -1 if the backend isn't redis. SCAN-based so it
		 * never blocks the server the way KEYS would. (FBS-83119)
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
					$this->delete_redis_pattern( $this->salt . ':*:' . $group . ':*' );
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
