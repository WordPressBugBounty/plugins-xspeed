<?php
/**
 * Output buffer for the MCP CLI bridge — collects the lines a bridged
 * xSpeed CLI command emits (via the \WP_CLI shim) during a run, so the
 * bridge can return them as structured MCP output. See Cli_Shim.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

defined( 'ABSPATH' ) || exit;

final class Cli_Output_Buffer {

	/** @var string[] */
	private $lines = array();

	public function push( string $line ): void {
		$this->lines[] = $line;
	}

	/** @return string[] */
	public function lines(): array {
		return $this->lines;
	}

	public function text(): string {
		return implode( "\n", $this->lines );
	}
}
