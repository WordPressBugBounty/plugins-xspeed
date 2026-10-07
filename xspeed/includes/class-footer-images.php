<?php
/**
 * Footer_Images — lets the browser pick a smaller file for images printed in
 * the footer, and lazy-loads them.
 *
 * Core runs wp_filter_content_tags() over post content and template parts,
 * never over what plugins print in the footer. A popup builder that renders
 * its blocks there (Kadence Conversions, for one) ships every image at full
 * size with no srcset. Its popup sits in the viewport, scaled to zero and
 * hidden, so loading="lazy" alone changes nothing: the browser still loads
 * each image straight away. srcset plus sizes="auto" is what helps. The
 * browser then picks the file that fits the box the image is laid out in,
 * and a 1036px original drawn in a 190px column comes down as the 194px
 * medium size instead.
 *
 * Every rendered box stays the size it was: no width or height attribute
 * is written, and the file's size reaches the browser through CSS any
 * theme rule overrides (see add_srcset()).
 *
 * The footer only. The header and the hero are where the LCP image lives,
 * and lazy-loading that is the regression this class must never cause.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Footer_Images {

	/**
	 * Output-buffer level of the footer buffer: null before it opens, -1
	 * once it has been handled, so a second wp_footer can't open another.
	 *
	 * @var int|null
	 */
	private static $level = null;

	/**
	 * File sizes given out in this pass, "width-height" => [width, height].
	 *
	 * @var array<string,array{0:int,1:int}>
	 */
	private static $ratios = array();

	/**
	 * Open the footer buffer. Hooked on both get_footer, so a classic
	 * theme's footer template is covered, and wp_footer, for block themes
	 * that never fire get_footer. Whichever comes first opens it.
	 */
	public static function start(): void {
		if ( null !== self::$level ) {
			return;
		}
		if ( function_exists( 'amp_is_request' ) && amp_is_request() ) {
			self::$level = -1;
			return;
		}
		ob_start( array( __CLASS__, 'passthrough' ) );
		self::$level = ob_get_level();
	}

	/**
	 * Output-buffer callback that changes nothing. It names the buffer, so
	 * finish() can tell ours from one another plugin swapped in at the
	 * same level.
	 *
	 * @param string $buffer Buffer contents.
	 */
	public static function passthrough( string $buffer ): string {
		return $buffer;
	}

	/**
	 * Close the footer buffer and print it rewritten.
	 *
	 * If another plugin opened a buffer inside ours and left it open, closed
	 * ours, or swapped its own in at the same level, the top buffer is not
	 * ours. Closing it would swallow or reorder that plugin's output, so we
	 * leave everything as it is and PHP flushes ours, untouched, at shutdown.
	 */
	public static function finish(): void {
		if ( null === self::$level || -1 === self::$level ) {
			return;
		}
		$level       = self::$level;
		self::$level = -1;
		$status = ob_get_status();
		if ( ob_get_level() !== $level || ( $status['name'] ?? '' ) !== __CLASS__ . '::passthrough' ) {
			return;
		}
		$html = ob_get_clean();
		echo self::process( is_string( $html ) ? $html : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-rendered page markup, only attributes added.
	}

	/** Forget the buffer state, once per page render. */
	public static function reset_state(): void {
		self::$level  = null;
		self::$ratios = array();
	}

	/**
	 * Add srcset/sizes and loading="lazy" to the footer's images.
	 *
	 * Script, style, template and similar blocks are left alone: an <img>
	 * inside a JS string would break the script if we wrote double quotes
	 * into it.
	 *
	 * @param string $html Footer markup.
	 */
	public static function process( string $html ): string {
		if ( false === stripos( $html, '<img' ) ) {
			return $html;
		}

		$stubs = array();
		$safe  = preg_replace_callback(
			'#<(script|style|noscript|template|textarea|pre|code)\b[^>]*>.*?</\1\s*>#is',
			static function ( array $m ) use ( &$stubs ): string {
				$key           = '<!--XSPEED_FOOTER_STUB_' . count( $stubs ) . '-->';
				$stubs[ $key ] = $m[0];
				return $key;
			},
			$html
		);
		if ( ! is_string( $safe ) ) {
			return $html;
		}

		$pattern = '#<img\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>#i';
		if ( ! preg_match_all( $pattern, $safe, $tags ) ) {
			return $html;
		}

		// One query for every attachment instead of one per image, as
		// wp_filter_content_tags() does.
		$ids = array();
		foreach ( $tags[0] as $tag ) {
			$id = self::attachment_id( $tag );
			if ( $id > 0 && ! self::has_attr( $tag, 'srcset' ) ) {
				$ids[] = $id;
			}
		}
		if ( $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_unique( $ids ), false, true );
		}

		self::$ratios = array();
		$opts         = Settings_Manager::get( 'lazy' );
		$out          = preg_replace_callback(
			$pattern,
			static function ( array $m ) use ( $opts ): string {
				return self::rewrite_img( $m[0], is_array( $opts ) ? $opts : array() );
			},
			$safe
		);
		if ( ! is_string( $out ) ) {
			return $html;
		}

		$out = self::ratio_style() . $out;
		return $stubs ? strtr( $out, $stubs ) : $out;
	}

	/**
	 * Only an attachment image that gets a srcset is touched. Lazy-loading
	 * on its own buys nothing in the footer (see the class comment) and
	 * would stop a hidden tracking pixel from ever loading (#558), so every
	 * other image is left exactly as printed.
	 *
	 * @param array<string,mixed> $opts Lazy settings.
	 */
	private static function rewrite_img( string $tag, array $opts ): string {
		$id = self::attachment_id( $tag );
		if ( $id < 1
			|| false !== stripos( $tag, 'data-skip-lazy' )
			|| false !== stripos( $tag, 'data-no-lazy' )
			|| Lazy_Loader::has_high_fetchpriority( $tag )
			|| Lazy_Loader::tag_is_hidden( $tag, 'img' )
			// Width and height attributes are left as they are, and a tag
			// that has either is skipped: every way of completing one
			// changes how some theme lays it out.
			|| self::has_attr( $tag, 'srcset' )
			|| self::has_attr( $tag, 'sizes' )
			|| self::has_attr( $tag, 'width' )
			|| self::has_attr( $tag, 'height' )
		) {
			return $tag;
		}
		// sizes="auto" is only valid on a lazy image.
		if ( self::has_attr( $tag, 'loading' ) && ! preg_match( '#\sloading\s*=\s*["\']?lazy\b#i', $tag ) ) {
			return $tag;
		}
		foreach ( (array) ( $opts['excluded_images'] ?? array() ) as $pattern ) {
			$pattern = (string) $pattern;
			if ( '' !== $pattern && false !== stripos( $tag, $pattern ) ) {
				return $tag;
			}
		}

		$out = self::add_srcset( $tag, $id );
		if ( $out === $tag || self::has_attr( $tag, 'loading' ) ) {
			return $out;
		}
		return (string) preg_replace( '#^<img\b#i', '<img loading="lazy"', $out, 1 );
	}

	/**
	 * Add core's srcset with sizes="auto, {width}px" and a ratio key.
	 *
	 * sizes="auto" makes Chrome lay the image out with size containment:
	 * the file's own size stops counting and core's CSS reserves 3000x1500
	 * instead. Measured on a footer image: 360x556 became 360x1500. A
	 * width or height attribute would fix that but breaks a theme that
	 * sizes the image on one axis (#556). So the file's size goes in
	 * through CSS instead (see ratio_style()), and the "{width}px" after
	 * "auto" is what a browser without sizes="auto" uses: the file's own
	 * width, so it lays the image out exactly as before.
	 */
	private static function add_srcset( string $tag, int $id ): string {
		if ( ! function_exists( 'wp_calculate_image_srcset' ) || ! function_exists( 'wp_image_src_get_dimensions' )
			|| ! preg_match( '#\ssrc\s*=\s*(["\'])([^"\']+)\1#i', $tag, $src ) ) {
			return $tag;
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own opt-out for sizes="auto", honoured, not defined here.
		if ( ! apply_filters( 'wp_img_tag_add_auto_sizes', true ) ) {
			return $tag;
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) ) {
			return $tag;
		}
		$dims = wp_image_src_get_dimensions( $src[2], $meta, $id );
		if ( ! is_array( $dims ) || (int) $dims[0] < 1 || (int) $dims[1] < 1 ) {
			return $tag;
		}
		$width  = (int) $dims[0];
		$height = (int) $dims[1];

		// Nothing wider than the file the page already asked for, so a
		// browser never downloads more than it did before: a 663px src with
		// the 1036px original in its srcset fetched the original. Core's own
		// cap does it, so URLs are never parsed (a CDN URL can hold commas).
		// Core checks the src against the attachment's files and returns
		// false when they don't match (an edited image, a CDN URL, a
		// placeholder src), and when fewer than two files are left.
		$cap = static function ( $max ) use ( $width ) {
			return min( (int) $max, $width );
		};
		add_filter( 'max_srcset_image_width', $cap, PHP_INT_MAX );
		$srcset = wp_calculate_image_srcset( array( $width, $height ), $src[2], $meta, $id );
		remove_filter( 'max_srcset_image_width', $cap, PHP_INT_MAX );
		if ( ! is_string( $srcset ) || '' === $srcset ) {
			return $tag;
		}

		$ratio                  = $width . '-' . $height;
		self::$ratios[ $ratio ] = array( $width, $height );

		return (string) preg_replace(
			'#^<img\b#i',
			'<img srcset="' . esc_attr( $srcset ) . '" sizes="auto, ' . $width . 'px" data-xspeed-ar="' . $ratio . '"',
			$tag,
			1
		);
	}

	/**
	 * The file's size for each image given srcset.
	 *
	 * aspect-ratio sits in :where(), at zero specificity, so a theme's own
	 * width, height or aspect-ratio rule still wins. contain-intrinsic-size
	 * has to beat core's own 3000x1500 rule, so it carries the same
	 * specificity and wins by coming later in the page.
	 */
	private static function ratio_style(): string {
		if ( ! self::$ratios ) {
			return '';
		}
		$css = '';
		foreach ( self::$ratios as $ratio => $dims ) {
			$sel  = 'img[data-xspeed-ar="' . $ratio . '"]';
			$css .= ':where(' . $sel . '){aspect-ratio:' . $dims[0] . '/' . $dims[1] . '}'
				. $sel . '{contain-intrinsic-size:' . $dims[0] . 'px ' . $dims[1] . 'px}';
		}
		return '<style id="xspeed-footer-img">' . $css . '</style>';
	}

	private static function attachment_id( string $tag ): int {
		return preg_match( '#(?<![-\w])class\s*=\s*["\'][^"\']*\bwp-image-(\d+)\b#i', $tag, $m ) ? (int) $m[1] : 0;
	}

	private static function has_attr( string $tag, string $name ): bool {
		return 1 === preg_match( '#\s' . preg_quote( $name, '#' ) . '\s*=#i', $tag );
	}
}
