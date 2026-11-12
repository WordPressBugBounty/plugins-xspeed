<?php
/**
 * XSpeed_Redis_Client — a minimal, dependency-free Redis client.
 *
 * Speaks the Redis wire protocol (RESP) directly over a TCP or unix socket. It exists
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

	/** @var string Last Redis error reply, cleared before each command. */
	private $last_error = '';

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
	 * The unix socket path a Redis host names, or '' for a network host.
	 *
	 * Hosts that run Redis on a socket only (Hetzner managed servers, shared
	 * LiteSpeed hosting) hand out a path rather than an address, written as
	 * `/run/redis/redis.sock`, `unix:/run/redis/redis.sock` (Hetzner's own
	 * docs) or `unix:///run/redis/redis.sock`. A socket has no port, so the
	 * caller ignores the configured one when this returns a path. (#566)
	 *
	 * The drop-in carries a copy as xspeed_oc_redis_socket(), because it loads
	 * before this class can; ObjectCacheSocketTest runs both over the same
	 * inputs so they cannot drift.
	 */
	public static function socket_path( string $host ): string {
		$host = trim( $host );
		if ( 0 === stripos( $host, 'unix:' ) ) {
			// unix:///path and unix:/path both name /path.
			$host = '/' . ltrim( substr( $host, 5 ), '/' );
		}
		return ( '' !== $host && '/' === $host[0] && '/' !== $host ) ? $host : '';
	}

	/**
	 * Open the socket. Returns true on success. Never throws — callers check
	 * the boolean and fall back to a non-persistent cache on failure.
	 */
	public function connect(): bool {
		$flags  = STREAM_CLIENT_CONNECT | ( $this->persistent ? STREAM_CLIENT_PERSISTENT : 0 );
		$errno  = 0;
		$errstr = '';
		$path   = self::socket_path( $this->host );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- A raw stream socket is the only way to speak the Redis protocol; WP_Filesystem cannot open TCP sockets. Errors are captured via $errno/$errstr and surfaced as a boolean.
		$sock = @stream_socket_client(
			'' !== $path ? "unix://{$path}" : "tcp://{$this->host}:{$this->port}",
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

	/** @return int|false Whether the key exists, or false on failure. */
	public function exists( string $key ) {
		return $this->command( array( 'EXISTS', $key ) );
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

	/**
	 * Read several keys in one round trip (MGET).
	 *
	 * @param string[] $keys Keys to read.
	 * @return array|false Values in the order of $keys, false for a missing
	 *                     key; false for the whole call on a socket error.
	 */
	public function mget( array $keys ) {
		if ( empty( $keys ) ) {
			return array();
		}
		$r = $this->command( array_merge( array( 'MGET' ), array_map( 'strval', array_values( $keys ) ) ) );
		if ( ! is_array( $r ) || count( $r ) !== count( $keys ) ) {
			return false;
		}
		return array_map(
			static function ( $v ) {
				return null === $v ? false : $v;
			},
			$r
		);
	}

	/**
	 * Remove keys, freeing their memory off Redis's main thread (UNLINK).
	 * Falls back to DEL on servers older than Redis 4, which reject UNLINK.
	 *
	 * @param string[] $keys Keys to remove.
	 * @return int|false Number of keys removed, or false on failure.
	 */
	public function unlink( array $keys ) {
		if ( empty( $keys ) ) {
			return 0;
		}
		$keys = array_map( 'strval', array_values( $keys ) );
		$n    = $this->command( array_merge( array( 'UNLINK' ), $keys ) );
		if ( false === $n && 0 === strpos( $this->last_error, 'ERR unknown command' ) ) {
			$n = $this->command( array_merge( array( 'DEL' ), $keys ) );
		}
		return is_int( $n ) ? $n : false;
	}

	/**
	 * One SCAN step.
	 *
	 * @param string $cursor  Cursor from the previous step, '0' to start.
	 * @param string $pattern MATCH pattern.
	 * @param int    $count   COUNT hint.
	 * @return array|false array( next_cursor, keys ), or false on error.
	 */
	public function scan_step( string $cursor, string $pattern, int $count = 500 ) {
		$reply = $this->command( array( 'SCAN', $cursor, 'MATCH', $pattern, 'COUNT', (string) $count ) );
		if ( ! is_array( $reply ) || count( $reply ) !== 2 || ! is_string( $reply[0] ) || ! ctype_digit( $reply[0] ) || ! is_array( $reply[1] ) ) {
			return false;
		}
		foreach ( $reply[1] as $key ) {
			if ( ! is_string( $key ) ) {
				return false;
			}
		}
		return $reply;
	}

	/**
	 * Send several commands in one write and read all their replies, so a
	 * batch costs one round trip instead of one per command. Replies come
	 * back in command order; an error reply reads as false in its slot.
	 *
	 * @param array<int,string[]> $commands Each entry is one command's args.
	 * @return array|false Replies, or false when the socket fails.
	 */
	public function pipeline( array $commands ) {
		if ( empty( $commands ) ) {
			return array();
		}
		if ( ! is_resource( $this->sock ) ) {
			return false;
		}
		$payload = '';
		foreach ( $commands as $args ) {
			$payload .= $this->encode( $args );
		}
		if ( ! $this->write( $payload ) ) {
			return false;
		}
		$out = array();
		foreach ( $commands as $unused ) {
			$out[] = $this->read_reply();
		}
		return $out;
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
	 * datasets) and UNLINK, which frees the memory off Redis's main thread.
	 * Returns false if any step fails. Used for prefix-scoped flushes so
	 * we only ever touch this site's namespace, never the whole Redis DB.
	 * (FBS-83119)
	 *
	 * @param string      $pattern e.g. "salt:*" or "salt:*:options:*".
	 * @param int         $count   SCAN COUNT hint (batch size per round trip).
	 * @param string|null $filter  Optional regex a key must also match. A glob
	 *                             cannot say "digits only", so callers that
	 *                             need an exact shape filter here.
	 * @return int|false Number deleted, or false on failure.
	 */
	public function delete_by_pattern( string $pattern, int $count = 500, ?string $filter = null ) {
		$deleted = 0;
		$cursor  = '0';
		do {
			$step = $this->scan_step( $cursor, $pattern, $count );
			if ( false === $step ) {
				return false;
			}
			list( $cursor, $keys ) = $step;
			if ( null !== $filter ) {
				$keys = preg_grep( $filter, $keys );
			}
			if ( ! empty( $keys ) ) {
				$n = $this->unlink( $keys );
				if ( false === $n ) {
					return false;
				}
				$deleted += $n;
			}
		} while ( '0' !== $cursor );
		return $deleted;
	}

	/** The last error reply, '' when the last command succeeded. */
	public function last_error(): string {
		return $this->last_error;
	}

	/** Backend errors stay in this request; callers expose only normalized reasons. */
	public function getLastError(): string {
		return $this->last_error;
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
		$this->last_error = '';
		if ( ! is_resource( $this->sock ) ) {
			return false;
		}

		$payload = $this->encode( $args );

		if ( ! $this->write( $payload ) ) {
			return false;
		}

		return $this->read_reply();
	}

	/**
	 * Write the whole payload. A socket write can be partial, and a command
	 * sent in part leaves the server waiting for the rest of it.
	 */
	private function write( string $payload ): bool {
		$sent = 0;
		$len  = strlen( $payload );
		while ( $sent < $len ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.NoSilencedErrors.Discouraged -- Writing to the Redis TCP socket; WP_Filesystem has no socket transport. Failure returns false and the cache degrades.
			$n = @fwrite( $this->sock, 0 === $sent ? $payload : substr( $payload, $sent ) );
			if ( false === $n || 0 === $n ) {
				return $this->drop();
			}
			$sent += $n;
		}
		return true;
	}

	/**
	 * Close the socket after a transport failure and return false.
	 *
	 * After a timeout or a cut-off reply the stream is out of step: the next
	 * read would return the answer to an earlier command. Nothing on it can
	 * be trusted again, and a persistent socket would carry that into the
	 * next request, so it is closed even when persistent.
	 *
	 * @return false
	 */
	private function drop(): bool {
		$this->last_error = '';
		if ( is_resource( $this->sock ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged -- Closing a raw socket that failed mid-reply.
			@fclose( $this->sock );
		}
		$this->sock = null;
		return false;
	}

	/**
	 * Encode one command as a RESP array of bulk strings.
	 *
	 * @param string[] $args
	 */
	private function encode( array $args ): string {
		$payload = '*' . count( $args ) . "\r\n";
		foreach ( $args as $a ) {
			$a        = (string) $a;
			$payload .= '$' . strlen( $a ) . "\r\n" . $a . "\r\n";
		}
		return $payload;
	}

	/**
	 * Read and decode a single RESP reply from the socket.
	 *
	 * @return mixed string|int|null|array|false
	 */
	private function read_reply() {
		$line = $this->read_line();
		if ( false === $line || '' === $line ) {
			return $this->drop();
		}

		$type = $line[0];
		$body = substr( $line, 1 );

		switch ( $type ) {
			case '+': // Simple string.
				return $body;
			case '-': // Error.
				$this->last_error = $body;
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
				return $this->drop();
		}
	}

	/** Read one CRLF-terminated line (without the CRLF). */
	private function read_line() {
		if ( ! is_resource( $this->sock ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.PHP.NoSilencedErrors.Discouraged -- Reading a line from the Redis TCP socket.
		$line = @fgets( $this->sock );
		if ( false === $line || "\n" !== substr( $line, -1 ) ) {
			return $this->drop();
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
				// Timed out or closed mid-value: a short value is not a hit.
				return $this->drop();
			}
			$buf .= $chunk;
		}
		return $buf;
	}
}
