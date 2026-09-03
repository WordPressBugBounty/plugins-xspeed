<?php
/**
 * Lazy module — defers img / iframe / video loading via native browser
 * lazy-load attributes. Also auto-fills missing image dimensions to
 * prevent CLS.
 *
 * What WordPress core does already (since 5.5):
 *   - Adds loading="lazy" to the_content images.
 *
 * What this module adds:
 *   - First N images get loading="eager" so the LCP isn't deferred.
 *   - Adds decoding="async" (core doesn't).
 *   - Lazy-loads iframes (core's iframe lazy was reverted).
 *   - preload="none" on <video> (closest thing to native video lazy).
 *   - Auto-fills missing width / height attributes (best CLS win).
 *   - Excludes by substring patterns (src or class match) — useful for
 *     hero banner classes, logo files, etc.
 *
 * Tier: Free per FEATURES.md "Images" §1-6 (LiteSpeed parity — all
 * Free in LS Cache).
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Lazy;

defined( 'ABSPATH' ) || exit;

use XSpeed\Lazy_Loader;
use XSpeed\Module;

final class LazyModule extends Module {

	public const SLUG    = 'lazy';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	public function ui_metadata(): array {
		return array(
			'label'        => 'Media Optimization',
			'tab_label'    => 'Lazy Loading', // its own tab on the Media Optimization page
			'icon'         => 'Image',
			'description'  => 'Control how images, iframes, and videos load — lazy-loading, missing dimensions, and format optimization.',
			// Host page: Lazy Loading (this module) + Image Optimization (Pro)
			// + AI Suggestions (Pro) as tabs — everything a page loads on one
			// page instead of separate rows (FBS-83633). Fonts is NOT here: it
			// has its own Optimization card (#86).
			'custom_panel' => 'MediaPanel',
		);
	}

	public function settings_schema(): array {
		return array(
			'lazy_images'            => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => 'Lazy-load Images',
				'description' => 'Add loading="lazy" + decoding="async" to <img> tags in post content. The first images on the page get loading="eager" so the LCP image is not deferred.',
			),
			'lazy_iframes'           => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => 'Lazy-load Iframes',
				'description' => 'Add loading="lazy" to <iframe> tags. Useful for YouTube / Vimeo embeds + map widgets that pull a lot of bytes.',
			),
			'video_facade'           => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => 'Click-to-Play Video Facade',
				'description' => 'Replace YouTube and Vimeo embeds — and self-hosted <video> tags that have a poster — with the poster image and a play button. The video only loads when a visitor clicks it, so a page with embeds no longer pays ~1MB of third-party JavaScript, or the full weight of a hosted video file, for visitors who never press play. Autoplaying videos are left alone. Falls back to the normal embed when JavaScript is off.',
			),
			'lazy_videos'            => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => 'Lazy-load HTML5 Videos',
				'description' => 'Set preload="none" on self-hosted <video> tags, overriding a player\'s own preload="auto"/"metadata". Autoplaying videos are left alone — they need their bytes regardless. Browsers do not yet support loading="lazy" on video; preload="none" is the closest equivalent.',
			),
			'eager_first_n'          => array(
				'type'        => 'int',
				'default'     => 1,
				'min'         => 0,
				'max'         => 10,
				'label'       => 'Eager-load First N Images',
				'description' => 'How many images at the top of the post get loading="eager". 1 is usually right (the LCP hero image). 0 to lazy-load everything.',
			),
			'add_missing_dimensions' => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => 'Add Missing Image Dimensions',
				'description' => 'When an <img class="wp-image-N"> has no width/height, look the values up from the media library and inject them. Prevents the page-layout shift that hurts CLS scores.',
			),
			'excluded_images'        => array(
				'type'        => 'list',
				'default'     => array(),
				'item_type'   => 'string',
				'label'       => 'Excluded Images',
				'description' => 'Substring patterns that, if found anywhere in the <img> / <iframe> tag (typically a class or filename), exempt that element from lazy-loading. Useful for hero / logo / sprite images. Or add data-skip-lazy to the tag directly.',
			),
		);
	}

	public function conflicts(): array {
		return array(
			array(
				'plugin'   => 'wp-smushit/wp-smush.php',
				'feature'  => 'images.lazyload',
				'strategy' => \XSpeed\Conflict_Registry::STRATEGY_WARN,
				'reason'   => 'Smush also offers lazy-loading; running both can cause double-rewriting.',
			),
			array(
				'plugin'   => 'a3-lazy-load/a3-lazy-load.php',
				'feature'  => 'images.lazyload',
				'strategy' => \XSpeed\Conflict_Registry::STRATEGY_REFUSE,
				'reason'   => 'a3 Lazy Load is a dedicated lazy-load plugin; disable it before enabling xSpeed lazy-load.',
			),
		);
	}

	public function boot(): void {
		// Bail entirely on admin / feed / cron / REST — same scope as
		// Minifier. Lazy-loading rendered HTML only matters on real
		// frontend page renders.
		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Never lazy-load inside a builder editor: the builder measures and
		// positions elements it expects to be loaded. (#281)
		if ( \XSpeed\Builder_Editor::is_active() ) {
			return;
		}

		$opts = $this->get_settings();
		$any_enabled = ! empty( $opts['lazy_images'] )
			|| ! empty( $opts['lazy_iframes'] )
			|| ! empty( $opts['lazy_videos'] )
			|| ! empty( $opts['video_facade'] )
			|| ! empty( $opts['add_missing_dimensions'] );
		if ( ! $any_enabled ) {
			return;
		}

		// Reset the eager-load budget once per page render, before any
		// content filter runs, so the "first N images eager" budget is
		// shared across the featured image + content + avatars rather than
		// restarting on every filter pass. (FBS-82172 Bug 1)
		add_action( 'template_redirect', array( Lazy_Loader::class, 'reset_state' ) );

		// Late priority so the_content runs after every other filter
		// (shortcodes, do_blocks, embeds). Avoids rewriting tags that
		// haven't been generated yet.
		add_filter( 'the_content',          array( Lazy_Loader::class, 'process_html' ), 999 );
		add_filter( 'post_thumbnail_html',  array( Lazy_Loader::class, 'process_html' ), 999 );
		add_filter( 'get_avatar',           array( Lazy_Loader::class, 'process_html' ), 999 );
		add_filter( 'widget_text_content',  array( Lazy_Loader::class, 'process_html' ), 999 );

		// The facade's click handler is printed only on pages that actually
		// rendered a facade — a page with no embeds should not carry the
		// script that reveals them.
		if ( ! empty( $opts['video_facade'] ) ) {
			add_action( 'wp_footer', array( $this, 'print_facade_script' ), 99 );
			// The layout rule goes in the HEAD, unconditionally, while the
			// handler stays conditional in the footer. Whether a facade
			// renders isn't known until the content filter has run — long
			// after wp_head — and a layout rule that arrives in the footer
			// fixes the gap only after the visitor has already seen it.
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_facade_style' ) );
		}

		// Same conditional-footer treatment for the autoplay restorer: it is
		// only printed on a response that actually deferred one.
		if ( ! empty( $opts['lazy_videos'] ) ) {
			/*
			 * HEAD, not footer — and as early as anything can run.
			 *
			 * A page-builder video block creates its <video> from its own
			 * script. Ours has to be listening BEFORE that happens: printed
			 * in the footer it loaded after the block's script had already
			 * built the player and started the fetch, so the bytes were
			 * committed before we could defer them. Measured on a live page,
			 * our restorer sat ~17KB after the first block script in the
			 * document, and the videos still downloaded on load.
			 *
			 * It costs ~1KB inline and installs only observers, so running it
			 * early is cheap; a MutationObserver on documentElement catches
			 * every <video> the moment it is inserted, whichever script did
			 * the inserting.
			 */
			add_action( 'wp_head', array( $this, 'print_autoplay_script' ), 1 );
		}
	}

	/**
	 * Register the facade's layout rule as an inline style on a
	 * dependency-free handle — ~150 bytes, so a separate file would cost
	 * more than the CSS.
	 */
	public function enqueue_facade_style(): void {
		wp_register_style( 'xspeed-video-facade', false, array(), XSPEED_VERSION );
		wp_enqueue_style( 'xspeed-video-facade' );
		wp_add_inline_style( 'xspeed-video-facade', \XSpeed\Video_Facade::facade_style() );
	}

	/**
	 * Emit the click-to-play handler inline. Inline (not enqueued) because
	 * it is ~400 bytes — a separate request would cost more than the code.
	 */
	public function print_facade_script(): void {
		if ( ! Lazy_Loader::facade_used() ) {
			return;
		}

		wp_print_inline_script_tag( \XSpeed\Video_Facade::facade_script(), array( 'id' => 'xspeed-video-facade' ) );
	}

	/**
	 * Emit the viewport restorer for deferred AUTOPLAY videos.
	 *
	 * Separate from the facade script because the two are independent: a
	 * page can defer an autoplay hero without any click-to-play facade on
	 * it, and vice versa. Both are gated on having actually rewritten
	 * something, so a page with no video ships neither.
	 */
	public function print_autoplay_script(): void {
		/*
		 * Deliberately NOT gated on needs_autoplay_script().
		 *
		 * That flag is only meaningful after the content filter has run, and
		 * this prints in wp_head — long before. The facade script above can
		 * afford to be conditional because it only has to be present by the
		 * time a human clicks; this one has to be listening before another
		 * plugin's script builds a <video> and starts fetching it, which
		 * happens well before wp_footer.
		 *
		 * The cost of being unconditional is ~1KB inline on pages with no
		 * video, and the script installs observers only — it does no work
		 * and touches nothing when it finds no autoplay video. That is a
		 * better trade than missing the one case the feature exists for.
		 */
		wp_print_inline_script_tag( Lazy_Loader::autoplay_script(), array( 'id' => 'xspeed-lazy-autoplay' ) );
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed lazy',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Show which lazy-load toggles are active.',
				'ai_hint'   => 'Which lazy-loading and image optimizations are on (images, iframes, missing width/height)? Use for questions about images loading too early, layout shift (CLS), or offscreen images flagged by PageSpeed.',
				'synopsis'  => array(),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$opts = $this->get_settings();
		foreach ( $opts as $key => $value ) {
			$display = is_array( $value ) ? implode( ',', $value ) : ( $value ? 'on' : ( is_numeric( $value ) ? (string) $value : 'off' ) );
			if ( is_int( $value ) ) {
				$display = (string) $value;
			}
			\WP_CLI::log( sprintf( '%-30s %s', $key, $display ) );
		}
	}
}
