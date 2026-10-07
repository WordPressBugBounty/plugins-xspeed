<?php
/**
 * Affected_Pages — which cached pages one post change can make render
 * differently.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * The list a narrow purge clears: the post, the address it had before the
 * save, and every list it joins or leaves, with all of their pages and feeds.
 *
 * The rules are the ones every cache plugin we compared uses (see
 * xspeed-pro docs/research/2026-10-04-purge-dependency-competitor-review.md),
 * with two additions none of them make: the OLD terms of a post that moved
 * category, from `set_object_terms`, and the OLD address of a post whose slug
 * or status changed, from the copy WordPress hands `wp_after_insert_post`.
 * Without them the category a post left keeps listing it, and the old URL
 * keeps serving it.
 *
 * Rules cannot see a post list a theme draws outside the main loop: a
 * "Recent posts" widget, a Query Loop block in a template such as Twenty
 * Twenty-Five's "More posts" under every post. When a change alters one,
 * lists_changed_by() says so and the caller purges the whole site instead;
 * an edit to an older post usually alters none. The same goes for lists of
 * pages, menus, and category and tag lists. A page whose own content holds
 * such a block, directly or in a synced pattern, is found and added to the
 * list. Page builder and block plugin grids, and lists hard-coded in a
 * classic theme's PHP, are not visible here; Listing_Pages records the
 * pages that ran one, and the caller adds those.
 *
 * Everything returned is an absolute URL on this site. Pages are listed one
 * by one rather than as prefixes because the flat cache tree has no
 * directories to clear.
 */
final class Affected_Pages {

	/** Over this many URLs a save purges the whole site instead. */
	public const LIMIT = 150;

	/**
	 * Archives up to this many pages are listed in full even for a plain
	 * edit. Past it, a plain edit lists only the page that holds the post.
	 */
	private const PAGED_IN_FULL = 5;

	/** Most pages with list blocks in their content that are listed one by one. */
	private const LIST_PAGE_LIMIT = 50;

	/**
	 * Old term_taxonomy_ids per post, captured as a save changes them.
	 *
	 * @var array<int,array<int,int>>
	 */
	private static $old_terms = array();

	/**
	 * The same old term_taxonomy_ids, grouped by taxonomy, so a save can be
	 * compared with the terms it left in each taxonomy it set.
	 *
	 * @var array<int,array<string,array<int,int>>>
	 */
	private static $old_terms_by_taxonomy = array();

	/**
	 * Per-request answer of post_list_specs().
	 *
	 * @var array<int,array{kind:string,type?:string,n?:int,sticky?:bool,ids?:int[]}>|null
	 */
	private static $post_list_specs = null;

	/**
	 * Per-request parsed blocks of active block widgets and the theme's
	 * templates, flattened.
	 *
	 * @var array<int,array>|null
	 */
	private static $theme_blocks = null;

	/**
	 * Neighbours' permalinks per post, read before a save moved it.
	 *
	 * @var array<int,string[]>
	 */
	private static $old_neighbours = array();

	/**
	 * Posts stuck or unstuck in this request, keyed by ID. The block editor
	 * changes `sticky_posts` before the save's purge runs, so the option
	 * alone no longer says the post was sticky.
	 *
	 * @var array<int,bool>
	 */
	private static $sticky_changed = array();

	/** Per-request answer of site_lists_comments(). @var bool|null */
	private static $site_lists_comments = null;

	/** Transient holding list_block_post_ids() between saves. */
	private const LIST_PAGES_TRANSIENT = 'xspeed_list_block_pages';

	/** Block comments that mark a post list in post content. */
	private const LIST_MARKERS = array( '<!-- wp:latest-posts', '<!-- wp:query ', '<!-- wp:archives' );

