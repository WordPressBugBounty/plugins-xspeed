<?php
/**
 * Optimize plan — what an autopilot run is allowed to change, and in what order.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * The settings an automated optimization run may touch, classified by risk.
 *
 * This file is deliberately pure: no I/O, no WordPress calls beyond the array
 * it returns. Every judgement an autopilot makes about "is this safe to turn
 * on" is encoded here, in one table, so the answer does not depend on which AI
 * is driving or what it happened to infer from a settings screen.
 *
 * Two of the classifications below are evidence, not taste:
 *
 *  - `combine_css` sits in STANDARD rather than SAFE because it has unstyled a
 *    production frontend before. WordPress can inline a stylesheet it considers
 *    small, which dropped the combined file every other sheet had been folded
 *    into; the cascade and inline-preservation contract exists because of it.
 *    It stays in the plan — the win is real — but only behind a verification
 *    that can put it back.
 *
 *  - `strip_jquery_migrate` sits in AGGRESSIVE because turning it on broke a
 *    live page with `jQuery.Deferred exception: e.indexOf is not a function`.
 *    The document was complete and the right size; only a rendered console
 *    caught it. Anything whose failure is invisible to HTML-level checks
 *    belongs behind an explicit opt-in.
 *
 * @since 1.2.0
 */
final class Optimize_Plan {

	/**
	 * Pseudo-module for settings that are not module settings.
	 *
	 * Page caching is the case: it installs a drop-in and writes a global
	 * option, so it cannot go through Settings_Manager::update() like the
	 * rest. Marking it here keeps the catalog uniform and lets the runner
	 * dispatch it correctly instead of writing a key that will be dropped.
	 */
	public const MODULE_GLOBAL = '__global';

	/** Always applied — removals and server-side wins with no render risk. */
	public const TIER_SAFE = 'safe';

	/** Applied by default. Real wins that can change how a page renders. */
	public const TIER_STANDARD = 'standard';

	/** Opt-in only. Known to break real sites in ways HTML checks miss. */
	public const TIER_AGGRESSIVE = 'aggressive';

	/**
	 * Never applied by an automated run, at any aggressiveness.
	 *
	 * Not a risk rating — a boundary. These edit content, disable other
	 * people's plugins, or restrict endpoints a theme may depend on. An
	 * autopilot that can do them is not an optimizer, it is a site editor,
	 * and nobody asked for one.
	 */
	public const TIER_NEVER = 'never';

