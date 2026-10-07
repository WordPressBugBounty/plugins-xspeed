<?php
/**
 * Listing_Pages: which cached pages ran a post list of their own, and which
 * posts each one showed.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * Records, as a page renders, the post lists it ran outside the main loop,
 * so a narrow purge can clear the pages a save may have changed.
 *
 * Affected_Pages names the pages the rules know: archives, feeds,
 * neighbours. It cannot see a list a page builder, a block plugin, a
 * related-posts plugin or a theme's own PHP draws with a WP_Query of its
 * own: an Elementor or Essential Addons post grid, an Essential Blocks,
 * Spectra or Kadence post block. This class watches `pre_get_posts`, which
 * fires for every query, even under `suppress_filters` (where `the_posts`
 * does not), and sorts what it saw at the end of the request.
 *
 * - A plain newest-N list (newest first by date, of whole post types, with
 *   no filter but a few excluded posts) shows the same posts on every page.
 *   It is kept once per site as a spec, `{types, n, sticky}`, and
 *   Affected_Pages::lists_changed_by() judges it like a Recent Posts widget:
 *   a change to one of the newest N clears the whole site, any other change
 *   clears nothing for it. A page whose only lists are specs gets no record.
 * - Any other list gives the page a record, one file per URL: the types it
 *   listed, the IDs it showed, and whether a plain edit of a post it does
 *   not show can change it ("precise").
 * - A precise record's IDs go into an inverse index, one file per post ID
 *   listing the pages that showed it, so a plain edit reads one file.
 * - Every record counts toward its types, and the URLs of each type are
 *   kept while there are no more than Affected_Pages::LIMIT of them, with
 *   the URLs of the records that are not precise kept apart. A change that
 *   is not plain, or a list that is not precise, needs every page of the
 *   type: over the limit that is a site-wide purge, read from a count
 *   rather than a scan.
 *
 * A save reads a handful of small files and never every record. Records are
 * written only when they change, never on a cache hit, and never to the
 * database; a page view costs a file read at most. All writes to the index
 * happen under one lock per blog (flock on `.lock`), and every file is
 * written atomically. Purges keep the index: a purge that does not reach a
 * cache in front would otherwise leave pages there that nothing records.
 */
final class Listing_Pages {

	/** Most non-main queries kept per request. */
	public const MAX_QUERIES = 200;

	/** Most post IDs kept per page. Past it, the record is not precise. */
	public const MAX_IDS = 100;

	/** Most page records per blog, for disk use. */
	public const CAP = 50000;

	/** Most newest-N specs per blog. Past it, a new one is recorded per page. */
	public const SPEC_CAP = 50;

	/** Largest N kept as a spec. A longer list is recorded per page. */
	public const MAX_SPEC_N = 100;

	/** Most excluded posts a newest-N list may have. */
	private const MAX_EXCLUDED = 5;

	/**
	 * Version of the page record format, stored as `v`. Raised whenever the
	 * rules for what a render records change, so a record written by an
	 * earlier build reads as different and the page's next render replaces
	 * it. Without it, a page whose record an older build had marked
	 * imprecise kept that record for as long as nothing else changed.
	 */
	public const RECORD_VERSION = 2;

	/** Marker file written in the blog's directory when a page was refused. */
	public const FULL_MARKER = 'full';

	/** Subdirectory of XSPEED_CACHE_DIR. */
	private const SUBDIR = 'listings';

	/** Longest path recorded. */
	private const MAX_PATH = 2000;

	/**
	 * Post types that are never a post list a visitor sees: menus,
	 * templates, styles, patterns, attachments, revisions and the like.
	 */
	private const IGNORED_TYPES = array(
		'nav_menu_item',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_block',
		'attachment',
		'revision',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_font_family',
		'wp_font_face',
		'elementor_library',
	);

	/**
	 * Query vars that pick posts by something other than type and date. Any
	 * of them set, and the list is not a plain newest-N list.
	 */
	private const FILTER_VARS = array(
		'p', 'page_id', 'name', 'pagename', 'attachment', 'attachment_id', 'subpost', 'subpost_id',
		'post__in', 'post_name__in', 'post_parent__in', 'post_parent__not_in',
		'author', 'author_name', 'author__in', 'author__not_in',
		'cat', 'category_name', 'category__in', 'category__and', 'category__not_in',
		'tag', 'tag_id', 'tag__in', 'tag__and', 'tag__not_in', 'tag_slug__in', 'tag_slug__and',
		'tax_query', 'taxonomy', 'term',
		's', 'meta_key', 'meta_value', 'meta_value_num', 'meta_query',
		'date_query', 'year', 'monthnum', 'day', 'w', 'm',
		'title', 'post_mime_type', 'post_password', 'comment_status', 'ping_status', 'comment_count',
		'nopaging',
	);

	/** Query vars where 0 is a filter, not "unset". */
	private const ZERO_IS_SET_VARS = array( 'hour', 'minute', 'second', 'menu_order' );

	/**
	 * Non-main queries seen this request, keyed by object id.
	 *
	 * @var array<int,\WP_Query>
	 */
	private static $queries = array();

	/** More queries ran than MAX_QUERIES. @var bool */
	private static $overflow = false;

	/** Per-request answer of enabled(), once a query asked. @var bool|null */
	private static $active = null;

	/** Per-request answer of ignored_types(). @var string[]|null */
	private static $ignored = null;

