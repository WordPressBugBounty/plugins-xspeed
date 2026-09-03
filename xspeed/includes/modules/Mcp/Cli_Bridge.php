<?php
/**
 * CLI bridge — run any registered xSpeed WP-CLI command from a web
 * (MCP) request, giving MCP 100% parity with the CLI surface through a
 * single tool instead of ~50 hand-written ones.
 *
 * Every module declares its commands via Module::cli_commands()
 * ({ name, callback, shortdesc, synopsis }). Those callbacks were
 * written for WP-CLI: they emit output with \WP_CLI::log/success/error/
 * warning and return void. In an MCP request WP_CLI isn't defined, so we
 * can't just call a callback and read a return value.
 *
 * This bridge:
 *   1. Builds a name → command map from every available module.
 *   2. Installs a lightweight \WP_CLI shim (only when the real WP_CLI is
 *      absent) that BUFFERS log/success/warning and THROWS on error
 *      instead of printing/halting.
 *   3. Invokes the matching callback with parsed (args, assoc), captures
 *      the buffered lines, and returns them as structured output.
 *
 * Because dispatch goes through the same cli_commands() callbacks the
 * CLI uses, MCP coverage can never drift from the CLI — a new command is
 * instantly reachable from both.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

use XSpeed\Module_Registry;

defined( 'ABSPATH' ) || exit;

final class Cli_Bridge {

	/**
	 * Map of full command name (e.g. "xspeed cloudflare purge") to its
	 * descriptor. Built once per request from every available module.
	 *
	 * @return array<string, array{callback:callable, shortdesc:string, synopsis:array, module:string}>
	 */
	public static function commands(): array {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}
		$map = array();
		foreach ( Module_Registry::available() as $module ) {
			foreach ( (array) $module->cli_commands() as $cmd ) {
				$name     = isset( $cmd['name'] ) ? (string) $cmd['name'] : '';
				$callback = $cmd['callback'] ?? null;
				if ( '' === $name || ! is_callable( $callback ) ) {
					continue;
				}
				$map[ $name ] = array(
					'callback'  => $callback,
					'shortdesc' => isset( $cmd['shortdesc'] ) ? (string) $cmd['shortdesc'] : '',
					// Optional AI-facing description. `shortdesc` is CLI help
					// text, written for someone who has ALREADY decided to run
					// the command — "Show GZIP status (server type, active,
					// mode)". A model reading tools/list has not decided yet
					// and needs the opposite: when to reach for this, and what
					// question it answers. Same string cannot serve both, and
					// rewriting shortdesc would degrade `--help`. (#184)
					'ai_hint'   => isset( $cmd['ai_hint'] ) ? (string) $cmd['ai_hint'] : '',
					'synopsis'  => isset( $cmd['synopsis'] ) && is_array( $cmd['synopsis'] ) ? $cmd['synopsis'] : array(),
					'module'    => $module->slug(),
				);
			}
		}
		ksort( $map );
		return $map;
	}

	/**
	 * A catalog of the available commands for discovery (the run_command
	 * tool advertises these so the AI knows what it can call).
	 *
	 * @return array<int, array{command:string, description:string, module:string, options:string[]}>
	 */
	public static function catalog(): array {
		$out = array();
		foreach ( self::commands() as $name => $spec ) {
			$options = array();
			foreach ( $spec['synopsis'] as $arg ) {
				if ( isset( $arg['name'] ) ) {
					$type      = $arg['type'] ?? 'assoc';
					$options[] = ( 'positional' === $type ? '<' . $arg['name'] . '>' : '--' . $arg['name'] );
				}
			}
			$out[] = array(
				'command'     => $name,
				'description' => $spec['shortdesc'],
				'module'      => $spec['module'],
				'options'     => $options,
			);
		}
		return $out;
	}

	/**
	 * Run a command by name.
	 *
	 * @param string $command Full command name, with or without the
	 *                        leading "xspeed " (e.g. "cloudflare purge"
	 *                        or "xspeed cloudflare purge").
	 * @param array  $args    Positional args.
	 * @param array  $assoc   Named options / flags (e.g. ['url' => '…']).
	 * @return array{command:string, ok:bool, output:string, lines:string[], error?:string}|\WP_Error
	 */
	public static function run( string $command, array $args = array(), array $assoc = array() ) {
		$input    = self::normalize( $command );
		$commands = self::commands();

		// A registered command name may be a prefix (e.g. "xspeed db") with
		// the subcommand passed as a positional arg ("scan"). Resolve to the
		// LONGEST registered name that prefixes the input, and fold any
		// trailing words into the leading positional args.
		list( $name, $extra ) = self::resolve( $input, $commands );

		if ( '' === $name ) {
			return new \WP_Error(
				'xspeed_mcp_unknown_command',
				sprintf(
					/* translators: %s: command name. */
					__( 'Unknown command: %s. Call list_commands to see what is available.', 'xspeed' ),
					$input
				),
				array( 'status' => 404 )
			);
		}

		// Trailing words from the command string come before explicit args.
		$args = array_merge( $extra, array_values( $args ) );

		$buffer = new Cli_Output_Buffer();
		Cli_Shim::bind( $buffer );

		$ok    = true;
		$error = '';
		try {
			call_user_func( $commands[ $name ]['callback'], $args, $assoc );
		} catch ( Cli_Error_Signal $e ) {
			// \WP_CLI::error() was called — a controlled failure, not a fatal.
			$ok    = false;
			$error = $e->getMessage();
		} catch ( \Throwable $e ) {
			$ok    = false;
			$error = $e->getMessage();
		} finally {
			Cli_Shim::unbind();
		}

		$result = array(
			'command' => $name,
			'ok'      => $ok,
			'output'  => $buffer->text(),
			'lines'   => $buffer->lines(),
		);
		if ( '' !== $error ) {
			$result['error'] = $error;
		}
		return $result;
	}

	/**
	 * Resolve any (command, args) pair to the canonical command name plus
	 * its leading action, using the SAME resolution `run()` performs.
	 *
	 * Callers reach one action by many spellings — `("db", ["clean"])`,
	 * `("database clean")`, `("xspeed  db", ["clean", "--types=x"])` — and a
	 * guard that compares raw strings only stops the spelling it was written
	 * against. Anything deciding whether a call is destructive must classify
	 * it here, not parse the caller's input itself.
	 *
	 * @param string   $command Raw command string, any accepted spelling.
	 * @param string[] $args    Positional args, if any.
	 * @return array{name:string,action:string} name is '' when unresolved.
	 */
	public static function classify( string $command, array $args = array() ): array {
		$input = self::normalize( $command );
		if ( '' === $input ) {
			return array(
				'name'   => '',
				'action' => '',
			);
		}

		list( $name, $extra ) = self::resolve( $input, self::commands() );

		/*
		 * Fall back to a structural split when the registry cannot resolve the
		 * input — an unbooted module, a command that is not registered on this
		 * install, or a bare unit-test context all leave commands() empty.
		 *
		 * Callers that ask "is this destructive?" must never be told "no"
		 * merely because the registry was unavailable: that turns a missing
		 * module into a disarmed confirmation. Splitting "xspeed db clean"
		 * into name "xspeed db" + action "clean" costs nothing when the
		 * command does not exist (run() rejects it as unknown a moment later)
		 * and keeps the guard closed when it does.
		 */
		if ( '' === $name ) {
			$parts = explode( ' ', $input );
			$name  = implode( ' ', array_slice( $parts, 0, 2 ) );
			$extra = array_slice( $parts, 2 );
		}

		// Trailing words from the command string come before explicit args —
		// identical to run(), so the action seen here is the action that runs.
		$merged = array_merge( $extra, array_values( $args ) );
		$action = '';
		foreach ( $merged as $candidate ) {
			if ( is_scalar( $candidate ) && '' !== trim( (string) $candidate ) ) {
				$action = strtolower( trim( (string) $candidate ) );
				break;
			}
		}

		return array(
			'name'   => $name,
			'action' => $action,
		);
	}

	/**
	 * Normalize a command name: trim, collapse whitespace, and ensure the
	 * "xspeed " namespace prefix so callers can pass either form.
	 */
	private static function normalize( string $command ): string {
		$command = trim( preg_replace( '/\s+/', ' ', $command ) ?? '' );
		if ( '' === $command ) {
			return '';
		}
		if ( 0 !== strpos( $command, 'xspeed ' ) && 'xspeed' !== $command ) {
			$command = 'xspeed ' . $command;
		}
		return $command;
	}

	/**
	 * Resolve a normalized input string to the longest registered command
	 * name that prefixes it, returning [name, trailing-words-as-args].
	 * Trailing words become leading positional args (e.g. "xspeed db scan"
	 * → name "xspeed db", args ["scan"]).
	 *
	 * @param string $input    Normalized command string.
	 * @param array  $commands Command map.
	 * @return array{0:string,1:string[]} [name (''=unresolved), extra args]
	 */
	private static function resolve( string $input, array $commands ): array {
		// Exact match wins immediately.
		if ( isset( $commands[ $input ] ) ) {
			return array( $input, array() );
		}
		$parts = explode( ' ', $input );
		// Try progressively shorter prefixes; longest match first.
		for ( $i = count( $parts ); $i >= 1; $i-- ) {
			$candidate = implode( ' ', array_slice( $parts, 0, $i ) );
			if ( isset( $commands[ $candidate ] ) ) {
				return array( $candidate, array_slice( $parts, $i ) );
			}
		}
		return array( '', array() );
	}
}