	/**
	 * One step: the module to write, the values, and why.
	 *
	 * `id` matches the recommendation vocabulary where one exists, so a step
	 * and a recommendation for the same problem are recognisably the same
	 * thing in a report.
	 *
	 * @return array<int,array{id:string,tier:string,module:string,values:array<string,mixed>,label:string}>
	 */
	private static function catalog(): array {
		return array(
			// --- SAFE -------------------------------------------------------
			// The single biggest win available, and the reason the plugin
			// exists. Nothing renders differently; requests stop reaching PHP.
			array(
				'id'     => 'cache_disabled',
				'tier'   => self::TIER_SAFE,
				// Not a module setting. Page caching is a drop-in install plus
				// a global option, so it goes through Cache::toggle() rather
				// than Settings_Manager — writing `cache.enabled` looks right,
				// is silently dropped as out-of-schema (#206), and reports
				// success having changed nothing.
				'module' => self::MODULE_GLOBAL,
				'values' => array( 'cache_enabled' => true ),
				'label'  => 'Turn on page caching',
			),
			// Bytes over the wire. Inert where the server cannot do it — the
			// module writes .htaccess only where supported and otherwise just
			// shows a snippet, so enabling it can never half-configure a host.
			array(
				'id'     => 'gzip_off',
				'tier'   => self::TIER_SAFE,
				'module' => 'gzip',
				'values' => array( 'gzip_enabled' => true ),
				'label'  => 'Turn on GZIP compression',
			),
			// Far-future headers for static assets only; HTML keeps its own
			// short TTL, so a content change is still picked up immediately.
			array(
				'id'     => 'browser_cache_off',
				'tier'   => self::TIER_SAFE,
				'module' => 'browser-cache',
				'values' => array( 'enabled' => true ),
				'label'  => 'Add browser cache headers',
			),
			// Whitespace and comments only. Neither reorders nor combines
			// anything, so none of the cascade risk that combine_css carries.
			array(
				'id'     => 'minify_html_off',
				'tier'   => self::TIER_SAFE,
				'module' => 'minify',
				'values' => array( 'minify_html' => true ),
				'label'  => 'Minify HTML',
			),
			array(
				'id'     => 'minify_css_off',
				'tier'   => self::TIER_SAFE,
				'module' => 'minify',
				'values' => array( 'minify_css' => true ),
				'label'  => 'Minify CSS',
			),
			// Admin icon font on a public page. Removed only for logged-out
			// visitors, who have no admin bar to render it in.
			array(
				'id'     => 'dashicons_frontend',
				'tier'   => self::TIER_SAFE,
				'module' => 'bloat',
				'values' => array( 'disable_dashicons_frontend' => true ),
				'label'  => 'Stop loading admin icons for visitors',
			),
			array(
				'id'     => 'xmlrpc_on',
				'tier'   => self::TIER_SAFE,
				'module' => 'bloat',
				'values' => array( 'disable_xmlrpc' => true ),
				'label'  => 'Disable XML-RPC',
			),

			// --- STANDARD ---------------------------------------------------
			array(
				'id'     => 'minify_js_off',
				'tier'   => self::TIER_STANDARD,
				'module' => 'minify',
				'values' => array( 'minify_js' => true ),
				'label'  => 'Minify JavaScript',
			),
			array(
				'id'     => 'defer_js_off',
				'tier'   => self::TIER_STANDARD,
				'module' => 'minify',
				'values' => array( 'defer_js' => true ),
				'label'  => 'Defer JavaScript',
			),
			array(
				'id'     => 'lazy_images_off',
				'tier'   => self::TIER_STANDARD,
				'module' => 'lazy',
				'values' => array( 'lazy_images' => true ),
				'label'  => 'Lazy-load images',
			),
			// Fills width/height where it can resolve them locally. Never
			// fetches a remote image to measure it, so an externally hosted
			// image is left alone rather than guessed at.
			array(
				'id'     => 'missing_dimensions_off',
				'tier'   => self::TIER_STANDARD,
				'module' => 'lazy',
				'values' => array( 'add_missing_dimensions' => true ),
				'label'  => 'Add missing image dimensions',
			),
			// Last in the order on purpose — see sort_order().
			array(
				'id'     => 'combine_css_off',
				'tier'   => self::TIER_STANDARD,
				'module' => 'minify',
				'values' => array( 'combine_css' => true ),
				'label'  => 'Combine CSS files',
			),
			array(
				'id'     => 'combine_js_off',
				'tier'   => self::TIER_STANDARD,
				'module' => 'minify',
				'values' => array( 'combine_js' => true ),
				'label'  => 'Combine JavaScript files',
			),

			// --- AGGRESSIVE -------------------------------------------------
			array(
				'id'     => 'delay_js_off',
				'tier'   => self::TIER_AGGRESSIVE,
				'module' => 'minify',
				'values' => array( 'delay_js' => true ),
				'label'  => 'Delay JavaScript until interaction',
			),
			// Flash of unstyled content when the theme has no critical CSS.
			array(
				'id'     => 'async_css_off',
				'tier'   => self::TIER_AGGRESSIVE,
				'module' => 'minify',
				'values' => array( 'async_css' => true ),
				'label'  => 'Load CSS asynchronously',
			),
			// Broke a live page; the failure was invisible to HTML checks.
			array(
				'id'     => 'jquery_migrate_on',
				'tier'   => self::TIER_AGGRESSIVE,
				'module' => 'bloat',
				'values' => array( 'strip_jquery_migrate' => true ),
				'label'  => 'Remove jQuery Migrate',
			),
		);
	}

	/**
	 * Order to attempt steps in: cheapest and safest first.
	 *
	 * The point is not speed, it is diagnosis. By the time the riskiest change
	 * is attempted, every earlier one has been applied AND verified — so if
	 * the page breaks, the change that broke it is the one just made, and
	 * reverting one setting is enough to recover.
	 *
	 * Ids not listed sort last, in catalog order, so adding a step without
	 * touching this list degrades to "attempt it late" rather than "attempt it
	 * first".
	 *
	 * @return string[]
	 */
	private static function sort_order(): array {
		return array(
			'cache_disabled',
			'gzip_off',
			'browser_cache_off',
			'minify_html_off',
			'minify_css_off',
			'dashicons_frontend',
			'xmlrpc_on',
			'minify_js_off',
			'defer_js_off',
			'lazy_images_off',
			'missing_dimensions_off',
			'delay_js_off',
			'async_css_off',
			'jquery_migrate_on',
			// Combining runs LAST. It is the change most likely to alter how
			// the page renders, so it is attempted against a page every other
			// step has already been verified against.
			'combine_css_off',
			'combine_js_off',
		);
	}

