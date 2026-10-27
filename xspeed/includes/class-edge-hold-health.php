<?php
/**
 * Passive PHP header evidence shared by Cache and Health.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * Stores bounded historical conflicts, never a CDN protection verdict.
 *
 * @phpstan-type Classification array{result:string,selected_header:string,classification:string}
 * @phpstan-type Intent array{cache_control:string,cdn_cache_control:string,cloudflare_cdn_cache_control:string,apo:string,preserved_control:string}
 * @phpstan-type Snapshot array{schema:int,version:string,provider:string,source:string,confidence:string,reason:string,status:string,intent:array,config_sig:string,generation:string,blog:int,mark_at:int,sent_before_resolution:bool,sent_at_mark:bool}
 * @phpstan-type Record array{provider:string,source:string,header:string,classification:string,config_sig:string,generation:string,version:string,mark_at:int,observed_at:int,expires_at:int}
 * @phpstan-type Envelope array{schema:int,records:array<string,array>}
 */
final class Edge_Hold_Health {

	public const SCHEMA = 1;
	public const GENERATION_OPTION = 'xspeed_edge_hold_generation';
	public const RECORDS_OPTION = 'xspeed_edge_hold_health';
	public const RECORD_TTL = 604800;
	public const THROTTLE = 600;
	public const MAX_RECORD_BYTES = 8192;
	public const REASONS = array( 'bypass', 'bypass-shape', 'miss', 'mobile-split', 'markdown', 'pending', 'query-variant', 'other' );
	public const PROVIDERS = array( 'cloudflare', 'fastly', 'varnish', 'nginx', 'akamai', 'cloudfront', 'google', 'keycdn', 'bunny', 'sucuri', 'incapsula', 'generic', 'custom' );
	public const SOURCES = array( 'filter', 'constant', 'setting', 'plugin', 'xcloud-cfe', 'request' );
	public const NO_CONFLICT = 'no_conflict';
	public const CONFLICT = 'conflict';
	public const INCONCLUSIVE = 'inconclusive';
	public const FORBID = 'FORBID';
	public const PERMIT = 'PERMIT';
	public const NEUTRAL = 'NEUTRAL';
	public const ABSENT = 'ABSENT';
	public const INVALID = 'INVALID';
	public const HOLD = 'HOLD';
	public const SITE_OPTIONS = array( 'xspeed_options', 'xspeed_module_cache', 'xspeed_module_cloudflare', 'active_plugins', 'stylesheet', 'template' );
	public const NETWORK_OPTIONS = array( 'active_sitewide_plugins' );

	/** Classify one response's raw header lines without retaining their values. */
	public static function classify( array $header_lines, string $provider ): array {
		$names = array(
			'cloudflare-cdn-cache-control' => 'cloudflare-cdn-cache-control',
			'cdn-cache-control' => 'cdn-cache-control',
			'cache-control' => 'cache-control',
			'cf-edge-cache' => 'cf-edge-cache',
			'surrogate-control' => 'surrogate_control',
			'edge-control' => 'edge_control',
			'set-cookie' => 'set_cookie',
			'vary' => 'vary',
		);
		$headers = array();
		foreach ($header_lines as $line) {
			if (!is_string($line) || false === strpos($line, ':')) {
				continue;
			}
			$colon = strpos($line, ':');
			$name = strtolower(substr($line, 0, $colon));
			if (isset($names[$name])) {
				$headers[$name][] = substr($line, $colon + 1);
			}
		}

		$family_order = array();
		if ('cloudflare' === $provider) {
			$family_order = array('cloudflare-cdn-cache-control', 'cdn-cache-control', 'cache-control');
		} elseif ('google' === $provider) {
			$family_order = array('cdn-cache-control', 'cache-control');
		}
		if (!$family_order) {
			return self::classification(self::INCONCLUSIVE, 'none', 'none');
		}

		$selected_name = null;
		foreach ($family_order as $name) {
			if (isset($headers[$name])) {
				$selected_name = $name;
				break;
			}
		}
		$selected_id = null === $selected_name ? 'none' : $names[$selected_name];
		$family_state = null === $selected_name
			? self::ABSENT
			: self::family_state(isset($headers[$selected_name]) ? $headers[$selected_name] : array());

		$apo_state = self::ABSENT;
		if ('cloudflare' === $provider && isset($headers['cf-edge-cache'])) {
			$apo_state = self::apo_state($headers['cf-edge-cache']);
		}

		$surrogate_present = isset($headers['surrogate-control']) || isset($headers['edge-control']);
		$set_cookie_present = isset($headers['set-cookie']);
		$vary_star = false;
		if (isset($headers['vary'])) {
			foreach ($headers['vary'] as $vary_value) {
				foreach (explode(',', $vary_value) as $member) {
					if ('*' === trim($member, " \t")) {
						$vary_star = true;
					}
				}
			}
		}

		$result = self::matrix($family_state, $apo_state);
		if (self::CONFLICT === $result) {
			if ($surrogate_present) {
				return self::classification(self::INCONCLUSIVE, $selected_id, 'none');
			}
			if (self::PERMIT === $apo_state && self::lower_family_veto($headers, $family_order, $selected_name)) {
				return self::classification(self::INCONCLUSIVE, $selected_id, 'none');
			}
			if ($set_cookie_present || $vary_star) {
				return self::classification(self::INCONCLUSIVE, $selected_id, 'none');
			}
			if (self::PERMIT === $family_state) {
				return self::classification($result, $selected_id, 'positive_shared_freshness');
			}
			return self::classification($result, 'cf-edge-cache', 'apo_permission');
		}
		if ($surrogate_present) {
			$result = self::INCONCLUSIVE;
		}
		return self::classification($result, $selected_id, 'none');
	}

