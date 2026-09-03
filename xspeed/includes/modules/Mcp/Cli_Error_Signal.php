<?php
/**
 * Controlled failure signal for the MCP CLI bridge.
 *
 * The \WP_CLI shim's error() throws this instead of halting the request,
 * so Cli_Bridge can catch it and return a structured error result. See
 * Cli_Shim.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

defined( 'ABSPATH' ) || exit;

/** Thrown by the shim's error() so Cli_Bridge can catch a controlled failure. */
final class Cli_Error_Signal extends \RuntimeException {}
