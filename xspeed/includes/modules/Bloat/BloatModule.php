<?php
/**
 * Bloat — disable WordPress features site owners rarely use but every
 * frontend pays for in bytes / requests / attack surface.
 *
 * Each setting is a single toggle that adds (or doesn't add) one or
 * two filters. Per the SETTINGS.md standard, every toggle ships with a
 * label + description that names the actual ergonomic value.
 *
 * Six toggles, all opt-in (default false). The defaults are
 * conservative because every site has at least one plugin that quietly
 * depends on the surface this module strips — better to make the user
 * choose than to break themes on activation.
 *
 * Tier: Free (FEATURES.md "Others" §10-§15 — declared in commit
 * `4e36051` before this implementation).
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Bloat;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;
use XSpeed\Settings_Manager;

final class BloatModule extends Module {

	public const SLUG    = 'bloat';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	public function ui_metadata(): array {
		return array(
			'label'       => 'Bloat Control',
			'icon'        => 'Sliders',
			'description' => 'Turn off WordPress defaults you do not use — saves bytes, requests, and attack surface.',
		);
	}

	public function settings_schema(): array {
		return array(
			'disable_dashicons_frontend' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => 'Disable Dashicons on Frontend',
				'description' => 'Drop the dashicons stylesheet from non-admin pages. Most themes do not need it. Saves ~45 KB per visitor.',
			),
			'disable_oembed' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => 'Disable oEmbed Discovery + wp-embed.min.js',
				'description' => 'Strip the auto-embed handlers + the embed script. Posts that paste a YouTube URL will no longer auto-render the player — embed it via a block instead. Saves a request per page.',
			),
			'disable_rss_feeds' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => 'Disable RSS Feeds',
				'description' => 'Return a 404 on /feed/ and similar endpoints. Useful for sites that do not publish feeds and want to cut feed-fetcher traffic.',
			),
			'disable_xmlrpc' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => 'Disable XML-RPC',
				'description' => 'Disable the legacy xmlrpc.php endpoint. Cuts pingback brute-force noise; safe to disable unless you use a remote WP client (Jetpack, WordPress mobile app).',
			),
			'strip_jquery_migrate' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => 'Strip jQuery Migrate on Frontend',
				'description' => 'Remove the jquery-migrate compatibility shim from non-admin pages. Saves ~10 KB; safe on modern themes / plugins.',
			),
			'restrict_rest_to_authed' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => 'Restrict REST API to Logged-In Users',
				'description' => 'Block /wp-json/ for anonymous requests. WooCommerce checkout, contact-form submissions, and many block-editor previews need anonymous REST — keep this off unless you know your site does not depend on it.',
			),
		);
	}

	public function boot(): void {
		$opts = Settings_Manager::get( self::SLUG );

		if ( ! empty( $opts['disable_dashicons_frontend'] ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_dashicons' ), 100 );
		}

		if ( ! empty( $opts['disable_oembed'] ) ) {
			add_action( 'init', array( __CLASS__, 'disable_oembed' ), 9 );
		}

		if ( ! empty( $opts['disable_rss_feeds'] ) ) {
			add_action( 'do_feed',      array( __CLASS__, 'block_feed' ), 1 );
			add_action( 'do_feed_rdf',  array( __CLASS__, 'block_feed' ), 1 );
			add_action( 'do_feed_rss',  array( __CLASS__, 'block_feed' ), 1 );
			add_action( 'do_feed_rss2', array( __CLASS__, 'block_feed' ), 1 );
			add_action( 'do_feed_atom', array( __CLASS__, 'block_feed' ), 1 );
			add_action( 'do_feed_rss2_comments', array( __CLASS__, 'block_feed' ), 1 );
			add_action( 'do_feed_atom_comments', array( __CLASS__, 'block_feed' ), 1 );
		}

		if ( ! empty( $opts['disable_xmlrpc'] ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'wp_headers', array( __CLASS__, 'strip_xmlrpc_header' ) );
			add_filter( 'pings_open', '__return_false' );
		}

		if ( ! empty( $opts['strip_jquery_migrate'] ) ) {
			add_action( 'wp_default_scripts', array( __CLASS__, 'strip_jquery_migrate' ) );
		}

		if ( ! empty( $opts['restrict_rest_to_authed'] ) ) {
			add_filter( 'rest_authentication_errors', array( __CLASS__, 'restrict_rest' ) );
		}
	}

	public static function dequeue_dashicons(): void {
		if ( is_admin_bar_showing() || is_user_logged_in() ) {
			return; // the admin bar uses dashicons; only strip on truly anonymous pages.
		}
		wp_dequeue_style( 'dashicons' );
		wp_deregister_style( 'dashicons' );
	}

	public static function disable_oembed(): void {
		// Strip discovery <link> from <head>.
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		// Drop the auto-embed filter (paste-a-URL-becomes-embed).
		remove_filter( 'the_content', array( $GLOBALS['wp_embed'] ?? null, 'autoembed' ), 8 );
		// Drop wp-embed.min.js + the rewrite rule.
		add_action(
			'wp_footer',
			static function () {
				wp_dequeue_script( 'wp-embed' );
			},
			1
		);
		add_filter(
			'rewrite_rules_array',
			static function ( $rules ) {
				if ( ! is_array( $rules ) ) {
					return $rules;
				}
				foreach ( $rules as $rule => $rewrite ) {
					if ( false !== strpos( (string) $rewrite, 'embed=true' ) ) {
						unset( $rules[ $rule ] );
					}
				}
				return $rules;
			}
		);
	}

	public static function block_feed(): void {
		wp_die(
			esc_html__( 'Feeds are disabled.', 'xspeed' ),
			'',
			array( 'response' => 404 )
		);
	}

	/**
	 * @param array $headers
	 * @return array
	 */
	public static function strip_xmlrpc_header( $headers ) {
		if ( is_array( $headers ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	/**
	 * @param \WP_Scripts $scripts
	 */
	public static function strip_jquery_migrate( $scripts ): void {
		// Builders and their add-ons still rely on jQuery Migrate shims; a
		// builder editing screen is a front-end URL, so is_admin() misses it
		// and the editor loses methods it calls. (#281)
		if ( is_admin() || \XSpeed\Builder_Editor::is_active() || ! isset( $scripts->registered['jquery'] ) ) {
			return;
		}
		$jquery = $scripts->registered['jquery'];
		if ( is_array( $jquery->deps ?? null ) ) {
			$jquery->deps = array_values( array_diff( $jquery->deps, array( 'jquery-migrate' ) ) );
		}
	}

	/**
	 * Block anonymous /wp-json/ access. Logged-in users + already-errored
	 * requests pass through untouched.
	 *
	 * @param \WP_Error|null|true $result
	 * @return \WP_Error|null|true
	 */
	public static function restrict_rest( $result ) {
		if ( ! empty( $result ) ) {
			return $result; // upstream auth already decided.
		}
		if ( is_user_logged_in() ) {
			return $result;
		}
		return new \WP_Error(
			'rest_forbidden_anonymous',
			__( 'Anonymous REST access is disabled on this site.', 'xspeed' ),
			array( 'status' => 401 )
		);
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed bloat',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Show which bloat-removal toggles are active.',
				'ai_hint'   => 'What unnecessary WordPress output is being stripped (emojis, embeds, jQuery Migrate, dashicons)? Use when asked why extra scripts still load on the frontend, or before recommending bloat removal.',
				'synopsis'  => array(),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$opts = Settings_Manager::get( self::SLUG );
		foreach ( $opts as $key => $value ) {
			\WP_CLI::log( sprintf( '%-30s %s', $key, $value ? 'on' : 'off' ) );
		}
	}
}