	private static function classification( string $result, string $selected_header, string $classification ): array {
		return array(
			'result' => $result,
			'selected_header' => $selected_header,
			'classification' => $classification,
		);
	}

	/** Return a family state for all raw lines of one Cache-Control field name. */
	public static function family_state( array $values ): string {
		if (!$values) { return self::ABSENT; }
		if (1 !== count($values) || !is_string($values[0])) {
			return self::INVALID;
		}
		$value = $values[0];
		if (strlen($value) > 4096) {
			return self::INVALID;
		}
		$tokens = explode(',', $value);
		if (count($tokens) > 32) {
			return self::INVALID;
		}
		$seen = array();
		$directives = array();
		$numeric = array('max-age', 's-maxage', 'stale-while-revalidate', 'stale-if-error');
		$bare = array('public', 'private', 'no-store', 'no-cache', 'must-revalidate', 'proxy-revalidate', 'no-transform', 'immutable', 'must-understand');
		foreach ($tokens as $token) {
			$token = trim($token, " \t");
			if (!preg_match('/\A([a-z][a-z-]*)(?:=([0-9]{1,10}))?\z/i', $token, $matches)) {
				return self::INVALID;
			}
			$name = strtolower($matches[1]);
			$has_value = isset($matches[2]) && '' !== $matches[2];
			if (isset($seen[$name])) {
				return self::INVALID;
			}
			$seen[$name] = true;
			if (in_array($name, $numeric, true)) {
				if (!$has_value) {
					return self::INVALID;
				}
				$directives[$name] = $matches[2];
			} elseif (in_array($name, $bare, true)) {
				if ($has_value) {
					return self::INVALID;
				}
				$directives[$name] = true;
			} else {
				return self::INVALID;
			}
		}

		if (isset($directives['no-store']) || isset($directives['private'])) {
			return self::FORBID;
		}
		if (isset($directives['no-cache']) || isset($directives['must-understand'])) {
			return self::NEUTRAL;
		}
		$lifetime = isset($directives['s-maxage']) ? $directives['s-maxage'] : (isset($directives['max-age']) ? $directives['max-age'] : null);
		if (null !== $lifetime && preg_match('/[1-9]/', $lifetime)) {
			return self::PERMIT;
		}
		return self::NEUTRAL;
	}

	/** Return an APO state for all raw cf-edge-cache field lines. */
	public static function apo_state( array $values ): string {
		if (!$values) { return self::ABSENT; }
		if (1 !== count($values) || !is_string($values[0]) || strlen($values[0]) > 4096) {
			return self::INVALID;
		}
		$value = strtolower(trim($values[0], " \t"));
		if ('no-cache' === $value) {
			return self::HOLD;
		}
		if (preg_match('/\Acache[ \t]*,[ \t]*platform=wordpress\z/', $value)) {
			return self::PERMIT;
		}
		return self::INVALID;
	}

