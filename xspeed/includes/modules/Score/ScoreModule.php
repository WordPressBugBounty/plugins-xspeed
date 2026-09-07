<?php
/**
 * Score — external performance scores (PageSpeed Insights / GTmetrix)
 * on the dashboard (issue #47).
 *
 * Free tier. Users judge a caching plugin by its PSI or GTmetrix score
 * whether or not the plugin shows one, so the number belongs next to the
 * internal TTFB benchmark instead of one browser tab away.
 *
 * The module owns settings, REST and CLI; the measuring lives in
 * \XSpeed\Score. Runs are stored in the shape Pro's Pagespeed engine
 * already returns, so a Pro audit and a Free audit are the same kind of
 * row and the history stays a single series — Pro augments this rather
 * than starting a second one.
 *
 * Outbound HTTP is opt-in: nothing here calls out unless the module is
 * enabled AND a person presses Test (or runs the command). No schedule,
 * no background call. Disclosed in readme.txt "External services".
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Score;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;
use XSpeed\Modules\Mcp\Mcp_Hub;
use XSpeed\Scan;
use XSpeed\Score;
use XSpeed\Settings_Manager;

final class ScoreModule extends Module {

	public const SLUG    = 'score';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.1.0';

	public function ui_metadata(): array {
		return array(
			'label'        => 'Speed Test',
			'icon'         => 'Gauge',
			// Provider-neutral: the panel runs whichever provider the site has
			// configured (PageSpeed Insights by default, no API key needed).
			// The Hub-run test has its own copy and is gated behind
			// hub_speed_test_enabled(), so this line must not promise it.
			'description'  => 'Run a PageSpeed Insights or GTmetrix audit from the dashboard and keep the history next to your TTFB benchmark.',
			'custom_panel' => 'ScorePanel',
		);
	}

	public function settings_schema(): array {
		return array(
			'enabled'           => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => 'Enable external scores',
				// Off by default and stated plainly: this is the only part
				// of the plugin that talks to a third party on your behalf.
				'description' => 'Lets you run a PageSpeed Insights or GTmetrix audit from this dashboard. Nothing is sent anywhere until you press Test.',
			),
			'provider'          => array(
				'type'        => 'enum',
				'default'     => 'psi',
				'options'     => array( 'psi', 'gtmetrix' ),
				'option_labels' => array(
					'psi'      => 'PageSpeed Insights',
					'gtmetrix' => 'GTmetrix',
				),
				'label'       => 'Provider',
				'description' => 'PageSpeed Insights works without an API key. GTmetrix requires one.',
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'psi_api_key'       => array(
				'type'        => 'secret',
				'default'     => '',
				'label'       => 'PageSpeed API key (optional)',
				'description' => 'Only needed if you hit Google\'s anonymous rate limit. Free from cloud.google.com.',
				// Rendered as a trailing "Check the documentation" link —
				// descriptions themselves are plain text (#111).
				'doc_url'     => 'https://xspeedcache.com/docs/pagespeed-insights-integration/',
				'dependsOn'   => array(
					'field' => 'provider',
					'value' => 'psi',
				),
			),
			'gtmetrix_api_key'  => array(
				'type'        => 'secret',
				'default'     => '',
				'label'       => 'GTmetrix API key',
				'description' => 'Required — GTmetrix has no anonymous mode. Found in your GTmetrix account settings.',
				'dependsOn'   => array(
					'field' => 'provider',
					'value' => 'gtmetrix',
				),
			),
			'test_url'          => array(
				'type'        => 'url',
				'default'     => '',
				'label'       => 'URL to test',
				'description' => 'Leave empty to test your home page.',
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'default_strategy'  => array(
				'type'        => 'enum',
				'default'     => 'mobile',
				'options'     => array( 'mobile', 'desktop' ),
				'label'       => 'Strategy',
				'description' => 'PageSpeed Insights only. Mobile is what Google ranks on.',
				'dependsOn'   => array(
					'field' => 'provider',
					'value' => 'psi',
				),
			),
		);
	}

	/**
	 * Encrypt the pre-1.1.0 plaintext API keys on upgrade — psi_api_key /
	 * gtmetrix_api_key became `secret`-typed fields (encrypted at rest).
	 * Idempotent. (#115)
	 */
	public function migrations(): array {
		return array(
			'1.1.0' => static function ( array $opts ): array {
				foreach ( array( 'psi_api_key', 'gtmetrix_api_key' ) as $key ) {
					if ( isset( $opts[ $key ] ) && is_string( $opts[ $key ] ) && '' !== $opts[ $key ] ) {
						$opts[ $key ] = \XSpeed\Settings_Manager::encrypt_for_storage( $opts[ $key ] );
					}
				}
				return $opts;
			},
		);
	}

	public function rest_routes(): array {
		return array_merge(
			parent::rest_routes(),
			array(
				array(
					'path'     => '/run',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_run' ),
					'feature'  => self::SLUG,
				),
				array(
					'path'     => '/status',
					'methods'  => 'GET',
					'callback' => array( $this, 'rest_status' ),
				),
				array(
					'path'     => '/history',
					'methods'  => 'GET',
					'callback' => array( $this, 'rest_history' ),
				),
				/*
				 * Hub-powered GTmetrix. Deliberately NOT gated on the
				 * `enabled` setting the way /run and /status are.
				 *
				 * That gate exists because /run makes an outbound call on the
				 * SITE's behalf with the SITE owner's key — it is the promise
				 * in readme.txt that nothing is sent to a third party until
				 * you say so. This path is different: the site talks only to
				 * the Hub it has already been deliberately connected to, and
				 * the Hub owns the GTmetrix account. Requiring the toggle as
				 * well would keep the five-step funnel this feature exists to
				 * remove.
				 */
				array(
					'path'     => '/hub-test',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_hub_test' ),
				),
				array(
					'path'     => '/hub-status',
					'methods'  => 'GET',
					'callback' => array( $this, 'rest_hub_status' ),
				),
				/*
				 * xSpeed Scan. Like the Hub routes above, deliberately NOT
				 * gated on the `enabled` setting: that toggle guards sending
				 * the site's URL to Google or GTmetrix with the SITE owner's
				 * own API key. The scan engine is our own service, needs no
				 * key, and the user starts it by pressing Scan — the consent
				 * is the press, and requiring a settings toggle first would
				 * reinstate exactly the funnel this feature removes.
				 *
				 * What the UI MUST NOT skip is telling the user whether the
				 * resulting report is public; see Scan::private_supported().
				 */
				array(
					'path'     => '/scan',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_scan_start' ),
				),
				array(
					'path'     => '/scan-status',
					'methods'  => 'GET',
					'callback' => array( $this, 'rest_scan_status' ),
				),
			)
		);
	}

	/**
	 * Start an xSpeed Scan.
	 *
	 * Answers as soon as the engine accepts the run — a scan takes 20-60s,
	 * so holding the request open would trip every proxy between here and
	 * the browser. The caller polls /scan-status.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_scan_start( \WP_REST_Request $request ) {
		// One at a time. Without this a double-click spends two runs against
		// the engine's rate limit and leaves two pending markers racing.
		$pending = Scan::pending();
		if ( null !== $pending ) {
			return rest_ensure_response(
				array(
					'status'  => 'running',
					'scan_id' => $pending['scan_id'],
					'step'    => '',
				)
			);
		}

		$started = Scan::start(
			(string) ( $request->get_param( 'url' ) ?? '' ),
			(bool) $request->get_param( 'fresh' )
		);
		if ( is_wp_error( $started ) ) {
			return $started;
		}

		return rest_ensure_response(
			array(
				'status'     => 'running',
				'scan_id'    => $started['scan_id'],
				'report_url' => $started['report_url'],
				'cached'     => $started['cached'],
			)
		);
	}

	/**
	 * Poll the in-flight scan, or report the last completed one.
	 *
	 * Always 200: "nothing has been scanned yet" is an empty state, not an
	 * error, and the dashboard renders it on first paint.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_scan_status() {
		$pending = Scan::pending();

		if ( null !== $pending ) {
			$polled = Scan::poll( (string) $pending['scan_id'] );
			if ( is_wp_error( $polled ) ) {
				return $polled;
			}
			if ( isset( $polled['status'] ) && 'running' === $polled['status'] ) {
				return rest_ensure_response(
					array(
						'status'     => 'running',
						'scan_id'    => $polled['scan_id'],
						'step'       => $polled['step'],
						'latest'     => Scan::latest(),
						'visibility' => Scan::visibility(),
						'private_supported' => Scan::private_supported(),
					)
				);
			}
			return rest_ensure_response(
				array(
					'status'     => 'complete',
					'latest'     => $polled,
					'visibility' => Scan::visibility(),
					'private_supported' => Scan::private_supported(),
				)
			);
		}

		return rest_ensure_response(
			array(
				'status'     => 'idle',
				'latest'     => Scan::latest(),
				'visibility' => Scan::visibility(),
				'private_supported' => Scan::private_supported(),
			)
		);
	}

	/**
	 * Start a Hub-run GTmetrix test.
	 *
	 * Returns the Hub's payload on success. On failure the WP_Error code is
	 * the Hub's own stable code (site_not_verified, gtmetrix_quota_exceeded,
	 * …) so the UI can respond specifically, and the HTTP status is carried
	 * through rather than flattened to 500.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_hub_test() {
		if ( ! self::hub_speed_test_enabled() ) {
			return new \WP_Error(
				'xspeed_hub_speed_test_disabled',
				__( 'Hub-run speed tests are not enabled on this site.', 'xspeed' ),
				array( 'status' => 403 )
			);
		}

		$result = Mcp_Hub::gtmetrix_test();
		return $this->hub_result( $result );
	}

	/**
	 * Is the Hub-run speed test available on this site?
	 *
	 * Off by default: the feature is built and merged, but the hosted runner
	 * behind it is not being announced yet, and a button that offers a test we
	 * are not ready to serve is worse than no button. The panel already has a
	 * complete story without it — PageSpeed Insights runs with no API key, and
	 * a site with its own provider key is unaffected either way.
	 *
	 * `rest_hub_status()` reports `feature_disabled`, which is deliberately NOT
	 * in the UI's CONNECTABLE_REASONS allowlist, so both surfaces (the Overview
	 * card and the panel's primary action) fall back to the PageSpeed flow
	 * rather than offering a Connect prompt that leads nowhere.
	 *
	 * Flip with `add_filter( 'xspeed_hub_speed_test_enabled', '__return_true' );`
	 * — one line, no code change, for when the runner is announced.
	 */
	public static function hub_speed_test_enabled(): bool {
		/**
		 * Whether the Hub-run speed test is offered in the dashboard.
		 *
		 * @param bool $enabled Default false.
		 */
		return (bool) apply_filters( 'xspeed_hub_speed_test_enabled', false );
	}

	/**
	 * Recent Hub-run tests + the remaining monthly allowance.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_hub_status() {
		if ( ! self::hub_speed_test_enabled() ) {
			return rest_ensure_response(
				array(
					'available' => false,
					'reason'    => 'feature_disabled',
					'message'   => __( 'Hub-run speed tests are not enabled on this site.', 'xspeed' ),
					'local'     => false,
				)
			);
		}

		$result = Mcp_Hub::gtmetrix_runs();
		if ( is_wp_error( $result ) ) {
			// A status read must never look like a hard failure — the panel
			// still has a history to draw. Report "unavailable" and let the UI
			// hide the Hub affordance rather than showing an error banner.
			return rest_ensure_response(
				array(
					'available' => false,
					'reason'    => $result->get_error_code(),
					'message'   => $result->get_error_message(),
					'local'     => Mcp_Hub::is_local_site(),
				)
			);
		}

		$result['available'] = true;
		$result['local']     = Mcp_Hub::is_local_site();
		return rest_ensure_response( $result );
	}

	/**
	 * Turn an Mcp_Hub result into a REST response, preserving the status code.
	 *
	 * @param array<string,mixed>|\WP_Error $result Hub call result.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function hub_result( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return rest_ensure_response( $result );
		}

		$data   = $result->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 502;
		// Anything below 400 would be nonsense on an error path.
		if ( $status < 400 ) {
			$status = 502;
		}
		$data            = is_array( $data ) ? $data : array();
		$data['status']  = $status;
		$data['local']   = Mcp_Hub::is_local_site();

		return new \WP_Error( $result->get_error_code(), $result->get_error_message(), $data );
	}

	/**
	 * Start (or, for PSI, complete) an audit.
	 *
	 * POST, never GET: this spends someone else's rate limit and takes up
	 * to a minute. A GET would be prefetched by a browser.
	 */
	public function rest_run( \WP_REST_Request $request ) {
		$opts = Settings_Manager::get( self::SLUG );

		if ( empty( $opts['enabled'] ) ) {
			return new \WP_Error(
				'xspeed_score_disabled',
				__( 'External scores are turned off. Enable them first — this is the only feature that contacts a third party.', 'xspeed' ),
				array( 'status' => 409 )
			);
		}

		$url = $this->resolve_url( (string) $request->get_param( 'url' ), $opts );
		if ( '' === $url ) {
			return new \WP_Error(
				'xspeed_score_no_url',
				__( 'No URL to test.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}

		$provider = (string) ( $request->get_param( 'provider' ) ?: $opts['provider'] );

		if ( 'gtmetrix' === $provider ) {
			$started = Score::start_gtmetrix( $url, (string) $opts['gtmetrix_api_key'] );
			return is_wp_error( $started ) ? $started : rest_ensure_response( $started );
		}

		$strategy = (string) ( $request->get_param( 'strategy' ) ?: $opts['default_strategy'] );
		return rest_ensure_response( Score::run_psi( $url, $strategy, (string) $opts['psi_api_key'] ) );
	}

	/**
	 * Poll an in-flight GTmetrix test.
	 *
	 * GET because it is a read of state we already started — the browser
	 * calls it every few seconds while a test is queued.
	 */
	public function rest_status() {
		$opts    = Settings_Manager::get( self::SLUG );
		$pending = get_option( Score::PENDING_OPTION, array() );

		// Same opt-in gate as rest_run(). Without it, `status` — which is
		// also the CLI's DEFAULT action — polled GTmetrix with the feature
		// switched off and no API key, which falsified readme.txt's promise
		// that nothing is sent while it is off.
		if ( ! $this->may_poll( $opts, $pending ) ) {
			return rest_ensure_response(
				array(
					'pending' => false,
					'state'   => 'idle',
					'latest'  => Score::latest(),
				)
			);
		}

		if ( ! is_array( $pending ) || empty( $pending['test_id'] ) ) {
			return rest_ensure_response(
				array(
					'pending' => false,
					'state'   => 'idle',
					'latest'  => Score::latest(),
				)
			);
		}

		$polled = Score::poll_gtmetrix( (string) $opts['gtmetrix_api_key'] );
		if ( is_wp_error( $polled ) ) {
			return $polled;
		}

		return rest_ensure_response(
			array_merge(
				$polled,
				array( 'latest' => Score::latest() )
			)
		);
	}

	public function rest_history() {
		return rest_ensure_response(
			array(
				'runs'       => Score::history(),
				'latest'     => Score::latest(),
				'thresholds' => Score::thresholds(),
			)
		);
	}

	/**
	 * May we contact GTmetrix to poll the in-flight test?
	 *
	 * Three conditions, all necessary: the feature is on, an API key exists
	 * (there is no anonymous GTmetrix), and the pending marker is real and
	 * not stale. A marker with no expiry turned one failed start into a
	 * permanent poll loop against a third party.
	 *
	 * @param array<string,mixed> $opts    Module settings.
	 * @param mixed               $pending The stored pending marker.
	 */
	private function may_poll( array $opts, $pending ): bool {
		if ( empty( $opts['enabled'] ) || '' === trim( (string) $opts['gtmetrix_api_key'] ) ) {
			return false;
		}
		if ( ! is_array( $pending ) || empty( $pending['test_id'] ) ) {
			return false;
		}
		// A GTmetrix test that hasn't resolved within the window is not going
		// to; drop the marker rather than poll it forever.
		$started = isset( $pending['started'] ) ? (int) $pending['started'] : 0;
		if ( $started > 0 && ( time() - $started ) > Score::PENDING_MAX_AGE ) {
			delete_option( Score::PENDING_OPTION );
			return false;
		}
		return true;
	}

	/**
	 * Fall back to the home page when no URL is configured — testing "my
	 * site" is what almost everyone means.
	 *
	 * @param array<string,mixed> $opts Module settings.
	 */
	private function resolve_url( string $requested, array $opts ): string {
		foreach ( array( $requested, (string) ( $opts['test_url'] ?? '' ) ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}
		return function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed score',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'External performance scores: `run` a PageSpeed Insights / GTmetrix audit (use --target=<url>, not --url, which WP-CLI reserves), `status` for an in-flight GTmetrix test, `history` for past runs.',
				'ai_hint'   => 'Measure real-world performance with an external audit (PageSpeed Insights / GTmetrix), or read past scores. Use to answer "did that change actually help" with a measured before/after instead of an assumption, and to get Core Web Vitals for a specific page. `run` starts an audit (pass --target=<url> for a page other than the home page; --url is reserved by WP-CLI), `status` polls an in-flight GTmetrix test, `history` returns previous runs. An audit takes up to a couple of minutes, so say so before starting one.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'status', 'run', 'history', 'hub-test' ),
						'optional' => true,
					),
					array(
						'type'        => 'assoc',
						// NOT `--url`: that is a WP-CLI *global* parameter, so
						// the value never reaches this handler and the flag is
						// silently ignored.
						'name'        => 'target',
						'description' => 'URL to audit. Defaults to the configured URL, then the home page.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'strategy',
						'description' => 'mobile (default) or desktop. PageSpeed Insights only.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'provider',
						'description' => 'psi (default) or gtmetrix.',
						'optional'    => true,
					),
				),
			),
			/*
			 * A SEPARATE command, not another action on `score`, because the
			 * two produce different numbers. A scan score is the xSpeed
			 * rubric (four weighted dimensions, ~20 checks); a score run is a
			 * raw provider score. Folding them together would invite exactly
			 * the comparison the two scales cannot support.
			 */
			array(
				'name'      => 'xspeed scan',
				'callback'  => array( $this, 'cli_scan_handler' ),
				'shortdesc' => 'xSpeed Scan: `run` a full graded site report, `status` to poll one or read the last, `fixes` for what to do next.',
				'ai_hint'   => 'Run a full xSpeed Scan and read the graded result. This is BROADER than `xspeed score`: it grades four weighted dimensions (speed, delivery, assets, platform) over ~20 checks and returns what to fix ranked by how many points each recovers, which a raw PageSpeed score cannot tell you. The scan score and a Lighthouse score are DIFFERENT SCALES - never present them as the same number or compare one to the other. `run` starts a scan (20-60s; poll with `status`), `fixes` lists the ranked remediations. Reports are published to a public per-host list unless the site is connected to the Hub, so say so before starting one.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'run', 'status', 'fixes' ),
						'optional' => true,
					),
					array(
						'type'        => 'assoc',
						// NOT `--url`, which WP-CLI reserves as a global.
						'name'        => 'target',
						'description' => 'URL to scan. Defaults to the home page.',
						'optional'    => true,
					),
					array(
						'type'        => 'flag',
						'name'        => 'fresh',
						'description' => 'Force a new scan instead of reusing a recent cached report.',
						'optional'    => true,
					),
					array(
						'type'        => 'flag',
						'name'        => 'wait',
						'description' => 'Poll until the scan finishes instead of returning immediately.',
						'optional'    => true,
					),
				),
			),
		);
	}

	/**
	 * `wp xspeed scan [run|status|fixes]`
	 *
	 * @param array<int,string>    $args       Positional.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function cli_scan_handler( array $args, array $assoc_args ): void {
		$action = $args[0] ?? 'status';

		if ( 'fixes' === $action ) {
			$latest = Scan::latest();
			if ( null === $latest || empty( $latest['fixes'] ) ) {
				\WP_CLI::log( 'No scan result yet. Run `wp xspeed scan run --wait` first.' );
				return;
			}
			$rows = array();
			foreach ( $latest['fixes'] as $f ) {
				$rows[] = array(
					'check'       => $f['id'] . ' ' . $f['name'],
					'status'      => $f['status'],
					'recoverable' => $f['recoverable'],
					'evidence'    => $f['evidence'],
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'status', 'recoverable', 'evidence' ) );
			return;
		}

		if ( 'run' === $action ) {
			// Said before the call, not after: on an unconnected site this
			// publishes a report about the user's site to a public list.
			// Name the remedy too -- connecting the Hub is what makes a
			// report unlisted, and a warning without it leaves no move.
			if ( 'private' !== Scan::visibility() || ! Scan::private_supported() ) {
				\WP_CLI::warning(
					'This report will be publicly visible at xspeedcache.com, listed under your domain. '
					. 'Connect xSpeed Hub from the dashboard to keep your reports unlisted.'
				);
			}

			$started = Scan::start(
				(string) ( $assoc_args['target'] ?? '' ),
				! empty( $assoc_args['fresh'] )
			);
			if ( is_wp_error( $started ) ) {
				\WP_CLI::error( $started->get_error_message() );
				return;
			}
			\WP_CLI::log( 'Scan started: ' . $started['scan_id'] );
			\WP_CLI::log( 'Report: ' . $started['report_url'] );

			if ( empty( $assoc_args['wait'] ) ) {
				\WP_CLI::log( 'Poll with `wp xspeed scan status`.' );
				return;
			}

			// A scan is 20-60s; cap the wait so a stuck engine cannot hang
			// a CLI session indefinitely.
			$deadline = time() + 180;
			while ( time() < $deadline ) {
				sleep( 5 );
				$polled = Scan::poll( (string) $started['scan_id'] );
				if ( is_wp_error( $polled ) ) {
					\WP_CLI::error( $polled->get_error_message() );
					return;
				}
				if ( ! isset( $polled['status'] ) || 'running' !== $polled['status'] ) {
					self::cli_print_scan( $polled );
					return;
				}
				\WP_CLI::log( '  ' . ( $polled['step'] ?: 'working' ) . '...' );
			}
			\WP_CLI::warning( 'Still running. Poll with `wp xspeed scan status`.' );
			return;
		}

		// status
		$pending = Scan::pending();
		if ( null !== $pending ) {
			$polled = Scan::poll( (string) $pending['scan_id'] );
			if ( is_wp_error( $polled ) ) {
				\WP_CLI::error( $polled->get_error_message() );
				return;
			}
			if ( isset( $polled['status'] ) && 'running' === $polled['status'] ) {
				\WP_CLI::log( 'Running: ' . ( $polled['step'] ?: 'working' ) );
				return;
			}
			self::cli_print_scan( $polled );
			return;
		}

		$latest = Scan::latest();
		if ( null === $latest ) {
			\WP_CLI::log( 'No scan yet. Run `wp xspeed scan run --wait`.' );
			return;
		}
		self::cli_print_scan( $latest );
	}

	/** Shared rendering for a completed scan. */
	private static function cli_print_scan( array $r ): void {
		\WP_CLI::log(
			sprintf(
				'Score %s/100  grade %s  (%s)%s',
				null === $r['score'] ? '-' : $r['score'],
				$r['grade'] ?: '-',
				$r['level_name'] ?: '-',
				! empty( $r['partial'] ) ? '  [partial scan]' : ''
			)
		);
		foreach ( (array) ( $r['dimensions'] ?? array() ) as $key => $d ) {
			\WP_CLI::log( sprintf( '  %-9s %3s/100  (%s of %s pts)', $key, $d['score'] ?? '-', $d['earned'] ?? '-', $d['weight'] ?? '-' ) );
		}
		$lh  = $r['measured']['lighthouse'] ?? null;
		$lhd = $r['measured']['lighthouse_desktop'] ?? null;
		if ( null !== $lh || null !== $lhd ) {
			// Both strategies: the engine measures both and they diverge
			// widely, so reporting only mobile states the harsher number as
			// though it were the whole picture. Labelled, and never as "the
			// score": different scale.
			\WP_CLI::log(
				sprintf(
					'Lighthouse: mobile %s, desktop %s - a different scale, one check inside the score above.',
					null === $lh ? '-' : $lh . '/100',
					null === $lhd ? '-' : $lhd . '/100'
				)
			);
		}
		\WP_CLI::log( 'Report: ' . ( $r['report_url'] ?? '' ) );
	}

	public function cli_handler( array $args, array $assoc ): void {
		$action = isset( $args[0] ) ? (string) $args[0] : 'status';
		$opts   = Settings_Manager::get( self::SLUG );

		/*
		 * Hub-powered GTmetrix. Handled before the `enabled` gate below: this
		 * path does not use the site's own key or settings at all — it asks
		 * the Hub the site is already connected to, and the Hub owns the
		 * GTmetrix account.
		 */
		if ( 'hub-test' === $action ) {
			if ( ! self::hub_speed_test_enabled() ) {
				\WP_CLI::error( 'Hub-run speed tests are not enabled on this site.' );
				return;
			}
			$result = Mcp_Hub::gtmetrix_test();
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
				return;
			}
			$quota = isset( $result['quota'] ) && is_array( $result['quota'] ) ? $result['quota'] : array();
			\WP_CLI::success(
				sprintf(
					'Test started. %s left this month.',
					isset( $quota['remaining'] ) ? (string) (int) $quota['remaining'] : '?'
				)
			);
			\WP_CLI::log( 'Results appear in the dashboard when the test finishes (about a minute).' );
			return;
		}

		if ( 'history' === $action ) {
			$runs = Score::history();
			if ( empty( $runs ) ) {
				\WP_CLI::log( 'No runs recorded yet.' );
				return;
			}
			foreach ( $runs as $run ) {
				\WP_CLI::log(
					sprintf(
						'%s  %-9s %-8s %s',
						gmdate( 'Y-m-d H:i', (int) $run['ts'] ),
						(string) ( $run['provider'] ?? '' ),
						empty( $run['ok'] ) ? 'FAILED' : ( null === ( $run['score'] ?? null ) ? 'no score' : $run['score'] . '/100' ),
						empty( $run['ok'] ) ? (string) ( $run['error'] ?? '' ) : (string) ( $run['url'] ?? '' )
					)
				);
			}
			return;
		}

		if ( 'run' === $action ) {
			if ( empty( $opts['enabled'] ) ) {
				\WP_CLI::error( 'External scores are turned off. Enable the score module first — this is the only feature that contacts a third party.' );
				return;
			}

			$url      = $this->resolve_url( isset( $assoc['target'] ) ? (string) $assoc['target'] : '', $opts );
			$provider = isset( $assoc['provider'] ) ? (string) $assoc['provider'] : (string) $opts['provider'];

			if ( 'gtmetrix' === $provider ) {
				$started = Score::start_gtmetrix( $url, (string) $opts['gtmetrix_api_key'] );
				if ( is_wp_error( $started ) ) {
					\WP_CLI::error( $started->get_error_message() );
					return;
				}
				\WP_CLI::success( sprintf( 'GTmetrix test queued (id %s). Poll with: wp xspeed score status', (string) ( $started['test_id'] ?? '?' ) ) );
				return;
			}

			$strategy = isset( $assoc['strategy'] ) ? (string) $assoc['strategy'] : (string) $opts['default_strategy'];
			$run      = Score::run_psi( $url, $strategy, (string) $opts['psi_api_key'] );

			if ( empty( $run['ok'] ) ) {
				\WP_CLI::error( (string) $run['error'] );
				return;
			}
			\WP_CLI::success(
				sprintf(
					'%s (%s): %s',
					$url,
					$strategy,
					null === $run['score'] ? 'no score returned' : $run['score'] . '/100'
				)
			);
			$this->print_metrics( is_array( $run['metrics'] ) ? $run['metrics'] : array() );
			return;
		}

		// status
		$pending = get_option( Score::PENDING_OPTION, array() );
		if ( $this->may_poll( $opts, $pending ) ) {
			$polled = Score::poll_gtmetrix( (string) $opts['gtmetrix_api_key'] );
			if ( is_wp_error( $polled ) ) {
				\WP_CLI::error( $polled->get_error_message() );
				return;
			}
			if ( ! empty( $polled['pending'] ) ) {
				\WP_CLI::log( sprintf( 'GTmetrix test %s is %s.', (string) $pending['test_id'], (string) ( $polled['state'] ?? 'running' ) ) );
				return;
			}
		}

		\WP_CLI::log( 'enabled  ' . ( empty( $opts['enabled'] ) ? 'no' : 'yes' ) );
		\WP_CLI::log( 'provider ' . (string) $opts['provider'] );

		$latest = Score::latest();
		if ( null === $latest ) {
			\WP_CLI::log( 'No successful run yet. Run one with: wp xspeed score run' );
			return;
		}
		\WP_CLI::log(
			sprintf(
				'latest   %s — %s (%s)',
				null === $latest['score'] ? 'no score' : $latest['score'] . '/100',
				gmdate( 'Y-m-d H:i', (int) $latest['ts'] ),
				(string) $latest['provider']
			)
		);
		$this->print_metrics( is_array( $latest['metrics'] ) ? $latest['metrics'] : array() );
	}

	/**
	 * @param array<string,mixed> $metrics Metric name → value.
	 */
	private function print_metrics( array $metrics ): void {
		foreach ( $metrics as $name => $value ) {
			if ( null === $value ) {
				continue;
			}
			\WP_CLI::log(
				sprintf(
					'  %-5s %-10s %s',
					strtoupper( (string) $name ),
					'cls' === $name ? (string) round( (float) $value, 3 ) : (int) $value . 'ms',
					Score::rate( (string) $name, (float) $value )
				)
			);
		}
	}
}
