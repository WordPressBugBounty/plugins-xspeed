<?php
/**
 * Object_Cache — read-only detector + flusher + wp-config snippet
 * generator for the persistent object cache.
 *
 * We deliberately do NOT install our own object-cache.php drop-in
 * from this Free release — that's invasive, has many failure modes
 * (auth, TLS, cluster vs single, Redis vs Predis vs phpredis,
 * Memcache vs Memcached), and changes how every site reads/writes
 * persistent state. The Free plugin's role is:
 *
 *   1. Tell the user whether a drop-in is currently active and
 *      which backend it appears to be.
 *   2. Provide a Flush button that calls wp_cache_flush() — which
 *      works regardless of which drop-in is installed.
 *   3. Save backend-config values (host, port, password, etc.) and
 *      render a wp-config.php snippet the user can paste, so the
 *      flow is "configure here → copy snippet → install drop-in"
 *      without us writing to wp-config ourselves.
 *
 * The Pro plugin (or a later Free release once well tested) can
 * ship a drop-in that consumes these saved values automatically.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Object_Cache {

	/**
	 * Inspect the runtime + filesystem for a persistent object cache.
	 *
	 * @return array{
	 *   drop_in_installed: bool,
	 *   drop_in_path: string,
	 *   drop_in_label: string,
	 *   backend: string,        // redis|memcached|apcu|wp_default|unknown
	 *   wp_cache_active: bool,  // wp_using_ext_object_cache
	 *   degraded: bool,         // ours is installed but NOT persisting
	 *   persistent: bool,       // ours is installed AND persisting
	 *   class_available: array<string,bool>
	 * }
	 */
	public static function detect(): array {
		$dropin       = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/object-cache.php' : '';
		$has_drop_in  = '' !== $dropin && file_exists( $dropin );
		$label        = $has_drop_in ? self::sniff_drop_in_label( $dropin ) : '';
		$ext_in_use   = function_exists( 'wp_using_ext_object_cache' ) ? (bool) wp_using_ext_object_cache() : false;

		// When OUR drop-in is the live one it exposes whether it actually
		// connected a persistent backend. A drop-in that's installed but
		// degraded reports wp_using_ext_object_cache()=true yet persists
		// nothing — the silent failure that makes a site slow. Read the honest
		// state straight off the running instance. (FBS-82210)
		$degraded   = false;
		$persistent = false;
		if ( $has_drop_in && isset( $GLOBALS['wp_object_cache'] ) && is_object( $GLOBALS['wp_object_cache'] ) ) {
			$oc = $GLOBALS['wp_object_cache'];
			if ( method_exists( $oc, 'is_persistent' ) ) {
				$persistent = (bool) $oc->is_persistent();
				$degraded   = ! $persistent;
			}
		}

		// Class sniffer — independent of any plugin. Tells us what's
		// available to actually use, separate from what's wired up.
		$class_available = array(
			'Redis'     => class_exists( '\\Redis' ),
			'Memcached' => class_exists( '\\Memcached' ),
			'Memcache'  => class_exists( '\\Memcache' ),
			'APCu'      => function_exists( 'apcu_enabled' ) && @apcu_enabled(),
		);

		$backend = 'unknown';
		if ( ! $ext_in_use ) {
			$backend = 'wp_default';
		} elseif ( $has_drop_in ) {
			// Authoritative source first: our own drop-in records the chosen
			// backend in the XSPEED_OC_BACKEND constant (written to wp-config
			// on enable). The drop-in label is the generic
			// "XSPEED_OBJECT_CACHE_DROPIN" and does NOT contain the backend
			// name, so the label sniff below would always yield "unknown" for
			// our drop-in — read the constant instead. (FBS-82111)
			if ( defined( 'XSPEED_OC_BACKEND' ) && '' !== (string) constant( 'XSPEED_OC_BACKEND' ) ) {
				$backend = strtolower( (string) constant( 'XSPEED_OC_BACKEND' ) );
			} else {
				// Foreign drop-in (W3TC / Redis Object Cache / …): best-effort
				// guess from the label, which usually names the backend.
				$lc = strtolower( $label );
				if ( false !== strpos( $lc, 'redis' ) ) {
					$backend = 'redis';
				} elseif ( false !== strpos( $lc, 'memcached' ) || false !== strpos( $lc, 'memcache' ) ) {
					$backend = 'memcached';
				} elseif ( false !== strpos( $lc, 'apcu' ) ) {
					$backend = 'apcu';
				}
			}
		}

		return array(
			'drop_in_installed' => $has_drop_in,
			'drop_in_path'      => $dropin,
			'drop_in_label'     => $label,
			'backend'           => $backend,
			'wp_cache_active'   => $ext_in_use,
			'degraded'          => $degraded,
			'persistent'        => $persistent,
			'class_available'   => $class_available,
		);
	}

	/**
	 * Flush whatever cache backend is wired up. Works against any
	 * compliant drop-in OR the WP default in-memory cache.
	 */
	public static function flush(): bool {
		if ( ! function_exists( 'wp_cache_flush' ) ) {
			return false;
		}
		return (bool) wp_cache_flush();
	}

	/**
	 * Render a paste-into-wp-config.php snippet for the chosen backend
	 * using the supplied settings. The constant names match the
	 * conventions of the widely-used Redis Object Cache + W3TC drop-ins
	 * so users with those installed get a working configuration
	 * without any further translation.
	 */
	public static function render_config_snippet( array $opts ): string {
		$backend = (string) ( $opts['backend'] ?? 'redis' );
		$lines   = array( "/* xSpeed object cache config — paste above the \"That's all, stop editing!\" comment in wp-config.php. */" );

		if ( 'redis' === $backend ) {
			$host    = self::str( $opts, 'redis_host', '127.0.0.1' );
			$port    = self::int( $opts, 'redis_port', 6379 );
			$user    = self::str( $opts, 'redis_user', '' );
			$pass    = self::str( $opts, 'redis_password', '' );
			$db      = self::int( $opts, 'redis_database', 0 );
			$prefix  = self::str( $opts, 'key_prefix', '' );
			$timeout = self::int( $opts, 'connection_timeout', 1 );
			$persist = ! empty( $opts['persistent'] );

			$lines[] = "define( 'WP_REDIS_HOST', '" . self::esc( $host ) . "' );";
			$lines[] = "define( 'WP_REDIS_PORT', " . $port . ' );';
			// Emit the ACL username only when set (Redis 6+). The drop-in
			// reads it; an empty user keeps the legacy default-user behavior.
			if ( '' !== $user ) {
				$lines[] = "define( 'WP_REDIS_USER', '" . self::esc( $user ) . "' );";
			}
			if ( '' !== $pass ) {
				$lines[] = "define( 'WP_REDIS_PASSWORD', '" . self::esc( $pass ) . "' );";
			}
			$lines[] = "define( 'WP_REDIS_DATABASE', " . $db . ' );';
			if ( '' !== $prefix ) {
				$lines[] = "define( 'WP_CACHE_KEY_SALT', '" . self::esc( $prefix ) . "' );";
			}
			$lines[] = "define( 'WP_REDIS_TIMEOUT', " . $timeout . ' );';
			$lines[] = "define( 'WP_REDIS_PERSISTENT', " . ( $persist ? 'true' : 'false' ) . ' );';
		} elseif ( 'memcached' === $backend ) {
			$host    = self::str( $opts, 'memcached_host', '127.0.0.1' );
			$port    = self::int( $opts, 'memcached_port', 11211 );
			$prefix  = self::str( $opts, 'key_prefix', '' );
			$lines[] = "global \$memcached_servers;";
			$lines[] = "\$memcached_servers = array( array( '" . self::esc( $host ) . "', " . $port . ' ) );';
			if ( '' !== $prefix ) {
				$lines[] = "define( 'WP_CACHE_KEY_SALT', '" . self::esc( $prefix ) . "' );";
			}
		} else {
			$lines[] = '// No snippet for backend: ' . $backend;
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Identifier embedded in our drop-in so we can recognise (and safely
	 * overwrite / remove) only files we installed.
	 */
	private const DROPIN_TAG = 'XSPEED_OBJECT_CACHE_DROPIN';

	/** Markers wrapping the constants we write into wp-config.php. */
	private const CONFIG_BEGIN = '/* BEGIN xSpeed Object Cache */';
	private const CONFIG_END   = '/* END xSpeed Object Cache */';

	/**
	 * True when wp-content/object-cache.php exists AND is ours (carries the
	 * drop-in tag). Lets callers decide whether a re-sync applies without
	 * exposing the tag itself.
	 */
	public static function is_our_dropin_present(): bool {
		$target = WP_CONTENT_DIR . '/object-cache.php';
		if ( ! file_exists( $target ) ) {
			return false;
		}
		$contents = file_get_contents( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- read-only ours-check; WP_Filesystem may not be initialized this early.
		return is_string( $contents ) && false !== strpos( $contents, self::DROPIN_TAG );
	}

	/**
	 * Live connection test against the configured backend. Never throws;
	 * returns a structured pass/fail the UI can show before we write anything.
	 *
	 * @param array $opts Settings array (backend, redis_host, ...).
	 * @return array{ok:bool,backend:string,message:string,latency_ms:?float}
	 */
	public static function test_connection( array $opts ): array {
		$backend = (string) ( $opts['backend'] ?? 'redis' );
		$start   = microtime( true );

		try {
			if ( 'memcached' === $backend ) {
				$host    = self::str( $opts, 'memcached_host', '127.0.0.1' );
				$port    = self::int( $opts, 'memcached_port', 11211 );
				$timeout = self::int( $opts, 'connection_timeout', 1 );

				// Prefer the ext/memcached extension (libmemcached).
				if ( class_exists( '\\Memcached' ) ) {
					$mc = new \Memcached();
					$mc->addServer( $host, $port );
					$stats = @$mc->getStats();
					$ok    = is_array( $stats ) && ! empty( array_filter( $stats ) );
					return self::test_result(
						$ok,
						$backend,
						$ok ? "Connected to Memcached at {$host}:{$port} (ext/memcached)." : "Could not reach Memcached at {$host}:{$port}.",
						$start
					);
				}

				// Pure-PHP fallback — our own client, zero dependencies.
				$mc = new Memcached_Client( $host, $port, (float) $timeout );
				if ( ! $mc->connect() ) {
					return self::test_result( false, $backend, "Could not connect to Memcached at {$host}:{$port}." );
				}
				$ver = $mc->version();
				$mc->close();
				$ok = ( false !== $ver );
				return self::test_result(
					$ok,
					$backend,
					$ok ? "Connected to Memcached at {$host}:{$port} (built-in client)." : "Memcached at {$host}:{$port} did not respond.",
					$start
				);
			}

			// Redis. Prefer the phpredis extension (faster C client); fall back
			// to xSpeed's own dependency-free Redis_Client (pure-PHP RESP over a
			// socket) so Redis works even without the extension — true
			// plug-and-play, no bundled library.
			$host    = self::str( $opts, 'redis_host', '127.0.0.1' );
			$port    = self::int( $opts, 'redis_port', 6379 );
			$timeout = self::int( $opts, 'connection_timeout', 1 );
			$user    = self::str( $opts, 'redis_user', '' );
			$pass    = self::str( $opts, 'redis_password', '' );
			$db      = self::int( $opts, 'redis_database', 0 );

			if ( class_exists( '\\Redis' ) ) {
				$redis = new \Redis();
				if ( ! @$redis->connect( $host, $port, $timeout ) ) {
					return self::test_result( false, $backend, "Could not connect to Redis at {$host}:{$port}." );
				}
				// Redis 6+ ACL: when a username is set, authenticate as that user
				// (phpredis ≥ 5.3 accepts ['user'=>..,'pass'=>..]); otherwise keep
				// the legacy password-only form that authenticates as `default`.
				$auth_ok = self::phpredis_auth( $redis, $user, $pass );
				if ( null !== $auth_ok && ! $auth_ok ) {
					return self::test_result( false, $backend, '' !== $user ? 'Redis authentication failed — check the Redis user + password (ACL).' : 'Redis authentication failed — check the password.' );
				}
				if ( $db > 0 && ! @$redis->select( $db ) ) {
					return self::test_result( false, $backend, "Could not select Redis database {$db}." );
				}
				$pong = @$redis->ping();
				$ok   = ( '+PONG' === $pong || true === $pong || 'PONG' === $pong );
				if ( ! $ok ) {
					return self::test_result( false, $backend, "Redis at {$host}:{$port} did not respond to PING.", $start );
				}
				// Write-verification: PING only proves auth, not that the user can
				// STORE data. ACL-namespaced hosts (xCloud) restrict a user to a
				// key pattern (~redis:<id>:*); a SET outside it is NOPERM-denied and
				// the drop-in's @$redis->set() swallows it — enable() would then
				// green-light a cache that silently persists nothing. Do a real
				// SET/GET/DEL round-trip on a probe key built with the user's key
				// prefix so a namespace restriction is caught here. (FBS-83118 OC-2)
				$probe = self::probe_key( $opts );
				$set   = @$redis->set( $probe, '1', 5 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- NOPERM/denied is the negative answer we report, not a fatal.
				$got   = @$redis->get( $probe ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same.
				@$redis->del( $probe ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup.
				if ( ! $set || '1' !== (string) $got ) {
					return self::test_result( false, $backend, self::write_denied_message( $opts, $host, $port ), $start );
				}
				return self::test_result( true, $backend, "Connected to Redis at {$host}:{$port} (phpredis).", $start );
			}

			// Pure-PHP fallback — our own client, zero dependencies.
			$rc = new Redis_Client( $host, $port, (float) $timeout, false );
			if ( ! $rc->connect() ) {
				return self::test_result( false, $backend, "Could not connect to Redis at {$host}:{$port}." );
			}
			// Authenticate when a user OR a password is set. Gating on password
			// alone skipped auth for the "ACL user + empty password" case, which
			// then failed later at PING with a misleading message. (FBS-83118 OC-1)
			if ( ( '' !== $pass || '' !== $user ) && false === $rc->auth( $pass, $user ) ) {
				$rc->close();
				return self::test_result( false, $backend, '' !== $user ? 'Redis authentication failed — check the Redis user + password (ACL).' : 'Redis authentication failed — check the password.' );
			}
			if ( $db > 0 ) {
				$rc->select( $db );
			}
			$pong = $rc->ping();
			$ok   = ( is_string( $pong ) && false !== stripos( $pong, 'PONG' ) );
			if ( ! $ok ) {
				$rc->close();
				return self::test_result( false, $backend, "Redis at {$host}:{$port} did not respond to PING.", $start );
			}
			// Write-verification round-trip — same rationale as the phpredis path
			// above. (FBS-83118 OC-2)
			$probe = self::probe_key( $opts );
			$set   = $rc->set( $probe, '1' );
			$got   = $rc->get( $probe );
			$rc->del( $probe );
			$rc->close();
			if ( ! $set || '1' !== (string) $got ) {
				return self::test_result( false, $backend, self::write_denied_message( $opts, $host, $port ), $start );
			}
			return self::test_result( true, $backend, "Connected to Redis at {$host}:{$port} (built-in client).", $start );
		} catch ( \Throwable $e ) {
			return self::test_result( false, $backend, 'Connection error: ' . $e->getMessage() );
		}
	}

	private static function test_result( bool $ok, string $backend, string $message, ?float $start = null ): array {
		return array(
			'ok'         => $ok,
			'backend'    => $backend,
			'message'    => $message,
			'latency_ms' => $start ? round( ( microtime( true ) - $start ) * 1000, 2 ) : null,
		);
	}

	/**
	 * Authenticate a phpredis connection, honoring Redis 6+ ACL usernames.
	 *
	 * Returns null when no auth is needed (empty username AND password) so
	 * callers can distinguish "didn't try" from "tried and failed". When a
	 * username is present we pass ['user'=>..,'pass'=>..] which phpredis
	 * ≥ 5.3 maps to the two-argument AUTH; otherwise the legacy
	 * password-only form authenticates as the built-in `default` user.
	 *
	 * @param \Redis $redis Connected phpredis instance.
	 * @param string $user  ACL username; '' = default user.
	 * @param string $pass  Password.
	 * @return bool|null    true/false on auth attempt, null if none needed.
	 */
	private static function phpredis_auth( $redis, string $user, string $pass ) {
		if ( '' === $user && '' === $pass ) {
			return null;
		}
		try {
			if ( '' !== $user ) {
				return (bool) @$redis->auth( array( 'user' => $user, 'pass' => $pass ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- phpredis throws on bad auth; we report it as a failed test, not a fatal.
			}
			return (bool) @$redis->auth( $pass ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same.
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Build a probe key for the write-verification round-trip. It must land in
	 * the same key space the drop-in writes to, so an ACL namespace restriction
	 * (~<prefix>:*) is exercised. The drop-in salts keys as
	 * `{salt}:{prefix}:{group}:{key}` where the salt is the user's key prefix,
	 * so prefixing the probe with that value makes it match the allowed pattern
	 * on namespaced hosts (xCloud) while staying harmless everywhere else.
	 *
	 * @param array $opts Settings array.
	 * @return string
	 */
	private static function probe_key( array $opts ): string {
		$prefix = self::str( $opts, 'key_prefix', '' );
		$suffix = 'xspeed-oc-probe';
		return '' !== $prefix ? $prefix . ':' . $suffix : $suffix;
	}

	/**
	 * Message for a connect-OK-but-write-denied result. Points ACL/namespaced
	 * hosts at the fix (match the key prefix to the host's Redis Object Cache
	 * Key), which is exactly the xCloud failure mode. (FBS-83118 OC-2)
	 *
	 * @param array  $opts Settings array.
	 * @param string $host Redis host.
	 * @param int    $port Redis port.
	 * @return string
	 */
	private static function write_denied_message( array $opts, string $host, int $port ): string {
		$has_prefix = '' !== self::str( $opts, 'key_prefix', '' );
		$hint       = $has_prefix
			? 'The Redis user may lack write permission for this key prefix (NOPERM).'
			: 'On ACL/namespaced Redis (e.g. xCloud), set Cache Key Prefix to the host\'s "Redis Object Cache Key" so writes land in the permitted namespace.';
		return "Connected to Redis at {$host}:{$port}, but the cache could not store data. {$hint}";
	}

	/**
	 * Full plug-and-play enable: test → write wp-config constants → install
	 * drop-in → verify. Reversible via disable(). Returns a structured result
	 * the REST/UI layer surfaces directly.
	 *
	 * @param array $opts Settings array.
	 * @return array{ok:bool,message:string,steps:array<string,bool>,test:array,detect:array}
	 */
	public static function enable( array $opts ): array {
		$steps = array(
			'connection' => false,
			'wp_config'  => false,
			'drop_in'    => false,
			'verified'   => false,
		);

		// 1. Don't write anything until the backend actually answers.
		$test = self::test_connection( $opts );
		if ( ! $test['ok'] ) {
			return array(
				'ok'      => false,
				'message' => 'Could not enable: ' . $test['message'],
				'steps'   => $steps,
				'test'    => $test,
				'detect'  => self::detect(),
			);
		}
		$steps['connection'] = true;

		// 2. Write the XSPEED_OC_* constants into wp-config.php.
		$steps['wp_config'] = self::write_wp_config( $opts );

		// 3. Install our drop-in.
		$steps['drop_in'] = self::install_dropin();

		// 4. Verify the drop-in is live (best-effort — wp_using_ext_object_cache
		//    reflects state only after the drop-in loads on the NEXT request, so
		//    we verify the file landed + constants are present this request).
		$detect            = self::detect();
		$steps['verified'] = $detect['drop_in_installed'] && self::wp_config_has_block();

		$all_ok = $steps['drop_in'] && ( $steps['wp_config'] || self::backend_uses_no_constants( $opts ) );

		return array(
			'ok'      => $all_ok,
			'message' => $all_ok
				? 'Object cache enabled. Drop-in installed and configured automatically.'
				: ( $steps['drop_in']
					? 'Drop-in installed, but wp-config.php is not writable — add the snippet manually (shown below).'
					: 'Could not install the object-cache drop-in (wp-content not writable).' ),
			'steps'   => $steps,
			'test'    => $test,
			'detect'  => $detect,
		);
	}

	/**
	 * Full reverse of enable(): remove drop-in + strip our wp-config block.
	 *
	 * @return array{ok:bool,message:string,steps:array<string,bool>,detect:array}
	 */
	public static function disable(): array {
		$dropin_removed = self::remove_dropin();
		$config_removed = self::remove_wp_config();

		return array(
			'ok'      => $dropin_removed,
			'message' => $dropin_removed
				? 'Object cache disabled. Drop-in removed and wp-config.php cleaned.'
				: 'Could not remove the drop-in — wp-content may not be writable.',
			'steps'   => array(
				'drop_in'   => $dropin_removed,
				'wp_config' => $config_removed,
			),
			'detect'  => self::detect(),
		);
	}

	/**
	 * Copy our object-cache.php template into wp-content/. Mirrors
	 * Cache::install_dropin(): only overwrites our own file, backs up a
	 * foreign drop-in before replacing it.
	 */
	public static function install_dropin(): bool {
		$source = ( defined( 'XSPEED_DIR' ) ? XSPEED_DIR : plugin_dir_path( __DIR__ ) . '../' ) . 'includes/object-cache.php';
		$target = WP_CONTENT_DIR . '/object-cache.php';
		if ( ! file_exists( $source ) ) {
			return false;
		}

		$fs = self::fs();
		if ( ! $fs ) {
			return false;
		}

		$source_contents = $fs->get_contents( $source );
		if ( ! is_string( $source_contents ) ) {
			return false;
		}

		if ( file_exists( $target ) ) {
			$existing  = $fs->get_contents( $target );
			$is_xspeed = is_string( $existing ) && false !== strpos( $existing, self::DROPIN_TAG );

			if ( $is_xspeed ) {
				if ( $existing === $source_contents ) {
					return true;
				}
				return (bool) $fs->put_contents( $target, $source_contents, FS_CHMOD_FILE );
			}

			// Foreign drop-in — back it up before overwriting.
			$upload  = wp_upload_dir( null, false );
			$basedir = isset( $upload['basedir'] ) ? trailingslashit( $upload['basedir'] ) . 'xspeed-backups' : false;
			if ( $basedir ) {
				if ( ! file_exists( $basedir ) ) {
					wp_mkdir_p( $basedir );
				}
				$backup = $basedir . '/object-cache.foreign-' . gmdate( 'Ymd-His' ) . '.php.bak';
				$fs->move( $target, $backup, true );
			} else {
				$fs->delete( $target );
			}
		}

		return (bool) $fs->put_contents( $target, $source_contents, FS_CHMOD_FILE );
	}

	/**
	 * Remove our drop-in (only if it's ours). Returns true when no xSpeed
	 * drop-in remains.
	 */
	public static function remove_dropin(): bool {
		$target = WP_CONTENT_DIR . '/object-cache.php';
		if ( ! file_exists( $target ) ) {
			return true;
		}
		$fs = self::fs();
		if ( ! $fs ) {
			return false;
		}
		$contents = $fs->get_contents( $target );
		if ( is_string( $contents ) && false !== strpos( $contents, self::DROPIN_TAG ) ) {
			wp_delete_file( $target );
			return ! file_exists( $target );
		}
		// Not ours — leave it, but report success (nothing of ours to remove).
		return true;
	}

	/**
	 * Write the XSPEED_OC_* constants between our markers in wp-config.php.
	 * Idempotent: replaces an existing block. Reversible via remove_wp_config().
	 */
	public static function write_wp_config( array $opts ): bool {
		$fs = self::fs();
		$wp_config = ABSPATH . 'wp-config.php';
		if ( ! $fs || ! file_exists( $wp_config ) || ! $fs->is_writable( $wp_config ) ) {
			return false;
		}

		$config = $fs->get_contents( $wp_config );
		if ( ! is_string( $config ) ) {
			return false;
		}

		$block = self::wp_config_block( $opts );

		// Replace an existing xSpeed block if present, else insert after <?php.
		// IMPORTANT: $block is inserted via preg_replace_callback returning it
		// VERBATIM — never as a preg_replace replacement string. In a
		// replacement string, `\` and `$` are special (backref escapes), so a
		// constant value ending in a backslash (e.g. a Redis password or key
		// prefix like "secret\") or containing "$1" would corrupt the output:
		// esc()'s "secret\\" collapses back to "secret\", producing
		// 'secret\' ) — a PHP parse error that white-screens the whole site.
		// The callback form treats $block as literal text. (FBS-82111 Bug 1)
		$pattern = '/' . preg_quote( self::CONFIG_BEGIN, '/' ) . '.*?' . preg_quote( self::CONFIG_END, '/' ) . "\s*/s";
		if ( preg_match( $pattern, $config ) ) {
			$config = preg_replace_callback(
				$pattern,
				static function () use ( $block ) {
					return $block;
				},
				$config,
				1
			);
		} else {
			$config = preg_replace_callback(
				'/(<\?php)/',
				static function ( $m ) use ( $block ) {
					return $m[1] . "\n" . $block;
				},
				$config,
				1
			);
		}

		return (bool) $fs->put_contents( $wp_config, $config, FS_CHMOD_FILE );
	}

	/**
	 * Strip our wp-config block. Returns true if the block is gone afterward.
	 */
	public static function remove_wp_config(): bool {
		$fs = self::fs();
		$wp_config = ABSPATH . 'wp-config.php';
		if ( ! $fs || ! file_exists( $wp_config ) ) {
			return true;
		}
		if ( ! $fs->is_writable( $wp_config ) ) {
			return false;
		}
		$config = $fs->get_contents( $wp_config );
		if ( ! is_string( $config ) ) {
			return false;
		}
		$pattern = '/' . preg_quote( self::CONFIG_BEGIN, '/' ) . '.*?' . preg_quote( self::CONFIG_END, '/' ) . "\s*/s";
		$config  = preg_replace( $pattern, '', $config );
		return (bool) $fs->put_contents( $wp_config, $config, FS_CHMOD_FILE );
	}

	/**
	 * The marker-wrapped constants block written into wp-config.php. Uses
	 * XSPEED_OC_* names (our drop-in reads these first, then falls back to
	 * WP_REDIS_* for interop).
	 */
	private static function wp_config_block( array $opts ): string {
		$backend = (string) ( $opts['backend'] ?? 'redis' );
		$lines   = array( self::CONFIG_BEGIN );
		$lines[] = "define( 'XSPEED_OC_BACKEND', '" . self::esc( $backend ) . "' );";

		if ( 'memcached' === $backend ) {
			$lines[] = "define( 'XSPEED_OC_HOST', '" . self::esc( self::str( $opts, 'memcached_host', '127.0.0.1' ) ) . "' );";
			$lines[] = "define( 'XSPEED_OC_PORT', " . self::int( $opts, 'memcached_port', 11211 ) . ' );';
		} else {
			$lines[] = "define( 'XSPEED_OC_HOST', '" . self::esc( self::str( $opts, 'redis_host', '127.0.0.1' ) ) . "' );";
			$lines[] = "define( 'XSPEED_OC_PORT', " . self::int( $opts, 'redis_port', 6379 ) . ' );';
			$user    = self::str( $opts, 'redis_user', '' );
			if ( '' !== $user ) {
				$lines[] = "define( 'XSPEED_OC_USER', '" . self::esc( $user ) . "' );";
			}
			$pass    = self::str( $opts, 'redis_password', '' );
			if ( '' !== $pass ) {
				$lines[] = "define( 'XSPEED_OC_PASSWORD', '" . self::esc( $pass ) . "' );";
			}
			$lines[] = "define( 'XSPEED_OC_DATABASE', " . self::int( $opts, 'redis_database', 0 ) . ' );';
			$lines[] = "define( 'XSPEED_OC_TIMEOUT', " . self::int( $opts, 'connection_timeout', 1 ) . ' );';
			$lines[] = "define( 'XSPEED_OC_PERSISTENT', " . ( ! empty( $opts['persistent'] ) ? 'true' : 'false' ) . ' );';
		}
		$prefix = self::str( $opts, 'key_prefix', '' );
		if ( '' !== $prefix ) {
			$lines[] = "define( 'XSPEED_OC_SALT', '" . self::esc( $prefix ) . "' );";
		}
		$lines[] = self::CONFIG_END;
		return implode( "\n", $lines ) . "\n";
	}

	private static function wp_config_has_block(): bool {
		$wp_config = ABSPATH . 'wp-config.php';
		if ( ! file_exists( $wp_config ) ) {
			return false;
		}
		$fs = self::fs();
		if ( ! $fs ) {
			return false;
		}
		$config = $fs->get_contents( $wp_config );
		return is_string( $config ) && false !== strpos( $config, self::CONFIG_BEGIN );
	}

	/**
	 * Memcached config goes through $memcached_servers (handled by our drop-in's
	 * defaults), so a non-writable wp-config isn't necessarily fatal for it.
	 */
	private static function backend_uses_no_constants( array $opts ): bool {
		return false; // both backends currently rely on the constants block
	}

	/**
	 * Initialised WP_Filesystem handle, or null. Plugin Check-compliant access.
	 *
	 * Forces the 'direct' transport when PHP can write the WordPress tree
	 * itself. Without this, WP_Filesystem() can fall back to the FTP transport
	 * (no credentials in a non-interactive context) and fatal in
	 * ftp_fget(). We only need 'direct' — these writes target wp-config.php /
	 * wp-content, both owned by the PHP user on a normal install.
	 */
	private static function fs() {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Pin the method to 'direct' for this call so a missing FTP/SSH config
		// can never trigger the credential-prompt / ftp_*() fatal path. Use a
		// closure on the filter so we don't permanently alter global behaviour.
		$force_direct = static function () {
			return 'direct';
		};
		add_filter( 'filesystem_method', $force_direct, 99 );
		$ok = WP_Filesystem();
		remove_filter( 'filesystem_method', $force_direct, 99 );

		if ( ! $ok || ! $wp_filesystem || 'direct' !== $wp_filesystem->method ) {
			return null;
		}
		return $wp_filesystem;
	}

	private static function sniff_drop_in_label( string $path ): string {
		$head = @file_get_contents( $path, false, null, 0, 2048 );
		if ( ! is_string( $head ) || '' === $head ) {
			return '';
		}
		// PluginName / Plugin Name in standard WP file header form.
		if ( preg_match( '#Plugin Name:\s*([^\r\n]+)#i', $head, $m ) ) {
			return trim( $m[1] );
		}
		// Many drop-ins just put their identity in a comment.
		if ( preg_match( '#\*\s*([A-Za-z][A-Za-z0-9 _\-]{2,40}(?:Cache|Redis|Memcached)[^\r\n]*)#i', $head, $m ) ) {
			return trim( $m[1] );
		}
		return basename( $path );
	}

	private static function str( array $opts, string $key, string $default ): string {
		return isset( $opts[ $key ] ) && '' !== $opts[ $key ] ? (string) $opts[ $key ] : $default;
	}

	private static function int( array $opts, string $key, int $default ): int {
		return isset( $opts[ $key ] ) && '' !== $opts[ $key ] ? (int) $opts[ $key ] : $default;
	}

	private static function esc( string $s ): string {
		return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $s );
	}
}
