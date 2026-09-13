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
	 *   drop_in_is_ours: bool,   // installed AND carries our tag
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
			// Whether the installed drop-in is OURS. A foreign one (W3TC,
			// Redis Object Cache, LiteSpeed) means the object cache belongs to
			// another plugin: we must not offer to configure or disable it,
			// and "installed" must not be read as "xSpeed is running".
			'drop_in_is_ours'   => $has_drop_in && self::is_our_dropin_present(),
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
			$prefix  = self::effective_salt( $opts );
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
			$lines[] = "define( 'WP_CACHE_KEY_SALT', '" . self::esc( $prefix ) . "' );";
			$lines[] = "define( 'WP_REDIS_TIMEOUT', " . $timeout . ' );';
			$lines[] = "define( 'WP_REDIS_PERSISTENT', " . ( $persist ? 'true' : 'false' ) . ' );';
		} elseif ( 'memcached' === $backend ) {
			$host    = self::str( $opts, 'memcached_host', '127.0.0.1' );
			$port    = self::int( $opts, 'memcached_port', 11211 );
			$prefix  = self::effective_salt( $opts );
			$lines[] = "global \$memcached_servers;";
			$lines[] = "\$memcached_servers = array( array( '" . self::esc( $host ) . "', " . $port . ' ) );';
			$lines[] = "define( 'WP_CACHE_KEY_SALT', '" . self::esc( $prefix ) . "' );";
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
				return self::test_result( true, $backend, self::with_prefix_advisory( "Connected to Redis at {$host}:{$port} (phpredis).", $opts ), $start );
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
			return self::test_result( true, $backend, self::with_prefix_advisory( "Connected to Redis at {$host}:{$port} (built-in client).", $opts ), $start );
		} catch ( \Throwable $e ) {
			return self::test_result( false, $backend, 'Connection error: ' . $e->getMessage() );
		}
	}

	/**
	 * Redis glob metacharacters that must never appear unescaped in a SCAN
	 * MATCH pattern. `\` is the escape character itself.
	 */
	private const GLOB_METACHARS = '*?[]\\';

	/**
	 * Whether a salt contains Redis glob metacharacters.
	 *
	 * The salt is interpolated into the drop-in's scoped-flush patterns. The
	 * drop-in escapes it, so caching and purging are correct either way — but
	 * an explicit Cache Key Prefix exists to match a host's ACL namespace
	 * byte-for-byte, and a wildcard in it is almost always a typo rather than
	 * a real namespace. Reporting it on Test connection is the one place the
	 * user is already looking at their prefix.
	 *
	 * @param string $salt Effective salt.
	 * @return bool
	 */
	public static function salt_has_glob_metachars( string $salt ): bool {
		return strcspn( $salt, self::GLOB_METACHARS ) !== strlen( $salt );
	}

	/**
	 * Append a prefix advisory to an otherwise-successful connection message.
	 *
	 * @param string $message Success message.
	 * @param array  $opts    Settings array.
	 * @return string
	 */
	private static function with_prefix_advisory( string $message, array $opts ): string {
		// Deliberately the TYPED prefix, not effective_salt(): this advisory
		// says "the field you are looking at probably has a typo in it". A
		// host-pinned WP_CACHE_KEY_SALT is not editable from this screen and
		// purges are correctly scoped regardless (the drop-in escapes it), so
		// warning about a host's own namespace would be noise on every ACL
		// host. A derived salt is glob-free by construction.
		$prefix = self::str( $opts, 'key_prefix', '' );
		if ( '' === $prefix || ! self::salt_has_glob_metachars( $prefix ) ) {
			return $message;
		}
		return $message . ' Note: the Cache Key Prefix contains one of * ? [ ] \\.'
			. ' Purges stay scoped to this site, but these are wildcard characters'
			. ' in Redis — check the prefix matches your host\'s key exactly.';
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
	 * Resolve the salt that namespaces this site's cache keys.
	 *
	 * An explicit Cache Key Prefix always wins — on ACL/namespaced hosts
	 * (xCloud) it MUST match the host's "Redis Object Cache Key" or writes are
	 * denied (NOPERM), so we never override what the user typed.
	 *
	 * When the field is blank we derive a stable, per-site salt instead of
	 * falling back to an empty one. An empty salt makes every key look like
	 * `:{prefix}:{group}:{key}` — identical on every install — so two sites
	 * sharing one Redis/Memcached server collide. That is not a theoretical
	 * clash: `blog-details` / `blog-lookup` are how WordPress resolves which
	 * site a request belongs to, so the second site reads the first site's
	 * entries and redirects to it.
	 *
	 * The derived value is a hash of the site URL plus the DB name/prefix, so
	 * it is unique per install, stable across requests (no cache churn), and
	 * safe to embed in wp-config.php.
	 *
	 * @param array $opts Settings array.
	 * @return string Non-empty salt.
	 */
	public static function effective_salt( array $opts ): string {
		$prefix = self::str( $opts, 'key_prefix', '' );
		if ( '' !== $prefix ) {
			return $prefix;
		}

		// A salt WE already wrote is authoritative over a fresh derivation.
		// The keys in the backend are named after it, so re-deriving a
		// different value would orphan every one of them — a needless
		// cache-cooling on an install that is already correctly namespaced.
		// This matters because derive_salt()'s rule was corrected (see there):
		// without this branch, the next wp-config sync would rewrite the block
		// with a new salt and throw away a warm cache on every existing site.
		if ( defined( 'XSPEED_OC_SALT' ) && '' !== (string) constant( 'XSPEED_OC_SALT' ) ) {
			return (string) constant( 'XSPEED_OC_SALT' );
		}

		// A salt the HOST pinned in its own wp-config (outside our block) is
		// the next authority. On ACL/namespaced Redis the host grants write
		// access to that namespace and no other, so replacing it with a
		// derived value gets every write denied (NOPERM) and the site silently
		// stops caching.
		// WP_REDIS_PREFIX is checked alongside WP_CACHE_KEY_SALT and before it,
		// matching the order the schema and the drop-in resolve (#398). It is
		// the name Redis Object Cache uses and the one managed hosts actually
		// write, so honouring only the older alias left the commonest
		// ACL-namespaced case deriving a salt the host denies writes to.
		foreach ( array( 'WP_REDIS_PREFIX', 'WP_CACHE_KEY_SALT' ) as $name ) {
			if ( defined( $name ) && '' !== (string) constant( $name ) ) {
				return (string) constant( $name );
			}
		}

		return self::derive_salt();
	}

	/**
	 * Build a stable per-site salt for installs that left Cache Key Prefix
	 * blank. Distinct per install: the site URL separates sites sharing a
	 * database, and DB name + table prefix separate installs sharing a domain
	 * (e.g. subdirectory installs).
	 *
	 * This MUST stay byte-identical to the drop-in's xspeed_oc_salt(), which
	 * is the harder constraint of the two: the drop-in loads from
	 * wp-settings.php before `$wpdb` exists, so it can only read constants and
	 * the `$table_prefix` global that wp-config.php itself assigns. Normally
	 * the two never both run — enable() writes XSPEED_OC_SALT and both sides
	 * read that constant — but where wp-config is NOT writable no constant is
	 * ever written, and then both fallbacks are live at once in different
	 * processes. Seeding them differently made "Test connection" verify a
	 * different key space than the cache actually writes to: on ACL/namespaced
	 * Redis (xCloud) that reports success while writes are refused, or reports
	 * a failure while caching is fine. (PR #390 QA round 2, issue 2)
	 *
	 * Two specific traps this alignment closes:
	 *
	 *   - WP_HOME / WP_SITEURL are OPTIONAL and absent from a stock
	 *     wp-config.php, so the drop-in's URL part is usually EMPTY while
	 *     get_site_url() always returns a real URL. Using get_site_url() here
	 *     therefore diverged on virtually every default install, not just an
	 *     exotic one — so this reads the same constants, and appends ABSPATH
	 *     on the same condition, rather than reaching for the richer value.
	 *   - `$wpdb->prefix` is PER-BLOG on multisite (`wp_2_` on a sub-site)
	 *     while `$table_prefix` is always the base prefix. The drop-in reads
	 *     the salt once and separates sub-sites with blog_prefix instead, so
	 *     `$table_prefix` is the value that matches; `$wpdb->prefix` would
	 *     hand every sub-site a different salt.
	 *
	 * @return string
	 */
	private static function derive_salt(): string {
		global $table_prefix;

		$url = '';
		if ( defined( 'WP_HOME' ) ) {
			$url = (string) WP_HOME;
		} elseif ( defined( 'WP_SITEURL' ) ) {
			$url = (string) WP_SITEURL;
		}

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
			// Nothing identifying available. Mirrors the drop-in's own
			// last-resort seed so the two still agree.
			$seed = 'xspeed';
		}

		return 'xs' . substr( md5( $seed ), 0, 12 );
	}

	/**
	 * Build a probe key for the write-verification round-trip. It must land in
	 * the same key space the drop-in writes to, so an ACL namespace restriction
	 * (~<prefix>:*) is exercised. The drop-in salts keys as
	 * `{salt}:{prefix}:{group}:{key}`, so prefixing the probe with the same
	 * salt makes it match the allowed pattern on namespaced hosts (xCloud)
	 * while staying harmless everywhere else.
	 *
	 * @param array $opts Settings array.
	 * @return string
	 */
	private static function probe_key( array $opts ): string {
		return self::effective_salt( $opts ) . ':xspeed-oc-probe';
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
		// A drop-in owned by another plugin is left in place by
		// remove_dropin(), which then reports success because nothing of ours
		// is there to remove. Reporting "disabled" for that is a lie: the site
		// still has someone else's object cache running. Say so instead.
		$dropin = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/object-cache.php' : '';
		if ( '' !== $dropin && file_exists( $dropin ) && ! self::is_our_dropin_present() ) {
			return array(
				'ok'      => false,
				'message' => 'The object-cache drop-in belongs to another plugin, so xSpeed left it alone. Turn its object cache off in that plugin instead.',
				'steps'   => array(
					'drop_in'   => false,
					'wp_config' => false,
				),
				'detect'  => self::detect(),
			);
		}

		$dropin_removed = self::remove_dropin();
		$config_removed = self::remove_wp_config();

		/*
		 * The sidecar is the config on a host where wp-config.php is read-only,
		 * and it carries the Redis password. Leaving it behind would keep a
		 * plaintext credential on disk for a feature the admin just switched
		 * off, and a later re-enable would silently pick up stale credentials
		 * from a file nothing in this path had touched.
		 */
		self::delete_sidecar();

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
	/** Sidecar holding the config when wp-config.php cannot be written. */
	private const SIDECAR_FILE = 'xspeed-object-cache.php';

	/**
	 * Absolute path of the config sidecar.
	 *
	 * Lives beside the drop-in in wp-content/ rather than under
	 * wp-content/cache/, which a purge empties -- losing the settings on the
	 * next purge would be a far stranger bug than the one this solves.
	 */
	public static function sidecar_path(): string {
		return WP_CONTENT_DIR . '/' . self::SIDECAR_FILE;
	}

	/**
	 * Write the config sidecar. Used when wp-config.php is not writable, which
	 * is the norm on several managed hosts -- there the panel could otherwise
	 * only ever tell the user to paste a snippet by hand.
	 *
	 * Written as PHP, not JSON: wp-content/ is web-reachable, and a .json here
	 * would serve the Redis password to anyone who guessed the filename. A PHP
	 * file with an ABSPATH guard returns nothing when requested directly.
	 *
	 * @param array<string,mixed> $opts Effective settings to persist.
	 */
	public static function write_sidecar( array $opts ): bool {
		$fs = self::fs();
		if ( ! $fs ) {
			return false;
		}

		$payload = array();
		foreach ( self::SIDECAR_KEYS as $key ) {
			if ( array_key_exists( $key, $opts ) ) {
				$payload[ $key ] = $opts[ $key ];
			}
		}

		$body = "<?php\n"
			. "/**\n"
			. " * xSpeed object-cache configuration.\n"
			. " *\n"
			. " * Written by xSpeed because wp-config.php is not writable on this host.\n"
			. " * The drop-in reads this before WordPress loads. Edit the Object Cache\n"
			. " * panel rather than this file -- it is rewritten on every save.\n"
			. " */\n"
			. "defined( 'ABSPATH' ) || exit;\n\n"
			. 'return ' . var_export( $payload, true ) . ";\n";

		/*
		 * Write to a temp file and rename() into place. The drop-in `include`s
		 * this file BEFORE WordPress loads, so a reader that catches a
		 * half-written copy gets a PHP parse error -- a white screen on every
		 * request, not a degraded cache. rename() within the same directory is
		 * atomic on every filesystem WordPress supports, so a reader sees
		 * either the whole old file or the whole new one.
		 */
		$path = self::sidecar_path();
		$tmp  = $path . '.' . wp_generate_password( 8, false ) . '.tmp';

		if ( ! $fs->put_contents( $tmp, $body, FS_CHMOD_FILE ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem has no atomic move; rename() is the whole point here.
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is reported by the return value.
			$fs->delete( $tmp );
			return false;
		}

		/*
		 * Managed hosts -- the ones this sidecar exists for -- often run
		 * opcache with validate_timestamps off, where `include` would keep
		 * returning the previously compiled array however many times we
		 * rewrite the file. That is the exact panel-says-one-thing,
		 * runtime-does-another failure this change exists to remove.
		 */
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- opcache may be disabled or restricted; nothing to do either way.
		}

		self::forget_sidecar();
		return true;
	}

	/**
	 * Remove the sidecar. Called when wp-config.php becomes writable again, so
	 * two sources can never disagree about the same setting.
	 */
	public static function delete_sidecar(): bool {
		$path = self::sidecar_path();
		if ( ! file_exists( $path ) ) {
			return true;
		}
		$fs = self::fs();
		$ok = $fs ? (bool) $fs->delete( $path ) : false;
		if ( $ok ) {
			self::forget_sidecar();
		}
		return $ok;
	}

	/**
	 * Settings the sidecar carries. Mirrors the fields wp_config_block()
	 * emits, so the two storage paths describe the same configuration.
	 */
	private const SIDECAR_KEYS = array(
		'backend',
		'redis_host',
		'redis_port',
		'redis_user',
		'redis_password',
		'redis_database',
		'memcached_host',
		'memcached_port',
		'key_prefix',
		'connection_timeout',
		'persistent',
	);

	/** Memoized sidecar contents; null until first read. */
	private static $sidecar_cache = null;

	/** Forget the memoized sidecar. */
	public static function forget_sidecar(): void {
		self::$sidecar_cache = null;
	}

	/**
	 * Read the sidecar, or an empty array when there is none.
	 *
	 * @return array<string,mixed>
	 */
	public static function read_sidecar(): array {
		if ( null !== self::$sidecar_cache ) {
			return self::$sidecar_cache;
		}
		$path = self::sidecar_path();
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			self::$sidecar_cache = array();
			return self::$sidecar_cache;
		}
		$data = include $path;
		self::$sidecar_cache = is_array( $data ) ? $data : array();
		return self::$sidecar_cache;
	}

	/**
	 * Host and port of the first server in a `$memcached_servers` global.
	 *
	 * Memcached has no constant convention the way Redis has WP_REDIS_*; this
	 * global IS the convention, and hosts write it in two shapes:
	 *
	 *     array( array( 'host', 11211 ) )              // W3TC pair form
	 *     array( 'default' => array( 'host:11211' ) )  // Memcached Object Cache
	 *
	 * Reading only the first left the second taking the whole "host:port"
	 * string as the hostname, or missing it entirely because its bucket is
	 * keyed `default` rather than 0.
	 *
	 * The drop-in carries `xspeed_oc_first_memcached_server()`, which must
	 * behave identically -- it loads before WordPress and cannot call this
	 * class. ObjectCacheConstantParityTest holds the two together. (#398)
	 *
	 * @param mixed $servers The global's value, unvalidated.
	 * @return array{0:?string,1:?int}|null Host and port, either possibly null.
	 */
	public static function first_memcached_server( $servers ): ?array {
		if ( ! is_array( $servers ) || array() === $servers ) {
			return null;
		}

		$bucket = array_key_exists( 0, $servers ) ? $servers[0] : reset( $servers );

		/*
		 * A bucket is EITHER a [host, port] pair or a list of server entries.
		 * Telling them apart by shape, not by nesting depth: descending into
		 * `array( 'mc.example', 11211 )` yields the host string and drops the
		 * port on the floor, which is the commonest form there is.
		 */
		$entry = $bucket;
		if ( is_array( $bucket ) && isset( $bucket[0] ) && is_array( $bucket[0] ) ) {
			$entry = $bucket[0];
		}

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

		// "host:port", or a bare host. Split only the LAST colon, and only when
		// what follows is numeric -- a unix socket path is a host with no port.
		$at = strrpos( $entry, ':' );
		if ( false !== $at && ctype_digit( substr( $entry, $at + 1 ) ) ) {
			return array( substr( $entry, 0, $at ), (int) substr( $entry, $at + 1 ) );
		}
		return array( $entry, null );
	}

	/**
	 * Names of the constants xSpeed itself wrote into wp-config.php.
	 *
	 * Ownership is decided by LOCATION, not by name. Our block is fenced by
	 * CONFIG_BEGIN / CONFIG_END, so a define inside it is one we wrote and a
	 * define anywhere else belongs to the host -- even when both are called
	 * `XSPEED_OC_HOST`, which is exactly what a user pasting our own snippet
	 * by hand produces.
	 *
	 * Judging by prefix instead is what made the panel treat xSpeed's own
	 * values as host-pinned: the field locked, the "manage this here" control
	 * could not unlock it, and Revert handed the field back to our snapshot
	 * rather than to the host. (#398)
	 *
	 * @return string[] Constant names, empty when the block is absent.
	 */

	public static function our_constants(): array {
		if ( null !== self::$our_constants_cache ) {
			return self::$our_constants_cache;
		}
		$cache = array();

		$wp_config = ABSPATH . 'wp-config.php';
		if ( ! file_exists( $wp_config ) || ! is_readable( $wp_config ) ) {
			self::$our_constants_cache = $cache;
			return $cache;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading our own block; WP_Filesystem is not always initialised on the read path.
		$config = (string) @file_get_contents( $wp_config ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable wp-config just means "we own nothing".
		if ( '' === $config ) {
			self::$our_constants_cache = $cache;
			return $cache;
		}

		$pattern = '/' . preg_quote( self::CONFIG_BEGIN, '/' ) . '(.*?)' . preg_quote( self::CONFIG_END, '/' ) . '/s';
		if ( ! preg_match( $pattern, $config, $m ) ) {
			self::$our_constants_cache = $cache;
			return $cache;
		}
		if ( preg_match_all( "/define\\(\\s*'([A-Z0-9_]+)'/", $m[1], $names ) ) {
			$cache = $names[1];
		}
		self::$our_constants_cache = $cache;
		return $cache;
	}

	/**
	 * Memoized result of our_constants(); null until the block is first read.
	 *
	 * @var string[]|null
	 */
	private static $our_constants_cache = null;

	/**
	 * Forget the memoized block scan. Every write that changes the block must
	 * call this, or the same request keeps answering from the pre-write copy.
	 */
	public static function forget_our_constants(): void {
		self::$our_constants_cache = null;
	}

	public static function write_wp_config( array $opts, array $force = array() ): bool {
		$fs = self::fs();
		$wp_config = ABSPATH . 'wp-config.php';
		if ( ! $fs || ! file_exists( $wp_config ) || ! $fs->is_writable( $wp_config ) ) {
			/*
			 * wp-config.php is read-only on several managed hosts. Fall back to
			 * a sidecar in wp-content/ -- writable wherever the drop-in itself
			 * could be installed, so the panel keeps working instead of telling
			 * the user to paste a snippet by hand. (#398)
			 */
			return self::write_sidecar( $opts );
		}


		$config = $fs->get_contents( $wp_config );
		if ( ! is_string( $config ) ) {
			return false;
		}

		$block = self::wp_config_block( $opts, $force );

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

		$written = (bool) $fs->put_contents( $wp_config, $config, FS_CHMOD_FILE );
		if ( $written ) {
			// The block just changed; a memoized scan from earlier in this
			// request would still name the previous set. (#398)
			self::forget_our_constants();

			// Only NOW is the block durable, so only now is a sidecar left
			// from an earlier read-only spell safely redundant. Deleting it
			// before the write -- is_writable() is not a promise the write
			// lands; get_contents() can fail, and put_contents() can fail on a
			// full disk or an SELinux denial -- would drop the live config and
			// leave the site on built-in defaults.
			self::delete_sidecar();
		}
		return $written;
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
		$removed = (bool) $fs->put_contents( $wp_config, $config, FS_CHMOD_FILE );
		if ( $removed ) {
			// A scan from earlier in this request would still name the
			// constants we just deleted, so origins() would report a field as
			// ours -- editable -- when a host define is now the only source
			// and the field should read as pinned.
			self::forget_our_constants();
		}
		return $removed;
	}

	/**
	 * The marker-wrapped constants block written into wp-config.php. Uses
	 * XSPEED_OC_* names (our drop-in reads these first, then falls back to
	 * WP_REDIS_* for interop).
	 */
	private static function wp_config_block( array $opts, array $force = array() ): string {
		// Fields the caller has decided we own, whatever pinned_elsewhere()
		// would otherwise say. Used when an admin saved an override: they were
		// told the host's define would stop applying, and this is the write
		// that makes that true. (#398)
		self::$force_fields = $force;
		$backend = (string) ( $opts['backend'] ?? 'redis' );
		$lines   = array( self::CONFIG_BEGIN );
		$lines[] = "define( 'XSPEED_OC_BACKEND', '" . self::esc( $backend ) . "' );";

		if ( 'memcached' === $backend ) {
			// XSPEED_OC_MC_*, not the Redis pair: one shared name meant enabling
			// Redis overwrote the Memcached host/port. (#398)
			if ( ! self::pinned_elsewhere( 'memcached_host' ) ) {
				$lines[] = "define( 'XSPEED_OC_MC_HOST', '" . self::esc( self::str( $opts, 'memcached_host', '127.0.0.1' ) ) . "' );";
			}
			if ( ! self::pinned_elsewhere( 'memcached_port' ) ) {
				$lines[] = "define( 'XSPEED_OC_MC_PORT', " . self::int( $opts, 'memcached_port', 11211 ) . ' );';
			}
		} else {
			if ( ! self::pinned_elsewhere( 'redis_host' ) ) {
				$lines[] = "define( 'XSPEED_OC_HOST', '" . self::esc( self::str( $opts, 'redis_host', '127.0.0.1' ) ) . "' );";
			}
			if ( ! self::pinned_elsewhere( 'redis_port' ) ) {
				$lines[] = "define( 'XSPEED_OC_PORT', " . self::int( $opts, 'redis_port', 6379 ) . ' );';
			}
			$user    = self::str( $opts, 'redis_user', '' );
			if ( '' !== $user && ! self::pinned_elsewhere( 'redis_user' ) ) {
				$lines[] = "define( 'XSPEED_OC_USER', '" . self::esc( $user ) . "' );";
			}
			$pass    = self::str( $opts, 'redis_password', '' );
			if ( '' !== $pass && ! self::pinned_elsewhere( 'redis_password' ) ) {
				$lines[] = "define( 'XSPEED_OC_PASSWORD', '" . self::esc( $pass ) . "' );";
			}
			if ( ! self::pinned_elsewhere( 'redis_database' ) ) {
				$lines[] = "define( 'XSPEED_OC_DATABASE', " . self::int( $opts, 'redis_database', 0 ) . ' );';
			}
			if ( ! self::pinned_elsewhere( 'connection_timeout' ) ) {
				$lines[] = "define( 'XSPEED_OC_TIMEOUT', " . self::int( $opts, 'connection_timeout', 1 ) . ' );';
			}
			if ( ! self::pinned_elsewhere( 'persistent' ) ) {
				$lines[] = "define( 'XSPEED_OC_PERSISTENT', " . ( ! empty( $opts['persistent'] ) ? 'true' : 'false' ) . ' );';
			}
		}
		// Always emit a salt (#390): a blank Cache Key Prefix derives a per-site
		// value rather than leaving keys unnamespaced, which collides when
		// several sites share one Redis/Memcached server.
		//
		// Unless a foreign define already owns it (#398). Emitting ours would
		// outrank the host's WP_REDIS_PREFIX, and on an ACL/namespaced Redis a
		// prefix that does not match the host's exactly means every write is
		// denied with NOPERM -- so a derived salt there is worse than none.
		// The host's define IS the namespace in that case, and it is already
		// non-empty, so the collision #390 closes cannot reopen.
		if ( ! self::pinned_elsewhere( 'key_prefix' ) ) {
			$lines[] = "define( 'XSPEED_OC_SALT', '" . self::esc( self::effective_salt( $opts ) ) . "' );";
		}
		$lines[] = self::CONFIG_END;
		self::$force_fields = array();
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Fields the current block write owns outright. Set for the duration of one
	 * wp_config_block() call; see the $force parameter there.
	 *
	 * @var string[]
	 */
	private static array $force_fields = array();

	/**
	 * Is this field already pinned by a constant we are not about to write?
	 *
	 * Enable() resolves settings through Settings_Manager, so on a
	 * host-provisioned site those values came FROM wp-config in the first
	 * place -- typically WP_REDIS_*. Writing them back out under our own
	 * XSPEED_OC_* names, which outrank every alias, would freeze a snapshot:
	 * when the host later rotated the password, the site would keep
	 * authenticating with our stale copy and silently drop to a
	 * non-persistent cache. It would also re-emit a credential as a second
	 * plaintext literal, which is the thing sourcing it from a constant
	 * avoids. So leave the host's define alone and emit nothing for it. (#398)
	 */
	private static function pinned_elsewhere( string $field ): bool {
		if ( in_array( $field, self::$force_fields, true ) ) {
			return false;
		}
		if ( ! class_exists( '\\XSpeed\\Settings_Manager' ) ) {
			return false;
		}
		$module = \XSpeed\Module_Registry::get( 'object-cache' );
		if ( ! $module ) {
			return false;
		}

		// An admin who deliberately overrode this field asked us to shadow the
		// host's define -- they were told so in as many words before the field
		// unlocked. Protecting it here would silently drop their value on the
		// next enable, which is the same silent-no-op failure the whole
		// pinned-field contract exists to prevent. (#398)
		if ( \XSpeed\Settings_Manager::is_overridden( 'object-cache', $field ) ) {
			return false;
		}

		// Somebody else's define, anywhere in this field's list, is protected --
		// even when our own XSPEED_OC_* copy currently outranks it. Testing only
		// the WINNING constant made an override permanent in a subtler way: on
		// revert we rewrote our copy with the host's value, our copy still
		// outranked theirs, and a later rotation on their side was shadowed
		// forever. Emitting nothing for the field lets the host's define surface
		// again and keep surfacing. (#398)
		return null !== \XSpeed\Settings_Manager::foreign_constant( 'object-cache', $field );
	}

	/**
	 * Is our marker block present in wp-config.php?
	 *
	 * Public so a caller can tell "we already manage constants here" from
	 * "this site never enabled the object cache" -- rewriting the block is
	 * right in the first case and would be an unasked-for file edit in the
	 * second. (#398)
	 */
	public static function wp_config_has_our_block(): bool {
		return self::wp_config_has_block();
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
