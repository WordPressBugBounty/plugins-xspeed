<?php
/**
 * Cache Preloader module.
 *
 * Owns the preloader's settings + the dashboard custom panel that pairs
 * the schema controls with a Start/Stop/Status surface.
 *
 * Tier: Free (LiteSpeed parity — their crawler is free).
 * Roadmap: §3 P3.1.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Preloader;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;
use XSpeed\Preloader;
use XSpeed\Settings_Manager;

final class PreloaderModule extends Module {

	public const SLUG    = 'preloader';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	public function ui_metadata(): array {
		return array(
			'label'        => 'Preloader',
			'tab_label'    => 'Crawl Now', // its own tab on the Preloader page
			'icon'         => 'Wand2',
			'description'  => 'Crawl the sitemap to warm cache so visitors never hit a cold MISS.',
			// Custom panel wraps the schema-driven settings with a
			// Start/Stop control surface + a live status readout
			// (queue depth, last URL, recent errors).
			'custom_panel' => 'PreloaderHost',
		);
	}

	public function settings_schema(): array {
		return array(
			'enabled'     => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => 'Enable Preloader',
				'description' => 'When on, xSpeed crawls the sitemap on the schedule below and warms the page cache.',
			),
			'schedule'    => array(
				'type'          => 'enum',
				'default'       => 'manual',
				'options'       => array( 'manual', 'hourly', 'daily', 'weekly' ),
				'option_labels' => array(
					'manual' => 'Manual',
					'hourly' => 'Hourly',
					'daily'  => 'Daily',
					'weekly' => 'Weekly',
				),
				'label'         => 'Schedule',
				'description'   => 'How often to start a fresh crawl. Manual means you trigger it from the dashboard.',
				'dependsOn'     => array( 'field' => 'enabled' ),
			),
			'batch_size'  => array(
				'type'        => 'int',
				'default'     => 5,
				'min'         => 1,
				'max'         => 50,
				'label'       => 'Batch Size',
				'description' => 'URLs warmed per cron tick. Higher = faster crawl, more load on the origin.',
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'sitemap_url' => array(
				'type'        => 'string',
				'default'     => '',
				'label'       => 'Sitemap URL (optional)',
				'description' => 'Override the auto-detected WordPress core sitemap (/wp-sitemap.xml). Leave blank for default.',
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'warm_on_publish' => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => 'Warm new content immediately',
				'description' => 'When a post or page is published, fetch it once so the first visitor sees a cache HIT, not a cold MISS.',
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'warm_on_comment' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => 'Re-warm after comments',
				'description' => 'Re-warm a page after a comment is posted (Cache purges the page on comment; this fetches it back into cache).',
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
		);
	}

	public function rest_routes(): array {
		// Module base gives us GET + POST for settings under
		// /xspeed/v1/preloader/. We add Start/Stop/Status alongside.
		$default = parent::rest_routes();
		return array_merge(
			$default,
			array(
				array(
					'path'     => '/start',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_start' ),
				),
				array(
					'path'     => '/stop',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_stop' ),
				),
				array(
					'path'     => '/status',
					'methods'  => 'GET',
					'callback' => array( $this, 'rest_status' ),
				),
			)
		);
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed preloader',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Drive the cache preloader (start | stop | status).',
				'ai_hint'   => 'Cache warming: crawl the site so visitors hit a warm cache instead of paying for the first render. Use after a full purge, or when the first visitor to each page reports a slow load.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'start', 'stop', 'status' ),
						'optional' => false,
					),
				),
			),
		);
	}

	public function boot(): void {
		add_action( Preloader::CRON_HOOK, array( Preloader::class, 'tick' ) );
		add_action( 'xspeed_preloader_recurring', array( Preloader::class, 'recurring_kickoff' ) );

		// Apply schedule changes immediately whenever this module's
		// settings get written (the standard per-module option hook).
		add_action( 'update_option_xspeed_module_preloader', array( $this, 'on_settings_change' ), 10, 2 );
		add_action( 'add_option_xspeed_module_preloader', array( $this, 'on_settings_added' ), 10, 2 );

		// Content warmer — auto-warm a single URL on post publish /
		// comment so the first visitor after a publish/comment sees a
		// HIT, not the cold MISS that Cache::purge_all just created.
		$opts = Settings_Manager::get( self::SLUG );
		if ( ! empty( $opts['warm_on_publish'] ) ) {
			add_action( 'transition_post_status', array( $this, 'on_post_transition' ), 10, 3 );
		}
		if ( ! empty( $opts['warm_on_comment'] ) ) {
			add_action( 'comment_post', array( $this, 'on_comment_post' ), 20, 2 );
		}
	}

	/**
	 * Hook: a post transitioned to publish. Warm its permalink once on
	 * shutdown so the post-save request itself stays fast.
	 *
	 * @param string   $new  New post status.
	 * @param string   $old  Old post status.
	 * @param \WP_Post $post Post object.
	 */
	public function on_post_transition( $new, $old, $post ): void {
		if ( 'publish' !== $new || 'publish' === $old ) {
			return;
		}
		// Only warm public post types so we don't crawl private CPTs.
		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || empty( $post_type_obj->public ) ) {
			return;
		}
		$url = get_permalink( $post );
		if ( ! $url ) {
			return;
		}
		// Defer to shutdown so the user's "Publish" click returns fast.
		// (Cache::purge_all has already fired by then on the save_post
		// hook, so the warm fetch lands AFTER the purge.)
		add_action(
			'shutdown',
			static function () use ( $url ) {
				Preloader::warm_one( $url, 'post published' );
			},
			20
		);
	}

	/**
	 * Hook: comment posted. Warm the post's permalink so the page is
	 * back in cache before the next visitor lands.
	 *
	 * @param int $comment_id The comment ID.
	 * @param int $approved   1, 0, or 'spam'.
	 */
	public function on_comment_post( $comment_id, $approved ): void {
		// Approved comments only — pending/spam don't show on the
		// public page and shouldn't trigger a warm.
		if ( 1 !== (int) $approved ) {
			return;
		}
		$comment = get_comment( $comment_id );
		if ( ! $comment || ! $comment->comment_post_ID ) {
			return;
		}
		$url = get_permalink( (int) $comment->comment_post_ID );
		if ( ! $url ) {
			return;
		}
		add_action(
			'shutdown',
			static function () use ( $url ) {
				Preloader::warm_one( $url, 'comment posted' );
			},
			20
		);
	}

	public function deactivate(): void {
		wp_clear_scheduled_hook( Preloader::CRON_HOOK );
		wp_clear_scheduled_hook( 'xspeed_preloader_recurring' );
	}

	public function on_settings_change( $old, $new ): void {
		$enabled  = is_array( $new ) && ! empty( $new['enabled'] );
		$schedule = is_array( $new ) ? (string) ( $new['schedule'] ?? 'manual' ) : 'manual';
		Preloader::apply_schedule( $enabled ? $schedule : 'manual' );
	}

	public function on_settings_added( $name, $value ): void {
		$enabled  = is_array( $value ) && ! empty( $value['enabled'] );
		$schedule = is_array( $value ) ? (string) ( $value['schedule'] ?? 'manual' ) : 'manual';
		Preloader::apply_schedule( $enabled ? $schedule : 'manual' );
	}

	public function rest_start( \WP_REST_Request $request ) {
		$opts = Settings_Manager::get( self::SLUG );
		if ( empty( $opts['enabled'] ) ) {
			return new \WP_Error(
				'xspeed_preloader_disabled',
				__( 'Enable the preloader before starting a crawl.', 'xspeed' ),
				array( 'status' => 409 )
			);
		}
		return rest_ensure_response( Preloader::start() );
	}

	public function rest_stop( \WP_REST_Request $request ) {
		return rest_ensure_response( Preloader::stop() );
	}

	public function rest_status( \WP_REST_Request $request ) {
		return rest_ensure_response( Preloader::status() );
	}

	public function cli_handler( array $args, array $assoc ): void {
		$action = $args[0] ?? 'status';

		switch ( $action ) {
			case 'start':
				$opts = Settings_Manager::get( self::SLUG );
				if ( empty( $opts['enabled'] ) ) {
					\WP_CLI::error( 'Preloader is disabled. Enable it via wp xspeed preloader set --enabled=1 first.' );
					return;
				}
				$state = Preloader::start();
				// Don't print a green Success over a crawl that queued
				// nothing — that exit-0 was the whole complaint in #142.
				$sitemap_error = (string) ( $state['sitemap_error'] ?? '' );
				if ( 0 === (int) $state['total'] ) {
					\WP_CLI::error(
						'' !== $sitemap_error
							? sprintf( 'Queued 0 URLs. %s', $sitemap_error )
							: 'Queued 0 URLs — nothing to warm. Check the sitemap URL and the cache exclusion rules.'
					);
					return;
				}
				if ( 'fallback' === ( $state['source'] ?? '' ) ) {
					\WP_CLI::warning( $sitemap_error );
					\WP_CLI::success(
						sprintf(
							'Queued %d URL%s from the site content instead of the sitemap.',
							$state['total'],
							1 === $state['total'] ? '' : 's'
						)
					);
					return;
				}
				\WP_CLI::success( sprintf( 'Queued %d URL%s.', $state['total'], 1 === $state['total'] ? '' : 's' ) );
				return;

			case 'stop':
				Preloader::stop();
				\WP_CLI::success( 'Preloader stopped.' );
				return;

			case 'status':
				$state = Preloader::status();
				\WP_CLI::log( 'Running   : ' . ( $state['running'] ? 'yes' : 'no' ) );
				\WP_CLI::log( 'Processed : ' . $state['processed'] . ' / ' . $state['total'] );
				if ( $state['last_url'] ) {
					\WP_CLI::log( 'Last URL  : ' . $state['last_url'] );
				}
				if ( ! empty( $state['errors'] ) ) {
					\WP_CLI::log( 'Errors    : ' . count( $state['errors'] ) );
					foreach ( array_slice( $state['errors'], -5 ) as $e ) {
						// Tolerate a bare string as well as the {url, error}
						// shape. A string entry fataled this command outright
						// ("Cannot access offset of type string on string"),
						// which also took MCP's get_preloader_status down with
						// it — an agent asking why a preload failed got a type
						// error instead of the reason. The writer is fixed, but
						// `status` is a diagnostic: it should survive whatever
						// it is handed rather than die reporting on it. (QA F1)
						if ( is_array( $e ) ) {
							$url  = isset( $e['url'] ) ? (string) $e['url'] : '';
							$msg  = isset( $e['error'] ) ? (string) $e['error'] : '';
							// The sitemap message already names the URL, so
							// prefixing it would print the URL twice on one line.
							$line = ( '' !== $url && false === strpos( $msg, $url ) )
								? $url . ' — ' . $msg
								: $msg;
						} else {
							$line = (string) $e;
						}
						\WP_CLI::log( '  ' . $line );
					}
				}
				return;

			default:
				\WP_CLI::error( "Unknown action: $action" );
		}
	}
}
