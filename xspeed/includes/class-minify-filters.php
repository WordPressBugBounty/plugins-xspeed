<?php
/**
 * Minify_Filters — frontend HTML rewriters for the "smarter minifier"
 * sub-features (Phase 4.1a): defer JS, delay JS, async CSS, remove
 * query strings.
 *
 * Each method is a WordPress filter callback. None of them touch the
 * file system — they're pure tag rewrites or src-string rewrites
 * applied to enqueued asset URLs / tags.
 *
 * The heavier combine-CSS / combine-JS engine lands in Phase 4.1b
 * with its own class; keeping the filter-only logic isolated here
 * makes that future split clean.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Minify_Filters {

	/**
	 * Settings cache (one read per request).
	 *
	 * @var array|null
	 */
	private static $opts = null;

	/**
	 * Has the delay-JS bootstrap snippet been printed? Guards against
	 * duplicate emission in pages that hit wp_footer multiple times.
	 */
	private static $delay_bootstrap_printed = false;

	/**
	 * Pre-minify script URLs, keyed by handle.
	 *
	 * `script_loader_src` (priority 10) rewrites a local script's URL to a
	 * hashed /cache/xspeed/min/<key>.js path long before
	 * `script_loader_tag` (priority 20/30) runs, so the delay + exclusion
	 * checks only ever see the hashed URL. A user targeting a script by
	 * URL substring — the obvious thing to do, and what the UI invites —
	 * would silently stop matching the moment minification was enabled.
	 * Minifier::rewrite_script() records the original here so those
	 * checks can test both. (FBS field report against 1.1.2)
	 *
	 * @var array<string,string>
	 */
	private static $original_src = array();

	/**
	 * Record a script's URL as it was BEFORE minification rewrote it.
	 * Called from Minifier::rewrite_script().
	 *
	 * @param string $handle Script handle.
	 * @param string $src    Original (pre-minify) URL.
	 */
	public static function remember_original_src( string $handle, string $src ): void {
		if ( '' !== $handle && '' !== $src ) {
			self::$original_src[ $handle ] = $src;
		}
	}

	/**
	 * The pre-minify URL for a handle, or '' when we never rewrote it
	 * (external script, minification off, or a handle we didn't touch).
	 *
	 * @param string $handle Script handle.
	 */
	public static function original_src( string $handle ): string {
		return isset( self::$original_src[ $handle ] ) ? self::$original_src[ $handle ] : '';
	}

	/**
	 * Reset the remembered URLs. Test-only seam.
	 */
	public static function reset_original_src(): void {
		self::$original_src = array();
	}

	/**
	 * Does a user-supplied target match this script?
	 *
	 * A target is either a script handle (exact) or a URL substring. The
	 * URL is checked against BOTH the current src and the pre-minify src,
	 * so a target written against the real asset path keeps working once
	 * minification starts rewriting URLs to hashed cache paths.
	 *
	 * @param string $needle Target from the user's list.
	 * @param string $handle Script handle.
	 * @param string $src    Current (possibly rewritten) src.
	 */
	private static function target_matches( string $needle, string $handle, string $src ): bool {
		if ( '' === $needle ) {
			return false;
		}
		if ( $handle === $needle ) {
			return true;
		}
		if ( '' !== $src && false !== stripos( $src, $needle ) ) {
			return true;
		}
		$original = self::original_src( $handle );
		return '' !== $original && false !== stripos( $original, $needle );
	}

	/**
	 * Filter: `script_loader_tag` — add defer="defer" to non-excluded
	 * scripts. WordPress passes the full <script> tag string, the
	 * handle, and the src. We bail when:
	 *   - the user excluded this handle / src substring,
	 *   - the tag already has defer or async (don't double-set),
	 *   - the tag has no src (inline scripts can't be deferred — would
	 *     execute synchronously regardless).
	 *
	 * @param string $tag
	 * @param string $handle
	 * @param string $src
	 */
	public static function defer_script_tag( $tag, $handle, $src ): string {
		if ( ! is_string( $tag ) || '' === $tag ) {
			return (string) $tag;
		}
		// Self-guard: even though Minifier::__construct() bails on admin/
		// AJAX/REST/cron at registration, a late context switch (e.g. a
		// custom wp_print_scripts() call inside an admin page render) can
		// leave the filter attached. Skipping here keeps the React admin
		// bundle's <script> tag intact so the dashboard mounts.
		if ( self::skip_in_non_frontend_context() ) {
			return $tag;
		}
		if ( '' === (string) $src ) {
			return $tag;
		}
		if ( self::is_excluded_script( (string) $handle, (string) $src ) ) {
			return $tag;
		}
		if ( false !== stripos( $tag, ' defer' ) || false !== stripos( $tag, ' async' ) ) {
			return $tag;
		}
		// Target the <script> that actually carries a src, NOT simply the
		// first one in the string. WP_Scripts::do_item() hands this filter
		// the CONCATENATION of before_inline + external + after_inline, so
		// for any handle carrying a `before` inline script the first
		// `<script` is the inline block. Deferring that is a no-op (the HTML
		// spec ignores defer on inline scripts) AND leaves the external
		// script undeferred while its dependencies get deferred — which
		// inverts WordPress's guaranteed execution order and throws in any
		// dependent that touches a global its dependency defines. (#234)
		//
		// The lookahead scans only within the tag (`[^>]*`) for ` src=`, so
		// an inline `<script id="…-js-before">` can never match.
		return (string) preg_replace( '#<script\b(?=[^>]*\ssrc\s*=)#i', '<script defer="defer"', $tag, 1 );
	}

	/**
	 * Filter: `script_loader_tag` — rewrite src= to data-xs-src= so the
	 * browser ignores it until the bootstrap (printed once on
	 * wp_footer) swaps it back on first user interaction. Same
	 * exclusion rules as defer. Inline scripts (no src) are also
	 * deferred until the first interaction.
	 *
	 * @param string $tag
	 * @param string $handle
	 * @param string $src
	 */
	public static function delay_script_tag( $tag, $handle, $src ): string {
		if ( ! is_string( $tag ) || '' === $tag ) {
			return (string) $tag;
		}
		if ( self::skip_in_non_frontend_context() ) {
			return $tag;
		}
		if ( self::is_excluded_script( (string) $handle, (string) $src ) ) {
			return $tag;
		}
		if ( ! self::is_delay_target( (string) $handle, (string) $src ) ) {
			return $tag;
		}
		// A non-executable type means this tag is data, or is being held by
		// somebody else on purpose. The buffer pass has always checked this;
		// the enqueue path did not, so a consent-blocked or JSON-carrying
		// handle could still be rewritten here. (#274)
		if ( preg_match( '#\btype\s*=\s*(["\'])(.*?)\1#is', $tag, $type_m )
			&& in_array( strtolower( trim( $type_m[2] ) ), self::NON_EXECUTABLE_TYPES, true ) ) {
			return $tag;
		}
		// src= variant: swap src → data-xs-src and add data-xs-delay marker.
		if ( '' !== (string) $src ) {
			// Anchor on the opening <script …> tag that carries the src.
			// Matching a bare `src=` across the whole string would rewrite
			// the first occurrence anywhere — including inside a `before`
			// inline block, where JS like `el.src = "…"` becomes the
			// syntax error `el.data-xs-src="…" data-xs-delay="1"` and the
			// real external script is left undelayed. $tag is the
			// concatenation of before_inline + external + after_inline,
			// so that is a routine shape, not a corner case. (#234)
			// `(?<![-\w])` where `\b` used to be. A hyphen is a non-word
			// character, so `\bsrc=` also matches the TAIL of any
			// `data-…-src=` attribute — and consent managers and other
			// optimizers park a blocked script's real URL in exactly that
			// shape. Complianz's `data-cmplz-src` became
			// `data-cmplz-data-xs-src`, so after the visitor clicked Accept
			// the plugin looked for an attribute that no longer existed and
			// the script never loaded: analytics and pixels silently dead,
			// no console error, nothing in the UI. Same class of bug as the
			// image-dimension resolver in #328. (#273)
			return (string) preg_replace(
				'#(<script\b[^>]*?)(?<![-\w])src\s*=\s*(["\'][^"\']*["\'])#i',
				'$1data-xs-src=$2 data-xs-delay="1"',
				$tag,
				1
			);
		}
		// Inline script: change type to text/plain so the browser
		// doesn't execute, mark for bootstrap rewriter.
		return (string) preg_replace(
			'#<script\b([^>]*)>#i',
			'<script$1 type="text/xspeed-delayed" data-xs-delay="1">',
			$tag,
			1
		);
	}

	/**
	 * Script types the buffer pass must never touch. `<script>` carries
	 * data as often as it carries code: JSON-LD feeds structured-data
	 * consumers, importmaps must resolve before any module runs, and our
	 * own delayed-inline marker is already handled by the bootstrap.
	 * Rewriting any of these breaks the page or its metadata.
	 */
	private const NON_EXECUTABLE_TYPES = array(
		'application/ld+json',
		'application/json',
		'importmap',
		'speculationrules',
		'text/template',
		'text/x-template',
		'text/xspeed-delayed',
		// A consent manager parks a blocked third-party script here and
		// swaps the type back only once the visitor has agreed. Whatever we
		// do to such a tag we do on behalf of a decision the visitor has not
		// made yet, so the only correct move is to leave it alone. (#274)
		'text/plain',
	);

	/**
	 * URL fragments that must keep a live src no matter what. The enqueue
	 * path guards these by handle (ALWAYS_EXCLUDED_HANDLES), but a buffer
	 * pass only ever sees a URL, so the same protection is re-expressed
	 * here. Without this the admin bundle could be delayed on a frontend
	 * render and the dashboard would not mount.
	 */
	private const ALWAYS_EXCLUDED_SRC = array(
		'/plugins/xspeed/assets/',
		'/wp-includes/js/dist/hooks',
		'/wp-includes/js/dist/i18n',
	);

	/**
	 * Delay `<script src>` tags that never passed through wp_enqueue_script.
	 *
	 * `delay_script_tag()` hooks `script_loader_tag`, so it only ever sees
	 * enqueued scripts. Analytics, pixels, chat widgets and most third-party
	 * embeds are printed straight into `wp_head` / `wp_footer` as literal
	 * markup, bypassing that filter entirely — and those are exactly the
	 * scripts most worth delaying. On the site that surfaced this, 39
	 * enqueued scripts were correctly delayed while one un-enqueued
	 * analytics tag still downloaded 441 KB: 98% of the page's JS payload.
	 *
	 * Runs on the finished page buffer via `xspeed_cache_final_html`, so the
	 * rewrite is baked into the cached HTML and replays on every static hit
	 * (where PHP never boots). Deliberately conservative — it rewrites only
	 * `src`, leaves inline code to the enqueue path, and skips any tag whose
	 * `type` marks it as data rather than code.
	 *
	 * @param string $html Complete page HTML.
	 */
	public static function delay_raw_script_tags( $html ): string {
		if ( ! is_string( $html ) || '' === $html ) {
			return (string) $html;
		}
		if ( self::skip_in_non_frontend_context() ) {
			return $html;
		}
		$opts = self::opts();
		if ( empty( $opts['delay_js'] ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'#<script\b[^>]*>#i',
			static function ( array $m ): string {
				$tag = $m[0];

				// Already handled by the enqueue-path filter.
				if ( false !== stripos( $tag, 'data-xs-delay' ) || false !== stripos( $tag, 'data-xs-src' ) ) {
					return $tag;
				}

				// No src → inline code. The enqueue path owns those; a
				// buffer rewrite here would have to reason about execution
				// order it cannot see.
				// `(?<![-\w])` not `\b` — see the note on the enqueue-path
				// rewrite above. With `\b`, a tag whose ONLY url lives in
				// `data-cmplz-src` (a consent-blocked script, no real src at
				// all) read as an external script here, and the rewrite
				// below then mangled that attribute. (#273)
				if ( ! preg_match( '#(?<![-\w])src\s*=\s*(["\'])(.*?)\1#is', $tag, $src_m ) ) {
					return $tag;
				}
				$src = $src_m[2];

				// Data, not code.
				if ( preg_match( '#\btype\s*=\s*(["\'])(.*?)\1#is', $tag, $type_m ) ) {
					$type = strtolower( trim( $type_m[2] ) );
					if ( in_array( $type, self::NON_EXECUTABLE_TYPES, true ) ) {
						return $tag;
					}
				}

				foreach ( self::ALWAYS_EXCLUDED_SRC as $needle ) {
					if ( false !== stripos( $src, $needle ) ) {
						return $tag;
					}
				}

				// Buffer-pass tags have no handle — match on URL only.
				if ( self::is_excluded_script( '', $src ) ) {
					return $tag;
				}
				if ( ! self::is_delay_target( '', $src ) ) {
					return $tag;
				}

				return (string) preg_replace(
					'#(?<![-\w])src\s*=\s*(["\'][^"\']*["\'])#i',
					'data-xs-src=$1 data-xs-delay="1"',
					$tag,
					1
				);
			},
			$html
		);
	}

	/**
	 * Inline bootstrap that flips delayed scripts on the first user
	 * interaction. Printed once on wp_footer priority 1000.
	 */
	public static function print_delay_bootstrap(): void {
		if ( self::skip_in_non_frontend_context() ) {
			return;
		}
		if ( self::$delay_bootstrap_printed ) {
			return;
		}
		self::$delay_bootstrap_printed = true;

		// Failsafe timer for visitors who never interact. 0 disables it
		// entirely (interaction-only), which is what lab tools measure
		// best: a timer that fires inside Lighthouse's / GTmetrix's
		// measurement window loads the "delayed" scripts anyway and
		// inflates the reported TTI, so the delay looks ineffective.
		$opts    = self::opts();
		$timeout = isset( $opts['delay_js_timeout'] ) ? (int) $opts['delay_js_timeout'] : 8000;
		$timeout = max( 0, min( 60000, $timeout ) );

		// Tiny vanilla bootstrap; keep it self-contained so the page
		// has no JS dependencies before the first interaction.
		?>
<script id="xspeed-delay-bootstrap">
(function(){
  var events=['mousemove','keydown','touchstart','scroll','wheel'];
  var fired=false;
  function load(){
    if(fired)return;fired=true;
    events.forEach(function(e){window.removeEventListener(e,load,{passive:true,capture:true});});
    var delayed=document.querySelectorAll('script[data-xs-delay]');
    delayed.forEach(function(s){
      var n=document.createElement('script');
      Array.prototype.slice.call(s.attributes).forEach(function(a){
        if(a.name==='data-xs-src'){n.setAttribute('src',a.value);return;}
        if(a.name==='data-xs-delay')return;
        // `type` is what a script IS, not decoration, so it is carried over
        // — with ONE exception: our own inline parking marker, which exists
        // only to stop the browser executing the original and must not be
        // copied onto the replacement. Dropping type wholesale broke two
        // things: `type="module"` became a classic script (core's Script
        // Modules — Navigation, lightbox, Query Loop — threw "Cannot use
        // import statement outside a module" on the default theme), and
        // `type="text/plain"`, which is precisely how a consent manager
        // parks a blocked third-party script, became executable again. The
        // second is a privacy failure, not a broken feature. (#274)
        if(a.name==='type'&&a.value==='text/xspeed-delayed')return;
        n.setAttribute(a.name,a.value);
      });
      if(!s.hasAttribute('data-xs-src')){n.text=s.text;}
      s.parentNode.replaceChild(n,s);
    });
  }
  events.forEach(function(e){window.addEventListener(e,load,{passive:true,capture:true});});
<?php if ( $timeout > 0 ) : ?>
  setTimeout(load,<?php echo (int) $timeout; ?>);
<?php endif; ?>
})();
</script>
		<?php
	}

	/**
	 * Filter: `style_loader_tag` — wrap stylesheets in the
	 * print → onload="all" pattern so they download non-blocking.
	 * Pairs with critical CSS workflows. Adds a <noscript> fallback so
	 * users with JS disabled still get styles applied (via media="all").
	 *
	 * @param string $tag
	 * @param string $handle
	 */
	public static function async_style_tag( $tag, $handle ): string {
		if ( ! is_string( $tag ) || '' === $tag ) {
			return (string) $tag;
		}
		if ( self::skip_in_non_frontend_context() ) {
			return $tag;
		}
		// Only operate on <link rel=stylesheet> with a media attribute
		// we can swap. Skip anything custom (preload, etc.) — we don't
		// want to fight with explicit author intent.
		if ( false === stripos( $tag, 'rel=\'stylesheet\'' ) && false === stripos( $tag, 'rel="stylesheet"' ) ) {
			return $tag;
		}
		// The stylesheets that lay the page out stay render-blocking.
		//
		// This transform moves a sheet to AFTER first paint. That is the
		// point of it — but a sheet the layout depends on is then missing
		// from the only paint the visitor sees, and the page renders as
		// unstyled HTML (bulleted nav, underlined links) until the swap
		// runs. The pattern is only safe when something already styles the
		// above-the-fold area, i.e. critical CSS — which Free does not
		// generate. Deferring EVERY sheet on a site without it guarantees
		// the flash rather than risking it: on the reported Kadence site
		// all 17 stylesheets were deferred and none was render-blocking,
		// so there was nothing left to paint the page with. (#269)
		if ( self::is_layout_critical_style( $handle ) ) {
			return $tag;
		}
		// A JS-measured layout on this page makes deferral unsafe for EVERY
		// sheet, not just the theme's.
		//
		// Masonry, isotope, packery and the slider libraries lay elements out
		// by MEASURING them and then writing absolute positions. Deferring the
		// stylesheet that sizes those elements means the script measures them
		// unstyled — zero or full-width — computes positions from those wrong
		// numbers, and commits them. The CSS arriving a moment later cannot
		// undo it: the script has already run and does not re-measure. The
		// result is a permanently broken grid (items overlapping, or stranded
		// with a large gap), which is worse than the flash this feature's
		// other guard prevents, because it never resolves itself.
		//
		// This is checked per PAGE rather than per handle deliberately. The
		// script that measures is rarely the one whose handle matches the
		// sheet — Kadence's gallery is styled by
		// `kadence-blocks-advancedgallery` but laid out by core's `masonry` —
		// so pairing handles misses it. Whether a measuring library is present
		// at all is the signal that generalises. (#269)
		if ( self::page_has_js_measured_layout() ) {
			return $tag;
		}
		// Avoid double-wrapping.
		if ( false !== stripos( $tag, 'data-xs-async' ) ) {
			return $tag;
		}
		// Someone else already made this sheet non-render-blocking.
		//
		// Plugins that ship their own async-CSS handling apply the same
		// media="print" + onload swap we do, and they run on the SAME
		// filter — SureCookie's consent banner does it at style_loader_tag
		// priority 10, ours is priority 20, so its finished tag arrives
		// here looking like a plain stylesheet with no marker of ours.
		//
		// Transforming it again breaks the sheet two ways: the media we'd
		// capture as "the original to restore" is already `print`, so we
		// emit onload="this.media='print'" — a swap to itself that never
		// activates the stylesheet — and we append a SECOND onload
		// attribute, of which the parser honours only the first (ours),
		// discarding the plugin's correct this.media='all'. The banner
		// then mounts unstyled, in both logged-in and logged-out states.
		//
		// An onload handler or a print media on a stylesheet link is only
		// ever this pattern; a genuinely print-only sheet is already off
		// the critical path and gains nothing from us. Either way the
		// right move is to leave the tag alone — the same "don't fight
		// explicit author intent" rule the rel= check above applies. (#216)
		if ( preg_match( '#\bonload\s*=#i', $tag ) ) {
			return $tag;
		}
		if ( preg_match( '#\bmedia\s*=\s*(["\'])\s*print\s*\1#i', $tag ) ) {
			return $tag;
		}
		$async = (string) preg_replace_callback(
			'#\bmedia\s*=\s*(["\'])([^"\']*)\1#i',
			static function ( $m ) {
				$orig = $m[2];
				return 'media="print" onload="this.media=\'' . esc_attr( $orig ) . '\'" data-xs-async="' . esc_attr( $orig ) . '"';
			},
			$tag,
			1
		);
		// If no media= was present (rare), inject one.
		if ( $async === $tag ) {
			$async = (string) preg_replace(
				'#<link\b#i',
				'<link media="print" onload="this.media=\'all\'" data-xs-async="all"',
				$tag,
				1
			);
		}
		// Fallback for noscript users — re-emit the original tag inside <noscript>.
		return $async . '<noscript>' . $tag . '</noscript>';
	}

	/**
	 * Whether a stylesheet handle carries the page's layout, and so must
	 * keep blocking the first paint.
	 *
	 * Two families qualify:
	 *
	 *  - The ACTIVE THEME's own sheets. A theme stylesheet is the page's
	 *    layout by definition; without it the document paints as unstyled
	 *    HTML. Resolved from the live theme's stem (`kadence` →
	 *    `kadence-global`, `kadence-header`, …) plus the handles WordPress
	 *    itself registers for a theme, so this holds for any theme rather
	 *    than a hard-coded list.
	 *  - WordPress' own BLOCK and layout sheets (`wp-block-library`,
	 *    `global-styles`, `classic-theme-styles`). These style block
	 *    content on the front end and are as structural as the theme's.
	 *
	 * Everything else — plugin sheets, icon fonts, widget and page-builder
	 * add-ons, the long tail that makes async CSS worth having — is still
	 * deferred, so the optimization keeps most of its benefit.
	 *
	 * A site WITH critical CSS can defer these too; that is what the
	 * `xspeed_async_css_layout_critical` filter is for.
	 *
	 * Pure aside from the theme lookup — unit-tested via the filter.
	 *
	 * @param string $handle Stylesheet handle from `style_loader_tag`.
	 */
	public static function is_layout_critical_style( string $handle ): bool {
		$handle = strtolower( $handle );

		// Core's front-end block + global styles.
		$core = array(
			'wp-block-library',
			'wp-block-library-theme',
			'global-styles',
			'classic-theme-styles',
		);
		$critical = in_array( $handle, $core, true );

		// The active theme's own sheets.
		//
		// Matched on the theme stem, but NOT as a bare prefix: a plugin from
		// the same vendor shares it (the Kadence theme is `kadence`, while
		// `kadence-blocks-rowlayout` and `kadence-fonts-gfonts` come from the
		// Kadence Blocks PLUGIN and a webfont loader). Treating those as
		// layout-critical would leave almost nothing deferred and quietly
		// undo the feature. So the stem must be followed by a recognised
		// theme-area segment, which is how themes name their split sheets.
		if ( ! $critical && function_exists( 'get_template' ) ) {
			$areas = array(
				'style',
				'global',
				'header',
				'content',
				'footer',
				'main',
				'layout',
				'base',
				'core',
				'theme',
				'woocommerce',
			);
			foreach ( array( get_template(), get_stylesheet() ) as $stem ) {
				$stem = strtolower( (string) $stem );
				if ( '' === $stem ) {
					continue;
				}
				if ( $handle === $stem ) {
					$critical = true;
					break;
				}
				foreach ( $areas as $area ) {
					if ( $handle === $stem . '-' . $area ) {
						$critical = true;
						break 2;
					}
				}
			}
		}

		/**
		 * Whether this stylesheet must keep blocking the first paint.
		 *
		 * Return false for a handle to let async CSS defer it anyway — the
		 * right call on a site that ships critical CSS. Return true to
		 * protect an additional sheet the layout depends on.
		 *
		 * @param bool   $critical Whether the sheet is treated as layout-critical.
		 * @param string $handle   The stylesheet handle.
		 */
		return (bool) apply_filters( 'xspeed_async_css_layout_critical', $critical, $handle );
	}

	/**
	 * Filter: `style_loader_src` + `script_loader_src` — strip the
	 * ?ver=X.Y query string that WP appends for cache busting. Some
	 * CDNs / reverse proxies cache better when the URL has no query.
	 *
	 * Skip URLs whose query carries non-ver params — those might be
	 * intentional (e.g. a CDN providing per-image transforms).
	 *
	 * @param string $src
	 */
	public static function strip_version_query( $src ): string {
		if ( ! is_string( $src ) || '' === $src ) {
			return (string) $src;
		}
		if ( self::skip_in_non_frontend_context() ) {
			return $src;
		}
		$parts = wp_parse_url( $src );
		if ( ! is_array( $parts ) || empty( $parts['query'] ) ) {
			return $src;
		}
		parse_str( $parts['query'], $query );
		if ( ! is_array( $query ) ) {
			return $src;
		}
		// Only strip 'ver' — keep anything else the asset URL needs.
		unset( $query['ver'] );
		$new_query = http_build_query( $query );
		$new_url   = ( $parts['scheme'] ?? 'http' ) . '://' . ( $parts['host'] ?? '' );
		if ( isset( $parts['port'] ) ) {
			$new_url .= ':' . $parts['port'];
		}
		$new_url .= $parts['path'] ?? '';
		if ( '' !== $new_query ) {
			$new_url .= '?' . $new_query;
		}
		if ( ! empty( $parts['fragment'] ) ) {
			$new_url .= '#' . $parts['fragment'];
		}
		return $new_url;
	}

	/**
	 * Defensive context guard for filter callbacks. Mirrors the registration-
	 * time bail in Minifier::__construct() so a late context flip (admin page
	 * render kicked off mid-request, REST_REQUEST set after plugins_loaded,
	 * etc.) doesn't let frontend tag rewrites leak into wp-admin / AJAX /
	 * REST / cron responses.
	 *
	 * Specifically prevents the React admin bundle's <script> tag from being
	 * deferred or src-swapped to data-xs-src — which would stop the dashboard
	 * from booting and make toggles appear unchecked until first interaction.
	 */
	private static function skip_in_non_frontend_context(): bool {
		if ( is_admin() ) {
			return true;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return true;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return true;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		return false;
	}

	/**
	 * Built-in exclusion list — always skipped regardless of user settings.
	 * Covers our own admin bundle and the WP script-modules it depends on,
	 * so that even if the registration-time admin guard is somehow bypassed,
	 * the dashboard's React app can still boot.
	 */
	private const ALWAYS_EXCLUDED_HANDLES = array(
		'xspeed-admin',
		'wp-hooks',
		'wp-i18n',
		'wp-url',
		'wp-api-fetch',
	);

	private static function is_excluded_script( string $handle, string $src ): bool {
		if ( in_array( $handle, self::ALWAYS_EXCLUDED_HANDLES, true ) ) {
			return true;
		}
		$opts     = self::opts();
		$excluded = is_array( $opts['defer_js_excluded'] ?? null ) ? $opts['defer_js_excluded'] : array();
		if ( empty( $excluded ) ) {
			return false;
		}
		foreach ( $excluded as $needle ) {
			// Matched against the pre-minify URL too: an exclusion that
			// stops matching is worse than a delay target that does — the
			// script the user explicitly protected gets deferred anyway.
			if ( self::target_matches( (string) $needle, $handle, $src ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Include-list targeting for delay (issue #36): when delay_js_targets
	 * is non-empty, ONLY matching scripts are delayed — a heavy
	 * third-party embed can be postponed without delaying the whole
	 * page's JS. Empty targets = historical behavior (delay everything
	 * minus exclusions). Same matching semantics as the exclusion list:
	 * exact handle match OR case-insensitive URL substring.
	 */
	private static function is_delay_target( string $handle, string $src ): bool {
		$opts    = self::opts();
		$targets = is_array( $opts['delay_js_targets'] ?? null ) ? $opts['delay_js_targets'] : array();
		$targets = array_filter( array_map( 'strval', $targets ), static fn( $t ) => '' !== $t );
		if ( empty( $targets ) ) {
			return true;
		}
		foreach ( $targets as $needle ) {
			if ( self::target_matches( $needle, $handle, $src ) ) {
				return true;
			}
		}
		return false;
	}

	private static function opts(): array {
		if ( null === self::$opts ) {
			self::$opts = Settings_Manager::get( 'minify' );
		}
		return self::$opts;
	}

	/**
	 * Test-only — clear cached opts + bootstrap-printed flag.
	 */
	public static function reset_state(): void {
		self::$opts                    = null;
		self::$delay_bootstrap_printed = false;
		self::$js_measured_layout      = null;
	}

	/**
	 * Per-request memo for page_has_js_measured_layout(). Null = not resolved.
	 *
	 * @var bool|null
	 */
	private static $js_measured_layout = null;

	/**
	 * Scripts that lay out the page by measuring the DOM.
	 *
	 * Each of these reads element sizes and then writes positions. If the CSS
	 * that sizes those elements has not applied when the script runs, it
	 * measures the wrong values and commits a broken layout that no later
	 * stylesheet can correct.
	 *
	 * Matched as a substring of the registered handle, so a plugin shipping
	 * `acme-masonry` or `masonry-init` is covered without naming it here.
	 *
	 * @return string[]
	 */
	private static function js_layout_script_markers(): array {
		return array(
			'masonry',
			'isotope',
			'packery',
			'salvattore',
			'justified-gallery',
			'slick',
			'splide',
			'swiper',
			'flickity',
			'owl-carousel',
			'matchheight',
		);
	}

	/**
	 * True when a script that measures the DOM to build a layout is enqueued
	 * for this request.
	 *
	 * Reads the enqueue registry rather than the finished HTML, because this
	 * runs on `style_loader_tag` — while the head is being printed, before any
	 * body markup exists to scan. Both the queue and each queued handle's
	 * dependencies are checked: core registers `masonry` as a DEPENDENCY of a
	 * plugin's init script, so it is frequently absent from the queue itself.
	 *
	 * Pure aside from the global registry read; the result is memoised per
	 * request and cleared by reset_state().
	 */
	public static function page_has_js_measured_layout(): bool {
		if ( null !== self::$js_measured_layout ) {
			return self::$js_measured_layout;
		}

		$found = false;
		if ( function_exists( 'wp_scripts' ) ) {
			$scripts = wp_scripts();
			if ( $scripts instanceof \WP_Scripts ) {
				$handles = (array) $scripts->queue;
				// Pull in dependencies — `masonry` usually arrives that way.
				foreach ( (array) $scripts->queue as $queued ) {
					if ( isset( $scripts->registered[ $queued ]->deps ) ) {
						$handles = array_merge( $handles, (array) $scripts->registered[ $queued ]->deps );
					}
				}
				$markers = self::js_layout_script_markers();
				foreach ( $handles as $handle ) {
					$handle = strtolower( (string) $handle );
					foreach ( $markers as $marker ) {
						if ( false !== strpos( $handle, $marker ) ) {
							$found = true;
							break 2;
						}
					}
				}
			}
		}

		/**
		 * Whether this request renders a JS-measured layout, making async CSS
		 * unsafe for the whole page.
		 *
		 * Return false to defer anyway (a site that ships critical CSS, or one
		 * whose grid is pure CSS), or true to protect a library not detected
		 * by handle.
		 *
		 * @param bool $found Whether a measuring script was detected.
		 */
		self::$js_measured_layout = (bool) apply_filters( 'xspeed_async_css_js_measured_layout', $found );

		return self::$js_measured_layout;
	}
}
