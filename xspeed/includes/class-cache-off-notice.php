<?php
/**
 * A notice on every admin page while the page cache is off (#594).
 *
 * The page cache defaults to off, and only the setup wizard turns it on. A
 * site activated with WP-CLI, MCP or a host's bulk install, or where nobody
 * finished the wizard, sent every visitor an uncached page and wp-admin never
 * said so. The cache is not turned on at activation: a host with its own page
 * cache needs a person to decide. So the notice asks, with a button that
 * turns it on. Dismissing it is kept for the site, so an admin who turned the
 * cache off on purpose sees it once.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Cache_Off_Notice {

	/** admin-post action of the "Turn it on" button. */
	public const ACTION_ON = 'xspeed_cache_off_notice_on';

	/** admin-post action of the Dismiss link. */
	public const ACTION_DISMISS = 'xspeed_cache_off_notice_dismiss';

	/** Set when an admin dismissed the notice. Kept for the site. */
	public const OPT_DISMISSED = 'xspeed_cache_off_notice_dismissed';

	/** Per-user transient prefix carrying a refused "Turn it on" across the redirect. */
	private const REFUSED_PREFIX = 'xspeed_cache_on_refused_';

	/** Query arg carrying the dashboard route (the URL fragment) back through the redirect. */
	private const ROUTE_PARAM = 'xspeed_route';

	/** Printed already: it is hooked to two notice actions. */
	private static bool $shown = false;

	public static function boot(): void {
		// xSpeed's own screens strip `admin_notices` and relay their own
		// through `xspeed_admin_notices` (Admin::suppress_foreign_notices()).
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice' ) );
		add_action( 'xspeed_admin_notices', array( __CLASS__, 'maybe_notice' ) );
		add_action( 'admin_post_' . self::ACTION_ON, array( __CLASS__, 'handle_on' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( __CLASS__, 'handle_dismiss' ) );
	}

	/** Whether the notice applies to this site and user now. */
	public static function applies(): bool {
		if ( ! current_user_can( 'manage_options' ) || ( function_exists( 'is_network_admin' ) && is_network_admin() ) ) {
			return false;
		}
		$opts = get_option( 'xspeed_options', array() );
		if ( is_array( $opts ) && ! empty( $opts['cache_enabled'] ) ) {
			return false;
		}
		if ( get_option( self::OPT_DISMISSED ) ) {
			return false;
		}
		// Another cache plugin is serving pages. "Built from scratch" would be
		// false, and the site chose that cache: the dashboard still offers the
		// takeover, but every admin screen should not.
		if ( in_array(
			Page_Cache_Detector::classify()['state'],
			array( Page_Cache_Detector::STATE_FOREIGN_LIVE, Page_Cache_Detector::STATE_POSSIBLE_LIVE, Page_Cache_Detector::STATE_CONTESTED ),
			true
		) ) {
			return false;
		}
		// The setup wizard asks the same question, with the cache on by default.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen check only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		return Onboarding::PAGE_SLUG !== $page;
	}

	public static function maybe_notice(): void {
		if ( self::$shown ) {
			return;
		}
		$user    = get_current_user_id();
		$refused = get_transient( self::REFUSED_PREFIX . $user );
		if ( is_string( $refused ) && '' !== $refused ) {
			delete_transient( self::REFUSED_PREFIX . $user );
			self::$shown = true;
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'xSpeed could not turn the page cache on.', 'xspeed' ),
				esc_html( $refused )
			);
			return;
		}
		if ( ! self::applies() ) {
			return;
		}
		self::$shown = true;

		// Turning the cache on replaces any advanced-cache.php already there.
		// The dashboard names its owner before that happens (QA on #642: one
		// click here took over WP Super Cache's cache without a word), so the
		// notice does too, and only its takeover link counts as consent.
		$disclosure = Page_Cache_Detector::dropin_disclosure();
		$on_url     = admin_url( 'admin-post.php?action=' . self::ACTION_ON );
		if ( $disclosure['replaceable'] ) {
			$on_url = add_query_arg( 'takeover', '1', $on_url );
			if ( null !== $disclosure['label'] ) {
				$body = sprintf(
					/* translators: %s: the page-caching plugin that owns advanced-cache.php. */
					__( 'Every visitor gets a page built from scratch. %s owns the page-cache file now. Turning xSpeed\'s cache on replaces it, and xSpeed serves the cached pages instead.', 'xspeed' ),
					$disclosure['label']
				);
				/* translators: %s: the page-caching plugin that owns advanced-cache.php. */
				$button = sprintf( __( 'Take over from %s', 'xspeed' ), $disclosure['label'] );
			} else {
				$body   = __( 'Every visitor gets a page built from scratch. Another page-cache file is in wp-content now. Turning xSpeed\'s cache on replaces it.', 'xspeed' );
				$button = __( 'Replace it and turn on', 'xspeed' );
			}
		} else {
			$body   = __( 'Every visitor gets a page built from scratch. Turn it on unless your host already caches pages for this site.', 'xspeed' );
			$button = __( 'Turn it on', 'xspeed' );
		}

		printf(
			'<div id="xspeed-cache-off-notice" class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" data-xspeed-keep-route href="%3$s">%4$s</a> <a class="button" href="%5$s">%6$s</a> <a data-xspeed-keep-route href="%7$s">%8$s</a></p></div>',
			esc_html__( "xSpeed's page cache is off.", 'xspeed' ),
			esc_html( $body ),
			esc_url( wp_nonce_url( $on_url, self::ACTION_ON ) ),
			esc_html( $button ),
			esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_SLUG . '#/cache/cache' ) ),
			esc_html__( 'Page cache settings', 'xspeed' ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_DISMISS ), self::ACTION_DISMISS ) ),
			esc_html__( 'Dismiss', 'xspeed' )
		);
		// The dashboard keeps its screen in the URL fragment, which no
		// referer carries, so the redirect back landed on Overview. The
		// links pass the fragment along when clicked.
		echo "<script>document.querySelectorAll('#xspeed-cache-off-notice [data-xspeed-keep-route]').forEach(function(a){a.addEventListener('click',function(){if(location.hash){var u=new URL(a.href);u.searchParams.set('" . esc_js( self::ROUTE_PARAM ) . "',location.hash);a.href=u.toString();}});});</script>";
	}

	/**
	 * "Turn it on": the same Cache::toggle() the dashboard switch calls, so a
	 * drop-in or WP_CACHE line owned by another plugin is refused the same
	 * way. A refusal is shown on the next page instead of the notice.
	 *
	 * Another plugin's drop-in is taken over only from the link that named it.
	 * If one appeared after the notice was printed, the toggle refuses and
	 * says whose it is.
	 */
	public static function handle_on(): void {
		self::authorize( self::ACTION_ON );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authorize() checked the nonce.
		$takeover = isset( $_GET['takeover'] ) && '1' === $_GET['takeover'];
		$state    = Cache::toggle( true, $takeover );
		if ( ! empty( $state['blocked'] ) ) {
			$reason = isset( $state['blocked_reason'] ) && is_string( $state['blocked_reason'] ) ? $state['blocked_reason'] : '';
			set_transient( self::REFUSED_PREFIX . get_current_user_id(), '' !== $reason ? $reason : __( 'Open the page cache settings to see why.', 'xspeed' ), 5 * MINUTE_IN_SECONDS );
		}
		self::back();
	}

	public static function handle_dismiss(): void {
		self::authorize( self::ACTION_DISMISS );
		update_option( self::OPT_DISMISSED, time(), false );
		self::back();
	}

	private static function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'xspeed' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function back(): void {
		$referer = wp_get_referer();
		$target  = $referer ? $referer : admin_url();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authorize() checked the nonce.
		$route = isset( $_GET[ self::ROUTE_PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::ROUTE_PARAM ] ) ) : '';
		if ( false === strpos( $target, '#' ) && preg_match( '~^#/[A-Za-z0-9/_-]*$~', $route ) ) {
			$target .= $route;
		}
		wp_safe_redirect( $target );
		exit;
	}
}
