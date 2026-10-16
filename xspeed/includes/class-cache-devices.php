<?php
/**
 * Cache_Devices — the device copies the page cache keeps, in one list.
 *
 * With Separate Mobile Cache on, a page is stored once per device and the
 * device is part of the cache key. Before this class, the list of devices
 * and their key suffixes was spelled out separately in the key builder, the
 * one-URL purge, the preloader, the listing-page records and the drop-in.
 * A device added to one and missed in another leaves entries a purge cannot
 * find, or a drop-in that never finds what PHP stored.
 *
 * Everything here is pure: no settings reads, no request reads. Callers
 * pass the cache settings they already hold, so each keeps reading them
 * the way it did (the stored option on boot paths, Settings_Manager on
 * request paths).
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Cache_Devices {

	public const MOBILE  = 'mobile';
	public const TABLET  = 'tablet';
	public const DESKTOP = 'desktop';

	/** Key suffix of an entry stored while one copy serves every device. */
	public const SHARED_SUFFIX = '';

	/**
	 * Every device copy and its cache-key suffix, in the order a purge walks
	 * them. The suffixes are part of stored file names: never change one.
	 */
	private const SUFFIXES = array(
		self::MOBILE  => '|m',
		self::TABLET  => '|t',
		self::DESKTOP => '|d',
	);

	/**
	 * Warm order. Desktop first: its response body is the one the preloader
	 * reads for links. Tablet last: it is the copy fewest visitors ask for.
	 */
	private const WARM_ORDER = array( self::DESKTOP, self::MOBILE, self::TABLET );

	/** What a tablet render can be built as (`tablet_renders_as`). Default first. */
	public const TABLET_RENDERS = array( self::DESKTOP, self::MOBILE );

	/*
	 * Every device pattern in Free lives here. The drop-in gets them baked in
	 * by bake(); nothing else defines one.
	 */

	/**
	 * The user-agent tokens core's wp_is_mobile() matches. Used where
	 * wp_is_mobile() cannot run: the drop-in, and Cache's fallback detector.
	 */
	public const MOBILE_UA_REGEX = '/(Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi)/i';

	/**
	 * Tablets, by Cloudflare's tablet rule plus Kindle (an e-ink Kindle UA
	 * names neither Android nor Silk). Checked before Sec-CH-UA-Mobile:
	 * Chrome on an Android tablet sends `?0`, which on its own reads as a
	 * desktop. iPadOS 13 and later sends a Mac user-agent and no hint, so
	 * those iPads stay desktop, as they do at Cloudflare and in WordPress.
	 */
	public const TABLET_UA_REGEX = '/(?:ipad|playbook|(?:android|bb\d+|meego|silk)(?! .+? mobile)|kindle)/i';

	/**
	 * Cloudflare's published rules for `CF-Device-Type`, verbatim and
	 * case-insensitive, user-agent only (no client hints):
	 * https://developers.cloudflare.com/automatic-platform-optimization/reference/cache-device-type/
	 * Tablet is tested first, then mobile; anything else is desktop.
	 */
	public const EDGE_TABLET_UA_REGEX = '/(?:ipad|playbook|(?:android|bb\d+|meego|silk)(?! .+? mobile))/i';

	/** Cloudflare's mobile rule, from the same page as EDGE_TABLET_UA_REGEX. */
	public const EDGE_MOBILE_UA_REGEX = '/(?:phone|windows\s+phone|ipod|blackberry|(?:android|bb\d+|meego|silk|googlebot) .+? mobile|palm|windows\s+ce|opera mini|avantgo|mobilesafari|docomo|kaios)/i';

	/** Drop-in flag files, next to the cache files, that say which copies exist. */
	public const MOBILE_FLAG = '.mobile-separate';
	public const TABLET_FLAG = '.tablet-separate';

	/** Drop-in placeholder replaced by bake(). Quoted, as it sits in the source. */
	public const DROPIN_PLACEHOLDER = "'@@XSPEED_DEVICE_RULES@@'";

	/**
	 * Every device copy, in purge order.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_keys( self::SUFFIXES );
	}

	/**
	 * The cache-key suffix of one device copy. An unknown device gets the
	 * shared suffix.
	 */
	public static function suffix( string $device ): string {
		return self::SUFFIXES[ $device ] ?? self::SHARED_SUFFIX;
	}

	/**
	 * Every suffix an entry on disk can carry, whatever the settings are now:
	 * the shared copy first, then each device copy. A purge walks all of
	 * them because entries written before a settings change are still there.
	 *
	 * @return string[]
	 */
	public static function stored_suffixes(): array {
		return array_merge( array( self::SHARED_SUFFIX ), array_values( self::SUFFIXES ) );
	}

	/** Does the cache keep a copy per device? */
	public static function split( array $cache_opts ): bool {
		return ! empty( $cache_opts['mobile_separate'] );
	}

	/**
	 * Do tablets get a copy of their own? Only on top of the phone split:
	 * `tablet_separate` means nothing while `mobile_separate` is off.
	 */
	public static function tablet_split( array $cache_opts ): bool {
		return self::split( $cache_opts ) && ! empty( $cache_opts['tablet_separate'] );
	}

	/**
	 * What a tablet's page is built as: 'desktop' (the default) or
	 * 'mobile'. Any other stored value reads as the default.
	 */
	public static function tablet_renders_as( array $cache_opts ): string {
		$value = $cache_opts['tablet_renders_as'] ?? self::DESKTOP;
		return in_array( $value, self::TABLET_RENDERS, true ) ? $value : self::DESKTOP;
	}

	/**
	 * The device copies the cache keeps under these settings, in purge
	 * order. Empty when one copy serves every device.
	 *
	 * @return string[]
	 */
	public static function active( array $cache_opts ): array {
		if ( ! self::split( $cache_opts ) ) {
			return array();
		}
		if ( self::tablet_split( $cache_opts ) ) {
			return self::all();
		}
		return array_values( array_diff( self::all(), array( self::TABLET ) ) );
	}

	/**
	 * Every device copy in the order the preloader warms them.
	 *
	 * @return string[]
	 */
	public static function warm_order(): array {
		return self::WARM_ORDER;
	}

	/**
	 * Phone or desktop, by the test core's wp_is_mobile() uses: the
	 * Sec-CH-UA-Mobile hint when the request sent one, else the user-agent.
	 *
	 * @param string|null $sec_ch_ua_mobile The hint's raw value, null when absent.
	 * @param string      $user_agent       The user-agent, as the caller cleaned it.
	 */
	public static function from_hint_or_user_agent( ?string $sec_ch_ua_mobile, string $user_agent ): string {
		if ( null !== $sec_ch_ua_mobile ) {
			return '?1' === $sec_ch_ua_mobile ? self::MOBILE : self::DESKTOP;
		}
		if ( '' === $user_agent ) {
			return self::DESKTOP;
		}
		return preg_match( self::MOBILE_UA_REGEX, $user_agent ) ? self::MOBILE : self::DESKTOP;
	}

	/**
	 * Mobile, tablet or desktop, in Cloudflare's words.
	 *
	 * Origin rules (the default): the tablet pattern first, then the
	 * Sec-CH-UA-Mobile hint when sent, then the phone tokens core's
	 * wp_is_mobile() matches. Edge rules: Cloudflare's own two patterns and
	 * nothing else, because Cloudflare keys on the user-agent alone.
	 *
	 * The drop-in carries a copy of this logic (it runs before WordPress);
	 * DevicesParityTest holds the two to the same answers.
	 *
	 * @param string      $user_agent       The raw user-agent.
	 * @param string|null $sec_ch_ua_mobile The hint's raw value, null when absent. Ignored by edge rules.
	 * @param bool        $edge_rules       Use Cloudflare's rules instead of the origin's.
	 * @return string self::MOBILE, self::TABLET or self::DESKTOP.
	 */
	public static function from_user_agent( string $user_agent, ?string $sec_ch_ua_mobile = null, bool $edge_rules = false ): string {
		if ( $edge_rules ) {
			if ( preg_match( self::EDGE_TABLET_UA_REGEX, $user_agent ) ) {
				return self::TABLET;
			}
			return preg_match( self::EDGE_MOBILE_UA_REGEX, $user_agent ) ? self::MOBILE : self::DESKTOP;
		}
		if ( self::is_tablet_user_agent( $user_agent ) ) {
			return self::TABLET;
		}
		return self::from_hint_or_user_agent( $sec_ch_ua_mobile, $user_agent );
	}

	/** Does the origin's tablet pattern match this user-agent? */
	public static function is_tablet_user_agent( string $user_agent ): bool {
		return '' !== $user_agent && (bool) preg_match( self::TABLET_UA_REGEX, $user_agent );
	}

	/**
	 * The suffix for a device class ('mobile', 'tablet' or 'desktop', as
	 * from_user_agent() returns). 'mobile' and 'desktop' map to their own copies.
	 * 'tablet' maps to the tablet copy while tablets have one, and otherwise
	 * to the copy `tablet_renders_as` names, so the page stored there is the
	 * page a tablet is built as.
	 *
	 * Returns '' for an empty or unknown value: no answer, and the caller
	 * falls back to its own detection. The split setting itself is not read
	 * here; deciding whether to key by device at all is the caller's job.
	 */
	public static function bucket_for_device( string $device, array $cache_opts ): string {
		$device = strtolower( trim( $device ) );
		if ( self::TABLET === $device ) {
			return self::tablet_split( $cache_opts )
				? self::SUFFIXES[ self::TABLET ]
				: self::SUFFIXES[ self::tablet_renders_as( $cache_opts ) ];
		}
		if ( self::MOBILE === $device || self::DESKTOP === $device ) {
			return self::SUFFIXES[ $device ];
		}
		return '';
	}

	/**
	 * What the drop-in needs to pick a device copy without WordPress: every
	 * device pattern, the flag file that turns tablets on, and the suffix of
	 * each copy.
	 *
	 * @return array{mobile_re:string,tablet_re:string,edge_tablet_re:string,edge_mobile_re:string,tablet_flag:string,suffix:array<string,string>}
	 */
	public static function dropin_rules(): array {
		return array(
			'mobile_re'      => self::MOBILE_UA_REGEX,
			'tablet_re'      => self::TABLET_UA_REGEX,
			'edge_tablet_re' => self::EDGE_TABLET_UA_REGEX,
			'edge_mobile_re' => self::EDGE_MOBILE_UA_REGEX,
			'tablet_flag'    => self::TABLET_FLAG,
			'suffix'         => self::SUFFIXES,
		);
	}

	/**
	 * Replace the drop-in's device placeholder with dropin_rules() as a PHP
	 * array literal. A source without the placeholder comes back unchanged.
	 */
	public static function bake( string $dropin_source ): string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- builds a PHP literal for the drop-in; nothing is printed.
		return str_replace( self::DROPIN_PLACEHOLDER, var_export( self::dropin_rules(), true ), $dropin_source );
	}
}
