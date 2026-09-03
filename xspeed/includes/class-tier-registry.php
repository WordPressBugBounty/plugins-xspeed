<?php
/**
 * Tier registry — single source of truth for whether a module is
 * available on the current install (Free always available; Pro available
 * only when xspeed-pro is active and reports a compatible API version).
 *
 * Pro features are loaded by the xspeed-pro plugin by hooking
 * `xspeed_register_modules` on `plugins_loaded` with priority later than
 * core (which registers Free modules at priority 10). Pro modules pass
 * through the same Module_Registry — there is no separate Pro registry.
 *
 * The `xspeed_module_tier` filter lets a site override a module's
 * declared tier (e.g., promote a Pro feature to Free for a custom build,
 * or beta-test a Pro feature without moving files). In stock installs no
 * filter fires, so the declared TIER constant wins.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Tier_Registry {

	/**
	 * Pro plugin signals it's loaded + compatible by defining this constant
	 * at its main entry point. The integer is the minimum Free-API version
	 * the Pro plugin expects. If we ever break the Module/Registry contract
	 * we bump XSPEED_API_VERSION; older Pro installs short-circuit safely.
	 */
	public const PRO_LOADED_CONSTANT = 'XSPEED_PRO_API';
	public const API_VERSION         = 1;

	/**
	 * Return the effective tier of a module — what the registry sees after
	 * the `xspeed_module_tier` filter runs. Falls back to the module's
	 * declared TIER if no filter overrides.
	 */
	public static function tier_of( Module $module ): string {
		$declared = $module->tier();

		/**
		 * Filter: xspeed_module_tier
		 *
		 * @param string $tier   Currently declared tier ('free' | 'pro').
		 * @param string $slug   Module slug.
		 * @param Module $module The module instance.
		 */
		$filtered = apply_filters( 'xspeed_module_tier', $declared, $module->slug(), $module );

		return in_array( $filtered, array( Module::TIER_FREE, Module::TIER_PRO ), true )
			? $filtered
			: $declared;
	}

	/**
	 * A module is available on the current install iff:
	 *   - Its effective tier is Free, OR
	 *   - Its effective tier is Pro AND the Pro plugin is active + compatible.
	 */
	public static function is_available( Module $module ): bool {
		$tier = self::tier_of( $module );
		if ( Module::TIER_FREE === $tier ) {
			return true;
		}
		return self::pro_active();
	}

	/**
	 * Is xspeed-pro loaded and reporting a compatible API version?
	 *
	 * Pro is expected to `define( 'XSPEED_PRO_API', N )` at bootstrap. We
	 * accept any constant whose value is >= our API_VERSION (forward
	 * compat — newer Pro on older Free is fine) and >= 1 (sanity).
	 */
	public static function pro_active(): bool {
		if ( ! defined( self::PRO_LOADED_CONSTANT ) ) {
			return false;
		}
		$pro_api = (int) constant( self::PRO_LOADED_CONSTANT );
		return $pro_api >= self::API_VERSION;
	}
}
