<?php
/**
 * Render_Caches — invalidate caches of RENDERED output owned by OTHER
 * plugins.
 *
 * Why this exists: not every asset-URL rewrite happens on the finished page.
 * The CDN module hooks `wp_get_attachment_url`, which fires *during* element
 * render, and core derives every sized image URL from it (`image_downsize()`
 * calls it and swaps the basename for the intermediate size). So the CDN host
 * is already in the markup by the time a page builder captures that markup
 * into a cache of its own.
 *
 * Elementor does exactly that, in two places:
 *
 *   - `_elementor_element_cache` post meta — the buffered output of a whole
 *     document, written on any ordinary front-end render, 24 h TTL by
 *     default (element caching is ON out of the box: the gate is
 *     `'disable' !== get_option( 'elementor_element_cache_ttl', '' )` and the
 *     option is unset on a stock install).
 *   - `uploads/elementor/css/post-<id>.css` — background-image `url()` values
 *     built from the same attachment URLs. **No TTL at all**; regenerated
 *     only on post save, "Regenerate Files & Data", or an Elementor version
 *     bump.
 *
 * `Cache::purge_all()` sweeps only trees xSpeed owns, so neither is reached.
 * The observable bug: disabling the CDN keeps emitting the old host for a day
 * (indefinitely from the CSS files), and *enabling* it leaves the heaviest
 * images — including the LCP hero — on the origin for the same window, which
 * reads as "the CDN does nothing".
 *
 * Deliberately NOT wired to every purge. A post-publish purge fires
 * constantly and blowing away every generated CSS file each time would be a
 * real regression; Elementor already invalidates its own copy for the post
 * being saved. This runs only where asset URLs themselves can have changed —
 * see Cache::purge_render_caches() for the call sites.
 *
 * Detection is by class/constant only, never `is_plugin_active()` on a path
 * string: a renamed plugin folder must not silently turn the integration off.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Render_Caches {

	/**
	 * Register the built-in integrations.
	 *
	 * Everything hangs off the public `xspeed_purge_third_party_render_caches`
	 * filter, so Pro — or a site owner with one builder we don't ship support
	 * for — adds a listener rather than patching this class.
	 */
	public static function boot(): void {
		add_filter( 'xspeed_purge_third_party_render_caches', array( __CLASS__, 'purge_elementor' ), 10, 2 );
	}

	/**
	 * Elementor: generated CSS files, `_elementor_css`,
	 * `_elementor_element_cache` and `_elementor_page_assets`.
	 *
	 * `Files_Manager::clear_cache()` clears all four in one pass and is what
	 * Elementor's own *Regenerate Files & Data* tool runs — public since
	 * Elementor 1.2.0. Everything regenerates lazily on the next front-end
	 * render of each page (measured on a 128 KB-CSS Elementor homepage:
	 * ~1.2 s for that first render against ~0.3 s warm, then back to normal).
	 *
	 * @param string[] $cleared Labels of caches cleared so far.
	 * @param string   $cause   Who asked, threaded through for the log.
	 * @return string[]
	 */
	public static function purge_elementor( $cleared, $cause = 'manual' ): array {
		$cleared = is_array( $cleared ) ? $cleared : array();
		unset( $cause );

		$files_manager = self::elementor_files_manager();
		if ( null === $files_manager ) {
			return $cleared;
		}

		$files_manager->clear_cache();
		$cleared[] = 'Elementor';

		return $cleared;
	}

	/**
	 * Elementor's files manager, or null when Elementor is absent or has not
	 * finished booting.
	 *
	 * Every step is guarded rather than assumed: this can run from a REST
	 * settings write, from WP-CLI and from admin-post, and `$instance` is
	 * populated late enough that "class exists" alone is not evidence the
	 * manager is there.
	 *
	 * @return object|null
	 */
	private static function elementor_files_manager() {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return null;
		}
		$instance = \Elementor\Plugin::$instance;
		if ( ! is_object( $instance ) || ! isset( $instance->files_manager ) ) {
			return null;
		}
		$files_manager = $instance->files_manager;
		if ( ! is_object( $files_manager ) || ! method_exists( $files_manager, 'clear_cache' ) ) {
			return null;
		}
		return $files_manager;
	}
}
