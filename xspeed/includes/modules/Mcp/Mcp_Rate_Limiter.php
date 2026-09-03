<?php
/**
 * MCP rate limiter — a per-IP lockout on FAILED token authentication.
 *
 * The MCP connection token is a 256-bit secret, so online brute-forcing
 * is already infeasible. This limiter exists to stop the cheaper abuse:
 * a flood of bad-token requests burning CPU + filling access logs, and to
 * give a leaked-then-rotated token's stale clients a hard wall instead of
 * hammering the endpoint. It is a defence-in-depth layer, not the primary
 * control (that's the token itself).
 *
 * Model: count consecutive FAILED attempts per client IP in a rolling
 * window (transient-backed). At/after the threshold the IP is locked out
 * for the window; a SUCCESSFUL auth clears the counter immediately so a
 * legitimate client that fixed a typo isn't punished.
 *
 * Threshold + window are overridable:
 *   - XSPEED_MCP_MAX_FAILS / XSPEED_MCP_LOCKOUT_SECONDS constants, and
 *   - the `xspeed_mcp_rate_limit` filter ( [ max_fails, lockout_seconds ] ).
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

defined( 'ABSPATH' ) || exit;

final class Mcp_Rate_Limiter {

	/** Transient key prefix; the client-IP hash is appended. */
	private const PREFIX = 'xspeed_mcp_rl_';

	/** Default: lock out after this many failed attempts. */
	private const DEFAULT_MAX_FAILS = 10;

	/** Default: lockout / rolling-window length, in seconds. */
	private const DEFAULT_LOCKOUT = 900; // 15 minutes.

	/**
	 * Is the current client currently locked out? Call BEFORE comparing the
	 * token so a locked IP never even reaches the (constant-time) compare.
	 *
	 * @return bool
	 */
	public static function is_locked(): bool {
		list( $max ) = self::limits();
		return self::attempts() >= $max;
	}

	/**
	 * Record a failed auth attempt for the current client and return whether
	 * the client is now locked out. Extends the rolling window on each fail.
	 *
	 * @return bool True if this failure crossed into a lockout.
	 */
	public static function record_failure(): bool {
		list( $max, $window ) = self::limits();
		$count = self::attempts() + 1;
		set_transient( self::key(), $count, $window );
		return $count >= $max;
	}

	/**
	 * Clear the counter for the current client — call on a SUCCESSFUL auth so
	 * a legitimate client isn't held back by earlier fumbles.
	 */
	public static function clear(): void {
		delete_transient( self::key() );
	}

	/** Seconds a locked client must wait (approximate; the window length). */
	public static function retry_after(): int {
		return self::limits()[1];
	}

	// -- internals --

	/** Current failed-attempt count for this client (0 when none). */
	private static function attempts(): int {
		$v = get_transient( self::key() );
		return is_numeric( $v ) ? (int) $v : 0;
	}

	/** Transient key bound to the (hashed) client IP. */
	private static function key(): string {
		return self::PREFIX . md5( self::client_ip() );
	}

	/**
	 * Resolve [ max_fails, lockout_seconds ] from constants, then filter.
	 *
	 * @return array{0:int,1:int}
	 */
	private static function limits(): array {
		$max    = defined( 'XSPEED_MCP_MAX_FAILS' ) ? (int) \XSPEED_MCP_MAX_FAILS : self::DEFAULT_MAX_FAILS;
		$window = defined( 'XSPEED_MCP_LOCKOUT_SECONDS' ) ? (int) \XSPEED_MCP_LOCKOUT_SECONDS : self::DEFAULT_LOCKOUT;

		/**
		 * Filter the MCP failed-auth rate limit.
		 *
		 * @param array{0:int,1:int} $limits [ max_fails, lockout_seconds ].
		 */
		$limits = (array) apply_filters( 'xspeed_mcp_rate_limit', array( $max, $window ) );
		$max    = isset( $limits[0] ) ? max( 1, (int) $limits[0] ) : self::DEFAULT_MAX_FAILS;
		$window = isset( $limits[1] ) ? max( 1, (int) $limits[1] ) : self::DEFAULT_LOCKOUT;
		return array( $max, $window );
	}

	/**
	 * Best-effort client IP. REMOTE_ADDR only — we deliberately do NOT trust
	 * X-Forwarded-For here (spoofable → an attacker could dodge the limit or
	 * lock out a victim). Behind a known proxy the site should set
	 * REMOTE_ADDR upstream.
	 */
	private static function client_ip(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- used only as a rate-limit bucket key (md5'd), never output or stored raw.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return '' !== $ip ? $ip : 'unknown';
	}
}
