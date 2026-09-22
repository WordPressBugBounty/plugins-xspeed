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
	private const MASKED_SPANS = '<script\b[^>]*>.*?</script>|<textarea\b[^>]*>.*?</textarea>|<noscript\b[^>]*>.*?</noscript>|<!--.*?-->';

	/** Fallback: a child spanning less bytes than this is decoration (an empty notices div, a spacer), not a section. */
	private const FALLBACK_MIN_SPAN = 150;

	/** Fallback: a child holding this share of its siblings' combined bytes is a wrapper to unwrap, not a section. */
	private const FALLBACK_DOMINANT = 0.6;

	public function ui_metadata(): array {
		return array(
			'label'       => __( 'Turbo Render', 'xspeed' ),
			'icon'        => 'Layers',
			'description' => __( 'Paints the top of your page first and brings the rest in as visitors scroll. Every section still loads in full.', 'xspeed' ),
		);
	}

	public function settings_schema(): array {
		return array(
			'enabled' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Render as visitors scroll', 'xspeed' ),
				'description' => __( 'Lets the browser spend its first moments on what visitors actually see, then render the lower sections just before they scroll into view. The content of your page is unchanged — it simply arrives in a smarter order.', 'xspeed' ),
			),
			'skip_first' => array(
				'type'        => 'int',
				'default'     => self::DEFAULT_SKIP_FIRST,
				'min'         => 1,
				'max'         => 10,
				'label'       => __( 'Sections to render immediately', 'xspeed' ),
				'description' => __( 'How many sections at the top of the page are rendered right away, before any deferring begins. The default of 2 suits most layouts. Increase it if a section near the top of your page appears a moment late.', 'xspeed' ),
			),
			'section_classes' => array(
				'type'        => 'list',
				'default'     => self::DEFAULT_CLASSES,
				'label'       => __( 'Section classes', 'xspeed' ),
				'description' => __( 'Tells xSpeed which parts of your page count as sections. The defaults cover Elementor, Divi, Bricks, Oxygen, and Beaver Builder — and when none of them match, xSpeed falls back to your page\'s own top-level sections automatically. Add a class here only if you want to target something specific.', 'xspeed' ),
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
			if ( false !== stripos( substr( $html, $offset, $end - $offset ), 'data-xspeed-turbo' ) ) {
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
		$classes = (array) apply_filters( 'xspeed_turbo_render_classes', $classes );

		// Class names end up inside a regex alternation; anything that is
		// not a plausible CSS class token is dropped rather than escaped
		// into something surprising.
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
	}
}
