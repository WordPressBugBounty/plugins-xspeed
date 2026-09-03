<?php
/**
 * Settings module — a first-class CLI/MCP surface for reading and writing
 * any module's settings.
 *
 * This module owns no settings of its own; it is a *surface* over
 * Settings_Manager, the way HealthModule is a surface over Health. It exists
 * because the curated MCP tools `get_settings` / `update_settings` are gated
 * on an `xspeed settings` command existing (Mcp_Tools::catalog()'s
 * $conditional map). No such command existed, so the drop loop unset both
 * tools on every request and they never appeared in tools/list — leaving
 * `run_command` as the only way to reach settings. (#149, and the field
 * report of the same bug in #153.)
 *
 * Registering the command satisfies the existing guard rather than adding a
 * special case to it, and gives CLI + MCP `run_command` a settings surface
 * that was independently missing. Per IMPLEMENTATION.md §17, a module's
 * cli_commands() entry is what makes a feature reachable from both CLI and
 * MCP — they dispatch to these same callbacks.
 *
 * Credential safety: reads go through Settings_Manager::get_public(), so
 * secrets come back masked (#115). Writes go through Settings_Manager::update(),
 * which strips secret fields on this path as the documented backstop to the
 * MCP `configure`-scope gate (#116) — so `wp xspeed settings update` can
 * never set a credential, by design. Credentials are set from the dashboard.
 *
 * Tier: Free. The command reads the Module_Registry, so it covers Pro modules
 * too once they register, with no Pro reference here.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Settings;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;
use XSpeed\Module_Registry;
use XSpeed\Settings_Manager;

final class SettingsModule extends Module {

	public const SLUG    = 'settings';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	/**
	 * No settings of its own and no hooks to wire — this module is purely a
	 * CLI/MCP surface. Declared explicitly so the empty body reads as
	 * intentional rather than unfinished.
	 */
	public function boot(): void {
	}

	/**
	 * No settings_schema(): this module configures nothing. It deliberately
	 * declares no ui_panels() either — the dashboard already renders every
	 * module's settings through ModulePanel, so a "Settings" panel would be
	 * a confusing duplicate of the whole app.
	 */
	public function settings_schema(): array {
		return array();
	}

	/**
	 * `wp xspeed settings <list|get|update>` — the command whose absence
	 * dropped get_settings/update_settings from the MCP catalog (#149/#153).
	 *
	 * Reachable over MCP two ways: via the curated typed tools (which this
	 * command's existence restores to tools/list) and via the run_command
	 * gateway.
	 */
	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed settings',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'List modules, or read/update a module\'s settings.',
				'ai_hint'   => 'Read or write any xSpeed module\'s settings directly, and export/import the whole configuration. Use for bulk changes or replicating one site\'s setup onto another.',
				'synopsis'  => array(
					// No 'options' constraint on `action`: WP_CLI validates a
					// positional's options list against every positional it
					// receives, so `settings get nosuchmodule` failed on the
					// MODULE value with a generic "Invalid value specified for
					// positional arg" and never reached the handler. Validating
					// in cli_handler() instead keeps the error messages
					// specific ("Unknown module X — run `settings list`").
					array(
						'type'        => 'positional',
						'name'        => 'action',
						'description' => 'list, get, or update. Defaults to list.',
						'optional'    => true,
					),
					array(
						'type'        => 'positional',
						'name'        => 'module',
						'description' => 'Module slug, e.g. "minify". Required for get/update.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'values',
						'description' => 'JSON object of setting keys to new values (update only).',
						'optional'    => true,
					),
					array(
						// `assoc`, not `flag`: the handler reads this as a
						// VALUE ('json' === $assoc['format']). Declared as a
						// flag the synopsis published `[--format]`, so an agent
						// following it passed a bare --format, silently got
						// table output, and had no error to learn from. That
						// synopsis is what list_commands advertises over MCP,
						// which makes an agent the caller most likely to hit
						// it. (QA D1)
						'type'        => 'assoc',
						'name'        => 'format',
						'description' => 'Output format: table (default) or json.',
						'optional'    => true,
						'options'     => array( 'table', 'json' ),
					),
				),
			),
		);
	}

	/**
	 * CLI: `wp xspeed settings [list|get <module>|update <module> --values=<json>]`
	 *
	 * @param array<int,string>    $args  Positional arguments.
	 * @param array<string,string> $assoc Associative arguments.
	 */
	public function cli_handler( array $args, array $assoc ): void {
		$action = isset( $args[0] ) ? (string) $args[0] : 'list';
		$module = isset( $args[1] ) ? (string) $args[1] : '';
		$json   = isset( $assoc['format'] ) && 'json' === $assoc['format'];

		switch ( $action ) {
			case 'list':
				$this->cli_list( $json );
				return;

			case 'get':
				$this->cli_get( $module, $json );
				return;

			case 'update':
				$this->cli_update( $module, $assoc, $json );
				return;

			default:
				\WP_CLI::error( sprintf( 'Unknown action "%s". Expected list, get, or update.', $action ) );
		}
	}


	/**
	 * Is this module reachable from the CLI / MCP right now?
	 *
	 * Registration is not enough. Module_Registry::available() only asks
	 * whether Pro is LOADED (Tier_Registry::pro_active() checks the
	 * XSPEED_PRO_API constant), not whether it is LICENSED — so on a site
	 * with Pro installed and the licence lapsed, every Pro module was
	 * enumerable, readable and writable from here while the dashboard
	 * correctly showed it locked. (QA M2)
	 *
	 * The licence answer lives in Pro, which Free must not reference by
	 * name, so it comes through the `xspeed_module_descriptor` filter Pro's
	 * own descriptor gate uses. NOT `xspeed_pro_licensed`: Pro only ever
	 * APPLIES that one as an override and nothing listens to it, so gating
	 * on it silently passed everything — the bug this method exists to fix.
	 * With Pro absent the filter is unhooked and the default stands: a
	 * Free-only site has no Pro modules registered anyway, so nothing
	 * changes there.
	 *
	 * `license` is exempt for the same reason Pro exempts it — locking the
	 * licence module on an unlicensed site would remove the only surface
	 * that can fix the problem.
	 */
	private static function module_reachable( string $slug ): bool {
		$module = Module_Registry::available()[ $slug ] ?? null;
		if ( ! $module ) {
			return false;
		}
		if ( Module::TIER_PRO !== $module->tier() || 'license' === $slug ) {
			return true;
		}

		// Ask the SAME question the dashboard asks. `xspeed_pro_licensed` is
		// only ever APPLIED by Pro as an override hook — nothing registers it
		// — so calling it here returned the default `true` and gated nothing.
		// Pro DOES register `xspeed_module_descriptor`, and sets
		// `locked => 'license'` on every Pro entry when the licence is
		// inactive. Reusing that keeps one definition of "locked" instead of
		// a second one in Free that can drift from the panel. (QA M2)
		$entry = apply_filters(
			'xspeed_module_descriptor',
			array(
				'slug' => $slug,
				'tier' => $module->tier(),
			),
			$module
		);

		return empty( $entry['locked'] );
	}

	/**
	 * `settings list` — every AVAILABLE module, its tier and enabled state.
	 *
	 * available(), not all(): all() is registration-scoped, so on a site with
	 * Pro installed but unlicensed it enumerated all 27 Pro modules and let
	 * them be read and written. Every other tier-gated surface —
	 * Cli_Bridge::commands(), Admin::modules_payload() — filters through
	 * available(), and this one should not be the exception. (QA M2)
	 */
	private function cli_list( bool $json ): void {
		$rows = array();
		foreach ( Module_Registry::available() as $slug => $module ) {
			if ( ! self::module_reachable( $slug ) ) {
				continue;
			}
			$settings = Settings_Manager::get_public( $slug );
			$rows[]   = array(
				'slug'    => $slug,
				'tier'    => $module->tier(),
				'version' => $module->version(),
				'enabled' => ! empty( $settings['enabled'] ) ? 'yes' : 'no',
			);
		}
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return strcmp( $a['slug'], $b['slug'] );
			}
		);

		if ( $json ) {
			\WP_CLI::log( (string) wp_json_encode( $rows ) );
			return;
		}
		foreach ( $rows as $row ) {
			\WP_CLI::log( sprintf( '%-20s %-6s %-8s enabled=%s', $row['slug'], $row['tier'], $row['version'], $row['enabled'] ) );
		}
	}

	/** `settings get <module>` — schema-coerced values, secrets masked. */
	private function cli_get( string $module, bool $json ): void {
		if ( '' === $module ) {
			\WP_CLI::error( 'A module slug is required, e.g. `wp xspeed settings get minify`.' );
		}
		// available(), not get(): a Pro module on an unlicensed site must be
		// as unreachable here as it is everywhere else, and must read as
		// "unknown" rather than "locked" so probing cannot enumerate the Pro
		// slug list. (QA M2)
		if ( ! self::module_reachable( $module ) ) {
			\WP_CLI::error( sprintf( 'Unknown module "%s". Run `wp xspeed settings list` to see the registered modules.', $module ) );
		}

		// get_public(), not get(): secrets come back masked so a credential
		// never lands in a terminal, a CI log, or an MCP transcript. (#115)
		$settings = Settings_Manager::get_public( $module );

		if ( $json ) {
			\WP_CLI::log( (string) wp_json_encode( $settings ) );
			return;
		}
		foreach ( $settings as $key => $value ) {
			\WP_CLI::log( sprintf( '%-28s %s', $key, self::scalar( $value ) ) );
		}
	}

	/** `settings update <module> --values=<json>` — validated by the module schema. */
	private function cli_update( string $module, array $assoc, bool $json ): void {
		if ( '' === $module ) {
			\WP_CLI::error( 'A module slug is required, e.g. `wp xspeed settings update minify --values=\'{"enabled":true}\'`.' );
		}
		// available(), not get(): a Pro module on an unlicensed site must be
		// as unreachable here as it is everywhere else, and must read as
		// "unknown" rather than "locked" so probing cannot enumerate the Pro
		// slug list. (QA M2)
		if ( ! self::module_reachable( $module ) ) {
			\WP_CLI::error( sprintf( 'Unknown module "%s". Run `wp xspeed settings list` to see the registered modules.', $module ) );
		}
		if ( ! isset( $assoc['values'] ) || '' === $assoc['values'] ) {
			\WP_CLI::error( 'A --values=<json> object is required, e.g. --values=\'{"enabled":true}\'.' );
		}

		$values = json_decode( (string) $assoc['values'], true );
		if ( ! is_array( $values ) ) {
			\WP_CLI::error( 'The --values argument must be a JSON object, e.g. --values=\'{"enabled":true}\'.' );
		}

		// Name the refusal rather than letting the write appear to succeed:
		// Settings_Manager::update() silently strips secrets on this path
		// (the #116 backstop), so without this the user would see a green
		// success and an unchanged credential.
		$secret_fields = Settings_Manager::secret_keys_in( $module, $values );
		if ( ! empty( $secret_fields ) ) {
			\WP_CLI::error(
				sprintf(
					'Credential fields (%s) cannot be set from the CLI or MCP — they are stripped on this path by design. Set them in the xSpeed dashboard instead.',
					implode( ', ', $secret_fields )
				)
			);
		}

		// Pro licence write gate — the CLI writes through
		// Settings_Manager::update() and so never reaches
		// Module::update_settings(), where the gate lives. Without this a
		// `wp xspeed settings update <pro-module>` turns a Pro feature on with
		// no licence, exactly as the MCP handler did. (#185)
		$module_object = \XSpeed\Module_Registry::get( $module );
		if ( $module_object && $module_object->is_license_locked() ) {
			\XSpeed\Activity_Log::record(
				'license_write_refused',
				sprintf(
					/* translators: %s: module slug. */
					__( 'Refused a CLI settings write to the Pro module "%s" — no valid license.', 'xspeed' ),
					$module
				),
				\XSpeed\Activity_Log::WARN
			);
			\WP_CLI::error(
				sprintf(
					'"%s" is a Pro module and this site has no active license, so the write was refused. Nothing was changed.',
					$module
				)
			);
		}

		// Refuse rather than report success over a write that won't happen.
		// update() walks the schema, so an out-of-schema key is never written
		// and never mentioned; an in-schema key with an invalid value is
		// dropped back to the stored value just as quietly. Both used to exit
		// 0 with "Success", which an agent — or a human script — cannot tell
		// apart from a real write. (#206)
		$report = Settings_Manager::inspect_input( $module, $values );
		if ( ! empty( $report['unknown'] ) || ! empty( $report['invalid'] ) ) {
			$lines = array();
			foreach ( $report['unknown'] as $key ) {
				$line = sprintf( '  %s — not a setting of module "%s"', $key, $module );
				$hint = Settings_Manager::hint_for_unknown_key( $key );
				if ( '' !== $hint ) {
					$line .= "\n      " . $hint;
				} else {
					$near = Settings_Manager::did_you_mean( $module, $key );
					if ( ! empty( $near ) ) {
						$line .= "\n      did you mean: " . implode( ', ', $near ) . '?';
					}
				}
				$lines[] = $line;
			}
			foreach ( $report['invalid'] as $key ) {
				$lines[] = sprintf( '  %s — value rejected by the schema (wrong type, or outside the allowed range/options)', $key );
			}

			$applied = empty( $report['applied'] )
				? 'Nothing was written.'
				: sprintf( 'Nothing was written — the valid keys (%s) were not applied either, so the whole payload can be corrected and re-sent.', implode( ', ', $report['applied'] ) );

			\WP_CLI::error(
				sprintf(
					"Refused to update %s:\n%s\n\n%s",
					$module,
					implode( "\n", $lines ),
					$applied
				)
			);
		}

		$updated = Settings_Manager::update( $module, $values );

		if ( $json ) {
			\WP_CLI::log( (string) wp_json_encode( $updated ) );
			return;
		}
		\WP_CLI::success( sprintf( 'Updated %s.', $module ) );
		foreach ( array_keys( $values ) as $key ) {
			if ( array_key_exists( $key, $updated ) ) {
				\WP_CLI::log( sprintf( '%-28s %s', $key, self::scalar( $updated[ $key ] ) ) );
			}
		}
	}

	/**
	 * Render a setting value for a single line of CLI output.
	 *
	 * @param mixed $value Any schema-coerced setting value.
	 */
	private static function scalar( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $value );
		}
		return (string) $value;
	}
}
