<?php
/**
 * Fonts module — keeps web-font loading from blocking text render.
 *
 * Two Free behaviors (FEATURES.md §Font Optimization rows 1 + 4):
 *   - Appends `display=swap` to Google Fonts stylesheet URLs so the
 *     browser paints text immediately in a fallback face while the
 *     web font downloads. Removes the FOIT window.
 *   - Emits <link rel="preload" as="font" crossorigin> for a
 *     site-defined list of font files so the LCP-critical face starts
 *     downloading at parser-discovery time, not after the CSS parses.
 *
 * Two opt-in behaviors, off unless the setup wizard or the admin turns
 * them on:
 *   - `async_google_css` loads font-CSS links (Google Fonts, Bunny,
 *     Typekit) without blocking the first paint, from the page buffer.
 *     Icon-font links stay blocking. See Minify_Filters::async_google_font_css().
 *   - `display_swap_in_css` adds font-display: swap to the @font-face rules
 *     of the CSS that xSpeed minifies. See Font_Display::force_swap().
 *
 * Pro adds self-hosting (OMGF-style download/serve) and subsetting —
 * those live in xspeed-pro and are surfaced through the manifest.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Fonts;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;

final class FontsModule extends Module {

	public const SLUG    = 'fonts';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.1.0';

	public function ui_metadata(): array {
		return array(
			'label'       => __( 'Fonts', 'xspeed' ),
			'icon'        => 'Type',
			'description' => __( 'Shows text right away while web fonts load, and preloads key fonts.', 'xspeed' ),
			'group'       => 'performance',
		);
	}

	public function settings_schema(): array {
		return array(
			'font_display_swap'   => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => __( 'Show text while fonts load', 'xspeed' ),
				'description' => __( 'Text in Google Fonts, and in fonts added to WordPress itself, shows at once in a standard font, then switches when the web font arrives.', 'xspeed' ),
			),
			'preload_fonts'       => array(
				'type'        => 'list',
				'default'     => array(),
				'item_type'   => 'url',
				'label'       => __( 'Fonts to preload', 'xspeed' ),
				'description' => __( 'One full font file URL per line (woff2, woff, ttf or otf). The browser fetches these first, so list only fonts used at the top of the page.', 'xspeed' ),
			),
			'async_google_css'    => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Load Google Fonts without blocking', 'xspeed' ),
				'description' => __( 'The page no longer waits for the Google Fonts stylesheet (or Bunny Fonts, Adobe Fonts or CDNFonts) before it paints. Text shows in a standard font first, and the font stylesheet loads at low priority. Icon fonts such as Material Icons load as before. Applies to pages the page cache stores.', 'xspeed' ),
			),
			'display_swap_in_css' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Show text while theme and plugin fonts load', 'xspeed' ),
				'description' => __( 'Fonts that a theme or plugin declares in its own stylesheets show text at once in a standard font, then switch when the font arrives. Stylesheet files change only with Minify CSS on. Fonts declared in inline styles on the page change only with Minify HTML on. Fonts that already set font-display, and icon fonts, are left as they are.', 'xspeed' ),
			),
		);
	}

	/**
	 * Say when a font switch is on but the feature it works through is off,
	 * so the switch changes nothing. Both read "on" while doing nothing, and
	 * only the long description said why (QA on 1.4.9).
	 *
	 * @return array<int,array<string,string>>
	 */
	public function ui_notices(): array {
		$opts    = $this->get_settings();
		$notices = array();
		if ( ! empty( $opts['display_swap_in_css'] ) ) {
			$minify = \XSpeed\Settings_Manager::get( 'minify' );
			if ( empty( $minify['minify_css'] ) && empty( $minify['minify_html'] ) ) {
				$notices[] = array(
					'tone'  => 'warn',
					'title' => __( 'Theme and plugin fonts are not changed yet', 'xspeed' ),
					'body'  => __( '"Show text while theme and plugin fonts load" works on the CSS that Minify CSS or Minify HTML processes, and both are off. Turn one of them on under Minify.', 'xspeed' ),
				);
			}
		}
		if ( ! empty( $opts['async_google_css'] ) && ! self::page_cache_on() ) {
			$notices[] = array(
				'tone'  => 'warn',
				'title' => __( 'Google Fonts still block the page', 'xspeed' ),
				'body'  => __( '"Load Google Fonts without blocking" changes the pages the page cache stores, and the page cache is off. Turn on the page cache to use it.', 'xspeed' ),
			);
		}
		return $notices;
	}

	/** Whether the page cache is on, read the way the cache reads it. */
	private static function page_cache_on(): bool {
		$legacy = \XSpeed\Settings_Manager::get( 'legacy' );
		if ( is_array( $legacy ) && ! empty( $legacy['cache_enabled'] ) ) {
			return true;
		}
		$opts = get_option( 'xspeed_options' );
		return is_array( $opts ) && ! empty( $opts['cache_enabled'] );
	}

	/**
	 * 1.1.0 added `async_google_css` and `display_swap_in_css`. A row saved
	 * before then gets both, off, so an upgraded site behaves as it did. A
	 * key already in the row keeps its value.
	 */
	public function migrations(): array {
		return array(
			'1.1.0' => static function ( array $opts ): array {
				foreach ( array( 'async_google_css', 'display_swap_in_css' ) as $key ) {
					if ( ! array_key_exists( $key, $opts ) ) {
						$opts[ $key ] = false;
					}
				}
				return $opts;
			},
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
		// Frontend-only rewriting. Admin / cron / AJAX / REST never
		// render <link rel="stylesheet"> tags we should touch.
		if ( is_admin()
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			// A builder editing screen is a front-end URL none of the above
			// catch; swapping font-display under it changes what the editor
			// measures. (#281)
			|| \XSpeed\Builder_Editor::is_active()
		) {
			return;
		}

		$opts = $this->get_settings();

		if ( ! empty( $opts['font_display_swap'] ) ) {
			add_filter( 'style_loader_tag', array( __CLASS__, 'inject_display_swap' ), 10, 2 );

			// Fonts added through the Font Library or theme.json are printed
			// by core with font-display: fallback, and core offers no filter
			// for it. Fallback hides the text for up to 100ms while the font
			// loads; when that text is the LCP, the hero paints after the
			// font instead of at first paint. Measured on a live text hero:
			// LCP landed ~85ms after FCP with fallback and on FCP with swap,
			// CLS unchanged, and PageSpeed mobile moved between 81 and 90
			// depending on which side of that window the font fell. So core's
			// own printer runs with swap as the default. Only an exact
			// priority-50 hook is replaced: anything that already moved or
			// removed it is left alone.
			if ( function_exists( 'wp_print_font_faces' )
				&& class_exists( '\WP_Font_Face_Resolver' )
				&& 50 === has_action( 'wp_head', 'wp_print_font_faces' )
			) {
				remove_action( 'wp_head', 'wp_print_font_faces', 50 );
				add_action( 'wp_head', array( __CLASS__, 'print_font_faces_swap' ), 50 );
			}
		}

		if ( ! empty( $opts['async_google_css'] ) ) {
			// Theme and plugin font links are mostly printed as literal
			// <link> markup that style_loader_tag never sees, so this runs
			// on the finished page buffer. Priority 22, the same slot as the
			// Async CSS font pass: whichever runs second skips the links the
			// first one marked.
			add_filter( 'xspeed_cache_final_html', array( \XSpeed\Minify_Filters::class, 'async_google_font_css' ), 22 );
		}

		if ( ! empty( $opts['preload_fonts'] ) ) {
			add_action(
				'wp_head',
				function () {
					echo self::render_preload_links( (array) $this->get_setting( 'preload_fonts', array() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				},
				1
			);
		}
	}

	/**
	 * Rewrite a single <link> tag emitted by WP for a Google Fonts
	 * stylesheet so it carries display=swap. No-op for non-Google
	 * hrefs and for URLs that already declare a display value
	 * (auto / block / swap / fallback / optional).
	 *
	 * Public + static so the test suite can drive it without booting
	 * the module or hitting WordPress hook internals.
	 */
	public static function inject_display_swap( string $tag, string $handle = '' ): string {
		unset( $handle ); // signature contract — not used.

		if ( false === stripos( $tag, 'fonts.googleapis.com' ) ) {
			return $tag;
		}

		if ( ! preg_match( '/href=([\'"])([^\'"]+)\1/i', $tag, $m ) ) {
			return $tag;
		}

		$href = $m[2];

		// A display param the theme set is respected ONLY when it is one of
		// the non-blocking choices (swap / fallback / optional) — someone
		// picked those deliberately and each is a defensible trade. `auto`
		// and `block` are the values this setting exists to remove: `auto`
		// IS block behavior in every engine, and it is almost never a
		// choice — it is the default a theme's enqueue happened to emit.
		// "Respecting" it turned the switch into a no-op on exactly the
		// sites that need it: a live text-LCP measured a 5.5s render delay
		// behind flatsome's `display=auto` Poppins URL while this option
		// was on and its label promised the opposite. WP Rocket and
		// LiteSpeed rewrite these too. The href here has been through
		// esc_url(), which encodes "&" as "&#038;" — decode before
		// matching, rewrite on the ORIGINAL encoded href so str_replace
		// finds it in the tag verbatim. (FBS-82161)
		$href_decoded = html_entity_decode( $href, ENT_QUOTES | ENT_HTML5 );
		if ( preg_match( '/([?&])display=(auto|block)(&|$)/i', $href_decoded ) ) {
			$new_href = preg_replace( '/((?:[?&]|&#0*38;|&#[xX]0*26;|&amp;)display=)(?:auto|block)(?=&|$)/i', '$1swap', $href );

			return str_replace( $href, $new_href, $tag );
		}
		if ( preg_match( '/[?&]display=/i', $href_decoded ) ) {
			return $tag;
		}

		// Pick the separator from the DECODED url (so a "?" hidden behind an
		// entity is still recognised), but append to the ORIGINAL (encoded)
		// href so the str_replace below matches the tag verbatim.
		$separator = ( false === strpos( $href_decoded, '?' ) ) ? '?' : '&';
		$new_href  = $href . $separator . 'display=swap';

		return str_replace( $href, $new_href, $tag );
	}

	/**
	 * Print core's font faces as wp_print_font_faces() does, with swap as
	 * the font-display default.
	 */
	public static function print_font_faces_swap(): void {
		$fonts = \WP_Font_Face_Resolver::get_fonts_from_theme_json();
		if ( empty( $fonts ) ) {
			return;
		}
		// WordPress 6.4+. Only hooked when the function exists (see
		// boot_on_init()), called by name so a 6.0 floor stays compatible.
		call_user_func( 'wp_print_font_faces', self::default_display_swap( $fonts ) );
	}

	/**
	 * Give every font face without its own font-display a swap one.
	 *
	 * The resolver only sets font-display when a theme.json fontFace
	 * declares fontDisplay, so a face that has one was chosen on purpose
	 * and keeps it. Public + static so the test suite can drive it.
	 *
	 * @param array<int|string,mixed> $fonts Font families, each a list of faces.
	 * @return array<int|string,mixed>
	 */
	public static function default_display_swap( array $fonts ): array {
		foreach ( $fonts as $family => $faces ) {
			if ( ! is_array( $faces ) ) {
				continue;
			}
			foreach ( $faces as $i => $face ) {
				if ( is_array( $face ) && ! isset( $face['font-display'] ) ) {
					$fonts[ $family ][ $i ]['font-display'] = 'swap';
				}
			}
		}
		return $fonts;
	}

	/**
	 * Render the preload <link> markup for a list of font URLs.
	 *
	 * Pulled out as a static so tests can assert the markup directly
	 * without buffering wp_head output.
	 */
	public static function render_preload_links( array $urls ): string {
		$out = '';
		foreach ( $urls as $url ) {
			$url = is_string( $url ) ? trim( $url ) : '';
			if ( '' === $url ) {
				continue;
			}

			$type = self::guess_font_mime( $url );

			$out .= sprintf(
				'<link rel="preload" as="font" type="%s" href="%s" crossorigin>' . "\n",
				esc_attr( $type ),
				esc_url( $url )
			);
		}
		return $out;
	}

	/**
	 * Map a font URL extension to its MIME. Defaults to woff2 because
	 * that's the dominant modern format; an unknown extension is
	 * almost always a fingerprinted woff2 in practice.
	 */
	public static function guess_font_mime( string $url ): string {
		$path = strtolower( wp_parse_url( $url, PHP_URL_PATH ) ?? '' );
		if ( '' === $path ) {
			$path = strtolower( $url );
		}
		// Plugin floor is PHP 7.4 — str_ends_with() is 8.0+. Use a
		// substr() compare instead so the matrix's 7.4 leg passes.
		$ends_with = static function ( string $haystack, string $needle ): bool {
			$len = strlen( $needle );
			return 0 !== $len && substr( $haystack, -$len ) === $needle;
		};
		if ( $ends_with( $path, '.woff2' ) ) {
			return 'font/woff2';
		}
		if ( $ends_with( $path, '.woff' ) ) {
			return 'font/woff';
		}
		if ( $ends_with( $path, '.ttf' ) ) {
			return 'font/ttf';
		}
		if ( $ends_with( $path, '.otf' ) ) {
			return 'font/otf';
		}
		return 'font/woff2';
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed fonts',
				'risk'      => 'read',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Show font-optimization settings.',
				'ai_hint'   => 'How are web fonts being optimized (font-display swap, swap in minified CSS, non-blocking Google Fonts CSS, preloading, local hosting)? Use for questions about invisible text while loading (FOIT/FOUT) or render-blocking fonts.',
				'synopsis'  => array(),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$opts = $this->get_settings();
		\WP_CLI::log( sprintf( '%-22s %s', 'font_display_swap', ! empty( $opts['font_display_swap'] ) ? 'on' : 'off' ) );
		\WP_CLI::log( sprintf( '%-22s %d url(s)', 'preload_fonts', count( (array) ( $opts['preload_fonts'] ?? array() ) ) ) );
		\WP_CLI::log( sprintf( '%-22s %s', 'async_google_css', ! empty( $opts['async_google_css'] ) ? 'on' : 'off' ) );
		\WP_CLI::log( sprintf( '%-22s %s', 'display_swap_in_css', ! empty( $opts['display_swap_in_css'] ) ? 'on' : 'off' ) );
	}
}
