<?php
/**
 * Server / SAPI detection.
 *
 * Used by Gzip and the UI to decide which optimizations are server-applied
 * (Apache / LiteSpeed via .htaccess) vs. require manual config (nginx).
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

class Server {

	const APACHE    = 'apache';
	const LITESPEED = 'litespeed';
	const NGINX     = 'nginx';
	const IIS       = 'iis';
	const UNKNOWN   = 'unknown';

	const OPT_CACHED_TYPE = 'xspeed_server_type';

	/**
	 * Last authoritative mod_headers answer, captured under mod_php where
	 * apache_get_modules() actually exists. Read by SAPIs that cannot
	 * detect (WP-CLI, FPM) so one host gives one answer. See
	 * apache_has_mod_headers().
	 */
	const OPT_CACHED_MOD_HEADERS = 'xspeed_apache_mod_headers';

	public static function type() {
		$detected = self::detect();
		if ( self::UNKNOWN !== $detected ) {
			// Persist whenever we have a real answer so future CLI /
			// cron / REST calls (where SERVER_SOFTWARE may be empty)
			// inherit it. Non-autoloaded — only read when needed.
			$cached = get_option( self::OPT_CACHED_TYPE, null );
			if ( $cached !== $detected ) {
				update_option( self::OPT_CACHED_TYPE, $detected, false );
			}
			return $detected;
		}

		// No definitive signal this request (typically WP-CLI, where
		// SERVER_SOFTWARE is empty). Read whatever was cached the last
		// time we ran from a real HTTP request.
		$cached = get_option( self::OPT_CACHED_TYPE, null );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		return self::UNKNOWN;
	}

	/**
	 * Live detection — never reads the cache. Used by type() and by
	 * any caller that explicitly wants the current-request answer
	 * (e.g. diagnostic UI showing "detected this request").
	 *
	 * We DO NOT fall back to "if .htaccess exists assume Apache" here:
	 * Cache::install_rewrite() writes .htaccess itself, so on nginx
	 * hosts the file appears after first cache toggle and a presence
	 * check then flips us to APACHE forever. Cached HTTP detection
	 * is the cleaner backstop.
	 */
	public static function detect(): string {
		global $is_apache, $is_nginx, $is_IIS, $is_iis7;

		$signature = self::server_signature();

		if ( false !== stripos( $signature, 'litespeed' ) ) {
			return self::LITESPEED;
		}
		// apache_get_modules() exists only with mod_php (not FPM), so
		// gate it behind SERVER_SOFTWARE first. Otherwise an
		// "apache_get_modules exists" check would false-positive on a
		// few PHP-builtin-server / mod_php-on-localhost dev edge cases.
		if ( false !== stripos( $signature, 'apache' ) || ! empty( $is_apache ) ) {
			return self::APACHE;
		}
		if ( false !== stripos( $signature, 'nginx' ) || ! empty( $is_nginx ) ) {
			return self::NGINX;
		}
		if ( false !== stripos( $signature, 'microsoft-iis' ) || ! empty( $is_IIS ) || ! empty( $is_iis7 ) ) {
			return self::IIS;
		}
		return self::UNKNOWN;
	}

	/**
	 * Whether the server respects .htaccess / web.config-style file-based config.
	 */
	public static function supports_htaccess() {
		$t = self::type();
		return self::APACHE === $t || self::LITESPEED === $t;
	}

	/**
	 * Whether Apache can stamp a response header from `.htaccess`
	 * (i.e. mod_headers is loaded).
	 *
	 * This decides whether the static-rewrite fast path can be used at
	 * all. A statically-served file bypasses PHP entirely, so the ONLY
	 * way to mark it as a cache HIT is a `Header` directive in the
	 * rewrite block. Without mod_headers that directive is silently
	 * swallowed by its `<IfModule>` guard, and the site serves fast but
	 * completely invisible cache hits — no `X-XSpeed-Cache` header for
	 * the user, nothing for the hit counter. That is exactly the
	 * "cache works, dashboard says 0%" report this check exists to
	 * prevent. (Cache::static_rewrite_allowed() consumes it.)
	 *
	 * Detection is best-effort by necessity, and MUST NOT vary by SAPI:
	 *   - mod_php exposes apache_get_modules() — authoritative. Persist
	 *     that answer so other SAPIs can inherit it.
	 *   - Under PHP-FPM / WP-CLI the function doesn't exist. Read the
	 *     stored mod_php answer; only when nothing was ever stored do we
	 *     assume the module IS present, matching Apache's own default
	 *     build (mod_headers ships enabled in every mainstream distro
	 *     package). Guessing "absent" there would push every FPM site
	 *     onto the slower drop-in path over a detection limitation
	 *     rather than a real capability gap; the loopback probe in
	 *     Cache::probe_static_rewrite() is what catches a genuinely
	 *     header-less FPM host.
	 *
	 * Returning a different answer per SAPI is not merely inaccurate: it
	 * makes static_rewrite_allowed() disagree with the on-disk .htaccess,
	 * so every WP-CLI bootstrap "corrects" what the last web request
	 * wrote and vice versa — an endless rewrite/purge ping-pong that
	 * keeps the hit ratio pinned near zero. (#138)
	 *
	 * @return bool
	 */
	public static function apache_has_mod_headers(): bool {
		if ( function_exists( 'apache_get_modules' ) ) {
			$has = in_array( 'mod_headers', apache_get_modules(), true );

			// Authoritative — persist so CLI/FPM inherit it instead of
			// guessing. Non-autoloaded; only read when needed.
			$cached = get_option( self::OPT_CACHED_MOD_HEADERS, null );
			$want   = $has ? '1' : '0';
			if ( (string) $cached !== $want ) {
				update_option( self::OPT_CACHED_MOD_HEADERS, $want, false );
			}
		} else {
			// Cannot detect here. Prefer the last known real answer over
			// an optimistic guess that would flip static_rewrite_allowed().
			$cached = get_option( self::OPT_CACHED_MOD_HEADERS, null );
			$has    = ( null === $cached || '' === $cached )
				? true            // never detected: assume the distro default.
				: (bool) (int) $cached;
		}

		/**
		 * Filter: xspeed_apache_has_mod_headers
		 *
		 * Override mod_headers detection. Return false on a host where
		 * `.htaccess` Header directives are stripped (some managed
		 * stacks do this) to force cache hits through the PHP drop-in,
		 * where they are stamped and counted.
		 *
		 * @param bool $has Whether mod_headers appears to be available.
		 */
		return (bool) apply_filters( 'xspeed_apache_has_mod_headers', $has );
	}

	/**
	 * Accept a candidate access-log path only if it can actually be
	 * tail-scanned, else ''.
	 *
	 * `is_readable()` alone is not enough. The official WordPress and
	 * Apache Docker images symlink `access.log -> /dev/stdout`, i.e. a
	 * PIPE: `is_file()` is false, `filesize()` is 0, and `is_readable()`
	 * is false for the PHP user. Hit_Counter::collect_server_log_hits()
	 * fseek()s to a stored byte offset and reads forward, which a pipe
	 * or character device cannot support at all — it would either fail
	 * or block. Requiring a REGULAR file makes that contract explicit
	 * instead of relying on the filesize>0 test to reject pipes as a
	 * side effect. Containerised Apache is the standard layout, not an
	 * edge case, so this path is common. (Field report: hit ratio stuck
	 * at 0% on Dockerised Apache while the cache served correctly.)
	 *
	 * @param string $path Candidate path.
	 * @return string The path when usable, '' otherwise.
	 */
	private static function usable_access_log( string $path ): string {
		if ( '' === $path ) {
			return '';
		}
		// is_file() resolves symlinks, so access.log -> /var/log/real.log
		// is still accepted; only the pipe/device targets are rejected.
		if ( ! @is_file( $path ) || ! is_readable( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- racey stat on an external log; treated as "unusable".
			return '';
		}
		return $path;
	}

	/**
	 * Best-effort path to the web server's access log, used to count
	 * static-rewrite HITs that bypass PHP on Apache/LiteSpeed (those
	 * requests are served straight from disk and never reach our
	 * Hit_Counter inline — see Hit_Counter::collect_server_log_hits()).
	 *
	 * Resolution order:
	 *   1. The `XSPEED_ACCESS_LOG` constant, if defined (explicit override
	 *      for hosts where the log lives somewhere non-standard).
	 *   2. The `xspeed_access_log_path` filter (programmatic override).
	 *   3. Auto-detection: a short list of the standard Apache/LiteSpeed
	 *      access-log locations, returning the first that exists AND is
	 *      readable by the PHP user.
	 *
	 * Every route is funnelled through usable_access_log(), so an
	 * override can no more hand us a pipe than auto-detection can.
	 *
	 * Returns '' when nothing usable is found — a very common case on
	 * managed/cPanel hosts where the PHP user can't read the server log,
	 * and on containers where it's a symlink to stdout. Callers MUST
	 * treat '' as "can't count static hits here" and fall back
	 * gracefully (the drop-in path still counts its own HITs).
	 *
	 * @return string Absolute path, or '' if none is usable.
	 */
	public static function access_log_path(): string {
		if ( defined( 'XSPEED_ACCESS_LOG' ) && is_string( XSPEED_ACCESS_LOG ) && '' !== XSPEED_ACCESS_LOG ) {
			return self::usable_access_log( XSPEED_ACCESS_LOG );
		}

		/**
		 * Filter: xspeed_access_log_path
		 *
		 * Override the auto-detected access-log path. Return '' to disable
		 * server-log hit counting entirely.
		 *
		 * @param string|null $path Null = use auto-detection below.
		 */
		$filtered = apply_filters( 'xspeed_access_log_path', null );
		if ( is_string( $filtered ) ) {
			return self::usable_access_log( $filtered );
		}

		// Auto-detect: the standard Apache + OpenLiteSpeed/LiteSpeed
		// Enterprise access-log locations. First readable, NON-EMPTY file
		// wins — an empty global access.log (common on LiteSpeed, which
		// logs per-vhost instead) must not shadow the real per-vhost log we
		// discover below.
		$candidates = array(
			'/var/log/apache2/access.log',              // Debian/Ubuntu Apache
			'/var/log/httpd/access_log',                // RHEL/CentOS Apache
			'/var/log/apache2/other_vhosts_access.log', // Debian multi-vhost
			'/usr/local/lsws/logs/access.log',          // OpenLiteSpeed global
			'/var/log/lshttpd/access.log',              // LiteSpeed Enterprise
		);
		foreach ( $candidates as $path ) {
			// usable_access_log() enforces "regular file + readable"; the
			// non-empty test stays here so an empty global log doesn't
			// shadow the real per-vhost one found further below.
			if ( '' !== self::usable_access_log( $path ) && (int) @filesize( $path ) > 0 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- racey stat, treated as "skip".
				return $path;
			}
		}

		// LiteSpeed (and some Apache vhost setups) write a per-vhost
		// `<vhost>.access.log` rather than a single global file. Scan the
		// known log dirs for the most-recently-written, readable, non-empty
		// *.access.log and use that. Auto-tracks whichever vhost is serving
		// this site without the admin having to set a path.
		$dirs = array( '/usr/local/lsws/logs', '/var/log/lshttpd', '/var/log/apache2', '/var/log/httpd' );
		$best = '';
		$best_mtime = 0;
		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$globbed = glob( $dir . '/*access*log*' );
			if ( ! is_array( $globbed ) ) {
				continue;
			}
			foreach ( $globbed as $path ) {
				// Same regular-file contract as the fixed candidates: a
				// glob can just as easily turn up a symlink to stdout.
				if ( '' === self::usable_access_log( $path ) || (int) @filesize( $path ) === 0 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					continue;
				}
				$mtime = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $mtime > $best_mtime ) {
					$best_mtime = $mtime;
					$best       = $path;
				}
			}
		}
		return $best;
	}

	/**
	 * GZIP support category for the UI:
	 *   'auto'   — toggling writes server config (Apache / LiteSpeed)
	 *   'manual' — must be configured outside the plugin (nginx, IIS, unknown)
	 */
	public static function gzip_mode() {
		return self::supports_htaccess() ? 'auto' : 'manual';
	}

	/**
	 * Whether the server can serve Brotli-compressed responses.
	 *
	 * Brotli is an optional server module (mod_brotli on Apache,
	 * ngx_brotli on nginx, built in on LiteSpeed/OpenLiteSpeed) — unlike
	 * GZIP it is NOT guaranteed present. We report availability so the UI
	 * and any add-on (xspeed-pro Brotli module) can decide whether to
	 * emit Brotli rules or fall back to GZIP only.
	 *
	 * Detection, cheapest signal first:
	 *   1. LiteSpeed — Brotli is part of the core server, always available.
	 *   2. Apache mod_php — apache_get_modules() lists 'mod_brotli'.
	 *   3. PHP `brotli` extension (kjdev/php-ext-brotli) — lets us at least
	 *      pre-compress static files even when the web server can't.
	 * Anything else (nginx/FPM, IIS, unknown) is reported as not detected;
	 * the user can still wire ngx_brotli manually and the UI surfaces a
	 * snippet, mirroring how GZIP behaves on nginx.
	 *
	 * Result is filterable so a host with a known-good but undetectable
	 * setup (e.g. nginx + ngx_brotli) can force-enable.
	 */
	public static function brotli_available(): bool {
		$available = false;

		if ( self::LITESPEED === self::type() ) {
			$available = true;
		} elseif ( function_exists( 'apache_get_modules' ) && in_array( 'mod_brotli', apache_get_modules(), true ) ) {
			$available = true;
		} elseif ( function_exists( 'brotli_compress' ) ) {
			$available = true;
		} elseif ( self::NGINX === self::type() ) {
			// nginx modules are not introspectable from PHP, so none of the
			// branches above can ever be true on the very common nginx +
			// php-fpm setup — even while ngx_brotli is actively serving
			// `Content-Encoding: br` on every request. Reporting "unavailable"
			// there told users who had done everything right to go install a
			// module they already had.
			//
			// So ask the server instead of asking PHP: one cached loopback
			// request with `Accept-Encoding: br`, and read what comes back.
			$available = self::brotli_probe();
		}

		/**
		 * Filter detected Brotli availability.
		 *
		 * @param bool $available Whether Brotli serving was detected.
		 */
		return (bool) apply_filters( 'xspeed_brotli_available', $available );
	}

	/**
	 * Ask the web server whether it serves Brotli, by requesting our own home
	 * URL with `Accept-Encoding: br` and reading the response encoding.
	 *
	 * The only way to answer this on nginx: the module list isn't visible to
	 * PHP, so introspection can't work and the request itself is the evidence.
	 *
	 * Cached in a transient — a positive result for a day (server modules
	 * don't come and go), a negative for an hour so someone who has just
	 * installed ngx_brotli isn't told "no" until tomorrow. Failures cache
	 * briefly too, so a host that hangs on loopback self-requests can't turn
	 * every dashboard load into a timeout.
	 *
	 * @param bool $force Skip the cache and re-probe.
	 */
	public static function brotli_probe( bool $force = false ): bool {
		return 'yes' === self::brotli_probe_state( $force );
	}

	/**
	 * The probe's three-way answer: 'yes', 'no', or 'unknown'.
	 *
	 * brotli_probe() collapses this to a bool because every consumer wants
	 * one, but the distinction matters for what we TELL the user.
	 * "unknown" — a blocked or failing loopback — is not evidence that the
	 * server lacks Brotli, and reporting it as "no" would repeat the original
	 * bug in a new place: telling someone whose setup is fine that it isn't.
	 *
	 * @param bool $force Skip the cache and re-probe.
	 * @return string 'yes' | 'no' | 'unknown'
	 */
	public static function brotli_probe_state( bool $force = false ): string {
		$key = 'xspeed_brotli_probe';

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( false !== $cached ) {
				$cached = (string) $cached;
				// Legacy '1'/'0' values from an earlier cache format.
				if ( '1' === $cached ) {
					return 'yes';
				}
				if ( '0' === $cached ) {
					return 'no';
				}
				return in_array( $cached, array( 'yes', 'no', 'unknown' ), true ) ? $cached : 'unknown';
			}
		}

		$url = home_url( '/' );
		if ( ! function_exists( 'wp_remote_get' ) || '' === $url ) {
			return 'unknown';
		}

		// Stampede guard. On a cold transient every concurrent dashboard load
		// would otherwise fire its own 3s loopback request, because nothing
		// was written until the response came back. Claim the slot BEFORE the
		// request so the other callers answer 'unknown' (accurate — they
		// genuinely don't know yet) rather than piling on.
		$inflight = $key . '_inflight';
		if ( ! $force && false !== get_transient( $inflight ) ) {
			return 'unknown';
		}
		set_transient( $inflight, 1, 30 );

		// Mirror Cache::probe_static_rewrite()'s posture: short timeout so a
		// blocked loopback can't stall the caller, and relax cert verification
		// only in local/dev where self-signed certs are normal.
		$is_local = function_exists( 'wp_get_environment_type' )
			&& in_array( wp_get_environment_type(), array( 'local', 'development' ), true );

		$resp = wp_remote_get(
			$url,
			array(
				'timeout'     => 3,
				'sslverify'   => ! $is_local,
				'redirection' => 0,
				'headers'     => array(
					// `br` ONLY. Offering gzip as well would let a server that
					// prefers gzip answer with it and look like a brotli
					// failure, which is exactly the false negative this method
					// exists to remove.
					'Accept-Encoding' => 'br',
					'Cache-Control'   => 'no-cache',
				),
			)
		);

		delete_transient( $inflight );

		if ( is_wp_error( $resp ) ) {
			// Can't reach ourselves. This is NOT evidence the server lacks
			// Brotli — reporting it as "no" would repeat the original bug in a
			// new place. Cache briefly so a hanging host doesn't cost 3s on
			// every call, but re-check soon.
			set_transient( $key, 'unknown', 5 * MINUTE_IN_SECONDS );
			return 'unknown';
		}

		// A non-2xx answer tells us nothing about compression: basic auth
		// (401), maintenance mode (503) and WAF challenge pages are all
		// "couldn't check", not "no module". Caching 'no' for an hour on the
		// strength of one is the same category error this method fixes.
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			set_transient( $key, 'unknown', 5 * MINUTE_IN_SECONDS );
			return 'unknown';
		}

		// A CDN or reverse proxy in front of the origin compresses on its own
		// behalf, so `content-encoding: br` would describe the EDGE, not this
		// server. On Apache/LiteSpeed that is harmless (brotli_available()
		// short-circuits before consulting the probe), but on nginx the probe
		// IS the answer — and a large share of nginx sites sit behind
		// Cloudflare, Fastly or a load balancer. Asserting 'yes' there is the
		// mirror image of the false negative this method exists to remove, so
		// we answer 'unknown': we genuinely could not observe the origin.
		if ( self::response_came_through_proxy( $resp ) ) {
			set_transient( $key, 'unknown', HOUR_IN_SECONDS );
			return 'unknown';
		}

		$encoding = wp_remote_retrieve_header( $resp, 'content-encoding' );
		if ( is_array( $encoding ) ) {
			$encoding = implode( ',', $encoding );
		}
		$serves_brotli = false !== stripos( (string) $encoding, 'br' );

		// A positive is durable (server modules don't come and go); a negative
		// expires sooner so someone who has just installed ngx_brotli isn't
		// told "no" until tomorrow.
		$state = $serves_brotli ? 'yes' : 'no';
		set_transient( $key, $state, $serves_brotli ? DAY_IN_SECONDS : HOUR_IN_SECONDS );

		return $state;
	}

	/**
	 * Did this response come back through a CDN / reverse proxy rather than
	 * straight from our own web server?
	 *
	 * home_url() resolves through public DNS, so the request can leave the
	 * box entirely and be answered at an edge. These headers are the evidence
	 * the edge leaves behind; none of them are set by a plain origin.
	 *
	 * Deliberately conservative — a false "there's a proxy" costs a user the
	 * capability assertion and shows the 'unknown' copy, while a false "no
	 * proxy" tells an nginx user Brotli is on when their origin cannot serve
	 * it. Cache::probe_static_rewrite() shares this blind spot, which is why
	 * this is a public helper rather than inline.
	 *
	 * @param array|\WP_Error $resp Response from wp_remote_get().
	 */
	public static function response_came_through_proxy( $resp ): bool {
		if ( is_wp_error( $resp ) ) {
			return false;
		}

		// Headers whose mere presence means an intermediary handled this.
		foreach ( array( 'cf-ray', 'x-served-by', 'x-cache', 'via', 'x-varnish', 'fastly-io-info', 'x-amz-cf-id', 'x-akamai-transformed', 'x-sucuri-id' ) as $header ) {
			$value = wp_remote_retrieve_header( $resp, $header );
			if ( is_array( $value ) ) {
				$value = implode( ',', $value );
			}
			if ( '' !== (string) $value ) {
				return true;
			}
		}

		// `server:` naming a known edge. Checked by substring because these
		// arrive as `cloudflare`, `Sucuri/Cloudproxy`, `AkamaiGHost`, etc.
		$server = wp_remote_retrieve_header( $resp, 'server' );
		if ( is_array( $server ) ) {
			$server = implode( ',', $server );
		}
		$server = strtolower( (string) $server );
		foreach ( array( 'cloudflare', 'cloudfront', 'akamai', 'fastly', 'sucuri', 'incapsula', 'stackpath', 'bunnycdn', 'keycdn' ) as $needle ) {
			if ( false !== strpos( $server, $needle ) ) {
				return true;
			}
		}

		return (bool) apply_filters( 'xspeed_response_came_through_proxy', false, $resp );
	}

	/**
	 * Drop the cached Brotli probe result so the next call re-checks.
	 *
	 * Without this a user who installs ngx_brotli has no way to make the
	 * dashboard notice before the transient expires — the same gap
	 * Cache::recheck_static_rewrite() exists to close.
	 */
	public static function recheck_brotli(): bool {
		delete_transient( 'xspeed_brotli_probe' );
		return self::brotli_probe( true );
	}

	/**
	 * Is WordPress running inside a container (Docker / Podman / k8s)?
	 *
	 * Three signals checked in cheapness order, OR'd together:
	 *   1. /.dockerenv exists — Docker's traditional marker; rare absence.
	 *   2. /proc/self/mountinfo references /var/lib/docker/overlay2 or
	 *      containerd/podman storage drivers — works under cgroup v2.
	 *   3. /proc/1/cgroup names docker / kubepods / containerd / podman / lxc
	 *      — the cgroup v1 signal, still present on older Docker installs.
	 *
	 * Any single positive returns true. On non-Linux hosts (Windows /
	 * macOS / WSL host process), all three quietly return false and we
	 * fall back to "not containerized."
	 */
	public static function is_containerized(): bool {
		// 1. Docker marker file — cheap to stat, almost always present.
		if ( file_exists( '/.dockerenv' ) ) {
			return true;
		}
		// 2. mountinfo overlay2 / containerd footprint — works under cgroup v2.
		// Gate on is_readable() first: on non-Linux hosts (macOS/Windows) or
		// hosts that hide /proc (open_basedir, hardened Apache), the file is
		// absent and reading it would emit a warning. Query Monitor surfaces
		// even @-suppressed warnings, so guard rather than silence. (FBS-83114)
		if ( is_readable( '/proc/self/mountinfo' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- /proc/self/mountinfo is a virtual file; WP_Filesystem doesn't model /proc.
			$mounts = file_get_contents( '/proc/self/mountinfo' );
			if ( is_string( $mounts ) && '' !== $mounts && preg_match( '#(docker/overlay2|/var/lib/containerd|/var/lib/podman)#i', $mounts ) ) {
				return true;
			}
		}
		// 3. cgroup v1 fallback.
		if ( is_readable( '/proc/1/cgroup' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- See above.
			$cgroup = file_get_contents( '/proc/1/cgroup' );
			if ( is_string( $cgroup ) && '' !== $cgroup && preg_match( '#(docker|kubepods|containerd|podman|lxc)#i', $cgroup ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is WordPress likely behind a reverse proxy (host nginx → container
	 * php-fpm, host nginx → docker nginx, etc.)? Detection is a heuristic
	 * built from the headers WordPress hands to PHP: when a proxy forwards
	 * the request it almost always sets X-Forwarded-* or X-Real-IP.
	 *
	 * False positives (CDN-only forwarding without a local reverse proxy)
	 * are acceptable — the caller uses this signal only to soften messaging
	 * that would otherwise mislead container-host customers. False negatives
	 * (proxy that strips headers) just mean we keep showing the snippet
	 * paste UX, which is the safe default.
	 */
	public static function is_behind_proxy(): bool {
		$proxy_headers = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_SERVER' );
		foreach ( $proxy_headers as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * High-level topology classifier driving the rewrite-alert UX.
	 *
	 * Decides WHERE the user's nginx snippet needs to be installed
	 * (or whether automatic install via .htaccess covers it). The
	 * dashboard banner uses the return value to render the right
	 * "paste this here" message — there is no topology that can't
	 * benefit from xSpeed's static-rewrite; the question is only
	 * which nginx is in the cache file's filesystem.
	 *
	 * Returns one of:
	 *   'htaccess'        — Apache / LiteSpeed; .htaccess block is
	 *                       installed automatically, no user action.
	 *   'nginx-host'      — self-managed nginx on the host (no
	 *                       container in the request path). User
	 *                       pastes the snippet into their vhost
	 *                       (typically /etc/nginx/sites-enabled/<site>).
	 *   'nginx-container' — nginx running inside the same container
	 *                       as PHP. User pastes the snippet into the
	 *                       container's nginx config (typically
	 *                       docker/nginx.conf in the site's
	 *                       docker-compose dir). Host nginx (if any)
	 *                       is a reverse-proxy that just forwards
	 *                       bytes — snippet does NOT go there.
	 *   'unknown'         — IIS or undetected; treat as manual.
	 */
	public static function rewrite_topology(): string {
		$type = self::type();
		if ( self::APACHE === $type || self::LITESPEED === $type ) {
			return 'htaccess';
		}
		if ( self::NGINX === $type ) {
			return self::is_containerized() ? 'nginx-container' : 'nginx-host';
		}
		return 'unknown';
	}

	private static function server_signature() {
		return isset( $_SERVER['SERVER_SOFTWARE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
			: '';
	}

	/**
	 * Active PAGE-CACHING plugins that would fight xSpeed over the cache
	 * drop-in. Returns human-readable labels; an empty array means the field
	 * is clear. Used by the onboarding wizard's Step 1 health check and the
	 * dashboard's Health card, both of which tell the user to deactivate what
	 * is listed "to avoid double-caching".
	 *
	 * Which is why the list is filtered on the page-cache capability rather
	 * than "is it a performance plugin": Autoptimize only minifies, so naming
	 * it here made the health row give advice that was flatly wrong.
	 * Minification overlap is still caught — by Conflict_Registry, per feature.
	 *
	 * The detection key is the plugin's main file path relative to the plugins
	 * directory — the same value WordPress uses internally in `active_plugins`.
	 * Folder-only checks (`is_plugin_active('foo/')`) would false-positive on
	 * disabled plugins still on disk.
	 *
	 * Membership comes from Cache_Plugin_Catalog, so a plugin is added in one
	 * place and shows up in both this list and the conflict matrix.
	 *
	 * Activation is not the whole test, though. What actually stops xSpeed
	 * enabling its cache is who holds advanced-cache.php, and a drop-in left
	 * behind by an uninstalled plugin holds it just as firmly as a running
	 * one. Checking only active_plugins let the wizard say "No other caching
	 * plugins detected" on the environment step and then refuse the enable on
	 * the very next step, for a file it had just looked past. So a foreign
	 * drop-in is listed too, named where we can name it.
	 */
	public static function conflicts() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$found = array();
		foreach ( Cache_Plugin_Catalog::with_capability( Cache_Plugin_Catalog::CAP_PAGE_CACHE ) as $file => $entry ) {
			// xSpeed is in the catalog — it is a page cache, and the detector
			// needs to be able to name our own drop-in. It is not a conflict
			// with itself, and listing it told every site running us to
			// deactivate us to avoid double-caching.
			if ( 'xspeed/xspeed.php' === $file ) {
				continue;
			}
			if ( is_plugin_active( $file ) ) {
				$found[] = $entry['label'];
			}
		}

		$dropin = self::foreign_dropin_label();
		if ( null !== $dropin && ! in_array( $dropin, $found, true ) ) {
			$found[] = $dropin;
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * The name of whoever owns advanced-cache.php, when it is not xSpeed.
	 *
	 * Null when the file is absent or ours. An owner we cannot identify still
	 * blocks the enable, so it is reported under a generic name rather than
	 * being silently dropped — "we could not tell" and "there is nothing
	 * there" are different answers.
	 */
	private static function foreign_dropin_label(): ?string {
		$owner = Cache::dropin_owner();
		if ( Cache::DROPIN_XSPEED === $owner || Cache::DROPIN_NONE === $owner ) {
			return null;
		}
		if ( Cache::DROPIN_UNREADABLE === $owner ) {
			return __( 'an unreadable advanced-cache.php', 'xspeed' );
		}

		$contents = @file_get_contents( WP_CONTENT_DIR . '/advanced-cache.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- read-only inspection of a drop-in we may not own; failure is reported as an unidentified owner.
		$named    = is_string( $contents ) ? Cache_Plugin_Catalog::identify_dropin( $contents ) : null;
		if ( null !== $named ) {
			$entry = Cache_Plugin_Catalog::get( $named );
			return (string) ( $entry['label'] ?? $named );
		}

		return __( 'an unidentified advanced-cache.php', 'xspeed' );
	}
}
