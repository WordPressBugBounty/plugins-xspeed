<?php
/**
 * One cached copy of a page per WooCommerce cart (#649).
 *
 * WooCommerce sets `woocommerce_cart_hash`, `woocommerce_items_in_cart` and
 * a `wp_woocommerce_session_*` cookie as soon as a visitor adds a product.
 * They match the default excluded cookies, so from then on every page the
 * visitor opens skipped the cache: the shoppers most likely to buy got the
 * slowest pages. With `woo_cart_vary` on, those three cookies no longer
 * exclude the visitor. The page is cached once per cart hash instead, so the
 * header mini-cart stays right and two shoppers with the same cart share a
 * copy.
 *
 * The drop-in carries its own copy of these rules, because it runs before
 * WordPress loads. Change the two together.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Cart_Vary {

	/** The cookie WooCommerce rewrites whenever the cart's contents change. */
	public const HASH_COOKIE = 'woocommerce_cart_hash';

	public const ITEMS_COOKIE = 'woocommerce_items_in_cart';

	/** Followed by a 32-character customer id, e.g. wp_woocommerce_session_8d79…. */
	public const SESSION_PREFIX = 'wp_woocommerce_session_';

	/**
	 * Copies kept per page and device. A bot that keeps changing its cart
	 * would otherwise add a file per cart state for every page it opens.
	 */
	public const MAX_COPIES = 50;

	/**
	 * Set when a WooCommerce notice template ran during this render.
	 *
	 * @var bool
	 */
	private static $notice_printed = false;

	/**
	 * Whether the setting is on. Read from the stored option, as
	 * mobile_separate is: this runs on the serve path, where the module
	 * registry may not have run.
	 */
	public static function enabled(): bool {
		return self::switched_on() && ! self::geolocation_ajax();
	}

	/** Whether the setting is stored as on, whatever WooCommerce's mode. */
	private static function switched_on(): bool {
		$stored = get_option( 'xspeed_module_cache', array() );
		if ( ! is_array( $stored ) ) {
			return false;
		}
		// Strict: while WooCommerce is inactive the key is preserved as
		// written, and ! empty() would read a stored "false" as on.
		$value = $stored['woo_cart_vary'] ?? false;
		return true === $value || 1 === $value || '1' === $value;
	}

	/**
	 * WooCommerce's Default customer location changed.
	 *
	 * Moving in or out of "Geolocate (with page caching support)" switches
	 * cart copies off or on without a save of our own settings. While they
	 * are off nothing purges them, so the copies left on disk could be
	 * served stale once they come back. Clear them, and re-bake the drop-in
	 * so it agrees with enabled() at once.
	 *
	 * @param mixed $old Previous value.
	 * @param mixed $new New value.
	 */
	public static function on_customer_location_change( $old, $new ): void {
		if ( ! self::switched_on() || ( 'geolocation_ajax' === $old ) === ( 'geolocation_ajax' === $new ) ) {
			return;
		}
		Cache::purge_cart_copies();
		$options = get_option( 'xspeed_options', array() );
		if ( is_array( $options ) && ! empty( $options['cache_enabled'] ) ) {
			Cache::toggle( true );
		}
	}

	/**
	 * WooCommerce's "Geolocate (with page caching support)" mode.
	 *
	 * In that mode prices and taxes follow the visitor's location, which no
	 * cookie of ours carries, so two shoppers with the same cart could see
	 * different totals. The setting stays off while it is selected.
	 */
	public static function geolocation_ajax(): bool {
		return 'geolocation_ajax' === get_option( 'woocommerce_default_customer_address' );
	}

	/**
	 * Note that WooCommerce printed a notice into this render.
	 *
	 * Hooked to `woocommerce_before_template_part`. Every notice goes through
	 * a `notices/*.php` template, and on block themes WooCommerce swaps in its
	 * banner markup inside that same call, so this sees notices whatever the
	 * theme renders them as.
	 *
	 * @param string $template_name Template being loaded.
	 * @param string $template_path Unused.
	 * @param string $located       Unused.
	 * @param mixed  $args          Template arguments.
	 */
	public static function note_template( $template_name, $template_path = '', $located = '', $args = array() ): void {
		if ( ! is_string( $template_name ) || 0 !== strpos( $template_name, 'notices/' ) ) {
			return;
		}
		if ( is_array( $args ) && array_key_exists( 'notices', $args ) && empty( $args['notices'] ) ) {
			return;
		}
		self::$notice_printed = true;
	}

	/** Test seam: forget the notice noted for this render. */
	public static function reset(): void {
		self::$notice_printed = false;
	}

	/**
	 * One of the three cookies this setting stops treating as a reason to
	 * bypass. Every other WooCommerce cookie, such as
	 * `woocommerce_recently_viewed`, says something personal beyond the cart
	 * and keeps bypassing.
	 */
	public static function is_cart_cookie( string $name ): bool {
		if ( self::HASH_COOKIE === $name || self::ITEMS_COOKIE === $name ) {
			return true;
		}
		// The same pattern the drop-in uses.
		return 1 === preg_match( '/^wp_woocommerce_session_[A-Za-z0-9]+$/D', $name );
	}

	/**
	 * The cache-key suffix for a visitor with these cookies.
	 *
	 * '' with no cart cookie at all: the visitor shares the plain copy.
	 * '.c<hash>' for a cart WooCommerce has hashed: that cart's own copy.
	 * null otherwise, which means bypass. That covers a hash that is not
	 * 32 lowercase hex characters (it becomes part of a file name) and a
	 * session with no cart behind it, which can still hold per-customer
	 * state such as a shipping location.
	 *
	 * @param array<string,mixed> $cookies Usually $_COOKIE.
	 */
	public static function suffix_for( array $cookies ): ?string {
		$has_cart_cookie = false;
		foreach ( array_keys( $cookies ) as $name ) {
			if ( self::is_cart_cookie( (string) $name ) ) {
				$has_cart_cookie = true;
				break;
			}
		}
		if ( ! $has_cart_cookie ) {
			return '';
		}

		$hash = $cookies[ self::HASH_COOKIE ] ?? null;
		if ( is_string( $hash ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $hash ) ) {
			return '.c' . $hash;
		}
		return null;
	}

	/**
	 * Whether this render may be stored under $key.
	 *
	 * A cart hash is the same for everyone with the same cart, so anyone can
	 * copy one into a cookie. Before a render is stored as that cart's copy,
	 * the cart WooCommerce actually loaded for this visitor must have that
	 * hash. Otherwise the page shows some other cart (an empty one, an
	 * expired session, a cart changed mid-render) and would be served to
	 * every real shopper with that hash. A page carrying a WooCommerce notice
	 * belongs to one visit and is not stored either: WooCommerce has already
	 * cleared a notice it printed, so the markup is checked as well.
	 *
	 * @param string $key  From Cache::cache_key().
	 * @param string $html The render about to be stored.
	 */
	public static function may_store( string $key, string $html ): bool {
		if ( false === strpos( $key, '.c' ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared with hash_equals().
		$hash = isset( $_COOKIE[ self::HASH_COOKIE ] ) ? wp_unslash( $_COOKIE[ self::HASH_COOKIE ] ) : null;
		if ( ! is_string( $hash ) || ! function_exists( 'WC' ) ) {
			return false;
		}
		$woocommerce = WC();
		$cart        = is_object( $woocommerce ) && isset( $woocommerce->cart ) ? $woocommerce->cart : null;
		if ( ! is_object( $cart ) || $cart->is_empty() || ! hash_equals( (string) $cart->get_cart_hash(), $hash ) ) {
			return false;
		}
		if ( self::$notice_printed || ( function_exists( 'wc_notice_count' ) && wc_notice_count() > 0 ) ) {
			return false;
		}
		// The markup as well, for a notice printed some other way. Classic
		// notices are woocommerce-message, -error and -info. Block themes
		// print a wc-block-components-notice-banner with an is-error,
		// is-success or is-info class; the bare class also sits in an empty
		// <template> on every page and says nothing. A false match only
		// means this page is built fresh for the shopper.
		return 1 !== preg_match( '/class=["\'][^"\']*\b(?:woocommerce-(?:message|error|info)\b|wc-block-components-notice-banner\s+is-)/', $html );
	}

	/**
	 * The suffix for this request: '' while the setting is off.
	 */
	public static function request_suffix(): ?string {
		if ( ! self::enabled() ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- names and one value are checked against fixed patterns in suffix_for(); nothing is echoed.
		return self::suffix_for( is_array( $_COOKIE ) ? $_COOKIE : array() );
	}
}
