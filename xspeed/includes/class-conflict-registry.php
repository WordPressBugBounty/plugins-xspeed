<?php
/**
 * Conflict_Registry — the strategy matrix for coexisting (or refusing to
 * coexist) with other caching / optimization plugins.
 *
 * Source of truth: Cache_Plugin_Catalog (which plugins exist, what they do,
 * how they conflict) projected through IMPLEMENTATION.md §6.2's contract.
 * Each entry says:
 *   - which plugin we're conflicting with (its main file path),
 *   - which "kind" of plugin it is (page-cache / minify / lazy-load / etc.),
 *   - per-feature strategy (refuse | warn | allow),
 *   - a human-readable reason rendered in UI + REST errors.
 *
 * The matrix is queried by:
 *   - Onboarding Step 1 (health rows).
 *   - Dashboard health card (Phase 2.1).
 *   - REST gates (refuse → 409, warn → response includes warnings[]).
 *   - CLI `wp xspeed conflicts`.
 *   - Cache toggle handler (refuses to enable when another page cache active).
 *
 * Detection results are cached for the request lifetime. The cache is
 * invalidated on `activated_plugin` / `deactivated_plugin`.
 *
 * Modules can declare additional conflicts via Module::conflicts(); those
 * are merged into the static matrix at runtime so Pro modules can extend
 * the registry without modifying Free code.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Conflict_Registry {

	public const STRATEGY_REFUSE = 'refuse';
	public const STRATEGY_WARN   = 'warn';
	public const STRATEGY_ALLOW  = 'allow';

	/**
	 * @var array|null Memoized detection result for this request.
	 */
	private static $cache = null;

	/**
	 * Per-feature strategy matrix, projected from Cache_Plugin_Catalog.
	 *
	 * The catalog is the single description of every plugin we know about;
	 * this projection keeps the shape older callers expect — plugin file =>
	 * label / kind / strategy. `kind` is derived from the catalog's
	 * capabilities (see kind_for()) rather than stored twice.
	 *
	 * Feature keys are namespaced `<module-slug>.<sub-feature>` or just
	 * `<module-slug>` for whole-module conflicts. Modules extend the matrix
	 * through the `xspeed_conflict_matrix` filter, and a Pro module can
	 * describe a whole new plugin through `xspeed_cache_plugin_catalog`.
	 */
	private static function matrix(): array {
		$out = array();
		foreach ( Cache_Plugin_Catalog::all() as $file => $entry ) {
			if ( empty( $entry['strategy'] ) ) {
				// Catalogued for detection only (e.g. an object-cache plugin);
				// it conflicts with nothing, so it is not a matrix row.
				continue;
			}
			$out[ $file ] = array(
				'label'    => $entry['label'],
				'kind'     => self::kind_for( $entry['capabilities'] ),
				'strategy' => $entry['strategy'],
			);
		}
		return $out;
	}

	/**
	 * Collapse a capability list into the single legacy `kind` label: the one
	 * capability when there is only one, 'mixed' when there are several.
	 *
	 * @param string[] $capabilities
	 */
	private static function kind_for( array $capabilities ): string {
		if ( empty( $capabilities ) ) {
			return 'mixed';
		}
		return count( $capabilities ) === 1 ? (string) $capabilities[0] : 'mixed';
	}

	/**
	 * Active conflicts on this site. Returns one entry per active
	 * conflicting plugin: [
	 *   'plugin'   => 'wp-rocket/wp-rocket.php',
	 *   'label'    => 'WP Rocket',
	 *   'kind'     => 'mixed',
	 *   'strategy' => [ feature => strategy ],
	 * ]
	 *
	 * @return array[]
	 */
	public static function detected(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$matrix = apply_filters( 'xspeed_conflict_matrix', self::matrix() );
		$out    = array();
		foreach ( $matrix as $plugin => $spec ) {
			if ( is_plugin_active( $plugin ) ) {
				$out[] = array(
					'plugin'   => $plugin,
					'label'    => $spec['label'],
					'kind'     => $spec['kind'],
					'strategy' => $spec['strategy'],
				);
			}
		}
		self::$cache = $out;
		return $out;
	}

	/**
	 * Strongest strategy across all detected conflicts for a given feature
	 * key. Returns 'refuse' > 'warn' > 'allow' (refuse wins if any
	 * conflict refuses; warn wins if any warns but none refuse).
	 */
	public static function strategy_for( string $feature_key ): string {
		$strongest = self::STRATEGY_ALLOW;
		foreach ( self::detected() as $conflict ) {
			$s = $conflict['strategy'][ $feature_key ] ?? self::STRATEGY_ALLOW;
			if ( self::STRATEGY_REFUSE === $s ) {
				return self::STRATEGY_REFUSE;
			}
			if ( self::STRATEGY_WARN === $s && self::STRATEGY_ALLOW === $strongest ) {
				$strongest = self::STRATEGY_WARN;
			}
		}
		return $strongest;
	}

	/**
	 * Human-readable reason a feature is blocked by a refuse-strategy
	 * conflict, or null if not blocked. Used by Rest_Manager (409) and UI
	 * tooltips.
	 *
	 * The optional $module_slug is currently informational only — the
	 * strategy lookup uses $feature_key alone. Reserved for future
	 * module-specific overrides.
	 */
	public static function why_blocked( string $module_slug, string $feature_key ): ?string {
		foreach ( self::detected() as $conflict ) {
			if ( ( $conflict['strategy'][ $feature_key ] ?? null ) === self::STRATEGY_REFUSE ) {
				return sprintf(
					/* translators: %s: conflicting plugin name */
					__( '%s is active and handles the same feature. Deactivate it before enabling this in xSpeed.', 'xspeed' ),
					$conflict['label']
				);
			}
		}
		return null;
	}

	/**
	 * All warnings for a feature (one string per warning-strategy conflict).
	 * Returned in the response body of warn-gated REST endpoints.
	 *
	 * @return string[]
	 */
	public static function warnings_for( string $feature_key ): array {
		$out = array();
		foreach ( self::detected() as $conflict ) {
			if ( ( $conflict['strategy'][ $feature_key ] ?? null ) === self::STRATEGY_WARN ) {
				$out[] = sprintf(
					/* translators: %s: conflicting plugin name */
					__( '%s is active and may interfere. Test carefully.', 'xspeed' ),
					$conflict['label']
				);
			}
		}
		return $out;
	}

	/**
	 * Invalidate the per-request cache. Called on activated_plugin /
	 * deactivated_plugin so the next read reflects the new state.
	 */
	public static function invalidate(): void {
		self::$cache = null;
		// The matrix is a projection of the catalog, and the catalog memoizes
		// its own filter pass — flushing one without the other would serve a
		// stale projection of fresh data.
		Cache_Plugin_Catalog::invalidate();
	}

	/**
	 * Bootstrap hooks. Call once from Plugin::init().
	 */
	public static function boot(): void {
		add_action( 'activated_plugin', array( __CLASS__, 'invalidate' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'invalidate' ) );
	}
}
