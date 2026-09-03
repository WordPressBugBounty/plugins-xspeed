<?php
/**
 * Migration — read settings from other popular caching plugins and
 * translate them into xSpeed equivalents.
 *
 * Each source plugin has its own importer that returns a single
 * normalized patch shape:
 *
 *   array<string,array> // module-slug → settings patch
 *
 * which feeds straight into update_option('xspeed_module_<slug>')
 * via the same write path the Recommendations module uses.
 *
 * Importers are pure: detect() reads the options table, returns
 * what it found (or null if the source plugin's options aren't
 * present). plan() turns that raw read into the patch. apply()
 * writes it. preview() returns plan() without writing — used by
 * the React panel for the "what would import" diff view.
 *
 * Adding a new source = adding a private detect_*() + plan_*()
 * pair, then wiring them in `sources()`.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Migration {

	/**
	 * Public list of source plugins with metadata for the UI:
	 *   [ id => [ label, detect_cb, plan_cb ] ]
	 */
	public static function sources(): array {
		return array(
			'wp-rocket' => array(
				'label'  => 'WP Rocket',
				'detect' => array( __CLASS__, 'detect_wp_rocket' ),
				'plan'   => array( __CLASS__, 'plan_wp_rocket' ),
			),
			'w3-total-cache' => array(
				'label'  => 'W3 Total Cache',
				'detect' => array( __CLASS__, 'detect_w3tc' ),
				'plan'   => array( __CLASS__, 'plan_w3tc' ),
			),
			'wp-super-cache' => array(
				'label'  => 'WP Super Cache',
				'detect' => array( __CLASS__, 'detect_wpsc' ),
				'plan'   => array( __CLASS__, 'plan_wpsc' ),
			),
			'litespeed-cache' => array(
				'label'  => 'LiteSpeed Cache',
				'detect' => array( __CLASS__, 'detect_litespeed' ),
				'plan'   => array( __CLASS__, 'plan_litespeed' ),
			),
		);
	}

	/** Site option holding the list of source ids already imported. */
	private const IMPORTED_OPTION = 'xspeed_migration_imported';

	/**
	 * Source id → plugin file. The ONE home for this map.
	 *
	 * Public because MigrationModule needs the same mapping to deactivate a
	 * source, and it used to keep a private copy. The two were identical, but
	 * a drift would have been silent and destructive: this map drives the
	 * `active` flag that decides whether the panel shows its confirmation, and
	 * the module's copy drove the actual deactivation. Diverge them and
	 * status() reports active:false, the panel skips the confirm, and the
	 * plugin is deactivated anyway — a genuinely silent deactivation, one edit
	 * away. (#189)
	 */
	public const PLUGIN_FILE = array(
		'wp-rocket'       => 'wp-rocket/wp-rocket.php',
		'w3-total-cache'  => 'w3-total-cache/w3-total-cache.php',
		'wp-super-cache'  => 'wp-super-cache/wp-cache.php',
		'litespeed-cache' => 'litespeed-cache/litespeed-cache.php',
	);

	/** The plugin file for a source id, or '' when the id is unknown. */
	public static function plugin_file( string $source ): string {
		return self::PLUGIN_FILE[ $source ] ?? '';
	}

	/**
	 * Site option recording a source that was imported but LEFT RUNNING.
	 *
	 * Holds `{ id, label }` for the last such import, or is absent. Not a
	 * history — the risk is "a second page cache is live right now", which is
	 * a single present-tense fact, not a log. (#189 AC4)
	 */
	private const ACTIVE_SOURCE_OPTION = 'xspeed_migration_source_active';

	/**
	 * Remember that an import finished with the source plugin still on, so the
	 * warning can outlive the import screen.
	 *
	 * Self-clearing rather than sticky: it stores only while the plugin is
	 * genuinely still active, and drops the record as soon as it is not. A
	 * user who deactivates the old plugin by hand from the Plugins screen
	 * never told us — so a stored flag we only cleared on OUR own deactivation
	 * path would nag forever about a plugin that is already off.
	 *
	 * @param string $source Source id.
	 * @param string $label  Human label for the source.
	 */
	public static function remember_active_source( string $source, string $label ): void {
		$file = self::plugin_file( $source );
		if ( '' === $file ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! is_plugin_active( $file ) ) {
			self::forget_active_source( $source );
			return;
		}

		update_option(
			self::ACTIVE_SOURCE_OPTION,
			array(
				'id'    => $source,
				'label' => '' !== $label ? $label : $source,
			)
		);
	}

	/** Drop the record — the source is off, or was never on. */
	public static function forget_active_source( string $source = '' ): void {
		if ( '' !== $source ) {
			$stored = get_option( self::ACTIVE_SOURCE_OPTION, array() );
			if ( is_array( $stored ) && ( $stored['id'] ?? '' ) !== $source ) {
				return;
			}
		}
		delete_option( self::ACTIVE_SOURCE_OPTION );
	}

	/**
	 * The imported-but-still-running source, or null.
	 *
	 * Re-checks the plugin's live state on every read, so the warning
	 * disappears by itself the moment the user deactivates the plugin —
	 * whether they did it through us or from the Plugins screen.
	 *
	 * @return array{id:string,label:string}|null
	 */
	public static function pending_source(): ?array {
		$stored = get_option( self::ACTIVE_SOURCE_OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored['id'] ) ) {
			return null;
		}

		$file = self::plugin_file( (string) $stored['id'] );
		if ( '' === $file ) {
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! is_plugin_active( $file ) ) {
			// Resolved itself — stop warning, and stop re-checking.
			delete_option( self::ACTIVE_SOURCE_OPTION );
			return null;
		}

		return array(
			'id'    => (string) $stored['id'],
			'label' => (string) ( $stored['label'] ?? $stored['id'] ),
		);
	}

	/** Record a source as imported (idempotent). */
	public static function mark_imported( string $id ): void {
		$done = (array) get_option( self::IMPORTED_OPTION, array() );
		if ( ! in_array( $id, $done, true ) ) {
			$done[] = $id;
			update_option( self::IMPORTED_OPTION, array_values( $done ) );
		}
	}

	/**
	 * For each source, return { id, label, detected, value_count, mapped_count,
	 * imported, active }.
	 *   - `detected`     true when the source plugin's settings are present.
	 *   - `value_count`  raw number of keys in the source's own config — NOT
	 *                    how many we import; kept for diagnostics only.
	 *   - `mapped_count` how many settings the importer ACTUALLY writes into
	 *                    xSpeed (the honest number to show users).
	 *   - `imported`     true once this source has been imported (so the panel
	 *                    shows it as done, not as a fresh Import target).
	 *   - `active`       whether the source plugin is still active.
	 */
	public static function status(): array {
		$imported = (array) get_option( self::IMPORTED_OPTION, array() );
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( self::sources() as $id => $spec ) {
			$raw      = call_user_func( $spec['detect'] );
			$detected = is_array( $raw );
			$mapped   = 0;
			if ( $detected ) {
				$patch = call_user_func( $spec['plan'], $raw );
				if ( is_array( $patch ) ) {
					$mapped = self::count_meaningful( $patch );
				}
			}
			$file = self::PLUGIN_FILE[ $id ] ?? '';
			$out[] = array(
				'id'           => $id,
				'label'        => $spec['label'],
				'detected'     => $detected,
				'value_count'  => $detected ? count( $raw ) : 0,
				'mapped_count' => $mapped,
				'imported'     => in_array( $id, $imported, true ),
				'active'       => '' !== $file && is_plugin_active( $file ),
			);
		}
		return $out;
	}

	/**
	 * Count the settings in a plan patch that will MEANINGFULLY change the
	 * config — i.e. the ones actually enabled / non-empty in the source.
	 *
	 * A `plan_*` patch always emits every mapped field, including the ones the
	 * source has turned OFF (`false`) or left empty. Counting those inflated
	 * the "settings available to migrate" number — a source with 2 settings on
	 * still reported ~12 because the importer listed every false mapping.
	 * Disabled (`false`) booleans and empty arrays/strings/zeros contribute
	 * nothing on import, so they're excluded from the count. (FBS-82449)
	 *
	 * @param array<string,mixed> $patch Plan patch (module slug => values).
	 * @return int Number of enabled / non-empty settings.
	 */
	private static function count_meaningful( array $patch ): int {
		$count = 0;
		foreach ( $patch as $vals ) {
			if ( is_array( $vals ) ) {
				$count += count( self::meaningful_values( $vals ) );
			}
		}
		return $count;
	}

	/**
	 * Filter one module's plan values down to the ones that meaningfully change
	 * the config: enabled (`true`) booleans, non-empty arrays, and non-empty
	 * scalars. Disabled toggles, empty lists, and zero/empty scalars are
	 * dropped — they represent "nothing to import" for that setting. Shared by
	 * the count (status) and the write (apply) so both agree. (FBS-82449)
	 *
	 * @param array<string,mixed> $values One module's mapped values.
	 * @return array<string,mixed> Only the meaningful entries.
	 */
	private static function meaningful_values( array $values ): array {
		$out = array();
		foreach ( $values as $key => $value ) {
			if ( is_bool( $value ) ) {
				if ( $value ) {
					$out[ $key ] = $value; // Only an enabled toggle imports.
				}
			} elseif ( is_array( $value ) ) {
				if ( ! empty( $value ) ) {
					$out[ $key ] = $value; // Non-empty list (e.g. excluded_urls).
				}
			} elseif ( '' !== $value && null !== $value && 0 !== $value && '0' !== $value ) {
				$out[ $key ] = $value; // Non-empty scalar (e.g. cache_expiry).
			}
			// NOTE: a scalar 0 is deliberately dropped — for the numeric
			// settings we import (cache_expiry, timeouts, db index) it means
			// "unset" in every source, and emitting it would put a
			// meaningless value in the plan the user is asked to confirm.
			// The trap: this also swallows a legitimate 0 from a TRI-STATE
			// key. WP Super Cache's `wp_cache_not_logged_in` is 0/1/2, where
			// 0 means "cache everyone" — a real choice. read_wpsc_config_file()
			// already preserves it as an int, but any future mapping of that
			// key must bypass this helper or the user's choice is discarded
			// silently. (#222 F4)
		}
		return $out;
	}

	/**
	 * Convert a source plugin's seconds-based lifetime to xSpeed's
	 * hour-granular `cache_expiry`, rounding to nearest within our 1–720
	 * bounds. See the call site for why flooring was wrong. (#218)
	 */
	private static function seconds_to_hours( int $seconds ): int {
		if ( $seconds <= 0 ) {
			return 24;
		}
		return (int) max( 1, min( 720, (int) round( $seconds / 3600 ) ) );
	}

	/**
	 * Map a source plugin's preload interval (seconds) onto xSpeed's
	 * schedule enum. Anything under a day rounds to `hourly` — our finest
	 * grain — rather than being dropped for not matching exactly. (#218)
	 */
	private static function seconds_to_schedule( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return 'manual';
		}
		// Literal seconds rather than WEEK_IN_SECONDS / DAY_IN_SECONDS: this
		// planner is a pure function and is unit-tested without a WP bootstrap.
		if ( $seconds >= 604800 ) {
			return 'weekly';
		}
		if ( $seconds >= 86400 ) {
			return 'daily';
		}
		return 'hourly';
	}

	/**
	 * Human-readable notes about values this import cannot carry across
	 * exactly, for the preview panel. Empty when everything maps cleanly.
	 *
	 * A silent lossy conversion is the thing the user cannot audit: a
	 * 15-minute page-cache lifetime arriving as 1 hour looks deliberate.
	 * (#218)
	 *
	 * @param string $source_id Source plugin id.
	 * @param array  $raw       Source plugin's own config.
	 * @return string[] One note per lossy conversion.
	 */
	public static function preview_notes( string $source_id, array $raw ): array {
		$notes = array();

		if ( 'w3-total-cache' === $source_id && isset( $raw['pgcache.lifetime'] ) ) {
			$seconds = (int) $raw['pgcache.lifetime'];
			$hours   = self::seconds_to_hours( $seconds );
			if ( $seconds > 0 && $seconds !== $hours * 3600 ) {
				$notes[] = sprintf(
					/* translators: 1: source lifetime in seconds, 2: imported lifetime in hours. */
					__( 'Page cache lifetime %1$ds cannot be expressed in whole hours — importing as %2$dh.', 'xspeed' ),
					$seconds,
					$hours
				);
			}
		}

		// A value we deliberately DROP needs saying too. Rounding a lifetime
		// was announced while a skipped secret was not, and skipping is the
		// more consequential of the two: the object cache silently fails to
		// connect afterwards and nothing on screen explains why. Only fires
		// when the source really has a secret we cannot read — an empty
		// password is not a loss. (#218 F3)
		if ( 'w3-total-cache' === $source_id ) {
			foreach ( array(
				'objectcache.redis.password'  => __( 'Redis password', 'xspeed' ),
				'dbcache.redis.password'      => __( 'Database-cache Redis password', 'xspeed' ),
				'pgcache.redis.password'      => __( 'Page-cache Redis password', 'xspeed' ),
			) as $key => $label ) {
				if ( empty( $raw[ $key ] ) || ! is_string( $raw[ $key ] ) ) {
					continue;
				}
				if ( null !== self::decrypt_w3tc_secret( $raw[ $key ] ) ) {
					continue; // readable — it will be imported.
				}
				$notes[] = sprintf(
					/* translators: %s: human label for the secret that could not be read. */
					__( '%s is encrypted and could not be read — it will not be imported. Enter it again in xSpeed after importing.', 'xspeed' ),
					$label
				);
			}
		}


		// A TLS endpoint imports its host and port, but NOT its transport:
		// xSpeed has no TLS option in the object-cache schema and
		// Redis_Client::connect() only ever builds "tcp://{$host}:{$port}".
		// Keeping the scheme in the host made that failure silent AND
		// unconditional — `tcp://tls://cache.internal:6380` fails to resolve
		// the literal host "tls" — so the scheme is stripped and the loss is
		// announced instead, the same way an unreadable secret is. The import
		// then connects wherever plain TCP is also open, and says so where it
		// cannot. (#224)
		if ( 'w3-total-cache' === $source_id ) {
			foreach ( array( 'redis', 'memcached' ) as $engine ) {
				$servers = $raw[ 'objectcache.' . $engine . '.servers' ] ?? null;
				if ( empty( $servers ) || ! is_array( $servers ) ) {
					continue;
				}
				$first = (string) ( $servers[0] ?? '' );
				if ( ! preg_match( '#^([a-z][a-z0-9+.-]*)://#i', $first, $m ) ) {
					continue;
				}
				$notes[] = sprintf(
					/* translators: 1: endpoint scheme, e.g. tls. 2: the endpoint as configured in the source plugin. */
					__( 'Object-cache endpoint %2$s uses %1$s, which xSpeed does not support — importing the host and port only. The cache will connect only if the server also accepts a plain connection.', 'xspeed' ),
					strtoupper( $m[1] ),
					$first
				);
			}
		}
		return $notes;
	}

	/**
	 * Map a source plugin's "separate mobile cache" flag onto the cache patch
	 * WITHOUT enabling xSpeed's mobile_separate. The xSpeed static-file fast
	 * path is device-blind, so mobile_separate=ON disables it — and a source
	 * site that had the flag on very often serves identical HTML to every
	 * device (it was on by habit). Rather than silently kill the fast path on
	 * import, we keep mobile_separate off and, when the source had it on, set
	 * `mobile_separate_review` so the dashboard can prompt the user to turn it
	 * back on only if their site really differs per device.
	 * (FBS-83144 / FBS-83145)
	 *
	 * @param array $patch     The plan patch (modified by reference).
	 * @param bool  $source_on Whether the source plugin had mobile-separate on.
	 */
	private static function map_mobile_separate( array &$patch, bool $source_on ): void {
		if ( ! isset( $patch['cache'] ) || ! is_array( $patch['cache'] ) ) {
			$patch['cache'] = array();
		}
		// Never import as ON; leave the fast path intact.
		$patch['cache']['mobile_separate'] = false;
		if ( $source_on ) {
			$patch['cache']['mobile_separate_review'] = true;
		}
	}

	/**
	 * Return the patch that `apply()` would write, without writing.
	 */
	public static function preview( string $source_id ): ?array {
		$full = self::preview_with_notes( $source_id );

		return null === $full ? null : $full['patch'];
	}

	/**
	 * The preview plan together with the notes that explain it.
	 *
	 * preview_notes() exists to tell the user when a source value could not
	 * be carried over exactly — e.g. a 15-minute W3TC lifetime that xSpeed
	 * can only express in whole hours. It was written but never called, so
	 * the panel showed the rounded number as plain fact and the user had no
	 * way to know their setting had changed. Returning both from one detect
	 * pass keeps them in lockstep. (#224 F2)
	 *
	 * @param string $source_id Source plugin id.
	 * @return array{patch:array,notes:string[]}|null Null when undetected.
	 */
	public static function preview_with_notes( string $source_id ): ?array {
		$src = self::sources()[ $source_id ] ?? null;
		if ( null === $src ) {
			return null;
		}
		$raw = call_user_func( $src['detect'] );
		if ( ! is_array( $raw ) ) {
			return null;
		}

		return array(
			'patch' => call_user_func( $src['plan'], $raw ),
			'notes' => self::preview_notes( $source_id, $raw ),
		);
	}

	/**
	 * Read source settings + write the translated patch. Returns the
	 * per-module write results, same shape as Recommendations::apply.
	 */
	public static function apply( string $source_id ): array {
		$patch = self::preview( $source_id );
		if ( null === $patch ) {
			return array();
		}
		$results = array();
		foreach ( $patch as $slug => $values ) {
			if ( ! is_string( $slug ) || ! is_array( $values ) ) {
				continue;
			}
			// Import only the settings that are actually enabled / non-empty in
			// the source. A plan patch emits every mapped field including the
			// ones the source turned OFF; merging those `false`/empty values
			// would silently DISABLE settings the user already had on in xSpeed.
			// Migration is additive — it never clobbers existing config with a
			// source's disabled value. (FBS-82449)
			$meaningful = self::meaningful_values( $values );
			if ( empty( $meaningful ) ) {
				continue;
			}

			// The page-cache switch is NOT a module setting. It lives in the
			// `xspeed_options` blob, and turning it on means installing the
			// drop-in, setting WP_CACHE and installing the rewrite — work only
			// Cache::toggle() does. Writing it to xspeed_module_cache['enabled']
			// put it in a key that is not in CacheModule::settings_schema() and
			// that nothing reads, so every importer's `cache.enabled` was a
			// dead destination: the import reported success and the site came
			// out of it with caching still off. (#219)
			$applied_enable = null;
			if ( 'cache' === $slug && array_key_exists( 'enabled', $meaningful ) ) {
				$applied_enable = (bool) $meaningful['enabled'];
				unset( $meaningful['enabled'] );
			}

			// Union list values with what is already in EFFECT rather than
			// overwriting them.
			//
			// A source's exclusion list is what IT needed; ours covers cases it
			// handled with separate settings we don't read (W3TC has
			// pgcache.cache.feed, pgcache.reject.request_head, …). Assigning
			// the mapped array straight over ours dropped every xSpeed-specific
			// safety rule — /wp-json/, /xmlrpc.php, ~wp-.*\.php, /feed/ and
			// ~sitemap(_index)?\.xml among them — and feeds and sitemaps
			// started being served from the page cache.
			//
			// Merge against Settings_Manager::get(), not the raw option: module
			// defaults live in the SCHEMA, so on a fresh install the stored
			// option is empty and a union with it would still lose all of them.
			// The class comment above already promised migration is additive;
			// before this that only held for scalars. (#218)
			$effective = (array) Settings_Manager::get( $slug );
			foreach ( $meaningful as $key => $value ) {
				if ( ! is_array( $value ) ) {
					continue;
				}
				$existing = $effective[ $key ] ?? array();
				if ( ! is_array( $existing ) || empty( $existing ) ) {
					continue;
				}
				$meaningful[ $key ] = array_values( array_unique( array_merge( $existing, $value ) ) );
			}

			// Write through Settings_Manager::update(), not a raw
			// update_option().
			//
			// The raw write skipped everything that makes a settings write
			// safe: schema coercion, per-field range/type validation, secret
			// encryption at rest, `_version` maintenance, and log_changes() —
			// so imported values landed unvalidated and no per-module change
			// ever reached the activity log, only the single "Imported
			// settings from…" line. It also means a bad value from a source
			// plugin could be stored where the UI could never have set it.
			//
			// update() returns the module's public view, which is also the
			// honest success signal: it reflects what is actually stored,
			// where update_option()'s return value conflates "no change
			// needed" with "write failed". (#218)
			$stored = Settings_Manager::update( $slug, $meaningful );

			// Did the intent land? Not "is the stored value identical" — for a
			// list we deliberately UNION with the existing rules, so the stored
			// array is legitimately bigger than what we passed in. Assert each
			// imported value is PRESENT instead, which is what "applied" means
			// and what a lossy or rejected write would fail.
			//
			// Secrets come back masked in the public view, so they are taken on
			// trust rather than compared against plaintext.
			$ok = ! empty( $stored );
			foreach ( $meaningful as $key => $value ) {
				if ( ! array_key_exists( $key, $stored ) ) {
					// A key the module deliberately owns elsewhere (cache.enabled
					// lives in xspeed_options and is applied via Cache::toggle)
					// is not a failed write.
					continue;
				}
				if ( is_string( $stored[ $key ] ) && Settings_Manager::is_masked_secret( $stored[ $key ] ) ) {
					continue;
				}
				if ( is_array( $value ) ) {
					if ( array_diff( $value, (array) $stored[ $key ] ) ) {
						$ok = false;
						break;
					}
					continue;
				}
				if ( $stored[ $key ] != $value ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- values round-trip through the DB and schema coercion, so a stored "1" must still count as an applied true.
					$ok = false;
					break;
				}
			}

			$applied = array_keys( $meaningful );

			if ( null !== $applied_enable ) {
				$state = Cache::toggle( $applied_enable );
				$applied[] = 'enabled';
				// Enabling is only real if the transaction went through. The
				// drop-in alone is not the test: a refusal reports the
				// artifacts already on disk, so a site whose xSpeed drop-in
				// was still installed read a refused write as success.
				if ( ! empty( $state['blocked'] ) ) {
					$ok = false;
				} elseif ( $applied_enable && empty( $state['dropin_installed'] ) ) {
					$ok = false;
				}
			}

			if ( empty( $applied ) ) {
				continue;
			}

			$results[ $slug ] = array(
				'ok'      => $ok,
				'applied' => $applied,
			);

			// Importing an object-cache backend has to actually turn it on.
			//
			// Writing `backend = redis` only records an intention: the drop-in
			// is what makes WordPress use it. Migration never installed one,
			// and on a LiteSpeed/W3TC source it also deactivates the source
			// plugin, which REMOVES that plugin's drop-in — so a site with a
			// working Redis object cache came out of a "successful" import with
			// no persistent object cache at all, and nothing said so.
			//
			// Object_Cache::enable() tests the connection before writing
			// anything, so an unreachable server degrades to a reported
			// failure rather than a broken drop-in. (#218 / #217)
			if ( 'object-cache' === $slug && $ok && ! empty( $meaningful['backend'] ) ) {
				$results[ $slug ] = self::enable_object_cache( $results[ $slug ] );
			}
		}
		// Mark the source imported on the same terms the handover uses.
		// Gating this on completed_successfully() meant a host without Redis
		// — where the object cache can never enable — never recorded the
		// import at all, so the migration notice came straight back after a
		// migration that had worked. (#189)
		if ( self::safe_to_hand_over( $results ) ) {
			self::mark_imported( $source_id );
		}
		return $results;
	}

	/**
	 * Whether an apply result represents a complete, retry-free import.
	 *
	 * A non-empty applied list proves only that a group was attempted. The
	 * source must stay actionable when any attempted group reports failure.
	 *
	 * @param array $results Per-module apply results.
	 */
	public static function completed_successfully( array $results ): bool {
		if ( empty( $results ) ) {
			return false;
		}

		$applied = false;
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) || empty( $result['ok'] ) ) {
				return false;
			}
			if ( ! empty( $result['applied'] ) ) {
				$applied = true;
			}
		}

		return $applied;
	}

	/**
	 * Modules whose failure must NOT block switching the old plugin off.
	 *
	 * `completed_successfully()` is all-or-nothing, which is right for
	 * reporting but wrong as a deactivation gate: it made one optional module
	 * veto the whole handover. The object cache is the case that bites —
	 * enabling it needs a running Redis or Memcached, so on a host without
	 * one it ALWAYS fails, and a site that migrated perfectly was left with
	 * both cache plugins active while the notice had promised otherwise.
	 *
	 * These are additive extras: the site is no worse off without them than
	 * it was before the migration. Page caching is deliberately absent — if
	 * THAT did not take, the old plugin has to stay on. (#189)
	 */
	private const OPTIONAL_FOR_HANDOVER = array(
		'object-cache',
		'cdn',
		'preloader',
	);

	/**
	 * Is it safe to switch the source plugin off?
	 *
	 * Stricter than "did anything import" and looser than "did everything":
	 * every module that is not an optional extra must have applied cleanly,
	 * and at least one must have applied at all. A failure in an optional
	 * module is reported to the user either way — it just does not veto the
	 * handover the notice already promised.
	 *
	 * @param array<string,array<string,mixed>> $results apply() output.
	 */
	public static function safe_to_hand_over( array $results ): bool {
		if ( empty( $results ) ) {
			return false;
		}

		$applied = false;
		foreach ( $results as $slug => $result ) {
			if ( ! is_array( $result ) ) {
				return false;
			}
			if ( empty( $result['ok'] ) ) {
				if ( ! in_array( (string) $slug, self::OPTIONAL_FOR_HANDOVER, true ) ) {
					return false;
				}
				continue;
			}
			if ( ! empty( $result['applied'] ) ) {
				$applied = true;
			}
		}

		return $applied;
	}

	/**
	 * Install the object-cache drop-in for a just-imported backend, and fold
	 * the outcome into that module's result row.
	 *
	 * A failure here is reported, never silent: the import has already told
	 * the user their object cache came across.
	 *
	 * @param array $result The module's result row so far.
	 * @return array The row, with ok/message reflecting the drop-in install.
	 */
	private static function enable_object_cache( array $result ): array {
		if ( ! class_exists( __NAMESPACE__ . '\\Object_Cache' ) ) {
			return $result;
		}

		$opts  = (array) Settings_Manager::get( 'object-cache' );
		$state = Object_Cache::enable( $opts );

		$result['ok']                = ! empty( $state['ok'] );
		$result['object_cache_ready'] = ! empty( $state['ok'] );
		if ( empty( $state['ok'] ) ) {
			// Surfaced by the panel instead of a green "imported" message.
			$result['message'] = (string) ( $state['message'] ?? 'Could not enable the object cache.' );
		}

		return $result;
	}

	// ─────────────────────────── WP Rocket ───────────────────────────

	public static function detect_wp_rocket(): ?array {
		$opt = get_option( 'wp_rocket_settings', null );
		return is_array( $opt ) ? $opt : null;
	}

	/**
	 * Translate WP Rocket's `wp_rocket_settings` array into our module
	 * settings. Only safe-to-port booleans + counts; behaviorally
	 * different toggles (Critical CSS, RUCSS) skip — Pro handles those.
	 *
	 * @param array $r raw wp_rocket_settings.
	 */
	public static function plan_wp_rocket( array $r ): array {
		$patch = array();
		// Page caching.
		// WP Rocket has no master on/off switch — installing and activating it
		// IS enabling page caching, so a detected settings blob means the
		// source site was caching. `cache_logged_user` is NOT that switch: it
		// controls whether LOGGED-IN users get cached pages. Reading it as the
		// master meant the most ordinary WP Rocket configuration of all —
		// caching on, but not for logged-in users (`cache_logged_user = 0`) —
		// imported as caching OFF, the exact inverse of the user's intent. The
		// `! isset` fallback then made a missing key mean ON, so the result was
		// right only by accident. (#222 F2)
		$patch['cache'] = array(
			'enabled' => true,
			// The Cache module's TTL setting is `cache_expiry` (hours), NOT
			// `expiry_hours` — the latter is a dead key nothing reads, so the
			// imported lifetime was silently dropped. Clamp to the same 1–720h
			// range the Cache schema + LiteSpeed importer use. (FBS-83144)
			'cache_expiry' => isset( $r['purge_cron_interval'] ) ? max( 1, min( 720, (int) ( (int) $r['purge_cron_interval'] / 3600 ) ) ) : 24,
		);
		// Excluded URLs / cookies — both are arrays of strings in WP Rocket.
		if ( ! empty( $r['cache_reject_uri'] ) && is_array( $r['cache_reject_uri'] ) ) {
			$patch['cache']['excluded_urls'] = array_values( array_filter( array_map( 'strval', $r['cache_reject_uri'] ) ) );
		}
		if ( ! empty( $r['cache_reject_cookies'] ) && is_array( $r['cache_reject_cookies'] ) ) {
			$patch['cache']['excluded_cookies'] = array_values( array_filter( array_map( 'strval', $r['cache_reject_cookies'] ) ) );
		}

		// Minify.
		$patch['minify'] = array(
			'minify_html' => ! empty( $r['minify_html'] ),
			'minify_css'  => ! empty( $r['minify_css'] ),
			'minify_js'   => ! empty( $r['minify_js'] ),
			'combine_css' => ! empty( $r['minify_concatenate_css'] ),
			'combine_js'  => ! empty( $r['minify_concatenate_js'] ),
			'defer_js'    => ! empty( $r['defer_all_js'] ),
		);

		// Lazy load.
		$patch['lazy'] = array(
			'lazy_images'  => ! empty( $r['lazyload'] ),
			'lazy_iframes' => ! empty( $r['lazyload_iframes'] ),
			'lazy_videos'  => ! empty( $r['lazyload_youtube'] ),
		);

		// Separate Mobile Cache — do NOT import this as ON. WP Rocket's
		// "separate cache files for mobile" is frequently left on by habit even
		// when the site serves identical HTML to every device, and xSpeed's
		// static-file fast path is device-blind — enabling mobile_separate
		// DISABLES it, silently dropping the site from HIT (nginx) to HIT (php).
		// Instead, keep the fast path (mobile_separate stays false) and flag it
		// for review so the dashboard can prompt the user to re-enable it only
		// if their site really differs per device. (FBS-83144 / FBS-83145)
		self::map_mobile_separate( $patch, ! empty( $r['do_caching_mobile_files'] ) );

		// Preloader.
		if ( ! empty( $r['manual_preload'] ) || ! empty( $r['sitemap_preload'] ) ) {
			$patch['preloader'] = array(
				'enabled'  => true,
				'schedule' => 'daily',
			);
			if ( ! empty( $r['sitemap_preload_url'] ) && is_array( $r['sitemap_preload_url'] ) ) {
				$patch['preloader']['sitemap_urls'] = array_values( array_filter( array_map( 'strval', $r['sitemap_preload_url'] ) ) );
			}
		}

		// CDN — WP Rocket stores CDN hosts in cdn_cnames (array).
		if ( ! empty( $r['cdn'] ) && ! empty( $r['cdn_cnames'] ) && is_array( $r['cdn_cnames'] ) ) {
			$first = (string) ( $r['cdn_cnames'][0] ?? '' );
			if ( '' !== $first ) {
				$patch['cdn'] = array(
					'enabled' => true,
					'cdn_url' => $first,
				);
			}
		}

		return $patch;
	}

	// ─────────────────────────── W3 Total Cache ──────────────────────

	public static function detect_w3tc(): ?array {
		// W3 Total Cache does NOT store its config in the options table — it
		// writes a PHP file at wp-content/w3tc-config/master.php whose body
		// is a short PHP guard followed by a JSON blob of dotted-key settings
		// (pgcache.enabled, minify.html.enable, …). Reading w3tc_config /
		// w3tc_master_settings options always returned null, so detection
		// failed on every install. Read + parse the config file instead.
		$cfg = self::read_w3tc_config_file();
		if ( is_array( $cfg ) && ! empty( $cfg ) ) {
			return $cfg;
		}
		// Defensive fallback for any build that did persist an options blob.
		$opt = get_option( 'w3tc_config', null );
		if ( ! is_array( $opt ) ) {
			$opt = get_option( 'w3tc_master_settings', null );
		}
		return is_array( $opt ) ? $opt : null;
	}

	/**
	 * Parse W3TC's master config file into a flat dotted-key array.
	 * Format: a short PHP guard (a php-open, exit, php-close) immediately
	 * followed by a JSON object. We strip everything up to and including the
	 * PHP closing tag, then JSON-decode the remainder.
	 *
	 * @return array|null parsed config, or null if the file is missing/unreadable.
	 */
	private static function read_w3tc_config_file(): ?array {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return null;
		}
		$path = WP_CONTENT_DIR . '/w3tc-config/master.php';
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading another plugin's local config file; WP_Filesystem is overkill for a one-shot read.
		if ( false === $raw || '' === $raw ) {
			return null;
		}
		// Drop the leading PHP guard and decode the JSON tail. The pattern
		// matches up to the first PHP closing tag; built from a char-code so
		// no literal close tag appears in this source file.
		$close_tag = '?' . '>';
		$json      = preg_replace( '/^.*?' . preg_quote( $close_tag, '/' ) . '/s', '', $raw );
		$cfg  = json_decode( trim( (string) $json ), true );
		return is_array( $cfg ) ? $cfg : null;
	}

	public static function plan_w3tc( array $r ): array {
		$patch = array();
		$patch['cache'] = array(
			'enabled' => ! empty( $r['pgcache.enabled'] ),
			// Cache module reads `cache_expiry` (hours), not the dead
			// `expiry_hours` key — see plan_wp_rocket. (FBS-83144)
			// W3TC stores this in SECONDS and sub-hour values are common (900 /
			// 1800 are its own defaults). Integer division floored those to 0
			// and max(1, …) then bumped them to a full hour, so a 5-minute
			// lifetime silently became 12x longer. xSpeed's cache_expiry is
			// hour-granular, so the closest honest answer is to round to
			// nearest and keep the 1-hour floor for anything under 30 minutes.
			// preview_notes() tells the user when the source value could not be
			// represented exactly. (#218)
			'cache_expiry' => isset( $r['pgcache.lifetime'] ) ? self::seconds_to_hours( (int) $r['pgcache.lifetime'] ) : 24,
		);
		$w3_list = static function ( $key ) use ( $r ): array {
			$v = $r[ $key ] ?? null;
			return is_array( $v ) ? array_values( array_filter( array_map( 'strval', $v ) ) ) : array();
		};

		foreach ( array(
			'pgcache.reject.uri'    => 'excluded_urls',
			'pgcache.reject.cookie' => 'excluded_cookies',
			'pgcache.reject.ua'     => 'bypass_user_agents',
		) as $src => $dest ) {
			$vals = $w3_list( $src );
			if ( $vals ) {
				$patch['cache'][ $dest ] = $vals;
			}
		}

		// `pgcache.accept.qs` is deliberately NOT imported. W3TC ships ~100
		// tracking parameters in it by default, so importing it wholesale
		// would bury the user's own additions under a stock list — and
		// apply() unions lists, which would make that permanent. Our own
		// ignored_query_params default already covers the same ground.
		// (#218)

		self::map_mobile_separate( $patch, ! empty( $r['mobile.enabled'] ) );

		// W3TC's minify "method" encodes BOTH operations in one value:
		// 'minify' | 'combine' | 'both'. Combining is on for the latter two.
		$method_combines = static function ( $key ) use ( $r ): bool {
			$m = isset( $r[ $key ] ) ? (string) $r[ $key ] : '';
			return 'combine' === $m || 'both' === $m;
		};

		// The master switch gates every child mapping.
		//
		// W3TC keeps its child defaults POPULATED while `minify.enabled` is
		// off — `minify.css.enable`, `minify.js.enable` and the method fields
		// all read as truthy on a site that has minification deliberately
		// switched off. Reading the children alone therefore imported Minify
		// CSS/JS and Combine CSS/JS as ON for a user who had turned the whole
		// feature off, which can change front-end output and introduce the
		// exact CSS/JS regressions migration is supposed to avoid.
		//
		// Every other W3TC block here already gates this way — pgcache on
		// `pgcache.enabled`, lazy load on `lazyload.enabled`, browser cache on
		// `browsercache.enabled`, object cache on `objectcache.enabled`.
		// Minify was the one that did not. (#218)
		$minify_on = ! empty( $r['minify.enabled'] );

		$patch['minify'] = array(
			'minify_html' => $minify_on && ! empty( $r['minify.html.enable'] ),
			'minify_css'  => $minify_on && ! empty( $r['minify.css.enable'] ),
			'minify_js'   => $minify_on && ! empty( $r['minify.js.enable'] ),
			// There is no `minify.css.combine`; CSS combining lives in the
			// method. JS splits its combine flag across three placements, and
			// any one of them means the user wanted combining.
			'combine_css' => $minify_on && $method_combines( 'minify.css.method' ),
			'combine_js'  => $minify_on && (
				$method_combines( 'minify.js.method' )
				|| ! empty( $r['minify.js.combine.header'] )
				|| ! empty( $r['minify.js.combine.body'] )
				|| ! empty( $r['minify.js.combine.footer'] )
			),
		);

		// ── Lazy load ────────────────────────────────────────────────────
		if ( ! empty( $r['lazyload.enabled'] ) ) {
			$patch['lazy'] = array( 'lazy_images' => true );
			$excluded      = $w3_list( 'lazyload.exclude' );
			if ( $excluded ) {
				$patch['lazy']['excluded_images'] = $excluded;
			}
		}

		// ── Browser cache + compression ──────────────────────────────────
		if ( ! empty( $r['browsercache.enabled'] ) ) {
			$patch['browser-cache'] = array( 'enabled' => true );
			foreach ( array(
				'browsercache.cssjs.lifetime' => 'asset_ttl',
				'browsercache.html.lifetime'  => 'html_ttl',
			) as $src => $dest ) {
				if ( ! empty( $r[ $src ] ) ) {
					$patch['browser-cache'][ $dest ] = (int) $r[ $src ];
				}
			}
			// W3TC has a compression toggle per content type; xSpeed has one
			// switch, so any of them being on means the user wanted GZIP.
			if ( ! empty( $r['browsercache.html.compression'] ) || ! empty( $r['browsercache.cssjs.compression'] ) || ! empty( $r['browsercache.other.compression'] ) ) {
				$patch['gzip'] = array( 'gzip_enabled' => true );
			}
		}

		// ── Preloader (W3TC calls it "cache priming") ────────────────────
		if ( ! empty( $r['pgcache.prime.enabled'] ) ) {
			$patch['preloader'] = array( 'enabled' => true );
			if ( ! empty( $r['pgcache.prime.sitemap'] ) ) {
				$patch['preloader']['sitemap_url'] = (string) $r['pgcache.prime.sitemap'];
			}
			if ( ! empty( $r['pgcache.prime.interval'] ) ) {
				$patch['preloader']['schedule'] = self::seconds_to_schedule( (int) $r['pgcache.prime.interval'] );
			}
			if ( ! empty( $r['pgcache.prime.post.update.enabled'] ) ) {
				$patch['preloader']['warm_on_publish'] = true;
			}
		}

		// ── Bloat ────────────────────────────────────────────────────────
		if ( ! empty( $r['jquerymigrate.disabled'] ) ) {
			$patch['bloat'] = array( 'strip_jquery_migrate' => true );
		}

		if ( ! empty( $r['objectcache.enabled'] ) && ! empty( $r['objectcache.engine'] ) ) {
			$is_memcached = 'memcached' === $r['objectcache.engine'];
			$engine       = $is_memcached ? 'memcached' : 'redis';

			$patch['object-cache'] = array( 'backend' => $engine );

			// W3TC namespaces these by engine — `objectcache.redis.servers` /
			// `objectcache.memcached.servers`. There is no bare
			// `objectcache.servers`, so the host/port branch here could never
			// run for a W3TC source: a site with Redis on a non-default host or
			// port silently fell back to 127.0.0.1:6379. (#218)
			$servers = $r[ 'objectcache.' . $engine . '.servers' ] ?? null;
			if ( ! empty( $servers ) && is_array( $servers ) ) {
				$first = (string) ( $servers[0] ?? '' );

				// A scheme says HOW to connect, not WHERE. Left in place it
				// becomes the hostname: Redis_Client::connect() builds
				// "tcp://{$host}:{$port}", so `tls://redis.example` produces
				// `tcp://tls://redis.example:6380` and resolution fails on the
				// literal host "tls". The same string is also written into the
				// drop-in's WP_REDIS_HOST, so the imported object cache could
				// never connect. A managed Redis on TLS is the common case.
				// Bracketed IPv6 is left alone — stream_socket_client() wants
				// the brackets. (#224)
				$first     = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', $first );
				$separator = strrpos( $first, ':' );
				if ( false !== $separator ) {
					$host = substr( $first, 0, $separator );
					$port = substr( $first, $separator + 1 );
					$patch['object-cache'][ $engine . '_host' ] = $host;
					$patch['object-cache'][ $engine . '_port' ] = (int) $port;
				}
			}

			// Everything else that has a destination in ObjectCacheModule and
			// was previously dropped. A non-zero Redis DB index matters most:
			// the connection would succeed while pointing at the wrong dataset.
			$copy = $is_memcached
				? array( 'objectcache.memcached.persistent' => 'persistent' )
				: array(
					'objectcache.redis.dbid'       => 'redis_database',
					'objectcache.redis.password'   => 'redis_password',
					'objectcache.redis.persistent' => 'persistent',
					'objectcache.redis.timeout'    => 'connection_timeout',
				);
			$numeric_dests = array( 'redis_database', 'connection_timeout' );
			foreach ( $copy as $src => $dest ) {
				if ( ! isset( $r[ $src ] ) || '' === $r[ $src ] ) {
					continue;
				}
				// W3TC stores an unset timeout / db index as 0, which carries no
				// intent — emitting it would put a meaningless value in the plan
				// preview the user is asked to confirm. Only the numeric fields
				// get that treatment: casting a password to int would read
				// "s3cret" as 0 and silently drop it.
				$is_numeric_dest = in_array( $dest, $numeric_dests, true );
				if ( $is_numeric_dest && 0 === (int) $r[ $src ] ) {
					continue;
				}
				// W3 Total Cache 2.8+ encrypts secrets in its config file
				// (Util_Crypto, `enc:v1:` prefix). Copying the ciphertext
				// through would hand Redis a password that can never
				// authenticate — and it is exactly the password-protected
				// sources this mapping exists to serve. Decrypt with W3TC's
				// own helper; if that is unavailable (no crypto key, plugin
				// files already gone), SKIP the field rather than import an
				// unusable value: a missing password is something the user
				// can fix in one edit, a silently wrong one is not. (#224 F1)
				if ( 'redis_password' === $dest ) {
					$secret = self::decrypt_w3tc_secret( (string) $r[ $src ] );
					if ( null === $secret ) {
						continue;
					}
					$patch['object-cache'][ $dest ] = $secret;
					continue;
				}
				$patch['object-cache'][ $dest ] = $is_numeric_dest
					? (int) $r[ $src ]
					: $r[ $src ];
			}
			// W3TC's Cache_Redis only ever calls auth( $password ) — it has no
			// ACL username support — so a W3TC source never carries one and we
			// must not invent a redis_user here.
		}

		return $patch;
	}

	/**
	 * Resolve a W3 Total Cache secret to plaintext.
	 *
	 * W3TC 2.8+ stores secrets encrypted with its own `Util_Crypto`, marked
	 * by an `enc:v1:` prefix. A plaintext value (older W3TC, or a config
	 * written before encryption landed) is returned unchanged.
	 *
	 * Returns null when the value is encrypted but cannot be decrypted —
	 * W3TC's classes are not loadable, or its crypto key is gone. Callers
	 * MUST treat null as "skip this field", never as an empty password:
	 * importing the ciphertext guarantees an auth failure, and importing an
	 * empty string would silently drop a password the source really had.
	 *
	 * @param string $value Raw value from the W3TC config.
	 * @return string|null Plaintext, or null when it cannot be resolved.
	 */
	private static function decrypt_w3tc_secret( string $value ): ?string {
		if ( 0 !== strpos( $value, 'enc:' ) ) {
			return $value;
		}

		if ( ! class_exists( '\\W3TC\\Util_Crypto' ) ) {
			return null;
		}

		// W3TC's method is envelope_decrypt(), NOT decrypt(). Guarding on the
		// wrong name meant method_exists() was false on every install, the
		// helper returned null before it ever ran, and the password was
		// silently dropped from every import — the exact users the decrypt
		// support was written for. Verified against W3TC 2.10.5:
		//
		//   ::decrypt()          MISSING
		//   ::envelope_decrypt() EXISTS
		//   ::is_envelope()      EXISTS
		//
		// Kept as a list so an older/newer W3TC that renames it again
		// degrades to "skip the field" rather than to a fatal. (#218 F1)
		$method = null;
		foreach ( array( 'envelope_decrypt', 'decrypt' ) as $candidate ) {
			if ( method_exists( '\\W3TC\\Util_Crypto', $candidate ) ) {
				$method = $candidate;
				break;
			}
		}
		if ( null === $method ) {
			return null;
		}

		try {
			$plain = \W3TC\Util_Crypto::$method( $value );
		} catch ( \Throwable $e ) {
			return null;
		}

		// A failed decrypt can come back as false/null/'' or as the
		// untouched ciphertext depending on the failure mode. None of those
		// are a usable password.
		if ( ! is_string( $plain ) || '' === $plain || 0 === strpos( $plain, 'enc:' ) ) {
			return null;
		}

		return $plain;
	}

	// ─────────────────────────── WP Super Cache ──────────────────────

	public static function detect_wpsc(): ?array {
		// WP Super Cache stores its settings as PHP globals in
		// wp-content/wp-cache-config.php (NOT the options table — the old
		// get_option('wp_cache_enabled') reads always returned null). Parse
		// the config file for the globals plan_wpsc() needs. If the file
		// doesn't exist yet (plugin active but never configured), fall back
		// to a minimal "active" marker so the source still appears in the UI
		// and a default import is possible.
		$cfg = self::read_wpsc_config_file();
		if ( is_array( $cfg ) && ! empty( $cfg ) ) {
			return $cfg;
		}
		if ( self::plugin_active( 'wp-super-cache/wp-cache.php' ) ) {
			// Active but unconfigured — expose the on/off intent only.
			return array( 'cache_enabled' => defined( 'WPCACHEHOME' ) );
		}
		return null;
	}

	/**
	 * Parse the WP Super Cache config file for the globals we map. The file
	 * is plain PHP assigning `$wp_cache_* = …;` lines; we extract them with a
	 * regex rather than including the file (including it would define
	 * constants / run code in our request).
	 *
	 * @return array|null name => value for the recognised globals, or null.
	 */
	private static function read_wpsc_config_file(): ?array {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return null;
		}
		$path = WP_CONTENT_DIR . '/wp-cache-config.php';
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- one-shot read of another plugin's config file.
		if ( false === $raw || '' === $raw ) {
			return null;
		}
		$out = array();

		// `$cache_enabled` is the master switch and `$super_cache_enabled`
		// selects mod_rewrite mode. WP Super Cache does NOT define
		// `$wp_cache_enabled` — reading that name meant the on/off intent was
		// never populated, plan_wpsc() computed `enabled => false`, and
		// meaningful_values() then dropped the false boolean entirely. A site
		// actively serving cached HTML migrated to caching OFF, silently. (#219)
		$keys = array( 'cache_enabled', 'super_cache_enabled', 'wp_cache_mod_rewrite', 'wp_cache_mobile_enabled', 'wp_cache_make_known_anon' );
		foreach ( $keys as $key ) {
			// Match `$key = 1;` / `$key = '1';` / `$key = true;` etc.
			if ( preg_match( '/\$' . preg_quote( $key, '/' ) . '\s*=\s*([^;]+);/', $raw, $m ) ) {
				$val         = trim( $m[1], " \t'\"" );
				$out[ $key ] = in_array( strtolower( $val ), array( '1', 'true' ), true );
			}
		}

		// Tri-state, so it cannot go through the boolean cast above:
		// 0 = cache everyone, 1 = skip visitors carrying any cookie,
		// 2 = skip logged-in visitors (WPSC's own recommended setting).
		// Casting collapsed 2 to false — the exact inverse of the user's
		// intent — which is harmless only while nothing maps the key. (#219)
		if ( preg_match( '/\$wp_cache_not_logged_in\s*=\s*([^;]+);/', $raw, $m ) ) {
			$out['wp_cache_not_logged_in'] = (int) trim( $m[1], " \t'\"" );
		}

		return ! empty( $out ) ? $out : null;
	}

	/** Thin wrapper so detection works before admin plugin.php is loaded. */
	private static function plugin_active( string $plugin ): bool {
		$active = (array) get_option( 'active_plugins', array() );
		if ( in_array( $plugin, $active, true ) ) {
			return true;
		}
		// Network-activated (multisite).
		$network = (array) get_site_option( 'active_sitewide_plugins', array() );
		return isset( $network[ $plugin ] );
	}

	public static function plan_wpsc( array $r ): array {
		$plan = array(
			'cache' => array(
				// Either flag means WP Super Cache was serving: `cache_enabled`
				// is the master switch, `super_cache_enabled` only picks
				// mod_rewrite over PHP delivery.
				'enabled' => ! empty( $r['cache_enabled'] ) || ! empty( $r['super_cache_enabled'] ),
			),
		);
		// See plan_wp_rocket: never import Separate Mobile Cache as ON — it
		// disables the device-blind static fast path. Flag for review instead.
		self::map_mobile_separate( $plan, ! empty( $r['wp_cache_mobile_enabled'] ) );
		return $plan;
	}

	// ─────────────────────────── LiteSpeed Cache ─────────────────────

	/**
	 * Read LiteSpeed Cache settings into a flat `name => value` array keyed
	 * by LiteSpeed's dotted setting names (cache, cache-mobile, optm-*,
	 * media-*, object-*, cdn-*, …) — the shape plan_litespeed() expects.
	 *
	 * Storage has changed across LiteSpeed versions:
	 *   - v4+ (current): ONE option PER setting, named `litespeed.conf.<name>`
	 *     (e.g. litespeed.conf.cache, litespeed.conf.cache-mobile). There is
	 *     NO single `litespeed.conf` blob — reading that key returns null,
	 *     which is why detection used to fail on every modern install.
	 *   - v3 and earlier: a single serialized array under `litespeed.conf`
	 *     (or the legacy `litespeed-cache-conf`).
	 * We handle all three: try the per-option family first (the common case
	 * today), then fall back to the legacy single-blob options.
	 *
	 * @return array|null raw conf (name => value), or null when absent.
	 */
	public static function detect_litespeed(): ?array {
		global $wpdb;

		// v4+: individual `litespeed.conf.<name>` options. Pull them all and
		// strip the prefix so keys match what plan_litespeed() reads.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time settings-import scan of another plugin's option rows by name prefix; no WP API bulk-reads by option_name LIKE, and caching a single migration-time read is pointless.
		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'litespeed.conf.%'",
			ARRAY_A
		);
		if ( ! empty( $rows ) ) {
			$conf = array();
			foreach ( $rows as $row ) {
				$name = substr( (string) $row['option_name'], strlen( 'litespeed.conf.' ) );
				if ( '' === $name || '_version' === $name ) {
					continue;
				}
				$conf[ $name ] = self::decode_litespeed_value( (string) $row['option_value'] );
			}
			if ( ! empty( $conf ) ) {
				return $conf;
			}
		}

		// v3 / legacy: a single serialized array.
		$opt = get_option( 'litespeed.conf', null );
		if ( ! is_array( $opt ) ) {
			$opt = get_option( 'litespeed-cache-conf', null );
		}
		return is_array( $opt ) && ! empty( $opt ) ? $opt : null;
	}

	/**
	 * Decode one `litespeed.conf.*` option value.
	 *
	 * LiteSpeed v4+ stores its list settings as JSON strings, not
	 * PHP-serialized arrays, so maybe_unserialize() hands the JSON straight
	 * back as a string. plan_litespeed()'s $list() helper then splits it on
	 * newlines — which JSON has none of — producing a ONE-element array
	 * holding the entire blob. Every exclusion rule imported that way is
	 * dead: the list no longer matches anything, so cart, checkout and
	 * account pages become publicly cacheable while the import reports
	 * success. (#217)
	 *
	 * Try JSON first for anything shaped like it, and fall back to
	 * maybe_unserialize() so v3 / legacy installs keep working.
	 */
	private static function decode_litespeed_value( string $raw ) {
		$trimmed = trim( $raw );
		if ( '' !== $trimmed && ( '[' === $trimmed[0] || '{' === $trimmed[0] ) ) {
			$decoded = json_decode( $trimmed, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return maybe_unserialize( $raw );
	}

	/**
	 * Translate LiteSpeed's `litespeed.conf` into xSpeed module patches.
	 * LiteSpeed uses dotted keys; values are mostly bool-ish (1/0/'1').
	 * We map only the settings that have a clean xSpeed equivalent and
	 * leave the rest untouched so nothing is silently mis-imported.
	 *
	 * @param array $r raw litespeed.conf.
	 */
	public static function plan_litespeed( array $r ): array {
		$on = static function ( $key ) use ( $r ): bool {
			return isset( $r[ $key ] ) && ! empty( $r[ $key ] );
		};
		// LiteSpeed list fields are stored as either a newline-delimited
		// string or an array. Normalize to a clean string[] either way.
		$list = static function ( $key ) use ( $r ): array {
			$v = $r[ $key ] ?? null;
			if ( is_string( $v ) ) {
				$v = preg_split( '/\r\n|\r|\n/', $v );
			}
			if ( ! is_array( $v ) ) {
				return array();
			}
			return array_values( array_filter( array_map( 'trim', array_map( 'strval', $v ) ) ) );
		};
		// LiteSpeed writes a regex rule bare (`^/secret-.*`); xSpeed marks one
		// with a leading `~` (see CacheModule's own `~wp-.*\.php` default) and
		// treats anything else as a literal/glob. Imported unchanged, a
		// LiteSpeed regex became a literal that matches nothing — the same
		// silent loss of protection as the JSON bug above, just narrower. Only
		// rules carrying an unmistakable regex metacharacter are converted, so
		// a plain path like `/cart` stays the literal it already is. (#217)
		$to_xspeed_pattern = static function ( string $rule ): string {
			if ( '' === $rule || '~' === $rule[0] ) {
				return $rule;
			}
			return preg_match( '/[\^$|]|\.\*|\.\+|\[.+\]|\\\\[dwsb]/', $rule ) ? '~' . $rule : $rule;
		};
		$set_list = static function ( array &$dest, string $dest_key, array $vals ) use ( $to_xspeed_pattern ): void {
			if ( in_array( $dest_key, array( 'excluded_urls', 'excluded_patterns' ), true ) ) {
				$vals = array_map( $to_xspeed_pattern, $vals );
			}
			if ( $vals ) {
				$dest[ $dest_key ] = $vals;
			}
		};

		$patch = array();

		// ── Page cache ────────────────────────────────────────────────
		$patch['cache'] = array(
			'enabled' => $on( 'cache' ) || $on( 'cache-priv' ),
		);
		// See plan_wp_rocket: never import Separate Mobile Cache as ON — it
		// disables the device-blind static fast path. Flag for review instead.
		self::map_mobile_separate( $patch, $on( 'cache-mobile' ) );
		// TTL: LiteSpeed stores cache-ttl_pub in seconds → xSpeed wants hours.
		if ( isset( $r['cache-ttl_pub'] ) && (int) $r['cache-ttl_pub'] > 0 ) {
			$patch['cache']['cache_expiry'] = max( 1, min( 720, (int) ( (int) $r['cache-ttl_pub'] / 3600 ) ) );
		}
		// Excluded URIs / cookies / user-agents / dropped query strings.
		$set_list( $patch['cache'], 'excluded_urls', $list( 'cache-exc' ) );
		$set_list( $patch['cache'], 'excluded_cookies', $list( 'cache-exc_cookies' ) );
		// `cache-exc_useragents`, plural — LiteSpeed's O_CACHE_EXC_USERAGENTS.
		// The singular spelling matched nothing, so the list always imported
		// empty, and an empty field looks "not configured" rather than lost. (#217)
		$set_list( $patch['cache'], 'bypass_user_agents', $list( 'cache-exc_useragents' ) );
		// LiteSpeed "drop query string" list ≈ xSpeed ignored_query_params.
		$set_list( $patch['cache'], 'ignored_query_params', $list( 'cache-drop_qs' ) );

		// ── Minify / optimization ─────────────────────────────────────
		$patch['minify'] = array(
			'minify_html'          => $on( 'optm-html_min' ),
			'minify_css'           => $on( 'optm-css_min' ),
			'minify_js'            => $on( 'optm-js_min' ),
			'combine_css'          => $on( 'optm-css_comb' ),
			'combine_js'           => $on( 'optm-js_comb' ),
			// optm-js_defer is a THREE-WAY switch, not a boolean:
			// 0 = OFF, 1 = Deferred, 2 = Delayed (LiteSpeed's own UI labels,
			// tpl/page_optm/settings_js.tpl.php). The modes replace each other,
			// so 2 must set delay_js INSTEAD of defer_js — the old mapping set
			// both, turning one LiteSpeed choice into two xSpeed transforms
			// that fight each other. (#217)
			'defer_js'             => isset( $r['optm-js_defer'] ) && 1 === (int) $r['optm-js_defer'],
			'delay_js'             => isset( $r['optm-js_defer'] ) && 2 === (int) $r['optm-js_defer'],
			// Async/“load CSS asynchronously” — LiteSpeed CCSS async.
			'async_css'            => $on( 'optm-css_async' ),
			// Remove query strings from static resources.
			'remove_query_strings' => $on( 'optm-qs_rm' ),
		);
		// Defer/delay exclusion list — merge LiteSpeed's JS defer + delay
		// exclude lists into xSpeed's single defer_js_excluded.
		// `optm-js_delay_exc` does not exist in LiteSpeed. Its delay list is
		// optm-js_delay_inc (O_OPTM_JS_DELAY_INC) — an INCLUDE list naming the
		// scripts to delay, which is xSpeed's delay_js_targets, not an
		// exclusion. Merging it into defer_js_excluded would have inverted the
		// user's intent, so it maps to its own destination below. (#217)
		$defer_exc = $list( 'optm-js_defer_exc' );
		$set_list( $patch['minify'], 'defer_js_excluded', $defer_exc );
		$set_list( $patch['minify'], 'delay_js_targets', $list( 'optm-js_delay_inc' ) );

		// ── Lazy load (media) ─────────────────────────────────────────
		$patch['lazy'] = array(
			'lazy_images'            => $on( 'media-lazy' ),
			'lazy_iframes'           => $on( 'media-iframe_lazy' ),
			// LiteSpeed has no separate HTML5-video lazy toggle; mirror the
			// image setting so video preload follows the same intent.
			'lazy_videos'            => $on( 'media-lazy' ),
			// "Add Missing Sizes" → add_missing_dimensions (anti-CLS).
			'add_missing_dimensions' => $on( 'media-add_missing_sizes' ),
		);
		$set_list( $patch['lazy'], 'excluded_images', $list( 'media-lazy_exc' ) );

		// ── Fonts ─────────────────────────────────────────────────────
		// LiteSpeed "Font Display Optimization" (optm-localize_style /
		// optm-css_font_display) → xSpeed font-display: swap.
		if ( $on( 'optm-css_font_display' ) || $on( 'optm-localize' ) ) {
			$patch['fonts'] = array( 'font_display_swap' => true );
		}

		// ── Disable bloat ─────────────────────────────────────────────
		// Only map the one LiteSpeed "remove" toggle with a clean xSpeed
		// equivalent: removing the emoji + oEmbed scripts ≈ disable_oembed.
		// (LiteSpeed's optm-emoji_rm strips the wp-emoji + wp-embed pair.)
		// jQuery-migrate / dashicons / XML-RPC / RSS / REST aren't
		// LiteSpeed-managed, so we don't guess at them.
		if ( $on( 'optm-emoji_rm' ) ) {
			$patch['bloat'] = array( 'disable_oembed' => true );
		}

		// ── Browser cache (LiteSpeed: cache-browser) ──────────────────
		if ( $on( 'cache-browser' ) ) {
			$patch['browser-cache'] = array( 'enabled' => true );
			if ( isset( $r['cache-ttl_browser'] ) && (int) $r['cache-ttl_browser'] > 0 ) {
				$patch['browser-cache']['asset_ttl'] = (int) $r['cache-ttl_browser'];
			}
		}

		// ── Object cache ──────────────────────────────────────────────
		if ( $on( 'object' ) ) {
			$kind = isset( $r['object-kind'] ) && (int) $r['object-kind'] === 1 ? 'redis' : 'memcached';
			$patch['object-cache'] = array( 'backend' => $kind );
			if ( ! empty( $r['object-host'] ) ) {
				$host_key = 'redis' === $kind ? 'redis_host' : 'memcached_host';
				$patch['object-cache'][ $host_key ] = (string) $r['object-host'];
			}
			if ( ! empty( $r['object-port'] ) ) {
				$port_key = 'redis' === $kind ? 'redis_port' : 'memcached_port';
				$patch['object-cache'][ $port_key ] = (int) $r['object-port'];
			}
			if ( 'redis' === $kind && isset( $r['object-db_id'] ) ) {
				$patch['object-cache']['redis_database'] = max( 0, min( 15, (int) $r['object-db_id'] ) );
			}
			if ( ! empty( $r['object-pswd'] ) && 'redis' === $kind ) {
				$patch['object-cache']['redis_password'] = (string) $r['object-pswd'];
			}
			if ( ! empty( $r['object-global_groups'] ) || ! empty( $r['object-persistent'] ) ) {
				$patch['object-cache']['persistent'] = $on( 'object-persistent' );
			}
		}

		// ── Image conversion (Pro Images module) ──────────────────────
		// LiteSpeed media-webp / next-gen image generation → xSpeed Images.
		if ( $on( 'img_optm-webp' ) || $on( 'media-webp' ) || $on( 'img_optm-auto' ) ) {
			$patch['images'] = array(
				'webp' => $on( 'img_optm-webp' ) || $on( 'media-webp' ),
				'avif' => $on( 'img_optm-avif' ),
			);
		}

		// ── CDN ───────────────────────────────────────────────────────
		if ( $on( 'cdn' ) ) {
			$cdn_url = '';
			if ( ! empty( $r['cdn-mapping'] ) && is_array( $r['cdn-mapping'] ) ) {
				$first   = $r['cdn-mapping'][0] ?? array();
				// LiteSpeed cdn-mapping rows use the 'url' sub-key (array form)
				// or a bare URL string (legacy).
				$cdn_url = is_array( $first ) ? (string) ( $first['url'] ?? ( $first['cdn_url'] ?? '' ) ) : (string) $first;
			}
			if ( '' !== $cdn_url ) {
				$patch['cdn'] = array(
					'enabled' => true,
					'cdn_url' => $cdn_url,
				);
				// LiteSpeed's O_CDN_EXC is `cdn-exc`, not `cdn-exclude`. (#217)
				$set_list( $patch['cdn'], 'excluded_patterns', $list( 'cdn-exc' ) );
			}
		}

		return $patch;
	}
}
