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

final class TurboRenderModule extends Module {

	public const SLUG    = 'turbo-render';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

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

	/** Sections presumed above the fold and left untouched. */
	private const DEFAULT_SKIP_FIRST = 2;

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

	private const MASKED_SPANS = '<script\b[^>]*>.*?</script>|<textarea\b[^>]*>.*?</textarea>|<noscript\b[^>]*>.*?</noscript>|<!--.*?-->';

	/** Fallback: a child spanning less bytes than this is decoration (an empty notices div, a spacer), not a section. */
	private const FALLBACK_MIN_SPAN = 150;

	/** Fallback: a child holding this share of its siblings' combined bytes is a wrapper to unwrap, not a section. */
	private const FALLBACK_DOMINANT = 0.6;

	public function ui_metadata(): array {
		return array(
			'label'       => __( 'Turbo Render', 'xspeed' ),
			'icon'        => 'Layers',
			'description' => __( 'Shows the top of each page first and the rest as visitors scroll.', 'xspeed' ),
			'group'       => 'performance',
		);
	}

	public function settings_schema(): array {
		return array(
			'enabled' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Render as visitors scroll', 'xspeed' ),
				'description' => __( 'The browser draws what visitors see first and draws lower sections just before they scroll to them. Your content does not change.', 'xspeed' ),
			),
			'skip_first' => array(
				'type'        => 'int',
				'default'     => self::DEFAULT_SKIP_FIRST,
				'min'         => 1,
				'max'         => 10,
				'label'       => __( 'Sections to render immediately', 'xspeed' ),
				'description' => __( 'How many sections at the top are drawn right away. The default of 2 suits most pages; raise it if a section near the top appears late.', 'xspeed' ),
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
		if ( ! $this->get_setting( 'enabled', false ) ) {
			return;
		}
		// After the CSS passes (combiner @5, a Pro CSS pass @6): they rewrite
		// <head>, this pass rewrites body sections — ordering only matters in
		// that our injected <style> must survive, and later passes never
		// strip inline styles.
		add_filter( 'xspeed_cache_final_html', array( $this, 'process' ), 8 );
	}

	/**
	 * Stamp below-fold top-level sections and inject the one style block.
	 *
	 * @param mixed $html Final page buffer.
	 * @return mixed
	 */
	public function process( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		if ( false === stripos( $html, '</head>' ) ) {
			return $html; // Not a full document (fragment, feed, JSON).
		}
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return $html;
		}
		// A measurement fetch (`?xspeed_css=off` — the render a CSS
		// generator reads) must see every section RENDERED. Shipped without
		// this guard, a renderer measured a page whose below-fold sections
		// the browser had skipped, judged their CSS unused, and pruned it —
		// mobile CLS went 0.00 → 0.37 because the fold's own sizing rules
		// were gone. The param is a shared contract (a Pro CSS module
		// defines it), matched here by name because Free never references
		// Pro code.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only bypass detection; changes nothing.
		if ( isset( $_GET['xspeed_css'] ) && 'off' === sanitize_text_field( wp_unslash( $_GET['xspeed_css'] ) ) ) {
			return $html;
		}

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

		$excluded = $this->excluded_pattern();

		$seen  = 0;
		$edits = array();
		foreach ( $offsets as $offset ) {
			++$seen;
			if ( $seen <= $skip ) {
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
			$edits[] = $offset;
		}

		if ( empty( $edits ) ) {
			return $html;
		}

		// Highest offset first, so earlier offsets stay valid as we splice.
		// The attribute goes right after the tag name — the one spot
		// guaranteed not to sit inside another attribute's value.
		rsort( $edits );
		foreach ( $edits as $offset ) {
			$gap  = (int) strcspn( $html, " \t\r\n/>", $offset + 1 );
			$html = substr_replace( $html, ' data-xspeed-turbo=""', $offset + 1 + $gap, 0 );
		}

		/**
		 * Filter the pre-first-render intrinsic size estimate, in pixels.
		 *
		 * @param int $px
		 */
		$px    = max( 100, (int) apply_filters( 'xspeed_turbo_render_intrinsic_px', self::DEFAULT_INTRINSIC_PX ) );
		$style = '<style id="xspeed-turbo">[data-xspeed-turbo]{content-visibility:auto;contain-intrinsic-size:auto ' . $px . 'px}@media print{[data-xspeed-turbo]{content-visibility:visible}}</style>';

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

		$head_end = stripos( $html, '</head>' );
		return substr_replace( $html, $style, (int) $head_end, 0 );
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
		static $void     = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );
		static $implicit = array( 'p', 'li', 'dt', 'dd', 'td', 'th', 'tr', 'option', 'optgroup' );

		if ( ! preg_match( '#\G<([a-zA-Z][a-zA-Z0-9-]*)#', $masked, $open, 0, $offset ) ) {
			return null;
		}
		$cursor = self::tag_end( $masked, $offset );
		if ( null === $cursor ) {
			return null;
		}
		$stack = array( strtolower( (string) $open[1] ) );
		while ( preg_match( '#<(/?)([a-zA-Z][a-zA-Z0-9-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>#', $masked, $t, PREG_OFFSET_CAPTURE, $cursor ) ) {
			$cursor  = (int) $t[0][1] + strlen( (string) $t[0][0] );
			$closing = '' !== $t[1][0];
			$tag     = strtolower( (string) $t[2][0] );
			if ( in_array( $tag, $void, true ) ) {
				continue;
			}
			if ( ! $closing ) {
				if ( in_array( $tag, $implicit, true ) && end( $stack ) === $tag ) {
					array_pop( $stack );
				}
				$stack[] = $tag;
				continue;
			}
			if ( ! in_array( $tag, $stack, true ) ) {
				if ( in_array( $tag, $implicit, true ) ) {
					continue; // Stray </p>-style close: harmless, skip it.
				}
				return null; // A close for an ancestor: the element never closed.
			}
			while ( ! empty( $stack ) && array_pop( $stack ) !== $tag ) {
				continue;
			}
			if ( empty( $stack ) ) {
				return $cursor;
			}
		}
		return null;
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
		if ( ! preg_match( '#\G<(/?)[a-zA-Z][a-zA-Z0-9-]*(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>#', $masked, $m, 0, $offset ) ) {
			return null;
		}
		return $offset + strlen( $m[0] );
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
		if ( ! preg_match_all( '#<[a-zA-Z][a-zA-Z0-9-]*\s+data-xspeed-turbo=""#', $masked, $opens, PREG_OFFSET_CAPTURE ) ) {
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
		return (string) preg_replace_callback(
			'#' . self::MASKED_SPANS . '#is',
			static fn( array $m ): string => str_repeat( "\0", strlen( $m[0] ) ),
			$html
		);
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed turbo-render status',
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
		$enabled = (bool) $this->get_setting( 'enabled', false );
		\WP_CLI::log( 'Turbo Render:       ' . ( $enabled ? 'enabled' : 'disabled' ) );
		\WP_CLI::log( 'Immediate sections: first ' . (int) $this->get_setting( 'skip_first', self::DEFAULT_SKIP_FIRST ) . ' sections' );
		\WP_CLI::log( 'Section classes:    ' . implode( ', ', $this->section_classes() ) );
		$excluded = $this->excluded_classes();
		\WP_CLI::log( 'Excluded classes:   ' . ( empty( $excluded ) ? '(none)' : implode( ', ', $excluded ) ) );
	}
}
