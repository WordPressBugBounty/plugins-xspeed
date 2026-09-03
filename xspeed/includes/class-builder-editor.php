<?php
/**
 * Builder_Editor — "is this request a page-builder editing screen?"
 *
 * Page builders do not edit inside wp-admin. Beaver Builder, Elementor,
 * Divi, Brizy, Oxygen and friends all render their editor over an ordinary
 * FRONT-END URL, flagged by a query argument:
 *
 *     /my-page/?fl_builder                     Beaver Builder
 *     /?p=12&elementor-preview=12              Elementor
 *     /my-page/?et_fb=1                        Divi
 *
 * Every guard in this plugin is `is_admin() || DOING_AJAX || DOING_CRON ||
 * REST_REQUEST`, and an editing screen is none of those. So the optimizer
 * treats the editor exactly like a public page: combining its scripts,
 * deferring them, stripping "bloat" it considers unnecessary.
 *
 * That breaks the editor outright. Combine JS merges ~21 builder handles
 * into one dependency-free footer bundle, so `fl-builder.min.js` runs
 * before `jquery.nanoscroller` and `fl-builder-system` exist — the toolbar
 * and canvas never render and the console fills with `… is not a function`.
 * The user cannot edit their page, and nothing in the UI points at us. (#281)
 *
 * There is no performance argument on the other side. An editing screen is
 * a logged-in, single-user, uncacheable request; optimizing it trades a
 * benefit nobody measures for a risk that costs the user their editor.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * Detects page-builder editing/preview requests on the front end.
 *
 * ## Why detection is deliberately conservative
 *
 * This predicate DISABLES optimization, so its failure modes are not
 * symmetric:
 *
 *   - a false negative breaks one builder's editor (the bug we already have)
 *   - a false positive silently disables optimization on real pages, which
 *     is invisible, site-wide, and reported as "xSpeed does nothing"
 *
 * So every signal below is a query argument a builder only ever sets on its
 * own editing screen. We do NOT infer from `is_user_logged_in()` or
 * `current_user_can( 'edit_posts' )` — an editor browsing their own site is
 * a normal front-end visitor whose pages should still be optimized. (#203
 * tracks that broader question separately.)
 */
class Builder_Editor {

	/**
	 * Query arguments that mean "a builder is editing this page".
	 *
	 * Presence alone is the signal — several builders set the argument with
	 * an empty value (`?fl_builder`), so a truthiness test would miss them.
	 *
	 * Keep this list additive. Removing an entry re-breaks a builder.
	 *
	 * @var string[]
	 */
	private const EDITOR_QUERY_ARGS = array(
		// Beaver Builder — the editor, and the iframe it renders its UI in.
		'fl_builder',
		'fl_builder_ui_iframe',
		// Elementor — the preview iframe inside the editor.
		'elementor-preview',
		// Brizy.
		'brizy-edit',
		'brizy-edit-iframe',
		// Divi — visual builder, and the backend (wireframe) builder.
		'et_fb',
		'et_bfb',
		// Visual Composer — current and legacy argument names.
		'vcv-editable',
		'vcv-be-editor',
		'vc_editable',
		// Oxygen.
		'ct_builder',
		// SiteOrigin Page Builder live editor.
		'siteorigin_panels_live_editor',
		// Thrive Architect.
		'tve',
		// WPBakery frontend editor.
		'vc_action',
	);

	/**
	 * Memoized result. The answer cannot change within a request, and this
	 * is consulted from several modules' boot paths.
	 *
	 * @var bool|null
	 */
	private static $is_editor = null;

	/**
	 * Is the current request a page-builder editing or preview screen?
	 */
	public static function is_active(): bool {
		if ( null !== self::$is_editor ) {
			return self::$is_editor;
		}

		$found = self::detect();

		/**
		 * Filter whether this request is a page-builder editing screen.
		 *
		 * Lets a site rescue a builder we do not know about — or force
		 * optimization back on for one we detect too eagerly — without
		 * patching the plugin.
		 *
		 * @param bool $found Whether a builder-editor signal was detected.
		 */
		self::$is_editor = (bool) apply_filters( 'xspeed_is_builder_editor', $found );

		return self::$is_editor;
	}

	/**
	 * The unfiltered detection itself.
	 */
	private static function detect(): bool {
		// Only a front-end request can be a builder editor. wp-admin, AJAX,
		// cron and REST are already excluded by every caller's own guard;
		// repeating it here keeps this correct when called from anywhere.
		if ( is_admin()
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
		) {
			return false;
		}

		foreach ( self::EDITOR_QUERY_ARGS as $arg ) {
			// isset(), not a value check: `?fl_builder` carries no value.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only request-shape detection; no state is changed and no input is used beyond "is this key present".
			if ( isset( $_GET[ $arg ] ) ) {
				return true;
			}
		}

		// Elementor's editor frame itself, which posts `action=elementor`.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only request-shape detection; the value is compared, never used.
		if ( isset( $_REQUEST['action'] ) && 'elementor' === $_REQUEST['action'] ) {
			return true;
		}

		// Ask the builders that expose a runtime answer. These are more
		// reliable than a query argument when available, and cover editor
		// sub-requests that carry no argument of their own.
		if ( class_exists( '\FLBuilderModel' ) && method_exists( '\FLBuilderModel', 'is_builder_active' ) && \FLBuilderModel::is_builder_active() ) {
			return true;
		}

		if ( class_exists( '\Elementor\Plugin' ) ) {
			$elementor = \Elementor\Plugin::$instance;
			if ( isset( $elementor->preview ) && method_exists( $elementor->preview, 'is_preview_mode' ) && $elementor->preview->is_preview_mode() ) {
				return true;
			}
			if ( isset( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) && $elementor->editor->is_edit_mode() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Drop the memo. Tests only — a single request never changes answer.
	 */
	public static function reset(): void {
		self::$is_editor = null;
	}
}
