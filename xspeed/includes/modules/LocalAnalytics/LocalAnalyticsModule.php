<?php
/**
 * Local Analytics module.
 *
 * Serves Google's analytics script (gtag.js) from this site's uploads folder
 * instead of www.googletagmanager.com. Google sends that file with a short
 * cache lifetime, so PageSpeed lists it under "Serve static assets with an
 * efficient cache policy", and a site owner cannot change Google's headers.
 * The local copy is cached as long as the site's own files. (#611)
 *
 * A visitor render never downloads anything. The first page that loads a
 * GA4 tag queues its measurement ID and schedules a cron fetch. Once the
 * copy is stored, later renders point at it and the page cache is purged
 * once. A copy is downloaded again every day. If that fails, the copy on
 * disk keeps serving; if there has never been a copy, the page keeps
 * Google's URL. Tracking itself still goes to Google: only the file that
 * starts it moves.
 *
 * Only a plain `gtag/js?id=G-…` URL is rewritten. A URL with any other
 * parameter (a renamed dataLayer, for one) is left alone, because the file
 * Google builds for it differs from the one stored.
 *
 * Tier: Free per FEATURES.md (Others, Local Analytics): LiteSpeed Cache's
 * free Localize Resources does the same for gtag.js.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\LocalAnalytics;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;
use XSpeed\Local_Analytics_Store as Store;

final class LocalAnalyticsModule extends Module {

	public const SLUG    = 'local-analytics';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	/** After the other Pro rewrites; it touches only gtag script tags. */
	public const FINAL_HTML_PRIORITY = 24;


	/** Guard so template_redirect opens one buffer per request. */
	private static bool $buffering = false;

	public function ui_metadata(): array {
		return array(
			'label'       => __( 'Local Analytics', 'xspeed' ),
			'icon'        => 'ChartLine',
			'description' => __( 'Serves the Google Analytics script from your own site, with your own cache headers.', 'xspeed' ),
			'group'       => 'performance',
		);
	}

	public function rest_routes(): array {
		return array_merge(
			parent::rest_routes(),
			array(
				array(
					'path'     => '/status',
					'methods'  => 'GET',
					'callback' => array( $this, 'rest_status' ),
				),
				array(
					'path'     => '/purge',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_purge' ),
				),
			)
		);
	}

	public function settings_schema(): array {
		return array(
			'enabled' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Serve Google Analytics from this site', 'xspeed' ),
				'description' => __( 'Downloads the Google Analytics 4 script (gtag.js) your pages load into the uploads folder, serves it from there and downloads it again every day. Tracking data still goes to Google. The first visits after turning this on still load the script from Google.', 'xspeed' ),
			),
		);
	}

	/**
	 * What the copies are doing, above the switch.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function ui_notices(): array {
		if ( empty( $this->get_setting( 'enabled', false ) ) ) {
			return array();
		}
		$manifest = Store::manifest();
		if ( array() === $manifest ) {
			return array(
				array(
					'tone'  => 'info',
					'title' => __( 'Waiting for a page with Google Analytics', 'xspeed' ),
					'body'  => __( 'No Google Analytics 4 tag has been seen yet. The script downloads after the next visit to a page that loads one.', 'xspeed' ),
				),
			);
		}
		$notices = array();
		foreach ( $manifest as $id => $entry ) {
			if ( Store::STATUS_READY === $entry['status'] ) {
				$notices[] = array(
					'tone'  => '' === $entry['error'] ? 'success' : 'warn',
					/* translators: %s: Google Analytics measurement ID. */
					'title' => sprintf( __( '%s loads from this site', 'xspeed' ), $id ),
					'body'  => '' === $entry['error']
						/* translators: %s: time since the download, such as "3 hours". */
						? sprintf( __( 'Downloaded %s ago.', 'xspeed' ), human_time_diff( (int) $entry['fetched_at'] ) )
						/* translators: %s: error message. */
						: sprintf( __( 'The last download failed, so the earlier copy keeps serving: %s', 'xspeed' ), $entry['error'] ),
				);
				continue;
			}
			$notices[] = array(
				'tone'  => Store::STATUS_FAILED === $entry['status'] ? 'danger' : 'info',
				/* translators: %s: Google Analytics measurement ID. */
				'title' => sprintf( __( '%s still loads from Google', 'xspeed' ), $id ),
				'body'  => '' === $entry['error']
					? __( 'The script downloads in the background shortly.', 'xspeed' )
					/* translators: %s: error message. */
					: sprintf( __( 'The download failed: %s', 'xspeed' ), $entry['error'] ),
			);
		}
		return $notices;
	}

	public function boot(): void {
		add_action( Store::CRON_HOOK, array( $this, 'cron_run' ) );
		add_action( 'update_option_xspeed_module_' . self::SLUG, array( $this, 'on_settings_change' ), 10, 2 );
		// Deferred to `init`: reading settings builds the schema, whose labels are translated.
		add_action( 'init', array( $this, 'boot_on_init' ) );
	}

	public function boot_on_init(): void {
		if ( is_admin()
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( function_exists( 'is_customize_preview' ) && is_customize_preview() )
			|| \XSpeed\Builder_Editor::is_active()
		) {
			return;
		}
		if ( empty( $this->get_setting( 'enabled', false ) ) ) {
			return;
		}

		add_filter( 'xspeed_cache_final_html', array( $this, 'rewrite_html' ), self::FINAL_HTML_PRIORITY );

		// Requests the page cache will not store never reach the filter
		// above, so buffer those ourselves.
		add_action(
			'template_redirect',
			function () {
				// The page cache is buffering and hands us the page through its filter.
				if ( self::$buffering || \XSpeed\Cache::is_buffering() ) {
					return;
				}
				if ( is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() ) {
					return;
				}
				self::$buffering = true;
				ob_start(
					function ( $buffer ) {
						return $this->rewrite_html( $buffer );
					}
				);
			},
			9
		);
	}

	/**
	 * Point every plain GA4 gtag.js tag at its local copy. An ID with no copy
	 * yet is queued and keeps Google's URL. Public for both transports and
	 * the tests.
	 *
	 * @param mixed $html Page HTML.
	 * @return mixed
	 */
	public function rewrite_html( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, 'googletagmanager.com/gtag/js' ) ) {
			return $html;
		}
		$queued = false;
		$out    = self::rewrite(
			$html,
			static function ( string $id ) use ( &$queued ): ?string {
				$local = Store::local_url( $id );
				if ( null === $local && Store::enqueue( $id ) ) {
					$queued = true;
				}
				return $local;
			}
		);
		if ( $queued || null !== Store::next_due() ) {
			// Covers a lost cron event as well as a new ID.
			Store::ensure_scheduled();
		}
		return $out;
	}

	/**
	 * The rewrite on its own: $resolve gets each GA4 ID and returns the URL
	 * to load it from, or null to keep Google's.
	 *
	 * @param callable(string):?string $resolve
	 */
	public static function rewrite( string $html, callable $resolve ): string {
		$out = preg_replace_callback(
			'#(<script\b[^>]*?\bsrc\s*=\s*)(["\'])((?:https?:)?//www\.googletagmanager\.com/gtag/js\?[^"\']*)\2#i',
			static function ( array $m ) use ( $resolve ): string {
				$id = self::id_from_url( $m[3] );
				if ( null === $id ) {
					return $m[0];
				}
				$local = $resolve( $id );
				return null === $local ? $m[0] : $m[1] . $m[2] . esc_url( $local ) . $m[2];
			},
			$html
		);
		return is_string( $out ) ? $out : $html;
	}

	/**
	 * The GA4 ID from a gtag.js URL whose only parameter is `id`, else null.
	 */
	public static function id_from_url( string $url ): ?string {
		$query = (string) wp_parse_url( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5 ), PHP_URL_QUERY );
		if ( '' === $query ) {
			return null;
		}
		parse_str( $query, $params );
		if ( array( 'id' ) !== array_keys( $params ) ) {
			return null;
		}
		return Store::normalize_id( $params['id'] );
	}

	/** Cron: fetch due copies. */
	public function cron_run(): void {
		if ( empty( $this->get_setting( 'enabled', false ) ) ) {
			return;
		}
		Store::run();
	}


	/**
	 * Turning the module off: cached pages still name the local copy, which
	 * would no longer be refreshed. Purge so they go back to Google's URL.
	 *
	 * @param mixed $old Previous option value.
	 * @param mixed $new New option value.
	 */
	public function on_settings_change( $old, $new ): void {
		$was = is_array( $old ) && ! empty( $old['enabled'] );
		$now = is_array( $new ) && ! empty( $new['enabled'] );
		if ( $was && ! $now ) {
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( Store::CRON_HOOK );
			}
			if ( Store::has_ready() ) {
				Store::purge_page_cache( 'local analytics turned off' );
			}
		}
	}


	public function deactivate(): void {
		wp_clear_scheduled_hook( Store::CRON_HOOK );
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed local-analytics status',
				'risk'      => 'read',
				'callback'  => array( $this, 'cli_status' ),
				'shortdesc' => 'Show Local Analytics: the setting and each Google Analytics ID with its state.',
				'ai_hint'   => 'Is the Google Analytics script served from this site or from Google? When was it last downloaded, and did the last download fail?',
				'synopsis'  => array(),
			),
			array(
				'name'      => 'xspeed local-analytics refresh',
				'risk'      => 'write',
				'callback'  => array( $this, 'cli_refresh' ),
				'shortdesc' => 'Download the Google Analytics script again now for every ID seen on the site.',
				'synopsis'  => array(),
			),
			array(
				'name'      => 'xspeed local-analytics purge',
				'risk'      => 'write',
				'callback'  => array( $this, 'cli_purge' ),
				'shortdesc' => 'Delete the local copies and purge the page cache. Pages load the script from Google until it downloads again.',
				'synopsis'  => array(),
			),
		);
	}

	/**
	 * `wp xspeed local-analytics status`
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Unused.
	 */
	public function cli_status( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$summary = $this->summary();
		\WP_CLI::log( sprintf( '%-10s %s', 'enabled', $summary['enabled'] ? 'yes' : 'no' ) );
		\WP_CLI::log( sprintf( '%-10s %s', 'folder', Store::dir() ) );
		foreach ( $summary['ids'] as $row ) {
			$line = sprintf( '%-16s %-8s %s', $row['id'], $row['status'], $row['fetched_at'] ? gmdate( 'Y-m-d H:i', $row['fetched_at'] ) . ' UTC' : '-' );
			if ( '' !== $row['error'] ) {
				$line .= '  (' . $row['error'] . ')';
			}
			\WP_CLI::log( $line );
		}
	}

	/**
	 * `wp xspeed local-analytics refresh`
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Unused.
	 */
	public function cli_refresh( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$res = Store::run( true );
		if ( $res['kept'] > 0 ) {
			\WP_CLI::warning( sprintf( '%d ready %s could not be downloaded again. The site keeps serving the copy it has; `status` shows the error.', $res['kept'], 1 === $res['kept'] ? 'copy' : 'copies' ) );
		}
		\WP_CLI::success( sprintf( '%d downloaded again, %d newly ready, %d failed.', $res['refreshed'], $res['ready'], $res['failed'] ) );
	}

	/**
	 * `wp xspeed local-analytics purge`
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Unused.
	 */
	public function cli_purge( array $args, array $assoc ): void {
		unset( $args, $assoc );
		Store::purge();
		// Cached pages point at the files just deleted.
		Store::purge_page_cache( 'local analytics purged' );
		\WP_CLI::success( 'Deleted the local copies and purged the page cache.' );
	}

	/**
	 * What `status` and the REST route show.
	 *
	 * @return array{enabled:bool,ids:list<array{id:string,status:string,fetched_at:int,error:string,bytes:int}>}
	 */
	private function summary(): array {
		$ids = array();
		foreach ( Store::manifest() as $id => $entry ) {
			$ids[] = array(
				'id'         => (string) $id,
				'status'     => (string) $entry['status'],
				'fetched_at' => (int) $entry['fetched_at'],
				'error'      => (string) $entry['error'],
				'bytes'      => (int) $entry['bytes'],
			);
		}
		return array(
			'enabled'  => (bool) $this->get_setting( 'enabled', false ),
			'ids'      => $ids,
		);
	}

	/**
	 * GET /xspeed/v1/local-analytics/status
	 *
	 * @param \WP_REST_Request $request Unused.
	 */
	public function rest_status( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		return new \WP_REST_Response( $this->summary() );
	}

	/**
	 * POST /xspeed/v1/local-analytics/purge
	 *
	 * Same as `wp xspeed local-analytics purge`.
	 *
	 * @param \WP_REST_Request $request Unused.
	 */
	public function rest_purge( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		Store::purge();
		Store::purge_page_cache( 'local analytics purged' );
		return new \WP_REST_Response( array_merge( $this->summary(), array( 'purged' => true ) ) );
	}
}
