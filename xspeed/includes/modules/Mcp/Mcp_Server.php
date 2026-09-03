<?php
/**
 * MCP server — the per-site JSON-RPC endpoint.
 *
 * This is the primary way an AI assistant talks to xSpeed: the plugin
 * speaks the MCP protocol directly at this site's own URL
 * (https://thissite.com/xspeed/mcp), so there is NO hosted broker in the
 * path. The user pastes their own site's MCP URL + connection token into
 * their AI client.
 *
 * MCP's Streamable-HTTP transport is JSON-RPC 2.0 over HTTP POST. We
 * implement the small server surface an AI client needs:
 *   - initialize            → capabilities + serverInfo
 *   - notifications/*        → acknowledged (no response body)
 *   - ping                   → {}
 *   - tools/list             → Mcp_Tools::list()
 *   - tools/call             → Mcp_Tools::invoke() wrapped as MCP content
 *
 * Auth: the connection token is presented either as a Bearer token
 * (Authorization header) or the X-XSpeed-MCP-Token header; both are
 * validated against the stored site_token by Mcp_Auth. A single
 * unauthenticated call gets a JSON-RPC error, never the tool result.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

defined( 'ABSPATH' ) || exit;

final class Mcp_Server {

	/** MCP protocol version this server implements. */
	public const PROTOCOL_VERSION = '2025-06-18';

	/** JSON-RPC standard error codes. */
	private const PARSE_ERROR      = -32700;
	private const INVALID_REQUEST  = -32600;
	private const METHOD_NOT_FOUND = -32601;
	private const INVALID_PARAMS   = -32602;
	private const UNAUTHORIZED     = -32001;

	/**
	 * Handle a raw MCP HTTP request. Reads the JSON-RPC message from the
	 * request body, dispatches it, and returns a WP_REST_Response (or a
	 * 202 with empty body for notifications).
	 *
	 * @param \WP_REST_Request $request Incoming request (raw body).
	 */
	public static function handle( \WP_REST_Request $request ) {
		// --- Lockout check first: a rate-limited IP never reaches the compare.
		if ( Mcp_Rate_Limiter::is_locked() ) {
			return self::error_response( null, self::UNAUTHORIZED, 'Too many failed attempts. Try again later.', 429 );
		}

		// --- Authenticate: static pairing token (Bearer / X-XSpeed-MCP-Token)
		// OR an OAuth 2.1 access token (Bearer). Either satisfies the gate.
		if ( true !== self::authorize( $request ) ) {
			Mcp_Rate_Limiter::record_failure();
			$response = self::error_response( null, self::UNAUTHORIZED, 'Unauthorized: invalid or missing connection token.', 401 );
			// RFC 9728 challenge: point OAuth-capable clients at the
			// protected-resource metadata so they can start the auth flow.
			$response->header( 'WWW-Authenticate', self::challenge_header() );
			return $response;
		}
		Mcp_Rate_Limiter::clear();

		$raw = $request->get_body();
		$msg = json_decode( $raw, true );

		if ( null === $msg && JSON_ERROR_NONE !== json_last_error() ) {
			return self::error_response( null, self::PARSE_ERROR, 'Parse error: body is not valid JSON.', 400 );
		}

		// Batched requests: an array of messages. Handle each; drop
		// notification (id-less) responses per JSON-RPC.
		if ( is_array( $msg ) && array_key_exists( 0, $msg ) ) {
			$responses = array();
			foreach ( $msg as $one ) {
				$r = self::dispatch( is_array( $one ) ? $one : array() );
				if ( null !== $r ) {
					$responses[] = $r;
				}
			}
			// All notifications → 202 Accepted, empty body.
			if ( empty( $responses ) ) {
				return new \WP_REST_Response( null, 202 );
			}
			return new \WP_REST_Response( $responses, 200 );
		}

		if ( ! is_array( $msg ) ) {
			return self::error_response( null, self::INVALID_REQUEST, 'Invalid request.', 400 );
		}

		$response = self::dispatch( $msg );
		if ( null === $response ) {
			// Notification — no response body, 202 Accepted.
			return new \WP_REST_Response( null, 202 );
		}
		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Dispatch a single JSON-RPC message. Returns the response array, or
	 * null for notifications (messages with no `id`).
	 *
	 * @param array $msg Decoded JSON-RPC message.
	 * @return array|null
	 */
	private static function dispatch( array $msg ) {
		$method = isset( $msg['method'] ) ? (string) $msg['method'] : '';
		$id     = $msg['id'] ?? null;
		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		// Notifications (no id) get acknowledged with no response.
		$is_notification = ! array_key_exists( 'id', $msg );

		switch ( $method ) {
			case 'initialize':
				return self::result(
					$id,
					array(
						'protocolVersion' => self::PROTOCOL_VERSION,
						'capabilities'    => array(
							'tools' => array( 'listChanged' => false ),
						),
						'serverInfo'      => array(
							'name'    => 'xspeed',
							'version' => defined( 'XSPEED_VERSION' ) ? XSPEED_VERSION : '1.0.0',
						),
					)
				);

			case 'ping':
				return self::result( $id, (object) array() );

			case 'tools/list':
				return self::result( $id, array( 'tools' => Mcp_Tools::list() ) );

			case 'tools/call':
				return self::call_tool( $id, $params );

			default:
				// notifications/initialized, notifications/cancelled, etc.
				if ( $is_notification || 0 === strpos( $method, 'notifications/' ) ) {
					return null;
				}
				return self::error( $id, self::METHOD_NOT_FOUND, 'Method not found: ' . $method );
		}
	}

	/**
	 * Execute a tools/call request and wrap the result in MCP content.
	 *
	 * @param mixed $id     JSON-RPC id.
	 * @param array $params { name:string, arguments:array }.
	 * @return array
	 */
	private static function call_tool( $id, array $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		if ( '' === $name ) {
			return self::error( $id, self::INVALID_PARAMS, 'Missing tool name.' );
		}

		Mcp_Tools::set_channel( 'mcp' );
		$result = Mcp_Tools::invoke( $name, $args );

		if ( is_wp_error( $result ) ) {
			// Tool-level failure is reported as a successful JSON-RPC
			// response with isError=true (per MCP), so the model can read
			// the message rather than the transport swallowing it.
			return self::result(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $result->get_error_message(),
						),
					),
					'isError' => true,
				)
			);
		}

		// A Cli_Bridge-backed tool reports command failure as ok:false inside
		// the payload. Without this, the envelope said isError:false and an
		// agent read "Could not connect to Redis" as a success.
		$failed = is_array( $result ) && array_key_exists( 'ok', $result ) && false === $result['ok'];

		return self::result(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
					),
				),
				'isError' => $failed,
			)
		);
	}

	// -- Auth --

	/**
	 * Validate the connection token from either the Authorization: Bearer
	 * header or X-XSpeed-MCP-Token. Reuses Mcp_Auth's constant-time check
	 * against the stored site_token.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return bool
	 */
	private static function authorize( \WP_REST_Request $request ): bool {
		$presented = self::extract_token( $request );
		if ( '' === $presented ) {
			return false;
		}

		// Path 1: the static per-site pairing token (Mcp_Pairing). Leave the
		// tool scope override cleared so Mcp_Tools defers to the pairing
		// token's own read-only scope. Credential writes over the pairing token
		// stay gated on the xspeed_mcp_allow_credential_writes filter (off by
		// default) — clear the configure override so that default applies. (#116)
		$stored = Mcp_Pairing::site_token();
		if ( '' !== $stored && hash_equals( $stored, $presented ) ) {
			Mcp_Tools::set_read_only_override( null );
			Mcp_Tools::set_configure_override( null );
			return true;
		}

		// Path 2: an OAuth 2.1 access token minted by Mcp_OAuth. Its own
		// granted scope decides read-only AND whether it may write credentials
		// (the explicit, opt-in `configure` scope), independent of any pairing
		// token.
		$grant = Mcp_OAuth::validate_token( $presented );
		if ( null !== $grant ) {
			Mcp_Tools::set_read_only_override( Mcp_OAuth::scope_is_read_only( $grant['scope'] ) );
			Mcp_Tools::set_configure_override( Mcp_OAuth::scope_allows_configure( $grant['scope'] ) );
			return true;
		}

		return false;
	}

	/**
	 * The RFC 9728 WWW-Authenticate challenge value. Points the client at
	 * this site's protected-resource metadata so an OAuth-capable client
	 * can discover the authorization server and begin the flow.
	 */
	private static function challenge_header(): string {
		return sprintf( 'Bearer resource_metadata="%s"', self::metadata_url() );
	}

	/**
	 * Where this site actually serves its protected-resource metadata.
	 *
	 * Prefers the canonical /.well-known/ URL, but many hosts own that prefix
	 * for ACME/Let's Encrypt and answer it before WordPress runs — the client
	 * then follows a pointer to a 404 (or a redirect to the homepage) and the
	 * OAuth flow dead-ends. RFC 9728 allows a single resource_metadata value,
	 * so when the pretty path is not ours to serve we advertise the /wp-json
	 * fallback, which no ACME tooling claims.
	 */
	private static function metadata_url(): string {
		$pretty = home_url( '/.well-known/oauth-protected-resource' );

		/**
		 * Filter the advertised protected-resource metadata URL.
		 *
		 * @param string $pretty The canonical /.well-known/ URL.
		 */
		$filtered = apply_filters( 'xspeed_mcp_resource_metadata_url', $pretty );
		if ( is_string( $filtered ) && '' !== $filtered && $filtered !== $pretty ) {
			return $filtered;
		}

		// Rewrites absent (plain permalinks, or a flush that never landed)
		// means the pretty URL cannot resolve at all — use the fallback.
		if ( ! McpModule::wellknown_rewrites_active() ) {
			return rest_url( McpModule::NS . '/mcp/.well-known/oauth-protected-resource' );
		}

		return $pretty;
	}

	/** Pull the token from Bearer or X-XSpeed-MCP-Token, Bearer wins. */
	private static function extract_token( \WP_REST_Request $request ): string {
		$auth = $request->get_header( 'authorization' );
		if ( is_string( $auth ) && preg_match( '/^Bearer\s+(.+)$/i', trim( $auth ), $m ) ) {
			return trim( $m[1] );
		}
		$header = $request->get_header( Mcp_Auth::TOKEN_HEADER );
		return is_string( $header ) ? trim( $header ) : '';
	}

	// -- JSON-RPC envelope helpers --

	/** Build a JSON-RPC success envelope. */
	private static function result( $id, $result ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/** Build a JSON-RPC error envelope (for a single message). */
	private static function error( $id, int $code, string $message ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/** Build a top-level error WP_REST_Response with an HTTP status. */
	private static function error_response( $id, int $code, string $message, int $http ): \WP_REST_Response {
		return new \WP_REST_Response( self::error( $id, $code, $message ), $http );
	}
}
