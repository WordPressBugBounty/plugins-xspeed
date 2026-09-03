<?php
/**
 * Deactivation feedback modal.
 *
 * Shows a short, polite "why are you leaving?" survey to every admin who clicks
 * Deactivate on the Plugins screen, so we learn what to fix.
 *
 * FLOW — this class is the UI + capture layer only; it never sends anything
 * itself. On "Submit & Deactivate" it STORES the reason in the canonical WP
 * Insights options (`wpins_deactivation_reason_<slug>` /
 * `wpins_deactivation_details_<slug>`) and returns. WordPress then fires the
 * real deactivation, and Usage_Tracker::deactivate_this_plugin() reads those
 * options and transmits them to WPInsight (send.wpinsight.com) using the
 * platform's `deactivation_reason` / `deactivation_details` contract — the same
 * two-phase pattern every WPDeveloper plugin uses. This keeps a single send
 * path (correlated to the registered site when usage analytics is on; a
 * minimal reason-only payload as per-action consent when it's off).
 *
 * "Skip & Deactivate", the close button, the overlay, and Esc store NOTHING
 * and deactivate/dismiss with no side effects.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

class Deactivation_Feedback {

	/** admin-ajax action + nonce name for the survey submission. */
	const AJAX_ACTION = 'xspeed_deactivation_feedback';
	const NONCE       = 'xspeed_deactivation_feedback';

	/**
	 * Canonical WP Insights option-name prefixes. Usage_Tracker reads these at
	 * deactivation time keyed on the same plugin slug (basename of XSPEED_FILE
	 * without .php), so the two must agree. These names are the WPInsight
	 * platform contract — do not rename them.
	 */
	const REASON_OPTION_PREFIX  = 'wpins_deactivation_reason_';
	const DETAILS_OPTION_PREFIX = 'wpins_deactivation_details_';

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_footer-plugins.php', array( $this, 'render_modal' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
	}

	/**
	 * The survey reasons. Defined in PHP (not JS) so every label + prompt is
	 * translatable via the `xspeed` textdomain. Each reason may declare:
	 *   - 'prompt'  : placeholder for an optional follow-up textarea.
	 *   - 'support' : true to surface a "contact support" callout (used for the
	 *                 reasons where we'd rather help than lose the user).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function reasons() {
		return array(
			array(
				'id'    => 'no_longer_needed',
				'label' => __( 'I no longer need the plugin', 'xspeed' ),
			),
			array(
				'id'     => 'switching_plugin',
				'label'  => __( "I'm switching to another plugin", 'xspeed' ),
				'prompt' => __( 'Which plugin are you switching to?', 'xspeed' ),
			),
			array(
				'id'      => 'difficult_to_use',
				'label'   => __( 'The plugin is difficult to use', 'xspeed' ),
				'prompt'  => __( 'What did you find confusing? We\'d love to improve it.', 'xspeed' ),
				'support' => true,
			),
			array(
				'id'      => 'couldnt_get_working',
				'label'   => __( "I couldn't get the plugin to work", 'xspeed' ),
				'prompt'  => __( 'What issue did you run into? We\'re glad to help.', 'xspeed' ),
				'support' => true,
			),
			array(
				'id'      => 'performance',
				'label'   => __( "The plugin affects my site's performance", 'xspeed' ),
				'prompt'  => __( 'What did you notice? Any detail helps us pin it down.', 'xspeed' ),
				'support' => true,
			),
			array(
				'id'     => 'missing_feature',
				'label'  => __( "It's missing a specific feature I need", 'xspeed' ),
				'prompt' => __( 'Which feature were you looking for?', 'xspeed' ),
			),
			array(
				'id'    => 'temporary',
				'label' => __( "It's a temporary deactivation", 'xspeed' ),
			),
			array(
				'id'     => 'other',
				'label'  => __( 'Other', 'xspeed' ),
				'prompt' => __( 'Please tell us a little more.', 'xspeed' ),
			),
		);
	}

	/**
	 * Enqueue the modal assets — only on the Plugins screen, only for users who
	 * can actually deactivate plugins.
	 *
	 * These two files are SOURCED from `public/`, not `assets/`, even though
	 * they load from `assets/` at runtime. `assets/` is Vite's `outDir` with
	 * `emptyOutDir: true`, so every `npm run build` DELETES anything there it
	 * didn't generate — a hand-written file placed in `assets/` silently
	 * disappears from the release zip (`npm run dist-archive` builds first).
	 * `public/` is Vite's `publicDir`: its contents are copied into `assets/`
	 * verbatim on each build, which is how `icon.svg` and `menu-icon.css`
	 * already ship. Keep these two there.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( 'plugins.php' !== $hook || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$css = XSPEED_DIR . 'assets/deactivate.css';
		$js  = XSPEED_DIR . 'assets/deactivate.js';

		if ( file_exists( $css ) ) {
			wp_enqueue_style(
				'xspeed-deactivate',
				XSPEED_URL . 'assets/deactivate.css',
				array(),
				XSPEED_VERSION . '.' . filemtime( $css )
			);
		}

		if ( file_exists( $js ) ) {
			wp_enqueue_script(
				'xspeed-deactivate',
				XSPEED_URL . 'assets/deactivate.js',
				array(),
				XSPEED_VERSION . '.' . filemtime( $js ),
				true
			);
			wp_localize_script(
				'xspeed-deactivate',
				'XSpeedDeactivate',
				array(
					// The plugin's row identity on plugins.php (data-plugin attr).
					'plugin'  => plugin_basename( XSPEED_FILE ),
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => self::AJAX_ACTION,
					'nonce'   => wp_create_nonce( self::NONCE ),
				)
			);
		}
	}

	/**
	 * Render the (hidden) modal markup into the Plugins-screen footer. All
	 * copy lives here so it's translatable; the JS only toggles visibility and
	 * posts the result.
	 *
	 * THEME — the modal follows xSpeed's OWN light/dark preference, not the
	 * OS's. `Admin::user_theme()` reads the `xspeed_theme` cookie that
	 * `useTheme` mirrors on every toggle, so the dashboard and this modal
	 * share one source of truth and agree by construction. We stamp the class
	 * on our own container rather than on `<body>`: `Admin::admin_body_class()`
	 * deliberately bails on screens that aren't ours, and `plugins.php` is a
	 * core screen we don't own — so `body.xspeed-dark` is never present here
	 * and keying the CSS off it would pin the modal to light mode forever.
	 * A `prefers-color-scheme` media query is equally wrong: it tracks the OS
	 * and ignores the in-app toggle, which is the bug this replaces (a user on
	 * dark-theme xSpeed with a light OS got a full-screen white dialog).
	 */
	public function render_modal() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$support_url = 'https://wpdeveloper.com/support/';
		$theme_class = 'dark' === Admin::user_theme() ? ' xspeed-deactivate--dark' : '';
		?>
		<div id="xspeed-deactivate-modal" class="xspeed-deactivate<?php echo esc_attr( $theme_class ); ?>" aria-hidden="true">
			<div class="xspeed-deactivate__overlay" data-xspeed-close></div>
			<div class="xspeed-deactivate__dialog" role="dialog" aria-modal="true" aria-labelledby="xspeed-deactivate-title">
				<div class="xspeed-deactivate__header">
					<span class="xspeed-deactivate__brand">
						<img class="xspeed-deactivate__logo" src="<?php echo esc_url( XSPEED_URL . 'assets/icon.svg' ); ?>" alt="" width="24" height="24" />
						<span><?php esc_html_e( 'xSpeed Cache', 'xspeed' ); ?></span>
					</span>
					<button type="button" class="xspeed-deactivate__close" data-xspeed-close aria-label="<?php esc_attr_e( 'Close', 'xspeed' ); ?>">&times;</button>
				</div>

				<div class="xspeed-deactivate__body">
					<h2 id="xspeed-deactivate-title" class="xspeed-deactivate__title"><?php esc_html_e( 'Sorry to see you go', 'xspeed' ); ?></h2>
					<p class="xspeed-deactivate__sub">
						<?php esc_html_e( "If you have a moment, we'd love to know why you're deactivating xSpeed Cache — pick all that apply. It takes less than a minute and helps us make it better for everyone, but it's completely optional.", 'xspeed' ); ?>
					</p>

					<form id="xspeed-deactivate-form" class="xspeed-deactivate__reasons">
						<?php foreach ( $this->reasons() as $reason ) : ?>
							<div class="xspeed-deactivate__reason">
								<label class="xspeed-deactivate__option">
									<input type="checkbox" name="xspeed-deactivate-reason" value="<?php echo esc_attr( $reason['id'] ); ?>" />
									<span><?php echo esc_html( $reason['label'] ); ?></span>
								</label>
								<?php if ( ! empty( $reason['prompt'] ) ) : ?>
									<textarea
										class="xspeed-deactivate__detail"
										data-for="<?php echo esc_attr( $reason['id'] ); ?>"
										rows="2"
										placeholder="<?php echo esc_attr( $reason['prompt'] ); ?>"
										hidden></textarea>
								<?php endif; ?>
								<?php if ( ! empty( $reason['support'] ) ) : ?>
									<div class="xspeed-deactivate__help" data-for="<?php echo esc_attr( $reason['id'] ); ?>" hidden>
										<span class="xspeed-deactivate__help-title"><?php esc_html_e( '💡 Need a hand before you go?', 'xspeed' ); ?></span>
										<a href="<?php echo esc_url( $support_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Contact our support team', 'xspeed' ); ?></a>
									</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</form>

					<p class="xspeed-deactivate__privacy">
						<?php esc_html_e( 'Your response is sent to WPDeveloper along with your site URL and plugin version. Choose “Skip & Deactivate” to leave without sharing anything.', 'xspeed' ); ?>
					</p>
				</div>

				<div class="xspeed-deactivate__footer">
					<a href="#" class="xspeed-deactivate__skip" data-xspeed-skip><?php esc_html_e( 'Skip & Deactivate', 'xspeed' ); ?></a>
					<button type="button" class="xspeed-deactivate__submit button button-primary" data-xspeed-submit>
						<?php esc_html_e( 'Submit & Deactivate', 'xspeed' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * admin-ajax handler for the survey. Verifies nonce + capability, then
	 * STORES the selected reason(s) in the canonical WP Insights options. The
	 * actual transmission to WPInsight happens later in
	 * Usage_Tracker::deactivate_this_plugin(), which WordPress fires when the
	 * browser follows the real deactivate URL after this resolves. This method
	 * never sends anything and never deactivates anything itself.
	 *
	 * Reasons are multi-select. Each selected id is resolved to its label
	 * SERVER-SIDE (never trust a client-sent display string) and the labels are
	 * joined into the single `deactivation_reason` string WPInsight files —
	 * keeping the platform's scalar contract. Per-reason follow-up text arrives
	 * as `detail_<id>` and is combined into `deactivation_details`.
	 */
	public function handle_submit() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed here and sanitized with sanitize_key() on the very next line; PHPCS cannot follow the two-statement form. Values are then matched against the hardcoded reasons() allowlist below, so anything unrecognised is dropped.
		$submitted = isset( $_POST['reason'] ) ? (array) wp_unslash( $_POST['reason'] ) : array();
		$submitted = array_map( 'sanitize_key', $submitted );

		$labels  = array();
		$details = array();
		foreach ( $this->reasons() as $reason ) {
			if ( ! in_array( $reason['id'], $submitted, true ) ) {
				continue;
			}
			$labels[] = $reason['label'];

			$detail_key = 'detail_' . $reason['id'];
			if ( isset( $_POST[ $detail_key ] ) ) {
				$text = sanitize_textarea_field( wp_unslash( $_POST[ $detail_key ] ) );
				if ( '' !== $text ) {
					// Prefix with the label so multi-reason notes stay legible.
					$details[] = $reason['label'] . ': ' . $text;
				}
			}
		}

		$slug = basename( XSPEED_FILE, '.php' );

		if ( ! empty( $labels ) ) {
			update_option( self::REASON_OPTION_PREFIX . $slug, implode( ', ', $labels ), false );
		}
		if ( ! empty( $details ) ) {
			update_option( self::DETAILS_OPTION_PREFIX . $slug, implode( ' | ', $details ), false );
		}

		wp_send_json_success();
	}
}
