<?php
/**
 * XSpeed_Memcached_Client — a minimal, dependency-free Memcached client.
 *
 * Speaks the Memcached text protocol directly over a TCP socket, so xSpeed's
 * object cache can use Memcached WITHOUT the PHP `memcached`/`memcache`
 * extension and WITHOUT bundling a library. Implements exactly the commands the
 * object cache needs:
 *
 *   set, get, delete, incr, decr, flush_all, version (ping)
 *
 * Counterpart to Redis_Client. Like it, this is intentionally minimal — one
 * method per command — and never throws past connect(); failures return false
 * so the object cache degrades gracefully instead of fataling the site.
 *
 * Protocol: https://github.com/memcached/memcached/blob/master/doc/protocol.txt
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

class Memcached_Client {

	/** @var resource|null */
	private $sock = null;

	/** @var string */
	private $host;

	/** @var int */
	private $port;

	/** @var float */
	private $timeout;

	public function __construct( string $host = '127.0.0.1', int $port = 11211, float $timeout = 1.0 ) {
		$this->host    = $host;
		$this->port    = $port;
		$this->timeout = $timeout > 0 ? $timeout : 1.0;
	}

	public function connect(): bool {
		$errno  = 0;
		$errstr = '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- A raw stream socket is the only way to speak the Memcached protocol; WP_Filesystem cannot open TCP sockets. Errors are captured via $errno/$errstr and surfaced as a boolean.
		$sock = @stream_socket_client(
			"tcp://{$this->host}:{$this->port}",
			$errno,
			$errstr,
			$this->timeout,
			STREAM_CLIENT_CONNECT
		);
		if ( ! $sock ) {
			// See class-redis-client.php: the `@`-suppressed warning still
			// lingers in error_get_last(), which WP reads to tag the admin
			// page `php-error` (empty banner above the menu). We handle the
			// failure gracefully, so clear it.
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

	/** A liveness probe — returns the server version string, or false. */
	public function version() {
		if ( ! $this->write( "version\r\n" ) ) {
			return false;
		}
		$line = $this->read_line();
		// Reply: "VERSION 1.6.21".
		if ( is_string( $line ) && 0 === strpos( $line, 'VERSION' ) ) {
			return trim( substr( $line, 8 ) );
		}
		return false;
	}

	/**
	 * Store a value. $exptime is seconds (0 = never expire). Memcached caps the
	 * relative form at 30 days; beyond that it's treated as a unix timestamp —
	 * the object cache passes small TTLs so this is fine.
	 */
	public function set( string $key, string $value, int $exptime = 0 ): bool {
		$key   = $this->sanitize_key( $key );
		$bytes = strlen( $value );
		$cmd   = "set {$key} 0 {$exptime} {$bytes}\r\n{$value}\r\n";
		if ( ! $this->write( $cmd ) ) {
			return false;
		}
		return 'STORED' === $this->read_line();
	}

	/**
	 * Atomic add — stores only if the key does NOT already exist (the native
	 * memcached `add` storage command). Returns true on STORED, false on
	 * NOT_STORED (key present) or error. Used by the drop-in's wp_cache_add
	 * so it honours add semantics across requests, not just the runtime
	 * cache. (FBS-82111 Bug 2)
	 */
	public function add( string $key, string $value, int $exptime = 0 ): bool {
		$key   = $this->sanitize_key( $key );
		$bytes = strlen( $value );
		$cmd   = "add {$key} 0 {$exptime} {$bytes}\r\n{$value}\r\n";
		if ( ! $this->write( $cmd ) ) {
			return false;
		}
		return 'STORED' === $this->read_line();
	}

	/** @return string|false The value, or false when the key is missing. */
	public function get( string $key ) {
		$key = $this->sanitize_key( $key );
		if ( ! $this->write( "get {$key}\r\n" ) ) {
			return false;
		}
		$line = $this->read_line();
		if ( ! is_string( $line ) || 0 !== strpos( $line, 'VALUE' ) ) {
			return false; // END = miss.
		}
		// "VALUE <key> <flags> <bytes>".
		$parts = explode( ' ', $line );
		$bytes = isset( $parts[3] ) ? (int) $parts[3] : 0;
		$data  = $this->read_bytes( $bytes + 2 ); // +2 for trailing CRLF.
		// Consume the trailing "END".
		$this->read_line();
		return false === $data ? false : substr( $data, 0, $bytes );
	}

	public function delete( string $key ): bool {
		$key = $this->sanitize_key( $key );
		if ( ! $this->write( "delete {$key}\r\n" ) ) {
			return false;
		}
		$r = $this->read_line();
		return 'DELETED' === $r || 'NOT_FOUND' === $r;
	}

	/** @return int|false New value, or false on error / missing key. */
	public function incr( string $key, int $offset ) {
		$key = $this->sanitize_key( $key );
		if ( ! $this->write( "incr {$key} {$offset}\r\n" ) ) {
			return false;
		}
		$r = $this->read_line();
		return is_numeric( $r ) ? (int) $r : false;
	}

	/** @return int|false New value, or false on error / missing key. */
	public function decr( string $key, int $offset ) {
		$key = $this->sanitize_key( $key );
		if ( ! $this->write( "decr {$key} {$offset}\r\n" ) ) {
			return false;
		}
		$r = $this->read_line();
		return is_numeric( $r ) ? (int) $r : false;
	}

	public function flush_all(): bool {
		if ( ! $this->write( "flush_all\r\n" ) ) {
			return false;
		}
		return 'OK' === $this->read_line();
	}

	public function close(): void {
		if ( is_resource( $this->sock ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a raw TCP socket opened with stream_socket_client.
			@fclose( $this->sock ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort close on shutdown.
		}
		$this->sock = null;
	}

	// --- Protocol primitives ------------------------------------------------

	/**
	 * Memcached keys must be <=250 bytes and contain no control chars or
	 * spaces. The object cache's keys can contain colons/spaces via the salt,
	 * so hash anything risky to a safe fixed-length token.
	 */
	private function sanitize_key( string $key ): string {
		if ( strlen( $key ) > 250 || preg_match( '/[\x00-\x20\x7f]/', $key ) ) {
			return 'xs_' . md5( $key );
		}
		return $key;
	}

	private function write( string $payload ): bool {
		if ( ! is_resource( $this->sock ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.NoSilencedErrors.Discouraged -- Writing to the Memcached TCP socket; WP_Filesystem has no socket transport. Failure returns false and the cache degrades.
		$ok = @fwrite( $this->sock, $payload );
		if ( false === $ok ) {
			$this->sock = null;
			return false;
		}
		return true;
	}

	private function read_line() {
		if ( ! is_resource( $this->sock ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.PHP.NoSilencedErrors.Discouraged -- Reading a line from the Memcached TCP socket.
		$line = @fgets( $this->sock );
		if ( false === $line ) {
			return false;
		}
		return rtrim( $line, "\r\n" );
	}

	private function read_bytes( int $n ) {
		if ( ! is_resource( $this->sock ) ) {
			return false;
		}
		$buf = '';
		while ( strlen( $buf ) < $n ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.PHP.NoSilencedErrors.Discouraged -- Reading the value body from the Memcached TCP socket.
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
