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
			'label'        => __( 'Media Optimization', 'xspeed' ),
			'tab_label'    => __( 'Lazy Loading', 'xspeed' ), // its own tab on the Media Optimization page
			'icon'         => 'Image',
			'description'  => __( 'Loads images, videos and embeds only when visitors scroll to them.', 'xspeed' ),
			'group'        => 'performance',
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
				'label'       => __( 'Lazy-load images', 'xspeed' ),
				'description' => __( 'Images load when the visitor scrolls near them. The first image on the page still loads right away.', 'xspeed' ),
			),
			'lazy_iframes'           => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => __( 'Lazy-load embeds', 'xspeed' ),
				'description' => __( 'Embedded content such as YouTube videos and maps loads when the visitor scrolls near it.', 'xspeed' ),
			),
			'video_facade'           => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Click-to-play videos', 'xspeed' ),
				'description' => __( 'Shows a preview image with a play button in place of YouTube, Vimeo and your own videos that have a poster image. The video loads only when clicked. Autoplaying videos are left alone.', 'xspeed' ),
			),
			'lazy_videos'            => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => __( 'Lazy-load your own videos', 'xspeed' ),
				'description' => __( 'Videos uploaded to your site download only when played. Autoplaying videos are left alone.', 'xspeed' ),
			),
			'lazy_background_images' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Lazy-load background images', 'xspeed' ),
				'description' => __( 'Hold back background images set in an element\'s inline style (a Cover or Group block, a page-builder section) until the element is near the screen. Uses a small script; visitors without JavaScript get every background as usual. The first N backgrounds on the page load straight away, like images. Backgrounds set in a stylesheet are not affected.', 'xspeed' ),
			),
			'eager_first_n'          => array(
				'type'        => 'int',
				'default'     => 1,
				'min'         => 0,
				'max'         => 10,
				'label'       => __( 'Images to load right away', 'xspeed' ),
				'description' => __( 'How many images at the top of the page skip lazy loading. Only the first of them also loads at high priority, and images in hidden or closed sections are not counted. Lazy-loaded background images use the same number. 1 suits most sites; 0 lazy-loads every image.', 'xspeed' ),
				'advanced'    => true,
				// Both counters read this number (class-lazy-loader.php), so it
				// shows while either kind of lazy loading is on.
				'dependsOn'   => array(
					'any' => array(
						array( 'field' => 'lazy_images' ),
						array( 'field' => 'lazy_background_images' ),
					),
				),
			),
			'add_missing_dimensions' => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => __( 'Add missing image sizes', 'xspeed' ),
				'description' => __( 'Adds width and height to images that lack them, using the media library. This stops the page from jumping as images load.', 'xspeed' ),
			),
			'excluded_images'        => array(
				'type'        => 'list',
				'default'     => array(),
				'item_type'   => 'string',
				'label'       => __( 'Excluded images', 'xspeed' ),
				'description' => __( 'Images and embeds whose tag contains a line here, such as a class or file name, are never lazy-loaded. Useful for logos and hero images.', 'xspeed' ),
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
		/*
		 * Deferred to `init`. This module reads its own settings to decide
		 * what to hook, and reading settings builds settings_schema(), whose
		 * labels are declared through __(). boot() runs on `plugins_loaded`,
		 * before `after_setup_theme` — the point WordPress 6.7+ treats as the
		 * earliest safe moment to translate — so doing that here fires
		 * _load_textdomain_just_in_time on every request AND resolves the
		 * labels against a domain that is not loaded yet.
		 *
		 * Everything below hooks actions that fire after `init`, so running
		 * one hook later is equivalent.
		 */
		add_action( 'init', array( $this, 'boot_on_init' ) );
	}

	/**
	 * The real boot body — see boot() for why it runs on `init`.
	 */
	public function boot_on_init(): void {
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
			|| ! empty( $opts['lazy_background_images'] )
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
			// Embeds that never touch the HTML: a builder widget builds its
			// YouTube iframe from script, so the buffer pass has nothing to
			// rewrite. Head, priority 1, for the same reason as the autoplay
			// restorer below — it must be listening before the widget's
			// script sets a src and commits the fetch.
			add_action( 'wp_head', array( $this, 'print_observer_script' ), 1 );
		}

		// Head, because the rule that holds a background back has to apply
		// before first paint, or the browser has already requested it.
		if ( ! empty( $opts['lazy_background_images'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_background_style' ) );
			add_action( 'wp_head', array( $this, 'print_background_script' ), 1 );
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

	public function enqueue_background_style(): void {
		wp_register_style( 'xspeed-lazy-bg', false, array(), XSPEED_VERSION );
		wp_enqueue_style( 'xspeed-lazy-bg' );
		wp_add_inline_style( 'xspeed-lazy-bg', Lazy_Loader::background_style() );
	}

	/**
	 * data-xs-nodelay: Delay JS would otherwise hold this back until the
	 * first interaction, and every held background would stay blank.
	 */
	public function print_background_script(): void {
		wp_print_inline_script_tag(
			Lazy_Loader::background_script(),
			array(
				'id'              => 'xspeed-lazy-bg',
				'data-xs-nodelay' => true,
			)
		);
	}

	/**
	 * Emit the click-to-play handler inline. Inline (not enqueued) because
	 * it is ~400 bytes — a separate request would cost more than the code.
	 */
	public function print_facade_script(): void {
		// No longer gated on facade_used(): the observer script can build a
		// facade for a JS-injected embed on a page where the server pass
		// rendered none, and a facade without its click handler is a play
		// button that plays nothing. ~400 bytes on facade-less pages is the
		// cost of never shipping that.
		wp_print_inline_script_tag( \XSpeed\Video_Facade::facade_script(), array( 'id' => 'xspeed-video-facade' ) );
	}

	/**
	 * Emit the interceptor for JS-injected embeds (see
	 * Video_Facade::observer_script() for the mechanism and why the
	 * footer is too late).
	 */
	public function print_observer_script(): void {
		wp_print_inline_script_tag( \XSpeed\Video_Facade::observer_script(), array( 'id' => 'xspeed-video-facade-observer' ) );
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

	/**
	 * Lazy has no master switch -- it is on when any of lazy_images /
	 * lazy_iframes / video_facade / lazy_videos is set. (#363)
	 */
	public function is_active(): ?bool {
		return $this->any_bool_flag_on();
	}
}
