<?php
/**
 * Health — shared diagnostic checks consumed by both Onboarding's
 * environment surface and the Health module's dashboard panel.
 *
 * Each check returns:
 *   [
 *     'id'     => 'wp_version',
 *     'tone'   => 'ok' | 'warn' | 'fail' | 'info',
 *     'label'  => 'WordPress 6.6',
 *     'detail' => 'Meets the 6.0+ minimum.',
 *   ]
 *
 * Pure reads — never writes to disk, never makes outbound HTTP calls.
 * Safe to call from any request including the loading dashboard.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Health {

	public const OK   = 'ok';
	public const WARN = 'warn';
	public const FAIL = 'fail';
	public const INFO = 'info';

	private const SERVER_LABELS = array(
		'apache'    => 'Apache',
		'litespeed' => 'LiteSpeed',
		'nginx'     => 'nginx',
		'iis'       => 'IIS',
		'unknown'   => 'Unknown',
	);

	/**
	 * Full check list used by the Health module's dashboard panel.
	 *
	 * @return array<int,array{id:string,tone:string,label:string,detail:string}>
	 */
	public static function checks(): array {
		global $wp_version;

		$server_type = Server::type();
		$gzip_mode   = Server::gzip_mode();
		$conflicts   = Server::conflicts();
		$cache_dir   = defined( 'XSPEED_CACHE_DIR' ) ? XSPEED_CACHE_DIR : ( WP_CONTENT_DIR . '/cache/xspeed' );

		$out = array();

		// WordPress version
		$wp_ok  = version_compare( (string) $wp_version, '6.0', '>=' );
		$out[]  = array(
			'id'     => 'wp_version',
			'tone'   => $wp_ok ? self::OK : self::FAIL,
			'label'  => sprintf( 'WordPress %s', (string) $wp_version ),
			'detail' => $wp_ok ? 'Meets the 6.0+ minimum.' : 'Upgrade to WordPress 6.0 or higher.',
		);

		// PHP version
		$php_ok      = version_compare( PHP_VERSION, '7.4', '>=' );
		$php_modern  = version_compare( PHP_VERSION, '8.1', '>=' );
		$out[]       = array(
			'id'     => 'php_version',
			'tone'   => $php_modern ? self::OK : ( $php_ok ? self::WARN : self::FAIL ),
			'label'  => sprintf( 'PHP %s', PHP_VERSION ),
			'detail' => $php_modern
				? 'Modern PHP — full speed.'
				: ( $php_ok
					? 'Works, but 8.1+ is recommended for best performance.'
					: 'Upgrade to PHP 7.4 or higher.' ),
		);

		// Server
		$out[] = array(
			'id'     => 'server',
			'tone'   => self::INFO,
			'label'  => sprintf( 'Server: %s', self::SERVER_LABELS[ $server_type ] ?? 'Unknown' ),
			'detail' => 'auto' === $gzip_mode
				? 'GZIP can be auto-configured via .htaccess.'
				: 'GZIP requires a manual server-config snippet (shown in the GZIP module).',
		);

		// Cache directory writable
		$dir_writable = wp_mkdir_p( $cache_dir ) && wp_is_writable( $cache_dir );
		$out[]        = array(
			'id'     => 'cache_dir',
			'tone'   => $dir_writable ? self::OK : self::FAIL,
			'label'  => 'Cache directory writable',
			'detail' => $dir_writable
				? $cache_dir
				: sprintf( 'Cannot write to %s. Adjust file permissions before enabling cache.', $cache_dir ),
		);

		// Drop-in installed (only when cache is enabled — otherwise N/A)
		$cache_enabled = (bool) Settings::get()['cache_enabled'];
		if ( $cache_enabled ) {
			// Ask the ownership oracle, not the bytes. A loose "xspeed"
			// substring matched any foreign drop-in that so much as mentions
			// us in a compatibility note, and reported it as "owned by
			// xSpeed" while another plugin served every hit — the exact
			// loose-substring test the drop-in contract forbids.
			$dropin_match = Cache::DROPIN_XSPEED === Cache::dropin_owner();
			$out[]        = array(
				'id'     => 'dropin',
				'tone'   => $dropin_match ? self::OK : self::FAIL,
				'label'  => 'advanced-cache.php drop-in',
				'detail' => $dropin_match
					? 'Installed and owned by xSpeed.'
					: 'Drop-in missing or owned by another plugin. Toggle Enable Cache off and on to reinstall.',
			);
		}

		// WP_CACHE constant
		$wp_cache_const = defined( 'WP_CACHE' ) && WP_CACHE;
		if ( $cache_enabled ) {
			// Why the constant is missing decides what we tell the user to
			// do, so the two cases can't share one sentence. An unwritable
			// wp-config.php (managed hosts make it read-only by design) is
			// the common cause and the user must paste the line by hand.
			// But it is NOT the only way to land here: a leftover
			// `define( 'WP_CACHE', false );` from a previous cache plugin,
			// a missing wp-config.php, or a WP_Filesystem that wants FTP
			// credentials all fail set_wp_cache_constant() on a perfectly
			// writable file. Asserting "not writable" unconditionally told
			// those users something demonstrably false about their own
			// server and sent them hand-editing a file the plugin could
			// have fixed by toggling the cache off and on. (#19)
			// The cost is real, but it is NOT "nothing is being cached": with
			// the constant absent, Cache::maybe_start_cache() still serves on
			// template_redirect and tags the response `HIT (php)`. What the
			// constant buys is answering from the drop-in BEFORE WordPress
			// boots — worth roughly an order of magnitude on TTFB, which is
			// what makes this a WARN worth acting on. Claiming the cache was
			// idle was simply false, and it is the sentence a worried user
			// reads first. (#19, QA on #174)
			//
			// Ask the WRITER which branch to show, not a second oracle: Health
			// used to run its own wp_is_writable() against its own path
			// resolution, and the two disagreed with set_wp_cache_constant()
			// in both directions — see Cache::can_write_wp_config().
			$wp_config_writable = Cache::can_write_wp_config();
			$check              = array(
				'id'     => 'wp_cache_constant',
				'tone'   => $wp_cache_const ? self::OK : self::WARN,
				'label'  => 'WP_CACHE constant',
				'detail' => $wp_cache_const
					? 'Defined and truthy in wp-config.php.'
					: 'Not set — cached pages are being served the slow way. Without this constant WordPress boots fully before xSpeed can answer from cache, costing roughly 10ms per hit. ' . ( $wp_config_writable
						? 'wp-config.php is writable, so toggling Enable Cache off and on should set it for you; if it comes back, another plugin may have left define( \'WP_CACHE\', false ) behind — add the line below by hand instead.'
						: 'wp-config.php is not writable here (managed hosts often make it read-only), so add the line below by hand, above the "That\'s all, stop editing!" comment.' ),
			);
			if ( ! $wp_cache_const ) {
				// The line to paste, carried on the check itself so it
				// survives on a persistent surface. Enabling the cache
				// offered this only inside a toast that cleared after two
				// seconds with no copy button, so a user who looked away
				// had no way back to it anywhere in the dashboard — while
				// the toggle read green and the site cached nothing.
				// HealthCard already renders `snippet` through CopySnippet
				// (the nginx check proves the wiring). (#19)
				$check['snippet'] = "define( 'WP_CACHE', true );";
			}
			$out[] = $check;
		}

		// Static-rewrite probe. Active end-to-end check: writes a probe
		// file under the static-cache dir, fetches it over HTTP, and
		// confirms the web server (nginx OR Apache/LiteSpeed) served
		// the raw file. Result is throttled to a 5-minute transient
		// inside Cache::probe_static_rewrite so we never hit the
		// network per-paint.
		if ( $cache_enabled ) {
			$server_type = Server::type();
			// The live loopback probe only matters where a server-level static
			// rewrite is actually used (nginx snippet / Apache .htaccess).
			// LiteSpeed serves hits via the drop-in (see below), so skip the
			// probe there entirely — no needless self-request. Health is the
			// right place to pay for the probe when we DO run it (the admin
			// bootstrap reads cache-only so it never blocks); the 5-minute
			// transient still throttles repeat runs. (FBS-82142)
			$probe     = ( Server::LITESPEED === $server_type )
				? array( 'active' => false )
				: Cache::probe_static_rewrite( true );
			$is_active = (bool) ( $probe['active'] ?? false );
			// An inconclusive probe (blocked loopback, TLS failure, timeout, a
			// CDN/WAF answering instead of the origin) proves nothing about the
			// rewrite. Reporting it as "not yet routing to the cache" told users
			// with a correct nginx config that their config was broken, and
			// pointed them at a snippet they had already pasted. (FBS-84012)
			$inconclusive = (bool) ( $probe['inconclusive'] ?? false );
			$probe_reason = (string) ( $probe['reason'] ?? '' );

			$block_reason = Cache::static_rewrite_block_reason();
			$mobile_block = ( 'mobile_separate' === $block_reason )
				? ' Note: Separate Mobile Cache is on, which disables the device-blind static rewrite — if your site serves the same HTML to all devices, turn it off (Cache settings) for much faster cache hits.'
				: '';

			// A known refusal OUTRANKS the probe. probe_static_rewrite() writes
			// its own file under the static-cache dir and fetches that — which
			// succeeds whenever the server can serve a static file at all, even
			// when static_rewrite_allowed() is false and no real page is being
			// served that way. Checking $is_active first therefore reported
			// "PHP bypassed" on a site whose pages were all returning
			// HIT (php): the panel answered "why isn't static serving active?"
			// with the opposite of the truth. When we already know why the
			// rewrite is off, say that and ignore the probe. (FBS-83145)
			$refused = ( '' !== $block_reason );
			$is_active = $is_active && ! $refused;
			// A refusal is a definite finding, so it also outranks
			// "inconclusive" — otherwise a blocked rewrite whose probe merely
			// failed to complete would be reported as INFO ("nothing to warn
			// about") instead of the WARN the block deserves.
			$inconclusive = $inconclusive && ! $refused;

			if ( Server::NGINX === $server_type ) {
				if ( $is_active ) {
					$nginx_detail = 'nginx is serving cache hits directly — PHP bypassed (~5-15ms TTFB).';
				} elseif ( 'mobile_separate' === $block_reason ) {
					$nginx_detail = 'nginx detected, but the static rewrite is disabled because Separate Mobile Cache is on.' . $mobile_block;
				} elseif ( $inconclusive ) {
					$nginx_detail = sprintf(
						'Could not verify the static rewrite — the check itself did not complete, so this is not evidence that your config is wrong. If you have already pasted the snippet, it may well be working. Reason: %s',
						$probe_reason
					);
				} else {
					$nginx_detail = 'nginx detected but not yet routing to the cache. Paste the snippet below into your site\'s server { } block, then reload nginx.';
				}

				$out[] = array(
					'id'      => 'static_rewrite_nginx',
					// Inconclusive is INFO, not WARN — we have no finding to warn about.
					'tone'    => $is_active ? self::OK : ( $inconclusive ? self::INFO : self::WARN ),
					'label'   => 'Static-file rewrite (nginx server config)',
					'detail'  => $nginx_detail,
					// Always ship the snippet — even when active, so the
					// admin has it handy for re-pasting after a server
					// rebuild without having to find it elsewhere. Mirror
					// the SAME unified block the "Server config" panel
					// renders (cache + gzip + browser-cache directives),
					// not the cache-only snippet — otherwise Health and
					// the Cache panel disagree on what to paste.
					'snippet' => Cache::full_nginx_server_block(),
				);
			} elseif ( Server::LITESPEED === $server_type ) {
				// LiteSpeed intentionally does NOT use the .htaccess static
				// rewrite: OpenLiteSpeed's .htaccess engine ignores
				// mod_headers (so we can't stamp X-XSpeed-Cache: HIT) and has
				// no per-rule access_log (so a static hit can't be counted).
				// We route LiteSpeed hits through the PHP drop-in instead, so
				// every hit is both visible (X-XSpeed-Cache: HIT) and counted
				// in the hit-ratio — see Cache::static_rewrite_allowed(). This
				// is the healthy, expected state on LiteSpeed, not a fallback.
				$out[] = array(
					'id'     => 'static_rewrite_litespeed',
					'tone'   => self::OK,
					'label'  => 'Cache serving (LiteSpeed)',
					'detail' => 'Cache hits are served by xSpeed\'s drop-in and tagged X-XSpeed-Cache: HIT — so every hit is visible and counted in your hit-ratio. (LiteSpeed\'s .htaccess can\'t add that header or log static hits, so xSpeed serves them itself for accurate reporting.)',
				);
			} elseif ( Server::APACHE === $server_type ) {
				$installed = Cache::rewrite_installed();
				if ( $is_active ) {
					$tone   = self::OK;
					$detail = 'Block installed and serving cache hits directly — PHP bypassed.';
				} elseif ( 'mobile_separate' === $block_reason ) {
					$tone   = self::WARN;
					$detail = 'Static rewrite disabled because Separate Mobile Cache is on.' . $mobile_block;
				} elseif ( 'no_mod_headers' === $block_reason ) {
					// Not "missing" — deliberately not installed, because
					// Apache can't stamp X-XSpeed-Cache without mod_headers
					// and the hit would be invisible and uncountable. Caching
					// still works via the drop-in; say so, and give the one
					// step that actually changes the outcome.
					$tone   = self::INFO;
					$detail = 'Cache hits are served by xSpeed\'s drop-in and tagged X-XSpeed-Cache: HIT (php), so every hit is visible and counted. The faster .htaccess fast path is off because Apache\'s mod_headers module is not loaded — without it a static hit could not be tagged or counted. Enable mod_headers (`a2enmod headers` on Debian/Ubuntu, then restart Apache) to shave roughly 20-30ms off each cache hit.';
				} elseif ( ! $installed ) {
					$tone   = self::WARN;
					$detail = 'Block missing from .htaccess. Toggle Enable Cache off and on to reinstall it.';
				} elseif ( $inconclusive ) {
					// Same distinction as the nginx branch: the probe never
					// reached a verdict, so telling the user to go re-check
					// AllowOverride blames a config that may be perfectly
					// fine. Report the failure honestly instead. (FBS-84012)
					$tone   = self::INFO;
					$detail = sprintf(
						'Block is installed, but the check could not complete (%s), so this is not evidence that anything is misconfigured. Re-run it with `wp xspeed cache recheck-rewrite` once the site can reach itself over HTTP.',
						$probe_reason
					);
				} else {
					$tone   = self::WARN;
					$detail = sprintf( 'Block installed but probe failed (%s). Confirm the .htaccess block is at the TOP of the file, and that AllowOverride is enabled for your site so mod_rewrite reads it.', $probe_reason );
				}
				$out[] = array(
					'id'     => 'static_rewrite',
					'tone'   => $tone,
					'label'  => 'Static-file rewrite (.htaccess)',
					'detail' => $detail,
				);
			}
		}

		// Cache expiry vs preloader schedule (deterministic rule, issue #31):
		// pages that expire faster than the preloader re-warms them leave the
		// cache cold for most real traffic — the classic "24.8% hit ratio with
		// everything on" misconfiguration. Pure logic in
		// expiry_preload_check() so it's unit-testable.
		if ( $cache_enabled ) {
			$cache_opts = Settings_Manager::get( 'cache' );
			$pre_opts   = Settings_Manager::get( 'preloader' );
			$schedule   = (string) ( $pre_opts['schedule'] ?? 'manual' );
			$mismatch   = self::expiry_preload_check(
				(int) ( $cache_opts['cache_expiry'] ?? \XSpeed\Modules\Cache\CacheModule::DEFAULT_EXPIRY_HOURS ),
				$schedule,
				! empty( $pre_opts['enabled'] ),
				self::schedule_interval_hours( $schedule )
			);
			if ( null !== $mismatch ) {
				$out[] = $mismatch;
			}
		}

		// Permalinks
		$permalinks_ok = (bool) get_option( 'permalink_structure' );
		$out[]         = array(
			'id'     => 'permalinks',
			'tone'   => $permalinks_ok ? self::OK : self::WARN,
			'label'  => 'Permalinks',
			'detail' => $permalinks_ok
				? 'Pretty permalinks active.'
				: 'Set permalinks to anything other than "Plain" — page caching needs URL paths to key on.',
		);

		// Cache-poisoning Set-Cookie detection (issue #33): a plugin emitting
		// Set-Cookie on anonymous pageviews forces CDN/edge BYPASS for all
		// HTML (Cloudflare never caches a response carrying Set-Cookie). Probe
		// is transient-throttled inside Cookie_Inspector, same pattern as the
		// static-rewrite probe above — Health is the right place to pay for it.
		// Cached-only: Health runs inside the dashboard REST request and the
		// MCP get_health tool, so this must never block on an HTTP call.
		// A cold verdict schedules a background refresh and reports nothing
		// this paint. See Cookie_Inspector::probe_cached().
		$cookie_probe = Cookie_Inspector::probe_cached();
		if ( $cookie_probe['checked'] ) {
			$offenders = $cookie_probe['cookies'];
			if ( empty( $offenders ) ) {
				$out[] = array(
					'id'     => 'set_cookie_poisoning',
					'tone'   => self::OK,
					'label'  => 'No cache-poisoning cookies',
					'detail' => 'Anonymous pages are served without Set-Cookie, so CDN/edge caches can store them.',
				);
			} else {
				$named = array();
				foreach ( $offenders as $c ) {
					$named[] = null !== $c['plugin']
						? sprintf( '%s is setting %s', $c['plugin'], $c['name'] )
						: sprintf( 'an unidentified plugin is setting %s', $c['name'] );
				}
				$out[] = array(
					'id'     => 'set_cookie_poisoning',
					'tone'   => self::WARN,
					'label'  => 'Set-Cookie on cacheable pages',
					'detail' => sprintf(
						'%s — this prevents CDN edge caching (Cloudflare returns BYPASS for any response with Set-Cookie). Configure the plugin to set its cookie via JavaScript instead, or exclude it from anonymous pageviews.',
						implode( '; ', $named )
					),
				);
			}
		}

		// Conflicting plugins
		$out[] = array(
			'id'     => 'conflicts',
			'tone'   => empty( $conflicts ) ? self::OK : self::WARN,
			'label'  => 'Caching plugin conflicts',
			// Not "Active:" — the list now includes a drop-in left behind by a
			// plugin that is not running, which is exactly the case that made
			// this row disagree with what the enable actually does.
			'detail' => empty( $conflicts )
				? 'No other caching plugins detected.'
				: sprintf( 'Found: %s. Another page cache must be off, and its advanced-cache.php gone, before xSpeed can enable its own.', implode( ', ', $conflicts ) ),
		);

		/*
		 * A migration whose source is STILL RUNNING.
		 *
		 * Distinct from the generic `conflicts` check above, which only says
		 * "another caching plugin is active". This one knows the user imported
		 * from it and chose (or was refused) to leave it on, so it can name the
		 * plugin and the decision.
		 *
		 * The point is persistence: the import screen's warning disappears the
		 * moment the user navigates away, and the risk does not. Two page
		 * caches fighting over the drop-in is exactly what breaks caching for
		 * both, so the warning has to outlive the screen it was raised on.
		 * (#189 AC4)
		 */
		$pending = class_exists( '\\XSpeed\\Migration' ) ? Migration::pending_source() : null;
		if ( null !== $pending ) {
			$out[] = array(
				'id'     => 'migration_source_active',
				'tone'   => self::WARN,
				'label'  => sprintf( '%s is still active after import', $pending['label'] ),
				'detail' => sprintf(
					'You imported settings from %s but left it running. Two page caches fight over the cache drop-in and can break caching for both — deactivate %s on the Plugins screen once you have checked the imported settings.',
					$pending['label'],
					$pending['label']
				),
			);
		}

		return $out;
	}

	/**
	 * Hours between recurring preloader crawls, per schedule option.
	 * `twicedaily` is a WordPress core schedule — omitting it meant a site
	 * using it got no check at all, not even a pass.
	 */
	public const PRELOAD_INTERVALS = array(
		'hourly'     => 1,
		'twicedaily' => 12,
		'daily'      => 24,
		'weekly'     => 168,
	);

	/**
	 * Interval in hours for a cron schedule slug, or null when it isn't a
	 * recurring schedule (`manual`) or can't be resolved.
	 *
	 * Falls back to `wp_get_schedules()` so custom crons registered by a
	 * theme or another plugin are covered too, rather than silently
	 * skipping the check.
	 */
	public static function schedule_interval_hours( string $schedule ): ?int {
		if ( isset( self::PRELOAD_INTERVALS[ $schedule ] ) ) {
			return self::PRELOAD_INTERVALS[ $schedule ];
		}
		if ( '' === $schedule || 'manual' === $schedule || ! function_exists( 'wp_get_schedules' ) ) {
			return null;
		}
		$schedules = wp_get_schedules();
		if ( ! isset( $schedules[ $schedule ]['interval'] ) ) {
			return null;
		}
		$hours = (int) round( (int) $schedules[ $schedule ]['interval'] / HOUR_IN_SECONDS );
		return $hours > 0 ? $hours : 1;
	}

	/**
	 * Deterministic rule: warn when cache_expiry is shorter than the
	 * preloader's recurring interval (pages go cold between crawls).
	 *
	 * Pure — no WP calls — so it can be unit-tested directly.
	 *
	 * @param int      $expiry_hours      Cache expiry in hours.
	 * @param string   $schedule          Preloader schedule (manual|hourly|twicedaily|daily|weekly|custom).
	 * @param bool     $preloader_enabled Whether the preloader module is on.
	 * @param int|null $interval_hours    Pre-resolved interval, for schedules
	 *                                    outside PRELOAD_INTERVALS. Keeps this
	 *                                    function pure — the caller does the
	 *                                    wp_get_schedules() lookup.
	 * @return array{id:string,tone:string,label:string,detail:string}|null Check
	 *         row, or null when the rule doesn't apply (preloader off/manual).
	 */
	public static function expiry_preload_check( int $expiry_hours, string $schedule, bool $preloader_enabled, ?int $interval_hours = null ): ?array {
		if ( ! $preloader_enabled ) {
			return null;
		}
		$interval = $interval_hours ?? ( self::PRELOAD_INTERVALS[ $schedule ] ?? null );
		if ( null === $interval || $interval < 1 ) {
			return null;
		}
		if ( $expiry_hours < $interval ) {
			return array(
				'id'     => 'expiry_preload_mismatch',
				'tone'   => self::WARN,
				'label'  => 'Cache expiry shorter than the preload schedule',
				'detail' => sprintf(
					'Pages expire after %dh but the preloader only re-warms them every %dh (%s), so most visits hit a cold cache. Raise Cache Expiry to at least %dh (Cache settings), or preload more often (Preloader settings).',
					$expiry_hours,
					$interval,
					$schedule,
					$interval
				),
			);
		}
		return array(
			'id'     => 'expiry_preload_mismatch',
			'tone'   => self::OK,
			'label'  => 'Cache expiry covers the preload schedule',
			'detail' => sprintf( 'Expiry %dh ≥ preload interval %dh (%s) — preloaded pages stay warm between crawls.', $expiry_hours, $interval, $schedule ),
		);
	}

	/**
	 * Lightweight environment payload for the onboarding wizard. Keeps
	 * the legacy shape Onboarding::env_payload returned so the Welcome
	 * step's HealthRow rendering doesn't change.
	 */
	public static function env_payload(): array {
		global $wp_version;
		$cache_dir = defined( 'XSPEED_CACHE_DIR' ) ? XSPEED_CACHE_DIR : ( WP_CONTENT_DIR . '/cache/xspeed' );
		return array(
			'wp'            => array(
				'version' => (string) $wp_version,
				'ok'      => version_compare( (string) $wp_version, '6.0', '>=' ),
			),
			'php'           => array(
				'version' => PHP_VERSION,
				'ok'      => version_compare( PHP_VERSION, '7.4', '>=' ),
				'modern'  => version_compare( PHP_VERSION, '8.1', '>=' ),
			),
			'server'        => array(
				'type'      => Server::type(),
				'gzip_mode' => Server::gzip_mode(),
			),
			'cache_dir'     => array(
				'path'     => $cache_dir,
				'writable' => wp_mkdir_p( $cache_dir ) && wp_is_writable( $cache_dir ),
			),
			'wp_config'     => array(
				'writable' => self::wp_config_writable(),
			),
			'permalinks_ok' => (bool) get_option( 'permalink_structure' ),
			'conflicts'     => Server::conflicts(),
			/*
			 * The reason the enable would be refused right now, or null.
			 *
			 * `conflicts` is a list of plugins, and the wizard used it to
			 * decide whether to open with page caching ticked. The two are
			 * not the same question: an orphaned or doubly-defined WP_CACHE
			 * refuses the enable with no plugin to name, so the wizard
			 * offered a pre-ticked switch it already knew would fail. This
			 * is the gate's own answer, so the box and the outcome agree.
			 */
			'page_cache_blocked' => Cache::acquisition_blocker(),
		);
	}

	/**
	 * Cheap writability probe for the onboarding env payload only.
	 *
	 * Deliberately NOT the oracle behind the WP_CACHE check — that asks
	 * Cache::can_write_wp_config(), which runs the same WP_Filesystem test
	 * the writer runs, so advice can never contradict behaviour. This one
	 * stays a plain filesystem read because env_payload() is documented as
	 * making no outbound calls, and WP_Filesystem() can try to open an
	 * FTP/SSH connection. It shares the writer's path resolution so the two
	 * at least agree on WHICH file they are describing. (#19, QA on #174)
	 */
	private static function wp_config_writable(): bool {
		$path = Cache::wp_config_path();
		return '' !== $path && wp_is_writable( $path );
	}
}
