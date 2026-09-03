<?php
/**
 * Cache module.
 *
 * Owns the cache_expiry and excluded_urls settings. cache_enabled is
 * deliberately NOT in this schema — flipping it triggers the
 * advanced-cache.php drop-in install + WP_CACHE constant edit in
 * wp-config.php, which is a sensitive single-purpose code path and lives
 * in Cache::toggle() with its own dedicated /xspeed/v1/cache/toggle REST
 * route. The dashboard's Cache page renders the special hero UI for it
 * above this module's schema-driven settings panel.
 *
 * Tier: Free.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Cache;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;
use XSpeed\Settings_Manager;

final class CacheModule extends Module {

	public const SLUG    = 'cache';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	/**
	 * Default cache lifetime in hours (7 days).
	 *
	 * Named so the readers that need a fallback share ONE value with the
	 * schema below. Three of them carried their own hardcoded `?? 24`, which
	 * silently became a stale copy the moment the default moved. They are
	 * unreachable today — Settings_Manager::get() always merges defaults —
	 * but an unreachable wrong number is still a trap for the next change.
	 * (#284 B5)
	 */
	public const DEFAULT_EXPIRY_HOURS = 24 * 7;

	public function ui_metadata(): array {
		return array(
			'label'       => 'Page Cache',
			'icon'        => 'Database',
			'description' => 'Page caching for non-logged-in visitors.',
		);
	}

	/**
	 * @inheritDoc
	 *
	 * Nothing exempt. Purging only ever touches xSpeed's own cache, so on an
	 * occupied site the setting is inert either way — but a host installing xSpeed
	 * on a user's behalf should leave nothing switched on that the user did not
	 * ask for, and "inert today" is a weak reason to make an exception.
	 */
	public function conflict_safe_exempt(): array {
		return array();
	}

	public function settings_schema(): array {
		return array(
			'cache_expiry'  => array(
				'type'        => 'int',
				// Matches the wizard's Balanced preset, which is what a fresh
				// install starts on — a shorter module default meant the two
				// disagreed about what "default" means. (#284)
				'default'     => self::DEFAULT_EXPIRY_HOURS,
				'min'         => 1,
				'max'         => 720,
				'label'       => 'Cache Expiry (hours)',
				'unit'        => 'hours',
				'description' => 'How long cached pages live before regenerating. 1 to 720 hours (30 days).',
			),
			'excluded_urls' => array(
				'type'        => 'list',
				// Comprehensive LiteSpeed / WP Rocket-parity default URL
				// exclusions (FBS-82181). Plain text = "contains", glob via
				// * ? [ ], or a `~` prefix for raw regex (e.g. ~wp-.*\.php).
				'default'     => array(
					'/wp-admin/',
					'/wp-json/',
					'/xmlrpc.php',
					'~wp-.*\.php',
					'/feed/',
					'index.php',
					'~sitemap(_index)?\.xml',
					// Bare (no trailing slash) so "contains" matches both
					// /cart and /cart/items — WooCommerce serves both forms.
					'/cart',
					'/checkout',
					'/my-account',
					'ao_noptirocket',
					'ao_speedup_cachebuster',
					'removed_item',
					'/wc-api',
					'/edd-api',
					'/wp-login',
				),
				'item_type'   => 'string',
				'label'       => 'Excluded URLs',
				'description' => 'One pattern per line. Plain text matches anywhere in the URL (e.g. /cart). Use glob for anchored matches (/cart/* matches /cart/items but not /foo/cart/bar; *.pdf matches PDFs). Prefix with ~ for a raw regex (e.g. ~wp-.*\.php).',
			),
			'excluded_cookies' => array(
				'type'        => 'list',
				// Cookies that signal a logged-in / transactional visitor
				// whose response must not be served from a shared cache.
				// `~` prefix = raw regex (e.g. ~wordpress_[a-f0-9]+). (FBS-82181)
				'default'     => array(
					'comment_author',
					'~wordpress_[a-f0-9]+',
					'wp-postpass',
					'wordpress_no_cache',
					'wordpress_logged_in',
					'edd_items_in_cart',
					'woocommerce_items_in_cart',
					'fct_cart_hash',
					'comment_',
					'woocommerce_',
					'wordpress',
					'xf_',
					'edd_',
					'jetpack',
					'yith_wcwl_session_',
					'yith_wrvp_',
					'wpsc_',
					'ecwid',
					'ec_',
					'bookly',
				),
				'item_type'   => 'string',
				'label'       => 'Excluded Cookies',
				'description' => 'Skip cache for any visitor whose request carries a cookie whose NAME matches one of these patterns. Plain text = "contains"; glob (woocommerce_*) and ~regex (~wordpress_[a-f0-9]+) supported. One per line.',
			),
			'bypass_user_agents' => array(
				'type'        => 'list',
				'default'     => array(),
				'item_type'   => 'string',
				'label'       => 'Bypass User Agents',
				'description' => 'Substring match against the visitor User-Agent. Matched UAs bypass cache (useful for screenshot bots, internal previews, monitoring). Glob + ~regex supported. One per line.',
			),
			'ignored_query_params' => array(
				'type'        => 'list',
				// Analytics / ad / session query keys stripped before the
				// cache key is computed, so /post?utm_source=x and /post
				// share one entry. `~` prefix = raw regex. (FBS-82181)
				// Matched whole-name, so every entry here means the param
				// it names and nothing that merely contains it.
				'default'     => array(
					'__s',
					'_ga',
					'_ke',
					'~[a-zA-Z0-9_-]+_sid',
					'adgroupid',
					'age-verified',
					'ao_noptimize',
					'campaignid',
					'ck_subscriber_id',
					'cn-reloaded',
					'dclid',
					'epik',
					'fb_action_ids',
					'fb_action_types',
					'fb_source',
					'fbclid',
					'gclid',
					'jobid',
					'mc_cid',
					'mc_eid',
					'mkt_tok',
					'msclkid',
					'ref',
					// Twitter/X (`ref_src`, `ref_url`) and Facebook (`refid`)
					// decorations. Enumerated because param names match
					// whole-name: the bare `ref` above no longer absorbs them,
					// and a `ref*` glob would over-match `referrer` and
					// `refund_id`, which are page-selecting.
					'ref_src',
					'ref_url',
					'refid',
					'~session_[a-zA-Z0-9_-]+_alive',
					'sseid',
					'sslid',
					'usqp',
					'~utm_[a-zA-Z0-9_-]+',
				),
				'item_type'   => 'string',
				'label'       => 'Ignored Query Parameters',
				'description' => 'Query keys removed from the URL before computing the cache key, so /post?utm_source=x and /post share a cache entry. Defaults cover the common analytics + ad + session params. Each entry matches a whole param name — plain text is an exact name, and glob (utm_*) or ~regex are anchored too, so "ref" does not also match "preference". One per line.',
			),
			'purge_on_upgrade' => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => 'Purge After Updates',
				'description' => 'Clear the page cache when a plugin, theme or WordPress core is updated. Cached HTML is produced by the code being replaced, so leaving it in place serves pre-update markup — and links to minified assets that no longer exist — until the cache expires. Translation updates are ignored, since a language pack changes no markup a cached page depends on. Updates to xSpeed itself always purge, regardless of this setting.',
			),
			'mobile_separate' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => 'Separate Mobile Cache',
				'description' => 'Keep mobile and desktop responses in separate cache buckets. Turn on for AMP, mobile-specific themes (WPtouch / Jetpack mobile theme), or any setup that serves different HTML by device.',
			),
		);
	}

	/**
	 * `mobile_separate_review` lives outside the schema: migration sets it
	 * (bool) when a source plugin had "separate mobile cache" on, so the
	 * dashboard can prompt the user to re-enable it deliberately instead of
	 * silently importing it (which would kill the device-blind static fast
	 * path). Without preserving it here, the first schema-driven cache save
	 * would rebuild the option from the schema alone and drop the flag before
	 * the user ever saw the prompt. (FBS-83145)
	 *
	 * @return string[]
	 */
	public function preserved_keys(): array {
		return array( 'mobile_separate_review' );
	}

	/**
	 * Seed per-module option from the legacy xspeed_options blob if we
	 * haven't done so yet. Idempotent — once xspeed_module_cache exists
	 * or the legacy keys are gone, this is a no-op. Runs on both boot
	 * and activate so installs on every code path are covered.
	 */
	public function boot(): void {
		$this->seed_from_legacy_if_needed();

		// Keep every mobile_separate-dependent artifact (the drop-in's
		// `.mobile-separate` flag, the device-blind server rewrite, and the
		// device-keyed caches) in lockstep with the setting — on boot, and
		// whenever the cache settings are saved. The drop-in can't read WP
		// options, so it reads the sidecar marker Cache maintains here.
		\XSpeed\Cache::reconcile_mobile_separate();
		// Both write paths matter. On a fresh install `xspeed_module_cache`
		// does not exist yet, so core's update_option() delegates to
		// add_option() and fires `add_option_…` INSTEAD of
		// `update_option_…`. Hooking only the latter meant the very first
		// save of Cache Expiry never re-baked the drop-in: the panel and the
		// DB read the new value while the drop-in kept enforcing the old
		// one, and re-saving the same value could not recover it because
		// update_option() short-circuits on an unchanged value (#251).
		$xspeed_resync_cache_artifacts = static function () {
			\XSpeed\Cache::reconcile_mobile_separate();
			// Re-bake the cookie / user-agent exclusion rules into the
			// drop-in. It runs before WordPress loads and so carries a
			// COPY of those rules, substituted at install time — and
			// auto_heal() deliberately only reinstalls when the file is
			// missing, foreign, or an older version, none of which a
			// settings change makes true. Without this, adding an
			// excluded cookie left the drop-in serving the shared
			// anonymous page to exactly the visitors it excluded, until
			// the next plugin upgrade happened to reinstall it.
			//
			// ONLY when page caching is actually on, for the same reason
			// refresh_rewrite_if_installed() below refuses to write a
			// block that isn't there: re-baking is maintenance of an
			// artifact the user opted into, never a way to acquire one.
			// Re-baking unconditionally reached past our own module — a
			// site that had declined our page cache got the drop-in
			// installed anyway on the next Cache Expiry save, and the
			// following toggle(false) then removed it. auto_heal() has
			// always gated on this flag; this path simply never did.
			// (#251)
			//
			// Through toggle() rather than install_dropin() so the re-bake
			// gets the same ownership check, lock and rollback as every
			// other page-cache write. A drop-in that turned out not to be
			// ours between the save and now is refused here too.
			$xspeed_options = get_option( 'xspeed_options', array() );
			if ( ! empty( $xspeed_options['cache_enabled'] ) ) {
				\XSpeed\Cache::toggle( true );
			}
			// Same staleness applies to the .htaccess block, which is
			// written to disk from the same generator. Refresh it only
			// when a block is already installed — writing one here would
			// enable the static path on a site that never opted in.
			\XSpeed\Cache::refresh_rewrite_if_installed();
		};
		add_action( 'update_option_xspeed_module_cache', $xspeed_resync_cache_artifacts );
		add_action( 'add_option_xspeed_module_cache', $xspeed_resync_cache_artifacts );

		// Time-driven collection of expired entries and superseded minified
		// assets. Scheduled here as well as in activate() because a site that
		// upgrades into this version never runs the activation hook again.
		add_action( \XSpeed\Cache_GC::CRON_HOOK, array( \XSpeed\Cache_GC::class, 'run' ) );
		\XSpeed\Cache_GC::ensure_scheduled();
	}

	public function activate(): void {
		$this->seed_from_legacy_if_needed();
		\XSpeed\Cache_GC::ensure_scheduled();
	}

	public function deactivate(): void {
		\XSpeed\Cache_GC::unschedule();
	}

	private function seed_from_legacy_if_needed(): void {
		if ( null !== get_option( 'xspeed_module_cache', null ) ) {
			return;
		}
		$legacy = get_option( 'xspeed_options', array() );
		if ( ! is_array( $legacy ) ) {
			return;
		}
		$seed  = array( '_version' => self::VERSION );
		$dirty = false;
		if ( array_key_exists( 'cache_expiry', $legacy ) ) {
			$seed['cache_expiry'] = max( 1, min( 720, (int) $legacy['cache_expiry'] ) );
			unset( $legacy['cache_expiry'] );
			$dirty                = true;
		}
		if ( array_key_exists( 'excluded_urls', $legacy ) ) {
			$seed['excluded_urls'] = is_array( $legacy['excluded_urls'] ) ? array_values( array_filter( $legacy['excluded_urls'], 'is_string' ) ) : array();
			unset( $legacy['excluded_urls'] );
			$dirty                  = true;
		}
		if ( $dirty ) {
			update_option( 'xspeed_module_cache', $seed );
			update_option( 'xspeed_options', $legacy );
		}
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed optimize',
				'callback'  => array( $this, 'cli_optimize' ),
				'shortdesc' => 'Measure, apply the recommended settings one at a time, verify the page still works after each, and report what changed. Use --dry-run to see the plan without touching anything.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'aggressiveness',
						'description' => 'safe (removals + server-side only), standard (default), or aggressive (includes settings known to break some themes).',
						'optional'    => true,
						'options'     => array( 'safe', 'standard', 'aggressive' ),
					),
					array(
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Show the plan and stop. Changes nothing.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'budget',
						'description' => 'Seconds to spend before stopping between steps. Default 120.',
						'optional'    => true,
					),
				),
			),
			array(
				'name'      => 'xspeed cache',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Inspect the Cache module: `status` (settings), `inventory` (which pages are cached, and how old), `size` (where the disk usage goes), `purge-log` (what cleared the cache, when and why), `purge-url <url>` to clear one page, or `recheck-rewrite` to re-run the static-rewrite probe (site-wide purge / toggle use the dedicated commands).',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'status', 'inventory', 'size', 'purge-log', 'purge-url', 'recheck-rewrite' ),
						'optional' => true,
					),
					array(
						'type'     => 'positional',
						'name'     => 'url',
						'optional' => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'limit',
						'description' => 'Rows to print for inventory / purge-log. Default 20.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'cause',
						'description' => 'Label recorded in the purge log for purge-url. Default "CLI".',
						'optional'    => true,
					),
				),
			),
		);
	}

	/**
	 * `wp xspeed optimize` — run the autopilot.
	 *
	 * Prints what it DID, not what it hoped to do: applied steps, reverted
	 * steps with the reason they were undone, and the problems it could not
	 * touch. A run that changes nothing prints that plainly rather than a
	 * success banner.
	 *
	 * @param array<int,string>    $args  Positional args (unused).
	 * @param array<string,string> $assoc Flags.
	 */
	public function cli_optimize( array $args, array $assoc ): void {
		$result = \XSpeed\Optimize_Runner::run(
			array(
				'aggressiveness' => (string) ( $assoc['aggressiveness'] ?? 'standard' ),
				'dry_run'        => isset( $assoc['dry-run'] ),
				'budget_seconds' => isset( $assoc['budget'] ) ? (int) $assoc['budget'] : 120,
			)
		);

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( ! empty( $result['dry_run'] ) ) {
			\WP_CLI::log( 'Plan (' . count( $result['plan'] ) . ' steps, nothing applied):' );
			foreach ( $result['plan'] as $step ) {
				\WP_CLI::log( '  - ' . $step['change'] . ' [' . $step['tier'] . ']' );
			}
			foreach ( $result['skipped'] as $row ) {
				\WP_CLI::log( '  skipped: ' . $row['id'] . ' — ' . $row['why'] );
			}
			return;
		}

		if ( isset( $result['message'] ) ) {
			\WP_CLI::success( (string) $result['message'] );
		}

		foreach ( $result['applied'] as $row ) {
			\WP_CLI::log( '  ✓ ' . $row['change'] );
		}
		foreach ( $result['reverted'] as $row ) {
			\WP_CLI::warning( 'Undone: ' . $row['id'] . ' — ' . $row['why'] );
		}
		foreach ( $result['unfixable'] as $row ) {
			\WP_CLI::log( '  ! ' . $row['issue'] . ( '' !== $row['fix'] ? ' — ' . $row['fix'] : '' ) );
		}

		if ( ! empty( $result['applied'] ) ) {
			\WP_CLI::success( count( $result['applied'] ) . ' change(s) applied and verified.' );
		}
	}

	public function cli_handler( array $args, array $assoc ): void {
		$action = isset( $args[0] ) ? (string) $args[0] : 'status';
		$limit  = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 20;

		/*
		 * Force a fresh static-rewrite probe. The result is cached for five
		 * minutes and nothing invalidated it, so after fixing an nginx config
		 * there was no way to re-check — the "configure your server" banner
		 * just stayed up. (FBS-84012)
		 */
		if ( 'recheck-rewrite' === $action ) {
			// Qualify the raw probe against known config refusals before
			// reporting. The probe fetches its OWN file from the static tree,
			// which succeeds even when no real page is served that way — so
			// an unqualified `active` reported "the web server is serving
			// cache hits directly" on sites whose every page returned
			// HIT (php). See Cache::qualify_rewrite_probe().
			$probe    = \XSpeed\Cache::qualify_rewrite_probe( \XSpeed\Cache::recheck_static_rewrite() );
			$blocked  = '' !== (string) $probe['block_reason'];

			if ( $probe['active'] ) {
				\WP_CLI::success( 'Static rewrite is active — the web server is serving cache hits directly.' );
				return;
			}
			if ( $blocked ) {
				\WP_CLI::warning( sprintf( 'Static rewrite is not active: %s', (string) $probe['reason'] ) );
				return;
			}
			if ( $probe['inconclusive'] ) {
				\WP_CLI::warning( sprintf( 'Could not verify the static rewrite: %s', (string) $probe['reason'] ) );
				\WP_CLI::log( 'This is a probe failure, not proof that your server config is wrong.' );
				return;
			}
			\WP_CLI::warning( sprintf( 'Static rewrite is not active: %s', (string) ( $probe['reason'] ?: 'unknown' ) ) );
			return;
		}

		if ( 'purge-url' === $action ) {
			$url = isset( $args[1] ) ? trim( (string) $args[1] ) : '';
			if ( '' === $url ) {
				\WP_CLI::error( 'Usage: wp xspeed cache purge-url <url-or-path>' );
				return;
			}
			$cause   = isset( $assoc['cause'] ) && '' !== trim( (string) $assoc['cause'] ) ? trim( (string) $assoc['cause'] ) : 'CLI';
			$removed = \XSpeed\Cache::purge_url( $url, $cause );
			if ( $removed > 0 ) {
				\WP_CLI::success( sprintf( 'Purged %d cache file(s) for %s', $removed, $url ) );
			} else {
				\WP_CLI::log( sprintf( 'No cache entries found for %s (already cold, or the URL never cached).', $url ) );
			}
			return;
		}

		if ( 'inventory' === $action ) {
			$this->cli_inventory( $limit );
			return;
		}

		if ( 'size' === $action ) {
			$this->cli_size();
			return;
		}

		if ( 'purge-log' === $action ) {
			$this->cli_purge_log( $limit );
			return;
		}

		$opts = Settings_Manager::get( self::SLUG );
		\WP_CLI::log( 'cache_expiry  ' . $opts['cache_expiry'] . 'h' );
		\WP_CLI::log( 'excluded_urls ' . count( $opts['excluded_urls'] ) . ' entries' );
		foreach ( $opts['excluded_urls'] as $u ) {
			\WP_CLI::log( '  - ' . $u );
		}
	}

	/** `wp xspeed cache inventory [--limit=N]` — which pages are cached, and how old. */
	private function cli_inventory( int $limit ): void {
		$data = \XSpeed\Cache_Inventory::entries( $limit );

		if ( empty( $data['entries'] ) ) {
			\WP_CLI::log( 'Cache is empty — no cached pages on disk.' );
			return;
		}

		\WP_CLI::log( sprintf( '%d cached page(s); showing %d.', $data['total'], count( $data['entries'] ) ) );
		if ( ! empty( $data['capped'] ) ) {
			\WP_CLI::warning( sprintf( 'Scan stopped at %d files — the list is a recent sample, not the whole cache.', \XSpeed\Cache_Inventory::SCAN_CAP ) );
		}
		foreach ( $data['entries'] as $entry ) {
			\WP_CLI::log(
				sprintf(
					'  %-58s %8s  %s  [%s]',
					null === $entry['url'] ? '(url unknown: ' . $entry['key'] . ')' : $entry['url'],
					size_format( (int) $entry['bytes'] ),
					$this->relative_age( (int) $entry['age'] ),
					implode( '+', (array) $entry['stored_in'] )
				)
			);
		}
	}

	/** `wp xspeed cache size` — where the cache's disk usage goes. */
	private function cli_size(): void {
		$data = \XSpeed\Cache_Inventory::size_breakdown();

		\WP_CLI::log( sprintf( 'Total %s across %d file(s).', size_format( (int) $data['total_bytes'] ), (int) $data['total_files'] ) );
		foreach ( $data['buckets'] as $bucket ) {
			if ( 0 === (int) $bucket['files'] ) {
				continue;
			}
			\WP_CLI::log( sprintf( '  %-32s %10s  %d file(s)', $bucket['label'], size_format( (int) $bucket['bytes'] ), (int) $bucket['files'] ) );
		}
		if ( (int) $data['compressed_bytes'] > 0 ) {
			\WP_CLI::log( sprintf( 'Precompressed on disk: %s (pages without a precompressed copy are compressed by the web server at request time).', size_format( (int) $data['compressed_bytes'] ) ) );
		}
	}

	/** `wp xspeed cache purge-log [--limit=N]` — what cleared the cache, when, and why. */
	private function cli_purge_log( int $limit ): void {
		$data = \XSpeed\Cache_Inventory::purge_log( $limit );

		if ( empty( $data['events'] ) ) {
			\WP_CLI::log( 'No purge events recorded yet.' );
			return;
		}
		foreach ( $data['events'] as $event ) {
			\WP_CLI::log( sprintf( '  %s  %s', $this->relative_age( max( 0, time() - (int) $event['ts'] ) ), $event['message'] ) );
		}
	}

	/** Compact "4h ago" for CLI columns. */
	private function relative_age( int $seconds ): string {
		if ( $seconds < 60 ) {
			return $seconds . 's ago';
		}
		if ( $seconds < 3600 ) {
			return (int) floor( $seconds / 60 ) . 'm ago';
		}
		if ( $seconds < 86400 ) {
			return (int) floor( $seconds / 3600 ) . 'h ago';
		}
		return (int) floor( $seconds / 86400 ) . 'd ago';
	}

	/**
	 * Static-rewrite directives for the unified nginx server-block
	 * snippet. Returns null when cache is disabled — there's no rewrite
	 * to install in that state. Delegates to \XSpeed\Cache::nginx_snippet()
	 * which already produces nginx-detection-gated output.
	 */
	public function nginx_directives(): ?string {
		$opts = get_option( 'xspeed_options', array() );
		if ( empty( $opts['cache_enabled'] ) ) {
			return null;
		}
		return \XSpeed\Cache::nginx_snippet();
	}
}
