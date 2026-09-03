<?php
/**
 * Deep_Link — build the dashboard hash a notice or recommendation CTA
 * points at (issue #49).
 *
 * The contract, shared with the React shell (src/components/deepLink.ts):
 *
 *   #cache                                     panel
 *   #health/pagespeed                          panel + tab
 *   #cache?focus=cache:cache_expiry            panel + scroll/blink control
 *   #cache?focus=cache:cache_expiry&suggest=24 …and offer that value
 *
 * It exists so no caller hand-assembles the string. A CTA that links to a
 * panel and leaves the user hunting for the setting it just described is
 * the exact failure this replaces, and a typo'd hash fails silently —
 * the panel opens, nothing focuses, and nobody notices for a release.
 *
 * Pure: no WordPress calls, so the format is unit-testable on its own.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Deep_Link {

	/**
	 * Build a dashboard hash.
	 *
	 * @param string      $module  Module slug — which panel opens.
	 * @param string|null $field   Field key to focus, within $focus_module.
	 * @param mixed       $suggest Recommended value, or null for none.
	 * @param string      $subtab  Tab within a host page.
	 * @param string|null $focus_module Module owning $field, when it isn't
	 *                                  $module (a host page can render
	 *                                  fields from several modules).
	 */
	public static function hash( string $module, ?string $field = null, $suggest = null, string $subtab = '', ?string $focus_module = null ): string {
		$module = trim( $module );
		if ( '' === $module ) {
			return '#';
		}

		$hash = '#' . $module;
		if ( '' !== $subtab ) {
			$hash .= '/' . $subtab;
		}

		$params = array();
		if ( null !== $field && '' !== $field ) {
			$params[] = 'focus=' . rawurlencode( ( $focus_module ?? $module ) . ':' . $field );
		}
		if ( null !== $suggest && '' !== $suggest ) {
			$params[] = 'suggest=' . rawurlencode( self::stringify( $suggest ) );
		}
		if ( ! empty( $params ) ) {
			$hash .= '?' . implode( '&', $params );
		}

		return $hash;
	}

	/**
	 * The action array a notice or recommendation carries to the UI.
	 *
	 * Structured rather than a pre-built hash so the React side owns the
	 * final string — one format, one builder per language, and no chance
	 * of a half-encoded hash arriving from PHP.
	 *
	 * @param mixed $suggest Recommended value, or null.
	 * @return array<string,string>
	 */
	public static function action( string $label, string $module, ?string $field = null, $suggest = null, string $subtab = '', ?string $focus_module = null ): array {
		$action = array(
			'label'  => $label,
			'module' => $module,
		);
		if ( '' !== $subtab ) {
			$action['subtab'] = $subtab;
		}
		if ( null !== $field && '' !== $field ) {
			$action['focus'] = ( $focus_module ?? $module ) . ':' . $field;
		}
		if ( null !== $suggest && '' !== $suggest ) {
			$action['suggest'] = self::stringify( $suggest );
		}
		return $action;
	}

	/**
	 * Render a value the way the link carries it.
	 *
	 * Booleans become 1/0 rather than PHP's "" for false — an empty string
	 * would be dropped as "no suggestion", turning "we recommend turning
	 * this off" into no recommendation at all.
	 *
	 * @param mixed $value Any scalar or list.
	 */
	private static function stringify( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_array( $value ) ) {
			return implode( ',', array_map( 'strval', $value ) );
		}
		return (string) $value;
	}
}
