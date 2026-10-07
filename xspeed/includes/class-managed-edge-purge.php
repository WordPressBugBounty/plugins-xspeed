<?php
/**
 * Managed_Edge_Purge — hand xSpeed's purges to the purge plugin xCloud
 * installs on sites whose Cloudflare Enterprise it provides (the "xCloud
 * purge plugin" in CONTEXT.md). Named for the role, an edge someone else
 * manages, so Pro can ask it without naming the service.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * xCloud installs a must-use plugin on every site it puts on its Cloudflare
 * Enterprise edge. From 1.3.0 that plugin takes purges from other plugins:
 *
 *   do_action( 'xcloud_cfe_purge_urls', $urls );
 *   do_action( 'xcloud_cfe_purge_everything' );
 *
 * It batches what it is given with its own purges and sends one request per
 * page load, with its own credential. xSpeed never sees a token and makes no
 * request of its own here. A queue raised after that request has gone is
 * still sent.
 *
 * Without this, xSpeed cleared its own copy of a page and the edge kept
 * serving the old one: xCloud's plugin purges only the edited post's own
 * address, so a new post never reached the cached home page or archives, and
 * xSpeed's "Purge all" never reached Cloudflare at all.
 *
 * What the plugin does NOT do yet, and what follows from it:
 *
 * - It reports no result. A purge it fails to send is not visible here, so
 *   the Purge_Runner row says the purge was handed over, not that it landed.
 * - Over 50 URLs in one request it purges the whole domain instead, assets
 *   included. That is broader than asked but never stale, so the list is sent
 *   as is rather than trimmed to fit.
 * - No prefix purges, so archive pagination goes as one URL per page.
 * - No path-scoped purge, so a full purge of a shared-domain subsite
 *   (`example.com/shop`) purges the whole domain. Broader than its own pages,
 *   but the alternative is leaving them stale.
 *
 * Its own automatic purging (`xcloud_cfe_auto_purge_enabled`) is left on. It
 * covers the edited post, which is already in our list, so nothing is sent
 * twice, and it still covers any edit xSpeed does not purge for.
 *
 * Engine infrastructure, like Server_Caches: no settings, no routes, no UI.
 * Called directly from Cache::dispatch_purge_event() rather than as a
 * listener, for the same reason that one is: a throwing third-party listener
 * on the public action must not be able to skip it. Free tier, because an
 * edge left serving pages xSpeed has purged is a correctness bug, not a paid
 * feature.
 */
final class Managed_Edge_Purge {

	/**
	 * First release that accepts purges from other plugins. Used only to
	 * word the message when the actions are missing: see status().
	 */
	public const MIN_VERSION = '1.3.0';

	/**
	 * Set once a whole-domain purge is queued, so later calls in the same
	 * page load add nothing. Never set in WP-CLI or cron: see latches().
	 */
	private static $everything_queued = false;

	/**
	 * Whether xCloud's purge plugin is on this site at all, at any version.
	 *
	 * The Purge_Runner row is registered on this rather than on status(), so
	 * a site with an old release is told to update instead of seeing nothing.
	 */
	public static function installed(): bool {
		return defined( 'XCLOUD_CFE_PURGE_VERSION' );
	}

	/**
	 * Whether purges can be handed over, or why not.
	 *
	 * @return true|string
	 */
	public static function status() {
		if ( ! self::installed() ) {
			return __( 'xCloud\'s purge plugin is not installed', 'xspeed' );
		}
		// The listeners decide, not the version. xCloud's template defines
		// XCLOUD_CFE_PURGE_VERSION as 1.0.0 when its deploy passes no
		// version, so a copy that takes purges can still report an old
		// number. A copy that failed to boot defines the constant and
		// nothing else, so both actions have to be there.
		if ( function_exists( 'has_action' ) && has_action( 'xcloud_cfe_purge_urls' ) && has_action( 'xcloud_cfe_purge_everything' ) ) {
			return true;
		}
		// The version only picks the message.
		$version = (string) constant( 'XCLOUD_CFE_PURGE_VERSION' );
		if ( ! version_compare( $version, self::MIN_VERSION, '>=' ) ) {
			return sprintf(
				/* translators: 1: installed version, 2: minimum version. */
				__( 'xCloud\'s purge plugin %1$s cannot take purges from xSpeed. Update it to %2$s or later from xCloud.', 'xspeed' ),
				$version,
				self::MIN_VERSION
			);
		}
		return __( 'xCloud\'s purge plugin is installed but not accepting purges', 'xspeed' );
	}

