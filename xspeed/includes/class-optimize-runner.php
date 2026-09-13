<?php
/**
 * Optimize runner — the five phases, wired to the real site.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * Measure, diagnose, apply, verify, re-measure — and report honestly.
 *
 * This is the thin layer that gives Optimize_Plan / Optimizer / Optimize_Verifier
 * a real site to work on. Everything interesting lives in those three; what is
 * here is the wiring and, more importantly, the shape of what comes back.
 *
 * ## Why `unfixable` exists
 *
 * The temptation with a tool like this is to return "done" and a green tick.
 * That is false on a large class of sites, and the falseness is the expensive
 * kind — the AI repeats it to the user, who believes their site is now fast.
 *
 * A real site tested during this feature's design carried a 97% hit ratio, a
 * 124ms TTFB, and every optimization already enabled — and still scored 50,
 * because of 15 images hotlinked from another domain, a 945KB video and 2,093
 * DOM elements. There was nothing left for a caching plugin to do, and saying
 * "optimized!" would have been a lie of omission.
 *
 * So the report names what it could not fix and why. A run that changes nothing
 * is a legitimate outcome, reported as such.
 *
 * ## Why the score is measured rather than remembered
 *
 * The same honesty problem applies to the number itself. This read the last
 * stored audit, which on a site measured a fortnight ago meant applying six
 * changes and then reporting a two-week-old 77 as the outcome — a stale figure
 * presented in the position a reader takes for a result.
 *
 * `measure_score` decides what to spend on avoiding that:
 *
 *  - `auto` (default) — measure when the stored score is stale, and always
 *    after changes land, subject to the cooldown in Optimize_Diagnosis.
 *  - `never` — the pre-1.2.0 behaviour, for callers that must not spend a
 *    measurement.
 *  - `always` — measure even for a dry run.
 *
 * Every score now carries `age_seconds`, and the summary sentence names it, so
 * a number that could not be refreshed is still readable as old rather than
 * passing for fresh.
 *
 * ## One pass, and the seam for more
 *
 * This runs ONE pass: every step the tier allows, then stop. That is the whole
 * of Free's behaviour and it is deliberate — a pass has a bounded cost, and
 * chasing a target score does not.
 *
 * Repeating the cycle until a site reaches, say, 90 means measuring after each
 * round and planning the next against whatever metric is actually weak, which
 * is several measurements per site and only pays off across a fleet. That loop
 * is Pro's; `xspeed_optimize_report` is where it attaches, so it never needs to
 * fork this file. `rounds` in the report says how many passes produced it, and
 * is 1 for everything Free does on its own.
 *
 * @since 1.2.0
 */
final class Optimize_Runner {

	/**
	 * How many pages OUR OWN suggestions offer for post-run checking.
	 *
	 * Applied before `xspeed_optimize_verify_urls` runs, so a site can add
	 * its own risky template without ours crowding it out. Three is the point
	 * where a person still opens all of them.
	 */
	private const VERIFY_URL_LIMIT = 3;

