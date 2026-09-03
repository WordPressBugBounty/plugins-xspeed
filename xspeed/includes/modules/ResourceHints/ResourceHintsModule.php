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
	public const VERSION = '1.0.0';

	/** Guards the cache-off buffer so it processes exactly once per request. */
	private static $buffering = false;

	public function ui_metadata(): array {
		return array(
			'label'        => 'Resource Hints',
			'tab_label'    => 'Hints', // its own tab on the Resource Hints page
			'icon'         => 'Zap',
			'description'  => 'Preload the LCP hero image and preconnect to font hosts so the largest element paints sooner.',
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
				'label'       => 'Enable Resource Hints',
				'description' => 'Master switch for the LCP-image preload + preconnect resource hints. On by default — these are safe, no-config optimizations that help every theme.',
			),
			'lcp_preload'      => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => 'Preload LCP Image',
				'description' => 'Detect the largest above-the-fold image and emit a <link rel="preload" as="image" fetchpriority="high"> in the head, plus fetchpriority="high" on the image. This is the highest-impact fix for Largest Contentful Paint — it beats any lazy-load the theme applied.',
			),
			'lcp_image_count'  => array(
				'type'        => 'int',
				'default'     => 1,
				'min'         => 0,
				'max'         => 3,
				'label'       => 'Images to Preload',
				'description' => 'How many of the first images on the page to preload. 1 is right for most sites (the single hero). Raise it only if the fold shows a small gallery.',
			),
			'lcp_exclusions'   => array(
				'type'        => 'list',
				'default'     => array(),
				'item_type'   => 'string',
				'label'       => 'Exclude From Preload',
				'description' => 'Substring patterns (filename or class) that, if found in an <img>, exempt it from being treated as the LCP image. Useful for tracking pixels, spacers, or a decorative first image that is not the hero.',
			),
			'preconnect'       => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => 'Preconnect to Font Hosts',
				'description' => 'When Google Fonts are detected, emit <link rel="preconnect"> to fonts.googleapis.com and fonts.gstatic.com so the DNS + TLS handshake happens ahead of the font request instead of on the critical path.',
			),
			'preconnect_hosts' => array(
				'type'        => 'list',
				'default'     => array(),
				'item_type'   => 'url',
				'label'       => 'Extra Preconnect Hosts',
				'description' => 'One origin per line (e.g. https://cdn.example.com) to preconnect in addition to the auto-detected font hosts. Use for a CDN or third-party origin that serves above-the-fold assets.',
			),
		);
	}

	public function boot(): void {
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
		if ( class_exists( '\XSpeed\Settings_Manager' ) ) {
			$lazy = Settings_Manager::get( 'lazy' );
			if ( is_array( $lazy ) && ! empty( $lazy['lazy_images'] ) && ! empty( $lazy['excluded_images'] ) && is_array( $lazy['excluded_images'] ) ) {
				$opts['eager_excluded_images'] = array_values( array_filter( array_map( 'strval', $lazy['excluded_images'] ) ) );
			}
		}
		return $opts;
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed resource-hints',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Show LCP-preload / preconnect resource-hint settings.',
				'ai_hint'   => 'Browser resource hints — preload, prefetch, preconnect — for the resources that block first paint. Use for LCP problems or "preconnect to required origins" in PageSpeed. Not the same as the Preloader, which warms the page cache.',
				'synopsis'  => array(),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$opts = $this->get_settings();
		\WP_CLI::log( sprintf( '%-20s %s', 'enabled', ! empty( $opts['enabled'] ) ? 'on' : 'off' ) );
		\WP_CLI::log( sprintf( '%-20s %s', 'lcp_preload', ! empty( $opts['lcp_preload'] ) ? 'on' : 'off' ) );
		\WP_CLI::log( sprintf( '%-20s %d', 'lcp_image_count', (int) ( $opts['lcp_image_count'] ?? 1 ) ) );
		\WP_CLI::log( sprintf( '%-20s %d pattern(s)', 'lcp_exclusions', count( (array) ( $opts['lcp_exclusions'] ?? array() ) ) ) );
		\WP_CLI::log( sprintf( '%-20s %s', 'preconnect', ! empty( $opts['preconnect'] ) ? 'on' : 'off' ) );
		\WP_CLI::log( sprintf( '%-20s %d host(s)', 'preconnect_hosts', count( (array) ( $opts['preconnect_hosts'] ?? array() ) ) ) );
	}
}
