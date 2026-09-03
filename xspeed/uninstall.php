<?php
/**
 * Uninstall handler — deletes options, cache, and drop-in.
 *
 * @package XSpeed
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

xspeed_uninstall_cleanup();

/**
 * Run all uninstall cleanup. Wrapped in a function so locals don't pollute global scope.
 */
function xspeed_uninstall_cleanup() {
	delete_option( 'xspeed_options' );

	/*
	 * Per-module rows, for the modules THIS plugin ships. The slugs are read
	 * out of the module files rather than kept as a list here, so a module
	 * added later cannot leave its row behind; and only Free's, because
	 * xspeed-pro keeps its own rows under the same prefix and a Free uninstall
	 * is not a decision about Pro's settings.
	 *
	 * These have to go. The conflict-safe profile writes an explicit `false`
	 * into every one of them when another plugin owns the page cache, and
	 * seeding on the next install only fills ABSENT keys — so a row that
	 * survived uninstall kept every switch off on a site that had since been
	 * cleared, with no way back but the dashboard (PR #295 review).
	 */
	foreach ( glob( __DIR__ . '/includes/modules/*/*Module.php' ) ?: array() as $module_file ) {
		$source = @file_get_contents( $module_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- own plugin file, uninstall.
		if ( is_string( $source ) && preg_match( "/const\s+SLUG\s*=\s*'([^']+)'/", $source, $m ) ) {
			delete_option( 'xspeed_module_' . $m[1] );
		}
	}
	delete_option( 'xspeed_data_version' );
	delete_option( 'xspeed_activity_log' );
	delete_option( 'xspeed_server_type' );
	delete_option( 'xspeed_oc_dropin_synced' );
	delete_option( 'xspeed_last_mobile_separate' );
	delete_option( 'xspeed_redirect_to_onboarding' );
	delete_option( 'xspeed_onboarding_complete' );
	// Provenance a host plugin wrote before it activated us, and the profile
	// that install came up with.
	delete_option( 'xspeed_installed_by' );
	delete_option( 'xspeed_installer' );
	delete_option( 'xspeed_install_profile' );
	// Written by the copy-vendored Setup that host plugins used to carry
	// before xSpeed seeded its own conflict-safe profile on activation. We no
	// longer write it; an older host may have, and it is ours to clean up.
	delete_option( 'xspeed_setup_snapshot' );
	delete_option( 'xspeed_stats' );
	delete_option( 'xspeed_gc_cursor' );
	wp_clear_scheduled_hook( 'xspeed_gc' );

	// Score history — the plugin's own table, plus the legacy option the
	// table was migrated from (kept on upgrade so a bad migration is
	// recoverable; there is nothing to recover on uninstall).
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, uninstall.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'xspeed_scores' );
	delete_option( 'xspeed_score_schema' );
	delete_option( 'xspeed_score_history' );

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;
	if ( ! $wp_filesystem ) {
		return;
	}

	$cache_dir = WP_CONTENT_DIR . '/cache/xspeed';
	if ( $wp_filesystem->is_dir( $cache_dir ) ) {
		$wp_filesystem->delete( $cache_dir, true );
	}

	// nginx hit log (FBS-82478). It lives under uploads/xspeed/, NOT the
	// cache dir, precisely so that a pasted nginx `access_log` directive
	// pointing at it does NOT get its parent directory deleted here — if it
	// did, `nginx -t` would fail [emerg] and refuse to (re)start, taking
	// down EVERY vhost on the host until someone manually finds the orphaned
	// directive. We therefore EMPTY the log file but DELIBERATELY LEAVE THE
	// DIRECTORY in place, so any still-pasted directive keeps a valid,
	// openable target after the plugin is gone. (A stray empty dir is
	// harmless; a broken nginx is not.) Users are also warned in the admin
	// UI to remove the snippet before uninstalling.
	$hits_log = WP_CONTENT_DIR . '/uploads/xspeed/hits.log';
	if ( function_exists( 'wp_upload_dir' ) ) {
		$uploads = wp_upload_dir( null, false );
		if ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			$hits_log = rtrim( (string) $uploads['basedir'], '/' ) . '/xspeed/hits.log';
		}
	}
	if ( $wp_filesystem->exists( $hits_log ) ) {
		// Truncate, don't delete the dir — keep the access_log target openable.
		$wp_filesystem->put_contents( $hits_log, '', FS_CHMOD_FILE );
	}

	// Static-cache tree — separate from the flat-hash cache dir, holds
	// the {host}/{path}/index.html files the .htaccess rewrite block
	// serves directly. Same teardown rules as XSPEED_CACHE_DIR.
	$static_dir = WP_CONTENT_DIR . '/cache/xspeed-static';
	if ( $wp_filesystem->is_dir( $static_dir ) ) {
		$wp_filesystem->delete( $static_dir, true );
	}

	// Rewrite block in site-root .htaccess. WP's marker helpers handle
	// the "remove only our block" semantics — passing an empty array
	// strips the markers in place.
	$htaccess = ABSPATH . '.htaccess';
	if ( $wp_filesystem->exists( $htaccess ) && $wp_filesystem->is_writable( $htaccess ) ) {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		insert_with_markers( $htaccess, 'xSpeed Static Cache', array() );
	}

	// Serialize the shared drop-in/config teardown with Cache::toggle(). If
	// the lock is unavailable, leave shared state and its receipt untouched.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- flock requires a local handle; failure is fail-closed.
	$ownership_lock = @fopen( WP_CONTENT_DIR . '/.xspeed-page-cache.lock', 'c+' );
	if ( ! is_resource( $ownership_lock ) || ! flock( $ownership_lock, LOCK_EX ) ) {
		return;
	}

	require_once __DIR__ . '/includes/wp-cache-constant.php';
	$drop_in     = WP_CONTENT_DIR . '/advanced-cache.php';
	$dropin_ours = false;
	if ( $wp_filesystem->exists( $drop_in ) ) {
		$contents    = $wp_filesystem->get_contents( $drop_in );
		$dropin_ours = is_string( $contents ) && xspeed_has_canonical_dropin_signature( $contents );
		if ( $dropin_ours ) {
			$wp_filesystem->delete( $drop_in );
		}
	}

	$wp_config = file_exists( ABSPATH . 'wp-config.php' ) ? ABSPATH . 'wp-config.php' : dirname( ABSPATH ) . '/wp-config.php';
	// `defined()` alone, not `&& WP_CACHE`: the old truthiness test skipped
	// the removal whenever the constant was false/0 — exactly the orphan we
	// are here to clean up — while still entering it for TRUE/1, where the
	// hardcoded-lowercase regex then matched nothing and reported success.
	// Strip whatever spelling is there. (#9)
	//
	// Gated on the drop-in being ours: if another caching plugin holds
	// advanced-cache.php, WP_CACHE is the switch that loads THEIR file, and
	// stripping it on our way out would silently disable their page cache.
	if ( $wp_filesystem->is_writable( $wp_config ) ) {
		$config = $wp_filesystem->get_contents( $wp_config );
		if ( is_string( $config ) ) {
			// Same helper Cache::set_wp_cache_constant() uses — one pattern,
			// one place. uninstall.php loads no plugin classes, hence a
			// plain requirable function rather than a method.
			require_once __DIR__ . '/includes/wp-cache-constant.php';
			$receipt = get_option( 'xspeed_page_cache_ownership_receipt', '' );
			$marked  = xspeed_wp_cache_receipt_matches( $config, $receipt );
			/*
			 * A receipt proves WE wrote the line; it does not prove the line
			 * is still ours to remove. If a competitor has since taken over
			 * advanced-cache.php, WP_CACHE is what loads THEIR drop-in — they
			 * had no reason to touch an already-true define, so our receipt
			 * comment is still sitting beside it. Stripping on the receipt
			 * alone silently stopped their live page cache.
			 *
			 * A foreign drop-in therefore vetoes the removal outright, which
			 * is the same rule Cache::set_wp_cache_constant() applies in the
			 * running plugin; only this path was missing it. `$dropin_ours`
			 * covers the file we just deleted, `$marked` the case where there
			 * is no drop-in left at all.
			 */
			$foreign_dropin = $wp_filesystem->exists( $drop_in ) && ! $dropin_ours;
			if ( ! $foreign_dropin && ( $dropin_ours || $marked ) ) {
				$config = xspeed_strip_wp_cache_define( $config );
				$wp_filesystem->put_contents( $wp_config, $config, FS_CHMOD_FILE );
			}
		}
	}
	delete_option( 'xspeed_page_cache_ownership_receipt' );
	flock( $ownership_lock, LOCK_UN );
	fclose( $ownership_lock );
	// Our own lock file, left in wp-content after everything else of ours is
	// gone. Deleted last, after the handle is closed, so we are not
	// unlinking a lock we are still inside. That ordering is hygiene, not a
	// guarantee: a request already past the fopen would hold a handle to an
	// unlinked inode. Harmless here — by this point the plugin is being
	// removed and nothing will take the lock again.
	$wp_filesystem->delete( WP_CONTENT_DIR . '/.xspeed-page-cache.lock' );
}
