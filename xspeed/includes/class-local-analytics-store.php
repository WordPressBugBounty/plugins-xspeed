<?php
/**
 * Local_Analytics_Store: the local copies of Google's gtag.js, one per
 * measurement ID, and the background fetch that keeps them current.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Local_Analytics_Store {

	public const OPTION    = 'xspeed_local_analytics';
	public const CRON_HOOK = 'xspeed_local_analytics_fetch';
	public const DIR_NAME  = 'xspeed-analytics';

	public const STATUS_PENDING = 'pending';
	public const STATUS_READY   = 'ready';
	public const STATUS_FAILED  = 'failed';

	/** A site names one or two IDs. The cap keeps the autoloaded option small. */
	public const MAX_IDS = 10;

	/** gtag.js is about 450 KB. Anything over twice that is not the script. */
	public const MAX_BYTES = 1048576;
	public const MIN_BYTES = 1024;

	/**
	 * How often a ready copy is downloaded again. Google changes the file,
	 * and a copy that drifts too far behind can stop recording.
	 */
	public const REFRESH_INTERVAL = DAY_IN_SECONDS;

	/** Seconds to wait before retry N (1-based) after a failed fetch. */
	private const BACKOFF = array( 300, 3600, 21600, DAY_IN_SECONDS );

	/** Only GA4 measurement IDs. Ads and Floodlight tags load other scripts. */
	private const ID_PATTERN = '/^G-[A-Z0-9]{4,20}$/';

	/**
	 * Test seam: replaces the page-cache purge. Null means Cache::purge_all().
	 *
	 * @var callable|null
	 */
	public static $purger = null;

	/** @var array<string,array<string,mixed>>|null */
	private static ?array $manifest = null;

	/** Forget the per-request manifest copy. */
	public static function reset(): void {
		self::$manifest = null;
	}

	/**
	 * A GA4 measurement ID in canonical (upper) case, or null.
	 *
	 * @param mixed $id
	 */
	public static function normalize_id( $id ): ?string {
		if ( ! is_string( $id ) ) {
			return null;
		}
		$id = strtoupper( trim( $id ) );
		return preg_match( self::ID_PATTERN, $id ) ? $id : null;
	}

	/**
	 * The manifest, read once per request. Malformed entries are dropped on
	 * read, so a damaged option costs a download, not a fatal.
	 *
	 * @return array<string,array<string,mixed>> Entries keyed by measurement ID.
	 */
	public static function manifest(): array {
		if ( null !== self::$manifest ) {
			return self::$manifest;
		}
		$raw   = get_option( self::OPTION, array() );
		$clean = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $id => $entry ) {
				if ( null === self::normalize_id( $id ) || $id !== self::normalize_id( $id )
					|| ! is_array( $entry ) || ! isset( $entry['status'] )
					|| ! in_array( $entry['status'], array( self::STATUS_PENDING, self::STATUS_READY, self::STATUS_FAILED ), true )
				) {
					continue;
				}
				$clean[ $id ] = array_merge( self::blank_entry(), $entry );
			}
		}
		self::$manifest = $clean;
		return $clean;
	}

	/** @param array<string,array<string,mixed>> $manifest */
	private static function save( array $manifest ): void {
		self::$manifest = $manifest;
		// Autoloaded: every uncached front-end render reads it, and it holds
		// at most MAX_IDS short entries.
		update_option( self::OPTION, $manifest, true );
	}

	/** @return array<string,mixed> */
	private static function blank_entry(): array {
		return array(
			'status'       => self::STATUS_PENDING,
			'attempts'     => 0,
			'next_attempt' => 0,
			'error'        => '',
			'fetched_at'   => 0,
			'bytes'        => 0,
		);
	}

	/**
	 * The local URL for an ID, versioned by its fetch time, or null when no
	 * copy is ready. A refresh that failed keeps the ready copy serving.
	 */
	public static function local_url( string $id ): ?string {
		$entry = self::manifest()[ $id ] ?? null;
		if ( null === $entry || self::STATUS_READY !== $entry['status'] ) {
			return null;
		}
		$base = self::url();
		if ( '' === $base || ! is_file( self::path( $id ) ) ) {
			return null;
		}
		return $base . '/' . self::file_name( $id ) . '?ver=' . (int) $entry['fetched_at'];
	}

	/** Queue an ID seen on a page. Returns true when it was new. */
	public static function enqueue( string $id ): bool {
		$manifest = self::manifest();
		if ( isset( $manifest[ $id ] ) || count( $manifest ) >= self::MAX_IDS ) {
			return false;
		}
		$manifest[ $id ] = self::blank_entry();
		self::save( $manifest );
		return true;
	}

	/**
	 * When the next fetch is due: a pending ID's next attempt, or a ready
	 * copy's refresh time. Null when nothing is due ever.
	 */
	public static function next_due(): ?int {
		$next = null;
		foreach ( self::manifest() as $entry ) {
			$when = self::due_at( $entry );
			if ( null !== $when && ( null === $next || $when < $next ) ) {
				$next = $when;
			}
		}
		return $next;
	}

	/** @param array<string,mixed> $entry */
	private static function due_at( array $entry ): ?int {
		if ( self::STATUS_READY === $entry['status'] ) {
			$refresh = (int) $entry['fetched_at'] + self::REFRESH_INTERVAL;
			return max( $refresh, (int) $entry['next_attempt'] );
		}
		if ( self::STATUS_PENDING === $entry['status'] ) {
			return (int) $entry['next_attempt'];
		}
		// Failed with no copy: tried MAX attempts. Only `refresh` retries it.
		return null;
	}

	/**
	 * Schedule the fetch event for the next due time, unless one is
	 * scheduled already. Every run calls this at its end, so the daily
	 * refresh keeps going on a site whose pages are all served from cache.
	 */
	public static function ensure_scheduled(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		$due = self::next_due();
		if ( null !== $due ) {
			wp_schedule_single_event( max( time(), $due ), self::CRON_HOOK );
		}
	}

	/**
	 * Fetch every ID that is due, or every ID when $all.
	 *
	 * @return array{ready:int,refreshed:int,kept:int,failed:int}
	 */
	public static function run( bool $all = false ): array {
		$result   = array( 'ready' => 0, 'refreshed' => 0, 'kept' => 0, 'failed' => 0 );
		$now      = time();
		$manifest = self::manifest();
		$newly    = false;

		foreach ( $manifest as $id => $entry ) {
			$due = self::due_at( $entry );
			if ( ! $all && ( null === $due || $due > $now ) ) {
				continue;
			}
			$was_ready = self::STATUS_READY === $entry['status'] && is_file( self::path( $id ) );
			$fetched   = self::fetch( $id );

			if ( is_array( $fetched ) ) {
				$manifest[ $id ] = array_merge(
					$entry,
					array(
						'status'       => self::STATUS_READY,
						'attempts'     => 0,
						'next_attempt' => 0,
						'error'        => '',
						'fetched_at'   => $now,
						'bytes'        => $fetched['bytes'],
					)
				);
				if ( $was_ready ) {
					++$result['refreshed'];
				} else {
					++$result['ready'];
					$newly = true;
				}
				continue;
			}

			$attempts = (int) $entry['attempts'] + 1;
			$entry['attempts']     = $attempts;
			$entry['error']        = $fetched->get_error_message();
			$entry['next_attempt'] = $now + self::BACKOFF[ min( $attempts, count( self::BACKOFF ) ) - 1 ];
			if ( $was_ready ) {
				// The copy on disk keeps serving. Analytics that stops recording
				// is worse than a stale script.
				++$result['kept'];
			} else {
				$entry['status'] = $attempts >= count( self::BACKOFF ) ? self::STATUS_FAILED : self::STATUS_PENDING;
				++$result['failed'];
			}
			$manifest[ $id ] = $entry;
		}

		self::save( $manifest );
		if ( $newly ) {
			// Cached pages still name Google. Purge once so they pick up the copy.
			self::purge_page_cache( 'local analytics ready' );
		}
		self::ensure_scheduled();
		return $result;
	}

	/**
	 * Download gtag.js for one ID and store it.
	 *
	 * @return array{bytes:int}|\WP_Error
	 */
	public static function fetch( string $id ) {
		$dir = self::dir();
		if ( '' === $dir ) {
			return new \WP_Error( 'xspeed_analytics_dir', __( 'The uploads folder is not available.', 'xspeed' ) );
		}
		$response = wp_safe_remote_get(
			self::remote_url( $id ),
			array(
				'timeout'             => 10,
				// Google answers this URL directly. A redirect is not something
				// to follow to an unknown host.
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES + 1,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return new \WP_Error( 'xspeed_analytics_http', sprintf( __( 'Google answered with HTTP %d.', 'xspeed' ), $code ) );
		}
		if ( ! self::looks_like_gtag( $body ) ) {
			return new \WP_Error( 'xspeed_analytics_body', __( 'Google did not send the analytics script.', 'xspeed' ) );
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'xspeed_analytics_dir', __( 'Could not create the analytics folder in uploads.', 'xspeed' ) );
		}
		if ( ! is_file( $dir . '/index.php' ) ) {
			self::write_atomic( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! self::write_atomic( self::path( $id ), $body ) ) {
			return new \WP_Error( 'xspeed_analytics_write', __( 'Could not write the analytics script. The web server user needs write access to the uploads folder.', 'xspeed' ) );
		}
		return array( 'bytes' => strlen( $body ) );
	}

	/**
	 * Checked on the bytes, so an HTML error page or a truncated body is
	 * never served as the script.
	 */
	public static function looks_like_gtag( string $body ): bool {
		$len = strlen( $body );
		if ( $len < self::MIN_BYTES || $len > self::MAX_BYTES ) {
			return false;
		}
		if ( '<' === substr( ltrim( $body ), 0, 1 ) ) {
			return false;
		}
		return false !== strpos( $body, 'dataLayer' );
	}

	public static function remote_url( string $id ): string {
		return 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $id );
	}

	/** Delete every local copy and the manifest. */
	public static function purge(): void {
		$dir = self::dir();
		if ( '' !== $dir && is_dir( $dir ) ) {
			foreach ( (array) scandir( $dir ) as $name ) {
				if ( '.' !== $name && '..' !== $name && is_file( $dir . '/' . $name ) ) {
					wp_delete_file( $dir . '/' . $name );
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort; a leftover folder is harmless.
		}
		delete_option( self::OPTION );
		self::$manifest = null;
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/** Purge the page cache because pages now name a different script URL. */
	public static function purge_page_cache( string $cause ): void {
		if ( is_callable( self::$purger ) ) {
			call_user_func( self::$purger, $cause );
			return;
		}
		Cache::purge_all( $cause );
	}

	/** True when at least one ID has a copy on disk. */
	public static function has_ready(): bool {
		foreach ( self::manifest() as $id => $entry ) {
			if ( self::STATUS_READY === $entry['status'] && is_file( self::path( (string) $id ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function file_name( string $id ): string {
		return 'gtag-' . strtolower( $id ) . '.js';
	}

	public static function path( string $id ): string {
		$dir = self::dir();
		return '' === $dir ? '' : $dir . '/' . self::file_name( $id );
	}

	/** Per site: uploads is per site on multisite. */
	public static function dir(): string {
		$up = wp_upload_dir( null, false );
		return is_array( $up ) && ! empty( $up['basedir'] ) ? rtrim( (string) $up['basedir'], '/' ) . '/' . self::DIR_NAME : '';
	}

	public static function url(): string {
		$up = wp_upload_dir( null, false );
		if ( ! is_array( $up ) || empty( $up['baseurl'] ) ) {
			return '';
		}
		$base = rtrim( (string) $up['baseurl'], '/' );
		// uploads baseurl stays http on some sites served over https; a script
		// over http on an https page is blocked as mixed content.
		if ( function_exists( 'is_ssl' ) && is_ssl() ) {
			$base = (string) preg_replace( '#^http://#i', 'https://', $base );
		}
		return $base . '/' . self::DIR_NAME;
	}

	/** Mode for files written here: FS_CHMOD_FILE, or WordPress's own default for it. */
	private static function file_mode(): int {
		if ( defined( 'FS_CHMOD_FILE' ) ) {
			return (int) FS_CHMOD_FILE;
		}
		$perms = @fileperms( ABSPATH . 'index.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file means the default.
		return ( false === $perms ? 0 : $perms & 0777 ) | 0644;
	}

	/** Temp file + rename, so a visitor never loads half a script. */
	private static function write_atomic( string $path, string $body ): bool {
		$tmp = $path . '.' . uniqid( '', true ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- WP_Filesystem needs admin context; this runs from cron.
		if ( false === @file_put_contents( $tmp, $body, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported as a WP_Error by the caller.
			wp_delete_file( $tmp );
			return false;
		}
		@chmod( $tmp, self::file_mode() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- best effort; the umask default still works.
		// WP_Filesystem::move() is not atomic and needs admin context; rename() within one directory is.
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- reported as a WP_Error by the caller.
			wp_delete_file( $tmp );
			return false;
		}
		return true;
	}
}
