<?php
/**
 * Delay_Compat — keeps known sliders and themes working when Delay JS holds
 * their scripts back until the first interaction.
 *
 * Three cases, each found on a live Bridge + Slider Revolution 5 site:
 *
 *  - A library's page-level init is plain inline code that no handle owns,
 *    so Smart Delay cannot tie it to the library. Revolution Slider's init
 *    ran at DOMContentLoaded, found no `.revolution()` and printed its
 *    "jquery.js library include" error in place of the hero.
 *  - With the init parked too, the slider's wrapper has no height and no
 *    image until the first interaction. Lighthouse never interacts, and a
 *    visitor saw an empty hero. A poster of the first slide fills it.
 *  - Bridge hides its logo with `.q_logo a{visibility:hidden}` and shows it
 *    from its delayed main script, after sizing it.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Delay_Compat {

	/**
	 * Libraries whose page-level init must wait for the library itself.
	 *
	 * `src` is a URL fragment of the library's own files; `body` matches the
	 * inline code that calls into it. The inline code is parked only when a
	 * file of that library was delayed on the page, so a library the user
	 * excluded keeps its init live with it.
	 */
	private const LIBRARY_CONSUMERS = array(
		'revslider' => array(
			'src'  => '/plugins/revslider/',
			'body' => '#\.revolution(?:Init)?\s*\(|\bsetREVStartSize\s*\(#',
		),
	);

	/**
	 * Theme script handles that must not be delayed, keyed by template.
	 *
	 * Bridge's `default_dynamic` holds only the theme's settings as globals
	 * (header and logo heights among them) plus `$j = jQuery.noConflict()`.
	 * The logo reveal below reads those heights before the theme's main
	 * script is back. The file is about 3 KB.
	 */
	private const THEME_LIVE_HANDLES = array(
		'bridge' => array( 'default_dynamic' ),
	);

	/**
	 * The `body` patterns of every library with a delayed file on the page.
	 *
	 * Read from the finished HTML, after every pass that can delay a tag,
	 * so it holds whatever ended up delayed and nothing else.
	 *
	 * @param string $html Complete page HTML.
	 * @return string[] Regex patterns.
	 */
	public static function delayed_library_patterns( string $html ): array {
		/**
		 * Filter: xspeed_delay_library_consumers. Libraries whose inline init
		 * code Delay JS parks along with them.
		 *
		 * @param array<string,array{src:string,body:string}> $libraries Keyed by a short name.
		 */
		$libraries = (array) apply_filters( 'xspeed_delay_library_consumers', self::LIBRARY_CONSUMERS );
		if ( ! preg_match_all( '#<script\b[^>]*?(?<![-\w])data-xs-src\s*=\s*(["\'])([^"\']*)\1#i', $html, $m ) ) {
			return array();
		}
		$patterns = array();
		foreach ( $libraries as $library ) {
			if ( ! is_array( $library ) || empty( $library['src'] ) || empty( $library['body'] ) ) {
				continue;
			}
			foreach ( $m[2] as $src ) {
				if ( false !== stripos( $src, (string) $library['src'] ) ) {
					$patterns[] = (string) $library['body'];
					break;
				}
			}
		}
		return $patterns;
	}

	/**
	 * Whether an inline body calls into one of the delayed libraries.
	 *
	 * @param string   $body     Inline script body.
	 * @param string[] $patterns From delayed_library_patterns().
	 */
	public static function calls_delayed_library( string $body, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			// A broken filter-supplied pattern returns false, not a match.
			if ( 1 === @preg_match( $pattern, $body ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid third-party pattern must not warn on every page.
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the active theme needs this handle live under Delay JS.
	 *
	 * @param string $handle Script handle.
	 */
	public static function keeps_live( string $handle ): bool {
		if ( '' === $handle || ! function_exists( 'get_template' ) ) {
			return false;
		}
		$handles = self::THEME_LIVE_HANDLES[ (string) get_template() ] ?? array();
		return in_array( $handle, $handles, true );
	}

	/**
	 * Output buffer for every front-end page while Delay JS is on.
	 *
	 * The two passes above run on `xspeed_cache_final_html`, which fires only
	 * on a page the cache stores. A logged-in visitor got the slider's files
	 * delayed and its init live, so the slider's error showed in place of
	 * the hero again. This runs the library rule and the poster on every
	 * page, and nothing else: with no delayed library file on the page it
	 * returns the page unchanged. On a page the cache stores it runs first,
	 * and the cache's own passes then find the work done.
	 *
	 * @param mixed $html Page HTML.
	 * @return mixed
	 */
	public static function buffer( $html ) {
		if ( ! is_string( $html ) || strlen( $html ) < 255 ) {
			return $html;
		}
		$out = Minify_Filters::delay_inline_snippets( $html, true );
		if ( $out === $html ) {
			return $html;
		}
		return self::revslider_posters( $out );
	}

	/**
	 * Filter: `xspeed_cache_final_html`, after the inline parking pass. Put a
	 * poster of the first slide into every Revolution Slider 5 whose init
	 * was parked.
	 *
	 * The poster is a real `<img>` with fetchpriority="high", so the
	 * browser's preload scanner finds it and it can be the page's LCP. A
	 * script beside it gives the wrapper the height the slider's own
	 * setREVStartSize() would have set, so the content below does not move
	 * when the slider starts. The slider paints over the poster once it
	 * runs.
	 *
	 * Fullscreen sliders are skipped: their height depends on other
	 * elements' heights, which this does not reproduce.
	 *
	 * @param string $html Complete page HTML.
	 */
	public static function revslider_posters( $html ): string {
		if ( ! is_string( $html ) || '' === $html || false === strpos( $html, 'setREVStartSize' ) ) {
			return (string) $html;
		}
		if ( ! preg_match_all( '#<script\b[^>]*\btype\s*=\s*(["\'])text/xspeed-delayed\1[^>]*>(.*?)</script>#is', $html, $scripts ) ) {
			return $html;
		}
		foreach ( $scripts[2] as $body ) {
			$size = self::parse_start_size( $body );
			if ( null === $size ) {
				continue;
			}
			$html = self::insert_poster( $html, $size );
		}
		return $html;
	}

	/**
	 * Read the setREVStartSize() call's arguments from a parked init.
	 *
	 * @param string $body Inline script body.
	 * @return array{id:string,levels:int[],widths:int[],heights:int[],min:int}|null
	 */
	private static function parse_start_size( string $body ): ?array {
		if ( ! preg_match( '#\bsetREVStartSize\s*\(\s*\{(.*?)\}\s*\)#s', $body, $call ) ) {
			return null;
		}
		$args = $call[1];
		if ( ! preg_match( '#\bc\s*:\s*(["\'])\#?([\w-]+)\1#', $args, $id ) ) {
			return null;
		}
		if ( preg_match( '#\bsliderLayout\s*:\s*(["\'])(\w+)\1#', $args, $layout ) && 'fullscreen' === strtolower( $layout[2] ) ) {
			return null;
		}
		$widths  = self::int_list( $args, 'gridwidth' );
		$heights = self::int_list( $args, 'gridheight' );
		if ( empty( $widths ) || empty( $heights ) ) {
			return null;
		}
		$min = preg_match( '#\bminHeight\s*:\s*(\d+)#', $args, $mh ) ? (int) $mh[1] : 0;
		return array(
			'id'      => $id[2],
			'levels'  => self::int_list( $args, 'responsiveLevels' ),
			'widths'  => $widths,
			'heights' => $heights,
			'min'     => $min,
		);
	}

	/**
	 * A numeric array argument (`key: [1,2]`) or a single number (`key: 5`).
	 *
	 * @param string $args Object literal body.
	 * @param string $key  Property name.
	 * @return int[]
	 */
	private static function int_list( string $args, string $key ): array {
		if ( preg_match( '#\b' . $key . '\s*:\s*\[([^\]]*)\]#', $args, $m ) ) {
			preg_match_all( '#\d+#', $m[1], $n );
			return array_map( 'intval', $n[0] );
		}
		if ( preg_match( '#\b' . $key . '\s*:\s*(\d+)#', $args, $m ) ) {
			return array( (int) $m[1] );
		}
		return array();
	}

	/**
	 * Insert the poster and sizing script as the first children of the
	 * slider's wrapper.
	 *
	 * @param string                                                                  $html Page HTML.
	 * @param array{id:string,levels:int[],widths:int[],heights:int[],min:int} $size From parse_start_size().
	 */
	private static function insert_poster( string $html, array $size ): string {
		$wrapper_re = '#<div\b[^>]*\bid\s*=\s*(["\'])' . preg_quote( $size['id'], '#' ) . '_wrapper\1[^>]*>#i';
		if ( ! preg_match( $wrapper_re, $html, $wrapper, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$open_end = $wrapper[0][1] + strlen( $wrapper[0][0] );

		// Already done on an earlier pass over the same buffer.
		if ( 0 === strpos( substr( $html, $open_end, 40 ), '<img class="xspeed-rs-poster"' ) ) {
			return $html;
		}

		// The first slide's main image. Lazy-load mode keeps the real URL in
		// data-lazyload and a placeholder in src.
		$rest = substr( $html, $open_end, 20000 );
		if ( ! preg_match( '#<img\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\brev-slidebg\b[^"\']*\1[^>]*>#i', $rest, $img ) ) {
			return $html;
		}
		$src = '';
		foreach ( array( 'data-lazyload', 'src' ) as $attr ) {
			if ( preg_match( '#(?<![-\w])' . $attr . '\s*=\s*(["\'])([^"\']+)\1#i', $img[0], $a ) ) {
				$src = html_entity_decode( $a[2], ENT_QUOTES );
				break;
			}
		}
		if ( '' === $src || false !== stripos( $src, 'dummy.png' ) ) {
			return $html;
		}
		$fit      = preg_match( '#data-bgfit\s*=\s*(["\'])contain\1#i', $img[0] ) ? 'contain' : 'cover';
		$position = 'center center';
		if ( preg_match( '#data-bgposition\s*=\s*(["\'])([a-z0-9% .-]+)\1#i', $img[0], $p ) ) {
			$position = $p[2];
		}

		$config = wp_json_encode(
			array(
				'l' => $size['levels'],
				'w' => $size['widths'],
				'h' => $size['heights'],
				'm' => $size['min'],
			)
		);
		$poster = '<img class="xspeed-rs-poster" src="' . esc_url( $src ) . '" alt="" aria-hidden="true" fetchpriority="high" decoding="async" data-skip-lazy'
			. ' style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:' . $fit . ';object-position:' . esc_attr( $position ) . ';pointer-events:none">'
			. '<script id="xspeed-rs-size-' . esc_attr( $size['id'] ) . '" data-xs-nodelay>' . self::size_script( (string) $config ) . '</script>';

		return substr( $html, 0, $open_end ) . $poster . substr( $html, $open_end );
	}

	/**
	 * setREVStartSize() from Slider Revolution 5, without jQuery. Picks the
	 * responsive level the same way, so the wrapper gets the height the
	 * slider gives it when it starts.
	 *
	 * @param string $config JSON: l levels, w grid widths, h grid heights, m min height.
	 */
	private static function size_script( string $config ): string {
		return '(function(s,c){var w=s.parentNode,i=document.documentElement.clientWidth,t=9999,r=0,n=0,l=0,f,g,k;'
			. 'c.l.forEach(function(v,e){if(v>i){t=r=v;l=e}if(i>v&&v>r){r=v;n=e}});if(t>r)l=n;'
			. 'f=c.h[l]||c.h[0];g=c.w[l]||c.w[0];k=Math.min(1,i/g);f=Math.round(k*f);if(c.m&&f<c.m)f=c.m;'
			. 'w.style.height=f+"px";if(getComputedStyle(w).position==="static")w.style.position="relative"})(document.currentScript,'
			. $config . ')';
	}

	/**
	 * Action: `wp_head`. Show Bridge's logo at the size its delayed main
	 * script gives it.
	 *
	 * Bridge keeps `.q_logo a` hidden until default.min.js has sized it from
	 * the theme's header and logo heights. With that script delayed, the
	 * logo stayed hidden until the first interaction. This applies the same
	 * sizes at DOMContentLoaded: the logo's own height on desktop when the
	 * header has room, else the header height less 10px, and at most 80px
	 * at 1000px and below. A menu placed left of the header starts where the
	 * shown logo image ends, as Bridge's setLeftPostionedMenuPadding() puts
	 * it; without that the logo covered the first menu items. The delayed
	 * script recomputes all of it when it runs.
	 *
	 * @param bool $delays_main Whether Delay JS holds Bridge's main script.
	 */
	public static function bridge_logo_reveal_markup( bool $delays_main ): string {
		if ( ! $delays_main || ! function_exists( 'get_template' ) || 'bridge' !== get_template() ) {
			return '';
		}
		return '<script id="xspeed-bridge-logo-js" data-xs-nodelay>document.addEventListener("DOMContentLoaded",function(){'
			. 'var l=window.logo_height,h=window.header_height,d=document,z;if(!l||!h)return;'
			. 'Array.prototype.forEach.call(d.querySelectorAll("header:not(.stick_with_left_right_menu) .q_logo a"),function(a){'
			. 'var f=a.closest("header");f=f&&f.classList.contains("fixed");'
			. 'z=d.documentElement.clientWidth>1000?((f?h-l>0:h-l>=10)?l:h-10):Math.min(80,l);'
			. 'a.style.height=z+"px";a.style.visibility="visible";'
			. 'Array.prototype.forEach.call(a.querySelectorAll("img"),function(i){i.style.height="100%";i.addEventListener("load",m)})});m();'
			. 'function m(){var n=d.querySelector("header:not(.centered_logo) nav.main_menu.left"),i;if(!n)return;'
			. 'i=Array.prototype.filter.call(d.querySelectorAll(".q_logo a img"),function(e){return getComputedStyle(e).opacity==="1"})[0];'
			. 'if(i&&i.offsetWidth)n.style.left=i.offsetWidth+"px"}})</script>';
	}
}
