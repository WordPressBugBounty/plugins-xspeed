<?php
/**
 * Optimizer — the autopilot loop.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * Apply an optimization plan one step at a time, verifying after each.
 *
 * The loop is deliberately boring, because the interesting part is what it
 * refuses to do:
 *
 *  - **One change at a time.** A batch that fails tells you nothing about
 *    which setting did it, and leaves you reverting work that was fine.
 *  - **Purge before verifying.** No settings change purges the page cache on
 *    its own (#205), so a check run against an unpurged site verifies the page
 *    as it was BEFORE the change and passes anything.
 *  - **Revert only the failing step.** A break at step 9 must not discard the
 *    eight verified wins before it, and must not abort the four steps after.
 *  - **Never leave a step applied-but-unverified.** If sampling itself fails,
 *    that is not permission to assume success.
 *
 * Every side effect is injected rather than called directly, so the whole loop
 * is unit-testable without a WordPress install or a live site: the tests drive
 * it with an applier that records, and a sampler that breaks on cue.
 *
 * @since 1.2.0
 */
final class Optimizer {

	/**
	 * Timing samples per state (before / after), medians compared.
	 *
	 * One sample cannot drive a decision: on the site that motivated this,
	 * render time across runs with identical config spanned more than 2×.
	 * Three is the floor at which a median starts to mean something without
	 * making each step cost a page-load storm.
	 */
	public const PERF_SAMPLES = 3;

	/**
	 * A regression must clear BOTH of these to trigger a revert — a
	 * relative floor so slow sites aren't reverted over jitter that is
	 * large in ms but small in proportion, and an absolute floor so fast
	 * sites aren't reverted over a 20ms wobble that is huge in percent.
	 * Anything inside the floor reports `unchanged`, never `worse`:
	 * confidently reverting on noise is a worse failure than keeping a
	 * mild regression, because it is invisible and self-assured.
	 */
	public const PERF_REGRESSION_PCT    = 30.0;
	public const PERF_REGRESSION_MIN_MS = 150.0;

