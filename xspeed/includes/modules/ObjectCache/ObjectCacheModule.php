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
			'label'        => 'Object Cache',
			'icon'         => 'Server',
			'description'  => 'Configure a persistent object cache (Redis / Memcached) and generate a paste-ready wp-config.php snippet.',
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
				'label'         => 'Backend',
				'description'   => 'Which cache server you intend to use. Affects the generated wp-config snippet.',
			),
			'redis_host' => array(
				'type'        => 'string',
				'default'     => '127.0.0.1',
				'label'       => 'Redis Host',
				'description' => 'Hostname or IP of the Redis server. Use 127.0.0.1 for a local socket on the same machine as PHP.',
			),
			'redis_port' => array(
				'type'        => 'int',
				'default'     => 6379,
				'min'         => 1,
				'max'         => 65535,
				'label'       => 'Redis Port',
				'description' => 'Default Redis port is 6379.',
			),
			'redis_user' => array(
				'type'        => 'string',
				'default'     => '',
				'label'       => 'Redis User',
				'description' => 'Optional. Set this only if your host provisioned a dedicated Redis ACL user (Redis 6+) — e.g. some managed hosts issue a Redis User alongside the password. Leave blank to authenticate as the default user (legacy password-only Redis).',
			),
			'redis_password' => array(
				'type'        => 'secret',
				'default'     => '',
				'label'       => 'Redis Password',
				'description' => 'Leave blank if your Redis server runs without auth.',
			),
			'redis_database' => array(
				'type'        => 'int',
				'default'     => 0,
				'min'         => 0,
				'max'         => 15,
				'label'       => 'Redis Database',
				'description' => 'Redis logical DB number (0-15). Use a dedicated DB per site if Redis is shared.',
			),
			'memcached_host' => array(
				'type'        => 'string',
				'default'     => '127.0.0.1',
				'label'       => 'Memcached Host',
				'description' => 'Used when Backend = Memcached.',
			),
			'memcached_port' => array(
				'type'        => 'int',
				'default'     => 11211,
				'min'         => 1,
				'max'         => 65535,
				'label'       => 'Memcached Port',
				'description' => 'Default Memcached port is 11211.',
			),
			'key_prefix' => array(
				'type'        => 'string',
				'default'     => '',
				'label'       => 'Cache Key Prefix',
				'description' => 'Unique salt for this site\'s cache keys. Critical when multiple WP sites share one Redis/Memcached server. On ACL/namespaced Redis (e.g. xCloud), this MUST match the host\'s "Redis Object Cache Key" — otherwise cache writes are denied (NOPERM) and nothing persists.',
			),
			'connection_timeout' => array(
				'type'        => 'int',
				'default'     => 1,
				'min'         => 0,
				'max'         => 60,
				'label'       => 'Connection Timeout (seconds)',
				'unit'        => 'seconds',
				'description' => 'How long to wait for a connection. Keep low (1-2s) so a misconfigured cache never stalls the page.',
			),
			'persistent' => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => 'Persistent Connections',
				'description' => 'Reuse the connection across PHP requests when supported. Generally a win unless the cache server complains about idle connections.',
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

	public function boot(): void {
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
				if ( Object_Cache::is_our_dropin_present() ) {
					Object_Cache::install_dropin();
				}
				update_option( 'xspeed_oc_dropin_synced', XSPEED_VERSION );
			}
		);
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
		return rest_ensure_response( Object_Cache::detect() );
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
				'shortdesc' => 'Show object cache status, flush, or print the wp-config snippet.',
				'ai_hint'   => 'Is a persistent object cache (Redis/Memcached) connected and working? Use for slow admin pages, high database load, or "should I add Redis" questions — it reports the backend, connection health and hit rate.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'status', 'flush', 'snippet', 'enable', 'disable', 'test' ),
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
}
