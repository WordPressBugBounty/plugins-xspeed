<?php
/**
 * Render Skip — stamp below-fold page sections with
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
 * containers by class, leaves the first N alone (the above-fold
 * estimate), and stamps the rest with a `data-xspeed-cv` attribute. One
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

namespace XSpeed\Modules\RenderSkip;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;

final class RenderSkipModule extends Module {

	public const SLUG    = 'render-skip';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	/**
	 * Top-level section containers, by class. Deliberately the TOP-LEVEL
	 * spellings only — `elementor-top-section` (legacy sections) and
	 * `e-parent` (flexbox containers) — never `elementor-section` /
	 * `e-con`, which also match nested wrappers and would stamp inside
	 * the sections we skip as above-fold.
	 */
	private const DEFAULT_CLASSES = array( 'elementor-top-section', 'e-parent' );

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

	public function ui_metadata(): array {
		return array(
			'label'       => __( 'Render Skip', 'xspeed' ),
			'icon'        => 'Layers',
			'description' => __( 'Skip the browser\'s style & layout work for below-fold sections until the visitor scrolls near them — cuts main-thread blocking time on long builder pages without changing the content.', 'xspeed' ),
		);
	}

	public function settings_schema(): array {
		return array(
			'enabled' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Skip below-fold rendering', 'xspeed' ),
				'description' => __( 'Stamp below-fold sections with content-visibility: auto so the browser defers their style and layout work until scroll approaches. Above-fold sections are never touched.', 'xspeed' ),
			),
			'skip_first' => array(
				'type'        => 'int',
				'default'     => self::DEFAULT_SKIP_FIRST,
				'min'         => 1,
				'max'         => 10,
				'label'       => __( 'Above-fold sections', 'xspeed' ),
				'description' => __( 'How many top-level sections from the top of the page are treated as above the fold and left untouched. Raise this if a section near the top appears late.', 'xspeed' ),
			),
			'section_classes' => array(
				'type'        => 'list',
				'default'     => self::DEFAULT_CLASSES,
				'label'       => __( 'Section classes', 'xspeed' ),
				'description' => __( 'Class names that identify a top-level page section. Defaults cover Elementor sections and flexbox containers; add your theme\'s section class for non-Elementor pages.', 'xspeed' ),
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
		if ( empty( $classes ) ) {
			return $html;
		}

		/**
		 * Filter how many top-level sections stay untouched as above-fold.
		 *
		 * @param int $skip_first
		 */
		$skip = max( 1, (int) apply_filters( 'xspeed_render_skip_after', (int) $this->get_setting( 'skip_first', self::DEFAULT_SKIP_FIRST ) ) );

		$masked  = self::mask( $html );
		$pattern = '#<(?:section|div|footer|main|article)\b[^>]*\bclass\s*=\s*(["\'])[^"\']*(?:' . implode( '|', array_map( 'preg_quote', $classes ) ) . ')[^"\']*\1[^>]*>#i';
		if ( ! preg_match_all( $pattern, $masked, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$stamped = 0;
		$seen    = 0;
		$edits   = array();
		foreach ( $m[0] as $hit ) {
			++$seen;
			if ( $seen <= $skip ) {
				continue;
			}
			$offset = (int) $hit[1];
			$tag    = substr( $html, $offset, strlen( (string) $hit[0] ) );
			if ( false !== stripos( $tag, 'data-xspeed-cv' ) ) {
				continue;
			}
			$edits[] = $offset;
			++$stamped;
		}

		if ( 0 === $stamped ) {
			return $html;
		}

		// Highest offset first, so earlier offsets stay valid as we splice.
		// The attribute goes right after the tag name — the one spot
		// guaranteed not to sit inside another attribute's value.
		rsort( $edits );
		foreach ( $edits as $offset ) {
			$gap  = (int) strcspn( $html, " \t\r\n/>", $offset + 1 );
			$html = substr_replace( $html, ' data-xspeed-cv=""', $offset + 1 + $gap, 0 );
		}

		/**
		 * Filter the pre-first-render intrinsic size estimate, in pixels.
		 *
		 * @param int $px
		 */
		$px    = max( 100, (int) apply_filters( 'xspeed_render_skip_intrinsic_px', self::DEFAULT_INTRINSIC_PX ) );
		$style = '<style id="xspeed-cv">[data-xspeed-cv]{content-visibility:auto;contain-intrinsic-size:auto ' . $px . 'px}@media print{[data-xspeed-cv]{content-visibility:visible}}</style>';

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
		$classes = (array) apply_filters( 'xspeed_render_skip_classes', $classes );

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
				'name'      => 'xspeed render-skip status',
				'callback'  => array( $this, 'cli_status' ),
				'shortdesc' => 'Show below-fold render-skip status.',
				'ai_hint'   => 'Is content-visibility stamping of below-fold sections on, and with what above-fold allowance? Use when diagnosing TBT / main-thread style & layout cost on long builder pages.',
				'synopsis'  => array(),
			),
		);
	}

	/**
	 * `wp xspeed render-skip status`.
	 *
	 * @param array $args  Positional args (unused).
	 * @param array $assoc Associative args (unused).
	 */
	public function cli_status( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$enabled = (bool) $this->get_setting( 'enabled', false );
		\WP_CLI::log( 'Render skip:        ' . ( $enabled ? 'enabled' : 'disabled' ) );
		\WP_CLI::log( 'Above-fold skip:    first ' . (int) $this->get_setting( 'skip_first', self::DEFAULT_SKIP_FIRST ) . ' sections' );
		\WP_CLI::log( 'Section classes:    ' . implode( ', ', $this->section_classes() ) );
	}
}
