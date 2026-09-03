<?php
/**
 * Database cleanup module — read-first scan + selective cleanup +
 * OPTIMIZE TABLE + optional auto-cleanup schedule.
 *
 * Tier: Free per FEATURES.md Database §1–11. (DB-side activity log
 * Database §12 stays Pro.)
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Database;

defined( 'ABSPATH' ) || exit;

use XSpeed\Database_Cleaner;
use XSpeed\Module;
use XSpeed\Settings_Manager;

final class DatabaseModule extends Module {

	public const SLUG    = 'database';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	public function ui_metadata(): array {
		return array(
			'label'        => 'Database',
			'icon'         => 'Trash2',
			'description'  => 'Scan + clean WordPress bloat (revisions, spam, transients, orphan meta) and optimize tables.',
			'custom_panel' => 'DatabaseCleanerPanel',
		);
	}

	public function settings_schema(): array {
		return array(
			'schedule' => array(
				'type'          => 'enum',
				'default'       => 'manual',
				'options'       => array( 'manual', 'hourly', 'daily', 'weekly' ),
				'option_labels' => array(
					'manual' => 'Manual',
					'hourly' => 'Hourly',
					'daily'  => 'Daily',
					'weekly' => 'Weekly',
				),
				'label'         => 'Auto-Cleanup Schedule',
				'description'   => 'How often to run cleanup automatically. Manual means cleanup only runs when you press the button.',
			),
			'included_types' => array(
				'type'        => 'list',
				'default'     => array(),
				'item_type'   => 'string',
				'label'       => 'Auto-Cleanup Types',
				'description' => 'Which cleanup categories run on the schedule above. Leave empty to keep auto-cleanup disabled even if a schedule is set.',
			),
		);
	}

	public function rest_routes(): array {
		// Schema POST/GET come from the base; we add three custom routes.
		$default = parent::rest_routes();
		return array_merge(
			$default,
			array(
				array(
					'path'     => '/scan',
					'methods'  => 'GET',
					'callback' => array( $this, 'rest_scan' ),
				),
				array(
					'path'     => '/clean',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_clean' ),
				),
				array(
					'path'     => '/optimize',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_optimize' ),
				),
			)
		);
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed db',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Scan or clean WordPress bloat. Use --types=a,b to clean specific categories.',
				'ai_hint'   => 'Find and remove database bloat (post revisions, spam comments, expired transients, orphaned metadata). Always `scan` first to see what would go; `clean` is destructive and requires confirmation.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'scan', 'clean', 'optimize' ),
						'optional' => false,
					),
					array(
						'type'     => 'assoc',
						'name'     => 'types',
						'optional' => true,
					),
				),
			),
		);
	}

	public function boot(): void {
		add_action( Database_Cleaner::CRON_HOOK, array( Database_Cleaner::class, 'cron_tick' ) );
		add_action( 'update_option_xspeed_module_database', array( $this, 'on_settings_change' ), 10, 2 );
		add_action( 'add_option_xspeed_module_database', array( $this, 'on_settings_added' ), 10, 2 );
	}

	public function deactivate(): void {
		wp_clear_scheduled_hook( Database_Cleaner::CRON_HOOK );
	}

	public function on_settings_change( $old, $new ): void {
		$schedule = is_array( $new ) ? (string) ( $new['schedule'] ?? 'manual' ) : 'manual';
		$types    = is_array( $new ) && isset( $new['included_types'] ) && is_array( $new['included_types'] ) ? $new['included_types'] : array();
		Database_Cleaner::apply_schedule( $schedule, $types );
	}

	public function on_settings_added( $name, $value ): void {
		$schedule = is_array( $value ) ? (string) ( $value['schedule'] ?? 'manual' ) : 'manual';
		$types    = is_array( $value ) && isset( $value['included_types'] ) && is_array( $value['included_types'] ) ? $value['included_types'] : array();
		Database_Cleaner::apply_schedule( $schedule, $types );
	}

	public function rest_scan( \WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'scan'   => Database_Cleaner::scan(),
				'labels' => Database_Cleaner::type_labels(),
			)
		);
	}

	public function rest_clean( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$types = isset( $params['types'] ) && is_array( $params['types'] ) ? $params['types'] : array();
		if ( empty( $types ) ) {
			return new \WP_Error( 'xspeed_db_no_types', __( 'Provide at least one cleanup type.', 'xspeed' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( Database_Cleaner::clean( $types ) );
	}

	public function rest_optimize( \WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'optimized' => Database_Cleaner::optimize_tables(),
			)
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$action = $args[0] ?? 'scan';

		switch ( $action ) {
			case 'scan':
				foreach ( Database_Cleaner::scan() as $key => $row ) {
					\WP_CLI::log( sprintf( '%-22s %s — %d rows', $key, $row['label'], $row['count'] ) );
				}
				return;

			case 'clean':
				$types = isset( $assoc['types'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc['types'] ) ) ) : array_keys( Database_Cleaner::type_labels() );
				$res   = Database_Cleaner::clean( $types );
				foreach ( $res['cleaned'] as $key => $affected ) {
					\WP_CLI::log( sprintf( '%-22s %d removed', $key, $affected ) );
				}
				\WP_CLI::success( 'Cleanup complete.' );
				return;

			case 'optimize':
				$res = Database_Cleaner::optimize_tables();
				foreach ( $res as $table => $ok ) {
					\WP_CLI::log( sprintf( '%-40s %s', $table, $ok ? 'ok' : 'failed' ) );
				}
				\WP_CLI::success( 'Tables optimized.' );
				return;

			default:
				\WP_CLI::error( "Unknown action: $action" );
		}
	}
}
