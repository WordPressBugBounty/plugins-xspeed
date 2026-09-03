<?php
/**
 * Heartbeat Control module.
 *
 * Controls the WordPress Heartbeat API per context (dashboard / editor /
 * frontend) and tunes its interval. Disabling heartbeat on the frontend
 * is one of the cheapest wins for sites that don't need polling there.
 *
 * Tier: Free (1:1 with LiteSpeed Cache).
 * Roadmap: §4.3.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Heartbeat;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;

final class HeartbeatModule extends Module {

	public const SLUG    = 'heartbeat';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	// Per-context behavior values stored under `behavior_<context>`.
	public const BEHAVIOR_KEEP     = 'keep';     // leave alone
	public const BEHAVIOR_THROTTLE = 'throttle'; // apply our `frequency`
	public const BEHAVIOR_DISABLE  = 'disable';  // turn off entirely

	private const CONTEXTS = array( 'dashboard', 'editor', 'frontend' );

	public function ui_metadata(): array {
		return array(
			'label'       => 'Heartbeat',
			'icon'        => 'Activity',
			'description' => 'Control the WordPress Heartbeat API per context.',
		);
	}

	/**
	 * Default profile mirrors the IMPLEMENTATION recommendation:
	 *   - frontend: off by default (most polling-heavy, rarely needed)
	 *   - editor: throttled to 60s (was 15s)
	 *   - dashboard: throttled to 60s (was 60s — left as is)
	 */
	public function settings_schema(): array {
		$behavior_options       = array( self::BEHAVIOR_KEEP, self::BEHAVIOR_THROTTLE, self::BEHAVIOR_DISABLE );
		$behavior_option_labels = array(
			self::BEHAVIOR_KEEP     => 'Keep',
			self::BEHAVIOR_THROTTLE => 'Throttle',
			self::BEHAVIOR_DISABLE  => 'Disable',
		);

		return array(
			'behavior_dashboard' => array(
				'type'          => 'enum',
				'default'       => self::BEHAVIOR_THROTTLE,
				'options'       => $behavior_options,
				'option_labels' => $behavior_option_labels,
				'label'         => 'Dashboard',
				'description'   => 'Heartbeat behavior on /wp-admin/ screens (autosave, notifications).',
			),
			'behavior_editor'    => array(
				'type'          => 'enum',
				'default'       => self::BEHAVIOR_THROTTLE,
				'options'       => $behavior_options,
				'option_labels' => $behavior_option_labels,
				'label'         => 'Editor',
				'description'   => 'Heartbeat in the post / block editor. Disable only if you do not need autosave or co-edit locks.',
			),
			'behavior_frontend'  => array(
				'type'          => 'enum',
				'default'       => self::BEHAVIOR_DISABLE,
				'options'       => $behavior_options,
				'option_labels' => $behavior_option_labels,
				'label'         => 'Frontend',
				'description'   => 'Controls the Heartbeat API on the public site. Only takes effect when a plugin or theme actually loads heartbeat on the frontend (e.g. WooCommerce cart fragments, membership/notification plugins) — a default WordPress site loads none there, so this has no visible effect on such sites. Recommended: Disable, to stop the admin-ajax polling those plugins add.',
			),
			'frequency'          => array(
				'type'        => 'int',
				'default'     => 60,
				'min'         => 15,
				'max'         => 300,
				'label'       => 'Throttle Frequency',
				'description' => 'Interval in seconds for contexts set to Throttle. 60 is a sane default; lower = faster sync but more requests.',
			),
		);
	}

	// rest_routes — using the Module base-class default (GET + POST under
	// /xspeed/v1/heartbeat/ wired to rest_get_settings + rest_update_settings).

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed heartbeat',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Inspect or modify xSpeed heartbeat settings.',
				'ai_hint'   => 'Inspect or change WordPress Heartbeat (admin-ajax polling) frequency. Use for high admin-ajax.php CPU load, or hosting warnings about too many background requests.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'show', 'set' ),
						'optional' => false,
					),
					// Use `--for` (not `--context`): WP-CLI silently swallows a
					// `--context` assoc arg before it reaches the command, so
					// `set --context= --behavior=` always lost the context and
					// errored "Nothing to set". `--for` reads naturally
					// (set --for=editor --behavior=disable) and is collision-
					// free. No `options` constraint either — an options list
					// also dropped args from $assoc; we validate in
					// cli_handler() instead. (FBS-82153 Bug 2.)
					array(
						'type'     => 'assoc',
						'name'     => 'for',
						'optional' => true,
					),
					array(
						'type'     => 'assoc',
						'name'     => 'behavior',
						'optional' => true,
					),
					array(
						'type'     => 'assoc',
						'name'     => 'frequency',
						'optional' => true,
					),
				),
			),
		);
	}

	public function boot(): void {
		// Context resolution depends on WHEN we can classify the request:
		//  - Frontend: known at `init` (is_admin() is reliable there), and we
		//    must act before wp_enqueue_scripts (priority 10) registers the
		//    heartbeat script.
		//  - Admin: dashboard-vs-editor needs get_current_screen(), which is
		//    NULL at `init` and only populated from the `current_screen`
		//    action onward. Resolving context at init always returned
		//    "dashboard" on editor screens, so behavior_editor was dead.
		//    (FBS-82153 Bug 1.) Hook admin on `current_screen` instead, which
		//    fires before admin_enqueue_scripts so we can still dequeue.
		if ( is_admin() ) {
			add_action( 'current_screen', array( $this, 'apply_settings' ) );
		} else {
			add_action( 'init', array( $this, 'apply_settings' ), 5 );
		}
	}

	/**
	 * Hook handler — translates our settings into Heartbeat behavior. Runs
	 * on every request. The branches below are pure read + dispatch — no
	 * I/O so the cost is negligible even on cached requests.
	 */
	public function apply_settings(): void {
		$settings = $this->get_settings();
		$context  = $this->detect_context();
		$behavior = $settings[ 'behavior_' . $context ] ?? self::BEHAVIOR_KEEP;

		if ( self::BEHAVIOR_DISABLE === $behavior ) {
			// Dequeue the heartbeat script entirely. wp_deregister_script
			// runs on `init` priority 5 → before wp_default_scripts
			// (priority 10) re-registers, so we hook the actual enqueue
			// stage instead.
			add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_heartbeat' ), 1 );
			add_action( 'admin_enqueue_scripts', array( $this, 'dequeue_heartbeat' ), 1 );
		} elseif ( self::BEHAVIOR_THROTTLE === $behavior ) {
			$interval = max( 15, min( 300, (int) ( $settings['frequency'] ?? 60 ) ) );
			add_filter(
				'heartbeat_settings',
				static function ( $hb_settings ) use ( $interval ) {
					$hb_settings['interval'] = $interval;
					return $hb_settings;
				}
			);
		}
		// BEHAVIOR_KEEP → no filter, WP defaults apply.
	}

	/**
	 * Dequeue + deregister the heartbeat script. Safe to call multiple
	 * times — both wp functions are idempotent.
	 */
	public function dequeue_heartbeat(): void {
		wp_dequeue_script( 'heartbeat' );
		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * Classify the current request into dashboard / editor / frontend.
	 * Editor here means the block / classic post editor screens — they're
	 * the heaviest heartbeat consumer and worth their own bucket.
	 */
	private function detect_context(): string {
		if ( ! is_admin() ) {
			return 'frontend';
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
			return 'editor';
		}
		return 'dashboard';
	}

	// REST handlers — provided by Module base class
	// (rest_get_settings / rest_update_settings).

	public function cli_handler( array $args, array $assoc ): void {
		$action = $args[0] ?? 'show';

		if ( 'show' === $action ) {
			$opts = $this->get_settings();
			foreach ( $opts as $key => $value ) {
				\WP_CLI::log( sprintf( '%-22s %s', $key, is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) );
			}
			return;
		}

		if ( 'set' === $action ) {
			$patch     = array();
			$behaviors = array( self::BEHAVIOR_KEEP, self::BEHAVIOR_THROTTLE, self::BEHAVIOR_DISABLE );

			$has_context  = isset( $assoc['for'] ) && '' !== $assoc['for'];
			$has_behavior = isset( $assoc['behavior'] ) && '' !== $assoc['behavior'];

			// --for and --behavior are a pair: both or neither.
			if ( $has_context xor $has_behavior ) {
				\WP_CLI::error( 'Pass --for and --behavior together.' );
				return;
			}
			if ( $has_context && $has_behavior ) {
				if ( ! in_array( $assoc['for'], self::CONTEXTS, true ) ) {
					\WP_CLI::error( 'Invalid --for. Use one of: ' . implode( ', ', self::CONTEXTS ) . '.' );
					return;
				}
				if ( ! in_array( $assoc['behavior'], $behaviors, true ) ) {
					\WP_CLI::error( 'Invalid --behavior. Use one of: ' . implode( ', ', $behaviors ) . '.' );
					return;
				}
				$patch[ 'behavior_' . $assoc['for'] ] = $assoc['behavior'];
			}
			if ( isset( $assoc['frequency'] ) ) {
				$patch['frequency'] = (int) $assoc['frequency'];
			}
			if ( empty( $patch ) ) {
				\WP_CLI::error( 'Nothing to set. Provide --for= --behavior= and/or --frequency=' );
				return;
			}
			$this->update_settings( $patch );
			\WP_CLI::success( 'Updated heartbeat settings.' );
			return;
		}

		\WP_CLI::error( "Unknown action: $action" );
	}
}
