<?php
/**
 * Object_Cache_Takeover — switch the object cache from another plugin to xSpeed.
 *
 * WordPress gives every object-cache plugin the same file,
 * wp-content/object-cache.php. Overwriting it while the owner is still active
 * starts a fight we lose: W3 Total Cache writes its own file back on the next
 * wp-admin request whenever it is missing, and LiteSpeed Cache copies its own
 * over any file whose md5 differs, on its next settings save or update. Every
 * one of them deletes only its OWN file on deactivation. (#686)
 *
 * So the switch takes the owner out of the picture first, and only then
 * installs ours:
 *
 *   1. Identify the owner and refuse up front where we cannot switch safely
 *      (a hosting platform's drop-in, a must-use plugin, a plugin we have no
 *      safe way to turn off).
 *   2. Test the connection. Nothing changes until it passes.
 *   3. Back up the current file (a copy).
 *   4. Deactivate an object-cache-only plugin, or turn off just the object
 *      cache in LiteSpeed / W3TC through their own settings API.
 *   5. Remove a foreign file the owner left behind.
 *   6. Install ours (purge our namespace, wp-config block, drop-in).
 *   7. Verify ours is in place and the owner is still off.
 *   8. Any failure after step 3 puts everything back.
 *
 * A record of the switch lets xSpeed's Disable put the previous plugin back.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Object_Cache_Takeover {

	/** Site option recording the last switch, so Disable can restore it. */
	public const RECORD_OPTION = 'xspeed_oc_takeover';

	/** Deactivate the plugin; its own deactivation removes its file. */
	public const STRATEGY_DEACTIVATE = 'deactivate';

	/** Turn off only the plugin's object cache; its other features keep running. */
	public const STRATEGY_FEATURE_OFF = 'feature_off';

	/** Nobody active owns the file (a leftover); back it up and replace it. */
	public const STRATEGY_REPLACE = 'replace';

	/** Do not switch. The reason says where to turn it off instead. */
	public const STRATEGY_REFUSE = 'refuse';

	/**
	 * Plugins whose only job is the object cache. Deactivating them is the
	 * switch, and each one's deactivation removes its own drop-in.
	 *
	 * Only plugins whose cache namespace we can clear on their own are here.
	 * While xSpeed runs, the outgoing plugin's keys go stale (the switch
	 * itself leaves `active_plugins` without it in there), and restoring it
	 * onto them makes it read itself as inactive. Object Cache Pro, WP Redis
	 * and Docket Cache are switched off by hand instead: their own flush
	 * empties the whole Redis database, or their key layout is not known.
	 * (#687)
	 */
	private const OBJECT_CACHE_ONLY = array(
		'redis-cache/redis-cache.php' => 'Redis Object Cache',
	);

	/**
	 * Multi-feature plugins we can switch off the object cache in, leaving
	 * their page cache and everything else running.
	 */
	private const FEATURE_OFF = array(
		'litespeed-cache/litespeed-cache.php' => 'litespeed',
		'w3-total-cache/w3-total-cache.php'   => 'w3tc',
	);

	/**
	 * Plugin URIs the object-cache drop-ins carry in their own header, so a
	 * leftover can be named after its plugin even when that plugin is no
	 * longer active (the plugin catalog matches page-cache headers only).
	 */
	private const KNOWN_DROPIN_URIS = array(
		'wordpress.org/plugins/redis-cache'     => 'redis-cache/redis-cache.php',
		'objectcache.pro'                       => 'object-cache-pro/object-cache-pro.php',
		'wordpress.org/plugins/wp-redis'        => 'wp-redis/wp-redis.php',
		'github.com/pantheon-systems/wp-redis'  => 'wp-redis/wp-redis.php',
		'wordpress.org/plugins/docket-cache'    => 'docket-cache/docket-cache.php',
		'docketcache.com'                       => 'docket-cache/docket-cache.php',
	);

	/** @var array|null Per-request memo of owner(). */
	private static $owner = null;

	/** @var array|null The switch whose outgoing namespace is cleared at shutdown. */
	private static $outgoing = null;

	/**
	 * Who owns wp-content/object-cache.php, and how a switch would go.
	 *
	 * @return array{
	 *   state: string,        // none|ours|foreign
	 *   label: string,        // who it belongs to, for messages
	 *   plugin: string,       // owning plugin basename, or ''
	 *   active: bool,         // owning plugin is active
	 *   strategy: string,     // one of the STRATEGY_* constants ('' unless foreign)
	 *   reason: string,       // why a refuse refuses, '' otherwise
	 *   plan: string[]        // the steps a switch will take, for the confirmation
	 * }
	 */
	public static function owner(): array {
		if ( null !== self::$owner ) {
			return self::$owner;
		}

		$path  = self::dropin_path();
		$empty = array(
			'state'    => 'none',
			'label'    => '',
			'plugin'   => '',
			'active'   => false,
			'strategy' => '',
			'reason'   => '',
			'plan'     => array(),
		);
		if ( '' === $path || ( ! file_exists( $path ) && ! is_link( $path ) ) ) {
			return self::$owner = $empty;
		}
		if ( Object_Cache::is_our_dropin_present() ) {
			return self::$owner = array_merge( $empty, array( 'state' => 'ours' ) );
		}

		$contents = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- read-only ownership check of a local file; an unreadable one reads as unknown.
		$plugin   = self::owner_plugin( $path, $contents );
		$label    = '' !== $plugin ? self::plugin_label( $plugin ) : self::header_label( $contents );
		$active   = '' !== $plugin && self::plugin_active( $plugin );

		$owner = array_merge(
			$empty,
			array(
				'state'  => 'foreign',
				'label'  => '' !== $label ? $label : 'object-cache.php',
				'plugin' => $plugin,
				'active' => $active,
			)
		);

		list( $owner['strategy'], $owner['reason'] ) = self::strategy( $owner );
		$owner['plan'] = self::plan( $owner );

		return self::$owner = $owner;
	}

	/**
	 * Run the switch. Callers have already established that a foreign drop-in
	 * is present and that the user asked for the switch.
	 *
	 * @param array $opts Object-cache settings.
	 * @return array{ok:bool,message:string,steps:array<string,bool>,test?:array,owner:array,detect:array}
	 */
	public static function run( array $opts ): array {
		self::$owner = null;
		$owner       = self::owner();
		$steps       = array(
			'connection' => false,
			'backup'     => false,
			'owner_off'  => false,
			'leftover'   => false,
			'wp_config'  => false,
			'drop_in'    => false,
			'verified'   => false,
		);
		$fail = static function ( string $message, array $steps, array $extra = array() ) use ( $owner ): array {
			return array_merge(
				array(
					'ok'      => false,
					'message' => $message,
					'steps'   => $steps,
					'owner'   => $owner,
					'detect'  => Object_Cache::detect( true ),
				),
				$extra
			);
		};

		if ( 'foreign' !== $owner['state'] ) {
			return $fail( __( 'There is no other object cache to switch from.', 'xspeed' ), $steps );
		}
		if ( self::STRATEGY_REFUSE === $owner['strategy'] ) {
			return $fail( $owner['reason'], $steps );
		}
		if ( ! self::can_manage( $owner ) ) {
			return $fail(
				/* translators: %s: plugin name. */
				sprintf( __( 'You do not have permission to turn off %s.', 'xspeed' ), $owner['label'] ),
				$steps
			);
		}

		// 1. Nothing changes until the cache server answers.
		$test = Object_Cache::test_connection( $opts );
		if ( empty( $test['ok'] ) ) {
			/* translators: %s: connection error. */
			return $fail( sprintf( __( 'Could not switch: %s', 'xspeed' ), (string) $test['message'] ), $steps, array( 'test' => $test ) );
		}
		$steps['connection'] = true;

		// 2. A copy, so the original stays in place until the owner is off.
		$backup = self::backup();
		if ( '' === $backup ) {
			return $fail( __( 'Could not back up the current object-cache.php, so nothing was changed.', 'xspeed' ), $steps, array( 'test' => $test ) );
		}
		$steps['backup'] = true;

		$record = array(
			'plugin'   => $owner['plugin'],
			'label'    => $owner['label'],
			'strategy' => $owner['strategy'],
			'network'  => self::network_active( $owner['plugin'] ),
			'backup'   => $backup,
			'at'       => time(),
		);

		// 3. The owner goes first, so it cannot put its file back over ours.
		if ( ! self::owner_off( $record ) ) {
			$problem = self::rollback( $record, $steps );
			return $fail(
				'' === $problem
					/* translators: %s: plugin name. */
					? sprintf( __( 'Could not turn off %s, so nothing was changed.', 'xspeed' ), $owner['label'] )
					/* translators: %s: plugin name. */
					: sprintf( __( 'Could not turn off %s.', 'xspeed' ), $owner['label'] ) . ' ' . self::put_back_message( $record, $problem ),
				$steps,
				array( 'test' => $test )
			);
		}
		$steps['owner_off'] = true;

		// 4. Owners that do not clean up (a symlink, a plain drop-in) leave
		// their file; we hold a backup of it.
		if ( ! self::remove_foreign() ) {
			$problem = self::rollback( $record, $steps );
			return $fail(
				'' === $problem
					? __( 'Could not remove the old object-cache.php, so the switch was undone.', 'xspeed' )
					: __( 'Could not remove the old object-cache.php.', 'xspeed' ) . ' ' . self::put_back_message( $record, $problem ),
				$steps,
				array( 'test' => $test )
			);
		}
		$steps['leftover'] = true;

		// 5. Ours, through the same path a plain enable takes.
		$installed          = Object_Cache::install( $opts, $test );
		$steps['wp_config'] = ! empty( $installed['steps']['wp_config'] );
		$steps['drop_in']   = ! empty( $installed['steps']['drop_in'] );

		// 6. Ours is on disk and the owner is still off.
		$steps['verified'] = ! empty( $installed['ok'] )
			&& Object_Cache::is_our_dropin_present()
			&& self::owner_is_off( $record );

		if ( ! $steps['verified'] ) {
			$problem = self::rollback( $record, $steps );
			$why     = empty( $installed['ok'] ) ? ' ' . (string) $installed['message'] : '';
			return $fail(
				'' === $problem
					? __( 'xSpeed could not take over the object cache, so the previous setup was put back.', 'xspeed' ) . $why
					: __( 'xSpeed could not take over the object cache.', 'xspeed' ) . $why . ' ' . self::put_back_message( $record, $problem ),
				$steps,
				array( 'test' => $test )
			);
		}

		// A leftover is not offered back: its plugin is gone, and the file
		// may load code that went with it. The backup stays in uploads.
		if ( self::STRATEGY_REPLACE === $record['strategy'] ) {
			self::forget();
		} else {
			update_site_option( self::RECORD_OPTION, $record );
		}
		self::$owner = null;

		/*
		 * The previous plugin's object cache is still the one loaded in this
		 * request, and in any request already running, so options written
		 * from here on (`active_plugins` without it, first of all) land in
		 * ITS namespace. Clear that namespace once this request is done --
		 * scoped to its own keys, never its wp_cache_flush(), which empties
		 * the whole database for LiteSpeed and Redis Object Cache. Requests
		 * still in flight can write after this, so restore and Disable clear
		 * it again before the plugin is back; that one is authoritative.
		 */
		if ( self::STRATEGY_REPLACE !== $record['strategy'] ) {
			self::$outgoing = $record;
			add_action( 'shutdown', array( __CLASS__, 'clean_outgoing_namespace' ), PHP_INT_MAX );
		}

		return array(
			'ok'      => true,
			'message' => self::STRATEGY_REPLACE === $record['strategy']
				? sprintf(
					/* translators: %s: plugin name or file name. */
					__( 'Replaced the object-cache.php left by %s (a copy is in uploads/xspeed-backups/). xSpeed now handles object caching.', 'xspeed' ),
					$owner['label']
				)
				: sprintf(
					/* translators: %s: plugin name. */
					__( 'Object caching switched from %s to xSpeed. Disable can put it back.', 'xspeed' ),
					$owner['label']
				),
			'steps'   => $steps,
			'test'    => $test,
			'owner'   => $owner,
			'detect'  => Object_Cache::detect( true ),
		);
	}

	/**
	 * Put the plugin xSpeed switched from back in charge. Runs after
	 * Object_Cache::disable() has removed our drop-in.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function restore(): array {
		$record = self::record();
		if ( null === $record ) {
			return array(
				'ok'      => false,
				'message' => __( 'There is no previous object cache to restore.', 'xspeed' ),
			);
		}

		$problem = self::put_back( $record );
		delete_site_option( self::RECORD_OPTION );
		self::$owner = null;

		return array(
			'ok'      => '' === $problem,
			'message' => '' === $problem
				/* translators: %s: plugin name. */
				? sprintf( __( '%s is handling object caching again.', 'xspeed' ), $record['label'] )
				: self::put_back_message( $record, $problem ),
		);
	}

	/**
	 * The recorded switch, or null.
	 *
	 * @return array{plugin:string,label:string,strategy:string,network:bool,backup:string,at:int}|null
	 */
	public static function record(): ?array {
		$record = get_site_option( self::RECORD_OPTION, null );
		if ( ! is_array( $record ) || empty( $record['strategy'] ) || empty( $record['label'] ) ) {
			return null;
		}
		return array(
			'plugin'   => (string) ( $record['plugin'] ?? '' ),
			'label'    => (string) $record['label'],
			'strategy' => (string) $record['strategy'],
			'network'  => ! empty( $record['network'] ),
			'backup'   => (string) ( $record['backup'] ?? '' ),
			'at'       => (int) ( $record['at'] ?? 0 ),
		);
	}

	/**
	 * Why enable() refused, worded for the owner it found. A leftover is not
	 * "handling" anything and there is nothing to put back later. (#687)
	 *
	 * @param array $owner owner() record.
	 * @return string
	 */
	public static function refusal_message( array $owner ): string {
		if ( self::STRATEGY_REFUSE === $owner['strategy'] ) {
			return $owner['reason'];
		}
		if ( self::STRATEGY_REPLACE === $owner['strategy'] ) {
			return sprintf(
				/* translators: %s: plugin name or file name. */
				__( 'wp-content/object-cache.php was left by %s, which is not active. Switch to xSpeed to replace it (a copy is kept in uploads/xspeed-backups/).', 'xspeed' ),
				$owner['label']
			);
		}
		return sprintf(
			/* translators: %s: plugin name. */
			__( '%s handles object caching on this site. Switch to xSpeed to take it over; Disable can put it back later.', 'xspeed' ),
			$owner['label']
		);
	}

	/** Shutdown after a switch: clear the outgoing plugin's own keys. */
	public static function clean_outgoing_namespace(): void {
		if ( null !== self::$outgoing ) {
			self::clean_namespace( self::$outgoing );
			self::$outgoing = null;
		}
	}

	/** Forget the recorded switch without acting on it. */
	public static function forget(): void {
		delete_site_option( self::RECORD_OPTION );
	}

	/**
	 * xSpeed was disabled without putting the previous plugin back. Clear its
	 * namespace anyway, so turning it on by hand later does not start from
	 * the snapshot the switch left there, then forget the switch.
	 */
	public static function discard(): void {
		$record = self::record();
		if ( null !== $record ) {
			self::clean_namespace( $record );
		}
		self::forget();
	}

	/** Drop the per-request memo (tests, and after the file changes). */
	public static function reset(): void {
		self::$owner = null;
	}

	// ─────────────────────────── Ownership ───────────────────────────

	/**
	 * Basename of the plugin that owns the drop-in, or ''.
	 *
	 * Evidence in order of strength: a symlink into a plugin's folder, a
	 * byte-identical copy of a template an active plugin ships, the drop-in's
	 * Plugin URI matching an active plugin's, then the catalog's tokens.
	 *
	 * @param string $path     Drop-in path.
	 * @param string $contents Drop-in contents.
	 * @return string
	 */
	private static function owner_plugin( string $path, string $contents ): string {
		$plugins_dir = defined( 'WP_PLUGIN_DIR' ) ? rtrim( str_replace( '\\', '/', (string) WP_PLUGIN_DIR ), '/' ) : '';

		if ( is_link( $path ) && '' !== $plugins_dir ) {
			$target = realpath( $path );
			$target = false !== $target ? str_replace( '\\', '/', $target ) : '';
			if ( '' !== $target && 0 === strpos( $target, $plugins_dir . '/' ) ) {
				$folder = strtok( substr( $target, strlen( $plugins_dir . '/' ) ), '/' );
				$match  = self::plugin_in_folder( (string) $folder );
				if ( '' !== $match ) {
					return $match;
				}
			}
		}

		$uri  = self::header_value( $contents, 'Plugin URI' );
		$hash = '' !== $contents ? md5( $contents ) : '';
		foreach ( self::active_plugins() as $plugin ) {
			$folder = dirname( $plugin );
			if ( '.' === $folder || '' === $plugins_dir ) {
				continue;
			}
			foreach ( self::templates( $plugins_dir . '/' . $folder ) as $template ) {
				if ( '' !== $hash && md5_file( $template ) === $hash ) {
					return $plugin;
				}
			}
			if ( '' !== $uri ) {
				$main = self::header_value( (string) @file_get_contents( $plugins_dir . '/' . $plugin, false, null, 0, 8192 ), 'Plugin URI' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- reading a local plugin header.
				if ( '' !== $main && rtrim( $main, '/' ) === rtrim( $uri, '/' ) ) {
					return $plugin;
				}
			}
		}

		if ( '' !== $uri ) {
			foreach ( self::KNOWN_DROPIN_URIS as $needle => $plugin ) {
				if ( false !== stripos( $uri, $needle ) ) {
					return $plugin;
				}
			}
		}

		if ( class_exists( __NAMESPACE__ . '\\Cache_Plugin_Catalog' ) ) {
			$hit = Cache_Plugin_Catalog::identify_object_dropin( $contents );
			if ( null !== $hit && 'xspeed/xspeed.php' !== $hit ) {
				return $hit;
			}
		}
		return '';
	}

	/**
	 * Files named like an object-cache template inside a plugin folder,
	 * three levels deep at most. Only runs while a foreign drop-in exists.
	 *
	 * @param string $dir Plugin folder.
	 * @return string[]
	 */
	private static function templates( string $dir ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$found = array();
		foreach ( array( '/', '/*/', '/*/*/' ) as $depth ) {
			foreach ( (array) glob( $dir . $depth . '*object-cache*.php' ) as $file ) {
				if ( is_string( $file ) && is_file( $file ) ) {
					$found[] = $file;
				}
			}
		}
		return $found;
	}

	/**
	 * Why a switch must not happen, and which way it would go otherwise.
	 *
	 * @param array $owner Partial owner record.
	 * @return array{0:string,1:string} Strategy and reason.
	 */
	private static function strategy( array $owner ): array {
		$platform = self::platform();
		if ( '' !== $platform ) {
			return array(
				self::STRATEGY_REFUSE,
				/* translators: %s: hosting platform name. */
				sprintf( __( '%s manages the object cache on this site and would put its own back. Turn it off in your hosting dashboard if you want xSpeed to handle it.', 'xspeed' ), $platform ),
			);
		}

		$plugin = $owner['plugin'];
		if ( '' !== $plugin && $owner['active'] ) {
			if ( isset( self::OBJECT_CACHE_ONLY[ $plugin ] ) ) {
				return array( self::STRATEGY_DEACTIVATE, '' );
			}
			if ( isset( self::FEATURE_OFF[ $plugin ] ) && self::adapter_available( self::FEATURE_OFF[ $plugin ] ) ) {
				return array( self::STRATEGY_FEATURE_OFF, '' );
			}
			return array(
				self::STRATEGY_REFUSE,
				/* translators: %s: plugin name. */
				sprintf( __( 'Turn off the object cache in %s first, then enable it here.', 'xspeed' ), $owner['label'] ),
			);
		}

		if ( '' === $plugin ) {
			$mu = self::mu_plugin_owner();
			if ( '' !== $mu ) {
				return array(
					self::STRATEGY_REFUSE,
					/* translators: %s: must-use plugin file name. */
					sprintf( __( 'The must-use plugin %s manages object-cache.php and would put it back. Ask your host before switching.', 'xspeed' ), $mu ),
				);
			}
		}

		// Owned by nobody active: a file left behind by a plugin that is gone
		// or switched off.
		return array( self::STRATEGY_REPLACE, '' );
	}

	/**
	 * The steps a switch will take, worded for the confirmation.
	 *
	 * @param array $owner Owner record.
	 * @return string[]
	 */
	private static function plan( array $owner ): array {
		if ( self::STRATEGY_REFUSE === $owner['strategy'] ) {
			return array();
		}
		$plan = array(
			__( 'Test the connection to your cache server. Nothing changes if it fails.', 'xspeed' ),
			__( 'Back up the current object-cache.php to wp-content/uploads/xspeed-backups/.', 'xspeed' ),
		);
		if ( self::STRATEGY_DEACTIVATE === $owner['strategy'] ) {
			/* translators: %s: plugin name. */
			$plan[] = sprintf( __( 'Deactivate %s. It removes its own object-cache.php as it goes.', 'xspeed' ), $owner['label'] );
		} elseif ( self::STRATEGY_FEATURE_OFF === $owner['strategy'] ) {
			/* translators: %s: plugin name. */
			$plan[] = sprintf( __( 'Turn off Object Cache in %s. Its other features keep running.', 'xspeed' ), $owner['label'] );
		} else {
			/* translators: %s: plugin name or file name. */
			$plan[] = sprintf( __( 'Remove the object-cache.php left by %s, which is not active.', 'xspeed' ), $owner['label'] );
		}
		$plan[] = __( "Install xSpeed's object cache and check it is in place.", 'xspeed' );
		$plan[] = __( 'If any step fails, put everything back as it was.', 'xspeed' );
		return $plan;
	}

	/**
	 * A hosting platform that ships its own object cache, or ''.
	 *
	 * @return string
	 */
	private static function platform(): string {
		if ( defined( 'WPE_APIKEY' ) || class_exists( 'WpeCommon' ) ) {
			return 'WP Engine';
		}
		if ( ( defined( 'IS_ATOMIC' ) && constant( 'IS_ATOMIC' ) ) || defined( 'WPCOMSH_VERSION' ) ) {
			return 'WordPress.com';
		}
		if ( defined( 'IS_PRESSABLE' ) && constant( 'IS_PRESSABLE' ) ) {
			return 'Pressable';
		}
		if ( defined( 'PANTHEON_ENVIRONMENT' ) || false !== getenv( 'PANTHEON_ENVIRONMENT' ) ) {
			return 'Pantheon';
		}
		return '';
	}

	/**
	 * A must-use plugin that mentions object-cache.php, or ''. Hosts install
	 * their object cache that way; deactivating one is not possible.
	 *
	 * @return string
	 */
	private static function mu_plugin_owner(): string {
		$dir = defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : '';
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return '';
		}
		foreach ( (array) glob( $dir . '/*.php' ) as $file ) {
			if ( ! is_string( $file ) || filesize( $file ) > 512000 ) {
				continue;
			}
			$code = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- reading a local mu-plugin.
			if ( false !== strpos( $code, 'object-cache.php' ) ) {
				return basename( $file );
			}
		}
		return '';
	}

	// ───────────────────────── Owner on / off ─────────────────────────

	/**
	 * Take the owner out of the picture.
	 *
	 * @param array $record Switch record.
	 * @return bool
	 */
	private static function owner_off( array $record ): bool {
		switch ( $record['strategy'] ) {
			case self::STRATEGY_DEACTIVATE:
				self::load_plugin_api();
				// Silent is false on purpose: the plugin's deactivation hook is
				// what removes its drop-in (and Redis Object Cache flushes).
				deactivate_plugins( $record['plugin'], false, $record['network'] );
				return ! self::plugin_active( $record['plugin'] );
			case self::STRATEGY_FEATURE_OFF:
				return self::adapter( self::FEATURE_OFF[ $record['plugin'] ] ?? '', false );
			case self::STRATEGY_REPLACE:
				return true;
		}
		return false;
	}

	/**
	 * Whether the owner is still out of the picture.
	 *
	 * @param array $record Switch record.
	 * @return bool
	 */
	private static function owner_is_off( array $record ): bool {
		switch ( $record['strategy'] ) {
			case self::STRATEGY_DEACTIVATE:
				return ! self::plugin_active( $record['plugin'] );
			case self::STRATEGY_FEATURE_OFF:
				return ! self::adapter_enabled( self::FEATURE_OFF[ $record['plugin'] ] ?? '' );
		}
		return true;
	}

	/**
	 * Put the owner and its file back. Used by rollback and by restore.
	 *
	 * Order matters. Ours goes first, so a plugin that writes its drop-in on
	 * activation finds the slot empty. Then the owner's namespace is cleared,
	 * while nothing can be writing to it: it holds a snapshot from the switch
	 * (`active_plugins` without it, from requests that were already running),
	 * and a plugin restored onto that reads itself as inactive. Then the
	 * owner, and its file only once the owner is back: a drop-in loads its
	 * plugin's code, so putting it back for a plugin deleted in the meantime
	 * would fatal every request.
	 *
	 * @param array $record Switch record.
	 * @return string '' when everything is back, otherwise what is not:
	 *                'ours' (our drop-in would not go), 'owner' (the plugin or
	 *                its object cache would not come back), 'file' (its
	 *                drop-in could not be put back).
	 */
	private static function put_back( array $record ): string {
		if ( Object_Cache::is_our_dropin_present() && ! Object_Cache::remove_dropin() ) {
			return 'ours';
		}

		self::clean_namespace( $record );

		$back = false;
		switch ( $record['strategy'] ) {
			case self::STRATEGY_DEACTIVATE:
				self::load_plugin_api();
				if ( '' !== $record['plugin'] && ! self::plugin_active( $record['plugin'] ) ) {
					// Silent: this restores the state of moments (or one
					// switch) ago, not a fresh install, so the plugin's
					// first-run side effects (redirects, notices) do not apply.
					$result = activate_plugin( $record['plugin'], '', $record['network'], true );
					$back   = ! is_wp_error( $result ) && self::plugin_active( $record['plugin'] );
				} else {
					$back = '' !== $record['plugin'];
				}
				break;
			case self::STRATEGY_FEATURE_OFF:
				/*
				 * The file goes back BEFORE the setting. LiteSpeed's
				 * update_file() copies its drop-in when the file is missing or
				 * differs, and every copy reconnects with flushDb(), which
				 * empties the whole database. Finding its own file already in
				 * place, it skips both. The plugin stayed active throughout, so
				 * its code is there for the file to load.
				 */
				$copied = false;
				if ( self::plugin_active( $record['plugin'] ) && ! file_exists( self::dropin_path() ) ) {
					$copied = self::copy_backup( $record );
				}
				$back = self::adapter( self::FEATURE_OFF[ $record['plugin'] ] ?? '', true );
				if ( ! $back && $copied ) {
					wp_delete_file( self::dropin_path() );
				}
				// W3TC versions its keys; its own flush retires the stale ones
				// and needs its object cache on to reach the engine.
				if ( $back && 'w3tc' === ( self::FEATURE_OFF[ $record['plugin'] ] ?? '' ) && function_exists( 'w3tc_objectcache_flush' ) ) {
					try {
						w3tc_objectcache_flush();
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				break;
			case self::STRATEGY_REPLACE:
				// Only a rollback gets here: the switch failed moments ago, so
				// the file it removed is still the right one.
				$back = true;
				break;
		}
		self::$owner = null;
		if ( ! $back ) {
			return 'owner';
		}

		$path = self::dropin_path();
		if ( ! file_exists( $path ) && ! self::copy_backup( $record ) ) {
			return 'file';
		}
		clearstatcache( true, $path );
		// LiteSpeed writes its own drop-in when its object cache comes back
		// on; the others need the copy. Either way it has to be there now.
		return file_exists( $path ) ? '' : 'file';
	}

	/**
	 * Copy the switch's backup into wp-content/object-cache.php.
	 *
	 * @param array $record Switch record.
	 * @return bool
	 */
	private static function copy_backup( array $record ): bool {
		if ( '' === $record['backup'] || ! is_readable( $record['backup'] ) ) {
			return false;
		}
		$fs = Object_Cache::filesystem();
		$ok = $fs && $fs->copy( $record['backup'], self::dropin_path(), true, FS_CHMOD_FILE );
		clearstatcache( true, self::dropin_path() );
		Object_Cache::invalidate_compiled( self::dropin_path() );
		return $ok && file_exists( self::dropin_path() );
	}

	// ─────────────────────── Outgoing namespace ───────────────────────

	/**
	 * Delete the outgoing plugin's cached values, and only those.
	 *
	 * Never through its wp_cache_flush(): Redis Object Cache and LiteSpeed
	 * flush with FLUSHDB, which wipes every other site and app sharing the
	 * database. Each known owner's key layout is matched instead. W3TC is
	 * handled in put_back(), where its own versioned flush is scoped.
	 *
	 * @param array $record Switch record.
	 * @return int Keys deleted, or -1 when there was nothing we could clear.
	 */
	public static function clean_namespace( array $record ): int {
		switch ( $record['plugin'] ) {
			case 'redis-cache/redis-cache.php':
				return self::redis_delete( self::redis_object_cache_server(), self::redis_object_cache_patterns() );
			case 'litespeed-cache/litespeed-cache.php':
				$server = self::litespeed_server();
				return null === $server ? -1 : self::redis_delete( $server, array( self::glob_escape( self::litespeed_prefix() ) . '*' ) );
		}
		return -1;
	}

	/**
	 * Redis Object Cache's key patterns for this site.
	 *
	 * Its keys are `{salt}{prefix}:{group}:{key}` (fast_build_key()). The
	 * salt is WP_REDIS_PREFIX, else the env var, else WP_CACHE_KEY_SALT.
	 * The prefix is the table prefix on a single site, and the blog id (or
	 * empty for global groups) on multisite.
	 *
	 * @return string[]
	 */
	public static function redis_object_cache_patterns(): array {
		$salt = '';
		if ( defined( 'WP_REDIS_PREFIX' ) ) {
			$salt = (string) constant( 'WP_REDIS_PREFIX' );
		} elseif ( false !== getenv( 'WP_REDIS_PREFIX' ) ) {
			$salt = (string) getenv( 'WP_REDIS_PREFIX' );
		} elseif ( defined( 'WP_CACHE_KEY_SALT' ) ) {
			$salt = (string) constant( 'WP_CACHE_KEY_SALT' );
		}
		$salt = self::glob_escape( trim( $salt ) );

		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
			// With a salt, everything under it is this network's.
			if ( '' !== $salt ) {
				return array( $salt . '*' );
			}
			$patterns = array( ':*' );
			foreach ( (array) get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $id ) {
				$patterns[] = (int) $id . ':*';
			}
			return $patterns;
		}

		global $table_prefix;
		$prefix = trim( (string) $table_prefix, '_-:$' );
		return array( $salt . self::glob_escape( $prefix ) . ':*' );
	}

	/**
	 * The Redis server Redis Object Cache uses, from its own constants.
	 *
	 * @return array{host:string,port:int,user:string,password:string,database:int,timeout:float}
	 */
	private static function redis_object_cache_server(): array {
		$scheme = defined( 'WP_REDIS_SCHEME' ) ? (string) constant( 'WP_REDIS_SCHEME' ) : 'tcp';
		$host   = defined( 'WP_REDIS_HOST' ) ? (string) constant( 'WP_REDIS_HOST' ) : '127.0.0.1';
		if ( 0 === strcasecmp( $scheme, 'unix' ) && defined( 'WP_REDIS_PATH' ) ) {
			$host = (string) constant( 'WP_REDIS_PATH' );
		}
		$password = defined( 'WP_REDIS_PASSWORD' ) ? constant( 'WP_REDIS_PASSWORD' ) : '';
		$user     = '';
		if ( is_array( $password ) ) {
			$parts    = array_values( $password );
			$user     = count( $parts ) > 1 ? (string) $parts[0] : '';
			$password = (string) ( count( $parts ) > 1 ? $parts[1] : ( $parts[0] ?? '' ) );
		}
		if ( defined( 'WP_REDIS_USERNAME' ) ) {
			$user = (string) constant( 'WP_REDIS_USERNAME' );
		}
		return array(
			'host'     => $host,
			'port'     => defined( 'WP_REDIS_PORT' ) ? (int) constant( 'WP_REDIS_PORT' ) : 6379,
			'user'     => $user,
			'password' => (string) $password,
			'database' => defined( 'WP_REDIS_DATABASE' ) ? (int) constant( 'WP_REDIS_DATABASE' ) : 0,
			'timeout'  => 1.0,
		);
	}

	/**
	 * LiteSpeed's key prefix: LSOC_PREFIX, which it derives from the path of
	 * its own object-cache class unless wp-config sets it.
	 *
	 * @return string
	 */
	public static function litespeed_prefix(): string {
		if ( defined( 'LSOC_PREFIX' ) ) {
			return (string) constant( 'LSOC_PREFIX' );
		}
		$file = ( defined( 'WP_PLUGIN_DIR' ) ? (string) WP_PLUGIN_DIR : '' ) . '/litespeed-cache/src/object-cache-wp.cls.php';
		$real = realpath( $file );
		return substr( md5( false !== $real ? $real : $file ), -5 );
	}

	/**
	 * The Redis server LiteSpeed's object cache is set to, or null when it
	 * uses Memcached (no key enumeration) or its settings cannot be read.
	 *
	 * @return array|null
	 */
	private static function litespeed_server(): ?array {
		if ( ! has_filter( 'litespeed_conf' ) ) {
			return null;
		}
		$conf = static function ( string $key ) {
			return apply_filters( 'litespeed_conf', $key );
		};
		if ( 1 !== (int) $conf( 'object-kind' ) ) {
			return null;
		}
		return array(
			'host'     => (string) $conf( 'object-host' ),
			'port'     => (int) $conf( 'object-port' ),
			'user'     => (string) $conf( 'object-user' ),
			'password' => (string) $conf( 'object-pswd' ),
			'database' => (int) $conf( 'object-db_id' ),
			'timeout'  => 1.0,
		);
	}

	/**
	 * SCAN-delete keys matching the patterns on one Redis server. phpredis
	 * when loaded (it reaches a unix socket as a bare path with port 0),
	 * otherwise our own client.
	 *
	 * @param array    $server   host, port, user, password, database, timeout.
	 * @param string[] $patterns SCAN MATCH patterns, already escaped.
	 * @return int Keys deleted, or -1 on a connection failure.
	 */
	private static function redis_delete( array $server, array $patterns ): int {
		$host = (string) $server['host'];
		if ( '' === $host || array() === $patterns ) {
			return -1;
		}
		$socket = '/' === substr( $host, 0, 1 ) || 0 === stripos( $host, 'unix:' );
		if ( $socket && 0 === stripos( $host, 'unix:' ) ) {
			$host = '/' . ltrim( substr( $host, 5 ), '/' );
		}
		$deleted = 0;

		try {
			if ( class_exists( '\\Redis' ) ) {
				$redis = new \Redis();
				if ( ! @$redis->connect( $host, $socket ? 0 : (int) $server['port'], (float) $server['timeout'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a dead server is a -1, not a warning on the page.
					return -1;
				}
				if ( '' !== $server['user'] ) {
					@$redis->auth( array( 'user' => $server['user'], 'pass' => $server['password'] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same.
				} elseif ( '' !== $server['password'] ) {
					@$redis->auth( $server['password'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same.
				}
				if ( $server['database'] > 0 ) {
					$redis->select( (int) $server['database'] );
				}
				$redis->setOption( \Redis::OPT_SCAN, \Redis::SCAN_RETRY );
				foreach ( $patterns as $pattern ) {
					$it = null;
					do {
						$keys = $redis->scan( $it, $pattern, 500 );
						if ( is_array( $keys ) && array() !== $keys ) {
							$deleted += (int) $redis->del( $keys );
						}
					} while ( $it > 0 );
				}
				$redis->close();
				return $deleted;
			}

			$client = new Redis_Client( $host, (int) $server['port'], (float) $server['timeout'], false );
			if ( ! $client->connect() ) {
				return -1;
			}
			if ( '' !== $server['password'] || '' !== $server['user'] ) {
				$client->auth( (string) $server['password'], (string) $server['user'] );
			}
			if ( $server['database'] > 0 ) {
				$client->select( (int) $server['database'] );
			}
			foreach ( $patterns as $pattern ) {
				$deleted += max( 0, $client->delete_by_pattern( $pattern ) );
			}
			$client->close();
			return $deleted;
		} catch ( \Throwable $e ) {
			return -1;
		}
	}

	/**
	 * Escape Redis glob metacharacters in a literal key segment.
	 *
	 * @param string $literal Literal text.
	 * @return string
	 */
	private static function glob_escape( string $literal ): string {
		return str_replace(
			array( '\\', '*', '?', '[', ']' ),
			array( '\\\\', '\\*', '\\?', '\\[', '\\]' ),
			$literal
		);
	}

	/**
	 * Undo a switch that failed part way.
	 *
	 * @param array $record Switch record.
	 * @param array $steps  Steps reached.
	 * @return string '' when everything is back, else put_back()'s problem.
	 */
	private static function rollback( array $record, array $steps ): string {
		if ( ! empty( $steps['wp_config'] ) || ! empty( $steps['drop_in'] ) ) {
			Object_Cache::remove_wp_config();
		}
		return self::put_back( $record );
	}

	/**
	 * What a failed put-back left behind, worded for the user. A rollback
	 * or restore must never claim the previous setup is back when it is not.
	 *
	 * @param array  $record  Switch record.
	 * @param string $problem One of the put_back() problem codes.
	 * @return string
	 */
	private static function put_back_message( array $record, string $problem ): string {
		if ( 'owner' === $problem ) {
			return sprintf(
				/* translators: %s: plugin name. */
				__( '%s could not be turned back on (is it still installed?), so its object-cache.php was not put back. WordPress is using its built-in cache.', 'xspeed' ),
				$record['label']
			);
		}
		if ( 'file' === $problem ) {
			return sprintf(
				/* translators: %s: plugin name. */
				__( '%s is on again, but its object-cache.php could not be put back (is wp-content writable?), so the site has no object cache. Turn its object cache on again from its settings.', 'xspeed' ),
				$record['label']
			);
		}
		return __( "xSpeed's object-cache.php could not be removed, so the previous plugin was not put back. Check that wp-content is writable.", 'xspeed' );
	}

	// ─────────────────────── LiteSpeed / W3TC ───────────────────────

	/**
	 * Whether the plugin's own settings API is there to call.
	 *
	 * @param string $adapter litespeed|w3tc.
	 * @return bool
	 */
	private static function adapter_available( string $adapter ): bool {
		if ( 'litespeed' === $adapter ) {
			return class_exists( '\\LiteSpeed\\Conf' ) && method_exists( '\\LiteSpeed\\Conf', 'cls' ) && method_exists( '\\LiteSpeed\\Conf', 'update_confs' );
		}
		if ( 'w3tc' === $adapter ) {
			return class_exists( '\\W3TC\\Dispatcher' ) && method_exists( '\\W3TC\\Dispatcher', 'config' );
		}
		return false;
	}

	/**
	 * Whether the plugin's object cache is on.
	 *
	 * @param string $adapter litespeed|w3tc.
	 * @return bool
	 */
	private static function adapter_enabled( string $adapter ): bool {
		if ( ! self::adapter_available( $adapter ) ) {
			return false;
		}
		try {
			if ( 'litespeed' === $adapter ) {
				return (bool) apply_filters( 'litespeed_conf', 'object' );
			}
			return (bool) \W3TC\Dispatcher::config()->get_boolean( 'objectcache.enabled' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Switch the plugin's object cache on or off through its own settings
	 * API, which also adds or removes its drop-in the way it normally would.
	 *
	 * @param string $adapter litespeed|w3tc.
	 * @param bool   $on      Desired state.
	 * @return bool Whether the plugin now reports that state.
	 */
	private static function adapter( string $adapter, bool $on ): bool {
		if ( ! self::adapter_available( $adapter ) ) {
			return false;
		}
		try {
			if ( 'litespeed' === $adapter ) {
				\LiteSpeed\Conf::cls()->update_confs( array( 'object' => $on ) );
			} else {
				$config = \W3TC\Dispatcher::config();
				$config->set( 'objectcache.enabled', $on );
				$config->save();
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return self::adapter_enabled( $adapter ) === $on;
	}

	// ──────────────────────────── Files ────────────────────────────

	/** @return string wp-content/object-cache.php, or '' before WP is set up. */
	private static function dropin_path(): string {
		return defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/object-cache.php' : '';
	}

	/**
	 * Copy the current drop-in into uploads/xspeed-backups/. Resolves a
	 * symlink so the backup holds the code, not a dangling link.
	 *
	 * @return string Backup path, or '' on failure.
	 */
	private static function backup(): string {
		$path = self::dropin_path();
		$fs   = Object_Cache::filesystem();
		if ( ! $fs || '' === $path ) {
			return '';
		}
		$upload = wp_upload_dir( null, false );
		$dir    = ! empty( $upload['basedir'] ) ? trailingslashit( (string) $upload['basedir'] ) . 'xspeed-backups' : '';
		if ( '' === $dir || ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) ) {
			return '';
		}
		$source = is_link( $path ) ? (string) realpath( $path ) : $path;
		$target = $dir . '/object-cache.foreign-' . gmdate( 'Ymd-His' ) . '.php.bak';
		if ( '' === $source || ! $fs->copy( $source, $target, true, FS_CHMOD_FILE ) ) {
			return '';
		}
		return $target;
	}

	/**
	 * Remove a foreign drop-in the owner left in place.
	 *
	 * @return bool Whether the slot is now free of foreign files.
	 */
	private static function remove_foreign(): bool {
		$path = self::dropin_path();
		if ( '' === $path || ( ! file_exists( $path ) && ! is_link( $path ) ) ) {
			return true;
		}
		if ( Object_Cache::is_our_dropin_present() ) {
			return true;
		}
		wp_delete_file( $path );
		clearstatcache( true, $path );
		Object_Cache::invalidate_compiled( $path );
		return ! file_exists( $path ) && ! is_link( $path );
	}

	// ─────────────────────────── Plugins ───────────────────────────

	/** Make the plugin functions available outside wp-admin (REST, CLI). */
	private static function load_plugin_api(): void {
		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/** @return string[] Active plugin basenames, site and network. */
	private static function active_plugins(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $active ) ) ) );
	}

	/**
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	private static function plugin_active( string $plugin ): bool {
		return in_array( $plugin, self::active_plugins(), true );
	}

	/**
	 * @param string $plugin Plugin basename.
	 * @return bool
	 */
	private static function network_active( string $plugin ): bool {
		if ( '' === $plugin || ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return false;
		}
		return array_key_exists( $plugin, (array) get_site_option( 'active_sitewide_plugins', array() ) );
	}

	/**
	 * An active plugin living in the given folder, or ''.
	 *
	 * @param string $folder Plugin folder name.
	 * @return string
	 */
	private static function plugin_in_folder( string $folder ): string {
		foreach ( self::active_plugins() as $plugin ) {
			if ( dirname( $plugin ) === $folder ) {
				return $plugin;
			}
		}
		return '';
	}

	/**
	 * Whether the current user may turn the owner off. WP-CLI runs as the
	 * site operator and has no current user.
	 *
	 * @param array $owner Owner record.
	 * @return bool
	 */
	private static function can_manage( array $owner ): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		if ( self::STRATEGY_REPLACE === $owner['strategy'] ) {
			return current_user_can( 'manage_options' );
		}
		return self::network_active( $owner['plugin'] )
			? current_user_can( 'manage_network_plugins' )
			: current_user_can( 'activate_plugins' );
	}

	/**
	 * Display name for a plugin basename.
	 *
	 * @param string $plugin Plugin basename.
	 * @return string
	 */
	private static function plugin_label( string $plugin ): string {
		if ( isset( self::OBJECT_CACHE_ONLY[ $plugin ] ) ) {
			return self::OBJECT_CACHE_ONLY[ $plugin ];
		}
		if ( class_exists( __NAMESPACE__ . '\\Cache_Plugin_Catalog' ) ) {
			foreach ( Cache_Plugin_Catalog::all() as $file => $entry ) {
				if ( $file === $plugin && ! empty( $entry['label'] ) ) {
					return (string) $entry['label'];
				}
			}
		}
		$dir  = defined( 'WP_PLUGIN_DIR' ) ? (string) WP_PLUGIN_DIR : '';
		$name = '' !== $dir ? self::header_value( (string) @file_get_contents( $dir . '/' . $plugin, false, null, 0, 8192 ), 'Plugin Name' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- reading a local plugin header.
		return '' !== $name ? $name : $plugin;
	}

	/**
	 * Display name from the drop-in's own header.
	 *
	 * @param string $contents Drop-in contents.
	 * @return string
	 */
	private static function header_label( string $contents ): string {
		return self::header_value( $contents, 'Plugin Name' );
	}

	/**
	 * One WordPress file-header field.
	 *
	 * @param string $contents File contents (the first few KB are enough).
	 * @param string $field    Header field name.
	 * @return string
	 */
	private static function header_value( string $contents, string $field ): string {
		if ( '' === $contents ) {
			return '';
		}
		if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', substr( $contents, 0, 8192 ), $m ) ) {
			return trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) );
		}
		return '';
	}
}
