<?php
/**
 * Score — external performance scores on the dashboard (issue #47).
 *
 * Users judge a caching plugin by its GTmetrix or PageSpeed score whether
 * or not the plugin shows one, so the number belongs next to the internal
 * TTFB benchmark rather than one tab away. Both live on the same timeline:
 * "expiry raised → TTFB fell → score climbed" is the story the dashboard
 * exists to tell, and it can't be told from two different tools.
 *
 * Two providers, deliberately different in shape:
 *
 *   psi       Google PageSpeed Insights v5. Synchronous, and works with NO
 *             key (Google rate-limits anonymous callers). A key raises the
 *             quota; it is optional, not required.
 *   gtmetrix  GTmetrix API v2. Asynchronous by design — you start a test,
 *             it queues, and you poll. Requires an API key; there is no
 *             anonymous mode.
 *
 * Outbound HTTP is opt-in and user-initiated: nothing here runs unless the
 * module is enabled AND someone presses Test / runs the command. There is
 * no schedule, no background call, no telemetry — see readme.txt "External
 * services".
 *
 * Storage: `xspeed_score_history`, capped, autoload off. The row shape
 * matches what Pro's Pagespeed engine already returns (ok/status/url/
 * strategy/score/metrics/issues) so a Pro run and a Free run are the same
 * kind of row and the history stays one series.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Score {

	public const HISTORY_OPTION = 'xspeed_score_history';
	public const PENDING_OPTION = 'xspeed_score_pending';

	/** Runs kept. Enough for a trend, small enough to stay a single option. */
	public const MAX_HISTORY = 30;

	public const PSI_ENDPOINT      = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
	public const GTMETRIX_ENDPOINT = 'https://gtmetrix.com/api/2.0/tests';

	/** Seconds allowed for the PSI call. Lighthouse runs are genuinely slow. */
	public const PSI_TIMEOUT = 60;

	/**
	 * How long a queued GTmetrix test may stay pending before we give up on
	 * it. Without a ceiling, a start that never resolves leaves a marker
	 * that polls a third-party API on every status check, forever.
	 */
	public const PENDING_MAX_AGE = 1800;

	/* ------------------------------------------------------------------ */
	/* PageSpeed Insights                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Build the PSI request URL.
	 *
	 * Pure, and separated from the call so the query — especially "no key
	 * means no key parameter", not `key=` — is testable without network.
	 */
	public static function psi_url( string $url, string $strategy, string $api_key = '' ): string {
		$query = array(
			'url'      => $url,
			'strategy' => 'desktop' === strtolower( $strategy ) ? 'desktop' : 'mobile',
			'category' => 'performance',
		);
		if ( '' !== trim( $api_key ) ) {
			$query['key'] = trim( $api_key );
		}
		return self::PSI_ENDPOINT . '?' . http_build_query( $query );
	}

	/**
	 * Pull the score and Core Web Vitals out of a PSI envelope.
	 *
	 * Public so tests can drive it with canned fixtures — the parsing, not
	 * the HTTP, is where this breaks when Google reshuffles the response.
	 *
	 * @param array $envelope Decoded PSI JSON.
	 * @return array<string,mixed>
	 */
	public static function parse_psi( string $url, string $strategy, array $envelope ): array {
		$lh     = isset( $envelope['lighthouseResult'] ) && is_array( $envelope['lighthouseResult'] ) ? $envelope['lighthouseResult'] : array();
		$audits = isset( $lh['audits'] ) && is_array( $lh['audits'] ) ? $lh['audits'] : array();

		$raw_score = isset( $lh['categories']['performance']['score'] ) ? $lh['categories']['performance']['score'] : null;
		// PSI reports 0-1; a missing score is null, NOT zero — "we didn't get
		// a score" and "your score is 0" are very different news.
		$score = is_numeric( $raw_score ) ? (int) round( ( (float) $raw_score ) * 100 ) : null;

		return array(
			'ok'       => true,
			'provider' => 'psi',
			'ts'       => time(),
			'url'      => $url,
			'strategy' => 'desktop' === strtolower( $strategy ) ? 'desktop' : 'mobile',
			'score'    => $score,
			'metrics'  => array(
				'lcp'  => self::audit_number( $audits, 'largest-contentful-paint' ),
				'fcp'  => self::audit_number( $audits, 'first-contentful-paint' ),
				'cls'  => self::audit_number( $audits, 'cumulative-layout-shift' ),
				'tbt'  => self::audit_number( $audits, 'total-blocking-time' ),
				'si'   => self::audit_number( $audits, 'speed-index' ),
				'ttfb' => self::audit_number( $audits, 'server-response-time' ),
			),
			'issues'   => self::top_issues( $audits ),
			'error'    => '',
		);
	}

	/**
	 * Numeric value of one Lighthouse audit, or null when absent.
	 *
	 * @param array $audits Lighthouse audits keyed by id.
	 */
	private static function audit_number( array $audits, string $id ): ?float {
		if ( ! isset( $audits[ $id ] ) || ! is_array( $audits[ $id ] ) ) {
			return null;
		}
		$value = $audits[ $id ]['numericValue'] ?? null;
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * The opportunities worth showing: biggest measured savings first.
	 *
	 * Capped at 5 — a dashboard card is not an audit report, and a list
	 * nobody reads to the end is a list that buries its own first item.
	 *
	 * @param array $audits Lighthouse audits keyed by id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function top_issues( array $audits ): array {
		$issues = array();
		foreach ( $audits as $id => $audit ) {
			if ( ! is_array( $audit ) ) {
				continue;
			}
			$savings = isset( $audit['details']['overallSavingsMs'] ) && is_numeric( $audit['details']['overallSavingsMs'] )
				? (int) $audit['details']['overallSavingsMs']
				: 0;
			if ( $savings <= 0 ) {
				continue;
			}
			$issues[] = array(
				'id'         => (string) $id,
				'title'      => isset( $audit['title'] ) ? (string) $audit['title'] : (string) $id,
				'savings_ms' => $savings,
			);
		}

		usort(
			$issues,
			static function ( array $a, array $b ): int {
				return $b['savings_ms'] <=> $a['savings_ms'];
			}
		);

		return array_slice( $issues, 0, 5 );
	}

	/**
	 * Run a PageSpeed Insights audit and record it.
	 *
	 * @return array<string,mixed> A history row (ok=false carries `error`).
	 */
	public static function run_psi( string $url, string $strategy = 'mobile', string $api_key = '' ): array {
		$response = wp_remote_get(
			self::psi_url( $url, $strategy, $api_key ),
			array( 'timeout' => self::PSI_TIMEOUT )
		);

		if ( is_wp_error( $response ) ) {
			return self::failure( 'psi', $url, $strategy, $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || ! is_array( $json ) ) {
			$message = is_array( $json ) && isset( $json['error']['message'] )
				? (string) $json['error']['message']
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'PageSpeed Insights returned HTTP %d.', 'xspeed' ),
					$code
				);
			return self::failure( 'psi', $url, $strategy, $message );
		}

		$row = self::parse_psi( $url, $strategy, $json );
		self::record( $row );
		return $row;
	}

	/* ------------------------------------------------------------------ */
	/* GTmetrix                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * The Authorization header GTmetrix v2 expects.
	 *
	 * HTTP Basic with the API key as the username and an EMPTY password —
	 * the trailing colon is load-bearing, and omitting it authenticates as
	 * nobody with a 401 that reads like a bad key.
	 */
	public static function gtmetrix_auth_header( string $api_key ): string {
		return 'Basic ' . base64_encode( trim( $api_key ) . ':' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth encoding, not obfuscation.
	}

	/**
	 * Body for "start a test". GTmetrix speaks JSON:API, so the URL is
	 * nested under data.attributes rather than posted flat.
	 *
	 * Pure — the shape is easy to get subtly wrong and impossible to
	 * notice, because a malformed body returns a generic 400.
	 */
	public static function gtmetrix_start_body( string $url ): string {
		return (string) wp_json_encode(
			array(
				'data' => array(
					'type'       => 'test',
					'attributes' => array( 'url' => $url ),
				),
			)
		);
	}

	/**
	 * Read a GTmetrix test envelope into either a pending marker or a
	 * finished history row.
	 *
	 * The API reports progress through `data.attributes.state`
	 * (queued / started / completed / error). Anything that is not
	 * `completed` is still in flight — treating an unknown state as done
	 * would record a row with no score in it.
	 *
	 * @param array $envelope Decoded GTmetrix JSON.
	 * @return array<string,mixed>
	 */
	public static function parse_gtmetrix( string $url, array $envelope ): array {
		$data       = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
		$attributes = isset( $data['attributes'] ) && is_array( $data['attributes'] ) ? $data['attributes'] : array();
		$state      = isset( $attributes['state'] ) ? (string) $attributes['state'] : '';
		$test_id    = isset( $data['id'] ) ? (string) $data['id'] : '';

		if ( 'error' === $state ) {
			$row          = self::failure(
				'gtmetrix',
				$url,
				'desktop',
				isset( $attributes['error'] ) && '' !== (string) $attributes['error']
					? (string) $attributes['error']
					: __( 'GTmetrix reported the test failed.', 'xspeed' )
			);
			$row['state'] = 'error';
			return $row;
		}

		if ( 'completed' !== $state ) {
			return array(
				'ok'       => true,
				'provider' => 'gtmetrix',
				'state'    => '' === $state ? 'queued' : $state,
				'test_id'  => $test_id,
				'url'      => $url,
				'pending'  => true,
			);
		}

		// GTmetrix reports Performance/Structure as 0-1 and the vitals in
		// milliseconds, with CLS unitless — the same units PSI uses, which
		// is why both providers can share one history shape.
		// GTmetrix has shipped this both ways: API 2.0 returns an integer
		// 0-100, older/other shapes a 0-1 fraction. Scaling unconditionally
		// turned a real 96 into 9600. Treat >1 as already-percent — a genuine
		// fractional score above 1 does not exist.
		$score = self::percent( $attributes['performance_score'] ?? null );

		return array(
			'ok'       => true,
			'provider' => 'gtmetrix',
			'state'    => 'completed',
			'test_id'  => $test_id,
			'ts'       => time(),
			'url'      => $url,
			'strategy' => 'desktop',
			'score'    => $score,
			'metrics'  => array(
				'lcp'  => self::numeric( $attributes['largest_contentful_paint'] ?? null ),
				'fcp'  => self::numeric( $attributes['first_contentful_paint'] ?? null ),
				'cls'  => self::numeric( $attributes['cumulative_layout_shift'] ?? null ),
				'tbt'  => self::numeric( $attributes['total_blocking_time'] ?? null ),
				'si'   => self::numeric( $attributes['speed_index'] ?? null ),
				'ttfb' => self::numeric( $attributes['time_to_first_byte'] ?? null ),
			),
			'issues'   => array(),
			'error'    => '',
		);
	}

	/**
	 * Start a GTmetrix test. Returns the pending marker; the result
	 * arrives via poll_gtmetrix().
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function start_gtmetrix( string $url, string $api_key ) {
		if ( '' === trim( $api_key ) ) {
			return new \WP_Error(
				'xspeed_score_no_key',
				__( 'GTmetrix requires an API key — there is no anonymous mode. Add one in the Score settings.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}

		$response = wp_remote_post(
			self::GTMETRIX_ENDPOINT,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => self::gtmetrix_auth_header( $api_key ),
					'Content-Type'  => 'application/vnd.api+json',
				),
				'body'    => self::gtmetrix_start_body( $url ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || ! is_array( $json ) ) {
			return new \WP_Error(
				'xspeed_score_gtmetrix_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'GTmetrix returned HTTP %d. Check the API key.', 'xspeed' ),
					$code
				),
				array( 'status' => 502 )
			);
		}

		$parsed = self::parse_gtmetrix( $url, $json );
		if ( ! empty( $parsed['test_id'] ) ) {
			update_option(
				self::PENDING_OPTION,
				array(
					'test_id'  => (string) $parsed['test_id'],
					'url'      => $url,
					'started'  => time(),
					'provider' => 'gtmetrix',
				),
				false
			);
		}
		return $parsed;
	}

	/**
	 * Check the in-flight GTmetrix test, recording it if it finished.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function poll_gtmetrix( string $api_key ) {
		$pending = get_option( self::PENDING_OPTION, array() );
		if ( ! is_array( $pending ) || empty( $pending['test_id'] ) ) {
			return array(
				'pending' => false,
				'state'   => 'idle',
			);
		}

		$response = wp_remote_get(
			self::GTMETRIX_ENDPOINT . '/' . rawurlencode( (string) $pending['test_id'] ),
			array(
				'timeout' => 30,
				'headers' => array( 'Authorization' => self::gtmetrix_auth_header( $api_key ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			return new \WP_Error(
				'xspeed_score_gtmetrix_failed',
				__( 'GTmetrix returned an unreadable response.', 'xspeed' ),
				array( 'status' => 502 )
			);
		}

		$parsed = self::parse_gtmetrix( (string) ( $pending['url'] ?? '' ), $json );

		if ( empty( $parsed['pending'] ) ) {
			// Terminal, either way — stop polling a test that has resolved.
			delete_option( self::PENDING_OPTION );
			if ( ! empty( $parsed['ok'] ) ) {
				self::record( $parsed );
			}
		}

		return $parsed;
	}

	/* ------------------------------------------------------------------ */
	/* History                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Append a run. Newest first, capped, autoload off — the history is
	 * only ever read in admin contexts.
	 *
	 * @param array<string,mixed> $row A parsed run.
	 */
	public static function record( array $row ): void {
		// The table is the store. It is created on activation and on
		// admin_init, but record() can run from CLI on a site that has done
		// neither yet, so make sure it exists before writing.
		Score_Store::maybe_install();
		Score_Store::insert( $row, isset( $row['source'] ) ? (string) $row['source'] : 'local' );

		$history = self::history_option();
		array_unshift( $history, $row );
		if ( count( $history ) > self::MAX_HISTORY ) {
			$history = array_slice( $history, 0, self::MAX_HISTORY );
		}

		if ( false === get_option( self::HISTORY_OPTION, false ) ) {
			add_option( self::HISTORY_OPTION, $history, '', 'no' );
			return;
		}
		update_option( self::HISTORY_OPTION, $history );
	}

	/**
	 * Stored runs, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function history(): array {
		Score_Store::maybe_install();
		$rows = Score_Store::history( self::MAX_HISTORY );
		if ( ! empty( $rows ) ) {
			return $rows;
		}
		// Empty table on a site whose migration has not run yet — fall back
		// so the panel never looks like it lost the user's history.
		return self::history_option();
	}

	/**
	 * The legacy option-based history.
	 *
	 * Retained for the one-time migration in Score_Store and as a fallback,
	 * NOT as a second source of truth. Nothing else should call it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function history_option(): array {
		$raw = get_option( self::HISTORY_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $row ) {
			if ( is_array( $row ) && isset( $row['ts'] ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/** Most recent successful run, or null. */
	public static function latest(): ?array {
		foreach ( self::history() as $row ) {
			if ( ! empty( $row['ok'] ) ) {
				return $row;
			}
		}
		return null;
	}

	public static function clear(): void {
		delete_option( self::HISTORY_OPTION );
		delete_option( self::PENDING_OPTION );
	}

	/* ------------------------------------------------------------------ */
	/* Core Web Vitals thresholds                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Google's published Core Web Vitals thresholds, in the units the
	 * metrics arrive in (ms, except CLS which is unitless).
	 *
	 * @return array<string,array{good:float,poor:float}>
	 */
	public static function thresholds(): array {
		return array(
			'lcp'  => array(
				'good' => 2500.0,
				'poor' => 4000.0,
			),
			'fcp'  => array(
				'good' => 1800.0,
				'poor' => 3000.0,
			),
			'cls'  => array(
				'good' => 0.1,
				'poor' => 0.25,
			),
			'tbt'  => array(
				'good' => 200.0,
				'poor' => 600.0,
			),
			'si'   => array(
				'good' => 3400.0,
				'poor' => 5800.0,
			),
			'ttfb' => array(
				'good' => 800.0,
				'poor' => 1800.0,
			),
		);
	}

	/**
	 * Rate one metric good / needs-improvement / poor.
	 *
	 * Returns 'unknown' for a missing value rather than defaulting to
	 * 'poor' — a chip that says "poor" because we have no measurement is a
	 * false alarm someone will chase.
	 */
	public static function rate( string $metric, ?float $value ): string {
		$thresholds = self::thresholds();
		if ( null === $value || ! isset( $thresholds[ $metric ] ) ) {
			return 'unknown';
		}
		if ( $value <= $thresholds[ $metric ]['good'] ) {
			return 'good';
		}
		if ( $value <= $thresholds[ $metric ]['poor'] ) {
			return 'needs-improvement';
		}
		return 'poor';
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * A failed run, in the same shape as a successful one.
	 *
	 * Recorded like any other row so "we tried and it failed" is visible in
	 * the history rather than looking like nobody ever ran a test.
	 *
	 * @return array<string,mixed>
	 */
	private static function failure( string $provider, string $url, string $strategy, string $error ): array {
		$row = array(
			'ok'       => false,
			'provider' => $provider,
			'ts'       => time(),
			'url'      => $url,
			'strategy' => $strategy,
			'score'    => null,
			'metrics'  => array(),
			'issues'   => array(),
			'error'    => $error,
		);
		self::record( $row );
		return $row;
	}

	/**
	 * @param mixed $value Raw metric value.
	 */
	private static function numeric( $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Normalise a performance score to 0-100 from either wire shape.
	 *
	 * Public so a test can pin both, because which one GTmetrix sends is the
	 * single assumption in this file we cannot verify without a live key.
	 *
	 * @param mixed $value Raw score, 0-1 or 0-100.
	 */
	public static function percent( $value ): ?int {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$number = (float) $value;
		$scaled = $number > 1.0 ? $number : $number * 100.0;
		return (int) max( 0, min( 100, round( $scaled ) ) );
	}
}