	private static function matrix( string $family, string $apo ): string {
		$matrix = array(
			self::FORBID => array(self::HOLD => self::NO_CONFLICT, self::PERMIT => self::INCONCLUSIVE, self::ABSENT => self::NO_CONFLICT, self::INVALID => self::INCONCLUSIVE),
			self::PERMIT => array(self::HOLD => self::INCONCLUSIVE, self::PERMIT => self::CONFLICT, self::ABSENT => self::CONFLICT, self::INVALID => self::INCONCLUSIVE),
			self::NEUTRAL => array(self::HOLD => self::INCONCLUSIVE, self::PERMIT => self::INCONCLUSIVE, self::ABSENT => self::INCONCLUSIVE, self::INVALID => self::INCONCLUSIVE),
			self::ABSENT => array(self::HOLD => self::INCONCLUSIVE, self::PERMIT => self::CONFLICT, self::ABSENT => self::INCONCLUSIVE, self::INVALID => self::INCONCLUSIVE),
			self::INVALID => array(self::HOLD => self::INCONCLUSIVE, self::PERMIT => self::INCONCLUSIVE, self::ABSENT => self::INCONCLUSIVE, self::INVALID => self::INCONCLUSIVE),
		);
		return $matrix[$family][$apo];
	}

	/** A lower-priority family line can veto only an APO-based conflict. */
	private static function lower_family_veto( array $headers, array $order, ?string $selected_name ): bool {
		$selected_index = array_search($selected_name, $order, true);
		foreach ($order as $index => $name) {
			if ($index <= $selected_index || !isset($headers[$name])) {
				continue;
			}
			$state = self::family_state($headers[$name]);
			if (in_array($state, array(self::FORBID, self::NEUTRAL, self::INVALID), true)) {
				return true;
			}
		}
		return false;
	}

	/** Read only a fixed option bundle when its callbacks cannot be replayed. */
	public static function signature(): ?string {
		global $wpdb;
		$previous_suppression = null;
		try {
			if ( self::unsafe_option_filters() || ! self::valid_version( XSPEED_VERSION ) ) { return null; }
			if ( is_object( $wpdb ) && method_exists( $wpdb, 'suppress_errors' ) ) { $previous_suppression = $wpdb->suppress_errors(); }
			$options = array();
			foreach ( self::SITE_OPTIONS as $name ) {
				$options[ $name ] = get_option( $name, in_array( $name, array( 'stylesheet', 'template' ), true ) ? null : array() );
			}
			$options['active_sitewide_plugins'] = get_site_option( 'active_sitewide_plugins', array() );
			if ( self::unsafe_option_filters() ) { return null; }
			foreach ( array( 'xspeed_options', 'xspeed_module_cache', 'xspeed_module_cloudflare', 'active_plugins', 'active_sitewide_plugins' ) as $name ) {
				if ( ! is_array( $options[ $name ] ) ) { return null; }
			}
			$cache = $options['xspeed_module_cache'];
			$pin = $cache['edge_provider'] ?? 'auto';
			// An explicitly stored null is malformed, not the missing default.
			if ( array_key_exists( 'edge_provider', $cache ) ) { $pin = $cache['edge_provider']; }
			$constant = defined( 'XSPEED_EDGE_PROVIDER' ) ? XSPEED_EDGE_PROVIDER : 'unset';
			if ( ! self::valid_pin( $pin ) || ( 'unset' !== $constant && ! self::valid_pin( $constant ) )
				|| ( defined( 'XSPEED_EDGE_PROVIDER' ) && 'unset' === $constant ) ) { return null; }
			$bools = array();
			foreach ( array( array( $options['xspeed_options'], 'cache_enabled' ), array( $cache, 'mobile_separate' ), array( $cache, 'tablet_separate' ), array( $cache, 'cache_markdown' ), array( $options['xspeed_module_cloudflare'], 'enabled' ) ) as $source ) {
				$value = array_key_exists( $source[1], $source[0] ) ? $source[0][ $source[1] ] : false;
				if ( ! in_array( $value, array( false, true, 0, 1, '', '0', '1' ), true ) ) { return null; }
				$bools[] = in_array( $value, array( true, 1, '1' ), true );
			}
			$tablet = array_key_exists( 'tablet_renders_as', $cache ) ? $cache['tablet_renders_as'] : 'desktop';
			if ( ! in_array( $tablet, array( 'desktop', 'mobile' ), true ) ) { return null; }
			$site = $options['active_plugins']; $network = $options['active_sitewide_plugins'];
			if ( count( $site ) + count( $network ) > 512 ) { return null; }
			foreach ( $site as $key => $basename ) {
				if ( ! is_int( $key ) || ! self::valid_basename( $basename ) ) { return null; }
			}
			foreach ( $network as $basename => $activated ) {
				if ( ! self::valid_basename( $basename ) || ! is_int( $activated ) || $activated < 0 ) { return null; }
			}
			$site = array_values( array_unique( $site, SORT_STRING ) ); $network = array_keys( $network );
			sort( $site, SORT_STRING ); sort( $network, SORT_STRING );
			$theme = array( $options['stylesheet'], $options['template'] );
			foreach ( $theme as $slug ) {
				if ( ! is_string( $slug ) || strlen( $slug ) > 255 || ! preg_match( '/\A[A-Za-z0-9._-]+\z/D', $slug ) || in_array( $slug, array( '.', '..' ), true ) ) { return null; }
			}
			$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
			$plugins_json = json_encode( array( $site, $network ), $flags ); $theme_json = json_encode( $theme, $flags );
			if ( ! is_string( $plugins_json ) || ! is_string( $theme_json ) ) { return null; }
			$tuple = array( self::SCHEMA, XSPEED_VERSION, $pin, $constant, $bools[0], $bools[1], $bools[2], $bools[3], $tablet, $bools[4], in_array( 'cloudflare/cloudflare.php', $site, true ), defined( 'XCLOUD_CFE_PURGE_VERSION' ), hash( 'sha256', $plugins_json ), hash( 'sha256', $theme_json ) );
			$encoded = json_encode( $tuple, $flags );
			return is_string( $encoded ) ? hash( 'sha256', $encoded ) : null;
		} catch ( \Throwable $error ) { return null; }
		finally { if ( null !== $previous_suppression ) { $wpdb->suppress_errors( $previous_suppression ); } }
	}

