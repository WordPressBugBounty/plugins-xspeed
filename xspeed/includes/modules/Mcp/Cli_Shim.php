<?php
/**
 * WP_CLI shim for the MCP CLI bridge.
 *
 * xSpeed's CLI command callbacks emit output via \WP_CLI::log/success/
 * warning/error. In a normal web (MCP) request the real WP_CLI class
 * isn't loaded, so those calls would fatal. This shim provides a minimal
 * global \WP_CLI whose methods forward to a swappable output buffer.
 *
 * Class definition is one-shot (PHP can't redefine a class), so the
 * global \WP_CLI delegates to Cli_Shim's current target buffer, which
 * Cli_Bridge swaps per command run. If the REAL WP_CLI is already loaded
 * (running under actual wp-cli), we do NOT define ours — the native one
 * wins and the bridge simply isn't used in that context.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

defined( 'ABSPATH' ) || exit;

final class Cli_Shim {

	/** @var Cli_Output_Buffer|null */
	private static $target = null;

	/** Point the global \WP_CLI shim at a buffer for the duration of a run. */
	public static function bind( Cli_Output_Buffer $buffer ): void {
		self::$target = $buffer;
		self::ensure_class();
	}

	public static function unbind(): void {
		self::$target = null;
	}

	/** Forwarded from the global \WP_CLI shim. */
	public static function emit( string $prefix, string $message ): void {
		if ( null !== self::$target ) {
			self::$target->push( '' === $prefix ? $message : $prefix . $message );
		}
	}

	/**
	 * Load the global \WP_CLI shim class routing to this shim — ONLY if
	 * the real one isn't present (i.e. we're not under actual wp-cli).
	 */
	private static function ensure_class(): void {
		if ( class_exists( '\\WP_CLI', false ) ) {
			return;
		}
		require_once __DIR__ . '/wp-cli-shim-class.php';
	}
}