	/**
	 * Mirror one purge-event context at the edge.
	 *
	 * @param mixed $context Purge-event context. See the
	 *                       `xspeed_after_purge` docblock in Cache.
	 */
	public static function forward( $context ): void {
		if ( ! is_array( $context ) || true !== self::status() ) {
			return;
		}
		// A purge run that includes the `xcloud` target (`wp xspeed purge`,
		// Purge All) hands the whole domain over itself and reports it on its
		// own line. Sending the page-cache event's purge as well made one
		// Purge All two whole-domain purges in WP-CLI and cron, where nothing
		// latches. Scoped to the run, not the request, so a later purge in
		// the same long run is still handed over.
		if ( Purge_Runner::covers( 'xcloud' ) ) {
			return;
		}
		$scope = isset( $context['scope'] ) && is_string( $context['scope'] ) ? $context['scope'] : '';
		if ( 'none' === $scope ) {
			return;
		}

		if ( 'urls' === $scope ) {
			$urls = array();
			foreach ( (array) ( $context['urls'] ?? array() ) as $url ) {
				// The endpoint refuses a URL on a host the domain does not
				// serve, and the refusal costs the whole request, xCloud's own
				// purges included. So only this domain's URLs go in.
				if ( is_string( $url ) && self::on_this_domain( $url ) ) {
					$urls[] = $url;
				}
			}
			self::queue_urls( $urls );
			return;
		}

		if ( 'network' === $scope ) {
			self::queue_everything();
			return;
		}

		// `site`, and anything unknown: a purge we cannot read is likelier to
		// need the edge than not. Only for this domain, though. A full purge
		// of another multisite site on a host of its own is not ours to send.
		$host = isset( $context['host'] ) && is_string( $context['host'] ) ? $context['host'] : '';
		if ( '' === $host || '*' === $host || self::host_is_this_domain( $host ) ) {
			self::queue_everything();
		}
	}

	/**
	 * Purge the whole domain at the edge. The Purge_Runner `xcloud` target.
	 *
	 * @param string $cause Who asked. Unused: xCloud's request carries no cause.
	 * @return array{ok?:bool,reason:string}
	 */
	public static function purge_everything( string $cause = 'manual' ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Purge_Runner callback signature.
		$status = self::status();
		if ( true !== $status ) {
			return array(
				'ok'     => false,
				'reason' => (string) $status,
			);
		}
		self::queue_everything();
		return array(
			'reason' => __( 'handed to xCloud\'s purge plugin, which sends it when this request ends', 'xspeed' ),
		);
	}

	/**
	 * Queue URLs with xCloud's plugin.
	 *
	 * @param array<int,string> $urls Absolute URLs on this domain.
	 */
	private static function queue_urls( array $urls ): void {
		$urls = array_values( array_unique( $urls ) );
		if ( array() === $urls || self::$everything_queued ) {
			return;
		}
		do_action( 'xcloud_cfe_purge_urls', $urls ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- xCloud's own action; xSpeed calls it, it does not define it.
	}

	/** Queue a whole-domain purge, once per page load. */
	private static function queue_everything(): void {
		if ( self::$everything_queued ) {
			return;
		}
		self::$everything_queued = self::latches();
		do_action( 'xcloud_cfe_purge_everything' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- xCloud's own action; xSpeed calls it, it does not define it.
	}

	/**
	 * Whether a queued whole-domain purge may stand for the rest of this
	 * process.
	 *
	 * In a page load, yes: the plugin sends once, at the end, so everything
	 * after the first whole-domain purge is covered by it. WP-CLI and cron
	 * can run for minutes and purge many times. When the plugin sends is
	 * not visible from here, and a purge it has already sent cannot cover a
	 * change made after it, so there every purge is handed over. The plugin
	 * batches what it is given, so a repeat costs nothing if it has not sent
	 * yet.
	 */
	private static function latches(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		return ! ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() );
	}

	/**
	 * Whether a URL is on the domain xCloud's plugin purges for.
	 *
	 * @param string $url Absolute URL.
	 */
	private static function on_this_domain( string $url ): bool {
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fallback for a bare test harness.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}
		// A non-default port is another origin, and not one the edge serves.
		if ( isset( $parts['port'] ) ) {
			$port = (int) $parts['port'];
			if ( ! ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) ) {
				return false;
			}
		}
		return self::host_is_this_domain( (string) $parts['host'] );
	}

	/**
	 * Whether a host is this site's, allowing the apex and `www` forms of it.
	 *
	 * xCloud registers an apex with its `www` form, so a URL on either is one
	 * the edge serves. Any other host, including another multisite site's own
	 * domain, is not.
	 *
	 * @param string $host Host, optionally with a port.
	 */
	private static function host_is_this_domain( string $host ): bool {
		if ( ! function_exists( 'home_url' ) ) {
			return false;
		}
		$home  = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) home_url( '/' ), PHP_URL_HOST ) : parse_url( (string) home_url( '/' ), PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- see above.
		$ours  = self::without_www( strtolower( (string) $home ) );
		$given = self::without_www( strtolower( (string) preg_replace( '/:\d+$/', '', $host ) ) );
		return '' !== $ours && $ours === $given;
	}

	/**
	 * Strip one leading `www.`.
	 *
	 * @param string $host Lowercase host.
	 */
	private static function without_www( string $host ): string {
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/** Test seam: forget the per-request state. */
	public static function reset(): void {
		self::$everything_queued = false;
	}
}
