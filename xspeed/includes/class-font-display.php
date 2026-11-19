<?php
/**
 * Font_Display: `font-display: swap` for the @font-face rules in CSS that
 * xSpeed minifies, and the icon-font list both font settings share.
 *
 * Icon fonts never get swap and their stylesheets never load late. An icon
 * font maps ligature words ("arrow_forward") or private-use code points to
 * glyphs. While it loads, a swap paints the standard font instead, so the
 * visitor reads the ligature word or sees an empty box where the icon goes.
 * Blocking is the lesser cost for those faces.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Font_Display {

	/**
	 * Icon-font family names, normalised (lowercase, hyphens and
	 * underscores read as spaces). A name matches itself and any variant
	 * that adds words after it: "material symbols" matches "Material
	 * Symbols Rounded", "font awesome" matches "Font Awesome 6 Brands".
	 *
	 * Names containing "icon", "awesome" or "symbols" are treated as icon
	 * fonts without being listed (see is_icon_family()). The list covers the
	 * ones that match neither.
	 */
	public const ICON_FAMILIES = array(
		'material icons',
		'material symbols',
		'font awesome',
		'fontawesome',
		'dashicons',
		'eicons',
		'elementskit',
		'ekiticons',
		'icomoon',
		'icofont',
		'genericons',
		'themify',
		'ionicons',
		'fontello',
		'line awesome',
		'bootstrap icons',
		'woocommerce',
		'star',
		'simple line icons',
		'etmodules',
		'glyphicons',
	);

	/**
	 * Words that mark a family as an icon font wherever they appear in its
	 * name. Wide on purpose: a text font caught here keeps blocking, which is
	 * how it loaded before, while an icon font missed here shows ligature
	 * words while it loads.
	 */
	private const ICON_WORDS = array( 'icon', 'awesome', 'symbols' );

	/**
	 * Add `font-display:swap;` to every @font-face rule that has no
	 * font-display of its own and is not an icon font.
	 *
	 * A rule that already sets font-display keeps it, whatever the value:
	 * the author chose it. Text outside @font-face rules is never changed.
	 * Comments and quoted strings are skipped while scanning, so a rule
	 * inside a comment, or a brace inside a font name, is read correctly.
	 *
	 * @param string $css Stylesheet text, minified or not.
	 * @return string The stylesheet with swap added where it applies.
	 */
	public static function force_swap( string $css ): string {
		if ( '' === $css || false === stripos( $css, '@font-face' ) ) {
			return $css;
		}

		$masked = self::mask( $css );
		$insert = array();
		$offset = 0;

		while ( false !== ( $at = stripos( $masked, '@font-face', $offset ) ) ) {
			$offset = $at + 10;
			$open   = strpos( $masked, '{', $offset );
			if ( false === $open || '' !== trim( substr( $masked, $offset, $open - $offset ) ) ) {
				continue;
			}
			$close = strpos( $masked, '}', $open );
			if ( false === $close ) {
				break;
			}
			$offset = $close + 1;

			$body = substr( $masked, $open + 1, $close - $open - 1 );
			if ( preg_match( '/(?<![-\w])font-display\s*:/i', $body ) ) {
				continue;
			}
			if ( ! preg_match( '/(?<![-\w])font-family\s*:/i', $body, $fm, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$value_start = $open + 1 + $fm[0][1] + strlen( $fm[0][0] );
			$value_end   = strpos( $masked, ';', $value_start );
			if ( false === $value_end || $value_end > $close ) {
				$value_end = $close;
			}
			$family = substr( $css, $value_start, $value_end - $value_start );
			if ( '' === trim( $family ) || self::is_icon_family( $family ) ) {
				continue;
			}
			$insert[] = $open + 1;
		}

		if ( array() === $insert ) {
			return $css;
		}

		// Back to front, so earlier offsets stay valid.
		foreach ( array_reverse( $insert ) as $pos ) {
			$css = substr( $css, 0, $pos ) . 'font-display:swap;' . substr( $css, $pos );
		}
		return $css;
	}

	/**
	 * Whether a font-family name is an icon font.
	 *
	 * @param string $family One family name, quoted or not.
	 */
	public static function is_icon_family( string $family ): bool {
		$name = self::normalise( $family );
		if ( '' === $name ) {
			return false;
		}
		foreach ( self::ICON_WORDS as $word ) {
			if ( false !== strpos( $name, $word ) ) {
				return true;
			}
		}
		foreach ( self::icon_families() as $listed ) {
			if ( $name === $listed || 0 === strpos( $name, $listed . ' ' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a font-CSS URL serves an icon font: Google's /icon endpoint,
	 * or a family= parameter that names an icon family. Covers the css and
	 * css2 APIs (several family= parameters, or several families joined by
	 * "|") and Bunny's lowercase-hyphenated names.
	 *
	 * @param string $href Stylesheet URL, possibly HTML-entity encoded.
	 */
	public static function href_names_icon_font( string $href ): bool {
		$url  = html_entity_decode( $href, ENT_QUOTES | ENT_HTML5 );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '/icon' === rtrim( $path, '/' ) ) {
			return true;
		}
		foreach ( self::href_families( $href ) as $family ) {
			if ( self::is_icon_family( $family ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Family keys shared by Google's css/css2 URLs and Bunny's URLs.
	 *
	 * @param string $href Stylesheet URL, possibly HTML-entity encoded.
	 * @return string[] Normalised family names without requested variants.
	 */
	public static function href_families( string $href ): array {
		$url   = html_entity_decode( $href, ENT_QUOTES | ENT_HTML5 );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' === $query || ! preg_match_all( '/(?:^|&)family=([^&]*)/i', $query, $m ) ) {
			return array();
		}
		$names = array();
		foreach ( $m[1] as $param ) {
			foreach ( explode( '|', urldecode( $param ) ) as $family ) {
				$name = self::normalise( explode( ':', $family, 2 )[0] );
				if ( '' !== $name ) {
					$names[ $name ] = true;
				}
			}
		}
		return array_keys( $names );
	}

	/**
	 * The icon-family list, filtered and normalised.
	 *
	 * @return string[]
	 */
	private static function icon_families(): array {
		/**
		 * Font families treated as icon fonts. An icon font keeps its
		 * blocking load: "Load Google Fonts without blocking" leaves its
		 * stylesheet link alone, and "Show text while theme and plugin
		 * fonts load" leaves its @font-face rule alone.
		 *
		 * A name matches that family and every variant that adds words after
		 * it ("Material Symbols" also matches "Material Symbols Rounded").
		 * Case, hyphens and underscores do not matter. Names that contain
		 * "icon", "awesome" or "symbols" match without being listed.
		 *
		 * @param string[] $families Family names.
		 */
		$families = (array) apply_filters( 'xspeed_icon_font_families', self::ICON_FAMILIES );

		$out = array();
		foreach ( $families as $family ) {
			$name = is_string( $family ) ? self::normalise( $family ) : '';
			if ( '' !== $name ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/** Lowercase, unquoted, with hyphens and underscores read as spaces. */
	private static function normalise( string $family ): string {
		$name = strtolower( trim( $family, " \t\n\r\0\x0B'\"" ) );
		$name = str_replace( array( '-', '_', '+' ), ' ', $name );
		return trim( (string) preg_replace( '/\s+/', ' ', $name ) );
	}

	/**
	 * A copy of the CSS, the same length, with comment text blanked and
	 * string contents replaced, so braces, semicolons and declarations
	 * inside either never count while scanning. Offsets map one to one onto
	 * the original.
	 *
	 * @param string $css Stylesheet text.
	 */
	private static function mask( string $css ): string {
		$out = $css;
		$len = strlen( $css );
		$i   = 0;
		while ( $i < $len ) {
			$c = $css[ $i ];
			if ( '/' === $c && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				$end = false === $end ? $len : $end + 2;
				for ( $j = $i; $j < $end; $j++ ) {
					$out[ $j ] = ' ';
				}
				$i = $end;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$j = $i + 1;
				while ( $j < $len && $css[ $j ] !== $c ) {
					if ( '\\' === $css[ $j ] ) {
						$out[ $j ] = '_';
						++$j;
						if ( $j < $len ) {
							$out[ $j ] = '_';
						}
						++$j;
						continue;
					}
					if ( "\n" === $css[ $j ] ) {
						break; // An unclosed string ends at the line, as in CSS.
					}
					$out[ $j ] = '_';
					++$j;
				}
				$i = $j + 1;
				continue;
			}
			++$i;
		}
		return $out;
	}
}
