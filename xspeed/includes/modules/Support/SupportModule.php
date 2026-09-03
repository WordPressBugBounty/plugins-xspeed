<?php
/**
 * Help & Support module — direct ticket link + paste-ready system
 * snapshot so tickets land with full context.
 *
 * Tier: Free per FEATURES.md "Support & Compatibility" → Help & Support.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Support;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;
use XSpeed\Support_Snapshot;

final class SupportModule extends Module {

	public const SLUG    = 'support';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	public function ui_metadata(): array {
		return array(
			'label'        => 'Help & Support',
			'icon'         => 'LifeBuoy',
			'description'  => 'Open a support ticket with a one-click system snapshot already attached.',
			'custom_panel' => 'SupportPanel',
		);
	}

	public function settings_schema(): array {
		return array(
			'support_url' => array(
				'type'        => 'string',
				'default'     => 'https://wpdeveloper.com/support',
				'label'       => 'Support URL',
				'description' => 'Where the "Open ticket" button takes the user.',
			),
		);
	}

	public function rest_routes(): array {
		$default = parent::rest_routes();
		return array_merge(
			$default,
			array(
				array(
					'path'     => '/snapshot',
					'methods'  => 'GET',
					'callback' => array( $this, 'rest_snapshot' ),
				),
			)
		);
	}

	public function rest_snapshot( \WP_REST_Request $request ) {
		$snapshot = Support_Snapshot::gather();
		return rest_ensure_response(
			array(
				'snapshot' => $snapshot,
				'markdown' => Support_Snapshot::to_markdown( $snapshot ),
			)
		);
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed support',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Print the support snapshot.',
				'ai_hint'   => 'Generate a diagnostic snapshot (environment, settings, active plugins, recent errors) to attach to a support request. Use when a problem needs escalating and the user is asked for their configuration.',
				'synopsis'  => array(),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		\WP_CLI::log( Support_Snapshot::to_markdown( Support_Snapshot::gather() ) );
	}
}
