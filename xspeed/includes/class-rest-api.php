<?php
/**
 * REST API endpoints.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

class Rest_Api {

	const NAMESPACE_V1 = 'xspeed/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	public function register() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);

		// Resolved white-label branding. The dashboard refetches this after
		// a white-label save so the chrome (sidebar name/logo, footer)
		// updates live without a reload. (FBS white-label-onboarding)
		register_rest_route(
			self::NAMESPACE_V1,
			'/branding',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_branding' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/cache/purge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'purge' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/cache/toggle',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'toggle_cache' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		// Force a fresh static-rewrite probe. The result is otherwise cached
		// for five minutes with nothing to invalidate it, so a user who just
		// fixed their nginx config had no way to confirm it. (FBS-84012)
		register_rest_route(
			self::NAMESPACE_V1,
			'/cache/recheck-rewrite',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'recheck_rewrite' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/cache/benchmark',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'benchmark' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/cache/benchmark/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'benchmark_history' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		// Drill-downs behind the four dashboard stat cards. Each is a plain
		// GET so the same data reaches the CLI and MCP through
		// `wp xspeed cache inventory|size|purge-log`.
		register_rest_route(
			self::NAMESPACE_V1,
			'/cache/inventory',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'cache_inventory' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					'limit'  => array(
						'type'    => 'integer',
						'default' => 50,
					),
					'offset' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'fresh'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/cache/size',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'cache_size' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/cache/purge-log',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'cache_purge_log' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 25,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/stats/hit-daily',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'hit_daily' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/recommendations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'recommendations' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/recommendations/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'recommendations_apply' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/audit/pro',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'pro_audit' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/activity',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'activity' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/modules',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_modules' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		// On-demand desktop-vs-mobile HTML equality probe (FBS-83145). POST so
		// it's never triggered by a prefetch/GET; runs only from the dashboard
		// "Check now" button behind manage_options.
		register_rest_route(
			self::NAMESPACE_V1,
			'/cache/mobile-probe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'mobile_probe' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		// Dismiss the Separate-Mobile-Cache review prompt (FBS-83145).
		register_rest_route(
			self::NAMESPACE_V1,
			'/cache/mobile-review-dismiss',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'mobile_review_dismiss' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
	}

	/**
	 * Run the on-demand mobile-equality probe and return the fresh /status
	 * mobile_separate block so the dashboard can update the callout in place.
	 */
	public function mobile_probe( $request ) {
		unset( $request );
		return rest_ensure_response( Cache::probe_mobile_equality() );
	}

	/**
	 * Clear the migration review flag so the callout stops nagging.
	 */
	public function mobile_review_dismiss( $request ) {
		unset( $request );
		Cache::clear_mobile_separate_review();
		return rest_ensure_response( array( 'dismissed' => true ) );
	}

	/**
	 * The registered-module descriptors — same payload baked into the
	 * admin bootstrap (Admin::modules_payload), re-evaluated live. The
	 * dashboard re-fetches this after a license activate/deactivate so a
	 * Pro module's custom_panel flips between its real surface and
	 * LicenseLockedPanel (decided server-side via the
	 * xspeed_module_descriptor filter) WITHOUT a full page reload.
	 */
	public function get_modules() {
		return rest_ensure_response( Admin::modules_payload() );
	}

	/**
	 * Run the Pro audit — scans current settings + cache stats,
	 * returns a personalized list of Pro features that would help
	 * THIS site. See Pro_Audit::run() for the rule set.
	 */
	public function pro_audit( $request ) {
		unset( $request );
		return rest_ensure_response( array( 'suggestions' => Pro_Audit::run() ) );
	}

	/**
	 * Recent activity-log entries for the Overview activity strip —
	 * newest-first (settings changes, purges, cache toggles, …).
	 *
	 * @param \WP_REST_Request $request Unused.
	 * @return \WP_REST_Response
	 */
	public function activity( $request ) {
		unset( $request );
		return rest_ensure_response( array( 'activity' => Activity_Log::entries() ) );
	}

	/**
	 * Cache before/after benchmark — fetches home_url() twice (with +
	 * without the bypass header) and returns side-by-side timings for
	 * the dashboard widget.
	 */
	public function benchmark( $request ) {
		unset( $request );
		return rest_ensure_response( Cache_Benchmark::run() );
	}

	/**
	 * Stored benchmark runs (oldest→newest) + the settings-change events
	 * the trend chart overlays as annotations.
	 */
	public function benchmark_history( $request ) {
		$limit = min( 100, max( 1, (int) ( $request['limit'] ?? 100 ) ) );
		return rest_ensure_response(
			array(
				'runs'    => Cache_Benchmark::history( $limit ),
				'changes' => self::settings_change_events(),
			)
		);
	}

	/**
	 * Daily hit/miss aggregates for the 7/30-day trend, plus change events
	 * for annotation markers.
	 */
	public function hit_daily( $request ) {
		$days = min( Hit_Counter::DAILY_MAX_DAYS, max( 1, (int) ( $request['days'] ?? 30 ) ) );
		return rest_ensure_response(
			array(
				'days'    => Hit_Counter::daily_series( $days ),
				'changes' => self::settings_change_events(),
			)
		);
	}

	/** Ranked "next best action" recommendations (issue #48). */
	public function recommendations( $request ) {
		unset( $request );
		return rest_ensure_response( array( 'recommendations' => Recommendations::all() ) );
	}

	/** One-click apply of a recommendation's settings fix. */
	public function recommendations_apply( $request ) {
		$id = sanitize_key( (string) ( $request['id'] ?? '' ) );
		if ( '' === $id ) {
			return new \WP_Error( 'xspeed_rec_missing_id', __( 'The id argument is required.', 'xspeed' ), array( 'status' => 400 ) );
		}
		$result = Recommendations::apply( $id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Recent settings_changed activity entries (the chart annotations).
	 *
	 * @return array<int,array{ts:int,message:string}>
	 */
	private static function settings_change_events(): array {
		$out = array();
		foreach ( Activity_Log::entries() as $entry ) {
			if ( 'settings_changed' === ( $entry['type'] ?? '' ) ) {
				$out[] = array(
					'ts'      => (int) $entry['ts'],
					'message' => (string) $entry['message'],
				);
			}
		}
		return $out;
	}

	public function permissions() {
		return current_user_can( 'manage_options' );
	}

	public function get_status() {
		$opts  = Settings::get();
		$stats = Cache::get_stats();

		// rewrite_probe + nginx_server_block mirror the admin bootstrap
		// payload (Admin::bootstrap_data). The dashboard re-fetches /status
		// after every module save to refresh the consolidated nginx
		// server-block snippet without a full page reload — if these were
		// omitted here, the snippet would only ever update on reload (the
		// QA bug: "Server config snippet requires full page reload to
		// reflect toggle changes"). Keep this in sync with Admin.
		$server_type     = Server::type();
		// LiteSpeed deliberately serves hits via the PHP drop-in (its
		// .htaccess can't add the HIT header or log a static hit), so the
		// static-rewrite probe is N/A there — surfacing it would pop the
		// "PHP fallback" nag for a setup that's working as designed. Only
		// nginx + Apache use a server-level rewrite worth probing.
		$rewrite_capable = ( $server_type === Server::NGINX || $server_type === Server::APACHE );
		$rewrite_probe   = null;
		if ( $opts['cache_enabled'] && $rewrite_capable ) {
			$probe         = Cache::probe_static_rewrite();
			$rewrite_probe = array(
				'active'       => (bool) ( $probe['active'] ?? false ),
				// Both flags were previously dropped here, so the dashboard
				// could not tell "proven inactive" from "no result yet" or
				// "probe failed" — and rendered the configure-your-server
				// banner for all three. (FBS-84012)
				'pending'      => (bool) ( $probe['pending'] ?? false ),
				'inconclusive' => (bool) ( $probe['inconclusive'] ?? false ),
				'reason'       => (string) ( $probe['reason'] ?? '' ),
				'server_type'  => $server_type,
				'snippet'      => Cache::nginx_snippet(),
				'topology'     => Server::rewrite_topology(),
				'behind_proxy' => Server::is_behind_proxy(),
			);
		}

		return rest_ensure_response(
			array(
				'enabled'            => (bool) $opts['cache_enabled'],
				'stats'              => $stats,
				'server'             => array(
					'type'           => $server_type,
					'gzip_mode'      => Server::gzip_mode(),
					'gzip_active'    => Gzip::probe_active(),
					'nginx_snippet'  => Gzip::nginx_snippet(),
				),
				'rewrite_probe'      => $rewrite_probe,
				'nginx_server_block' => Cache::full_nginx_server_block(),
				// Separate Mobile Cache visibility (FBS-83145). `blocking` is
				// true when mobile_separate is what's keeping the device-blind
				// static fast path from installing on a rewrite-capable server;
				// `needs_review` is true when a migration turned it on for us and
				// the user hasn't confirmed they actually need it. The dashboard
				// renders a callout (+ "Check now" equality probe) from these.
				'mobile_separate'    => array(
					'enabled'      => ! empty( Settings::get()['cache_enabled'] ) ? (bool) ( Settings_Manager::get( 'cache' )['mobile_separate'] ?? false ) : false,
					'blocking'     => $rewrite_capable && 'mobile_separate' === Cache::static_rewrite_block_reason(),
					'needs_review' => Cache::mobile_separate_needs_review(),
				),
			)
		);
	}

	public function get_settings() {
		return rest_ensure_response( Settings::get() );
	}

	public function update_settings( \WP_REST_Request $request ) {
		$params  = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		// `cache_enabled` is the trigger for drop-in install / wp-config.php
		// edit and must only flow through the dedicated /cache/toggle
		// endpoint. Strip it here so generic settings updates can never
		// implicitly write a drop-in or modify wp-config.php.
		unset( $params['cache_enabled'] );
		$updated = Settings::update( $params );
		return rest_ensure_response( $updated );
	}

	public function purge() {
		// purge_type( 'all' ) rather than purge_all() so the dashboard button
		// behaves identically to the admin-bar "Purge All" — including
		// clearing third-party render caches (Render_Caches).
		Cache::purge_type( 'all', __( 'dashboard', 'xspeed' ) );
		return rest_ensure_response( array( 'stats' => Cache::get_stats() ) );
	}

	/**
	 * The "Cached Pages" drill-down: which pages are cached and how old they
	 * are. Paginated because a busy site's cache is thousands of entries and
	 * the answer to "is my cache working" doesn't need all of them at once.
	 */
	public function cache_inventory( \WP_REST_Request $request ) {
		return rest_ensure_response(
			Cache_Inventory::entries(
				(int) $request->get_param( 'limit' ),
				(int) $request->get_param( 'offset' ),
				(bool) $request->get_param( 'fresh' )
			)
		);
	}

	/** The "Cache Size" drill-down: where the bytes actually go. */
	public function cache_size() {
		return rest_ensure_response( Cache_Inventory::size_breakdown() );
	}

	/** The "Last Purge" drill-down: what cleared the cache, when, and why. */
	public function cache_purge_log( \WP_REST_Request $request ) {
		return rest_ensure_response( Cache_Inventory::purge_log( (int) $request->get_param( 'limit' ) ) );
	}

	/**
	 * Resolved branding ({name, footer_credit, hide_help_links, logo_svg}).
	 * Runs the `xspeed_branding` filter so Pro's white-label override is
	 * reflected. Consumed by the dashboard's post-save branding refresh.
	 */
	public function get_branding() {
		return rest_ensure_response( Admin::branding() );
	}

	/**
	 * Re-run the static-rewrite probe, bypassing the cached result.
	 *
	 * Returns the same shape the dashboard bootstrap uses, so the caller can
	 * swap it straight into state without a second round trip. (FBS-84012)
	 */
	public function recheck_rewrite() {
		$raw         = Cache::recheck_static_rewrite();
		$server_type = Server::detect();

		// Qualify the raw probe against known config refusals. The probe
		// fetches its own file from the static tree, which succeeds even when
		// no real page is served that way — so an unqualified `active` told
		// clients the static path was engaged on sites where it demonstrably
		// wasn't. `block_reason` is exposed so a client can act on the
		// specific cause rather than re-deriving it. See
		// Cache::qualify_rewrite_probe().
		$probe = Cache::qualify_rewrite_probe( $raw );

		return rest_ensure_response(
			array(
				'active'       => $probe['active'],
				'pending'      => (bool) ( $raw['pending'] ?? false ),
				'inconclusive' => $probe['inconclusive'],
				'reason'       => $probe['reason'],
				'block_reason' => $probe['block_reason'],
				'server_type'  => $server_type,
				'snippet'      => Cache::nginx_snippet(),
				'topology'     => Server::rewrite_topology(),
				'behind_proxy' => Server::is_behind_proxy(),
			)
		);
	}

	public function toggle_cache( \WP_REST_Request $request ) {
		$params  = $request->get_json_params();
		$enabled = isset( $params['enabled'] ) ? (bool) $params['enabled'] : false;

		// User-explicit drop-in install / wp-config.php edit happens here.
		// permission_callback above already enforced current_user_can(
		// 'manage_options' ); the REST nonce is verified by core via the
		// X-WP-Nonce header.
		$state   = Cache::toggle( $enabled );
		$updated = Settings::get();

		// Recompute the unified nginx block AFTER cache_enabled is persisted.
		// Cache::toggle() computes it inline, but cache_enabled isn't written
		// until the Settings::update() above — so the block inside $state
		// reflects the PRE-toggle state (CacheModule::nginx_directives() gates
		// on cache_enabled). Regenerate here so the dashboard's optimistic
		// update shows the snippet for the state the user just selected.
		$state['nginx_server_block'] = Cache::full_nginx_server_block();

		return rest_ensure_response(
			array(
				'enabled'        => $updated['cache_enabled'],
				// Surfaced at the top level so the dashboard can explain a
				// refusal rather than silently snapping the toggle back:
				// Cache::toggle() writes nothing when another plugin owns the
				// drop-in or WP_CACHE is in a shape we must not rewrite.
				'blocked'        => ! empty( $state['blocked'] ),
				'blocked_reason' => $state['blocked_reason'] ?? null,
				'stats'          => Cache::get_stats(),
				'install_state'  => $state,
			)
		);
	}
}
