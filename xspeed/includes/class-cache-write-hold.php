<?php
/**
 * Cache_Write_Hold: lets an add-on keep one render of a URL out of the page
 * cache while the optimized version of that URL is about to land, and stops
 * it from doing so for long.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Cache_Write_Hold {

	/** Transient prefix; the rest of the name is md5 of host and path. */
	public const PREFIX = 'xspeed_write_hold_';

	/**
	 * How long one URL may be held by default, counted from its first held
	 * render. Long enough for a background build that is already running,
	 * short enough that a build which never lands costs a couple of minutes
	 * of uncached renders, not hours. An add-on that knows its build takes
	 * longer can ask for more through `xspeed_cache_write_hold_seconds`, up
	 * to HARD_CAP_SECONDS.
	 */
	public const MAX_SECONDS = 120;

	/**
	 * The most any add-on may extend a hold to. Every held render is an
	 * uncached render, so the window stays bounded whatever the add-on says.
	 */
	public const HARD_CAP_SECONDS = 300;

	/**
	 * What an add-on may report about the build behind a hold, for the
	 * activity-log row when the window runs out.
	 */
	private const STATES = array( 'scheduled', 'running', 'failed' );

	/**
	 * Lifetime of a record. Longer than MAX_SECONDS on purpose: a record that
	 * expired inside the window would restart it, and a page visited less
	 * often than the TTL would then be held on every visit and never cached.
	 */
	public const RECORD_TTL = DAY_IN_SECONDS;

	/**
	 * Site-wide throttle on the activity-log row. A renderer that fails for
	 * every page would otherwise write one row per URL and push every purge
	 * out of the 50-row log.
	 */
	public const LOG_THROTTLE_TRANSIENT = 'xspeed_write_hold_logged';
	public const LOG_THROTTLE_SECONDS   = 600;

	/**
	 * Should this MISS be served without being stored?
	 *
	 * Asks `xspeed_cache_write_hold`, then applies the per-URL cap. Call once
	 * per render, at the point the page would otherwise be written: each
	 * true answer counts as one held render.
	 *
	 * @param string $host Request host, as the cache key uses it.
	 * @param string $path Normalized request path, without the query string.
	 */
	public static function should_hold( string $host, string $path ): bool {
		/**
		 * Filter: xspeed_cache_write_hold
		 *
		 * Return true when the optimized version of THIS URL is about to be
		 * ready (a build for it is running or starts within seconds), so the
		 * render being finished now should be sent to the visitor with
		 * `no-store` and not written to the page cache. The next request
		 * renders again and is stored once the answer goes back to false.
		 *
		 * Answer narrowly. A URL that is only waiting in a queue is not
		 * imminent, and every true answer costs a full uncached render. xSpeed
		 * ignores a true answer for a URL once its hold window has passed since
		 * its first held render (MAX_SECONDS, or what
		 * `xspeed_cache_write_hold_seconds` asks for up to HARD_CAP_SECONDS),
		 * and caches the page as usual. There is no cap
		 * on how many renders are held inside that window: a busy page would
		 * reach any count before its build lands and get cached unoptimized,
		 * which is the outcome the hold exists to prevent.
		 *
		 * @param bool   $hold Default false.
		 * @param string $path Normalized request path, without the query string.
		 */
		if ( ! (bool) apply_filters( 'xspeed_cache_write_hold', false, $path ) ) {
			self::clear( $host, $path );
			return false;
		}

		$name   = self::transient_name( $host, $path );
		$now    = time();
		$record = get_transient( $name );
		if ( ! is_array( $record ) || ! isset( $record['first'], $record['count'] ) ) {
			$record = array(
				'first' => $now,
				'count' => 0,
			);
		}
		$first = (int) $record['first'];
		$count = (int) $record['count'];

		$window = self::window_seconds( $path );
		if ( $now - $first >= $window ) {
			delete_transient( $name );
			self::log_cap( $host . $path, $path, $count, $window );
			return false;
		}

		set_transient(
			$name,
			array(
				'first' => $first,
				'count' => $count + 1,
			),
			self::RECORD_TTL
		);
		return true;
	}

	/**
	 * Forget the hold record for one URL. Runs when the URL is purged and when
	 * it is cached, so a later hold starts a fresh window instead of reading
	 * an old one as already expired.
	 *
	 * @param string $host Host, with a non-default port if there is one.
	 * @param string $path Normalized path.
	 */
	public static function clear( string $host, string $path ): void {
		if ( ! function_exists( 'get_transient' ) ) {
			return;
		}
		$name = self::transient_name( $host, $path );
		// Read before deleting: this runs on every stored MISS, and most have
		// no record. A read is cheaper than a DELETE that finds nothing.
		if ( false !== get_transient( $name ) ) {
			delete_transient( $name );
		}
	}

	/**
	 * Send the response headers for a held render.
	 *
	 * `no-store` because the copy is about to be replaced, so no browser,
	 * proxy or CDN should keep it. `CDN-Cache-Control` and `X-Accel-Expires`
	 * are the same pair an edge hold sends, for caches that do not read
	 * Cache-Control. A stronger answer WordPress already sent is left alone.
	 */
	public static function send_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		if ( ! self::cache_control_already_no_store() ) {
			header( 'Cache-Control: no-store, private' );
		}
		header( 'CDN-Cache-Control: no-store' );
		header( 'X-Accel-Expires: 0' );
		header( 'X-XSpeed-Write-Hold: held' );
	}

	/**
	 * One record per URL. Lowercased host and no trailing slash, so the
	 * request side and purge_url() name the same record for `/page` and
	 * `/page/`.
	 */
	public static function transient_name( string $host, string $path ): string {
		$path = rtrim( $path, '/' );
		if ( '' === $path ) {
			$path = '/';
		}
		return self::PREFIX . md5( strtolower( $host ) . '|' . $path );
	}

	private static function cache_control_already_no_store(): bool {
		foreach ( headers_list() as $line ) {
			if ( 0 === stripos( $line, 'cache-control:' ) && preg_match( '/\bno-store\b/i', $line ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * How long this URL may be held: MAX_SECONDS unless an add-on that knows
	 * its build takes longer asks for more, never past HARD_CAP_SECONDS and
	 * never below MAX_SECONDS.
	 *
	 * @param string $path Normalized request path.
	 */
	private static function window_seconds( string $path ): int {
		/**
		 * Filter: xspeed_cache_write_hold_seconds
		 *
		 * How long a URL held by `xspeed_cache_write_hold` may stay out of
		 * the page cache, counted from its first held render. Return more
		 * than the default only when the build behind the hold is known to
		 * take longer, such as a cloud render that is queued behind others.
		 * Values are clamped to MAX_SECONDS..HARD_CAP_SECONDS.
		 *
		 * @param int    $seconds Default MAX_SECONDS.
		 * @param string $path    Normalized request path, without the query string.
		 */
		$seconds = (int) apply_filters( 'xspeed_cache_write_hold_seconds', self::MAX_SECONDS, $path );
		return max( self::MAX_SECONDS, min( self::HARD_CAP_SECONDS, $seconds ) );
	}

	/**
	 * What the add-on says about the build behind the hold, or '' when it
	 * says nothing it recognizes.
	 *
	 * @param string $path Normalized request path.
	 */
	private static function build_state( string $path ): string {
		/**
		 * Filter: xspeed_cache_write_hold_state
		 *
		 * Where the build behind a held URL stood when its window ran out:
		 * 'scheduled' (not started yet), 'running' (started, no result yet)
		 * or 'failed'. Used only for the activity-log row.
		 *
		 * @param string $state Default ''.
		 * @param string $path  Normalized request path, without the query string.
		 */
		$state = (string) apply_filters( 'xspeed_cache_write_hold_state', '', $path );
		return in_array( $state, self::STATES, true ) ? $state : '';
	}

	/**
	 * @param string $url    Host and normalized path. Both were sanitized on
	 *                       the way in, and the log renders as text.
	 * @param string $path   Normalized path, for the state filter.
	 * @param int    $held   Renders held before the window ran out.
	 * @param int    $window The window, in seconds.
	 */
	private static function log_cap( string $url, string $path, int $held, int $window ): void {
		if ( false !== get_transient( self::LOG_THROTTLE_TRANSIENT ) ) {
			return;
		}
		set_transient( self::LOG_THROTTLE_TRANSIENT, 1, self::LOG_THROTTLE_SECONDS );

		switch ( self::build_state( $path ) ) {
			case 'scheduled':
				/* translators: 1: URL host and path, 2: number of seconds, 3: number of renders that were not cached. */
				$format = __( 'Cached %1$s before its optimized version was ready: after %2$d seconds its build had not started yet. %3$d render(s) were sent uncached in that time. The page is purged again when the build lands.', 'xspeed' );
				break;
			case 'running':
				/* translators: 1: URL host and path, 2: number of seconds, 3: number of renders that were not cached. */
				$format = __( 'Cached %1$s before its optimized version was ready: after %2$d seconds its build was still running. %3$d render(s) were sent uncached in that time. The page is purged again when the build lands.', 'xspeed' );
				break;
			case 'failed':
				/* translators: 1: URL host and path, 2: number of seconds, 3: number of renders that were not cached. */
				$format = __( 'Cached %1$s without its optimized version: its build failed within %2$d seconds. %3$d render(s) were sent uncached in that time.', 'xspeed' );
				break;
			default:
				/* translators: 1: URL host and path, 2: number of seconds, 3: number of renders that were not cached. */
				$format = __( 'Cached %1$s before its optimized version was ready: it was not ready after %2$d seconds. %3$d render(s) were sent uncached in that time. The page is purged again when the build lands.', 'xspeed' );
		}

		Activity_Log::record( 'cache_write_hold_capped', sprintf( $format, $url, $window, $held ), Activity_Log::WARN );
	}
}