	/** A synced pattern placed in post content, which may hold a list. */
	private const SYNCED_MARKER = '<!-- wp:block {"ref":';

	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'set_object_terms', array( __CLASS__, 'remember_old_terms' ), 10, 6 );
		// Before Cache's own save_post handler, so a page that just gained a
		// list block is already known when the purge runs.
		add_action( 'save_post', array( __CLASS__, 'forget_list_pages_on_save' ), 5, 2 );
	}

	/**
	 * Drop the cached list of pages with list blocks when a post that has one
	 * is saved, or one that places a synced pattern (which may hold a list).
	 * A page that loses its block stays listed until the cache expires, which
	 * costs one extra page purged per save, never a stale one.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function forget_list_pages_on_save( $post_id, $post = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- hook signature.
		$content = is_object( $post ) && isset( $post->post_content ) ? (string) $post->post_content : '';
		foreach ( array_merge( self::LIST_MARKERS, array( self::SYNCED_MARKER ) ) as $marker ) {
			if ( false !== strpos( $content, $marker ) ) {
				delete_transient( self::LIST_PAGES_TRANSIENT );
				return;
			}
		}
	}

	/**
	 * Keep the terms a post had before this save replaced them.
	 *
	 * Called once per taxonomy. The first call for a taxonomy carries what
	 * the post had before the save; later calls in the same request (the
	 * REST API sets terms after `save_post`, then again) carry what an
	 * earlier call already set, so the union is what matters.
	 *
	 * @param int    $object_id  Post ID.
	 * @param array  $terms      Terms passed in.
	 * @param array  $tt_ids     New term_taxonomy_ids.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Whether terms were appended.
	 * @param array  $old_tt_ids Term_taxonomy_ids before the change.
	 */
	public static function remember_old_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- hook signature.
		$object_id = (int) $object_id;
		if ( $object_id < 1 || ! is_array( $old_tt_ids ) ) {
			return;
		}
		self::$old_terms_by_taxonomy[ $object_id ][ (string) $taxonomy ] = self::$old_terms_by_taxonomy[ $object_id ][ (string) $taxonomy ] ?? array();
		foreach ( $old_tt_ids as $tt_id ) {
			self::$old_terms[ $object_id ][ (int) $tt_id ]                                   = (int) $tt_id;
			self::$old_terms_by_taxonomy[ $object_id ][ (string) $taxonomy ][ (int) $tt_id ] = (int) $tt_id;
		}
	}

	/**
	 * The pages a change to this post can make render differently.
	 *
	 * @param \WP_Post      $post   The post as it is now.
	 * @param \WP_Post|null $before The post before this save, when known.
	 * @return string[] Absolute URLs, de-duplicated.
	 */
	public static function for_post( \WP_Post $post, ?\WP_Post $before = null ): array {
		$urls = array();

		$now_public    = self::is_public( $post );
		$before_public = $before instanceof \WP_Post && self::is_public( $before );

		if ( $now_public ) {
			$link = get_permalink( $post );
			if ( is_string( $link ) && '' !== $link ) {
				$urls[] = $link;
				$urls   = array_merge( $urls, self::split_pages( $post, $link ), self::comment_pages( $post, $link ) );
			}
		}
		// The address it had. Different after a slug or parent change; the
		// only one there is after unpublishing or trashing.
		if ( $before_public ) {
			$old = get_permalink( $before );
			if ( is_string( $old ) && '' !== $old ) {
				$urls[] = $old;
			}
		}

		// Nothing anonymous could see before or after: no list changed.
		if ( ! $now_public && ! $before_public ) {
			return array();
		}

		$type = (string) $post->post_type;

		// An edit that cannot move the post within a date-ordered list. On a
		// long list only the page holding the post changed, so position()
		// finds it; every later page keeps the same posts.
		$plain = self::is_plain_change( $post, $before );
		$date  = (string) ( $post->post_date ?? '' );

		// Home and the posts page list `post`.
		if ( 'post' === $type ) {
			$count = self::published_count( 'post' );
			$at    = $plain ? static fn() => self::position( array( 'post' ), '', $date ) : null;
			if ( 'page' === get_option( 'show_on_front' ) ) {
				$urls[]  = home_url( '/' );
				$page_id = (int) get_option( 'page_for_posts' );
				if ( $page_id > 0 ) {
					$posts_page = get_permalink( $page_id );
					if ( is_string( $posts_page ) && '' !== $posts_page ) {
						$urls = array_merge( $urls, self::paged( $posts_page, $count, $at ) );
					}
				}
			} else {
				$urls = array_merge( $urls, self::paged( home_url( '/' ), $count, $at ) );
			}
		} else {
			// A static front page can show anything: a block, a shortcode.
			$urls[] = home_url( '/' );
		}

		// The post type's own archive.
		if ( 'post' !== $type && 'page' !== $type ) {
			$archive = get_post_type_archive_link( $type );
			if ( is_string( $archive ) && '' !== $archive ) {
				$at   = $plain ? static fn() => self::position( array( $type ), '', $date ) : null;
				$urls = array_merge( $urls, self::paged( $archive, self::published_count( $type ), $at ) );
				$feed = get_post_type_archive_feed_link( $type );
				if ( is_string( $feed ) && '' !== $feed ) {
					$urls[] = $feed;
				}
			}
		}

		// Every public term it is in now, and every one it was in before.
		$urls = array_merge( $urls, self::term_pages( $post, $plain ) );

		// The author's archive.
		if ( (int) ( $post->post_author ?? 0 ) > 0 && post_type_supports( $type, 'author' ) ) {
			$author = get_author_posts_url( (int) $post->post_author );
			if ( is_string( $author ) && '' !== $author ) {
				$author_id = (int) $post->post_author;
				$at        = $plain ? static fn() => self::position( array( $type ), self::prepare( ' AND p.post_author = %d', $author_id ), $date ) : null;
				$urls      = array_merge( $urls, self::paged( $author, (int) count_user_posts( $author_id, $type, true ), $at ) );
				$urls[]    = get_author_feed_link( $author_id );
			}
		}

		// Date archives list `post` only.
		if ( 'post' === $type ) {
			$urls = array_merge( $urls, self::date_pages( $post, $plain ) );
		}

		// A changed date or author leaves the old archives listing it.
		if ( $before_public ) {
			if ( 'post' === $type && (string) ( $before->post_date ?? '' ) !== (string) ( $post->post_date ?? '' ) ) {
				$urls = array_merge( $urls, self::date_pages( $before ) );
			}
			if ( (int) ( $before->post_author ?? 0 ) > 0 && (int) $before->post_author !== (int) ( $post->post_author ?? 0 ) ) {
				$old_author = get_author_posts_url( (int) $before->post_author );
				if ( is_string( $old_author ) && '' !== $old_author ) {
					$urls   = array_merge( $urls, self::paged( $old_author, (int) count_user_posts( (int) $before->post_author, $type, true ) ) );
					$urls[] = get_author_feed_link( (int) $before->post_author );
				}
			}
		}

		// Feeds.
		$urls[] = get_feed_link();
		$urls[] = get_feed_link( 'comments_' . get_default_feed() );
		if ( $now_public ) {
			$urls[] = get_post_comments_feed_link( $post->ID );
		}

		// Neighbours whose previous/next links now point somewhere else: the
		// ones it has now, and the ones it had before a new date or category
		// moved it away from them.
		$urls = array_merge( $urls, self::adjacent_post_urls( $post ) );
		if ( $before_public ) {
			$urls = array_merge( $urls, self::$old_neighbours[ (int) $post->ID ] ?? array() );
		}

		// Parents, for hierarchical types that list their children.
		foreach ( get_post_ancestors( $post ) as $ancestor_id ) {
			$link = get_permalink( (int) $ancestor_id );
			if ( is_string( $link ) && '' !== $link ) {
				$urls[] = $link;
			}
		}

		// Pages whose own content lists posts with a block.
		$urls = array_merge( $urls, self::pages_with_list_blocks() );

		/**
		 * Filter the URLs cleared when one post is purged.
		 *
		 * Used by both the automatic purge on save and the "Purge this post"
		 * admin action, so they always clear the same pages.
		 *
		 * @param string[] $urls Absolute URLs.
		 * @param \WP_Post $post The post being purged.
		 */
		$urls = (array) apply_filters( 'xspeed_post_purge_urls', $urls, $post );

		$urls = array_filter( $urls, static fn( $url ) => is_string( $url ) && '' !== $url );

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Whether this change alters a post list the theme draws outside the
	 * main loop, on pages the rules cannot name.
	 *
	 * Those lists can sit on every page, so when one changes the caller has
	 * to purge every page. Most edits change none of them:
	 *
	 * - A newest-first list of N posts (core's Recent Posts widget, a Latest
	 *   Posts block, a Query Loop sorted by date with no other filter, such
	 *   as Twenty Twenty-Five's "More posts" under every post) changes when
	 *   the post is, or was, one of the N it shows: its date now or before
	 *   is at least as new as the Nth newest. That covers a publish, a
	 *   withdraw, a date move and an edit to a listed post, and leaves an old
	 *   post's edit or withdrawal alone.
	 * - Core's Archives and Calendar change only on publish, withdraw or a
	 *   date change.
	 * - A list of pages (the Page List block, a Navigation block with no menu
	 *   of its own, which falls back to one, the Pages widget, or a classic
	 *   theme's menu location with no menu, which falls back to
	 *   wp_page_menu()) changes when a page is published or withdrawn, or
	 *   its title, slug, parent or order changes. An edit to a page's text
	 *   leaves it alone.
	 * - The Categories and Tag Cloud widgets and blocks change when a post
	 *   is published or withdrawn, or moves between terms: they show counts,
	 *   hide empty terms and size tags by count.
	 * - A classic menu draws each item with its post's current title and
	 *   link, so it changes when a post in it is published or withdrawn, or
	 *   its title, slug or parent changes.
	 * - A block Navigation menu stores each link's label and URL, and skips
	 *   a link whose post is not published. It changes when a post it links
	 *   is published or withdrawn.
	 * - A Query Loop that includes sticky posts (its default) puts them
	 *   first whatever their date, so it also changes when the post is
	 *   sticky, or was stuck or unstuck in this request.
	 * - Any list this cannot read (another order, a category or author
	 *   filter) counts as changed.
	 *
	 * Found in active sidebars, in a block theme's templates with the
	 * template parts, patterns, synced patterns and navigation menus they
	 * use, and in the newest-N lists Listing_Pages saw pages run. A list that
	 * puts sticky posts first also changes when a sticky post is edited, or a
	 * post is made sticky or unsticky.
	 *
	 * @param \WP_Post      $post   Post as it is now.
	 * @param \WP_Post|null $before Post before the change, null when new.
	 */
	public static function lists_changed_by( \WP_Post $post, ?\WP_Post $before ): bool {
		$specs = self::post_list_specs();
		if ( array() === $specs ) {
			return false;
		}
		$was_public     = $before instanceof \WP_Post && self::is_public( $before );
		$status_changed = self::is_public( $post ) !== $was_public;
		$date_changed   = $before instanceof \WP_Post && (string) ( $before->post_date ?? '' ) !== (string) ( $post->post_date ?? '' );

		foreach ( $specs as $spec ) {
			if ( 'any' === $spec['kind'] ) {
				return true;
			}
			if ( 'dated' === $spec['kind'] ) {
				if ( $status_changed || $date_changed ) {
					return true;
				}
				continue;
			}
			if ( 'pages' === $spec['kind'] ) {
				if ( 'page' === (string) $post->post_type && ( $status_changed || self::listing_fields_changed( $post, $before ) ) ) {
					return true;
				}
				continue;
			}
			if ( 'terms' === $spec['kind'] ) {
				if ( $status_changed || self::terms_changed( $post ) ) {
					return true;
				}
				continue;
			}
			if ( 'linked' === $spec['kind'] ) {
				if ( $status_changed && in_array( (int) $post->ID, $spec['ids'], true ) ) {
					return true;
				}
				continue;
			}
			if ( 'menu' === $spec['kind'] ) {
				if ( ( $status_changed || self::listing_fields_changed( $post, $before ) ) && self::in_classic_menu( $post ) ) {
					return true;
				}
				continue;
			}
			// 'recent': the newest N of a type, or of `any` type. A post that
			// changed type is matched on the type it had as well.
			$types = array( (string) $post->post_type );
			if ( $before instanceof \WP_Post ) {
				$types[] = (string) $before->post_type;
			}
			if ( 'any' !== $spec['type'] && ! in_array( $spec['type'], $types, true ) ) {
				continue;
			}
			// A list that puts sticky posts first shows a sticky post
			// whatever its date.
			if ( ! empty( $spec['sticky'] ) && self::is_or_was_sticky( (int) $post->ID ) ) {
				return true;
			}
			$dates = array();
			if ( self::is_public( $post ) ) {
				$dates[] = (string) ( $post->post_date ?? '' );
			}
			if ( $was_public ) {
				$dates[] = (string) ( $before->post_date ?? '' );
			}
			$window = 'any' === $spec['type'] ? (string) $post->post_type : (string) $spec['type'];
			if ( self::in_latest_window( $window, (int) $spec['n'], $dates ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the site draws a list of recent comments on pages the rules
	 * cannot name: the Recent Comments widget or the Latest Comments block.
	 */
	public static function site_lists_comments(): bool {
		if ( null === self::$site_lists_comments ) {
			$found = false;
			foreach ( self::active_widget_ids() as $widget_id ) {
				if ( 0 === strpos( $widget_id, 'recent-comments-' ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				foreach ( self::theme_and_widget_blocks() as $block ) {
					if ( 'core/latest-comments' === $block['blockName'] ) {
						$found = true;
						break;
					}
				}
			}
			self::$site_lists_comments = $found;
		}
		return self::$site_lists_comments;
	}

	/**
	 * Note the posts a change to `sticky_posts` stuck or unstuck.
	 *
	 * @param mixed $old_value Sticky post IDs before.
	 * @param mixed $value     Sticky post IDs after.
	 * @return int[] The IDs that changed.
	 */
	public static function remember_sticky_change( $old_value, $value ): array {
		$old     = array_map( 'intval', is_array( $old_value ) ? $old_value : array() );
		$new     = array_map( 'intval', is_array( $value ) ? $value : array() );
		$changed = array_values( array_unique( array_merge( array_diff( $old, $new ), array_diff( $new, $old ) ) ) );
		foreach ( $changed as $id ) {
			self::$sticky_changed[ $id ] = true;
		}
		return $changed;
	}

	/**
	 * Whether the theme draws a list of this post type that puts sticky
	 * posts first, on pages the rules cannot name. A list of any kind
	 * counts too, and so does a recorded newest-N list of `any` type
	 * (Listing_Pages::recent_specs()).
	 *
	 * @param string $type Post type.
	 */
	public static function lists_show_sticky( string $type ): bool {
		foreach ( self::post_list_specs() as $spec ) {
			if ( 'any' === $spec['kind'] ) {
				return true;
			}
			if ( 'recent' === $spec['kind'] && ! empty( $spec['sticky'] ) && ( 'any' === $spec['type'] || $spec['type'] === $type ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Page 1 of the list WordPress's main query draws for `post`: the home
	 * page, or the posts page when the front page is static. '' when the
	 * front page is static and no posts page is set.
	 */
	public static function posts_page_url(): string {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return (string) home_url( '/' );
		}
		$page_id = (int) get_option( 'page_for_posts' );
		if ( $page_id < 1 ) {
			return '';
		}
		$url = get_permalink( $page_id );
		return is_string( $url ) ? $url : '';
	}

	/** Whether the post is sticky, or was stuck or unstuck in this request. */
	private static function is_or_was_sticky( int $post_id ): bool {
		if ( isset( self::$sticky_changed[ $post_id ] ) ) {
			return true;
		}
		$sticky = get_option( 'sticky_posts', array() );
		return is_array( $sticky ) && in_array( $post_id, array_map( 'intval', $sticky ), true );
	}

	/**
	 * The pages one comment change can make render differently: the post,
	 * its comment pages and the comment feeds.
	 *
	 * @param \WP_Post $post Post the comment is on.
	 * @return string[]
	 */
	public static function for_comment( \WP_Post $post ): array {
		if ( ! self::is_public( $post ) ) {
			return array();
		}
		$link = get_permalink( $post );
		if ( ! is_string( $link ) || '' === $link ) {
			return array();
		}
		$urls   = array_merge( array( $link ), self::comment_pages( $post, $link ) );
		$urls[] = get_post_comments_feed_link( $post->ID );
		$urls[] = get_feed_link( 'comments_' . get_default_feed() );
		$urls   = array_filter( $urls, static fn( $url ) => is_string( $url ) && '' !== $url );
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Whether a save changed what a list of pages or a menu shows for the
	 * post: its title, its slug, its parent or its order.
	 *
	 * @param \WP_Post      $post   Post as it is now.
	 * @param \WP_Post|null $before Post before the change, null when new.
	 */
	private static function listing_fields_changed( \WP_Post $post, ?\WP_Post $before ): bool {
		if ( ! $before instanceof \WP_Post ) {
			return true;
		}
		foreach ( array( 'post_title', 'post_name', 'post_parent', 'menu_order' ) as $field ) {
			if ( (string) ( $before->$field ?? '' ) !== (string) ( $post->$field ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether this save moved the post between public terms: the terms it
	 * had before (remember_old_terms()) differ from the ones it has now.
	 * A save that set no terms changed none.
	 */
	private static function terms_changed( \WP_Post $post ): bool {
		foreach ( self::$old_terms_by_taxonomy[ (int) $post->ID ] ?? array() as $taxonomy => $old ) {
			$now     = array();
			$current = get_the_terms( $post, (string) $taxonomy );
			if ( is_array( $current ) ) {
				foreach ( $current as $term ) {
					$now[] = (int) $term->term_taxonomy_id;
				}
			}
			$old = array_values( $old );
			sort( $old );
			sort( $now );
			if ( $old !== $now ) {
				return true;
			}
		}
		return false;
	}

	/** Whether a classic menu has an item that draws this post. */
	private static function in_classic_menu( \WP_Post $post ): bool {
		if ( ! function_exists( 'wp_get_associated_nav_menu_items' ) ) {
			return false;
		}
		$items = wp_get_associated_nav_menu_items( (int) $post->ID, 'post_type', (string) $post->post_type );
		return is_array( $items ) && array() !== $items;
	}

	/**
	 * Keep the post's neighbours as they are before a save, which can move
	 * it to another date or category and leave them linking to it.
	 *
	 * @param \WP_Post $post Post as it is before the save.
	 */
	public static function remember_old_neighbours( \WP_Post $post ): void {
		self::$old_neighbours[ (int) $post->ID ] = self::adjacent_post_urls( $post );
	}

	/** Test seam: forget per-request state. */
	public static function reset(): void {
		self::$old_terms             = array();
		self::$old_terms_by_taxonomy = array();
		self::$post_list_specs       = null;
		self::$theme_blocks          = null;
		self::$site_lists_comments   = null;
		self::$sticky_changed        = array();
		self::$old_neighbours        = array();
	}

	/**
	 * Forget a post's captured terms once its purge has run.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function forget( int $post_id ): void {
		unset( self::$old_terms[ $post_id ], self::$old_terms_by_taxonomy[ $post_id ], self::$sticky_changed[ $post_id ], self::$old_neighbours[ $post_id ] );
	}

	/**
	 * Whether an anonymous visitor can see this post: published, of a type
	 * with a front end.
	 */
	public static function is_public( \WP_Post $post ): bool {
		return 'publish' === $post->post_status && is_post_type_viewable( $post->post_type );
	}

	// ---------------------------------------------------------------------

	/**
	 * A list page and its later pages, through the one that will exist after
	 * this change plus one.
	 *
	 * The +1 covers a list that just got shorter: the page that dropped off
	 * the end still holds a cached copy.
	 *
	 * With `$at`, for a plain edit (is_plain_edit()), a list longer than
	 * PAGED_IN_FULL pages names only its first page and the page that holds
	 * the post. `$at` returns how many posts in the list are newer than the
	 * post and how many are as new or newer, which bound its place when
	 * other posts share its date. The first page stays in, for a sticky post
	 * shown there as well.
	 *
	 * @param string        $base  First page.
	 * @param int           $count Items in the list now.
	 * @param callable|null $at    Returns array{0:int,1:int} or null.
	 * @return string[]
	 */
	private static function paged( string $base, int $count, ?callable $at = null ): array {
		$urls     = array( $base );
		$per_page = max( 1, (int) get_option( 'posts_per_page', 10 ) );
		$pages    = (int) ceil( max( 0, $count ) / $per_page ) + 1;
		$first    = 2;
		if ( null !== $at && $pages > self::PAGED_IN_FULL ) {
			$place = $at();
			if ( is_array( $place ) ) {
				$first = intdiv( max( 0, (int) $place[0] ), $per_page ) + 1;
				$pages = max( $first, intdiv( max( 0, (int) $place[1] - 1 ), $per_page ) + 1 );
				$first = max( 2, $first );
			}
		}
		for ( $i = $first; $i <= $pages; $i++ ) {
			$urls[] = self::page_url( $base, $i );
		}
		return $urls;
	}

	/** Page `$i` of the list at `$base`. */
	private static function page_url( string $base, int $i ): string {
		global $wp_rewrite;
		$pretty = is_object( $wp_rewrite ) && method_exists( $wp_rewrite, 'using_permalinks' ) && $wp_rewrite->using_permalinks();
		$base_n = is_object( $wp_rewrite ) && ! empty( $wp_rewrite->pagination_base ) ? $wp_rewrite->pagination_base : 'page';
		return $pretty
			? trailingslashit( $base ) . user_trailingslashit( $base_n . '/' . $i, 'paged' )
			: add_query_arg( 'paged', $i, $base );
	}

	/**
	 * Whether this change is a plain edit (is_plain_edit()) of a post that
	 * was public before and is public now. Asked before forget(), since it
	 * compares the terms the save replaced.
	 *
	 * @param \WP_Post      $post   Post as it is now.
	 * @param \WP_Post|null $before Post before the change, null when new.
	 */
	public static function is_plain_change( \WP_Post $post, ?\WP_Post $before ): bool {
		return $before instanceof \WP_Post
			&& self::is_public( $post )
			&& self::is_public( $before )
			&& self::is_plain_edit( $post, $before );
	}

	/**
	 * Whether a save cannot move the post within its lists: it was public
	 * before and after, and kept its type, date, author, parent, terms,
	 * title and menu order. A text, excerpt or slug edit is plain. A publish,
	 * a withdrawal, a new date or a move between terms shifts every later
	 * page and is not. Title and menu order are kept too, for an archive a
	 * theme sorts by either instead of by date. An archive sorted by
	 * something else again (a custom field) is not detected.
	 *
	 * @param \WP_Post $post   Post as it is now.
	 * @param \WP_Post $before Post before the change.
	 */
	private static function is_plain_edit( \WP_Post $post, \WP_Post $before ): bool {
		foreach ( array( 'post_type', 'post_date', 'post_author', 'post_parent', 'post_title', 'menu_order' ) as $field ) {
			if ( (string) ( $before->$field ?? '' ) !== (string) ( $post->$field ?? '' ) ) {
				return false;
			}
		}
		return ! self::terms_changed( $post );
	}

	/**
	 * How many published posts of these types, in the list `$where` narrows
	 * to, are newer than `$date`, and how many are as new or newer.
	 *
	 * @param string[] $types Post types.
	 * @param string   $where Prepared `AND ...` on `p`, or ''.
	 * @param string   $date  The post's `post_date`.
	 * @return array{0:int,1:int}|null Null when it cannot be read.
	 */
	private static function position( array $types, string $where, string $date ): ?array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || '' === $date || array() === $types ) {
			return null;
		}
		// $where is prepared by the caller.
		$sql = $wpdb->prepare(
			"SELECT SUM(p.post_date > %s) AS newer, SUM(p.post_date >= %s) AS upto FROM {$wpdb->posts} p WHERE p.post_status = 'publish' AND p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')',
			array_merge( array( $date, $date ), $types )
		) . $where;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- prepared above; a count per long list on a plain edit.
		$row = $wpdb->get_row( $sql );
		if ( ! is_object( $row ) || ! isset( $row->newer, $row->upto ) ) {
			return null;
		}
		return array( (int) $row->newer, (int) $row->upto );
	}

	/**
	 * `$wpdb->prepare()` for a WHERE fragment, or '' without a database.
	 *
	 * @param string $sql  Fragment with placeholders.
	 * @param mixed  ...$args Values.
	 */
	private static function prepare( string $sql, ...$args ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- this is the prepare call.
		return is_object( $wpdb ) ? (string) $wpdb->prepare( $sql, ...$args ) : '';
	}

	/**
	 * Pages of a post split with `<!--nextpage-->`.
	 *
	 * @return string[]
	 */
	private static function split_pages( \WP_Post $post, string $link ): array {
		$parts = substr_count( (string) ( $post->post_content ?? '' ), '<!--nextpage-->' ) + 1;
		$urls  = array();
		global $wp_rewrite;
		$pretty = is_object( $wp_rewrite ) && method_exists( $wp_rewrite, 'using_permalinks' ) && $wp_rewrite->using_permalinks();
		for ( $i = 2; $i <= $parts; $i++ ) {
			$urls[] = $pretty
				? trailingslashit( $link ) . user_trailingslashit( (string) $i, 'single_paged' )
				: add_query_arg( 'page', $i, $link );
		}
		return $urls;
	}

	/**
	 * Comment pages, when comments are split into pages.
	 *
	 * @return string[]
	 */
	private static function comment_pages( \WP_Post $post, string $link ): array {
		if ( ! get_option( 'page_comments' ) ) {
			return array();
		}
		$per_page = max( 1, (int) get_option( 'comments_per_page', 50 ) );
		$pages    = (int) ceil( (int) ( $post->comment_count ?? 0 ) / $per_page ) + 1;
		$urls     = array();
		global $wp_rewrite;
		$pretty = is_object( $wp_rewrite ) && method_exists( $wp_rewrite, 'using_permalinks' ) && $wp_rewrite->using_permalinks();
		$base_n = is_object( $wp_rewrite ) && ! empty( $wp_rewrite->comments_pagination_base ) ? $wp_rewrite->comments_pagination_base : 'comment-page';
		for ( $i = 1; $i <= $pages; $i++ ) {
			$urls[] = $pretty
				? trailingslashit( $link ) . user_trailingslashit( $base_n . '-' . $i, 'commentpaged' )
				: add_query_arg( 'cpage', $i, $link );
		}
		return $urls;
	}

	/**
	 * Every public term the post is in, and was in before this save, with
	 * parent terms, all of their pages and their feeds.
	 *
	 * @return string[]
	 */
	private static function term_pages( \WP_Post $post, bool $plain = false ): array {
		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->public ) || empty( $taxonomy->rewrite ) && ! $taxonomy->query_var ) {
				continue;
			}
			$current = get_the_terms( $post, $taxonomy->name );
			if ( is_array( $current ) ) {
				foreach ( $current as $term ) {
					$terms[ (int) $term->term_taxonomy_id ] = $term;
				}
			}
		}
		foreach ( self::$old_terms[ (int) $post->ID ] ?? array() as $tt_id ) {
			if ( isset( $terms[ $tt_id ] ) ) {
				continue;
			}
			$term = get_term_by( 'term_taxonomy_id', $tt_id );
			if ( $term instanceof \WP_Term ) {
				$taxonomy = get_taxonomy( $term->taxonomy );
				if ( $taxonomy && ! empty( $taxonomy->public ) ) {
					$terms[ $tt_id ] = $term;
				}
			}
		}

		// Parent terms list their children's posts too.
		foreach ( $terms as $term ) {
			if ( ! is_taxonomy_hierarchical( $term->taxonomy ) ) {
				continue;
			}
			foreach ( get_ancestors( (int) $term->term_id, $term->taxonomy, 'taxonomy' ) as $ancestor_id ) {
				$ancestor = get_term( (int) $ancestor_id, $term->taxonomy );
				if ( $ancestor instanceof \WP_Term ) {
					$terms[ (int) $ancestor->term_taxonomy_id ] = $ancestor;
				}
			}
		}

		$urls = array();
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( ! is_string( $link ) || '' === $link ) {
				continue;
			}
			$at   = $plain ? static fn() => self::position_in_term( $post, $term ) : null;
			$urls = array_merge( $urls, self::paged( $link, self::term_post_count( $post, $term ), $at ) );
			$feed = get_term_feed_link( (int) $term->term_id, $term->taxonomy );
			if ( is_string( $feed ) && '' !== $feed ) {
				$urls[] = $feed;
			}
		}
		return $urls;
	}

	/**
	 * The post's place in a term's archive, which also lists the posts of
	 * the term's children in a hierarchical taxonomy.
	 *
	 * @return array{0:int,1:int}|null
	 */
	private static function position_in_term( \WP_Post $post, \WP_Term $term ): ?array {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return null;
		}
		$in = implode( ',', array_map( 'intval', array_keys( self::term_tree( $term ) ) ) );
		return self::position( self::term_post_types( $post, $term ), " AND p.ID IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($in))", (string) ( $post->post_date ?? '' ) );
	}

	/**
	 * How many published posts a term's archive lists. In a hierarchical
	 * taxonomy that includes the posts of every child term, which the
	 * term's own `count` leaves out: a parent category whose posts all sit
	 * in a child counts 0, so only its first page was named and its later
	 * pages kept the old copy.
	 *
	 * Without a database to ask, the counts of the term and its children
	 * are added up. A post in both counts twice, which names a page past
	 * the end, never one too few.
	 */
	private static function term_post_count( \WP_Post $post, \WP_Term $term ): int {
		$tree = self::term_tree( $term );
		if ( count( $tree ) < 2 ) {
			return (int) $term->count;
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			$sum = 0;
			foreach ( $tree as $member ) {
				$sum += (int) $member->count;
			}
			return $sum;
		}
		$types = self::term_post_types( $post, $term );
		$in    = implode( ',', array_map( 'intval', array_keys( $tree ) ) );
		$list  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $list is placeholders and $in is integers.
		$sql = $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p WHERE p.post_status = 'publish' AND p.post_type IN ($list) AND p.ID IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($in))", $types );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- prepared above; one count per parent term per save.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * The term and, in a hierarchical taxonomy, every term below it, keyed
	 * by term_taxonomy_id: the terms whose posts its archive lists.
	 *
	 * @return array<int,\WP_Term>
	 */
	private static function term_tree( \WP_Term $term ): array {
		$tree = array( (int) $term->term_taxonomy_id => $term );
		if ( is_taxonomy_hierarchical( $term->taxonomy ) && function_exists( 'get_term_children' ) ) {
			$children = get_term_children( (int) $term->term_id, $term->taxonomy );
			foreach ( is_array( $children ) ? $children : array() as $child_id ) {
				$child = get_term( (int) $child_id, $term->taxonomy );
				if ( $child instanceof \WP_Term ) {
					$tree[ (int) $child->term_taxonomy_id ] = $child;
				}
			}
		}
		return $tree;
	}

	/**
	 * Post types a term's archive lists.
	 *
	 * @return string[]
	 */
	private static function term_post_types( \WP_Post $post, \WP_Term $term ): array {
		$taxonomy = get_taxonomy( $term->taxonomy );
		return $taxonomy && ! empty( $taxonomy->object_type ) ? array_map( 'strval', (array) $taxonomy->object_type ) : array( (string) $post->post_type );
	}

	/**
	 * The year, month and day archives the post appears on, each with all of
	 * its pages, or for a plain edit the page that holds the post.
	 *
	 * @return string[]
	 */
	private static function date_pages( \WP_Post $post, bool $plain = false ): array {
		$time = strtotime( (string) ( $post->post_date ?? '' ) );
		if ( false === $time ) {
			return array();
		}
		$y = (int) gmdate( 'Y', $time );
		$m = (int) gmdate( 'm', $time );
		$d = (int) gmdate( 'd', $time );

		$date = (string) $post->post_date;
		$in   = static function ( string $where ) use ( $plain, $date ): ?callable {
			return $plain ? static fn() => self::position( array( 'post' ), $where, $date ) : null;
		};

		$urls = array();
		$urls = array_merge( $urls, self::paged( get_year_link( $y ), self::count_in( $y ), $in( self::prepare( ' AND YEAR(p.post_date) = %d', $y ) ) ) );
		$urls = array_merge( $urls, self::paged( get_month_link( $y, $m ), self::count_in( $y, $m ), $in( self::prepare( ' AND YEAR(p.post_date) = %d AND MONTH(p.post_date) = %d', $y, $m ) ) ) );
		$urls = array_merge( $urls, self::paged( get_day_link( $y, $m, $d ), self::count_in( $y, $m, $d ), $in( self::prepare( ' AND YEAR(p.post_date) = %d AND MONTH(p.post_date) = %d AND DAYOFMONTH(p.post_date) = %d', $y, $m, $d ) ) ) );
		return $urls;
	}

	/**
	 * Published posts in a year, month or day.
	 */
	private static function count_in( int $y, int $m = 0, int $d = 0 ): int {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return 0;
		}
		$where = $wpdb->prepare( 'YEAR(post_date) = %d', $y );
		if ( $m > 0 ) {
			$where .= $wpdb->prepare( ' AND MONTH(post_date) = %d', $m );
		}
		if ( $d > 0 ) {
			$where .= $wpdb->prepare( ' AND DAYOFMONTH(post_date) = %d', $d );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- $where is built from prepare() above; a count per save.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND {$where}" );
	}

	private static function published_count( string $type ): int {
		$counts = wp_count_posts( $type );
		return is_object( $counts ) && isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Permalinks of the four posts adjacent to this one: previous and next,
	 * each in the whole timeline and within a shared term.
	 *
	 * @return string[]
	 */
	public static function adjacent_post_urls( \WP_Post $post ): array {
		$urls = array();

		// get_adjacent_post() reads the global $post. Swap it for the one
		// being purged and put it back, or on an edit screen we would collect
		// the neighbours of whatever WordPress happened to have loaded.
		$previous_global = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.

		try {
			foreach ( array( array( false, true ), array( true, true ), array( false, false ), array( true, false ) ) as $args ) {
				list( $same_term, $previous ) = $args;
				$adjacent                     = get_adjacent_post( $same_term, '', $previous );
				if ( $adjacent instanceof \WP_Post && 'publish' === $adjacent->post_status ) {
					$link = get_permalink( $adjacent );
					if ( is_string( $link ) && '' !== $link ) {
						$urls[] = $link;
					}
				}
			}
		} finally {
			if ( null === $previous_global ) {
				unset( $GLOBALS['post'] );
			} else {
				$GLOBALS['post'] = $previous_global; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
			}
		}

		return $urls;
	}

	/**
	 * Published pages and posts whose own content lists posts with a block.
	 *
	 * Those pages list the post wherever they are, so they go in the list by
	 * name. More than LIST_PAGE_LIMIT of them and the caller is better off
	 * purging the site: post_list_specs() treats that as a list of any kind.
	 *
	 * @return string[]
	 */
	private static function pages_with_list_blocks(): array {
		$ids = self::list_block_post_ids();
		if ( count( $ids ) > self::LIST_PAGE_LIMIT ) {
			return array();
		}
		$urls = array();
		foreach ( $ids as $id ) {
			$link = get_permalink( (int) $id );
			if ( is_string( $link ) && '' !== $link ) {
				$urls[] = $link;
			}
		}
		return $urls;
	}

	/**
	 * IDs of published posts whose content has a list block, directly or
	 * through a synced pattern that holds one.
	 *
	 * A LIKE scan over every post's content, so it is cached for an hour and
	 * dropped when a post with a list block or a synced pattern is saved
	 * (forget_list_pages_on_save()). Synced patterns are followed one level:
	 * a pattern placed inside another pattern is not.
	 *
	 * @return array<int,int>
	 */
	private static function list_block_post_ids(): array {
		$cached = get_transient( self::LIST_PAGES_TRANSIENT );
		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return array();
		}
		$like = array_map( static fn( $marker ) => '%' . $wpdb->esc_like( $marker ) . '%', self::LIST_MARKERS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one indexed-status query per save.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','wp_block','wp_template','wp_template_part','wp_navigation') AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s) LIMIT %d",
				$like[0],
				$like[1],
				$like[2],
				self::LIST_PAGE_LIMIT + 1
			)
		);
		$ids = array_map( 'intval', is_array( $rows ) ? $rows : array() );
		if ( count( $ids ) <= self::LIST_PAGE_LIMIT ) {
			$ids = array_values( array_unique( array_merge( $ids, self::posts_placing_list_patterns( $like ) ) ) );
		}
		set_transient( self::LIST_PAGES_TRANSIENT, $ids, HOUR_IN_SECONDS );
		return $ids;
	}

	/**
	 * IDs of published posts that place a synced pattern holding a list.
	 *
	 * More such patterns than LIST_PAGE_LIMIT come back as the pattern IDs
	 * themselves: the caller only needs to see the list is too long to name.
	 *
	 * @param string[] $like The three LIKE patterns for LIST_MARKERS.
	 * @return array<int,int>
	 */
	private static function posts_placing_list_patterns( array $like ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cached with the scan above.
		$patterns = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wp_block' AND post_status = 'publish' AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s) LIMIT %d",
				$like[0],
				$like[1],
				$like[2],
				self::LIST_PAGE_LIMIT + 1
			)
		);
		$patterns = array_map( 'intval', is_array( $patterns ) ? $patterns : array() );
		if ( count( $patterns ) > self::LIST_PAGE_LIMIT ) {
			return $patterns;
		}
		$ids = array();
		foreach ( $patterns as $pattern_id ) {
			// `{"ref":12}` or `{"ref":12,...}`, so pattern 12 does not match 123.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cached with the scan above.
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','wp_block','wp_template','wp_template_part','wp_navigation') AND (post_content LIKE %s OR post_content LIKE %s) LIMIT %d",
					'%' . $wpdb->esc_like( self::SYNCED_MARKER . $pattern_id . '}' ) . '%',
					'%' . $wpdb->esc_like( self::SYNCED_MARKER . $pattern_id . ',' ) . '%',
					self::LIST_PAGE_LIMIT + 1
				)
			);
			$ids = array_merge( $ids, array_map( 'intval', is_array( $rows ) ? $rows : array() ) );
			if ( count( $ids ) > self::LIST_PAGE_LIMIT ) {
				break;
			}
		}
		return $ids;
	}

	/**
	 * Post lists drawn outside the main loop, as specs lists_changed_by()
	 * can test a change against.
	 *
	 * @return array<int,array{kind:string,type?:string,n?:int,sticky?:bool,ids?:int[]}>
	 */
	private static function post_list_specs(): array {
		if ( null !== self::$post_list_specs ) {
			return self::$post_list_specs;
		}
		$specs = array();

		foreach ( self::active_widget_ids() as $widget_id ) {
			if ( 0 === strpos( $widget_id, 'recent-posts-' ) ) {
				$settings = get_option( 'widget_recent-posts', array() );
				$n        = (int) substr( $widget_id, strlen( 'recent-posts-' ) );
				$number   = is_array( $settings ) && isset( $settings[ $n ]['number'] ) ? (int) $settings[ $n ]['number'] : 5;
				$specs[]  = array( 'kind' => 'recent', 'type' => 'post', 'n' => max( 1, $number ) );
			} elseif ( 0 === strpos( $widget_id, 'archives-' ) || 0 === strpos( $widget_id, 'calendar-' ) ) {
				$specs[] = array( 'kind' => 'dated' );
			} elseif ( 0 === strpos( $widget_id, 'pages-' ) ) {
				$specs[] = array( 'kind' => 'pages' );
			} elseif ( 0 === strpos( $widget_id, 'categories-' ) || 0 === strpos( $widget_id, 'tag_cloud-' ) ) {
				$specs[] = array( 'kind' => 'terms' );
			}
		}

		// A classic theme draws its menu locations with wp_nav_menu(). One
		// with no menu falls back to wp_page_menu(), a list of every page;
		// one with a menu draws each item's current title and link.
		if ( ! ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() )
			&& function_exists( 'get_registered_nav_menus' ) && function_exists( 'get_nav_menu_locations' )
		) {
			$registered = get_registered_nav_menus();
			$assigned   = get_nav_menu_locations();
			$assigned   = is_array( $assigned ) ? $assigned : array();
			foreach ( is_array( $registered ) ? array_keys( $registered ) : array() as $location ) {
				$specs[] = empty( $assigned[ $location ] ) ? array( 'kind' => 'pages' ) : array( 'kind' => 'menu' );
			}
		}

		foreach ( self::theme_and_widget_blocks() as $block ) {
			$spec = self::spec_for_block( $block );
			if ( null !== $spec ) {
				$specs[] = $spec;
			}
		}

		// Too many pages with list blocks in their own content to name.
		if ( count( self::list_block_post_ids() ) > self::LIST_PAGE_LIMIT ) {
			$specs[] = array( 'kind' => 'any' );
		}

		// Newest-N lists seen at render, from page builders, block plugins
		// and theme PHP (Listing_Pages). They show the same posts on every
		// page, so they are judged here like a widget.
		if ( Listing_Pages::enabled() ) {
			$specs = array_merge( $specs, Listing_Pages::recent_specs() );
		}

		self::$post_list_specs = $specs;
		return $specs;
	}

	/**
	 * The spec for one block, or null when it draws no post list of its own.
	 *
	 * @param array $block A parsed block.
	 * @return array{kind:string,type?:string,n?:int,sticky?:bool,ids?:int[]}|null
	 */
	private static function spec_for_block( array $block ): ?array {
		$name  = (string) ( $block['blockName'] ?? '' );
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

		if ( 'core/archives' === $name || 'core/calendar' === $name ) {
			return array( 'kind' => 'dated' );
		}

		if ( 'core/page-list' === $name ) {
			return array( 'kind' => 'pages' );
		}

		// With no menu of its own, a Navigation block falls back to the
		// newest navigation menu or, without one, to a list of every page.
		if ( 'core/navigation' === $name && empty( $attrs['ref'] ) && empty( $block['innerBlocks'] ) ) {
			return array( 'kind' => 'pages' );
		}

		// A link to a post or page. It is drawn only while that post is
		// published; label and URL are stored in the block. Core treats a
		// link of type post or page as one even without `kind`.
		if ( 'core/navigation-link' === $name || 'core/navigation-submenu' === $name ) {
			$to_post = 'post-type' === ( $attrs['kind'] ?? '' ) || in_array( $attrs['type'] ?? '', array( 'post', 'page' ), true );
			return $to_post && isset( $attrs['id'] ) && is_numeric( $attrs['id'] )
				? array( 'kind' => 'linked', 'ids' => array( (int) $attrs['id'] ) )
				: null;
		}

		if ( 'core/categories' === $name || 'core/tag-cloud' === $name ) {
			return array( 'kind' => 'terms' );
		}

		if ( 'core/latest-posts' === $name ) {
			$plain = empty( $attrs['categories'] ) && empty( $attrs['selectedAuthor'] )
				&& 'date' === ( $attrs['orderBy'] ?? 'date' ) && 'desc' === strtolower( (string) ( $attrs['order'] ?? 'desc' ) );
			return $plain
				? array( 'kind' => 'recent', 'type' => 'post', 'n' => max( 1, (int) ( $attrs['postsToShow'] ?? 5 ) ) )
				: array( 'kind' => 'any' );
		}

		if ( 'core/query' === $name ) {
			$query = isset( $attrs['query'] ) && is_array( $attrs['query'] ) ? $attrs['query'] : array();
			// `inherit` is the page's own main loop, which the rules cover.
			if ( ! empty( $query['inherit'] ) ) {
				return null;
			}
			$sticky = (string) ( $query['sticky'] ?? '' );
			$plain  = 'date' === ( $query['orderBy'] ?? 'date' )
				&& 'desc' === strtolower( (string) ( $query['order'] ?? 'desc' ) )
				&& empty( $query['author'] ) && empty( $query['search'] ) && empty( $query['taxQuery'] )
				&& empty( $query['parents'] ) && empty( $query['exclude'] )
				&& in_array( $sticky, array( '', 'exclude', 'ignore' ), true );
			return $plain
				? array(
					'kind'   => 'recent',
					'type'   => (string) ( $query['postType'] ?? 'post' ),
					'n'      => max( 1, (int) ( $query['perPage'] ?? get_option( 'posts_per_page', 10 ) ) + (int) ( $query['offset'] ?? 0 ) ),
					// '' (the default) puts sticky posts first, whatever their date.
					'sticky' => '' === $sticky,
				)
				: array( 'kind' => 'any' );
		}

		return null;
	}

	/**
	 * Every block drawn by the active block widgets and, on a block theme,
	 * by its templates and the template parts and patterns they use,
	 * flattened into one list.
	 *
	 * @return array<int,array>
	 */
	private static function theme_and_widget_blocks(): array {
		if ( null !== self::$theme_blocks ) {
			return self::$theme_blocks;
		}
		$out = array();

		$block_widgets = get_option( 'widget_block', array() );
		foreach ( self::active_widget_ids() as $widget_id ) {
			if ( 0 === strpos( $widget_id, 'block-' ) && is_array( $block_widgets ) ) {
				$n       = (int) substr( $widget_id, 6 );
				$content = isset( $block_widgets[ $n ]['content'] ) ? (string) $block_widgets[ $n ]['content'] : '';
				if ( '' !== $content ) {
					self::flatten( parse_blocks( $content ), $out, 0 );
				}
			}
		}

		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && function_exists( 'get_block_templates' ) ) {
			foreach ( (array) get_block_templates( array(), 'wp_template' ) as $template ) {
				$content = is_object( $template ) && isset( $template->content ) ? (string) $template->content : '';
				if ( '' !== $content ) {
					self::flatten( parse_blocks( $content ), $out, 0 );
				}
			}
		}

		self::$theme_blocks = $out;
		return $out;
	}

	/**
	 * Append every block in a tree to $out, following template parts,
	 * patterns and synced patterns a few levels down.
	 *
	 * @param array            $blocks parse_blocks() output.
	 * @param array<int,array> $out    Collected blocks.
	 * @param int              $depth  Nesting depth through references.
	 */
	private static function flatten( array $blocks, array &$out, int $depth ): void {
		if ( $depth > 4 ) {
			return;
		}
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$block['blockName'] = (string) ( $block['blockName'] ?? '' );
			$out[]              = $block;
			$attrs              = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$content            = '';

			if ( 'core/template-part' === $block['blockName'] && ! empty( $attrs['slug'] ) && function_exists( 'get_block_template' ) ) {
				$theme = ! empty( $attrs['theme'] ) ? (string) $attrs['theme'] : ( function_exists( 'get_stylesheet' ) ? get_stylesheet() : '' );
				$part  = get_block_template( $theme . '//' . $attrs['slug'], 'wp_template_part' );
				$content = is_object( $part ) && isset( $part->content ) ? (string) $part->content : '';
			} elseif ( 'core/pattern' === $block['blockName'] && ! empty( $attrs['slug'] ) && class_exists( '\\WP_Block_Patterns_Registry' ) ) {
				$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( (string) $attrs['slug'] );
				$content = is_array( $pattern ) && ! empty( $pattern['content'] ) ? (string) $pattern['content'] : '';
			} elseif ( ( 'core/block' === $block['blockName'] || 'core/navigation' === $block['blockName'] ) && ! empty( $attrs['ref'] ) ) {
				// A synced pattern, or the wp_navigation menu a Navigation
				// block draws.
				$synced  = get_post( (int) $attrs['ref'] );
				$content = $synced instanceof \WP_Post ? (string) ( $synced->post_content ?? '' ) : '';
			}
			if ( '' !== $content ) {
				self::flatten( parse_blocks( $content ), $out, $depth + 1 );
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::flatten( $block['innerBlocks'], $out, $depth );
			}
		}
	}

	/**
	 * Widget IDs in every sidebar the current theme draws.
	 *
	 * A sidebar the theme does not register is never shown: a block theme
	 * registers none, yet a fresh install still stores core's default
	 * Recent Posts and Archives widgets under `sidebar-1`. Before sidebars
	 * are registered (too early to know), every stored sidebar counts.
	 *
	 * @return string[]
	 */
	private static function active_widget_ids(): array {
		global $wp_registered_sidebars;
		$ids      = array();
		$sidebars = get_option( 'sidebars_widgets', array() );
		if ( ! is_array( $sidebars ) ) {
			return $ids;
		}
		$known = function_exists( 'did_action' ) && did_action( 'widgets_init' ) && is_array( $wp_registered_sidebars )
			? $wp_registered_sidebars
			: null;
		foreach ( $sidebars as $sidebar => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar || ! is_array( $widgets ) ) {
				continue;
			}
			if ( null !== $known && ! isset( $known[ $sidebar ] ) ) {
				continue;
			}
			foreach ( $widgets as $widget_id ) {
				$ids[] = (string) $widget_id;
			}
		}
		return $ids;
	}

	/**
	 * Whether any of these post dates falls within the N newest published
	 * posts of a type: at least as new as the Nth newest, or the list is not
	 * full yet.
	 *
	 * Read after the save, so a post that just left the list is judged by
	 * its old date against the list as it is now, which then holds an older
	 * Nth post: a post that was listed is always at least as new as that.
	 *
	 * @param string   $type  Post type.
	 * @param int      $n     List length.
	 * @param string[] $dates `post_date` values of the post now and before.
	 */
	private static function in_latest_window( string $type, int $n, array $dates ): bool {
		$dates = array_filter( $dates, static fn( $d ) => '' !== $d );
		if ( array() === $dates ) {
			return false;
		}
		// The date of the Nth newest published post, read straight from the
		// table as the other counts here are: what the posts table holds,
		// with no other plugin's query filters applied. No row means the list
		// is not full yet, so any post is in it.
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one indexed read per list per save; nothing to cache across saves.
		$threshold = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_date FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY post_date DESC LIMIT %d, 1",
				$type,
				max( 0, $n - 1 )
			)
		);
		if ( ! is_string( $threshold ) || '' === $threshold ) {
			return true;
		}
		foreach ( $dates as $date ) {
			if ( $date >= $threshold ) {
				return true;
			}
		}
		return false;
	}
}
