<?php
/**
 * Privacy module — the dashboard control for usage-analytics consent.
 *
 * Consent was collectable in exactly one place, the setup wizard, and
 * withdrawable in none: `Onboarding::apply()` was the only writer in Free or
 * Pro, no module schema carried a field for it, and the strings only ever
 * appeared in the wizard bundle. So the wizard's own "Change it anytime from
 * the dashboard" and readme.txt's "disable it later from the xSpeed Cache
 * dashboard" were both untrue — the only way to withdraw consent was to
 * re-run the whole wizard. (#437)
 *
 * This module is that missing control, and being a Module rather than a
 * bespoke panel is what gives it the dashboard, `wp xspeed privacy` and MCP
 * `run_command` in one go (IMPLEMENTATION.md §17).
 *
 * The setting is a VIEW over WP Insights' own `wpins_allow_tracking` row, not
 * a copy of it:
 *   - reads come from the tracker via `xspeed_setting_external_source`, so
 *     the panel can never disagree with what the tracker actually believes;
 *   - writes go through `Usage_Tracker::opt_in()` on `xspeed_settings_saved`,
 *     because consent is not just a flag — opting in schedules the cron and
 *     registers the install, opting out clears the cron. Writing the option
 *     row alone would leave a site "opted out" in the UI with the daily send
 *     still scheduled.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Privacy;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;
use XSpeed\Plugin;

final class PrivacyModule extends Module {

	public const SLUG    = 'privacy';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	public function ui_metadata(): array {
		return array(
			'label'       => __( 'Privacy & usage data', 'xspeed' ),
			'icon'        => 'ShieldCheck',
			'description' => __( 'Control the anonymous usage analytics you were asked about in the setup wizard.', 'xspeed' ),
		);
	}

	public function settings_schema(): array {
		return array(
			'usage_tracking' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Share anonymous usage data', 'xspeed' ),
				'description' => __( 'Share anonymous basics — WordPress & PHP version, active theme & plugins, server type, and which features you enable. Never personal data or page content. Turning this off stops all collection and clears the scheduled send.', 'xspeed' ),
			),
		);
	}

	public function boot(): void {
		// Read the tracker's own state rather than our option row. The row is
		// still written (Settings_Manager owns that), but it is never the
		// source of truth: a site that opted in through the wizard has no
		// row at all, and would otherwise render as opted out.
		add_filter( 'xspeed_setting_external_source', array( $this, 'read_consent' ), 10, 3 );
		add_action( 'xspeed_settings_saved', array( $this, 'apply_consent' ), 10, 2 );
	}

	/**
	 * Answer reads of `privacy.usage_tracking` from the tracker.
	 *
	 * @param mixed  $value Value resolved so far (null = not ours).
	 * @param string $slug  Module slug being read.
	 * @param string $key   Setting key being read.
	 * @return mixed
	 */
	public function read_consent( $value, $slug, $key ) {
		if ( self::SLUG !== $slug || 'usage_tracking' !== $key ) {
			return $value;
		}
		$tracker = $this->tracker();

		return ( $tracker && method_exists( $tracker, 'is_opted_in' ) ) ? (bool) $tracker->is_opted_in() : false;
	}

	/**
	 * The consent authority.
	 *
	 * Filterable so this module can be exercised against a double — the
	 * Plugin accessor is typed to the concrete Usage_Tracker, so there is
	 * otherwise no seam that does not require booting the whole singleton.
	 *
	 * @return object|null
	 */
	private function tracker() {
		/**
		 * Filter the object that answers usage-analytics consent.
		 *
		 * @param object|null $tracker Usage_Tracker instance, or null.
		 */
		return apply_filters( 'xspeed_usage_consent_tracker', Plugin::instance()->usage_tracker() );
	}

	/**
	 * Route a save through the tracker so the cron follows the flag.
	 *
	 * Only acts when the key was actually part of the save: a write to some
	 * other module, or a partial save that never mentioned consent, must not
	 * be read as the admin revoking it.
	 *
	 * @param string              $slug  Module whose settings were saved.
	 * @param array<string,mixed> $clean Settings as stored.
	 */
	public function apply_consent( $slug, $clean ): void {
		if ( self::SLUG !== $slug || ! is_array( $clean ) || ! array_key_exists( 'usage_tracking', $clean ) ) {
			return;
		}
		$tracker = $this->tracker();
		if ( ! $tracker || ! method_exists( $tracker, 'opt_in' ) ) {
			return;
		}
		$wanted = ! empty( $clean['usage_tracking'] );
		// opt_in( true ) sends immediately to register the install, so only
		// call it on an actual change — re-saving an unrelated field on this
		// panel must not fire a payload.
		if ( method_exists( $tracker, 'is_opted_in' ) && (bool) $tracker->is_opted_in() === $wanted ) {
			return;
		}
		$tracker->opt_in( $wanted );
	}

	/**
	 * CLI surface — and with it MCP, which dispatches to these same
	 * callbacks. `status` is the minimum every module owes
	 * tests/e2e/50-cli-mcp-coverage.spec.ts.
	 */
	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed privacy',
				'callback'  => array( $this, 'cli_privacy' ),
				'shortdesc' => 'Show or change usage-analytics consent.',
				'ai_hint'   => 'Whether this site shares anonymous usage analytics, and the way to turn that on or off. Use for "am I sending telemetry", "stop sharing usage data", or any consent/privacy question about analytics.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'optional' => true,
						'options'  => array( 'status', 'enable', 'disable' ),
					),
				),
			),
		);
	}

	/**
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function cli_privacy( $args = array(), $assoc_args = array() ): void {
		unset( $assoc_args );
		$action  = isset( $args[0] ) ? (string) $args[0] : 'status';
		$tracker = $this->tracker();
		if ( ! $tracker ) {
			\WP_CLI::error( 'Usage tracker unavailable.' );
			return;
		}

		if ( 'enable' === $action || 'disable' === $action ) {
			$this->update_settings( array( 'usage_tracking' => 'enable' === $action ) );
		}

		$on = method_exists( $tracker, 'is_opted_in' ) && $tracker->is_opted_in();
		\WP_CLI::log( 'usage analytics: ' . ( $on ? 'on' : 'off' ) );
		\WP_CLI::log( 'scheduled send: ' . ( wp_next_scheduled( \XSpeed\Usage_Tracker::EVENT_HOOK ) ? 'scheduled' : 'none' ) );
	}
}
