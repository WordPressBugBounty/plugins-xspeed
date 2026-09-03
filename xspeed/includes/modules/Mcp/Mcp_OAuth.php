<?php
/**
 * MCP OAuth 2.1 authorization server -- the "paste a URL only" connect path.
 *
 * The pairing token (Mcp_Pairing) covers clients that accept a pasted Bearer
 * token; this class covers spec-compliant MCP clients (e.g. the claude.ai
 * remote-connector flow) that take only the server URL and run the OAuth 2.1
 * authorization-code + PKCE flow themselves. See the security contract and
 * the end-to-end flow notes below.
 *
 * Flow: unauthenticated MCP call -> 401 + WWW-Authenticate (Mcp_Server) ->
 * client fetches /.well-known/oauth-protected-resource + oauth-authorization-
 * server -> dynamic registration (RFC 7591) -> /authorize (admin consent +
 * PKCE) -> /token (code + verifier -> access + refresh) -> MCP calls with
 * `Authorization: Bearer <access>` validated by validate_token().
 *
 * Security contract:
 *   - PKCE S256 REQUIRED (OAuth 2.1 public clients); codes are single-use,
 *     60 s TTL, bound to client_id + redirect_uri + challenge.
 *   - /authorize gates on manage_options -- only an admin can grant access,
 *     matching the pairing token's admin-only mint (anon -> wp-login first).
 *   - Access/refresh tokens stored only as SHA-256 hashes; raw value exists
 *     solely in the /token response. Constant-time comparison.
 *   - Tokens carry the read/write scope model; a read-only grant refuses
 *     every write tool, exactly like a read-only pairing token.
 *   - Off until an admin approves consent; a fresh install exposes discovery
 *     metadata but issues nothing.
 *
 * State lives in the `xspeed_mcp_oauth` option (clients, codes, tokens,
 * refresh -- keyed by id or sha256 of the secret); expired codes/tokens are
 * pruned lazily on every read.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

defined( 'ABSPATH' ) || exit;

final class Mcp_OAuth {

	/** Option key holding all OAuth server state. */
	public const OPTION = 'xspeed_mcp_oauth';

	/** Authorization-code lifetime (seconds). Deliberately short. */
	private const CODE_TTL = 60;

	/** Access-token lifetime (seconds) -- 1 hour, refreshable. */
	private const ACCESS_TTL = 3600;

	/** Refresh-token lifetime (seconds) -- 30 days. */
	private const REFRESH_TTL = 2592000;

	/**
	 * Scopes we advertise + honor. `mcp` is the umbrella scope MCP clients
	 * request (read+write). `configure` is an ADDITIONAL, opt-in scope that a
	 * client must request explicitly to write credential/secret fields — it is
	 * NOT implied by `mcp` or `write`, so credential writes stay off by default
	 * on an ordinary connection. (#116)
	 */
	private const SUPPORTED_SCOPES = array( 'mcp', 'read', 'write', 'configure' );

	/**
	 * Resource bounds for dynamic client registration (RFC 7591).
	 *
	 * The register endpoint is public by design — that is what makes an MCP
	 * client able to connect without an admin minting credentials first. What
	 * was missing is a resource policy: every request appended a client to one
	 * persistent option with no count, size, expiry or rate bound, so repeated
	 * anonymous requests grew `xspeed_mcp_oauth` without limit. Every later
	 * registration and OAuth operation then loaded and reserialized a larger
	 * option, burning storage, memory and CPU until availability degraded.
	 *
	 * Codes, access tokens and refresh tokens were already pruned on expiry;
	 * `clients` was the one collection retained forever.
	 */
	private const MAX_CLIENTS = 100;

	/** Redirect URIs accepted per registration. */
	private const MAX_REDIRECT_URIS = 5;

	/** Longest accepted redirect URI, in bytes. */
	private const MAX_REDIRECT_URI_LEN = 2048;

	/** Longest accepted client_name, in bytes. */
	private const MAX_CLIENT_NAME_LEN = 200;

	/**
	 * How long an UNUSED client survives. A client that never completes a
	 * flow is almost always an abandoned or hostile registration, so it is
	 * collected after this window. A client with a live code, access token or
	 * refresh token is never collected on age — see prune_clients().
	 */
	private const UNUSED_CLIENT_TTL = 86400;

	/** Registrations allowed per IP inside RATE_WINDOW. */
	private const RATE_MAX = 10;

	/** Window for the registration rate limit, in seconds. Fixed, not sliding. */
	private const RATE_WINDOW = 3600;

	/**
	 * Fixed number of rate-limit counters.
	 *
	 * One transient per IP would let a distributed flood grow the options
	 * TABLE without bound — the same CWE-770 shape this class exists to fix,
	 * moved from the option value to the row count. Hashing the IP into a
	 * fixed bucket space caps that at a constant, whatever the traffic.
	 * (QA F2 on #254)
	 */
	private const RATE_BUCKETS = 64;

	/**
	 * True while a caller is between state() and its own save().
	 *
	 * state() persists a self-heal prune on read-only paths, but a caller that
	 * is about to save() anyway would then write twice — and the second write
	 * would be against a $state it had already mutated. Callers that mutate
	 * set this so the read-side write stands down. (QA F1 on #254)
	 */
	private static $writing = false;

	// -- URLs ------------------------------------------------------------

	/** Base site URL used as the OAuth issuer (no trailing slash). */
	public static function issuer(): string {
		return untrailingslashit( home_url() );
	}

	/** The protected resource identifier -- the MCP endpoint URL. */
	public static function resource(): string {
		return Mcp_Pairing::site_endpoint();
	}

	/**
	 * The browser-facing authorize page. Served OUTSIDE the REST API (via a
	 * rewrite rule) so standard cookie auth works after the wp-login
	 * round-trip — a REST route would see the cookie without a nonce and
	 * treat the admin as logged-out, looping back to login.
	 */
	public static function authorize_url(): string {
		return home_url( '/xspeed/authorize' );
	}

	public static function token_url(): string {
		return rest_url( 'xspeed/v1/mcp/oauth/token' );
	}

	public static function register_url(): string {
		return rest_url( 'xspeed/v1/mcp/oauth/register' );
	}

	// -- Discovery documents (RFC 8414 / RFC 9728) -----------------------

	/**
	 * RFC 9728 protected-resource metadata -- tells the client which
	 * authorization server(s) protect the MCP endpoint (this site).
	 *
	 * @return array<string,mixed>
	 */
	public static function protected_resource_metadata(): array {
		return array(
			'resource'                => self::resource(),
			'authorization_servers'   => array( self::issuer() ),
			'scopes_supported'        => self::SUPPORTED_SCOPES,
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	/**
	 * RFC 8414 authorization-server metadata -- the endpoint map + the
	 * capabilities we actually implement (auth-code grant, PKCE S256,
	 * dynamic registration, refresh tokens).
	 *
	 * @return array<string,mixed>
	 */
	public static function authorization_server_metadata(): array {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::authorize_url(),
			'token_endpoint'                        => self::token_url(),
			'registration_endpoint'                 => self::register_url(),
			'scopes_supported'                      => self::SUPPORTED_SCOPES,
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
		);
	}

	// -- Dynamic client registration (RFC 7591) --------------------------

	/**
	 * Register a public client. We accept the client's redirect_uris and
	 * mint a client_id (no secret -- public clients rely on PKCE). Minimal
	 * metadata is echoed back per RFC 7591.
	 *
	 * @param array<string,mixed> $body Parsed JSON registration request.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function register_client( array $body ) {
		// CHECK the rate limit before any work — an over-limit caller must not
		// be able to make us read, mutate or reserialize the option at all.
		// The COUNT happens later, only once the payload has proven valid, so
		// a legitimate but buggy client sending malformed bodies is not locked
		// out for an hour over registrations that never stored anything.
		// (QA F3 on #254)
		if ( ! self::rate_limit_ok( false ) ) {
			return new \WP_Error(
				'too_many_requests',
				__( 'Too many client registrations. Try again later.', 'xspeed' ),
				array( 'status' => 429 )
			);
		}

		$raw = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] )
			? array_map( 'strval', $body['redirect_uris'] )
			: array();

		// Cap the count before validating, so a huge array costs a count()
		// rather than a full validation pass.
		if ( count( $raw ) > self::MAX_REDIRECT_URIS ) {
			return new \WP_Error(
				'invalid_redirect_uri',
				sprintf(
					/* translators: %d: maximum number of redirect URIs. */
					__( 'At most %d redirect_uris are allowed.', 'xspeed' ),
					self::MAX_REDIRECT_URIS
				),
				array( 'status' => 400 )
			);
		}

		$redirect_uris = array_values( array_filter( $raw, array( self::class, 'is_valid_redirect_uri' ) ) );

		if ( empty( $redirect_uris ) ) {
			return new \WP_Error(
				'invalid_redirect_uri',
				__( 'At least one valid redirect_uri is required.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}

		$name = isset( $body['client_name'] ) ? sanitize_text_field( (string) $body['client_name'] ) : 'MCP Client';
		if ( strlen( $name ) > self::MAX_CLIENT_NAME_LEN ) {
			// mb_strcut, NOT substr: the bound is in BYTES (that is what the
			// storage limit is about), but cutting at byte 200 lands inside a
			// multi-byte character for any CJK or emoji name — 200 is not a
			// multiple of 3 — and the result is invalid UTF-8. MySQL then
			// refuses the whole option write, so the registration was silently
			// dropped while the endpoint still answered 201 with a client_id
			// that had never been stored. mb_strcut keeps the byte budget and
			// never splits a character. (QA on #254)
			$name = function_exists( 'mb_strcut' )
				? mb_strcut( $name, 0, self::MAX_CLIENT_NAME_LEN, 'UTF-8' )
				: substr( $name, 0, self::MAX_CLIENT_NAME_LEN );
		}

		// The payload is valid, so this request counts against the window.
		// Deliberately AFTER validation (F3) but BEFORE the option read below,
		// so an over-limit caller still cannot make us touch the option.
		self::rate_limit_ok( true );

		$client_id = 'xsc_' . bin2hex( random_bytes( 16 ) );

		// state() has already pruned unused/expired clients on load.
		$state = self::state_for_write();

		// Hard cap. Pruning above already dropped unused and expired
		// registrations, so hitting this means MAX_CLIENTS clients are
		// genuinely in use — refuse rather than evict a live one, which would
		// break a working integration to satisfy an anonymous caller.
		if ( count( $state['clients'] ) >= self::MAX_CLIENTS ) {
			return new \WP_Error(
				'too_many_clients',
				__( 'Client registration limit reached.', 'xspeed' ),
				array( 'status' => 429 )
			);
		}

		$state['clients'][ $client_id ] = array(
			'redirect_uris' => $redirect_uris,
			'name'          => $name,
			'created'       => time(),
		);

		// A freshly-minted client_id always changes the option, so a false here
		// is a genuine write failure and never the "value unchanged" case. Fail
		// loudly: handing back a 201 and a client_id that was never stored
		// leaves the caller holding a credential that can never authorize, and
		// the only symptom is a confusing "Unknown client_id" much later.
		if ( ! self::save( $state ) ) {
			return new \WP_Error(
				'registration_failed',
				__( 'The client registration could not be stored.', 'xspeed' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'client_id'                => $client_id,
			'client_id_issued_at'      => time(),
			'redirect_uris'            => $redirect_uris,
			'client_name'              => $name,
			'token_endpoint_auth_method' => 'none',
			'grant_types'              => array( 'authorization_code', 'refresh_token' ),
			'response_types'           => array( 'code' ),
		);
	}

	// -- Authorization endpoint ------------------------------------------

	/**
	 * Validate an /authorize request's parameters WITHOUT issuing anything.
	 * Returns a sanitized param bag on success, or WP_Error on a protocol
	 * violation the client must fix. The caller (route handler) decides how
	 * to surface it (redirect vs error page) based on whether redirect_uri
	 * is trustworthy.
	 *
	 * @param array<string,string> $params Query params.
	 * @return array<string,string>|\WP_Error
	 */
	public static function validate_authorize_request( array $params ) {
		$client_id     = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';
		$redirect_uri  = isset( $params['redirect_uri'] ) ? (string) $params['redirect_uri'] : '';
		$response_type = isset( $params['response_type'] ) ? (string) $params['response_type'] : '';
		$challenge     = isset( $params['code_challenge'] ) ? (string) $params['code_challenge'] : '';
		$method        = isset( $params['code_challenge_method'] ) ? (string) $params['code_challenge_method'] : '';
		$scope         = isset( $params['scope'] ) ? (string) $params['scope'] : 'mcp';
		$state         = isset( $params['state'] ) ? (string) $params['state'] : '';

		$client = self::client( $client_id );
		if ( null === $client ) {
			return new \WP_Error( 'invalid_client', __( 'Unknown client_id.', 'xspeed' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
			// redirect_uri mismatch must NOT redirect (open-redirect guard).
			return new \WP_Error( 'invalid_redirect_uri', __( 'redirect_uri does not match a registered value.', 'xspeed' ), array( 'status' => 400 ) );
		}
		if ( 'code' !== $response_type ) {
			return new \WP_Error( 'unsupported_response_type', __( 'Only response_type=code is supported.', 'xspeed' ), array( 'status' => 400, 'redirectable' => true ) );
		}
		// OAuth 2.1: PKCE S256 is mandatory for public clients.
		if ( 'S256' !== $method || '' === $challenge ) {
			return new \WP_Error( 'invalid_request', __( 'PKCE with code_challenge_method=S256 is required.', 'xspeed' ), array( 'status' => 400, 'redirectable' => true ) );
		}

		return array(
			'client_id'     => $client_id,
			'client_name'   => $client['name'],
			'redirect_uri'  => $redirect_uri,
			'code_challenge' => $challenge,
			'scope'         => self::normalize_scope( $scope ),
			'state'         => $state,
		);
	}

	/**
	 * Issue an authorization code after the admin approves consent. Binds
	 * the code to the client, redirect_uri, PKCE challenge, granted scope,
	 * and the approving user. Single-use, 60 s TTL.
	 *
	 * @param array<string,string> $req  Output of validate_authorize_request().
	 * @param int                  $user_id Approving admin user id.
	 * @return string The authorization code.
	 */
	public static function issue_code( array $req, int $user_id ): string {
		$code  = bin2hex( random_bytes( 32 ) );
		$state = self::state_for_write();
		$state['codes'][ $code ] = array(
			'client_id'    => $req['client_id'],
			'redirect_uri' => $req['redirect_uri'],
			'challenge'    => $req['code_challenge'],
			'scope'        => $req['scope'],
			'user_id'      => $user_id,
			'expires'      => time() + self::CODE_TTL,
		);
		self::save( $state );
		return $code;
	}

	// -- Token endpoint --------------------------------------------------

	/**
	 * Exchange an authorization code (+ PKCE verifier) for tokens, or a
	 * refresh token for a fresh access token. Returns the RFC 6749 token
	 * response or a WP_Error whose data carries the OAuth error code.
	 *
	 * @param array<string,string> $body POST body params.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function exchange_token( array $body ) {
		$grant = isset( $body['grant_type'] ) ? (string) $body['grant_type'] : '';

		if ( 'authorization_code' === $grant ) {
			return self::grant_authorization_code( $body );
		}
		if ( 'refresh_token' === $grant ) {
			return self::grant_refresh_token( $body );
		}
		return self::oauth_error( 'unsupported_grant_type', 'Unsupported grant_type.' );
	}

	/**
	 * authorization_code grant: verify the code + PKCE, mint tokens.
	 *
	 * @param array<string,string> $body POST body.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function grant_authorization_code( array $body ) {
		$code          = isset( $body['code'] ) ? (string) $body['code'] : '';
		$client_id     = isset( $body['client_id'] ) ? (string) $body['client_id'] : '';
		$redirect_uri  = isset( $body['redirect_uri'] ) ? (string) $body['redirect_uri'] : '';
		$verifier      = isset( $body['code_verifier'] ) ? (string) $body['code_verifier'] : '';

		$state = self::state_for_write();
		if ( '' === $code || ! isset( $state['codes'][ $code ] ) ) {
			return self::oauth_error( 'invalid_grant', 'Unknown or expired authorization code.' );
		}
		$entry = $state['codes'][ $code ];

		// Single-use: remove immediately whether or not verification passes.
		unset( $state['codes'][ $code ] );
		self::save( $state );

		if ( $entry['expires'] < time() ) {
			return self::oauth_error( 'invalid_grant', 'Authorization code expired.' );
		}
		if ( ! hash_equals( (string) $entry['client_id'], $client_id ) ) {
			return self::oauth_error( 'invalid_grant', 'client_id mismatch.' );
		}
		if ( ! hash_equals( (string) $entry['redirect_uri'], $redirect_uri ) ) {
			return self::oauth_error( 'invalid_grant', 'redirect_uri mismatch.' );
		}
		// PKCE S256: BASE64URL(SHA256(verifier)) must equal the stored challenge.
		if ( '' === $verifier || ! hash_equals( (string) $entry['challenge'], self::s256( $verifier ) ) ) {
			return self::oauth_error( 'invalid_grant', 'PKCE verification failed.' );
		}

		return self::mint_tokens( $entry['client_id'], $entry['scope'], (int) $entry['user_id'] );
	}

	/**
	 * refresh_token grant: rotate the refresh token, issue a fresh access
	 * token. The old refresh + its access token are revoked.
	 *
	 * @param array<string,string> $body POST body.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function grant_refresh_token( array $body ) {
		$refresh   = isset( $body['refresh_token'] ) ? (string) $body['refresh_token'] : '';
		$client_id = isset( $body['client_id'] ) ? (string) $body['client_id'] : '';

		$state = self::state_for_write();
		$rhash = self::hash( $refresh );
		if ( '' === $refresh || ! isset( $state['refresh'][ $rhash ] ) ) {
			return self::oauth_error( 'invalid_grant', 'Unknown refresh token.' );
		}
		$entry = $state['refresh'][ $rhash ];
		if ( '' !== $client_id && ! hash_equals( (string) $entry['client_id'], $client_id ) ) {
			return self::oauth_error( 'invalid_grant', 'client_id mismatch.' );
		}

		// Rotate: drop old refresh + its access token.
		unset( $state['refresh'][ $rhash ] );
		if ( isset( $entry['access_hash'] ) ) {
			unset( $state['tokens'][ $entry['access_hash'] ] );
		}
		self::save( $state );

		return self::mint_tokens( $entry['client_id'], $entry['scope'], (int) $entry['user_id'] );
	}

	/**
	 * Mint an access + refresh token pair, store them hashed, and return
	 * the RFC 6749 token response with the raw values.
	 *
	 * @param string $client_id Client id.
	 * @param string $scope     Granted scope string.
	 * @param int    $user_id   Resource-owner user id.
	 * @return array<string,mixed>
	 */
	private static function mint_tokens( string $client_id, string $scope, int $user_id ): array {
		$access  = bin2hex( random_bytes( 32 ) );
		$refresh = bin2hex( random_bytes( 32 ) );
		$ahash   = self::hash( $access );
		$rhash   = self::hash( $refresh );

		$state                     = self::state_for_write();
		$state['tokens'][ $ahash ] = array(
			'client_id' => $client_id,
			'scope'     => $scope,
			'user_id'   => $user_id,
			'expires'   => time() + self::ACCESS_TTL,
			'refresh'   => $rhash,
		);
		$state['refresh'][ $rhash ] = array(
			'access_hash' => $ahash,
			'client_id'   => $client_id,
			'scope'       => $scope,
			'user_id'     => $user_id,
			'expires'     => time() + self::REFRESH_TTL,
		);
		self::save( $state );

		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope'         => $scope,
		);
	}

	// -- Access-token validation (called by Mcp_Server) ------------------

	/**
	 * Validate a bearer access token presented to the MCP endpoint.
	 * Returns the token's grant record (scope, user_id, client_id) when
	 * valid + unexpired, or null. Constant-time via hashed lookup.
	 *
	 * @param string $token Raw access token from the Authorization header.
	 * @return array{client_id:string,scope:string,user_id:int}|null
	 */
	public static function validate_token( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}
		$state = self::state();
		$hash  = self::hash( $token );
		if ( ! isset( $state['tokens'][ $hash ] ) ) {
			return null;
		}
		$entry = $state['tokens'][ $hash ];
		if ( (int) $entry['expires'] < time() ) {
			return null;
		}
		return array(
			'client_id' => (string) $entry['client_id'],
			'scope'     => (string) $entry['scope'],
			'user_id'   => (int) $entry['user_id'],
		);
	}

	/**
	 * Whether a granted scope string is read-only. `mcp` is the umbrella
	 * scope that grants read+write (matching a default pairing token), so
	 * only a grant that carries NEITHER `write` NOR `mcp` -- i.e. `read`
	 * alone -- is read-only.
	 */
	public static function scope_is_read_only( string $scope ): bool {
		$parts = preg_split( '/\s+/', trim( $scope ) ) ?: array();
		return ! in_array( 'write', $parts, true ) && ! in_array( 'mcp', $parts, true );
	}

	/**
	 * Whether a granted scope string may write credential/secret fields. Unlike
	 * read/write, `configure` is never implied by the `mcp` umbrella — the
	 * client must ask for it by name — so an ordinary read-write connection
	 * cannot rewrite API tokens or passwords. (#116)
	 */
	public static function scope_allows_configure( string $scope ): bool {
		$parts = preg_split( '/\s+/', trim( $scope ) ) ?: array();
		return in_array( 'configure', $parts, true );
	}

	/** Revoke every OAuth token + client (used by disconnect). */
	public static function revoke_all(): void {
		delete_option( self::OPTION );
	}

	// -- State + helpers -------------------------------------------------

	/**
	 * Load state with defaults, pruning expired codes/tokens/refresh
	 * entries on the way out so the option can't grow unbounded.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function state(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$state = array(
			'clients' => isset( $stored['clients'] ) && is_array( $stored['clients'] ) ? $stored['clients'] : array(),
			'codes'   => isset( $stored['codes'] ) && is_array( $stored['codes'] ) ? $stored['codes'] : array(),
			'tokens'  => isset( $stored['tokens'] ) && is_array( $stored['tokens'] ) ? $stored['tokens'] : array(),
			'refresh' => isset( $stored['refresh'] ) && is_array( $stored['refresh'] ) ? $stored['refresh'] : array(),
		);

		$now = time();
		foreach ( $state['codes'] as $k => $v ) {
			if ( ! isset( $v['expires'] ) || $v['expires'] < $now ) {
				unset( $state['codes'][ $k ] );
			}
		}
		foreach ( $state['tokens'] as $k => $v ) {
			if ( ! isset( $v['expires'] ) || $v['expires'] < $now ) {
				unset( $state['tokens'][ $k ] );
			}
		}
		foreach ( $state['refresh'] as $k => $v ) {
			if ( isset( $v['expires'] ) && $v['expires'] < $now ) {
				unset( $state['refresh'][ $k ] );
			}
		}

		// Clients were the one collection this method never pruned, which is
		// what let an anonymous caller grow the option without bound. Prune
		// them here too, AFTER the grant buckets above, so "in use" is
		// decided against live grants only.
		$before           = count( $state['clients'] );
		$state['clients'] = self::prune_clients( $state );

		// Persist when pruning ACTUALLY removed something. Pruning in memory
		// alone left a bloated option on disk until the next write happened to
		// land — so a site whose flood had stopped kept carrying the weight
		// indefinitely, which is not the self-heal this was described as.
		// (QA F1 on #254)
		//
		// Guarded on a real reduction, so the common case — nothing to prune —
		// stays a pure read and adds no write to an OAuth request. $writing
		// stops a caller that is about to save() anyway from writing twice.
		if ( ! self::$writing && count( $state['clients'] ) < $before ) {
			self::save( $state );
		}

		return $state;
	}

	/**
	 * Load state for a caller that intends to mutate and save() it.
	 *
	 * Identical to state(), except it suppresses the read-side self-heal
	 * write — the caller's own save() persists the same prune moments later,
	 * so doing it here would write twice per request. (QA F1 on #254)
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function state_for_write(): array {
		self::$writing = true;
		try {
			return self::state();
		} finally {
			self::$writing = false;
		}
	}

	/** Persist state (autoload off -- this is a hot-write, request-scoped option). */
	private static function save( array $state ): bool {
		// Report the outcome rather than discarding it. update_option() returns
		// false when the DB refuses the write — e.g. a value MySQL rejects as
		// invalid — and swallowing that let register_client() answer 201 with a
		// client_id it had never stored. A caller that mints a credential must
		// be able to tell a real write from a silent no-op. (QA on #254)
		//
		// Note update_option() also returns false when the value is UNCHANGED,
		// so this is "did not write", not "failed" — only callers that just
		// added something to $state may treat false as an error.
		return (bool) update_option( self::OPTION, $state, false );
	}

	/**
	 * Look up a registered client.
	 *
	 * @param string $client_id Client id.
	 * @return array{redirect_uris:string[],name:string,created:int}|null
	 */
	private static function client( string $client_id ): ?array {
		if ( '' === $client_id ) {
			return null;
		}
		$clients = self::state()['clients'];
		if ( ! isset( $clients[ $client_id ] ) || ! is_array( $clients[ $client_id ] ) ) {
			return null;
		}
		$c = $clients[ $client_id ];
		return array(
			'redirect_uris' => isset( $c['redirect_uris'] ) && is_array( $c['redirect_uris'] ) ? array_map( 'strval', $c['redirect_uris'] ) : array(),
			'name'          => isset( $c['name'] ) ? (string) $c['name'] : 'MCP Client',
			'created'       => isset( $c['created'] ) ? (int) $c['created'] : 0,
		);
	}

	/** SHA-256 hash used to store tokens at rest. */
	private static function hash( string $value ): string {
		return hash( 'sha256', $value );
	}

	/** BASE64URL(SHA256(verifier)) -- the PKCE S256 transformation. */
	private static function s256( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Constrain a requested scope to what we support. Defaults to `mcp`
	 * (read+write umbrella). An explicit `mcp:read` / `read`-only request
	 * yields a read-only grant.
	 */
	private static function normalize_scope( string $requested ): string {
		$parts = preg_split( '/\s+/', trim( $requested ) ) ?: array();
		$parts = array_values( array_intersect( $parts, self::SUPPORTED_SCOPES ) );
		if ( empty( $parts ) ) {
			return 'mcp';
		}
		return implode( ' ', $parts );
	}

	/** Whether a redirect_uri is structurally acceptable (http(s) or a custom scheme). */
	private static function is_valid_redirect_uri( string $uri ): bool {
		$uri = trim( $uri );
		if ( '' === $uri ) {
			return false;
		}
		// Bound the length: without this a single registration could carry
		// multi-megabyte URIs straight into the stored option.
		if ( strlen( $uri ) > self::MAX_REDIRECT_URI_LEN ) {
			return false;
		}
		// Allow standard web redirect URIs and native-client custom schemes.
		return (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $uri );
	}

	/**
	 * Drop registered clients that are neither in use nor recent.
	 *
	 * "In use" means the client still owns a live authorization code, access
	 * token or refresh token — state() has already pruned the expired ones, so
	 * whatever remains is genuinely live. Those are kept whatever their age; a
	 * connected client must never be collected out from under a working
	 * integration.
	 *
	 * Everything else is a registration that never completed a flow. Those are
	 * kept for UNUSED_CLIENT_TTL so a slow but legitimate authorization can
	 * finish, then collected.
	 *
	 * @param array<string,array<string,mixed>> $state Loaded state.
	 * @return array<string,mixed> The surviving clients.
	 */
	private static function prune_clients( array $state ): array {
		$clients = isset( $state['clients'] ) && is_array( $state['clients'] ) ? $state['clients'] : array();
		if ( empty( $clients ) ) {
			return array();
		}

		// Which client ids still hold live grants?
		$in_use = array();
		foreach ( array( 'codes', 'tokens', 'refresh' ) as $bucket ) {
			if ( empty( $state[ $bucket ] ) || ! is_array( $state[ $bucket ] ) ) {
				continue;
			}
			foreach ( $state[ $bucket ] as $entry ) {
				if ( is_array( $entry ) && ! empty( $entry['client_id'] ) ) {
					$in_use[ (string) $entry['client_id'] ] = true;
				}
			}
		}

		$cutoff = time() - self::UNUSED_CLIENT_TTL;
		foreach ( $clients as $id => $client ) {
			if ( isset( $in_use[ (string) $id ] ) ) {
				continue;
			}
			$created = ( is_array( $client ) && isset( $client['created'] ) ) ? (int) $client['created'] : 0;
			if ( $created < $cutoff ) {
				unset( $clients[ $id ] );
			}
		}

		return $clients;
	}

	/**
	 * Per-IP sliding-window rate limit for dynamic client registration.
	 *
	 * Deliberately mirrors Mcp_Rate_Limiter's approach (transient counter
	 * keyed on a hashed IP) rather than adding a dependency: that class gates
	 * failed *authentication*, while this gates *creation* of state, and the
	 * two must be tunable apart.
	 *
	 * Fails OPEN when the IP is unavailable — a proxy that hides REMOTE_ADDR
	 * must not lock every client out of registering.
	 */
	private static function rate_limit_ok( bool $count = true ): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return true;
		}

		/**
		 * Filter the dynamic-client-registration rate limit.
		 *
		 * @param int $max Registrations allowed per IP inside the window.
		 */
		$max = (int) apply_filters( 'xspeed_mcp_register_rate_limit', self::RATE_MAX );
		if ( $max <= 0 ) {
			return true;
		}

		// Bucket the IP into a FIXED key space instead of one transient per
		// IP. Per-IP keys meant a distributed flood grew the options TABLE
		// without bound — the same CWE-770 shape as the bug this class fixes,
		// just moved from the option value to the row count. RATE_BUCKETS
		// caps it at a constant: 64 counters, whatever the traffic.
		// (QA F2 on #254)
		//
		// Collisions make the limit stricter for the colliding IPs, never
		// looser, so the bound still holds. With 64 buckets a handful of
		// unrelated clients may share a counter; that is the deliberate trade
		// for a storage ceiling, and RATE_MAX is generous enough to absorb it.
		$bucket = hexdec( substr( md5( $ip ), 0, 4 ) ) % self::RATE_BUCKETS;
		$key    = 'xspeed_mcp_reg_' . $bucket;

		$entry = get_transient( $key );
		$now   = time();

		// Window START is stored with the counter, so the window is genuinely
		// FIXED rather than extending on every hit. set_transient()'s TTL was
		// previously reset on each accepted request, which quietly turned
		// "10 per hour" into "10, then locked until an hour after your LAST
		// attempt". (QA F4 on #254)
		if ( ! is_array( $entry ) || ! isset( $entry['start'], $entry['count'] ) || ( $now - (int) $entry['start'] ) >= self::RATE_WINDOW ) {
			$entry = array(
				'start' => $now,
				'count' => 0,
			);
		}

		if ( (int) $entry['count'] >= $max ) {
			return false;
		}

		// CHECK-only mode writes nothing. The caller re-invokes with $count
		// true once the payload has proven valid, so a malformed request
		// costs the caller nothing — it never consumed a slot and never
		// touched the database. (QA F3 on #254)
		if ( ! $count ) {
			return true;
		}

		++$entry['count'];

		// TTL covers only the REMAINDER of the current window, so the entry
		// expires when the window does instead of being pushed forward.
		$remaining = self::RATE_WINDOW - ( $now - (int) $entry['start'] );
		set_transient( $key, $entry, max( 1, $remaining ) );

		return true;
	}

	/**
	 * Build a WP_Error whose data carries an OAuth 2.0 `error` code so the
	 * token route can render the RFC 6749 error body.
	 *
	 * @param string $code    OAuth error code (invalid_grant, ...).
	 * @param string $message Human-readable description.
	 * @return \WP_Error
	 */
	private static function oauth_error( string $code, string $message ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array(
				'status'            => 400,
				'error'             => $code,
				'error_description' => $message,
			)
		);
	}
}
