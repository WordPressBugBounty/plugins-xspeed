<?php
/**
 * Support_Snapshot — gather a system-info dump for support tickets.
 *
 * No write surface, no setting. Just a read-only aggregation of
 * versions + environment + module config that users paste into a
 * support email. Keeps diagnostics out of the ticket flow itself.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Support_Snapshot {

	/**
	 * Build the snapshot as a structured array. Public for the REST
	 * handler + the CLI command + the panel's "Copy to clipboard"
	 * button (it stringifies on the client side).
	 *
	 * @return array<string,mixed>
	 */
	public static function gather(): array {
		global $wp_version;
		$active = self::active_module_slugs();
		return array(
			'generated_at'    => time(),
			'xspeed'          => defined( 'XSPEED_VERSION' ) ? XSPEED_VERSION : 'unknown',
			'xspeed_pro'      => defined( 'XSPEED_PRO_VERSION' ) ? XSPEED_PRO_VERSION : 'unknown',
			'xspeed_api'      => defined( 'XSPEED_API_VERSION' ) ? XSPEED_API_VERSION : null,
			'wordpress'       => isset( $wp_version ) ? (string) $wp_version : 'unknown',
			'php'             => PHP_VERSION,
			'mysql'           => self::mysql_version(),
			'server_software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown',
			'multisite'       => function_exists( 'is_multisite' ) && is_multisite(),
			'home_url'        => function_exists( 'home_url' ) ? (string) home_url() : '',
			'memory_limit'    => function_exists( 'wp_convert_hr_to_bytes' ) ? ini_get( 'memory_limit' ) : ini_get( 'memory_limit' ),
			'active_modules'  => $active,
			'object_cache'    => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
			'license_key'     => self::masked_license_key(),
		);
	}

	/**
	 * Format the snapshot as a paste-ready Markdown block for support
	 * tickets. Public so the panel + CLI + the REST handler emit the
	 * same shape.
	 */
	public static function to_markdown( array $snapshot ): string {
		$lines = array( '### xSpeed support snapshot' );
		foreach ( $snapshot as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			}
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$lines[] = sprintf( '- **%s:** %s', $key, (string) $value );
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Slugs of every xSpeed module that currently has an `enabled`
	 * option set to true. Doesn't reach into Module_Registry because
	 * the registry is reset between switch_to_blog calls — option
	 * scan is more portable.
	 *
	 * @return string[]
	 */
	private static function active_module_slugs(): array {
		$candidates = array(
			'cache', 'minify', 'gzip', 'lazy', 'browser-cache',
			'object-cache', 'cdn', 'cloudflare', 'database', 'preloader',
			'heartbeat', 'bloat', 'pagespeed', 'rum', 'recommendations',
			'images', 'migration', 'cloudflare-apo', 'custom-rules',
			'analytics', 'critical-css', 'unused-css', 'white-label',
		);
		$active = array();
		foreach ( $candidates as $slug ) {
			$opt = get_option( 'xspeed_module_' . $slug, null );
			if ( is_array( $opt ) && ! empty( $opt['enabled'] ) ) {
				$active[] = $slug;
			}
		}
		return $active;
	}

	/**
	 * Show the first 4 + last 4 of the license key with ••• in the
	 * middle — never log the full key, even in support dumps the
	 * user copies to ticket systems.
	 */
	private static function masked_license_key(): string {
		$status = get_option( 'xspeed_module_pro_status', array() );
		$key    = is_array( $status ) ? (string) ( $status['license_key'] ?? '' ) : '';
		if ( strlen( $key ) <= 8 ) {
			return '' === $key ? 'none' : str_repeat( '•', strlen( $key ) );
		}
		return substr( $key, 0, 4 ) . '••••' . substr( $key, -4 );
	}

	private static function mysql_version(): string {
		global $wpdb;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ) {
			try {
				return (string) $wpdb->db_version();
			} catch ( \Throwable $e ) {
				return 'unknown';
			}
		}
		return 'unknown';
	}
}
