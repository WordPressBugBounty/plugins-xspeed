<?php
/**
 * Third-party assets module. Copies CSS and JavaScript from allow-listed
 * hosts onto the site and loads them from there.
 *
 * The engine is Third_Party_Assets (includes/class-third-party-assets.php).
 * This class owns the settings, the page-HTML hook, the two cron callbacks and
 * the CLI command.
 *
 * It runs on the cache-write path through xspeed_cache_final_html, so a rewritten
 * URL is baked into the cached page and replayed on every HIT. With the page
 * cache off it buffers the page itself, as the Resource Hints and CDN modules do.
 *
 * Off by default. Turning it on makes the site fetch the files on the allow-list
 * from their hosts, which the readme lists under External services.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\ThirdPartyAssets;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;
use XSpeed\Third_Party_Assets;

final class ThirdPartyAssetsModule extends Module {

	public const SLUG    = 'third-party-assets';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	/** Guards the cache-off buffer so it processes exactly once per request. */
	private static $buffering = false;

	public function ui_metadata(): array {
		return array(
			'label'       => __( 'Third-party files', 'xspeed' ),
			'tab_label'   => __( 'Third-party files', 'xspeed' ),
			'icon'        => 'Replace',
			'description' => __( 'Copies CSS and JavaScript from other hosts onto your site.', 'xspeed' ),
			'group'       => 'performance',
		);
	}

	public function settings_schema(): array {
		return array(
			'enabled'      => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Copy third-party files to this site', 'xspeed' ),
				'description' => __( 'Fetches the CSS and JavaScript files listed below from their hosts, keeps a copy on this site and loads the copy. Visitors stop connecting to those hosts, and the copies get this site\'s long cache lifetime. A file is copied a minute or so after a page that uses it is first built, so the first visit still loads the original. The host list starts with common library hosts, so copying starts as soon as this is on.', 'xspeed' ),
			),
			'hosts'        => array(
				'type'        => 'list',
				'default'     => Third_Party_Assets::DEFAULT_HOSTS,
				'item_type'   => 'string',
				'label'       => __( 'Hosts to copy from', 'xspeed' ),
				'description' => __( 'One host per line, such as cdn.example.com. Only files from these hosts are copied. The list starts with common library hosts such as cdnjs and jsDelivr. Remove any you don\'t want copied. An empty list copies nothing.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'mirror_css'   => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => __( 'Copy stylesheets', 'xspeed' ),
				'description' => __( 'Stylesheets block the first paint, so copying them gives the largest gain. Font and image paths inside a copied stylesheet keep pointing at the original host. Turning this off also stops queued and scheduled downloads of stylesheets.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'mirror_js'    => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => __( 'Copy scripts', 'xspeed' ),
				'description' => __( 'Copies plain scripts. A script that finds other files next to itself, such as a code-split bundle, is left on its host, because those files are never in the page and cannot be copied.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'refresh_days' => array(
				'type'        => 'int',
				'default'     => 7,
				'min'         => 1,
				'max'         => 90,
				'unit'        => 'days',
				'label'       => __( 'Check for new versions every (days)', 'xspeed' ),
				'description' => __( 'How often each copy is compared with the original. A copy that failed to refresh keeps serving until the next check succeeds.', 'xspeed' ),
				'advanced'    => true,
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'max_file_kb'  => array(
				'type'        => 'int',
				'default'     => 1024,
				'min'         => 64,
				'max'         => 4096,
				'label'       => __( 'Largest file to copy (KB)', 'xspeed' ),
				'description' => __( 'A file larger than this is left on its host.', 'xspeed' ),
				'advanced'    => true,
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
		);
	}

	public function boot(): void {
		// The cron callbacks must exist on every request, including cron
		// itself. They read settings only when they run, after init.
		add_action( Third_Party_Assets::FETCH_HOOK, array( $this, 'run_fetch' ) );
		add_action( Third_Party_Assets::REFRESH_HOOK, array( $this, 'run_refresh' ) );
		// Deferred to init, so reading settings does not translate labels
		// before the text domain can load.
		add_action( 'init', array( $this, 'boot_on_init' ) );
	}

	public function deactivate(): void {
		Third_Party_Assets::unschedule();
	}

	public function boot_on_init(): void {
		if ( is_admin()
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| \XSpeed\Builder_Editor::is_active()
		) {
			return;
		}
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) ) {
			return;
		}
		Third_Party_Assets::ensure_scheduled();

		// Before Combine CSS (5) and the delay and async passes (19 to 26), so
		// each of them sees the local URL.
		add_filter(
			'xspeed_cache_final_html',
			function ( $html ) {
				return $this->process( (string) $html );
			},
			3,
			1
		);

		// The cache engine opens its buffer at template_redirect priority 0 and
		// hands the page to the filter above. A request it did not buffer (an
		// excluded URL, a logged-in visitor, or the cache off) never reaches that
		// filter, so this module opens its own buffer for it.
		add_action(
			'template_redirect',
			function () {
				if ( self::$buffering || \XSpeed\Cache::is_buffering() ) {
					return;
				}
				self::$buffering = true;
				ob_start(
					function ( $buffer ) {
						if ( strlen( (string) $buffer ) < 255 ) {
							return $buffer;
						}
						return $this->process( (string) $buffer );
					}
				);
			},
			9
		);
	}

	/** Rewrite one page. */
	private function process( string $html ): string {
		$opts = $this->get_settings();
		$max  = (int) $opts['max_file_kb'] * 1024;
		return Third_Party_Assets::rewrite_html(
			$html,
			Third_Party_Assets::normalize_hosts( (array) ( $opts['hosts'] ?? array() ) ),
			! empty( $opts['mirror_css'] ),
			! empty( $opts['mirror_js'] ),
			function ( array $candidate, string $integrity ) use ( $max ): ?array {
				return Third_Party_Assets::local_url_or_queue( $candidate['url'], $integrity, $max );
			}
		);
	}

	/** Cron: fetch queued files. */
	public function run_fetch(): void {
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) ) {
			return;
		}
		Third_Party_Assets::run_pending(
			Third_Party_Assets::normalize_hosts( (array) ( $opts['hosts'] ?? array() ) ),
			(int) $opts['max_file_kb'] * 1024,
			! empty( $opts['mirror_css'] ),
			! empty( $opts['mirror_js'] )
		);
	}

	/**
	 * Cron, daily: refresh old copies and retry failed ones. One run re-checks a
	 * few copies. When more are due it books a follow-up run, so a slow host can
	 * not hold one cron worker for the whole list.
	 *
	 * @param string $followup Set by the follow-up event; unused.
	 */
	public function run_refresh( $followup = '' ): void {
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) ) {
			return;
		}
		$hosts = Third_Party_Assets::normalize_hosts( (array) ( $opts['hosts'] ?? array() ) );
		$max   = (int) $opts['max_file_kb'] * 1024;
		$css   = ! empty( $opts['mirror_css'] );
		$js    = ! empty( $opts['mirror_js'] );
		$round = Third_Party_Assets::refresh_due( $hosts, $max, (int) $opts['refresh_days'], $css, $js );
		Third_Party_Assets::run_pending( $hosts, $max, $css, $js );
		if ( $round['more'] ) {
			Third_Party_Assets::schedule_refresh_followup();
		}
	}

	/** Failed copies, shown above the form. */
	public function ui_notices(): array {
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) ) {
			return array();
		}
		$failed = array();
		foreach ( Third_Party_Assets::index() as $rec ) {
			if ( ! empty( $rec['error'] ) && 'skipped' !== ( $rec['status'] ?? '' ) ) {
				$failed[] = (string) ( $rec['url'] ?? '' ) . ' (' . (string) $rec['error'] . ')';
			}
		}
		if ( array() === $failed ) {
			return array();
		}
		return array(
			array(
				'tone'  => 'warn',
				'title' => sprintf(
					/* translators: %d: number of third-party files that could not be copied or refreshed. */
					_n( '%d third-party file could not be copied or refreshed', '%d third-party files could not be copied or refreshed', count( $failed ), 'xspeed' ),
					count( $failed )
				),
				'body'  => __( 'Pages keep loading these files from their original host, or from the last good copy. Run "wp xspeed third-party-assets status" for the reason on each.', 'xspeed' ) . ' ' . implode( '; ', array_slice( $failed, 0, 5 ) ),
			),
		);
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed third-party-assets',
				'risk'      => array(
					'status'  => 'read',
					'refresh' => 'write',
					'clear'   => 'write',
				),
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Show, refresh or clear the copied third-party CSS and JavaScript.',
				'ai_hint'   => 'Copies of CSS and JavaScript from other hosts (such as cdnjs) kept on this site. Use for PageSpeed findings about third-party files, render-blocking files on another host, or short cache lifetimes on files this site does not serve.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'status', 'refresh', 'clear' ),
						'optional' => false,
					),
				),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$action = isset( $args[0] ) ? (string) $args[0] : 'status';
		$opts   = $this->get_settings();
		$hosts  = Third_Party_Assets::normalize_hosts( (array) ( $opts['hosts'] ?? array() ) );
		$max    = (int) $opts['max_file_kb'] * 1024;

		if ( 'clear' === $action ) {
			\WP_CLI::success( sprintf( 'Cleared %d copied file(s) and the page cache.', Third_Party_Assets::clear() ) );
			return;
		}
		if ( 'refresh' === $action ) {
			if ( empty( $opts['enabled'] ) ) {
				\WP_CLI::error( 'Third-party files is off. Turn it on first.' );
			}
			// Make every copy due, and retry what failed or was skipped, then
			// run both queues to the end. A command line has no time limit, so
			// it does not stop at the cron batch size.
			$index = Third_Party_Assets::index();
			foreach ( $index as $key => $rec ) {
				$index[ $key ]['checked_at'] = 0;
				if ( in_array( $rec['status'] ?? '', array( 'failed', 'skipped' ), true ) ) {
					$index[ $key ]['status']   = 'pending';
					$index[ $key ]['next_try'] = 0;
				}
			}
			update_option( Third_Party_Assets::INDEX_OPTION, $index, false );
			$css   = ! empty( $opts['mirror_css'] );
			$js    = ! empty( $opts['mirror_js'] );
			$new   = Third_Party_Assets::run_pending( $hosts, $max, $css, $js, Third_Party_Assets::MAX_ENTRIES );
			$round = Third_Party_Assets::refresh_due( $hosts, $max, 1, $css, $js, Third_Party_Assets::MAX_ENTRIES );
			\WP_CLI::success( sprintf( 'Copied %d new file(s). %d existing file(s) changed.', $new, $round['changed'] ) );
			return;
		}

		\WP_CLI::log( sprintf( '%-14s %s', 'enabled', ! empty( $opts['enabled'] ) ? 'on' : 'off' ) );
		\WP_CLI::log( sprintf( '%-14s %s', 'hosts', $hosts ? implode( ', ', $hosts ) : '(none, nothing is copied)' ) );
		$index = Third_Party_Assets::index();
		if ( array() === $index ) {
			\WP_CLI::log( 'No files seen yet.' );
			return;
		}
		foreach ( $index as $rec ) {
			$status = (string) ( $rec['status'] ?? '?' );
			$line   = sprintf( '%-8s %s', $status, (string) ( $rec['url'] ?? '' ) );
			if ( 'ok' === $status && ! empty( $rec['file'] ) ) {
				$line .= sprintf(
					"\n         copy: %s  %d KB  checked %s ago",
					Third_Party_Assets::base_url() . '/' . rawurlencode( (string) $rec['file'] ),
					(int) round( (int) ( $rec['size'] ?? 0 ) / 1024 ),
					human_time_diff( (int) ( $rec['checked_at'] ?? 0 ) )
				);
			}
			if ( ! empty( $rec['error'] ) ) {
				$line .= sprintf( '  last error: %s', (string) $rec['error'] );
			}
			\WP_CLI::log( $line );
		}
	}
}