	/**
	 * Run the whole thing.
	 *
	 * @param array{aggressiveness?:string,dry_run?:bool,budget_seconds?:int,url?:string,measure_score?:string,round?:int} $args Options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function run( array $args = array() ) {
		$aggressiveness = (string) ( $args['aggressiveness'] ?? Optimize_Plan::TIER_STANDARD );
		$dry_run        = (bool) ( $args['dry_run'] ?? false );
		$budget         = (int) ( $args['budget_seconds'] ?? 120 );
		$url            = (string) ( $args['url'] ?? home_url( '/' ) );
		$measure        = (string) ( $args['measure_score'] ?? 'auto' );

		// Which round this is. A listener on xspeed_optimize_report re-enters
		// run() for round 2 onward and passes this, so the filter context
		// reports the true round rather than always claiming to be the first.
		$round = max( 1, (int) ( $args['round'] ?? 1 ) );

		// Arguments Free does not act on, forwarded to the seam so a listener
		// can. `target_score` / `max_rounds` are the multi-round tuner's, and
		// they arrive here from the MCP tool and the CLI like any other
		// argument — Free's job is to carry them, not to understand them.
		//
		// Clamped on the way through, not passed on raw. Free does not act on
		// these, but it is the only place that sees them before a listener
		// does, and a tuner handed `target_score = 9000` would chase a score
		// that cannot exist — burning rounds and measurements on an
		// unreachable goal. A Lighthouse score is 0-100 and a round count
		// below 1 is not a request. (#306 QA, minor 2)
		$bounds      = array(
			'target_score' => array( 0, 100 ),
			'max_rounds'   => array( 1, 10 ),
		);
		$passthrough = array();
		foreach ( $bounds as $key => list( $min, $max ) ) {
			if ( isset( $args[ $key ] ) && is_numeric( $args[ $key ] ) ) {
				$passthrough[ $key ] = max( $min, min( $max, (int) $args[ $key ] ) );
			}
		}

		// Built once, passed to every seam call, so the two report paths cannot
		// hand a listener different context for the same run.
		$context = array_merge(
			array(
				'aggressiveness' => $aggressiveness,
				'measure_score'  => $measure,
				'budget_seconds' => $budget,
				'url'            => $url,
				'round'          => $round,
			),
			$passthrough
		);

		if ( ! in_array( $measure, array( 'auto', 'never', 'always' ), true ) ) {
			return new \WP_Error(
				'xspeed_optimize_measure_score',
				__( 'measure_score must be auto, never or always.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $aggressiveness, array( Optimize_Plan::TIER_SAFE, Optimize_Plan::TIER_STANDARD, Optimize_Plan::TIER_AGGRESSIVE ), true ) ) {
			return new \WP_Error(
				'xspeed_optimize_aggressiveness',
				__( 'Aggressiveness must be safe, standard or aggressive.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}

		// --- 1. Diagnose ------------------------------------------------
		$current = self::current_settings();
		$plan    = Optimize_Plan::build( $current, $aggressiveness );

		if ( $dry_run ) {
			// A dry run changes nothing, so it has nothing to prove with a
			// fresh number — and starting a PSI run for a preview is the
			// surprise spend the cooldown exists to prevent. `always` is still
			// honoured: someone explicitly asking to measure gets a
			// measurement.
			$preview = Optimize_Diagnosis::build( $current, self::score_for( 'always' === $measure ? 'always' : 'never' ) );
			$out     = array(
				'dry_run'   => true,
				'message'   => self::summary( $preview, 0, count( $plan['steps'] ) ),
				'score'     => $preview['score'],
				'plan'      => array_map(
					static function ( $s ) {
						return array(
							'id'     => $s['id'],
							'change' => $s['label'],
							'tier'   => $s['tier'],
						);
					},
					$plan['steps']
				),
				'skipped'   => $plan['skipped'],
				'next_steps' => $preview['agent_fixable'],
				'unfixable' => $preview['human_fixable'],
			);

			// A preview asked to reach a target owes the same explanation a
			// real run gives. The tool description tells an assistant to read
			// `stopped_because` and relay the reason, so leaving it off a
			// preview meant the assistant either said nothing about the target
			// or invented a reason. The real-run path sets this below; a dry
			// run returns before reaching it. (QA #306, issue 3)
			if ( isset( $context['target_score'] ) ) {
				$out['stopped_because'] = __( 'A target score was requested, but this was a preview — no changes were applied and the target was not chased.', 'xspeed' );
			}

			return $out;
		}

		// Nothing to do is a real answer, and a common one on a site that has
		// already been tuned. Returning early avoids spending two benchmarks
		// to prove we changed nothing.
		if ( array() === $plan['steps'] ) {
			// The case that most needs a diagnosis attached. "Nothing to do"
			// on a site scoring 50 is not an answer — it is the start of the
			// conversation about what is actually wrong and who can fix it.
			// The score carries this answer entirely. "Nothing to do" is only
			// useful next to a number that is true NOW — beside a stale one it
			// is indistinguishable from "we looked a fortnight ago".
			$diagnosis = Optimize_Diagnosis::build( $current, self::score_for( $measure ) );

			// This path fires the seam too. It is the case a multi-round tuner
			// most needs to see: nothing left at THIS tier, with a score that
			// says whether the target was reached. Returning early without
			// filtering would hide the "already tuned and still short" state —
			// the one where a listener has to decide between escalating the
			// tier and stopping honestly.
			return self::filter_report(
				array(
					'before'    => null,
					'applied'   => array(),
					'skipped'   => $plan['skipped'],
					'reverted'  => array(),
					'after'     => null,
					'score'     => $diagnosis['score'],
					'next_steps' => $diagnosis['agent_fixable'],
					'unfixable' => $diagnosis['human_fixable'],
					'verified'  => true,
					'message'   => self::summary( $diagnosis, 0 ),
					'rounds'    => $round,
				),
				$context
			);
		}

		// --- 2. Measure -------------------------------------------------
		$before   = self::measure();
		$baseline = Optimize_Verifier::sample( $url );
		if ( is_wp_error( $baseline ) ) {
			// No baseline means no way to tell a broken page from a working
			// one. Refusing to start is the only safe option — running blind
			// is exactly what this feature exists to stop.
			return new \WP_Error(
				'xspeed_optimize_no_baseline',
				sprintf(
					/* translators: %s: the underlying error */
					__( 'Could not load the site to take a baseline, so no changes were made: %s', 'xspeed' ),
					$baseline->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		// --- 3-4. Apply + verify ---------------------------------------
		$result = Optimizer::run(
			$plan['steps'],
			$baseline,
			array(
				'apply'  => static function ( array $step ): ?string {
					return self::write( (string) $step['module'], (array) $step['values'] );
				},
				'revert' => static function ( array $step, array $previous ): void {
					self::write( (string) $step['module'], $previous );
				},
				'purge'  => static function (): void {
					Cache::purge_all( 'optimize run' );
				},
				'sample' => static function () use ( $url ) {
					$s = Optimize_Verifier::sample( $url );
					return is_wp_error( $s ) ? null : $s;
				},
				// The per-step timing probe: one uncached render, its
				// wall-clock in ms. Uses the same fetch as the integrity
				// sample so both measure the same thing; the Optimizer
				// medians PERF_SAMPLES of these per state and reverts a
				// step that regresses past the noise floor. (#310)
				'time'   => static function () use ( $url ) {
					$s = Optimize_Verifier::sample( $url );
					if ( is_wp_error( $s ) || ! isset( $s['elapsed_ms'] ) ) {
						return null;
					}
					return (float) $s['elapsed_ms'];
				},
			),
			$budget
		);