	private static function valid_pin( $value ): bool {
		return is_string( $value ) && in_array( $value, array_merge( self::PROVIDERS, array( 'auto', 'off' ) ), true );
	}

	private static function valid_version( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[\x20-\x7e]{1,64}\z/D', $value );
	}

	private static function valid_basename( $value ): bool {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 255 || preg_match( '/[\\\\:\x00-\x1f\x7f?#]/', $value ) ) { return false; }
		foreach ( explode( '/', $value ) as $part ) {
			if ( in_array( $part, array( '', '.', '..' ), true ) ) { return false; }
		}
		return true;
	}

	private static function unsafe_option_filters(): bool {
		$tags = array( 'all', 'pre_option', 'pre_wp_load_alloptions', 'pre_cache_alloptions', 'alloptions', 'wp_autoload_values_to_autoload', 'pre_site_option' );
		foreach ( array_merge( self::SITE_OPTIONS, self::NETWORK_OPTIONS ) as $name ) {
			foreach ( array( 'pre_option_', 'default_option_', 'option_' ) as $prefix ) { $tags[] = $prefix . $name; }
		}
		foreach ( self::NETWORK_OPTIONS as $name ) {
			foreach ( array( 'pre_site_option_', 'default_site_option_', 'site_option_' ) as $prefix ) { $tags[] = $prefix . $name; }
		}
		foreach ( $tags as $tag ) { if ( false !== has_filter( $tag ) ) { return true; } }
		return false;
	}
	private static bool $attempted = false;

	/** The only direct-query exception is this named scalar, never serialized data. */
	private static function generation_location( ?int $network = null ): ?array {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) { return null; }
		$blog = is_multisite() ? (int) get_main_site_id( $network ?? get_current_network_id() ) : (int) get_current_blog_id();
		if ( $blog < 1 ) { return null; }
		$table = $wpdb->get_blog_prefix( $blog ) . 'options';
		return 1 === preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ? array( $table, $blog ) : null;
	}

	public static function read_generation( ?int $network = null ): ?string {
		global $wpdb;
		$previous_suppression = null;
		try {
			$previous_suppression = $wpdb->suppress_errors();
			$location = self::generation_location( $network );
			if ( null === $location ) { return null; }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- fresh named scalar bypasses option filters and persistent caches.
			$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . $location[0] . ' WHERE option_name = %s LIMIT 1', self::GENERATION_OPTION ) );
			return is_string( $value ) && preg_match( '/\A[a-f0-9]{32}\z/D', $value ) ? $value : null;
		} catch ( \Throwable $error ) { return null; }
		finally { if ( null !== $previous_suppression ) { $wpdb->suppress_errors( $previous_suppression ); } }
	}

	public static function initialize(): bool {
		if ( self::$tearing_down ) { return false; }
		if ( null !== self::read_generation() ) { return true; }
		global $wpdb;
		$previous_suppression = null;
		try {
			$previous_suppression = $wpdb->suppress_errors();
			$location = self::generation_location();
			if ( null === $location ) { return false; }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- distinguish missing from malformed without unserializing.
			$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . $location[0] . ' WHERE option_name = %s LIMIT 1', self::GENERATION_OPTION ) );
			return self::publish_generation( null, null !== $raw ) && null !== self::read_generation();
		} catch ( \Throwable $error ) { return false; }
		finally { if ( null !== $previous_suppression ) { $wpdb->suppress_errors( $previous_suppression ); } }
	}

	public static function rotate( ?int $network = null ): bool {
		if ( self::$tearing_down ) { return false; }
		self::$invalidated = true;
		return self::publish_generation( $network, true );
	}

	private static function publish_generation( ?int $network, bool $replace ): bool {
		global $wpdb;
		$previous_suppression = null;
		try {
			$previous_suppression = $wpdb->suppress_errors();
			$location = self::generation_location( $network );
			if ( null === $location ) { return false; }
			$bytes = random_bytes( 16 );
			if ( ! is_string( $bytes ) || 16 !== strlen( $bytes ) ) { return false; }
			$token = bin2hex( $bytes );
			$sql = 'INSERT ' . ( $replace ? '' : 'IGNORE ' ) . 'INTO ' . $location[0] . " (option_name,option_value,autoload) VALUES (%s,%s,'no')";
			if ( $replace ) { $sql .= " ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'"; }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic scalar publication under the existing unique option_name key.
			if ( false === $wpdb->query( $wpdb->prepare( $sql, self::GENERATION_OPTION, $token ) ) ) { return false; }
			$current = (int) get_current_blog_id();
			$switch = is_multisite() && $current !== $location[1];
			try {
				if ( $switch ) { wp_cache_switch_to_blog( $location[1] ); }
				wp_cache_delete( self::GENERATION_OPTION, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'notoptions', 'options' );
			} finally { if ( $switch ) { wp_cache_switch_to_blog( $current ); } }
			return true;
		} catch ( \Throwable $error ) { return false; }
		finally { if ( null !== $previous_suppression ) { $wpdb->suppress_errors( $previous_suppression ); } }
	}

	public static function option_changed( string $name ): void {
		if ( in_array( $name, array( 'xspeed_options', 'xspeed_overridden_constants', 'active_plugins', 'stylesheet', 'template' ), true ) || 0 === strpos( $name, 'xspeed_module_' ) ) { self::rotate(); }
	}

	public static function network_option_changed( string $name, ?int $network = null ): void {
		if ( 'active_sitewide_plugins' === $name || 'xspeed_overridden_constants' === $name || 0 === strpos( $name, 'xspeed_module_' ) ) { self::rotate( $network ); }
	}

	public static function upgrader_complete( $upgrader, $options ): void {
		if ( is_array( $options ) && in_array( $options['type'] ?? '', array( 'plugin', 'theme' ), true )
			&& is_object( $upgrader ) && isset( $upgrader->result ) && $upgrader->result && ! is_wp_error( $upgrader->result ) ) { self::rotate_all_networks(); }
	}

	public static function automatic_updates_complete( $results ): void {
		if ( ! is_array( $results ) || empty( $results ) ) { return; }
		foreach ( array( 'plugin', 'theme', 'core' ) as $type ) {
			if ( empty( $results[ $type ] ) || ! is_array( $results[ $type ] ) ) { continue; }
			foreach ( $results[ $type ] as $item ) {
				$result = is_object( $item ) && isset( $item->result ) ? $item->result : true;
				if ( ! is_wp_error( $result ) && ! empty( $result ) ) { self::rotate_all_networks(); return; }
			}
		}
	}

	private static function rotate_all_networks(): void {
		if ( ! is_multisite() ) { self::rotate(); return; }
		$networks = get_networks( array( 'fields' => 'ids', 'number' => 0 ) );
		if ( ! is_array( $networks ) || empty( $networks ) ) { self::rotate(); return; }
		foreach ( $networks as $network ) { $network = (int) $network; if ( $network > 0 ) { self::rotate( $network ); } }
	}

	/** Validate each field before any untrusted option value is retained. */
	public static function valid_record( $record, $sig, $generation, $version, $now ): bool {
		$keys = array( 'provider', 'source', 'header', 'classification', 'config_sig', 'generation', 'version', 'mark_at', 'observed_at', 'expires_at' );
		if ( ! is_array( $record ) || count( $record ) !== count( $keys ) || array_diff( $keys, array_keys( $record ) ) ) { return false; }
		foreach ( array_slice( $keys, 0, 7 ) as $key ) { if ( ! is_string( $record[ $key ] ) ) { return false; } }
		if ( ! in_array( $record['provider'], array( 'cloudflare', 'google' ), true ) || ! in_array( $record['source'], self::SOURCES, true )
			|| ( 'google' === $record['provider'] && in_array( $record['source'], array( 'plugin', 'xcloud-cfe' ), true ) ) ) { return false; }
		if ( 'cf-edge-cache' === $record['header'] ) {
			if ( 'cloudflare' !== $record['provider'] || 'apo_permission' !== $record['classification'] ) { return false; }
		} elseif ( ! in_array( $record['header'], array( 'cloudflare-cdn-cache-control', 'cdn-cache-control', 'cache-control' ), true )
			|| 'positive_shared_freshness' !== $record['classification'] || ( 'google' === $record['provider'] && 'cloudflare-cdn-cache-control' === $record['header'] ) ) { return false; }
		if ( ! preg_match( '/\A[a-f0-9]{64}\z/D', $record['config_sig'] ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $record['generation'] )
			|| ! self::valid_version( $record['version'] ) || $record['config_sig'] !== $sig || $record['generation'] !== $generation || $record['version'] !== $version ) { return false; }
		foreach ( array( 'mark_at', 'observed_at', 'expires_at' ) as $key ) { if ( ! is_int( $record[ $key ] ) ) { return false; } }
		return is_int( $now ) && 0 <= $record['mark_at'] && $record['mark_at'] <= $record['observed_at'] && $record['observed_at'] <= $now
			&& $record['observed_at'] <= PHP_INT_MAX - self::RECORD_TTL && $record['expires_at'] === $record['observed_at'] + self::RECORD_TTL && $record['expires_at'] > $now;
	}

	public static function clean_records( $envelope, $sig, $generation, $version, $now ): array {
		$clean = array( 'schema' => self::SCHEMA, 'records' => array() );
		if ( ! is_array( $envelope ) || count( $envelope ) !== 2 || ! isset( $envelope['schema'], $envelope['records'] )
			|| self::SCHEMA !== $envelope['schema'] || ! is_array( $envelope['records'] ) || count( $envelope['records'] ) > 8
			|| array_diff( array_keys( $envelope['records'] ), self::REASONS ) ) { return $clean; }
		foreach ( self::REASONS as $reason ) {
			if ( isset( $envelope['records'][ $reason ] ) && self::valid_record( $envelope['records'][ $reason ], $sig, $generation, $version, $now ) ) { $clean['records'][ $reason ] = $envelope['records'][ $reason ]; }
		}
		$encoded = json_encode( $clean, JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) && strlen( $encoded ) <= self::MAX_RECORD_BYTES ? $clean : array( 'schema' => self::SCHEMA, 'records' => array() );
	}

	public static function read_record(): ?array {
		global $wpdb;
		$previous_suppression = null;
		try {
			if ( is_object( $wpdb ) && method_exists( $wpdb, 'suppress_errors' ) ) { $previous_suppression = $wpdb->suppress_errors(); }
			$sig = self::signature(); $generation = self::read_generation();
			if ( null === $sig || null === $generation ) { return null; }
			$clean = self::clean_records( get_option( self::RECORDS_OPTION, null ), $sig, $generation, XSPEED_VERSION, time() );
			$newest = null;
			foreach ( self::REASONS as $reason ) {
				$record = $clean['records'][ $reason ] ?? null;
				if ( $record && ( null === $newest || $record['observed_at'] > $newest['record']['observed_at'] ) ) { $newest = array( 'reason' => $reason, 'record' => $record ); }
			}
			return $newest;
		} catch ( \Throwable $error ) { return null; }
		finally { if ( null !== $previous_suppression ) { $wpdb->suppress_errors( $previous_suppression ); } }
	}

	private static function persist_conflict( array $snapshot, array $classification, int $now ): bool {
		global $wpdb;
		$previous_suppression = null;
		try {
			if ( self::$tearing_down || self::$attempted ) { return false; }
			self::$attempted = true;
			if ( self::CONFLICT !== $classification['result'] || ! in_array( $snapshot['reason'], self::REASONS, true ) || $now > PHP_INT_MAX - self::RECORD_TTL ) { return false; }
			$record = array( 'provider' => $snapshot['provider'], 'source' => $snapshot['source'], 'header' => $classification['selected_header'], 'classification' => $classification['classification'], 'config_sig' => $snapshot['config_sig'], 'generation' => $snapshot['generation'], 'version' => $snapshot['version'], 'mark_at' => $snapshot['mark_at'], 'observed_at' => $now, 'expires_at' => $now + self::RECORD_TTL );
			if ( ! self::valid_record( $record, $snapshot['config_sig'], $snapshot['generation'], XSPEED_VERSION, $now ) || self::read_generation() !== $snapshot['generation'] ) { return false; }
			$previous_suppression = $wpdb->suppress_errors();
			$missing = new \stdClass();
			$existing = get_option( self::RECORDS_OPTION, $missing );
			$clean = self::clean_records( $existing, $snapshot['config_sig'], $snapshot['generation'], XSPEED_VERSION, $now );
			$reason = $snapshot['reason'];
			if ( isset( $clean['records'][ $reason ] ) && $now - $clean['records'][ $reason ]['observed_at'] < self::THROTTLE ) { return false; }
			$clean['records'][ $reason ] = $record;
			$encoded = json_encode( $clean, JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_RECORD_BYTES ) { return false; }
			return $existing === $missing ? add_option( self::RECORDS_OPTION, $clean, '', false ) : update_option( self::RECORDS_OPTION, $clean, false );
		} catch ( \Throwable $error ) { return false; }
		finally { if ( null !== $previous_suppression ) { $wpdb->suppress_errors( $previous_suppression ); } }
	}
	private static bool $tearing_down = false;
	private static bool $booted = false;
	private static bool $observed = false;
	private static bool $invalidated = false;
	private static ?string $baseline = null;
	private static ?array $snapshot = null;
	private static ?array $capture = null;
	private static ?array $decision = null;
	private static int $mark_depth = 0;
	private static int $policy_depth = 0;
	private static string $preserved_control = self::ABSENT;

	/** End capture and invalidation for the rest of this teardown request. */
	public static function begin_teardown(): void {
		self::$tearing_down = true;
		self::$invalidated = true;
		self::$observed = true;
		self::$snapshot = null;
		self::$capture = null;
		self::$decision = null;
	}

	public static function boot(): void {
		if ( self::$tearing_down || self::$booted ) { return; }
		self::$booted = true;
		self::$baseline = self::read_generation();
		add_action( 'shutdown', array( self::class, 'observe' ), PHP_INT_MAX );
		foreach ( array( 'added_option' => 2, 'updated_option' => 3, 'deleted_option' => 1 ) as $hook => $arity ) { add_action( $hook, array( self::class, 'option_changed' ), 10, $arity ); }
		add_action( 'add_site_option', static function ( $name, $value, $network ) { self::network_option_changed( $name, (int) $network ); }, 10, 3 );
		add_action( 'update_site_option', static function ( $name, $value, $old, $network ) { self::network_option_changed( $name, (int) $network ); }, 10, 4 );
		add_action( 'delete_site_option', static function ( $name, $network ) { self::network_option_changed( $name, (int) $network ); }, 10, 2 );
		foreach ( array( 'activated_plugin' => 2, 'deactivated_plugin' => 2, 'switch_theme' => 3 ) as $hook => $arity ) { add_action( $hook, static function () { self::rotate(); }, 10, $arity ); }
		add_action( 'upgrader_process_complete', array( self::class, 'upgrader_complete' ), 10, 2 );
		add_action( '_core_updated_successfully', static function () { self::rotate_all_networks(); }, 10, 1 );
		add_action( 'automatic_updates_complete', array( self::class, 'automatic_updates_complete' ), 10, 1 );
	}

	/** Capture only the outer final mark, never a nested policy calculation. */
	public static function begin_mark(): void {
		++self::$mark_depth;
		if ( 1 !== self::$mark_depth || self::$observed ) { return; }
		self::$snapshot = null; self::$capture = null; self::$decision = null;
		if ( ! self::$booted || self::$invalidated || null === self::$baseline || ! in_array( php_sapi_name(), array( 'fpm-fcgi', 'apache2handler', 'cli-server' ), true ) ) { return; }
		$sent = headers_sent();
		$sig = self::signature();
		if ( null !== $sig ) { self::$capture = array( 'config_sig' => $sig, 'blog' => (int) get_current_blog_id(), 'sent_before_resolution' => $sent ); }
	}

	public static function begin_policy(): void {
		++self::$policy_depth;
		if ( 1 === self::$policy_depth && 1 === self::$mark_depth && null !== self::$capture ) { self::$decision = null; self::$preserved_control = self::ABSENT; }
	}

	public static function end_policy(): void { --self::$policy_depth; }

	public static function preserve_cache_control( array $lines ): void {
		if ( 1 !== self::$policy_depth || 1 !== self::$mark_depth || null === self::$capture ) { return; }
		$values = array();
		foreach ( $lines as $line ) { if ( 0 === stripos( $line, 'cache-control:' ) ) { $values[] = substr( $line, 14 ); } }
		self::$preserved_control = self::family_state( $values );
	}

	public static function resolved_hold( array $detected, string $reason, string $context ): void {
		if ( 1 !== self::$policy_depth || 1 !== self::$mark_depth || null === self::$capture || 'request' !== $context ) { return; }
		if ( ! isset( $detected['provider'], $detected['source'], $detected['confidence'] ) || ! in_array( $detected['provider'], array_merge( self::PROVIDERS, array( '' ) ), true ) || ! in_array( $detected['source'], array_merge( self::SOURCES, array( 'context' ) ), true ) || ! in_array( $detected['confidence'], array( 'confirmed', 'proxy', 'none' ), true ) ) { return; }
		self::$decision = array( 'provider' => $detected['provider'], 'source' => $detected['source'], 'confidence' => $detected['confidence'], 'reason' => in_array( $reason, self::REASONS, true ) ? $reason : 'other' );
	}

	public static function end_mark( array $headers, string $status ): void {
		try {
			if ( 1 !== self::$mark_depth || self::$observed || null === self::$capture || null === self::$decision || self::$invalidated ) { return; }
			$intent = self::intent( $headers, self::$decision['provider'], self::$preserved_control );
			if ( null === $intent || self::$capture['blog'] !== (int) get_current_blog_id() || self::$capture['config_sig'] !== self::signature() ) { return; }
			self::$snapshot = array_merge( self::$capture, self::$decision, array( 'schema' => self::SCHEMA, 'version' => XSPEED_VERSION, 'generation' => self::$baseline, 'intent' => $intent, 'status' => $status, 'mark_at' => time(), 'sent_at_mark' => headers_sent() ) );
		} finally {
			if ( 1 === self::$mark_depth ) { self::$capture = null; self::$decision = null; self::$preserved_control = self::ABSENT; }
			--self::$mark_depth;
		}
	}

	private static function intent( array $headers, string $provider, string $preserved ): ?array {
		$values = array(); $lines = array();
		foreach ( $headers as $name => $value ) { $values[ strtolower( $name ) ][] = $value; $lines[] = $name . ': ' . $value; }
		$intent = array();
		foreach ( array( 'cache-control' => 'cache_control', 'cdn-cache-control' => 'cdn_cache_control', 'cloudflare-cdn-cache-control' => 'cloudflare_cdn_cache_control' ) as $name => $key ) {
			$intent[ $key ] = self::family_state( $values[ $name ] ?? array() );
			if ( self::INVALID === $intent[ $key ] ) { return null; }
		}
		$intent['apo'] = self::apo_state( $values['cf-edge-cache'] ?? array() );
		$intent['preserved_control'] = $preserved;
		if ( self::INVALID === $intent['apo'] || self::INVALID === $preserved ) { return null; }
		if ( self::ABSENT !== $preserved ) {
			// Only its class survives the existing stronger-header read point.
			$lines[] = 'Cache-Control: ' . ( self::FORBID === $preserved ? 'private' : 'public' );
		}
		if ( self::NO_CONFLICT === self::classify( $lines, $provider )['result'] ) { return $intent; }
		if ( 'cloudflare' === $provider && self::HOLD === $intent['apo'] && self::ABSENT === $intent['cache_control'] && self::ABSENT === $intent['cdn_cache_control'] && self::ABSENT === $intent['cloudflare_cdn_cache_control'] && self::ABSENT === $preserved ) { return $intent; }
		return null;
	}

	public static function observe(): void {
		if ( self::$observed ) { return; }
		self::$observed = true;
		$snapshot = self::$snapshot;
		if ( null === $snapshot || self::$invalidated || ! in_array( php_sapi_name(), array( 'fpm-fcgi', 'apache2handler', 'cli-server' ), true )
			|| ! in_array( $snapshot['provider'], array( 'cloudflare', 'google' ), true ) || ! in_array( $snapshot['source'], self::SOURCES, true ) || 'none' === $snapshot['confidence']
			|| $snapshot['sent_before_resolution'] || $snapshot['sent_at_mark'] || ! headers_sent() || $snapshot['blog'] !== (int) get_current_blog_id() || $snapshot['config_sig'] !== self::signature() ) { return; }
		$classification = self::classify( headers_list(), $snapshot['provider'] );
		if ( self::CONFLICT === $classification['result'] ) { self::persist_conflict( $snapshot, $classification, time() ); }
	}
}
