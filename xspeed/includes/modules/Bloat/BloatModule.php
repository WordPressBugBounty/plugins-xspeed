<?php
/**
 * Bloat — disable WordPress features site owners rarely use but every
 * frontend pays for in bytes / requests / attack surface.
 *
 * Each setting is a single toggle that adds (or doesn't add) one or
 * two filters. Per the SETTINGS.md standard, every toggle ships with a
 * label + description that names the actual ergonomic value.
 *
 * Every toggle is opt-in (default false). The defaults are
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
			'label'       => __( 'Bloat Control', 'xspeed' ),
			'icon'        => 'Sliders',
			'description' => __( 'Turns off WordPress features you do not use, so pages load less.', 'xspeed' ),
			'group'       => 'performance',
		);
	}

	public function settings_schema(): array {
		return array(
			'disable_emojis' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Disable emojis', 'xspeed' ),
				'description' => __( 'Remove the emoji detection script and its inline styles from every page. Modern browsers draw emojis natively, so visitors still see them. Saves a script and an inline stylesheet per page.', 'xspeed' ),
			),
			'disable_dashicons_frontend' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Remove Dashicons for visitors', 'xspeed' ),
				'description' => __( 'Removes the WordPress admin icon font for logged-out visitors. Most themes do not use it, and it saves about 45 KB.', 'xspeed' ),
			),
			'disable_oembed' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Disable auto-embeds', 'xspeed' ),
				'description' => __( 'Removes the embed script, saving one request per page. A pasted YouTube link no longer turns into a player, so use an embed block.', 'xspeed' ),
			),
			'disable_rss_feeds' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Disable RSS feeds', 'xspeed' ),
				'description' => __( 'Feed addresses such as /feed/ return "not found". Use this if your site has no feed readers.', 'xspeed' ),
			),
			'disable_xmlrpc' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Disable XML-RPC', 'xspeed' ),
				'description' => __( 'Turns off the old xmlrpc.php file that attackers often target. Leave this off if you use Jetpack or the WordPress mobile app.', 'xspeed' ),
			),
			'strip_jquery_migrate' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Remove jQuery Migrate', 'xspeed' ),
				'description' => __( 'Removes a script that old themes and plugins need, from pages visitors see. Saves about 10 KB and is safe on current themes.', 'xspeed' ),
			),
			'strip_editor_styles' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Remove editor styles for visitors', 'xspeed' ),
				'description' => __( 'Removes block editor CSS that some plugins load on public pages by mistake, which can add hundreds of KB. Block styles for visitors stay.', 'xspeed' ),
			),
			'remove_rsd_link' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Remove RSD link', 'xspeed' ),
				'description' => __( 'Drop the Really Simple Discovery link from the page head. Only old desktop blogging clients read it.', 'xspeed' ),
			),
			'remove_shortlink' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Remove shortlink', 'xspeed' ),
				'description' => __( 'Drop the ?p=123 shortlink tag and header from posts and pages. The shortlinks keep working; they are just no longer advertised.', 'xspeed' ),
			),
			'remove_rest_api_links' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Remove REST API links', 'xspeed' ),
				'description' => __( 'Drop the /wp-json/ discovery link tag and Link header. The REST API itself stays on; to block it, use the setting below.', 'xspeed' ),
			),
			'hide_wp_version' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Hide WordPress version', 'xspeed' ),
				'description' => __( 'Remove the WordPress generator tag from pages and feeds, so it no longer states the WordPress version. Other plugins print their own tags; those stay. Script and style URLs still carry ?ver= numbers.', 'xspeed' ),
			),
			'disable_self_pingbacks' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Disable self-pingbacks', 'xspeed' ),
				'description' => __( 'Stop WordPress from sending a pingback to your own site when a post links to another of your posts. Pingbacks to other sites are not affected.', 'xspeed' ),
			),
			'restrict_rest_to_authed' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'REST API for logged-in users only', 'xspeed' ),
				'description' => __( 'Blocks /wp-json/ for logged-out visitors. This breaks WooCommerce checkout and many contact forms, so keep it off unless you are sure.', 'xspeed' ),
				'advanced'    => true,
			),
		);
	}

	public function boot(): void {
		/*
		 * Deferred to `init` priority 0. This reads the module's settings,
		 * which builds settings_schema(), whose labels go through __(), and
		 * boot() runs on `plugins_loaded` — before `after_setup_theme`, the
		 * earliest point WordPress 6.7+ treats as safe to translate.
		 *
		 * Priority 0 (not the default 10) because the body itself registers
		 * an `init` callback at priority 9: adding a hook to the action that
		 * is currently running only takes effect if the new priority is still
		 * ahead of the running position, so we have to be first. Every other
		 * hook it registers fires later than `init`.
		 */
		add_action( 'init', array( $this, 'boot_on_init' ), 0 );
	}

	/**
	 * The real boot body — see boot() for why it runs on `init`.
	 */
	public function boot_on_init(): void {
		$opts = Settings_Manager::get( self::SLUG );

		if ( ! empty( $opts['disable_emojis'] ) ) {
			self::disable_emojis();
		}

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
			/*
			 * `wp_default_scripts` fires once, when something first builds the
			 * script registry. A plugin that registers a script while it loads
			 * (Elementor Pro's Forms module registers its reCAPTCHA script) does
			 * that before this runs on `init`, so the hook above never fires and
			 * Migrate stays. Strip it from the registry that already exists,
			 * before the page enqueues anything. (#587)
			 */
			if ( did_action( 'wp_default_scripts' ) ) {
				add_action( 'wp_enqueue_scripts', array( __CLASS__, 'strip_jquery_migrate_now' ), 0 );
			}
		}

		if ( ! empty( $opts['strip_editor_styles'] ) ) {
			// Late, so anything enqueued at normal priority is already queued.
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_editor_styles' ), PHP_INT_MAX );
		}

		if ( ! empty( $opts['remove_rsd_link'] ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}

		if ( ! empty( $opts['remove_shortlink'] ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}

		if ( ! empty( $opts['remove_rest_api_links'] ) ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head' );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
			remove_action( 'xmlrpc_rsd_apis', 'rest_output_rsd' );
		}

		if ( ! empty( $opts['hide_wp_version'] ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}

		if ( ! empty( $opts['disable_self_pingbacks'] ) ) {
			add_action( 'pre_ping', array( __CLASS__, 'strip_self_pings' ) );
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

	/**
	 * The front-end hooks live in default-filters.php, which loads before
	 * `init`, so removing them here works. The admin ones are added by
	 * wp-admin/includes/admin-filters.php, which loads after `init`; they
	 * are removed on `admin_init` instead. wp_enqueue_emoji_styles is the
	 * WP 6.4+ path; print_emoji_styles is kept for older cores.
	 */
	public static function disable_emojis(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'embed_head', 'print_emoji_detection_script' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'enqueue_embed_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'tiny_mce_plugins', array( __CLASS__, 'strip_tinymce_emoji' ) );
		add_action( 'admin_init', array( __CLASS__, 'disable_admin_emojis' ) );
	}

	public static function disable_admin_emojis(): void {
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
	}

	/**
	 * @param mixed $plugins
	 * @return mixed
	 */
	public static function strip_tinymce_emoji( $plugins ) {
		return is_array( $plugins ) ? array_values( array_diff( $plugins, array( 'wpemoji' ) ) ) : $plugins;
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

	/**
	 * Editor-only style handles that have no business on an anonymous
	 * frontend page. Deliberately NOT wp-block-library /
	 * wp-block-library-theme / global-styles — those style the blocks
	 * visitors actually see. Observed live: a plugin pulled wp-editor +
	 * wp-components (and their deps) onto a marketing homepage, several
	 * hundred KB of render-blocking CSS nothing on the page used.
	 */
	private const EDITOR_STYLE_HANDLES = array(
		'wp-editor',
		'wp-block-editor',
		'wp-block-directory',
		'wp-components',
		'wp-preferences',
		'wp-media-utils',
		'wp-reusable-blocks',
		'wp-patterns',
		'wp-edit-blocks',
		'wp-edit-post',
		'wp-edit-site',
		'wp-edit-widgets',
		'wp-format-library',
		'wp-list-reusable-blocks',
		'wp-nux',
	);

	public static function dequeue_editor_styles(): void {
		// Logged-in views legitimately reach editor surfaces (front-end
		// editing, admin bar flows), and a builder editing screen is a
		// front-end URL — same guard set as the other frontend strips.
		if ( is_user_logged_in() || is_admin() || \XSpeed\Builder_Editor::is_active() ) {
			return;
		}
		$styles = wp_styles();
		foreach ( self::EDITOR_STYLE_HANDLES as $handle ) {
			wp_dequeue_style( $handle );
		}
		// Dequeue alone is not enough: dependencies are resolved again at
		// print time, so any queued sheet that lists one of these as a dep
		// pulls it straight back. Strip the handles from every registered
		// sheet's deps too — same technique strip_jquery_migrate() uses.
		foreach ( $styles->registered as $dependency ) {
			if ( is_array( $dependency->deps ?? null ) && array_intersect( $dependency->deps, self::EDITOR_STYLE_HANDLES ) ) {
				$dependency->deps = array_values( array_diff( $dependency->deps, self::EDITOR_STYLE_HANDLES ) );
			}
		}
	}

	/**
	 * `pre_ping` passes the link list by reference.
	 *
	 * @param array $links
	 */
	public static function strip_self_pings( &$links ): void {
		if ( ! is_array( $links ) ) {
			return;
		}
		$links = array_values(
			array_filter(
				$links,
				static function ( $link ): bool {
					return ! self::is_own_url( (string) $link );
				}
			)
		);
	}

	/**
	 * Same host (any scheme, any case, with or without www.) and a path
	 * inside the home path. A plain prefix check missed http:// links on
	 * an https site, which migrated sites still carry, and matched
	 * example.test.evil.test as home.
	 */
	public static function is_own_url( string $url ): bool {
		$home_parts = wp_parse_url( (string) home_url() );
		$parts      = wp_parse_url( $url );
		if ( ! is_array( $home_parts ) || ! is_array( $parts ) || empty( $parts['host'] ) || empty( $home_parts['host'] ) ) {
			return false;
		}
		$strip = static function ( string $host ): string {
			$host = strtolower( $host );
			return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		};
		if ( $strip( $parts['host'] ) !== $strip( $home_parts['host'] ) ) {
			return false;
		}
		$home_path = rtrim( (string) ( $home_parts['path'] ?? '' ), '/' );
		$path      = (string) ( $parts['path'] ?? '' );
		return '' === $home_path || $path === $home_path || 0 === strpos( $path, $home_path . '/' );
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
	 * strip_jquery_migrate() on the registry that exists now, for a request
	 * where `wp_default_scripts` fired before this module hooked it. (#587)
	 */
	public static function strip_jquery_migrate_now(): void {
		self::strip_jquery_migrate( wp_scripts() );
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

	/**
	 * Bloat has no master switch -- it is on when any of its boolean
	 * flags is set. (#363)
	 */
	public function is_active(): ?bool {
		return $this->any_bool_flag_on();
	}
}
