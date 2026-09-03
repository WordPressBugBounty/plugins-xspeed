<?php
/**
 * Global \WP_CLI shim class for the MCP CLI bridge.
 *
 * Loaded ONLY when the real WP_CLI is absent (i.e. a web/MCP request, not
 * actual wp-cli). Provides the four static methods xSpeed's CLI callbacks
 * use — log/line/success/warning forward to the active output buffer;
 * error() throws a controlled signal Cli_Bridge catches. See Cli_Shim.
 *
 * This lives in the global namespace on purpose: callbacks reference
 * `\WP_CLI`. It is required conditionally by Cli_Shim::ensure_class(),
 * never on a real wp-cli run, so it can't collide with the genuine class.
 *
 * @package XSpeed
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI', false ) ) {

	/**
	 * Minimal drop-in for xSpeed's MCP CLI bridge. NOT a general WP-CLI
	 * replacement — only the methods our command callbacks call.
	 */
	class WP_CLI {

		public static function log( $message ) {
			\XSpeed\Modules\Mcp\Cli_Shim::emit( '', (string) $message );
		}

		public static function line( $message = '' ) {
			\XSpeed\Modules\Mcp\Cli_Shim::emit( '', (string) $message );
		}

		public static function success( $message ) {
			\XSpeed\Modules\Mcp\Cli_Shim::emit( 'Success: ', (string) $message );
		}

		public static function warning( $message ) {
			\XSpeed\Modules\Mcp\Cli_Shim::emit( 'Warning: ', (string) $message );
		}

		public static function error( $message ) {
			throw new \XSpeed\Modules\Mcp\Cli_Error_Signal( (string) $message );
		}
	}
}
