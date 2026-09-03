<?php
/**
 * CLI_Manager — registers a module's WP-CLI commands under the
 * `wp xspeed <module> ...` namespace.
 *
 * Loaded only when WP_CLI is defined and truthy (Module_Registry guards
 * the call). Modules declare commands via Module::cli_commands().
 *
 * The full xSpeed CLI surface is enumerated in FEATURES.md → "xSpeed CLI"
 * section (24 planned commands across all modules). Each handler shares
 * its core function with the matching REST callback — no duplicated
 * validation paths.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Cli_Manager {

	public static function register_module( Module $module ): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		foreach ( $module->cli_commands() as $cmd ) {
			$name     = $cmd['name'] ?? '';
			$callback = $cmd['callback'] ?? null;
			if ( '' === $name || ! is_callable( $callback ) ) {
				continue;
			}
			$args = array();
			if ( isset( $cmd['synopsis'] ) ) {
				$args['synopsis'] = $cmd['synopsis'];
			}
			if ( isset( $cmd['shortdesc'] ) ) {
				$args['shortdesc'] = $cmd['shortdesc'];
			}
			\WP_CLI::add_command( $name, $callback, $args );
		}
	}
}
