<?php
/**
 * Per-post cache rules meta box (Phase 3.4).
 *
 * Renders in the classic editor sidebar and (with WP 5.0+'s legacy
 * meta-box compatibility) in the block editor's bottom panel. The
 * Gutenberg-native sidebar UI is a separate enhancement; this file
 * ships the read/write surface so power users get the feature today
 * regardless of editor choice.
 *
 * The postmetas themselves are registered (with show_in_rest) by
 * Cache_Rules::register_post_meta — letting any REST consumer
 * (Gutenberg, automation, an external CMS) read + write the rules
 * without going through this meta box.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Cache_Meta_Box {

	public const NONCE_ACTION = 'xspeed_per_post_rules';
	public const NONCE_FIELD  = 'xspeed_per_post_rules_nonce';

	public static function boot(): void {
		add_action( 'init', array( Cache_Rules::class, 'register_post_meta' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'handle_save' ), 10, 2 );
	}

	public static function register_meta_box(): void {
		foreach ( Cache_Rules::supported_post_types() as $type ) {
			add_meta_box(
				'xspeed-cache-rules',
				__( 'xSpeed · Cache Rules', 'xspeed' ),
				array( __CLASS__, 'render' ),
				$type,
				'side',
				'default'
			);
		}
	}

	public static function render( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$no_cache = (bool) get_post_meta( $post->ID, Cache_Rules::META_NO_CACHE, true );
		$expiry   = (int) get_post_meta( $post->ID, Cache_Rules::META_EXPIRY_HOURS, true );
		?>
		<p>
			<label>
				<input
					type="checkbox"
					name="xspeed_no_cache"
					value="1"
					<?php checked( $no_cache ); ?>
				/>
				<?php esc_html_e( 'Never cache this page', 'xspeed' ); ?>
			</label>
		</p>
		<p style="font-size:12px;color:#666;margin:-8px 0 12px;">
			<?php esc_html_e( 'Skip the page cache entirely for this post. Useful for highly dynamic pages.', 'xspeed' ); ?>
		</p>

		<p>
			<label for="xspeed_expiry_hours">
				<?php esc_html_e( 'Custom cache lifetime', 'xspeed' ); ?>
			</label>
			<br />
			<input
				type="number"
				id="xspeed_expiry_hours"
				name="xspeed_expiry_hours"
				min="0"
				max="720"
				value="<?php echo esc_attr( (string) max( 0, $expiry ) ); ?>"
				style="width:80px;"
			/>
			<span style="font-size:12px;color:#666;">
				<?php esc_html_e( 'hours · 0 = use global', 'xspeed' ); ?>
			</span>
		</p>
		<p style="font-size:12px;color:#666;margin:-4px 0 0;">
			<?php esc_html_e( 'Override the global cache expiry for this post (1–720 hours). Leave at 0 to inherit the site default.', 'xspeed' ); ?>
		</p>
		<?php
	}

	/**
	 * @param int      $post_id
	 * @param \WP_Post $post
	 */
	public static function handle_save( $post_id, $post ): void {
		// Skip the noise paths first — autosaves, revisions, ajax,
		// REST writes (REST has its own auth via register_post_meta).
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return; // block editor goes through register_post_meta + the REST auth_callback.
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return; // meta box wasn't rendered on this save (quick edit, bulk edit, etc.).
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, Cache_Rules::supported_post_types(), true ) ) {
			return;
		}

		$no_cache = ! empty( $_POST['xspeed_no_cache'] );
		if ( $no_cache ) {
			update_post_meta( $post_id, Cache_Rules::META_NO_CACHE, true );
		} else {
			delete_post_meta( $post_id, Cache_Rules::META_NO_CACHE );
		}

		$expiry = isset( $_POST['xspeed_expiry_hours'] ) ? (int) $_POST['xspeed_expiry_hours'] : 0;
		$expiry = max( 0, min( 720, $expiry ) );
		if ( $expiry > 0 ) {
			update_post_meta( $post_id, Cache_Rules::META_EXPIRY_HOURS, $expiry );
		} else {
			delete_post_meta( $post_id, Cache_Rules::META_EXPIRY_HOURS );
		}
	}
}
