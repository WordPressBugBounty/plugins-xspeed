<?php
/**
 * Scan — the xSpeed Scan engine, run from the plugin.
 *
 * A graded whole-site report: four weighted dimensions (speed, delivery,
 * assets, stack) over ~20 checks, each with evidence and a suggested fix.
 * It is NOT a PageSpeed score wearing a different name — a Lighthouse
 * result is ONE check inside it (S1, 18 of 100 points), and the rubric
 * also measures things PSI cannot see at all: whether the request was a
 * cache HIT, whether a caching plugin is active, whether the site is
 * AI-controllable. `Score` stays the home of raw provider scores; the two
 * numbers are different scales and must never be presented as one.
 *
 * Outbound HTTP is opt-in and user-initiated: nothing here runs unless
 * someone presses Scan or runs the command. No schedule, no background
 * call — see readme.txt "External services".
 *
 * PRIVACY, and the reason this class takes a `visibility` at all: the scan
 * engine publishes every report it stores to a per-host feed that anyone
 * can enumerate by domain. A site attached to the Hub gets an unlisted
 * report instead, which the engine now honours -- the rule is simply
 * unattached => public, attached => private. `visibility()` decides which
 * is asked for and `private_supported()` says whether the engine can
 * deliver it, so the UI can promise privacy only when both are true.
 *
 * "Unlisted" is the exact promise: the report is kept off the public
 * per-host feed, but its URL stays reachable to anyone holding it.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Scan {

	/** Where the engine lives. Overridable for development only. */
	public const BASE = 'https://xspeedcache.com';

	/** Last completed report, plus the in-flight scan id. Autoload off. */
	public const RESULT_OPTION  = 'xspeed_scan_result';
	public const PENDING_OPTION = 'xspeed_scan_pending';

	/**
	 * A scan takes 20-60s, so a pending marker older than this is a scan
	 * that died — otherwise a crashed run wedges the button forever.
	 */
	public const PENDING_MAX_AGE = 900;

	/** Scan ids are `<host-slug>-<10 hex>`; mirror the engine's own shape. */
	private const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,80}-[0-9a-f]{10}$/';

	/** The engine origin, filterable so a dev site can point elsewhere. */
	public static function base(): string {
		$base = (string) apply_filters( 'xspeed_scan_base', self::BASE );
		return rtrim( $base, '/' );
	}

	/**
	 * Would this site's report be public or private?
	 *
	 * Attachment to the Hub is the switch. Note this reports INTENT: until
	 * the engine supports unlisted scans, every report is in fact public,
	 * which is why `private_supported()` exists as a separate question and
	 * the UI must not promise privacy on the strength of this alone.
	 */
	public static function visibility(): string {
		return self::attached() ? 'private' : 'public';
	}

	/**
	 * Is this site attached to the Hub?
	 *
	 * Guarded rather than a hard dependency: the Hub client lives in the Mcp
	 * module, and a site with that module inactive is simply not attached —
	 * which is a "no", not a fatal.
	 */
	public static function attached(): bool {
		if ( ! class_exists( '\XSpeed\Modules\Mcp\Mcp_Hub' ) ) {
			return false;
		}
		return (bool) \XSpeed\Modules\Mcp\Mcp_Hub::site_attached();
	}

	/**
	 * Can a private report actually be produced yet?
	 *
	 * True: the engine honours `visibility` on /api/scan, and a report sent
	 * as `unlisted` is kept off the public per-host feed. Kept separate from
	 * `visibility()` so the UI still asks two questions -- "would this be
	 * private?" and "can that be delivered?" -- and so a site pointed at an
	 * older engine by `xspeed_scan_base` can filter it back to false rather
	 * than promising a privacy that build cannot honour.
	 *
	 * Note the scope of the promise: unlisted suppresses the LISTING, not
	 * the report URL, which stays reachable by anyone who has it.
	 */
	public static function private_supported(): bool {
		return (bool) apply_filters( 'xspeed_scan_private_supported', true );
	}

	/**
	 * Start a scan. Returns the scan id to poll, or a WP_Error.
	 *
	 * `$fresh` forces a new run; without it the engine may hand back a
	 * recent cached report for the same URL, which is what you want for a
	 * first look and not what you want after a change.
	 *
	 * @return array{scan_id:string,report_url:string,cached:bool}|\WP_Error
	 */
	public static function start( string $url = '', bool $fresh = false ) {
		$url = '' !== $url ? $url : home_url( '/' );

		// The engine probes this URL from the outside, so a host it cannot
		// reach produces a confusing failure deep in the scan rather than
		// here. Catch the obvious cases up front.
		if ( ! wp_http_validate_url( $url ) ) {
			return new \WP_Error(
				'xspeed_scan_bad_url',
				__( 'That does not look like a public URL the scanner can reach.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}

		// Claim the slot BEFORE the 20s remote call, or a second overlapping
		// request starts its own scan while this one is still in flight.
		if ( ! self::claim() ) {
			$p = self::pending();
			return new \WP_Error(
				'xspeed_scan_in_progress',
				__( 'A scan is already running for this site.', 'xspeed' ),
				array(
					'status'  => 409,
					'scan_id' => is_array( $p ) ? ( $p['scan_id'] ?? '' ) : '',
				)
			);
		}

		$res = wp_remote_post(
			self::base() . '/api/scan',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'url'   => $url,
						'fresh' => $fresh,
						// Sent ahead of engine support: an unknown field is
						// ignored today and becomes meaningful the moment
						// the flag lands, with no plugin release needed.
						'visibility' => 'private' === self::visibility() ? 'unlisted' : 'listed',
					)
				),
			)
		);

		$body = self::decode( $res );
		if ( is_wp_error( $body ) ) {
			self::release();
			return $body;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 429 === $code ) {
			self::release();
			return new \WP_Error(
				'xspeed_scan_rate_limited',
				isset( $body['message'] ) && is_string( $body['message'] )
					? $body['message']
					: __( 'The scanner is rate limited right now. Try again in a few minutes.', 'xspeed' ),
				array( 'status' => 429 )
			);
		}

		$scan_id = isset( $body['scanId'] ) && is_string( $body['scanId'] ) ? $body['scanId'] : '';
		if ( $code >= 400 || '' === $scan_id || ! self::valid_id( $scan_id ) ) {
			self::release();
			return new \WP_Error(
				'xspeed_scan_failed',
				isset( $body['message'] ) && is_string( $body['message'] )
					? $body['message']
					: __( 'The scan could not be started.', 'xspeed' ),
				array( 'status' => 502 )
			);
		}

		$report_url = isset( $body['reportUrl'] ) && is_string( $body['reportUrl'] )
			? esc_url_raw( $body['reportUrl'] )
			: self::report_url( $scan_id );

		update_option(
			self::PENDING_OPTION,
			array(
				'scan_id'    => $scan_id,
				'report_url' => $report_url,
				'started'    => time(),
			),
			false
		);

		return array(
			'scan_id'    => $scan_id,
			'report_url' => $report_url,
			'cached'     => ! empty( $body['cached'] ),
		);
	}

	/**
	 * Poll one scan. A still-running scan reports its current step; a
	 * finished one is normalised, stored, and returned.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function poll( string $scan_id ) {
		if ( ! self::valid_id( $scan_id ) ) {
			return new \WP_Error(
				'xspeed_scan_bad_id',
				__( 'That is not a valid scan id.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}

		$res  = wp_remote_get(
			self::base() . '/api/scan/' . rawurlencode( $scan_id ),
			array( 'timeout' => 20 )
		);
		$body = self::decode( $res );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$status = isset( $body['status'] ) && is_string( $body['status'] ) ? $body['status'] : '';

		if ( 'running' === $status ) {
			return array(
				'status'  => 'running',
				'scan_id' => $scan_id,
				'step'    => isset( $body['step'] ) && is_string( $body['step'] ) ? $body['step'] : '',
			);
		}

		if ( 'complete' !== $status ) {
			delete_option( self::PENDING_OPTION );
			return new \WP_Error(
				'xspeed_scan_failed',
				isset( $body['error'] ) && is_string( $body['error'] )
					? $body['error']
					: __( 'The scan did not complete.', 'xspeed' ),
				array( 'status' => 502 )
			);
		}

		$result = self::normalise( $body, self::visibility() );
		update_option( self::RESULT_OPTION, $result, false );
		delete_option( self::PENDING_OPTION );

		return $result;
	}

	/**
	 * Claim the right to start a scan, atomically.
	 *
	 * `pending()` then `update_option()` is a read-then-write, and the write
	 * only happens AFTER a 20-second remote call -- so two overlapping
	 * requests both saw no marker, both spent a run against the engine's rate
	 * limit, and the second write buried the first scan's id so its result was
	 * never polled or stored.
	 *
	 * `add_option()` is the lock: it fails if the row already exists, and it
	 * is a single INSERT rather than a check followed by a write. The claim is
	 * placed BEFORE the remote call and released if that call fails, so a
	 * failed start does not leave the feature wedged until PENDING_MAX_AGE.
	 *
	 * @return bool True when this caller owns the scan slot.
	 */
	private static function claim(): bool {
		// Sweep a stale marker first, so an abandoned claim cannot block
		// scanning for longer than PENDING_MAX_AGE.
		self::pending();

		return add_option(
			self::PENDING_OPTION,
			array(
				'scan_id'    => '',
				'report_url' => '',
				'started'    => time(),
			),
			'',
			false
		);
	}

	/** Release a claim taken by claim() -- used when the start fails. */
	private static function release(): void {
		delete_option( self::PENDING_OPTION );
	}

	/** The in-flight scan, or null. Stale markers are swept, not returned. */
	public static function pending(): ?array {
		$p = get_option( self::PENDING_OPTION );
		if ( ! is_array( $p ) || empty( $p['scan_id'] ) ) {
			return null;
		}
		if ( time() - (int) ( $p['started'] ?? 0 ) > self::PENDING_MAX_AGE ) {
			delete_option( self::PENDING_OPTION );
			return null;
		}
		return $p;
	}

	/** The last completed report, or null. */
	public static function latest(): ?array {
		$r = get_option( self::RESULT_OPTION );
		return is_array( $r ) && isset( $r['score'] ) ? $r : null;
	}

	public static function report_url( string $scan_id ): string {
		return self::base() . '/scan/r/' . $scan_id;
	}

	private static function valid_id( string $id ): bool {
		return 1 === preg_match( self::ID_PATTERN, $id );
	}

	/**
	 * Reduce the engine's full report to what the dashboard stores.
	 *
	 * The whole audit stays one link away on the report page; keeping a
	 * copy of all 20 checks in an option would bloat it for no gain. What
	 * survives is the headline grade, the four dimensions, and the failing
	 * checks ranked by how many points fixing each recovers — the last is
	 * the part PSI cannot give us, so it is the part worth keeping.
	 */
	private static function normalise( array $body, ?string $visibility = null ): array {
		$dims = array();
		if ( isset( $body['dimensions'] ) && is_array( $body['dimensions'] ) ) {
			foreach ( $body['dimensions'] as $key => $d ) {
				if ( ! is_array( $d ) || empty( $d['applicable'] ) ) {
					continue;
				}
				$dims[ (string) $key ] = array(
					'score'  => self::num( $d['score'] ?? null ),
					'earned' => self::num( $d['earned'] ?? null ),
					'weight' => self::num( $d['weight'] ?? null ),
				);
			}
		}

		// Failing and partial checks, biggest recoverable gap first — the
		// "do this next and gain N points" list.
		$fixes = array();
		if ( isset( $body['checks'] ) && is_array( $body['checks'] ) ) {
			foreach ( $body['checks'] as $c ) {
				if ( ! is_array( $c ) ) {
					continue;
				}
				$status = isset( $c['status'] ) ? (string) $c['status'] : '';
				if ( 'fail' !== $status && 'partial' !== $status ) {
					continue;
				}
				$weight = (float) self::num( $c['weight'] ?? 0 );
				$earned = (float) self::num( $c['earned'] ?? 0 );
				$fixes[] = array(
					'id'          => isset( $c['id'] ) ? (string) $c['id'] : '',
					'name'        => isset( $c['name'] ) ? (string) $c['name'] : '',
					'status'      => $status,
					'evidence'    => isset( $c['evidence'] ) ? (string) $c['evidence'] : '',
					'remediation' => isset( $c['remediation'] ) ? (string) $c['remediation'] : '',
					'recoverable' => round( max( 0, $weight - $earned ), 1 ),
				);
			}
			usort(
				$fixes,
				static fn( array $a, array $b ): int => $b['recoverable'] <=> $a['recoverable']
			);
			$fixes = array_slice( $fixes, 0, 8 );
		}

		$measured = isset( $body['measured'] ) && is_array( $body['measured'] ) ? $body['measured'] : array();

		return array(
			'scan_id'    => isset( $body['scanId'] ) ? (string) $body['scanId'] : '',
			'ts'         => time(),
			'url'        => isset( $body['url'] ) ? esc_url_raw( (string) $body['url'] ) : '',
			// `overallScore`, not `score` — the engine's own field name.
			'score'      => self::num( $body['overallScore'] ?? null ),
			'grade'      => isset( $body['grade'] ) ? (string) $body['grade'] : '',
			'level'      => self::num( $body['level'] ?? null ),
			'level_name' => isset( $body['levelName'] ) ? (string) $body['levelName'] : '',
			// A partial scan graded less than the full rubric; saying so is
			// the difference between a low score and an incomplete one.
			'partial'    => ! empty( $body['partial'] ),
			'dimensions' => $dims,
			'measured'   => array(
				// Lighthouse is reported alongside, never AS, the score.
				'lighthouse'         => self::num( $measured['lighthouse'] ?? null ),
				'lighthouse_desktop' => self::num( $measured['lighthouseDesktop'] ?? null ),
				'ttfb_ms'            => self::num( $measured['ttfbMs'] ?? null ),
				'lcp_ms'             => self::num( $measured['lcpMs'] ?? null ),
				'cls'                => self::num( $measured['cls'] ?? null ),
				'tbt_ms'             => self::num( $measured['tbtMs'] ?? null ),
				'cache_hit'          => isset( $measured['cacheHit'] ) ? (bool) $measured['cacheHit'] : null,
			),
			'fixes'      => $fixes,
			'report_url' => isset( $body['reportUrl'] )
				? esc_url_raw( (string) $body['reportUrl'] )
				: self::report_url( isset( $body['scanId'] ) ? (string) $body['scanId'] : '' ),
			// Passed in, not resolved here: shaping a payload must not depend
			// on Hub state, or the transform cannot be reasoned about (or
			// tested) without a database behind it.
			'visibility' => $visibility ?? '',
		);
	}

	/**
	 * Numbers from an external service, kept nullable.
	 *
	 * (int) null is 0, and a missing measurement rendered as zero is the
	 * one coercion this feature must not make — "not measured" and "scored
	 * nothing" are different news.
	 */
	private static function num( $v ) {
		return is_numeric( $v ) ? ( is_float( $v + 0 ) && (float) $v !== floor( (float) $v ) ? round( (float) $v, 3 ) : (int) $v ) : null;
	}

	/** Shared transport handling: network error, then JSON shape. */
	private static function decode( $res ) {
		if ( is_wp_error( $res ) ) {
			return new \WP_Error(
				'xspeed_scan_unreachable',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'Could not reach the scanner: %s', 'xspeed' ),
					$res->get_error_message()
				),
				array( 'status' => 502 )
			);
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'xspeed_scan_bad_response',
				__( 'The scanner returned an unreadable response.', 'xspeed' ),
				array( 'status' => 502 )
			);
		}
		return $body;
	}
}