	/**
	 * Which tiers a run at this aggressiveness may apply.
	 *
	 * @param string $aggressiveness safe|standard|aggressive.
	 * @return string[]
	 */
	public static function tiers_for( string $aggressiveness ): array {
		switch ( $aggressiveness ) {
			case self::TIER_SAFE:
				return array( self::TIER_SAFE );
			case self::TIER_AGGRESSIVE:
				return array( self::TIER_SAFE, self::TIER_STANDARD, self::TIER_AGGRESSIVE );
			case self::TIER_STANDARD:
			default:
				return array( self::TIER_SAFE, self::TIER_STANDARD );
		}
	}

	/**
	 * The tier of one step id, or TIER_NEVER when it is not in the catalog.
	 *
	 * Unknown ids resolve to `never` rather than to a default tier: a setting
	 * nobody has classified must not become applyable by being forgotten.
	 *
	 * @param string $id Step id.
	 */
	public static function tier( string $id ): string {
		foreach ( self::catalog() as $step ) {
			if ( $step['id'] === $id ) {
				return $step['tier'];
			}
		}
		return self::TIER_NEVER;
	}

	/**
	 * Every step in the catalog, ordered. Exposed for tests and `--dry-run`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all_steps(): array {
		$order  = array_flip( self::sort_order() );
		$steps  = self::catalog();
		$fallback = count( $order );
		usort(
			$steps,
			static function ( $a, $b ) use ( $order, $fallback ) {
				$ra = $order[ $a['id'] ] ?? $fallback;
				$rb = $order[ $b['id'] ] ?? $fallback;
				return $ra <=> $rb;
			}
		);
		return $steps;
	}

	/**
	 * Build the ordered plan for a site.
	 *
	 * A setting already at its target value is NOT a step — it belongs in the
	 * report's `skipped[]`, because "we turned this on" about something that
	 * was already on is a false claim of work, and it inflates any before/after
	 * story built from the plan.
	 *
	 * @param array<string,array<string,mixed>> $current        Current settings, keyed by module slug.
	 * @param string                            $aggressiveness safe|standard|aggressive.
	 * @return array{steps:array<int,array<string,mixed>>,skipped:array<int,array<string,string>>}
	 */
	public static function build( array $current, string $aggressiveness = self::TIER_STANDARD ): array {
		$allowed = self::tiers_for( $aggressiveness );
		$steps   = array();
		$skipped = array();

		foreach ( self::all_steps() as $step ) {
			if ( ! in_array( $step['tier'], $allowed, true ) ) {
				$skipped[] = array(
					'id'  => $step['id'],
					'why' => sprintf(
						/* translators: 1: tier name, 2: current aggressiveness */
						__( 'Needs %1$s aggressiveness (run is %2$s).', 'xspeed' ),
						$step['tier'],
						$aggressiveness
					),
				);
				continue;
			}

			$module = $current[ $step['module'] ] ?? array();
			if ( self::already_satisfied( $module, $step['values'] ) ) {
				$skipped[] = array(
					'id'  => $step['id'],
					'why' => __( 'Already enabled.', 'xspeed' ),
				);
				continue;
			}

			$steps[] = $step;
		}

		return array(
			'steps'   => $steps,
			'skipped' => $skipped,
		);
	}

	/**
	 * True when every target value is already the current value.
	 *
	 * Compared loosely on purpose: settings arrive from the options table where
	 * a stored `1` and a schema `true` mean the same thing, and a strict
	 * comparison would re-apply half the catalog on every run.
	 *
	 * @param array<string,mixed> $module Current module settings.
	 * @param array<string,mixed> $values Target values.
	 */
	private static function already_satisfied( array $module, array $values ): bool {
		foreach ( $values as $key => $want ) {
			if ( ! array_key_exists( $key, $module ) ) {
				return false;
			}
			if ( is_bool( $want ) ) {
				if ( (bool) $module[ $key ] !== $want ) {
					return false;
				}
				continue;
			}
			// phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- stored options are strings; 1 == true is the intent.
			if ( $module[ $key ] != $want ) {
				return false;
			}
		}
		return true;
	}
}
