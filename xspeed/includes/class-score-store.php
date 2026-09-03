<?php
/**
 * Score history storage — the plugin's own table.
 *
 * Runs used to live in the `xspeed_score_history` option: a capped array,
 * rewritten whole on every append. That is fine for twenty rows and wrong for
 * a history you want to keep, because an option cannot be queried — you cannot
 * ask for "GTmetrix runs in March" or "the last run before this deploy"
 * without loading and filtering the lot in PHP.
 *
 * A table gives us the thing the feature is actually for: a record of how the
 * site's score moved over time, owned by the plugin, surviving whatever
 * happens to a Hub connection.
 *
 * The option remains the source of truth until this table exists and has been
 * migrated into — see maybe_install(). Nothing reads the option afterwards.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Score_Store {

	/**
	 * Bumped whenever the schema changes. Stored per-site so dbDelta only
	 * runs when it has something to do, rather than on every admin request.
	 */
	private const SCHEMA_VERSION = 3;

	private const VERSION_OPTION = 'xspeed_score_schema';

	/**
	 * Is a database available?
	 *
	 * $wpdb is absent in unit tests and during very early boot. Every method
	 * here checks rather than assuming, so a missing database degrades to
	 * "no history" instead of a fatal — the score panel is not worth taking a
	 * request down for.
	 */
	private static function has_db(): bool {
		global $wpdb;
		return isset( $wpdb ) && is_object( $wpdb );
	}

	/** Prefixed table name. Empty string when there is no database. */
	public static function table(): string {
		global $wpdb;
		return self::has_db() ? $wpdb->prefix . 'xspeed_scores' : '';
	}

	/**
	 * Create or upgrade the table, and migrate the old option once.
	 *
	 * Safe to call repeatedly: it returns immediately unless the stored
	 * schema version is behind. Called on activation AND on admin_init,
	 * because activation does not fire for a site added to a multisite
	 * network later, nor after a plugin update that ships a new schema.
	 */
	public static function maybe_install(): void {
		if ( ! self::has_db() ) {
			return;
		}
		if ( (int) get_option( self::VERSION_OPTION, 0 ) === self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		/*
		 * Nullable metrics throughout, deliberately. A failed audit is not a
		 * score of zero, and a metric the provider did not report is not a
		 * perfect one — the panel renders "—" for null and a real number for
		 * 0, and coercing here would make a failure look like a catastrophe
		 * on the score and a triumph on the timings.
		 *
		 * `ran_at` is the RUN's own timestamp, not when we stored it, so the
		 * ordering stays honest when a Hub result arrives minutes late.
		 */
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider varchar(20) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT 'local',
			ran_at bigint(20) unsigned NOT NULL DEFAULT 0,
			url text NOT NULL,
			strategy varchar(20) NOT NULL DEFAULT '',
			ok tinyint(1) NOT NULL DEFAULT 1,
			score smallint(5) DEFAULT NULL,
			lcp float DEFAULT NULL,
			fcp float DEFAULT NULL,
			cls float DEFAULT NULL,
			tbt float DEFAULT NULL,
			si float DEFAULT NULL,
			ttfb float DEFAULT NULL,
			report_url text NULL,
			error text NULL,
			remote_id varchar(64) NOT NULL DEFAULT '',
			opportunities longtext NULL,
			PRIMARY KEY  (id),
			KEY ran_at (ran_at),
			KEY provider_ran_at (provider, ran_at),
			KEY remote_id (remote_id)
		) {$collate};";

		dbDelta( $sql );

		self::migrate_option_history();

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Move the old option-based history into the table, once.
	 *
	 * The option is left in place rather than deleted: if an upgrade goes
	 * wrong, the user's history is still there to recover, and a second run
	 * of this method is a no-op because the table is only empty the first
	 * time.
	 */
	private static function migrate_option_history(): void {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time migration, no cache to invalidate.
		$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing > 0 ) {
			return;
		}

		$legacy = get_option( Score::HISTORY_OPTION, array() );
		if ( ! is_array( $legacy ) || empty( $legacy ) ) {
			return;
		}

		foreach ( array_reverse( $legacy ) as $row ) {
			if ( is_array( $row ) ) {
				self::insert( $row, 'local' );
			}
		}
	}

	/**
	 * Store one run.
	 *
	 * @param array<string,mixed> $row    A parsed run, in the shape \XSpeed\Score produces.
	 * @param string              $source 'local' (this site called the provider) or 'hub'.
	 * @return int Inserted row id, or 0 on failure.
	 */
	public static function insert( array $row, string $source = 'local' ): int {
		global $wpdb;

		if ( ! self::has_db() ) {
			return 0;
		}

		$metrics = isset( $row['metrics'] ) && is_array( $row['metrics'] ) ? $row['metrics'] : array();

		$data = array(
			'provider'   => isset( $row['provider'] ) ? substr( (string) $row['provider'], 0, 20 ) : '',
			'source'     => 'hub' === $source ? 'hub' : 'local',
			'ran_at'     => isset( $row['ts'] ) ? (int) $row['ts'] : time(),
			'url'        => substr( (string) self::url( $row['url'] ?? null ), 0, 2048 ),
			'strategy'   => isset( $row['strategy'] ) ? substr( (string) $row['strategy'], 0, 20 ) : '',
			'ok'         => empty( $row['ok'] ) ? 0 : 1,
			'score'      => self::num( $row['score'] ?? null ),
			'lcp'        => self::num( $metrics['lcp'] ?? null ),
			'fcp'        => self::num( $metrics['fcp'] ?? null ),
			'cls'        => self::num( $metrics['cls'] ?? null ),
			'tbt'        => self::num( $metrics['tbt'] ?? null ),
			'si'         => self::num( $metrics['si'] ?? null ),
			'ttfb'       => self::num( $metrics['ttfb'] ?? null ),
			// The report link is rendered as an href and, for a Hub-run test,
			// arrives over the network. esc_url_raw() strips anything that
			// isn't an allowed scheme, so a `javascript:` or `data:` value
			// can never reach the panel's link. Empty result => null, never
			// a half-sanitized string.
			'report_url' => self::url( $row['report_url'] ?? null ),
			// Bounded: this is shown to the user and the column is TEXT, so
			// an unbounded remote string would be both a storage and a
			// rendering problem.
			'error'      => isset( $row['error'] ) && '' !== $row['error']
				? substr( sanitize_text_field( (string) $row['error'] ), 0, 500 )
				: null,
			// The Hub's run id. A STABLE identity for a remote run — dedupe
			// keyed on a timestamp let one test in twice when a retry and the
			// original arrived milliseconds apart.
			'remote_id'  => isset( $row['remote_id'] ) ? substr( (string) $row['remote_id'], 0, 64 ) : '',
			// What the report said to fix. JSON in a longtext: it belongs to
			// one run, is read whenever that run is read, and written once.
			'opportunities' => self::encode_opportunities( $row['opportunities'] ?? null ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, no core API for it.
		$ok = $wpdb->insert( self::table(), $data );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Runs, newest first, in the array shape the REST layer already returns.
	 *
	 * @param int $limit Rows to return (1-500).
	 * @return array<int,array<string,mixed>>
	 */
	public static function history( int $limit = 20 ): array {
		global $wpdb;

		if ( ! self::has_db() ) {
			return array();
		}

		$limit = max( 1, min( 500, $limit ) );
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; $limit is clamped above.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY ran_at DESC, id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( self::class, 'to_run' ), $rows );
	}

	/**
	 * Whether a run with this provider + timestamp is already stored.
	 *
	 * The Hub is polled repeatedly while a test runs, and the finished result
	 * comes back on every poll after it completes — without this, one test
	 * would be recorded several times.
	 */
	/** Whether a run with this remote (Hub) id is already stored. */
	public static function exists_remote( string $remote_id ): bool {
		global $wpdb;
		if ( ! self::has_db() || '' === $remote_id ) {
			return false;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE remote_id = %s LIMIT 1", $remote_id )
		);
	}

	public static function exists( string $provider, int $ran_at ): bool {
		global $wpdb;
		if ( ! self::has_db() ) {
			return false;
		}
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE provider = %s AND ran_at = %d LIMIT 1", $provider, $ran_at )
		);
	}

	/** Remove every stored run. */
	public static function clear(): void {
		global $wpdb;
		if ( ! self::has_db() ) {
			return;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/** Drop the table — uninstall only. */
	public static function drop(): void {
		global $wpdb;
		if ( ! self::has_db() ) {
			return;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * A DB row back into the run shape the panel expects.
	 *
	 * @param array<string,mixed> $r Raw row.
	 * @return array<string,mixed>
	 */
	private static function to_run( array $r ): array {
		return array(
			'provider'   => (string) ( $r['provider'] ?? '' ),
			'source'     => (string) ( $r['source'] ?? 'local' ),
			'ts'         => (int) ( $r['ran_at'] ?? 0 ),
			'url'        => (string) ( $r['url'] ?? '' ),
			'strategy'   => (string) ( $r['strategy'] ?? '' ),
			'ok'         => ! empty( $r['ok'] ),
			'score'      => self::num( $r['score'] ?? null ),
			'metrics'    => array(
				'lcp'  => self::num( $r['lcp'] ?? null ),
				'fcp'  => self::num( $r['fcp'] ?? null ),
				'cls'  => self::num( $r['cls'] ?? null ),
				'tbt'  => self::num( $r['tbt'] ?? null ),
				'si'   => self::num( $r['si'] ?? null ),
				'ttfb' => self::num( $r['ttfb'] ?? null ),
			),
			'report_url' => isset( $r['report_url'] ) && '' !== $r['report_url'] ? (string) $r['report_url'] : null,
			'remote_id'  => isset( $r['remote_id'] ) ? (string) $r['remote_id'] : '',
			'opportunities' => self::decode_opportunities( $r['opportunities'] ?? null ),
			'error'      => isset( $r['error'] ) && '' !== $r['error'] ? (string) $r['error'] : '',
		);
	}

	/**
	 * A number, or null.
	 *
	 * NEVER casts null to 0. "We have no measurement" and "the value is zero"
	 * are different facts, and the whole history is built on telling them
	 * apart.
	 *
	 * @param mixed $value Raw value.
	 */
	/**
	 * A safe, storable URL — or null.
	 *
	 * Applied to everything that will be rendered as a link, including
	 * values that came back from the Hub. esc_url_raw() enforces the scheme
	 * allow-list, which is what stops `javascript:` reaching an href.
	 *
	 * @param mixed $value Raw value.
	 */
	/**
	 * Validate and encode the audit list for storage.
	 *
	 * Comes over the network from the Hub, so it is treated as untrusted:
	 * every row must have an id and a title, both bounded, and the list is
	 * capped. Returns null rather than an empty array, so "none" and "not
	 * recorded" are the same absent value in the column.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function encode_opportunities( $value ): ?string {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return null;
		}

		$clean = array();
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id    = isset( $row['id'] ) ? substr( sanitize_text_field( (string) $row['id'] ), 0, 64 ) : '';
			$title = isset( $row['title'] ) ? substr( sanitize_text_field( (string) $row['title'] ), 0, 160 ) : '';
			if ( '' === $id || '' === $title ) {
				continue;
			}
			$metrics = array();
			if ( isset( $row['metrics'] ) && is_array( $row['metrics'] ) ) {
				foreach ( array_slice( $row['metrics'], 0, 6 ) as $m ) {
					$metrics[] = substr( sanitize_text_field( (string) $m ), 0, 8 );
				}
			}
			$clean[] = array(
				'id'      => $id,
				'title'   => $title,
				'metrics' => $metrics,
			);
			if ( count( $clean ) >= 15 ) {
				break;
			}
		}

		return empty( $clean ) ? null : (string) wp_json_encode( $clean );
	}

	/**
	 * The stored audit list, back as an array.
	 *
	 * @param mixed $value Raw column value.
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function decode_opportunities( $value ): ?array {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) && ! empty( $decoded ) ? $decoded : null;
	}

	private static function url( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$clean = esc_url_raw( $value );
		return '' === $clean ? null : $clean;
	}

	private static function num( $value ): ?float {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return is_numeric( $value ) ? (float) $value : null;
	}
}
