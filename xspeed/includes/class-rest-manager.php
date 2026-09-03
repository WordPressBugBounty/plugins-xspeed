<?php
/**
 * Rest_Manager — registers a module's REST routes under
 * `/xspeed/v1/<module-slug>/...` and wraps every callback with:
 *   - A final capability check (defaults to `manage_options`).
 *   - A tier gate that returns 404 if the module is unavailable on this
 *     install (Pro module without active Pro plugin → 404, not 403, so
 *     the route looks like it doesn't exist).
 *   - A conflict gate (refuse strategy → 409 with conflict details).
 *
 * Modules declare routes via Module::rest_routes(). They don't repeat the
 * cap check / tier gate / conflict gate — Rest_Manager always enforces.
 *
 * The pre-existing /xspeed/v1/status, /settings, /cache/purge,
 * /cache/toggle, /onboarding/* routes (registered by Rest_Api and
 * Onboarding) continue to work alongside per-module routes. They'll move
 * onto the module pattern when those features are refactored.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Rest_Manager {

	public const NAMESPACE_V1 = 'xspeed/v1';

	/**
	 * Register every route a module declared, prefixed with its slug.
	 */
	public static function register_module( Module $module ): void {
		// rest_routes() is resolved INSIDE the callback, not here.
		//
		// This method runs at plugins_loaded, before `init`. Calling
		// rest_routes() there makes a module build its settings schema, and
		// those schemas carry __() labels — so WordPress emitted a
		// _load_textdomain_just_in_time notice for every module, on every
		// request including the front end (135 per page load with WP_DEBUG on).
		// The eager call existed only to skip add_action() for modules with no
		// routes; deferring costs one no-op hook each and moves all translation
		// work to where it belongs. (QA, 9 Aug 2026)
		add_action(
			'rest_api_init',
			static function () use ( $module ) {
				$routes = $module->rest_routes();
				if ( empty( $routes ) ) {
					return;
				}
				$slug = $module->slug();
				foreach ( $routes as $route ) {
					$path = '/' . trim( $slug, '/' ) . '/' . ltrim( $route['path'] ?? '', '/' );
					$path = rtrim( $path, '/' );

					$args = array(
						'methods'             => $route['methods'] ?? 'GET',
						'callback'            => self::wrap_callback( $module, $route ),
						'permission_callback' => self::wrap_permission( $module, $route ),
					);
					if ( isset( $route['args'] ) ) {
						$args['args'] = $route['args'];
					}

					register_rest_route( self::NAMESPACE_V1, $path, $args );
				}
			}
		);
	}

	/**
	 * Wrap a module's callback with the tier + conflict gates.
	 */
	private static function wrap_callback( Module $module, array $route ): callable {
		$callback = $route['callback'] ?? null;
		$feature  = $route['feature'] ?? null; // optional sub-feature key for conflict resolution.

		return static function ( \WP_REST_Request $request ) use ( $module, $callback, $feature ) {
			// Tier gate — Pro route without active Pro plugin looks like it doesn't exist.
			if ( ! Tier_Registry::is_available( $module ) ) {
				return new \WP_Error(
					'rest_no_route',
					__( 'No route was found matching the URL and request method.', 'xspeed' ),
					array( 'status' => 404 )
				);
			}

			// Conflict gate — refuse-strategy → 409.
			if ( $feature ) {
				$reason = Conflict_Registry::why_blocked( $module->slug(), $feature );
				if ( $reason ) {
					return new \WP_Error(
						'xspeed_conflict_refused',
						$reason,
						array( 'status' => 409 )
					);
				}
			}

			if ( ! is_callable( $callback ) ) {
				return new \WP_Error( 'xspeed_no_callback', 'Module REST callback is not callable.', array( 'status' => 500 ) );
			}

			return self::run_isolated( $callback, $request, $module->slug() );
		};
	}

	/**
	 * Backstop for every module REST callback. Containment, not a substitute
	 * for per-query guards: callbacks should still avoid failing queries.
	 *
	 * Two things leak non-JSON into a REST body and break the client's
	 * JSON.parse ("Unexpected token '<'"):
	 *   1. $wpdb echoes "WordPress database error: …" as an HTML <div> the
	 *      instant a query fails, when display-errors is on (common on the
	 *      hosts we ship to). That HTML is flushed BEFORE our handler
	 *      returns, so a try/catch around the return value can't catch it.
	 *      We suppress $wpdb's echo for the duration of the call (errors are
	 *      still logged) and capture any other stray output via an output
	 *      buffer, discarding it so only our JSON reaches the client.
	 *   2. A thrown Throwable would surface as a fatal/HTML error page. We
	 *      convert it to a clean 500 WP_Error.
	 *
	 * $wpdb's prior show-errors state and the buffer are always restored in
	 * finally, so global state is untouched after the call.
	 */
	private static function run_isolated( callable $callback, \WP_REST_Request $request, string $slug ) {
		global $wpdb;

		$prev_show_errors = null;
		if ( $wpdb instanceof \wpdb ) {
			// hide_errors() returns the previous flag so we can restore it.
			$prev_show_errors = $wpdb->hide_errors();
		}

		ob_start();
		try {
			$result = call_user_func( $callback, $request );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( '[xspeed] REST callback for "%s" threw: %s', $slug, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated behind WP_DEBUG.
			}
			$result = new \WP_Error(
				'xspeed_rest_exception',
				__( 'The request could not be completed due to a server error.', 'xspeed' ),
				array( 'status' => 500 )
			);
		} finally {
			// Discard anything the callback (or $wpdb) echoed — DB-error
			// HTML, notices, debug output — so the response body is JSON only.
			$stray = ob_get_clean();
			if ( '' !== $stray && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( '[xspeed] discarded %d bytes of stray REST output from "%s"', strlen( $stray ), $slug ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated behind WP_DEBUG.
			}
			if ( $wpdb instanceof \wpdb && false !== $prev_show_errors && null !== $prev_show_errors ) {
				$wpdb->show_errors();
			}
		}

		return $result;
	}

	/**
	 * Wrap permission_callback with the always-on cap check. A module may
	 * declare its own permission_callback for an extra-strict gate; both
	 * must pass.
	 *
	 * A route may opt out of the capability check with
	 * `'allow_unauthenticated' => true`. That is ONLY for endpoints designed to
	 * be called by anonymous frontend visitors — the RUM beacon is the reason
	 * this exists: it collects Core Web Vitals from real visitors, who by
	 * definition are not logged in, so the default `manage_options` gate
	 * rejected every sample with a 401 and the feature could never record
	 * anything. (FBS-84070)
	 *
	 * Opting out drops ONLY the capability check. A route-declared
	 * `permission_callback` still runs and still has to pass, so a module can
	 * keep its own validation (nonce, rate limit, payload shape) on top.
	 */
	private static function wrap_permission( Module $module, array $route ): callable {
		$declared   = $route['permission_callback'] ?? null;
		$capability = $route['capability'] ?? 'manage_options';
		$public     = ! empty( $route['allow_unauthenticated'] );

		return static function ( \WP_REST_Request $request ) use ( $declared, $capability, $public ) {
			if ( ! $public && ! current_user_can( $capability ) ) {
				return false;
			}
			if ( is_callable( $declared ) ) {
				$result = call_user_func( $declared, $request );
				if ( true !== $result ) {
					return $result;
				}
			}
			return true;
		};
	}
}
