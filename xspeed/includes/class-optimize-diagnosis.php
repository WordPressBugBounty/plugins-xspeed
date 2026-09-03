<?php
/**
 * Optimize diagnosis — what is wrong, and who can actually fix it.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * Turn a site's own measurements into an answer to three questions:
 * what is slow, what could this tool still do about it, and what can only a
 * person do.
 *
 * The autopilot without this is a dead end. It applies what it can, says
 * "everything is already on", and stops — leaving a user looking at a score of
 * 50 with no idea whether that is as good as it gets, what the remaining
 * problems even are, or whether anything else is worth trying. The tool knows
 * all three and was throwing the knowledge away.
 *
 * The split that matters is **agent-fixable vs human-fixable**:
 *
 *  - `agent` — a setting this tool can flip, given permission. It belongs in an
 *    offer: "I can try this, here is the risk."
 *  - `human` — content and infrastructure. A 945KB autoplaying video, 2,000
 *    DOM elements from a page builder, an old PHP version. No caching plugin
 *    reaches these, and pretending otherwise is how a user ends up believing
 *    their site is fast when it is not.
 *
 * The boundary moves as the plugin learns to do more, and this file has to
 * move with it. Images hotlinked from another domain sat under `human` until
 * the dimension lookup learned to measure them during a cache warm; leaving
 * them there would have kept telling people to re-host media the plugin now
 * handles. An entry that has quietly become false is as harmful as a win we
 * never earned — it just wastes the user's time instead of their trust.
 *
 * Keeping them apart is the whole point. An assistant handed one flat list of
 * problems will offer to fix all of them; handed this, it can say "I can try
 * these two, the other four need you or your host."
 *
 * @since 1.2.0
 */
final class Optimize_Diagnosis {

	/**
	 * The remaining problems, split by who can act on them.
	 *
	 * @param array<string,array<string,mixed>> $settings Current settings, as Optimize_Plan reads them.
	 * @return array{
	 *     score:array<string,mixed>|null,
	 *     agent_fixable:array<int,array<string,mixed>>,
	 *     human_fixable:array<int,array<string,mixed>>
	 * }
	 */
	public static function build( array $settings ): array {
		$opportunities = self::routed_opportunities( $settings );

		return array(
			'score'         => self::latest_score(),
			'agent_fixable' => array_merge( self::agent_fixable( $settings ), $opportunities['agent'] ),
			'human_fixable' => array_merge( self::human_fixable(), $opportunities['human'] ),
		);
	}

