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
		// Inline code elsewhere on the page reads this handle (or something
		// it depends on). Inline blocks never defer, so deferring this one
		// would run the consumer first. Defer only — delay is an opt-in
		// target list, where the user has named the script deliberately.
		if ( isset( self::inline_bound_handles()[ (string) $handle ] ) ) {
			return $tag;
		}
		// NB: is_protected_from_bundling() is the same two rules in one call
		// for the combiner; the split here is deliberate, since the
		// exclusion check above already ran and short-circuits earlier.
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
		if ( in_array( self::extract_type( $tag ), self::NON_EXECUTABLE_TYPES, true ) ) {
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
		// Inline script: change type to text/xspeed-delayed so the browser
		// doesn't execute, mark for bootstrap rewriter. Any existing type
		// is REPLACED, not appended-after: HTML keeps an attribute's first
		// occurrence, so a snippet carrying its own `type="text/javascript"`
		// would win over a marker appended behind it and keep executing.
		// A non-default original type is stashed in data-xs-type so the
		// bootstrap can restore it on replay (#274 — type is what a script
		// IS; a parked `type="module"` must come back as a module).
		$tag = (string) preg_replace_callback(
			'#<script\b([^>]*)>#i',
			static function ( array $m ): string {
				return '<script' . self::park_type_attrs( $m[1] ) . '>';
			},
			$tag,
			1
		);
		return $tag;
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
	 * The `type` attribute, quoted OR unquoted, anchored to attribute
	 * position — a required leading whitespace, never a bare `\b`.
	 *
	 * The anchoring matters twice over. `\btype` also matches the tail of
	 * any hyphenated `data-…-type` attribute (a `-` is a non-word char, so
	 * the boundary sits inside the name — the same #273 class as `src`),
	 * and it matches a `type=` sitting INSIDE another attribute's value
	 * (`onload="this.type='done'"`). Requiring whitespace before the name
	 * rules both out: attributes are whitespace-separated, while `.type`
	 * and `-type` never are. The unquoted branch exists because
	 * `type=text/javascript` is valid HTML: a quoted-only pattern left it
	 * standing, the parking type appended after it lost the
	 * first-occurrence race, and the snippet executed immediately AND
	 * replayed on interaction — every vendor event fired twice.
	 */
	private const TYPE_ATTR_RE = '#\stype\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))#is';

	/**
	 * `type` values a parked tag need not remember: the replay default is
	 * already JavaScript, so stashing these would only fatten the markup.
	 */
	private const DEFAULT_JS_TYPES = array(
		'text/javascript',
		'application/javascript',
	);

	/**
	 * Read a tag's `type` attribute value, lowercased and trimmed.
	 *
	 * @param string $haystack Full tag or its attribute string.
	 * @return string '' when no type attribute is present.
	 */
	private static function extract_type( string $haystack ): string {
		if ( ! preg_match( self::TYPE_ATTR_RE, $haystack, $m ) ) {
			return '';
		}
		$value = ( isset( $m[3] ) && '' !== $m[3] ) ? $m[3] : $m[2];
		return strtolower( trim( $value ) );
	}

	/**
	 * Rewrite an inline tag's attribute string for parking: strip its own
	 * `type`, stash a non-default one in `data-xs-type` (the bootstrap
	 * restores it on replay, so a parked `type="module"` comes back as a
	 * module rather than a classic script — #274), and append the parking
	 * marker pair.
	 *
	 * @param string $attrs Raw attribute string (everything between
	 *                      `<script` and `>`).
	 */
	private static function park_type_attrs( string $attrs ): string {
		$orig  = self::extract_type( $attrs );
		$attrs = (string) preg_replace( self::TYPE_ATTR_RE, '', $attrs );
		$stash = '';
		if ( '' !== $orig && ! in_array( $orig, self::DEFAULT_JS_TYPES, true ) ) {
			// MIME-ish charset only — a type value is never markup, and this
			// string is re-emitted inside a double-quoted attribute.
			$orig = (string) preg_replace( '#[^a-z0-9/+.\-]#', '', $orig );
			if ( '' !== $orig ) {
				$stash = ' data-xs-type="' . $orig . '"';
			}
		}
		return $attrs . $stash . ' type="text/xspeed-delayed" data-xs-delay="1"';
	}

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
				if ( in_array( self::extract_type( $tag ), self::NON_EXECUTABLE_TYPES, true ) ) {
					return $tag;
				}

				foreach ( self::ALWAYS_EXCLUDED_SRC as $needle ) {
					if ( false !== stripos( $src, $needle ) ) {
						return $tag;
					}
				}

				// Recover the handle from the tag's id before deciding.
				//
				// This pass used to pass '' as the handle, on the reasoning
				// that a tag reaching the buffer was never enqueued and so has
				// none. That holds for the third-party snippets this pass
				// exists for — but NOT for enqueued scripts, which also travel
				// through here, and which WordPress prints with
				// `id="<handle>-js"`. Passing '' meant every handle-based
				// exclusion was silently inert at this layer: the user writes
				// `jquery-core`, the enqueue path honours it, and then the
				// buffer pass — which only ever compared URLs — delayed the
				// very script the list was protecting.
				//
				// That is how a site with jquery-core AND jquery-migrate
				// excluded still shipped jQuery delayed while migrate loaded
				// normally, and every inline `jQuery(...)` on the page threw
				// "jQuery is not defined". The two behaved differently for no
				// reason a user could see, which is what made it look like a
				// matching quirk rather than a whole layer ignoring the list.
				$tag_handle = '';
				if ( preg_match( '#\sid\s*=\s*(["\'])(.*?)\1#i', $tag, $id_m ) ) {
					// WP appends `-js`; anything else is somebody's own id and
					// is still worth matching literally.
					$tag_handle = (string) preg_replace( '/-js$/', '', $id_m[2] );
				}

				if ( self::is_excluded_script( $tag_handle, $src ) ) {
					return $tag;
				}
				if ( ! self::is_delay_target( $tag_handle, $src ) ) {
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
	 * Delay inline vendor snippets that reference a known third-party host.
	 *
	 * The pass above rewrites `src` and deliberately leaves inline code
	 * alone — but the OFFICIAL install for Clarity, GA, GTM and the Meta
	 * pixel is an inline loader (`(function(c,l,a,r,i,t,y){…t.src=…})`)
	 * with no `src` attribute at all. That snippet executes on every page
	 * load, fetches the vendor bundle inside the measurement window, and
	 * puts the one host whose Cache-Control the site cannot set straight
	 * into the cache-policy and TBT audits. Delaying the enqueue path and
	 * the raw-src path while this runs untouched is delaying everything
	 * except the tag the feature exists for.
	 *
	 * The judgment call is the same one KNOWN_THIRD_PARTY_SRC already
	 * makes: an inline body that names one of those hosts is that vendor's
	 * loader or its config — never something first-party code holds a
	 * synchronous reference to. The body is the haystack for the user's
	 * exclusion and target lists too, so the same fragment that protects a
	 * `src` tag protects its inline install.
	 *
	 * `document.write` bodies are skipped outright: replayed after the
	 * parser has closed the document, a delayed write would replace the
	 * page rather than add to it.
	 *
	 * @param string $html Complete page HTML.
	 */
	public static function delay_inline_snippets( $html ): string {
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

		$out = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			static function ( array $m ): string {
				list( $whole, $attrs, $body ) = $m;

				if ( '' === trim( $body ) ) {
					return $whole;
				}

				// Our own replay bootstrap. Its body quotes the delay
				// machinery's own strings, so a pathological user target
				// fragment could match it — and a parked bootstrap means
				// nothing on the page ever replays.
				if ( false !== stripos( $attrs, 'xspeed-delay-bootstrap' ) ) {
					return $whole;
				}

				// Already marked, or a real src= — the src passes own those.
				// `(?<![-\w])` for the same reason as above: `data-cmplz-src`
				// must not read as a src. (#273)
				if ( false !== stripos( $attrs, 'data-xs-delay' ) || false !== stripos( $attrs, 'data-xs-src' ) ) {
					return $whole;
				}
				if ( preg_match( '#(?<![-\w])src\s*=\s*(["\']).*?\1#is', $attrs ) ) {
					return $whole;
				}

				// Data, a module map, or a consent manager's parked tag.
				if ( in_array( self::extract_type( $attrs ), self::NON_EXECUTABLE_TYPES, true ) ) {
					return $whole;
				}

				// A delayed document.write replays after the document has
				// closed and replaces the page. Never delay one.
				if ( false !== stripos( $body, 'document.write' ) ) {
					return $whole;
				}

				// The body stands in for the URL in the lists the src passes
				// consult — but NOT via is_delay_target(), whose empty-list
				// default is "delay everything". That default is right for a
				// tag with a URL and catastrophic here: it would park every
				// inline script on the page. Inline code is delayed only on a
				// positive identification — the body names a known vendor
				// host, or a fragment the user targeted — and the exclusion
				// list still wins first.
				if ( self::is_excluded_script( '', $body ) ) {
					return $whole;
				}
				if ( ! self::matches_known_third_party( $body ) && ! self::matches_user_targets( $body ) ) {
					return $whole;
				}

				// Replace — not append — any existing type. Attributes keep
				// their FIRST occurrence in HTML, so appending the parking
				// type after the snippet's own `type="text/javascript"`
				// would leave the original executable. A non-default type is
				// stashed in data-xs-type for the bootstrap to restore.
				return '<script' . self::park_type_attrs( $attrs ) . '>' . $body . '</script>';
			},
			$html
		);
		// A PCRE failure (backtrack limit on a huge inline body) returns
		// null — and casting that to '' would serve AND cache a blank page.
		// The unrewritten original is always the safe fallback.
		return null === $out ? $html : $out;
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
      // Nonce hiding: a connected element's nonce CONTENT attribute reads
      // as "", so copying it via the attribute loop would hand the clone
      // an empty nonce and a nonce-based CSP would block the replay. The
      // IDL property still carries the real value.
      if(s.nonce){n.nonce=s.nonce;}
      Array.prototype.slice.call(s.attributes).forEach(function(a){
        if(a.name==='data-xs-src'){n.setAttribute('src',a.value);return;}
        if(a.name==='data-xs-delay')return;
        if(a.name==='nonce')return;
        // A parked inline tag's ORIGINAL type (module, mostly) rides in
        // data-xs-type — restore it, or the replay runs a module as a
        // classic script and its imports throw. (#274)
        if(a.name==='data-xs-type'){n.setAttribute('type',a.value);return;}
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
		return self::async_link_markup( $tag );
	}

	/**
	 * The one place the async-CSS output shape lives: swap the link's media
	 * to `print`, restore the original media onload, record it in
	 * `data-xs-async`, and re-emit the untouched tag inside `<noscript>` for
	 * clients that never run the onload handler.
	 *
	 * Shared by the enqueue-path filter above and the raw-tag buffer pass
	 * below so the two can never drift — Pro's Critical CSS recognises this
	 * exact marker to avoid double-wrapping, and a second copy of the
	 * pattern is how that kind of contract quietly breaks.
	 *
	 * Callers own every skip decision (markers, onload, non-screen media);
	 * this helper only produces the markup.
	 *
	 * @param string $tag A `<link rel="stylesheet">` tag deemed safe to defer.
	 */
	private static function async_link_markup( string $tag ): string {
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
	 * Stylesheet hosts that serve FONT CSS — small, render-blocking sheets of
	 * `@font-face` rules. The buffer pass below defers only these: a raw
	 * cross-origin `<link>` could carry anything, and blindly deferring an
	 * unknown vendor's layout CSS from the buffer would reintroduce the
	 * unstyled-flash failure async_style_tag()'s guards exist to prevent.
	 * Font CSS is the safe subset — text renders in a fallback face and swaps,
	 * which is exactly what `font-display: swap` does on purpose.
	 */
	private const FONT_CSS_HOSTS = array(
		'fonts.googleapis.com',
		'fonts.bunny.net',
		'use.typekit.net',
		'p.typekit.net',
		'fonts.cdnfonts.com',
	);

	/**
	 * The font-CSS host allowlist, filtered and normalised.
	 *
	 * @return string[] Lowercase hostnames.
	 */
	private static function font_css_hosts(): array {
		/**
		 * Hosts whose stylesheet links the async-CSS buffer pass rewrites to
		 * the non-blocking print → onload pattern. Only font-CSS providers
		 * belong here: every listed host's sheets are safe to load late
		 * because they only add `@font-face` rules.
		 *
		 * @param string[] $hosts Hostnames (exact match, case-insensitive).
		 */
		$hosts = (array) apply_filters( 'xspeed_async_css_font_hosts', self::FONT_CSS_HOSTS );

		return array_map( 'strtolower', array_map( 'strval', $hosts ) );
	}

	/**
	 * Media values that never apply to a screen paint. A sheet restricted to
	 * one of these is not render-blocking for screen, so deferring it saves
	 * nothing — and `print` in particular is either a genuine print sheet or
	 * somebody's finished async pattern, both of which must be left alone.
	 */
	private const NON_SCREEN_MEDIA = array(
		'print',
		'speech',
		'aural',
		'braille',
		'embossed',
		'handheld',
		'projection',
		'tty',
		'tv',
	);

	/**
	 * Filter: `xspeed_cache_final_html` — defer RAW font-CSS stylesheet links
	 * that never passed through wp_enqueue_style.
	 *
	 * `async_style_tag()` hooks `style_loader_tag`, so it only ever sees
	 * enqueued stylesheets. Themes and font plugins print Google Fonts (and
	 * Bunny, Typekit, CDNFonts) as literal
	 * `<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=…">`
	 * markup in the head — on the site that surfaced this, four such tags —
	 * and each one stays render-blocking with no plugin lever. Unused CSS
	 * skips cross-origin hrefs by design, so nothing else picks them up.
	 *
	 * Runs on the finished page buffer, so the rewrite is baked into the
	 * cached HTML and replays on every static hit. Deliberately narrow: only
	 * links whose host is on the font-CSS allowlist are touched — see
	 * FONT_CSS_HOSTS. Same-origin links (no host, or the site's own) never
	 * match the allowlist and are untouched.
	 *
	 * @param string $html Complete page HTML.
	 */
	public static function async_raw_font_css_links( $html ): string {
		if ( ! is_string( $html ) || '' === $html ) {
			return (string) $html;
		}
		if ( self::skip_in_non_frontend_context() ) {
			return $html;
		}
		$opts = self::opts();
		if ( empty( $opts['async_css'] ) ) {
			return $html;
		}

		// Never rewrite inside a <noscript>. That block IS the no-JS
		// fallback — its <link> is a plain blocking stylesheet on purpose,
		// and async_style_tag() itself emits one for every sheet it defers.
		// Rewriting it would nest <noscript> (invalid; the parser closes the
		// outer block at the first </noscript>) and hand no-JS visitors a
		// media="print" sheet whose onload never runs: no stylesheet at all.
		// Splitting the buffer on <noscript> spans and rewriting only the
		// slices between them also makes the pass idempotent against
		// whatever an earlier pass emitted.
		$parts = preg_split(
			'#(<noscript\b[^>]*>.*?</noscript\s*>)#is',
			$html,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		// preg_split failed (pathological buffer / backtrack limit). Without
		// the split we cannot tell a fallback link from a live one, so leave
		// the page untouched — a few blocking font sheets beat a broken
		// no-JS fallback.
		if ( ! is_array( $parts ) ) {
			return $html;
		}

		foreach ( $parts as $i => $part ) {
			// Odd indices are the captured <noscript> blocks.
			if ( 1 === $i % 2 || '' === $part ) {
				continue;
			}
			$parts[ $i ] = self::async_font_links_in_slice( $part );
		}

		return implode( '', $parts );
	}

	/**
	 * Rewrite the font-CSS links in one <noscript>-free slice of the buffer.
	 *
	 * @param string $html Slice of page HTML with no <noscript> spans.
	 */
	private static function async_font_links_in_slice( string $html ): string {
		$hosts = self::font_css_hosts();

		$out = preg_replace_callback(
			'#<link\b[^>]*>#i',
			static function ( array $m ) use ( $hosts ): string {
				$tag = $m[0];

				// Only plain stylesheets — never preload/alternate/anything
				// carrying explicit author intent. `(?<![-\w])` not `\b`, so
				// a `data-rel=` attribute can never read as the rel — same
				// reason the delay passes spell src that way. (#273)
				if ( ! preg_match( '#(?<![-\w])rel\s*=\s*(["\']?)\s*stylesheet\s*\1#i', $tag ) ) {
					return $tag;
				}

				// Already deferred (either marker spelling — ours and Pro's),
				// or explicitly opted out by the theme.
				foreach ( array( 'data-xs-async', 'data-xspeed-async', 'data-xspeed-keep' ) as $marker ) {
					if ( false !== stripos( $tag, $marker ) ) {
						return $tag;
					}
				}

				// An onload handler on a stylesheet link is only ever
				// somebody's finished async pattern — same rule as
				// async_style_tag(). (#216)
				if ( preg_match( '#(?<![-\w])onload\s*=#i', $tag ) ) {
					return $tag;
				}

				// A sheet that never applies on screen is not blocking paint.
				if ( preg_match( '#(?<![-\w])media\s*=\s*(["\'])([^"\']*)\1#i', $tag, $mm )
					&& in_array( strtolower( trim( $mm[2] ) ), self::NON_SCREEN_MEDIA, true ) ) {
					return $tag;
				}

				if ( ! preg_match( '#(?<![-\w])href\s*=\s*(["\'])([^"\']+)\1#i', $tag, $hm ) ) {
					return $tag;
				}
				// No host means a relative URL — same-origin, and the enqueue
				// path's business if it is anybody's.
				$host = strtolower( (string) wp_parse_url( $hm[2], PHP_URL_HOST ) );
				if ( '' === $host || ! in_array( $host, $hosts, true ) ) {
					return $tag;
				}

				return self::async_link_markup( $tag );
			},
			$html
		);

		// A PCRE failure returns null — the unrewritten slice is the safe
		// fallback, never an empty page.
		return null === $out ? $html : $out;
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
	 * `ver` is load-bearing on one class of asset: a file a plugin
	 * REGENERATES IN PLACE. Complianz rewrites
	 * uploads/complianz/css/banner-1-optin.css whenever the banner is
	 * edited, Beaver Builder rewrites uploads/bb-plugin/cache/<post>-layout.css
	 * on every layout save, Elementor uploads/elementor/css/post-<id>.css on
	 * publish. The path never changes, so `?ver=<timestamp|hash>` is the only
	 * thing telling a browser — or our own Browser Cache `immutable` rule — to
	 * refetch. Strip it and the old styling is served until the browser cache
	 * gives up, which for us is a year. So anything under the uploads root
	 * keeps its version.
	 *
	 * Release assets under plugins/, themes/ and core are still stripped, but
	 * not because they are safe: an update overwrites the same path there too,
	 * and only `?ver=` changed. The difference is frequency, not mechanism — a
	 * plugin update lands rarely and is expected to, a banner edit is a setting
	 * the user just changed and expects to see. Stripping is the feature the
	 * toggle is for; with Browser Cache on it is what the user is buying, and
	 * `docs/user/minification.md` states the cost. (#276)
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
		if ( ! is_array( $query ) || ! array_key_exists( 'ver', $query ) ) {
			return $src;
		}

		$strip = ! self::is_regenerated_asset( $parts );

		/**
		 * Whether Remove Query Strings drops `?ver` from this asset URL.
		 *
		 * False by default under the uploads root, where page builders and
		 * consent plugins rewrite generated CSS/JS in place and `ver` is its
		 * only cache-buster. Return false to protect a generator that writes
		 * somewhere else, true to force stripping.
		 *
		 * @param bool   $strip Whether `ver` will be removed.
		 * @param string $src   The asset URL as enqueued.
		 */
		if ( ! apply_filters( 'xspeed_strip_asset_version', $strip, $src ) ) {
			return $src;
		}

		// Only strip 'ver' — keep anything else the asset URL needs.
		unset( $query['ver'] );
		$new_query = http_build_query( $query );

		// Rebuild the authority only when the source had one. An enqueued
		// src is not always absolute: `//cdn.example/x.css` says "the
		// page's own scheme", and defaulting that to http:// is mixed
		// content an https page blocks outright; `/wp-includes/x.js` has no
		// host at all, and pasting one in produced `http:///wp-includes/…`,
		// which resolves nowhere.
		$new_url = '';
		if ( isset( $parts['host'] ) && '' !== $parts['host'] ) {
			$new_url = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//';
			$new_url .= $parts['host'];
			if ( isset( $parts['port'] ) ) {
				$new_url .= ':' . $parts['port'];
			}
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
	 * Memoised uploads root, see uploads_base(). Cleared by reset_state().
	 *
	 * @var array{host:string,path:string}|null
	 */
	private static $uploads_base = null;

	/**
	 * The uploads root as a URL host + PATH, read from wp_get_upload_dir()
	 * rather than hardcoded so a moved uploads dir, the `UPLOADS` constant and
	 * the legacy multisite `/files/` layout all work.
	 *
	 * On multisite wp_get_upload_dir() answers with the per-site
	 * `…/uploads/sites/<id>`. Generated assets live under the network root
	 * too, so the suffix comes off and the whole tree matches.
	 *
	 * @return array{host:string,path:string}
	 */
	private static function uploads_base(): array {
		if ( null !== self::$uploads_base ) {
			return self::$uploads_base;
		}
		$base = '';
		if ( function_exists( 'wp_get_upload_dir' ) ) {
			$dir  = wp_get_upload_dir();
			$base = is_array( $dir ) && isset( $dir['baseurl'] ) ? (string) $dir['baseurl'] : '';
		}
		$host = '';
		$path = '';
		if ( '' !== $base ) {
			$host = strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) );
			$path = (string) wp_parse_url( $base, PHP_URL_PATH );
		}
		$path = (string) preg_replace( '#/sites/\d+/?$#', '', rtrim( $path, '/' ) );
		if ( '' === $path && '' === $host ) {
			// Unreadable. An empty prefix would match every asset on the
			// site, so fall back to where uploads normally is.
			$path = '/wp-content/uploads';
		}
		self::$uploads_base = array(
			'host' => $host,
			'path' => $path,
		);
		return self::$uploads_base;
	}

	/**
	 * Does this URL sit under the uploads root — i.e. is it a file some plugin
	 * generates at runtime and rewrites in place?
	 *
	 * @param array<string,mixed> $parts wp_parse_url() output for the asset.
	 */
	private static function is_regenerated_asset( array $parts ): bool {
		$base = self::uploads_base();

		if ( '' !== $base['path'] ) {
			// Path only, never host: a pull-zone CDN, a protocol-relative URL
			// and an http/https flip all leave the path alone.
			$path = (string) ( $parts['path'] ?? '' );
			return '' !== $path && 0 === strpos( $path, $base['path'] . '/' );
		}

		// Uploads AT the root of their own domain — an offload plugin
		// pointing `upload_url_path` at https://cdn.example.com. There is no
		// prefix left to test, and testing the path anyway would have read
		// every generated file on that CDN as an ordinary release asset and
		// stripped the one thing telling a browser it had changed. The host
		// is the whole answer here: everything served from it is an upload.
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		return '' !== $host && $host === $base['host'];
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
	/**
	 * Whether the user's delay_js_targets list matches this haystack.
	 *
	 * The inline-snippet pass needs the target list WITHOUT
	 * is_delay_target()'s empty-list-means-everything default — an inline
	 * body is only ever delayed on a positive match.
	 *
	 * @param string $haystack Script body (or URL) to match fragments against.
	 */
	private static function matches_user_targets( string $haystack ): bool {
		$opts    = self::opts();
		$targets = is_array( $opts['delay_js_targets'] ?? null ) ? $opts['delay_js_targets'] : array();
		foreach ( $targets as $needle ) {
			$needle = (string) $needle;
			if ( '' !== $needle && false !== stripos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}

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
		// The user's list is an ALLOW-list, so a target they never thought to
		// add is not delayed — and the scripts worth delaying are third-party
		// tags nobody enumerates by hand. Falling back to the built-in vendor
		// list means a site that lists one heavy embed still gets the obvious
		// analytics and widget tags postponed, instead of silently keeping
		// them on the main thread. (A user who wants one of these to run
		// early excludes it; the exclusion list is checked before this.)
		return self::matches_known_third_party( $src );
	}

	/**
	 * Whether a URL belongs to a third-party tag that is safe to postpone.
	 *
	 * These are analytics, tag managers, chat widgets, review embeds, session
	 * recorders and error trackers: scripts that never paint anything above
	 * the fold and that no first-party code holds a synchronous reference to.
	 * They are also the scripts that dominate a real page's blocking time —
	 * on embedpress.com one chat widget alone accounted for ~450ms of TBT and
	 * a 22-point score swing between runs, purely on whether it happened to
	 * arrive inside the measurement window.
	 *
	 * Matched on URL only, never on handle: these tags are printed straight
	 * into wp_head / wp_footer by their vendors' snippets and usually have no
	 * WordPress handle at all. Host fragments rather than whole domains, so a
	 * regional or versioned CDN path still matches.
	 *
	 * Deliberately NOT here: anything from the site's own origin, jQuery, or
	 * any wp-* core script. Those carry inline consumers, and delaying them
	 * is what breaks pages — see inline_bound_handles().
	 */
	private const KNOWN_THIRD_PARTY_SRC = array(
		// Tag managers and analytics.
		'googletagmanager.com',
		'google-analytics.com',
		'analytics.google.com',
		'/gtag/js',
		'gtm4wp',
		'plausible.io',
		'matomo',
		'segment.com/analytics.js',
		'stats.wp.com',
		// Advertising and conversion pixels.
		'connect.facebook.net',
		'fbevents.js',
		'ads-twitter.com',
		'snap.licdn.com',
		'analytics.tiktok.com',
		'googleadservices.com',
		'doubleclick.net',
		// Session recording and heatmaps.
		'hotjar.com',
		'clarity.ms',
		'mouseflow.com',
		'fullstory.com',
		'luckyorange',
		// Chat and support widgets.
		'client.crisp.chat',
		'widget.intercom.io',
		'js.driftt.com',
		'tawk.to',
		'livechatinc.com',
		'zdassets.com',
		'helpscout.net',
		// Reviews, social proof and marketing.
		'tp.widget.bootstrap',
		'trustpilot.com',
		'static.klaviyo.com',
		'js.hs-scripts.com',
		'list-manage.com',
		'sumo.com',
		// Error and performance monitoring.
		'sentry-cdn.com',
		'browser.sentry',
		'bugsnag.com',
		'newrelic.com',
	);

	/**
	 * Match a script URL against the built-in third-party list.
	 *
	 * @param string $src Script source URL.
	 */
	private static function matches_known_third_party( string $src ): bool {
		if ( '' === $src ) {
			return false;
		}

		$known = self::KNOWN_THIRD_PARTY_SRC;

		/**
		 * URL fragments the delay pass treats as safe-to-postpone third-party
		 * tags when the user's target list does not match.
		 *
		 * Append a vendor this list does not know yet, or remove one the site
		 * genuinely needs early. Entries are case-insensitive substrings of
		 * the script URL.
		 *
		 * @param string[] $known Built-in fragments.
		 * @param string   $src   The script URL being tested.
		 */
		$known = (array) apply_filters( 'xspeed_delay_known_third_party', $known, $src );

		foreach ( $known as $needle ) {
			$needle = (string) $needle;
			if ( '' !== $needle && false !== stripos( $src, $needle ) ) {
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
		self::$uploads_base            = null;
		self::$delay_bootstrap_printed = false;
		self::$js_measured_layout      = null;
		self::$inline_bound_handles    = null;
	}

	/**
	 * Per-request memo for inline_bound_handles(). Null = not resolved.
	 *
	 * @var array<string,true>|null
	 */
	private static $inline_bound_handles = null;

	/**
	 * Handles that cannot be deferred because inline code depends on them.
	 *
	 * #234 fixed the case where a handle carries its OWN inline block: the
	 * tag WordPress hands the filter is `before_inline + external +
	 * after_inline`, so defer goes on the external <script> and order holds.
	 * That leaves the cross-handle case, which is the one that actually
	 * breaks sites: `wp_add_inline_script( 'foo', … )` prints a bare inline
	 * block that runs at parse time and calls into whatever `foo` — or any
	 * of foo's DEPENDENCIES — defined. Inline scripts can never be deferred
	 * (the HTML spec ignores the attribute), so deferring anything they read
	 * from inverts the order WordPress guarantees and throws on a global
	 * that is not there yet.
	 *
	 * jQuery is the canonical victim: one `wp_add_inline_script( 'jquery',
	 * 'jQuery(function($){…})' )` anywhere on the page makes `jquery-core`
	 * undeferrable, and every hand-maintained exclusion list in the wild
	 * exists to say so. The registry already knows it, so read it instead of
	 * asking the user.
	 *
	 * Walks each handle carrying `after`/`before` inline data and marks the
	 * handle plus its transitive dependency chain. Cycles are guarded by the
	 * seen-map, so a self- or mutually-referential deps array terminates.
	 *
	 * Pure aside from the global registry read; memoised per request and
	 * cleared by reset_state().
	 *
	 * @return array<string,true> Handle => true, for O(1) lookup.
	 */
	public static function inline_bound_handles(): array {
		if ( null !== self::$inline_bound_handles ) {
			return self::$inline_bound_handles;
		}

		$bound = array();
		if ( function_exists( 'wp_scripts' ) ) {
			$scripts = wp_scripts();
			if ( $scripts instanceof \WP_Scripts ) {
				foreach ( array_keys( (array) $scripts->registered ) as $handle ) {
					$handle = (string) $handle;
					if ( ! self::handle_carries_inline( $scripts, $handle ) ) {
						continue;
					}
					self::mark_with_deps( $scripts, $handle, $bound );
				}
			}
		}

		/**
		 * Handles auto-excluded from defer because inline code reads them.
		 *
		 * Return a handle => true map. Add an entry to protect a script whose
		 * inline consumer this cannot see (one printed directly by a theme
		 * rather than through wp_add_inline_script), or remove one to defer a
		 * handle whose inline block is known not to touch it.
		 *
		 * @param array<string,true> $bound Detected handles.
		 */
		$bound = (array) apply_filters( 'xspeed_defer_inline_bound_handles', $bound );

		self::$inline_bound_handles = $bound;

		return self::$inline_bound_handles;
	}

	/**
	 * Whether a handle must be kept out of a combined bundle.
	 *
	 * Combining re-homes a script's code under a different handle, so every
	 * protection keyed to the ORIGINAL handle or URL stops matching: the
	 * user's `defer_js_excluded` entry, and the inline-bound set above. The
	 * combiner already refuses a handle carrying its own inline data, which
	 * is why the gap is invisible until you look for it — a DEPENDENCY of an
	 * inline consumer carries none of its own, so `jquery-core` lands in the
	 * bundle while the exclusion list still reads as though it were honoured.
	 *
	 * Returning true here is enough on its own: the combiner drops any
	 * dependent of an uncombinable handle transitively, so the whole chain
	 * stays in the queue where WordPress prints it in the right order.
	 *
	 * @param string $handle Script handle.
	 * @param string $src    Registered source URL.
	 */
	public static function is_protected_from_bundling( string $handle, string $src ): bool {
		if ( self::is_excluded_script( $handle, $src ) ) {
			return true;
		}
		return isset( self::inline_bound_handles()[ $handle ] );
	}

	/**
	 * Whether a handle has inline JS attached in either position.
	 *
	 * `get_data()` returns the raw value, which is an array of code chunks
	 * for `after` and a string for `before`; both are falsy when absent, and
	 * an empty chunk array must not count as inline code.
	 *
	 * @param \WP_Scripts $scripts Registry.
	 * @param string      $handle  Handle to inspect.
	 */
	private static function handle_carries_inline( \WP_Scripts $scripts, string $handle ): bool {
		foreach ( array( 'after', 'before' ) as $position ) {
			$data = $scripts->get_data( $handle, $position );
			if ( is_array( $data ) ) {
				foreach ( $data as $chunk ) {
					if ( '' !== trim( (string) $chunk ) ) {
						return true;
					}
				}
				continue;
			}
			if ( '' !== trim( (string) $data ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mark a handle and everything it depends on, transitively.
	 *
	 * @param \WP_Scripts        $scripts Registry.
	 * @param string             $handle  Handle to mark.
	 * @param array<string,true> $seen    Accumulator, by reference.
	 */
	private static function mark_with_deps( \WP_Scripts $scripts, string $handle, array &$seen ): void {
		if ( isset( $seen[ $handle ] ) ) {
			return;
		}
		$seen[ $handle ] = true;
		if ( ! isset( $scripts->registered[ $handle ]->deps ) ) {
			return;
		}
		foreach ( (array) $scripts->registered[ $handle ]->deps as $dep ) {
			self::mark_with_deps( $scripts, (string) $dep, $seen );
		}
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
