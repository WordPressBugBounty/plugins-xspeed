<?php
/**
 * Video_Facade — replace embedded-video iframes with a lightweight
 * placeholder that loads the real player only when the visitor asks.
 *
 * Why this exists even though the Lazy module already sets
 * `loading="lazy"` on iframes: deferring the iframe only delays the cost,
 * it doesn't remove it. The moment a YouTube embed scrolls into view the
 * browser fetches ~1MB of player JavaScript across several third-party
 * connections — on a page with three embeds that is most of the page
 * weight, and it lands exactly when the visitor is trying to read. A
 * facade replaces the iframe with a poster image and a play button; the
 * real embed is injected on click, so a visitor who never plays the
 * video never pays for the player at all.
 *
 * Privacy side effect worth stating plainly: the facade makes FEWER
 * third-party requests than the embed it replaces, and none at all for
 * providers whose poster we can't derive without an API call.
 *
 * Everything in the parsing half is pure (no WP, no I/O) so provider
 * detection is unit-testable against the real-world src shapes.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Video_Facade {

	/**
	 * Identify the provider and video id behind an iframe src.
	 *
	 * Handles the shapes that actually appear in the wild: youtube.com
	 * /embed/, youtube-nocookie.com, youtu.be short links, and Vimeo's
	 * player.vimeo.com/video/. Returns null for anything else so an
	 * unknown embed is passed through untouched rather than guessed at.
	 *
	 * @return array{provider:string,id:string}|null
	 */
	public static function parse_embed( string $src ): ?array {
		$src = trim( html_entity_decode( $src, ENT_QUOTES ) );
		if ( '' === $src ) {
			return null;
		}

		// Protocol-relative and bare-host srcs still need to match.
		$probe = preg_replace( '#^//#', 'https://', $src );

		// YouTube: /embed/<id>, youtube-nocookie, and youtu.be/<id>.
		if ( preg_match(
			'#^https?://(?:www\.)?(?:youtube(?:-nocookie)?\.com/embed/|youtu\.be/)([A-Za-z0-9_-]{6,20})#i',
			(string) $probe,
			$m
		) ) {
			return array(
				'provider' => 'youtube',
				'id'       => $m[1],
			);
		}

		// Vimeo: player.vimeo.com/video/<numeric id>.
		if ( preg_match(
			'#^https?://player\.vimeo\.com/video/(\d{6,12})#i',
			(string) $probe,
			$m
		) ) {
			return array(
				'provider' => 'vimeo',
				'id'       => $m[1],
			);
		}

		return null;
	}

	/**
	 * Poster URL for an embed, or '' when we can't derive one without an
	 * extra API round-trip.
	 *
	 * YouTube exposes deterministic thumbnail URLs, so a poster costs one
	 * image request — far less than the player it replaces. Vimeo requires
	 * an oEmbed lookup per video, which would mean a server-side HTTP call
	 * during page render; we decline and render a neutral facade instead.
	 */
	public static function poster_url( string $provider, string $id ): string {
		if ( 'youtube' === $provider ) {
			// `/embed/videoseries?list=…` and `/embed/live_stream?channel=…`
			// put a keyword where a video id normally goes. Both are
			// id-shaped enough to pass parse_embed, but neither names a
			// video, so the thumbnail URL built from them 404s — a broken
			// request on every page view. The facade still renders (a
			// playlist loads the same heavy player a single video does);
			// it just renders without a poster.
			if ( in_array( strtolower( $id ), array( 'videoseries', 'live_stream' ), true ) ) {
				return '';
			}

			// hqdefault exists for every video; maxres does not.
			return 'https://i.ytimg.com/vi/' . rawurlencode( $id ) . '/hqdefault.jpg';
		}

		return '';
	}

	/**
	 * The real player URL to swap in on click — autoplay appended so the
	 * click that revealed the player also starts it (one click, not two).
	 */
	public static function player_url( string $src ): string {
		$src = html_entity_decode( $src, ENT_QUOTES );
		if ( false !== strpos( $src, 'autoplay=' ) ) {
			return $src;
		}

		return $src . ( false === strpos( $src, '?' ) ? '?' : '&' ) . 'autoplay=1';
	}

	/**
	 * Build the facade markup for one parsed embed.
	 *
	 * Contract:
	 *   - a <button> (not a div) so it is focusable and keyboard-operable
	 *   - width/height/style carried over so layout doesn't shift
	 *   - the original iframe preserved inside <noscript> so a JS-less
	 *     visitor still gets the video
	 *   - the player URL travels in a data attribute; the swap is done by
	 *     the inline script in facade_script()
	 *
	 * $original_markup must be the COMPLETE element — `<iframe …></iframe>`,
	 * not just the opening tag. The caller replaces that whole span, so a
	 * fallback missing its closing tag would leave a stray `</iframe>`
	 * outside the <noscript> and break the surrounding markup.
	 *
	 * @param string                           $original_markup The untouched <iframe …></iframe> element.
	 * @param array{provider:string,id:string} $embed           Parsed provider + id.
	 * @param string                           $src             The iframe src.
	 * @param string                           $title           Accessible label for the play button.
	 */
	public static function render( string $original_markup, array $embed, string $src, string $title = '' ): string {
		$poster = self::poster_url( $embed['provider'], $embed['id'] );
		$player = self::player_url( $src );

		$label = '' !== $title
			? sprintf(
				/* translators: %s: video title. */
				__( 'Play video: %s', 'xspeed' ),
				$title
			)
			: __( 'Play video', 'xspeed' );

		$style = 'position:relative;display:block;width:100%;padding:0;border:0;cursor:pointer;background:#000;aspect-ratio:16/9;';
		if ( '' !== $poster ) {
			$style .= 'background-image:url(' . esc_url( $poster ) . ');background-size:cover;background-position:center;';
		}

		$markup  = '<button type="button" class="xspeed-video-facade" data-xspeed-video="' . esc_attr( $player ) . '"';
		$markup .= ' aria-label="' . esc_attr( $label ) . '" style="' . esc_attr( $style ) . '">';
		// Play glyph — inline SVG so the facade costs zero extra requests
		// beyond the poster itself.
		$markup .= '<span class="xspeed-video-facade__play" aria-hidden="true" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:68px;height:48px;border-radius:14px;background:rgba(0,0,0,.7);display:flex;align-items:center;justify-content:center;">';
		$markup .= '<svg width="24" height="24" viewBox="0 0 24 24" fill="#fff" focusable="false"><path d="M8 5v14l11-7z"/></svg>';
		$markup .= '</span>';
		$markup .= '</button>';
		$markup .= '<noscript>' . $original_markup . '</noscript>';

		return $markup;
	}

	/**
	 * Build the facade markup for one self-hosted <video>. (#309)
	 *
	 * Same contract as render(): a focusable <button>, the original
	 * element preserved whole inside <noscript>, the swap done by
	 * facade_script(). Differences that matter:
	 *
	 *   - the poster comes from the element's own poster attribute, never
	 *     derived — the caller has already refused to build a facade
	 *     without one, because a blank black box is worse than the video.
	 *   - the source URL travels in data-xspeed-video-native, a separate
	 *     attribute from the iframe player URL, so the click handler knows
	 *     to build a <video controls autoplay> rather than an <iframe>.
	 *
	 * @param string $original_markup The untouched <video …>…</video> element.
	 * @param string $src             The video file URL to load on click.
	 * @param string $poster          The element's own poster URL.
	 * @param string $title           Accessible label for the play button.
	 */
	public static function render_native( string $original_markup, string $src, string $poster, string $title = '' ): string {
		$label = '' !== $title
			? sprintf(
				/* translators: %s: video title. */
				__( 'Play video: %s', 'xspeed' ),
				$title
			)
			: __( 'Play video', 'xspeed' );

		$style  = 'position:relative;display:block;width:100%;padding:0;border:0;cursor:pointer;background:#000;aspect-ratio:16/9;';
		$style .= 'background-image:url(' . esc_url( $poster ) . ');background-size:cover;background-position:center;';

		$markup  = '<button type="button" class="xspeed-video-facade" data-xspeed-video-native="' . esc_url( $src ) . '"';
		$markup .= ' data-xspeed-poster="' . esc_url( $poster ) . '"';
		$markup .= ' aria-label="' . esc_attr( $label ) . '" style="' . esc_attr( $style ) . '">';
		$markup .= '<span class="xspeed-video-facade__play" aria-hidden="true" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:68px;height:48px;border-radius:14px;background:rgba(0,0,0,.7);display:flex;align-items:center;justify-content:center;">';
		$markup .= '<svg width="24" height="24" viewBox="0 0 24 24" fill="#fff" focusable="false"><path d="M8 5v14l11-7z"/></svg>';
		$markup .= '</span>';
		$markup .= '</button>';
		$markup .= '<noscript>' . $original_markup . '</noscript>';

		return $markup;
	}

	/**
	 * The one CSS rule the facade can't express as an inline style.
	 *
	 * Gutenberg wraps an embed in
	 * `figure.wp-has-aspect-ratio > div.wp-block-embed__wrapper`, gives the
	 * wrapper a `::before` with `padding-top:56.25%` to reserve the 16:9
	 * box, and then absolutely positions the iframe on top of it. Our
	 * facade is a `<button>`, so core's `… iframe { position:absolute }`
	 * rule doesn't reach it: the button flowed BELOW the reserved box and
	 * left a block of empty space the height of the placeholder (363px on
	 * a 645px-wide content column). Same fix core uses, aimed at the
	 * button — and `!important` because it has to beat the element's own
	 * inline style, which is the only place the facade can carry its
	 * standalone layout.
	 */
	public static function facade_style(): string {
		return '.wp-has-aspect-ratio .xspeed-video-facade{position:absolute!important;top:0;right:0;bottom:0;left:0;'
			. 'width:100%!important;height:100%!important;aspect-ratio:auto!important}'
			// Magnific Popup's iframe scaler positions only `iframe` children;
			// a facade that reaches a popup at runtime (a template class the
			// server pass doesn't know) would otherwise collapse to nothing
			// and the modal opens blank. Same absolute-fill treatment.
			. '.mfp-iframe-scaler .xspeed-video-facade{position:absolute!important;top:0;right:0;bottom:0;left:0;'
			. 'width:100%!important;height:100%!important;aspect-ratio:auto!important}';
	}

	/**
	 * The click handler, injected once per page that rendered a facade.
	 *
	 * Deliberately tiny and dependency-free: find the clicked facade,
	 * build the iframe it stands for, replace it. `allow` mirrors what
	 * the providers' own embed codes request so autoplay and fullscreen
	 * behave the same as an un-faceted embed.
	 */
	public static function facade_script(): string {
		return <<<'JS'
document.addEventListener('click',function(e){
var b=e.target.closest&&e.target.closest('.xspeed-video-facade');
if(!b)return;
var n=b.getAttribute('data-xspeed-video-native');
if(n){
var v=document.createElement('video');
v.setAttribute('src',n);
var p=b.getAttribute('data-xspeed-poster');if(p)v.setAttribute('poster',p);
v.setAttribute('controls','');
v.setAttribute('autoplay','');
v.setAttribute('playsinline','');
v.setAttribute('style','width:100%;aspect-ratio:16/9;background:#000;');
var nt=b.getAttribute('aria-label');if(nt)v.setAttribute('title',nt);
b.parentNode.replaceChild(v,b);
return;
}
var u=b.getAttribute('data-xspeed-video');if(!u)return;
var f=document.createElement('iframe');
// Released BEFORE src: the observer script's interceptor holds any
// provider src it sees, and the player the visitor just asked for is
// the one iframe that must load immediately.
f.setAttribute('data-xspeed-loaded','1');
f.setAttribute('src',u);
f.setAttribute('frameborder','0');
f.setAttribute('allow','accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture');
f.setAttribute('allowfullscreen','');
f.setAttribute('style','width:100%;aspect-ratio:16/9;border:0;');
var t=b.getAttribute('aria-label');if(t)f.setAttribute('title',t);
b.parentNode.replaceChild(f,b);
},false);
JS;
	}

	/**
	 * The early interceptor for embeds that never appear in the HTML.
	 *
	 * A page-builder video widget builds its YouTube/Vimeo <iframe> from
	 * its own script, so the server pass — which rewrites the response
	 * buffer — never sees an element to replace, and the full player
	 * (3MB+ of JS per embed) downloads on page load anyway. Measured live:
	 * one homepage carried three JS-built embeds and 22MB of YouTube
	 * player resources with the facade "on".
	 *
	 * Same playbook as Lazy_Loader::autoplay_script(), for the same
	 * reason: builders set `src` BEFORE inserting the element, so a
	 * MutationObserver alone is always too late — the fetch starts
	 * off-DOM. So the property setter and setAttribute are wrapped, a
	 * recognised provider src is parked in data-xspeed-held instead of
	 * applied, and the observer only has to dress the inert element as a
	 * facade once it lands in the DOM. The click handler above does the
	 * swap; its data-xspeed-loaded release mark is honoured here so the
	 * player it builds is never re-held.
	 *
	 * The provider patterns mirror parse_embed()/poster_url() and must
	 * stay in step with them — one facade, two capture paths.
	 */
	public static function observer_script(): string {
		$label = wp_json_encode( __( 'Play video', 'xspeed' ) );

		return <<<JS
(function(){
var L={$label};
function parse(u){
if(!u)return null;
u=String(u).replace(/^\/\//,'https://');
var m=u.match(/^https?:\/\/(?:www\.)?(?:youtube(?:-nocookie)?\.com\/embed\/|youtu\.be\/)([A-Za-z0-9_-]{6,20})/i);
if(m)return{p:'youtube',id:m[1]};
m=u.match(/^https?:\/\/player\.vimeo\.com\/video\/(\d{6,12})/i);
if(m)return{p:'vimeo',id:m[1]};
return null;
}
function hold(el,val){
if(!el||el.tagName!=='IFRAME')return false;
if(el.getAttribute('data-xspeed-loaded')||el.hasAttribute('data-skip-lazy'))return false;
if(!parse(val))return false;
el.setAttribute('data-xspeed-held',String(val));
return true;
}
try{
var IP=window.HTMLIFrameElement&&HTMLIFrameElement.prototype;
var SD=IP&&Object.getOwnPropertyDescriptor(IP,'src');
if(SD&&SD.set){
Object.defineProperty(IP,'src',{configurable:true,enumerable:SD.enumerable,
get:function(){return SD.get.call(this);},
set:function(v){if(hold(this,v))return;return SD.set.call(this,v);}});
}
var SA=Element.prototype.setAttribute;
Element.prototype.setAttribute=function(n,v){
if(n==='src'&&hold(this,v))return;
return SA.call(this,n,v);
};
}catch(e){}
function dress(f){
if(!f.parentNode)return;
var s=f.getAttribute('data-xspeed-held');
var e=parse(s);
if(!e)return;
var u=s+(s.indexOf('autoplay=')>-1?'':(s.indexOf('?')>-1?'&':'?')+'autoplay=1');
var b=document.createElement('button');
b.type='button';
b.className='xspeed-video-facade';
b.setAttribute('data-xspeed-video',u);
var t=f.getAttribute('title');
b.setAttribute('aria-label',t?L+': '+t:L);
var st='position:relative;display:block;width:100%;padding:0;border:0;cursor:pointer;background:#000;aspect-ratio:16/9;';
if(e.p==='youtube'&&!/^(videoseries|live_stream)$/i.test(e.id))
st+='background-image:url(https://i.ytimg.com/vi/'+encodeURIComponent(e.id)+'/hqdefault.jpg);background-size:cover;background-position:center;';
b.setAttribute('style',st);
b.innerHTML='<span class="xspeed-video-facade__play" aria-hidden="true" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:68px;height:48px;border-radius:14px;background:rgba(0,0,0,.7);display:flex;align-items:center;justify-content:center;"><svg width="24" height="24" viewBox="0 0 24 24" fill="#fff" focusable="false"><path d="M8 5v14l11-7z"/></svg></span>';
f.parentNode.replaceChild(b,f);
}
function sweep(root){
if(!root||!root.querySelectorAll)return;
if(root.tagName==='IFRAME'&&root.getAttribute('data-xspeed-held')){dress(root);return;}
var h=root.querySelectorAll('iframe[data-xspeed-held]');
for(var i=0;i<h.length;i++)dress(h[i]);
// An embed written via innerHTML never passed through the wrapped
// setters — its src is live, but the parser has only just created it,
// so replacing it here still cancels the load before the player runs.
if(root.tagName==='IFRAME'&&!root.getAttribute('data-xspeed-loaded')&&!root.hasAttribute('data-skip-lazy')&&parse(root.getAttribute('src'))){
root.setAttribute('data-xspeed-held',root.getAttribute('src'));dress(root);return;
}
var f=root.querySelectorAll('iframe[src]:not([data-xspeed-loaded]):not([data-skip-lazy])');
for(var j=0;j<f.length;j++){
if(parse(f[j].getAttribute('src'))){f[j].setAttribute('data-xspeed-held',f[j].getAttribute('src'));dress(f[j]);}
}
}
try{
new MutationObserver(function(ms){
for(var i=0;i<ms.length;i++)for(var j=0;j<ms[i].addedNodes.length;j++)sweep(ms[i].addedNodes[j]);
}).observe(document.documentElement,{childList:true,subtree:true});
}catch(e){}
if(document.readyState!=='loading')sweep(document);
else document.addEventListener('DOMContentLoaded',function(){sweep(document);});
})();
JS;
	}
}
