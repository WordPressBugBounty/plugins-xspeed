<?php
/**
 * Module registry — discovers, boots, and indexes all Modules (Free + Pro).
 *
 * Lifecycle:
 *   plugins_loaded(10)  → Free modules register themselves via
 *                          xspeed_register_modules action
 *   plugins_loaded(20)  → Pro modules register (xspeed-pro hooks later)
 *   plugins_loaded(30)  → Registry resolves dependencies, runs migrations,
 *                          calls boot() on each available module in topo order
 *
 * No call site references concrete module classes directly. Inter-module
 * lookups go through Module_Registry::get($slug). This is the architectural
 * constraint that makes Pro↔Free portability cheap (see IMPLEMENTATION.md
 * §1.3).
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Module_Registry {

	/**
	 * @var array<string,Module>
	 */
	private static $modules = array();

	/**
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register a Module instance. Idempotent on slug — re-registration is
	 * a programming error and triggers a doing_it_wrong notice.
	 */
	public static function register( Module $module ): void {
		$slug = $module->slug();
		if ( '' === $slug ) {
			_doing_it_wrong( __METHOD__, esc_html__( 'Module is missing a SLUG constant.', 'xspeed' ), 'xspeed 1.1.0' );
			return;
		}
		if ( isset( self::$modules[ $slug ] ) ) {
			_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Module "%s" already registered.', $slug ) ), 'xspeed 1.1.0' );
			return;
		}
		self::$modules[ $slug ] = $module;
	}

	public static function get( string $slug ): ?Module {
		return self::$modules[ $slug ] ?? null;
	}

	public static function has( string $slug ): bool {
		return isset( self::$modules[ $slug ] );
	}

	/**
	 * Is the module registered AND available on this install (tier check)?
	 * Anything answering false is treated as "doesn't exist" by REST + UI.
	 */
	public static function is_available( string $slug ): bool {
		$m = self::get( $slug );
		return $m && Tier_Registry::is_available( $m );
	}

	/**
	 * @return array<string,Module>
	 */
	public static function all(): array {
		return self::$modules;
	}

	/**
	 * @return array<string,Module>
	 */
	public static function available(): array {
		return array_filter(
			self::$modules,
			static function ( Module $m ) {
				return Tier_Registry::is_available( $m );
			}
		);
	}

	/**
	 * @param string $tier Module::TIER_FREE or Module::TIER_PRO
	 * @return array<string,Module>
	 */
	public static function list_by_tier( string $tier ): array {
		return array_filter(
			self::$modules,
			static function ( Module $m ) use ( $tier ) {
				return Tier_Registry::tier_of( $m ) === $tier;
			}
		);
	}

	/**
	 * Boot all available modules in dependency order. Called once during
	 * plugins_loaded. Calling twice is a no-op (guarded).
	 *
	 * Modules whose declared dependencies are missing or unavailable are
	 * SKIPPED with a debug-log notice — they never boot, REST callers see
	 * 404. We do not crash the site over a missing dep.
	 */
	public static function boot_all(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		$available = self::available();
		$ordered   = self::topological_sort( $available );

		foreach ( $ordered as $module ) {
			$ok = true;
			foreach ( $module->dependencies() as $dep_slug ) {
				if ( ! self::is_available( $dep_slug ) ) {
					self::log( sprintf( 'Module "%s" skipped: missing dependency "%s".', $module->slug(), $dep_slug ) );
					$ok = false;
					break;
				}
			}
			if ( ! $ok ) {
				continue;
			}

			// Run schema migrations for the stored version → current version.
			Settings_Manager::run_migrations( $module );

			$module->boot();

			// Register REST routes + CLI commands declared by the module.
			Rest_Manager::register_module( $module );
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				Cli_Manager::register_module( $module );
			}
		}
	}

	/**
	 * Activation hook propagator — calls activate() on every registered
	 * module. Plugin::activate() fills the registry first via
	 * Plugin::ensure_modules_registered(); plugins_loaded has already fired
	 * by the time activate_plugin() includes the plugin, so nothing else
	 * would. Pro modules run their own activation via the xspeed-pro main
	 * file.
	 */
	public static function activate_all(): void {
		foreach ( self::$modules as $module ) {
			if ( Tier_Registry::is_available( $module ) ) {
				$module->activate();
			}
		}
	}

	public static function deactivate_all(): void {
		foreach ( self::$modules as $module ) {
			if ( Tier_Registry::is_available( $module ) ) {
				$module->deactivate();
			}
		}
	}

	/**
	 * Kahn's algorithm — deterministic topological sort. Modules with no
	 * remaining deps go first; cycles produce a doing_it_wrong notice and
	 * are returned in arbitrary order so the site still boots.
	 *
	 * @param array<string,Module> $modules
	 * @return Module[]
	 */
	private static function topological_sort( array $modules ): array {
		$in_degree = array();
		$adj       = array();

		foreach ( $modules as $slug => $module ) {
			$in_degree[ $slug ] = 0;
			$adj[ $slug ]       = array();
		}
		foreach ( $modules as $slug => $module ) {
			foreach ( $module->dependencies() as $dep ) {
				if ( ! isset( $modules[ $dep ] ) ) {
					continue; // missing-dep modules are caught in boot_all().
				}
				$adj[ $dep ][]        = $slug;
				$in_degree[ $slug ]   = ( $in_degree[ $slug ] ?? 0 ) + 1;
			}
		}

		$queue  = array();
		foreach ( $in_degree as $slug => $deg ) {
			if ( 0 === $deg ) {
				$queue[] = $slug;
			}
		}

		$ordered = array();
		while ( $queue ) {
			$slug      = array_shift( $queue );
			$ordered[] = $modules[ $slug ];
			foreach ( $adj[ $slug ] as $next ) {
				if ( --$in_degree[ $next ] === 0 ) {
					$queue[] = $next;
				}
			}
		}

		if ( count( $ordered ) !== count( $modules ) ) {
			_doing_it_wrong( __METHOD__, esc_html__( 'Circular module dependency detected; boot order is undefined for the offending modules.', 'xspeed' ), 'xspeed 1.1.0' );
			// Append any modules left out (in cycle) so they still get a chance.
			foreach ( $modules as $slug => $module ) {
				if ( ! in_array( $module, $ordered, true ) ) {
					$ordered[] = $module;
				}
			}
		}

		return $ordered;
	}

	private static function log( string $msg ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[xspeed] ' . $msg );
		}
	}
}