		// --- 5. Re-measure ----------------------------------------------
		$after = self::measure();

		// Re-read settings: what is still off AFTER this run is what an
		// aggressive run could try next, and offering a step we just applied
		// would be nonsense.
		// Changes landed, so the stored score now describes a site that no
		// longer exists. This is the one path where staleness is guaranteed
		// rather than likely, so the age check is skipped instead of asking
		// whether six hours have passed.
		//
		// The COOLDOWN still applies, though — which is what the previous
		// form got wrong. It passed `'always'`, and `'always'` means $force,
		// which skips the cooldown as well: so every run that applied
		// anything spent a measurement, on the default `auto`, ignoring the
		// rate limit the manual promises. The nothing-to-do path was the only
		// one actually held back, i.e. the case that matters least.
		// A run inside the cooldown now falls back to the stored number with
		// its age attached rather than billing the quota. (#306 QA issue 1)
		$diagnosis = Optimize_Diagnosis::build(
			self::current_settings(),
			self::score_for( $measure, true )
		);

		$report = array(
			'before'     => $before,
			'applied'    => $result['applied'],
			'skipped'    => array_merge( $plan['skipped'], $result['skipped'] ),
			'reverted'   => $result['reverted'],
			'after'      => $after,
			'score'      => $diagnosis['score'],
			'next_steps' => $diagnosis['agent_fixable'],
			'unfixable'  => $diagnosis['human_fixable'],
			'verified'   => array() === $result['reverted'],
			'message'    => self::summary( $diagnosis, count( $result['applied'] ) ),
			'rounds'     => $round,
		);

		// What `verified` does NOT cover, said out loud, next to the field it
		// qualifies. Optimize_Verifier reads HTML in PHP: it catches a fatal, a
		// truncated document, a stylesheet that vanished. It cannot execute
		// JavaScript, so a page can arrive structurally perfect and still be
		// broken in a browser — removing jQuery Migrate did exactly that, with
		// intact HTML and `e.indexOf is not a function` at runtime.
		//
		// The caller usually CAN look: an AI assistant driving this has a
		// browser, and a person has one by definition. Nothing was asking them
		// to. These two fields are the ask, and they are only attached when a
		// run actually changed something — a pass that applied nothing has
		// nothing new to check.
		if ( array() !== $result['applied'] ) {
			$report['verify_urls'] = self::verify_urls( $url );
			$report['verify_note'] = __( 'Changes were applied and the HTML checks passed, which does not prove the page works in a browser — those checks cannot run JavaScript. Open these URLs and confirm each renders correctly with no console errors. If you cannot open them, tell the user to check them and what a problem would look like.', 'xspeed' );
		}