	/** Per-request read of the specs, for a save. @var array<int,array>|null */
	private static $specs = null;

	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) || ! self::request_may_record() ) {
			return;
		}
		add_action( 'pre_get_posts', array( __CLASS__, 'note_query' ), PHP_INT_MAX );
		// After WordPress has flushed the output buffers (priority 1), when
		// the page and its status are final.
		add_action( 'shutdown', array( __CLASS__, 'record' ), 1000, 0 );
	}

	/**
	 * Whether pages may be recorded and read at all: narrow purge is on, and
	 * `xspeed_listing_pages_enabled` agrees.
	 */
	public static function enabled(): bool {
		$opts = Settings_Manager::get( 'cache' );
		if ( empty( $opts['purge_affected_only'] ) ) {
			return false;
		}
		/**
		 * Filter whether pages that run a post list outside the main loop
		 * are recorded and cleared by a narrow purge.
		 *
		 * Only asked while "Clear only the pages a change affects" is on.
		 * Return false to clear only the pages the rules name, as before.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'xspeed_listing_pages_enabled', true );
	}

	// --- Render side ------------------------------------------------------

	/**
	 * Keep a non-main query until the end of the request, when its results
	 * are known.
	 *
	 * @param \WP_Query $query Query about to run.
	 */
	public static function note_query( $query ): void {
		if ( ! $query instanceof \WP_Query || $query->is_main_query() ) {
			return;
		}
		if ( null === self::$active ) {
			self::$active = self::enabled();
		}
		if ( ! self::$active ) {
			return;
		}
		// A query for menus or templates only is not a list; skipping it
		// here keeps them out of the count.
		$types = $query->get( 'post_type' );
		if ( '' !== $types && array() !== $types && array() === array_diff( (array) $types, self::ignored_types() ) ) {
			return;
		}
		$id = spl_object_id( $query );
		if ( ! isset( self::$queries[ $id ] ) && count( self::$queries ) >= self::MAX_QUERIES ) {
			self::$overflow = true;
			return;
		}
		self::$queries[ $id ] = $query;
	}

	/**
	 * Write, update or delete this page's record and the specs it ran, once
	 * the response is final.
	 */
	public static function record(): void {
		if ( null === self::$active ) {
			// No query asked yet: there is no list to record, only a stale
			// record to drop, and that needs the setting too.
			self::$active = self::enabled();
		}
		if ( ! self::$active || ! self::request_may_record() ) {
			return;
		}
		$status = http_response_code();
		if ( ( 404 === $status || 410 === $status ) && Cache::query_has_only_ignored_params() ) {
			// The page is gone, and the save that removed it cleared its
			// cached copies. Without this its record would stay forever.
			// Not with a query string: `/page/?p=999` is a 404 while
			// `/page/` is not.
			$url = self::current_url();
			if ( '' !== $url ) {
				foreach ( array( '', '|m', '|d' ) as $device ) {
					self::forget_page( md5( $url . $device ) );
				}
			}
			return;
		}
		if ( ! self::response_may_record() ) {
			return;
		}
		$url = self::current_url();
		if ( '' === $url ) {
			return;
		}
		// A page that never reached its footer stopped early: what ran is a
		// part of the page, so it may only add to the record.
		$complete = function_exists( 'did_action' ) && did_action( 'wp_footer' ) > 0;
		self::store( $url, self::summarise( self::$queries, self::$overflow ), $complete );
	}

	/**
	 * What a set of queries listed: the newest-N specs among them, each with
	 * the record it falls back to when the specs are full, and the record
	 * for every other list. Null when none of them is a post list.
	 *
	 * @param array<int,\WP_Query> $queries  Non-main queries that ran.
	 * @param bool                 $overflow More ran than were kept.
	 * @return array{specs:array<int,array{spec:array{types:string[],n:int,sticky:bool},list:array{types:string[],ids:int[],precise:bool}}>,record:array{types:string[],ids:int[],precise:bool}|null}|null
	 */
	public static function summarise( array $queries, bool $overflow = false ): ?array {
		$specs   = array();
		$lists   = array();
		$ignored = self::ignored_types();

		foreach ( $queries as $query ) {
			if ( ! $query instanceof \WP_Query ) {
				continue;
			}
			$types = self::listing_types( $query, $ignored );
			if ( array() === $types ) {
				continue;
			}
			$shown = self::returned_ids( $query );
			$list  = array(
				'types'   => $types,
				'ids'     => null === $shown ? array() : $shown,
				'precise' => null !== $shown && self::query_is_precise( $query ),
			);
			$spec = self::recent_spec( $query, $types );
			if ( null !== $spec ) {
				$specs[] = array(
					'spec' => $spec,
					'list' => self::merge( array( $list ) ),
				);
			} else {
				$lists[] = $list;
			}
		}

		if ( $overflow ) {
			// The queries past the limit could list anything.
			$lists[] = array(
				'types'   => array( 'any' ),
				'ids'     => array(),
				'precise' => false,
			);
		}
		if ( array() === $specs && array() === $lists ) {
			return null;
		}
		return array(
			'specs'  => $specs,
			'record' => array() === $lists ? null : self::merge( $lists ),
		);
	}

	/**
	 * The spec of a plain newest-N list, or null when the query is anything
	 * else.
	 *
	 * Plain: newest first by date (the default order counts), of whole post
	 * types, published posts only, with no taxonomy, author, search, meta,
	 * date, parent, slug or hand-picked filter. A list that excludes a few
	 * posts (the current one, usually) widens N by that many, as does an
	 * offset or a later page. Such a list holds the newest N of its types on
	 * every page that runs it, so it changes only when the newest N do.
	 *
	 * @param \WP_Query $query Query, after it ran.
	 * @param string[]  $types Its listing types.
	 * @return array{types:string[],n:int,sticky:bool}|null
	 */
	public static function recent_spec( \WP_Query $query, array $types ): ?array {
		if ( array() === $types ) {
			return null;
		}
		$vars = is_array( $query->query_vars ) ? $query->query_vars : array();

		foreach ( self::FILTER_VARS as $key ) {
			if ( ! empty( $vars[ $key ] ) ) {
				return null;
			}
		}
		foreach ( self::ZERO_IS_SET_VARS as $key ) {
			if ( isset( $vars[ $key ] ) && '' !== $vars[ $key ] && array() !== $vars[ $key ] ) {
				return null;
			}
		}
		if ( isset( $vars['post_parent'] ) && is_numeric( $vars['post_parent'] ) ) {
			return null;
		}
		if ( isset( $vars['has_password'] ) && null !== $vars['has_password'] ) {
			return null;
		}
		foreach ( array( 'tax_query', 'meta_query' ) as $parsed ) {
			if ( isset( $query->$parsed ) && is_object( $query->$parsed ) && ! empty( $query->$parsed->queries ) ) {
				return null;
			}
		}
		if ( ! empty( $query->date_query ) ) {
			return null;
		}
		$status = $vars['post_status'] ?? '';
		if ( '' !== $status && 'publish' !== $status && array( 'publish' ) !== $status ) {
			return null;
		}

		// Newest first by date.
		$orderby = $vars['orderby'] ?? '';
		$order   = strtoupper( is_scalar( $vars['order'] ?? '' ) ? (string) ( $vars['order'] ?? '' ) : '' );
		if ( is_array( $orderby ) ) {
			if ( array() === $orderby ) {
				$orderby = '';
			} elseif ( 1 !== count( $orderby ) || ! in_array( strtolower( (string) key( $orderby ) ), array( 'date', 'post_date' ), true ) ) {
				return null;
			} else {
				$order   = strtoupper( (string) current( $orderby ) );
				$orderby = 'date';
			}
		}
		if ( ! is_scalar( $orderby ) || ! in_array( strtolower( trim( (string) $orderby ) ), array( '', 'date', 'post_date' ), true ) ) {
			return null;
		}
		if ( '' !== $order && 'DESC' !== $order ) {
			return null;
		}

		$per_page = (int) ( $vars['posts_per_page'] ?? 0 );
		if ( $per_page < 1 ) {
			return null;
		}
		$offset = isset( $vars['offset'] ) && is_numeric( $vars['offset'] ) ? (int) $vars['offset'] : 0;
		$paged  = max( 1, (int) ( $vars['paged'] ?? 1 ) );
		$n      = $offset > 0 ? $offset + $per_page : $paged * $per_page;

		$excluded = $vars['post__not_in'] ?? array();
		$excluded = is_array( $excluded ) ? array_filter( $excluded ) : array();
		if ( count( $excluded ) > self::MAX_EXCLUDED ) {
			return null;
		}
		$n += count( $excluded );
		if ( $n > self::MAX_SPEC_N ) {
			return null;
		}

		sort( $types );
		return array(
			'types'  => array_values( $types ),
			'n'      => $n,
			'sticky' => self::puts_sticky_first( $query, $types ),
		);
	}

	/**
	 * The post types a query listed, without the ignored and non-viewable
	 * ones: `any`, or a list of type slugs. Empty when it listed none a
	 * visitor sees.
	 *
	 * Read after the query ran. An empty `post_type` resolves the way
	 * WP_Query resolves it: a custom taxonomy query lists every type that
	 * shares the taxonomy (counted as `any`), an attachment or page lookup
	 * lists that type, and anything else lists `post`.
	 *
	 * @param \WP_Query $query   Query.
	 * @param string[]  $ignored Ignored types.
	 * @return string[]
	 */
	private static function listing_types( \WP_Query $query, array $ignored ): array {
		$asked = $query->get( 'post_type' );
		if ( '' === $asked || array() === $asked || null === $asked ) {
			if ( ! empty( $query->is_tax ) ) {
				$asked = 'any';
			} elseif ( ! empty( $query->is_attachment ) ) {
				$asked = 'attachment';
			} elseif ( ! empty( $query->is_page ) ) {
				$asked = 'page';
			} else {
				$asked = 'post';
			}
		}
		$kept = array();
		foreach ( (array) $asked as $type ) {
			$type = (string) $type;
			if ( 'any' === $type ) {
				return array( 'any' );
			}
			if ( '' === $type || in_array( $type, $ignored, true ) || ! is_post_type_viewable( $type ) ) {
				continue;
			}
			$kept[] = $type;
		}
		return array_values( array_unique( $kept ) );
	}

	/**
	 * Whether a plain edit of a post this list does not show can never put
	 * it there: the list is ordered by date or ID (or the default order),
	 * and picks its posts by nothing a plain edit can change.
	 *
	 * Not precise: a search, a meta query or meta order, a title, modified
	 * or comment-count order, a filter on the modified date, a password or
	 * comment-status filter, and a list of posts that puts sticky posts
	 * first (making a post sticky is not a field is_plain_edit() compares).
	 * A random order is precise; see the order check below.
	 *
	 * A lookup by slug (`name`, `pagename`, `post_name__in`) is precise. A
	 * slug change is the one plain edit that can move a post into or out of
	 * it, and pages_for() treats a slug change as not plain, so it reaches
	 * every page of the type. Counting it imprecise made one empty lookup
	 * that a plugin runs on every page (QA on #675, issue 4) turn every page
	 * record imprecise, and every plain edit cleared them all.
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function query_is_precise( \WP_Query $query ): bool {
		$vars = is_array( $query->query_vars ) ? $query->query_vars : array();

		$search = $vars['s'] ?? '';
		if ( ! is_scalar( $search ) || '' !== trim( (string) $search ) ) {
			return false;
		}
		foreach ( array( 'meta_query', 'meta_key', 'meta_value' ) as $key ) {
			if ( isset( $vars[ $key ] ) && '' !== $vars[ $key ] && array() !== $vars[ $key ] ) {
				return false;
			}
		}
		foreach ( array( 'post_password', 'comment_status', 'ping_status', 'comment_count' ) as $key ) {
			if ( ! empty( $vars[ $key ] ) ) {
				return false;
			}
		}
		if ( isset( $vars['has_password'] ) && null !== $vars['has_password'] ) {
			return false;
		}
		if ( self::puts_sticky_first( $query, self::listing_types( $query, self::ignored_types() ) ) ) {
			return false;
		}
		if ( self::filters_on_modified_date( $query ) ) {
			return false;
		}

		$orderby = $vars['orderby'] ?? '';
		if ( ! is_array( $orderby ) && ! is_scalar( $orderby ) ) {
			return false;
		}
		$keys = is_array( $orderby )
			? array_keys( $orderby )
			: preg_split( '/[\s,]+/', trim( (string) $orderby ), -1, PREG_SPLIT_NO_EMPTY );
		$allowed = array( 'date', 'post_date', 'id' );
		if ( self::is_child_page_check( $query ) ) {
			$allowed = array_merge( $allowed, array( 'title', 'post_title', 'menu_order' ) );
		}
		foreach ( (array) $keys as $key ) {
			$key = strtolower( (string) $key );
			// A random pick: the cached page holds one draw, and its IDs are
			// recorded. A plain edit cannot add a post to the set the query
			// draws from (type, status, terms and date are not plain), so
			// only an edit of a post the page shows changes it, and that
			// clears the page through the post's own index file.
			if ( 1 === preg_match( '/^rand(\(\d*\))?$/', $key ) ) {
				continue;
			}
			if ( ! in_array( $key, $allowed, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a list puts sticky posts first: WP_Query does that on a
	 * query it marks `is_home` unless told to ignore them, but it fetches
	 * the sticky posts with the query's own post type, and `sticky_posts`
	 * holds posts. So only a list of `post`, or of any type, can show them.
	 * WP_Query marks almost every secondary query that is not singular, an
	 * archive, a search or a feed as `is_home`, which made a lookup of a
	 * plugin's own post type look sticky-first (QA round 3 on #675).
	 *
	 * @param \WP_Query $query Query.
	 * @param string[]  $types Its listing types, from listing_types().
	 */
	private static function puts_sticky_first( \WP_Query $query, array $types ): bool {
		$vars = is_array( $query->query_vars ) ? $query->query_vars : array();
		if ( ! empty( $vars['ignore_sticky_posts'] ) || empty( $query->is_home ) ) {
			return false;
		}
		return in_array( 'post', $types, true ) || in_array( 'any', $types, true );
	}

	/**
	 * Whether a list picks its posts by their modified date, which every
	 * save moves, plain or not ("recently updated").
	 *
	 * @param \WP_Query $query Query.
	 */
	private static function filters_on_modified_date( \WP_Query $query ): bool {
		$vars   = is_array( $query->query_vars ) ? $query->query_vars : array();
		$clause = $vars['date_query'] ?? array();
		if ( empty( $clause ) && isset( $query->date_query ) && is_object( $query->date_query ) && ! empty( $query->date_query->queries ) ) {
			$clause = $query->date_query->queries;
		}
		if ( empty( $clause ) ) {
			return false;
		}
		$encoded = wp_json_encode( $clause );
		return ! is_string( $encoded ) || false !== stripos( $encoded, 'modified' );
	}

	/**
	 * Whether a query only asks whether one page has a child page: one
	 * post, picked by its parent, of one post type.
	 *
	 * WordPress core runs this on every page view. body_class() asks
	 * get_pages( array( 'parent' => $id, 'number' => 1 ) ) for its
	 * `page-parent` class, and since 6.3 that is a WP_Query of type `page`
	 * with `post_parent` set, one post per page and ordered by title. The
	 * title order made it imprecise, and with it every page record on every
	 * theme (QA round 2 on #675).
	 *
	 * Its answer cannot move on a plain edit: a post only enters or leaves
	 * a parent's children, or changes its title or order among them,
	 * through a change of parent, status, title or menu order, and none of
	 * those is plain. So title and menu order are precise here. A longer
	 * list of children ordered by title stays imprecise, as does anything
	 * else run with a search, meta or other filter: query_is_precise()
	 * checks those before the order.
	 *
	 * @param \WP_Query $query Query.
	 */
	private static function is_child_page_check( \WP_Query $query ): bool {
		$vars = is_array( $query->query_vars ) ? $query->query_vars : array();
		if ( ! isset( $vars['post_parent'] ) || ! is_numeric( $vars['post_parent'] ) ) {
			return false;
		}
		if ( 1 !== (int) ( $vars['posts_per_page'] ?? 0 ) ) {
			return false;
		}
		$type = $vars['post_type'] ?? '';
		if ( is_array( $type ) ) {
			$type = 1 === count( $type ) ? (string) reset( $type ) : '';
		}
		if ( ! is_string( $type ) || '' === $type || 'any' === $type ) {
			return false;
		}
		foreach ( self::FILTER_VARS as $key ) {
			if ( ! empty( $vars[ $key ] ) ) {
				return false;
			}
		}
		return empty( $vars['offset'] ) && max( 1, (int) ( $vars['paged'] ?? 1 ) ) === 1;
	}

	/**
	 * The post IDs a query returned, from post objects, `fields => ids` or
	 * `fields => id=>parent`. Null when it never ran to the end.
	 *
	 * @param \WP_Query $query Query.
	 * @return int[]|null
	 */
	private static function returned_ids( \WP_Query $query ): ?array {
		if ( ! is_array( $query->posts ) ) {
			return null;
		}
		$ids = array();
		foreach ( $query->posts as $item ) {
			if ( is_object( $item ) && isset( $item->ID ) ) {
				$ids[] = (int) $item->ID;
			} elseif ( is_numeric( $item ) ) {
				$ids[] = (int) $item;
			}
		}
		return $ids;
	}

	/**
	 * One record from several lists: their types, their IDs, and precise
	 * only when all of them are and they showed MAX_IDS posts or fewer.
	 *
	 * @param array<int,array{types:string[],ids:int[],precise:bool}> $lists Lists.
	 * @return array{types:string[],ids:int[],precise:bool}
	 */
	private static function merge( array $lists ): array {
		$types   = array();
		$ids     = array();
		$precise = true;
		foreach ( $lists as $list ) {
			$types   = array_merge( $types, $list['types'] );
			$ids     = array_merge( $ids, $list['ids'] );
			$precise = $precise && $list['precise'];
		}
		$types = in_array( 'any', $types, true ) ? array( 'any' ) : array_values( array_unique( array_map( 'strval', $types ) ) );
		sort( $types );
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );
		if ( count( $ids ) > self::MAX_IDS ) {
			$precise = false;
		}
		return array(
			'types'   => $types,
			'ids'     => $precise ? $ids : array(),
			'precise' => $precise,
		);
	}

	/**
	 * Keep what one render listed: add its specs, and write, update or
	 * delete its record. Nothing is written when nothing changed.
	 *
	 * @param string     $url      Page URL.
	 * @param array|null $summary  summarise() output.
	 * @param bool       $complete The page rendered in full.
	 */
	public static function store( string $url, ?array $summary, bool $complete = true ): void {
		$key    = md5( $url . self::device() );
		$specs  = null === $summary ? array() : $summary['specs'];
		$record = null === $summary ? null : $summary['record'];

		// Without the lock first: most renders change nothing.
		list( $pending, $base ) = self::sort_specs( array() === $specs ? array() : self::read_specs(), $specs, $record );
		$existing               = self::read_record( self::page_file( $key ) );
		if ( array() === $pending && self::same_record( $existing, self::wanted_record( $existing, $base, $complete ), $url ) ) {
			return;
		}

		$lock = self::lock();
		try {
			// Again under the lock: another render may have got here first.
			$known                  = array() === $specs ? array() : self::read_specs();
			list( $pending, $base ) = self::sort_specs( $known, $specs, $record );
			if ( array() !== $pending ) {
				foreach ( $pending as $spec ) {
					$known[ self::spec_key( $spec ) ] = $spec;
				}
				self::write_json( self::specs_file(), array( 'specs' => array_values( $known ) ) );
				self::$specs = null;
			}

			$existing = self::read_record( self::page_file( $key ) );
			$wanted   = self::wanted_record( $existing, $base, $complete );
			if ( self::same_record( $existing, $wanted, $url ) ) {
				return;
			}
			if ( null === $existing && null !== $wanted && self::total() >= self::CAP ) {
				self::refuse( $wanted['types'] );
				return;
			}
			self::apply( $key, $url, $existing, $wanted );
		} finally {
			self::unlock( $lock );
		}
	}

	/**
	 * Split a render's specs into the ones to add or widen, and the ones
	 * with no room left, whose lists go into the page's record instead.
	 *
	 * @param array<string,array> $known  Stored specs, keyed by spec_key().
	 * @param array<int,array>    $specs  summarise()'s specs.
	 * @param array|null          $record summarise()'s record.
	 * @return array{0:array<int,array{types:string[],n:int,sticky:bool}>,1:array|null}
	 */
	private static function sort_specs( array $known, array $specs, ?array $record ): array {
		$pending = array();
		$room    = self::SPEC_CAP - count( $known );
		foreach ( $specs as $item ) {
			$spec = $item['spec'];
			$key  = self::spec_key( $spec );
			$have = $pending[ $key ]['n'] ?? ( $known[ $key ]['n'] ?? null );
			if ( null !== $have ) {
				if ( $have < $spec['n'] ) {
					$pending[ $key ] = $spec;
				}
				continue;
			}
			if ( $room > 0 ) {
				--$room;
				$pending[ $key ] = $spec;
				continue;
			}
			$record = null === $record ? $item['list'] : self::merge( array( $record, $item['list'] ) );
		}
		return array( array_values( $pending ), $record );
	}

	/**
	 * Drop a page's record and its index entries, when it has one.
	 *
	 * @param string $key Record key.
	 */
	private static function forget_page( string $key ): void {
		if ( ! is_file( self::page_file( $key ) ) ) {
			return;
		}
		$lock = self::lock();
		try {
			$existing = self::read_record( self::page_file( $key ) );
			if ( null !== $existing ) {
				self::apply( $key, $existing['url'], $existing, null );
			} else {
				wp_delete_file( self::page_file( $key ) );
			}
		} finally {
			self::unlock( $lock );
		}
	}

	/**
	 * The record a render leaves: what it listed, or for a render that
	 * stopped early, that added to what was there.
	 *
	 * @param array|null $existing Stored record.
	 * @param array|null $record   What this render listed outside specs.
	 * @param bool       $complete The page rendered in full.
	 * @return array{types:string[],ids:int[],precise:bool}|null
	 */
	private static function wanted_record( ?array $existing, ?array $record, bool $complete ): ?array {
		if ( $complete || null === $existing ) {
			return $record;
		}
		return null === $record ? $existing : self::merge( array( $existing, $record ) );
	}

	/** Whether a stored record already says what $wanted says. */
	private static function same_record( ?array $existing, ?array $wanted, string $url ): bool {
		if ( null === $existing || null === $wanted ) {
			return null === $existing && null === $wanted;
		}
		return $existing['url'] === $url
			&& self::RECORD_VERSION === ( $existing['v'] ?? 0 )
			&& $existing['types'] === $wanted['types']
			&& $existing['ids'] === $wanted['ids']
			&& $existing['precise'] === $wanted['precise'];
	}

	/**
	 * Change one page's record from $old to $new, and move its URL in the
	 * per-type lists and the inverse index to match. Called under the lock.
	 *
	 * @param string     $key Record key.
	 * @param string     $url Page URL.
	 * @param array|null $old Stored record.
	 * @param array|null $new Record to store, null to delete.
	 */
	private static function apply( string $key, string $url, ?array $old, ?array $new ): void {
		$index = self::read_types();
		if ( null === $old && null !== $new ) {
			++$index['total'];
		} elseif ( null !== $old && null === $new ) {
			$index['total'] = max( 0, $index['total'] - 1 );
		}

		$old_types = null === $old ? array() : $old['types'];
		$new_types = null === $new ? array() : $new['types'];
		foreach ( array_unique( array_merge( $old_types, $new_types ) ) as $type ) {
			$was       = in_array( $type, $old_types, true );
			$is        = in_array( $type, $new_types, true );
			$was_loose = $was && ! $old['precise'];
			$is_loose  = $is && ! $new['precise'];
			if ( $was === $is && $was_loose === $is_loose ) {
				continue;
			}
			$counts = $index['types'][ $type ] ?? array( 'all' => 0, 'loose' => 0 );
			$lists  = self::read_type( $type );
			foreach ( array( 'all' => array( $was, $is ), 'loose' => array( $was_loose, $is_loose ) ) as $set => $change ) {
				if ( $change[0] === $change[1] ) {
					continue;
				}
				$counts[ $set ] = max( 0, $counts[ $set ] + ( $change[1] ? 1 : -1 ) );
				if ( null === $lists[ $set ] ) {
					continue;
				}
				if ( $change[1] ) {
					$lists[ $set ][ $key ] = $url;
				} else {
					unset( $lists[ $set ][ $key ] );
				}
				// Past the limit any change of the type clears the whole
				// site, so the URLs are no longer needed. Kept, the list
				// would be rewritten in full by every new page.
				if ( count( $lists[ $set ] ) > Affected_Pages::LIMIT ) {
					$lists[ $set ] = null;
				}
			}
			if ( 0 === $counts['all'] && 0 === $counts['loose'] ) {
				unset( $index['types'][ $type ] );
				wp_delete_file( self::type_file( $type ) );
			} else {
				$index['types'][ $type ] = $counts;
				self::write_json( self::type_file( $type ), $lists );
			}
		}
		self::write_json( self::types_file(), $index );

		$old_ids = null !== $old && $old['precise'] ? $old['ids'] : array();
		$new_ids = null !== $new && $new['precise'] ? $new['ids'] : array();
		foreach ( array_diff( $new_ids, $old_ids ) as $id ) {
			$pages         = self::read_post( (int) $id ) ?? array();
			$pages[ $key ] = $url;
			self::write_json( self::post_file( (int) $id ), array( 'urls' => $pages ) );
		}
		foreach ( array_diff( $old_ids, $new_ids ) as $id ) {
			$pages = self::read_post( (int) $id ) ?? array();
			unset( $pages[ $key ] );
			if ( array() === $pages ) {
				wp_delete_file( self::post_file( (int) $id ) );
			} else {
				self::write_json( self::post_file( (int) $id ), array( 'urls' => $pages ) );
			}
		}

		if ( null === $new ) {
			wp_delete_file( self::page_file( $key ) );
			return;
		}
		self::write_json(
			self::page_file( $key ),
			array(
				'url'     => $url,
				'types'   => $new['types'],
				'ids'     => $new['ids'],
				'precise' => $new['precise'],
				'v'       => self::RECORD_VERSION,
				'at'      => time(),
			)
		);
	}

	/**
	 * Note a page that was not recorded because the index is at CAP, with
	 * the types it listed. Called under the lock.
	 *
	 * @param string[] $types Types the refused page listed.
	 */
	private static function refuse( array $types ): void {
		$marker = self::read_json( self::dir() . '/' . self::FULL_MARKER );
		$known  = is_array( $marker ) && is_array( $marker['types'] ?? null ) ? array_map( 'strval', $marker['types'] ) : array();
		$merged = array_values( array_unique( array_merge( $known, $types ) ) );
		sort( $merged );
		if ( is_array( $marker ) && $merged === $known ) {
			return;
		}
		self::write_json(
			self::dir() . '/' . self::FULL_MARKER,
			array(
				'types' => $merged,
				'at'    => time(),
			)
		);
	}

	// --- Save side --------------------------------------------------------

	/**
	 * Newest-N specs in the shape Affected_Pages::lists_changed_by() reads:
	 * one `recent` spec per type, which is never narrower than the list it
	 * came from (a post among the newest N of several types is among the
	 * newest N of its own).
	 *
	 * @return array<int,array{kind:string,type:string,n:int,sticky:bool}>
	 */
	public static function recent_specs(): array {
		if ( null === self::$specs ) {
			self::$specs = self::read_specs();
		}
		$out = array();
		foreach ( self::$specs as $spec ) {
			foreach ( $spec['types'] as $type ) {
				$out[] = array(
					'kind'   => 'recent',
					'type'   => (string) $type,
					'n'      => (int) $spec['n'],
					'sticky' => (bool) $spec['sticky'],
				);
			}
		}
		return $out;
	}

	/**
	 * The recorded pages a change to this post may have altered, or null
	 * when the index cannot answer and the caller has to clear the whole
	 * site.
	 *
	 * A plain edit (Affected_Pages::is_plain_change()) that keeps the slug
	 * needs the pages that showed the post (one inverse-index file) and the
	 * pages of its type whose lists are not precise. Any other change,
	 * including a slug change, needs every page of its type. The types are the post's, the one it had before the save, and
	 * `any`. Null when one of those sets is over Affected_Pages::LIMIT, when
	 * a page that might list the type was refused at CAP, or when the index
	 * does not read back.
	 *
	 * @param \WP_Post      $post   Post as it is now.
	 * @param \WP_Post|null $before Post before the change.
	 * @return string[]|null Absolute URLs.
	 */
	public static function pages_for( \WP_Post $post, ?\WP_Post $before ): ?array {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$types = array( (string) $post->post_type, 'any' );
		if ( $before instanceof \WP_Post ) {
			$types[] = (string) $before->post_type;
		}
		$types = array_values( array_unique( $types ) );

		$marker = $dir . '/' . self::FULL_MARKER;
		if ( is_file( $marker ) ) {
			$full = self::read_json( $marker );
			if ( self::total() < self::CAP ) {
				// Back under the cap. This save clears the whole site, which
				// also clears the pages that went unrecorded meanwhile.
				$lock = self::lock();
				try {
					wp_delete_file( $marker );
				} finally {
					self::unlock( $lock );
				}
				return null;
			}
			$refused = is_array( $full ) && is_array( $full['types'] ?? null ) ? array_map( 'strval', $full['types'] ) : array( 'any' );
			if ( in_array( 'any', $refused, true ) || array() !== array_intersect( $types, $refused ) ) {
				return null;
			}
		}

		// A slug change is plain to Affected_Pages, but it can move a post
		// into or out of a lookup by slug, which query_is_precise() counts
		// as precise. So here it needs every page of the type.
		$plain = Affected_Pages::is_plain_change( $post, $before )
			&& (string) ( $before->post_name ?? '' ) === (string) ( $post->post_name ?? '' );
		$set   = $plain ? 'loose' : 'all';
		$index = self::read_types_checked();
		if ( null === $index ) {
			return null;
		}

		$urls = array();
		foreach ( $types as $type ) {
			$count = (int) ( $index['types'][ $type ][ $set ] ?? 0 );
			if ( 0 === $count ) {
				continue;
			}
			if ( $count > Affected_Pages::LIMIT ) {
				return null;
			}
			$lists = self::read_type( $type );
			if ( null === $lists[ $set ] || count( $lists[ $set ] ) !== $count ) {
				// Dropped when the type went past the limit, or out of step:
				// rebuild from the records, once.
				if ( ! self::rebuild() ) {
					return null;
				}
				$index = self::read_types();
				$lists = self::read_type( $type );
				if ( null === $lists[ $set ] || (int) ( $index['types'][ $type ][ $set ] ?? 0 ) > Affected_Pages::LIMIT ) {
					return null;
				}
			}
			$urls = array_merge( $urls, array_values( $lists[ $set ] ) );
		}

		if ( $plain ) {
			$shown = self::read_post( (int) $post->ID );
			if ( null === $shown && is_file( self::post_file( (int) $post->ID ) ) ) {
				if ( ! self::rebuild() ) {
					return null;
				}
				$shown = self::read_post( (int) $post->ID );
			}
			$urls = array_merge( $urls, array_values( (array) $shown ) );
		}
		return array_values( array_unique( array_map( 'strval', $urls ) ) );
	}

	/**
	 * What is recorded for this blog, for `wp xspeed cache listings`.
	 *
	 * @param int $samples How many page records to return in full.
	 * @return array{dir:string,specs:array,pages:int,files:int,types:array,posts:int,full:array|null,samples:array}
	 */
	public static function stats( int $samples = 5 ): array {
		$dir   = self::dir();
		$files = is_dir( $dir . '/pages' ) ? glob( $dir . '/pages/*.json' ) : array();
		$files = is_array( $files ) ? $files : array();
		$posts = is_dir( $dir . '/post' ) ? glob( $dir . '/post/*.json' ) : array();
		$index = self::read_types();
		$types = array();
		foreach ( $index['types'] as $type => $counts ) {
			$lists          = self::read_type( (string) $type );
			$types[ $type ] = array(
				'all'     => (int) $counts['all'],
				'loose'   => (int) $counts['loose'],
				'tracked' => null !== $lists['all'],
			);
		}
		uasort( $types, static fn( $a, $b ) => $b['all'] <=> $a['all'] );
		$out = array(
			'dir'     => $dir,
			'specs'   => array_values( self::read_specs() ),
			'pages'   => (int) $index['total'],
			'files'   => count( $files ),
			'types'   => $types,
			'posts'   => is_array( $posts ) ? count( $posts ) : 0,
			'full'    => is_file( $dir . '/' . self::FULL_MARKER ) ? ( self::read_json( $dir . '/' . self::FULL_MARKER ) ?? array( 'types' => array( 'any' ) ) ) : null,
			'samples' => array(),
		);
		foreach ( $files as $file ) {
			if ( count( $out['samples'] ) >= $samples ) {
				break;
			}
			$record = self::read_record( $file );
			if ( null !== $record ) {
				$out['samples'][] = $record;
			}
		}
		return $out;
	}

	/** Test seam: forget per-request state. */
	public static function reset(): void {
		self::$queries  = array();
		self::$overflow = false;
		self::$active   = null;
		self::$ignored  = null;
		self::$specs    = null;
	}

	/** This blog's index directory. */
	public static function dir(): string {
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		return XSPEED_CACHE_DIR . '/' . self::SUBDIR . '/' . max( 1, $blog );
	}

	// --- Index files ------------------------------------------------------

	private static function page_file( string $key ): string {
		return self::dir() . '/pages/' . $key . '.json';
	}

	private static function post_file( int $id ): string {
		return self::dir() . '/post/' . max( 0, $id ) . '.json';
	}

	private static function type_file( string $type ): string {
		$slug = (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $type ) );
		return self::dir() . '/type/' . ( '' === $slug ? md5( $type ) : $slug ) . '.json';
	}

	private static function types_file(): string {
		return self::dir() . '/types.json';
	}

	private static function specs_file(): string {
		return self::dir() . '/specs.json';
	}

	/**
	 * The specs, keyed by spec_key().
	 *
	 * @return array<string,array{types:string[],n:int,sticky:bool}>
	 */
	private static function read_specs(): array {
		$data = self::read_json( self::specs_file() );
		$out  = array();
		foreach ( is_array( $data['specs'] ?? null ) ? $data['specs'] : array() as $spec ) {
			if ( ! is_array( $spec ) || ! is_array( $spec['types'] ?? null ) || array() === $spec['types'] ) {
				continue;
			}
			$spec = array(
				'types'  => array_values( array_map( 'strval', $spec['types'] ) ),
				'n'      => max( 1, (int) ( $spec['n'] ?? 1 ) ),
				'sticky' => ! empty( $spec['sticky'] ),
			);
			$out[ self::spec_key( $spec ) ] = $spec;
		}
		return $out;
	}

	/** One spec per set of types and stickiness; the longest N stands for the rest. */
	private static function spec_key( array $spec ): string {
		return implode( ',', $spec['types'] ) . ( $spec['sticky'] ? '|sticky' : '' );
	}

	/**
	 * The per-type counts, as stored; empty when there are none.
	 *
	 * @return array{total:int,types:array<string,array{all:int,loose:int}>}
	 */
	private static function read_types(): array {
		$data = self::read_json( self::types_file() );
		return self::types_shape( $data ) ?? array(
			'total' => 0,
			'types' => array(),
		);
	}

	/**
	 * The per-type counts for a save: rebuilt from the records when the
	 * file is missing or does not parse while records exist. Null when the
	 * rebuild found a record it could not read.
	 *
	 * @return array{total:int,types:array<string,array{all:int,loose:int}>}|null
	 */
	private static function read_types_checked(): ?array {
		$index = self::types_shape( self::read_json( self::types_file() ) );
		if ( null !== $index ) {
			return $index;
		}
		$pages = glob( self::dir() . '/pages/*.json' );
		if ( ! is_array( $pages ) || array() === $pages ) {
			return array(
				'total' => 0,
				'types' => array(),
			);
		}
		return self::rebuild() ? self::read_types() : null;
	}

	/**
	 * Validate the per-type counts.
	 *
	 * @param mixed $data Decoded file.
	 * @return array{total:int,types:array<string,array{all:int,loose:int}>}|null
	 */
	private static function types_shape( $data ): ?array {
		if ( ! is_array( $data ) || ! isset( $data['total'] ) || ! is_array( $data['types'] ?? null ) ) {
			return null;
		}
		$types = array();
		foreach ( $data['types'] as $type => $counts ) {
			$types[ (string) $type ] = array(
				'all'   => max( 0, (int) ( $counts['all'] ?? 0 ) ),
				'loose' => max( 0, (int) ( $counts['loose'] ?? 0 ) ),
			);
		}
		return array(
			'total' => max( 0, (int) $data['total'] ),
			'types' => $types,
		);
	}

	/** Pages recorded, from the per-type counts. */
	private static function total(): int {
		return self::read_types()['total'];
	}

	/**
	 * The URLs of one type's records, all and not precise, keyed by record
	 * key. A set is null once it went past Affected_Pages::LIMIT.
	 *
	 * @return array{all:array<string,string>|null,loose:array<string,string>|null}
	 */
	private static function read_type( string $type ): array {
		$data = self::read_json( self::type_file( $type ) );
		$out  = array(
			'all'   => array(),
			'loose' => array(),
		);
		if ( ! is_array( $data ) ) {
			return $out;
		}
		foreach ( array( 'all', 'loose' ) as $set ) {
			$out[ $set ] = array_key_exists( $set, $data ) && null === $data[ $set ]
				? null
				: array_map( 'strval', is_array( $data[ $set ] ?? null ) ? $data[ $set ] : array() );
		}
		return $out;
	}

	/**
	 * The pages that showed a post, keyed by record key, or null when its
	 * file is missing or does not parse.
	 *
	 * @return array<string,string>|null
	 */
	private static function read_post( int $id ): ?array {
		$data = self::read_json( self::post_file( $id ) );
		return is_array( $data ) && is_array( $data['urls'] ?? null ) ? array_map( 'strval', $data['urls'] ) : null;
	}

	/**
	 * One page record, or null when it is missing or does not parse.
	 *
	 * @return array{url:string,types:string[],ids:int[],precise:bool,v:int}|null
	 */
	private static function read_record( string $file ): ?array {
		$data = self::read_json( $file );
		if ( ! is_array( $data ) || ! is_string( $data['url'] ?? null ) || '' === $data['url']
			|| ! is_array( $data['types'] ?? null ) || ! is_array( $data['ids'] ?? null ) || ! is_bool( $data['precise'] ?? null )
		) {
			return null;
		}
		return array(
			'url'     => $data['url'],
			'types'   => array_values( array_map( 'strval', $data['types'] ) ),
			'ids'     => array_values( array_map( 'intval', $data['ids'] ) ),
			'precise' => $data['precise'],
			'v'       => is_int( $data['v'] ?? null ) ? $data['v'] : 0,
		);
	}

	/**
	 * Rebuild the per-type counts and lists and the inverse index from the
	 * page records, under the lock. False when a record does not parse: it
	 * is deleted, and the caller clears the whole site, which also clears
	 * that page so its next render records it again.
	 */
	private static function rebuild(): bool {
		$lock = self::lock();
		try {
			$dir   = self::dir();
			$files = glob( $dir . '/pages/*.json' );
			$files = is_array( $files ) ? $files : array();
			$index = array(
				'total' => 0,
				'types' => array(),
			);
			$lists = array();
			$posts = array();
			$ok    = true;
			foreach ( $files as $file ) {
				$record = self::read_record( $file );
				if ( null === $record ) {
					wp_delete_file( $file );
					$ok = false;
					continue;
				}
				$key = basename( $file, '.json' );
				++$index['total'];
				foreach ( $record['types'] as $type ) {
					$index['types'][ $type ] = $index['types'][ $type ] ?? array( 'all' => 0, 'loose' => 0 );
					$lists[ $type ]          = $lists[ $type ] ?? array( 'all' => array(), 'loose' => array() );
					++$index['types'][ $type ]['all'];
					$lists[ $type ]['all'][ $key ] = $record['url'];
					if ( ! $record['precise'] ) {
						++$index['types'][ $type ]['loose'];
						$lists[ $type ]['loose'][ $key ] = $record['url'];
					}
				}
				if ( $record['precise'] ) {
					foreach ( $record['ids'] as $id ) {
						$posts[ $id ][ $key ] = $record['url'];
					}
				}
			}

			foreach ( array( 'type', 'post' ) as $sub ) {
				foreach ( (array) glob( $dir . '/' . $sub . '/*.json' ) as $stale ) {
					if ( is_string( $stale ) ) {
						wp_delete_file( $stale );
					}
				}
			}
			foreach ( $lists as $type => $sets ) {
				foreach ( $sets as $set => $urls ) {
					if ( count( $urls ) > Affected_Pages::LIMIT ) {
						$sets[ $set ] = null;
					}
				}
				self::write_json( self::type_file( (string) $type ), $sets );
			}
			foreach ( $posts as $id => $urls ) {
				self::write_json( self::post_file( (int) $id ), array( 'urls' => $urls ) );
			}
			self::write_json( self::types_file(), $index );
			return $ok;
		} finally {
			self::unlock( $lock );
		}
	}

	/**
	 * Decoded JSON file, or null.
	 *
	 * @return array|null
	 */
	private static function read_json( string $file ): ?array {
		if ( ! is_file( $file ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own cache dir; WP_Filesystem needs admin creds unavailable on a front-end request.
		$raw  = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file removed meanwhile reads as none.
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Write a JSON file atomically, creating its directory.
	 *
	 * @param string $file Path.
	 * @param array  $data Data.
	 */
	private static function write_json( string $file, array $data ): bool {
		if ( ! self::ensure_dir( dirname( $file ) ) ) {
			return false;
		}
		$json = wp_json_encode( $data );
		return is_string( $json ) && Cache::write_atomic( $file, $json );
	}

	private static function ensure_dir( string $dir ): bool {
		if ( is_dir( $dir ) ) {
			return true;
		}
		$root = XSPEED_CACHE_DIR . '/' . self::SUBDIR;
		wp_mkdir_p( $dir );
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		for ( $at = $dir; strlen( $at ) >= strlen( $root ); $at = dirname( $at ) ) {
			Cache::write_silence( $at );
		}
		return true;
	}

	/**
	 * Take the blog's index lock: flock on `.lock`, as Cache takes its page
	 * cache lock. Null when it cannot be had (no directory, or a filesystem
	 * without flock); the caller writes anyway, every file atomically, and a
	 * race then loses at most one URL, which stays stale until it expires.
	 *
	 * @return resource|null
	 */
	private static function lock() {
		if ( ! self::ensure_dir( self::dir() ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- flock needs a local handle; a failure falls back to unlocked atomic writes.
		$handle = @fopen( self::dir() . '/.lock', 'c' );
		if ( ! is_resource( $handle ) ) {
			return null;
		}
		if ( ! flock( $handle, LOCK_EX ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen above.
			return null;
		}
		return $handle;
	}

	/** @param resource|null $handle Lock from lock(). */
	private static function unlock( $handle ): void {
		if ( is_resource( $handle ) ) {
			flock( $handle, LOCK_UN );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen in lock().
		}
	}

	// --- Request checks ---------------------------------------------------

	/**
	 * The device copy this render is, as Cache::cache_key() splits it: ''
	 * unless phones get a cache of their own. Each copy can list different
	 * posts, so each keeps its own record.
	 */
	private static function device(): string {
		$opts = Settings_Manager::get( 'cache' );
		if ( empty( $opts['mobile_separate'] ) ) {
			return '';
		}
		return function_exists( 'wp_is_mobile' ) && wp_is_mobile() ? '|m' : '|d';
	}

	/**
	 * The ignored post types, after `xspeed_listing_ignored_post_types`.
	 *
	 * @return string[]
	 */
	private static function ignored_types(): array {
		if ( null !== self::$ignored ) {
			return self::$ignored;
		}
		/**
		 * Filter the post types whose queries never make a page a listing
		 * page: menus, templates, styles, patterns, attachments and the like.
		 *
		 * Add a type a plugin queries on every page for its own use, when
		 * saving a post of it never changes what visitors see.
		 *
		 * @param string[] $types Post type slugs.
		 */
		self::$ignored = array_map( 'strval', (array) apply_filters( 'xspeed_listing_ignored_post_types', self::IGNORED_TYPES ) );
		return self::$ignored;
	}

	/**
	 * Whether this request is one whose pages are cached for visitors: a
	 * front-end GET, not admin, AJAX, REST, cron, XML-RPC or WP-CLI.
	 */
	private static function request_may_record(): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( function_exists( 'is_admin' ) && is_admin() )
		) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		return 'GET' === $method;
	}

	/**
	 * Whether the finished response is a page to record: a 200 rendered
	 * here for an anonymous visitor, not a cached copy, not a feed, and
	 * with no query string other than the ones the cache ignores.
	 */
	private static function response_may_record(): bool {
		if ( 200 !== http_response_code() ) {
			return false;
		}
		// A copy served from the cache rendered no lists.
		if ( 0 === strpos( Cache::status_header(), 'HIT' ) ) {
			return false;
		}
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return false;
		}
		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return false;
		}
		return Cache::query_has_only_ignored_params();
	}

	/**
	 * The page's URL on the site's own host, or '' for a request made on
	 * another host name: a forged Host header must not be able to fill the
	 * index.
	 */
	private static function current_url(): string {
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return '';
		}
		$scheme = ! empty( $home['scheme'] ) ? strtolower( (string) $home['scheme'] ) : 'https';
		$host   = strtolower( (string) $home['host'] ) . ( isset( $home['port'] ) ? ':' . (int) $home['port'] : '' );

		$asked = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		$asked = (string) preg_replace( '/:(80|443)$/', '', $asked );
		if ( (string) preg_replace( '/:(80|443)$/', '', $host ) !== $asked ) {
			return '';
		}

		// The raw path, as the visitor and a cache in front see it. Printable
		// ASCII only: a browser percent-encodes everything else.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- filtered to printable ASCII below; stored as JSON and only ever handed back to purge_url().
		$path = (string) strtok( $uri, '?#' );
		$path = (string) preg_replace( '/[^\x21-\x7E]/', '', $path );
		if ( '' === $path || '/' !== $path[0] || strlen( $path ) > self::MAX_PATH ) {
			return '';
		}
		return $scheme . '://' . $host . $path;
	}
}
