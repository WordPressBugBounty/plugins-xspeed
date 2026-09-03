<?php
/**
 * Cloudflare — thin wrapper around the Cloudflare v4 API for purging,
 * dev-mode toggle, and zone verification.
 *
 * Authentication: two supported modes —
 *   token   → Authorization: Bearer <api_token>  (preferred — scoped)
 *   key     → X-Auth-Email + X-Auth-Key          (legacy "Global API Key")
 *
 * We don't store or send the auth headers anywhere outside the
 * outgoing API request. No logging. The Free tier exposes Auto Purge
 * on Update + dev mode + manual purge; APO / Edge Cache TTL stay in
 * the Pro plugin per FEATURES.md "Cloudflare Integration" §8-10.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Cloudflare {

	private const API_BASE = 'https://api.cloudflare.com/client/v4';

	/**
	 * Verify the configured credentials by hitting GET /zones/{id}.
	 * Returns parsed zone payload on success.
	 *
	 * @return array{ok:bool,status:int,body:array}
	 */
	public static function verify( array $opts ): array {
		$zone = (string) ( $opts['zone_id'] ?? '' );
		if ( '' === $zone ) {
			return self::fail( 0, 'Zone ID is empty.' );
		}
		return self::request( $opts, 'GET', '/zones/' . rawurlencode( $zone ) );
	}

	/**
	 * Purge everything in the configured zone. Equivalent to clicking
	 * "Purge Everything" in the Cloudflare dashboard.
	 *
	 * @return array{ok:bool,status:int,body:array}
	 */
	public static function purge_all( array $opts ): array {
		$zone = (string) ( $opts['zone_id'] ?? '' );
		if ( '' === $zone ) {
			return self::fail( 0, 'Zone ID is empty.' );
		}
		return self::request(
			$opts,
			'POST',
			'/zones/' . rawurlencode( $zone ) . '/purge_cache',
			array( 'purge_everything' => true )
		);
	}

	/**
	 * Purge a specific list of URLs. CF accepts up to 30 per call;
	 * caller should chunk if it has more.
	 *
	 * @param string[] $urls
	 * @return array{ok:bool,status:int,body:array}
	 */
	public static function purge_urls( array $opts, array $urls ): array {
		$urls = array_values( array_filter( array_map( 'strval', $urls ) ) );
		if ( empty( $urls ) ) {
			return self::fail( 0, 'No URLs supplied.' );
		}
		$zone = (string) ( $opts['zone_id'] ?? '' );
		if ( '' === $zone ) {
			return self::fail( 0, 'Zone ID is empty.' );
		}
		return self::request(
			$opts,
			'POST',
			'/zones/' . rawurlencode( $zone ) . '/purge_cache',
			array( 'files' => array_slice( $urls, 0, 30 ) )
		);
	}

	/**
	 * Flip development mode on or off. CF auto-disables it after 3
	 * hours; that's the user's behavior, not something we model here.
	 *
	 * @return array{ok:bool,status:int,body:array}
	 */
	public static function set_dev_mode( array $opts, bool $on ): array {
		$zone = (string) ( $opts['zone_id'] ?? '' );
		if ( '' === $zone ) {
			return self::fail( 0, 'Zone ID is empty.' );
		}
		return self::request(
			$opts,
			'PATCH',
			'/zones/' . rawurlencode( $zone ) . '/settings/development_mode',
			array( 'value' => $on ? 'on' : 'off' )
		);
	}

	/**
	 * Build the auth + content headers based on the selected auth_method.
	 * Public for tests — they assert the right header pair is set.
	 */
	public static function build_headers( array $opts ): array {
		$method = (string) ( $opts['auth_method'] ?? 'token' );
		$base   = array( 'Content-Type' => 'application/json' );
		if ( 'token' === $method ) {
			$token = (string) ( $opts['api_token'] ?? '' );
			if ( '' === $token ) {
				return $base;
			}
			$base['Authorization'] = 'Bearer ' . $token;
			return $base;
		}
		// key mode.
		$email = (string) ( $opts['email'] ?? '' );
		$key   = (string) ( $opts['api_key'] ?? '' );
		if ( '' !== $email && '' !== $key ) {
			$base['X-Auth-Email'] = $email;
			$base['X-Auth-Key']   = $key;
		}
		return $base;
	}

	/**
	 * Core request helper. Returns a normalized envelope:
	 *   ok      → true when HTTP < 400 AND CF body { success: true }.
	 *   status  → HTTP code (0 on transport failure).
	 *   body    → decoded JSON or [ 'message' => $err ] on failure.
	 */
	private static function request( array $opts, string $method, string $path, ?array $payload = null ): array {
		$headers = self::build_headers( $opts );
		$args    = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 12,
		);
		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}
		$url = self::API_BASE . $path;

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return self::fail( 0, $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$decoded = is_string( $body ) ? json_decode( $body, true ) : null;
		if ( ! is_array( $decoded ) ) {
			$decoded = array( 'message' => is_string( $body ) ? $body : 'Unparseable response' );
		}
		$ok = ( $status < 400 ) && ! empty( $decoded['success'] );
		return array(
			'ok'     => $ok,
			'status' => $status,
			'body'   => $decoded,
		);
	}

	private static function fail( int $status, string $message ): array {
		return array(
			'ok'     => false,
			'status' => $status,
			'body'   => array( 'success' => false, 'message' => $message ),
		);
	}
}