	/**
	 * The last audit's measured opportunities, routed by who can act.
	 *
	 * The audit already names the problems ("Reduce unused CSS — 280ms
	 * available") and the split rule of this class applies to them exactly
	 * as it does to settings:
	 *
	 *  - a module of ours addresses it and is OFF → agent_fixable, as an
	 *    offer with the measured saving attached.
	 *  - a module of ours addresses it and is ON → suppressed. The module
	 *    is already working on it, and reporting it as the user's problem
	 *    is the same dishonesty as the CLS entry this class already
	 *    exempts.
	 *  - nothing of ours reaches it (or the module isn't installed) →
	 *    human_fixable with its measured cost, which is what makes the
	 *    entry actionable: "Reduce unused CSS — 280ms" beats "TBT is
	 *    734ms".
	 *
	 * Reads history only — same rule as latest_score(): a diagnosis never
	 * spends the owner's audit quota.
	 *
	 * @param array<string,array<string,mixed>> $settings Current settings, keyed by module slug.
	 * @return array{agent:array<int,array<string,mixed>>,human:array<int,array<string,mixed>>}
	 */
	private static function routed_opportunities( array $settings ): array {
		$out = array(
			'agent' => array(),
			'human' => array(),
		);

		if ( ! class_exists( '\XSpeed\Score' ) ) {
			return $out;
		}
		$latest = Score::latest();
		if ( ! is_array( $latest ) ) {
			return $out;
		}

		// Local runs store `issues` (parse_psi); Hub-synced rows store
		// `opportunities` (Score_Store). Same facts, two field names.
		$raw = array();
		if ( isset( $latest['issues'] ) && is_array( $latest['issues'] ) ) {
			$raw = $latest['issues'];
		} elseif ( isset( $latest['opportunities'] ) && is_array( $latest['opportunities'] ) ) {
			$raw = $latest['opportunities'];
		}

		$map       = self::opportunity_map();
		$available = Module_Registry::available();

		foreach ( $raw as $opportunity ) {
			if ( ! is_array( $opportunity ) ) {
				continue;
			}
			$id    = isset( $opportunity['id'] ) ? (string) $opportunity['id'] : '';
			$title = isset( $opportunity['title'] ) ? (string) $opportunity['title'] : $id;
			if ( '' === $id ) {
				continue;
			}
			$savings = isset( $opportunity['savings_ms'] ) && is_numeric( $opportunity['savings_ms'] )
				? (int) $opportunity['savings_ms']
				: null;
			$cost = null !== $savings
				? sprintf(
					/* translators: %d: measured saving in milliseconds. */
					__( '%dms available', 'xspeed' ),
					$savings
				)
				: '';

			$route = $map[ $id ] ?? null;
			if ( null !== $route && isset( $available[ $route['module'] ] ) ) {
				$module_settings = $settings[ $route['module'] ] ?? Settings_Manager::get( $route['module'] );
				if ( ! empty( $module_settings[ $route['setting'] ] ) ) {
					// Already handled — the next crawl/render resolves it.
					// Reporting it as the user's problem would be wrong.
					continue;
				}
				$out['agent'][] = array(
					'id'     => $id,
					'change' => $title,
					'risk'   => '' !== $cost
						? sprintf(
							/* translators: 1: measured saving, 2: setting name. */
							__( 'The last audit measured %1$s here; the %2$s setting addresses it and is currently off.', 'xspeed' ),
							$cost,
							$route['setting']
						)
						: sprintf(
							/* translators: %s: setting name. */
							__( 'The last audit flagged this; the %s setting addresses it and is currently off.', 'xspeed' ),
							$route['setting']
						),
				);
				continue;
			}

			$out['human'][] = array(
				'issue' => '' !== $cost ? $title . ' — ' . $cost : $title,
				'why'   => __( 'Measured by the last audit. This is page content or a third-party asset — no caching setting reaches it.', 'xspeed' ),
				'owner' => 'content',
			);
		}

		return $out;
	}

	/**
	 * Lighthouse opportunity id → the module setting that addresses it.
	 *
	 * Only mappings that are actually true belong here — an id routed to a
	 * setting that doesn't move it sends the user (or an agent) to flip a
	 * switch and trust the tool less when nothing changes. When in doubt,
	 * leave the id unmapped and let it report as content with its cost.
	 *
	 * @return array<string,array{module:string,setting:string}>
	 */
	private static function opportunity_map(): array {
		return array(
			'offscreen-images'          => array(
				'module'  => 'lazy',
				'setting' => 'lazy_images',
			),
			'unminified-css'            => array(
				'module'  => 'minify',
				'setting' => 'minify_css',
			),
			'unminified-javascript'     => array(
				'module'  => 'minify',
				'setting' => 'minify_js',
			),
			'render-blocking-resources' => array(
				'module'  => 'minify',
				'setting' => 'async_css',
			),
			'uses-text-compression'     => array(
				'module'  => 'gzip',
				'setting' => 'gzip_enabled',
			),
			'uses-long-cache-ttl'       => array(
				'module'  => 'browser-cache',
				'setting' => 'enabled',
			),
			// Registered by Pro when installed; absent → routes to content,
			// which is the honest answer on a Free-only site.
			'unused-css-rules'          => array(
				'module'  => 'unused-css',
				'setting' => 'enabled',
			),
		);
	}

