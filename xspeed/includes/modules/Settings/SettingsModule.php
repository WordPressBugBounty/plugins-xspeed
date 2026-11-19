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

	public function ui_metadata(): array {
		return array_merge( parent::ui_metadata(), array( 'group' => 'settings' ) );
	}

	/**
	 * `POST /xspeed/v1/settings/modules`: write a settings patch, several
	 * modules in one request.
	 *
	 * The module has no settings schema, so the default GET/POST routes are
	 * not registered, and this does not call parent::rest_routes(). No
	 * `feature` key: Rest_Manager's conflict gate would check this module, not
	 * the ones the patch writes, so save() checks each field the patch turns
	 * on (the `conflict` check).
	 *
	 * @return array[]
	 */
	public function rest_routes(): array {
		return array(
			array(
				'path'     => '/modules',
				'methods'  => 'POST',
				'callback' => array( $this, 'rest_update_patch' ),
			),
		);
	}

	/**
	 * Write a settings patch from REST.
	 *
	 * Each module runs the checks its own `POST /xspeed/v1/<slug>` save runs
	 * (licence, a module another has taken over from, wp-config constant, a
	 * field another setting holds, rejected values), after a check that the
	 * module exists. Unlike that route, an unknown key is refused too: the
	 * per-module route drops unknown keys because the dashboard panels post
	 * their whole settings object back, and nothing posts a patch that way.
	 * So is turning on a field whose feature an active plugin refuses: that
	 * route's gate checks the feature `<slug>`, which no catalog entry uses.
	 * Every module is checked before anything is written.
	 *
	 * @return \WP_REST_Response|\WP_Error|array<string,mixed>
	 */
	public function rest_update_patch( \WP_REST_Request $request ) {
		$patch = $request->get_json_params();
		if ( ! Settings_Manager::is_patch( $patch ) ) {
			return new \WP_Error(
				'xspeed_bad_patch',
				__( 'The request body must be an object of module slugs, each holding an object of setting keys.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}

		$result = Settings_Manager::save(
			$patch,
			array(
				'mode'        => 'strict',
				'checks'      => array( 'reachable', 'conflict', 'license', 'blocked', 'constant', 'managed', 'inspect', 'module' ),
				'refuse'      => array( 'unknown', 'invalid' ),
				'keep_echoes' => true,
				'write'       => 'module',
			)
		);

		if ( ! $result['ok'] ) {
			$parts   = array();
			$modules = array();
			foreach ( $result['modules'] as $slug => $entry ) {
				if ( $entry['ok'] ) {
					continue;
				}
				$error            = self::patch_refusal( (string) $slug, $entry['code'], $entry['data'] );
				// The batch message already opens with "Nothing was saved.", so a
				// module's own copy of it would say it twice (QA on 1.4.9).
				$parts[]          = $slug . ': ' . preg_replace( '/^Nothing was saved\.\s*/', '', $error->get_error_message() );
				$modules[ $slug ] = array(
					'code'    => $error->get_error_code(),
					'message' => $error->get_error_message(),
					'data'    => $error->get_error_data(),
				);
			}
			return new \WP_Error(
				'xspeed_settings_refused',
				sprintf(
					/* translators: %s: each refused module with its reason. */
					__( 'Nothing was saved. %s', 'xspeed' ),
					implode( ' | ', $parts )
				),
				array(
					'status'  => 400,
					'modules' => $modules,
				)
			);
		}

		foreach ( array_keys( $result['modules'] ) as $slug ) {
			/**
			 * Run module-owned REST save effects after the whole patch is written.
			 *
			 * @param string $slug Module whose settings were saved.
			 */
			do_action( 'xspeed_rest_settings_patch_saved', (string) $slug );
		}

		return rest_ensure_response(
			array(
				'modules' => array_map( static fn( array $entry ): array => $entry['settings'], $result['modules'] ),
			)
		);
	}

	/**
	 * The error for one module of a REST settings patch. The module's own
	 * REST route words licence, constant and rejected-value refusals, so they
	 * read the same as a single-module save. A conflict reads as
	 * Rest_Manager's gate words it.
	 *
	 * @param array<string,mixed> $data save() refusal data.
	 */
	private static function patch_refusal( string $slug, string $code, array $data ): \WP_Error {
		if ( 'unknown_module' === $code ) {
			return new \WP_Error(
				'xspeed_unknown_module',
				/* translators: %s: module slug. */
				sprintf( __( 'Unknown module "%s".', 'xspeed' ), $slug ),
				array( 'status' => 404 )
			);
		}
		if ( 'conflict' === $code ) {
			return new \WP_Error(
				'xspeed_conflict_refused',
				(string) $data['reason'],
				array(
					'status' => 409,
					'field'  => (string) $data['field'],
				)
			);
		}
		if ( 'refused' === $code && ! empty( $data['unknown'] ) ) {
			return new \WP_Error(
				'xspeed_settings_refused',
				sprintf(
					/* translators: %s: comma-separated setting keys. */
					__( 'These are not settings of this module: %s.', 'xspeed' ),
					implode( ', ', (array) $data['unknown'] )
				),
				array(
					'status'  => 400,
					'unknown' => $data['unknown'],
					'invalid' => $data['invalid'] ?? array(),
				)
			);
		}
		return Module_Registry::get( $slug )->settings_save_refusal( $code, $data );
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
				'risk'      => array( '' => 'read', 'list' => 'read', 'get' => 'read', 'update' => 'write' ),
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'List modules, or read/update a module\'s settings (or several modules at once).',
				'ai_hint'   => 'Read or write any xSpeed module\'s settings directly. To change several modules in one call, leave out the module slug and pass --values as an object of module slug to values; every module is checked first, and nothing is written unless all of them pass.',
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
						'description' => 'Module slug, e.g. "minify". Required for get. For update, leave it out to pass a settings patch in --values.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'values',
						'description' => 'JSON object of setting keys to new values (update only). With no module slug: an object of module slug to such an object.',
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
	 * CLI: `wp xspeed settings [list|get <module>|update <module> --values=<json>|update --values=<patch>]`
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
	 * Is this module reachable from the CLI / MCP right now? Registration is
	 * not enough: a Pro module on an unlicensed site must read as unknown.
	 * Settings_Manager::is_reachable() carries the full rule. (QA M2)
	 */
	private static function module_reachable( string $slug ): bool {
		return Settings_Manager::is_reachable( $slug );
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

	/**
	 * `settings update <module> --values=<json>`, validated by the module
	 * schema. With no module slug, `--values` can be a settings patch instead:
	 * an object of module slug => values, written all together or not at all.
	 */
	private function cli_update( string $module, array $assoc, bool $json ): void {
		if ( '' === $module ) {
			$patch = self::patch_from( $assoc );
			if ( null !== $patch ) {
				foreach ( $patch as $slug => $values ) {
					if ( empty( $values ) || array_values( $values ) === $values ) {
						\WP_CLI::error( sprintf( '%s: settings must be an object of field values, e.g. {"minify":{"minify_css":true}}.', $slug ) );
					}
				}
				$this->cli_update_patch( $patch, $json );
				return;
			}
			\WP_CLI::error( 'A module slug is required, e.g. `wp xspeed settings update minify --values=\'{"enabled":true}\'`.' );
		}
		// available(), not get(): a Pro module on an unlicensed site must be
		// as unreachable here as it is everywhere else, and must read as
		// "unknown" rather than "locked" so probing cannot enumerate the Pro
		// slug list. (QA M2)
		if ( ! self::module_reachable( $module ) ) {
			\WP_CLI::error( self::refusal_message( $module, 'unknown_module', array() ) );
		}
		if ( ! isset( $assoc['values'] ) || '' === $assoc['values'] ) {
			\WP_CLI::error( 'A --values=<json> object is required, e.g. --values=\'{"enabled":true}\'.' );
		}

		$values = json_decode( (string) $assoc['values'], true );
		if ( ! is_array( $values ) ) {
			\WP_CLI::error( 'The --values argument must be a JSON object, e.g. --values=\'{"enabled":true}\'.' );
		}

		$result = Settings_Manager::save( array( $module => $values ), self::write_policy( false ) );
		$entry  = $result['modules'][ $module ];
		if ( ! $entry['ok'] ) {
			\WP_CLI::error( self::refusal_message( $module, $entry['code'], $entry['data'] ) );
		}

		if ( $json ) {
			\WP_CLI::log( (string) wp_json_encode( $entry['settings'] ) );
			return;
		}
		self::log_update( $module, $values, $entry['settings'] );
	}

	/**
	 * `settings update --values=<patch>`: several modules in one call.
	 *
	 * Every module is checked before anything is written. One refusal and
	 * nothing is written, and the error names every module that failed, so the
	 * whole patch can be corrected and re-sent.
	 *
	 * @param array<string,array<string,mixed>> $patch Module slug => values.
	 */
	private function cli_update_patch( array $patch, bool $json ): void {
		$result = Settings_Manager::save( $patch, self::write_policy( true ) );
		if ( ! $result['ok'] ) {
			$messages = array();
			foreach ( $result['modules'] as $slug => $entry ) {
				if ( ! $entry['ok'] ) {
					$messages[] = self::refusal_message( (string) $slug, $entry['code'], $entry['data'], true );
				}
			}
			\WP_CLI::error( implode( "\n\n", $messages ) . "\n\nNo module was written." );
		}

		if ( $json ) {
			\WP_CLI::log( (string) wp_json_encode( array_map( static fn( array $entry ): array => $entry['settings'], $result['modules'] ) ) );
			return;
		}
		foreach ( $result['modules'] as $slug => $entry ) {
			self::log_update( (string) $slug, $patch[ $slug ], $entry['settings'] );
		}
	}

	/**
	 * The `--values` of a call with no module slug, read as a settings patch.
	 *
	 * An object whose every value is itself an object or list reads as a
	 * patch. The caller still refuses a module whose value is empty or a list
	 * (Settings_Manager::is_patch() is the full rule), naming that module.
	 * Anything else is the flat values of one module with its slug forgotten,
	 * and gets the "slug is required" error it always got.
	 *
	 * @param array<string,string> $assoc Associative arguments.
	 * @return array<string,array<mixed>>|null
	 */
	private static function patch_from( array $assoc ): ?array {
		if ( ! isset( $assoc['values'] ) || '' === $assoc['values'] ) {
			return null;
		}
		$patch = json_decode( (string) $assoc['values'], true );
		if ( ! is_array( $patch ) || empty( $patch ) ) {
			return null;
		}
		foreach ( $patch as $slug => $values ) {
			if ( ! is_string( $slug ) || ! is_array( $values ) ) {
				return null;
			}
		}
		return $patch;
	}

	/**
	 * The checks a CLI write runs, in the order it has always run them.
	 *
	 * A single-module call checks reachability itself, before it reads
	 * `--values`, so an unknown module is reported ahead of a missing or
	 * malformed `--values`, as before. A patch has no such step, so the check
	 * runs here.
	 *
	 * A patch also refuses turning on a feature an active plugin refuses, as
	 * the REST batch does (IMPLEMENTATION.md §6.2). A single-module call does
	 * not check conflicts, as before.
	 *
	 * Credential fields are refused rather than left to update(), which strips
	 * them on the MCP path (the #116 backstop), so the user never sees a green
	 * success over an unchanged credential. A field another setting holds is
	 * dropped by update() just as quietly, so it is refused by name too. So is
	 * a field pinned by a wp-config.php constant in a patch, where a dropped
	 * value would leave the other modules written under a success. A
	 * single-module call still has update() drop it without an error, as it
	 * always has.
	 *
	 * @param bool $patch Whether this is a multi-module patch.
	 * @return array<string,mixed>
	 */
	private static function write_policy( bool $patch ): array {
		$checks = $patch
			? array( 'reachable', 'conflict', 'secrets', 'consent', 'constant', 'managed', 'license', 'inspect' )
			: array( 'secrets', 'consent', 'managed', 'license', 'inspect' );
		return array(
			'mode'        => 'strict',
			'checks'      => $checks,
			// Pro licence write gate. The CLI writes through
			// Settings_Manager::update() and so never reaches
			// Module::update_settings(), where the gate lives. Without this a
			// `wp xspeed settings update <pro-module>` turns a Pro feature on
			// with no licence. (#185)
			/* translators: %s: module slug. */
			'license_log' => __( 'Refused a CLI settings write to the Pro module "%s" — no valid license.', 'xspeed' ),
			// Refuse rather than report success over a write that won't
			// happen: update() drops an out-of-schema key, and an in-schema
			// key with an invalid value, without a word. Both used to exit 0
			// with "Success". (#206)
			'refuse'      => array( 'unknown', 'invalid' ),
			'write'       => 'update',
		);
	}

	/**
	 * The CLI's words for a module that save() refused.
	 *
	 * Inside a patch the module's own closing sentence ("Nothing was
	 * changed.") is left off: the patch error ends with one line for the
	 * whole call.
	 *
	 * @param string              $module   Module slug.
	 * @param string              $code     save() refusal code.
	 * @param array<string,mixed> $data     save() refusal data.
	 * @param bool                $in_patch Part of a settings patch.
	 */
	private static function refusal_message( string $module, string $code, array $data, bool $in_patch = false ): string {
		$unchanged = $in_patch ? '' : ' Nothing was changed.';
		switch ( $code ) {
			case 'unknown_module':
				return sprintf( 'Unknown module "%s". Run `wp xspeed settings list` to see the registered modules.', $module );

			case 'conflict':
				return sprintf( 'Refused to turn on %1$s in "%2$s": %3$s%4$s', $data['field'], $module, $data['reason'], $unchanged );

			case 'secret_fields':
				return sprintf(
					'Credential fields (%s) cannot be set from the CLI or MCP — they are stripped on this path by design. Set them in the xSpeed dashboard instead.',
					implode( ', ', (array) $data['fields'] )
				);

			case 'consent_grant':
				return sprintf(
					'Turning on %1$s in "%2$s" records the site owner\'s consent, so it can\'t be done from an MCP tool call. The owner can turn it on from the xSpeed dashboard. Turning it off is allowed.',
					implode( ', ', (array) $data['fields'] ),
					$module
				);

			case 'constant_locked':
				$pairs = array();
				foreach ( (array) $data['fields'] as $field => $constant ) {
					$pairs[] = $field . ' (' . $constant . ')';
				}
				return sprintf(
					'These settings are defined in wp-config.php and cannot be changed here: %s. Edit the constant, or remove it to manage the setting from xSpeed.%s',
					implode( ', ', $pairs ),
					$unchanged
				);

			case 'managed':
				$pairs = array();
				foreach ( (array) $data['fields'] as $field => $by ) {
					$pairs[] = $field . ' (' . $by . ')';
				}
				return sprintf(
					'These settings are held by another setting and cannot be changed here: %s. Turn that setting off to change them.%s',
					implode( ', ', $pairs ),
					$unchanged
				);

			case 'license_locked':
				return sprintf(
					'"%s" is a Pro module and this site has no active license, so the write was refused.%s',
					$module,
					$unchanged
				);
		}

		$lines = array();
		foreach ( (array) ( $data['unknown'] ?? array() ) as $key ) {
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
		foreach ( (array) ( $data['invalid'] ?? array() ) as $key ) {
			$lines[] = sprintf( '  %s — value rejected by the schema (wrong type, or outside the allowed range/options)', $key );
		}

		$message = sprintf( "Refused to update %s:\n%s", $module, implode( "\n", $lines ) );
		if ( $in_patch ) {
			return $message;
		}
		$applied = empty( $data['applied'] )
			? 'Nothing was written.'
			: sprintf( 'Nothing was written — the valid keys (%s) were not applied either, so the whole payload can be corrected and re-sent.', implode( ', ', (array) $data['applied'] ) );

		return $message . "\n\n" . $applied;
	}

	/**
	 * Report one module's successful write: a success line, then each key
	 * that was sent, with the value now stored.
	 *
	 * @param array<string,mixed> $values  What was sent.
	 * @param array<string,mixed> $updated What update() returned.
	 */
	private static function log_update( string $module, array $values, array $updated ): void {
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
