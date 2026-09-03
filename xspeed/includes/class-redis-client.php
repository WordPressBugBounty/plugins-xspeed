<?php
/**
 * XSpeed_Redis_Client — a minimal, dependency-free Redis client.
 *
 * Speaks the Redis wire protocol (RESP) directly over a TCP socket. It exists
 * so xSpeed's object cache can talk to Redis WITHOUT the phpredis extension and
 * WITHOUT bundling a heavyweight library (Predis ships 700+ files for cluster /
 * sentinel / pub-sub / transactions we never use). This implements exactly the
 * commands the object cache needs and nothing more:
 *
 *   AUTH, SELECT, PING, GET, SET, SETEX, DEL, INCRBY, DECRBY, FLUSHDB
 *
 * It is intentionally NOT a general-purpose client. Every method maps to one
 * Redis command. Errors never throw past connect(); read/write failures return
 * false so the object cache degrades gracefully instead of fataling the site.
 *
 * RESP reference: https://redis.io/docs/reference/protocol-spec/
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

class Redis_Client {

	/** @var resource|null Socket handle. */
	private $sock = null;

	/** @var string */
	private $host;

	/** @var int */
	private $port;

	/** @var float */
	private $timeout;

	/** @var bool Persistent connection (pconnect-style). */
	private $persistent;

	public function __construct( string $host = '127.0.0.1', int $port = 6379, float $timeout = 1.0, bool $persistent = false ) {
		$this->host       = $host;
		$this->port       = $port;
		$this->timeout    = $timeout > 0 ? $timeout : 1.0;
		$this->persistent = $persistent;
	}

	/**
	 * Open the socket. Returns true on success. Never throws — callers check
	 * the boolean and fall back to a non-persistent cache on failure.
	 */
	public function connect(): bool {
		$flags  = STREAM_CLIENT_CONNECT | ( $this->persistent ? STREAM_CLIENT_PERSISTENT : 0 );
		$errno  = 0;
		$errstr = '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- A raw stream socket is the only way to speak the Redis protocol; WP_Filesystem cannot open TCP sockets. Errors are captured via $errno/$errstr and surfaced as a boolean.
		$sock = @stream_socket_client(
			"tcp://{$this->host}:{$this->port}",
			$errno,
			$errstr,
			$this->timeout,
			$flags
		);
		if ( ! $sock ) {
			// The `@` above hides the warning from output/logs, but a failed
			// connect (e.g. an unreachable/unresolvable host) still leaves a
			// PHP warning in `error_get_last()`. WP reads that at
			// `admin_body_class` time and tags the page `php-error` — which
			// renders an empty banner above the admin menu even though we
			// handle the failure gracefully (caller falls back to a
			// non-persistent cache). Clear it so a degraded-but-handled Redis
			// backend doesn't masquerade as a site error.
			if ( function_exists( 'error_clear_last' ) ) {
				error_clear_last();
			}
			return false;
		}
		stream_set_timeout( $sock, (int) $this->timeout, (int) ( ( $this->timeout - (int) $this->timeout ) * 1000000 ) );
		$this->sock = $sock;
		return true;
	}

	public function is_connected(): bool {
		return is_resource( $this->sock );
	}

	// --- Commands -----------------------------------------------------------

	/**
	 * Authenticate. With a username (Redis 6+ ACL) emit the two-argument
	 * `AUTH <user> <pass>`; without one fall back to the legacy
	 * single-argument `AUTH <pass>` (which authenticates as the built-in
	 * `default` user). Managed hosts that provision a dedicated ACL user
	 * and disable `default` reject the one-arg form with WRONGPASS, so the
	 * username must be threaded through here. (FBS-83118)
	 *
	 * @param string $password Redis password.
	 * @param string $username Optional ACL username; '' = legacy default user.
	 */
	public function auth( string $password, string $username = '' ) {
		if ( '' !== $username ) {
			return $this->command( array( 'AUTH', $username, $password ) );
		}
		return $this->command( array( 'AUTH', $password ) );
	}

	public function select( int $db ) {
		return $this->command( array( 'SELECT', (string) $db ) );
	}

	/** @return string|bool '+PONG' on success, false on failure. */
	public function ping() {
		$r = $this->command( array( 'PING' ) );
		return ( null === $r || false === $r ) ? false : $r;
	}

	/** @return string|false The value, or false if the key is missing. */
	public function get( string $key ) {
		$r = $this->command( array( 'GET', $key ) );
		return null === $r ? false : $r;
	}

	public function set( string $key, string $value ): bool {
		$r = $this->command( array( 'SET', $key, $value ) );
		return '+OK' === $r || 'OK' === $r;
	}

	public function setex( string $key, int $ttl, string $value ): bool {
		$r = $this->command( array( 'SETEX', $key, (string) $ttl, $value ) );
		return '+OK' === $r || 'OK' === $r;
	}

	/**
	 * Atomic add — SET ... NX, which stores only if the key does NOT exist.
	 * Returns true when stored, false when the key already existed (Redis
	 * replies nil → null here) or on error. With $ttl > 0 the EX option makes
	 * the store + expiry atomic. Used by the drop-in's wp_cache_add so add()
	 * honours its "fail if the key is present" contract across requests, not
	 * just the per-request runtime cache. (FBS-82111 Bug 2)
	 */
	public function add( string $key, string $value, int $ttl = 0 ): bool {
		$args = array( 'SET', $key, $value, 'NX' );
		if ( $ttl > 0 ) {
			$args[] = 'EX';
			$args[] = (string) $ttl;
		}
		$r = $this->command( $args );
		return '+OK' === $r || 'OK' === $r;
	}

	/** @return int Number of keys removed. */
	public function del( string $key ): int {
		return (int) $this->command( array( 'DEL', $key ) );
	}

	/** @return int|false New value, or false on error. */
	public function incrBy( string $key, int $offset ) {
		return $this->command( array( 'INCRBY', $key, (string) $offset ) );
	}

	/** @return int|false New value, or false on error. */
	public function decrBy( string $key, int $offset ) {
		return $this->command( array( 'DECRBY', $key, (string) $offset ) );
	}

	public function flushDB(): bool {
		$r = $this->command( array( 'FLUSHDB' ) );
		return '+OK' === $r || 'OK' === $r;
	}

	/**
	 * Delete every key matching a glob-style pattern, using a non-blocking
	 * SCAN cursor (never KEYS, which blocks the whole server on large
	 * datasets). Returns the number of keys deleted. Used for prefix-scoped
	 * flushes so we only ever touch this site's namespace, never the whole
	 * Redis DB. (FBS-83119)
	 *
	 * @param string $pattern e.g. "salt:*" or "salt:*:options:*".
	 * @param int    $count    SCAN COUNT hint (batch size per round trip).
	 */
	public function delete_by_pattern( string $pattern, int $count = 500 ): int {
		$deleted = 0;
		$cursor  = '0';
		do {
			$reply = $this->command( array( 'SCAN', $cursor, 'MATCH', $pattern, 'COUNT', (string) $count ) );
			// Expected: [ next_cursor, [ key, key, ... ] ]. Anything else
			// (false on socket error, malformed) ends the loop safely.
			if ( ! is_array( $reply ) || count( $reply ) < 2 || ! is_array( $reply[1] ) ) {
				break;
			}
			$cursor = (string) $reply[0];
			$keys   = $reply[1];
			if ( ! empty( $keys ) ) {
				// DEL accepts variadic keys — one round trip per batch.
				$args     = array_merge( array( 'DEL' ), array_map( 'strval', $keys ) );
				$n        = $this->command( $args );
				$deleted += is_int( $n ) ? $n : 0;
			}
		} while ( '0' !== $cursor );
		return $deleted;
	}

	public function close(): void {
		if ( is_resource( $this->sock ) && ! $this->persistent ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a raw TCP socket opened with stream_socket_client; not a WP_Filesystem-managed handle.
			@fclose( $this->sock ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort close on shutdown.
		}
		$this->sock = null;
	}

	// --- RESP protocol ------------------------------------------------------

	/**
	 * Encode a command as a RESP array of bulk strings, write it, read one
	 * reply. Returns the decoded reply, or false on any socket error.
	 *
	 * @param string[] $args
	 * @return mixed
	 */
	private function command( array $args ) {
		if ( ! is_resource( $this->sock ) ) {
			return false;
		}

		$payload = '*' . count( $args ) . "\r\n";
		foreach ( $args as $a ) {
			$a        = (string) $a;
			$payload .= '$' . strlen( $a ) . "\r\n" . $a . "\r\n";
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.NoSilencedErrors.Discouraged -- Writing to the Redis TCP socket; WP_Filesystem has no socket transport. Failure returns false and the cache degrades.
		if ( false === @fwrite( $this->sock, $payload ) ) {
			$this->sock = null;
			return false;
		}

		return $this->read_reply();
	}

	/**
	 * Read and decode a single RESP reply from the socket.
	 *
	 * @return mixed string|int|null|array|false
	 */
	private function read_reply() {
		$line = $this->read_line();
		if ( false === $line || '' === $line ) {
			return false;
		}

		$type = $line[0];
		$body = substr( $line, 1 );

		switch ( $type ) {
			case '+': // Simple string.
				return $body;
			case '-': // Error.
				return false;
			case ':': // Integer.
				return (int) $body;
			case '$': // Bulk string.
				$len = (int) $body;
				if ( $len < 0 ) {
					return null; // Null bulk = key missing.
				}
				$data = $this->read_bytes( $len + 2 ); // +2 for trailing CRLF.
				return false === $data ? false : substr( $data, 0, $len );
			case '*': // Array.
				$count = (int) $body;
				if ( $count < 0 ) {
					return null;
				}
				$out = array();
				for ( $i = 0; $i < $count; $i++ ) {
					$out[] = $this->read_reply();
				}
				return $out;
			default:
				return false;
		}
	}

	/** Read one CRLF-terminated line (without the CRLF). */
	private function read_line() {
		if ( ! is_resource( $this->sock ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.PHP.NoSilencedErrors.Discouraged -- Reading a line from the Redis TCP socket.
		$line = @fgets( $this->sock );
		if ( false === $line ) {
			return false;
		}
		return rtrim( $line, "\r\n" );
	}

	/** Read exactly $n bytes from the socket. */
	private function read_bytes( int $n ) {
		if ( ! is_resource( $this->sock ) ) {
			return false;
		}
		$buf = '';
		while ( strlen( $buf ) < $n ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.PHP.NoSilencedErrors.Discouraged -- Reading the bulk-string body from the Redis TCP socket.
			$chunk = @fread( $this->sock, $n - strlen( $buf ) );
			if ( false === $chunk || '' === $chunk ) {
				$meta = stream_get_meta_data( $this->sock );
				if ( ! empty( $meta['timed_out'] ) ) {
					return false;
				}
				break;
			}
			$buf .= $chunk;
		}
		return $buf;
	}
}
