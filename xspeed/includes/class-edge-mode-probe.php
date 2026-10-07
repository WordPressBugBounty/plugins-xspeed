<?php
/**
 * Whether the Cloudflare edge in front of the site obeys "do not store".
 *
 * xSpeed marks first renders, bypassed pages and per-visitor pages
 * `no-store` for the edge. That only works when the edge reads the site's
 * headers. A Cloudflare Cache Rule whose Edge TTL ignores the origin, or
 * Cloudflare Enterprise bought from xCloud with Edge Page Caching in
 * `override_origin` mode, stores every HTML response anyway, so a cart or
 * account page can be served to the next visitor. Nothing on the site can
 * read those settings, so this asks the edge directly: it requests a page
 * that answers `no-store` twice, and a cached second answer means the edge
 * ignores the site.
 *
 * The round-trips run from cron. Health only reads the stored verdict.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Edge_Mode_Probe {

	/** Stored verdict. */
	public const TRANSIENT = 'xspeed_edge_mode_probe';

	/** Token the probe page answers to, set for the length of one probe. */
	private const TOKEN_TRANSIENT = 'xspeed_edge_mode_probe_token';

	/** Query parameter the probe page answers on. */
	public const PARAM = 'xspeed_edge_probe';

	/** Cron hook for the background run. */
	public const CRON_HOOK = 'xspeed_edge_mode_probe_refresh';

	/** The edge stored a `no-store` page: it ignores the site's headers. */
	public const IGNORES_ORIGIN = 'ignores_origin';

	/** The edge did not store it. */
	public const RESPECTS_ORIGIN = 'respects_origin';

	/** No Cloudflare in front, as far as the probe could see. */
	public const NOT_CLOUDFLARE = 'not_cloudflare';

	/** The probe could not finish. */
	public const UNKNOWN = 'unknown';

	/** Answer the probe request, before anything else renders. */
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'maybe_answer' ), 0 );
	}

	/**
	 * The verdict from the two answers' Cloudflare headers.
	 *
	 * Pure, so the rule is tested without a network.
	 *
	 * @param array{ray:string,status:string} $first  First answer.
	 * @param array{ray:string,status:string} $second Second answer.
	 */
	public static function verdict( array $first, array $second ): string {
		if ( '' === $first['ray'] && '' === $second['ray'] ) {
			return self::NOT_CLOUDFLARE;
		}
		$stored = array( 'HIT', 'STALE', 'UPDATING', 'REVALIDATED' );
		return in_array( strtoupper( $second['status'] ), $stored, true ) ? self::IGNORES_ORIGIN : self::RESPECTS_ORIGIN;
	}

	/**
	 * The stored verdict. A cold one schedules a background run and reads
	 * as unknown, so a dashboard load never waits on the edge.
	 *
	 * @return array{verdict:string,checked_at:int}
	 */
	public static function cached(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['verdict'] ) ) {
			return array(
				'verdict'    => (string) $cached['verdict'],
				'checked_at' => (int) ( $cached['checked_at'] ?? 0 ),
			);
		}
		self::schedule();
		return array(
			'verdict'    => self::UNKNOWN,
			'checked_at' => 0,
		);
	}

	/** Queue one background run, unless one is pending. */
	public static function schedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + 30, self::CRON_HOOK );
	}

	/**
	 * Run the probe: two requests through whatever is in front of the site.
	 *
	 * @return array{verdict:string,checked_at:int}
	 */
	public static function run(): array {
		$token = function_exists( 'wp_generate_password' ) ? wp_generate_password( 16, false ) : bin2hex( random_bytes( 8 ) );
		set_transient( self::TOKEN_TRANSIENT, $token, 2 * MINUTE_IN_SECONDS );

		$url    = add_query_arg( self::PARAM, $token, home_url( '/' ) );
		$first  = self::fetch( $url );
		$second = null === $first ? null : self::fetch( $url );
		delete_transient( self::TOKEN_TRANSIENT );

		if ( null === $first || null === $second ) {
			$result = array(
				'verdict'    => self::UNKNOWN,
				'checked_at' => time(),
			);
			set_transient( self::TRANSIENT, $result, HOUR_IN_SECONDS );
			return $result;
		}

		$result = array(
			'verdict'    => self::verdict( $first, $second ),
			'checked_at' => time(),
		);
		set_transient( self::TRANSIENT, $result, 12 * HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * One request, or null when it failed.
	 *
	 * @param string $url Probe URL.
	 * @return array{ray:string,status:string}|null
	 */
	private static function fetch( string $url ): ?array {
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => Self_Traffic::headers( array( 'User-Agent' => 'xSpeed Health Probe/1.0' ) ),
				'cookies'     => array(),
				'sslverify'   => ! Cookie_Inspector::is_local_host( home_url( '/' ) ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		return array(
			'ray'    => (string) wp_remote_retrieve_header( $res, 'cf-ray' ),
			'status' => (string) wp_remote_retrieve_header( $res, 'cf-cache-status' ),
		);
	}

	/**
	 * Answer the probe with a small HTML page marked `no-store` everywhere.
	 * Only while a probe is running, and only for its token.
	 */
	public static function maybe_answer(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an anonymous probe; the token below is the check.
		$given = isset( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
		if ( ! self::answers( $given ) ) {
			return;
		}
		self::send_answer();
		exit;
	}

	/**
	 * Whether a request carrying this token is the running probe.
	 *
	 * @param string $given Token from the query string.
	 */
	public static function answers( string $given ): bool {
		if ( '' === $given ) {
			return false;
		}
		$token = get_transient( self::TOKEN_TRANSIENT );
		return is_string( $token ) && '' !== $token && hash_equals( $token, $given );
	}

	/** The probe page's headers and body. */
	public static function send_answer(): void {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Cache-Control: no-store, private' );
			header( 'CDN-Cache-Control: no-store' );
			header( 'Cloudflare-CDN-Cache-Control: no-store' );
		}
		echo '<!doctype html><title>xSpeed edge probe</title>';
	}

	/**
	 * Health row when the edge stores pages it was told not to. Null
	 * otherwise: a site whose edge obeys has nothing to act on.
	 *
	 * @param string $verdict    Stored verdict.
	 * @param bool   $xcloud_cfe Cloudflare Enterprise comes from xCloud's
	 *                           purge plugin on this site.
	 * @return array{id:string,tone:string,label:string,detail:string}|null
	 */
	public static function health_row( string $verdict, bool $xcloud_cfe ): ?array {
		if ( self::IGNORES_ORIGIN !== $verdict ) {
			return null;
		}
		$fix = $xcloud_cfe
			? 'This domain\'s Cloudflare Enterprise comes from xCloud. Ask xCloud to set its edge caching mode to respect origin (edge_ttl_mode: respect_origin), or turn Edge Page Caching off in xCloud.'
			: 'A Cloudflare Cache Rule probably sets Edge TTL to "Ignore cache-control header and use this TTL". Set it to "Respect origin TTL", or leave HTML out of that rule.';
		return array(
			'id'     => 'edge_mode',
			'tone'   => Health::WARN,
			'label'  => 'Cloudflare stores pages marked do-not-store',
			'detail' => 'A test page sent with "do not store" came back from Cloudflare\'s cache, so cart, account and other per-visitor pages can be served to the wrong visitor. ' . $fix,
		);
	}
}
