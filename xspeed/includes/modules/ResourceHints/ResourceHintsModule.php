<?php
/**
 * Resource Hints module — LCP-image preload + preconnect.
 *
 * NB: distinct from the Preloader module (crawler / cache warming). This one
 * rewrites a page's HTML with browser resource hints (preload, preconnect);
 * the Preloader warms the server-side page cache. Different layer, different
 * metric — this moves LCP/FCP, the crawler moves TTFB. See docs/notes.
 *
 * The single biggest lever on Largest Contentful Paint is telling the
 * browser to fetch the hero image immediately, in the <head>, instead of
 * waiting for CSS + layout to discover it (and past any loading="lazy" the
 * theme set). WP Rocket's LCP edge in the GTmetrix comparison came entirely
 * from this. Page-builder heroes (Elementor etc.) are rendered outside
 * the_content, so the Lazy module's eager-first-N never sees them — this
 * module works on the full page buffer instead.
 *
 * Two Free behaviors (FEATURES.md rows 115 basic preload, 125/356 preconnect):
 *   - LCP image preload: <link rel="preload" as="image" fetchpriority="high">
 *     for the first above-the-fold <img>, plus fetchpriority="high" on the tag.
 *   - Preconnect: <link rel="preconnect"> for detected font hosts + a user list.
 *
 * Runs on the cache-write path via the xspeed_cache_final_html filter so the
 * hints are baked into the cached HTML and replayed on every HIT (a wp_head
 * hook would never fire on a HIT — the drop-in short-circuits before PHP).
 * When page caching is OFF it buffers the page itself at template_redirect.
 *
 * LCP detection sees through JS-lazy heroes (real URL in data-src) and skips
 * logos/icons via a size + marker gate, so the preload targets the actual
 * hero rather than the first plain <img> in the DOM. Format-negotiating layers
 * (e.g. Pro's Images module, which wraps the LCP <img> in a <picture> with a
 * WebP/AVIF <source>) coordinate through the `xspeed_lcp_preload_url` /
 * `xspeed_lcp_preload_srcset` / `xspeed_lcp_preload_type` filters so the
 * high-priority preload points at the format actually served. (FBS-83553)
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\ResourceHints;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;
use XSpeed\Resource_Hints_Processor;
use XSpeed\Settings_Manager;

final class ResourceHintsModule extends Module {

	public const SLUG    = 'resource-hints';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.2.0';

	/** Guards the cache-off buffer so it processes exactly once per request. */
	private static $buffering = false;

	/**
	 * The main image chosen for the page being served (#703), resolved once
	 * the main query has run. False until then; null when nothing was chosen.
	 *
	 * @var array<string,mixed>|null|false
	 */
	private $lcp_choice = false;

	public function ui_metadata(): array {
		return array(
			'label'        => __( 'Resource Hints', 'xspeed' ),
			'tab_label'    => __( 'Hints', 'xspeed' ), // its own tab on the Resource Hints page
			'icon'         => 'Zap',
			'description'  => __( 'Tells the browser to fetch the main image and fonts early.', 'xspeed' ),
			'group'        => 'performance',
			// Host page: Hints (this module) + a Speculation Rules section
			// (SmartPredict, Pro). SmartPredict prefetches the next page in
			// the visitor's browser — same family as preload/preconnect, so
			// it belongs here, not under AI (FBS-83633).
			'custom_panel' => 'ResourceHintsPanel',
		);
	}

	public function settings_schema(): array {
		return array(
			'enabled'          => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => __( 'Enable resource hints', 'xspeed' ),
				'description' => __( 'Turns on all the hints below. They are safe on every theme, so this is on by default.', 'xspeed' ),
			),
			'lcp_preload'      => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => __( 'Preload the main image', 'xspeed' ),
				'description' => __( 'Finds the largest image at the top of the page and tells the browser to fetch it first. This usually gives the biggest LCP gain.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'lcp_image_count'  => array(
				'type'        => 'int',
				'default'     => 1,
				'min'         => 0,
				'max'         => 3,
				'label'       => __( 'Images to preload', 'xspeed' ),
				'description' => __( 'How many images at the top of the page to preload. 1 suits most sites; raise it only if the top shows a small gallery.', 'xspeed' ),
				'advanced'    => true,
				'dependsOn'   => array( 'field' => 'lcp_preload' ),
			),
			'lcp_exclusions'   => array(
				'type'        => 'list',
				'default'     => array(),
				'item_type'   => 'string',
				'label'       => __( 'Excluded from preload', 'xspeed' ),
				'description' => __( 'Images whose tag contains a line here, such as a file name or class, are never picked as the main image. Useful for tracking pixels and spacers.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'lcp_preload' ),
			),
			'lcp_rules'        => array(
				'type'        => 'list',
				'default'     => array(),
				'item_type'   => 'string',
				'label'       => __( 'Main image rules', 'xspeed' ),
				'description' => __( 'Pick the main image on pages the editor does not cover, one rule per line: where | image. Where is a page type (front_page, blog, archive, category, tag, author, search, 404, shop, singular, page, post, post_type:product) or a path such as /shop/*. Image is an image URL, featured, none, or a file name or class the image tag contains. The first matching rule wins; a choice made on the page itself beats every rule.', 'xspeed' ),
				'advanced'    => true,
				'dependsOn'   => array( 'field' => 'lcp_preload' ),
			),
			'preload_images'   => array(
				'type'        => 'list',
				'default'     => array(),
				'item_type'   => 'string',
				'label'       => __( 'Always preload these images', 'xspeed' ),
				'description' => __( 'Image URLs to fetch first on every page, such as a hero background set in CSS. Keep it to one or two; only the first three are used.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'preconnect'       => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => __( 'Connect early to Google Fonts', 'xspeed' ),
				'description' => __( 'When the page uses Google Fonts, the browser connects to Google\'s servers early, so fonts arrive sooner.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'preconnect_hosts' => array(
				'type'        => 'list',
				'default'     => array(),
				'item_type'   => 'url',
				'label'       => __( 'Other domains to connect early', 'xspeed' ),
				'description' => __( 'One address per line, such as https://cdn.example.com. Use for a CDN or other domain that serves files at the top of the page.', 'xspeed' ),
				'advanced'    => true,
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
		);
	}

	public function boot(): void {
		// The per-page choice is edited in wp-admin and saved through REST,
		// so its hooks run outside the front-end gate below. (#703)
		Lcp_Choice::boot();

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
		// Frontend page renders only. Admin / feed / cron / AJAX / REST never
		// produce an HTML document we should rewrite.
		if ( is_admin()
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			// Builder editing screens are front-end URLs; injecting hints into
			// the editor document helps nobody and can preload the wrong
			// assets. (#281)
			|| \XSpeed\Builder_Editor::is_active()
		) {
			return;
		}

		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) ) {
			return;
		}

		$any = ! empty( $opts['lcp_preload'] ) || ! empty( $opts['preconnect'] ) || ! empty( $opts['preconnect_hosts'] );
		if ( ! $any ) {
			return;
		}

		// The main image chosen for this page needs the main query, which
		// is ready by template_redirect. Resolved here rather than in the
		// output callback, which runs at shutdown.
		if ( ! empty( $opts['lcp_preload'] ) ) {
			add_action( 'template_redirect', array( $this, 'resolve_lcp_choice' ), 1 );
		}

		// Cache-write path: transform the HTML just before it is minified and
		// written to the cache file, so hints are baked in and survive HITs.
		add_filter(
			'xspeed_cache_final_html',
			function ( $html ) {
				return Resource_Hints_Processor::process( (string) $html, $this->processor_opts() );
			},
			10,
			1
		);

		// Cache-off path: the cache filter above never fires, so buffer the
		// page ourselves. Guarded so we don't double-buffer when the cache
		// engine is also running (its filter handles that case).
		if ( ! $this->cache_enabled() ) {
			add_action(
				'template_redirect',
				function () {
					if ( self::$buffering ) {
						return;
					}
					self::$buffering = true;
					ob_start(
						function ( $buffer ) {
							if ( strlen( (string) $buffer ) < 255 ) {
								return $buffer;
							}
							return Resource_Hints_Processor::process( (string) $buffer, $this->processor_opts() );
						}
					);
				},
				9
			);
		}
	}

	/**
	 * Is the page cache turned on? When it is, Cache::finalize_buffer runs and
	 * our xspeed_cache_final_html filter fires — so we must NOT also ob_start.
	 */
	private function cache_enabled(): bool {
		$legacy = Settings_Manager::get( 'legacy' );
		if ( is_array( $legacy ) && ! empty( $legacy['cache_enabled'] ) ) {
			return true;
		}
		$opts = get_option( 'xspeed_options' );
		return is_array( $opts ) && ! empty( $opts['cache_enabled'] );
	}

	/**
	 * Settings passed to the full-page processor: this module's own settings,
	 * plus the Lazy module's `excluded_images` list. The processor runs over the
	 * WHOLE page (via xspeed_cache_final_html), so it can reach a theme/builder
	 * hero rendered OUTSIDE the_content — which the Lazy module's the_content-
	 * scoped filters can't. Surfacing the exclusions here lets the processor
	 * strip core's loading="lazy" + set fetchpriority=high on those heroes so an
	 * excluded above-the-fold image actually loads eagerly no matter where the
	 * theme printed it. (FBS-83553 H2)
	 */
	private function processor_opts(): array {
		$opts = $this->get_settings();
		$opts['cdn_context'] = \XSpeed\Modules\Cdn\CdnModule::rewrite_context();
		if ( class_exists( '\XSpeed\Settings_Manager' ) ) {
			$lazy = Settings_Manager::get( 'lazy' );
			if ( is_array( $lazy ) && ! empty( $lazy['lazy_images'] ) && ! empty( $lazy['excluded_images'] ) && is_array( $lazy['excluded_images'] ) ) {
				$opts['eager_excluded_images'] = array_values( array_filter( array_map( 'strval', $lazy['excluded_images'] ) ) );
			}
		}
		$opts['page_url'] = self::current_page_url();
		if ( is_array( $this->lcp_choice ) ) {
			$opts['lcp_choice'] = $this->lcp_choice;
		}
		return $opts;
	}

	/**
	 * Resolve the main image chosen for the page being served: the page's
	 * own choice, else the first matching rule (#703).
	 */
	public function resolve_lcp_choice(): void {
		$opts             = $this->get_settings();
		$rules            = array_values( array_filter( array_map( 'strval', (array) ( $opts['lcp_rules'] ?? array() ) ) ) );
		$post_id          = \XSpeed\Cache_Rules::current_post_id();
		$singular         = null !== $post_id && in_array( (string) get_post_type( $post_id ), Lcp_Choice::post_types(), true );
		$this->lcp_choice = ( $singular || ! empty( $rules ) )
			? Lcp_Choice::resolve( $rules, $singular ? $post_id : null, empty( $rules ) ? array() : Lcp_Choice::current_context() )
			: null;
	}

	public function migrations(): array {
		return array(
			// Main image rules (#703). Defaults merge on read too; this keeps
			// the stored row complete for export.
			'1.2.0' => static function ( array $stored ): array {
				return $stored + array( 'lcp_rules' => array() );
			},
		);
	}

	/**
	 * The URL of the page being served, for the LCP preload candidate seam.
	 * Empty outside a request (CLI, tests).
	 *
	 * The site's scheme and host with the request path as the browser sent
	 * it. Not home_url( REQUEST_URI ): on a site in a subfolder both carry the
	 * folder, which then appeared twice. And not sanitize_text_field(), which
	 * drops percent-encoded octets, so `/caf%C3%A9/` lost its é.
	 */
	private static function current_page_url(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! function_exists( 'home_url' ) ) {
			return '';
		}
		$uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		if ( '' === $uri || '/' !== $uri[0] ) {
			return '';
		}
		$home   = wp_parse_url( home_url( '/' ) );
		$scheme = isset( $home['scheme'] ) ? $home['scheme'] : 'https';
		$host   = isset( $home['host'] ) ? $home['host'] : '';
		$port   = isset( $home['port'] ) ? ':' . $home['port'] : '';
		return '' === $host ? '' : $scheme . '://' . $host . $port . $uri;
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed resource-hints',
				'risk'      => array( '' => 'read', 'status' => 'read', 'lcp-get' => 'read', 'lcp-set' => 'write' ),
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Show resource-hint settings, or read/set the main (LCP) image chosen for a post.',
				'ai_hint'   => 'Browser resource hints — preload, prefetch, preconnect — for the resources that block first paint. Use for LCP problems or "preconnect to required origins" in PageSpeed. Not the same as the Preloader, which warms the page cache.',
				'synopsis'  => array(
					array(
						'type'        => 'positional',
						'name'        => 'action',
						'description' => 'status (default), lcp-get <post-id>, or lcp-set <post-id> <auto|featured|none|image-url>.',
						'optional'    => true,
					),
					array(
						'type'        => 'positional',
						'name'        => 'post',
						'description' => 'Post ID, for lcp-get and lcp-set.',
						'optional'    => true,
					),
					array(
						'type'        => 'positional',
						'name'        => 'value',
						'description' => 'For lcp-set: auto, featured, none, or an image URL.',
						'optional'    => true,
					),
				),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$action = isset( $args[0] ) ? (string) $args[0] : 'status';
		if ( 'lcp-get' === $action || 'lcp-set' === $action ) {
			$this->cli_lcp( $action, $args );
			return;
		}
		if ( 'status' !== $action ) {
			\WP_CLI::error( sprintf( 'Unknown action "%s". Use status, lcp-get or lcp-set.', $action ) );
			return;
		}
		$opts = $this->get_settings();
		\WP_CLI::log( sprintf( '%-20s %s', 'enabled', ! empty( $opts['enabled'] ) ? 'on' : 'off' ) );
		\WP_CLI::log( sprintf( '%-20s %s', 'lcp_preload', ! empty( $opts['lcp_preload'] ) ? 'on' : 'off' ) );
		\WP_CLI::log( sprintf( '%-20s %d', 'lcp_image_count', (int) ( $opts['lcp_image_count'] ?? 1 ) ) );
		\WP_CLI::log( sprintf( '%-20s %d pattern(s)', 'lcp_exclusions', count( (array) ( $opts['lcp_exclusions'] ?? array() ) ) ) );
		\WP_CLI::log( sprintf( '%-20s %d rule(s)', 'lcp_rules', count( Lcp_Choice::parse_rules( $opts['lcp_rules'] ?? array() ) ) ) );
		\WP_CLI::log( sprintf( '%-20s %d url(s)', 'preload_images', count( (array) ( $opts['preload_images'] ?? array() ) ) ) );
		\WP_CLI::log( sprintf( '%-20s %s', 'preconnect', ! empty( $opts['preconnect'] ) ? 'on' : 'off' ) );
		\WP_CLI::log( sprintf( '%-20s %d host(s)', 'preconnect_hosts', count( (array) ( $opts['preconnect_hosts'] ?? array() ) ) ) );
	}

	/**
	 * `resource-hints lcp-get <post>` / `lcp-set <post> <value>`: the main
	 * image chosen for one post (#703). Setting it purges that post's page.
	 *
	 * @param array<int,string> $args Positional arguments.
	 */
	private function cli_lcp( string $action, array $args ): void {
		$post_id = isset( $args[1] ) ? (int) $args[1] : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			\WP_CLI::error( 'Pass a post ID, e.g. `wp xspeed resource-hints lcp-get 42`.' );
			return;
		}
		if ( ! in_array( $post->post_type, Lcp_Choice::post_types(), true ) ) {
			\WP_CLI::error( sprintf( 'Post type "%s" has no main image choice.', $post->post_type ) );
			return;
		}
		if ( 'lcp-set' === $action ) {
			$raw   = isset( $args[2] ) ? trim( (string) $args[2] ) : '';
			$value = 'auto' === $raw ? '' : Lcp_Choice::sanitize_value( $raw );
			if ( '' === $raw || ( 'auto' !== $raw && '' === $value ) ) {
				\WP_CLI::error( 'Value must be auto, featured, none, or an image URL (https://… or /…).' );
				return;
			}
			Lcp_Choice::store( $post_id, $value );
		}
		$stored = Lcp_Choice::sanitize_value( get_post_meta( $post_id, Lcp_Choice::META, true ) );
		\WP_CLI::log( sprintf( '%-12s %d', 'post', $post_id ) );
		\WP_CLI::log( sprintf( '%-12s %s', 'main_image', '' === $stored ? 'auto' : $stored ) );
		if ( 'lcp-set' === $action ) {
			\WP_CLI::success( 'Saved. If the post is published, its cached page was purged.' );
		}
	}
}
