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
	 * Does this tag (or attribute string) opt out of optimization?
	 *
	 * `data-no-optimize` / `data-no-minify` are the de-facto convention
	 * consent managers and other plugins print so optimizers keep hands
	 * off (Borlabs Cookie stamps both on its config script). The CSS
	 * combine buffer has honored `data-no-optimize` from the start; the
	 * JS paths did not, so a marked consent script was still minified
	 * into a hashed cache file — and a stale copy of a legally relevant
	 * consent config is a correctness problem, not a cosmetic one. (#456)
	 *
	 * @param string $tag A full tag, or just its attribute string.
	 */
	public static function tag_opts_out( string $tag ): bool {
		return (bool) preg_match( '#\sdata-no-(?:optimize|minify)\b#i', $tag );
	}

	/**
	 * Pristine tags as they looked before any of our transforms, keyed by
	 * handle. See snapshot_tag() / revert_late_marked_tag().
	 *
	 * @var array<string,string>
	 */
	private static $pristine_tag = array();

	/**
	 * Priority for the late opt-out re-check. Past Borlabs' ScriptBlocker
	 * at 999 — the highest stamper we have seen in the wild — so the
	 * marker has certainly landed by the time we look. (#469)
	 */
	private const LATE_OPT_OUT_PRIORITY = 1000;

	/**
	 * The priority the late opt-out re-check runs at.
	 *
	 * A site whose stamper hooks even later can move ours past it.
	 */
	public static function late_opt_out_priority(): int {
		/**
		 * Filter the priority of xSpeed's late data-no-optimize re-check.
		 *
		 * @param int $priority Default 1000.
		 */
		return (int) apply_filters( 'xspeed_late_opt_out_priority', self::LATE_OPT_OUT_PRIORITY );
	}

	/**
	 * Filter: `script_loader_tag`, priority 9 — remember the tag before we
	 * touch it, so a marker stamped later can still be honored.
	 *
	 * Our three opt-out-aware transforms run at 15/20/30. A plugin that
	 * stamps `data-no-optimize` AFTER them is invisible to all three:
	 * Borlabs Cookie stamps at priority 100, so its consent config was
	 * still minified into a hashed cache file AND delayed — the script
	 * that has to run before anything else on the page ran only on first
	 * interaction. Snapshotting here is what lets the late pass put the
	 * original back verbatim, rather than trying to unpick each transform
	 * in reverse. (#469)
	 *
	 * @param string $tag
	 * @param string $handle
	 * @param string $src
	 */
	public static function snapshot_tag( $tag, $handle, $src ): string {
		if ( is_string( $tag ) && '' !== $tag && '' !== (string) $handle ) {
			self::$pristine_tag[ (string) $handle ] = $tag;
		}
		return (string) $tag;
	}

	/**
	 * Filter: `script_loader_tag`, priority `LATE_OPT_OUT_PRIORITY` — hand
	 * back the untouched tag when a late filter stamped an opt-out marker
	 * after our transforms had already run.
	 *
	 * The priority has to clear the stamper, not merely the transforms:
	 * Borlabs stamps at 100 and Borlabs' own script blocker at 999, so an
	 * earlier hook reads a tag whose marker has not landed yet. PHP_INT_MAX
	 * would be unfriendly to a site that legitimately wants the last word,
	 * so this sits just past the highest stamper we know of and is
	 * filterable. Reverting to the snapshot is deliberate: undoing
	 * a delay rewrite in place would mean re-deriving `src` from
	 * `data-xs-src` and stripping markers, and #273 is a standing reminder
	 * that regex-editing these attributes in reverse goes wrong quietly.
	 *
	 * The pristine tag still carries whatever priority-10 filters did to
	 * it, so only OUR changes are dropped. (#469)
	 *
	 * @param string $tag
	 * @param string $handle
	 * @param string $src
	 */
	public static function revert_late_marked_tag( $tag, $handle, $src ): string {
		if ( ! is_string( $tag ) || '' === $tag || ! self::tag_opts_out( $tag ) ) {
			return (string) $tag;
		}
		$handle   = (string) $handle;
		$pristine = isset( self::$pristine_tag[ $handle ] ) ? self::$pristine_tag[ $handle ] : '';
		if ( '' !== $pristine && $pristine !== $tag ) {
			// The marker is on the tag we were handed, not on the snapshot,
			// so carry it — and everything else the late filter set in the
			// same pass — over. A consumer reading the rendered HTML (or
			// our own buffer passes) must still see the opt-out it asked
			// for.
			$tag = self::copy_late_attributes( $tag, $pristine, $handle );
		}
		// The snapshot was taken on `script_loader_tag`, by which point
		// `script_loader_src` (priority 10) had ALREADY swapped in the
		// hashed cache URL — so reverting the tag alone still leaves the
		// minified src behind, which is the half the client actually
		// reported. Undo that here too, using the URL rewrite_script()
		// recorded. (#469)
		return self::restore_marked_script_src( $tag, $handle, self::current_src( $tag, (string) $src ) );
	}

	/**
	 * The src currently on a tag, falling back to the one WordPress passed.
	 *
	 * After a revert the tag carries the snapshot's src, which is not
	 * necessarily the `$src` argument this late in the chain.
	 *
	 * @param string $tag      Tag to read.
	 * @param string $fallback Value to use when the tag has no src.
	 */
	private static function current_src( string $tag, string $fallback ): string {
		$open = self::open_tag_offsets( $tag );
		if ( null !== $open
			&& preg_match( '#(?<![-\w])src\s*=\s*["\']([^"\']*)["\']#i', $open['attrs'], $m ) ) {
			return $m[1];
		}
		return $fallback;
	}

	/**
	 * Carry the attributes a late filter added onto the snapshot tag.
	 *
	 * Copying only `data-no-*` would silently drop the rest of what the
	 * stamper set in the same pass. Borlabs adds `data-cfasync="false"`
	 * alongside its markers — the attribute that keeps Cloudflare Rocket
	 * Loader off the consent config, i.e. the same class of breakage this
	 * fix exists to prevent, reintroduced by the fix itself. So diff the
	 * attribute names and bring over every one the snapshot lacks.
	 *
	 * Our own transform markers are excluded: they are what we are
	 * reverting, and re-adding `data-xs-delay` would re-delay the script.
	 *
	 * @param string $from Tag as the late filter left it.
	 * @param string $to   Snapshot tag to stamp onto.
	 */
	private static function copy_late_attributes( string $from, string $to, string $handle ): string {
		$late_tags = self::open_tags( $from );
		$to_tags   = self::open_tags( $to );
		if ( empty( $late_tags ) || empty( $to_tags ) || count( $late_tags ) !== count( $to_tags ) ) {
			// Counts differ when a stamper/blocker injected or replaced a
			// tag inside the concatenated string, or a transform dropped an
			// inline block. Positional pairing is meaningless then — but
			// returning the bare snapshot would silently strip the opt-out,
			// and the buffer passes would re-optimize an unmarked tag: #469
			// again, on the mismatch path. Over-marking merely leaves a tag
			// unoptimized, so stamp the protective attributes onto every
			// snapshot tag instead. (#470)
			return self::stamp_protective_attributes( $from, $to, $to_tags );
		}
		// Pair the tags positionally and stamp each one from its own
		// counterpart. A stamper runs over the whole concatenated string
		// and may mark several of the tags in it; collapsing that onto one
		// tag would strip the opt-out from the others, and the buffer
		// passes re-test `tag_opts_out()` per tag, so an unmarked sibling
		// is free to be re-optimized downstream — #469 again, one pass
		// later. (#469)
		$out = $to;
		// Right to left: an earlier splice would shift every later offset.
		for ( $i = count( $to_tags ) - 1; $i >= 0; $i-- ) {
			$add = self::late_attribute_delta( $late_tags[ $i ]['attrs'], $to_tags[ $i ]['attrs'], $handle );
			if ( '' !== $add ) {
				$out = substr_replace( $out, $add, $to_tags[ $i ]['attrs_end'], 0 );
			}
		}
		return $out;
	}

	/**
	 * Fallback when the late tag and the snapshot cannot be paired
	 * positionally: copy only the attributes that protect the script from
	 * optimizers — the opt-out markers plus `data-cfasync` — onto every
	 * snapshot tag missing them. Values are taken as the stamper wrote
	 * them on the late tag. (#470)
	 *
	 * @param string $from    Tag as the late filter left it.
	 * @param string $to      Snapshot tag to stamp onto.
	 * @param array  $to_tags open_tags() result for $to.
	 */
	private static function stamp_protective_attributes( string $from, string $to, array $to_tags ): string {
		$protect = array();
		foreach ( array( 'data-no-optimize', 'data-no-minify', 'data-cfasync' ) as $name ) {
			if ( preg_match(
				'#\s(' . preg_quote( $name, '#' ) . ')(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*))?#i',
				$from,
				$m
			) ) {
				$protect[ $name ] = ' ' . $name . ( isset( $m[2] ) ? $m[2] : '' );
			}
		}
		if ( empty( $protect ) ) {
			return $to;
		}
		$out = $to;
		// Right to left: an earlier splice would shift every later offset.
		for ( $i = count( $to_tags ) - 1; $i >= 0; $i-- ) {
			$add = '';
			foreach ( $protect as $name => $attr ) {
				if ( ! preg_match( '#\s' . preg_quote( $name, '#' ) . '\b#i', $to_tags[ $i ]['attrs'] ) ) {
					$add .= $attr;
				}
			}
			if ( '' !== $add ) {
				$out = substr_replace( $out, $add, $to_tags[ $i ]['attrs_end'], 0 );
			}
		}
		return $out;
	}

	/**
	 * The attributes present on the late tag but not the snapshot, minus
	 * the ones OUR transforms put there for this handle.
	 *
	 * Only attributes recorded in $our_late_attrs are dropped — a blanket
	 * defer/type skip threw away a defer the STAMPER set in the same pass
	 * as its marker. Themify prints main.js with defer + data-no-optimize
	 * together, and its config rides a deferred data: URI script printed
	 * just before it; stripping the theme's defer made main.js
	 * parser-blocking, so it ran ahead of its config and the theme died
	 * with "themify_vars is not defined". `src` is still never copied:
	 * it belongs to the snapshot, and restore_marked_script_src() owns
	 * undoing a minified URL.
	 *
	 * @param string $late_attrs Attribute string from the transformed tag.
	 * @param string $to_attrs   Attribute string from the snapshot tag.
	 * @param string $handle     Script handle the tags belong to.
	 */
	private static function late_attribute_delta( string $late_attrs, string $to_attrs, string $handle ): string {
		$pattern = '#\s([-\w:]+)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*))?#';
		if ( ! preg_match_all( $pattern, $late_attrs, $late, PREG_SET_ORDER ) ) {
			return '';
		}
		$have = array();
		if ( preg_match_all( $pattern, $to_attrs, $existing, PREG_SET_ORDER ) ) {
			foreach ( $existing as $attr ) {
				$have[ strtolower( $attr[1] ) ] = true;
			}
		}
		$add = '';
		foreach ( $late as $attr ) {
			$name = strtolower( $attr[1] );
			if ( isset( $have[ $name ] ) || 'src' === $name || isset( self::$our_late_attrs[ $handle ][ $name ] ) ) {
				continue;
			}
			if ( 0 === strpos( $name, 'data-xs-' ) ) {
				continue;
			}
			$add .= $attr[0];
		}
		return $add;
	}

	/**
	 * Attribute names OUR transforms added in this request, keyed by
	 * handle: defer_script_tag() records `defer`, delay_script_tag()
	 * records `type` when it parks an inline block. Copying one of these
	 * from the late tag back onto the snapshot would re-apply the very
	 * transform the revert is undoing — but the same names coming from a
	 * STAMPER are the author's intent and must survive, so the skip is
	 * per-handle, never by name alone. `data-xs-*` is handled by prefix
	 * separately. (#469)
	 *
	 * @var array<string,array<string,true>>
	 */
	private static $our_late_attrs = array();

	/**
	 * Locate the opening `<script>` that carries the src, falling back to
	 * the last one when none does.
	 *
	 * WP_Scripts::do_item() hands `script_loader_tag` the concatenation of
	 * before_inline + external + after_inline, so the FIRST `<script` is
	 * routinely an inline block rather than the asset — the same trap
	 * #234 fixed for defer and #273 for delay. Scanning attribute-wise
	 * also means a quoted value containing `>` (an `onerror` guard, a JSON
	 * payload) cannot truncate the tag the way `[^>]*` did. (#469)
	 *
	 * @param string $tag Full tag string.
	 * @return array{attrs:string,attrs_end:int}|null
	 */
	private static function open_tag_offsets( string $tag ): ?array {
		$tags     = self::open_tags( $tag );
		$fallback = null;
		foreach ( $tags as $found ) {
			if ( preg_match( '#(?<![-\w])src\s*=#i', $found['attrs'] ) ) {
				return $found;
			}
			$fallback = $found;
		}
		return $fallback;
	}

	/**
	 * Every well-formed opening `<script>` in the string, in order.
	 *
	 * @param string $tag Full tag string.
	 * @return array<int,array{attrs:string,attrs_end:int}>
	 */
	private static function open_tags( string $tag ): array {
		if ( ! preg_match_all( '#<script\b#i', $tag, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$found = array();
		foreach ( $m[0] as $hit ) {
			$start = (int) $hit[1] + strlen( $hit[0] );
			$end   = self::scan_open_tag_end( $tag, $start );
			if ( null === $end ) {
				continue;
			}
			$found[] = array(
				'attrs'     => substr( $tag, $start, $end - $start ),
				'attrs_end' => $end,
			);
		}
		return $found;
	}

	/**
	 * Offset of the `>` closing an opening tag, skipping any that sit
	 * inside a quoted attribute value. Null when the tag is unterminated.
	 *
	 * @param string $tag    Full tag string.
	 * @param int    $offset Index just past `<script`.
	 */
	private static function scan_open_tag_end( string $tag, int $offset ): ?int {
		$len   = strlen( $tag );
		$quote = '';
		for ( $i = $offset; $i < $len; $i++ ) {
			$char = $tag[ $i ];
			if ( '' !== $quote ) {
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;
			}
			if ( '>' === $char ) {
				// A self-closing `/>` keeps the slash out of the attributes.
				return ( $i > $offset && '/' === $tag[ $i - 1 ] ) ? $i - 1 : $i;
			}
		}
		return null;
	}

	/**
	 * Filter: `script_loader_tag`, priority 15 — undo the minify-cache
	 * rewrite for a script whose printed tag opts out.
	 *
	 * The src rewrite happens on `script_loader_src` (priority 10), long
	 * before any plugin's own `script_loader_tag` filter can stamp
	 * `data-no-minify` onto the tag — so the marker arrived too late to
	 * prevent the rewrite. This runs after the filters that stamp at the
	 * default priority 10 and swaps the hashed cache URL back to the
	 * recorded original. A marker stamped later than our transforms is
	 * caught by revert_late_marked_tag() in the late pass instead. (#469)
	 *
	 * @param string $tag
	 * @param string $handle
	 * @param string $src
	 */
	public static function restore_marked_script_src( $tag, $handle, $src ): string {
		if ( ! is_string( $tag ) || '' === $tag || ! self::tag_opts_out( $tag ) ) {
			return (string) $tag;
		}
		$original = self::original_src( (string) $handle );
		if ( '' === $original || '' === (string) $src || false === strpos( $tag, (string) $src ) ) {
			return $tag;
		}
		return str_replace( (string) $src, $original, $tag );
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
		// The author asked every optimizer to leave this tag alone.
		if ( self::carries_optimizer_opt_out( $tag ) ) {
			return $tag;
		}
		if ( '' === (string) $src ) {
			return $tag;
		}
		if ( self::is_excluded_script( (string) $handle, (string) $src ) ) {
			return $tag;
		}
		// The tag itself asked to be left alone. (#456)
		if ( self::tag_opts_out( $tag ) ) {
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
		$deferred = (string) preg_replace( '#<script\b(?=[^>]*\ssrc\s*=)#i', '<script defer="defer"', $tag, 1 );
		if ( $deferred !== $tag ) {
			// Remember that THIS defer is ours, so the late opt-out revert
			// drops it — and only it, never a stamper's own defer.
			self::$our_late_attrs[ (string) $handle ]['defer'] = true;
		}
		return $deferred;
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
		// The author asked every optimizer to leave this tag alone.
		if ( self::carries_optimizer_opt_out( $tag ) ) {
			return $tag;
		}
		if ( self::is_excluded_script( (string) $handle, (string) $src, true ) ) {
			return $tag;
		}
		// The tag itself asked to be left alone. (#456)
		if ( self::tag_opts_out( $tag ) ) {
			return $tag;
		}
		if ( ! self::is_delay_target( (string) $handle, (string) $src ) ) {
			return $tag;
		}
		// Inline code elsewhere on the page reads this handle (or something
		// it depends on) — same registry walk defer uses. Delaying it runs
		// the consumer at parse time against a global that arrives on first
		// interaction: `wp_add_inline_script( 'jquery-ui-core',
		// 'jQuery.uiBackCompat…', 'before' )` throws "jQuery is not defined"
		// the moment jquery-core is delayed. A handle the user NAMED in
		// delay_js_targets is still delayed — an explicit entry is the user
		// saying they know the inline consumer is safe to break or absent.
		if ( isset( self::inline_bound_handles()[ (string) $handle ] )
			&& ! self::is_user_named_target( (string) $handle, (string) $src )
			// Smart Delay inverts this protection: the handle is delayed and
			// its own before/after snippets are parked WITH it (see
			// park_smart_inline()), so the consumer no longer runs against a
			// missing global — it replays after its provider, in page order.
			// On a builder page nearly every script is inline-bound, which is
			// why delay-all without this delayed almost nothing.
			&& ! self::smart_delay_enabled() ) {
			return $tag;
		}
		// A non-executable type means this tag is data, or is being held by
		// somebody else on purpose. The buffer pass has always checked this;
		// the enqueue path did not, so a consent-blocked or JSON-carrying
		// handle could still be rewritten here. (#274)
		//
		// Read the type from the tag that carries the src. $tag is before +
		// external + after, and Smart Delay parks the before/after snippets
		// as text/xspeed-delayed before this filter runs. Reading the first
		// `type=` in the whole string found the parked snippet, so every
		// handle with its own snippets kept a live src behind parked
		// snippets.
		$type_open = '' !== (string) $src ? self::open_tag_offsets( $tag ) : null;
		if ( in_array( self::extract_type( null !== $type_open ? $type_open['attrs'] : $tag ), self::NON_EXECUTABLE_TYPES, true ) ) {
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
		$parked = (string) preg_replace_callback(
			'#<script\b([^>]*)>#i',
			static function ( array $m ): string {
				return '<script' . self::park_type_attrs( $m[1] ) . '>';
			},
			$tag,
			1
		);
		if ( $parked !== $tag ) {
			// The parked type is ours to drop on a late opt-out revert; an
			// author-set type sits on the snapshot and survives regardless.
			self::$our_late_attrs[ (string) $handle ]['type'] = true;
		}
		return $parked;
	}

	/**
	 * Script types the buffer pass must never touch. `<script>` carries
	 * data as often as it carries code: JSON-LD feeds structured-data
	 * consumers, importmaps must resolve before any module runs, and our
	 * own delayed-inline marker is already handled by the bootstrap.
	 * Rewriting any of these breaks the page or its metadata.
	 */
	/**
	 * Attributes by which a script's own author tells optimizers to stand down.
	 *
	 * The report behind #275 also asked us to leave a script alone when its
	 * author "already marked it to load late". Read literally that means
	 * `defer`/`async`, and that reading is wrong twice over: `defer` is
	 * stamped onto every enqueued script by our OWN Defer JS filter at
	 * priority 20, before Delay JS sees it at 30 — so honouring it would
	 * switch Delay JS off entirely on sites running both — and `async` is the
	 * shape of gtag, GTM and every pixel loader, which is precisely the
	 * payload Delay JS exists to postpone. `defer`/`async` say WHEN TO FETCH,
	 * not "leave me alone".
	 *
	 * These attributes do say it. Each is an established opt-out honoured by
	 * another optimizer — WP Rocket, LiteSpeed, Autoptimize, NitroPack,
	 * Jetpack Boost — so an author who prints one has already declared that
	 * no optimizer should touch this tag. Consent banners are the main
	 * beneficiary, but the rule is general and needs neither a handle nor a
	 * recognised URL, so it works identically on both passes.
	 *
	 * Two deliberate omissions:
	 *
	 *   - `data-cfasync="false"` is a Cloudflare Rocket Loader opt-out, and
	 *     ad stacks (Mediavine, Ezoic, AdThrive) print it on exactly the
	 *     heavy loaders a site turns Delay JS on for. Honouring it would
	 *     un-delay the ads.
	 *   - `data-no-minify` is about minification, not execution timing.
	 */
	private const OPT_OUT_ATTRIBUTES = array(
		'nowprocket',               // WP Rocket
		'data-nowprocket',          // WP Rocket
		'data-no-optimize',         // LiteSpeed
		'data-noptimize',           // Autoptimize
		'data-no-defer',            // used by several optimizers
		'nitro-exclude',            // NitroPack
		'data-jetpack-boost',       // Jetpack Boost (value "ignore")
		'data-wpmeteor-nooptimize', // WP Meteor
		'data-xs-nodelay',          // ours
	);

	/**
	 * Does this tag carry an explicit "optimizers keep out" attribute?
	 *
	 * Same `(?<![-\w])` lookbehind the rest of this file uses, so
	 * `data-nowprocket` does not also satisfy a bare `nowprocket` lookup, and
	 * a trailing `\b` so `data-no-defer` does not match `data-no-deferral`.
	 * Bare and valued forms both count: an author writes `nowprocket`,
	 * `nowprocket=""` and `data-noptimize="1"` interchangeably.
	 *
	 * @param string $tag Full opening tag.
	 */
	private static function carries_optimizer_opt_out( string $tag ): bool {
		// Read ATTRIBUTE NAMES, not the tag as a string. A substring scan
		// matched `src="https://cdn/nowprocket/loader.js"`, `?nowprocket=1`,
		// `class="nowprocket"` and any inline body that merely mentioned one
		// of these names -- each silently un-delaying a script that should
		// have been delayed.
		//
		// EVERY opening tag is checked, not just the first. On the enqueue
		// path WP_Scripts::do_item() hands us translations + before-inline +
		// the real tag + after-inline concatenated, so the first `<script`
		// is often an inline block and the author's opt-out sits on the
		// external tag behind it. Reading only the first tag missed it and
		// delayed the script anyway -- the wrong direction: a consent banner
		// its author told optimizers to leave alone would not appear.
		//
		// The tag regex is quote-aware because `[^>]*>` stops at a `>` inside
		// a quoted value, and consent managers routinely ship JSON in a
		// data-* attribute.
		if ( ! preg_match_all( '#<script\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#is', $tag, $tags ) ) {
			return false;
		}

		foreach ( $tags[0] as $open ) {
			// Walk name/value pairs. The name pattern is deliberately
			// permissive: a name we cannot recognise (Alpine's `@load`, say)
			// must still consume ITS OWN VALUE, or the value gets scanned as
			// if it were more attribute names.
			if ( ! preg_match_all(
				'#\s+([^\s=/>]+)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*))?#s',
				substr( $open, 7, -1 ),
				$found
			) ) {
				continue;
			}

			foreach ( $found[1] as $name ) {
				if ( in_array( strtolower( $name ), self::OPT_OUT_ATTRIBUTES, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

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

				// The author asked every optimizer to leave this tag alone.
				// Checked before src/type: it needs neither, so an
				// un-enqueued banner printed straight into wp_head is
				// covered the same as an enqueued one.
				//
				// Both checks, because they disagree on purpose and either
				// saying "leave it" is the safe answer. tag_opts_out() is
				// dev's (#456) and also drives the late re-check at #469;
				// carries_optimizer_opt_out() reads attribute NAMES across
				// every opening tag, so it is not fooled by a marker sitting
				// inside a quoted value or an inline body, and it knows the
				// other optimizers' markers.
				if ( self::tag_opts_out( $tag ) || self::carries_optimizer_opt_out( $tag ) ) {
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

				// An ENQUEUED script reaches this sweep too: the enqueue-path
				// filter leaves an EXCLUDED tag unmarked, and unmarked is all
				// this pass can see. Judging it on its URL alone re-delays the
				// very script the exclusion protected — and a handle is not
				// generally in its own URL, which is the shape Complianz
				// (`cmplz-cookiebanner`), NotificationX (`notificationx-public`)
				// and jQuery (`jquery-core`) all have. A site with jquery-core
				// excluded still shipped jQuery delayed, and every inline
				// `jQuery(...)` on the page threw "jQuery is not defined".
				// WordPress prints `id="<handle>-js"` on every enqueued
				// script, so the handle is right there in the tag. (#275)
				//
				// The lookbehind matters: `data-id="cmplz-cookiebanner-js"` is
				// somebody's own attribute, not the handle, and reading it as
				// one would shield a script nobody excluded.
				//
				// Merge note for #374: if a URL->handle map built from
				// wp_scripts() lands first, resolve through that and keep
				// this as the FALLBACK rather than replacing it. The map is
				// keyed on the REGISTERED src, and Minifier::rewrite_script()
				// rewrites a local script's URL to a hashed /cache/xspeed/min/
				// path at output time -- which is why remember_original_src()
				// exists. So with minify_js on, the map misses every minified
				// script and an excluded one would be re-delayed here. The
				// `id` survives that rewrite.
				$tag_handle = '';
				if ( preg_match( '#(?<![-\w])id\s*=\s*(["\'])(.*?)\1#is', $tag, $id_m ) ) {
					// WP appends `-js`; anything else is somebody's own id and
					// is still worth matching literally.
					$tag_handle = (string) preg_replace( '/-js$/', '', trim( $id_m[2] ) );
				}

				if ( self::is_excluded_script( $tag_handle, $src, true ) ) {
					return $tag;
				}
				// The handle is passed to the target test as well, so naming a
				// handle in the delay list behaves the same here as it does on
				// the enqueue path. The two layers disagreeing on what a target
				// means is what made this look like a matching quirk rather
				// than a whole layer ignoring the list.
				if ( ! self::is_delay_target( $tag_handle, $src ) ) {
					return $tag;
				}
				// Mirror of the enqueue-path guard: a handle that inline code
				// reads stays eager unless the user named it. wp_scripts()
				// is still populated at xspeed_cache_final_html time on a
				// MISS, so the registry walk is consultable here too; an
				// unrecoverable handle ('') simply never matches the set.
				if ( '' !== $tag_handle
					&& isset( self::inline_bound_handles()[ $tag_handle ] )
					&& ! self::is_user_named_target( $tag_handle, $src ) ) {
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

		$handle_delayed = self::handle_delay_outcomes( $html );

		$out = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			static function ( array $m ) use ( $handle_delayed ): string {
				list( $whole, $attrs, $body ) = $m;

				if ( '' === trim( $body ) ) {
					return $whole;
				}

				// The tag itself asked to be left alone. (#456)
				if ( self::tag_opts_out( $attrs ) ) {
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

				// Our own inline scripts, by the id they are printed with.
				// The src passes get this for free from the handle prefix,
				// but here the handle is '' — and the facade observer's body
				// names youtube/vimeo, so a user target like "youtube" would
				// park the very script that makes those embeds cheap.
				if ( preg_match( '#(?<![-\w])id\s*=\s*(["\'])xspeed-#i', $attrs ) ) {
					return $whole;
				}

				// A handle's own inline blocks follow the handle, not only their
				// body text. The body rule below decided them alone, so a
				// `-js-extra` whose data named a target was parked while its
				// script, kept eager by a URL exclusion, ran first and read an
				// undefined global. (#549)
				//
				// Ahead of the exclusion list on purpose. The handle's own tag
				// was already weighed against it by handle and URL; a body
				// match here would keep the after-code of a delayed script
				// eager, running it before the script it calls.
				if ( preg_match( '#(?<![-\w])id\s*=\s*(["\'])(.+?)-js-(extra|before|after)\1#i', $attrs, $own ) ) {
					$part = strtolower( $own[3] );
					// wp_localize_script data. Early is always safe: it only
					// assigns, and its script cannot run before it.
					if ( 'extra' === $part ) {
						return $whole;
					}
					if ( isset( $handle_delayed[ $own[2] ] ) ) {
						if ( $handle_delayed[ $own[2] ] ) {
							return '<script' . self::park_type_attrs( $attrs ) . '>' . $body . '</script>';
						}
						// The script runs at load, so its `before` code must too.
						// Its `after` code still runs after it if parked, so that
						// one is left to the body rule, like any vendor loader.
						if ( 'before' === $part ) {
							return $whole;
						}
					}
				}

				// The body stands in for the URL in the lists the src passes
				// consult — but NOT via is_delay_target(), whose empty-list
				// default is "delay everything". That default is right for a
				// tag with a URL and catastrophic here: it would park every
				// inline script on the page. Inline code is delayed only on a
				// positive identification — the body names a known vendor
				// host, or a fragment the user targeted — and the exclusion
				// list still wins first. A target naming a consent manager
				// lifts the floor here the same way it does for a src tag;
				// without it the same entry delayed the file and left the
				// vendor's inline code eager.
				if ( self::is_excluded_script( '', $body, true ) ) {
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
	 * Whether each enqueued handle's external tag ended up delayed.
	 *
	 * Read from the finished HTML rather than recorded as tags are filtered:
	 * this runs after every pass that can delay a tag (script_loader_tag, the
	 * late opt-out revert, the raw-tag sweep), so the page itself is the only
	 * complete answer.
	 *
	 * @param string $html Complete page HTML.
	 * @return array<string,bool> Handle => delayed.
	 */
	private static function handle_delay_outcomes( string $html ): array {
		if ( ! preg_match_all( '#<script\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>#i', $html, $m ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[1] as $attrs ) {
			if ( ! preg_match( '#(?<![-\w])id\s*=\s*(["\'])(.+?)-js\1#i', $attrs, $id ) ) {
				continue;
			}
			$out[ $id[2] ] = false !== stripos( $attrs, 'data-xs-delay' ) || false !== stripos( $attrs, 'data-xs-src' );
		}
		return $out;
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

		// The script is includes/js/delay-bootstrap.js, which also carries
		// the design notes for the lifecycle replay (#494). `npm run build`
		// minifies it into assets/delay-bootstrap.min.js; the timeout goes in
		// place of its one placeholder.
		//
		// The tag goes out through wp_print_inline_script_tag(), like Free's
		// other inline scripts, so a CSP plugin's wp_inline_script_attributes
		// filter can give it a nonce. Printed bare, a nonce CSP blocked it
		// and nothing was ever replayed.
		$js = str_replace( 'XSPEED_DELAY_TIMEOUT', (string) $timeout, self::delay_bootstrap_js() );
		wp_print_inline_script_tag( $js, array( 'id' => 'xspeed-delay-bootstrap' ) );
	}

	/** The delay bootstrap's code, read once per request. */
	private static $delay_bootstrap_js = null;

	/**
	 * The built delay bootstrap, without the line that records its source.
	 * If the build is missing, the readable source is valid JS too, only
	 * larger: printing nothing would leave every delayed script parked for
	 * good, because the tags are already rewritten by the time this runs.
	 */
	private static function delay_bootstrap_js(): string {
		if ( null !== self::$delay_bootstrap_js ) {
			return self::$delay_bootstrap_js;
		}
		$root  = dirname( __DIR__ );
		$built = $root . '/assets/delay-bootstrap.min.js';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local plugin file, not a remote URL.
		$js = is_readable( $built ) ? (string) file_get_contents( $built ) : '';
		if ( '' !== $js ) {
			$js = (string) preg_replace( '#\A/\*[^\n]*\*/\n#', '', $js );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local plugin file, not a remote URL.
			$js = (string) file_get_contents( $root . '/includes/js/delay-bootstrap.js' );
		}
		self::$delay_bootstrap_js = trim( $js );
		return self::$delay_bootstrap_js;
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
		// No critical CSS for this page: every stylesheet stays blocking.
		//
		// Deferring a stylesheet only helps when something already styles the
		// first screen. Without that, the page paints unstyled and then jumps
		// when the sheets arrive. Measured on the Templately Astoria pages
		// (Elementor and a block theme): CLS 0.43-1.27 and 15-43 points lower
		// on 7 of 8 pages than the same settings without async CSS. The guards
		// below narrow the damage; this one removes it. WP Rocket, LiteSpeed,
		// Jetpack Boost and FlyingPress likewise never defer CSS without
		// critical CSS. (#588)
		if ( ! self::page_has_critical_css() ) {
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
	 *  - A page builder's GRID sheets: the rows, columns, sections and
	 *    containers everything else sits in (`kadence-blocks-rowlayout`,
	 *    `kadence-blocks-column`, `elementor-frontend`, `elementor-post-N`).
	 *    On a builder page these lay out the hero, not the theme. Deferred,
	 *    the hero painted as one stacked column and then snapped into its
	 *    grid: CLS 0.665 on desktop, from one row.
	 *
	 * Everything else — plugin sheets, icon fonts, buttons, forms, the
	 * builder's per-widget sheets, the long tail that makes async CSS worth
	 * having — is still deferred, so the optimization keeps most of its
	 * benefit.
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
		$critical = in_array( $handle, $core, true ) || self::is_builder_grid_style( $handle );

		// The active theme's own sheets.
		//
		// Matched on the theme stem, but NOT as a bare prefix: a plugin from
		// the same vendor shares it (the Kadence theme is `kadence`, while
		// `kadence-blocks-image` and `kadence-fonts-gfonts` come from the
		// Kadence Blocks PLUGIN and a webfont loader). Treating every such
		// sheet as layout-critical would leave almost nothing deferred and
		// quietly undo the feature; the builder's grid sheets are caught
		// above by what they do, not whose they are. So the stem must be
		// followed by a recognised theme-area segment, which is how themes
		// name their split sheets.
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
	 * Whether a handle is a page builder's grid sheet.
	 *
	 * Matched on the last segment of the handle, so a builder that names its
	 * row sheet `acme-blocks-row-layout` is covered without being listed. The
	 * segments are the ones that only ever carry structure; a button, image
	 * or form sheet styles an element inside the grid, and the grid holds its
	 * place while that sheet loads.
	 *
	 * @param string $handle Lowercase stylesheet handle.
	 */
	private static function is_builder_grid_style( string $handle ): bool {
		if ( preg_match( '#(?:^|-)(?:rowlayout|row-layout|column|columns|container|section|grid)$#', $handle ) ) {
			return true;
		}
		// Builders whose grid lives in a sheet named after the builder or the
		// post, not after a structural element.
		return (bool) preg_match( '#^(?:elementor-frontend|elementor-post-\d+|fl-builder-layout(?:-\d+)?|generateblocks)$#', $handle );
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

	/**
	 * Consent managers are never deferred, and never delayed by a broad
	 * setting — only a delay_js_targets entry that NAMES the vendor lifts
	 * the floor (see user_named_consent_manager()); the
	 * `xspeed_js_exclusion_floor` filter remains the code-level override.
	 *
	 * A consent banner is drawn by JavaScript, and it is the one thing on
	 * the page that has to appear before anything else happens. Delay it
	 * and a visitor who lands, reads and leaves without touching the page
	 * is never asked — on an opt-in configuration the site then ran
	 * without ever offering the choice.
	 *
	 * The editable list cannot carry this. A stored value replaces the
	 * schema default outright (Settings_Manager::get()), so widening that
	 * default would reach fresh installs only, and clearing the textarea
	 * would drop the protection again. Same floor pattern as
	 * Server_Rules::COOKIE_FLOOR. Trim or extend it through
	 * `xspeed_js_exclusion_floor`.
	 *
	 * A URL token cannot survive a rewrite of that URL: Minify JS rewrites a
	 * local script to a hashed /cache/xspeed/min/ path, and Combine JS folds
	 * it into a bundle. On the enqueue path original_src() gives the pre-minify
	 * URL back, but the buffer sweep has only the tag -- so with Minify JS on
	 * and the `id` stripped, a banner shipped un-minified is not recognised.
	 * Documented in docs/user/minification.md rather than papered over.
	 *
	 * Each entry goes through target_matches(): an exact handle OR a
	 * case-insensitive URL substring. Both passes can match either — the
	 * enqueue path is handed the handle, and the buffer sweep reads it back
	 * out of the tag's `id`. A URL token additionally covers a banner that
	 * was never enqueued at all, which is how Cookiebot prints itself. (#275)
	 */
	private const CONSENT_MANAGER_FLOOR = array(
		// Prefer a plugin-directory or vendor-host URL token over a handle.
		// A handle is only readable on the enqueue path and, on the buffer
		// sweep, only if the tag still carries the `id` WordPress prints —
		// which another plugin can strip. A URL token matches on both passes
		// and covers a banner that was never enqueued at all. (#275 QA)

		// CookieYes / GDPR Cookie Consent. Handle and plugin directory are
		// the same string, so this covers both paths.
		'cookie-law-info',
		// Complianz: the plugin directory, covering -gdpr and -gdpr-premium.
		// Was the `cmplz-cookiebanner` handle, which needed the `id` tag.
		'complianz',
		// NotificationX runs its GDPR cookie notice off the same handle as
		// every other notification, so excluding it excludes them all. That
		// is what the plugin's own team asked for. Directory token covers
		// the Pro build too; was the `notificationx-public` handle.
		'notificationx',
		// Cookiebot prints its loader straight into wp_head, so only the
		// buffer sweep ever sees it. This is the token Cookiebot's own WP
		// Rocket and LiteSpeed integrations exclude.
		'consent.cookiebot.com',
		// Cookie Notice — named in the original report and one of the most
		// installed consent plugins. Its banner is enqueued from
		// /plugins/cookie-notice/js/front.min.js.
		'cookie-notice',
		// Cookie Notice in Cookie Compliance mode prints a different loader,
		// whose host is overridable via CN_APP_WIDGET_URL — so key on the
		// filename, not the CDN host.
		'hu-banner',
		// Moove GDPR Cookie Compliance. Directory token: its handle
		// (`moove_gdpr_frontend`) does not appear in its own URL.
		'gdpr-cookie-compliance',
		// Termly's resource blocker, which also covers the legacy embed.
		'app.termly.io',
		// Usercentrics, reached three ways: Cookiebot's UC mode
		// (web.cmp.usercentrics.eu), Termageddon (app.usercentrics.eu) and
		// the privacy proxy.
		'usercentrics.eu',
		// Iubenda: both the consent solution and the consent database SDK.
		'cdn.iubenda.com',
		// OneTrust. Pasted snippet rather than a wordpress.org plugin, so
		// this is the SDK host rather than a verified plugin path.
		'cdn.cookielaw.org',
		// Borlabs is commercial and renames its files per release; the
		// vendor's own guidance is that this string stays in every path.
		'borlabs-cookie',
		// Real Cookie Banner, free and pro. Its anti-adblock mode serves the
		// banner from an anonymised path that no URL token can match — use
		// `xspeed_js_exclusion_floor` to add the handle on such a site.
		'real-cookie-banner',
		// SureCookie.
		'surecookie',
	);

	/**
	 * What a site owner types to name each floor entry, and what the admin
	 * shows them. Keyed by CONSENT_MANAGER_FLOOR token; a test holds the two
	 * in step.
	 *
	 * The keyword is the part of the token a person would actually write
	 * (`cookiebot`, not `consent.cookiebot.com`), and it is always a substring
	 * of the token, so an entry that names the vendor this way still matches
	 * the vendor's URL. A brand name that appears nowhere in the URL
	 * (`cookieyes`, `onetrust`, `cmplz`) is deliberately not a keyword: it
	 * could never match the tag, so offering it would promise a lift that
	 * cannot happen. The exact handle always works as well.
	 *
	 * Cookiebot in Usercentrics CMP mode loads from web.cmp.usercentrics.eu,
	 * so the floor catches it as Usercentrics and `usercentrics` names it,
	 * not `cookiebot`.
	 */
	private const CONSENT_MANAGER_NAMES = array(
		'cookie-law-info'        => array( 'label' => 'CookieYes', 'keyword' => 'cookie-law-info' ),
		'complianz'              => array( 'label' => 'Complianz', 'keyword' => 'complianz' ),
		'notificationx'          => array( 'label' => 'NotificationX', 'keyword' => 'notificationx' ),
		'consent.cookiebot.com'  => array( 'label' => 'Cookiebot', 'keyword' => 'cookiebot' ),
		'cookie-notice'          => array( 'label' => 'Cookie Notice', 'keyword' => 'cookie-notice' ),
		'hu-banner'              => array( 'label' => 'Cookie Notice (Cookie Compliance)', 'keyword' => 'hu-banner' ),
		'gdpr-cookie-compliance' => array( 'label' => 'GDPR Cookie Compliance (Moove)', 'keyword' => 'gdpr-cookie-compliance' ),
		'app.termly.io'          => array( 'label' => 'Termly', 'keyword' => 'termly' ),
		'usercentrics.eu'        => array( 'label' => 'Usercentrics', 'keyword' => 'usercentrics' ),
		'cdn.iubenda.com'        => array( 'label' => 'Iubenda', 'keyword' => 'iubenda' ),
		'cdn.cookielaw.org'      => array( 'label' => 'OneTrust', 'keyword' => 'cookielaw' ),
		'borlabs-cookie'         => array( 'label' => 'Borlabs Cookie', 'keyword' => 'borlabs' ),
		'real-cookie-banner'     => array( 'label' => 'Real Cookie Banner', 'keyword' => 'real-cookie-banner' ),
		'surecookie'             => array( 'label' => 'SureCookie', 'keyword' => 'surecookie' ),
	);

	/**
	 * "Label (keyword)" for every built-in consent manager, for the admin.
	 *
	 * Reads the built-in list, not the filtered floor: the admin describes
	 * what ships, and a site that trimmed the floor in code knows it did.
	 *
	 * @return string[]
	 */
	public static function consent_manager_labels(): array {
		$out = array();
		foreach ( self::CONSENT_MANAGER_FLOOR as $token ) {
			$name  = self::CONSENT_MANAGER_NAMES[ $token ] ?? array(
				'label'   => $token,
				'keyword' => $token,
			);
			$out[] = $name['label'] . ' (' . $name['keyword'] . ')';
		}
		return $out;
	}

	/**
	 * Per-request memo for exclusion_floor(). Null = not resolved.
	 *
	 * @var string[]|null
	 */
	private static $exclusion_floor = null;

	/**
	 * The built-in exclusion floor, after the site has had its say.
	 *
	 * @return string[]
	 */
	private static function exclusion_floor(): array {
		if ( null === self::$exclusion_floor ) {
			/**
			 * Scripts that are never deferred or delayed, whatever the
			 * user's exclusion list holds. Each entry is an exact script
			 * handle or a case-insensitive URL substring.
			 *
			 * Return the array minus a token to let Delay JS postpone that
			 * consent manager on purpose; add one to protect another script.
			 *
			 * @param string[] $floor Built-in floor.
			 */
			$floor                 = apply_filters( 'xspeed_js_exclusion_floor', self::CONSENT_MANAGER_FLOOR );
			self::$exclusion_floor = array_values(
				array_filter( array_map( 'strval', (array) $floor ), static fn( $t ) => '' !== $t )
			);
		}
		return self::$exclusion_floor;
	}

	/**
	 * @param string $handle           Script handle ('' on the buffer sweep
	 *                                 when no id survived).
	 * @param string $src              Script URL.
	 * @param bool   $named_lifts_floor Delay paths only: a delay_js_targets
	 *                                 entry that NAMES the consent manager
	 *                                 passes the floor (see
	 *                                 user_named_consent_manager()). Typing a
	 *                                 consent manager's name into an
	 *                                 allow-list is the site owner taking the
	 *                                 consent-timing decision back — GDPR is
	 *                                 theirs to weigh, not ours; the floor
	 *                                 only exists so Delay JS can't hide a
	 *                                 banner NOBODY pointed at. Their own
	 *                                 exclusion list, ALWAYS_EXCLUDED_HANDLES
	 *                                 and our beacons still win: on a
	 *                                 conflict between the user's two lists,
	 *                                 protection beats postponement.
	 */
	private static function is_excluded_script( string $handle, string $src, bool $named_lifts_floor = false ): bool {
		if ( in_array( $handle, self::ALWAYS_EXCLUDED_HANDLES, true ) ) {
			return true;
		}
		// Never defer or delay our own scripts. The fold and RUM beacons
		// measure the FIRST paint — delayed to first interaction they
		// measure a scrolled page or nothing, so fold quorum never fills
		// and full CSS deferral never licenses. Found live: delay_js with
		// empty targets delayed the fold beacon itself, and the site sat
		// at zero fold reports for hours while its stylesheets stayed
		// render-blocking. Prefix, not a handle list, so a Pro module's
		// beacon added later cannot re-open the hole.
		if ( 0 === strpos( $handle, 'xspeed-' ) ) {
			return true;
		}
		// Ahead of the user list, and ahead of the empty-list early return
		// below: an install that saved the Minify panel before this shipped
		// has a stored list that knows nothing about consent managers, and
		// one that cleared the textarea has no list at all. Neither may
		// hide the banner. (#275)
		foreach ( self::exclusion_floor() as $needle ) {
			if ( self::floor_matches( $needle, $handle, $src ) ) {
				// `continue`, not `break`: a second floor token matching the
				// same tag has to be named too, or a filter-added token
				// would be lifted by an entry that names only the first.
				if ( $named_lifts_floor && self::user_named_consent_manager( $needle, $handle, $src ) ) {
					continue;
				}
				return true;
			}
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
	 * target_matches() for a floor token, with the site's own host removed
	 * from the URL first.
	 *
	 * Floor tokens are plugin names, and a plugin's own website is often
	 * named after the plugin. On notificationx.com the `notificationx` token
	 * matched every same-origin script URL, so Defer JS and Delay JS skipped
	 * every script on the site. The path still matches, so a script under
	 * /plugins/notificationx/ keeps its protection, and a third-party host
	 * such as consent.cookiebot.com still matches in full.
	 *
	 * @param string $needle Floor token.
	 * @param string $handle Script handle.
	 * @param string $src    Script URL.
	 */
	private static function floor_matches( string $needle, string $handle, string $src ): bool {
		if ( '' === $needle ) {
			return false;
		}
		if ( $handle === $needle ) {
			return true;
		}
		foreach ( array( $src, self::original_src( $handle ) ) as $url ) {
			$url = self::without_own_host( $url );
			if ( '' !== $url && false !== stripos( $url, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The URL without its scheme and host when the host is the site's own.
	 * Any other URL comes back unchanged.
	 *
	 * @param string $url Script URL.
	 */
	private static function without_own_host( string $url ): string {
		if ( '' === $url || ! function_exists( 'home_url' ) ) {
			return $url;
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $host ) {
			return $url;
		}
		return (string) preg_replace( '#^(?:https?:)?//' . preg_quote( $host, '#' ) . '(?::\d+)?(?=[/?\#]|$)#i', '', $url );
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

	/**
	 * Whether the user EXPLICITLY named this script in delay_js_targets.
	 *
	 * Unlike is_delay_target() this never treats an empty list as
	 * everything and never falls back to the vendor list — it answers
	 * only "did the user deliberately point at this handle/URL?", which
	 * is what lets an explicit entry override the inline-bound guard.
	 *
	 * @param string $handle Script handle.
	 * @param string $src    Script URL.
	 */
	private static function is_user_named_target( string $handle, string $src ): bool {
		$opts    = self::opts();
		$targets = is_array( $opts['delay_js_targets'] ?? null ) ? $opts['delay_js_targets'] : array();
		foreach ( $targets as $needle ) {
			$needle = (string) $needle;
			if ( '' !== $needle && self::target_matches( $needle, $handle, $src ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a delay_js_targets entry NAMES the consent manager whose floor
	 * token matched this tag, and so lifts the floor for it.
	 *
	 * is_user_named_target() is not enough here: it asks whether any entry
	 * matches the tag, and a delay target is a URL substring. `/plugins/`,
	 * `.js`, `min.js`, `frontend` or the site's own host each match every
	 * consent banner on the page, so one broad entry switched the floor off
	 * for all of them and brought #275 back. An entry names the vendor when
	 * it is the exact handle, or when it contains the vendor's keyword (or
	 * its floor token) and still matches the tag, so `notificationx` and
	 * `/plugins/notificationx/` lift NotificationX, `termly` cannot lift
	 * Cookiebot, and `/plugins/` lifts nothing.
	 *
	 * A token added through `xspeed_js_exclusion_floor` has no keyword, so
	 * only an entry containing that token, or the exact handle, names it.
	 *
	 * @param string $floor_token The floor entry that matched this tag.
	 * @param string $handle      Script handle ('' when unknown).
	 * @param string $src         Script URL, or the body on the inline pass.
	 */
	private static function user_named_consent_manager( string $floor_token, string $handle, string $src ): bool {
		$opts    = self::opts();
		$targets = is_array( $opts['delay_js_targets'] ?? null ) ? $opts['delay_js_targets'] : array();
		$names   = array( $floor_token );
		if ( isset( self::CONSENT_MANAGER_NAMES[ $floor_token ] ) ) {
			$names[] = self::CONSENT_MANAGER_NAMES[ $floor_token ]['keyword'];
		}
		foreach ( $targets as $needle ) {
			$needle = trim( (string) $needle );
			if ( '' === $needle ) {
				continue;
			}
			$names_it = '' !== $handle && $handle === $needle;
			foreach ( $names as $name ) {
				if ( false !== stripos( $needle, $name ) ) {
					$names_it = true;
					break;
				}
			}
			if ( $names_it && self::target_matches( $needle, $handle, $src ) ) {
				return true;
			}
		}
		return false;
	}

	/** Both toggles on: delay_js and its carry-the-inline-snippets mode. */
	private static function smart_delay_enabled(): bool {
		$opts = self::opts();
		return ! empty( $opts['delay_js'] ) && ! empty( $opts['delay_js_smart'] );
	}

	/**
	 * Would Smart Delay postpone this handle's tag?
	 *
	 * The snippet-parking filter runs when WordPress prints a handle's
	 * `before` snippet — BEFORE script_loader_tag sees the tag itself — so
	 * the decision cannot be read back from what happened to the tag; both
	 * sides evaluate this same predicate. It mirrors the handle/src checks
	 * of delay_script_tag() only: the tag-level outs there (an optimizer
	 * opt-out attribute, a non-executable type) are invisible here, so a
	 * tag that keeps itself eager through one of those can still have its
	 * snippets parked. That parks an init until first interaction rather
	 * than throwing, and Smart Delay is opt-in — acceptable, and documented
	 * on the setting.
	 */
	private static function smart_delays_handle( string $handle ): bool {
		if ( '' === $handle ) {
			return false;
		}
		// Check the URL delay_script_tag() checks. original_src() is set
		// only when Minify JS rewrote the URL, so with Minify JS off it was
		// '' here. A URL exclusion then passed here and failed there, and
		// the snippets were parked while the script stayed live.
		$src = self::original_src( $handle );
		if ( '' === $src ) {
			$src = self::registered_src( $handle );
		}
		if ( self::is_excluded_script( $handle, $src, true ) ) {
			return false;
		}
		return self::is_delay_target( $handle, $src );
	}

	/**
	 * A handle's registered URL, made absolute the way WP_Scripts prints it,
	 * without the version query. '' when the handle has no file.
	 *
	 * @param string $handle Script handle.
	 */
	private static function registered_src( string $handle ): string {
		if ( ! function_exists( 'wp_scripts' ) ) {
			return '';
		}
		$scripts = wp_scripts();
		if ( ! $scripts instanceof \WP_Scripts ) {
			return '';
		}
		$reg = $scripts->registered[ $handle ] ?? null;
		$src = ( is_object( $reg ) && is_string( $reg->src ) ) ? $reg->src : '';
		if ( '' !== $src && ! preg_match( '#^(?:https?:)?//#i', $src ) ) {
			$src = (string) ( $scripts->base_url ?? '' ) . $src;
		}
		return $src;
	}

	/**
	 * Filter: `script_loader_tag`, after every other xSpeed pass. Un-park a
	 * handle's before/after snippets when its external tag was not delayed.
	 *
	 * park_smart_inline() decides before the tag exists, so a later rule
	 * that keeps the tag live (an opt-out attribute, a non-executable type,
	 * the late opt-out revert, another plugin's filter) left the snippets
	 * parked and the script live. The script then ran without the config
	 * its `before` snippet sets, which is how Elementor's frontend lost
	 * elementorFrontendConfig. This filter makes that state impossible.
	 *
	 * @param string $tag
	 * @param string $handle
	 * @param string $src
	 */
	public static function unpark_orphaned_smart_inline( $tag, $handle, $src ): string {
		if ( ! is_string( $tag ) || '' === $tag || '' === (string) $handle || false === stripos( $tag, 'text/xspeed-delayed' ) ) {
			return (string) $tag;
		}
		// Only the external tag in this string can carry data-xs-src. Its
		// `src` is gone once delayed, so open_tag_offsets() cannot find it.
		if ( preg_match( '#<script\b[^>]*(?<![-\w])data-xs-src\s*=#i', $tag ) ) {
			return $tag;
		}
		return (string) preg_replace_callback(
			'#<script\b([^>]*\sid\s*=\s*(["\'])' . preg_quote( (string) $handle, '#' ) . '-js-(?:before|after)\2[^>]*)>#i',
			static function ( array $m ): string {
				$attrs = $m[1];
				if ( 'text/xspeed-delayed' !== self::extract_type( $attrs ) ) {
					return $m[0];
				}
				$orig  = preg_match( '#\sdata-xs-type\s*=\s*(["\'])([^"\']*)\1#i', $attrs, $t ) ? $t[2] : '';
				$attrs = (string) preg_replace( self::TYPE_ATTR_RE, '', $attrs );
				$attrs = (string) preg_replace( '#\sdata-xs-(?:delay|type)\s*=\s*(["\'])[^"\']*\1#i', '', $attrs );
				if ( '' !== $orig ) {
					$attrs .= ' type="' . esc_attr( $orig ) . '"';
				}
				return '<script' . $attrs . '>';
			},
			$tag
		);
	}

	/**
	 * Park a delayed handle's own before/after snippet, in Smart Delay mode.
	 *
	 * Runs on `wp_inline_script_attributes`, which fires for every inline
	 * script WordPress prints itself — so it works on pages the HTML buffer
	 * never filters (a BYPASS route like /cart), where the handle's tag is
	 * still delayed by script_loader_tag. `-js-extra` stays eager on
	 * purpose: it is data assignments, harmless early and sometimes read by
	 * eager code.
	 *
	 * @param mixed  $attributes Inline script attributes.
	 * @param string $javascript The snippet body.
	 * @return mixed
	 */
	public static function park_smart_inline( $attributes, $javascript = '' ) {
		if ( ! is_array( $attributes ) || ! self::smart_delay_enabled() || self::skip_in_non_frontend_context() ) {
			return $attributes;
		}
		$id = isset( $attributes['id'] ) ? (string) $attributes['id'] : '';
		if ( ! preg_match( '#^(.+)-js-(?:before|after)$#', $id, $m ) ) {
			return $attributes;
		}
		if ( ! self::smart_delays_handle( $m[1] ) ) {
			return $attributes;
		}
		$type = isset( $attributes['type'] ) ? (string) $attributes['type'] : '';
		if ( in_array( $type, self::NON_EXECUTABLE_TYPES, true ) ) {
			return $attributes; // data, or parked by someone else on purpose.
		}
		// A delayed document.write replays after the document has closed
		// and replaces the page. Same rule as delay_inline_snippets().
		if ( false !== stripos( (string) $javascript, 'document.write' ) ) {
			return $attributes;
		}
		if ( '' !== $type && ! in_array( $type, self::DEFAULT_JS_TYPES, true ) ) {
			$stash = (string) preg_replace( '#[^a-z0-9/+.\-]#', '', $type );
			if ( '' !== $stash ) {
				$attributes['data-xs-type'] = $stash;
			}
		}
		$attributes['type']          = 'text/xspeed-delayed';
		$attributes['data-xs-delay'] = '1';
		return $attributes;
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
		self::$has_critical_css        = null;
		self::$exclusion_floor         = null;
		self::$inline_bound_handles    = null;
		self::$pristine_tag            = array();
		self::$our_late_attrs          = array();
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
	 * Per-request memo for page_has_critical_css(). Null = not resolved.
	 *
	 * @var bool|null
	 */
	private static $has_critical_css = null;

	/**
	 * Does something inline critical CSS for the page being served?
	 *
	 * Free generates none, so the answer comes from the filter: an extension
	 * that inlines critical CSS for this page returns true, and a site whose
	 * theme ships its own can too. Resolved once per request, because every
	 * stylesheet tag asks.
	 */
	public static function page_has_critical_css(): bool {
		if ( null === self::$has_critical_css ) {
			/**
			 * Whether the page being served has critical CSS inlined in its head.
			 *
			 * Async CSS defers stylesheets only when this is true; without
			 * critical CSS it leaves them render-blocking, because deferring
			 * them makes the page paint unstyled and shift. Return true when
			 * something inlines critical CSS for this page, or to keep deferring
			 * without it.
			 *
			 * @param bool $has_critical_css Default false.
			 */
			self::$has_critical_css = (bool) apply_filters( 'xspeed_async_css_page_has_critical_css', false );
		}
		return self::$has_critical_css;
	}

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
