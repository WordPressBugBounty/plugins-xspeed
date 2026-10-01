<?php
/**
 * Keep xSpeed's own requests out of the site's analytics.
 *
 * The cache warmer, the benchmark, the optimize verifier, the health
 * probe and the dashboard's server checks fetch the site's pages
 * server-side. No JavaScript runs, but WP
 * Statistics and Slimstat can record hits in PHP, and neither recognises
 * our user agents, so every warm was counted as a visitor.
 *
 * Both plugins offer a record-time filter, which is what this uses. It must
 * stay at record time: the warmed page is cached and served to real
 * visitors, so anything that stopped a plugin printing its tracking snippet
 * on a warm would switch analytics off for everyone who gets that copy.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * Recognises xSpeed's own requests and tells analytics plugins to skip them.
 */
final class Self_Traffic {

	/**
	 * User-agent fragments xSpeed's own requests carry. `xSpeed-Preloader` is
	 * the warmer's name before 1.3.5; it can still arrive from a warm queued
	 * before an update.
	 */
	public const AGENTS = array( 'xSpeed-Warmer', 'xSpeed-Preloader', 'xSpeed Benchmark', 'xSpeed-Verifier', 'xSpeed Health Probe' );

	/**
	 * Request header every xSpeed loopback sends, so a request is recognised
	 * by what it is rather than by what it calls itself. The user agent is
	 * not enough on its own: the warmer's is filterable, the documented
	 * firewall remedy is to set it to a real browser's, and matching that
	 * string would drop every real visitor on the same browser. Some probes
	 * also have to send a real browser's or WordPress's own UA.
	 */
	public const HEADER = 'X-XSpeed-Self';

	/** $_SERVER key the header arrives under. */
	public const SERVER_KEY = 'HTTP_X_XSPEED_SELF';

	/** Register the analytics filters. Safe when neither plugin is active. */
	public static function boot(): void {
		add_filter( 'wp_statistics_exclusion_robots', array( __CLASS__, 'add_to_robot_list' ) );
		add_filter( 'slimstat_filter_pageview_stat_init', array( __CLASS__, 'drop_slimstat_hit' ) );
	}

	/**
	 * Add the marker header to a loopback request's headers.
	 *
	 * @param array<string,string> $headers Headers the request already sends.
	 * @return array<string,string>
	 */
	public static function headers( array $headers = array() ): array {
		$headers[ self::HEADER ] = '1';
		return $headers;
	}

	/**
	 * The fragments, filterable so an add-on can name its own requests.
	 *
	 * @return string[]
	 */
	public static function agents(): array {
		/**
		 * Filter the user-agent fragments that mark a request as xSpeed's own,
		 * so analytics plugins don't count it as a visitor.
		 *
		 * @param string[] $agents Case-insensitive substrings, longer than 3 characters.
		 */
		$agents = function_exists( 'apply_filters' ) ? apply_filters( 'xspeed_self_user_agents', self::AGENTS ) : self::AGENTS;
		if ( ! is_array( $agents ) ) {
			return array();
		}
		// WP Statistics ignores fragments of 3 characters or fewer, and so
		// does every other consumer, so the paths agree on what matches.
		$agents = array_filter(
			array_map( static fn ( $agent ): string => trim( (string) $agent ), $agents ),
			static fn ( string $agent ): bool => strlen( $agent ) > 3
		);
		return array_values( $agents );
	}

	/** Is this user agent one of ours? */
	public static function is_self( string $ua ): bool {
		if ( '' === $ua ) {
			return false;
		}
		foreach ( self::agents() as $fragment ) {
			if ( false !== stripos( $ua, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/** Does the current request carry the marker header? */
	public static function request_is_marked(): bool {
		return isset( $_SERVER[ self::SERVER_KEY ] ) && '' !== $_SERVER[ self::SERVER_KEY ];
	}

	/** Is the current request one of ours, by header or by user agent? */
	public static function is_self_request(): bool {
		return self::request_is_marked() || self::is_self( self::request_ua() );
	}

	/** The current request's user agent, sanitised. */
	private static function request_ua(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}

	/**
	 * WP Statistics: add our fragments to its robot list, which it matches
	 * by substring. It has no hook that sees request headers, so a marked
	 * request whose UA is not one of ours (a renamed warmer, a probe) gets
	 * its own UA added, for this request only. A real visitor on the same
	 * browser doesn't carry the header and is still counted.
	 *
	 * @param mixed $robots The list so far.
	 * @return mixed
	 */
	public static function add_to_robot_list( $robots ) {
		if ( ! is_array( $robots ) ) {
			return $robots;
		}
		$robots = array_merge( $robots, self::agents() );
		$ua     = self::request_ua();
		if ( self::request_is_marked() && strlen( $ua ) > 3 && ! self::is_self( $ua ) ) {
			$robots[] = $ua;
		}
		return $robots;
	}

	/**
	 * Slimstat: an empty stat aborts the pageview (its error e-302).
	 *
	 * @param mixed $stat The pageview being recorded.
	 * @return mixed
	 */
	public static function drop_slimstat_hit( $stat ) {
		return self::is_self_request() ? array() : $stat;
	}
}
