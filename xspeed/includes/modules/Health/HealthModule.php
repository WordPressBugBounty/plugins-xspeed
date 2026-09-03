<?php
/**
 * Health module — read-only diagnostic surface for the dashboard.
 *
 * No settings_schema (this is a status panel, not a configuration
 * surface). The React side renders a custom panel (HealthCard, declared
 * via `ui_metadata.custom_panel`) instead of going through ModulePanel's
 * schema-driven path.
 *
 * Data sources are all existing services:
 *   - Health::checks()          — diagnostic rows
 *   - Cache::get_stats()        — cached_pages / size / last_purge /
 *                                  hits_24h / misses_24h / hit_ratio
 *   - Hit_Counter::buckets()    — 24 hourly buckets for the sparkline
 *   - Activity_Log::entries()   — newest-first event log
 *
 * Tier: Free (per FEATURES.md "Cache Insights" — Cache Performance +
 * Last 24h chart are Free; Recommendations + Frequently-missed-URLs
 * stay Pro).
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Health;

defined( 'ABSPATH' ) || exit;

use XSpeed\Activity_Log;
use XSpeed\Cache;
use XSpeed\Health;
use XSpeed\Hit_Counter;
use XSpeed\Module;

final class HealthModule extends Module {

	public const SLUG    = 'health';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	/**
	 * Surface the most important diagnostics in WordPress's built-in
	 * Site Health screen (Tools → Site Health → Status). Admins who
	 * never open the xSpeed dashboard still get a heads-up when
	 * static-rewrite is missing on Apache/LiteSpeed or when the nginx
	 * snippet hasn't been pasted yet — both lead to a 5-10× slowdown
	 * vs the optimal cache hit path.
	 */
	public function boot(): void {
		add_filter( 'site_status_tests', array( $this, 'register_site_status_tests' ) );

		// Out-of-band refresh of the Set-Cookie probe. Health::checks()
		// only ever reads the cached verdict, so the HTTP round-trip
		// happens here instead of inside a request the user waits on.
		add_action( \XSpeed\Cookie_Inspector::CRON_HOOK, array( $this, 'refresh_cookie_probe' ) );
	}

	/** Cron callback: perform the real (blocking) probe off-request. */
	public function refresh_cookie_probe(): void {
		\XSpeed\Cookie_Inspector::probe( true );
	}

	public function register_site_status_tests( array $tests ): array {
		$tests['direct']['xspeed_static_rewrite'] = array(
			'label' => __( 'xSpeed static-rewrite cache', 'xspeed' ),
			'test'  => array( $this, 'site_status_static_rewrite' ),
		);
		return $tests;
	}

	/**
	 * Site Health test row. Reports green when the .htaccess block is
	 * present (Apache/LiteSpeed) or yellow with the nginx snippet
	 * embedded when nginx is detected. Skipped entirely when cache is
	 * disabled — no point telling the user to install a rewrite they
	 * haven't opted into.
	 */
	public function site_status_static_rewrite(): array {
		$result = array(
			'label'       => __( 'xSpeed static-rewrite cache is active', 'xspeed' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance', 'xspeed' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Cache hits bypass PHP for ~5-15ms TTFB.', 'xspeed' ) . '</p>',
			'test'        => 'xspeed_static_rewrite',
		);

		$cache_enabled = (bool) ( \XSpeed\Settings::get()['cache_enabled'] ?? false );
		if ( ! $cache_enabled ) {
			$result['label']       = __( 'xSpeed cache is disabled', 'xspeed' );
			$result['status']      = 'recommended';
			$result['description'] = '<p>' . esc_html__( 'Enable the page cache in the xSpeed dashboard to start serving cached HTML for non-logged-in visitors.', 'xspeed' ) . '</p>';
			return $result;
		}

		$server_type = \XSpeed\Server::type();
		if ( \XSpeed\Server::APACHE === $server_type || \XSpeed\Server::LITESPEED === $server_type ) {
			// Only call the block "missing" when it is genuinely absent by
			// accident. When static_rewrite_allowed() deliberately refused it,
			// "toggle Enable Cache off and on" cannot reinstall anything —
			// the same condition suppresses the write and auto_heal() strips
			// the block again on the next admin load. Explain the real cause.
			$block_reason = \XSpeed\Cache::static_rewrite_block_reason();
			if ( 'no_mod_headers' === $block_reason ) {
				$result['label']       = __( 'xSpeed is serving cache hits through PHP', 'xspeed' );
				$result['status']      = 'recommended';
				$result['description'] = '<p>' . esc_html__( 'Caching is working — hits are served by the xSpeed drop-in and tagged X-XSpeed-Cache: HIT (php). The faster .htaccess fast path is off because Apache\'s mod_headers module is not loaded, without which a static hit could not be tagged or counted. Enable mod_headers (a2enmod headers on Debian/Ubuntu, then restart Apache) to shave roughly 20-30ms off each cache hit.', 'xspeed' ) . '</p>';
			} elseif ( 'mobile_separate' === $block_reason ) {
				$result['label']       = __( 'xSpeed static rewrite is off (Separate Mobile Cache)', 'xspeed' );
				$result['status']      = 'recommended';
				$result['description'] = '<p>' . esc_html__( 'Separate Mobile Cache is on, so cache hits are served by the PHP drop-in to keep per-device HTML correct. Turn Separate Mobile Cache off if your site serves the same HTML to every device to regain the faster static path.', 'xspeed' ) . '</p>';
			} elseif ( \XSpeed\Server::APACHE === $server_type && ! \XSpeed\Cache::rewrite_installed() ) {
				$result['label']       = __( 'xSpeed .htaccess rewrite block is missing', 'xspeed' );
				$result['status']      = 'recommended';
				$result['description'] = '<p>' . esc_html__( 'Without the static-rewrite block, cache hits go through the PHP drop-in (~85ms TTFB) instead of the web server (~5-15ms). Toggle Enable Cache off and on in xSpeed to reinstall the block.', 'xspeed' ) . '</p>';
			}
			return $result;
		}

		if ( \XSpeed\Server::NGINX === $server_type ) {
			$snippet               = \XSpeed\Cache::nginx_snippet();
			$result['label']       = __( 'xSpeed nginx server config required', 'xspeed' );
			$result['status']      = 'recommended';
			$result['description'] = '<p>' . esc_html__( 'xSpeed can\'t write nginx config from PHP. Paste this snippet into your site\'s server { } block, then reload nginx so cache hits serve without booting PHP:', 'xspeed' ) . '</p>'
				. '<pre style="white-space:pre;overflow-x:auto;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;padding:12px;font-size:12px;line-height:1.4;">'
				. esc_html( (string) $snippet )
				. '</pre>';
			return $result;
		}

		// Unknown / IIS — no server-level rewrite path available; PHP
		// drop-in is the best we can offer. Don't flag as broken.
		$result['label']       = __( 'xSpeed PHP drop-in cache active', 'xspeed' );
		$result['status']      = 'recommended';
		$result['description'] = '<p>' . esc_html__( 'Static-rewrite caching needs Apache, LiteSpeed, or nginx. The PHP drop-in is still serving cache hits at ~85ms TTFB on this server.', 'xspeed' ) . '</p>';
		return $result;
	}

	public function ui_metadata(): array {
		return array(
			'label'        => 'Health',
			'icon'         => 'HeartPulse',
			'description'  => 'Diagnostics, hit ratio, and recent cache activity.',
			// Health is the single host page for all Insights (FBS-83633):
			// a Recommendations action card + Cache / Visitors / PageSpeed
			// tabs. HealthPanel renders the Free cache diagnostics (the old
			// HealthCard) as the Cache tab and hosts the Pro insight panels
			// as the other tabs via ProSlot.
			'custom_panel' => 'HealthPanel',
		);
	}

	// No settings — explicit empty so Module::rest_routes() doesn't
	// auto-wire the schema-driven GET+POST.
	public function settings_schema(): array {
		return array();
	}

	public function rest_routes(): array {
		return array(
			array(
				'path'     => '/',
				'methods'  => 'GET',
				'callback' => array( $this, 'rest_get_payload' ),
			),
		);
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed health',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Print diagnostic checks + cache stats + recent activity.',
				'ai_hint'   => 'Full diagnostic sweep: what is misconfigured or degraded on this site right now, plus cache stats and recent activity. The best FIRST call for open-ended "why is my site slow" or "is anything wrong" questions.',
				'synopsis'  => array(),
			),
			array(
				'name'      => 'xspeed recommend',
				'callback'  => array( $this, 'cli_recommend' ),
				'shortdesc' => 'List ranked next-best-action recommendations, or apply one by id.',
				'ai_hint'   => 'The ranked list of what to do next to make this site faster, and the way to apply one. Use when asked "what should I improve" or "what\'s the biggest win" — each item is actionable and ordered by impact.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'list', 'apply' ),
						'optional' => true,
					),
					array(
						'type'     => 'positional',
						'name'     => 'id',
						'optional' => true,
					),
				),
			),
		);
	}

	/** CLI: `wp xspeed recommend [list|apply <id>]` — MCP-reachable via run_command. */
	public function cli_recommend( array $args, array $assoc ): void {
		$action = isset( $args[0] ) ? (string) $args[0] : 'list';

		if ( 'apply' === $action ) {
			$id = isset( $args[1] ) ? (string) $args[1] : '';
			if ( '' === $id ) {
				\WP_CLI::error( 'Usage: wp xspeed recommend apply <id>' );
				return;
			}
			$result = \XSpeed\Recommendations::apply( $id );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
				return;
			}
			\WP_CLI::success( sprintf( 'Applied "%s". %d recommendation(s) remain.', $id, count( $result['recommendations'] ) ) );
			return;
		}

		$recs = \XSpeed\Recommendations::all();
		if ( empty( $recs ) ) {
			\WP_CLI::success( 'No recommendations — configuration looks healthy.' );
			return;
		}
		foreach ( $recs as $i => $rec ) {
			$fixable = 'apply' === ( $rec['action']['type'] ?? '' ) ? ' (one-click: wp xspeed recommend apply ' . $rec['id'] . ')' : '';
			\WP_CLI::log( sprintf( '%d. [%s] %s — %s%s', $i + 1, $rec['id'], $rec['title'], $rec['detail'], $fixable ) );
		}
	}

	/**
	 * Single endpoint that backs the dashboard panel. Refreshed lazily by
	 * the React side; cheap enough that aggregating into one response is
	 * the right call (the buckets array is at most 24 entries; activity
	 * is capped at 50).
	 */
	public function rest_get_payload( \WP_REST_Request $request ) {
		return rest_ensure_response( $this->payload() );
	}

	public function payload(): array {
		return array(
			'checks'   => Health::checks(),
			'stats'    => Cache::get_stats(),
			'buckets'  => Hit_Counter::buckets(),
			'activity' => Activity_Log::entries(),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$payload = $this->payload();
		\WP_CLI::log( '== Checks ==' );
		foreach ( $payload['checks'] as $c ) {
			\WP_CLI::log( sprintf( '[%s] %s — %s', strtoupper( $c['tone'] ), $c['label'], $c['detail'] ) );
		}
		\WP_CLI::log( '' );
		\WP_CLI::log( '== Stats (24h) ==' );
		\WP_CLI::log( sprintf( 'Hits %d · Misses %d · Hit ratio %.2f%%', $payload['stats']['hits_24h'], $payload['stats']['misses_24h'], $payload['stats']['hit_ratio'] * 100 ) );
		\WP_CLI::log( '' );
		\WP_CLI::log( '== Recent activity ==' );
		foreach ( array_slice( $payload['activity'], 0, 10 ) as $e ) {
			\WP_CLI::log( sprintf( '%s [%s] %s', gmdate( 'Y-m-d H:i:s', $e['ts'] ), $e['severity'], $e['message'] ) );
		}
	}
}
