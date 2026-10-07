<?php
/**
 * Asset_Manifest: content keys for source files, validated by stat.
 *
 * Minified and combined files used to be named after the source's path and
 * mtime. That name says nothing about the bytes:
 *
 *   - An edit that keeps the mtime (rsync -t, a restore, some deploy tools)
 *     keeps the old name, so a regenerated file serves new bytes under a URL
 *     browsers and CDNs have cached for a year.
 *   - An edit to a file the source pulls in (an @import child, a small image
 *     the minifier embeds as a data: URI) changes nothing in the name at all.
 *   - A touch with no change mints a new name, so every cached page linking
 *     the old one has to go.
 *
 * So output is named after its content instead, and this class answers
 * "what is the content key of this source?" without hashing on every render.
 * Per source it keeps a small manifest:
 *
 *   {
 *     v:       XSPEED_VERSION + schema,
 *     kind:    what the key is for ('css', 'js', 'part-css', 'part-js'),
 *     src:     the source path,
 *     sig:     { path: [mtime, ctime, size, ino] | null } for the source and
 *              every dependency, null for a candidate that does not exist yet,
 *     src_md5: md5 over the source and dependency bytes,
 *     key:     the content key (md5 of the minified output, or src_md5), or
 *              '' when these bytes could not be minified,
 *     at:      when the signature was taken,
 *   }
 *
 * On render the signature is re-taken with stat() alone. When it matches and
 * is older than RACY_SECONDS, the stored key is trusted. Otherwise the bytes
 * are hashed, and the caller rebuilds only when the hash differs. That is the
 * git index rule, including its "racy" clause: a file changed within the same
 * clock tick as the signature can keep its mtime and size, so a signature
 * taken that close to a write is never trusted on its own.
 *
 * Manifests live per blog under `min/manifests/<blog_id>/`, so one subsite
 * can drop its own without touching another's. Outputs stay shared: the same
 * bytes have the same name whichever blog produced them.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Asset_Manifest {

	/** Bump when the manifest shape or the key derivation changes. */
	public const SCHEMA = 1;

	/** A signature this close to a file's own timestamps is not trusted. */
	public const RACY_SECONDS = 2;

	/** Subdirectory of min/ that holds the manifests. */
	public const SUBDIR = 'manifests';

	/**
	 * Extensions matthiasmullie/minify embeds as data: URIs (CSS.php
	 * `$importExtensions`). Mirrored rather than read from the library, which
	 * keeps it protected.
	 */
	private const EMBEDDABLE = array(
		'gif',
		'png',
		'jpe',
		'jpg',
		'jpeg',
		'svg',
		'woff',
		'woff2',
		'avif',
		'apng',
		'webp',
		'tif',
		'tiff',
		'xbm',
	);

	/** Deepest @import chain the dependency walk follows. */
	private const MAX_WALK_DEPTH = 16;

	/**
	 * Fixed "now", set only by tests to step past the racy window without
	 * sleeping.
	 *
	 * @var int|null
	 */
	private static $clock = null;

	/**
	 * Freeze the clock at $now, or unfreeze it with null. Tests only.
	 *
	 * @param int|null $now Timestamp.
	 */
	public static function freeze_clock( ?int $now ): void {
		self::$clock = $now;
	}

	/** Current time, honouring a frozen clock. */
	private static function now(): int {
		return null === self::$clock ? time() : self::$clock;
	}

	/** Version stamp every manifest carries. */
	public static function version(): string {
		return ( defined( 'XSPEED_VERSION' ) ? (string) XSPEED_VERSION : '0' ) . '+' . self::SCHEMA;
	}

	/** Blog whose manifests this request reads and writes. */
	public static function blog_id(): int {
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_current_blog_id' ) ) {
			return max( 1, (int) get_current_blog_id() );
		}
		return 1;
	}

	/** Root of every blog's manifests. */
	public static function root(): string {
		return rtrim( (string) XSPEED_CACHE_DIR, '/' ) . '/min/' . self::SUBDIR;
	}

	/**
	 * Manifest directory for one blog.
	 *
	 * @param int|null $blog_id Blog, or the current one.
	 */
	public static function dir( ?int $blog_id = null ): string {
		return self::root() . '/' . ( null === $blog_id ? self::blog_id() : max( 1, $blog_id ) );
	}

	/**
	 * Manifest file for one source.
	 *
	 * @param string $kind   What the key is for ('css', 'js', 'part-css', 'part-js').
	 * @param string $source Absolute source path.
	 */
	public static function file( string $kind, string $source ): string {
		return self::dir() . '/' . md5( $kind . '|' . $source ) . '.json';
	}

	/**
	 * Content key for a source, rebuilding only when its bytes changed.
	 *
	 * @param string        $kind       Manifest kind ('css', 'js', 'part-css', 'part-js').
	 * @param string        $source     Absolute source path.
	 * @param callable      $find_deps  fn( string $source ): string[], the dependency paths.
	 * @param callable|null $build      fn( string $source ): string|null|false. Builds
	 *                                  the output and returns its key; null when the
	 *                                  bytes cannot be built, false when the output
	 *                                  could not be written. Null here means the key
	 *                                  IS the source hash and there is nothing to build.
	 * @param callable|null $has_output fn( string $key ): bool. Whether the output for
	 *                                  a key is still on disk.
	 * @return string|null Key, or null when the source is unreadable or the build failed.
	 */
	public static function key_for( string $kind, string $source, callable $find_deps, ?callable $build = null, ?callable $has_output = null ): ?string {
		$file   = self::file( $kind, $source );
		$stored = self::read( $file );
		$now    = self::now();

		$output_ok = static function ( string $key ) use ( $has_output ): bool {
			return null === $has_output || (bool) $has_output( $key );
		};

		// Fast path: stat only. A recorded failure is an answer too: the same
		// bytes would fail the same way, so they are not rebuilt every render.
		if ( null !== $stored && self::is_fresh( $stored, $now ) ) {
			if ( '' === (string) $stored['key'] ) {
				return null;
			}
			if ( $output_ok( (string) $stored['key'] ) ) {
				return (string) $stored['key'];
			}
		}

		// Slow path. Stat BEFORE reading: a write that lands between the two
		// leaves a signature older than the bytes, so the next render sees a
		// mismatch and hashes again. The other order could pin new bytes
		// under an old signature.
		$paths = array_merge( array( $source ), self::unique_paths( (array) $find_deps( $source ), $source ) );
		$sig   = self::signature( $paths );
		if ( null === $sig[ $source ] ) {
			return null;
		}
		$src_md5 = self::content_hash( $paths );
		if ( null === $src_md5 ) {
			return null;
		}

		$same_bytes = null !== $stored
			&& self::version() === ( $stored['v'] ?? '' )
			&& ( $stored['src_md5'] ?? '' ) === $src_md5;
		$stored_key = null === $stored ? '' : (string) ( $stored['key'] ?? '' );

		if ( $same_bytes && '' !== $stored_key && $output_ok( $stored_key ) ) {
			$key = $stored_key;
		} elseif ( $same_bytes && '' === $stored_key ) {
			// These bytes failed before; they still would.
			$key = '';
		} elseif ( null === $build ) {
			$key = $src_md5;
		} else {
			$built = $build( $source );
			// false: the output could not be WRITTEN. That can clear up (a
			// full disk, a permission fixed), so nothing is recorded.
			if ( false === $built ) {
				return null;
			}
			// null: these bytes cannot be minified. Recorded as an empty key,
			// so the fast path answers "no" without minifying again until the
			// bytes change.
			$key = is_string( $built ) ? $built : '';
		}

		$manifest = array(
			'v'       => self::version(),
			'kind'    => $kind,
			'src'     => $source,
			'sig'     => $sig,
			'src_md5' => $src_md5,
			'key'     => $key,
			'at'      => $now,
		);

		// Unchanged and still inside the racy window: rewriting would only
		// store another racy signature, once per render, until the window
		// passes. Leave it; the first render after the window writes it.
		$unchanged = null !== $stored
			&& ( $stored['sig'] ?? null ) === $sig
			&& ( $stored['src_md5'] ?? '' ) === $src_md5
			&& ( $stored['key'] ?? '' ) === $key
			&& self::version() === ( $stored['v'] ?? '' );
		if ( ! ( $unchanged && self::is_racy( $sig, $now ) ) ) {
			self::write( $file, $manifest );
		}

		return '' === $key ? null : $key;
	}

	/**
	 * Whether a stored manifest can be trusted without reading any bytes.
	 *
	 * @param array<string,mixed> $manifest Stored manifest.
	 * @param int                 $now      Current time.
	 */
	public static function is_fresh( array $manifest, int $now ): bool {
		if ( self::version() !== ( $manifest['v'] ?? '' ) || ! isset( $manifest['key'] ) || ! is_string( $manifest['key'] ) || ! is_array( $manifest['sig'] ?? null ) ) {
			return false;
		}
		// A signature from the future means the clock moved back since it
		// was taken; nothing about it can be trusted.
		$at = (int) ( $manifest['at'] ?? 0 );
		if ( $at > $now ) {
			return false;
		}
		$stored = $manifest['sig'];
		if ( self::signature( array_keys( $stored ) ) !== $stored ) {
			return false;
		}
		// Racy is judged against when the signature was TAKEN, not now: a
		// file written in the same tick as the stat may have changed after it
		// without moving mtime or size.
		return ! self::is_racy( $stored, $at );
	}

	/**
	 * Is any timestamp in the signature within RACY_SECONDS of $at?
	 *
	 * @param array<string,mixed> $sig Signature.
	 * @param int                 $at  When it was taken.
	 */
	public static function is_racy( array $sig, int $at ): bool {
		foreach ( $sig as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$newest = max( (int) ( $entry[0] ?? 0 ), (int) ( $entry[1] ?? 0 ) );
			if ( $at - $newest < self::RACY_SECONDS ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * [mtime, ctime, size, ino] per path, null for a path that is not a file.
	 *
	 * @param string[] $paths Paths.
	 * @return array<string,array<int,int>|null>
	 */
	public static function signature( array $paths ): array {
		$sig = array();
		foreach ( $paths as $path ) {
			$path = (string) $path;
			clearstatcache( true, $path );
			$stat = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing dependency is an expected answer (null), not an error.
			if ( false === $stat || ! is_file( $path ) ) {
				$sig[ $path ] = null;
				continue;
			}
			$sig[ $path ] = array( (int) $stat['mtime'], (int) $stat['ctime'], (int) $stat['size'], (int) $stat['ino'] );
		}
		return $sig;
	}

	/**
	 * md5 over the bytes of every path, in order. A missing dependency counts
	 * as a fixed marker, so it appearing later changes the hash.
	 *
	 * @param string[] $paths Source first, then dependencies.
	 * @return string|null Null when the source itself cannot be read.
	 */
	public static function content_hash( array $paths ): ?string {
		$ctx   = hash_init( 'md5' );
		$first = true;
		foreach ( $paths as $path ) {
			$path = (string) $path;
			hash_update( $ctx, "\0" . $path . "\0" );
			$ok = is_file( $path ) && is_readable( $path ) && @hash_update_file( $ctx, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable dependency is folded into the hash as missing.
			if ( ! $ok ) {
				if ( $first ) {
					return null;
				}
				hash_update( $ctx, "\0missing\0" );
			}
			$first = false;
		}
		return hash_final( $ctx );
	}

	/**
	 * Every file a minified stylesheet's bytes depend on, recursively.
	 *
	 * Mirrors matthiasmullie/minify's CSS::combineImports() and importFiles():
	 *
	 *   - `@import` (url() or quoted) whose path is not data:, http(s): or
	 *     root-relative and carries no query. The library inlines those.
	 *     Their own imports and embeds are followed in turn.
	 *   - `url()` with an embeddable extension (or none), of ANY size: the
	 *     library embeds only files up to 5 KB, and a file that grows past
	 *     that, or shrinks under it, changes the output too.
	 *
	 * Candidates that do not exist yet are kept. The library skips them, but
	 * creating one later changes the output, so its absence is part of the
	 * signature.
	 *
	 * @param string $source Absolute stylesheet path.
	 * @return string[] Dependency paths, source excluded.
	 */
	public static function css_dependencies( string $source ): array {
		$found   = array();
		$visited = array( $source => true );
		self::walk_css( $source, $found, $visited, 0 );
		unset( $found[ $source ] );
		return array_keys( $found );
	}

	/**
	 * @param string              $source  Stylesheet being scanned.
	 * @param array<string,true>  $found   Accumulated dependencies.
	 * @param array<string,true>  $visited Stylesheets already scanned.
	 * @param int                 $depth   Import depth.
	 */
	private static function walk_css( string $source, array &$found, array &$visited, int $depth ): void {
		if ( $depth > self::MAX_WALK_DEPTH ) {
			return;
		}
		$css = @file_get_contents( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- unreadable file has no dependencies; reading a local stylesheet during page render; WP_Filesystem needs admin context.
		if ( ! is_string( $css ) || '' === $css ) {
			return;
		}
		$dir = dirname( $source );

		// Same two patterns as CSS::combineImports().
		$import_patterns = array(
			'/@import\s+url\((?P<quotes>["\']?)(?P<path>.+?)(?P=quotes)\)\s*(?P<media>[^;]*)\s*;?/ix',
			'/@import\s+(?P<quotes>["\'])(?P<path>.+?)(?P=quotes)\s*(?P<media>[^;]*)\s*;?/ix',
		);
		foreach ( $import_patterns as $pattern ) {
			if ( ! preg_match_all( $pattern, $css, $matches, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $matches as $match ) {
				$rel = (string) $match['path'];
				// Same test as the library's CSS::canImportByPath.
				if ( 1 === preg_match( '/^(data:|https?:|\\/)/', $rel ) ) {
					continue;
				}
				$candidate = self::candidate( $dir, $rel );
				if ( null === $candidate ) {
					continue;
				}
				$found[ $candidate ] = true;
				if ( ! isset( $visited[ $candidate ] ) && is_file( $candidate ) ) {
					$visited[ $candidate ] = true;
					self::walk_css( $candidate, $found, $visited, $depth + 1 );
				}
			}
		}

		// Same pattern and extension rule as CSS::importFiles().
		if ( preg_match_all( '/url\((["\']?)(.+?)\\1\)/i', $css, $urls, PREG_SET_ORDER ) ) {
			foreach ( $urls as $match ) {
				$rel = (string) $match[2];
				if ( 0 === stripos( $rel, 'data:' ) ) {
					continue;
				}
				$dot       = strrchr( $rel, '.' );
				$extension = false === $dot ? '' : substr( $dot, 1 );
				if ( '' !== $extension && ! in_array( $extension, self::EMBEDDABLE, true ) ) {
					continue;
				}
				$candidate = self::candidate( $dir, $rel );
				if ( null !== $candidate ) {
					$found[ $candidate ] = true;
				}
			}
		}
	}

	/**
	 * The path the library would try for a reference, or null when it would
	 * never read one (remote, or carrying a query). Same construction as the
	 * library: dirname(source) . '/' . reference, unnormalised.
	 *
	 * @param string $dir Directory of the referencing file.
	 * @param string $rel Reference as written.
	 */
	private static function candidate( string $dir, string $rel ): ?string {
		$path = $dir . '/' . $rel;
		if ( strlen( $path ) >= PHP_MAXPATHLEN ) {
			return null;
		}
		$parsed = wp_parse_url( $path );
		if ( ! is_array( $parsed ) || isset( $parsed['host'] ) || isset( $parsed['query'] ) ) {
			return null;
		}
		return $path;
	}

	/**
	 * Files the combiner inlines into a part through `@import`, to the same
	 * depth it follows them (Asset_Combiner::resolve_imports()).
	 *
	 * Resolution mirrors the combiner: each reference is resolved against the
	 * part's URL, and a URL under home_url() maps to ABSPATH. Candidates that
	 * do not exist yet are kept, for the same reason as css_dependencies().
	 *
	 * @param string $url Absolute URL of the part.
	 * @return string[] Dependency paths.
	 */
	public static function import_dependencies( string $url ): array {
		$found = array();
		self::walk_imports( $url, $found, 0 );
		return array_keys( $found );
	}

	/**
	 * @param string             $url   URL whose file is scanned.
	 * @param array<string,true> $found Accumulated dependencies.
	 * @param int                $depth Same counter resolve_imports() uses.
	 */
	private static function walk_imports( string $url, array &$found, int $depth ): void {
		if ( $depth > Asset_Combiner::MAX_IMPORT_DEPTH ) {
			return;
		}
		$path = self::url_to_candidate( $url );
		if ( null === $path || ! is_file( $path ) ) {
			return;
		}
		$css = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- unreadable file has no dependencies; reading a local stylesheet during page render; WP_Filesystem needs admin context.
		if ( ! is_string( $css ) || false === stripos( $css, '@import' ) ) {
			return;
		}
		if ( ! preg_match_all( '#@import\s+(?:url\s*\(\s*)?["\']?([^"\')]+)["\']?\s*\)?\s*([^;]*);#i', $css, $matches, PREG_SET_ORDER ) ) {
			return;
		}
		foreach ( $matches as $match ) {
			$child_url = Asset_Combiner::resolve_relative( trim( (string) $match[1] ), $url );
			$child     = self::url_to_candidate( $child_url );
			if ( null === $child || isset( $found[ $child ] ) ) {
				continue;
			}
			$found[ $child ] = true;
			self::walk_imports( $child_url, $found, $depth + 1 );
		}
	}

	/**
	 * Asset_Combiner::local_info()'s mapping without its existence check.
	 *
	 * @param string $url Absolute URL.
	 */
	private static function url_to_candidate( string $url ): ?string {
		$home = home_url();
		if ( '' === $url || 0 !== strpos( $url, $home ) ) {
			return null;
		}
		$clean = strtok( $url, '?' );
		if ( ! is_string( $clean ) ) {
			return null;
		}
		return ABSPATH . ltrim( str_replace( $home, '', $clean ), '/' );
	}

	/**
	 * Read a manifest, or null when absent or unreadable.
	 *
	 * @param string $file Manifest path.
	 * @return array<string,mixed>|null
	 */
	public static function read( string $file ): ?array {
		$raw = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a missing manifest is the normal first-render answer; our own cache sidecar, read on the front end where WP_Filesystem is unavailable.
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Write a manifest atomically.
	 *
	 * @param string              $file     Manifest path.
	 * @param array<string,mixed> $manifest Contents.
	 */
	public static function write( string $file, array $manifest ): bool {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$json = wp_json_encode( $manifest );
		return is_string( $json ) && self::write_atomic( $file, $json );
	}

	/**
	 * Write bytes so a reader sees either nothing or the whole file.
	 *
	 * Writes a temp file in the SAME directory (rename() is only atomic
	 * within one filesystem), checks every byte landed, then renames it over
	 * the target. A full disk or a quota truncates the temp file, which is
	 * then discarded rather than published.
	 *
	 * @param string $file  Target path.
	 * @param string $bytes Contents.
	 */
	public static function write_atomic( string $file, string $bytes ): bool {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$tmp     = $file . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
		$written = @file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- failure is reported through the return value; WP_Filesystem requires admin context; this runs on front-end renders.
		if ( strlen( $bytes ) !== $written ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- discarding our own partial temp file.
			return false;
		}
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- failure is reported through the return value; atomic publish of our own cache file; WP_Filesystem::move() is not atomic and needs admin context.
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- discarding our own temp file.
			return false;
		}
		return true;
	}

	/**
	 * Dependencies in first-seen order, without the source or duplicates.
	 *
	 * @param array<int,mixed> $deps   Dependency paths.
	 * @param string           $source Source path.
	 * @return string[]
	 */
	private static function unique_paths( array $deps, string $source ): array {
		$out = array();
		foreach ( $deps as $dep ) {
			$dep = (string) $dep;
			if ( '' !== $dep && $dep !== $source ) {
				$out[ $dep ] = true;
			}
		}
		return array_keys( $out );
	}
}
