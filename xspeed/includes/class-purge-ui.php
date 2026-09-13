<?php
/**
 * Single-URL purge surfaces: admin bar, post row actions, edit screen.
 *
 * `Cache::purge_url()` has always been able to clear one page, but the only
 * ways in were WP-CLI and the MCP tool. Someone who had just corrected a typo
 * on one page had to throw away the whole cache to see the fix, which on a
 * large site costs every other page its warm entry too. This class is the
 * missing entry point, in the three places the errand actually starts:
 *
 * - the admin bar, while looking at the page (front end) or editing it,
 * - the Posts/Pages list, in the hover row actions,
 * - the edit screen, in the xSpeed meta box.
 *
 * All three build the same nonce-protected admin-post URL and land in the
 * same handler, so there is one authorization path rather than three.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Purge_Ui {

	/** admin-post action for purging one URL (the front-end admin bar). */
	public const ACTION = 'xspeed_purge_url';

	/**
	 * admin-post action for purging one POST and the pages that list it.
	 *
	 * Separate from ACTION because the scope genuinely differs, and the nonce
	 * has to be bound to a post id rather than to a URL.
	 */
	public const POST_ACTION = 'xspeed_purge_post';

	/** Per-user transient prefix carrying one purge's result across the redirect. */
	private const NOTICE_KEY = 'xspeed_purge_result_';

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_' . self::POST_ACTION, array( __CLASS__, 'handle_post' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		// After Cache::admin_bar_purge() at 100, so the parent node it adds
		// already exists and this call merges into it.
		add_action( 'admin_bar_menu', array( __CLASS__, 'flag_admin_bar_result' ), 110 );
	}

	/**
	 * Can this user purge at all? Same capability the admin-bar menu and the
	 * dashboard purge button use — purging is a site-wide performance action,
	 * not something an author gets over their own posts.
	 */
	public static function user_can_purge(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * The URL the CURRENT screen is about, or '' when the screen isn't about
	 * one page.
	 *
	 * Two contexts resolve, deliberately:
	 *
	 * - Front end: whatever is being viewed. Taken from REQUEST_URI rather
	 *   than the queried object's permalink, because an archive or a paged
	 *   URL has no permalink at all, and the page on screen is the one the
	 *   user means.
	 * - Post edit screen: the edited post's permalink, since the admin URL
	 *   itself is never cached.
	 *
	 * Anywhere else there is no single page in view, so the caller hides the
	 * menu item rather than guessing.
	 */
	public static function current_target(): string {
		if ( ! is_admin() ) {
			if ( ! self::request_is_path_addressable() ) {
				return '';
			}
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- esc_url_raw sanitizes.
			if ( '' === $uri ) {
				return '';
			}
			// Drop the query string, exactly as cache_key() does with
			// strtok( $uri, '?' ) — /post and /post?utm_source=x are one
			// entry, so a link carrying the params would suggest it targets
			// something narrower than it does.
			$uri = (string) strtok( $uri, '?' );

			$root = self::request_root();
			return '' === $root ? '' : $root . $uri;
		}

		$post_id = self::edited_post_id();
		if ( $post_id <= 0 ) {
			return '';
		}
		return self::permalink_of( $post_id );
	}

	/**
	 * Post being edited on the current admin screen, or 0.
	 *
	 * `get_the_ID()` is unreliable this early on post.php, so read the
	 * request directly — post.php uses `post`, and nothing else on the edit
	 * screens carries a post id we should act on.
	 */
	/**
	 * Is the CURRENT front-end request one that `Cache::purge_url()` can
	 * actually reach by path?
	 *
	 * Three request shapes get a cache key that no path can address, because
	 * `cache_key()` builds them from something other than the URI:
	 *
	 * - a cacheable 404 shares one generic `md5( $host . '|404' )` entry per
	 *   host, so every 404 on the site is the same file,
	 * - a cached search folds the term in as `|s=…`, and the query string is
	 *   otherwise stripped,
	 * - a query-form feed (`/?feed=rss2`) folds the type in as `|feed=…`.
	 *
	 * Offering "Purge this URL" on those would purge the bare path instead —
	 * on a search page, the HOME page. Since the redirect carries no success
	 * notice, that lands as a silent wrong answer, so the item is hidden
	 * instead. (Where the matching feature is switched off the page is not
	 * cached at all, and hiding costs nothing.)
	 */
	private static function request_is_path_addressable(): bool {
		if ( function_exists( 'is_404' ) && is_404() ) {
			return false;
		}
		if ( function_exists( 'is_search' ) && is_search() ) {
			return false;
		}
		if ( function_exists( 'is_feed' ) && is_feed() ) {
			// A pretty-permalink feed (/feed/rss/) carries the type in the
			// path and is fine; only the query form is unreachable.
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared, never output or stored.
			if ( false !== strpos( $uri, 'feed=' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Scheme + host (+ port) the CURRENT request came in on, with no path.
	 *
	 * The host comes from HTTP_HOST rather than home_url() because that is
	 * what `cache_key()` hashed when the entry was written. Where the two
	 * disagree — a proxy forwarding `Host: site.com:8080`, a bare-vs-www
	 * mismatch, a mapped domain — home_url()'s host computes a different md5,
	 * finds no file and reports "already cold" while the page keeps serving
	 * HIT.
	 *
	 * REQUEST_URI is already absolute from the domain root, so it must NOT be
	 * passed through home_url(): on a subdirectory install that prepends the
	 * subdirectory a second time and the link points at `/blog/blog/about/`.
	 */
	private static function request_root(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		if ( '' === $host ) {
			return self::home_root();
		}
		return ( is_ssl() ? 'https' : 'http' ) . '://' . $host;
	}

	/** Scheme + host (+ port) of home_url(), with no path. */
	private static function home_root(): string {
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return '';
		}
		$root = ( $home['scheme'] ?? 'http' ) . '://' . $home['host'];
		if ( ! empty( $home['port'] ) ) {
			$root .= ':' . (int) $home['port'];
		}
		return $root;
	}

	private static function edited_post_id(): int {
		global $pagenow;
		if ( 'post.php' !== $pagenow ) {
			return 0;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which post is on screen, no state change.
		return isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
	}

	/**
	 * Permalink of a post, but only when the post is something a visitor can
	 * actually reach — a draft or a non-viewable type has no cached page to
	 * clear, so offering the action would be a button that always reports
	 * "already cold".
	 */
	public static function permalink_of( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		if ( 'publish' !== $post->post_status ) {
			return '';
		}
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return '';
		}
		$link = get_permalink( $post );
		return is_string( $link ) ? $link : '';
	}

	/**
	 * Nonce-protected admin-post URL that purges one URL.
	 *
	 * The nonce action is bound to the target URL, so a link leaked from one
	 * page can't be replayed to purge a different one. The URL is hashed into
	 * the action rather than concatenated raw to keep the action short and
	 * free of characters `wp_create_nonce` would otherwise carry verbatim.
	 */
	public static function purge_link( string $url ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					// add_query_arg() does NOT encode values (build_query()
					// passes $urlencode = false), so a URL carrying its own
					// query string would otherwise swallow the nonce.
					'url'    => rawurlencode( $url ),
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $url )
		);
	}

	private static function nonce_action( string $url ): string {
		return self::ACTION . '_' . md5( $url );
	}

	/**
	 * "Purge cache" in the Posts/Pages hover row actions.
	 *
	 * @param array<string,string> $actions Existing row actions.
	 * @param \WP_Post             $post    Row's post.
	 * @return array<string,string>
	 */
	public static function row_action( $actions, $post ) {
		if ( ! is_array( $actions ) || ! $post instanceof \WP_Post ) {
			return $actions;
		}
		if ( ! self::user_can_purge() ) {
			return $actions;
		}
		if ( '' === self::permalink_of( (int) $post->ID ) ) {
			return $actions;
		}

		$actions['xspeed_purge'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::post_purge_link( (int) $post->ID ) ),
			esc_html__( 'Purge cache', 'xspeed' )
		);
		return $actions;
	}

	/**
	 * Purge one URL, then send the user back where they came from.
	 *
	 * The URL is re-validated against this site's home host instead of being
	 * trusted from the query string. `Cache::purge_url()` derives its cache
	 * directory from the host it is given, so an off-site host would have it
	 * walking a bucket that isn't ours.
	 */
	public static function handle(): void {
		if ( ! self::user_can_purge() ) {
			wp_die( esc_html__( 'Unauthorized.', 'xspeed' ), 403 );
		}

		// PHP has already percent-decoded $_GET once, which undoes the
		// rawurlencode() purge_link() applied. Decoding a second time here
		// would corrupt any URL containing a literal percent sequence, and
		// the nonce below is bound to the value BEFORE that encoding.
		$url = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- esc_url_raw sanitizes; the nonce below binds this exact value.
		check_admin_referer( self::nonce_action( $url ) );

		if ( '' === $url || ! self::is_local_url( $url ) ) {
			wp_die( esc_html__( 'That URL is not on this site.', 'xspeed' ), 400 );
		}

		self::record_result( $url, Cache::purge_url( $url, __( 'admin', 'xspeed' ) ), 1 );

		wp_safe_redirect( self::redirect_target( wp_get_referer() ) );
		exit;
	}

	/**
	 * Where to send the user back to.
	 *
	 * Cache::safe_purge_redirect() strips `action` from the referer so a
	 * one-shot admin action (a plugin upload) isn't replayed on load. That is
	 * right everywhere except the editor: post.php with no `action` falls
	 * through to its default case and redirects to edit.php, so purging from
	 * the meta box threw the user out of the post they were editing. Rebuild
	 * the edit URL for that one case.
	 *
	 * @param string|false $referer Raw wp_get_referer() value.
	 */
	private static function redirect_target( $referer ): string {
		$referer = is_string( $referer ) ? $referer : '';
		if ( '' !== $referer ) {
			$path = (string) wp_parse_url( $referer, PHP_URL_PATH );
			if ( preg_match( '#/wp-admin/post\.php$#', $path ) ) {
				parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $query );
				$post_id = isset( $query['post'] ) ? absint( $query['post'] ) : 0;
				if ( $post_id > 0 ) {
					return get_edit_post_link( $post_id, 'raw' ) ?: admin_url();
				}
			}
		}

		return Cache::safe_purge_redirect( $referer );
	}

	/**
	 * Remember what the purge did, for the page the user lands on next.
	 *
	 * A transient rather than a query argument: the front-end redirect goes
	 * back to the page that was just purged, and hanging `?xspeed_purged=1`
	 * off it would leave the marker sitting in the address bar and in
	 * anything the visitor copies out of it.
	 *
	 * Keyed per user, so two admins purging at once don't read each other's
	 * result, and short-lived because it is only ever meant to survive one
	 * redirect.
	 */
	private static function record_result( string $url, int $count, int $urls = 1 ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		set_transient(
			self::NOTICE_KEY . $user_id,
			array(
				'url'   => $url,
				'count' => $count,
				'urls'  => $urls,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read the pending result and clear it. Consumed once: whichever surface
	 * renders first owns it, and a reload afterwards shows nothing.
	 *
	 * @return array{url:string,count:int,urls:int}|null
	 */
	private static function take_result(): ?array {
		$result = self::peek_result();
		if ( null === $result ) {
			return null;
		}
		delete_transient( self::NOTICE_KEY . get_current_user_id() );

		return $result;
	}

	/**
	 * Read the pending result WITHOUT clearing it, so a caller that turns out
	 * not to be the right place to show it can leave it for the next screen.
	 *
	 * @return array{url:string,count:int,urls:int}|null
	 */
	private static function peek_result(): ?array {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return null;
		}
		$result = get_transient( self::NOTICE_KEY . $user_id );
		if ( ! is_array( $result ) || ! isset( $result['url'] ) ) {
			return null;
		}

		return array(
			'url'   => (string) $result['url'],
			'count' => (int) ( $result['count'] ?? 0 ),
			'urls'  => max( 1, (int) ( $result['urls'] ?? 1 ) ),
		);
	}

	/**
	 * What to tell the user.
	 *
	 * A count of zero is reported as such rather than as success. The page
	 * having no cached copy is the single most useful thing to know here —
	 * it means either the purge already happened or the page was never
	 * cacheable, and calling that "cleared" sends people looking for a bug
	 * in the wrong place.
	 *
	 * @param array{url:string,count:int,urls:int} $result
	 */
	private static function message( array $result ): string {
		$path = (string) wp_parse_url( $result['url'], PHP_URL_PATH );
		$path = '' === $path ? '/' : $path;

		// A post purge also clears the pages that list it, so say so — a user
		// who asked for one page and sees "12 files" should not have to guess
		// whether something over-reached.
		$scope = $result['urls'] > 1
			? sprintf(
				/* translators: 1: URL path of the post, 2: number of OTHER pages also cleared. */
				_n(
					'%1$s and %2$d page that lists it',
					'%1$s and %2$d pages that list it',
					$result['urls'] - 1,
					'xspeed'
				),
				$path,
				$result['urls'] - 1
			)
			: $path;

		if ( $result['count'] < 1 ) {
			return sprintf(
				/* translators: %s: what was purged. */
				__( 'xSpeed: %s was not cached, so there was nothing to clear.', 'xspeed' ),
				$scope
			);
		}

		return sprintf(
			/* translators: 1: what was purged, 2: number of files removed. */
			_n(
				'xSpeed: cleared the cache for %1$s (%2$d file).',
				'xSpeed: cleared the cache for %1$s (%2$d files).',
				$result['count'],
				'xspeed'
			),
			$scope,
			$result['count']
		);
	}

	/** Admin surfaces: the row action and the editor button land here. */
	public static function render_admin_notice(): void {
		if ( ! self::user_can_purge() ) {
			return;
		}
		$result = self::take_result();
		if ( null === $result ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			$result['count'] > 0 ? 'success' : 'info',
			esc_html( self::message( $result ) )
		);
	}

	/**
	 * Front end: `admin_notices` never fires there, and the redirect lands on
	 * the purged page itself. Say it in the admin bar instead — the one piece
	 * of our UI already on screen, styled by core, needing no stylesheet and
	 * no script on a front-end page view.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar
	 */
	public static function flag_admin_bar_result( $wp_admin_bar ): void {
		if ( is_admin() || ! self::user_can_purge() ) {
			return; // In wp-admin the notice above owns the result.
		}
		if ( ! is_object( $wp_admin_bar ) || ! method_exists( $wp_admin_bar, 'get_node' ) ) {
			return;
		}
		$node = $wp_admin_bar->get_node( 'xspeed-purge' );
		if ( ! $node ) {
			return; // Menu not rendered (no capability, or a filter removed it).
		}
		// Peek before consuming. The redirect lands on the page that was
		// purged, but the user may have opened another tab first — burning
		// the confirmation on an unrelated front-end view would leave the
		// purge looking like it did nothing. Anything not aimed at THIS page
		// is left for the screen it belongs to; it expires on its own.
		$result = self::peek_result();
		if ( null === $result || ! self::result_is_about_this_request( $result ) ) {
			return;
		}
		self::take_result();

		$wp_admin_bar->add_node(
			array(
				'id'    => 'xspeed-purge',
				'title' => $node->title . ' · ' . (
					$result['count'] > 0
						? esc_html__( 'cleared', 'xspeed' )
						: esc_html__( 'was not cached', 'xspeed' )
				),
				'meta'  => array( 'title' => self::message( $result ) ),
			)
		);
	}

	/**
	 * The "purge what I'm looking at" admin-bar item for this screen, or null
	 * when the screen isn't about one thing.
	 *
	 * The two contexts want different scopes, which is why this returns a
	 * whole node rather than a URL:
	 *
	 * - On the front end you are looking at ONE rendered page, and that page
	 *   is what you want gone. Anything else would be a surprise.
	 * - On a post edit screen you have just changed a post, and the post's own
	 *   URL is rarely the only page that got stale — the homepage, the archive
	 *   and the neighbouring posts all render its title. Purging just the
	 *   permalink there leaves the visitor's route TO the post showing the old
	 *   version, which reads as "the purge didn't work".
	 *
	 * @return array{title:string,href:string}|null
	 */
	public static function context_node(): ?array {
		if ( ! self::user_can_purge() ) {
			return null;
		}

		if ( is_admin() ) {
			$post_id = self::edited_post_id();
			if ( $post_id <= 0 || '' === self::permalink_of( $post_id ) ) {
				return null;
			}
			return array(
				'title' => __( 'Purge this post', 'xspeed' ),
				'href'  => self::post_purge_link( $post_id ),
			);
		}

		$target = self::current_target();
		if ( '' === $target ) {
			return null;
		}
		return array(
			'title' => __( 'Purge this URL', 'xspeed' ),
			'href'  => self::purge_link( $target ),
		);
	}

	/** Nonce-protected admin-post URL that purges one post and its listings. */
	public static function post_purge_link( int $post_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::POST_ACTION,
					'post'   => $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::POST_ACTION . '_' . $post_id
		);
	}

	/**
	 * Every URL that goes stale when one post changes.
	 *
	 * The set follows WP Rocket's `rocket_get_purge_urls()`, which is the
	 * closest thing this problem has to a settled answer: the post itself,
	 * the blog page or the post-type archive it appears on, the four
	 * adjacent posts whose prev/next links now name a different neighbour,
	 * the author archive, every ancestor, and the homepage.
	 *
	 * Term archives are deliberately NOT in the set — Rocket leaves them out
	 * too. A post can carry dozens of terms, and purging every one of them
	 * turns a one-post edit back into the broad sweep this feature exists to
	 * avoid.
	 *
	 * @return string[] Absolute URLs, de-duplicated.
	 */
	public static function post_purge_urls( \WP_Post $post ): array {
		$urls = array();

		$permalink = self::permalink_of( (int) $post->ID );
		if ( '' !== $permalink ) {
			$urls[] = $permalink;
		}

		// The blog page for posts; the post-type archive for anything else.
		if ( 'post' === $post->post_type ) {
			$page_for_posts = (int) get_option( 'page_for_posts' );
			if ( $page_for_posts > 0 ) {
				$urls[] = (string) get_permalink( $page_for_posts );
			}
		} else {
			$archive = get_post_type_archive_link( $post->post_type );
			if ( is_string( $archive ) && '' !== $archive ) {
				$urls[] = $archive;
			}
		}

		// The neighbours whose own prev/next links now point somewhere else.
		// Read in the post's own context: get_adjacent_post() works off the
		// global $post, which on an admin screen is not the one being purged.
		$urls = array_merge( $urls, self::adjacent_post_urls( $post ) );

		$author = get_author_posts_url( (int) $post->post_author );
		if ( is_string( $author ) && '' !== $author ) {
			$urls[] = $author;
		}

		foreach ( get_post_ancestors( $post ) as $ancestor_id ) {
			$link = self::permalink_of( (int) $ancestor_id );
			if ( '' !== $link ) {
				$urls[] = $link;
			}
		}

		$urls[] = home_url( '/' );

		/**
		 * Filter the URLs cleared when one post is purged.
		 *
		 * @param string[] $urls Absolute URLs.
		 * @param \WP_Post $post The post being purged.
		 */
		$urls = (array) apply_filters( 'xspeed_post_purge_urls', $urls, $post );

		$urls = array_filter( $urls, static fn( $url ) => is_string( $url ) && '' !== $url );

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Permalinks of the four posts adjacent to this one: previous and next,
	 * each in the whole timeline and within a shared term.
	 *
	 * @return string[]
	 */
	private static function adjacent_post_urls( \WP_Post $post ): array {
		$urls = array();

		// get_adjacent_post() reads the global $post. Swap it for the one
		// being purged and put it back, or on an edit screen we would collect
		// the neighbours of whatever WordPress happened to have loaded.
		$previous_global = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.

		foreach ( array( array( false, true ), array( true, true ), array( false, false ), array( true, false ) ) as $args ) {
			list( $same_term, $previous ) = $args;
			$adjacent                     = get_adjacent_post( $same_term, '', $previous );
			if ( $adjacent instanceof \WP_Post ) {
				$link = self::permalink_of( (int) $adjacent->ID );
				if ( '' !== $link ) {
					$urls[] = $link;
				}
			}
		}

		if ( null === $previous_global ) {
			unset( $GLOBALS['post'] );
		} else {
			$GLOBALS['post'] = $previous_global; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
		}

		return $urls;
	}

	/**
	 * Purge one post and everything that lists it.
	 *
	 * Same shape as handle(): the nonce is bound to the post id, the
	 * capability is checked first, and the result is carried to the next
	 * screen so the user is told what happened.
	 */
	public static function handle_post(): void {
		if ( ! self::user_can_purge() ) {
			wp_die( esc_html__( 'Unauthorized.', 'xspeed' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked on the next line, against this value.
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		check_admin_referer( self::POST_ACTION . '_' . $post_id );

		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			wp_die( esc_html__( 'That post does not exist.', 'xspeed' ), 400 );
		}

		$count   = 0;
		$cleared = 0;
		foreach ( self::post_purge_urls( $post ) as $url ) {
			// Count only what we actually acted on. The set is filterable, so
			// an off-site URL added through xspeed_post_purge_urls is skipped
			// here — reporting it as cleared would inflate the notice.
			if ( ! self::is_local_url( $url ) ) {
				continue;
			}
			++$cleared;
			$count += Cache::purge_url( $url, __( 'admin', 'xspeed' ) );
		}

		self::record_result( self::permalink_of( $post_id ), $count, $cleared );

		wp_safe_redirect( self::redirect_target( wp_get_referer() ) );
		exit;
	}

	/**
	 * Does a pending result describe the page currently being rendered?
	 *
	 * Compared on path alone: the result was recorded against an absolute URL
	 * built from the request that purged it, and the host on the request
	 * showing the notice is the same one by construction.
	 *
	 * @param array{url:string,count:int,urls:int} $result
	 */
	private static function result_is_about_this_request( array $result ): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared, never output or stored.
		if ( '' === $uri ) {
			return false;
		}
		$here   = untrailingslashit( (string) strtok( $uri, '?' ) );
		$purged = untrailingslashit( (string) wp_parse_url( $result['url'], PHP_URL_PATH ) );

		return $here === $purged;
	}

	/**
	 * Is this URL served by this site?
	 *
	 * Compared with port attached, because the cache key hashes the host WITH
	 * its port — `site.test` and `site.test:8080` are separate buckets.
	 *
	 * More than one host can be the right answer: home_url() and site_url()
	 * differ on a WordPress-in-a-subdirectory install, and a proxy or a mapped
	 * domain means the host the page was CACHED under is the one on the
	 * request rather than the one in the option. All three are accepted. The
	 * real gate is the nonce, which is bound to this exact URL and mintable
	 * only by a user who can already purge; this check exists so a
	 * hand-edited URL can't point `purge_url()` at some other site's bucket.
	 */
	public static function is_local_url( string $url ): bool {
		$target = wp_parse_url( $url );
		if ( ! is_array( $target ) || empty( $target['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $target['host'] );
		if ( ! empty( $target['port'] ) ) {
			$host .= ':' . (int) $target['port'];
		}
		return in_array( $host, self::known_hosts(), true );
	}

	/**
	 * Hosts (with port where non-default) this install answers on.
	 *
	 * @return string[]
	 */
	private static function known_hosts(): array {
		$hosts = array();
		foreach ( array( home_url( '/' ), site_url( '/' ) ) as $known ) {
			$parts = wp_parse_url( (string) $known );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}
			$host = strtolower( (string) $parts['host'] );
			if ( ! empty( $parts['port'] ) ) {
				$host .= ':' . (int) $parts['port'];
			}
			$hosts[] = $host;
		}
		if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$hosts[] = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
		}
		return array_values( array_unique( $hosts ) );
	}
}
