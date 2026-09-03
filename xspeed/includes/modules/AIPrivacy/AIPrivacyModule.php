<?php
/**
 * AI Privacy module — the GDPR off-switch FEATURES.md §AI row 6
 * mandates ship in Free, regardless of whether the user has Pro
 * installed.
 *
 * Why this lives in Free even though every AI feature is Pro: privacy
 * is a fundamental user right, not a paid feature. If a site activates
 * xSpeed Pro and starts collecting navigation patterns / Web Vitals
 * for AI analysis, the GDPR off-switch must already be present and
 * configured — not gated behind a license. So this module ships with
 * Free and exposes a public filter that every Pro AI feature checks
 * before recording any data point.
 *
 * Pro consumes this via:
 *   if ( ! apply_filters( 'xspeed_ai_can_collect_data', true ) ) return;
 *
 * When Pro is not installed, the module still works (the toggle just
 * has no consumer) — same pattern as our other Free/Pro contracts.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\AIPrivacy;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;
use XSpeed\Settings_Manager;

final class AIPrivacyModule extends Module {

	public const SLUG    = 'ai-privacy';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	/**
	 * Cookie name a consent banner sets when the visitor accepts AI
	 * data collection. Banner ships separately (UI work) — defining the
	 * key here means Pro modules and any third-party banner agree on a
	 * single source of truth.
	 */
	public const CONSENT_COOKIE = 'xspeed_ai_consent';

	public function ui_metadata(): array {
		return array(
			'label'       => 'AI Privacy',
			'icon'        => 'Shield',
			'description' => 'Govern whether AI-powered features (Pro) may collect data from your visitors. The off-switch is here in Free because privacy is a fundamental right, not a paid feature.',
		);
	}

	/**
	 * @inheritDoc
	 *
	 * OFF here would mean asking for less consent than the user configured, which
	 * is the one direction this profile must never move.
	 */
	public function conflict_safe_exempt(): array {
		return array( 'gdpr_consent_required' );
	}

	public function settings_schema(): array {
		return array(
			'gdpr_consent_required' => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => 'Require consent before AI data collection',
				'description' => 'When ON, AI-powered features only record data after a visitor accepts the consent banner. When OFF, they collect from every visitor — only legal in regions without GDPR-style consent rules. The setting applies even if Pro is not installed (so a later Pro upgrade respects whichever choice you made).',
			),
			'consent_banner_text'   => array(
				'type'        => 'string',
				'default'     => 'We collect anonymized navigation and performance data to make this site faster. Accept to help us optimize your experience.',
				'label'       => 'Consent banner text',
				'description' => 'Shown in the cookie consent banner. Keep it factual — what you collect (page navigation, performance metrics) and why.',
			),
		);
	}

	/**
	 * The canonical "may we collect data right now?" decision. Pro AI
	 * features should ALWAYS go through the `xspeed_ai_can_collect_data`
	 * filter (which this method backs by default) rather than calling
	 * this directly — so a site owner can override the decision with
	 * their own filter callback (e.g., a custom CMP integration).
	 */
	public static function can_collect( bool $default = true ): bool {
		$opts = Settings_Manager::get( self::SLUG );

		// Privacy mode off → fall back to whatever the caller proposed.
		// Most callers proposed true; an upstream filter that already
		// returned false gets preserved.
		if ( empty( $opts['gdpr_consent_required'] ) ) {
			return $default;
		}

		// Privacy mode on → require explicit consent cookie. Absence of
		// the cookie means the visitor hasn't accepted the banner yet
		// (or rejected it) and we record nothing.
		return ! empty( $_COOKIE[ self::CONSENT_COOKIE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	public function boot(): void {
		add_filter( 'xspeed_ai_can_collect_data', array( self::class, 'can_collect' ), 10, 1 );
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed ai-privacy',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Show whether AI data collection is allowed for the current request context (CLI = no cookie).',
				'ai_hint'   => 'May xSpeed send this site\'s data to an AI provider? Check before invoking any AI-backed feature — it reports the user\'s opt-in state, not a setting to change casually.',
				'synopsis'  => array(),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$opts = $this->get_settings();
		\WP_CLI::log( sprintf( '%-26s %s', 'gdpr_consent_required', ! empty( $opts['gdpr_consent_required'] ) ? 'on' : 'off' ) );
		\WP_CLI::log( sprintf( '%-26s %s', 'can_collect (no cookie)', self::can_collect() ? 'yes' : 'no' ) );
	}
}
