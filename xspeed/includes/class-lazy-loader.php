<?php
/**
 * Lazy_Loader — rewrites img / iframe / video tags in rendered HTML to
 * add native `loading="lazy"` (or "eager" for above-the-fold) plus
 * `decoding="async"` on images. Also auto-adds missing width/height
 * attributes to prevent CLS.
 *
 * Why regex instead of DOMDocument:
 *   - DOMDocument forces a full HTML5 parse round trip per filter call;
 *     on a content-heavy post that's measurably slow. Regex over the
 *     specific tags is ~10× faster.
 *   - We don't need full DOM understanding — every rewrite is a tag-
 *     local attribute injection. Regex is sufficient + predictable.
 *   - Edge cases (img inside HTML comments, img in <script>) are rare
 *     in real post content; we leave those alone with a pre-pass that
 *     stubs out script / style / pre blocks before rewriting.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Lazy_Loader {

	/**
	 * In-process counter for above-the-fold skipping. Reset by
	 * process_html on every call so a fresh post starts at 0.
	 *
	 * @var int
	 */
	private static $image_counter = 0;

	/**
	 * Settings cache (one read per request).
	 *
	 * @var array|null
	 */
	private static $opts = null;

	/**
	 * Per-URL dimension cache (md5(src) => [w,h] | 0 for known-failure),
	 * hydrated from the `xspeed_img_dims` transient once per request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $src_dims_cache = null;

	/**
	 * True while a background pass is resolving dimensions.
	 *
	 * Front-end renders read the cache and never fetch; a warm pass is the
	 * one thing allowed to pay the network cost, because no visitor is
	 * waiting on it.
	 *
	 * @var bool
	 */
	private static $warming = false;

	/**
	 * Main entry point: take rendered HTML, return rewritten HTML.
	 * Pure function aside from the static counters.
	 */
	public static function process_html( string $html ): string {
		if ( '' === $html ) {
			return $html;
		}
		$opts = self::opts();

		// NOTE: the eager-load budget counter is NOT reset here. process_html
		// runs once per filter pass — the_content, post_thumbnail_html, and
		// once per get_avatar — so resetting per call let the featured image,
		// the first content image, AND every comment avatar each claim an
		// "eager" slot, defeating the budget. The counter is reset once per
		// page render via reset_state() on template_redirect, so it now
		// accumulates across all passes as intended. (FBS-82172 Bug 1)

		// Stub out <script>, <style>, <noscript>, <pre>, <code> blocks
		// so img tags embedded in them as text examples aren't
		// rewritten. Restore after pass.
		[ $work, $stubs ] = self::stub_safe_blocks( $html );

		// Tag matcher that respects quoted attribute values, so a ">" inside
		// an attribute (e.g. alt="a > b") doesn't end the match early and
		// corrupt the tag. Matches: double-quoted runs, single-quoted runs,
		// or any non-> char — repeated up to the real closing >.
		// (FBS-82172 Bug 3)
		$tag_re = static function ( string $name ): string {
			return '#<' . $name . '\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>#i';
		};

		if ( ! empty( $opts['lazy_images'] ) || ! empty( $opts['add_missing_dimensions'] ) ) {
			$work = self::apply_pass( $work, $tag_re( 'img' ), array( __CLASS__, 'rewrite_img' ) );
		}
		if ( ! empty( $opts['lazy_iframes'] ) ) {
			$work = self::apply_pass( $work, $tag_re( 'iframe' ), array( __CLASS__, 'rewrite_iframe' ) );
		}
		// Facade runs AFTER the lazy pass, deliberately. The facade keeps the
		// original tag inside <noscript> as the JS-less fallback, and that
		// fallback should carry loading="lazy" too — running this first would
		// produce an eager iframe for exactly the visitors least able to
		// afford one.
		//
		// Unlike every other pass here, the facade REPLACES the element
		// rather than injecting attributes into its opening tag — so it has
		// to consume the whole element, `</iframe>` included. Matching the
		// opening tag alone orphaned the closing tag outside the injected
		// <noscript>, which broke nesting and swallowed sibling content in
		// real browsers. The body is tempered (`(?!</?iframe\b)`) so an
		// unclosed iframe can't make the match run on to a LATER embed's
		// closing tag and eat everything in between; an iframe with no
		// closing tag simply doesn't match and passes through untouched.
		if ( ! empty( $opts['video_facade'] ) ) {
			$work = self::apply_pass(
				$work,
				'#(<iframe\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>)((?:(?!</?iframe\b).)*)</iframe\s*>#is',
				array( __CLASS__, 'rewrite_iframe_facade' )
			);
		}
		if ( ! empty( $opts['lazy_videos'] ) ) {
			$work = self::apply_pass( $work, $tag_re( 'video' ), array( __CLASS__, 'rewrite_video' ) );
			// A page builder's video block renders no <video> server-side, so
			// the pass above sees nothing to rewrite. Note that such markup is
			// here anyway, so the restorer ships and can defer the element the
			// block's own script creates. (See detect_attribute_video().)
			self::detect_attribute_video( $work );
		}
		// Self-hosted <video> facade — after the lazy pass for the same
		// reason as the iframe facade above: the original element lands in
		// <noscript> as the JS-less fallback, and that copy should carry
		// preload="none" too. Same whole-element, tempered match so an
		// unclosed <video> passes through rather than eating siblings.
		if ( ! empty( $opts['video_facade'] ) ) {
			$work = self::apply_pass(
				$work,
				'#(<video\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>)((?:(?!</?video\b).)*)</video\s*>#is',
				array( __CLASS__, 'rewrite_video_facade' )
			);
		}

		return self::restore_safe_blocks( $work, $stubs );
	}

	/**
	 * Run one rewrite pass, keeping the input if PCRE bails.
	 *
	 * preg_replace_callback() returns null when it hits the backtrack or
	 * recursion limit — on a large page that would otherwise blank the
	 * whole document. Returning the untouched HTML costs the optimization
	 * for that request and nothing else.
	 *
	 * @param callable $callback Rewrite callback for one match.
	 */
	private static function apply_pass( string $html, string $pattern, callable $callback ): string {
		$result = preg_replace_callback( $pattern, $callback, $html );

		return is_string( $result ) ? $result : $html;
	}

	private static function rewrite_img( array $m ): string {
		$tag  = $m[0];
		$opts = self::opts();

		// Explicit skip flag, or matches an exclusion pattern: opt OUT of
		// LAZY-LOADING only. Dimension injection (CLS protection) still
		// applies — excluding an above-the-fold hero/logo from lazy-load is
		// exactly when you most want its width/height kept. Previously both
		// of these returned early, silently stripping dimensions too.
		// (FBS-82172 Bug 2)
		//
		// `fetchpriority="high"` joins that set: an image carrying it has been
		// declared the LCP element by whoever rendered it — WordPress core, the
		// theme, a page builder, or our own Resource_Hints_Processor. Lazy-
		// loading it contradicts that declaration, because the tag would then
		// tell the browser to fetch at top priority AND that it may defer the
		// fetch indefinitely. Browsers resolve that in favour of the deferral,
		// so the hero arrives late and any layout sized from it (a Kadence hero
		// row, for example) reflows when it finally paints — the "sometimes
		// broken, sometimes fine" symptom, because it depends on paint timing.
		// Treat the hint as authoritative and keep the image eager. (#269)
		$skip_lazy = false !== stripos( $tag, 'data-skip-lazy' )
			|| false !== stripos( $tag, 'data-no-lazy' )
			|| self::has_high_fetchpriority( $tag )
			|| self::is_excluded( $tag, $opts );

		if ( $skip_lazy && ! empty( $opts['lazy_images'] ) ) {
			// An EXCLUDED image is one the user marked as above-the-fold (a
			// hero/logo) — the opposite of lazy. WordPress core adds
			// `loading="lazy"` to images by default (since 5.5), so merely
			// *skipping* our lazy pass would leave core's lazy attribute on
			// the LCP hero and tank LCP. Actively make it eager +
			// high-priority so an excluded hero loads immediately.
			$tag = self::set_attr( $tag, 'loading', 'eager' );
			$tag = self::set_attr( $tag, 'fetchpriority', 'high', true );
			$tag = self::set_attr( $tag, 'decoding', 'async', true );
		} elseif ( ! empty( $opts['lazy_images'] ) ) {
			// Above-the-fold skip: first N images get loading="eager"
			// instead of "lazy" so the LCP image isn't deferred. Only
			// non-excluded images consume the budget.
			self::$image_counter++;
			$is_above_fold = self::$image_counter <= max( 0, (int) ( $opts['eager_first_n'] ?? 1 ) );
			$tag           = self::set_attr( $tag, 'loading', $is_above_fold ? 'eager' : 'lazy' );
			$tag           = self::set_attr( $tag, 'decoding', 'async', true );
			// The eager hero should also drop any core `loading="lazy"`; the
			// set_attr above already overrode it. Give the first eager image
			// high fetch priority so it wins the LCP race.
			if ( $is_above_fold ) {
				$tag = self::set_attr( $tag, 'fetchpriority', 'high', true );
			}
		}

		if ( ! empty( $opts['add_missing_dimensions'] ) ) {
			$tag = self::ensure_dimensions( $tag );
		}

		return $tag;
	}

	private static function rewrite_iframe( array $m ): string {
		$tag = $m[0];
		if ( false !== stripos( $tag, 'data-skip-lazy' ) ) {
			return $tag;
		}
		if ( self::is_excluded( $tag, self::opts() ) ) {
			return $tag;
		}
		return self::set_attr( $tag, 'loading', 'lazy' );
	}

	/**
	 * Swap a recognised video embed for a click-to-play facade.
	 *
	 * Passes the element through untouched unless it is a provider we can
	 * build a facade for — an unknown iframe (a map, a form, a dashboard)
	 * must never be replaced by a play button.
	 *
	 * $m[0] is the WHOLE element (`<iframe …>…</iframe>`); $m[1] is just
	 * the opening tag. Attributes are read from the opening tag, but what
	 * goes into the <noscript> fallback — and what is returned on every
	 * bail-out path — is the whole element, so the closing tag is never
	 * left stranded outside it.
	 */
	private static function rewrite_iframe_facade( array $m ): string {
		$element = $m[0];
		$tag     = $m[1];

		if ( false !== stripos( $tag, 'data-skip-lazy' ) ) {
			return $element;
		}
		if ( self::is_excluded( $tag, self::opts() ) ) {
			return $element;
		}

		if ( ! preg_match( '#\bsrc\s*=\s*(["\'])(.*?)\1#i', $tag, $src_m ) ) {
			return $element;
		}
		$src = $src_m[2];

		$embed = Video_Facade::parse_embed( $src );
		if ( null === $embed ) {
			return $element;
		}

		$title = '';
		if ( preg_match( '#\btitle\s*=\s*(["\'])(.*?)\1#i', $tag, $title_m ) ) {
			$title = $title_m[2];
		}

		self::$facade_used = true;

		return Video_Facade::render( $element, $embed, $src, $title );
	}

	/** @var bool True once a facade has been rendered on this page. */
	private static $facade_used = false;

	/**
	 * True when this render produced at least one facade — the module uses
	 * it to decide whether the click handler is worth printing at all.
	 */
	public static function facade_used(): bool {
		return self::$facade_used;
	}

	private static function rewrite_video( array $m ): string {
		$tag = $m[0];
		if ( false !== stripos( $tag, 'data-skip-lazy' ) ) {
			return $tag;
		}
		/*
		 * An autoplaying video is the one case preload="none" cannot help:
		 * browsers fetch an autoplay source regardless of preload, because
		 * the author asked for it to start on its own. Setting the attribute
		 * would only make the markup lie about what happens.
		 *
		 * But "starts on its own" does not mean "must download before the
		 * visitor has scrolled anywhere near it". A page of nine autoplay
		 * demo clips pulled 44 MB on load and held the browser's loading
		 * indicator open for 33 s, while none of them were on screen.
		 *
		 * So defer the SOURCE and restore it when the element reaches the
		 * viewport, which is the first moment autoplay is meant to be
		 * visible anyway. The author's choice is honoured — the video still
		 * plays by itself — it simply costs nothing until it can be seen.
		 */
		if ( preg_match( '#\sautoplay(?=[\s/>=])#i', $tag ) ) {
			return self::defer_autoplay_source( $tag );
		}
		// HTML5 `<video>` doesn't support loading=lazy yet (Chromium
		// won't add it before there's broad support). What we CAN do
		// is set preload="none" so the browser doesn't pre-fetch the
		// video bytes until play is requested — that's the actual win
		// users want from "lazy-load videos".
		//
		// OVERRIDE an existing value rather than bailing on it: players
		// that ship preload="auto" or "metadata" (Elementor's video
		// widget, most block themes) are exactly the case this setting
		// exists for, and skipping them made it a no-op right where it
		// mattered. (#309 — a 924KB MP4 transferred in full on every run
		// with this setting on.)
		return self::set_attr( $tag, 'preload', 'none' );
	}

	/**
	 * Swap a self-hosted <video> for the click-to-play facade.
	 *
	 * The easy case of the facade, not the hard one: no third-party player
	 * to defer, and the element usually already carries a real poster
	 * frame. Bails out — element returned untouched — whenever the facade
	 * would be worse than the video:
	 *
	 *   - `autoplay` is a deliberate author choice (a hero background); a
	 *     play button in its place changes the page, not just its weight.
	 *   - no `poster` means the facade renders as a blank black box, which
	 *     is worse than the preload="none" the lazy pass already applied.
	 *   - no resolvable source means there is nothing to play on click.
	 *
	 * $m[0] is the whole element, $m[1] the opening tag — same contract as
	 * rewrite_iframe_facade() above, and the same rule: every bail-out
	 * path returns the WHOLE element so the closing tag is never stranded.
	 */
	private static function rewrite_video_facade( array $m ): string {
		$element = $m[0];
		$tag     = $m[1];

		if ( false !== stripos( $tag, 'data-skip-lazy' ) ) {
			return $element;
		}
		if ( preg_match( '#\sautoplay(?=[\s/>=])#i', $tag ) ) {
			return $element;
		}
		if ( self::is_excluded( $tag, self::opts() ) ) {
			return $element;
		}

		if ( ! preg_match( '#\bposter\s*=\s*(["\'])(.*?)\1#i', $tag, $poster_m ) || '' === trim( $poster_m[2] ) ) {
			return $element;
		}
		$poster = $poster_m[2];

		// Source: the src attribute, else the first <source src="…"> child.
		$src = '';
		if ( preg_match( '#\bsrc\s*=\s*(["\'])(.*?)\1#i', $tag, $src_m ) ) {
			$src = $src_m[2];
		} elseif ( preg_match( '#<source\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1#i', $m[2], $src_m ) ) {
			$src = $src_m[2];
		}
		if ( '' === trim( $src ) ) {
			return $element;
		}

		$title = '';
		if ( preg_match( '#\btitle\s*=\s*(["\'])(.*?)\1#i', $tag, $title_m ) ) {
			$title = $title_m[2];
		}

		self::$facade_used = true;

		return Video_Facade::render_native( $element, $src, $poster, $title );
	}

	/**
	 * Add an attribute to an opening tag if it isn't already present.
	 * Pass $only_if_missing=false to override an existing value (e.g.
	 * flipping loading="lazy" → "eager" on the first image).
	 */
	/**
	 * Hold an autoplay video's bytes until the element reaches the viewport.
	 *
	 * `preload="none"` is ignored for autoplay, so the only way to stop the
	 * download is to take the source away and give it back later. We move
	 * `src` to `data-xspeed-src` and drop `autoplay` — a `<video>` with no
	 * resolvable source fetches nothing — then the script below restores
	 * both when the element scrolls into view.
	 *
	 * Restoring `autoplay` rather than calling play() matters: play() from
	 * a non-user gesture is refused unless the video is muted, and returns
	 * a promise whose rejection most callers never handle. Setting the
	 * attribute lets the browser apply its own autoplay policy exactly as
	 * it would have on load.
	 *
	 * `<source>` children are handled too, since a video with multiple
	 * formats carries no `src` of its own.
	 *
	 * Marked with a data attribute rather than a class so a theme's CSS
	 * cannot accidentally select — or style away — the deferred state.
	 */
	private static function defer_autoplay_source( string $tag ): string {
		// Already processed (a second pass, or another plugin got there).
		if ( false !== stripos( $tag, 'data-xspeed-src' ) ) {
			return $tag;
		}

		$deferred = false;

		// The element's own src, when it has one.
		if ( preg_match( '#\bsrc\s*=\s*(["\'])(.*?)\1#i', $tag, $m ) && '' !== trim( $m[2] ) ) {
			$tag      = (string) preg_replace(
				'#\bsrc\s*=\s*(["\'])(.*?)\1#i',
				'data-xspeed-src="' . esc_attr( $m[2] ) . '"',
				$tag,
				1
			);
			$deferred = true;
		}

		if ( ! $deferred ) {
			// No src of its own — the <source> children carry it, and those
			// are outside this opening tag. Mark the element so the script
			// knows to move them, and let it do the work in the DOM where
			// the children are actually reachable.
			$tag = self::set_attr( $tag, 'data-xspeed-defer-sources', '1' );
		}

		// Without this the browser starts fetching the moment a source is
		// restored, which is what we want — but it must not autoplay before
		// then, and it must not report itself as autoplaying meanwhile.
		$tag = (string) preg_replace( '#\sautoplay(?=[\s/>=])#i', ' data-xspeed-autoplay="1"', $tag, 1 );

		// preload="none" as well: belt and braces for the window between
		// parse and the observer attaching.
		$tag = self::set_attr( $tag, 'preload', 'none' );

		self::$deferred_autoplay = true;

		return $tag;
	}

	/**
	 * Did this response defer at least one autoplay video? Gates the script
	 * so a page with no such video ships no extra bytes.
	 *
	 * @var bool
	 */
	private static $deferred_autoplay = false;

	/** Whether the viewport script needs to be injected into this response. */
	public static function needs_autoplay_script(): bool {
		return self::$deferred_autoplay || self::$has_deferred_video_markup;
	}

	/**
	 * Page-builder video blocks that render NO <video> tag server-side.
	 *
	 * Essential Blocks' advanced-video, and widgets shaped like it, ship a
	 * plain <div> carrying the file URL in an attribute and let their own JS
	 * build the player after load. The PHP pass cannot rewrite what is not
	 * there, so a page of nine such blocks was completely untouched — which
	 * is exactly the 44 MB case this feature exists for.
	 *
	 * We deliberately do NOT rewrite those attributes. They belong to
	 * another plugin, whose script reads them on init; renaming one is how
	 * you get a player that silently never appears. Instead we note that
	 * such markup is present so the restorer ships, and let its
	 * MutationObserver catch the <video> the block creates — at which point
	 * it is an ordinary element we can defer like any other.
	 *
	 * @var bool
	 */
	private static $has_deferred_video_markup = false;

	/**
	 * Does this HTML carry a video URL in an attribute rather than a tag?
	 *
	 * Matched on the URL, not on any one plugin's attribute name: `data-url`
	 * is Essential Blocks, but `data-src`, `data-video-url` and others are
	 * equally common, and a rule keyed to one vendor would miss the rest.
	 */
	private static function detect_attribute_video( string $html ): void {
		if ( self::$has_deferred_video_markup ) {
			return;
		}
		if ( preg_match( '#\sdata-[\w-]+\s*=\s*(["\'])[^"\']*\.(?:mp4|webm|m4v|ogv|mov)(?:\?[^"\']*)?\1#i', $html ) ) {
			self::$has_deferred_video_markup = true;
		}
	}

	/**
	 * Restore the source when the video reaches the viewport.
	 *
	 * Dependency-free and tiny, matching Video_Facade::facade_script(). The
	 * rootMargin starts the fetch slightly before the element is visible so
	 * playback begins without a visible stall.
	 */
	public static function autoplay_script(): string {
		return <<<'JS'
(function(){
var S='video[data-xspeed-src],video[data-xspeed-defer-sources]';

/*
 * Intercept the ASSIGNMENT, because observing the DOM is always too late.
 *
 * Measured on a live page: a builder's video player creates nine elements
 * and sets `src` BEFORE inserting them, so a MutationObserver watching for
 * insertions saw zero of them — and the browser had already begun fetching
 * by the time any observer could run. The order is: setAttribute('src'),
 * then setAttribute('preload','auto'), then insert. Only the first of those
 * matters, and it happens off-DOM.
 *
 * So wrap the two ways a source can be set on a media element and hold the
 * value instead of applying it. Nothing else can start a download: a
 * <video> with no resolvable source fetches nothing. The value is stored on
 * the element and handed back by go() when it reaches the viewport.
 *
 * Scoped to <video> only. <audio> is small and usually deliberate, and
 * touching it would change behaviour nobody complained about.
 */
try{
var VP=window.HTMLMediaElement&&HTMLMediaElement.prototype;
var SD=VP&&Object.getOwnPropertyDescriptor(VP,'src');
var hold=function(el,val){
if(el.tagName!=='VIDEO')return false;
if(el.getAttribute('data-xspeed-loaded'))return false; // released: let it through
if(!val)return false;
el.setAttribute('data-xspeed-src',String(val));
el.setAttribute('data-xspeed-adopted','1');
return true;
};
if(SD&&SD.set){
Object.defineProperty(VP,'src',{configurable:true,enumerable:SD.enumerable,
get:function(){return SD.get.call(this);},
set:function(v){if(hold(this,v))return;return SD.set.call(this,v);}});
}
var SA=Element.prototype.setAttribute;
Element.prototype.setAttribute=function(n,v){
if(n==='src'&&hold(this,v))return;
// An eager preload on a held video would re-arm the fetch the moment a
// source comes back; keep it at none until we release it deliberately.
if(n==='preload'&&this.tagName==='VIDEO'&&this.getAttribute('data-xspeed-src')&&v!=='none')
return SA.call(this,'preload','none');
return SA.call(this,n,v);
};
}catch(e){}
function go(v){
if(v.getAttribute('data-xspeed-loaded'))return;
v.setAttribute('data-xspeed-loaded','1');
var s=v.getAttribute('data-xspeed-src');
if(s){v.setAttribute('src',s);v.removeAttribute('data-xspeed-src');}
if(v.getAttribute('data-xspeed-defer-sources')){
var c=v.querySelectorAll('source[data-xspeed-src]');
for(var i=0;i<c.length;i++){c[i].setAttribute('src',c[i].getAttribute('data-xspeed-src'));c[i].removeAttribute('data-xspeed-src');}
v.removeAttribute('data-xspeed-defer-sources');
}

if(v.getAttribute('data-xspeed-autoplay')){v.setAttribute('autoplay','');v.removeAttribute('data-xspeed-autoplay');}
v.removeAttribute('preload');
// load() picks up the sources we just restored; without it a <video>
// that has already failed to resolve a source will not retry.
if(v.load)v.load();
}
// A multi-format <video> carries no src of its own — the <source> children
// do, and those sit outside the opening tag PHP rewrote. Strip them here,
// as early as this script runs, then restore on intersect like the rest.
function strip(){
var d=document.querySelectorAll('video[data-xspeed-defer-sources]');
for(var i=0;i<d.length;i++){
if(d[i].getAttribute('data-xspeed-loaded'))continue;
var c=d[i].querySelectorAll('source[src]');
for(var j=0;j<c.length;j++){c[j].setAttribute('data-xspeed-src',c[j].getAttribute('src'));c[j].removeAttribute('src');}
if(c.length&&d[i].load)d[i].load();
}
}
// A page-builder block builds its <video> after load, so PHP never saw it
// and it arrives with a live src and autoplay already set. Defer it here,
// the same way the server would have, BEFORE the browser gets far into
// fetching it. Only autoplay videos: anything else is already covered by
// preload="none" and taking a source from a user-controlled player would
// break its own play button.
function adopt(){
// Any JS-built <video> that would fetch on sight — NOT just autoplay.
// Measured on a live page: a builder's video block creates nine elements
// with autoplay=false and preload="auto", so an autoplay-only selector
// skipped every one of them and 40 MB still downloaded. preload="auto" is
// the same eager-fetch instruction by another name, and the server pass
// would have rewritten it to "none" had the element existed in the HTML.
var a=document.querySelectorAll('video[autoplay]:not([data-xspeed-loaded]):not([data-xspeed-adopted]),video[preload="auto"]:not([data-xspeed-loaded]):not([data-xspeed-adopted]),video[preload="metadata"]:not([data-xspeed-loaded]):not([data-xspeed-adopted])');
for(var i=0;i<a.length;i++){
var v=a[i];
v.setAttribute('data-xspeed-adopted','1');
var auto=v.hasAttribute('autoplay');
var s=v.getAttribute('src');
if(s){v.setAttribute('data-xspeed-src',s);v.removeAttribute('src');}
var c=v.querySelectorAll('source[src]');
for(var j=0;j<c.length;j++){c[j].setAttribute('data-xspeed-src',c[j].getAttribute('src'));c[j].removeAttribute('src');}
if(c.length)v.setAttribute('data-xspeed-defer-sources','1');
// Only remember autoplay for the ones that actually had it — restoring it
// on a video the author left click-to-play would start playback nobody
// asked for.
if(auto){v.removeAttribute('autoplay');v.setAttribute('data-xspeed-autoplay','1');}
v.setAttribute('preload','none');
if(v.load)v.load();
}
}
function scan(){
strip();
adopt();
var v=document.querySelectorAll(S);
if(!('IntersectionObserver'in window)){for(var i=0;i<v.length;i++)go(v[i]);return;}
var o=new IntersectionObserver(function(es){
for(var i=0;i<es.length;i++){if(es[i].isIntersecting){go(es[i].target);o.unobserve(es[i].target);}}
},{rootMargin:'200px'});
for(var j=0;j<v.length;j++)o.observe(v[j]);
}
if(document.readyState!=='loading')scan();else document.addEventListener('DOMContentLoaded',scan);
// Players that build their <video> after load (page-builder video blocks)
// must be caught the INSTANT the element lands. A debounce loses the race:
// the browser begins fetching as soon as a src is set, so by the time a
// timer fires the bytes are already committed. adopt() is idempotent and
// cheap (one guarded querySelectorAll), so run it synchronously on every
// mutation and only debounce the fuller scan that attaches observers.
if(window.MutationObserver){
var t;
new MutationObserver(function(){
adopt();
clearTimeout(t);t=setTimeout(scan,200);
}).observe(document.documentElement,{childList:true,subtree:true});
}
})();
JS;
	}

	private static function set_attr( string $tag, string $name, string $value, bool $only_if_missing = false ): string {
		$pattern = '#\b' . preg_quote( $name, '#' ) . '\s*=\s*(["\'][^"\']*["\']|\S+)#i';
		if ( preg_match( $pattern, $tag ) ) {
			if ( $only_if_missing ) {
				return $tag;
			}
			return (string) preg_replace( $pattern, $name . '="' . $value . '"', $tag, 1 );
		}
		// Inject before the closing > (preserving self-closing `/>` if present).
		if ( preg_match( '#(/?>)$#', $tag, $m ) ) {
			$close = $m[1];
			return substr( $tag, 0, -strlen( $close ) ) . ' ' . $name . '="' . $value . '"' . $close;
		}
		return $tag;
	}

	/**
	 * Attempt to fill in missing width / height from either an attached
	 * media library record (when class="wp-image-N") or from the local
	 * filesystem when src points at the uploads dir. Skip when we can't
	 * resolve cheaply — never block the request on a remote getimagesize.
	 */
	private static function ensure_dimensions( string $tag ): string {
		$has_w = (bool) preg_match( '#\bwidth\s*=#i', $tag );
		$has_h = (bool) preg_match( '#\bheight\s*=#i', $tag );
		if ( $has_w && $has_h ) {
			return $tag;
		}

		// Try wp-image-<id> class first (cheapest path; one DB-cached
		// get_post_meta call).
		if ( preg_match( '#\bclass\s*=\s*["\']([^"\']*)["\']#i', $tag, $cm ) && preg_match( '#wp-image-(\d+)#i', $cm[1], $idm ) ) {
			$dims = self::dimensions_for_attachment( (int) $idm[1] );
			if ( $dims ) {
				return self::apply_dimensions( $tag, $dims, $has_w, $has_h );
			}
		}

		// No wp-image-N class — page-builder markup (Essential Blocks and
		// friends) never emits it, which is why the setting silently failed
		// on those images (issue #37). Resolve from the src instead, but only
		// when the tag doesn't already tell us it renders at some other size:
		// stamping the intrinsic file size onto a responsive or CSS-sized
		// image would CREATE the layout shift this feature exists to remove.
		if ( ! self::has_constrained_render( $tag ) && preg_match( '#\bsrc\s*=\s*["\']([^"\']+)["\']#i', $tag, $sm ) ) {
			$dims = self::dimensions_for_src( $sm[1] );
			if ( $dims ) {
				return self::apply_dimensions( $tag, $dims, $has_w, $has_h );
			}
		}

		// Couldn't resolve. Leave the tag alone — better no dimensions
		// than wrong ones.
		return $tag;
	}

	/**
	 * True when the tag already declares itself the LCP image via
	 * `fetchpriority="high"`.
	 *
	 * Only "high" counts. `fetchpriority="low"` and `="auto"` say the opposite
	 * (or nothing), and an image marked low-priority is a perfectly good
	 * lazy-load candidate. Pure — unit-tested.
	 */
	public static function has_high_fetchpriority( string $tag ): bool {
		return 1 === preg_match( '#\bfetchpriority\s*=\s*["\']?high\b#i', $tag );
	}

	/**
	 * True when the tag says it renders at a size other than the file's
	 * intrinsic one — a `srcset`/`sizes` pair (the browser picks a
	 * candidate) or an inline width/height style.
	 *
	 * Only guards the src-suffix fallback. The `wp-image-N` path stays
	 * unguarded: attachment metadata is authoritative, and WordPress'
	 * own `wp_filter_content_tags()` adds dimensions to responsive
	 * images the same way. Pure — unit-tested.
	 */
	public static function has_constrained_render( string $tag ): bool {
		if ( preg_match( '#\bsrcset\s*=#i', $tag ) || preg_match( '#\bsizes\s*=#i', $tag ) ) {
			return true;
		}
		if ( preg_match( '#\bstyle\s*=\s*["\']([^"\']*)["\']#i', $tag, $m ) ) {
			// width/height in the inline style wins over the attribute, so
			// the file's intrinsic size would disagree with the layout.
			return 1 === preg_match( '#(?:^|;)\s*(?:max-)?(?:width|height)\s*:#i', $m[1] );
		}
		return false;
	}

	/** @param int[] $dims [width, height]. */
	private static function apply_dimensions( string $tag, array $dims, bool $has_w, bool $has_h ): string {
		// One dimension already present: derive the other from the file's
		// real aspect ratio rather than stamping its intrinsic size.
		//
		// A tag that says width="300" on a 1200x800 file renders 300x200. If
		// we wrote height="800" the browser would reserve a box two and a
		// half times too tall, then snap when the image painted — CREATING
		// the shift this feature exists to remove. Scaling keeps the reserved
		// box the shape the image will actually be.
		if ( $has_w !== $has_h ) {
			if ( $dims[0] <= 0 || $dims[1] <= 0 ) {
				return $tag;
			}
			$from     = $has_w ? 'width' : 'height';
			$declared = self::attr_int( $tag, $from );
			// A declared value we cannot read in pixels (`50%`, `auto`) means
			// we do not know the rendered size, so there is no ratio to scale
			// from. Stamping the intrinsic size here is exactly the bug this
			// branch exists to avoid, so the tag is left alone.
			if ( $declared <= 0 ) {
				return $tag;
			}
			if ( $has_w ) {
				$height = (int) round( $dims[1] * $declared / $dims[0] );
				return $height > 0 ? self::set_attr( $tag, 'height', (string) $height ) : $tag;
			}
			$width = (int) round( $dims[0] * $declared / $dims[1] );
			return $width > 0 ? self::set_attr( $tag, 'width', (string) $width ) : $tag;
		}

		// A header that reported 0 for either side is not a measurement. Half
		// a dimension pair is worse than none: the browser reserves a box of
		// the wrong shape and still shifts when the real image lands.
		if ( $dims[0] <= 0 || $dims[1] <= 0 ) {
			return $tag;
		}

		if ( ! $has_w ) {
			$tag = self::set_attr( $tag, 'width', (string) $dims[0] );
		}
		if ( ! $has_h ) {
			$tag = self::set_attr( $tag, 'height', (string) $dims[1] );
		}
		return $tag;
	}

	/**
	 * Read one numeric attribute off a tag.
	 *
	 * Returns 0 for anything that is not a plain number — `width="50%"` and
	 * `width="auto"` are CSS-ish values whose pixel size we do not know, and
	 * scaling from them would invent a box rather than reserve one.
	 *
	 * @param string $tag  The tag.
	 * @param string $name Attribute name.
	 */
	private static function attr_int( string $tag, string $name ): int {
		// The value must be ENTIRELY digits. Matching a leading run would read
		// `width="50%"` as 50 and scale from a percentage as though it were
		// pixels — inventing a box rather than declining to guess.
		if ( ! preg_match( '#\b' . preg_quote( $name, '#' ) . '\s*=\s*(?:"(\d+)"|\'(\d+)\'|(\d+)(?=[\s/>]))#i', $tag, $m ) ) {
			return 0;
		}
		$value = '' !== ( $m[1] ?? '' ) ? $m[1] : ( '' !== ( $m[2] ?? '' ) ? $m[2] : ( $m[3] ?? '' ) );
		return (int) $value;
	}

	/**
	 * WordPress names resized files `<name>-WxH.<ext>` — when the suffix is
	 * present it IS the rendered size, resolvable with zero I/O (works for
	 * CDN-hosted copies too). Pure — unit-tested.
	 *
	 * @return int[]|null [width, height] or null.
	 */
	public static function parse_size_suffix( string $src ): ?array {
		$path = (string) preg_replace( '/[?#].*$/', '', $src );
		if ( preg_match( '#-(\d{1,4})x(\d{1,4})\.(?:jpe?g|png|gif|webp|avif)$#i', $path, $m ) ) {
			$w = (int) $m[1];
			$h = (int) $m[2];
			if ( $w > 0 && $h > 0 ) {
				return array( $w, $h );
			}
		}
		return null;
	}

	/**
	 * Intrinsic size of an image hosted on another domain.
	 *
	 * An image the site does not host is still an image whose dimensions
	 * decide whether the page jumps while it loads. Refusing to look them up
	 * was leaving real layout shift unfixed on any site that embeds media from
	 * a CDN, a sister site, or a shared asset host — and telling the owner to
	 * go and edit their content, which is not a fix a caching plugin should be
	 * proud of.
	 *
	 * The reason for the old refusal was sound but too broad: a page render
	 * must never block on somebody else's server. So this fetches only the
	 * first few KB — enough for the header of every format WordPress
	 * supports — with a short timeout, and caches the answer (successes AND
	 * failures) so a URL is fetched once rather than once per pageview.
	 *
	 * By default it runs only when something has already warmed the cache
	 * off-request (the preloader, a cron pass, WP-CLI). A visitor's request
	 * therefore never waits on it. A site that would rather pay the cost
	 * inline can opt in:
	 *
	 *     add_filter( 'xspeed_lazy_remote_dimensions_inline', '__return_true' );
	 *
	 * and one that wants nothing fetched from other hosts at all can opt out:
	 *
	 *     add_filter( 'xspeed_lazy_remote_dimensions', '__return_false' );
	 *
	 * @param string $src Absolute URL on another host.
	 * @return int[]|null [width, height] or null when it cannot be resolved.
	 */
	private static function remote_dimensions( string $src ): ?array {
		/**
		 * Whether to resolve dimensions for images on other hosts at all.
		 *
		 * @param bool   $enabled Default true.
		 * @param string $src     The image URL.
		 */
		if ( ! apply_filters( 'xspeed_lazy_remote_dimensions', true, $src ) ) {
			return null;
		}

		if ( ! function_exists( 'wp_remote_get' ) ) {
			return null;
		}

		// Only http(s). A data: or blob: src has no server to ask.
		if ( ! preg_match( '#^https?://#i', $src ) ) {
			return null;
		}

		/**
		 * Whether a front-end request may perform the fetch itself.
		 *
		 * Off by default: the whole point of the cache is that a visitor
		 * never waits on another host. Warm passes (cron, preloader, CLI)
		 * set this true for themselves.
		 *
		 * @param bool $inline Default false.
		 */
		$inline = (bool) apply_filters( 'xspeed_lazy_remote_dimensions_inline', self::$warming );
		if ( ! $inline ) {
			return null;
		}

		// 32KB covers the header of JPEG, PNG, GIF, WebP and AVIF. Range is a
		// request, not a guarantee — a server that ignores it sends the whole
		// file, which the timeout still bounds.
		$resp = wp_remote_get(
			$src,
			array(
				'timeout'    => 5,
				'headers'    => array( 'Range' => 'bytes=0-32767' ),
				'user-agent' => 'xSpeed/dimension-probe',
			)
		);
		if ( is_wp_error( $resp ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code && 206 !== $code ) {
			return null;
		}

		$body = (string) wp_remote_retrieve_body( $resp );
		if ( '' === $body ) {
			return null;
		}

		// getimagesizefromstring reads the header out of the bytes we already
		// have — no second request, no temp file.
		$size = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a truncated or non-image body must degrade to null, not warn.
		if ( is_array( $size ) && ! empty( $size[0] ) && ! empty( $size[1] ) ) {
			return array( (int) $size[0], (int) $size[1] );
		}
		return null;
	}

	/**
	 * Resolve dimensions from an image URL, cheapest first:
	 *   1. `-WxH` filename suffix (no I/O).
	 *   2. Intrinsic size of the local file when src is under uploads
	 *      (getimagesize on the header — no remote fetches, ever).
	 *   3. Attachment lookup by URL (uploads-hosted src only).
	 * Results — including failures — are cached per URL in a bounded
	 * transient so each image pays the lookup once, not per pageview.
	 *
	 * @return int[]|null [width, height] or null.
	 */
	private static function dimensions_for_src( string $src ): ?array {
		$suffix = self::parse_size_suffix( $src );
		if ( $suffix ) {
			return $suffix;
		}

		if ( ! function_exists( 'wp_get_upload_dir' ) || ! function_exists( 'get_transient' ) ) {
			return null;
		}
		$uploads = wp_get_upload_dir();
		$baseurl = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		$basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';

		// The cache is consulted BEFORE the local/remote split, so a remote
		// image pays its lookup once for the life of the transient rather
		// than once per page render.
		if ( null === self::$src_dims_cache ) {
			$stored               = get_transient( 'xspeed_img_dims' );
			self::$src_dims_cache = is_array( $stored ) ? $stored : array();
		}
		$key = md5( $src );
		if ( array_key_exists( $key, self::$src_dims_cache ) ) {
			$hit = self::$src_dims_cache[ $key ];
			if ( is_array( $hit ) ) {
				return $hit;
			}
			// A cached FAILURE, not a cached answer. A front-end render
			// honours it — that is the whole point, one failed lookup must
			// not cost a request on every pageview. A warm pass does NOT:
			// it was asked to resolve these, nothing is waiting on it, and
			// the usual reason for a failure is a moment of bad luck rather
			// than an image that can never be measured.
			//
			// Without this, one slow response poisoned a URL for the life of
			// the transient. It happened on a real site: 15 images cached as
			// failures, and every later warm returned "resolved: 0" while the
			// page kept shifting.
			if ( ! self::$warming || ! self::failure_is_retryable( $hit ) ) {
				return null;
			}
		}

		$is_local = '' !== $baseurl && '' !== $basedir && 0 === strpos( $src, $baseurl );

		$dims = null;

		if ( $is_local ) {
			$relative = (string) preg_replace( '/[?#].*$/', '', substr( $src, strlen( $baseurl ) ) );
			if ( false === strpos( $relative, '..' ) ) {
				$file = $basedir . $relative;
				if ( is_file( $file ) ) {
					$size = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- non-image/corrupt file must degrade to null, not warn.
					if ( is_array( $size ) && ! empty( $size[0] ) && ! empty( $size[1] ) ) {
						$dims = array( (int) $size[0], (int) $size[1] );
					}
				}
			}

			// File not on disk (offloaded originals) — one DB lookup by URL.
			if ( null === $dims && function_exists( 'attachment_url_to_postid' ) ) {
				$id = (int) attachment_url_to_postid( $src );
				if ( $id > 0 ) {
					$dims = self::dimensions_for_attachment( $id );
				}
			}
		} else {
			$dims = self::remote_dimensions( $src );
		}

		// Cache success AND failure (0), bounded so the blob can't grow
		// unbounded on media-heavy sites.
		if ( count( self::$src_dims_cache ) >= 500 ) {
			self::$src_dims_cache = array_slice( self::$src_dims_cache, 250, null, true );
		}
		// A resolved size is permanent — the file's intrinsic dimensions do
		// not change under the same URL. A failure is a snapshot of one
		// moment, so it is stored as a TIMESTAMP rather than a bare 0 and
		// stops counting after a while. Storing both the same way is what let
		// a transient blip look identical to "this can never be measured".
		self::$src_dims_cache[ $key ] = null === $dims ? time() : $dims;
		if ( function_exists( 'set_transient' ) ) {
			set_transient( 'xspeed_img_dims', self::$src_dims_cache, DAY_IN_SECONDS );
		}
		return $dims;
	}

	/**
	 * @return int[]|null [width, height] or null
	 */
	private static function dimensions_for_attachment( int $attachment_id ): ?array {
		if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return null;
		}
		return array( (int) $meta['width'], (int) $meta['height'] );
	}

	private static function is_excluded( string $tag, array $opts ): bool {
		$excluded = $opts['excluded_images'] ?? array();
		if ( ! is_array( $excluded ) || empty( $excluded ) ) {
			return false;
		}
		foreach ( $excluded as $pattern ) {
			$pattern = (string) $pattern;
			if ( '' === $pattern ) {
				continue;
			}
			if ( false !== stripos( $tag, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replace <script>, <style>, <noscript>, <pre>, <code> blocks with
	 * placeholder tokens before tag rewriting. Returns [stubbed_html,
	 * stubs_map]. Restore via restore_safe_blocks().
	 *
	 * @return array{0: string, 1: array<string,string>}
	 */
	private static function stub_safe_blocks( string $html ): array {
		$stubs = array();
		$re    = '#<(script|style|noscript|pre|code)\b[^>]*>.*?</\1>#is';
		$out   = preg_replace_callback(
			$re,
			static function ( $m ) use ( &$stubs ) {
				$key            = '<!--XSPEED_LAZY_STUB_' . count( $stubs ) . '-->';
				$stubs[ $key ] = $m[0];
				return $key;
			},
			$html
		);
		return array( (string) $out, $stubs );
	}

	private static function restore_safe_blocks( string $html, array $stubs ): string {
		if ( empty( $stubs ) ) {
			return $html;
		}
		return strtr( $html, $stubs );
	}

	private static function opts(): array {
		if ( null === self::$opts ) {
			self::$opts = Settings_Manager::get( 'lazy' );
		}
		return self::$opts;
	}

	/**
	 * How long a failed lookup is trusted before a warm pass tries again.
	 *
	 * Long enough that a genuinely unmeasurable URL is not re-fetched on every
	 * crawl, short enough that an outage does not cost a day of layout shift.
	 */
	private const FAILURE_RETRY_AFTER = 900; // 15 minutes.

	/**
	 * Whether a stored failure is old enough to be worth retrying.
	 *
	 * Legacy entries were written as a bare `0` with no timestamp. Those are
	 * always retryable: they predate this distinction, and one extra request
	 * for each is a far better outcome than leaving a site permanently unable
	 * to resolve images it could resolve today.
	 *
	 * @param mixed $entry Stored cache value.
	 */
	private static function failure_is_retryable( $entry ): bool {
		if ( ! is_int( $entry ) || $entry <= 0 ) {
			return true; // legacy `0`, or nonsense — retry.
		}
		return ( time() - $entry ) >= self::FAILURE_RETRY_AFTER;
	}

	/**
	 * Whether this URL's dimensions are already known (or known-unresolvable).
	 *
	 * Lets a caller skip URLs that would cost nothing to look up, so a bounded
	 * batch spends its budget on images it has not seen. Without this a capped
	 * collector re-picks the same first N images every pass — they are always
	 * in the same DOM order — and anything past the cap is never resolved at
	 * all, however many times the crawl runs.
	 *
	 * Reads the cache only; never fetches.
	 *
	 * @param string $src Absolute image URL.
	 */
	public static function dimensions_known( string $src ): bool {
		if ( ! function_exists( 'get_transient' ) ) {
			return false;
		}
		if ( null === self::$src_dims_cache ) {
			$stored               = get_transient( 'xspeed_img_dims' );
			self::$src_dims_cache = is_array( $stored ) ? $stored : array();
		}
		$key = md5( $src );
		if ( ! array_key_exists( $key, self::$src_dims_cache ) ) {
			return false;
		}
		$hit = self::$src_dims_cache[ $key ];
		if ( is_array( $hit ) ) {
			return true;
		}
		// A failure that has aged out is NOT known — reporting it as known
		// would make the crawl skip the one URL that has become worth
		// retrying.
		return ! self::failure_is_retryable( $hit );
	}

	/**
	 * Resolve and cache dimensions for a batch of image URLs.
	 *
	 * Meant for anything running OFF a visitor's request — the preloader
	 * crawling the sitemap, a cron pass, `wp xspeed lazy warm-dimensions`.
	 * Once warmed, the front end serves the dimensions from cache, so the
	 * layout shift is fixed without a single visitor waiting on another host.
	 *
	 * @param string[] $urls Absolute image URLs.
	 * @return int How many were resolved.
	 */
	public static function warm_dimensions( array $urls ): int {
		$resolved       = 0;
		self::$warming  = true;
		try {
			foreach ( array_unique( $urls ) as $url ) {
				if ( ! is_string( $url ) || '' === $url ) {
					continue;
				}
				if ( self::dimensions_for_src( $url ) ) {
					$resolved++;
				}
			}
		} finally {
			// In a finally so a throw mid-batch cannot leave the flag set and
			// silently turn every later front-end render into a fetcher.
			self::$warming = false;
		}
		return $resolved;
	}

	/**
	 * Test-only: clear cached opts + counter between assertions.
	 */
	public static function reset_state(): void {
		self::$opts           = null;
		self::$image_counter  = 0;
		self::$src_dims_cache = null;
		self::$facade_used    = false;
		self::$warming        = false;
		// Both gate whether the autoplay restorer is printed. Left set, one
		// page carrying a video would make every later response in the same
		// process ship the script — and, worse for the preloader, a warmed
		// page could inherit a decision made for a different URL.
		self::$deferred_autoplay         = false;
		self::$has_deferred_video_markup = false;
	}
}