	/**
	 * Run a plan.
	 *
	 * @param array<int,array<string,mixed>> $steps    Ordered steps from Optimize_Plan::build().
	 * @param array<string,mixed>            $baseline Sample taken before anything changed.
	 * @param array{
	 *     apply:callable,   // (array $step): ?string — a non-empty string refuses the step
	 *     revert:callable,
	 *     purge:callable,
	 *     sample:callable,
	 *     time?:callable,
	 *     now?:callable
	 * }                                     $io       Injected side effects.
	 * @param int                            $budget_seconds Wall-clock cap; 0 = uncapped.
	 * @return array{applied:array<int,array<string,mixed>>,reverted:array<int,array<string,mixed>>,skipped:array<int,array<string,string>>}
	 */
	public static function run( array $steps, array $baseline, array $io, int $budget_seconds = 0 ): array {
		$apply  = $io['apply'];
		$revert = $io['revert'];
		$purge  = $io['purge'];
		$sample = $io['sample'];
		// Optional timing probe: returns one render time in ms, or null.
		// Kept separate from `sample` so the integrity contract (exactly
		// one sample per step) is untouched, and a caller without a probe
		// gets the old behavior with every verdict `unknown`.
		$time = isset( $io['time'] ) && is_callable( $io['time'] ) ? $io['time'] : null;
		$now    = $io['now'] ?? static function () {
			return time();
		};

		$started  = (int) $now();
		$applied  = array();
		$reverted = array();
		$skipped  = array();

		foreach ( $steps as $i => $step ) {
			// Budget check BEFORE applying, never mid-step: stopping between
			// "wrote the setting" and "verified it" is the one state this
			// loop must never end in.
			if ( $budget_seconds > 0 && ( (int) $now() - $started ) >= $budget_seconds ) {
				foreach ( array_slice( $steps, $i ) as $rest ) {
					$skipped[] = array(
						'id'  => (string) $rest['id'],
						'why' => __( 'Ran out of time before this step.', 'xspeed' ),
					);
				}
				break;
			}

			$before = self::snapshot_of( $step );

			// Pre-change timing baseline for THIS step. Taken fresh each
			// step rather than reused from the last one, because the
			// previous step just changed the site.
			$pre_perf = null !== $time ? self::collect_times( $time, self::PERF_SAMPLES ) : null;

			/*
			 * `apply` may refuse. Page caching is the one step whose write is
			 * gated on shared state another plugin can own — Cache::toggle()
			 * returns a reason and changes nothing. Reporting that step as
			 * applied-and-verified told the user the opposite of what
			 * happened: the run claimed "Turn on page caching · verified"
			 * over a site whose cache was still off.
			 *
			 * A refusal is a skip, not a revert: nothing was written, so
			 * there is nothing to undo, nothing to purge, and no sample to
			 * spend.
			 */
			$refusal = $apply( $step );
			if ( is_string( $refusal ) && '' !== $refusal ) {
				$skipped[] = array(
					'id'  => (string) $step['id'],
					'why' => $refusal,
				);
				continue;
			}
			$purge();

			$current = $sample();

			// A sample we could not take is NOT a pass. Treating an
			// unreachable site as "probably fine" is how an autopilot leaves
			// a site broken and reports success.
			if ( ! is_array( $current ) ) {
				$revert( $step, $before );
				$purge();
				$reverted[] = array(
					'id'  => (string) $step['id'],
					'why' => __( 'Could not load the page to check it, so the change was undone.', 'xspeed' ),
				);
				continue;
			}

			$check = Optimize_Verifier::compare( $baseline, $current );
			if ( ! $check['ok'] ) {
				$revert( $step, $before );
				$purge();
				$reverted[] = array(
					'id'  => (string) $step['id'],
					'why' => implode( ' ', $check['failures'] ),
				);
				continue;
			}

			// Integrity holds — now ask whether the change HELPED. "Verified"
			// used to stop at "the HTML still renders", which kept a change
			// that made the site measurably slower and reported it as a win.
			// (#310 — defer_js regressed TBT on every measured run and was
			// marked verified.)
			$post_perf   = null !== $time ? self::collect_times( $time, self::PERF_SAMPLES ) : null;
			$measurement = self::measure( $pre_perf, $post_perf );

			if ( 'worse' === $measurement['improved'] ) {
				$revert( $step, $before );
				$purge();
				$reverted[] = array(
					'id'          => (string) $step['id'],
					'why'         => sprintf(
						/* translators: 1: median render time before, 2: after, 3: percent change. */
						__( 'The page got measurably slower: median render time %1$dms before, %2$dms after (+%3$d%%), beyond what run-to-run noise explains. The change was undone.', 'xspeed' ),
						(int) $measurement['before_ms'],
						(int) $measurement['after_ms'],
						(int) $measurement['change_pct']
					),
					'measurement' => $measurement,
				);
				continue;
			}

			$applied[] = array(
				'id'          => (string) $step['id'],
				'change'      => (string) $step['label'],
				// Two separate claims where there used to be one. `renders`
				// is the integrity check (what `verified` really meant);
				// `improved` is the measured performance verdict —
				// better / unchanged / unknown here, since `worse` was
				// reverted above. Neither implies the other.
				'renders'     => true,
				'improved'    => $measurement['improved'],
				'measurement' => $measurement,
				// Kept for consumers reading the old field; means renders.
				'verified'    => true,
			);
		}

		return array(
			'applied'  => $applied,
			'reverted' => $reverted,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Run the timing probe N times and reduce to a median + spread.
	 *
	 * @param callable $time Probe returning one render time in ms, or null.
	 * @return array{median_ms:float,spread_ms:float,samples:int}|null Null when no probe run returned a number.
	 */
	private static function collect_times( callable $time, int $n ): ?array {
		$times = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$t = $time();
			if ( is_numeric( $t ) && (float) $t > 0 ) {
				$times[] = (float) $t;
			}
		}
		if ( array() === $times ) {
			return null;
		}
		sort( $times );
		$count  = count( $times );
		$middle = (int) floor( $count / 2 );
		$median = ( 0 === $count % 2 )
			? ( $times[ $middle - 1 ] + $times[ $middle ] ) / 2
			: $times[ $middle ];

		return array(
			'median_ms' => $median,
			'spread_ms' => $times[ $count - 1 ] - $times[0],
			'samples'   => $count,
		);
	}

	/**
	 * The performance verdict for one step.
	 *
	 * `worse` only when the after-median regresses past BOTH noise floors;
	 * symmetric rule for `better`; inside the floor is `unchanged`; and a
	 * state we could not time is `unknown` — never a guess in either
	 * direction. The raw medians and spreads ride along so the verdict is
	 * auditable rather than an oracle.
	 *
	 * @param array{median_ms:float,spread_ms:float,samples:int}|null $pre  Before the change.
	 * @param array{median_ms:float,spread_ms:float,samples:int}|null $post After it.
	 * @return array{improved:string,before_ms:float|null,after_ms:float|null,before_spread_ms:float|null,after_spread_ms:float|null,change_pct:float|null,metric:string}
	 */
	private static function measure( ?array $pre, ?array $post ): array {
		$out = array(
			'metric'           => 'render_time',
			'improved'         => 'unknown',
			'before_ms'        => null !== $pre ? round( $pre['median_ms'] ) : null,
			'after_ms'         => null !== $post ? round( $post['median_ms'] ) : null,
			'before_spread_ms' => null !== $pre ? round( $pre['spread_ms'] ) : null,
			'after_spread_ms'  => null !== $post ? round( $post['spread_ms'] ) : null,
			'change_pct'       => null,
		);
		if ( null === $pre || null === $post || $pre['median_ms'] <= 0 ) {
			return $out;
		}

		$delta             = $post['median_ms'] - $pre['median_ms'];
		$pct               = 100.0 * $delta / $pre['median_ms'];
		$out['change_pct'] = round( $pct, 1 );

		$past_floor = abs( $delta ) >= self::PERF_REGRESSION_MIN_MS
			&& abs( $pct ) >= self::PERF_REGRESSION_PCT;

		if ( ! $past_floor ) {
			$out['improved'] = 'unchanged';
		} elseif ( $delta > 0 ) {
			$out['improved'] = 'worse';
		} else {
			$out['improved'] = 'better';
		}

		return $out;
	}

	/**
	 * The values to put back if this step has to be undone.
	 *
	 * Every step in the catalog turns something ON, so the inverse is the
	 * same keys set to false. Kept as its own method so a future step whose
	 * inverse is not simply `false` has one obvious place to say so, rather
	 * than the revert path quietly writing the wrong thing.
	 *
	 * @param array<string,mixed> $step Step definition.
	 * @return array<string,mixed>
	 */
	private static function snapshot_of( array $step ): array {
		$out = array();
		foreach ( (array) $step['values'] as $key => $value ) {
			$out[ $key ] = is_bool( $value ) ? ! $value : false;
		}
		return $out;
	}
}
