<?php
/**
 * Database_Cleaner — read-first scan + destructive cleanup for common
 * WordPress bloat: revisions, auto-drafts, trashed posts/comments,
 * spam, expired transients, orphan metadata, and OPTIMIZE TABLE.
 *
 * Two-phase API on purpose:
 *   - scan() is read-only — returns counts + rough size estimates so
 *     the dashboard can show "this is what cleanup would remove"
 *     BEFORE the user clicks Clean. Cheap enough to run every panel
 *     mount.
 *   - clean( $types ) is destructive — accepts a whitelist of type
 *     keys, runs the DELETE / OPTIMIZE for each, returns the
 *     post-cleanup counts so the UI can refresh.
 *
 * All queries go through $wpdb->prepare or are string-literal SQL on
 * static table-name interpolations (sanitized via $wpdb->prefix).
 * Never trusts user input directly in SQL.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Database_Cleaner {

	public const CRON_HOOK = 'xspeed_database_cleanup';

	/**
	 * Cleanup types. Each entry:
	 *   label   — human description
	 *   scan_sql_tpl  — SELECT for counting candidates (sprintf
	 *                   placeholder for table prefix)
	 *   clean_sql_tpl — DELETE for the same set
	 *
	 * Auto-drafts use a 7-day grace window so in-progress drafts aren't
	 * destroyed mid-edit. Expired transients use the option_value
	 * column for both `_transient_timeout_*` and `_site_transient_timeout_*`.
	 *
	 * @return array<string,array{label:string,scan_sql_tpl:string,clean_sql_tpl:string}>
	 */
	private static function types(): array {
		return array(
			'post_revisions'      => array(
				'label'         => 'Post Revisions',
				'scan_sql_tpl'  => "SELECT COUNT(*) FROM %1\$sposts WHERE post_type = 'revision'",
				'clean_sql_tpl' => "DELETE FROM %1\$sposts WHERE post_type = 'revision'",
			),
			'auto_drafts'         => array(
				'label'         => 'Auto-Drafts (older than 7 days)',
				'scan_sql_tpl'  => "SELECT COUNT(*) FROM %1\$sposts WHERE post_status = 'auto-draft' AND post_modified < DATE_SUB( NOW(), INTERVAL 7 DAY )",
				'clean_sql_tpl' => "DELETE FROM %1\$sposts WHERE post_status = 'auto-draft' AND post_modified < DATE_SUB( NOW(), INTERVAL 7 DAY )",
			),
			'trashed_posts'       => array(
				'label'         => 'Trashed Posts',
				'scan_sql_tpl'  => "SELECT COUNT(*) FROM %1\$sposts WHERE post_status = 'trash'",
				'clean_sql_tpl' => "DELETE FROM %1\$sposts WHERE post_status = 'trash'",
			),
			'spam_comments'       => array(
				'label'         => 'Spam Comments',
				'scan_sql_tpl'  => "SELECT COUNT(*) FROM %1\$scomments WHERE comment_approved = 'spam'",
				'clean_sql_tpl' => "DELETE FROM %1\$scomments WHERE comment_approved = 'spam'",
			),
			'trashed_comments'    => array(
				'label'         => 'Trashed Comments',
				'scan_sql_tpl'  => "SELECT COUNT(*) FROM %1\$scomments WHERE comment_approved = 'trash'",
				'clean_sql_tpl' => "DELETE FROM %1\$scomments WHERE comment_approved = 'trash'",
			),
			'expired_transients'  => array(
				'label'         => 'Expired Transients',
				// Scan counts one row per EXPIRED transient — the timeout
				// row — across BOTH the normal (_transient_timeout_*) and the
				// site/network (_site_transient_timeout_*) families. The
				// parentheses around the two LIKEs are required so the AND
				// expiry condition applies to both, not just the second.
				// (FBS-82149 Bug 1: site transients were never matched;
				// Bug 3: scan counts logical transients = 1 per timeout row.)
				'scan_sql_tpl'  => "SELECT COUNT(*) FROM %1\$soptions WHERE ( option_name LIKE '_transient_timeout_%%' OR option_name LIKE '_site_transient_timeout_%%' ) AND CAST( option_value AS UNSIGNED ) < UNIX_TIMESTAMP()",
				// Clean deletes the timeout row AND its value sibling via a
				// LEFT JOIN so an ORPHAN timeout row (value sibling missing)
				// is still removed — an INNER JOIN silently kept those, so a
				// "clean" never drove the scan count to zero. REPLACE maps
				// both families to their value-key name. (FBS-82149 Bug 2.)
				'clean_sql_tpl' => "DELETE a, b FROM %1\$soptions a LEFT JOIN %1\$soptions b ON b.option_name = REPLACE( REPLACE( a.option_name, '_site_transient_timeout_', '_site_transient_' ), '_transient_timeout_', '_transient_' ) WHERE ( a.option_name LIKE '_transient_timeout_%%' OR a.option_name LIKE '_site_transient_timeout_%%' ) AND CAST( a.option_value AS UNSIGNED ) < UNIX_TIMESTAMP()",
			),
			'orphan_postmeta'     => array(
				'label'         => 'Orphan Post Meta',
				'scan_sql_tpl'  => "SELECT COUNT(*) FROM %1\$spostmeta pm LEFT JOIN %1\$sposts p ON p.ID = pm.post_id WHERE p.ID IS NULL",
				'clean_sql_tpl' => "DELETE pm FROM %1\$spostmeta pm LEFT JOIN %1\$sposts p ON p.ID = pm.post_id WHERE p.ID IS NULL",
			),
			'orphan_commentmeta'  => array(
				'label'         => 'Orphan Comment Meta',
				'scan_sql_tpl'  => "SELECT COUNT(*) FROM %1\$scommentmeta cm LEFT JOIN %1\$scomments c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL",
				'clean_sql_tpl' => "DELETE cm FROM %1\$scommentmeta cm LEFT JOIN %1\$scomments c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL",
			),
		);
	}

	/**
	 * Read-only scan. Returns array keyed by type slug:
	 *   [ slug => [ label => …, count => N ] ]
	 *
	 * @param \wpdb|null $wpdb_in Injectable for tests; defaults to global.
	 */
	public static function scan( $wpdb_in = null ): array {
		$wpdb = self::wpdb( $wpdb_in );
		$out  = array();
		foreach ( self::types() as $key => $spec ) {
			$out[ $key ] = array(
				'label' => $spec['label'],
				'count' => self::count_for( $spec, $wpdb ),
			);
		}
		return $out;
	}

	/**
	 * Run a type's scan SQL and return its COUNT. Shared by scan() and by
	 * clean() (before/after) so the "rows removed" number is always in the
	 * same unit the UI shows. (FBS-82149 Bug 3.)
	 *
	 * @param array{scan_sql_tpl:string} $spec
	 * @param \wpdb                      $wpdb
	 */
	private static function count_for( array $spec, $wpdb ): int {
		$sql = sprintf( $spec['scan_sql_tpl'], $wpdb->prefix );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL is built from a hardcoded template registry (types()); only $wpdb->prefix is interpolated. Read-only count; not cached so the user always sees current row counts.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Destructive cleanup for the supplied list of type slugs. Anything
	 * not in the static types() table is silently skipped — never run
	 * arbitrary SQL because someone POSTed an unexpected slug.
	 *
	 * Returns the new scan() shape so the UI can refresh in one
	 * round trip, plus a per-type `affected` count of rows removed.
	 *
	 * @param string[] $types
	 */
	public static function clean( array $types, $wpdb_in = null ): array {
		$wpdb     = self::wpdb( $wpdb_in );
		$registry = self::types();
		$results  = array();
		$total    = 0;

		foreach ( $types as $key ) {
			if ( ! isset( $registry[ $key ] ) ) {
				continue;
			}
			// Report `affected` in the SAME unit the scan counts + the UI
			// button shows: the reduction in the scan count (logical items),
			// not raw rows deleted. Without this the paired transient DELETE
			// (timeout + value = 2 rows per transient) reported "2" while the
			// button said "(1)". Measure before/after so every type — paired
			// or single-row — reports a consistent, user-meaningful count.
			// (FBS-82149 Bug 3.)
			$before = self::count_for( $registry[ $key ], $wpdb );

			$sql = sprintf( $registry[ $key ]['clean_sql_tpl'], $wpdb->prefix );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL built from a hardcoded template registry (types()); only $wpdb->prefix is interpolated. Destructive cleanup explicitly bypasses the object cache.
			$wpdb->query( $sql );

			$after    = self::count_for( $registry[ $key ], $wpdb );
			$affected = max( 0, $before - $after );

			$results[ $key ] = $affected;
			$total          += $affected;
		}

		if ( $total > 0 && class_exists( '\\XSpeed\\Activity_Log' ) ) {
			Activity_Log::record(
				'database_cleaned',
				sprintf( 'Database cleanup removed %d row%s across %d table(s).', $total, 1 === $total ? '' : 's', count( $results ) ),
				Activity_Log::INFO
			);
		}

		return array(
			'cleaned' => $results,
			'scan'    => self::scan( $wpdb_in ),
		);
	}

	/**
	 * OPTIMIZE TABLE on every WordPress core table (and any with the
	 * site's prefix). Returns the table names + per-table status.
	 */
	public static function optimize_tables( $wpdb_in = null ): array {
		$wpdb   = self::wpdb( $wpdb_in );
		$prefix = $wpdb->prefix;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix from $wpdb, not user input. SHOW TABLES is metadata, not cached.
		$tables = $wpdb->get_col( "SHOW TABLES LIKE '" . esc_sql( $prefix ) . "%'" );
		if ( ! is_array( $tables ) ) {
			return array();
		}
		$out = array();
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name comes from SHOW TABLES on our prefix; safe to interpolate. OPTIMIZE TABLE is a maintenance DDL, not cacheable.
			$ok = $wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( $table ) . '`' );
			$out[ $table ] = false !== $ok;
		}

		if ( ! empty( $out ) && class_exists( '\\XSpeed\\Activity_Log' ) ) {
			Activity_Log::record(
				'database_optimized',
				sprintf( 'Database tables optimized (%d).', count( $out ) ),
				Activity_Log::INFO
			);
		}

		return $out;
	}

	/**
	 * Register / cancel the cleanup cron schedule. Called from the
	 * DatabaseModule's settings-change hook.
	 */
	public static function apply_schedule( string $schedule, array $types ): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( in_array( $schedule, array( 'hourly', 'daily', 'weekly' ), true ) && ! empty( $types ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, self::CRON_HOOK );
		}
	}

	/**
	 * Cron handler — pulls the configured included_types from settings
	 * and runs clean() on them. Idempotent; if nothing's eligible, the
	 * underlying DELETE runs but affects 0 rows.
	 */
	public static function cron_tick(): void {
		$opts  = Settings_Manager::get( 'database' );
		$types = is_array( $opts['included_types'] ?? null ) ? $opts['included_types'] : array();
		if ( empty( $types ) ) {
			return;
		}
		self::clean( $types );
	}

	private static function wpdb( $injected ) {
		if ( null !== $injected ) {
			return $injected;
		}
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Public list of type metadata for the dashboard payload, so the
	 * React side doesn't have to hardcode labels.
	 *
	 * @return array<string,string>
	 */
	public static function type_labels(): array {
		$out = array();
		foreach ( self::types() as $key => $spec ) {
			$out[ $key ] = $spec['label'];
		}
		return $out;
	}
}
