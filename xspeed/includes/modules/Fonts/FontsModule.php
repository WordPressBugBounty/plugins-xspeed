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
	public const VERSION = '1.0.0';

	public function ui_metadata(): array {
		return array(
			'label'       => 'Fonts',
			'icon'        => 'Type',
			'description' => 'Stop web fonts from blocking text. Adds display=swap to Google Fonts and preloads the fonts you mark critical.',
		);
	}

	public function settings_schema(): array {
		return array(
			'font_display_swap' => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => 'Add font-display: swap',
				'description' => 'Append display=swap to Google Fonts URLs so text renders immediately in a fallback face while the web font loads. No effect on URLs that already declare a display value.',
			),
			'preload_fonts'     => array(
				'type'        => 'list',
				'default'     => array(),
				'item_type'   => 'url',
				'label'       => 'Preload Font URLs',
				'description' => 'One absolute font URL per line (woff2/woff/ttf/otf). Each becomes a <link rel="preload" as="font" crossorigin> in the head so the browser starts downloading before the CSS parses. Use only for fonts that render above the fold.',
			),
		);
	}

	public function boot(): void {
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

		// Already has a display param — leave it alone (respect the theme /
		// plugin that set it). The href here has been through esc_url(),
		// which encodes "&" as the entity "&#038;", so a real URL like
		// ...?family=Roboto&display=optional arrives as
		// ...?family=Roboto&#038;display=optional — the char before
		// "display=" is then ";" (tail of the entity), not "&", and the old
		// [?&]display= guard missed it, double-appending a second display.
		// Decode entities before the check so it matches either form.
		// (FBS-82161)
		$href_decoded = html_entity_decode( $href, ENT_QUOTES | ENT_HTML5 );
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
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Show font-optimization settings.',
				'ai_hint'   => 'How are web fonts being optimized (font-display swap, preloading, local hosting)? Use for questions about invisible text while loading (FOIT/FOUT) or render-blocking fonts.',
				'synopsis'  => array(),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$opts = $this->get_settings();
		\WP_CLI::log( sprintf( '%-22s %s', 'font_display_swap', ! empty( $opts['font_display_swap'] ) ? 'on' : 'off' ) );
		\WP_CLI::log( sprintf( '%-22s %d url(s)', 'preload_fonts', count( (array) ( $opts['preload_fonts'] ?? array() ) ) ) );
	}
}
