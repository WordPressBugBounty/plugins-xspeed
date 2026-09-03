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
 * @since 1.2.0
 */
final class Optimize_Runner {

	/**
	 * Run the whole thing.
	 *
	 * @param array{aggressiveness?:string,dry_run?:bool,budget_seconds?:int,url?:string} $args Options.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function run( array $args = array() ) {
		$aggressiveness = (string) ( $args['aggressiveness'] ?? Optimize_Plan::TIER_STANDARD );
		$dry_run        = (bool) ( $args['dry_run'] ?? false );
		$budget         = (int) ( $args['budget_seconds'] ?? 120 );
		$url            = (string) ( $args['url'] ?? home_url( '/' ) );

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
			$preview = Optimize_Diagnosis::build( $current );
			return array(
				'dry_run'   => true,
				'message'   => self::summary( $preview, 0 ),
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
		}

		// Nothing to do is a real answer, and a common one on a site that has
		// already been tuned. Returning early avoids spending two benchmarks
		// to prove we changed nothing.
		if ( array() === $plan['steps'] ) {
			// The case that most needs a diagnosis attached. "Nothing to do"
			// on a site scoring 50 is not an answer — it is the start of the
			// conversation about what is actually wrong and who can fix it.
			$diagnosis = Optimize_Diagnosis::build( $current );
			return array(
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
		$diagnosis = Optimize_Diagnosis::build( self::current_settings() );

		return array(
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
		);
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
	 */
	private static function summary( array $diagnosis, int $applied ): string {
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
		} else {
			$parts[] = __( 'Everything that can be turned on safely is already on.', 'xspeed' );
		}

		if ( null !== $score ) {
			$parts[] = sprintf(
				/* translators: %d: last recorded performance score */
				__( 'Last recorded score: %d.', 'xspeed' ),
				(int) $score
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