	/**
	 * The most recent stored audit, reduced to the numbers that decide a score.
	 *
	 * Read from history rather than run fresh: an optimize run should not
	 * silently spend someone's PageSpeed allowance, and a score from this
	 * morning is enough to say what is wrong.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function latest_score(): ?array {
		if ( ! class_exists( '\XSpeed\Score' ) ) {
			return null;
		}
		// Score::latest() is the newest run with ok === true. History is
		// newest-first, so end() walked to the OLDEST retained run — and a
		// failed audit has score null, which must never be presented as the
		// current score.
		$latest = Score::latest();
		if ( ! is_array( $latest ) ) {
			return null;
		}

		$bag = isset( $latest['metrics'] ) && is_array( $latest['metrics'] ) ? $latest['metrics'] : array();

		$metrics = array();
		foreach ( array( 'lcp', 'cls', 'tbt' ) as $key ) {
			$value = $bag[ $key ] ?? null;
			if ( ! is_numeric( $value ) ) {
				continue;
			}
			$metrics[ $key ] = array(
				'value'  => (float) $value,
				'rating' => self::rate( $key, (float) $value ),
			);
		}

		return array(
			'score'    => $latest['score'] ?? null,
			'strategy' => $latest['strategy'] ?? null,
			'ran_at'   => $latest['ts'] ?? null,
			'metrics'  => $metrics,
		);
	}

	/**
	 * Rate one metric against Google's published thresholds.
	 *
	 * Uses Score::thresholds() rather than a second copy of the numbers: a
	 * dashboard saying "needs improvement" while this says "poor" about the
	 * same measurement is the kind of contradiction that costs trust.
	 *
	 * @param string $key   Metric key.
	 * @param float  $value Measured value.
	 */
	private static function rate( string $key, float $value ): string {
		$thresholds = Score::thresholds();
		if ( ! isset( $thresholds[ $key ] ) ) {
			return 'unknown';
		}
		if ( $value <= $thresholds[ $key ]['good'] ) {
			return 'good';
		}
		if ( $value <= $thresholds[ $key ]['poor'] ) {
			return 'needs-improvement';
		}
		return 'poor';
	}

	/**
	 * Settings this tool could still turn on, with the risk of each.
	 *
	 * Only AGGRESSIVE steps appear here: safe and standard ones are applied
	 * automatically, so anything still off at those tiers has already been
	 * tried and skipped. What is left is exactly the set that needs a human to
	 * say yes.
	 *
	 * Every entry carries `risk` in plain language, naming the real failure
	 * mode rather than a generic warning. "This may affect your site" teaches
	 * nobody anything; "removing jQuery Migrate broke a page with
	 * `e.indexOf is not a function`" lets someone decide.
	 *
	 * @param array<string,array<string,mixed>> $settings Current settings.
	 * @return array<int,array<string,mixed>>
	 */
	private static function agent_fixable( array $settings ): array {
		$risks = self::risks();
		$plan  = Optimize_Plan::build( $settings, Optimize_Plan::TIER_AGGRESSIVE );

		$out = array();
		foreach ( $plan['steps'] as $step ) {
			if ( Optimize_Plan::TIER_AGGRESSIVE !== $step['tier'] ) {
				continue;
			}
			$out[] = array(
				'id'     => $step['id'],
				'change' => $step['label'],
				'risk'   => $risks[ $step['id'] ] ?? __( 'May change how the page renders. It is verified after applying and undone if the page breaks.', 'xspeed' ),
			);
		}
		return $out;
	}

	/**
	 * What each aggressive setting can actually break.
	 *
	 * Written from failures that have happened, not from imagination. A risk
	 * note the reader has no reason to believe is worse than none, because it
	 * trains them to click past the next one.
	 *
	 * @return array<string,string>
	 */
	private static function risks(): array {
		return array(
			'delay_js_off'      => __( 'Scripts do not run until the visitor interacts. Sliders, counters and anything that animates on load may sit still until first touch, and a script that expects to run immediately can misbehave.', 'xspeed' ),
			'async_css_off'     => __( 'Stylesheets load without blocking the first paint, so a theme with no critical CSS can flash unstyled for a moment. It also moves styling to after first paint, which can INCREASE layout shift on a page that already shifts.', 'xspeed' ),
			'jquery_migrate_on' => __( 'Older themes and plugins still depend on it. Removing it broke a real page with "jQuery.Deferred exception: e.indexOf is not a function" — an error the page-level check cannot see, because the HTML arrives intact and only the browser notices.', 'xspeed' ),
		);
	}

