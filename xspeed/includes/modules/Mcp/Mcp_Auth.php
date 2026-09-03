<?php
/**
 * MCP authentication — the permission_callback for MCP-originated REST
 * calls proxied by the hosted broker (api.xspeedcache.com).
 *
 * The Free plugin's REST routes all gate on
 * `current_user_can( 'manage_options' )` + a WP nonce
 * (class-rest-api.php), and Rest_Manager::wrap_permission() forces that
 * cap check as an always-on gate a module can only make stricter. MCP
 * calls arrive server-to-server from the broker with NO logged-in user,
 * so they can't use that path.
 *
 * Instead the broker presents the per-site `site_token` in the
 * `X-XSpeed-MCP-Token` header on every proxied call. This class
 * validates that token (constant-time) against the value stored by the
 * pairing flow. Routes wired to Mcp_Auth::permission() are registered
 * DIRECTLY by McpModule::boot() (not via Rest_Manager) precisely so they
 * can bypass the forced cap check.
 *
 * Security contract:
 *   - The token is a 32-byte random secret minted at connect time.
 *   - Comparison is constant-time (hash_equals) to avoid timing oracles.
 *   - A missing/empty stored token means "not connected" → always deny.
 *   - This is an ADDITIONAL credential surface exposed only on Pro and
 *     only after an admin explicitly connects; it never weakens the
 *     existing admin-only routes.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

defined( 'ABSPATH' ) || exit;

final class Mcp_Auth {

	/** Header the broker sends the site token in. */
	public const TOKEN_HEADER = 'X-XSpeed-MCP-Token';

	/**
	 * The permission_callback for MCP tool routes.
	 *
	 * Accepts the request iff a valid `site_token` is presented in the
	 * X-XSpeed-MCP-Token header. Does NOT fall through to
	 * current_user_can — these routes are token-only by design.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return bool|\WP_Error True when authorized, WP_Error(401) otherwise.
	 */
	public static function permission( \WP_REST_Request $request ) {
		// Locked-out IPs are rejected before any token comparison.
		if ( Mcp_Rate_Limiter::is_locked() ) {
			return new \WP_Error(
				'xspeed_mcp_rate_limited',
				__( 'Too many failed attempts. Try again later.', 'xspeed' ),
				array( 'status' => 429 )
			);
		}

		$presented = self::extract_token( $request );

		if ( '' === $presented ) {
			Mcp_Rate_Limiter::record_failure();
			return self::unauthorized( 'Missing MCP token.' );
		}

		$stored = Mcp_Pairing::site_token();

		if ( '' === $stored ) {
			// Not connected — nothing to authenticate against.
			return self::unauthorized( 'This site is not connected to xSpeed MCP.' );
		}

		if ( ! hash_equals( $stored, $presented ) ) {
			Mcp_Rate_Limiter::record_failure();
			return self::unauthorized( 'Invalid MCP token.' );
		}

		Mcp_Rate_Limiter::clear();
		return true;
	}

	/**
	 * Read the site token from the request header. Trimmed; empty string
	 * when absent.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 */
	private static function extract_token( \WP_REST_Request $request ): string {
		$header = $request->get_header( self::TOKEN_HEADER );
		if ( ! is_string( $header ) ) {
			return '';
		}
		return trim( $header );
	}

	/**
	 * Build a 401 WP_Error with the REST status attached so the broker
	 * (and any direct caller) sees a proper HTTP 401.
	 *
	 * @param string $message Human-readable reason.
	 * @return \WP_Error
	 */
	private static function unauthorized( string $message ) {
		return new \WP_Error(
			'xspeed_mcp_unauthorized',
			$message,
			array( 'status' => 401 )
		);
	}
}
