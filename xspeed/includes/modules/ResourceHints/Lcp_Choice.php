<?php
/**
 * The main (LCP) image chosen by hand: per page, and by site-wide rule (#703).
 *
 * The automatic pick reads the served HTML and can pick the wrong image. A
 * page's editor can name the right one in the block editor sidebar or the
 * classic meta box, and the Resource Hints settings hold rules for templates
 * no editor covers (home, archives, shop). A page's choice beats a rule, and
 * both beat the automatic pick.
 *
 * This class only resolves what was chosen. Resource_Hints_Processor applies
 * it to the page HTML through the `lcp_choice` option.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\ResourceHints;

defined( 'ABSPATH' ) || exit;

final class Lcp_Choice {

	/** Post meta holding a page's choice: '', 'featured', 'none' or an image URL. */
	public const META = '_xspeed_lcp_image';

	public const NONCE_ACTION = 'xspeed_lcp_image';
	public const NONCE_FIELD  = 'xspeed_lcp_image_nonce';

	/** Page types a rule can name. Anything starting with `/` is a URL path pattern. */
	public const PAGE_TYPES = array( 'front_page', 'blog', 'singular', 'page', 'post', 'archive', 'category', 'tag', 'author', 'search', '404', 'shop' );

	/** Longest URL accepted, so a pasted data: blob can't land in post meta. */
	private const MAX_URL = 2048;

	/**
	 * Hooks that run everywhere: the meta registration (the block editor saves
	 * through REST), the editor UI, and the purge when a choice changes.
	 */
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'handle_save' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta_change' ), 10, 3 );
	}

	/** @return string[] Post types that show the choice. Same set as the per-page cache rules. */
	public static function post_types(): array {
		return \XSpeed\Cache_Rules::supported_post_types();
	}

	public static function register_meta(): void {
		foreach ( self::post_types() as $type ) {
			register_post_meta(
				$type,
				self::META,
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => array( __CLASS__, 'sanitize_value' ),
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/**
	 * A stored choice: '' (automatic), 'featured', 'none', or an absolute or
	 * site-relative image URL. Anything else reads as automatic.
	 *
	 * @param mixed $value Raw input.
	 */
	public static function sanitize_value( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( in_array( $value, array( 'featured', 'none' ), true ) ) {
			return $value;
		}
		if ( '' === $value || strlen( $value ) > self::MAX_URL ) {
			return '';
		}
		$is_absolute = (bool) preg_match( '#^https?://#i', $value );
		$is_relative = '/' === $value[0] && ( strlen( $value ) < 2 || '/' !== $value[1] );
		if ( ! $is_absolute && ! $is_relative ) {
			return '';
		}
		return (string) esc_url_raw( $value );
	}

	/**
	 * Parse the "Main image rules" lines: `<where> | <image>`.
	 *
	 * `<where>` is a page type from PAGE_TYPES, `post_type:<slug>`, or a URL
	 * path starting with `/` where `*` matches anything. `<image>` is an image
	 * URL, `featured`, `none`, or text the <img> tag contains (a file name or
	 * class). Lines that don't parse are skipped.
	 *
	 * @param mixed $lines The stored list.
	 * @return array<int,array{where:string,image:string}>
	 */
	public static function parse_rules( $lines ): array {
		$rules = array();
		foreach ( (array) $lines as $line ) {
			if ( ! is_string( $line ) || false === strpos( $line, '|' ) ) {
				continue;
			}
			[ $where, $image ] = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( '' === $where || '' === $image ) {
				continue;
			}
			if ( '/' !== $where[0] ) {
				$where = strtolower( $where );
			}
			if ( '/' !== $where[0] && ! in_array( $where, self::PAGE_TYPES, true ) && ! preg_match( '#^post_type:[a-z0-9_-]+$#', $where ) ) {
				continue;
			}
			$rules[] = array(
				'where' => $where,
				'image' => $image,
			);
		}
		return $rules;
	}

	/**
	 * Whether a rule's `<where>` names the page being served.
	 *
	 * @param string                                  $where   From parse_rules().
	 * @param array{types:string[],path:string}       $context From current_context().
	 */
	public static function rule_matches( string $where, array $context ): bool {
		if ( '/' === $where[0] ) {
			$path = (string) ( $context['path'] ?? '' );
			if ( '' === $path ) {
				return false;
			}
			$re = '#^' . str_replace( '\*', '.*', preg_quote( untrailingslashit( $where ), '#' ) ) . '/?$#i';
			return (bool) preg_match( $re, $path );
		}
		return in_array( $where, (array) ( $context['types'] ?? array() ), true );
	}

	/**
	 * Page facts the rules match against, read from the main query. Call it
	 * once the query has run (template_redirect or later).
	 *
	 * @return array{types:string[],path:string}
	 */
	public static function current_context(): array {
		$types = array();
		$checks = array(
			'front_page' => 'is_front_page',
			'blog'       => 'is_home',
			'singular'   => 'is_singular',
			'page'       => 'is_page',
			'post'       => 'is_single',
			'archive'    => 'is_archive',
			'category'   => 'is_category',
			'tag'        => 'is_tag',
			'author'     => 'is_author',
			'search'     => 'is_search',
			'404'        => 'is_404',
			'shop'       => 'is_shop',
		);
		foreach ( $checks as $type => $fn ) {
			if ( function_exists( $fn ) && $fn() ) {
				$types[] = $type;
			}
		}
		// is_single() is true for every non-page post type; `post` means posts.
		if ( in_array( 'post', $types, true ) && 'post' !== get_post_type( (int) get_queried_object_id() ) ) {
			$types = array_values( array_diff( $types, array( 'post' ) ) );
		}
		if ( is_singular() ) {
			$types[] = 'post_type:' . (string) get_post_type( (int) get_queried_object_id() );
		} elseif ( is_post_type_archive() ) {
			foreach ( (array) get_query_var( 'post_type' ) as $pt ) {
				$types[] = 'post_type:' . (string) $pt;
			}
		}

		$path = '';
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$path = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
			// Rules are written from the site root, so a site in a subfolder
			// drops its folder first.
			$home = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
			if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
				$path = substr( $path, strlen( $home ) );
			}
		}
		return array(
			'types' => $types,
			'path'  => rawurldecode( $path ),
		);
	}

	/**
	 * What the processor gets as `lcp_choice` for the page being served, or
	 * null to leave the automatic pick in charge.
	 *
	 * @param array<int,string>                  $rules   The stored rule lines.
	 * @param int|null                           $post_id The singular post, if any.
	 * @param array{types:string[],path:string}  $context Page facts.
	 * @return array<string,mixed>|null
	 */
	public static function resolve( array $rules, ?int $post_id, array $context ): ?array {
		if ( null !== $post_id && $post_id > 0 ) {
			$stored = self::sanitize_value( get_post_meta( $post_id, self::META, true ) );
			if ( '' !== $stored ) {
				$choice = self::choice_for( $stored, $post_id );
				if ( null !== $choice ) {
					return array( 'source' => 'page' ) + $choice;
				}
			}
		}
		foreach ( self::parse_rules( $rules ) as $rule ) {
			if ( ! self::rule_matches( $rule['where'], $context ) ) {
				continue;
			}
			$choice = self::choice_for( $rule['image'], $post_id, true );
			if ( null !== $choice ) {
				return array( 'source' => 'rule' ) + $choice;
			}
		}
		return null;
	}

	/**
	 * Turn one stored value into the processor's terms.
	 *
	 * @param bool $allow_pattern A rule may name text the tag contains.
	 * @return array<string,mixed>|null
	 */
	private static function choice_for( string $value, ?int $post_id, bool $allow_pattern = false ): ?array {
		if ( 'none' === $value ) {
			return array( 'none' => true );
		}
		if ( 'featured' === $value ) {
			$thumb = null !== $post_id && $post_id > 0 ? (int) get_post_thumbnail_id( $post_id ) : 0;
			$urls  = $thumb > 0 ? self::attachment_urls( $thumb ) : array();
			// No featured image, or one the page doesn't show: the automatic
			// pick runs, rather than preloading a file nothing paints.
			return empty( $urls ) ? null : array( 'urls' => $urls );
		}
		$url = self::sanitize_value( $value );
		if ( '' !== $url ) {
			return array(
				'urls'     => array( $url ),
				'link_url' => $url,
			);
		}
		return $allow_pattern ? array( 'pattern' => $value ) : null;
	}

	/**
	 * Every size of an attachment, full first: a theme prints whichever size
	 * it likes, and the full file may not be in the srcset.
	 *
	 * @return string[]
	 */
	private static function attachment_urls( int $id ): array {
		$urls = array();
		$full = wp_get_attachment_url( $id );
		if ( is_string( $full ) && '' !== $full ) {
			$urls[] = $full;
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) && '' !== $full ) {
			$base = trailingslashit( dirname( (string) $full ) );
			foreach ( $meta['sizes'] as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$urls[] = $base . (string) $size['file'];
				}
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$urls[] = $base . (string) $meta['original_image'];
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Images a page's editor can pick from: those in its content, then the
	 * featured image. Used by the classic meta box; the block editor reads
	 * the same from its own state.
	 *
	 * @return array<int,string> Image URLs.
	 */
	public static function content_images( \WP_Post $post ): array {
		$urls = array();
		if ( preg_match_all( '#<img\b[^>]*?\ssrc=(["\'])([^"\']+)\1#i', (string) $post->post_content, $m ) ) {
			foreach ( $m[2] as $src ) {
				$src = self::sanitize_value( html_entity_decode( $src, ENT_QUOTES ) );
				if ( '' !== $src ) {
					$urls[] = $src;
				}
			}
		}
		$thumb = (int) get_post_thumbnail_id( $post->ID );
		if ( $thumb > 0 ) {
			$full = wp_get_attachment_url( $thumb );
			if ( is_string( $full ) && '' !== $full ) {
				$urls[] = $full;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/** The choice only does something while "Preload the main image" is on. */
	public static function ui_enabled(): bool {
		$opts = \XSpeed\Settings_Manager::get( ResourceHintsModule::SLUG );
		return is_array( $opts ) && ! empty( $opts['enabled'] ) && ! empty( $opts['lcp_preload'] );
	}

	public static function register_meta_box(): void {
		if ( ! self::ui_enabled() ) {
			return;
		}
		foreach ( self::post_types() as $type ) {
			add_meta_box(
				'xspeed-lcp-image',
				__( 'xSpeed · Main (LCP) image', 'xspeed' ),
				array( __CLASS__, 'render_meta_box' ),
				$type,
				'side',
				'default',
				// The block editor has its own sidebar panel.
				array( '__back_compat_meta_box' => true )
			);
		}
	}

	public static function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$stored  = self::sanitize_value( get_post_meta( $post->ID, self::META, true ) );
		$images  = self::content_images( $post );
		$is_url  = ! in_array( $stored, array( '', 'featured', 'none' ), true );
		$listed  = $is_url && in_array( $stored, $images, true );
		$mode    = $is_url ? 'image' : ( '' === $stored ? 'auto' : $stored );
		$options = array(
			'auto'     => __( 'Auto (recommended)', 'xspeed' ),
			'featured' => __( 'Featured image', 'xspeed' ),
			'image'    => __( 'Choose an image…', 'xspeed' ),
			'none'     => __( 'None', 'xspeed' ),
		);
		?>
		<p>
			<label for="xspeed_lcp_mode"><?php esc_html_e( 'Main image to load first', 'xspeed' ); ?></label>
			<select id="xspeed_lcp_mode" name="xspeed_lcp_mode" style="width:100%;">
				<?php foreach ( $options as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $mode, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<div id="xspeed_lcp_pick">
			<?php if ( ! empty( $images ) ) : ?>
				<p>
					<label for="xspeed_lcp_listed"><?php esc_html_e( 'Image on this page', 'xspeed' ); ?></label>
					<select id="xspeed_lcp_listed" name="xspeed_lcp_listed" style="width:100%;">
						<option value=""><?php esc_html_e( 'Use the URL below', 'xspeed' ); ?></option>
						<?php foreach ( $images as $src ) : ?>
							<option value="<?php echo esc_attr( $src ); ?>" <?php selected( $listed && $stored === $src ); ?>><?php echo esc_html( wp_basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php endif; ?>
			<p>
				<label for="xspeed_lcp_url"><?php esc_html_e( 'Or an image URL', 'xspeed' ); ?></label>
				<input type="url" id="xspeed_lcp_url" name="xspeed_lcp_url" value="<?php echo esc_attr( $is_url && ! $listed ? $stored : '' ); ?>" placeholder="https://" style="width:100%;" />
			</p>
		</div>
		<p style="font-size:12px;color:#666;margin:6px 0 0;">
			<?php esc_html_e( 'The image picked here is fetched first and gets high priority. Use a URL for a hero set as a CSS background. None turns off the main-image preload on this page.', 'xspeed' ); ?>
		</p>
		<?php
	}

	/**
	 * Classic editor save. The block editor writes the meta through REST.
	 *
	 * @param int      $post_id
	 * @param \WP_Post $post
	 */
	public static function handle_save( $post_id, $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return;
		}
		$mode  = isset( $_POST['xspeed_lcp_mode'] ) ? sanitize_key( wp_unslash( $_POST['xspeed_lcp_mode'] ) ) : 'auto';
		$value = '';
		if ( 'featured' === $mode || 'none' === $mode ) {
			$value = $mode;
		} elseif ( 'image' === $mode ) {
			$listed = isset( $_POST['xspeed_lcp_listed'] ) ? self::sanitize_value( esc_url_raw( wp_unslash( $_POST['xspeed_lcp_listed'] ) ) ) : '';
			$typed  = isset( $_POST['xspeed_lcp_url'] ) ? self::sanitize_value( esc_url_raw( wp_unslash( $_POST['xspeed_lcp_url'] ) ) ) : '';
			$value  = '' !== $typed ? $typed : $listed;
		}
		self::store( (int) $post_id, $value );
	}

	/** Write a choice; an automatic one removes the meta row. */
	public static function store( int $post_id, string $value ): void {
		$value = self::sanitize_value( $value );
		if ( '' === $value ) {
			delete_post_meta( $post_id, self::META );
			return;
		}
		update_post_meta( $post_id, self::META, $value );
	}

	public static function enqueue_editor(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! self::ui_enabled() || ! $screen || ! in_array( (string) $screen->post_type, self::post_types(), true ) ) {
			return;
		}
		wp_enqueue_script(
			'xspeed-editor-lcp',
			plugins_url( 'assets/editor-lcp.js', XSPEED_FILE ),
			array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-i18n' ),
			defined( 'XSPEED_VERSION' ) ? XSPEED_VERSION : false,
			true
		);
		wp_set_script_translations( 'xspeed-editor-lcp', 'xspeed', XSPEED_DIR . 'languages' );
	}

	/**
	 * A changed choice changes the cached page, so that page is purged. Only
	 * its own URL: the choice affects no other page.
	 *
	 * @param int|int[] $meta_ids
	 * @param int       $post_id
	 * @param string    $meta_key
	 */
	public static function on_meta_change( $meta_ids, $post_id, $meta_key ): void {
		if ( self::META !== $meta_key || ! class_exists( '\XSpeed\Cache' ) ) {
			return;
		}
		if ( 'publish' !== get_post_status( (int) $post_id ) ) {
			return;
		}
		$url = get_permalink( (int) $post_id );
		if ( is_string( $url ) && '' !== $url ) {
			\XSpeed\Cache::purge_url( $url, 'lcp_choice' );
		}
	}
}
