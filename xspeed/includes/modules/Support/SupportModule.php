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
	public const VERSION = '1.1.0';

	public function ui_metadata(): array {
		return array(
			'label'        => __( 'Help & Support', 'xspeed' ),
			'icon'         => 'LifeBuoy',
			'description'  => __( 'Open a support ticket with a one-click system snapshot already attached.', 'xspeed' ),
			'custom_panel' => 'SupportPanel',
		);
	}

	public function settings_schema(): array {
		return array(
			'support_url' => array(
				'type'        => 'string',
				'default'     => 'https://xspeedcache.com/support/',
				'label'       => __( 'Support URL', 'xspeed' ),
				'description' => __( 'Where the "Open ticket" button takes the user.', 'xspeed' ),
			),
		);
	}

	/**
	 * The ticket link moved to the dedicated xSpeed support page (#414). A
	 * site that persisted the old default keeps it in its options row, so the
	 * new default alone would not reach it — rewrite exactly the old default
	 * and leave any genuinely custom URL alone.
	 */
	public function migrations(): array {
		return array(
			'1.1.0' => static function ( array $opts ): array {
				$stored = isset( $opts['support_url'] ) ? untrailingslashit( trim( (string) $opts['support_url'] ) ) : '';
				if ( 'https://wpdeveloper.com/support' === $stored ) {
					$opts['support_url'] = 'https://xspeedcache.com/support/';
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
