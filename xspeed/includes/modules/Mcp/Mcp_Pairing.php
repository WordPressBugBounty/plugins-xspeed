<?php
/**
 * MCP pairing lifecycle — the site side of the hosted-broker handshake.
 *
 * Flow (see IMPLEMENTATION.md §17):
 *   1. Admin clicks "Connect AI" in the dashboard.
 *   2. connect() mints a 32-byte `site_token`, POSTs it to the broker's
 *      /pair endpoint together with this site's URL + the Pro license
 *      key. The broker verifies the license against api.wpdeveloper.com,
 *      stores { connection_token → site_url + site_token }, and returns
 *      the `connection_token` the user pastes into their AI client.
 *   3. The broker thereafter proxies MCP tool calls to this site's
 *      xspeed/v1 REST routes, presenting `site_token` in the
 *      X-XSpeed-MCP-Token header (validated by Mcp_Auth).
 *   4. disconnect() clears the local token and asks the broker to revoke
 *      the pairing, so a leaked token dies immediately.
 *
 * The plugin is the only party that legitimately holds BOTH the license
 * key and the canonical site URL, so it is the correct place to
 * initiate pairing — this is a root design, not a workaround.
 *
 * State is stored in the `xspeed_module_mcp` option:
 *   {
 *     site_token:      string (secret; the broker's credential to us),
 *     connection_token:string (what the user pastes into their AI client),
 *     connected:       bool,
 *     connected_at:    int (unix ts),
 *     scopes:          string[] (e.g. ['read','write'])
 *   }
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

defined( 'ABSPATH' ) || exit;

final class Mcp_Pairing {

	/** Option key holding all MCP pairing state. */
	public const OPTION = 'xspeed_module_mcp';

	/**
	 * Optional hosted broker base URL, for the single-vanity-URL path.
	 * Overridable via the XSPEED_MCP_BROKER_URL constant (wp-config.php)
	 * and the `xspeed_mcp_broker_url` filter. The broker is NOT required —
	 * the primary path is this site's own endpoint (site_endpoint()).
	 */
	public const DEFAULT_BROKER = 'https://api.xspeedcache.com';

	/** Path segment of the pretty per-site endpoint. */
	public const SITE_ENDPOINT_PATH = 'xspeed/mcp';

	/** Default scopes granted on connect. */
	private const DEFAULT_SCOPES = array( 'read', 'write' );

	/**
	 * The PRIMARY endpoint the user pastes into their AI client — this
	 * site's own MCP URL. No hosted infra involved.
	 */
	public static function site_endpoint(): string {
		return home_url( '/' . self::SITE_ENDPOINT_PATH );
	}

	/**
	 * Always-on fallback endpoint via the REST namespace, for hosts where
	 * the pretty rewrite can't be served (e.g. plain permalinks).
	 */
	public static function site_endpoint_fallback(): string {
		return rest_url( 'xspeed/v1/mcp' );
	}

	/**
	 * The SINGLE URL the user pastes into their AI client — the pretty
	 * endpoint with the connection token embedded as a path segment. No
	 * separate token field needed. Empty string when not connected.
	 */
	public static function connect_url(): string {
		$token = self::site_token();
		if ( '' === $token ) {
			return '';
		}
		return self::site_endpoint() . '/' . $token;
	}

	/**
	 * Resolve the optional broker base URL (no trailing slash).
	 */
	public static function broker_url(): string {
		$url = defined( 'XSPEED_MCP_BROKER_URL' ) ? (string) \XSPEED_MCP_BROKER_URL : self::DEFAULT_BROKER;
		/** Filter the MCP broker base URL. */
		$url = (string) apply_filters( 'xspeed_mcp_broker_url', $url );
		return untrailingslashit( $url );
	}

	/**
	 * Current pairing state, defaults merged.
	 *
	 * @return array{site_token:string,connection_token:string,connected:bool,connected_at:int,scopes:string[]}
	 */
	public static function state(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array(
			'site_token'       => isset( $stored['site_token'] ) ? (string) $stored['site_token'] : '',
			'connection_token' => isset( $stored['connection_token'] ) ? (string) $stored['connection_token'] : '',
			'connected'        => ! empty( $stored['connected'] ),
			'connected_at'     => isset( $stored['connected_at'] ) ? (int) $stored['connected_at'] : 0,
			'scopes'           => isset( $stored['scopes'] ) && is_array( $stored['scopes'] )
				? array_values( array_map( 'strval', $stored['scopes'] ) )
				: array(),
		);
	}

	/** The stored site token (secret). Empty string when not connected. */
	public static function site_token(): string {
		return self::state()['site_token'];
	}

	/** Whether an MCP connection token is currently active for this site. */
	public static function is_connected(): bool {
		$state = self::state();
		return $state['connected'] && '' !== $state['site_token'];
	}

	/**
	 * Sanitized snapshot for the dashboard panel.
	 *
	 * In the per-site model the token the user pastes IS the site_token
	 * (the plugin validates it directly). We surface it as
	 * `connection_token`. The site's own endpoint is primary; the broker
	 * endpoint is offered only as an optional alternative.
	 *
	 * @return array<string,mixed>
	 */
	public static function public_status(): array {
		$state = self::state();
		return array(
			'connected'         => self::is_connected(),
			'connection_token'  => $state['connection_token'],
			// The single paste-in URL (token embedded). Convenient fallback.
			'connect_url'       => self::connect_url(),
			'mcp_endpoint'      => self::site_endpoint(),
			'mcp_endpoint_rest' => self::site_endpoint_fallback(),
			'broker_endpoint'   => self::broker_url() . '/mcp',
			'connected_at'      => $state['connected_at'],
			'scopes'            => $state['scopes'],
			'read_only'         => self::is_read_only(),
			// Ready-to-paste connection recipes (header-based — token never in
			// the URL, so it can't leak into server/proxy logs). Empty when
			// not connected.
			'config'            => self::config_snippets(),
			// A drop-in instruction the user can paste into their AI client so
			// it knows what it's connected to and how to behave.
			'ai_prompt'         => self::ai_prompt(),
			// The tool catalog (name + short description + whether it writes),
			// so the panel can show the user exactly what the AI can do.
			'tools'             => self::tools_summary(),
		);
	}

	/**
	 * Compact tool catalog for the dashboard: each tool's name, a short
	 * description, and whether it mutates state (write). Mirrors the same
	 * catalog the MCP `tools/list` call returns, so the panel can never drift
	 * from what an AI client actually sees.
	 *
	 * @return array<int,array{name:string,description:string,write:bool}>
	 */
	public static function tools_summary(): array {
		if ( ! class_exists( __NAMESPACE__ . '\\Mcp_Tools' ) ) {
			return array();
		}
		$out = array();
		foreach ( Mcp_Tools::catalog() as $name => $spec ) {
			$out[] = array(
				'name'        => (string) $name,
				'description' => isset( $spec['description'] ) ? (string) $spec['description'] : '',
				'write'       => ! empty( $spec['write'] ),
			);
		}
		return $out;
	}

	/**
	 * Ready-to-paste connection recipes for the dashboard. All header-based
	 * (Authorization: Bearer) so the secret stays out of URLs and logs.
	 * Empty strings when not connected.
	 *
	 * @return array{cli:string,json:string}
	 */
	public static function config_snippets(): array {
		$token = self::site_token();
		if ( '' === $token ) {
			return array(
				'cli'  => '',
				'json' => '',
			);
		}
		$endpoint = self::site_endpoint();
		$name     = self::server_name();

		// Claude Code one-liner. The CLI requires the positional NAME and URL
		// BEFORE any flags (`claude mcp add <name> <url> --flags`); putting
		// --transport first fails with "missing required argument 'name'".
		$cli = sprintf(
			'claude mcp add %s %s --transport http --header "Authorization: Bearer %s"',
			$name,
			$endpoint,
			$token
		);

		// Portable mcpServers JSON block (Claude Desktop / other clients).
		// `type: http` declares the Streamable-HTTP transport explicitly —
		// clients that default to stdio otherwise fail to connect.
		$json = wp_json_encode(
			array(
				'mcpServers' => array(
					$name => array(
						'type'    => 'http',
						'url'     => $endpoint,
						'headers' => array(
							'Authorization' => 'Bearer ' . $token,
						),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		return array(
			'cli'  => $cli,
			'json' => is_string( $json ) ? $json : '',
		);
	}

	/**
	 * A per-SITE MCP server name so a user can connect MANY sites to the same
	 * AI client without a name collision. `claude mcp add xspeed …` hardcoded
	 * "xspeed" for every site, so the second site failed with "server xspeed
	 * already exists". We derive `xspeed-<label>` from the FIRST label of the
	 * site host (e.g. `xspeedproaudit.emon.info` → `xspeed-xspeedproaudit`) —
	 * short + readable, sanitized to the simple identifier MCP clients accept
	 * (lowercase, digits, single hyphens).
	 *
	 * Overridable via the `xspeed_mcp_server_name` filter for white-label or
	 * multi-connection setups.
	 */
	public static function server_name(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		// Drop a leading www. so www.foo.com and foo.com read the same.
		$host = preg_replace( '/^www\./i', '', $host );
		// Use just the FIRST label of the host — the distinctive part — so the
		// name stays short (`xspeed-<label>`) instead of the full dotted host.
		$label = explode( '.', $host )[0];
		// Collapse anything that isn't a-z/0-9 into single hyphens.
		$slug = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $label ) );
		$slug = trim( $slug, '-' );

		$name = '' !== $slug ? 'xspeed-' . $slug : 'xspeed';

		/**
		 * Filter the per-site MCP server name shown in the connection recipes.
		 *
		 * @param string $name Default `xspeed-<host>`.
		 * @param string $host The site host the name was derived from.
		 */
		return (string) apply_filters( 'xspeed_mcp_server_name', $name, $host );
	}

	/**
	 * A copy-paste instruction the user hands to their AI assistant so IT
	 * sets up the xSpeed MCP connection for them (the assistant walks the
	 * user through adding the server to whatever client they use). Formatted
	 * as multi-line labeled fields — server URL, auth type, API key — not a
	 * paragraph. Empty when not connected.
	 */
	public static function ai_prompt(): string {
		if ( ! self::is_connected() ) {
			return '';
		}
		$token    = self::site_token();
		$endpoint = self::site_endpoint();
		$access   = self::is_read_only() ? 'read-only (inspect status & settings only)' : 'read-write (can purge, toggle caching, and change settings)';

		$lines = array(
			'Add the following remote MCP server to your connections so you can manage the cache on my WordPress site, then use it.',
			'',
			'Server name: ' . self::server_name(),
			'Server URL: ' . $endpoint,
			'Transport: streamable HTTP',
			'Authentication: Bearer token (in the Authorization header)',
			'API key: ' . $token,
			'Access level: ' . $access,
			'',
			'If you use the Claude Code CLI, this is the exact command (name and URL come BEFORE the flags):',
			'  ' . self::config_snippets()['cli'],
			'',
			'Add it now, confirm it is connected by calling its "get_cache_status" tool, and tell me the current cache status.',
		);
		$prompt = implode( "\n", $lines );

		/**
		 * Filter the copy-paste AI setup prompt shown in the MCP panel.
		 *
		 * @param string $prompt    The default prompt text.
		 * @param bool   $read_only Whether the connection is read-only.
		 */
		return (string) apply_filters( 'xspeed_mcp_ai_prompt', $prompt, self::is_read_only() );
	}

	/**
	 * Connect — mint a connection token for this site's own MCP endpoint.
	 *
	 * Per-site model: NO hosted broker is involved. The token the user
	 * pastes into their AI client IS the credential the plugin validates
	 * directly (site_token === connection_token). Idempotent: re-connecting
	 * keeps the existing token so a paired client isn't silently broken.
	 *
	 * @param bool $read_only When true, grant only the `read` scope — the AI
	 *                        can inspect state but every write tool (purge,
	 *                        toggle, settings changes, cleans) is refused.
	 *                        Only applied when minting a NEW token; a
	 *                        re-connect preserves the existing scopes so an
	 *                        already-paired client's access doesn't silently
	 *                        change. Use rotate() to change scopes.
	 * @return array<string,mixed> Public status on success.
	 */
	public static function connect( bool $read_only = false ) {
		// Reuse an existing token so re-connecting doesn't break a client
		// that's already paired; otherwise mint a fresh 32-byte secret.
		$state    = self::state();
		$existing = '' !== $state['site_token'];
		$token    = $existing ? $state['site_token'] : self::mint_token();
		// Preserve scopes on re-connect; only a fresh token honors read_only.
		$scopes = $existing && ! empty( $state['scopes'] )
			? $state['scopes']
			: self::scopes_for( $read_only );

		update_option(
			self::OPTION,
			array(
				// site_token (what the plugin checks) and connection_token
				// (what the user pastes) are the same secret in this model.
				'site_token'       => $token,
				'connection_token' => $token,
				'connected'        => true,
				'connected_at'     => $existing ? $state['connected_at'] : time(),
				'scopes'           => $scopes,
			),
			false
		);

		return self::public_status();
	}

	/**
	 * Rotate — mint a BRAND-NEW token, invalidating the previous one
	 * immediately (any client still using the old token gets 401 on its next
	 * call). This is the leaked-token remedy: unlike connect(), it never
	 * reuses the existing secret. Optionally also flips the read-only scope.
	 *
	 * @param bool|null $read_only null = keep current scopes; true/false =
	 *                             set read-only on/off for the new token.
	 * @return array<string,mixed> Public status with the fresh token.
	 */
	public static function rotate( ?bool $read_only = null ): array {
		$state  = self::state();
		$scopes = null === $read_only
			? ( ! empty( $state['scopes'] ) ? $state['scopes'] : self::DEFAULT_SCOPES )
			: self::scopes_for( $read_only );
		$token  = self::mint_token();

		update_option(
			self::OPTION,
			array(
				'site_token'       => $token,
				'connection_token' => $token,
				'connected'        => true,
				'connected_at'     => time(),
				'scopes'           => $scopes,
			),
			false
		);

		return self::public_status();
	}

	/**
	 * Change the access level of the CURRENT connection without minting a new
	 * token — the paired client keeps working, only its allowed tools change.
	 * Unlike rotate() (which invalidates the token), this is for flipping
	 * read-only on/off on a live connection from the dashboard. No-op with a
	 * WP_Error if nothing is connected.
	 *
	 * @param bool $read_only true = grant only `read`; false = read & write.
	 * @return array<string,mixed>|\WP_Error Public status, or error if not connected.
	 */
	public static function set_read_only( bool $read_only ) {
		$state = self::state();
		if ( '' === $state['site_token'] ) {
			return new \WP_Error(
				'xspeed_mcp_not_connected',
				__( 'No active MCP connection to change. Connect first.', 'xspeed' ),
				array( 'status' => 409 )
			);
		}

		update_option(
			self::OPTION,
			array(
				'site_token'       => $state['site_token'],
				'connection_token' => $state['connection_token'],
				'connected'        => true,
				'connected_at'     => $state['connected_at'],
				'scopes'           => self::scopes_for( $read_only ),
			),
			false
		);

		return self::public_status();
	}

	/** Whether the active connection is limited to read-only tools. */
	public static function is_read_only(): bool {
		$scopes = self::state()['scopes'];
		return ! in_array( 'write', $scopes, true );
	}

	/** Map a read-only flag to the granted scope list. */
	private static function scopes_for( bool $read_only ): array {
		return $read_only ? array( 'read' ) : self::DEFAULT_SCOPES;
	}

	/**
	 * Disconnect — revoke the connection token. Any AI client using it
	 * immediately loses access on the next call (Mcp_Server/Mcp_Auth deny
	 * once the stored token is gone).
	 *
	 * @return array<string,mixed> Public status after disconnect.
	 */
	public static function disconnect(): array {
		delete_option( self::OPTION );

		// Also revoke every OAuth grant (clients, codes, access + refresh
		// tokens) so "Disconnect" is a single kill switch for ALL MCP access,
		// not just the pasted pairing token.
		Mcp_OAuth::revoke_all();

		return self::public_status();
	}

	/** Mint a 32-byte URL-safe-ish random token (64 hex chars). */
	private static function mint_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
