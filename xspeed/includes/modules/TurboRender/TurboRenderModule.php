<?php
/**
 * Turbo Render — stamp below-fold page sections with
 * `content-visibility: auto` so the browser skips their style & layout
 * work until scroll approaches.
 *
 * Why this exists: on large-DOM builder pages the dominant share of TBT
 * is Style & Layout, not script execution — measured live on a
 * 2,003-element Elementor homepage: 661ms Style & Layout, biggest long
 * task 543ms attributed to the document itself. JS delay/defer cannot
 * touch that cost; `content-visibility` is the only browser primitive
 * that skips rendering work for off-screen subtrees. Measured effect on
 * that page: TBT 635ms -> 22-63ms across five runs.
 *
 * How: a buffer pass over the final HTML finds top-level section
 * containers by class (Elementor, Divi, Bricks, Oxygen, Beaver Builder),
 * and when no class matches falls back to the direct children of <main>
 * — so any theme or builder gets the treatment. It leaves the first N
 * alone (the above-fold estimate) and stamps the rest with a
 * `data-xspeed-turbo` attribute. One
 * inline <style> gives every stamped section
 * `content-visibility: auto` + `contain-intrinsic-size: auto <est>` —
 * the `auto` keyword remembers the real rendered size after first
 * paint, so the estimate only matters before a section has ever been
 * rendered, and a print stylesheet forces everything visible.
 *
 * Tier: Free (FEATURES.md Core Web Vitals #4 — the heuristic tier; the
 * fold-beacon-measured variant is the Pro half of that row).
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\TurboRender;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;
use XSpeed\Modules\ResourceHints\ResourceHintsModule;

final class TurboRenderModule extends Module {

	public const SLUG    = 'turbo-render';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.1.0';

	/**
	 * Top-level section containers, by class, across the major builders.
	 * Deliberately the TOP-LEVEL spellings only — e.g. Elementor's
	 * `elementor-top-section` / `e-parent`, never `elementor-section` /
	 * `e-con`, which also match nested wrappers and would stamp inside
	 * the sections we skip as above-fold. WPBakery is absent for the same
	 * reason: its inner rows carry `vc_row` too, so there is no nesting-safe
	 * spelling (users can still add it per site).
	 */
	private const DEFAULT_CLASSES = array(
		'elementor-top-section', // Elementor legacy sections.
		'e-parent',              // Elementor flexbox containers.
		'et_pb_section',         // Divi.
		'brxe-section',          // Bricks.
		'ct-section',            // Oxygen.
		'fl-row',                // Beaver Builder.
	);

	/**
	 * On for every site that never saved the switch: a fresh install and an
	 * upgrade from an off-by-default version alike. Activation with the
	 * installer marker, or beside another page cache, saves it off on purpose
	 * (Settings::apply_conflict_safe_profile()). A saved off stays off.
	 */
	private const DEFAULT_ENABLED = true;

	/**
	 * Sections presumed above the fold and left untouched. Three, not two:
	 * the only other automatic implementation (WP Rocket's lazy render)
	 * refuses anything within 1800px of the viewport bottom, and until the
	 * fold is measured a section count should err the same way. Only a
	 * fresh install sees the change — a stored value is kept as is.
	 */
	private const DEFAULT_SKIP_FIRST = 3;

	/**
	 * Pre-first-render size estimate (px) for contain-intrinsic-size.
	 * Only the scrollbar sees it, and only until a section has rendered
	 * once — `contain-intrinsic-size: auto` then remembers the real size.
	 */
	private const DEFAULT_INTRINSIC_PX = 800;

	/**
	 * Spans whose markup is text, not the page: a section tag inside a
	 * JS template string or a comment must not be stamped.
	 */
	/**
	 * Background images inside a deferred section stay off until the
	 * section is near the viewport. Scoped to `.xs-tbg`, which only the
	 * script below sets: without JavaScript, or without
	 * IntersectionObserver, nothing is held. Screen only, so a printed page
	 * keeps every background.
	 */
	private const HOLD_STYLE = '<style id="xspeed-turbo-hold">@media screen{.xs-tbg [data-xspeed-turbo]:not([data-xspeed-near]),.xs-tbg [data-xspeed-turbo]:not([data-xspeed-near]) *{background-image:none!important}}</style>';

	/**
	 * Releases a section's backgrounds 1000px before it scrolls into view.
	 * data-xs-nodelay keeps Delay JS from holding it until the first
	 * interaction, which would leave every deferred background blank.
	 */
	private const HOLD_SCRIPT = '<script id="xspeed-turbo-hold-js" data-xs-nodelay>(function(d){if(!("IntersectionObserver" in window))return;d.documentElement.classList.add("xs-tbg");var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){e.target.setAttribute("data-xspeed-near","");io.unobserve(e.target);}});},{rootMargin:"1000px 0px"});function go(){d.querySelectorAll("[data-xspeed-turbo]").forEach(function(s){io.observe(s);});}if(d.readyState!=="loading")go();else d.addEventListener("DOMContentLoaded",go);})(document);</script>';

	/**
	 * Lands a same-page link on its target.
	 *
	 * Whoever works out where a `#pricing` link goes (the browser for a CSS
	 * smooth scroll, as Elementor's frontend CSS sets it, or a page script)
	 * does it at the click, while the deferred sections above the target still
	 * have the 800px estimate. They render at their real height on the way and
	 * the target lands hundreds or thousands of pixels off (QA on #841: -855px
	 * with short sections, +4,600px with tall ones on Elementor 4.3.5).
	 *
	 * So a capture-phase click listener on window, which runs before the
	 * browser's jump and before any page script's listener, renders just the
	 * sections between the screen and the target first. The scroll itself is
	 * left alone, smooth or not, so the site keeps its own scroll behaviour.
	 * Once it has ended, a ResizeObserver puts the
	 * target back if a late image moves it, until no section has resized for
	 * 1.5s (6s at most); then the sections go back to deferred, and
	 * contain-intrinsic-size: auto keeps their rendered size. A page opened at
	 * #target, or a hash change, is held the same way. The visitor's own wheel,
	 * touch, pointer or scroll key ends the watch, and every correction is
	 * instant. Without ResizeObserver, or when the target is missing or
	 * detached, it does nothing. The readable source and the measurements are
	 * in docs/research/turbo-render-jump-landing.md.
	 */
	private const JUMP_SCRIPT = '<script id="xspeed-turbo-jump" data-xs-nodelay>(function(e,t){if(!("ResizeObserver"in e)||!t.querySelectorAll)return;var n=null,r=e.scrollTo,i=Element.prototype.scrollIntoView;function a(e){if(!e||e.length<2)return null;try{e=decodeURIComponent(e.slice(1))}catch(e){return null}return t.getElementById(e)||t.getElementsByName(e)[0]||null}function o(e){return e.getBoundingClientRect().top}function s(e,t){return parseFloat(getComputedStyle(e)[t])||0}function c(e){return s(e,"scrollMarginTop")+s(t.documentElement,"scrollPaddingTop")}function l(n){return e.pageYOffset>=t.documentElement.scrollHeight-e.innerHeight-1?o(n):c(n)}function u(){var t=n;if(t){n=null,clearTimeout(t.t),clearTimeout(t.cap),t.f&&cancelAnimationFrame(t.f),t.ro&&t.ro.disconnect(),t.rb&&t.rb.disconnect(),e.removeEventListener("scroll",t.s),e.removeEventListener("scroll",t.g),t.done&&t.done();var r=t.open.map(function(e){var t=getComputedStyle(e[0]);return e[0].getBoundingClientRect().height-s(e[0],"paddingTop")-s(e[0],"paddingBottom")-(parseFloat(t.borderTopWidth)||0)-(parseFloat(t.borderBottomWidth)||0)});t.open.forEach(function(e,t){r[t]>0&&(e[0].style.containIntrinsicHeight="auto "+r[t]+"px"),e[0].style.contentVisibility=e[1],e[0].style.contain=e[2]})}}function d(i,a,s){u();var c=n={open:[]},l=t.querySelectorAll("[data-xspeed-turbo]"),d=o(i),f=Math.min(0,d),p=[];l.forEach(function(e){p.push(e.getBoundingClientRect())}),l.forEach(function(e,t){p[t].top<d&&p[t].bottom>f&&(c.open.push([e,e.style.contentVisibility,e.style.contain]),e.style.contentVisibility="visible",e.style.contain="layout paint")}),c.y=o(i)+e.pageYOffset;function m(){clearTimeout(c.t),t.readyState==="complete"&&(c.t=setTimeout(u,1500))}function h(t){n===c&&(t&&(c.k=1),!c.f&&(c.f=requestAnimationFrame(function(){if(c.f=0,n===c){if(!i.isConnected||!i.getClientRects().length)return u();var t=o(i)-c.off;if(t>2||t<-2){var a=e.pageYOffset+t;try{r.call(e,{top:a,behavior:"instant"})}catch(t){r.call(e,0,a)}}c.py=e.pageYOffset,c.k&&(c.k=0,m())}})))}return c.hold=function(){if(!(n!==c||c.ro)){e.removeEventListener("scroll",c.s);var r=a(c);if(!(r>-3&&r<e.innerHeight))return u();c.off=r,c.ro=new ResizeObserver(function(){h(1)}),l.forEach(function(e){c.ro.observe(e)}),t.body&&(c.rb=new ResizeObserver(function(){h(0)})).observe(t.body),c.py=e.pageYOffset,c.g=function(){if(!(n!==c||c.f)){var r=o(i)-c.off,a=e.pageYOffset-c.py;if((r>2||r<-2)&&(a>1||a<-1))return!c.x&&t.readyState!=="complete"?h(0):u();c.py=e.pageYOffset}},e.addEventListener("scroll",c.g,{passive:!0}),c.q=m}},c.s=function(){c.m=1,clearTimeout(c.t),c.t=setTimeout(c.hold,150)},e.addEventListener("scroll",c.s,{passive:!0}),c.t=setTimeout(c.hold,1e3),c.cap=setTimeout(u,s),c}function f(n,r){if(n.button||n.ctrlKey||n.metaKey||n.shiftKey||n.altKey||r.hasAttribute("download"))return!1;var i=(r.hasAttribute("target")?r:t.querySelector("base[target]")||r).target.toLowerCase();return!i||i==="_self"||(i==="_top"||i==="_parent")&&e===e.top}e.addEventListener("click",function(t){var n=t.target&&t.target.closest&&t.target.closest("a[href*=\'#\']");if(n&&typeof n.href=="string"&&n.href.split("#")[0]===location.href.split("#")[0]&&f(t,n)){var r=a(n.hash);r&&d(r,function(n){if(!t.defaultPrevented)return l(r);var i=n.y-e.pageYOffset;return Math.abs(o(r)-c(r))<=2||n.m&&!(i>-3&&i<e.innerHeight)?c(r):i},6e3)}},!0),e.addEventListener("hashchange",function(){if(!n||n.ro){var e=a(location.hash);e&&d(e,function(){return l(e)},6e3)}});function p(e){(e.type!=="keydown"||/^(Arrow(Up|Down)|Page(Up|Down)|Home|End| |Spacebar)$/.test(e.key))&&u()}["wheel","touchstart","pointerdown","keydown"].forEach(function(t){e.addEventListener(t,p,{passive:!0,capture:!0})}),Element.prototype.scrollIntoView=function(){var t=this,r;return m(),(!n||n.ro)&&t.isConnected&&t.getClientRects().length&&(r=o(t),(r<0||r>=e.innerHeight)&&d(t,function(){return NaN},6e3)),i.apply(t,arguments)};function m(){n&&(n.x=1)}["scrollTo","scroll","scrollBy"].forEach(function(t){var n=e[t];typeof n=="function"&&(e[t]=function(){return m(),n.apply(this,arguments)})});var h=Object.getOwnPropertyDescriptor(Element.prototype,"scrollTop");h&&h.set&&h.configurable&&Object.defineProperty(Element.prototype,"scrollTop",{configurable:!0,enumerable:h.enumerable,get:h.get,set:function(e){(this===t.scrollingElement||this===t.documentElement||this===t.body)&&m(),h.set.call(this,e)}});function g(e,t){return{get isConnected(){return e.isConnected},getClientRects:function(){return e.getClientRects()},getBoundingClientRect:function(){return{top:e.getBoundingClientRect().top+t}}}}var _=e.performance&&performance.getEntriesByType&&performance.getEntriesByType("navigation")[0];if(location.hash&&!(_&&_.type!=="navigate")){var v=function(){var t=a(location.hash);if(t){var r=d(t,function(){return c(t)},15e3);r.hold(),e.addEventListener("load",function(){n===r&&r.q()})}};t.readyState==="loading"?t.addEventListener("DOMContentLoaded",v):v()}function y(t){try{r.call(e,{top:t,behavior:"instant"})}catch(n){r.call(e,0,t)}}function b(){var t=e.navigation,n=history.state;return t&&t.currentEntry&&t.currentEntry.key?t.currentEntry.key:n!==null&&(typeof n!="object"||Array.isArray(n))?null:((!n||!n.xspeedTurbo)&&(n=Object.assign({},n,{xspeedTurbo:Math.random().toString(36).slice(2)}),history.replaceState(n,"")),n.xspeedTurbo)}function x(){return location.pathname+location.search}var S=null,C=null,w=0;function T(){w&&(w=0,history.scrollRestoration="auto")}try{S=e.sessionStorage,C=b()}catch(e){S=null}if(S&&C&&"scrollRestoration"in history){var E=null;if(e.addEventListener("pagehide",function(){var n=t.querySelectorAll("[data-xspeed-turbo]"),r={u:x(),y:e.pageYOffset,n:n.length,i:-1,o:0},i,a;for(T(),i=0;i<n.length;i++)if(a=n[i].getBoundingClientRect(),a.bottom>0||i===n.length-1){r.i=i,r.o=a.top;break}try{(i=b())&&S.setItem("xspeed-turbo-pos:"+i,JSON.stringify(r))}catch(e){}}),_&&(_.type==="reload"||_.type==="back_forward")){try{E=JSON.parse(S.getItem("xspeed-turbo-pos:"+C))}catch(e){}if(E&&typeof E.y=="number"&&E.u===x()&&history.scrollRestoration==="auto"){history.scrollRestoration="manual",w=1;var D=function(){var r=t.querySelectorAll("[data-xspeed-turbo]"),i=E.n===r.length&&E.i>=0?r[E.i]:null,a,s;if(!i){t.readyState==="complete"?(y(E.y),T()):e.addEventListener("load",function(){y(E.y),T()});return}a=g(i,-E.o),s=d(a,function(){return e.pageYOffset>=t.documentElement.scrollHeight-e.innerHeight-1?o(a):0},15e3),s.done=T,y(o(a)+e.pageYOffset),s.hold(),e.addEventListener("load",function(){n===s&&s.q()})};t.readyState==="loading"?t.addEventListener("DOMContentLoaded",D):D()}}}})(window,document);</script>';

	/** Fallback: a child spanning less bytes than this is decoration (an empty notices div, a spacer), not a section. */
	private const FALLBACK_MIN_SPAN = 150;

	/** Fallback: a child holding this share of its siblings' combined bytes is a wrapper to unwrap, not a section. */
	private const FALLBACK_DOMINANT = 0.6;

	/**
	 * Put on each candidate's open tag by capture() and removed by
	 * process() before anything else reads the page, so the stamping pass
	 * can tell that it picked the same elements capture() hashed. Never
	 * shipped.
	 */
	private const CAND_ATTR = 'data-xspeed-cand';

	/** Our own output-buffer nesting level, when we had to open one. */
	private ?int $buffer_level = null;

	/**
	 * Sigs from the last capture(), by ordinal. process() takes them once,
	 * so a capture never answers for a later page.
	 *
	 * @var string[]|null
	 */
	private ?array $captured = null;

	public function ui_metadata(): array {
		return array(
			'label'       => __( 'Turbo Render', 'xspeed' ),
			'icon'        => 'Layers',
			'description' => __( 'Shows the top of each page first and the rest as visitors scroll.', 'xspeed' ),
			'group'       => 'performance',
		);
	}

	/**
	 * The switch for a site that never saved it: on, unless a plugin that
	 * defers sections itself is active (Conflict_Registry, `turbo-render`).
	 * Read on every request and never written, so it follows that plugin
	 * being switched on and off.
	 */
	private static function default_enabled(): bool {
		return self::DEFAULT_ENABLED
			&& \XSpeed\Conflict_Registry::STRATEGY_ALLOW === \XSpeed\Conflict_Registry::strategy_for( self::SLUG );
	}

	/**
	 * Whether the site runs Turbo Render only because of the default: it never
	 * saved the switch, and no conflicting plugin turns the default off.
	 * Plugin::maybe_upgrade() purges once on the update that turned the default
	 * on, because the pages cached before it carry no Turbo Render, and with
	 * "Clear cache after updates" off nothing else would rebuild them.
	 */
	public static function on_by_default(): bool {
		$row = get_option( \XSpeed\Settings_Manager::OPTION_PREFIX . self::SLUG, array() );
		return ! ( is_array( $row ) && array_key_exists( 'enabled', $row ) ) && self::default_enabled();
	}

	/**
	 * Say why the switch is off on a site that never saved it. The generic
	 * conflict notice says the other plugin overlaps, not that it is the reason
	 * the default stood down (QA on 1.4.9).
	 *
	 * @return array<int,array<string,string>>
	 */
	public function ui_notices(): array {
		$row = get_option( \XSpeed\Settings_Manager::OPTION_PREFIX . self::SLUG, array() );
		if ( ( is_array( $row ) && array_key_exists( 'enabled', $row ) ) || self::default_enabled() ) {
			return array();
		}
		$labels = array();
		foreach ( \XSpeed\Conflict_Registry::detected() as $conflict ) {
			if ( \XSpeed\Conflict_Registry::STRATEGY_ALLOW !== ( $conflict['strategy'][ self::SLUG ] ?? \XSpeed\Conflict_Registry::STRATEGY_ALLOW ) ) {
				$labels[] = (string) $conflict['label'];
			}
		}
		if ( array() === $labels ) {
			return array();
		}
		$names = implode( ', ', $labels );
		return array(
			array(
				'tone'  => 'info',
				/* translators: %s: name of the plugin that turned the default off, such as WP Rocket. */
				'title' => sprintf( __( 'Off by default because %s is active', 'xspeed' ), $names ),
				/* translators: %s: name of the plugin that turned the default off, such as WP Rocket. */
				'body'  => sprintf( __( '%s already renders sections lazily as visitors scroll, so Turbo Render stays off unless you switch it on.', 'xspeed' ), $names ),
			),
		);
	}

	/**
	 * 1.1.0 turned the `enabled` default on. The migration writes nothing on
	 * purpose: the new default reaches a site only while its row has no
	 * `enabled` key. Writing the key would freeze whatever it held, and a
	 * saved value, on or off, is the admin's choice and stays. A site with
	 * no row is not migrated at all (Settings_Manager::run_migrations()), so
	 * it keeps no row.
	 */
	public function migrations(): array {
		return array(
			'1.1.0' => static fn( array $opts ): array => $opts,
		);
	}

	public function settings_schema(): array {
		return array(
			'enabled' => array(
				'type'        => 'bool',
				'default'     => self::default_enabled(),
				'label'       => __( 'Render as visitors scroll', 'xspeed' ),
				'description' => __( 'The browser draws what visitors see first and draws lower sections just before they scroll to them. Your content does not change.', 'xspeed' ),
			),
			'skip_first' => array(
				'type'        => 'int',
				'default'     => self::DEFAULT_SKIP_FIRST,
				'min'         => 1,
				'max'         => 10,
				'label'       => __( 'Sections to render immediately', 'xspeed' ),
				'description' => __( 'How many sections at the top are drawn right away. The default of 3 suits most pages; raise it if a section near the top appears late.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'section_classes' => array(
				'type'        => 'list',
				'default'     => self::DEFAULT_CLASSES,
				'label'       => __( 'Section classes', 'xspeed' ),
				'description' => __( 'CSS classes that mark a section. The defaults cover the main page builders, and other themes are detected on their own.', 'xspeed' ),
				'advanced'    => true,
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'excluded_classes' => array(
				'type'        => 'list',
				'default'     => array(),
				'label'       => __( 'Excluded classes', 'xspeed' ),
				'description' => __( 'Sections with any of these classes are always drawn right away. Add one here if part of a section gets cut off where it overlaps the next.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
		);
	}

	public function boot(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		// Deferred to `init`: reading the enabled flag builds
		// settings_schema(), whose labels go through __(), and boot() runs
		// on plugins_loaded — before WP 6.7 considers translation loading
		// safe (same reasoning as BloatModule::boot()).
		add_action( 'init', array( $this, 'boot_on_init' ) );
	}

	/** The real boot body — see boot() for why it runs on `init`. */
	public function boot_on_init(): void {
		if ( ! $this->get_setting( 'enabled', self::DEFAULT_ENABLED ) ) {
			return;
		}
		// After the CSS passes (combiner @5, a Pro CSS pass @6): they rewrite
		// <head>, this pass rewrites body sections — ordering only matters in
		// that our injected <style> must survive, and later passes never
		// strip inline styles.
		add_filter( 'xspeed_cache_final_html', array( $this, 'process' ), 8 );
		// Section identity is read before any pass rewrites the page, so a
		// normal page and the measurement render (which runs fewer passes)
		// hash the same markup.
		add_filter( 'xspeed_cache_final_html', array( $this, 'capture' ), 0 );
		// That filter fires only when the page cache stores a page. With the
		// cache off, or on a URL it excludes, nothing else hands us the HTML,
		// so open our own buffer then (#507), the way Combine CSS does (#195).
		// Combine's buffer opens first and is the outer one, so this pass
		// runs before it there: harmless, since we only add a <style> to
		// <head>, which Combine leaves in place.
		add_action( 'template_redirect', array( $this, 'maybe_buffer' ), 1 );
	}

	/**
	 * Buffer the page ourselves when the page cache will not.
	 *
	 * Stands down on the same requests Combine CSS's buffer does, and when
	 * the page cache is buffering: it will call process() through its filter,
	 * and a second buffer would only copy the page again.
	 */
	public function maybe_buffer(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( function_exists( 'is_feed' ) && is_feed() )
			|| ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() )
			|| \XSpeed\Builder_Editor::is_active() ) {
			return; // process() never stamps for a signed-in visitor either.
		}
		if ( class_exists( '\\XSpeed\\Cache' ) && \XSpeed\Cache::is_buffering() ) {
			return;
		}
		if ( $this->request_opted_out() ) {
			return;
		}
		ob_start( array( $this, 'filter_buffer' ) );
		$this->buffer_level = ob_get_level();
		add_action( 'shutdown', array( $this, 'close_buffer' ), 0 );
	}

	/**
	 * Pages the site has marked as not-for-optimizing, which Turbo Render
	 * leaves alone even with the page cache off.
	 *
	 * With the cache on, a page reached Turbo Render only by being cached, so
	 * the cache's URL exclusions (cart, checkout, my-account by default, plus
	 * whatever the site added) kept it off transactional pages. Its own
	 * buffer must keep that promise, or turning on Turbo Render would start
	 * deferring sections of a checkout. The WooCommerce conditionals cover a
	 * store whose cart or checkout lives at a translated slug the URL list
	 * does not name.
	 */
	public function request_opted_out(): bool {
		foreach ( array( 'is_cart', 'is_checkout', 'is_account_page' ) as $fn ) {
			if ( function_exists( $fn ) && $fn() ) {
				return true;
			}
		}
		if ( ! class_exists( '\\XSpeed\\Settings_Manager' ) || ! class_exists( '\\XSpeed\\Cache' ) ) {
			return false;
		}
		$cache    = \XSpeed\Settings_Manager::get( 'cache' );
		$excluded = is_array( $cache['excluded_urls'] ?? null ) ? $cache['excluded_urls'] : array();
		// The cache's own matcher: it keeps percent-escapes that
		// sanitize_text_field() would delete, so an entry for a non-ASCII
		// slug matches here too.
		return array() !== $excluded && \XSpeed\Cache::request_matches_exclusions( $excluded );
	}

	/** ob_start() callback. */
	public function filter_buffer( string $buffer ): string {
		$out = $this->process( $this->capture( $buffer ) );
		return is_string( $out ) ? $out : $buffer;
	}

	/** Flush only the buffer we opened. */
	public function close_buffer(): void {
		if ( null !== $this->buffer_level && ob_get_level() >= $this->buffer_level ) {
			ob_end_flush();
			$this->buffer_level = null;
		}
	}

	/**
	 * Record each candidate section's sig and mark its open tag, before any
	 * other pass changes the page.
	 *
	 * Runs at `xspeed_cache_final_html` priority 0 on a cacheable page, and
	 * first thing in our own buffer on the cache-off and measurement
	 * requests, which never reach that filter. It looks only at pages
	 * process() would stamp, so no other page ever carries a marker, and
	 * process() removes every marker before it does anything else.
	 *
	 * @param mixed $html Final page buffer.
	 * @return mixed
	 */
	public function capture( $html ) {
		$this->captured = null;
		if ( ! $this->get_setting( 'enabled', false ) || $this->declines( $html, self::measuring() ) ) {
			return $html;
		}
		list( $masked, $offsets ) = $this->candidates( $html );

		$sorted = $offsets;
		sort( $sorted );
		$body_end = stripos( $masked, '</body>' );
		$doc_end  = false === $body_end ? strlen( $masked ) : (int) $body_end;

		$sigs = array();
		foreach ( array_values( $offsets ) as $ordinal => $offset ) {
			$stop             = self::span_stop( $offset, self::element_end( $masked, $offset ), $sorted, $doc_end );
			$sigs[ $ordinal ] = self::sig( substr( $html, $offset, $stop - $offset ) );
		}

		$marks = array_values( $offsets );
		arsort( $marks );
		foreach ( $marks as $ordinal => $offset ) {
			$gap  = (int) strcspn( $html, " \t\r\n/>", $offset + 1 );
			$html = substr_replace( $html, ' ' . self::CAND_ATTR . '="' . $ordinal . '"', $offset + 1 + $gap, 0 );
		}
		$this->captured = $sigs;
		return $html;
	}

	/**
	 * Stamp below-fold top-level sections and inject the one style block.
	 *
	 * @param mixed $html Final page buffer.
	 * @return mixed
	 */
	public function process( $html ) {
		$captured       = $this->captured;
		$this->captured = null;
		if ( ! is_string( $html ) ) {
			return $html;
		}
		// Before any return: a marker left in would ship.
		list( $html, $markers ) = self::strip_markers( $html );
		$measuring              = self::measuring();
		if ( $this->declines( $html, $measuring ) ) {
			return $html;
		}

		list( $masked, $offsets, $skip ) = $this->candidates( $html );
		$bound                           = self::bound( $captured, $offsets, $markers );

		if ( $measuring ) {
			// Sigs that cannot be tied to positions would mislabel sections;
			// with none, whoever measures finds nothing to read.
			return $bound ? self::number_sections( $html, $offsets, $captured ) : $html;
		}

		$excluded = $this->excluded_pattern();

		// Document order and the end of <body>, the fallback span bound for
		// the containment check below.
		$sorted = $offsets;
		sort( $sorted );
		$body_end = stripos( $masked, '</body>' );
		$doc_end  = false === $body_end ? strlen( $masked ) : (int) $body_end;

		// Keyed by the section's ordinal: its position in $offsets, the
		// number the measurement render gives it.
		$edits = array();
		foreach ( array_values( $offsets ) as $ordinal => $offset ) {
			if ( $ordinal < $skip ) {
				continue;
			}
			$end = self::tag_end( $masked, $offset );
			if ( null === $end ) {
				continue; // Unterminated tag at EOF — never stamp it.
			}
			$open_tag = substr( $html, $offset, $end - $offset );
			if ( false !== stripos( $open_tag, 'data-xspeed-turbo' ) ) {
				continue;
			}
			if ( '' !== $excluded && preg_match( $excluded, $open_tag ) ) {
				continue; // Opted out — an overlap design the stamp would clip.
			}
			// `content-visibility` implies paint containment: it clips
			// content that overhangs the section, and skipped iframes can
			// blank or reload when the section re-renders. An embed holder
			// (map, video) is never worth deferring — skip it. (Found live:
			// a Kadence maps container painted over the card overlapping it.)
			$span_end = self::element_end( $masked, $offset );
			if ( null !== $span_end && false !== stripos( substr( $masked, $offset, $span_end - $offset ), '<iframe' ) ) {
				continue;
			}
			if ( self::breaks_under_containment( $masked, $offset, $span_end, $sorted, $doc_end ) ) {
				continue;
			}
			$edits[ $ordinal ] = $offset;
		}

		if ( empty( $edits ) ) {
			return $html;
		}

		$sizes = $bound ? $this->measured_sizes( $offsets, $edits, $captured ) : array();

		// Highest offset first, so earlier offsets stay valid as we splice.
		// The attribute goes right after the tag name — the one spot
		// guaranteed not to sit inside another attribute's value. A section
		// with a measured size carries its ordinal for the size rule to
		// select; the rest keep the empty value they always had.
		arsort( $edits );
		foreach ( $edits as $ordinal => $offset ) {
			$gap  = (int) strcspn( $html, " \t\r\n/>", $offset + 1 );
			$mark = isset( $sizes[ $ordinal ] ) ? (string) $ordinal : '';
			$html = substr_replace( $html, ' data-xspeed-turbo="' . $mark . '"', $offset + 1 + $gap, 0 );
		}

		/**
		 * Filter the pre-first-render intrinsic size estimate, in pixels.
		 *
		 * @param int $px
		 */
		$px    = max( 100, (int) apply_filters( 'xspeed_turbo_render_intrinsic_px', self::DEFAULT_INTRINSIC_PX ) );
		$style = '<style id="xspeed-turbo">[data-xspeed-turbo]{content-visibility:auto;contain-intrinsic-size:auto ' . $px . 'px}@media print{[data-xspeed-turbo]{content-visibility:visible}}' . self::size_rules( $sizes ) . '</style>';

		/**
		 * Filter whether a deferred section's images and CSS backgrounds wait
		 * until the visitor scrolls near it.
		 *
		 * content-visibility skips a section's rendering, not its downloads:
		 * the browser still fetched every background a stylesheet gave it,
		 * and eager images in it. On a live Kadence page a 117 KB row
		 * background 3,600px down loaded before the hero heading painted,
		 * and PageSpeed counts every byte that lands before LCP.
		 *
		 * @param bool $hold Default true.
		 */
		if ( apply_filters( 'xspeed_turbo_render_hold_media', true ) ) {
			$html   = self::lazy_section_images( $html );
			$style .= self::HOLD_STYLE . self::HOLD_SCRIPT;
		}
		$style .= self::JUMP_SCRIPT;

		$head_end = stripos( $html, '</head>' );
		return substr_replace( $html, $style, (int) $head_end, 0 );
	}

	/**
	 * Whether this is the measurement render (`?xspeed_css=off`, the render
	 * a CSS generator reads), which must see every section RENDERED.
	 * Shipped without this guard, a renderer measured a page whose
	 * below-fold sections the browser had skipped, judged their CSS unused,
	 * and pruned it: mobile CLS went 0.00 to 0.37 because the fold's own
	 * sizing rules were gone. The param is a shared contract (a Pro CSS
	 * module defines it), matched here by name because Free never
	 * references Pro code.
	 */
	private static function measuring(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only bypass detection; changes nothing.
		return isset( $_GET['xspeed_css'] ) && 'off' === sanitize_text_field( wp_unslash( $_GET['xspeed_css'] ) );
	}

	/**
	 * Whether the stamping pass leaves this page alone before it looks for
	 * sections. capture() asks the same question, so a page that process()
	 * will not stamp never gets a marker.
	 *
	 * @param mixed $html      Final page buffer.
	 * @param bool  $measuring From measuring().
	 */
	private function declines( $html, bool $measuring ): bool {
		if ( ! is_string( $html ) || '' === $html ) {
			return true;
		}
		if ( false === stripos( $html, '</head>' ) ) {
			return true; // Not a full document (fragment, feed, JSON).
		}
		// Already processed: our buffer and the cache filter can both see a
		// page, and stamping twice would add a second style block.
		if ( false !== strpos( $html, '<style id="xspeed-turbo">' ) ) {
			return true;
		}
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return true;
		}
		if ( ! $measuring ) {
			return false;
		}
		/**
		 * Filter whether the measurement render numbers the sections.
		 *
		 * On, every section Turbo Render would consider (the above-fold
		 * ones and the excluded ones too) carries
		 * `data-xspeed-section="N"`, its 0-based position in document
		 * order, and `data-xspeed-sig`, the section's identity (see
		 * `xspeed_turbo_render_sections`). Nothing else changes: no
		 * content-visibility, so the page renders in full. Whoever measures
		 * the page reads each section's height by that number and can hand
		 * it back through `xspeed_turbo_render_sections`.
		 *
		 * @param bool $mark Default false.
		 */
		return ! apply_filters( 'xspeed_turbo_render_mark_sections', false )
			|| false !== strpos( $html, 'data-xspeed-section="' );
	}

	/**
	 * The candidate sections, in the order their ordinals count them.
	 *
	 * @param string $html Final page buffer, without capture markers.
	 * @return array{0:string, 1:int[], 2:int} The masked copy, the candidates' offsets, the above-fold allowance.
	 */
	private function candidates( string $html ): array {
		$classes = $this->section_classes();

		/**
		 * Filter how many top-level sections stay untouched as above-fold.
		 *
		 * @param int $skip_first
		 */
		$skip = max( 1, (int) apply_filters( 'xspeed_turbo_render_after', (int) $this->get_setting( 'skip_first', self::DEFAULT_SKIP_FIRST ) ) );

		$masked  = self::mask( $html );
		$offsets = array();
		$pattern = '#<(?:section|div|footer|main|article)\b[^>]*\bclass\s*=\s*(["\'])[^"\']*(?:' . implode( '|', array_map( 'preg_quote', $classes ) ) . ')[^"\']*\1[^>]*>#i';
		if ( ! empty( $classes ) && preg_match_all( $pattern, $masked, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$offsets[] = (int) $hit[1];
			}
		}

		/**
		 * Filter whether the structural fallback runs when no known section
		 * class matches: the direct children of <main> are treated as the
		 * page's sections, which is what makes Turbo Render work on block
		 * themes, classic themes, and builders not in the class list.
		 *
		 * @param bool $enabled
		 */
		if ( empty( $offsets ) && apply_filters( 'xspeed_turbo_render_fallback', true ) ) {
			$offsets = self::main_children( $masked, $skip );
		}
		return array( $masked, array_values( $offsets ), $skip );
	}

	/**
	 * Remove every capture marker, wherever a pass has moved or re-quoted it
	 * inside its tag. Only attributes of open tags are markers: the same text
	 * in a script, a comment, a quoted value or the page's text is content,
	 * and removing it would change what the visitor reads.
	 *
	 * @param string $html Final page buffer.
	 * @return array{0:string, 1:array<int, int>} The page without markers, and
	 *         the offset in it of each marked open tag's `<` => its ordinal.
	 */
	private static function strip_markers( string $html ): array {
		$masked = false === stripos( $html, self::CAND_ATTR ) ? '' : self::mask( $html );
		if ( '' === $masked || ! preg_match_all( '#<([a-zA-Z][a-zA-Z0-9-]*)(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>#', $masked, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array( $html, array() );
		}
		$out     = '';
		$from    = 0;
		$markers = array();
		foreach ( $tags as $tag ) {
			if ( false === stripos( (string) $tag[0][0], self::CAND_ATTR ) ) {
				continue;
			}
			$lt    = (int) $tag[0][1];
			$pos   = $lt + 1 + strlen( (string) $tag[1][0] );
			$first = true;
			$open  = strlen( $out ) + $lt - $from;
			while ( preg_match( '#\G([\s/]*)([^\s"\'>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']*)))?#', $masked, $attr, PREG_OFFSET_CAPTURE, $pos ) ) {
				$pos = (int) $attr[0][1] + strlen( (string) $attr[0][0] );
				if ( 0 !== strcasecmp( (string) $attr[2][0], self::CAND_ATTR ) ) {
					$first = false;
					continue;
				}
				$cut   = (int) $attr[2][1] - ( strlen( (string) $attr[1][0] ) - strlen( rtrim( (string) $attr[1][0] ) ) );
				$out  .= substr( $html, $from, $cut - $from );
				$from  = $pos;
				$value = '';
				for ( $g = 3; $g <= 5; $g++ ) {
					if ( isset( $attr[ $g ] ) && -1 !== $attr[ $g ][1] ) {
						$value = (string) $attr[ $g ][0];
					}
				}
				// Bound only where capture() put it: right after the tag name.
				if ( $first && ctype_digit( $value ) ) {
					$markers[ $open ] = (int) $value;
				}
			}
		}
		return array( $out . substr( $html, $from ), $markers );
	}

	/**
	 * Whether the stamping pass picked exactly the elements capture()
	 * hashed: the same number, each carrying its own ordinal. A pass between
	 * the two can change which element the fallback picks without changing
	 * the count, so the count alone proves nothing.
	 *
	 * @param string[]|null   $captured Sigs from capture().
	 * @param int[]           $offsets  The stamping pass's candidates.
	 * @param array<int, int> $markers  From strip_markers().
	 */
	private static function bound( ?array $captured, array $offsets, array $markers ): bool {
		if ( null === $captured || count( $captured ) !== count( $offsets ) ) {
			return false;
		}
		foreach ( $offsets as $ordinal => $offset ) {
			if ( ( $markers[ $offset ] ?? null ) !== $ordinal ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A section's identity: the first 16 hex characters of sha1 over its
	 * markup, open tag to close tag. Only two things are taken out first,
	 * because they differ between renders of one page and never change its
	 * layout: xSpeed's own `data-xspeed-*` attributes, and `nonce` values.
	 * Everything else stays in, so a swapped image, changed inline CSS or
	 * changed `<pre>` text gives a new sig and an old height is not reused.
	 *
	 * @param string $span The section's markup.
	 */
	private static function sig( string $span ): string {
		$normalized = (string) preg_replace_callback(
			'#<[a-zA-Z][a-zA-Z0-9-]*\K(?:"[^"]*"|\'[^\']*\'|[^>"\'])*#',
			static fn( array $tag ): string => (string) preg_replace_callback(
				'#(\s+)([^\s=/>"\']+)(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>"\']*))?#',
				static function ( array $attr ): string {
					$name = strtolower( $attr[2] );
					if ( 0 === strpos( $name, 'data-xspeed-' ) ) {
						return '';
					}
					return 'nonce' === $name ? $attr[1] . 'nonce=""' : $attr[0];
				},
				$tag[0]
			),
			$span
		);
		return substr( sha1( $normalized ), 0, 16 );
	}

	/**
	 * Where a candidate's span ends: its close tag, or, when element_end()
	 * can't pair it, the next candidate (or `</body>` for the last one).
	 *
	 * @param int      $offset   The candidate's `<`.
	 * @param int|null $span_end element_end() for this candidate.
	 * @param int[]    $sorted   Every candidate offset, ascending.
	 * @param int      $doc_end  Offset of `</body>`, or the buffer length.
	 */
	private static function span_stop( int $offset, ?int $span_end, array $sorted, int $doc_end ): int {
		if ( null !== $span_end ) {
			return $span_end;
		}
		foreach ( $sorted as $next ) {
			if ( $next > $offset ) {
				return $next;
			}
		}
		return max( $offset, $doc_end );
	}

	/**
	 * The measurement render: every candidate section gets its ordinal as
	 * `data-xspeed-section` and its sig as `data-xspeed-sig`, and nothing
	 * else changes.
	 *
	 * @param string   $html    Final page buffer.
	 * @param int[]    $offsets Candidate offsets, in the order process() counts them.
	 * @param string[] $sigs    From capture(), by ordinal.
	 */
	private static function number_sections( string $html, array $offsets, array $sigs ): string {
		arsort( $offsets );
		foreach ( $offsets as $ordinal => $offset ) {
			$gap  = (int) strcspn( $html, " \t\r\n/>", $offset + 1 );
			$html = substr_replace( $html, ' data-xspeed-section="' . $ordinal . '" data-xspeed-sig="' . $sigs[ $ordinal ] . '"', $offset + 1 + $gap, 0 );
		}
		return $html;
	}

	/**
	 * Ask `xspeed_turbo_render_sections` for measured heights.
	 *
	 * @param int[]           $offsets Every candidate, by ordinal.
	 * @param array<int, int> $edits   Stamped sections, ordinal => offset.
	 * @param string[]        $sigs    From capture(), by ordinal.
	 * @return array<int, array<string, int>> From understood_sizes().
	 */
	private function measured_sizes( array $offsets, array $edits, array $sigs ): array {
		$sections = array();
		foreach ( array_keys( $offsets ) as $ordinal ) {
			$sections[] = array(
				'index'   => $ordinal,
				'stamped' => isset( $edits[ $ordinal ] ),
				'sig'     => $sigs[ $ordinal ],
			);
		}
		/**
		 * Filter the measured height of each deferred section.
		 *
		 * Without an answer every deferred section reserves the same
		 * estimate (`xspeed_turbo_render_intrinsic_px`) until it first
		 * renders, so the scrollbar is wrong and a jump to an anchor lands
		 * in the wrong place. A measured height per section and per screen
		 * fixes that.
		 *
		 * Return an array keyed by a section's `index` (the number the
		 * measurement render gave it, see `xspeed_turbo_render_mark_sections`).
		 * Each value is a height in whole pixels for every screen, or an
		 * array of media query => height, '' meaning every screen. Heights
		 * run from 0 to 50000. A media query is up to 200 characters of
		 * letters, digits, spaces and `( ) : , . -`. Anything else, and any
		 * section not deferred on this page, is ignored.
		 *
		 * @param array  $sizes    Default empty: keep the estimate.
		 * @param array  $sections Every section found, in document order:
		 *                         array( 'index' => int, 'stamped' => bool, 'sig' => string ).
		 *                         Only stamped (deferred) sections use a size.
		 *                         `sig` is the section's identity, the value
		 *                         the measurement render puts in
		 *                         `data-xspeed-sig`: answer only for a
		 *                         section whose measured sig matches.
		 * @param string $page_url The page being served; empty outside a request.
		 */
		return self::understood_sizes(
			apply_filters( 'xspeed_turbo_render_sections', array(), $sections, ResourceHintsModule::current_page_url() ),
			$edits
		);
	}

	/**
	 * The part of an `xspeed_turbo_render_sections` answer that can be used:
	 * stamped sections only, whole-pixel heights in range, media queries that
	 * cannot leave the @media prelude they are printed into.
	 *
	 * @param mixed           $answer The filter's return value.
	 * @param array<int, int> $edits  Stamped sections, ordinal => offset.
	 * @return array<int, array<string, int>> ordinal => media ('' for all) => px.
	 */
	private static function understood_sizes( $answer, array $edits ): array {
		if ( ! is_array( $answer ) ) {
			return array();
		}
		$sizes = array();
		foreach ( $answer as $ordinal => $value ) {
			if ( ! is_int( $ordinal ) || ! isset( $edits[ $ordinal ] ) ) {
				continue;
			}
			$by_media = is_array( $value ) ? $value : array( '' => $value );
			foreach ( $by_media as $media => $px ) {
				$media = (string) $media;
				if ( ! is_int( $px ) || $px < 0 || $px > 50000 ) {
					continue;
				}
				if ( '' !== $media && ( strlen( $media ) > 200 || ! preg_match( '/^[A-Za-z0-9 ():,.\-]+$/', $media ) || ! self::balanced( $media ) ) ) {
					continue;
				}
				$sizes[ $ordinal ][ trim( $media ) ] = $px;
			}
		}
		return $sizes;
	}

	/**
	 * Whether every `(` in a media query closes, in order. An open one
	 * reaches past the @media prelude and the browser drops the rules after
	 * it, other sections' heights included.
	 */
	private static function balanced( string $media ): bool {
		$depth = 0;
		foreach ( str_split( $media ) as $char ) {
			$depth += '(' === $char ? 1 : ( ')' === $char ? -1 : 0 );
			if ( $depth < 0 ) {
				return false;
			}
		}
		return 0 === $depth;
	}

	/**
	 * CSS for measured heights. Height only: the width of a section in the
	 * page flow comes from its container, and the shared estimate rule keeps
	 * supplying the rest. Rules for every screen first, then one @media
	 * block per query in the order the answer first named it.
	 *
	 * @param array<int, array<string, int>> $sizes From understood_sizes().
	 */
	private static function size_rules( array $sizes ): string {
		$groups = array();
		ksort( $sizes );
		foreach ( $sizes as $ordinal => $by_media ) {
			foreach ( $by_media as $media => $px ) {
				$groups[ $media ] = ( $groups[ $media ] ?? '' ) . '[data-xspeed-turbo="' . $ordinal . '"]{contain-intrinsic-height:auto ' . $px . 'px}';
			}
		}
		$css = $groups[''] ?? '';
		unset( $groups[''] );
		foreach ( $groups as $media => $rules ) {
			$css .= '@media ' . $media . '{' . $rules . '}';
		}
		return $css;
	}

	/** @return string[] */
	private function section_classes(): array {
		$classes = $this->get_setting( 'section_classes', self::DEFAULT_CLASSES );
		$classes = is_array( $classes ) ? array_values( array_filter( array_map( 'strval', $classes ) ) ) : self::DEFAULT_CLASSES;

		/**
		 * Filter the class names identifying a top-level page section.
		 *
		 * @param string[] $classes
		 */
		return self::sanitize_classes( (array) apply_filters( 'xspeed_turbo_render_classes', $classes ) );
	}

	/** @return string[] */
	private function excluded_classes(): array {
		$classes = $this->get_setting( 'excluded_classes', array() );
		$classes = is_array( $classes ) ? $classes : array();

		/**
		 * Filter the class names whose sections are never stamped.
		 *
		 * @param string[] $classes
		 */
		return self::sanitize_classes( (array) apply_filters( 'xspeed_turbo_render_excluded_classes', $classes ) );
	}

	/**
	 * The exclusion regex for an open tag, or '' when nothing is excluded.
	 * Token-bounded, unlike the section scan: an exclusion is a user-typed
	 * remedy, and "card" silently matching "cardigan-grid" would make it
	 * look like the setting does nothing.
	 */
	private function excluded_pattern(): string {
		$classes = $this->excluded_classes();
		if ( empty( $classes ) ) {
			return '';
		}
		return '#\bclass\s*=\s*(["\'])[^"\']*(?<![A-Za-z0-9_-])(?:'
			. implode( '|', array_map( 'preg_quote', $classes ) )
			. ')(?![A-Za-z0-9_-])[^"\']*\1#i';
	}

	/**
	 * Class names end up inside a regex alternation; anything that is not a
	 * plausible CSS class token is dropped rather than escaped into
	 * something surprising.
	 *
	 * @param string[] $classes
	 * @return string[]
	 */
	private static function sanitize_classes( array $classes ): array {
		return array_values(
			array_filter(
				array_map( 'strval', $classes ),
				static fn( string $c ): bool => (bool) preg_match( '/^[A-Za-z0-9_-]+$/', $c )
			)
		);
	}

	/**
	 * Structural fallback: the byte offsets of <main>'s section children.
	 *
	 * Themes love wrapper chains — Kadence renders <main> > a hero
	 * <section> + an empty notices <div> + one wrapper <div> holding
	 * everything else four levels deep. Two rules recover the real
	 * sections: drop tiny children (decoration, not layout), and while the
	 * list is too short to stamp anything, unwrap — in place — a child
	 * that dominates its siblings by byte share. Only a <div>/<article>
	 * unwraps, and a dominant FIRST child only when it is the sole child:
	 * wrappers in the wild are divs, but so are many heroes, and opening a
	 * hero would stamp above-fold content. The cap only guards against
	 * pathological markup.
	 *
	 * @param string $masked Markup with script/textarea/noscript/comments nulled.
	 * @param int    $skip   The above-fold allowance in effect.
	 * @return int[]
	 */
	private static function main_children( string $masked, int $skip ): array {
		if ( ! preg_match( '#<main\b[^>]*>#i', $masked, $open, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$children = self::element_children( $masked, (int) $open[0][1] + strlen( (string) $open[0][0] ) );

		for ( $level = 0; $level < 10; $level++ ) {
			$children = array_values(
				array_filter( $children, static fn( array $c ): bool => ( $c['end'] - $c['start'] ) >= self::FALLBACK_MIN_SPAN )
			);
			if ( count( $children ) > $skip || empty( $children ) ) {
				break;
			}
			$total     = array_sum( array_map( static fn( array $c ): int => $c['end'] - $c['start'], $children ) );
			$unwrapped = false;
			foreach ( $children as $i => $child ) {
				if ( ! in_array( $child['tag'], array( 'div', 'article' ), true ) ) {
					continue;
				}
				if ( 0 === $i && count( $children ) > 1 ) {
					continue; // A dominant first child among siblings is a hero, not a wrapper.
				}
				if ( ( $child['end'] - $child['start'] ) < self::FALLBACK_DOMINANT * $total ) {
					continue;
				}
				$end = self::tag_end( $masked, $child['start'] );
				if ( null === $end ) {
					break 2;
				}
				$inner = self::element_children( $masked, $end );
				if ( empty( $inner ) ) {
					break 2;
				}
				array_splice( $children, $i, 1, $inner );
				$unwrapped = true;
				break;
			}
			if ( ! $unwrapped ) {
				break;
			}
		}
		return array_map( static fn( array $c ): int => $c['start'], $children );
	}

	/**
	 * Byte spans of an element's direct section-shaped children.
	 *
	 * A tag walk with an open-tag stack, not a depth counter, so the HTML5
	 * that classic themes actually emit doesn't desync it: implicitly
	 * closed tags (<li>a<li>b, an unclosed <p>) are popped by the matching
	 * ancestor close, a stray close of an implicit tag is ignored, and a
	 * trailing slash on a non-void tag is meaningless (HTML5) so <div/> is
	 * an OPEN div. A close tag for anything not on the stack ends the walk
	 * — normally the container's own close. Only section-shaped tags count
	 * as children: stamping a stray <p> or <h2> would put an 800px
	 * intrinsic-size estimate on a one-line element and wreck the
	 * scrollbar.
	 *
	 * @param string $masked Markup with script/textarea/noscript/comments nulled.
	 * @param int    $cursor Byte offset just past the container's open tag.
	 * @return array<int, array{start:int, end:int, tag:string}>
	 */
	private static function element_children( string $masked, int $cursor ): array {
		static $void     = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );
		static $want     = array( 'div', 'section', 'article', 'footer', 'aside', 'figure', 'table', 'ul', 'ol' );
		static $implicit = array( 'p', 'li', 'dt', 'dd', 'td', 'th', 'tr', 'option', 'optgroup' );

		$children = array();
		$pending  = null;
		$stack    = array();
		while ( preg_match( '#<(/?)([a-zA-Z][a-zA-Z0-9-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>#', $masked, $t, PREG_OFFSET_CAPTURE, $cursor ) ) {
			$offset  = (int) $t[0][1];
			$cursor  = $offset + strlen( (string) $t[0][0] );
			$closing = '' !== $t[1][0];
			$tag     = strtolower( (string) $t[2][0] );
			if ( in_array( $tag, $void, true ) ) {
				continue;
			}
			if ( ! $closing ) {
				if ( in_array( $tag, $implicit, true ) && end( $stack ) === $tag ) {
					array_pop( $stack ); // A sibling <li>/<p>/<td> implicitly closes the previous one.
				}
				if ( empty( $stack ) && in_array( $tag, $want, true ) ) {
					$pending = array(
						'start' => $offset,
						'end'   => $offset,
						'tag'   => $tag,
					);
				}
				$stack[] = $tag;
				continue;
			}
			if ( ! in_array( $tag, $stack, true ) ) {
				if ( in_array( $tag, $implicit, true ) ) {
					continue; // Stray </p>-style close: harmless, skip it.
				}
				break; // The container's own close tag: the walk is done.
			}
			while ( ! empty( $stack ) && array_pop( $stack ) !== $tag ) {
				continue; // Unclosed implicit tags between here and the match.
			}
			if ( empty( $stack ) && null !== $pending ) {
				$pending['end'] = $cursor;
				$children[]     = $pending;
				$pending        = null;
			}
		}
		return $children;
	}

	/**
	 * Whether stamping this section would break something inside it.
	 *
	 * `content-visibility: auto` applies layout, style and paint
	 * containment even while the section is on screen and rendered. Two
	 * kinds of content break under that:
	 *
	 * - an SVG `<use>`: paint containment breaks sprite references (WP
	 *   Rocket skips these too);
	 * - an inline `position: fixed` or `sticky`: the section becomes the
	 *   containing block, so a back-to-top button or a sticky bar is
	 *   positioned against the section instead of the viewport.
	 *
	 * The span runs to the element's close tag. When element_end() can't
	 * pair it, the span runs to the next candidate (or `</body>` for the
	 * last one) instead: overshooting into a sibling only skips more, which
	 * is the safe direction. Read from the masked copy, so text in a script
	 * (`el.style.position = 'fixed'`) never counts. (#478)
	 *
	 * @param string   $masked   Markup with script/textarea/noscript/comments nulled.
	 * @param int      $offset   The candidate's `<`.
	 * @param int|null $span_end element_end() for this candidate.
	 * @param int[]    $sorted   Every candidate offset, ascending.
	 * @param int      $doc_end  Offset of `</body>`, or the buffer length.
	 */
	private static function breaks_under_containment( string $masked, int $offset, ?int $span_end, array $sorted, int $doc_end ): bool {
		$stop = self::span_stop( $offset, $span_end, $sorted, $doc_end );
		$span = substr( $masked, $offset, max( 0, $stop - $offset ) );
		// A <style> element in the body often carries rules for other parts
		// of the page (a sticky header rule printed by a page builder), so it
		// says nothing about this section. Inline style="" attributes do.
		$span = (string) preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $span );

		return (bool) preg_match( '#<use[\s/>]|position\s*:\s*(?:-webkit-)?(?:fixed|sticky)\b#i', $span );
	}

	/**
	 * The offset just past an element's close tag — the same tag walk as
	 * element_children(), seeded with the element's own tag, so implicitly
	 * closed tags don't desync it. Null when the element never closes (or
	 * the markup is too broken to tell), in which case the caller keeps
	 * the old behavior rather than guessing at a span.
	 *
	 * @param string $masked Markup with script/textarea/noscript/comments nulled.
	 * @param int    $offset Byte offset of the element's `<`.
	 * @return int|null
	 */
	private static function element_end( string $masked, int $offset ): ?int {
		return \XSpeed\Html_Spans::element_end( $masked, $offset );
	}

	/**
	 * The offset just past an open tag's `>`, quote-aware — a raw strpos
	 * would stop at a `>` inside an attribute value.
	 *
	 * @param string $masked Markup with script/textarea/noscript/comments nulled.
	 * @param int    $offset Byte offset of the tag's `<`.
	 * @return int|null Null when the tag never terminates.
	 */
	private static function tag_end( string $masked, int $offset ): ?int {
		return \XSpeed\Html_Spans::tag_end( $masked, $offset );
	}

	/**
	 * Make the images inside stamped sections lazy.
	 *
	 * A stamped section is below the fold by Turbo Render's own count, so
	 * loading="eager" there buys nothing: the Lazy module's eager slot had
	 * gone to the first image of a slider 5,600px down. Left alone: an
	 * image marked fetchpriority="high", data-skip-lazy or data-no-lazy,
	 * one matching Lazy's Excluded Images, and any loading value other
	 * than eager.
	 */
	private static function lazy_section_images( string $html ): string {
		$masked = self::mask( $html );
		if ( ! preg_match_all( '#<[a-zA-Z][a-zA-Z0-9-]*\s+data-xspeed-turbo="\d*"#', $masked, $opens, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$spans = array();
		$until = -1;
		foreach ( $opens[0] as $open ) {
			$start = (int) $open[1];
			if ( $start < $until ) {
				continue; // Nested inside a span already taken.
			}
			$end = self::element_end( $masked, $start );
			if ( null === $end ) {
				continue;
			}
			$spans[] = array( $start, $end );
			$until   = $end;
		}
		if ( empty( $spans ) || ! preg_match_all( '#<img\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>#i', $masked, $imgs, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$lazy     = \XSpeed\Settings_Manager::get( 'lazy' );
		$excluded = is_array( $lazy ) && is_array( $lazy['excluded_images'] ?? null ) ? $lazy['excluded_images'] : array();

		$edits = array();
		foreach ( $imgs[0] as $img ) {
			$at = (int) $img[1];
			$in = false;
			foreach ( $spans as $span ) {
				if ( $at > $span[0] && $at < $span[1] ) {
					$in = true;
					break;
				}
			}
			if ( ! $in ) {
				continue;
			}
			$tag = substr( $html, $at, strlen( (string) $img[0] ) );
			$new = self::lazy_img( $tag, $excluded );
			if ( $new !== $tag ) {
				$edits[] = array( $at, strlen( $tag ), $new );
			}
		}
		foreach ( array_reverse( $edits ) as $edit ) {
			$html = substr_replace( $html, $edit[2], $edit[0], $edit[1] );
		}
		return $html;
	}

	/**
	 * @param string   $tag      One <img> tag.
	 * @param string[] $excluded Lazy's Excluded Images patterns.
	 */
	private static function lazy_img( string $tag, array $excluded ): string {
		if ( false !== stripos( $tag, 'data-skip-lazy' ) || false !== stripos( $tag, 'data-no-lazy' )
			|| \XSpeed\Lazy_Loader::has_high_fetchpriority( $tag ) ) {
			return $tag;
		}
		foreach ( $excluded as $pattern ) {
			$pattern = (string) $pattern;
			if ( '' !== $pattern && false !== stripos( $tag, $pattern ) ) {
				return $tag;
			}
		}
		if ( preg_match( '#(?<![-\w])loading\s*=\s*(["\']?)([^"\'\s>]*)\1#i', $tag, $m, PREG_OFFSET_CAPTURE ) ) {
			if ( 'eager' !== strtolower( (string) $m[2][0] ) ) {
				return $tag;
			}
			return substr_replace( $tag, 'loading="lazy"', (int) $m[0][1], strlen( (string) $m[0][0] ) );
		}
		return (string) preg_replace( '#^<img\b#i', '<img loading="lazy"', $tag, 1 );
	}

	private static function mask( string $html ): string {
		return \XSpeed\Html_Spans::mask( $html );
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed turbo-render status',
				'risk'      => 'read',
				'callback'  => array( $this, 'cli_status' ),
				'shortdesc' => 'Show Turbo Render status.',
				'ai_hint'   => 'Is Turbo Render (content-visibility stamping of below-fold sections) on, and how many sections render immediately? Use when diagnosing TBT / main-thread style & layout cost on long builder pages.',
				'synopsis'  => array(),
			),
		);
	}

	/**
	 * `wp xspeed turbo-render status`.
	 *
	 * @param array $args  Positional args (unused).
	 * @param array $assoc Associative args (unused).
	 */
	public function cli_status( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$enabled = (bool) $this->get_setting( 'enabled', self::DEFAULT_ENABLED );
		\WP_CLI::log( 'Turbo Render:       ' . ( $enabled ? 'enabled' : 'disabled' ) );
		\WP_CLI::log( 'Immediate sections: first ' . (int) $this->get_setting( 'skip_first', self::DEFAULT_SKIP_FIRST ) . ' sections' );
		\WP_CLI::log( 'Section classes:    ' . implode( ', ', $this->section_classes() ) );
		$excluded = $this->excluded_classes();
		\WP_CLI::log( 'Excluded classes:   ' . ( empty( $excluded ) ? '(none)' : implode( ', ', $excluded ) ) );
	}
}
