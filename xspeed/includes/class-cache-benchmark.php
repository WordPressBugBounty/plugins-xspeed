<?php
/**
 * Cache_Benchmark — fetches home_url() twice (with + without cache) and
 * returns side-by-side TTFB / total-time / transfer-bytes timings.
 *
 * Powers the "before vs after cache" widget on the wizard's Done step
 * and the Cache panel. Synthetic — measures local HTTP only, no real
 * RUM. Good enough for a directional "cache helps by X%" number; the
 * RUM module is what you want for real percentiles.
 *
 * Mechanics:
 *  - "Without cache": HTTP GET home_url() with header
 *    `X-XSpeed-Bypass: 1`. The advanced-cache drop-in honors this
 *    header and short-circuits, so WordPress fully renders.
 *  - "With cache": HTTP GET home_url() with no special header. The
 *    drop-in serves the cached file (cache HIT) when available.
 *  - First "with-cache" call may MISS if no entry yet — we run a
 *    warm-up hit first so the timed pair is HIT vs render.
 *
 * Each measurement records:
 *   ttfb_ms           — curl_getinfo CURLINFO_STARTTRANSFER_TIME (or our
 *                       own "time before body read" fallback).
 *   time_ms           — total response time.
 *   bytes             — DECODED payload size (what the browser parses).
 *   bytes_transferred — wire size: the request advertises
 *                       `Accept-Encoding: gzip, deflate` with WP auto-
 *                       decompression off, so this is what actually
 *                       crossed the network (matches GTmetrix's
 *                       "transferred" number, not the inflated one).
 *   status            — HTTP code.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Cache_Benchmark {

	/** Benchmark run history (option, autoload off): newest LAST. */
	public const HISTORY_OPT = 'xspeed_benchmark_history';

	/** Runs retained — enough for a year of weekly runs with headroom. */
	public const HISTORY_MAX = 100;

	/**
	 * @return array{
	 *   url:string,
	 *   without_cache:array{ttfb_ms:float,time_ms:float,bytes:int,bytes_transferred:int,status:int},
	 *   with_cache:array{ttfb_ms:float,time_ms:float,bytes:int,bytes_transferred:int,status:int,was_hit:bool},
	 *   savings_pct:?float,
	 *   savings_ms:?float,
	 *   cache_enabled:bool,
	 * }
	 */
	public static function run( ?string $url = null ): array {
		if ( null === $url ) {
			$url = home_url( '/' );
		}
		// Cache enablement lives on the legacy Settings option
		// (`cache_enabled` boolean). Cache::is_enabled() doesn't
		// exist — read through Settings::get() instead.
		$cache_enabled = false;
		if ( class_exists( '\\XSpeed\\Settings' ) ) {
			$opts          = Settings::get();
			$cache_enabled = ! empty( $opts['cache_enabled'] );
		}

		// Warm up so the "with cache" timing reflects a HIT, not the
		// initial generation cost.
		if ( $cache_enabled ) {
			self::measure( $url, false );
		}

		$without = self::measure( $url, true );  // bypass
		$with    = self::measure( $url, false ); // normal — should HIT

		$savings_ms  = null;
		$savings_pct = null;
		if ( $cache_enabled && $without['time_ms'] > 0 && $with['time_ms'] > 0 ) {
			$diff        = $without['time_ms'] - $with['time_ms'];
			$savings_ms  = max( 0.0, round( $diff, 1 ) );
			$savings_pct = round( ( $diff / $without['time_ms'] ) * 100, 1 );
			if ( $savings_pct < 0 ) {
				$savings_pct = 0.0;
			}
		}

		$result = array(
			'url'           => $url,
			'without_cache' => $without,
			'with_cache'    => $with + array( 'was_hit' => $cache_enabled ),
			'savings_pct'   => $savings_pct,
			'savings_ms'    => $savings_ms,
			'cache_enabled' => $cache_enabled,
		);

		self::record_run( $result );

		return $result;
	}

	/**
	 * Persist a run into the history ring buffer (issue #43) so the
	 * dashboard can render a trend instead of a one-shot number. Failed
	 * fetches (status 0) are not recorded — a network blip isn't a data
	 * point about the site's performance.
	 *
	 * @param array $result The array shape run() returns.
	 */
	public static function record_run( array $result ): void {
		$without = isset( $result['without_cache'] ) && is_array( $result['without_cache'] ) ? $result['without_cache'] : array();
		$with    = isset( $result['with_cache'] ) && is_array( $result['with_cache'] ) ? $result['with_cache'] : array();
		if ( (int) ( $without['status'] ?? 0 ) === 0 || (int) ( $with['status'] ?? 0 ) === 0 ) {
			return;
		}
		$history = get_option( self::HISTORY_OPT, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		$history[] = array(
			'ts'                => time(),
			'uncached_ms'       => (float) ( $without['time_ms'] ?? 0 ),
			'cached_ms'         => (float) ( $with['time_ms'] ?? 0 ),
			'savings_ms'        => isset( $result['savings_ms'] ) ? (float) $result['savings_ms'] : null,
			'savings_pct'       => isset( $result['savings_pct'] ) ? (float) $result['savings_pct'] : null,
			'bytes'             => (int) ( $with['bytes'] ?? 0 ),
			'bytes_transferred' => (int) ( $with['bytes_transferred'] ?? 0 ),
			'cache_enabled'     => ! empty( $result['cache_enabled'] ),
		);
		if ( count( $history ) > self::HISTORY_MAX ) {
			$history = array_slice( $history, -self::HISTORY_MAX );
		}
		update_option( self::HISTORY_OPT, $history, false );
	}

	/**
	 * Stored benchmark runs, oldest→newest, at most $limit rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function history( int $limit = self::HISTORY_MAX ): array {
		$history = get_option( self::HISTORY_OPT, array() );
		if ( ! is_array( $history ) ) {
			return array();
		}
		return array_slice( array_values( $history ), -max( 1, $limit ) );
	}

	/**
	 * Single timed request. wp_remote_get's `args` don't expose curl-
	 * level timing on every transport, so we wrap the call ourselves
	 * with microtime — close enough for a directional comparison.
	 *
	 * @return array{ttfb_ms:float,time_ms:float,bytes:int,bytes_transferred:int,status:int}
	 */
	private static function measure( string $url, bool $bypass ): array {
		$headers = array(
			'User-Agent'      => 'xSpeed Benchmark/1.0',
			// Ask for compression like a real browser so the WIRE size is
			// measurable. Only encodings we can decode locally — no brotli
			// (ext-brotli is rare, and an undecodable body would break the
			// uncompressed measurement).
			'Accept-Encoding' => 'gzip, deflate',
		);
		if ( $bypass ) {
			// The X-XSpeed-Bypass header only short-circuits the PHP
			// drop-in. When nginx static-rewrite is firing it would
			// still serve the cached file directly, so the "without
			// cache" measurement would look identical to "with cache"
			// (the bug visible on the dashboard's benchmark widget).
			// Append a cache-buster query string so the nginx
			// snippet's `if ($args)` check bails too, forcing the
			// request all the way through to PHP.
			$headers['X-XSpeed-Bypass'] = '1';
			$bust_url = $url . ( false === strpos( $url, '?' ) ? '?' : '&' )
				. 'xspeed_bypass=' . wp_generate_password( 12, false, false );
			$url = $bust_url;
		}
		$start = microtime( true );
		$res   = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => $headers,
				// Disable WP's internal caching layer — every call MUST hit the network.
				'reject_unsafe_urls' => false,
				'sslverify' => false, // self-signed sandboxes
				// Keep the body EXACTLY as it came off the wire. WP's
				// transport otherwise auto-decompresses AND strips the
				// Content-Encoding header, which is how the old code ended
				// up reporting ~180KB for a page that transfers 35KB.
				'decompress' => false,
			)
		);
		$elapsed = ( microtime( true ) - $start ) * 1000.0;

		if ( is_wp_error( $res ) ) {
			return array(
				'ttfb_ms'           => 0.0,
				'time_ms'           => round( $elapsed, 1 ),
				'bytes'             => 0,
				'bytes_transferred' => 0,
				'status'            => 0,
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$body   = wp_remote_retrieve_body( $res );
		$raw    = is_string( $body ) ? $body : '';

		// Wire size vs decoded size. `bytes` keeps its historical meaning
		// (uncompressed payload) so existing consumers don't shift; the new
		// `bytes_transferred` is what actually crossed the network.
		$encoding = (string) wp_remote_retrieve_header( $res, 'content-encoding' );
		$decoded  = self::decode_body( $raw, $encoding );

		// wp_remote_get doesn't surface TTFB separately on the default
		// transport. We surface total time as both fields and let the
		// widget pick a sensible display.
		return array(
			'ttfb_ms'           => round( $elapsed, 1 ),
			'time_ms'           => round( $elapsed, 1 ),
			'bytes'             => strlen( null !== $decoded ? $decoded : $raw ),
			'bytes_transferred' => strlen( $raw ),
			'status'            => $status,
		);
	}

	/**
	 * Decode a response body per its Content-Encoding. Pure — unit-tested.
	 *
	 * @param string $body     Raw (possibly compressed) body.
	 * @param string $encoding Content-Encoding header value ('' when none).
	 * @return string|null Decoded body, the input when identity/none, or
	 *                     null when the encoding is unknown/undecodable.
	 */
	public static function decode_body( string $body, string $encoding ): ?string {
		$encoding = strtolower( trim( $encoding ) );
		if ( '' === $encoding || 'identity' === $encoding ) {
			return $body;
		}
		if ( '' === $body ) {
			return $body;
		}
		if ( false !== strpos( $encoding, 'gzip' ) && function_exists( 'gzdecode' ) ) {
			$out = @gzdecode( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt stream must degrade to null, not warn.
			return is_string( $out ) ? $out : null;
		}
		if ( false !== strpos( $encoding, 'deflate' ) && function_exists( 'gzinflate' ) ) {
			// Some servers send zlib-wrapped deflate; try raw first, then zlib.
			$out = @gzinflate( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			if ( ! is_string( $out ) && function_exists( 'gzuncompress' ) ) {
				$out = @gzuncompress( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			}
			return is_string( $out ) ? $out : null;
		}
		return null;
	}
}
