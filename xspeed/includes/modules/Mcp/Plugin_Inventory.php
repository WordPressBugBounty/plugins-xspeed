<?php
/**
 * Every plugin on this site, in one list the Hub can read (#952).
 *
 * The rows follow `wp plugin list`: the same files, the same status words
 * (`active`, `active-network`, `inactive`, `must-use`, `dropin`). Update data
 * comes from the `update_plugins` site transient that WordPress already
 * keeps, so reading the list never calls wordpress.org or anyone else.
 *
 * Nothing here returns a package URL or a licence key. A package URL from a
 * commercial updater often carries the key in its query string, so the
 * transient's `package` field is read only to decide `source` and is never
 * copied into a row.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

defined( 'ABSPATH' ) || exit;

final class Plugin_Inventory {

	/** Filter that fills each row's `licence` field. row() names it literally so Plugin Check can see the prefix. */
	public const LICENCE_FILTER = 'xspeed_plugin_licence_state';

	/** The update came through wordpress.org's update API. */
	public const SOURCE_WPORG = 'wordpress.org';

	/** WordPress has checked for updates and wordpress.org does not know this plugin. */
	public const SOURCE_OTHER = 'other';

	/** WordPress has not checked for updates yet, so the source cannot be told. */
	public const SOURCE_UNKNOWN = 'unknown';

	/**
	 * The only licence fields a row may carry. The list goes to the Hub and
	 * into MCP tool results a read-only client can see, so a field is kept
	 * because it is known to be safe, not dropped because it is known to be
	 * a secret: a filter that returned `activation_key` or `api_token` got
	 * through the earlier list of named secrets (QA round 4 on #956).
	 */
	private const LICENCE_FIELDS = array( 'item_id', 'status', 'expires', 'in_grace', 'grace_until', 'site_count', 'license_limit', 'activations_left', 'verified_at', 'is_stale', 'masked_key' );

	/** Longest string value a licence field may hold. */
	private const LICENCE_VALUE_MAX = 64;

	/**
	 * Build the list.
	 *
	 * @return array<int,array<string,mixed>> One row per plugin, sorted by file.
	 */
	public static function rows(): array {
		self::load_plugin_api();

		$installed = function_exists( 'get_plugins' ) ? (array) get_plugins() : array();
		$site      = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );
		$network   = is_multisite() ? array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) : array();
		// Null, not false, when WordPress has not checked yet: false marks a
		// row that the update API never covers (see below).
		$updates = get_site_transient( 'update_plugins' );
		$updates = is_object( $updates ) ? $updates : null;

		$rows = array();
		foreach ( $installed as $file => $data ) {
			$file = (string) $file;
			$data = is_array( $data ) ? $data : array();

			$network_active = in_array( $file, $network, true );
			$site_active    = in_array( $file, $site, true );
			if ( $network_active ) {
				$status = 'active-network';
			} elseif ( $site_active ) {
				$status = 'active';
			} else {
				$status = 'inactive';
			}

			$rows[] = self::row( $file, $data, $status, $site_active, $network_active, $updates );
		}

		// Must-use plugins and drop-ins are in `wp plugin list` too, and a
		// cache drop-in is often the first thing to check on a slow site.
		// Neither can be updated, activated or turned off from wp-admin, and
		// wordpress.org's update API never lists them, so their source is
		// `other` whatever the transient holds.
		$extra = array(
			'must-use' => function_exists( 'get_mu_plugins' ) ? (array) get_mu_plugins() : array(),
			'dropin'   => function_exists( 'get_dropins' ) ? (array) get_dropins() : array(),
		);
		foreach ( $extra as $status => $list ) {
			foreach ( $list as $file => $data ) {
				$rows[] = self::row( (string) $file, is_array( $data ) ? $data : array(), $status, true, false, false );
			}
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return strcmp( $a['file'], $b['file'] );
			}
		);
		return $rows;
	}

	/**
	 * The list as `wp xspeed plugins list` prints it.
	 *
	 * Public so the plugins command can serve its `list` action with the
	 * same output when that command is installed.
	 *
	 * @param string $format `json` for every field, anything else for the table.
	 * @return string Lines joined with "\n", without a trailing newline.
	 */
	public static function render( string $format = 'table' ): string {
		$rows = self::rows();
		if ( 'json' === $format ) {
			return (string) wp_json_encode( $rows );
		}
		if ( array() === $rows ) {
			return 'No plugins found.';
		}
		$lines = array( sprintf( '%-32s %-15s %-12s %-12s %-14s %s', 'slug', 'status', 'version', 'update', 'source', 'requires' ) );
		foreach ( $rows as $row ) {
			$lines[] = sprintf(
				'%-32s %-15s %-12s %-12s %-14s %s',
				$row['slug'],
				$row['status'],
				'' === $row['version'] ? '-' : $row['version'],
				$row['update_available'] ? (string) $row['new_version'] : '-',
				$row['source'],
				array() === $row['requires_plugins'] ? '-' : implode( ',', $row['requires_plugins'] )
			);
		}
		return implode( "\n", $lines );
	}

	/**
	 * The slug WordPress uses for a plugin file: the folder name, or the file
	 * name without `.php` for a plugin that is a single file.
	 *
	 * @param string $file Plugin file relative to the plugins folder.
	 */
	public static function slug_for( string $file ): string {
		$file = str_replace( '\\', '/', trim( $file ) );
		if ( '' === $file ) {
			return '';
		}
		$dir = dirname( $file );
		if ( '.' !== $dir && '' !== $dir ) {
			$first = explode( '/', $dir )[0];
			return sanitize_key( $first );
		}
		return sanitize_key( preg_replace( '/\.php$/i', '', basename( $file ) ) );
	}

	/**
	 * One row.
	 *
	 * @param string              $file           Plugin file.
	 * @param array<string,mixed> $data           Header data from get_plugins().
	 * @param string              $status         `wp plugin list` status word.
	 * @param bool                $site_active    In this site's active_plugins.
	 * @param bool                $network_active Network-activated.
	 * @param mixed               $updates        The update_plugins transient, or false for
	 *                                            a must-use plugin or drop-in.
	 * @return array<string,mixed>
	 */
	private static function row( string $file, array $data, string $status, bool $site_active, bool $network_active, $updates ): array {
		$slug    = self::slug_for( $file );
		$managed = false !== $updates;
		$update  = $managed ? self::update_entry( $updates, $file, 'response' ) : null;

		$new_version = '';
		if ( null !== $update && isset( $update['new_version'] ) && is_scalar( $update['new_version'] ) ) {
			$new_version = (string) $update['new_version'];
		}

		$row = array(
			'file'             => $file,
			'slug'             => $slug,
			'name'             => isset( $data['Name'] ) ? (string) $data['Name'] : $slug,
			'version'          => isset( $data['Version'] ) ? (string) $data['Version'] : '',
			'status'           => $status,
			'active'           => $site_active || $network_active,
			'network_active'   => $network_active,
			'update_available' => '' !== $new_version,
			'new_version'      => '' !== $new_version ? $new_version : null,
			'requires_plugins' => self::requires_plugins( $data ),
			'source'           => $managed ? self::source( $updates, $file ) : self::SOURCE_OTHER,
		);

		/**
		 * Filter one plugin's licence state for the plugin list.
		 *
		 * Null (the default) means xSpeed does not know. A licensing library
		 * that does can return a short array such as
		 * `array( 'status' => 'valid', 'expires' => '2027-01-31' )`. Only the
		 * fields in LICENCE_FIELDS are kept; see clean_licence().
		 *
		 * @param array|string|null   $licence Null by default.
		 * @param string              $file    Plugin file, e.g. `akismet/akismet.php`.
		 * @param string              $slug    Plugin slug.
		 * @param array<string,mixed> $row     The row so far.
		 */
		$row['licence'] = self::clean_licence( apply_filters( 'xspeed_plugin_licence_state', null, $file, $slug, $row ) );

		return $row;
	}

	/**
	 * Where a plugin's updates come from.
	 *
	 * The rule: WordPress core sends every installed plugin to
	 * api.wordpress.org and stores the answer in the update transient, under
	 * `response` (an update is out) or `no_update` (up to date). An entry whose
	 * `id` starts with `w.org/plugins/`, or whose `url` or `package` is on
	 * wordpress.org, came from there. Any other entry was put there by the
	 * plugin's own updater, and a plugin with no entry at all is not on
	 * wordpress.org. Both of those are `other`. The Plugin URI header is not
	 * used: many wordpress.org plugins point it at their own site.
	 *
	 * @param mixed  $updates The update_plugins transient.
	 * @param string $file    Plugin file.
	 */
	private static function source( $updates, string $file ): string {
		$entry = self::update_entry( $updates, $file, 'response' ) ?? self::update_entry( $updates, $file, 'no_update' );
		if ( null === $entry ) {
			$checked = is_object( $updates ) && ! empty( $updates->last_checked );
			return $checked ? self::SOURCE_OTHER : self::SOURCE_UNKNOWN;
		}

		$id = isset( $entry['id'] ) && is_string( $entry['id'] ) ? $entry['id'] : '';
		if ( 0 === strpos( $id, 'w.org/plugins/' ) ) {
			return self::SOURCE_WPORG;
		}
		foreach ( array( 'url', 'package' ) as $key ) {
			$host = isset( $entry[ $key ] ) && is_string( $entry[ $key ] ) ? (string) wp_parse_url( $entry[ $key ], PHP_URL_HOST ) : '';
			$host = strtolower( $host );
			if ( 'wordpress.org' === $host || '.wordpress.org' === substr( $host, -14 ) ) {
				return self::SOURCE_WPORG;
			}
		}
		return self::SOURCE_OTHER;
	}

	/**
	 * One plugin's entry in the transient's `response` or `no_update` list, as
	 * an array. Core stores objects; some updaters store arrays.
	 *
	 * @param mixed  $updates The update_plugins transient.
	 * @param string $file    Plugin file.
	 * @param string $list    `response` or `no_update`.
	 * @return array<string,mixed>|null
	 */
	private static function update_entry( $updates, string $file, string $list ): ?array {
		if ( ! is_object( $updates ) || ! isset( $updates->{$list} ) || ! is_array( $updates->{$list} ) ) {
			return null;
		}
		$entry = $updates->{$list}[ $file ] ?? null;
		if ( is_object( $entry ) ) {
			return get_object_vars( $entry );
		}
		return is_array( $entry ) ? $entry : null;
	}

	/**
	 * The `Requires Plugins` header (WordPress 6.5) as a list of slugs.
	 *
	 * @param array<string,mixed> $data Header data from get_plugins().
	 * @return string[]
	 */
	private static function requires_plugins( array $data ): array {
		$raw = isset( $data['RequiresPlugins'] ) && is_string( $data['RequiresPlugins'] ) ? $data['RequiresPlugins'] : '';
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$slugs = array_map( 'sanitize_key', array_map( 'trim', explode( ',', $raw ) ) );
		return array_values( array_unique( array_filter( $slugs, 'strlen' ) ) );
	}

	/**
	 * Keep only the licence fields known to be safe to send.
	 *
	 * - Only the keys in LICENCE_FIELDS, each with a scalar value.
	 * - A string value that looks like a URL, or is longer than 64
	 *   characters, is dropped: a download link or a token does not belong
	 *   in a status field.
	 * - `masked_key` only when at least half its characters are `*`.
	 * - A bare string is read as `status` when it is a short word such as
	 *   `valid`, and dropped otherwise, since it could be the key itself.
	 *
	 * @param mixed $licence Filter result.
	 * @return array<string,bool|int|float|string>|null
	 */
	private static function clean_licence( $licence ) {
		if ( is_string( $licence ) ) {
			$licence = 1 === preg_match( '/^[a-z_-]{1,20}$/', $licence ) ? array( 'status' => $licence ) : array();
		}
		if ( ! is_array( $licence ) ) {
			return null;
		}
		$out = array();
		foreach ( self::LICENCE_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $licence ) ) {
				continue;
			}
			$value = $licence[ $field ];
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			if ( is_string( $value ) && ! self::safe_licence_string( $value ) ) {
				continue;
			}
			if ( 'masked_key' === $field && ! self::is_masked( (string) $value ) ) {
				continue;
			}
			$out[ $field ] = $value;
		}
		return array() === $out ? null : $out;
	}

	/**
	 * A string short enough and plain enough to be a status, a date or a
	 * count, and not a link.
	 *
	 * @param string $value Licence field value.
	 */
	private static function safe_licence_string( string $value ): bool {
		if ( strlen( $value ) > self::LICENCE_VALUE_MAX ) {
			return false;
		}
		if ( false !== strpos( $value, '://' ) || 1 === preg_match( '/^(www\.|\/\/)/i', $value ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether at least half the characters of a key are `*`.
	 *
	 * @param string $value The masked key.
	 */
	private static function is_masked( string $value ): bool {
		$length = strlen( $value );
		return $length > 0 && substr_count( $value, '*' ) * 2 >= $length;
	}

	/**
	 * get_plugins() and get_mu_plugins() live in an admin include that a
	 * front-end or REST request (an MCP call) has not loaded.
	 */
	private static function load_plugin_api(): void {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