	/**
	 * Problems no caching plugin can reach.
	 *
	 * Two sources: the site's own environment checks, and the last audit's
	 * measured opportunities. Both are things the user or their host has to
	 * act on — which is precisely why they must be reported rather than
	 * quietly dropped from a success message.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function human_fixable(): array {
		$out = array();

		if ( class_exists( '\XSpeed\Health' ) ) {
			foreach ( Health::checks() as $check ) {
				$tone = (string) ( $check['tone'] ?? '' );
				if ( 'warn' !== $tone && 'fail' !== $tone ) {
					continue;
				}
				// Environment facts the plugin surfaces but cannot change: a
				// PHP version, a server config snippet only the host can paste.
				if ( ! in_array( (string) ( $check['id'] ?? '' ), array( 'php_version', 'server', 'static_rewrite_nginx' ), true ) ) {
					continue;
				}
				$out[] = array(
					'issue' => (string) ( $check['label'] ?? '' ),
					'why'   => (string) ( $check['detail'] ?? '' ),
					'owner' => 'host',
				);
			}
		}

		// The audit's own measured savings. These are page CONTENT — an
		// oversized image, a render-blocking third-party script, a DOM the
		// theme builds — so they are named with their measured cost and left
		// to the person who can change the page.
		//
		// With one exception. A poor CLS used to be listed here unconditionally
		// with "images on another domain need the dimensions set by hand",
		// which stopped being true once the dimension lookup learned to
		// measure remote images during a cache warm. Telling someone to go and
		// re-host their media for something the plugin now fixes is the same
		// dishonesty as claiming a win we did not earn, pointed the other way:
		// it sends them off to do unnecessary work.
		$latest = self::latest_score();
		if ( is_array( $latest ) && isset( $latest['metrics'] ) ) {
			$dimensions_on = ! empty( Settings_Manager::get( 'lazy' )['add_missing_dimensions'] );
			foreach ( $latest['metrics'] as $key => $metric ) {
				if ( 'poor' !== $metric['rating'] ) {
					continue;
				}
				if ( 'cls' === $key && $dimensions_on ) {
					// Handled automatically — the next crawl resolves what is
					// missing. Reporting it as the user's problem would be
					// wrong; reporting it as nothing would hide that it needs
					// a warm to take effect.
					continue;
				}
				$out[] = array(
					'issue' => self::metric_label( $key, $metric['value'] ),
					'why'   => self::metric_cause( $key ),
					'owner' => 'content',
				);
			}
		}

		return $out;
	}

	/**
	 * @param string $key   Metric key.
	 * @param float  $value Measured value.
	 */
	private static function metric_label( string $key, float $value ): string {
		$names = array(
			'lcp' => __( 'Largest Contentful Paint', 'xspeed' ),
			'cls' => __( 'Cumulative Layout Shift', 'xspeed' ),
			'tbt' => __( 'Total Blocking Time', 'xspeed' ),
		);
		$name  = $names[ $key ] ?? strtoupper( $key );
		$shown = 'cls' === $key ? number_format( $value, 3 ) : round( $value ) . 'ms';
		return $name . ' is ' . $shown;
	}

	/**
	 * What actually causes a metric to be poor once caching is already right.
	 *
	 * @param string $key Metric key.
	 */
	private static function metric_cause( string $key ): string {
		switch ( $key ) {
			case 'lcp':
				return __( 'The biggest thing on screen takes too long to appear. Usually a large hero image or video, or a font that blocks text. Caching does not shrink it — the file itself has to get smaller or load sooner.', 'xspeed' );
			case 'cls':
				return __( 'The page moves while it loads. Almost always images without width and height, or content injected above what is already visible. xSpeed fills in missing dimensions — including for images hosted on other domains, which it measures during a cache warm — so what is usually left here is content that appears above existing content: an embed, a banner, or a script that inserts markup after first paint.', 'xspeed' );
			case 'tbt':
				return __( 'Scripts hold the main thread so the page cannot respond. Fewer or smaller scripts is the only real fix; delaying them (aggressive mode) moves the work rather than removing it.', 'xspeed' );
			default:
				return '';
		}
	}
}
