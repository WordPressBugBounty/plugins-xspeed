<?php
/**
 * CDN module — rewrites local asset URLs to a user-supplied pull-zone
 * CDN hostname (BunnyCDN, KeyCDN, Cloudflare R2, custom).
 *
 * Tier: Free per FEATURES.md "CDN Integration" §1-6 (LiteSpeed parity).
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Cdn;

defined( 'ABSPATH' ) || exit;

use XSpeed\Cdn_Rewriter;
use XSpeed\Module;

final class CdnModule extends Module {

	public const SLUG    = 'cdn';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	public function ui_metadata(): array {
		return array(
			'label'       => 'CDN',
			'icon'        => 'Globe',
			'description' => 'Serve static assets (images, fonts, CSS, JS) from a pull-zone CDN host like BunnyCDN, KeyCDN, or your own.',
		);
	}

	public function settings_schema(): array {
		return array(
			'enabled' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => 'Enable CDN',
				'description' => 'Rewrite static asset URLs to the CDN hostname below. Your CDN must be a pull-zone configured to fetch from this site.',
			),
			'cdn_url' => array(
				'type'        => 'string',
				'default'     => '',
				'label'       => 'CDN URL',
				'description' => 'CDN hostname, e.g. cdn.example.com. https:// and trailing slashes are stripped automatically.',
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'included_extensions' => array(
				'type'        => 'list',
				'default'     => Cdn_Rewriter::DEFAULT_EXTENSIONS,
				'item_type'   => 'string',
				'label'       => 'Included File Extensions',
				'description' => 'Only URLs ending in these extensions are rewritten. Defaults cover images, fonts, CSS, JS, and common media.',
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'excluded_patterns' => array(
				'type'        => 'list',
				'default'     => array(),
				'item_type'   => 'string',
				'label'       => 'Excluded Patterns',
				'description' => 'Glob patterns matched against the URL path. Matching URLs stay on the origin. Examples: /wp-admin/*, *.pdf, /private/*',
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
		);
	}

	public function conflicts(): array {
		return array(
			array(
				'plugin'   => 'cdn-enabler/cdn-enabler.php',
				'feature'  => 'cdn.rewrite',
				'strategy' => \XSpeed\Conflict_Registry::STRATEGY_REFUSE,
				'reason'   => 'CDN Enabler rewrites the same URLs; running both will double-rewrite or produce broken hosts.',
			),
		);
	}

	public function boot(): void {
		// Always-on: normalize cdn_url on save (admin context too).
		add_filter( 'pre_update_option_xspeed_module_cdn', array( $this, 'normalize_on_save' ), 10, 1 );

		// CDN URLs are baked into cached HTML, so a settings change that
		// isn't followed by a purge is invisible: the user edits the CDN
		// host, reloads, sees the old host still served from cache, and
		// concludes the feature is broken. Also keeps the font-CORS rules
		// in .htaccess in step with the enabled flag.
		add_action( 'update_option_xspeed_module_cdn', array( $this, 'on_settings_change' ), 10, 0 );

		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Rewriting asset hosts under a builder editor sends the editor's own
		// scripts to the CDN, where the copy can be stale or absent. (#281)
		if ( \XSpeed\Builder_Editor::is_active() ) {
			return;
		}
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) || empty( $opts['cdn_url'] ) ) {
			return;
		}
		Cdn_Rewriter::reset_state();

		// Attachment URLs still go through their own filter: media-library
		// URLs are frequently consumed as PHP strings (feeds, oEmbed, REST
		// echoes) rather than emitted into the page HTML we rewrite below.
		add_filter( 'wp_get_attachment_url', array( $this, 'rewrite_attachment_url' ), 1000 );

		// Preconnect to the CDN host. Every asset on the page now resolves
		// there, so paying the DNS + TLS handshake once up front rather than
		// on first asset request is worth the one tag.
		add_filter( 'wp_resource_hints', array( $this, 'add_preconnect' ), 10, 2 );

		// Whole-page pass.
		//
		// This module used to hook only the_content, post_thumbnail_html and
		// widget_text_content — four filters that between them can never
		// contain a stylesheet, a script or a font. So `css`, `js` and the
		// five font extensions shipped ticked by default and rewrote nothing:
		// a user enabled the CDN, saw them enabled, and found zero requests
		// in their pull zone.
		//
		// Enqueued assets can't be reached with those filters at all, and
		// hooking style_loader_src/script_loader_src would still miss inline
		// url(), hardcoded theme-template images and third-party echo output.
		// One pass over the finished page catches every category at once.
		//
		// It also fixes the srcset split: core builds srcset from
		// wp_get_upload_dir() and never calls wp_get_attachment_url(), so a
		// theme image previously got a CDN `src` and an origin `srcset` in
		// the same tag.
		//
		// Cost: on the cache-write path this runs once per MISS and the CDN
		// URLs bake into the stored HTML, so cache HITs pay nothing. This is
		// what Powered Cache, Breeze and SpeedyCache all do. The trade-off is
		// that turning the CDN off needs a cache purge — handled by
		// purge_on_change() below.
		add_filter(
			'xspeed_cache_final_html',
			static function ( $html ) {
				if ( ! self::should_rewrite_request() ) {
					return $html;
				}
				return Cdn_Rewriter::process_html( (string) $html );
			},
			// After Resource Hints (10) so any preload/preconnect tag it
			// injects gets its URL rewritten too.
			20,
			1
		);

		// Cache-off path: the filter above never fires, so buffer the page
		// ourselves. Guarded so we never double-buffer when the cache engine
		// is running.
		if ( ! $this->cache_enabled() ) {
			add_action(
				'template_redirect',
				static function () {
					if ( self::$buffering || ! self::should_rewrite_request() ) {
						return;
					}
					self::$buffering = true;
					ob_start(
						static function ( $buffer ) {
							if ( strlen( (string) $buffer ) < 255 ) {
								return $buffer;
							}
							return Cdn_Rewriter::process_html( (string) $buffer );
						}
					);
				},
				9
			);
		}
	}

	/**
	 * Guard against opening our buffer twice on one request.
	 *
	 * @var bool
	 */
	private static $buffering = false;

	/**
	 * Should this request have its asset URLs rewritten at all?
	 *
	 * The module's original bail set covered admin / AJAX / cron / REST only.
	 * These four are the remaining request types where a CDN URL is either
	 * wrong or actively unhelpful:
	 *
	 *   - Previews render unsaved content for one logged-in author; pointing
	 *     their assets at a pull zone caches a draft at the edge.
	 *   - robots.txt and trackbacks are not HTML and have no assets.
	 *   - Non-GET requests are form posts and API calls, never a page whose
	 *     asset URLs matter.
	 */
	public static function should_rewrite_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return false;
		}
		if ( function_exists( 'is_preview' ) && is_preview() ) {
			return false;
		}
		if ( function_exists( 'is_robots' ) && is_robots() ) {
			return false;
		}
		if ( function_exists( 'is_trackback' ) && is_trackback() ) {
			return false;
		}
		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return false;
		}

		/**
		 * Final say on whether to rewrite asset URLs for this request.
		 *
		 * @param bool $should Whether to rewrite.
		 */
		return (bool) apply_filters( 'xspeed_cdn_should_rewrite', true );
	}

	/**
	 * Is the page cache on? When it is, Cache::finalize_buffer() runs and our
	 * xspeed_cache_final_html filter fires — so we must NOT also ob_start().
	 */
	private function cache_enabled(): bool {
		$legacy = \XSpeed\Settings_Manager::get( 'legacy' );
		if ( is_array( $legacy ) && ! empty( $legacy['cache_enabled'] ) ) {
			return true;
		}
		$opts = get_option( 'xspeed_options' );
		return is_array( $opts ) && ! empty( $opts['cache_enabled'] );
	}

	/**
	 * Settings changed — purge the page cache and re-sync the font-CORS
	 * rules in .htaccess.
	 */
	public function on_settings_change(): void {
		$this->sync_font_cors();
		if ( class_exists( '\\XSpeed\\Cache' ) ) {
			\XSpeed\Cache::purge_all( 'cdn settings change' );
			// purge_all() only reaches what we wrote. The attachment-URL
			// filter below runs DURING render, so a page builder that caches
			// rendered output has already stored the old host — Elementor
			// keeps it in `_elementor_element_cache` for 24 h and in
			// `uploads/elementor/css/post-<id>.css` with no expiry at all.
			// Without this, turning the CDN OFF keeps serving the dead host
			// (images 404 once the pull zone lapses) and turning it ON leaves
			// the LCP hero on the origin — both for a day or more, both after
			// a purge the user watched succeed.
			\XSpeed\Cache::purge_render_caches( 'cdn settings change' );
		}
	}

	/**
	 * Write (or remove) the Apache/LiteSpeed font-CORS block.
	 *
	 * nginx hosts get the same directives through nginx_directives() and the
	 * unified server-block snippet instead — we can't write their config.
	 */
	public function sync_font_cors(): void {
		if ( ! class_exists( '\\XSpeed\\Server' ) || ! \XSpeed\Server::supports_htaccess() ) {
			return;
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			return;
		}

		$opts   = $this->get_settings();
		$active = ! empty( $opts['enabled'] ) && ! empty( $opts['cdn_url'] );

		$rules = $active
			? array(
				'<IfModule mod_headers.c>',
				'  # Allow the CDN to pull webfonts cross-origin.',
				'  <FilesMatch "\\.(woff2?|ttf|otf|eot)$">',
				'    Header always set Access-Control-Allow-Origin "*"',
				'  </FilesMatch>',
				'</IfModule>',
			)
			: array();

		// ABSPATH rather than get_home_path(): that function lives in
		// wp-admin/includes/file.php, which is not loaded on a REST, CLI or
		// cron request — and because this class is namespaced, the
		// unqualified call resolved to XSpeed\Modules\Cdn\get_home_path()
		// and fatalled on every real save, including disabling the module.
		// This mirrors class-gzip.php, and the file_exists() guard it brings
		// also stops insert_with_markers() creating a stray .htaccess at the
		// WP root on a subdirectory install.
		$htaccess = ABSPATH . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// Nothing to amend, and nothing to clean up.
			if ( empty( $rules ) ) {
				return;
			}
			if ( ! is_writable( ABSPATH ) ) {
				return;
			}
		}

		insert_with_markers( $htaccess, 'xSpeed CDN', $rules );
	}

	/**
	 * Font CORS for the origin.
	 *
	 * We ship the five font extensions enabled by default, and now that CSS
	 * actually reaches the CDN, `@font-face` inside those stylesheets
	 * resolves against the CDN host too. A font fetched cross-origin is a
	 * CORS request: without `Access-Control-Allow-Origin` on the ORIGIN
	 * response, the CDN caches a response the browser then refuses, and every
	 * webfont silently falls back to a system face.
	 *
	 * This was latent before — nothing reached the CDN, so nothing broke.
	 * Fixing the rewrite without this would turn a dead setting into a live
	 * regression, which is why it ships in the same change.
	 *
	 * @return string|null nginx directives, or null when the CDN is off.
	 */
	public function nginx_directives(): ?string {
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) || empty( $opts['cdn_url'] ) ) {
			return null;
		}
		return "# Allow the CDN to pull webfonts cross-origin.\n"
			. "location ~* \\.(woff2?|ttf|otf|eot)$ {\n"
			. "    add_header Access-Control-Allow-Origin \"*\" always;\n"
			. "}";
	}

	/**
	 * Emit a preconnect hint for the CDN host.
	 *
	 * @param array  $hints         URLs for this relation type.
	 * @param string $relation_type One of dns-prefetch / preconnect / …
	 * @return array
	 */
	public function add_preconnect( $hints, $relation_type ) {
		if ( 'preconnect' !== $relation_type || ! is_array( $hints ) ) {
			return $hints;
		}
		if ( Cdn_Rewriter::is_dev_host() ) {
			return $hints;
		}
		$opts = $this->get_settings();
		$host = Cdn_Rewriter::normalize_host( (string) ( $opts['cdn_url'] ?? '' ) );
		if ( '' === $host ) {
			return $hints;
		}
		// crossorigin so the hint also warms the connection fonts will use —
		// font requests are CORS requests and would otherwise open a second
		// connection.
		$hints[] = array(
			'href'        => '//' . $host,
			'crossorigin' => 'anonymous',
		);
		return $hints;
	}

	public function rewrite_attachment_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		return Cdn_Rewriter::rewrite_url( $url, $this->get_settings() );
	}

	/**
	 * pre_update_option filter — strips https:// + trailing slash from
	 * cdn_url before storage, so we always work against a bare host.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public function normalize_on_save( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( isset( $value['cdn_url'] ) ) {
			$value['cdn_url'] = Cdn_Rewriter::normalize_host( (string) $value['cdn_url'] );
		}
		return $value;
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed cdn',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Show CDN settings + test rewriting a URL.',
				'ai_hint'   => 'Is a CDN configured, and does URL rewriting work? Use to check whether assets are served from the CDN, or to test what a given URL rewrites to before trusting the setting.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'status', 'test' ),
						'optional' => true,
					),
					array(
						'type'     => 'assoc',
						'name'     => 'url',
						'optional' => true,
					),
				),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$action = $args[0] ?? 'status';
		$opts   = $this->get_settings();
		if ( 'test' === $action ) {
			$url = isset( $assoc['url'] ) ? (string) $assoc['url'] : '';
			if ( '' === $url ) {
				\WP_CLI::error( 'Pass --url=<url> to test rewriting.' );
			}
			Cdn_Rewriter::reset_state();
			\WP_CLI::log( 'in:  ' . $url );
			\WP_CLI::log( 'out: ' . Cdn_Rewriter::rewrite_url( $url, $opts ) );
			return;
		}
		\WP_CLI::log( sprintf( '%-22s %s', 'enabled', ! empty( $opts['enabled'] ) ? 'on' : 'off' ) );
		\WP_CLI::log( sprintf( '%-22s %s', 'cdn_url', (string) ( $opts['cdn_url'] ?? '' ) ) );
		\WP_CLI::log( sprintf( '%-22s %s', 'included_extensions', implode( ',', (array) ( $opts['included_extensions'] ?? array() ) ) ) );
		\WP_CLI::log( sprintf( '%-22s %s', 'excluded_patterns', implode( ',', (array) ( $opts['excluded_patterns'] ?? array() ) ) ) );
	}
}
