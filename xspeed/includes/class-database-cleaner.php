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
 *     keys, removes what each one matches, returns the post-cleanup
 *     counts so the UI can refresh.
 *
 * Posts and comments are deleted through wp_delete_post() and
 * wp_delete_comment(), in bounded batches, never by raw DELETE. A raw
 * DELETE skipped every deletion hook, left postmeta and term
 * relationships behind, and held one unbounded lock on wp_posts (#449,
 * #237). Rows WordPress has no deletion API for — expired transients,
 * orphan meta, orphan term relationships — are still raw SQL.
 *
 * All SQL comes from the hardcoded template registry in types(). The
 * only values interpolated are $wpdb->prefix, integers cast with %d (the
 * batch cursor and LIMIT), and the names of registered taxonomies passed
 * through esc_sql(). Never trusts user input directly in SQL.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Database_Cleaner {

	public const CRON_HOOK = 'xspeed_database_cleanup';

	/**
	 * Where the scheduled clean stopped when its time budget ran out.
	 *
	 * Shape: { types_hash, type, after_id }. Only cron_tick() reads or writes
	 * it. A manual, REST, CLI or MCP clean never does: those delete what the
	 * caller just saw, in the same request, and must not leave work behind
	 * for a later tick to finish. An MCP clean in particular is authorised by
	 * a confirm_token bound to one scan, and a resumed run on a later tick
	 * would delete rows that became eligible after that confirmation.
	 */
	public const CURSOR_OPTION = 'xspeed_db_clean_cursor';

	/** Rows fetched per SELECT for the types deleted one at a time. */
	private const BATCH = 100;

	/** Seconds an interactive clean (REST, CLI, MCP) may spend deleting. */
	private const BUDGET_INTERACTIVE = 20;

	/** Seconds one scheduled tick may spend deleting. */
	private const BUDGET_CRON = 10;

	/**
	 * Cleanup types, in the order clean() runs them. Each entry:
	 *   label          — human description
	 *   scan_sql_tpl   — SELECT COUNT(*) of candidates. %1$s is the table
	 *                    prefix, %2$s the post-taxonomy IN list.
	 *   select_ids_tpl — for types deleted through the WordPress API: one
	 *                    page of candidate ids. %2$d is the id to start
	 *                    after, %3$d the page size.
	 *   delete         — 'post' or 'comment': which API deletes an id.
	 *   clean_sql_tpl  — for raw types: the DELETE for the same set.
	 *
	 * The slugs are stored in users' `included_types` setting. Renaming one
	 * strands that stored value: clean() skips unknown slugs, so a renamed
	 * slug would turn a scheduled clean into a silent no-op. Add, never
	 * rename.
	 *
	 * Order matters. The orphan sweeps run after the post and comment
	 * deletes, and the resume cursor walks this list.
	 *
	 * Auto-drafts use a 7-day grace window so in-progress drafts aren't
	 * destroyed mid-edit. Expired transients use the option_value
	 * column for both `_transient_timeout_*` and `_site_transient_timeout_*`.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function types(): array {
		return array(
			'post_revisions'            => array(
				'label'          => 'Post Revisions',
				'scan_sql_tpl'   => "SELECT COUNT(*) FROM %1\$sposts WHERE post_type = 'revision'",
				'select_ids_tpl' => "SELECT ID FROM %1\$sposts WHERE post_type = 'revision' AND ID > %2\$d ORDER BY ID ASC LIMIT %3\$d",
				'delete'         => 'post',
			),
			'auto_drafts'               => array(
				'label'          => 'Auto-Drafts (older than 7 days)',
				'scan_sql_tpl'   => "SELECT COUNT(*) FROM %1\$sposts WHERE post_status = 'auto-draft' AND post_modified < DATE_SUB( NOW(), INTERVAL 7 DAY )",
				'select_ids_tpl' => "SELECT ID FROM %1\$sposts WHERE post_status = 'auto-draft' AND post_modified < DATE_SUB( NOW(), INTERVAL 7 DAY ) AND ID > %2\$d ORDER BY ID ASC LIMIT %3\$d",
				'delete'         => 'post',
			),
			// A trashed ATTACHMENT (only possible with MEDIA_TRASH on) loses
			// its files from uploads/ here, because wp_delete_post() hands
			// attachments to wp_delete_attachment(). That is what core's own
			// "Empty Trash" does with the same row. The raw DELETE this
			// replaced removed the row and stranded the files.
			'trashed_posts'             => array(
				'label'          => 'Trashed Posts',
				'scan_sql_tpl'   => "SELECT COUNT(*) FROM %1\$sposts WHERE post_status = 'trash'",
				'select_ids_tpl' => "SELECT ID FROM %1\$sposts WHERE post_status = 'trash' AND ID > %2\$d ORDER BY ID ASC LIMIT %3\$d",
				'delete'         => 'post',
			),
			'spam_comments'             => array(
				'label'          => 'Spam Comments',
				'scan_sql_tpl'   => "SELECT COUNT(*) FROM %1\$scomments WHERE comment_approved = 'spam'",
				'select_ids_tpl' => "SELECT comment_ID FROM %1\$scomments WHERE comment_approved = 'spam' AND comment_ID > %2\$d ORDER BY comment_ID ASC LIMIT %3\$d",
				'delete'         => 'comment',
			),
			'trashed_comments'          => array(
				'label'          => 'Trashed Comments',
				'scan_sql_tpl'   => "SELECT COUNT(*) FROM %1\$scomments WHERE comment_approved = 'trash'",
				'select_ids_tpl' => "SELECT comment_ID FROM %1\$scomments WHERE comment_approved = 'trash' AND comment_ID > %2\$d ORDER BY comment_ID ASC LIMIT %3\$d",
				'delete'         => 'comment',
			),
			'expired_transients'        => array(
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
			'orphan_postmeta'           => array(
				'label'         => 'Orphan Post Meta',
				'scan_sql_tpl'  => "SELECT COUNT(*) FROM %1\$spostmeta pm LEFT JOIN %1\$sposts p ON p.ID = pm.post_id WHERE p.ID IS NULL",
				'clean_sql_tpl' => "DELETE pm FROM %1\$spostmeta pm LEFT JOIN %1\$sposts p ON p.ID = pm.post_id WHERE p.ID IS NULL",
			),
			'orphan_commentmeta'        => array(
				'label'         => 'Orphan Comment Meta',
				'scan_sql_tpl'  => "SELECT COUNT(*) FROM %1\$scommentmeta cm LEFT JOIN %1\$scomments c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL",
				'clean_sql_tpl' => "DELETE cm FROM %1\$scommentmeta cm LEFT JOIN %1\$scomments c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL",
			),
			// A term relationship's object_id is a post id only when the
			// taxonomy belongs to post types. link_category points at
			// wp_links, and plugins attach taxonomies to users (BuddyPress
			// member types, for one), so "no such post" is not "orphan" for
			// those. The sweep is limited to taxonomies registered for post
			// types only; an unregistered taxonomy is left alone.
			'orphan_term_relationships' => array(
				'label'         => 'Orphan Term Relationships',
				'scan_sql_tpl'  => "SELECT COUNT(*) FROM %1\$sterm_relationships tr INNER JOIN %1\$sterm_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id LEFT JOIN %1\$sposts p ON p.ID = tr.object_id WHERE p.ID IS NULL AND tt.taxonomy IN ( %2\$s )",
				'clean_sql_tpl' => "DELETE tr FROM %1\$sterm_relationships tr INNER JOIN %1\$sterm_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id LEFT JOIN %1\$sposts p ON p.ID = tr.object_id WHERE p.ID IS NULL AND tt.taxonomy IN ( %2\$s )",
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
		$sql = self::fill( $spec['scan_sql_tpl'], $wpdb );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL is built from a hardcoded template registry (types()); only $wpdb->prefix and esc_sql()'d registered taxonomy names are interpolated. Read-only count; not cached so the user always sees current row counts.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Destructive cleanup for the supplied list of type slugs. Anything
	 * not in the static types() table is skipped and named in `skipped` —
	 * never run arbitrary SQL because someone POSTed an unexpected slug.
	 *
	 * Types run in registry order, whatever order the caller listed them.
	 * Posts and comments go one id at a time through the WordPress API, a
	 * page of ids per SELECT, until nothing matches or the time budget is
	 * spent. At least one id is always attempted, so a zero budget still
	 * makes progress.
	 *
	 * Options:
	 *   batch          ids per SELECT (default 100)
	 *   budget_seconds time allowed (default 20, or 10 with resume)
	 *   resume         start from, and save to, the scheduled-clean cursor.
	 *                  Only cron_tick() passes this.
	 *
	 * Returns:
	 *   cleaned   per type, items removed
	 *   remaining per requested type, what still matches afterwards
	 *   partial   true when the budget ran out before every type finished
	 *   skipped   requested slugs that are not cleanup types
	 *   scan      the full scan() after the clean, so the UI can refresh
	 *
	 * @param string[]   $types
	 * @param \wpdb|null $wpdb_in Injectable for tests; defaults to global.
	 * @param array      $opts
	 */
	public static function clean( array $types, $wpdb_in = null, array $opts = array() ): array {
		$wpdb     = self::wpdb( $wpdb_in );
		$registry = self::types();
		$resume   = ! empty( $opts['resume'] );
		$batch    = max( 1, (int) ( $opts['batch'] ?? self::BATCH ) );
		$budget   = (float) ( $opts['budget_seconds'] ?? ( $resume ? self::BUDGET_CRON : self::BUDGET_INTERACTIVE ) );
		$deadline = microtime( true ) + max( 0.0, $budget );

		$requested = array_values( array_unique( array_map( 'strval', array_filter( $types, 'is_scalar' ) ) ) );
		$keys      = array_values( array_intersect( array_keys( $registry ), $requested ) );
		$skipped   = array_values( array_diff( $requested, $keys ) );

		$start = 0;
		$after = 0;
		$hash  = self::types_hash( $keys );
		if ( $resume ) {
			list( $start, $after ) = self::read_cursor( $hash, $keys );
		}

		$results  = array();
		$total    = 0;
		$partial  = false;
		$stop_at  = null;
		$progress = false;

		for ( $i = $start, $n = count( $keys ); $i < $n; $i++ ) {
			$key  = $keys[ $i ];
			$spec = $registry[ $key ];

			if ( $progress && microtime( true ) >= $deadline ) {
				$partial = true;
				$stop_at = array( $key, 0 );
				break;
			}

			if ( isset( $spec['select_ids_tpl'] ) ) {
				list( $removed, $stopped_after ) = self::delete_batched( $spec, $wpdb, $after, $batch, $deadline, $progress );
			} else {
				$removed       = self::delete_raw( $spec, $wpdb );
				$stopped_after = null;
				$progress      = true;
			}

			$results[ $key ] = $removed;
			$total          += $removed;

			if ( null !== $stopped_after ) {
				$partial = true;
				$stop_at = array( $key, $stopped_after );
				break;
			}
			$after = 0;
		}

		if ( $resume ) {
			if ( null !== $stop_at ) {
				self::write_cursor( $hash, $stop_at[0], $stop_at[1] );
			} else {
				// Last type finished: the cycle wraps, and the next tick
				// starts again from the first type and id 0. Rows that became
				// eligible behind the cursor are picked up then.
				self::reset_cursor();
			}
		}

		if ( $total > 0 && class_exists( '\\XSpeed\\Activity_Log' ) ) {
			Activity_Log::record(
				'database_cleaned',
				sprintf( 'Database cleanup removed %d row%s across %d table(s).', $total, 1 === $total ? '' : 's', count( $results ) ),
				Activity_Log::INFO
			);
		}

		$scan      = self::scan( $wpdb );
		$remaining = array();
		foreach ( $keys as $key ) {
			$remaining[ $key ] = (int) ( $scan[ $key ]['count'] ?? 0 );
		}

		return array(
			'cleaned'   => $results,
			'remaining' => $remaining,
			'partial'   => $partial,
			'skipped'   => $skipped,
			'scan'      => $scan,
		);
	}

	/**
	 * Delete one type's matches through the WordPress API, a page at a time.
	 *
	 * wp_delete_post( $id, true ) fires before_delete_post / delete_post /
	 * deleted_post, removes the post's meta, term relationships, comments and
	 * revisions, and moves its attachments and same-type children up to its
	 * parent. detach_children() then clears the parent of any other child. For a
	 * trashed attachment it also deletes the files. wp_delete_comment( $id,
	 * true ) does the same for a comment and its meta. Each fires every
	 * other plugin's hooks too, which is the point, and is why the run is
	 * bounded by a time budget rather than left to finish.
	 *
	 * xSpeed's own purge on before_delete_post already ignores revisions,
	 * auto-drafts and trashed posts (Cache::post_change_is_cacheable_content),
	 * so emptying these does not sweep the page cache per row.
	 *
	 * The cursor moves past every id attempted, deleted or not. A row a
	 * plugin refuses to delete (pre_delete_post) is stepped over rather than
	 * fetched forever.
	 *
	 * @param array      $spec     Registry entry with select_ids_tpl + delete.
	 * @param \wpdb      $wpdb
	 * @param int        $after    Start after this id.
	 * @param int        $batch    Ids per SELECT.
	 * @param float      $deadline microtime() at which to stop.
	 * @param bool       $progress Set once any id has been attempted.
	 * @return array{0:int,1:int|null} Removed count, and the last id attempted
	 *                                 when the budget stopped the run (null
	 *                                 when the type finished).
	 */
	private static function delete_batched( array $spec, $wpdb, int $after, int $batch, float $deadline, bool &$progress ): array {
		$removed = 0;
		while ( true ) {
			$sql = sprintf( $spec['select_ids_tpl'], $wpdb->prefix, $after, $batch );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL built from a hardcoded template registry (types()); only $wpdb->prefix and two integers formatted with %d are interpolated. Candidate ids must be read live.
			$ids = $wpdb->get_col( $sql );
			if ( ! is_array( $ids ) || array() === $ids ) {
				return array( $removed, null );
			}

			foreach ( $ids as $id ) {
				$id    = (int) $id;
				$after = $id;
				if ( 'comment' === $spec['delete'] ) {
					$ok = wp_delete_comment( $id, true );
				} else {
					$ok = wp_delete_post( $id, true );
					if ( $ok ) {
						self::detach_children( $id, $wpdb );
					}
				}
				if ( $ok ) {
					++$removed;
				}
				$progress = true;
				if ( microtime( true ) >= $deadline ) {
					return array( $removed, $after );
				}
			}

			if ( count( $ids ) < $batch ) {
				return array( $removed, null );
			}
		}
	}

	/**
	 * Point the children a deleted post left behind at no parent.
	 *
	 * wp_delete_post() moves the deleted post's attachments, and its children
	 * of the same hierarchical post type, up to its own parent. Plugins that
	 * own their children (WooCommerce variations) delete them on
	 * before_delete_post. A child of any other post type keeps a post_parent
	 * naming a post that no longer exists: a course's lessons, for example
	 * (#237).
	 *
	 * Those are set to 0, core's "no parent". They are not deleted: they may
	 * be published, and a scheduled cleaner must not remove live content. Not
	 * moved to the grandparent either, which core does only within one post
	 * type. For a lesson it would name a course the lesson was never under.
	 *
	 * @param int   $id   The deleted post.
	 * @param \wpdb $wpdb
	 * @return int Children detached.
	 */
	private static function detach_children( int $id, $wpdb ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the children of a post deleted a moment ago; nothing caches that.
		$children = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d", $id ) );
		if ( ! is_array( $children ) || array() === $children ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the same column core's wp_delete_post() rewrites for same-type children; caches are cleared below.
		$wpdb->update( $wpdb->posts, array( 'post_parent' => 0 ), array( 'post_parent' => $id ) );
		foreach ( $children as $child ) {
			clean_post_cache( (int) $child );
		}

		return count( $children );
	}

	/**
	 * Run a raw type's DELETE and report the drop in its scan count, the unit
	 * the UI shows. Without before/after, the paired transient DELETE
	 * (timeout + value = 2 rows per transient) reported "2" while the button
	 * said "(1)". (FBS-82149 Bug 3.)
	 *
	 * @param array $spec Registry entry with scan_sql_tpl + clean_sql_tpl.
	 * @param \wpdb $wpdb
	 */
	private static function delete_raw( array $spec, $wpdb ): int {
		$before = self::count_for( $spec, $wpdb );
		if ( 0 === $before ) {
			return 0;
		}

		$sql = self::fill( $spec['clean_sql_tpl'], $wpdb );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SQL built from a hardcoded template registry (types()); only $wpdb->prefix and esc_sql()'d registered taxonomy names are interpolated. Destructive cleanup explicitly bypasses the object cache.
		$wpdb->query( $sql );

		return max( 0, $before - self::count_for( $spec, $wpdb ) );
	}

	/** Fill a count or raw-delete template: prefix, then the taxonomy list. */
	private static function fill( string $tpl, $wpdb ): string {
		if ( false === strpos( $tpl, '%2$s' ) ) {
			return sprintf( $tpl, $wpdb->prefix );
		}
		return sprintf( $tpl, $wpdb->prefix, self::post_taxonomy_list() );
	}

	/**
	 * Quoted, escaped names of the taxonomies whose objects are all posts.
	 *
	 * A taxonomy qualifies only if every object type it is registered for is
	 * a registered post type. When none do, the list is '' and the orphan
	 * term relationship sweep matches nothing.
	 */
	private static function post_taxonomy_list(): string {
		$names = array();
		if ( function_exists( 'get_taxonomies' ) && function_exists( 'post_type_exists' ) ) {
			foreach ( (array) get_taxonomies( array(), 'objects' ) as $name => $taxonomy ) {
				$object_types = is_object( $taxonomy ) && isset( $taxonomy->object_type ) ? (array) $taxonomy->object_type : array();
				if ( array() === $object_types ) {
					continue;
				}
				foreach ( $object_types as $object_type ) {
					if ( ! post_type_exists( (string) $object_type ) ) {
						continue 2;
					}
				}
				$names[] = "'" . esc_sql( (string) $name ) . "'";
			}
		}
		return array() === $names ? "''" : implode( ', ', $names );
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
	 * and runs clean() on them within one tick's budget, resuming where the
	 * previous tick stopped.
	 */
	public static function cron_tick(): void {
		$opts  = Settings_Manager::get( 'database' );
		$types = is_array( $opts['included_types'] ?? null ) ? $opts['included_types'] : array();
		if ( empty( $types ) ) {
			return;
		}
		self::clean( $types, null, array( 'resume' => true ) );
	}

	/**
	 * Forget where the scheduled clean stopped. Called whenever the module's
	 * settings are written, so a cursor never resumes into a category the
	 * user has just turned off.
	 */
	public static function reset_cursor(): void {
		delete_option( self::CURSOR_OPTION );
	}

	/** Identity of a type selection, so a cursor is only honoured for the set it was written for. */
	private static function types_hash( array $keys ): string {
		sort( $keys );
		return sha1( implode( ',', $keys ) );
	}

	/**
	 * Where to resume: [ index into $keys, id to start after ].
	 *
	 * A cursor written for a different selection, or naming a type no longer
	 * in it, is discarded and the run starts from the top.
	 *
	 * @param string   $hash types_hash() of $keys.
	 * @param string[] $keys Types this run will clean, in registry order.
	 * @return array{0:int,1:int}
	 */
	private static function read_cursor( string $hash, array $keys ): array {
		$stored = get_option( self::CURSOR_OPTION, array() );
		if ( ! is_array( $stored ) || array() === $stored ) {
			return array( 0, 0 );
		}

		$index = isset( $stored['type'] ) ? array_search( (string) $stored['type'], $keys, true ) : false;
		if ( ( $stored['types_hash'] ?? '' ) !== $hash || false === $index ) {
			self::reset_cursor();
			return array( 0, 0 );
		}

		return array( (int) $index, max( 0, (int) ( $stored['after_id'] ?? 0 ) ) );
	}

	private static function write_cursor( string $hash, string $type, int $after_id ): void {
		$value = array(
			'types_hash' => $hash,
			'type'       => $type,
			'after_id'   => $after_id,
		);
		// autoload=no: read once per scheduled tick, never on a page view.
		if ( false === get_option( self::CURSOR_OPTION, false ) ) {
			add_option( self::CURSOR_OPTION, $value, '', 'no' );
			return;
		}
		update_option( self::CURSOR_OPTION, $value );
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
