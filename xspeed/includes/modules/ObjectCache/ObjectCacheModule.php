<?php
/**
 * Object Cache module — status, settings, wp-config snippet, flush.
 *
 * Tier: Free per FEATURES.md "Object Cache" §1-11 (LiteSpeed parity).
 *
 * We don't ship our own object-cache.php drop-in in this Free release
 * (see Object_Cache class docblock for rationale). The settings here
 * are surfaced via the wp-config snippet; advanced consumers (Pro,
 * external drop-ins like Redis Object Cache) can read them too.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\ObjectCache;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;
use XSpeed\Object_Cache;

final class ObjectCacheModule extends Module {

	public const SLUG    = 'object-cache';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.1.0';

	public function ui_metadata(): array {
		return array(
			'label'        => __( 'Object Cache', 'xspeed' ),
			'icon'         => 'Server',
			'description'  => __( 'Configure a persistent object cache (Redis / Memcached) and generate a paste-ready wp-config.php snippet.', 'xspeed' ),
			'custom_panel' => 'ObjectCachePanel',
		);
	}

	/**
	 * @inheritDoc
	 *
	 * Nothing exempt. Object caching sits beside a page cache and competes for
	 * nothing, and the drop-in is only re-synced when ours is already installed
	 * (see boot()), so the flag installs nothing on its own — all true, and all
	 * a weak reason to leave a switch on that nobody asked for.
	 */
	public function conflict_safe_exempt(): array {
		return array();
	}

	public function settings_schema(): array {
		return array(
			'backend' => array(
				'type'          => 'enum',
				'default'       => 'redis',
				'options'       => array( 'redis', 'memcached' ),
				'option_labels' => array(
					'redis'     => 'Redis',
					'memcached' => 'Memcached',
				),
				'label'         => __( 'Backend', 'xspeed' ),
				'description'   => __( 'Which cache server you intend to use. Affects the generated wp-config snippet.', 'xspeed' ),
			),
			'redis_host' => array(
				'type'        => 'string',
				'default'     => '127.0.0.1',
				'constants'   => array( 'XSPEED_OC_HOST', 'WP_REDIS_HOST' ),
				'label'       => __( 'Redis Host', 'xspeed' ),
				'description' => __( 'Hostname or IP of the Redis server. Use 127.0.0.1 for a local socket on the same machine as PHP.', 'xspeed' ),
			),
			'redis_port' => array(
				'type'        => 'int',
				'default'     => 6379,
				'min'         => 1,
				'max'         => 65535,
				'constants'   => array( 'XSPEED_OC_PORT', 'WP_REDIS_PORT' ),
				'label'       => __( 'Redis Port', 'xspeed' ),
				'description' => __( 'Default Redis port is 6379.', 'xspeed' ),
			),
			'redis_user' => array(
				'type'                => 'string',
				'default'             => '',
				// WP_REDIS_PASSWORD trails the dedicated names because in its
				// array form -- how xCloud and Cloudways hand out ACL
				// credentials -- it carries the username too. It is pair-only:
				// as a plain string it is a password, never a username.
				'constants'           => array( 'XSPEED_OC_USER', 'WP_REDIS_USER', 'WP_REDIS_PASSWORD' ),
				'constants_pair_only' => array( 'WP_REDIS_PASSWORD' ),
				'constant_pair'       => 'user',
				'label'               => __( 'Redis User', 'xspeed' ),
				'description'         => __( 'Optional. Set this only if your host provisioned a dedicated Redis ACL user (Redis 6+) — e.g. some managed hosts issue a Redis User alongside the password. Leave blank to authenticate as the default user (legacy password-only Redis).', 'xspeed' ),
			),
			'redis_password' => array(
				'type'          => 'secret',
				'default'       => '',
				'constants'     => array( 'XSPEED_OC_PASSWORD', 'WP_REDIS_PASSWORD' ),
				'constant_pair' => 'password',
				'label'         => __( 'Redis Password', 'xspeed' ),
				'description'   => __( 'Leave blank if your Redis server runs without auth.', 'xspeed' ),
			),
			'redis_database' => array(
				'type'        => 'int',
				'default'     => 0,
				'min'         => 0,
				'max'         => 15,
				'constants'   => array( 'XSPEED_OC_DATABASE', 'WP_REDIS_DATABASE' ),
				'label'       => __( 'Redis Database', 'xspeed' ),
				'description' => __( 'Redis logical DB number (0-15). Use a dedicated DB per site if Redis is shared.', 'xspeed' ),
			),
			'memcached_host' => array(
				'type'        => 'string',
				'default'     => '127.0.0.1',
				// Memcached names its OWN constants. Sharing XSPEED_OC_HOST/PORT
				// with Redis meant enabling Redis rewrote the Memcached host and
				// port with Redis's, and locked them -- switching backend later
				// then failed with no way to fix it on screen. (#398)
				'constants'   => array( 'XSPEED_OC_MC_HOST', 'XSPEED_OC_HOST' ),
				// XSPEED_OC_HOST/PORT are the pre-split names, kept so an install
				// configured before the split keeps its host on upgrade. Gated on
				// the backend actually being Memcached, or a Redis site would read
				// the Redis port here. Fades out on the next save. (#398)
				'constants_when' => array(
					'XSPEED_OC_HOST' => array( 'constant' => 'XSPEED_OC_BACKEND', 'is' => 'memcached' ),
				),

				// Memcached has no constant convention the way Redis has
				// WP_REDIS_*; $memcached_servers IS the convention (W3TC, the
				// Memcached Object Cache drop-in), and hosts write it. The
				// drop-in already honoured it while the panel did not. (#398)
				'global_source' => array(
					'var'    => 'memcached_servers',
					'reader' => array( '\\XSpeed\\Object_Cache', 'first_memcached_server' ),
					'slot'   => 0,
				),
				'label'       => __( 'Memcached Host', 'xspeed' ),
				'description' => __( 'Used when Backend = Memcached.', 'xspeed' ),
			),
			'memcached_port' => array(
				'type'          => 'int',
				'default'       => 11211,
				'min'           => 1,
				'max'           => 65535,
				'constants'     => array( 'XSPEED_OC_MC_PORT', 'XSPEED_OC_PORT' ),
				// XSPEED_OC_HOST/PORT are the pre-split names, kept so an install
				// configured before the split keeps its host on upgrade. Gated on
				// the backend actually being Memcached, or a Redis site would read
				// the Redis port here. Fades out on the next save. (#398)
				'constants_when' => array(
					'XSPEED_OC_PORT' => array( 'constant' => 'XSPEED_OC_BACKEND', 'is' => 'memcached' ),
				),

				'global_source' => array(
					'var'    => 'memcached_servers',
					'reader' => array( '\\XSpeed\\Object_Cache', 'first_memcached_server' ),
					'slot'   => 1,
				),
				'label'       => __( 'Memcached Port', 'xspeed' ),
				'description' => __( 'Default Memcached port is 11211.', 'xspeed' ),
			),
			'key_prefix' => array(
				'type'        => 'string',
				'default'     => '',
				// ONE field for both backends on purpose: the drop-in has one
				// salt and applies it identically either way (full_key()), so
				// splitting it would be two controls over one value.
				//
				// Both names are honoured whichever backend is selected.
				// WP_REDIS_PREFIX reads oddly on a Memcached site, but a site
				// that has defined it has said what namespace it wants, and
				// ignoring that to keep the label tidy would be the panel
				// disagreeing with the drop-in -- the exact bug this closes.
				// The field's own description carries the explanation. (#398)
				//
				// WP_CACHE_KEY_SALT is deliberately NOT declared here (#430):
				// it is WordPress's own cache-uniqueness salt, present and
				// random on nearly every install, not a namespace declaration.
				// Listing it pinned this field on that random value and locked
				// editing -- while the override path already refused to treat
				// it as a foreign authority, so "Manage here" could never
				// unlock the field. With no salt of our own the field stays
				// blank and editable; the drop-in still honours a defined
				// WP_CACHE_KEY_SALT as its last-resort salt at runtime.
				'constants'   => array( 'XSPEED_OC_SALT', 'WP_REDIS_PREFIX' ),
				'label'       => __( 'Cache Key Prefix', 'xspeed' ),
				'description' => __( 'Unique salt for this site\'s cache keys. Leave blank and xSpeed derives one automatically for this install, so sites sharing a Redis/Memcached server never collide. On ACL/namespaced Redis (e.g. xCloud), set this to the host\'s "Redis Object Cache Key" — otherwise cache writes are denied (NOPERM) and nothing persists.', 'xspeed' ),
			),
			'connection_timeout' => array(
				'type'        => 'int',
				'default'     => 1,
				'min'         => 0,
				'max'         => 60,
				'constants'   => array( 'XSPEED_OC_TIMEOUT', 'WP_REDIS_TIMEOUT' ),
				'label'       => __( 'Connection Timeout (seconds)', 'xspeed' ),
				'unit'        => 'seconds',
				'description' => __( 'How long to wait for a connection. Keep low (1-2s) so a misconfigured cache never stalls the page.', 'xspeed' ),
			),
			'persistent' => array(
				'type'        => 'bool',
				'default'     => true,
				'constants'   => array( 'XSPEED_OC_PERSISTENT', 'WP_REDIS_PERSISTENT' ),
				'label'       => __( 'Persistent Connections', 'xspeed' ),
				'description' => __( 'Reuse the connection across PHP requests when supported. Generally a win unless the cache server complains about idle connections.', 'xspeed' ),
			),
		);
	}

	/**
	 * Encrypt the pre-1.1.0 plaintext redis_password on upgrade — it became a
	 * `secret`-typed field (encrypted at rest). Idempotent. (#115)
	 */
	public function migrations(): array {
		return array(
			'1.1.0' => static function ( array $opts ): array {
				if ( isset( $opts['redis_password'] ) && is_string( $opts['redis_password'] ) && '' !== $opts['redis_password'] ) {
					$opts['redis_password'] = \XSpeed\Settings_Manager::encrypt_for_storage( $opts['redis_password'] );
				}
				return $opts;
			},
		);
	}

	/** Counts failed drop-in sync attempts so a permanent failure stops retrying. */
	private const SYNC_ATTEMPTS_OPTION = 'xspeed_oc_sync_attempts';

	/**
	 * Verdict of the write probe run after the last save. Read by the status
	 * card so a backend that silently refuses writes cannot keep reporting a
	 * healthy cache. (#398)
	 */
	private const WRITE_PROBE_OPTION = 'xspeed_oc_write_probe';

	/**
	 * The recorded write failure, if it is still true right now.
	 *
	 * The probe is written on save, so anything that fixes the backend WITHOUT
	 * a save -- `objcache revert` rescuing a bad key prefix is the case that
	 * matters -- left the warning standing over a cache that had recovered. Any
	 * reader would then cry wolf until the next save. Re-checking before
	 * reporting keeps one stored probe honest for both readers, and clears it
	 * so the recheck happens once rather than on every status call. (#398)
	 *
	 * @return array{ok:bool,message:string,at:int}|null Null when writes are fine.
	 */
	private static function live_write_failure(): ?array {
		$probe = get_option( self::WRITE_PROBE_OPTION, array() );
		if ( ! is_array( $probe ) || ! array_key_exists( 'ok', $probe ) || ! empty( $probe['ok'] ) ) {
			return null;
		}
		if ( ! Object_Cache::is_our_dropin_present() ) {
			delete_option( self::WRITE_PROBE_OPTION );
			return null;
		}

		/*
		 * Throttle the recheck. This reader sits on rest_detect(), which the
		 * panel polls, and on every `objcache status` -- and the recheck is a
		 * real TCP connect plus a SET/GET/DEL round trip. On a backend that is
		 * DOWN rather than merely refusing writes the probe never clears, so
		 * without this every poll blocks for the full timeout and the object
		 * cache screen feels hung at exactly the moment someone is trying to
		 * fix it. A stale-by-a-minute warning is the cheaper error. (#398)
		 */
		$checked = (int) ( $probe['rechecked_at'] ?? 0 );
		if ( $checked > 0 && ( time() - $checked ) < MINUTE_IN_SECONDS ) {
			return array(
				'ok'      => false,
				'message' => (string) ( $probe['message'] ?? '' ),
				'at'      => (int) ( $probe['at'] ?? 0 ),
			);
		}

		/*
		 * Recheck against the STORED row, not the resolved settings. Within the
		 * request that saved the value, our block has been rewritten but PHP
		 * has already defined those constants and cannot redefine them -- so
		 * the resolved read still returns the PRE-save value. Rechecking that
		 * tests a configuration the site is no longer being asked to use, and a
		 * healthy answer would clear a probe that is genuinely true, putting
		 * the silent-dead-cache bug straight back. The row is what the next
		 * request's block is built from, so it is what the probe describes.
		 * (#398)
		 */
		$opts                       = \XSpeed\Settings_Manager::stored_with_defaults( self::SLUG );
		$opts['connection_timeout'] = min( 2, max( 1, (int) ( $opts['connection_timeout'] ?? 1 ) ) );
		$now                        = Object_Cache::test_connection( $opts );
		if ( ! empty( $now['ok'] ) ) {
			delete_option( self::WRITE_PROBE_OPTION );
			return null;
		}

		$still = array(
			'ok'           => false,
			'message'      => (string) ( $now['message'] ?? ( $probe['message'] ?? '' ) ),
			'at'           => (int) ( $probe['at'] ?? 0 ),
			'rechecked_at' => time(),
		);
		update_option( self::WRITE_PROBE_OPTION, $still, false );

		unset( $still['rechecked_at'] );
		return $still;
	}

	/** Give up after this many failed syncs for one plugin version. */
	private const MAX_SYNC_ATTEMPTS = 5;

	public function boot(): void {
		// A saved override is promoted into our wp-config block, then the field
		// re-locks reading OUR constant. This is what makes "edit once, lock
		// again" honest: the drop-in loads before WordPress and cannot read an
		// option row, so a value that stayed in the database would be a setting
		// the panel showed and the runtime ignored. (#398)
		/*
		 * Mirror every save into our own store, so the drop-in -- which loads
		 * before WordPress and cannot read an option row -- sees what the panel
		 * just wrote. Only OUR block is rewritten: a define the host owns is
		 * left alone by wp_config_block()'s pinned_elsewhere() check, and when
		 * wp-config.php is read-only the write lands in the sidecar instead.
		 *
		 * Without this a save updated the option row while the block kept the
		 * previous value, and the constant outranks the row -- so the panel
		 * reported success and the site went on using the old setting. (#398)
		 */
		/*
		 * Answer the backend gate from the sidecar when no constant defines it.
		 * The legacy Memcached fallback (XSPEED_OC_HOST/PORT) is gated on the
		 * backend being Memcached, and on a read-only-wp-config host that fact
		 * lives in the sidecar -- so without this the panel reported the
		 * default host while the drop-in used the real one. (#398)
		 */
		/*
		 * Answer settings reads from the sidecar. On a host where wp-config.php
		 * is read-only the sidecar IS the configuration, so a panel that read
		 * only the option row would show the stored values while the drop-in
		 * ran on the sidecar's -- the panel/runtime split this change exists to
		 * close. Constants still win; this sits between them and the row. (#398)
		 */
		add_filter(
			'xspeed_setting_external_source',
			static function ( $value, string $slug, string $key ) {
				if ( null !== $value || self::SLUG !== $slug ) {
					return $value;
				}
				$sidecar = Object_Cache::read_sidecar();
				return array_key_exists( $key, $sidecar ) ? $sidecar[ $key ] : null;
			},
			10,
			3
		);

		add_filter(
			'xspeed_constant_gate_value',
			static function ( $value, string $constant ) {
				if ( null !== $value || 'XSPEED_OC_BACKEND' !== $constant ) {
					return $value;
				}
				$sidecar = Object_Cache::read_sidecar();
				return $sidecar['backend'] ?? null;
			},
			10,
			2
		);

		add_action(
			'xspeed_settings_saved',
			static function ( string $slug, array $clean ): void {
				if ( self::SLUG !== $slug ) {
					return;
				}
				// Only when we already own a block or a sidecar. Creating one
				// on a site that never enabled the object cache would write to
				// wp-config.php for a feature that is switched off.
				/*
				 * Mirror whenever the drop-in is installed -- that, not the
				 * presence of a store, is what makes a DB-only value a setting
				 * the panel shows and the runtime ignores. Keying on the store
				 * instead could strand a site whose block write once failed:
				 * with no block and no sidecar the guard would return early
				 * forever and saves would stop being mirrored. (#398)
				 */
				if ( ! Object_Cache::is_our_dropin_present() ) {
					return;
				}
				Object_Cache::write_wp_config( $clean );

				/*
				 * Prove the backend still ACCEPTS WRITES with the settings just
				 * saved. On a namespaced/ACL Redis a key prefix outside the
				 * granted namespace is refused with NOPERM, and the drop-in
				 * swallows that -- so the panel reported success, the status
				 * card kept saying "On", and the site paid for a cache that
				 * stored nothing. Only an explicit Test connection revealed it.
				 *
				 * Recorded rather than thrown: the save itself DID land, and
				 * failing it would leave the panel and the row disagreeing. The
				 * status card reads this and says so. (#398)
				 */
				if ( Object_Cache::is_our_dropin_present() ) {
					/*
					 * Clamp the probe's timeout. It runs inside an admin POST,
					 * and connection_timeout is the user's own setting -- a
					 * save that points at a black-holed IP would otherwise
					 * block the request for as long as they typed. The probe is
					 * a check, not the connection the site runs on, so a short
					 * ceiling costs nothing.
					 */
					$probe_opts                       = $clean;
					$probe_opts['connection_timeout'] = min( 2, max( 1, (int) ( $clean['connection_timeout'] ?? 1 ) ) );
					$probe                            = Object_Cache::test_connection( $probe_opts );
					update_option(
						self::WRITE_PROBE_OPTION,
						array(
							'ok'      => ! empty( $probe['ok'] ),
							'message' => (string) ( $probe['message'] ?? '' ),
							'at'      => time(),
						),
						false
					);
				}
			},
			10,
			2
		);

		/*
		 * Ownership of a field changed hands, so our block no longer reflects
		 * what should be in it. On a revert this is the step that actually
		 * frees the field: Settings_Manager::revert() has dropped our stored
		 * value, and rewriting the block from the settings as they NOW resolve
		 * is what removes our define and lets the host's constant win again.
		 *
		 * Without this listener the hook fired into nothing, the define stayed,
		 * and "Use the host value" was a no-op in the panel while the CLI --
		 * which did the same work inline -- worked. (#398)
		 */
		add_action(
			'xspeed_setting_override_changed',
			static function ( string $slug, string $key, bool $on ): void {
				unset( $key );
				if ( self::SLUG !== $slug || ! Object_Cache::is_our_dropin_present() ) {
					return;
				}
				/*
				 * Only on a REVERT, and only when nothing else is mid-flight.
				 *
				 * update() also lifts an override as its last act, having just
				 * promoted the typed value into our block -- a rewrite here
				 * would resolve the host's constant again and erase the define
				 * that save had only just written, silently undoing the edit.
				 * A revert is the one case where erasing our define IS the
				 * point. (#398)
				 */
				if ( $on || \XSpeed\Settings_Manager::is_promoting( self::SLUG ) ) {
					return;
				}
				Object_Cache::write_wp_config( \XSpeed\Settings_Manager::get( self::SLUG ) );
			},
			10,
			3
		);

		add_action(
			'xspeed_settings_promote_to_config',
			static function ( string $slug, array $values ): void {
				if ( self::SLUG !== $slug ) {
					return;
				}
				// The block is CREATED here if absent, unlike the passive
				// rewrites elsewhere. This write is the thing the admin just
				// asked for and was warned about; refusing it because the
				// object cache is not enabled yet would make the save a silent
				// no-op -- the failure mode this whole contract exists to
				// prevent. (#398)
				// Forced: these fields are exactly the ones a foreign define
				// still pins, which is why they were overridden in the first
				// place. Without the force list pinned_elsewhere() would drop
				// them and the save would write nothing.
				Object_Cache::write_wp_config(
					array_merge( \XSpeed\Settings_Manager::get( self::SLUG ), $values ),
					array_keys( $values )
				);
			},
			10,
			2
		);


		// Keep the deployed drop-in in sync with the shipped template. It is
		// copied into wp-content/object-cache.php on enable and then never
		// touched again — so a fix shipped in a plugin update (e.g. the
		// stale-alloptions eviction on failed backend writes, issue #41)
		// would never reach existing installs. Version-gated so the file
		// comparison runs once per plugin version, not on every admin load.
		add_action(
			'admin_init',
			static function (): void {
				if ( get_option( 'xspeed_oc_dropin_synced', '' ) === XSPEED_VERSION ) {
					return;
				}
				$ok = true;
				if ( Object_Cache::is_our_dropin_present() ) {
					// Both steps run regardless of each other — they fail
					// independently (drop-in needs wp-content writable, the
					// backfill needs wp-config writable), and short-circuiting
					// would skip a backfill that could have succeeded.
					$installed = Object_Cache::install_dropin();
					$salted    = self::backfill_key_salt();
					$ok        = $installed && $salted;
				}
				// Only stamp the version when the sync actually succeeded. A
				// transient failure (wp-config momentarily unwritable, a
				// filesystem hiccup) then gets retried on a later admin load
				// rather than being recorded as migrated and left unsalted.
				//
				// Bounded, though: where the failure is permanent — a host that
				// ships a read-only wp-config — retrying forever would run
				// WP_Filesystem work on every single admin page load for no
				// gain. It is safe to stop, because the drop-in derives its own
				// salt when the constant is absent, so such an install is
				// namespaced either way; the constant is only the faster path.
				if ( $ok ) {
					update_option( 'xspeed_oc_dropin_synced', XSPEED_VERSION );
					delete_option( self::SYNC_ATTEMPTS_OPTION );
					return;
				}

				$attempts = (int) get_option( self::SYNC_ATTEMPTS_OPTION, 0 ) + 1;
				if ( $attempts >= self::MAX_SYNC_ATTEMPTS ) {
					update_option( 'xspeed_oc_dropin_synced', XSPEED_VERSION );
					delete_option( self::SYNC_ATTEMPTS_OPTION );
					return;
				}
				update_option( self::SYNC_ATTEMPTS_OPTION, $attempts );
			}
		);
	}

	/**
	 * Backfill a per-site key salt on installs enabled before the salt became
	 * mandatory.
	 *
	 * Enabling with a blank Cache Key Prefix used to write no salt constant at
	 * all, leaving every key namespaced as `:{blog}:{group}:{key}` — identical
	 * on every install. Two sites sharing one Redis/Memcached server then read
	 * each other's `blog-details` / `blog-lookup` entries, and the second site
	 * resolves to (and redirects to) the first.
	 *
	 * Rewriting wp-config re-emits the block with a derived salt.
	 *
	 * Order matters, and NOT the way it first appears. Flushing before the
	 * rewrite looks right — it would drop the old unnamespaced entries rather
	 * than stranding them — but the cache object serving this request was
	 * constructed from the OLD, salt-less config. Asking it to flush is asking
	 * an unsalted object to purge, which on Redis used to mean FLUSHDB and on
	 * Memcached means flush_all(): either one destroys every neighbouring site
	 * sharing the server. That is the exact failure this migration exists to
	 * prevent, so we never flush through the stale object.
	 *
	 * Writing the config first means the NEXT request loads a properly salted
	 * drop-in and simply starts using the new namespace. The old unnamespaced
	 * keys are orphaned rather than deleted; they expire on their own, and they
	 * are unreachable in the meantime because nothing builds those keys any
	 * more.
	 *
	 * @return bool True when the install is namespaced afterwards.
	 */
	private static function backfill_key_salt(): bool {
		$module   = new self();
		$settings = $module->get_settings();
		$written  = defined( 'XSPEED_OC_SALT' ) ? (string) constant( 'XSPEED_OC_SALT' ) : '';

		// Nothing written yet — the original backfill case (an install that
		// enabled the object cache before a salt was emitted at all).
		if ( '' === $written ) {
			return Object_Cache::write_wp_config( $settings );
		}

		// A salt IS written. Re-sync only when the user's typed Cache Key
		// Prefix disagrees with it, which is the ACL-remediation path: on a
		// namespaced host the admin types the host's "Redis Object Cache Key"
		// to stop NOPERM denials, but saving settings does not touch
		// wp-config — only enable() and this backfill do. Without this
		// comparison the drop-in kept reading the old constant while the
		// probe key used the new prefix, so Test connection reported success
		// while real writes were still denied: the same probe-vs-reality
		// divergence issue 2 set out to remove, just on a narrower path.
		//
		// Deliberately compared against the TYPED prefix, not
		// effective_salt(): effective_salt() returns the written constant
		// when the prefix is blank (so a warm cache is never orphaned by a
		// re-derivation), which would make this a no-op comparison.
		$typed = isset( $settings['key_prefix'] ) ? (string) $settings['key_prefix'] : '';
		if ( '' === $typed || $typed === $written ) {
			return true; // Correctly namespaced — leave the warm cache alone.
		}

		return Object_Cache::write_wp_config( $settings );
	}

	public function rest_routes(): array {
		$default = parent::rest_routes();
		return array_merge(
			$default,
			array(
				array(
					'path'     => '/detect',
					'methods'  => 'GET',
					'callback' => array( $this, 'rest_detect' ),
				),
				array(
					'path'     => '/flush',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_flush' ),
				),
				array(
					'path'     => '/snippet',
					'methods'  => 'GET',
					'callback' => array( $this, 'rest_snippet' ),
				),
				array(
					'path'     => '/test-connection',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_test_connection' ),
				),
				array(
					'path'     => '/enable',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_enable' ),
				),
				array(
					'path'     => '/disable',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_disable' ),
				),
			)
		);
	}

	public function rest_detect( \WP_REST_Request $request ) {
		/*
		 * Carry the write probe alongside detection. `detect()` answers "is a
		 * drop-in installed and which backend" -- it cannot see that the
		 * backend is CONNECTED but refusing writes, which is what a key prefix
		 * outside an ACL namespace does (NOPERM, swallowed by the drop-in). The
		 * probe recorded on save knows; until this it had no reader outside
		 * WP-CLI, so the panel kept saying "Redis ready" over a cache that
		 * stored nothing. (#398)
		 */
		$detect  = Object_Cache::detect();
		$failure = self::live_write_failure();
		if ( null !== $failure ) {
			$detect['write_probe'] = $failure;
		}
		return rest_ensure_response( $detect );
	}

	public function rest_flush( \WP_REST_Request $request ) {
		$ok = Object_Cache::flush();
		if ( $ok && class_exists( '\\XSpeed\\Activity_Log' ) ) {
			\XSpeed\Activity_Log::record(
				'object_cache_flushed',
				'Object cache flushed.',
				\XSpeed\Activity_Log::INFO
			);
		}
		return rest_ensure_response( array( 'ok' => $ok ) );
	}

	public function rest_snippet( \WP_REST_Request $request ) {
		return rest_ensure_response(
			array( 'snippet' => Object_Cache::render_config_snippet( $this->get_settings() ) )
		);
	}

	/**
	 * Merge any settings sent in the request body over the saved settings, so
	 * the UI can "Test connection" with unsaved values. Only known keys pass.
	 */
	private function settings_with_overrides( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		return self::merge_overrides(
			$this->get_settings(),
			is_array( $body ) ? $body : array(),
			$this->settings_schema()
		);
	}

	/**
	 * Overlay request-body values onto the stored settings for a one-off "Test
	 * connection" — but NEVER let a masked secret echoed from the panel overwrite
	 * the real stored value. The panel holds `Redi••••CRET`; without this guard,
	 * clicking Test connection authenticates Redis with the mask and a correct
	 * password reports as wrong. A genuinely new (typed) password still applies,
	 * and an explicit empty value still tests the no-auth case. Static + pure so
	 * it's unit-testable without a REST request. (QA B3)
	 *
	 * @param array<string,mixed> $settings Stored, decrypted settings.
	 * @param array<string,mixed> $body     Request overrides.
	 * @param array<string,array> $schema   The module schema (for secret detection).
	 * @return array<string,mixed>
	 */
	public static function merge_overrides( array $settings, array $body, array $schema ): array {
		foreach ( $settings as $key => $value ) {
			if ( ! array_key_exists( $key, $body ) ) {
				continue;
			}
			if ( isset( $schema[ $key ] )
				&& \XSpeed\Settings_Manager::is_secret_field( $key, $schema[ $key ] )
				&& \XSpeed\Settings_Manager::is_masked_secret( (string) $body[ $key ] ) ) {
				continue;
			}
			// A field pinned by a wp-config.php constant is not overridable. Two
			// reasons, either sufficient: "Test connection" must exercise the
			// config the drop-in actually uses, or it answers a question nobody
			// asked; and an overridable host turns this admin endpoint into a
			// request-forgery probe against arbitrary internal addresses. The
			// panel posts every field it rendered, so the pinned value arrives
			// in the body on a normal Test click too. (#398)
			// write_blocking_constant(), not effective_constant(): a value we
			// wrote ourselves is this module's own storage, so the admin may
			// still type over it. Only somebody else's define is protected.
			if ( isset( $schema[ $key ] )
				&& null !== \XSpeed\Settings_Manager::write_blocking_constant( self::SLUG, $key, $schema[ $key ] ) ) {
				continue;
			}
			$settings[ $key ] = $body[ $key ];
		}
		return $settings;
	}

	public function rest_test_connection( \WP_REST_Request $request ) {
		return rest_ensure_response( Object_Cache::test_connection( $this->settings_with_overrides( $request ) ) );
	}

	public function rest_enable( \WP_REST_Request $request ) {
		// Persist any settings sent with the enable call first, then act on them.
		$body = $request->get_json_params();
		if ( is_array( $body ) && ! empty( $body ) ) {
			\XSpeed\Settings_Manager::update( self::SLUG, $body );
		}
		$result = Object_Cache::enable( $this->get_settings() );

		if ( $result['ok'] && class_exists( '\\XSpeed\\Activity_Log' ) ) {
			\XSpeed\Activity_Log::record(
				'object_cache_enabled',
				'Object cache enabled (' . ( $result['test']['backend'] ?? '' ) . ').',
				\XSpeed\Activity_Log::INFO
			);
		}
		return rest_ensure_response( $result );
	}

	public function rest_disable( \WP_REST_Request $request ) {
		$result = Object_Cache::disable();
		if ( $result['ok'] && class_exists( '\\XSpeed\\Activity_Log' ) ) {
			\XSpeed\Activity_Log::record(
				'object_cache_disabled',
				'Object cache disabled.',
				\XSpeed\Activity_Log::INFO
			);
		}
		return rest_ensure_response( $result );
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed objcache',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Show object cache status, read or write a setting, flush, or print the wp-config snippet.',
				'ai_hint'   => 'Is a persistent object cache (Redis/Memcached) connected and working? Use for slow admin pages, high database load, or "should I add Redis" questions — it reports the backend, connection health and hit rate. `get`/`set <key> [value]` read and write individual settings; `status` also reports whether each value comes from wp-config.php or the database.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'status', 'flush', 'snippet', 'enable', 'disable', 'test', 'get', 'set', 'override', 'revert' ),
						'optional' => true,
					),
					array(
						'type'     => 'positional',
						'name'     => 'key',
						'optional' => true,
					),
					array(
						'type'     => 'positional',
						'name'     => 'value',
						'optional' => true,
					),
				),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$action = $args[0] ?? 'status';
		switch ( $action ) {
			case 'status':
				$d = Object_Cache::detect();
				\WP_CLI::log( 'drop-in installed: ' . ( $d['drop_in_installed'] ? 'yes' : 'no' ) );
				\WP_CLI::log( 'label:             ' . $d['drop_in_label'] );
				\WP_CLI::log( 'backend:           ' . $d['backend'] );
				\WP_CLI::log( 'ext object cache:  ' . ( $d['wp_cache_active'] ? 'yes' : 'no' ) );

				// A backend that connects but refuses WRITES looks identical to
				// a healthy one everywhere else -- that is the whole failure.
				$probe = self::live_write_failure();
				if ( null !== $probe ) {
					\WP_CLI::warning(
						'writes refused: ' . ( $probe['message'] ?: 'unknown error' )
						. ' — the cache is connected but storing nothing.'
					);
				}

				// Per value, where it came from. On a host-provisioned site the
				// difference between "wp-config.php" and "database" is the whole
				// question when the cache is not behaving. (#398)
				\WP_CLI::log( '' );
				\WP_CLI::log( 'settings:' );
				$settings = \XSpeed\Settings_Manager::get_public( self::SLUG );
				$origins  = \XSpeed\Settings_Manager::origins( self::SLUG );
				$schema   = $this->settings_schema();
				foreach ( $schema as $key => $spec ) {
					$origin = $origins[ $key ] ?? array(
						'source'   => 'default',
						'constant' => null,
					);
					$source = 'constant' === $origin['source']
						? 'wp-config.php: ' . $origin['constant']
						: $origin['source'];
					\WP_CLI::log(
						sprintf(
							'  %-20s %-24s [%s]',
							$key,
							self::scalar_for_display( $settings[ $key ] ?? null ),
							$source
						)
					);
				}
				return;
			case 'override':
			case 'revert':
				$key    = $args[1] ?? '';
				$schema = $this->settings_schema();
				if ( '' === $key || ! array_key_exists( $key, $schema ) ) {
					\WP_CLI::error( 'Unknown setting: ' . ( '' === $key ? '(none given)' : $key ) . '. Run `wp xspeed objcache status` for the list.' );
				}
				$on = 'override' === $action;
				if ( $on && null === \XSpeed\Settings_Manager::constant_source( $schema[ $key ] ) ) {
					\WP_CLI::error( sprintf( '"%s" is not defined in wp-config.php, so there is nothing to override.', $key ) );
				}
				/*
				 * Reverting hands a field BACK to the host, so it needs a host
				 * define to hand it back to, and it has to REMOVE our own
				 * define rather than only dropping the override entry -- ours
				 * outranks the host's, so leaving it in place meant the field
				 * kept our value and a later credential rotation was ignored
				 * for good. Both rules live in Settings_Manager::revert() so
				 * this command and the panel's button cannot drift. (#398)
				 */
				$foreign = null;
				if ( $on ) {
					\XSpeed\Settings_Manager::set_override( self::SLUG, $key, true );
				} else {
					$reverted = \XSpeed\Settings_Manager::revert( self::SLUG, $key );
					if ( is_wp_error( $reverted ) ) {
						\WP_CLI::error(
							'xspeed_nothing_to_revert' === $reverted->get_error_code()
								? sprintf(
									'"%s" is not set in wp-config.php by your host, so there is nothing to revert to. Set it to the value you want with `wp xspeed objcache set %s <value>`.',
									$key,
									$key
								)
								: $reverted->get_error_message()
						);
					}
					$foreign = $reverted;
				}

				$origin = \XSpeed\Settings_Manager::origins( self::SLUG )[ $key ] ?? array( 'source' => 'db' );
				\WP_CLI::success(
					$on
						? sprintf( '%s is now managed here. Set it with `wp xspeed objcache set %s <value>`.', $key, $key )
						: sprintf( '%s handed back to your host\'s %s.', $key, (string) $foreign )
				);
				return;
			case 'get':
				$key = $args[1] ?? '';
				if ( '' === $key || ! array_key_exists( $key, $this->settings_schema() ) ) {
					\WP_CLI::error( 'Unknown setting: ' . ( '' === $key ? '(none given)' : $key ) . '. Run `wp xspeed objcache status` for the list.' );
				}
				// get_public(), so a credential is never printed to a terminal
				// or captured in a CI log.
				$settings = \XSpeed\Settings_Manager::get_public( self::SLUG );
				\WP_CLI::log( self::scalar_for_display( $settings[ $key ] ?? null ) );
				return;
			case 'set':
				$key = $args[1] ?? '';
				if ( '' === $key || ! array_key_exists( $key, $this->settings_schema() ) ) {
					\WP_CLI::error( 'Unknown setting: ' . ( '' === $key ? '(none given)' : $key ) . '. Run `wp xspeed objcache status` for the list.' );
				}
				if ( ! array_key_exists( 2, $args ) ) {
					\WP_CLI::error( 'No value given. Usage: wp xspeed objcache set <key> <value>' );
				}

				// Fail loudly rather than writing a row that get() will never
				// read back. A silent no-op is the worst outcome here: the
				// automation reports success and nothing changed. (#398)
				$locked = \XSpeed\Settings_Manager::locked_in_input( self::SLUG, array( $key => $args[2] ) );
				if ( isset( $locked[ $key ] ) ) {
					\WP_CLI::error(
						sprintf(
							'"%1$s" is defined in wp-config.php as %2$s, so it cannot be set here. Edit that constant, or run `wp xspeed objcache override %1$s` to manage it here instead.',
							$key,
							$locked[ $key ]
						)
					);
				}

				$this->update_settings( array( $key => $args[2] ) );

				// Read back rather than echoing the input: coercion may have
				// clamped or rejected it, and reporting the input would claim a
				// write that did not land as typed.
				//
				// From the OPTION ROW, not get_public(): the save also rewrites
				// our wp-config block, but PHP has already defined those
				// constants for this request and cannot redefine them -- so
				// get_public() would resolve the constant and report the value
				// from BEFORE the write, making a successful save look ignored.
				// The row is what the next request's block was built from. (#398)
				$after = \XSpeed\Settings_Manager::get_public( self::SLUG, true );
				\WP_CLI::success( $key . ' = ' . self::scalar_for_display( $after[ $key ] ?? null ) );
				return;
			case 'flush':
				$ok = Object_Cache::flush();
				$ok ? \WP_CLI::success( 'Flushed.' ) : \WP_CLI::error( 'Flush failed.' );
				return;
			case 'snippet':
				\WP_CLI::log( Object_Cache::render_config_snippet( $this->get_settings() ) );
				return;
			case 'test':
				$t = Object_Cache::test_connection( $this->get_settings() );
				$t['ok'] ? \WP_CLI::success( $t['message'] ) : \WP_CLI::error( $t['message'] );
				return;
			case 'enable':
				$r = Object_Cache::enable( $this->get_settings() );
				$r['ok'] ? \WP_CLI::success( $r['message'] ) : \WP_CLI::error( $r['message'] );
				return;
			case 'disable':
				$r = Object_Cache::disable();
				$r['ok'] ? \WP_CLI::success( $r['message'] ) : \WP_CLI::error( $r['message'] );
				return;
			default:
				\WP_CLI::error( "Unknown action: $action" );
		}
	}

	/**
	 * Render one setting for a terminal. Bools read as true/false rather than
	 * 1/"", and an empty string is shown as (empty) so a blank line is never
	 * mistaken for a missing key.
	 *
	 * @param mixed $value Setting value, already masked if secret.
	 */
	private static function scalar_for_display( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( null === $value ) {
			return '(unset)';
		}
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $value );
		}
		$value = (string) $value;
		return '' === $value ? '(empty)' : $value;
	}

	/**
	 * The object cache is on when OUR drop-in is installed and actually
	 * persisting -- not when a backend host is merely typed into the
	 * settings. `detect()` reads the running instance, so a drop-in that is
	 * installed but degraded (connected to nothing) correctly reports off
	 * rather than claiming a cache the site is not getting. (#363)
	 */
	public function is_active(): ?bool {
		$state = Object_Cache::detect();
		return ! empty( $state['persistent'] );
	}

	/**
	 * Configured is not the same as working, and the difference is the whole
	 * point here -- a drop-in connected to nothing reports on to WordPress
	 * while persisting no data. Report what is actually happening.
	 */
	public function active_reason(): ?string {
		$state = Object_Cache::detect();
		if ( ! empty( $state['persistent'] ) ) {
			return __( 'The object cache drop-in is installed and storing data. This is measured from the running cache, not from the settings on this page.', 'xspeed' );
		}
		if ( ! empty( $state['degraded'] ) ) {
			return __( 'The drop-in is installed but is not storing anything, so this counts as off. Check the connection settings below.', 'xspeed' );
		}
		return __( 'No object cache is running. Entering a host below does not switch it on by itself -- the drop-in has to be installed and connect successfully.', 'xspeed' );
	}
}
