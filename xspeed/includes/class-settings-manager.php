<?php
/**
 * Settings_Manager — per-module typed settings storage, validation, and
 * versioned migrations.
 *
 * Storage layout: one wp_option per module under the key
 * `xspeed_module_<slug>`. The option value is an associative array that
 * also carries a `_version` field (the module VERSION at the time of last
 * write) so migrations know what schema produced the stored data.
 *
 * The pre-Module v1 settings (the global cache_enabled / minify_* /
 * gzip_enabled / cache_expiry / excluded_urls) keep living in
 * `xspeed_options` under the existing Settings class — Settings_Manager
 * does not touch them. When v1 features are refactored into Modules,
 * they'll migrate from `xspeed_options` to their per-module options as
 * part of that PR.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Settings_Manager {

	public const OPTION_PREFIX = 'xspeed_module_';

	/**
	 * Marker prefixing a secret value that has been encrypted at rest. A stored
	 * value without this prefix is legacy plaintext (or empty) and is read back
	 * verbatim — so the encryption rollout is lazy and non-destructive.
	 */
	private const SECRET_CIPHER_PREFIX = 'xsenc:v1:';

	/**
	 * Bullet run embedded in a masked secret hint. Also the write-preserve
	 * sentinel: an incoming value containing it (or an empty string) is treated
	 * as "the client is echoing the mask, keep the stored secret" — so saving an
	 * unrelated field on the same panel never wipes the credential. (#115)
	 */
	public const SECRET_MASK_BULLETS = '••••';

	/**
	 * Read settings for a module slug. Returns defaults merged with stored
	 * values + the schema applied (unknown keys stripped). Always safe to
	 * call before activation — returns pure defaults if nothing is stored.
	 */
	public static function get( string $slug ): array {
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return array();
		}
		$schema   = $module->settings_schema();
		$defaults = self::defaults_from_schema( $schema );
		$stored   = get_option( self::option_key( $slug ), array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$merged = array_merge( $defaults, $stored );

		// Strip keys not in schema; coerce types to what the schema declares.
		$clean = array();
		foreach ( $schema as $key => $spec ) {
			$clean[ $key ] = array_key_exists( $key, $merged )
				? self::coerce( $merged[ $key ], $spec )
				: ( $spec['default'] ?? null );
		}

		// Carry through any out-of-schema keys the module explicitly preserves
		// (e.g. the REST-cache route `rules` array) so a schema-driven save
		// doesn't silently drop them. (FBS-82408)
		foreach ( $module->preserved_keys() as $key ) {
			if ( array_key_exists( $key, $stored ) ) {
				$clean[ $key ] = $stored[ $key ];
			}
		}

		return $clean;
	}

	/**
	 * Validate input against the module's schema, merge over stored values,
	 * and persist. Returns the final clean array. Unknown keys are stripped
	 * silently. Out-of-range / wrong-type values fall back to the previous
	 * stored value (or default).
	 */
	public static function update( string $slug, array $input ): array {
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return array();
		}
		$schema  = $module->settings_schema();
		$current = self::get( $slug );

		// An MCP agent must not silently rewrite credentials — repointing the
		// Cloudflare or object-cache backend at an attacker endpoint — unless the
		// connection was explicitly granted the `configure` scope. Strip secret
		// fields from an unprivileged MCP write here so every write path (the
		// update_settings tool AND run_command → CLI) is covered at one choke
		// point. The tool handler surfaces the refusal as a clear error. (#116)
		if ( self::mcp_write_blocked() ) {
			foreach ( $schema as $key => $spec ) {
				if ( self::is_secret_field( $key, $spec ) ) {
					unset( $input[ $key ] );
				}
			}
		}

		$clean = $current;
		foreach ( $schema as $key => $spec ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			// A secret field whose incoming value is the masked placeholder means
			// the client is echoing back what get_public() sent, not setting a new
			// credential — keep the stored value so an unrelated save on the same
			// panel never wipes the key. An empty value is NOT a mask echo: it's a
			// deliberate clear and flows through to remove the credential. (#115)
			if ( self::is_secret_field( $key, $spec ) && self::is_masked_secret( (string) $input[ $key ] ) ) {
				continue;
			}
			[ $value, $valid ] = self::validate_field( $input[ $key ], $spec );
			if ( $valid ) {
				$clean[ $key ] = $value;
			}
			// Invalid → keep $current[$key]. We do not throw; REST layer can
			// add its own strict-mode validation that 400s on invalid input.
		}

		// Carry through out-of-schema keys the module explicitly preserves when
		// they arrive in the INPUT — not only when already stored. Otherwise a
		// caller that routes through update() to SET a preserved key (e.g. a
		// migration/profile writing `mobile_separate_review`) has it silently
		// stripped, because it isn't in $current yet. (FBS-83144)
		foreach ( $module->preserved_keys() as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = $input[ $key ];
			}
		}

		// Change annotation (issue #45): every real mutation — from the UI,
		// REST, CLI, or an MCP agent — lands in the activity log with the
		// old→new diff and its source channel, so the dashboard can tell the
		// causal story ("expiry raised → hit ratio climbed").
		//
		// $clean holds plaintext secrets (carried from $current, which get()
		// decrypts, or freshly validated). Log + diff BEFORE encrypting, so the
		// change annotation compares like-for-like (log_changes redacts secret
		// values by key anyway). The encrypted copy is persisted below. (#115)
		//
		// Pass the FULL schema, not just its keys — log_changes() needs each
		// field's `label` to write "Disable Dashicons on Frontend" instead of
		// `disable_dashicons_frontend`. The schema was already in scope here
		// and was simply being discarded. (#88)
		self::log_changes( $slug, $current, $clean, $schema );

		// Encrypt at rest ONLY fields explicitly typed `secret`. This must match
		// coerce(), which decrypts only for `type === 'secret'` — encrypting a
		// merely name-matched `string` field (a credential a module author typed
		// as string) would store ciphertext that the string coercer then hands
		// back verbatim, breaking the engine. Such fields are still masked and
		// write-preserved via the broader is_secret_field() (masking a plaintext
		// is always safe); they just aren't encrypted until retyped to `secret`.
		$stored = $clean;
		foreach ( $schema as $key => $spec ) {
			if ( 'secret' === ( $spec['type'] ?? '' ) ) {
				$stored[ $key ] = self::encrypt_for_storage( (string) ( $stored[ $key ] ?? '' ) );
			}
		}
		$stored['_version'] = $module->version();
		update_option( self::option_key( $slug ), $stored );

		// Return the PUBLIC view: real non-secret values, masked secrets. This
		// is the REST/CLI/MCP response, so it must never carry credentials. (#115)
		return self::get_public( $slug );
	}

	/**
	 * The public, safe-to-serialize view of a module's settings: identical to
	 * get() except every secret field is replaced by a masked hint (first/last
	 * few chars, never the middle). This is what the REST GET handler, the MCP
	 * read tools, and the dashboard bootstrap payload return — get() itself
	 * stays plaintext for the engine.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_public( string $slug ): array {
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return array();
		}
		$settings = self::get( $slug );
		foreach ( $module->settings_schema() as $key => $spec ) {
			if ( self::is_secret_field( $key, $spec ) && array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = self::mask_secret_value( (string) $settings[ $key ] );
			}
		}
		return $settings;
	}

	/**
	 * Record changed schema fields as one activity event. No-op when
	 * nothing actually changed (idempotent re-saves stay silent).
	 *
	 * @param string $slug   Module slug.
	 * @param array  $before Settings before the write.
	 * @param array  $after  Settings after the write.
	 * @param array  $schema Full settings schema — used for each field's label.
	 */
	private static function log_changes( string $slug, array $before, array $after, array $schema ): void {
		if ( ! class_exists( '\\XSpeed\\Activity_Log' ) ) {
			return;
		}
		$diffs = array();
		foreach ( array_keys( $schema ) as $key ) {
			$old = $before[ $key ] ?? null;
			$new = $after[ $key ] ?? null;
			if ( $old === $new ) {
				continue;
			}

			// The schema already declares a human label for every field — the
			// same one rendered a few inches away on the settings screen. The
			// feed used the raw storage key instead, so users read
			// `disable_dashicons_frontend` rather than "Disable Dashicons on
			// Frontend". Fall back to the key when a schema has no label, so
			// an entry is never blank. (#88)
			$label = isset( $schema[ $key ]['label'] ) && is_string( $schema[ $key ]['label'] ) && '' !== $schema[ $key ]['label']
				? $schema[ $key ]['label']
				: $key;

			if ( self::is_redacted_key( $key ) ) {
				// Never record the value itself — the annotation is served to
				// the dashboard by the trend endpoints, so anything written
				// here is readable by any user who can load the dashboard.
				$diffs[] = sprintf( '%s changed', $label );
				continue;
			}
			$diffs[] = sprintf( '%s %s→%s', $label, self::describe_value( $old ), self::describe_value( $new ) );
		}
		if ( empty( $diffs ) ) {
			return;
		}
		Activity_Log::record(
			'settings_changed',
			sprintf( '%s: %s (via %s)', self::module_label( $slug ), implode( ', ', array_slice( $diffs, 0, 5 ) ), self::source_channel() )
		);
	}

	/**
	 * A module's display name for the activity feed, e.g. `gzip` →
	 * "Compression".
	 *
	 * Resolved through the module registry rather than a lookup table here,
	 * so Pro modules (feed-cache, search-cache, …) get their labels from the
	 * same path — Pro persists through this class and contributes no logging
	 * code of its own.
	 *
	 * Falls back to the raw slug when the module isn't registered or declares
	 * no label; an entry is never blank.
	 */
	private static function module_label( string $slug ): string {
		if ( ! class_exists( '\\XSpeed\\Module_Registry' ) ) {
			return $slug;
		}
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return $slug;
		}
		$meta = $module->ui_metadata();
		return ( isset( $meta['label'] ) && is_string( $meta['label'] ) && '' !== $meta['label'] )
			? $meta['label']
			: $slug;
	}

	/**
	 * Setting keys whose VALUE must never reach the activity log. The log is
	 * surfaced by the dashboard trend endpoints, so anything recorded here is
	 * readable by any user who can load the dashboard.
	 *
	 * Matched on the key name rather than the value, because a credential is
	 * indistinguishable from an ordinary string once it's been stringified.
	 * Pure — unit-tested.
	 *
	 * @param string $key Schema key, e.g. 'api_token'.
	 */
	public static function is_secret_key( string $key ): bool {
		// `license_key` is matched explicitly: the pattern requires `api_key`
		// rather than a bare `key` so that `key_prefix` (an ordinary,
		// useful-to-see setting) isn't swallowed, which left a real license
		// key printing in plaintext.
		return 1 === preg_match( '/(token|password|secret|api_key|license_key|passwd|private_key|credential)/i', $key );
	}

	/**
	 * Setting keys whose value is withheld from the activity feed.
	 *
	 * Secrets (above) plus infrastructure IDENTIFIERS. `redis_password` was
	 * correctly redacted while `redis_user`, `redis_host` and `key_prefix`
	 * were written out in full — and the feed is served to any user who can
	 * load the dashboard, not just admins (see the trend endpoints).
	 *
	 * A Redis hostname and username are most of a credential, and they
	 * describe internal infrastructure that has no business being readable by
	 * a subscriber. The feed's job — "this setting changed, when, and by
	 * whom" — is served without printing the value. (#88)
	 *
	 * Deliberately matched on the key NAME: once stringified, a hostname is
	 * indistinguishable from any other short string. Pure — unit-tested.
	 *
	 * @param string $key Schema key, e.g. 'redis_host'.
	 */
	public static function is_redacted_key( string $key ): bool {
		if ( self::is_secret_key( $key ) ) {
			return true;
		}

		// Deliberately an explicit list rather than a broad word match. A
		// pattern like /(host|user|prefix|port)/ also swallows
		// `bypass_user_agents`, `preconnect_hosts` and `excluded_urls` —
		// ordinary user-facing settings whose values are exactly what makes
		// the feed useful. Over-redacting is a quieter failure than leaking,
		// but it is still a failure.
		//
		// Scoped to connection details and account identifiers. A new backend
		// or provider setting must be added here consciously — see the
		// schema-coverage test that walks every registered module and fails
		// on an unreviewed key.
		$identifiers = array(
			// Object-cache backends.
			'redis_host',
			'redis_port',
			'redis_user',
			'redis_socket',
			'redis_database',
			'memcached_host',
			'memcached_port',
			'memcached_user',
			'key_prefix',
			// Cloudflare. The same reasoning that withholds redis_user /
			// redis_host applies at least as strongly here: an account email
			// plus a full Zone ID together identify the account and the exact
			// zone. api_token / api_key are already covered by
			// is_secret_key(); these two were the gap.
			'email',
			'zone_id',
		);

		/**
		 * Setting keys whose value is withheld from the activity feed.
		 *
		 * @param string[] $identifiers Keys to redact, on top of is_secret_key().
		 */
		$identifiers = (array) apply_filters( 'xspeed_activity_redacted_keys', $identifiers );

		return in_array( strtolower( $key ), array_map( 'strtolower', $identifiers ), true );
	}

	/**
	 * Whether a schema field holds credential material. A field is secret when
	 * it declares `type => 'secret'` (the explicit, preferred marker) OR its key
	 * name matches the credential pattern (is_secret_key) — the backstop that
	 * catches a credential a module author forgot to type, so a leak can't open
	 * just because a field was declared `string`.
	 *
	 * @param string $key  Schema field key.
	 * @param array  $spec Field spec from settings_schema().
	 */
	public static function is_secret_field( string $key, array $spec ): bool {
		return ( ( $spec['type'] ?? '' ) === 'secret' ) || self::is_secret_key( $key );
	}

	/**
	 * The subset of $input keys that are secret fields for this module's schema.
	 * Used by the MCP update_settings tool to name exactly which fields it
	 * refused. Returns [] for an unknown module.
	 *
	 * @param string              $slug  Module slug.
	 * @param array<string,mixed> $input Proposed settings patch.
	 * @return string[]
	 */
	public static function secret_keys_in( string $slug, array $input ): array {
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return array();
		}
		$schema = $module->settings_schema();
		$out    = array();
		foreach ( $input as $key => $value ) {
			if ( isset( $schema[ $key ] ) && self::is_secret_field( $key, $schema[ $key ] ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Classify an input payload against a module's schema WITHOUT writing
	 * anything: which keys would be applied, which are unknown, and which are
	 * in-schema but carry a value the validator rejects.
	 *
	 * update() walks the SCHEMA rather than the input, so a key with no schema
	 * entry is never iterated — never written, never mentioned. And an
	 * in-schema key whose value fails validation is dropped deliberately
	 * ("REST layer can add its own strict-mode validation"), which CLI and MCP
	 * never traverse. Both therefore reported success over a write that did
	 * not happen; the realistic case is an agent sending
	 * `cache_enabled` to the `cache` module — a no-op reported as done. (#206)
	 *
	 * Pure: no side effects, so callers can decide to refuse BEFORE writing.
	 * update()'s own signature is deliberately unchanged — a dozen callers
	 * depend on it returning the settings array.
	 *
	 * @param string              $slug  Module slug.
	 * @param array<string,mixed> $input Proposed values.
	 * @return array{applied:string[],unknown:string[],invalid:string[]}
	 */
	public static function inspect_input( string $slug, array $input ): array {
		$out = array(
			'applied' => array(),
			'unknown' => array(),
			'invalid' => array(),
		);

		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			// Unknown module: the caller reports that separately, and every key
			// is by definition unapplied.
			$out['unknown'] = array_keys( $input );
			return $out;
		}

		$schema    = $module->settings_schema();
		$preserved = $module->preserved_keys();

		foreach ( $input as $key => $value ) {
			// Out-of-schema keys a module explicitly preserves are written
			// verbatim by update(), so they count as applied, not unknown.
			if ( in_array( $key, $preserved, true ) ) {
				$out['applied'][] = $key;
				continue;
			}
			if ( ! isset( $schema[ $key ] ) ) {
				$out['unknown'][] = $key;
				continue;
			}
			$spec = $schema[ $key ];
			// A masked secret echo is a deliberate "keep what's stored", not a
			// failed write — update() skips it by design, so don't report it.
			if ( self::is_secret_field( $key, $spec ) && self::is_masked_secret( (string) $value ) ) {
				$out['applied'][] = $key;
				continue;
			}
			[ , $valid ] = self::validate_field( $value, $spec );
			if ( $valid ) {
				$out['applied'][] = $key;
			} else {
				$out['invalid'][] = $key;
			}
		}

		return $out;
	}

	/**
	 * Where a key the caller asked for actually lives, when it isn't in the
	 * module's schema. Turns "unknown key" into a pointer.
	 *
	 * `cache_enabled` is the case worth naming: it is deliberately outside the
	 * cache module's schema because it drives the drop-in install, so the most
	 * natural command an agent issues to turn caching on is a silent no-op.
	 * (#206)
	 *
	 * @param string $key Rejected input key.
	 * @return string Human-readable hint, or '' when there's nothing useful.
	 */
	public static function hint_for_unknown_key( string $key ): string {
		$hints = array(
			'cache_enabled' => 'page caching is not a module setting — it installs the drop-in. Use the dashboard toggle, the REST route /xspeed/v1/cache/toggle, or the MCP `toggle_cache` tool.',
			'gzip_enabled'  => 'this moved to the `gzip` module — try `--values=\'{"enabled":true}\'` against module `gzip`.',
		);
		return $hints[ $key ] ?? '';
	}

	/**
	 * Schema keys closest to a rejected key, so a typo gets a pointer rather
	 * than a bare refusal. Levenshtein over the schema, nearest three. (#206)
	 *
	 * @param string $slug Module slug.
	 * @param string $key  Rejected input key.
	 * @return string[] Suggested key names, nearest first.
	 */
	public static function did_you_mean( string $slug, string $key ): array {
		$module = Module_Registry::get( $slug );
		if ( ! $module ) {
			return array();
		}
		$scored = array();
		foreach ( array_keys( $module->settings_schema() ) as $candidate ) {
			$distance = levenshtein( $key, (string) $candidate );
			// Only near-misses: beyond a third of the key's length it's a
			// different word, and listing it would be noise.
			if ( $distance <= max( 3, (int) floor( strlen( $key ) / 3 ) ) ) {
				$scored[ (string) $candidate ] = $distance;
			}
		}
		asort( $scored );
		return array_slice( array_keys( $scored ), 0, 3 );
	}

	/**
	 * Masked hint for a stored secret: first 4 + bullets + last 4 (mirrors the
	 * support-snapshot license masking), or all-bullets for a short secret, or
	 * '' when unset. Enough to confirm "a key is saved, ending 4f2a" without
	 * disclosing it. Deterministic — unit-tested.
	 */
	public static function mask_secret_value( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( strlen( $value ) <= 8 ) {
			return str_repeat( '•', 8 );
		}
		return substr( $value, 0, 4 ) . self::SECRET_MASK_BULLETS . substr( $value, -4 );
	}

	/**
	 * Whether an incoming write value is the masked placeholder the client is
	 * echoing back, rather than a real new secret — i.e. it still carries the
	 * mask bullets. A genuine credential never contains the bullet run, so this
	 * can't swallow a real key. update() uses it to keep the stored secret.
	 *
	 * An EMPTY string is NOT a mask echo — it's a deliberate clear, so it flows
	 * through to storage and removes the credential. The dashboard always
	 * re-sends the masked hint (with bullets) on an unrelated save, never an
	 * empty string, so this still can't wipe a key by accident. (#115, QA B7)
	 */
	public static function is_masked_secret( string $value ): bool {
		return false !== strpos( $value, self::SECRET_MASK_BULLETS );
	}

	/**
	 * Encrypt a plaintext secret for storage. Idempotent: an already-encrypted
	 * value (carrying the marker) is returned unchanged, so module migrations
	 * can call this over existing rows without double-wrapping. Empty stays
	 * empty. Used by update() and by the per-module encrypt-on-upgrade
	 * migrations. (#115)
	 */
	public static function encrypt_for_storage( string $value ): string {
		if ( '' === $value || 0 === strpos( $value, self::SECRET_CIPHER_PREFIX ) ) {
			return $value;
		}
		return self::encrypt( $value );
	}

	/**
	 * 32-byte encryption key derived from this site's WordPress salts, so the
	 * ciphertext is bound to the install and never stored alongside the data.
	 * Rotating the salts makes existing secrets undecryptable — decrypt() then
	 * returns '' (treated as "unset", the user re-enters the key) rather than
	 * fataling. Uses AUTH_KEY + SECURE_AUTH_SALT, falling back to wp_salt().
	 */
	private static function secret_key(): string {
		$material = '';
		if ( defined( 'AUTH_KEY' ) ) {
			$material .= (string) AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_SALT' ) ) {
			$material .= (string) SECURE_AUTH_SALT;
		}
		if ( '' === $material && function_exists( 'wp_salt' ) ) {
			$material = (string) wp_salt( 'secure_auth' );
		}
		return sodium_crypto_generichash( 'xspeed-secret-v1|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Authenticated-encrypt a non-empty plaintext with libsodium's secretbox
	 * (XSalsa20-Poly1305). The random nonce is prepended to the ciphertext and
	 * the whole thing base64'd behind the version marker. libsodium ships in
	 * PHP core from 7.2 (our floor is 7.4); if it were somehow unavailable we
	 * store plaintext rather than fatal — masking on read still applies.
	 */
	private static function encrypt( string $plain ): string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return $plain;
		}
		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, self::secret_key() );
		} catch ( \Throwable $e ) {
			return $plain;
		}
		return self::SECRET_CIPHER_PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding for ciphertext, not obfuscation.
	}

	/**
	 * Reverse encrypt(). A value without the marker is legacy plaintext (or
	 * empty) and is returned as-is — the encryption rollout is lazy, so reads
	 * keep working before the first re-save. A marked value that fails to
	 * decrypt (salts rotated, row tampered) returns '' so the caller behaves as
	 * "no credential set", never a fatal.
	 */
	private static function decrypt( string $stored ): string {
		if ( 0 !== strpos( $stored, self::SECRET_CIPHER_PREFIX ) ) {
			return $stored;
		}
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, strlen( self::SECRET_CIPHER_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding our own ciphertext envelope.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::secret_key() );
		} catch ( \Throwable $e ) {
			return '';
		}
		return ( false === $plain ) ? '' : $plain;
	}

	/**
	 * Whether the current write is an MCP write that may NOT touch secret
	 * fields — i.e. it came in over MCP and the connection lacks the `configure`
	 * grant. Keeps credential writes off the default MCP surface (#116). Guarded
	 * by class_exists so Settings_Manager never hard-depends on the MCP module.
	 */
	private static function mcp_write_blocked(): bool {
		if ( ! class_exists( '\\XSpeed\\Modules\\Mcp\\Mcp_Tools' ) ) {
			return false;
		}
		return \XSpeed\Modules\Mcp\Mcp_Tools::in_dispatch()
			&& ! \XSpeed\Modules\Mcp\Mcp_Tools::can_configure();
	}

	/** Compact human form of a setting value for the change log. */
	private static function describe_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'on' : 'off';
		}
		if ( is_array( $value ) ) {
			return count( $value ) . ' item' . ( 1 === count( $value ) ? '' : 's' );
		}
		if ( null === $value ) {
			return '—';
		}
		$str = (string) $value;
		return strlen( $str ) > 40 ? substr( $str, 0, 39 ) . '…' : $str;
	}

	/**
	 * Which surface performed this write. MCP is detected via the tool
	 * dispatcher's in-flight flag; the dashboard UI writes through REST.
	 */
	private static function source_channel(): string {
		if ( class_exists( '\\XSpeed\\Modules\\Mcp\\Mcp_Tools' ) && \XSpeed\Modules\Mcp\Mcp_Tools::in_dispatch() ) {
			return 'mcp';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'dashboard';
		}
		return 'admin';
	}

	/**
	 * Run any pending schema migrations for a module. Called by
	 * Module_Registry before boot(). Idempotent — migrations only run once
	 * per version bump because we persist `_version` after each successful
	 * migration step.
	 */
	public static function run_migrations( Module $module ): void {
		$migrations = $module->migrations();
		if ( empty( $migrations ) ) {
			return;
		}
		$option_key = self::option_key( $module->slug() );
		$stored     = get_option( $option_key, null );
		if ( null === $stored ) {
			return; // fresh install — no data to migrate.
		}
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$from = isset( $stored['_version'] ) ? (string) $stored['_version'] : '0.0.0';

		// Sort migrations by version ascending.
		uksort(
			$migrations,
			static function ( $a, $b ) {
				return version_compare( (string) $a, (string) $b );
			}
		);

		$dirty = false;
		foreach ( $migrations as $target => $callable ) {
			$target = (string) $target;
			if ( version_compare( $from, $target, '>=' ) ) {
				continue;
			}
			$migrated = call_user_func( $callable, $stored );
			if ( is_array( $migrated ) ) {
				$stored             = $migrated;
				$stored['_version'] = $target;
				$from               = $target;
				$dirty              = true;
			}
		}

		if ( $dirty ) {
			update_option( $option_key, $stored );
		}
	}

	/**
	 * Coerce a stored value to the schema's declared type — used on read
	 * to defend against options edited by hand or imported across versions.
	 */
	private static function coerce( $value, array $spec ) {
		$type = $spec['type'] ?? 'string';
		switch ( $type ) {
			case 'bool':
				return (bool) $value;
			case 'int':
				$v = (int) $value;
				if ( isset( $spec['min'] ) ) {
					$v = max( (int) $spec['min'], $v );
				}
				if ( isset( $spec['max'] ) ) {
					$v = min( (int) $spec['max'], $v );
				}
				return $v;
			case 'enum':
				return in_array( $value, $spec['options'] ?? array(), true )
					? $value
					: ( $spec['default'] ?? null );
			case 'list':
				if ( ! is_array( $value ) ) {
					return $spec['default'] ?? array();
				}
				return array_values( array_filter( $value, 'is_scalar' ) );
			case 'url':
				// A deliberately-cleared URL must read back as empty, not snap
				// to the schema default — `?:` swallowed the empty string and
				// resurrected the default on every read. (#197)
				if ( '' === trim( (string) $value ) ) {
					return '';
				}
				$url = esc_url_raw( (string) $value );
				if ( ! empty( $spec['endpoint'] ) && ! self::is_endpoint_url( $url ) ) {
					// A hand-edited or pre-validation stored value that can't
					// be called reads back as empty, so consumers see "no
					// endpoint configured" instead of silently failing on it.
					return $spec['default'] ?? '';
				}
				return $url ?: ( $spec['default'] ?? '' );
			case 'media':
				// Media-library image URL. Empty is a valid "no image" state.
				// esc_url_raw alone lets through any safe URL (…/evil.txt,
				// non-images) which then renders as a broken <img>; require it
				// to look like an image and drop anything else to empty.
				$media = esc_url_raw( (string) $value );
				return ( '' === $media || self::is_image_url( $media ) ) ? $media : '';
			case 'secret':
				// A credential (API token, password, …). Stored encrypted at
				// rest (SECRET_CIPHER_PREFIX). Reading decrypts to plaintext so
				// the engine — Cloudflare purge, Redis auth — gets the real
				// value; the masking that keeps it out of REST/MCP/dashboard
				// payloads happens later, at the output boundary (get_public),
				// never here. Legacy unencrypted values pass straight through.
				return self::decrypt( (string) $value );
			case 'string':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Validate one field; returns [ coerced_value, was_valid ]. Distinct
	 * from coerce() because validate is strict (out-of-range int is
	 * INVALID) while coerce is forgiving (clamps to range).
	 */
	private static function validate_field( $value, array $spec ): array {
		$type = $spec['type'] ?? 'string';
		switch ( $type ) {
			case 'bool':
				// Strictly validate (don't blindly (bool)-cast). A plain cast
				// treated every non-empty string as true, so a client sending
				// the string "false" (or any junk text) silently ENABLED the
				// toggle. filter_var with FILTER_NULL_ON_FAILURE accepts the
				// real bool-ish forms (true/false, 1/0, "1"/"0", "true"/
				// "false", "yes"/"no", "on"/"off") and returns null for
				// anything else — which we report as invalid so the previous
				// stored value is kept, mirroring int/enum. (FBS-82158)
				$b = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if ( null === $b ) {
					return array( null, false );
				}
				return array( $b, true );
			case 'int':
				if ( ! is_numeric( $value ) ) {
					return array( null, false );
				}
				$v = (int) $value;
				if ( isset( $spec['min'] ) && $v < (int) $spec['min'] ) {
					return array( null, false );
				}
				if ( isset( $spec['max'] ) && $v > (int) $spec['max'] ) {
					return array( null, false );
				}
				return array( $v, true );
			case 'enum':
				$ok = in_array( $value, $spec['options'] ?? array(), true );
				return array( $ok ? $value : null, $ok );
			case 'list':
				if ( ! is_array( $value ) ) {
					return array( null, false );
				}
				$item_type = $spec['item_type'] ?? 'string';
				$out       = array();
				foreach ( $value as $item ) {
					// Skip non-scalar items (e.g. a nested array). Casting one
					// with (string) emits an "Array to string conversion"
					// warning and stores the garbage literal "Array" — coerce()
					// already filters these via is_scalar; mirror it here.
					// (FBS-82172 Bug 4)
					if ( ! is_scalar( $item ) ) {
						continue;
					}
					if ( 'url' === $item_type ) {
						$u = esc_url_raw( (string) $item );
						if ( $u ) {
							$out[] = $u;
						}
					} else {
						$out[] = sanitize_text_field( (string) $item );
					}
				}
				return array( $out, true );
			case 'url':
				// Empty is a valid "cleared" state, not invalid input — same
				// as `media` below. Reporting it invalid made the previous
				// stored value stick, so clearing a URL field appeared to
				// "come back" a moment later when the save echo landed. (#197)
				if ( '' === trim( (string) $value ) ) {
					return array( '', true );
				}
				$u = esc_url_raw( (string) $value );
				if ( ! empty( $spec['endpoint'] ) && ! self::is_endpoint_url( $u ) ) {
					return array( null, false );
				}
				return array( $u, (bool) $u );
			case 'media':
				// Empty (cleared logo) is valid; any non-empty value must be a
				// safe URL after esc_url_raw AND look like an image, so a
				// non-image URL (…/evil.txt) is rejected rather than stored to
				// render as a broken <img>.
				$m = esc_url_raw( (string) $value );
				if ( '' === (string) $value ) {
					return array( '', true );
				}
				$ok = '' !== $m && self::is_image_url( $m );
				return array( $ok ? $m : '', $ok );
			case 'secret':
				// Validated like a string; encryption is applied uniformly in
				// update() after this returns, so a secret carried over from the
				// current stored value gets encrypted the same way a freshly
				// entered one does. Masked placeholders never reach here — update()
				// filters them out before validating. (#115)
				return array( sanitize_text_field( (string) $value ), true );
			case 'string':
			default:
				return array( sanitize_text_field( (string) $value ), true );
		}
	}

	/**
	 * Whether a URL is something an HTTP client could actually call.
	 *
	 * `url` fields flagged `endpoint => true` in the schema hold URLs the
	 * plugin will POST to (a critical-CSS generator, an unused-CSS
	 * generator). esc_url_raw() alone is not enough for those:
	 * `nahid@wpdeveloper.com` — an email address pasted into the field —
	 * comes back as `http://nahid@wpdeveloper.com`, a syntactically valid
	 * URL whose userinfo is the whole address, and the feature then fails
	 * silently for as long as nobody rereads the field. Require a real
	 * http(s) scheme and a host, and refuse userinfo outright: no
	 * endpoint of ours authenticates that way, and accepting it is how
	 * that address survived in production. (xspeed-pro#77)
	 */
	public static function is_endpoint_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		if ( ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( '' === (string) ( $parts['host'] ?? '' ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a URL looks like an image — used to gate `media` fields so a
	 * non-image URL can't be stored and later rendered as a broken <img>
	 * (e.g. the white-label brand logo, FBS-82222). Tests the path extension
	 * against the known image types (query/fragment tolerated). Not a content
	 * check — a cheap, deterministic guard that pairs with the front-end
	 * onError fallback; the Media Library picker already yields conforming
	 * http(s) upload URLs. (data: URIs are stripped by esc_url_raw upstream,
	 * since `data` isn't an allowed protocol, so they never reach here.)
	 */
	private static function is_image_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url ) {
			return false;
		}
		// Drop the query string + fragment so ?ver=… / #frag don't defeat the
		// extension test (e.g. logo.webp?v=2). Plain string ops — no WP URL
		// parser dependency on this low-level coercion path.
		$path = (string) preg_replace( '/[?#].*$/', '', $url );
		return (bool) preg_match( '/\.(jpe?g|png|gif|svg|webp|avif|ico|bmp)$/i', $path );
	}

	private static function defaults_from_schema( array $schema ): array {
		$out = array();
		foreach ( $schema as $key => $spec ) {
			$out[ $key ] = $spec['default'] ?? null;
		}
		return $out;
	}

	private static function option_key( string $slug ): string {
		return self::OPTION_PREFIX . $slug;
	}
}
