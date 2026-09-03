<?php
/**
 * Optimize verifier — did that change break the page?
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * Capture what a page looks like, then decide whether a later fetch of it is
 * still healthy.
 *
 * The autopilot's whole claim to being safe rests here. Applying settings is
 * easy; knowing you have not just served a blank page to every visitor is the
 * hard part, and it is the part a human doing this by hand actually performs —
 * they look at the site.
 *
 * ## What this can and cannot see
 *
 * This runs in PHP, over the HTML a request returns. It catches the failures
 * that show up in markup: a fatal, a truncated document, a stylesheet that
 * vanished, a page that collapsed to a fraction of its size.
 *
 * It does NOT execute JavaScript, so it cannot see a page that arrives intact
 * and then breaks in the browser. That failure is real and has happened here:
 * removing jQuery Migrate produced `jQuery.Deferred exception: e.indexOf is not
 * a function` on a page whose HTML was complete and the right size. Every
 * assertion below would have passed it.
 *
 * That is why `Optimize_Plan` puts anything with an invisible failure mode in
 * the AGGRESSIVE tier rather than trusting this class to catch it. The check
 * and the classification are two halves of one safety story; neither is
 * sufficient alone. A JS-executing check belongs in the E2E layer, which has a
 * real browser — see #210.
 *
 * Comparison is always against a baseline captured BEFORE the run, never
 * against absolute thresholds: "this page has 3 stylesheets" is meaningless,
 * "this page had 54 stylesheets and now has 0" is a broken site.
 *
 * @since 1.2.0
 */
final class Optimize_Verifier {

	/**
	 * How far the HTML may shrink or grow before it is treated as broken.
	 *
	 * Wide on purpose. Minification legitimately removes a chunk of a page,
	 * and combining rewrites a headful of tags — neither is damage. What this
	 * catches is the catastrophic case: a fatal that truncates the document, or
	 * a blank page, both of which collapse the size far past any optimization.
	 */
	private const SIZE_TOLERANCE = 0.5;

	/**
	 * Fetch a page and reduce it to the handful of facts worth comparing.
	 *
	 * Requested ANONYMOUSLY and uncached. A logged-in request hits the
	 * drop-in's bailout and never sees the cached path, so it would verify a
	 * page no visitor is served; a cached response would verify the page as it
	 * was BEFORE the change, which is worse than not checking at all.
	 *
	 * @param string $url Absolute URL to sample.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function sample( string $url ) {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return new \WP_Error( 'xspeed_verify_url', __( 'A URL is required.', 'xspeed' ) );
		}

		// Cache-buster: without it a static-cached HIT returns the pre-change
		// page and every check passes against stale HTML.
		$bust = add_query_arg( 'xspeed_verify', (string) time(), $url );

		$t0   = microtime( true );
		$resp = wp_remote_get(
			$bust,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'sslverify'   => false,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				// A real browser UA: some hosts and firewalls serve a
				// challenge page to unknown agents, which would read as the
				// site being broken.
				'user-agent'  => 'Mozilla/5.0 (compatible; xSpeed-Verifier/1.0)',
			)
		);

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$body = (string) wp_remote_retrieve_body( $resp );

		return array(
			'status'      => (int) wp_remote_retrieve_response_code( $resp ),
			'bytes'       => strlen( $body ),
			'complete'    => (bool) preg_match( '#</body\s*>#i', $body ),
			'stylesheets' => self::count_stylesheets( $body ),
			'scripts'     => (int) preg_match_all( '#<script\b[^>]*\bsrc=#i', $body ),
			'title'       => self::extract_title( $body ),
			// Wall-clock for the whole request, in ms. The cache-buster above
			// makes every sample an uncached render, so successive samples
			// measure the same thing and their medians are comparable — this
			// is what Optimizer's per-step regression check reads. (#310)
			'elapsed_ms'  => ( microtime( true ) - $t0 ) * 1000,
		);
	}

	/**
	 * Count real stylesheet links.
	 *
	 * `<noscript>` blocks are stripped first. The async-CSS pattern emits a
	 * no-JS fallback `<link>` beside every deferred one, so counting naively
	 * doubles the total and makes a healthy page look like it grew — a
	 * mistake worth guarding against in code, having been made once in
	 * analysis.
	 *
	 * @param string $html Page HTML.
	 */
	private static function count_stylesheets( string $html ): int {
		$stripped = (string) preg_replace( '#<noscript\b[^>]*>.*?</noscript\s*>#is', '', $html );
		return (int) preg_match_all( '#<link\b[^>]*\brel=["\']?stylesheet#i', $stripped );
	}

	/**
	 * @param string $html Page HTML.
	 */
	private static function extract_title( string $html ): string {
		if ( preg_match( '#<title\b[^>]*>(.*?)</title\s*>#is', $html, $m ) ) {
			return trim( wp_strip_all_tags( $m[1] ) );
		}
		return '';
	}

	/**
	 * Compare a fresh sample against the baseline.
	 *
	 * Returns every failure rather than the first, so a report can say what
	 * actually went wrong instead of "verification failed".
	 *
	 * Pure — no I/O, unit-tested.
	 *
	 * @param array<string,mixed> $baseline Sample taken before the run.
	 * @param array<string,mixed> $current  Sample taken after a change.
	 * @return array{ok:bool,failures:string[]}
	 */
	public static function compare( array $baseline, array $current ): array {
		$failures = array();

		if ( 200 !== (int) ( $current['status'] ?? 0 ) ) {
			$failures[] = sprintf(
				/* translators: %d: HTTP status code */
				__( 'The page returned HTTP %d.', 'xspeed' ),
				(int) ( $current['status'] ?? 0 )
			);
			// Nothing below is meaningful once the response itself failed.
			return array(
				'ok'       => false,
				'failures' => $failures,
			);
		}

		if ( empty( $current['complete'] ) ) {
			$failures[] = __( 'The page stopped part-way through — no closing </body>, which usually means a PHP fatal.', 'xspeed' );
		}

		$before = (int) ( $baseline['bytes'] ?? 0 );
		$after  = (int) ( $current['bytes'] ?? 0 );
		if ( $before > 0 ) {
			$ratio = $after / $before;
			if ( $ratio < ( 1 - self::SIZE_TOLERANCE ) || $ratio > ( 1 + self::SIZE_TOLERANCE ) ) {
				$failures[] = sprintf(
					/* translators: 1: before size in bytes, 2: after size in bytes */
					__( 'The page size changed from %1$d to %2$d bytes — too far to be optimization.', 'xspeed' ),
					$before,
					$after
				);
			}
		}

		// Zero is the signal, not a decrease: combining legitimately takes 54
		// stylesheets down to 3. Losing them ALL is a page with no styling.
		if ( (int) ( $baseline['stylesheets'] ?? 0 ) > 0 && 0 === (int) ( $current['stylesheets'] ?? 0 ) ) {
			$failures[] = __( 'Every stylesheet disappeared — the page would render unstyled.', 'xspeed' );
		}
		if ( (int) ( $baseline['scripts'] ?? 0 ) > 0 && 0 === (int) ( $current['scripts'] ?? 0 ) ) {
			$failures[] = __( 'Every script disappeared.', 'xspeed' );
		}

		$before_title = (string) ( $baseline['title'] ?? '' );
		if ( '' !== $before_title && $before_title !== (string) ( $current['title'] ?? '' ) ) {
			$failures[] = __( 'The page title changed — this may be an error page rather than the site.', 'xspeed' );
		}

		return array(
			'ok'       => array() === $failures,
			'failures' => $failures,
		);
	}
}
