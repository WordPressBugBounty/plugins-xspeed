<?php
/**
 * Cloudflare module — connect a CF zone for purge + dev-mode toggles.
 *
 * Free tier (this module): API token / global key auth, zone
 * verification, manual purge, auto purge on xSpeed's own purge, dev
 * mode toggle.
 *
 * Pro tier (xspeed-pro): APO toggle, edge cache rules, edge cache TTL.
 * Per FEATURES.md "Cloudflare Integration" §8-10.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Cloudflare;

defined( 'ABSPATH' ) || exit;

use XSpeed\Cloudflare;
use XSpeed\Module;

final class CloudflareModule extends Module {

	public const SLUG    = 'cloudflare';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.1.0';

	/**
	 * Where the last connection-health result is cached: the outcome of the
	 * most recent verify (token + zone reachable) or purge (Cache-Purge
	 * permission actually works). Read by ui_notices() to show a persistent
	 * warning when Cloudflare is silently failing. (#119)
	 */
	private const HEALTH_OPTION = 'xspeed_cloudflare_health';

	public function ui_metadata(): array {
		return array(
			'label'        => 'Cloudflare',
			'icon'         => 'Cloud',
			'description'  => 'Connect a Cloudflare zone for automatic edge purging when xSpeed clears its cache, plus a dev-mode toggle.',
			'custom_panel' => 'CloudflarePanel',
		);
	}

	/**
	 * @inheritDoc
	 *
	 * Nothing exempt. It is inert without Cloudflare credentials, and where
	 * credentials exist the user set them up for a CDN rather than for the page
	 * cache we stood down from — but "inert today" is a weak reason to leave a
	 * switch on that nobody asked for, and on a site where the host DID take
	 * the page cache it is not inert at all.
	 */
	public function conflict_safe_exempt(): array {
		return array();
	}

	public function settings_schema(): array {
		return array(
			'enabled' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => 'Enable Cloudflare integration',
				'description' => 'Use the credentials below to verify your zone and run purges.',
			),
			'auth_method' => array(
				'type'          => 'enum',
				'default'       => 'token',
				'options'       => array( 'token', 'key' ),
				'option_labels' => array(
					'token' => 'API Token',
					'key'   => 'Global API Key',
				),
				'label'         => 'Authentication',
				'description'   => 'API Tokens (scoped, recommended) or the legacy Global API Key with your account email.',
				'dependsOn'     => array( 'field' => 'enabled' ),
			),
			'api_token' => array(
				'type'        => 'secret',
				'default'     => '',
				'label'       => 'API Token',
				'description' => 'Create a token at dash.cloudflare.com → My Profile → API Tokens. Needs "Zone → Cache Purge" + "Zone Settings" permissions.',
				// Only the token auth branch (and only while CF is enabled, via
				// the transitive gate on auth_method → enabled).
				'dependsOn'   => array( 'field' => 'auth_method', 'value' => 'token' ),
			),
			'email' => array(
				'type'        => 'string',
				'default'     => '',
				'label'       => 'Account Email',
				'description' => 'Only used when Authentication is set to Global API Key.',
				'dependsOn'   => array( 'field' => 'auth_method', 'value' => 'key' ),
			),
			'api_key' => array(
				'type'        => 'secret',
				'default'     => '',
				'label'       => 'Global API Key',
				'description' => 'Found at dash.cloudflare.com → My Profile → API Tokens → Global API Key.',
				'dependsOn'   => array( 'field' => 'auth_method', 'value' => 'key' ),
			),
			'zone_id' => array(
				'type'        => 'string',
				'default'     => '',
				'label'       => 'Zone ID',
				'description' => 'The 32-character hex Zone ID from your domain overview page.',
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'auto_purge_on_update' => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => 'Auto-purge Cloudflare on xSpeed purge',
				'description' => 'When xSpeed clears its own cache (post save, settings change, manual purge), trigger a Cloudflare purge too.',
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
		);
	}

	/**
	 * Encrypt the pre-1.1.0 plaintext credentials on upgrade. api_token /
	 * api_key became `secret`-typed fields (encrypted at rest); this converts
	 * any already-stored plaintext in one pass. Idempotent — encrypt_for_storage
	 * skips a value that already carries the cipher marker. (#115)
	 */
	public function migrations(): array {
		return array(
			'1.1.0' => static function ( array $opts ): array {
				foreach ( array( 'api_token', 'api_key' ) as $key ) {
					if ( isset( $opts[ $key ] ) && is_string( $opts[ $key ] ) && '' !== $opts[ $key ] ) {
						$opts[ $key ] = \XSpeed\Settings_Manager::encrypt_for_storage( $opts[ $key ] );
					}
				}
				return $opts;
			},
		);
	}

	public function rest_routes(): array {
		$default = parent::rest_routes();
		return array_merge(
			$default,
			array(
				array(
					'path'     => '/verify',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_verify' ),
				),
				array(
					'path'     => '/purge',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_purge' ),
				),
				array(
					'path'     => '/dev-mode',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_dev_mode' ),
				),
			)
		);
	}

	public function conflicts(): array {
		return array(
			array(
				'plugin'   => 'cloudflare/cloudflare.php',
				'feature'  => 'cloudflare.purge',
				'strategy' => \XSpeed\Conflict_Registry::STRATEGY_WARN,
				'reason'   => 'The official Cloudflare plugin also auto-purges; keep auto-purge enabled in only one to avoid double API calls.',
			),
		);
	}

	public function boot(): void {
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) ) {
			return;
		}
		if ( ! empty( $opts['auto_purge_on_update'] ) ) {
			// xSpeed fires this action whenever it purges its own
			// cache (see Cache::purge_all). Listening here keeps
			// CF in sync without any new wiring elsewhere.
			add_action( 'xspeed_after_purge_all', array( $this, 'on_xspeed_purge' ), 10, 0 );
		}
	}

	public function on_xspeed_purge(): void {
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) || empty( $opts['zone_id'] ) ) {
			return;
		}
		$result = Cloudflare::purge_all( $opts );
		$ok     = ! empty( $result['ok'] );

		// A GET /zones verify can pass with a token that still lacks the
		// "Zone → Cache Purge" permission, so the real purge is the only
		// authoritative signal for purge capability. Record it either way so
		// a silent auth failure becomes a visible, unresolved warning on the
		// module rather than an entry buried in the activity log. (#119)
		$this->record_health( $ok, 'purge', $ok ? '' : $this->message_of( $result ) );

		if ( ! $ok && class_exists( '\\XSpeed\\Activity_Log' ) ) {
			\XSpeed\Activity_Log::record(
				'cloudflare_purge_failed',
				'Cloudflare auto-purge failed: ' . ( $result['body']['message'] ?? 'unknown error' ),
				\XSpeed\Activity_Log::WARN
			);
		}
	}

	/**
	 * Persist any settings sent with the save, then verify the credentials
	 * immediately so an invalid or newly-changed token surfaces on the panel
	 * instead of failing silently the next time xSpeed purges. Response shape
	 * is unchanged (flat settings) so the autosave client is unaffected. (#119)
	 */
	public function rest_update_settings( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$settings = $this->update_settings( is_array( $params ) ? $params : array() );
		$this->verify_and_record();
		return rest_ensure_response( $settings );
	}

	public function rest_verify( \WP_REST_Request $request ) {
		$res = Cloudflare::verify( $this->get_settings() );
		$this->record_health( ! empty( $res['ok'] ), 'verify', $this->message_of( $res ) );
		return rest_ensure_response( $res );
	}

	public function rest_purge( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$opts = $this->get_settings();
		if ( isset( $params['urls'] ) && is_array( $params['urls'] ) && ! empty( $params['urls'] ) ) {
			return rest_ensure_response( Cloudflare::purge_urls( $opts, $params['urls'] ) );
		}
		return rest_ensure_response( Cloudflare::purge_all( $opts ) );
	}

	public function rest_dev_mode( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$on     = ! empty( $params['on'] );
		return rest_ensure_response( Cloudflare::set_dev_mode( $this->get_settings(), $on ) );
	}

	/**
	 * Persistent callouts on the Cloudflare panel: a hard warning when the
	 * connection is enabled but silently failing (bad token, or a purge that
	 * was rejected for lack of the Cache-Purge permission), and a soft warning
	 * when it's enabled but not fully configured yet. (#119)
	 */
	public function ui_notices(): array {
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) ) {
			return array();
		}
		if ( ! $this->has_credentials( $opts ) ) {
			return array(
				array(
					'tone'  => 'warn',
					'title' => __( 'Cloudflare is not fully configured.', 'xspeed' ),
					'body'  => __( 'Add your API token (or Global API Key + account email) and the Zone ID, then press Verify. Until then auto-purge does nothing.', 'xspeed' ),
				),
			);
		}
		$health = get_option( self::HEALTH_OPTION, null );
		if ( is_array( $health ) && array_key_exists( 'ok', $health ) && false === $health['ok'] ) {
			$context = isset( $health['context'] ) ? (string) $health['context'] : 'verify';
			$message = isset( $health['message'] ) ? (string) $health['message'] : '';
			$suffix  = '' !== $message ? ': ' . $message : '';
			if ( 'purge' === $context ) {
				return array(
					array(
						'tone'  => 'danger',
						'title' => __( 'Cloudflare purge is failing.', 'xspeed' ),
						'body'  => sprintf(
							/* translators: %s: the Cloudflare API error message, or empty. */
							__( 'The last edge purge was rejected by Cloudflare%s. Confirm the API token includes the "Zone → Cache Purge" permission for this zone — a token that can read the zone can still lack purge rights.', 'xspeed' ),
							$suffix
						),
					),
				);
			}
			return array(
				array(
					'tone'  => 'danger',
					'title' => __( 'Cloudflare credentials were rejected.', 'xspeed' ),
					'body'  => sprintf(
						/* translators: %s: the Cloudflare API error message, or empty. */
						__( 'The saved credentials could not verify this zone%s. Auto-purge will not work until this is fixed.', 'xspeed' ),
						$suffix
					),
				),
			);
		}
		return array();
	}

	/** Verify the current credentials and cache the outcome (save-time hook). */
	private function verify_and_record(): void {
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) || ! $this->has_credentials( $opts ) ) {
			// Nothing to verify — drop any stale health so an old failure notice
			// doesn't linger after the user disables or clears the integration.
			delete_option( self::HEALTH_OPTION );
			return;
		}
		$res = Cloudflare::verify( $opts );
		$this->record_health( ! empty( $res['ok'] ), 'verify', $this->message_of( $res ) );
	}

	/** Cache the last verify/purge outcome for ui_notices(). */
	private function record_health( bool $ok, string $context, string $message ): void {
		update_option(
			self::HEALTH_OPTION,
			array(
				'ok'         => $ok,
				'context'    => $context,
				'message'    => $message,
				'checked_at' => time(),
			),
			false
		);
	}

	/** Whether the current auth branch has all the fields it needs. */
	private function has_credentials( array $opts ): bool {
		if ( empty( $opts['zone_id'] ) ) {
			return false;
		}
		$method = isset( $opts['auth_method'] ) ? (string) $opts['auth_method'] : 'token';
		if ( 'key' === $method ) {
			return ! empty( $opts['api_key'] ) && ! empty( $opts['email'] );
		}
		return ! empty( $opts['api_token'] );
	}

	/** Human-readable failure reason from a Cloudflare engine result. */
	private function message_of( array $res ): string {
		if ( ! empty( $res['ok'] ) ) {
			return '';
		}
		$body = isset( $res['body'] ) && is_array( $res['body'] ) ? $res['body'] : array();
		if ( ! empty( $body['message'] ) ) {
			return (string) $body['message'];
		}
		if ( ! empty( $body['errors'][0]['message'] ) ) {
			return (string) $body['errors'][0]['message'];
		}
		return 'HTTP ' . ( isset( $res['status'] ) ? (string) $res['status'] : '0' );
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed cf',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Cloudflare verify / purge / dev-mode helpers.',
				'ai_hint'   => 'Cloudflare operations: verify the API credentials work, purge the edge cache, or toggle development mode. Use when a change is live on the origin but visitors still see the old version — that is usually the edge, not the local cache.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'verify', 'purge', 'dev-on', 'dev-off' ),
						'optional' => false,
					),
				),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$opts   = $this->get_settings();
		$action = $args[0] ?? 'verify';
		switch ( $action ) {
			case 'verify':
				$res = Cloudflare::verify( $opts );
				break;
			case 'purge':
				$res = Cloudflare::purge_all( $opts );
				break;
			case 'dev-on':
				$res = Cloudflare::set_dev_mode( $opts, true );
				break;
			case 'dev-off':
				$res = Cloudflare::set_dev_mode( $opts, false );
				break;
			default:
				\WP_CLI::error( "Unknown action: $action" );
				return;
		}
		\WP_CLI::log( 'HTTP ' . $res['status'] . ' — ' . ( $res['ok'] ? 'ok' : 'failed' ) );
		\WP_CLI::log( wp_json_encode( $res['body'] ) );

		// A failed call must exit non-zero, or the MCP bridge reports the
		// whole invocation as ok:true and an agent reads a rejected token
		// or an empty Zone ID as a successful verification.
		if ( empty( $res['ok'] ) ) {
			$detail = '';
			if ( is_array( $res['body'] ) && ! empty( $res['body']['message'] ) ) {
				$detail = ': ' . $res['body']['message'];
			}
			\WP_CLI::error( sprintf( '%s failed (HTTP %s)%s', $action, $res['status'], $detail ) );
		}
	}
}