		return self::filter_report( $report, $context );
	}

	/**
	 * Fire the report seam.
	 *
	 * Both exit paths that produce a report go through here — the applied-run
	 * one and the nothing-to-do one — so a listener cannot silently miss half
	 * the outcomes.
	 *
	 * @param array<string,mixed> $report  The finished report.
	 * @param array<string,mixed> $context Seam context — see the filter docblock below.
	 * @return array<string,mixed>
	 */
	private static function filter_report( array $report, array $context ): array {
		/**
		 * Filter the finished report, allowing a listener to run further rounds.
		 *
		 * The seam exists because one pass cannot chase a target score. This
		 * pass applies every step the tier allows and stops; whether that got
		 * anywhere near 90 is unknown until it is measured, and acting on the
		 * answer means planning again against the metric that is actually
		 * weak. That loop is Pro's (FEATURES.md, Score card #9) — but it must
		 * not require forking this file, so it hooks here.
		 *
		 * A listener is expected to RE-ENTER this method for each additional
		 * round and merge the results, rather than reimplementing the apply
		 * loop. Everything that makes a round safe — baseline sampling,
		 * one-step-at-a-time application, revert-on-breakage, the budget
		 * check — lives in Optimizer::run() and is not worth a second
		 * implementation that can drift out of agreement with this one.
		 *
		 * Re-entrancy is the listener's problem to handle: unhook before
		 * recursing, or guard on `$context['round']`, or this filter fires
		 * again inside its own callback and recurses without end.
		 *
		 * Whatever a listener returns is what callers see, so it must keep the
		 * report's shape and its honesty — `unfixable` in particular is the
		 * part that stops a tuner claiming a win it did not make.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string,mixed> $report  The finished report.
		 * @param array<string,mixed> $context {
		 *     What a further round needs to plan and run.
		 *
		 *     @type string $aggressiveness Tier this run used.
		 *     @type string $measure_score  The caller's measure_score.
		 *     @type int    $budget_seconds Per-round time budget.
		 *     @type string $url            URL sampled for verification.
		 *     @type int    $round          1-based index of the round just finished.
		 *     @type int    $target_score   Optional. Score the caller asked to reach.
		 *                                  Free does not act on it; it is carried
		 *                                  here so a listener can. Absent unless the
		 *                                  caller sent it.
		 *     @type int    $max_rounds     Optional. Ceiling the caller asked for,
		 *                                  same arrangement.
		 * }
		 */
		$filtered = apply_filters(
			'xspeed_optimize_report',
			$report,
			$context
		);

		// A listener that returns a non-array — or nothing, the easy mistake in
		// a filter callback written as an action — must not turn a completed
		// run into a fatal downstream. Fall back to the unfiltered report.
		//
		// An EMPTY array is the same mistake wearing a different hat: it is
		// technically an array, so it passed this guard and blanked the whole
		// report, losing what was applied on a run that genuinely changed the
		// site. A listener with nothing to add returns the report it was
		// given; one that returns nothing at all has failed, and the honest
		// answer is our own report rather than silence. (#306 QA, minor 1)
		$out = ( is_array( $filtered ) && array() !== $filtered ) ? $filtered : $report;

		// The caller asked to reach a score and nothing chased it: no listener
		// is installed, so this was a single pass. Say so. The tool
		// description tells an assistant to read `stopped_because` and relay
		// it, and nothing ever set it — leaving the assistant to either stay
		// silent about the target or invent a reason (#306 review, issue 2).
		// Only filled when still absent, so a tuner's own reason always wins.
		if ( isset( $context['target_score'] ) && ! isset( $out['stopped_because'] ) ) {
			$out['stopped_because'] = __( 'A target score was requested, but no iterative tuner is installed, so this was a single pass and the target was not chased.', 'xspeed' );
		}

		return $out;
	}

	/**
	 * A page exercising a third template — the shop where WooCommerce is
	 * active, otherwise the blog/posts archive, otherwise a category archive.
	 *
	 * Returns '' when none resolves; verify_urls() drops empties.
	 */
	private static function third_template_url(): string {
		if ( function_exists( 'wc_get_page_id' ) ) {
			$shop = wc_get_page_id( 'shop' );
			if ( $shop > 0 ) {
				$link = get_permalink( (int) $shop );
				if ( is_string( $link ) && '' !== $link ) {
					return $link;
				}
			}
		}

		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page > 0 ) {
			$link = get_permalink( $posts_page );
			if ( is_string( $link ) && '' !== $link ) {
				return $link;
			}
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'number'     => 1,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'hide_empty' => true,
			)
		);
		if ( is_array( $terms ) && ! empty( $terms[0] ) && ! is_wp_error( $terms[0] ) ) {
			$link = get_term_link( $terms[0] );
			if ( is_string( $link ) && '' !== $link ) {
				return $link;
			}
		}

		return '';
	}

	/**
	 * A short list of pages worth looking at after changes land.
	 *
	 * Verification sampled ONE url — the home page — because that is enough to
	 * catch a fatal. It is not enough to catch a broken template: combining CSS
	 * or deferring JS can leave the front page perfect and wreck a single post,
	 * an archive, or a shop page, because those load handles the home page
	 * never enqueued.
	 *
	 * So this returns the sampled page plus a couple of pages that exercise
	 * DIFFERENT templates. Kept to three: a list long enough to feel like
	 * homework gets skipped, and the point is that someone actually looks.
	 *
	 * @param string $sampled The URL verification already sampled.
	 * @return array<int,string>
	 */
	private static function verify_urls( string $sampled ): array {
		$urls = array( $sampled );

		// A single post exercises the post template and its assets, which is
		// where combine/defer breakage usually shows first.
		$posts = get_posts(
			array(
				'numberposts'      => 1,
				'post_status'      => 'publish',
				'suppress_filters' => false,
				'fields'           => 'ids',
			)
		);
		if ( ! empty( $posts ) ) {
			$link = get_permalink( (int) $posts[0] );
			if ( is_string( $link ) && '' !== $link ) {
				$urls[] = $link;
			}
		}

		// A third template, and the one most likely to break differently: a
		// shop page loads WooCommerce's own handles, an archive loads the
		// theme's list template. Without this the list was always exactly two
		// — the docblock above promised a spread and named shop pages
		// specifically, and a WooCommerce site never saw one (#306 review,
		// issue 4).
		$urls[] = self::third_template_url();

		// Cap OUR OWN suggestions before filtering, not the filtered result.
		// The cap ran last, so on a site where three defaults already resolve
		// — any WooCommerce site — everything a filter added landed past the
		// limit and was silently dropped. The documented example is a shop's
		// checkout page: the template most likely to break when scripts are
		// combined, and the one the cap threw away. (#306 QA issue 4)
		//
		// Cleaned BEFORE capping, so the limit counts real, distinct pages.
		// third_template_url() returns '' when nothing resolves, and the
		// sampled URL can equal the shop or posts page — capping the raw list
		// let a placeholder or a duplicate burn a slot that nothing refills.
		$urls = array_slice( array_values( array_unique( array_filter( $urls ) ) ), 0, self::VERIFY_URL_LIMIT );

		/**
		 * Filter the pages an optimize run asks the caller to check.
		 *
		 * A site whose risky template is a checkout, a login, or a builder
		 * landing page knows that better than this does.
		 *
		 * Receives our suggestions already capped, and whatever it returns is
		 * what the caller is asked to check — the filter is the site owner's
		 * final say, so it is not re-capped afterwards. Return a short list:
		 * the point is pages someone will actually open.
		 *
		 * @since 1.2.0
		 *
		 * @param array<int,string> $urls    Suggested URLs, already capped.
		 * @param string            $sampled The URL verification sampled.
		 */
		$urls = apply_filters( 'xspeed_optimize_verify_urls', $urls, $sampled );

		$clean = array();
		foreach ( (array) $urls as $u ) {
			$u = esc_url_raw( (string) $u );
			if ( '' !== $u && ! in_array( $u, $clean, true ) ) {
				$clean[] = $u;
			}
		}

		return $clean;
	}

	/**
	 * The score to diagnose against, honouring the caller's measure_score.
	 *
	 * `never` is the pre-1.2.0 behaviour and stays available for callers that
	 * genuinely must not spend a measurement. `auto` measures only when the
	 * stored score is stale AND the cooldown allows it, which is what makes a
	 * post-run score mean what a reader assumes it means.
	 *
	 * @param string $measure      One of auto|never|always.
	 * @param bool   $assume_stale Treat the stored score as stale regardless
	 *                             of its age — the post-apply path, where
	 *                             changes just landed. Does NOT spend the
	 *                             cooldown: only an explicit `always` does
	 *                             that. (#306 QA issue 1)
	 * @return array<string,mixed>|null
	 */
	private static function score_for( string $measure, bool $assume_stale = false ): ?array {
		if ( 'never' === $measure ) {
			return Optimize_Diagnosis::latest_score();
		}
		return Optimize_Diagnosis::measure_fresh( 'always' === $measure, $assume_stale );
	}

	/**
	 * One sentence the assistant can lead with.
	 *
	 * Written so the honest outcomes read as outcomes rather than failures. A
	 * site where nothing was left to do is not a disappointing result, but
	 * "no changes" with no context reads like one — and an assistant given
	 * that alone will either apologise or invent a win.
	 *
	 * @param array<string,mixed> $diagnosis From Optimize_Diagnosis::build().
	 * @param int                 $applied   How many changes landed.
	 * @param int                 $planned   How many changes are WAITING —
	 *                                       non-zero only on a preview, where
	 *                                       nothing is applied by definition.
	 */
	private static function summary( array $diagnosis, int $applied, int $planned = 0 ): string {
		$score = $diagnosis['score']['score'] ?? null;
		$next  = count( $diagnosis['agent_fixable'] );
		$human = count( $diagnosis['human_fixable'] );

		$parts = array();

		if ( $applied > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of settings changed */
				_n( 'Applied %d change.', 'Applied %d changes.', $applied, 'xspeed' ),
				$applied
			);
		} elseif ( $planned > 0 ) {
			// A preview applies nothing BY DEFINITION, so "nothing was
			// applied" must not be read as "nothing needed applying". This
			// sentence is what an assistant relays to the owner, and it
			// previously opened with "everything is already on" directly above
			// a list of ten changes it would make — so the assistant reported
			// a fully optimised site while the work sat waiting.
			// (#306 QA issue 3)
			$parts[] = sprintf(
				/* translators: %d: number of changes a real run would make */
				_n(
					'Preview only — %d change would be applied.',
					'Preview only — %d changes would be applied.',
					$planned,
					'xspeed'
				),
				$planned
			);
		} else {
			$parts[] = __( 'Everything that can be turned on safely is already on.', 'xspeed' );
		}

		if ( null !== $score ) {
			// The age is not a footnote. This sentence is what an assistant
			// reads back to the user, and "score: 77" after a run that just
			// finished says the run produced it. Naming when it was measured
			// is the difference between a report and a claim.
			$age = $diagnosis['score']['age_seconds'] ?? null;

			if ( null === $age ) {
				$when = __( 'date unknown', 'xspeed' );
			} elseif ( $age < 5 * MINUTE_IN_SECONDS ) {
				$when = __( 'measured just now', 'xspeed' );
			} else {
				$when = sprintf(
					/* translators: %s: human-readable duration, e.g. "2 hours" */
					__( 'measured %s ago', 'xspeed' ),
					human_time_diff( time() - $age, time() )
				);
			}

			$parts[] = sprintf(
				/* translators: 1: performance score, 2: when it was measured */
				__( 'Score: %1$d (%2$s).', 'xspeed' ),
				(int) $score,
				$when
			);
		}

		if ( $next > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of riskier settings available */
				_n(
					'%d further setting could help, but can break some sites — ask before enabling it.',
					'%d further settings could help, but can break some sites — ask before enabling them.',
					$next,
					'xspeed'
				),
				$next
			);
		}

		if ( $human > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of problems only the user can fix */
				_n(
					'%d problem is outside what caching can reach.',
					'%d problems are outside what caching can reach.',
					$human,
					'xspeed'
				),
				$human
			);
		}

		return implode( ' ', $parts );
	}

	/**
	 * Current settings for every module the plan can touch.
	 *
	 * The `__global` bucket is not a module: it carries options that live
	 * outside the per-module schema, page caching being the one that matters
	 * here. Reading it the same shape as a module keeps Optimize_Plan free of
	 * special cases.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function current_settings(): array {
		$out = array();
		foreach ( array( 'gzip', 'browser-cache', 'minify', 'lazy', 'bloat' ) as $slug ) {
			$out[ $slug ] = Settings_Manager::get( $slug );
		}

		$global = Settings::get();
		$out[ Optimize_Plan::MODULE_GLOBAL ] = array(
			'cache_enabled' => (bool) ( $global['cache_enabled'] ?? false ),
		);

		return $out;
	}

	/**
	 * Write one step's values to wherever they actually live.
	 *
	 * Page caching is not a module setting — it installs the advanced-cache
	 * drop-in and sets WP_CACHE, then records a global flag. Routing it
	 * through Settings_Manager::update() writes a key no schema declares,
	 * which is dropped silently while the call still reports success (#206):
	 * the run then claims "page caching on" over a site that never enabled it.
	 * That exact false success showed up on the first live run of this
	 * feature, which is why the dispatch is explicit rather than uniform.
	 *
	 * @param string              $module Module slug, or MODULE_GLOBAL.
	 * @param array<string,mixed> $values Values to write.
	 * @return string|null Reason the write was refused, or null when it landed.
	 */
	private static function write( string $module, array $values ): ?string {
		if ( Optimize_Plan::MODULE_GLOBAL !== $module ) {
			Settings_Manager::update( $module, $values );
			return null;
		}

		if ( array_key_exists( 'cache_enabled', $values ) ) {
			$enabled = (bool) $values['cache_enabled'];
			// Order matters: the drop-in + wp-config first, the flag second,
			// so a failure to install never leaves the option claiming a
			// cache that is not wired up. Persist what toggle() achieved, not
			// what was asked for — it refuses when another plugin owns the
			// drop-in, and the flag must follow the refusal.
			$state = Cache::toggle( $enabled );
			// And REPORT the refusal. Swallowing it here is what let the run
			// return "Turn on page caching · verified" for a site where the
			// drop-in belonged to another plugin and nothing had been
			// changed. The Optimizer turns a returned reason into a skipped
			// step.
			//
			// `blocked` alone is the test. It used to also require the
			// operational state to differ from what was asked, and `enabled`
			// answers "is the cache serving", not "did the write land" — so a
			// refused step whose outcome happened to match was recorded as
			// verified with nothing persisted behind it.
			if ( ! empty( $state['blocked'] ) ) {
				return is_string( $state['blocked_reason'] ) && '' !== $state['blocked_reason']
					? $state['blocked_reason']
					: __( 'xSpeed would not change the page cache on this site.', 'xspeed' );
			}
		}

		return null;
	}

	/**
	 * A cached-vs-uncached benchmark, reduced to the numbers a report needs.
	 *
	 * Deliberately NOT a Lighthouse score: this runs on the site itself, and
	 * spending someone's PageSpeed quota twice per optimize run is not ours to
	 * do. The caller can run a speed test either side if it wants one.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function measure(): ?array {
		if ( ! class_exists( '\XSpeed\Cache_Benchmark' ) ) {
			return null;
		}
		$run = Cache_Benchmark::run();
		if ( ! is_array( $run ) ) {
			return null;
		}
		return array(
			'savings_ms'    => $run['savings_ms'] ?? null,
			'savings_pct'   => $run['savings_pct'] ?? null,
			'cache_enabled' => $run['cache_enabled'] ?? null,
		);
	}

	/**
	 * Problems this tool cannot solve, named plainly.
	 *
	 * Derived from the site's own health checks rather than invented here, so
	 * the list stays true as those checks improve. Anything a caching plugin
	 * genuinely cannot reach — page weight, hotlinked media, DOM size — belongs
	 * here rather than being silently omitted from a success report.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function unfixable(): array {
		$out = array();

		if ( ! class_exists( '\XSpeed\Health' ) ) {
			return $out;
		}

		foreach ( Health::checks() as $check ) {
			if ( 'warn' !== ( $check['tone'] ?? '' ) && 'fail' !== ( $check['tone'] ?? '' ) ) {
				continue;
			}
			// Environment facts the plugin reports but cannot change itself:
			// a PHP version, a server config snippet the host must paste.
			$id = (string) ( $check['id'] ?? '' );
			if ( in_array( $id, array( 'php_version', 'server', 'static_rewrite_nginx' ), true ) ) {
				$out[] = array(
					'issue' => (string) ( $check['label'] ?? $id ),
					'fix'   => (string) ( $check['detail'] ?? '' ),
				);
			}
		}

		return $out;
	}
}
