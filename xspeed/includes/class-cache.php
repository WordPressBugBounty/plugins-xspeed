<?php
/**
 * Page cache engine.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

class Cache {

	/**
	 * Output-buffer nesting level at which we opened our cache buffer, so
	 * `close_buffer()` can flush ONLY our buffer and never disturb a buffer
	 * another plugin pushed on top of (or below) ours.
	 *
	 * @var int|null
	 */
	private static $buffer_level = null;

	/**
	 * Bytes freed by the current sweep, accumulated by sweep_delete().
	 *
	 * A counter rather than a return value because the two sweeps that free
	 * the bytes — the flat glob loop and the recursive static walk — already
	 * report a FILE count, and `wp xspeed purge` needs both numbers from a
	 * single pass. Re-walking the tree to size it would double the I/O on
	 * exactly the caches large enough for the number to matter.
	 *
	 * @var int
	 */
	private static $sweep_bytes = 0;

	/**
	 * The `X-XSpeed-Cache` value decided for this request, and — when the
	 * decision was BYPASS — the slug of the gate that made it.
	 *
	 * Recorded as well as sent so unit tests (CLI SAPI, where header() is a
	 * no-op and headers_sent() is meaningless) can assert on the decision.
	 *
	 * @var string
	 */
	private static $status_header = '';
	private static $bypass_reason = '';

	/**
	 * Edge/CDN headers decided for this request, after sanitising.
	 *
	 * Same reason as $status_header: header() cannot be observed from the CLI
	 * SAPI, so the pairs we sent are recorded here too.
	 *
	 * @var array<string,string>
	 */
	private static $edge_headers = array();

	/**
	 * This entry's edge headers when they differ from the site-wide bake,
	 * resolved once per store. Null until asked.
	 *
	 * @var array<string,string>|null
	 */
	private static $per_entry_edge = null;

	/**
	 * Cache key whose write was deferred to shutdown because a render-time
	 * translation plugin's buffer wraps ours. Null on every ordinary request.
	 *
	 * @var string|null
	 */
	private static $deferred_key = null;

	/**
	 * Translated page HTML captured by the outer buffer, for the deferred
	 * write. Only populated when a translation plugin is active.
	 *
	 * @var string
	 */
	private static $translated_output = '';

	/**
	 * Did finalize_buffer() run to completion on this request?
	 *
	 * The deferred translated write runs as a PHP shutdown function, which
	 * fires after a `wp_die()` or a bare `exit()` exactly as it does after a
	 * clean render. Only finalize_buffer() sets this, and only at the point
	 * where it has the full buffer in hand — so an aborted render leaves it
	 * false and the writer declines rather than caching a truncated page
	 * under the real key.
	 *
	 * @var bool
	 */
	private static $render_completed = false;

	/**
	 * Hooks that get an argument-aware handler instead of a blanket purge.
	 *
	 * Each fires on an ordinary visitor action — an order, a review, a
	 * registration — where purge_all() cannot see WHAT changed and so wiped
	 * the whole cache on every one. They are re-bound further down to
	 * handlers that inspect the payload first.
	 *
	 * Listed here so the generic invalidation loop skips them. It binds a
	 * closure (to name the cause), and a closure cannot be unbound by the
	 * remove_action() pairs below — binding one would leave the coarse purge
	 * running alongside its replacement and silently undo #243.
	 */
	private const TARGETED_INVALIDATION_HOOKS = array(
		'save_post',
		'before_delete_post',
		'trashed_post',
		'comment_post',
		'wp_set_comment_status',
		'user_register',
		'profile_update',
	);

	public function __construct() {
		/**
		 * When the page-cache output buffer opens.
		 *
		 * Filterable because buffer ORDER decides what gets cached. PHP's
		 * output buffers are LIFO: the last one opened is innermost, and its
		 * callback runs first. A render-time translation plugin that opens
		 * an outer buffer therefore translates AFTER we have already captured
		 * and cached the raw HTML — see translation_buffer_compat().
		 *
		 * @param string $hook     Hook to open the buffer on.
		 * @param int    $priority Priority for that hook.
		 */
		$hook     = (string) apply_filters( 'xspeed_cache_buffer_hook', 'template_redirect' );
		$priority = (int) apply_filters( 'xspeed_cache_buffer_priority', 0 );
		add_action( $hook, array( $this, 'maybe_start_cache' ), $priority );

		// When a render-time translation plugin is present, open one extra
		// buffer OUTSIDE its own so we can capture post-translation HTML.
		// TranslatePress opens on `init` priority 0, so we take a negative
		// priority to land outside it. This buffer only collects bytes for
		// the deferred cache write — it never modifies the response.
		add_action(
			'init',
			static function () {
				if ( ! self::translation_plugin_active() ) {
					return;
				}
				// `init` fires on EVERY request type, and
				// translation_plugin_active() is a class_exists() check that
				// is true site-wide — so without this guard the buffer opened
				// on REST, admin-ajax, cron and WP-CLI too. None of those
				// reach template_redirect, so $deferred_key stays null and
				// the collected bytes are never released: a long-running
				// WP-CLI command copied every byte of its output into a
				// string that grew for the life of the process.
				if ( is_admin()
					|| wp_doing_ajax()
					|| wp_doing_cron()
					|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
					|| ( defined( 'WP_CLI' ) && WP_CLI )
					|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
					return;
				}
				ob_start(
					static function ( $chunk ) {
						self::$translated_output .= $chunk;
						return $chunk;
					}
				);
			},
			(int) apply_filters( 'xspeed_translation_outer_buffer_priority', -100 )
		);

		// Events that should invalidate cached output. Beyond posts/comments,
		// this covers user and term changes — the REST cache can serve
		// /wp/v2/users, /wp/v2/categories, /wp/v2/tags, and these also affect
		// rendered author bylines / term-archive pages. Without them, an edit
		// left the matching endpoint (and archives) stale for the full TTL.
		// (FBS-82408)
		$invalidate_hooks = array(
			'save_post', 'before_delete_post', 'trashed_post',
			'comment_post', 'wp_set_comment_status',
			'switch_theme', 'activated_plugin', 'deactivated_plugin',
			// Users → /wp/v2/users + author archives.
			'profile_update', 'user_register', 'deleted_user',
			// Terms → /wp/v2/{taxonomy} + term archives.
			'created_term', 'edited_term', 'delete_term',
			// Menu structure changes (reorder, rename, assign to a location)
			// fire only here — the per-item `nav_menu_item` save_post does
			// not cover them. (#270 regression)
			'wp_update_nav_menu',
		);
		foreach ( $invalidate_hooks as $hook ) {
			// Name the hook in the cause rather than binding purge_all bare.
			// Bound bare, WordPress passes the action's own first argument
			// into $cause — a term id, a user id, a menu id — so the activity
			// feed read "Cache purged (12)" and told the user nothing about
			// what happened. (#270 QA round 2)
			//
			// The four hooks that get an argument-aware handler below
			// (save_post, comment_post, user_register, profile_update) are
			// deliberately NOT wired here: a closure cannot be unbound by
			// remove_action(), so binding one would leave the coarse purge in
			// place alongside its replacement and silently undo #243. Skipping
			// them is equivalent — each is re-added with its own handler, and
			// each of those names its own cause.
			if ( in_array( $hook, self::TARGETED_INVALIDATION_HOOKS, true ) ) {
				continue;
			}
			add_action(
				$hook,
				static function () use ( $hook ): void {
					self::purge_all(
						'hook:' . $hook,
						null,
						self::invalidation_for_hook( $hook )
					);
				}
			);
			add_action( $hook, array( 'XSpeed\\Minifier', 'purge_minified' ) );
		}

		// Updating a plugin, theme or core changes the markup and the assets
		// a page is built from, but fires NONE of the hooks above: WordPress
		// does not deactivate and reactivate a plugin to update it, so
		// `activated_plugin` never runs and the cached HTML survives the
		// update untouched for the whole TTL — up to 7 days on the Aggressive
		// preset, 30 at the maximum.
		//
		// The stale copy is not merely old, it is wrong in a way the user
		// cannot see the cause of: they update a plugin to get a fix, the
		// cache keeps serving the pre-fix HTML, and the update looks like it
		// did nothing. Minified assets do regenerate on their own (their key
		// includes the source filemtime), which makes it worse rather than
		// better — the cached pages still link the PREVIOUS hashes.
		//
		// Purge unconditionally on any completed update. Scoping it to
		// "plugins that enqueue front-end assets" is not knowable here, and a
		// cold cache after an update is the cheaper mistake. (#269)
		add_action( 'upgrader_process_complete', array( __CLASS__, 'purge_after_upgrade' ), 10, 2 );
		// The replacement signal has to outlive OUR listener: add-ons read it
		// through upgrade_replaced_code() from their own priority-10 callbacks,
		// and consuming it inside purge_after_upgrade() meant whoever
		// registered second saw false. Cleared at the END of the dispatch
		// instead, once every listener has had its turn.
		//
		// Depth-counted, because this action NESTS. Core hangs
		// Language_Pack_Upgrader::async_upgrade() on it at priority 20
		// (wp-admin/includes/admin-filters.php), and that runs a whole
		// upgrader of its own, which fires this same action again. A flat
		// reset therefore fired while the OUTER dispatch was still running —
		// on any site with pending translations — and every listener after
		// priority 20 read the cleared signal as false. Which is the bug this
		// pair exists to fix, back again and harder to see. (#303)
		add_action( 'upgrader_process_complete', array( __CLASS__, 'note_upgrade_dispatch' ), PHP_INT_MIN );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget_cleared_destination' ), PHP_INT_MAX );
		// WordPress labels an upload-and-replace as an INSTALL, so the action
		// alone cannot tell "added beside nothing" from "replaced live code".
		// This filter fires only when the upgrader removed an existing copy,
		// which is exactly the difference. Registered as a filter listener
		// that returns its input untouched. (#303)
		add_filter( 'upgrader_clear_destination', array( __CLASS__, 'note_cleared_destination' ), 10, 1 );
		// Unattended auto-updates are the case that matters most here: they
		// land overnight with nobody around to purge by hand, which is the
		// exact scenario the stale cache goes undiagnosed in. WordPress fires
		// this INSTEAD of a per-item upgrader_process_complete for some
		// background runs. Its payload is a results array keyed by type
		// rather than a hook_extra, so it needs its own handler — passing it
		// to purge_after_upgrade() landed it in the unused $upgrader slot and
		// left $type empty, which read as "invalidating" and purged the whole
		// cache for a language-pack-only run. Matches what LiteSpeed binds.
		// (#298)
		add_action( 'automatic_updates_complete', array( __CLASS__, 'purge_after_auto_updates' ), 10, 1 );
		// …except the four hooks above that fire on ordinary visitor actions.
		// Attached bare, purge_all() can't see WHAT changed, so on a store
		// every order, every product review and every checkout
		// account-creation wiped 100% of the cache — all anonymous happy-path
		// actions, so the cache never reached steady state (#243). Measured:
		// 3 orders across 36 pageviews took the hit rate from 83% to 50% and
		// the average response from 23ms to 57ms.
		//
		// HPOS does NOT help: WooCommerce still writes a
		// `shop_order_placehold` row into wp_posts to reserve the order ID,
		// so save_post fires either way. The gate therefore keys on POST-TYPE
		// VIEWABILITY, not on storage mode — which fixes both modes at once,
		// and generalises to Flamingo (#229) and Tutor LMS (#231) too.
		remove_action( 'save_post', array( __CLASS__, 'purge_all' ) );
		remove_action( 'save_post', array( 'XSpeed\\Minifier', 'purge_minified' ) );
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 10, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_removed' ), 10, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'on_post_removed' ), 10, 2 );
		// wp_delete_post() hands an attachment to wp_delete_attachment() and
		// returns BEFORE before_delete_post fires, so deleting media reached
		// neither hook above. Attachment pages are public and media appears in
		// galleries, so that left cached pages showing a file that is gone.
		// (dev caught this via `deleted_post`, which this branch replaced.)
		add_action( 'delete_attachment', array( __CLASS__, 'on_post_removed' ), 10, 2 );

		remove_action( 'comment_post', array( __CLASS__, 'purge_all' ) );
		remove_action( 'comment_post', array( 'XSpeed\\Minifier', 'purge_minified' ) );
		add_action( 'comment_post', array( __CLASS__, 'on_comment_post' ), 10, 3 );
		add_action( 'wp_set_comment_status', array( __CLASS__, 'on_comment_status' ), 10, 2 );

		remove_action( 'user_register', array( __CLASS__, 'purge_all' ) );
		remove_action( 'user_register', array( 'XSpeed\\Minifier', 'purge_minified' ) );
		add_action( 'user_register', array( __CLASS__, 'on_user_change' ) );

		remove_action( 'profile_update', array( __CLASS__, 'purge_all' ) );
		remove_action( 'profile_update', array( 'XSpeed\\Minifier', 'purge_minified' ) );
		add_action( 'profile_update', array( __CLASS__, 'on_user_change' ) );

		// Product data lives in post meta and lookup tables, NOT in wp_posts,
		// so WC_Product_Data_Store_CPT::update() takes a direct $wpdb->update()
		// branch and save_post never fires. Anchoring invalidation on
		// save_post therefore missed 100% of commerce-relevant mutations: a
		// REST price change, wc_update_product_stock(), a CLI ->save(), and
		// every scheduled sale start/end left the product page, the shop and
		// the category archives serving the old price and stock for the full
		// lifetime — the store quoting one price and charging another (#242).
		//
		// This MUST ship with the gate above: once orders stop purging
		// everything, the accidental invalidation that was masking this
		// disappears, and an order that reduces stock would leave the product
		// page stale.
		if ( class_exists( 'WooCommerce' ) ) {
			foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product' ) as $wc_hook ) {
				add_action( $wc_hook, array( __CLASS__, 'purge_product' ) );
			}
			// Direct stock writes bypass the CRUD entirely.
			add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'purge_product_object' ) );
			add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'purge_product_object' ) );
			add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'purge_product' ) );
			add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'purge_product' ) );
		}

		add_action( 'update_option_xspeed_options', array( __CLASS__, 'on_settings_change' ), 10, 2 );

		// …and the same for every PER-MODULE option. The handler above only
		// ever watched the legacy `xspeed_options` blob, but every module has
		// since migrated to its own `xspeed_module_<slug>` option and no hook
		// followed — so changing Minify HTML, Lazy Load, Remove Query Strings
		// etc. left the cached HTML untouched until the TTL expired (24h by
		// default) and the feature read as broken. (#205)
		//
		// One central listener rather than a hook per module: it covers Pro
		// modules with no cross-repo change, and a new module can't forget to
		// wire it up.
		add_action( 'updated_option', array( __CLASS__, 'on_module_settings_change' ), 10, 1 );
		// `added_option` matters as much as `updated_option`: on a fresh install
		// a module's option doesn't exist yet, so the FIRST save of every panel
		// goes through add_option() and would otherwise skip the purge — the
		// original bug surviving one save per module. `deleted_option` covers a
		// reset-to-defaults, which changes rendered HTML just as much. (#205)
		add_action( 'added_option', array( __CLASS__, 'on_module_settings_change' ), 10, 1 );
		add_action( 'deleted_option', array( __CLASS__, 'on_module_settings_change' ), 10, 1 );

		add_action( 'admin_bar_menu', array( $this, 'admin_bar_purge' ), 100 );
		add_action( 'admin_post_xspeed_purge', array( $this, 'handle_admin_bar_purge' ) );
	}

	public static function on_settings_change( $old, $new ) {
		// gzip_enabled moved to xspeed_module_gzip — GzipModule owns the
		// .htaccess flip via its own update_option_xspeed_module_gzip hook.
		// Same migration is planned for cache_expiry + excluded_urls
		// (Cache module). Keep this handler around for whatever still
		// lives in the legacy blob (cache_enabled is special and goes
		// through Cache::toggle anyway).

		// Any settings change — purge caches so changes take effect.
		self::purge_all( 'settings change' );
		Minifier::purge_minified();
	}

	/**
	 * Modules whose settings cannot change rendered HTML, so a write to them
	 * doesn't warrant throwing away the page cache.
	 *
	 * The safe default is to purge: a module is listed here only when it is
	 * clearly incapable of altering front-end output (diagnostics, the MCP
	 * server, licensing/telemetry surfaces). When in doubt, leave it off the
	 * list — a needless purge costs a re-render, a missed one makes the
	 * feature look broken. (#205)
	 *
	 * @return string[] Module slugs.
	 */
	public static function non_rendering_modules(): array {
		return (array) apply_filters(
			'xspeed_non_rendering_modules',
			array(
				'mcp',            // AI endpoint — no front-end output.
				'health',         // diagnostics only.
				'support',        // support snapshot.
				'score',          // PageSpeed/GTmetrix runner.
				'migration',      // one-shot importer.
				'settings',       // import/export surface.
				'cache-coverage', // read-only reporting.
				'ai-privacy',     // consent flags for AI surfaces.
				'database',       // DB cleanup schedule — no HTML impact.
				// Pro slugs — listed by name rather than by asking Pro, so
				// Free stays unaware of it. A Pro module absent here simply
				// purges, which is the safe default.
				'license',
				'pro_status',
				'analytics',
				'performance-health',
				'recommendations',
				'ai-provider',
				'migration-pro',
			)
		);
	}

	/**
	 * Purge when ANY module's settings option is written. (#205)
	 *
	 * Bound to `updated_option`, `added_option` and `deleted_option` — all three
	 * fire for every option on the site, so the prefix test comes first and is
	 * the cheap path for the ~99% of writes that aren't ours. All three pass the
	 * option name first, which is why this can't hook purge_all() directly:
	 * that takes $cause first, so every purge would be filed under a cause
	 * literally named "xspeed_module_minify".
	 *
	 * @param string $option Option name that was just written or removed.
	 */
	public static function on_module_settings_change( $option ): void {
		$option = (string) $option;
		$prefix = Settings_Manager::OPTION_PREFIX;
		if ( 0 !== strpos( $option, $prefix ) ) {
			return;
		}

		$slug = substr( $option, strlen( $prefix ) );
		if ( '' === $slug || in_array( $slug, self::non_rendering_modules(), true ) ) {
			return;
		}

		// Guard against re-entry: purge_all() and purge_minified() can write
		// options of their own (stats, timestamps), and a nested purge would
		// both waste work and risk recursing through this same hook.
		static $purging = false;
		if ( $purging ) {
			return;
		}
		$purging = true;

		self::purge_all( 'settings change' );
		Minifier::purge_minified();

		$purging = false;
	}

	/**
	 * Stamp the request's cache decision on the response.
	 *
	 * `X-XSpeed-Cache` was only ever written on the serve-from-cache paths,
	 * so a miss and a deliberate bypass both came back with no header at all
	 * — indistinguishable from a `curl -I`, the first thing anyone reaches
	 * for when a site "isn't caching" (issue #10). The reason slug rides
	 * along on `X-XSpeed-Reason`, but only under WP_DEBUG so production
	 * responses stay clean. Slugs are fixed per gate — never the matched
	 * pattern, cookie or user-agent, which would echo request input back.
	 *
	 * @param string $value  HIT (php) | MISS | BYPASS.
	 * @param string $reason Fixed slug naming the gate, for BYPASS only.
	 */
	private static function mark( string $value, string $reason = '' ): void {
		self::$status_header = $value;
		self::$bypass_reason = $reason;

		// Every status, not just a HIT. A page we declined to cache is the
		// one an edge most needs telling about: it goes out naked today, and
		// a CDN that stores HTML by default keeps somebody's cart.
		//
		// Resolved before the headers_sent() guard so the decision is
		// recorded (and observable in tests) even on a request that can no
		// longer send headers; only the emission below is conditional.
		self::$edge_headers = self::edge_headers_for( self::edge_status( $value ), 'request', $reason );

		if ( headers_sent() ) {
			return;
		}
		header( 'X-XSpeed-Cache: ' . $value );
		if ( '' !== $reason && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			header( 'X-XSpeed-Reason: ' . $reason );
		}
		foreach ( self::$edge_headers as $name => $val ) {
			header( $name . ': ' . $val );
		}
	}

	/**
	 * Normalize an `X-XSpeed-Cache` value to the vocabulary the edge seam
	 * speaks.
	 *
	 * The header value carries which layer served the page (`HIT (php)`,
	 * `HIT (nginx)`, `HIT (static)`); nothing deciding what to tell a CDN
	 * cares, and making a caller match on three spellings of one outcome is
	 * how a rule ends up applied on two paths out of three.
	 */
	private static function edge_status( string $value ): string {
		return 0 === strpos( $value, 'HIT' ) ? 'HIT' : $value;
	}

	/** Record a bypass gate and answer "don't cache" in one statement. */
	private static function bypass( string $reason ): bool {
		self::mark( 'BYPASS', $reason );
		return false;
	}

	/** The X-XSpeed-Cache value decided for this request ('' if none yet). */
	public static function status_header(): string {
		return self::$status_header;
	}

	/** The bypass gate slug for this request ('' unless BYPASS). */
	public static function bypass_reason(): string {
		return self::$bypass_reason;
	}

	/**
	 * The edge/CDN pairs sent on this request ('' if none were).
	 *
	 * @return array<string,string>
	 */
	public static function edge_headers(): array {
		return self::$edge_headers;
	}

	/**
	 * Bypass gates that do NOT ask a cache in front of us to stand down.
	 *
	 * Every other slug does. The split is the reason this reads the gate
	 * rather than the status: a bypass usually means "this response is
	 * personal, or someone decided this page is never stored", and an edge
	 * holding one of those does precisely what we refused to do. These two
	 * mean something else.
	 *
	 * `cache-disabled` is the user switching OUR page cache off. Nothing
	 * about the page became personal. Sending `no-store` on every page of a
	 * site whose owner chose a different cache would make a local toggle a
	 * site-wide side effect on infrastructure we do not own.
	 *
	 * `non-frontend` is admin, REST, cron and AJAX. Not ours to describe:
	 * WordPress already nocaches admin, and a REST caller sets its own
	 * policy.
	 */
	private const HOLD_EXEMPT_BYPASS = array( 'cache-disabled', 'non-frontend' );

	/**
	 * Bypass gates that describe the SHAPE of the request rather than the
	 * visitor or the page.
	 *
	 * These still hold, but only once we have evidence of an edge — the same
	 * bar a MISS has to clear. The difference matters because the default
	 * excluded-URL list contains `/feed/`, the sitemap and `/wp-json/`, and
	 * `query-param` catches `?lang=fr`, `?paged=2`, and every page of a
	 * plain-permalink site.
	 *
	 * xSpeed refuses those because IT cannot key on a query string, not
	 * because the response is private. A CDN keys on the full URL and caches
	 * them correctly. Holding them unconditionally would have meant every
	 * default install stopped its feed and sitemap being edge-cached — a
	 * performance regression shipped to sites that never had a CDN in the
	 * first place, in the name of protecting them from one.
	 *
	 * The gates left out of this list are about the visitor (`logged-in`,
	 * `excluded-cookie`) or are somebody stating outright that this page is
	 * never to be stored (`donotcachepage`, `post-excluded`, `filtered`).
	 * Those hold whether or not we can see an edge.
	 */
	private const REQUEST_SHAPE_BYPASS = array( 'query-param', 'non-get', 'user-agent' );

	/**
	 * Default exclusions that are about the site's plumbing, not its content.
	 *
	 * `excluded-url` covers two unlike things. The default list carries
	 * `/cart`, `/checkout`, `/my-account` and `/wp-login` — personal pages,
	 * and the reason this feature exists. It also carries the entries below:
	 * feeds, sitemaps, the REST root, the front controller. Those are public,
	 * cacheable, and hammered by pollers; a CDN keys on the full URL and
	 * serves them correctly, so telling it to stop is a cost with no benefit.
	 *
	 * Matched as exact strings against the stored list, never as patterns
	 * against the path. Three bugs came out of doing it the other way round:
	 * `strpos( $uri, '/feed' )` matched `/my-account/feedback/`, reading the
	 * whole URI let `/cart/?utm_source=/feed/` disguise a cart as a feed, and
	 * a bare `index.php` — which is in this list, and which every URL contains
	 * on an "almost pretty" permalink site — made every page on such a site
	 * look personal. Comparing the LIST ENTRY rather than the path cannot make
	 * any of those mistakes, and it keeps a pattern the site owner added
	 * themselves on the personal side where it belongs.
	 */
	private const STRUCTURAL_EXCLUSIONS = array(
		'/wp-json/',
		'/xmlrpc.php',
		'~wp-.*\.php',
		'/feed/',
		'index.php',
		'/robots.txt',
		// Both spellings, and no entry here is ever retired. This is a
		// RECOGNITION list, not a source of truth: it is matched against
		// whatever the site has STORED, and a site that saved its settings
		// before `~sitemap(_index)?\.xml` was widened to `sitemaps?` (for
		// SEOPress, which ships sitemaps.xml) still has the old string in
		// its option row. Dropping the old spelling when the default moved
		// would read every upgraded site's sitemap exclusion as somebody's
		// personal data and hold sitemaps off the CDN — the bug this whole
		// predicate exists to prevent, reintroduced by a rename.
		'~sitemaps?(_index)?\.xml',
		'~sitemap(_index)?\.xml',
	);
	/**
	 * Header names no edge instruction may ever carry.
	 *
	 * These describe the transfer, not the caching policy, and one wrong
	 * value from a settings field is a white screen rather than a missing
	 * optimization.
	 */
	private const NEVER_AN_EDGE_HEADER = array(
		'content-length',
		'content-encoding',
		'content-type',
		'transfer-encoding',
		'set-cookie',
		'location',
		'x-xspeed-cache',
		'x-xspeed-edge-hold',
	);

	/**
	 * Reasons that hold the edge off even when we detected nothing in front.
	 *
	 * `none` confidence means no evidence of a proxy, which is not proof
	 * there is none — a transparent proxy and a host page cache both leave
	 * the request untouched. So the question is what a wasted header costs
	 * against what a missed one does, and the answer differs by reason.
	 *
	 * These two are correctness failures. A cart page stored by something we
	 * could not see is the defect this exists to fix, and a mobile-split page
	 * served to the wrong device is a wrong page rather than a slow one.
	 * Ninety bytes on a response that was never cacheable is a cheap premium.
	 *
	 * `miss` and `pending` are performance hedges, and a hedge against a
	 * cache that does not exist is noise on every first render. Skipping them
	 * has a second benefit: because per_entry_edge_headers() compares `store`
	 * against `bake`, a `pending` hold that never fires leaves the two
	 * agreeing, which keeps the page on the static tree.
	 */
	private const HOLD_WITHOUT_EVIDENCE = array( 'bypass', 'mobile-split' );

	/**
	 * Is a module still going to change this page after this response?
	 *
	 * Free itself never says yes — nothing in Free defers work past the
	 * request. Minification and combining write their file and return its URL
	 * inside the same render; the LCP preload is chosen by parsing the HTML
	 * being sent. It is the question that matters to anything caching in
	 * front of us, so Free asks it on their behalf and lets whoever owns the
	 * deferred work answer.
	 *
	 * Answer TRUE while the work is outstanding for the page being served.
	 * The cost of a false yes is one extra origin hit; the cost of a false no
	 * is an un-optimized page pinned at the edge for the full lifetime, which
	 * is the failure this exists to prevent — so when in doubt, say yes.
	 *
	 * Asked on a `request` only, and that boundary is the whole safety of it.
	 *
	 * A `bake` is generated once, in an admin or CLI request, and serves every
	 * static HIT on the site; a per-page answer frozen into it would be wrong
	 * for every other page.
	 *
	 * A `store` is worse, and cost a live site an afternoon. The pairs written
	 * at store time go into the `.meta` sidecar, which the drop-in replays on
	 * every later HIT — before plugins load, so nothing can re-ask this
	 * question. A hold written there therefore outlives the state that caused
	 * it, and the only thing that clears it is the page being stored again. On
	 * a site where the deferred work never completes, every re-store re-pins
	 * it, and the page is never edge-cacheable again. The symptom is a cache
	 * HIT carrying `no-store` and `X-XSpeed-Edge-Hold: pending` on a page
	 * whose deferred work finished long ago — the sidecar answering with
	 * state nothing can re-ask.
	 *
	 * Holding the MISS is what this is for, and it is enough: that response is
	 * the un-optimized one. The copy we then store is what an edge should
	 * mirror, and when the work does land the module purges the page, which
	 * reaches the edge. The purge is the correctness mechanism; this is only
	 * meant to cover the single render before it.
	 *
	 * @param string $context `request`, `store` or `bake`.
	 */
	public static function edge_optimization_pending( string $context = 'request' ): bool {
		if ( 'request' !== $context ) {
			return false;
		}

		/**
		 * Filter: xspeed_edge_optimization_pending
		 *
		 * @param bool $pending Whether deferred work will still change this page.
		 */
		return (bool) apply_filters( 'xspeed_edge_optimization_pending', false );
	}

	/**
	 * Does mobile cache split this URL into two renders?
	 *
	 * With `mobile_separate` on, Free keys its cache on device and serves a
	 * different page to a phone than to a desktop at the SAME url. No CDN
	 * varies on User-Agent, so an edge holding one of those renders serves it
	 * to everyone: whichever device asked first decides what the other sees,
	 * for the whole lifetime. A wrong page, not a slow one.
	 *
	 * Read from the stored option rather than through Settings_Manager: this
	 * is consulted from the serve path, where the module registry may not
	 * have run.
	 */
	private static function mobile_cache_splits_html(): bool {
		$stored = self::stored_cache_opts();
		return ! empty( $stored['mobile_separate'] );
	}

	/**
	 * Why, if at all, a cache in front of us should refuse to store this.
	 *
	 * @param string $status        `HIT`, `MISS` or `BYPASS`.
	 * @param string $context       `request`, `store` or `bake`.
	 * @param string $bypass_reason The gate slug, for BYPASS only.
	 * @return string '' or one of bypass|bypass-shape|miss|mobile-split|pending.
	 */
	private static function edge_hold_reason( string $status, string $context, string $bypass_reason ): string {
		$reason = '';

		// The two exempt gates are answered before anything else, or a site
		// with Separate Mobile Cache on would keep holding after the page
		// cache was switched off — which is exactly the "a local toggle must
		// not become a site-wide side effect on infrastructure we do not own"
		// rule below, defeated by the ordering rather than by the logic.
		if ( 'BYPASS' === $status && in_array( $bypass_reason, self::HOLD_EXEMPT_BYPASS, true ) ) {
			/** This filter is documented below. */
			return (string) apply_filters( 'xspeed_edge_hold_reason', '', $status, $context, $bypass_reason );
		}

		// First, because it is the only reason true in every context: the
		// setting is a property of the site, not of one request, so it is the
		// one thing a baked artifact can honestly assert.
		//
		// It is also the only reason that holds a HIT — a response we DID
		// cache — and that is deliberate rather than an artefact of the
		// ordering. With mobile_separate on we key the cache by device and
		// serve different HTML to a phone than to a desktop at the same URL.
		// No CDN varies on User-Agent, so an edge holding one of those
		// renders serves it to everyone and whichever device asked first
		// decides what the other sees. Our copy is fine; theirs would be a
		// wrong page. The static path is switched off in this mode anyway
		// (static_rewrite_allowed()), so these hits come from the drop-in,
		// which carries the same baked answer.
		if ( self::mobile_cache_splits_html() ) {
			$reason = 'mobile-split';
		} elseif ( 'BYPASS' === $status ) {
			$shaped = in_array( $bypass_reason, array( 'excluded-url', 'query-param' ), true )
				? ! self::path_is_a_personal_exclusion( $bypass_reason )
				: in_array( $bypass_reason, self::REQUEST_SHAPE_BYPASS, true );
			$reason = $shaped ? 'bypass-shape' : 'bypass';
		} elseif ( self::edge_optimization_pending( $context ) ) {
			$reason = 'pending';
		} elseif ( 'MISS' === $status ) {
			$reason = 'miss';
		}

		/**
		 * Filter: xspeed_edge_hold_reason
		 *
		 * Return '' to veto a hold, or a reason string to force one.
		 *
		 * @param string $reason        '' or bypass|bypass-shape|miss|mobile-split|pending.
		 * @param string $status        `HIT`, `MISS` or `BYPASS`.
		 * @param string $context       `request`, `store` or `bake`.
		 * @param string $bypass_reason The gate slug, for BYPASS only.
		 */
		return (string) apply_filters( 'xspeed_edge_hold_reason', $reason, $status, $context, $bypass_reason );
	}

	/**
	 * Was this page excluded because it is personal, or because it is
	 * plumbing we cannot key a cache entry on?
	 *
	 * Answers by removing the structural defaults from the site's own
	 * exclusion list and asking whether anything is left that matches. So a
	 * feed matches only `/feed/` and comes back false; `/my-account/feedback/`
	 * matches `/my-account` and comes back true; and on an "almost pretty"
	 * permalink site, where every path contains `index.php`, an ordinary page
	 * matches nothing else and is correctly treated as public.
	 *
	 * The path only, never the query string — a visitor writes that, and
	 * `/cart/?utm_source=/feed/` must not be able to talk a cart out of its
	 * hold. It is also what `should_cache()` matches the list against.
	 *
	 * Asked for a `query-param` bypass too, because the query gate runs
	 * BEFORE the URL gate, so `/cart/?add-to-cart=12` reports `query-param`
	 * and never reaches `excluded-url` at all. Which gate fired first says
	 * nothing about whose data is on the page.
	 */
	private static function path_is_a_personal_exclusion( string $bypass_reason ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the path of the request being served; there is no form here to nonce.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) strtok( $uri, '?' );
		if ( '' === $path ) {
			return false;
		}

		// Through Settings_Manager, not the raw option, because the schema's
		// default IS the structural list and a fresh install has never
		// written the option. Read raw, every site that has not visited the
		// settings screen looks like a site with no exclusions at all, takes
		// the contradiction branch below, and reports its feeds as personal.
		//
		// Safe here where `mobile_cache_splits_html()` is not: we are only
		// ever called with a bypass reason, and those come from
		// `should_cache()`, which resolved the same settings through
		// `Settings_Manager::get()` to produce them.
		$opts     = Settings_Manager::get( 'cache' );
		$excluded = is_array( $opts['excluded_urls'] ?? null ) ? $opts['excluded_urls'] : array();
		if ( array() === $excluded ) {
			// An `excluded-url` bypass with no exclusion list is a
			// contradiction — something excluded the request and the list
			// cannot say what — so assume personal, because a wasted header
			// costs a little origin traffic while a missing one serves
			// somebody's basket to a stranger. A `query-param` bypass with an
			// empty list is just an ordinary page carrying a parameter, and
			// says nothing about the path at all.
			return 'excluded-url' === $bypass_reason;
		}

		$personal = array_values(
			array_filter(
				$excluded,
				static fn ( $pattern ) => ! in_array( (string) $pattern, self::STRUCTURAL_EXCLUSIONS, true )
			)
		);

		return array() !== $personal && Glob_Matcher::any_match( $personal, $path );
	}

	/**
	 * The edge/CDN headers to send on a response with this cache status.
	 *
	 * @param string $status        `HIT`, `MISS` or `BYPASS`.
	 * @param string $context       `request` when resolved per request on the
	 *                              PHP serve path, `store` when resolved for
	 *                              one entry's sidecar, `bake` when resolved
	 *                              once and frozen into an artifact.
	 * @param string $bypass_reason The gate slug, for BYPASS only.
	 * @return array<string,string>
	 */
	public static function edge_headers_for( string $status, string $context = 'request', string $bypass_reason = '' ): array {
		$base = array();
		if ( 'HIT' === $status ) {
			/**
			 * Filter: xspeed_edge_cache_headers
			 *
			 * Response headers to add to a cached HTML response. A HIT-only
			 * contract: a lifetime is a promise that this copy is worth
			 * keeping, and neither a first render nor a page we refused to
			 * cache is one.
			 *
			 * The same filter feeds three regimes and `$context` says which.
			 * On the PHP serve path it runs per request (`request`); at store
			 * time it runs for one entry (`store`); when the drop-in or a
			 * server rule is generated it runs once (`bake`) and the result
			 * answers for every static HIT on the site. Anything per-page — a
			 * post id in a cache tag, say — must be skipped under `bake`.
			 *
			 * @param array<string,string> $headers Header name => value.
			 * @param string               $status  Always `HIT` here.
			 * @param string               $context `request`, `store` or `bake`.
			 */
			$base = self::sanitize_edge_headers( (array) apply_filters( 'xspeed_edge_cache_headers', array(), 'HIT', $context ) );
		}

		$reason = self::edge_hold_reason( $status, $context, $bypass_reason );
		if ( '' === $reason ) {
			return $base;
		}
		$detected = Edge_Provider::detect( $context );
		if ( Edge_Provider::is_off( $detected ) ) {
			return $base;
		}
		if ( Edge_Provider::NONE === $detected['confidence']
			&& ! in_array( $reason, self::HOLD_WITHOUT_EVIDENCE, true ) ) {
			return $base;
		}

		$hold = Edge_Provider::hold_headers( $detected['provider'] );

		/**
		 * Filter: xspeed_edge_hold_headers
		 *
		 * The last word on what a hold INSTRUCTS. Runs before sanitising, so
		 * a value that cannot be sent as a header is still dropped, and
		 * before `X-XSpeed-Edge-Hold` is added, so it cannot rewrite the
		 * reason xSpeed held the page for — that is a diagnosis, not an
		 * instruction, and a forged one sends a reader after the wrong
		 * module.
		 *
		 * @param array<string,string> $hold     Header name => value.
		 * @param array<string,string> $detected Provider, confidence, source.
		 * @param string               $reason   Why the hold fired.
		 * @param string               $context  `request`, `store` or `bake`.
		 */
		$hold = (array) apply_filters( 'xspeed_edge_hold_headers', $hold, $detected, $reason, $context );

		// A hold replaces the lifetime rather than sitting beside it: the two
		// describe the same response and would contradict each other. The
		// cache tag survives, because a later purge still has to be able to
		// name whatever the edge picked up on its own terms.
		if ( isset( $base['Cache-Tag'] ) ) {
			$hold['Cache-Tag'] = $base['Cache-Tag'];
		}

		// Never argue with a stronger answer WordPress already gave. It sends
		// `no-store, private` of its own accord on a logged-in, 404 or
		// password-protected response, from WP::send_headers() — which runs
		// before template_redirect, so it is already on the wire by the time
		// we get here. Ours is the weaker statement of the two; replacing it
		// would be a downgrade dressed as a fix. Only meaningful per request:
		// a bake has no response to inspect.
		if ( 'request' === $context && isset( $hold['Cache-Control'] ) && self::cache_control_already_stronger() ) {
			unset( $hold['Cache-Control'] );
		}

		// A page we refused to cache must not carry a validator either. A
		// `Last-Modified` left on it invites a conditional request, and a
		// shared cache that gets a 304 back serves the copy it should not
		// have stored. Only on a bypass, and only per request: a MISS is
		// about to be stored by us, so its validator is ours to keep.
		if ( 'request' === $context && 'bypass' === $reason && ! headers_sent() ) {
			header_remove( 'Last-Modified' );
		}

		$hold = self::sanitize_edge_headers( $hold );

		// Name the reason in the hold set itself, rather than sending it
		// separately from mark().
		//
		// "Why is my page not being cached at the edge?" is the question this
		// answers, and mark() could only answer it on the PHP serve path. The
		// other emitters send whatever this function returns and never ran
		// mark() at all — so the responses hardest to explain went out
		// carrying `no-store` with nothing beside it to say why. Chiefly the
		// drop-in, which serves from the `.meta` sidecar written under
		// `store` and from the literal baked under `bake`, before plugins
		// load and with no way to re-ask (the symptom
		// edge_optimization_pending() describes above).
		//
		// The nginx and Apache blocks are a third path in principle and
		// almost never in practice: they are only installed when
		// static_rewrite_allowed() is true, and the one reason a stock site
		// can hold under `bake` is `mobile-split`, which is exactly what
		// makes that false. They will carry it where a site forces a hold
		// through `xspeed_edge_hold_reason`, and otherwise have no hold to
		// carry.
		//
		// Added AFTER sanitising and banned in NEVER_AN_EDGE_HEADER, so
		// neither of the two filters above can forge a reason or suppress the
		// real one.
		//
		// Reduced to the slug CHARACTER CLASS, not checked against the five
		// slugs: `xspeed_edge_hold_reason` is documented as able to force a
		// reason, and a site that forces its own deserves to see it. What is
		// not negotiable is the shape, because this value reaches an
		// .htaccess and an nginx conf as well as a response header — so no
		// CR/LF, no `$`, no `%`, no `\`, and a length a config file can hold.
		$slug = preg_replace( '/[^a-z0-9-]/', '', strtolower( $reason ) );
		if ( is_string( $slug ) && '' !== $slug ) {
			$hold['X-XSpeed-Edge-Hold'] = substr( $slug, 0, 32 );
		}

		return $hold;
	}

	/** Has something already sent a Cache-Control at least as strict as ours? */
	private static function cache_control_already_stronger(): bool {
		foreach ( headers_list() as $line ) {
			if ( 0 !== stripos( $line, 'cache-control:' ) ) {
				continue;
			}
			if ( preg_match( '/\b(?:no-store|private)\b/i', $line ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Edge headers that belong to THIS page rather than to every page.
	 *
	 * `edge_headers_for('HIT','bake')` is the answer frozen into the drop-in
	 * and the server rules: one set, serving the whole site. But the answer
	 * for one URL can legitimately differ — a page whose deferred work is
	 * still outstanding, say — and that answer has nowhere to live, because
	 * the baked set is all the fast paths know about.
	 *
	 * So ask again in a `store` context, with the request still in scope, and
	 * return the pairs only when they differ from the baked ones. Identical is
	 * the overwhelmingly common case and writes nothing: pages do not pay a
	 * sidecar for an answer the drop-in already has.
	 *
	 * Memoised because two callers ask within one store — the sidecar writer
	 * and the static-tree guard — and the filters behind it are not required
	 * to be cheap.
	 *
	 * @return array<string,string> Empty when this page needs no override.
	 */
	private static function per_entry_edge_headers(): array {
		if ( is_array( self::$per_entry_edge ) ) {
			return self::$per_entry_edge;
		}
		$baked                = self::edge_headers_for( 'HIT', 'bake' );
		$request              = self::edge_headers_for( 'HIT', 'store' );
		self::$per_entry_edge = ( $request === $baked ) ? array() : $request;

		return self::$per_entry_edge;
	}

	/**
	 * Render baked pairs as a PHP array literal for the drop-in.
	 *
	 * Single-quoted literals with quotes escaped, because the result is
	 * written into a PHP file that must still parse. Values reaching here
	 * have already been through sanitize_edge_headers(), so neither name nor
	 * value can carry a newline.
	 *
	 * @param array<string,string> $headers Name => value.
	 */
	private static function edge_headers_literal( array $headers ): string {
		if ( array() === $headers ) {
			return 'array()';
		}
		// var_export(), not hand-rolled quoting. A single-quoted PHP string
		// escapes BOTH `'` and `\\`, and escaping only the first is how a
		// value ending in a backslash — `X-Foo: C:\path\` from the custom
		// headers box — leaves the literal unterminated. That file is
		// included on every request once WP_CACHE is on, so the result is a
		// parse error on the front end AND in wp-admin, with no way back
		// except deleting the file over SSH.
		$parts = array();
		foreach ( $headers as $name => $value ) {
			$parts[] = var_export( (string) $name, true ) . ' => ' . var_export( (string) $value, true );
		}

		return 'array( ' . implode( ', ', $parts ) . ' )';
	}

	/**
	 * Quote a header value for an nginx / Apache directive.
	 *
	 * Both accept a double-quoted string with backslash escapes, and both
	 * refuse to load a config where the quoting is wrong — a mis-escaped
	 * value takes the whole vhost down, not just this header.
	 */
	private static function quote_directive_value( string $value ): string {
		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
	}

	/**
	 * The same directive twice — once per name Apache can expose the
	 * rewrite's environment variable under.
	 *
	 * `RewriteRule ... [E=XSPEED_STATIC_HIT:1]` in a per-directory context is
	 * an INTERNAL REDIRECT: Apache re-enters the request with the substituted
	 * path, and every variable set on the first pass is renamed with a
	 * `REDIRECT_` prefix for the second. `env=XSPEED_STATIC_HIT` is evaluated
	 * on that second pass, where nothing answers to that name any more, so
	 * the directive never fires — dropping the headers from precisely the
	 * responses they exist for.
	 *
	 * It cannot be written once: `env=` takes a single name with no
	 * alternation, and `expr=` — which could express both — is not dependable
	 * on LiteSpeed, which reads this same block. So both are emitted; the one
	 * whose variable is unset on a given pass does nothing.
	 *
	 * @param string $directive The directive, without its `env=` clause.
	 * @return string[]
	 */
	private static function static_hit_directives( string $directive ): array {
		return array(
			$directive . ' env=XSPEED_STATIC_HIT',
			$directive . ' env=REDIRECT_XSPEED_STATIC_HIT',
		);
	}

	/**
	 * Keep only pairs that can be sent as a header verbatim.
	 *
	 * These values reach three different emitters — PHP's header(), an nginx
	 * `add_header` and an Apache `Header always set` — so a name with a space
	 * or a value carrying CR/LF is not merely malformed, it is a
	 * response-splitting vector in the first and a broken server config in
	 * the other two. Names must be token-shaped; values lose CR/LF and are
	 * dropped if nothing survives.
	 *
	 * @param array<mixed,mixed> $headers Raw pairs.
	 * @return array<string,string>
	 */
	public static function sanitize_edge_headers( array $headers ): array {
		$clean = array();
		foreach ( $headers as $name => $value ) {
			// Never let one of these through, whoever asked. They describe the
			// transfer rather than the caching policy, and getting one wrong
			// from a settings field is a white screen: `Content-Encoding: gzip`
			// on an uncompressed body, a `Content-Length` that disagrees with
			// the bytes. `X-XSpeed-Cache` is ours and a second copy would lie
			// to whoever reads it.
			if ( is_string( $name ) && in_array( strtolower( $name ), self::NEVER_AN_EDGE_HEADER, true ) ) {
				continue;
			}
			// `\z`, not `$`: PCRE's `$` also matches immediately BEFORE a
			// trailing newline, so "Cache-Tag\n" passes a `$` check and gets
			// concatenated raw into the generated .htaccess — splitting one
			// Header directive across two lines, which is a syntax error
			// Apache reports as a 500 on every request while `httpd -t` stays
			// green (.htaccess is parsed per request, not at load).
			if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9-]+\z/', $name ) ) {
				continue;
			}
			if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
				continue;
			}
			$value = trim( str_replace( array( "\r", "\n" ), '', (string) $value ) );
			if ( '' === $value ) {
				continue;
			}
			// `$` is a variable reference in an nginx string and `%` is a
			// format tag to Apache's mod_headers, which rejects an
			// unrecognised one — in .htaccess that is a 500 on every request
			// while `httpd -t` still reports OK, because .htaccess is parsed
			// per request. `\` escapes the quote in the PHP literal baked into
			// the drop-in. None of them can be escaped reliably in all three
			// places at once, and nothing a cache reads needs any of them, so
			// the value is dropped rather than mangled.
			if ( preg_match( '/[$%\\\\]/', $value ) ) {
				continue;
			}
			$clean[ $name ] = $value;
		}

		return $clean;
	}

	/**
	 * Bypass gates that describe THE VISITOR rather than THIS REQUEST.
	 *
	 * Only these may be recorded in the bypass cookie. A visitor-scoped
	 * verdict stays true for the visitor's next request — they are still
	 * logged in, still hold a cart cookie — so the web server can act on
	 * it without booting PHP.
	 *
	 * Every other gate describes the request in front of us: its method,
	 * its URL, its query string, the client's user agent. Persisting one
	 * of those pins a visitor to the uncached path over a property that
	 * was never theirs to begin with. (#218)
	 */
	private const VISITOR_SCOPED_BYPASS = array( 'logged-in', 'excluded-cookie' );

	/**
	 * Whether $reason describes the visitor (persist it) or merely this
	 * request (don't).
	 *
	 * Split out as a pure function because it is the whole decision behind
	 * the bypass cookie, and the cookie write itself (setcookie()) can't be
	 * asserted in a unit test.
	 */
	public static function bypass_is_visitor_scoped( string $reason ): bool {
		return in_array( $reason, self::VISITOR_SCOPED_BYPASS, true );
	}

	public function maybe_start_cache() {
		if ( ! self::should_cache() ) {
			// PHP has just evaluated the FULL exclusion rule list — including
			// the `~regex` patterns the server config can't express — and
			// decided this response must not be served from cache. Record that
			// verdict in the conventional bypass cookie so the web server can
			// enforce it on subsequent requests without starting PHP.
			//
			// This is what stops most settings changes from needing an nginx
			// reload: the config tests one fixed cookie name forever, and the
			// rule list behind it can change freely.
			//
			// But ONLY when the verdict is about the visitor. A request-shape
			// gate — `non-get` above all — says nothing about who is asking,
			// and persisting it pinned that visitor to the uncached path for
			// the rest of their session: one search-form POST, one comment,
			// one `curl -I` from an uptime monitor, and every later GET
			// bypassed. It could not self-heal either, because the bypass
			// cookie is itself in excluded_cookies, so the next GET bypassed
			// with `excluded-cookie` and landed right back here, where
			// sync_bypass_cookie()'s no-change short-circuit left the cookie
			// exactly where it was. (#218)
			if ( self::bypass_is_visitor_scoped( self::bypass_reason() ) ) {
				self::sync_bypass_cookie( true );
			}
			return;
		}

		// Cacheable: clear any stale bypass cookie, or a visitor who once
		// had a cart would keep skipping the fast path long after checkout.
		self::sync_bypass_cookie( false );

		$key  = self::cache_key();
		$file = self::cache_file_for( $key );

		if ( file_exists( $file ) && ! self::is_expired( $file ) ) {
			Hit_Counter::record_hit();
			// Emit the HIT marker on THIS path too. The drop-in
			// (advanced-cache.php) sends "HIT (php)" and the nginx static
			// rewrite sends "HIT (nginx)", but this template_redirect
			// serve path — the one that runs when the drop-in isn't loaded
			// (e.g. WP_CACHE not true) — previously streamed the cached
			// file with NO marker, so a genuine HIT looked like a MISS in
			// the response headers. Same header + value as the drop-in.
			self::mark( 'HIT (php)' );
			// Replay stored response bits so the HIT matches the original:
			// a non-HTML Content-Type (cached feeds, sitemaps) and a non-200
			// status (a cached 404 must serve 404, not 200). No-op for
			// ordinary pages, which write no .meta.
			$meta = self::read_meta( $key );
			if ( ! headers_sent() ) {
				if ( ! empty( $meta['status'] ) && function_exists( 'http_response_code' ) ) {
					http_response_code( (int) $meta['status'] );
				}
				if ( ! empty( $meta['content_type'] ) && is_string( $meta['content_type'] ) ) {
					header( 'Content-Type: ' . $meta['content_type'] );
				}
				// Conditional GET: emit Last-Modified + ETag and answer a
				// matching If-Modified-Since / If-None-Match with 304 so
				// aggregators (and browsers) skip re-downloading an unchanged
				// cached response — the bandwidth win feeds are about.
				// (FBS-82407 #5)
				if ( self::serve_not_modified( $file ) ) {
					exit; // 304 sent, no body.
				}
			}
			// Serve the precompressed Brotli sibling when the client accepts
			// it (an add-on, the Pro Brotli module, wrote <file>.br). On this
			// PHP serve path the web server never sees the .br, so without
			// this a br-capable client got the plain .html — precompression
			// did nothing here. Falls through to plain readfile otherwise.
			$br = self::maybe_serve_brotli( $file );
			if ( null !== $br ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a static cache file directly; WP_Filesystem would buffer through PHP memory and is not appropriate for response streaming.
				readfile( $br );
				exit;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- readfile is optimal for streaming a static cache file directly to the visitor; WP_Filesystem would buffer through PHP memory and is not appropriate for response streaming.
			readfile( $file );
			exit;
		}

		// Cache miss → render fresh + write cache. On LiteSpeed we send an
		// explicit "stand down" header so the server's LSCache module does
		// NOT cache + shadow our response — xSpeed's own .htaccess static
		// rewrite owns hit serving (and hit accounting) here, exactly as on
		// Apache. See maybe_emit_lscache_headers() for the full rationale.
		self::maybe_emit_lscache_headers();

		// We're about to render fresh + cache → miss for this request.
		// …UNLESS this request is a 404 or a known bot/scanner. Those reach the
		// render path too, but counting them as cache misses makes the ratio
		// meaningless — a wave of `/wp-x7.php` scanner 404s reads as a collapsing
		// cache when nothing is wrong. Runs at template_redirect (priority 0), so
		// is_404() is already resolved. Excluded requests are tallied separately
		// for the "you absorbed N scanner hits" line, not dropped. (#118)
		if ( self::miss_is_excluded() ) {
			Hit_Counter::record_excluded();
		} else {
			Hit_Counter::record_miss();
		}

		// Stamp it, so "eligible but not cached yet" is visibly different
		// from "deliberately bypassed" (issue #10). Headers can't be sent
		// after the body starts, so this has to happen here, not in
		// finalize_buffer() — nothing has been output at template_redirect.
		self::mark( 'MISS' );


		// WP < 6.9 fallback: ob_start() with a callback, paired with an
		// explicit shutdown close so the buffer lifecycle is visible to
		// reviewers and Plugin Check, instead of relying on PHP's implicit
		// request-end flush. We record our nesting level so close_buffer()
		// flushes ONLY the buffer we opened.
		ob_start( array( __CLASS__, 'finalize_buffer' ) );
		self::$buffer_level = ob_get_level();

		add_action( 'shutdown', array( __CLASS__, 'close_buffer' ), 0 );
	}

	/**
	 * Close the cache buffer opened by maybe_start_cache().
	 *
	 * Guarded by the recorded buffer level so we never flush a buffer that
	 * another plugin pushed on top of (or under) ours. If something else is
	 * currently on top, we leave the stack alone — PHP's shutdown sequence
	 * will unwind buffers in order and our finalize_buffer() callback will
	 * still run when our level becomes the topmost one.
	 */
	public static function close_buffer() {
		if ( null === self::$buffer_level ) {
			return;
		}
		if ( ob_get_level() === self::$buffer_level ) {
			ob_end_flush();
		}
		self::$buffer_level = null;
	}

	/**
	 * Are we buffering this request?
	 *
	 * Asked by Css_Combine_Buffer, which needs the finished HTML but must not
	 * open a second buffer when this one is already going to hand it the page
	 * through `xspeed_cache_final_html`. False here means the request is not
	 * cacheable — cache off, excluded URL, logged in — and the combiner has to
	 * provide its own buffer or it silently stops working. (#195)
	 */
	public static function is_buffering(): bool {
		return null !== self::$buffer_level;
	}

	/**
	 * Is a render-time translation plugin going to wrap our output buffer?
	 *
	 * TranslatePress opens its translation buffer on `init` priority 0. We
	 * open ours on `template_redirect`, which runs much later, so ours nests
	 * INSIDE theirs. PHP unwinds output buffers LIFO — innermost callback
	 * first — so `finalize_buffer()` saw the raw, pre-translation HTML and
	 * cached that, while the live visitor still got the translated bytes from
	 * TRP's outer buffer.
	 *
	 * Result: the first (MISS) visitor to /fr/some-page/ got correct French;
	 * every visitor after got English body text under a `lang="fr-FR"`
	 * document, plus TRP's internal `#TRPLINKPROCESSED` link markers, which
	 * TRP strips at the very end of its own buffer and which therefore leak
	 * into anything captured from inside it.
	 *
	 * Note the ordering cannot be fixed from TRP's side: its
	 * `trp_start_output_buffer_priority` filter only moves the PRIORITY on
	 * `init`, and `init` always fires before `template_redirect` whatever the
	 * priority. The buffer that has to move is ours.
	 *
	 * Detected by main class rather than plugin path, so a renamed directory
	 * or a bundled copy still matches.
	 */
	public static function translation_plugin_active(): bool {
		$active = class_exists( 'TRP_Translate_Press' );

		/**
		 * Whether to treat this request as wrapped by a translation buffer.
		 *
		 * Lets a site add another render-time translation plugin (or opt out)
		 * without patching the engine.
		 *
		 * @param bool $active
		 */
		return (bool) apply_filters( 'xspeed_translation_plugin_active', $active );
	}

	/**
	 * Write the cache file for a request whose output was wrapped by a
	 * render-time translation plugin.
	 *
	 * Registered as a PHP shutdown function (not a WP `shutdown` action) so
	 * it runs after PHP has unwound the output-buffer stack — by which point
	 * the translation plugin's callback has transformed the bytes and its
	 * internal markers are gone.
	 *
	 * finalize_buffer() has already applied the status gate, the
	 * xspeed_cache_final_html filter and HTML minification to the
	 * untranslated copy and then declined to write it. Here we re-run only
	 * what's needed on the translated bytes: minify, write, and fire the
	 * same downstream hooks so Brotli / static-tree listeners behave
	 * identically to the ordinary path.
	 */
	public static function write_deferred_translated_cache(): void {
		$key                = self::$deferred_key;
		self::$deferred_key = null;

		// Release the collected bytes BEFORE the early return, so the static
		// is cleared on every path rather than only when a key survived.
		$full                    = self::$translated_output;
		self::$translated_output = '';

		$completed              = self::$render_completed;
		self::$render_completed = false;

		if ( null === $key ) {
			return;
		}

		// Did the render actually finish?
		//
		// This runs as a PHP shutdown function, which fires after a wp_die()
		// or a bare exit() just as readily as after a clean render — but in
		// those cases finalize_buffer() never returned, so the bytes we hold
		// are a page that was cut off partway through. The length and
		// TRPLINKPROCESSED checks below don't catch that: a fatal after the
		// footer's translated markup is both over 255 bytes and free of TRP
		// markers, i.e. truncated but entirely plausible. Caching it would
		// freeze a half-rendered page under the real key for the full TTL.
		//
		// Serving this one URL uncached is the cheap failure; the corrupt
		// cache entry is the expensive one.
		if ( ! $completed ) {
			return;
		}

		if ( strlen( $full ) < 255 ) {
			return;
		}

		// Refuse to cache a copy still carrying the translation plugin's
		// internal link markers. TRP strips these at the very end of its own
		// buffer, so their presence means we captured too early — and a
		// cached page containing them is SEO-visible damage. Better to serve
		// this URL uncached than to freeze broken markup for the full TTL.
		if ( false !== strpos( $full, 'TRPLINKPROCESSED' ) ) {
			return;
		}

		$minify_opts = Settings_Manager::get( 'minify' );
		if ( ! empty( $minify_opts['minify_html'] ) ) {
			$full = Minifier::minify_html( $full );
		}
		$full = self::signed( $full );

		// Per-site directory: on multisite every blog shares this tree, so
		// entries are bucketed by host to keep one site's purge from
		// sweeping the whole network. (#6)
		self::ensure_host_dir();

		// Never author a cache entry from a request that carried a query
		// string: cache_key() files it under the BARE url, so the params'
		// render would be served to every clean-URL visitor (#241).
		if ( self::query_string_blocks_write() ) {
			return;
		}

		$file = self::cache_file_for( $key );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- WP_Filesystem requires admin context for credentials; this runs on a frontend shutdown where it's unavailable.
		file_put_contents( $file, $full, LOCK_EX );

		/** This action is documented in includes/class-cache.php */
		do_action( 'xspeed_flat_file_written', $file, $full );

		self::write_meta( $key, $full );

		// Static tree too, under the same gates finalize_buffer() applies —
		// otherwise deferring the write would silently cost translated pages
		// the web-server fast path and leave them on the slower drop-in.
		// The static tree cannot replay a sidecar. A file served straight by
		// the web server carries the headers baked into the rule that serves
		// the whole site — the very answer this entry exists because it
		// disagreed with. Same reasoning as the status and content-type
		// cases: what the fast path cannot replay belongs on the drop-in path.
		if ( self::static_rewrite_allowed()
			&& self::response_is_plain_html()
			&& array() === self::per_entry_edge_headers() ) {
			self::store_static( $full );
		}
	}

	public static function should_cache() {
		// Reset first: a single request only reaches this once (the sole
		// caller is maybe_start_cache()), but tests and any future caller
		// must never inherit the previous request's verdict.
		self::$status_header  = '';
		self::$bypass_reason  = '';
		self::$edge_headers   = array();
		self::$per_entry_edge = null;
		// Under PHP-FPM a process serves one request and this is moot. Under
		// a persistent worker runtime it is not: without it, an answer
		// resolved from one visitor's forgeable headers would be reused for
		// every later request the worker handles.
		Edge_Provider::forget();

		$opts = Settings::get();
		if ( empty( $opts['cache_enabled'] ) ) {
			return self::bypass( 'cache-disabled' );
		}

		if ( is_user_logged_in() ) {
			return self::bypass( 'logged-in' );
		}

		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return self::bypass( 'non-frontend' );
		}

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return self::bypass( 'donotcachepage' );
		}

		// All exclusion knobs now owned by CacheModule.
		$cache_opts = Settings_Manager::get( 'cache' );

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'GET' !== $method ) {
			return self::bypass( 'non-get' );
		}

		// Search-results requests carry a `s` query param, which the
		// query-string gate below would normally reject as "dynamic". An
		// add-on (xspeed-pro search cache) can opt them in: when this is a
		// genuine is_search() and the filter returns true, the `s` param is
		// treated as cacheable (the search term goes into the cache key so
		// different searches stay distinct — see cache_key()).
		$cache_search = self::should_cache_search();

		// Feed opt-in is resolved BEFORE the query-string gate so query-form
		// feeds (/?feed=rss2, used on plain-permalink sites) aren't rejected
		// as "dynamic" by that gate — the `feed` param is then allowed through
		// just like the search `s` param. Feeds are excluded by default (the
		// `/feed/` pattern in excluded_urls); an add-on (xspeed-pro feed cache)
		// opts them back in via the filter. (FBS-82407 #4)
		$is_feed_request = function_exists( 'is_feed' ) && is_feed();
		/**
		 * Whether to cache the current feed request.
		 *
		 * Default false → feeds fall through to the normal URL-exclusion
		 * rules (so `/feed/` keeps them out). A listener returning true
		 * opts this feed request into caching.
		 *
		 * @param bool $cache_feed Whether to cache this feed request.
		 */
		$cache_feed = $is_feed_request && (bool) apply_filters( 'xspeed_should_cache_feed', false );

		// WordPress's virtual robots.txt (and virtual favicon) are not HTML:
		// caching one runs it through the whole HTML pipeline, which stamped
		// the footer comment onto text/plain and let HTML minification
		// collapse robots.txt to a single line — a line-based format, so
		// every directive after the first was lost and crawlers read an
		// invalid file. No opt-in filter here: there is no correct way to
		// treat these as pages. (Reported live on a customer site.)
		if ( ( function_exists( 'is_robots' ) && is_robots() )
			|| ( function_exists( 'is_favicon' ) && is_favicon() ) ) {
			return self::bypass( 'non-html' );
		}

		// Query string handling: anything OUTSIDE the ignored-params
		// allow-list (utm_*, fbclid, gclid by default) means a unique
		// request that we don't want to share with the canonical cache
		// entry. Skip cache rather than poison the key.
		//
		// Parse the RAW query string, NOT a sanitize_text_field() copy:
		// that filter strips percent-encoded octets (%XX), so `?%73=…`
		// would lose its `s` key here while WordPress still decodes it to
		// a search request — the gate would wave the request through and
		// cache_key() would file the search page under the bare URL,
		// letting an attacker poison the homepage cache with `/?%73=<spam>`.
		// parse_str() does its own urldecoding, matching WP's own parse, and
		// only the KEYS are used below (fed to Glob_Matcher → preg_match,
		// never echoed or executed), so no sanitization is needed here.
		$query_raw = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see note above: parse_str() urldecodes to match WP; only keys are consumed, via preg_match, never output.
		if ( '' !== $query_raw ) {
			$ignored = is_array( $cache_opts['ignored_query_params'] ?? null ) ? $cache_opts['ignored_query_params'] : array();
			parse_str( $query_raw, $params );
			foreach ( $params as $key => $_ ) {
				// Allow the search param through when search caching is on.
				if ( $cache_search && 's' === $key ) {
					continue;
				}
				// Allow query-form feed params through when feed caching opted
				// this request in (?feed=rss2 / &withcomments=1 on feeds).
				if ( $cache_feed && in_array( $key, array( 'feed', 'withcomments', 'withoutcomments' ), true ) ) {
					continue;
				}
				if ( ! self::query_key_is_ignored( (string) $key, $ignored ) ) {
					// Slug only — never the param name, which is attacker-
					// controlled and would be reflected into a header.
					return self::bypass( 'query-param' );
				}
			}
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = (string) strtok( $request_uri, '?' );

		$excluded_urls = is_array( $cache_opts['excluded_urls'] ?? null ) ? $cache_opts['excluded_urls'] : array();
		if ( ! $cache_feed && Glob_Matcher::any_match( $excluded_urls, $path ) ) {
			return self::bypass( 'excluded-url' );
		}

		// Cookie-based exclusion. We only check cookie NAMES (matching
		// values would leak content-sensitive logic into the cache key
		// rules); presence of any matching cookie name skips cache.
		$excluded_cookies = is_array( $cache_opts['excluded_cookies'] ?? null ) ? $cache_opts['excluded_cookies'] : array();
		if ( ! empty( $excluded_cookies ) && ! empty( $_COOKIE ) ) {
			foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
				// Our own bypass cookie is a RECORD of a previous verdict, not
				// evidence about this visitor, so it never gets a vote here.
				// Letting it match made the verdict self-confirming: once set,
				// it produced `excluded-cookie` forever, which re-set it, and
				// no later request could ever re-evaluate the visitor on the
				// rules that actually describe them. The web server still acts
				// on the cookie without booting PHP; when PHP does boot it is
				// authoritative and re-decides from scratch. (#218)
				if ( Server_Rules::BYPASS_COOKIE === $cookie_name ) {
					continue;
				}
				if ( Glob_Matcher::any_match( $excluded_cookies, (string) $cookie_name ) ) {
					return self::bypass( 'excluded-cookie' );
				}
			}
		}

		// User-agent bypass list. Substring match (not glob) since UA
		// strings have so much variation that glob anchoring rarely
		// helps and confuses users.
		$bypass_uas = is_array( $cache_opts['bypass_user_agents'] ?? null ) ? $cache_opts['bypass_user_agents'] : array();
		if ( ! empty( $bypass_uas ) ) {
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			foreach ( $bypass_uas as $needle ) {
				if ( '' !== $needle && false !== stripos( $ua, (string) $needle ) ) {
					return self::bypass( 'user-agent' );
				}
			}
		}

		// Per-post override (Phase 3.4). Honored only on singular
		// post-context requests — archives / 404s / taxonomies use the
		// global policy above.
		if ( Cache_Rules::should_skip_for_post( Cache_Rules::current_post_id() ) ) {
			return self::bypass( 'post-excluded' );
		}

		/**
		 * Final say on whether the current request is cacheable.
		 *
		 * Runs at template_redirect (full WP context), so listeners may use
		 * conditional tags (is_search(), is_feed(), is_404(),
		 * wp_is_maintenance_mode(), …). The core engine has already applied
		 * its own exclusion rules and reached `true`; a listener returning
		 * false vetoes caching for this request. This is the documented
		 * extension point add-ons (xspeed-pro) hook to add their own
		 * request-level cache policy without forking the engine.
		 *
		 * Note: this gates the WRITE side. The pre-WP drop-in
		 * (advanced-cache.php) cannot run PHP filters, so request types that
		 * must never be *served* from a stale file are handled by not
		 * writing them here and/or by purging — see the conflict notes in
		 * advanced-cache.php.
		 *
		 * @param bool $should_cache Whether to cache the current request.
		 */
		if ( ! apply_filters( 'xspeed_should_cache', true ) ) {
			// One slug for every listener — a third-party callback name is
			// not ours to put in a response header. Which listener vetoed is
			// a WP_DEBUG-level question the filter itself can answer.
			return self::bypass( 'filtered' );
		}

		return true;
	}

	/**
	 * Whether the current request is a 404 we may cache.
	 *
	 * True only when: it's a genuine main-query is_404(), an add-on opted
	 * in via `xspeed_should_cache_404` (default false), and the request
	 * isn't a transient 404 we must never freeze — maintenance mode or a
	 * 404 emitted while the DB/site is in an error state. The xspeed-pro
	 * 404 cache flips the filter; Free never caches 404s on its own.
	 */
	public static function should_cache_404(): bool {
		if ( ! function_exists( 'is_404' ) || ! is_404() ) {
			return false;
		}
		// Never cache a 404 served because the site is down for
		// maintenance — that screen disappears the moment maintenance
		// ends, and a cached copy would outlive it.
		if ( function_exists( 'wp_is_maintenance_mode' ) && wp_is_maintenance_mode() ) {
			return false;
		}

		/**
		 * Whether to cache the current 404 response.
		 *
		 * Default false. A listener returning true opts the (genuine)
		 * 404 into the page cache, served back for any unknown URL under
		 * one generic key. The 404 status is preserved on the HIT.
		 *
		 * @param bool $cache_404 Whether to cache this 404.
		 */
		return (bool) apply_filters( 'xspeed_should_cache_404', false );
	}

	/**
	 * Whether the current request is an internal search-results page we
	 * may cache.
	 *
	 * True only when: it's a genuine main-query is_search() with a
	 * non-empty term, and an add-on opted in via `xspeed_should_cache_search`
	 * (default false). The search term is folded into the cache key (see
	 * search_term() / cache_key()) so different searches stay distinct.
	 * The xspeed-pro search cache flips the filter; Free never caches
	 * search results on its own.
	 */
	/**
	 * Whether this response was rendered for a query string and therefore
	 * must not be STORED under the bare-URL key.
	 *
	 * should_cache() lets a request through when every key is on the
	 * `ignored_query_params` allow-list, and cache_key() then drops the
	 * query string so `/post` and `/post?utm_source=x` share one entry.
	 * Sharing on READ is the point of the allow-list and stays. Sharing on
	 * WRITE is a cache-poisoning vector: the response was rendered *with*
	 * those params, and WordPress reflects REQUEST_URI into form actions,
	 * share links, canonical helpers and plugin smart tags. One anonymous
	 * GET to a cold URL therefore freezes an attacker-chosen variant under
	 * the clean URL's key, served for the whole TTL by the drop-in and by
	 * the web server — neither of which runs these checks (issue #241).
	 *
	 * The allow-list keeps its benefit: a visitor arriving on
	 * `?utm_source=…` is still SERVED the canonical cached entry. Only the
	 * write is skipped, so the entry is authored by a clean request.
	 *
	 * This is the same reasoning as the `should_cache_search()` guard in
	 * store_static() (#191), generalised to the allow-listed params.
	 */
	public static function request_has_query_string(): bool {
		$query = isset( $_SERVER['QUERY_STRING'] )
			? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only tested for emptiness; never echoed, stored or used as a path.
			: '';

		return '' !== trim( $query );
	}

	/**
	 * Would authoring a cache entry from THIS request file a query-string
	 * render under the bare URL?
	 *
	 * The one predicate both write sites ask, so they cannot drift.
	 *
	 * Two shapes are exempt because cache_key() does NOT drop their query —
	 * it folds the distinguishing part into the key, so each variant gets
	 * its own entry and none is filed under the bare URL:
	 *
	 *   - searches, keyed by `|s=<term>` (#191)
	 *   - feeds, keyed by `|feed=<type>` — `/?feed=rss2` is the ONLY feed URL
	 *     core generates on plain permalinks, so treating it as poisonable
	 *     made feed caching a no-op on exactly the sites that need it
	 *
	 * @return bool True when the write must be skipped.
	 */
	public static function query_string_blocks_write(): bool {
		if ( ! self::request_has_query_string() ) {
			return false;
		}

		if ( self::should_cache_search() ) {
			return false;
		}

		// Feed caching is opt-in, via the same filter should_cache() reads
		// to admit the feed params in the first place.
		if ( function_exists( 'is_feed' ) && is_feed()
			&& (bool) apply_filters( 'xspeed_should_cache_feed', false )
		) {
			return false;
		}

		return true;
	}

	public static function should_cache_search(): bool {
		if ( ! function_exists( 'is_search' ) || ! is_search() ) {
			return false;
		}
		// Empty search (`?s=`) renders the same as a normal archive and
		// carries no term to key on — let it fall through to the usual
		// rules rather than caching an ambiguous entry.
		if ( '' === self::search_term() ) {
			return false;
		}

		/**
		 * Whether to cache the current search-results request.
		 *
		 * Default false. A listener returning true opts the search page
		 * into the cache, keyed by the normalized search term.
		 *
		 * @param bool $cache_search Whether to cache this search request.
		 */
		return (bool) apply_filters( 'xspeed_should_cache_search', false );
	}

	/**
	 * The current request's normalized search term, or '' if none. Reads
	 * the raw `s` query param (works on the pre-WP drop-in path too, where
	 * get_search_query() isn't available), trims + lowercases so
	 * "WordPress" and "wordpress" share one entry, and collapses internal
	 * whitespace.
	 */
	public static function search_term(): string {
		$raw = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache-key derivation from a public search param; no state change.
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		$raw = preg_replace( '/\s+/', ' ', $raw );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw ) : strtolower( $raw );
	}

	/**
	 * Is this query-string key on the ignored-params allow-list? Supports
	 * globs (`utm_*` matches `utm_source`, `utm_medium`, etc.) so users
	 * don't have to enumerate every UTM variant, and `~regex`.
	 *
	 * Matching is whole-name, not "contains" — a param name is an
	 * identifier, not a path. Under the old contains match the shipped
	 * default `ref` also swallowed `preference`, `product_ref` and
	 * `referrer`: those params were dropped from the cache key, so
	 * `/shop?preference=1` was served — and, on a cold entry, WRITTEN as —
	 * `/shop`. Same for `_ga` vs `_gallery`, and for the unanchored
	 * `~utm_…` default vs `my_utm_source`. A param name that is genuinely
	 * unknown now bypasses the cache, which is the safe direction.
	 */
	private static function query_key_is_ignored( string $key, array $ignored ): bool {
		return Glob_Matcher::any_match_name( $ignored, $key );
	}

	public static function cache_key() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : 'default';

		// Cacheable 404s share ONE generic per-host entry — keying them by
		// URL would let a scanner flood (millions of random paths) bloat
		// the cache with identical 404 bodies. Both the write and the HIT
		// lookup run through here, so they agree on the key automatically.
		if ( self::should_cache_404() ) {
			return md5( $host . '|404' );
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		// Strip the query string from the key so /post and /post?utm_*=…
		// share the same cache entry. should_cache() above already
		// rejected requests with non-ignored params, so by the time we
		// build the key the only params left are safe to drop.
		$uri = (string) strtok( $uri, '?' );

		// Optional device bucket: when mobile_separate is on, mobile and
		// desktop responses live in different cache files so themes that
		// serve different HTML by device (AMP, WPtouch, Jetpack mobile)
		// can't poison each other.
		$device = '';
		$opts   = Settings_Manager::get( 'cache' );
		if ( ! empty( $opts['mobile_separate'] ) ) {
			$device = self::is_mobile_request() ? '|m' : '|d';
		}

		// Search-results requests fold the normalized term into the key so
		// /?s=foo and /?s=bar get distinct entries (the query string is
		// otherwise stripped above). Only added when search caching opted
		// in, so non-search URLs are unaffected.
		$search = self::should_cache_search() ? '|s=' . self::search_term() : '';

		// Query-form feeds (/?feed=rss2 vs /?feed=atom) share the same path
		// once the query is stripped, so fold the feed type into the key to
		// keep the flavors distinct. Pretty-permalink feeds (/feed/rss/) carry
		// the type in $uri already and are unaffected. (FBS-82407 #4)
		$feed = '';
		if ( function_exists( 'is_feed' ) && is_feed() && function_exists( 'get_query_var' ) ) {
			$feed_type = (string) get_query_var( 'feed' );
			if ( '' !== $feed_type ) {
				$feed = '|feed=' . preg_replace( '/[^a-z0-9]/i', '', $feed_type );
			}
		}

		return md5( $host . $uri . $device . $search . $feed );
	}

	/**
	 * Server-side mobile detection. Prefers WordPress's `wp_is_mobile()`
	 * which uses the same UA tokens as core (so our bucket aligns with
	 * whatever theme-side branching uses). Falls back to a tiny inline
	 * detector if wp_is_mobile() isn't loaded (e.g. the drop-in path).
	 */
	private static function is_mobile_request(): bool {
		if ( function_exists( 'wp_is_mobile' ) ) {
			return (bool) wp_is_mobile();
		}
		// Fallback for the rare context where wp_is_mobile() isn't loaded.
		// Mirrors core's wp_is_mobile() EXACTLY — including the
		// Sec-CH-UA-Mobile client hint it checks *before* UA tokens — so the
		// bucket this picks matches whatever the engine's primary path (and
		// the drop-in's own copy of this logic) would pick for the same
		// request. Drift here re-introduces the cross-path key mismatch.
		if ( isset( $_SERVER['HTTP_SEC_CH_UA_MOBILE'] ) ) {
			return '?1' === $_SERVER['HTTP_SEC_CH_UA_MOBILE'];
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $ua ) {
			return false;
		}
		return (bool) preg_match( '/(Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi)/i', $ua );
	}

	/**
	 * Filesystem-safe directory name for a host, or '' when unusable.
	 *
	 * The charset MUST match the static tree (store_static()) and the
	 * drop-in's own copy, or the paths disagree about where an entry lives.
	 * The colon of `host:port` is stripped: it is legal in a Host header but
	 * not portable in a path.
	 *
	 * @param string $host Raw host, e.g. from HTTP_HOST.
	 * @return string Safe directory segment, or '' if nothing usable remains.
	 */
	/**
	 * The host segment of the STATIC tree — `xspeed-static/<host>/…`, which
	 * the web server resolves without PHP.
	 *
	 * Different from host_dir(): here the port is folded INTO the segment
	 * (`localhost:8080` → `localhost8080`) rather than dropped, because the
	 * generated server rules have to reproduce this from their own variables
	 * and nginx's `$host` has no port to drop — see the `$xspeed_host`
	 * derivation in nginx_snippet(). Shared by the write and the purge so the
	 * two can't drift; when they did, purging a page on a ported host deleted
	 * nothing and the stale copy kept being served by the rewrite.
	 */
	public static function static_host_dir( string $host ): string {
		return (string) preg_replace( '/[^a-zA-Z0-9.\-]/', '', $host );
	}

	public static function host_dir( string $host ): string {
		$host = str_replace( "\0", '', $host );
		// Drop the port BEFORE filtering, or `example.com:8080` collapses to
		// `example.com8080` — which both loses the boundary and could collide
		// with a real host of that name.
		$colon = strpos( $host, ':' );
		if ( false !== $colon ) {
			$host = substr( $host, 0, $colon );
		}
		$host = preg_replace( '/[^a-zA-Z0-9.\-]/', '', $host );
		// Collapse any run of dots so no traversal sequence can survive the
		// charset filter (`a/../b` would otherwise reduce to `a..b`).
		$host = preg_replace( '/\.{2,}/', '.', (string) $host );
		$host = trim( (string) $host, '.-' );
		return '' === $host ? '' : $host;
	}

	/**
	 * The per-site bucket a cache entry belongs to: `<host>` on a single
	 * site, `<host>/<path-prefix>` for a subdirectory multisite blog.
	 *
	 * On multisite every blog shares one cache directory, and a flat md5
	 * filename carries no clue which site wrote it — so purging one subsite
	 * swept the whole network cold. (#6)
	 *
	 * Host alone is NOT enough: a subdirectory network (the common layout)
	 * puts every blog on the same host, so `example.com/` and
	 * `example.com/siteb/` would share a bucket and keep purging each other.
	 * The path prefix is what separates them, and it is derivable from the
	 * REQUEST_URI alone — which matters because the drop-in must compute
	 * this identical value before WordPress (and get_blog_details()) exist.
	 *
	 * Subdomain and domain-mapped networks differ by host already, so they
	 * get a bare host bucket and are unaffected.
	 *
	 * @param string $host Raw host.
	 * @param string $uri  Raw REQUEST_URI (query string is ignored).
	 * @return string Bucket path, always non-empty.
	 */
	public static function site_bucket( string $host, string $uri ): string {
		$dir = self::host_dir( $host );
		if ( '' === $dir ) {
			$dir = 'default';
		}

		$prefix = self::site_path_prefix();
		return '' === $prefix ? $dir : $dir . '/' . $prefix;
	}

	/**
	 * The current blog's path prefix as a single safe segment ('' for the
	 * root blog or a non-multisite install). `/siteb/` becomes `siteb`;
	 * a nested `/a/b/` becomes `a-b` so the bucket stays one level deep.
	 *
	 * Written to a sidecar for the drop-in by sync_site_paths().
	 */
	public static function site_path_prefix(): string {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return '';
		}
		if ( function_exists( 'is_subdomain_install' ) && is_subdomain_install() ) {
			return ''; // Hosts already differ; no prefix needed.
		}
		$path = function_exists( 'get_blog_details' ) ? (string) get_blog_details()->path : '/';
		return self::path_prefix_segment( $path );
	}

	/**
	 * The bucket an arbitrary URL's cache entry lives in.
	 *
	 * `site_bucket()` answers for the CURRENT request; this answers for a URL
	 * that may belong to another blog entirely — which is what a per-URL purge
	 * is usually doing (WP-CLI, cron, the MCP tool, a network-admin action).
	 *
	 * The blog is resolved from the URL itself: on a subdirectory network
	 * `get_blog_details()` is asked which blog owns `<host><path>`, and its
	 * registered path becomes the prefix. Deriving the prefix from the URL's
	 * first path segment directly would be wrong — `/shop/` on the main blog
	 * is a page, not a subsite, and would send the purge into a bucket that
	 * does not exist. (QA B2 on #166)
	 *
	 * @param string $host Host of the URL being purged.
	 * @param string $path Path of the URL being purged.
	 * @return string Bucket path, always non-empty.
	 */
	public static function bucket_for_url( string $host, string $path ): string {
		$dir = self::host_dir( $host );
		if ( '' === $dir ) {
			$dir = 'default';
		}

		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return $dir;
		}
		if ( function_exists( 'is_subdomain_install' ) && is_subdomain_install() ) {
			return $dir; // Hosts already differ; no prefix.
		}
		if ( ! function_exists( 'get_blog_details' ) ) {
			return $dir;
		}

		// Longest registered blog path that prefixes this URL wins, so
		// `/one/2026/post/` resolves to blog `/one/` and not to the root blog.
		$blog = self::blog_for_path( $host, $path );
		if ( null === $blog ) {
			return $dir;
		}
		$prefix = self::path_prefix_segment( (string) $blog );
		return '' === $prefix ? $dir : $dir . '/' . $prefix;
	}

	/**
	 * The registered path of the blog that owns `<host><path>`, or null.
	 *
	 * Uses get_blog_details() with a domain/path pair rather than scanning
	 * every blog, so a large network costs one lookup per candidate segment
	 * instead of a full table read.
	 */
	private static function blog_for_path( string $host, string $path ): ?string {
		$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );

		// Try the longest candidate first: /a/b/ before /a/ before /.
		for ( $take = min( count( $segments ), 2 ); $take >= 1; $take-- ) {
			$candidate = '/' . implode( '/', array_slice( $segments, 0, $take ) ) . '/';
			$details   = get_blog_details(
				array(
					'domain' => $host,
					'path'   => $candidate,
				),
				false
			);
			if ( $details && ! empty( $details->path ) ) {
				return (string) $details->path;
			}
		}
		return null;
	}

	/**
	 * Normalise a blog path ('/', '/siteb/', '/a/b/') into a single
	 * filesystem-safe segment. Shared with the drop-in's copy.
	 */
	public static function path_prefix_segment( string $path ): string {
		$path = trim( str_replace( "\0", '', $path ), '/' );
		if ( '' === $path ) {
			return '';
		}
		$path = preg_replace( '/[^a-zA-Z0-9._\-\/]/', '', $path );
		$path = str_replace( '/', '-', (string) $path );
		return trim( (string) $path, '.-' );
	}

	/**
	 * The current blog's path as the static tree stores it — real slashes
	 * preserved, because that tree mirrors the URL
	 * (`xspeed-static/{host}{request_uri}/index.html`) rather than using a
	 * single flattened segment. '' for a root blog / single site.
	 */
	public static function site_path_raw(): string {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return '';
		}
		if ( function_exists( 'is_subdomain_install' ) && is_subdomain_install() ) {
			return '';
		}
		$path = function_exists( 'get_blog_details' ) ? (string) get_blog_details()->path : '/';
		$path = trim( str_replace( "\0", '', $path ), '/' );
		if ( '' === $path ) {
			return '';
		}
		$path = preg_replace( '#[^a-zA-Z0-9._\-/]#', '', $path );
		return trim( (string) $path, '/' );
	}

	/**
	 * Static-tree root for the current site: `<host>` plus the blog's real
	 * path. Mirrors store_static()'s layout so a scoped purge deletes
	 * exactly this blog's pages.
	 */
	public static function current_static_scope(): string {
		// Same switch_to_blog() caveat as current_host_dir() — see current_host().
		// Keep the port folded into the segment exactly as store_static() does.
		$dir = self::static_host_dir( self::current_host() );
		if ( '' === $dir ) {
			$dir = 'default';
		}
		$path = self::site_path_raw();
		return '' === $path ? $dir : $dir . '/' . $path;
	}

	/**
	 * The bucket for the CURRENT request. Never empty, so an entry is never
	 * written to the tree root (which is what the unscoped sweeps used to
	 * delete indiscriminately).
	 */
	public static function current_host_dir(): string {
		$host = self::current_host();
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return self::site_bucket( $host, $uri );
	}

	/**
	 * The host the CURRENT blog is served from.
	 *
	 * Deliberately NOT just $_SERVER['HTTP_HOST']: inside a
	 * switch_to_blog() the request header still names whichever site is
	 * serving the admin screen, while the cache entries we want belong to
	 * the switched-to blog. On a subdomain network the host IS the bucket,
	 * so reading the header there would make Pro's per-site "purge this
	 * site" button clear the network admin's own cache instead — the very
	 * bug this scoping exists to fix, surviving in one topology.
	 *
	 * get_blog_details() follows the switch, so prefer it whenever we are
	 * on multisite, and fall back to the request header otherwise.
	 */
	public static function current_host(): string {
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_blog_details' ) ) {
			$details = get_blog_details();
			if ( $details && ! empty( $details->domain ) ) {
				return (string) $details->domain;
			}
		}

		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		}

		/*
		 * No request header — WP-CLI, or WP-Cron driven by system cron.
		 *
		 * Returning '' here made the bucket resolve to the literal `default`
		 * while HTTP requests were writing to `<host>/`, so a scheduled purge
		 * swept an empty directory and reported success, and get_stats()
		 * reported 0 cached pages on a site with a full cache. That is the
		 * normal setup on any host running DISABLE_WP_CRON, which is most of
		 * them. Fall back to the site's own registered host. (QA D4 on #166)
		 */
		if ( function_exists( 'home_url' ) ) {
			$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( home_url( '/' ) ) : parse_url( home_url( '/' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- early-boot fallback only.
			if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
				return (string) $parts['host'];
			}
		}

		return '';
	}

	/**
	 * Ensure the current site's cache directory exists, with the silence
	 * index in both it and the shared root. Returns the directory.
	 */
	public static function ensure_host_dir(): string {
		$dir = XSPEED_CACHE_DIR . '/' . self::current_host_dir();
		if ( ! file_exists( XSPEED_CACHE_DIR ) ) {
			wp_mkdir_p( XSPEED_CACHE_DIR );
			self::write_silence( XSPEED_CACHE_DIR );
		}
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			self::write_silence( $dir );
		}
		return $dir;
	}

	public static function cache_file_for( $key ) {
		return XSPEED_CACHE_DIR . '/' . self::current_host_dir() . '/' . $key . '.html';
	}

	/**
	 * If a precompressed Brotli sibling (`<file>.br`) exists and the client
	 * advertises `Accept-Encoding: br`, emit the Brotli response headers and
	 * return the `.br` path to stream. Returns null to fall through to the
	 * plain file. Keeps the PHP serve path in parity with the web server's
	 * static .br serving (mod_brotli / ngx_brotli rewrite).
	 *
	 * Free has no Brotli logic of its own — this only fires when an add-on
	 * (the Pro Brotli module) actually wrote the .br, so it's a safe no-op
	 * on Free-only installs.
	 *
	 * @param string $file Absolute path to the cached .html file.
	 * @return string|null The .br path to stream, or null to serve $file.
	 */
	public static function maybe_serve_brotli( string $file ): ?string {
		if ( headers_sent() ) {
			return null;
		}
		$accept = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) )
			: '';
		// Match `br` as a token (comma/space delimited), not a substring, so
		// a hypothetical "xbr" encoding can't false-positive.
		if ( ! preg_match( '/(^|[\s,])br([\s,;]|$)/', $accept ) ) {
			return null;
		}
		$br = $file . '.br';
		if ( ! is_string( $br ) || ! file_exists( $br ) || ! is_readable( $br ) ) {
			return null;
		}
		if ( ! self::brotli_sibling_is_usable( $file, $br ) ) {
			return null; // fall through to the plain .html
		}
		header( 'Content-Encoding: br' );
		header( 'Vary: Accept-Encoding', false );
		// The byte length changes for the compressed body — drop any
		// Content-Length the caller may have set so the stream isn't
		// truncated/padded. readfile() lets the SAPI set the right length.
		header_remove( 'Content-Length' );
		return $br;
	}

	/**
	 * Is a precompressed `.br` sibling safe to serve?
	 *
	 * Existence is not enough. The sibling is written with a plain
	 * file_put_contents() — no atomic rename — so a crash, a full disk, or a
	 * read that races the write leaves a TRUNCATED file behind. Serving that
	 * with `Content-Encoding: br` hands the browser a stream it cannot
	 * inflate: it renders nothing at all (document.body is null) and the
	 * navigation can hang. A 16-byte .br for a 172KB page reproduces it
	 * exactly. (#286)
	 *
	 * Brotli has no magic number, and no byte-level marker distinguishes a
	 * truncated stream from a short valid one (the ISLAST bit is bit-packed,
	 * not byte-aligned). So this checks only what CAN be known by stat:
	 *
	 *  - Not empty. A zero-byte sibling is unambiguously broken.
	 *  - Not older than the HTML. A stale sibling would serve the PREVIOUS
	 *    revision of the page under the current entry's ETag.
	 *
	 * A size-RATIO floor was tried here and removed. Brotli's ratio is
	 * unbounded on repetitive input: a ~1 MB page of table rows or a product
	 * grid — the ordinary shape of a big generated page — compresses to
	 * about 0.04%, so a 2% floor rejected a perfectly good sibling and sent
	 * visitors the uncompressed page instead, silently. Measured: 963 KB of
	 * repeated markup → 89 bytes at q5 (0.009%). No floor can separate
	 * "impossibly small" from "extremely compressible" for arbitrary HTML.
	 *
	 * Truncation is prevented at the WRITE side instead — see
	 * write_atomic(), which the Brotli writer uses so a partial file is
	 * never visible under the final name. Detection at read time cannot be
	 * made correct; not creating the bad file can.
	 *
	 * Anything suspicious returns false and the caller streams the plain
	 * .html — slower, always correct. Serving an uninflatable body is worse
	 * than serving no compression at all.
	 *
	 * @param string $file Absolute path to the .html cache file.
	 * @param string $br   Absolute path to its .br sibling.
	 * @return bool True when the sibling may be served.
	 */
	/**
	 * Write a cache sidecar so a partial file is never visible.
	 *
	 * `file_put_contents()` truncates the target and then fills it, so any
	 * reader arriving mid-write — or any crash, full disk, or killed worker
	 * — leaves a SHORT file under the real name. For HTML that degrades to a
	 * clipped page; for a `.br` sibling it is worse, because a truncated
	 * brotli stream is not a short page but an UNINFLATABLE one: the browser
	 * renders nothing at all and the navigation can hang.
	 *
	 * Writing to a unique temp file in the same directory and renaming is
	 * atomic on POSIX, so readers see either the previous complete file or
	 * the new complete file, never a partial one. This is the half of #286
	 * that is actually fixable — a read-time heuristic cannot tell a
	 * truncated brotli stream from a very small valid one, but a truncated
	 * file that never becomes visible needs no detection.
	 *
	 * @param string $path     Absolute destination path.
	 * @param string $contents Bytes to write.
	 * @return bool True when the destination now holds exactly $contents.
	 */
	public static function write_atomic( string $path, string $contents ): bool {
		$dir = dirname( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem needs admin creds unavailable on a frontend cache write; this is our own cache dir.
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}

		// Same directory, so the rename stays on one filesystem — a rename
		// across devices is a copy and loses atomicity.
		$tmp = @tempnam( $dir, '.xspeed-tmp-' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failure returns false and the caller skips the write.
		if ( ! is_string( $tmp ) || '' === $tmp ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- WP_Filesystem needs admin creds unavailable on a frontend cache write; target is our own cache dir.
		$written = @file_put_contents( $tmp, $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- handled by the length check below.

		// A short write is exactly the failure this function exists to
		// prevent, so verify the byte count before publishing the file.
		if ( false === $written || $written !== strlen( $contents ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup of our own temp file; non-fatal.
			return false;
		}

		// tempnam() creates the file 0600; cache files must stay readable by
		// the web server, which may run as a different user.
		@chmod( $tmp, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged -- the web server may run as another uid and must be able to read the published file; a chmod failure is not fatal.

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- the atomic publish this function exists for; WP_Filesystem offers no atomic rename and needs admin creds.
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup; non-fatal.
			return false;
		}

		return true;
	}

	public static function brotli_sibling_is_usable( string $file, string $br ): bool {
		$br_size = (int) @filesize( $br ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a stat failure means "don't serve it", handled by the <= 0 check.
		if ( $br_size <= 0 ) {
			return false;
		}

		$html_size = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		if ( $html_size <= 0 ) {
			return false;
		}

		// A sibling older than the page it compresses is stale.
		$br_mtime   = (int) @filemtime( $br );   // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		$html_mtime = (int) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		if ( $br_mtime > 0 && $html_mtime > 0 && $br_mtime < $html_mtime ) {
			return false;
		}

		// The writer recorded how many bytes it produced. Where that record
		// exists, truncation is a certainty rather than an inference: a
		// stream shorter than its own declared length cannot inflate, and
		// one that matches was published whole. This is what a size ratio
		// could never be — brotli's ratio is unbounded on repetitive input,
		// so a 0.01% sibling of a generated page is genuinely valid.
		//
		// Absent for a sibling written before this version, or by an add-on
		// that writes the file directly. That case keeps the checks above
		// and no more, which is where a pre-existing truncated file on a
		// live site still slips through — write_atomic() stops NEW ones,
		// but it cannot retroactively vouch for what is already on disk.
		$expected = self::brotli_expected_size( $br );
		if ( $expected > 0 && $br_size !== $expected ) {
			return false;
		}

		return true;
	}

	/**
	 * Path of the sidecar recording a `.br` sibling's complete byte count.
	 *
	 * Kept beside the sibling as `<file>.html.br.size` rather than folded
	 * into the entry's `.meta`: the static tree the web server serves has no
	 * `.meta` at all, and the two trees must answer this question the same
	 * way. Every path that deletes a `.br` deletes this with it.
	 *
	 * @param string $br Absolute path to the `.br` sibling.
	 * @return string Absolute path to its size sidecar.
	 */
	public static function brotli_size_sidecar( string $br ): string {
		return $br . '.size';
	}

	/**
	 * The byte count the writer recorded for a `.br` sibling, or 0 when no
	 * record exists (a sibling predating this version, or written by an
	 * add-on that bypassed write_brotli_sibling()).
	 *
	 * @param string $br Absolute path to the `.br` sibling.
	 * @return int Expected size in bytes, or 0 when unknown.
	 */
	public static function brotli_expected_size( string $br ): int {
		$sidecar = self::brotli_size_sidecar( $br );
		if ( ! is_file( $sidecar ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own cache dir; WP_Filesystem needs admin creds unavailable on a frontend HIT.
		$raw = @file_get_contents( $sidecar ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable sidecar means "unknown", handled by the cast below.
		return max( 0, (int) trim( (string) $raw ) );
	}

	/**
	 * Publish a `.br` sibling together with the record of its own length.
	 *
	 * The single writer every producer of a `.br` should route through — the
	 * Pro Brotli module included. Publishing the body atomically stops a
	 * truncated file from ever becoming visible; recording the byte count
	 * lets the serve path prove wholeness for the files that already exist
	 * on disk when this ships.
	 *
	 * Order matters: the size sidecar is removed first and written last, so
	 * a reader arriving mid-update sees "no record" (checks above still
	 * apply) rather than the previous body's length against the new body.
	 *
	 * @param string $br       Absolute path to the `.br` sibling to write.
	 * @param string $contents Compressed bytes.
	 * @return bool True when both the sibling and its size record are in place.
	 */
	public static function write_brotli_sibling( string $br, string $contents ): bool {
		$sidecar = self::brotli_size_sidecar( $br );
		if ( is_file( $sidecar ) ) {
			wp_delete_file( $sidecar );
		}

		if ( ! self::write_atomic( $br, $contents ) ) {
			return false;
		}

		if ( self::write_atomic( $sidecar, (string) strlen( $contents ) ) ) {
			return true;
		}

		// The body landed but its length did not. That sibling is servable
		// and unguarded — exactly the file this function exists to prevent —
		// and the caller has no way to know. Withdraw it: a MISS costs one
		// uncompressed response, where an unguarded sibling can cost a blank
		// page for as long as the entry lives.
		wp_delete_file( $br );
		return false;
	}

	/**
	 * Sidecar metadata file for a cache entry. Holds response bits the HIT
	 * path must replay — Content-Type (cached feeds → application/rss+xml,
	 * sitemaps → text/xml) and status (a cached 404 must serve 404, not
	 * 200). JSON, one tiny file per entry, written only when there's
	 * something non-default to replay.
	 */
	public static function cache_meta_for( $key ) {
		return XSPEED_CACHE_DIR . '/' . self::current_host_dir() . '/' . $key . '.meta';
	}

	/**
	 * Read the .meta sidecar for a cache entry as an array, or [] if none.
	 * Keys: 'content_type' (string), 'status' (int), 'ttl' (int seconds).
	 * Used on the HIT path to replay content-type/status before streaming
	 * the file, and by Cache_GC to age an entry by its own TTL rather than
	 * the global one — hence public.
	 */
	public static function read_meta( $key ): array {
		$meta_file = self::cache_meta_for( $key );
		if ( ! file_exists( $meta_file ) ) {
			return array();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own cache dir; WP_Filesystem needs admin creds unavailable on a frontend HIT.
		$raw  = file_get_contents( $meta_file );
		$data = json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Conditional-GET support for a cache HIT. Emits Last-Modified + ETag
	 * derived from the cache file's mtime, and — when the request's
	 * If-Modified-Since / If-None-Match still match — sends 304 Not Modified
	 * and returns true (caller should exit without a body). Returns false to
	 * proceed with a normal 200 body. Lets aggregators/browsers skip
	 * re-downloading an unchanged cached response. (FBS-82407 #5)
	 *
	 * @param string $file Absolute path to the cache .html file.
	 * @return bool True when a 304 was sent.
	 */
	public static function serve_not_modified( string $file ): bool {
		$mtime = (int) filemtime( $file );
		if ( $mtime <= 0 ) {
			return false;
		}
		$last_modified = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';
		$etag          = '"' . md5( $file . '|' . $mtime ) . '"';
		header( 'Last-Modified: ' . $last_modified );
		header( 'ETag: ' . $etag );

		$ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) ) : '';
		$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';

		$etag_match = '' !== $inm && false !== strpos( $inm, $etag );
		$time_match = '' !== $ims && ( strtotime( $ims ) >= $mtime );

		if ( $etag_match || $time_match ) {
			if ( function_exists( 'http_response_code' ) ) {
				http_response_code( 304 );
			}
			return true;
		}
		return false;
	}

	public static function is_expired( $file ) {
		// cache_expiry now owned by CacheModule; per-post override
		// (Phase 3.4) shrinks the TTL further when the editor set one.
		$opts            = Settings_Manager::get( 'cache' );
		$max_age         = (int) $opts['cache_expiry'] * HOUR_IN_SECONDS;
		$post_override   = Cache_Rules::expiry_override_seconds_for_post( Cache_Rules::current_post_id() );
		if ( null !== $post_override ) {
			$max_age = $post_override;
		}

		/**
		 * Filter the max-age (seconds) for the current cache entry.
		 *
		 * Lets an add-on apply a request-type-specific TTL — e.g. the
		 * xspeed-pro feed cache gives feeds a longer expiry than pages,
		 * since aggregators tolerate more staleness. Return seconds.
		 *
		 * @param int $max_age Computed max-age in seconds.
		 */
		$max_age = (int) apply_filters( 'xspeed_cache_max_age', $max_age );

		// Honour the per-entry TTL the .meta sidecar carries, when it is
		// SHORTER than what we just resolved. The sidecar records the TTL
		// this specific entry was written under — a nonce cap (#236), a Pro
		// feed/404 expiry — and the drop-in already reads it. is_expired()
		// did not, so on the engine path a capped entry was still served for
		// the full configured lifetime: exactly the stale nonce the cap
		// exists to prevent. Only ever shortens, so an entry can never be
		// kept alive past the configured maximum by a stale sidecar.
		// Derive the sidecar from the FILE we were handed rather than
		// recomputing cache_key(): callers legitimately ask about an entry
		// that isn't the current request's (Cache_GC sweeps, Pro's warmer),
		// and cache_key() would answer for the wrong one — besides needing a
		// request context this function has no business requiring.
		$meta_file = preg_replace( '/\.html$/', '.meta', (string) $file );
		if ( is_string( $meta_file ) && $meta_file !== $file && is_readable( $meta_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own cache dir; WP_Filesystem needs admin creds unavailable on a frontend read.
			$raw     = file_get_contents( $meta_file );
			$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( is_array( $decoded ) && isset( $decoded['ttl'] ) ) {
				$entry_ttl = (int) $decoded['ttl'];
				if ( $entry_ttl > 0 && ( $max_age < 1 || $entry_ttl < $max_age ) ) {
					$max_age = $entry_ttl;
				}
			}
		}

		// A missing file is "expired" — the caller should re-render. Guard
		// filemtime() rather than letting it warn: callers legitimately ask
		// about a file that isn't there (Pro's predictive warmer probes for
		// freshness, and Cache_GC can collect an entry between the check and
		// the read), and on a site with WP_DEBUG the warning is noise.
		$mtime = file_exists( $file ) ? filemtime( $file ) : false;
		if ( false === $mtime ) {
			return true;
		}

		return ( time() - (int) $mtime ) > $max_age;
	}

	/**
	 * Accumulator for the full response body across all output-handler phases.
	 *
	 * PHP invokes an ob_start() callback once per flush, and each invocation
	 * only receives the chunk produced *since the previous flush*. If anything
	 * during the render calls `ob_flush()` or `flush()` (some themes, lazy-
	 * load plugins, AMP, etc. do), the final-phase call would otherwise only
	 * see the tail of the page — and we'd cache a truncated response that
	 * gets served repeatedly until purge. We accumulate every chunk here so
	 * the cache file always reflects the complete page.
	 *
	 * @var string
	 */
	private static $accumulated = '';

	public static function finalize_buffer( $buffer, $phase = PHP_OUTPUT_HANDLER_FINAL ) {
		self::$accumulated .= $buffer;

		// On non-final phases (mid-request flushes), pass the current chunk
		// through to the client unmodified and keep collecting. The WP 6.9
		// filter path always passes the full body in one shot with the
		// default $phase, so it falls straight through to the final block.
		$is_final = ( $phase & ( PHP_OUTPUT_HANDLER_FINAL | PHP_OUTPUT_HANDLER_END ) ) !== 0;
		if ( ! $is_final ) {
			return $buffer;
		}

		$full              = self::$accumulated;
		self::$accumulated = '';

		if ( strlen( $full ) < 255 ) {
			return $buffer;
		}

		// Status gate. We cache 200 by default. A 404 may be cached too,
		// but only when an add-on (xspeed-pro 404 cache) opts in for a
		// genuine is_404() — never a transient 404 (maintenance screen,
		// DB error, or a 404 emitted outside the main query), which would
		// otherwise be frozen until purge. Any other status is skipped.
		$status = function_exists( 'http_response_code' ) ? (int) http_response_code() : 200;
		if ( 200 !== $status ) {
			if ( 404 !== $status || ! self::should_cache_404() ) {
				return $buffer;
			}
		}

		// If no mid-request flush happened, $buffer === $full and we can
		// safely minify the on-wire bytes too. Otherwise earlier chunks have
		// already been sent unminified, so we minify only what goes to disk —
		// the first visitor sees unminified HTML, every cache hit after that
		// is minified.
		$single_chunk = ( $buffer === $full );

		/**
		 * Filter: xspeed_cache_final_html
		 *
		 * Last chance to transform the fully-rendered page HTML before it is
		 * minified and written to the cache file. Runs on cache MISS only, so
		 * whatever a listener injects here is baked into the cached HTML and
		 * replayed on every subsequent HIT (the drop-in short-circuits before
		 * PHP on a HIT — a wp_head hook would never fire there).
		 *
		 * The Preload module uses this to inject the LCP-image <link rel=preload>
		 * + preconnect hints and add fetchpriority="high" to the hero <img>.
		 * Keep listeners fast and idempotent; this is the on-wire body.
		 *
		 * @param string $full Complete page HTML.
		 */
		$full = (string) apply_filters( 'xspeed_cache_final_html', $full );
		if ( $single_chunk ) {
			$buffer = $full;
		}

		// minify_html now owned by the Minify module; read through the
		// module's storage so this stays consistent with the engine that
		// applies CSS/JS minification.
		$minify_opts = Settings_Manager::get( 'minify' );
		if ( ! empty( $minify_opts['minify_html'] ) ) {
			$full = Minifier::minify_html( $full );
			if ( $single_chunk ) {
				$buffer = $full;
			}
		}

		// AFTER minification on purpose — the HTML minifier strips comments,
		// so signing earlier would erase the signature from every minified
		// page. Baked into the cached bytes so all three serve paths (nginx
		// static rewrite, .htaccess, the PHP drop-in) carry it identically.
		$full = self::signed( $full );
		if ( $single_chunk ) {
			$buffer = $full;
		}

		// Per-site directory — see ensure_host_dir(). (#6)
		self::ensure_host_dir();

		// Path safety: cache_file_for() builds
		// `XSPEED_CACHE_DIR . '/' . <host> . '/' . $key . '.html'` where $key
		// comes from md5() — guaranteed to be exactly 32 lowercase hex chars —
		// and <host> is filtered by host_dir() to [A-Za-z0-9.-] with leading
		// dots trimmed, so no traversal sequence ('..', '/', null byte, etc.)
		// can appear in either segment. The write is therefore always inside
		// XSPEED_CACHE_DIR.
		$key = self::cache_key();

		// Query-string gate. should_cache() waved this request through
		// because every param is on the ignored_query_params allow-list, and
		// cache_key() drops the query so reads share the canonical entry.
		// That sharing is safe on READ but not on WRITE: this response was
		// rendered WITH the params, and WordPress reflects REQUEST_URI into
		// form actions, share links and plugin smart tags — so storing it
		// would serve an attacker-chosen variant under the clean URL for the
		// whole TTL (#241).
		//
		// This sits BELOW the transforms deliberately. Returning above them
		// also skipped xspeed_cache_final_html, and every listener disables
		// its own fallback ob_start() when the page cache is on precisely
		// because that filter is the shared transport — so a visitor
		// arriving on ?utm_source=… was served HTML with no LCP preload, no
		// preconnect, no CDN rewrite, no CSS combine and no HTML minify.
		// That is the ad-click and newsletter cohort getting the least
		// optimised page on the site. Only the WRITE is skipped, which is
		// what this fix was always meant to do — and it is where the
		// deferred writer has always placed its own copy of the guard.
		if ( self::query_string_blocks_write() ) {
			return $buffer;
		}
		$file = self::cache_file_for( $key );

		// A render-time translation plugin (TranslatePress) wraps our buffer,
		// so the bytes we hold here are still UNTRANSLATED — its callback has
		// not run yet, and writing now would cache English under a French URL
		// and bake in its internal #TRPLINKPROCESSED markers. Hand off to
		// shutdown, where the outer buffer has already translated, and let
		// the pass-through below deliver this request untouched.
		if ( self::translation_plugin_active() ) {
			self::$deferred_key = $key;
			// Reaching here means finalize_buffer() ran to completion: the
			// status gate passed, should_cache() said yes, and PHP handed us
			// the whole buffer. A wp_die() or exit() mid-render unwinds the
			// buffer stack WITHOUT calling this callback, so the flag stays
			// false and the shutdown writer declines — see the guard there.
			self::$render_completed = true;
			// A PHP shutdown function, not a WP `shutdown` action: this must
			// run after the output-buffer stack has unwound, and WP's
			// shutdown action fires while our outer buffer is still open.
			register_shutdown_function( array( __CLASS__, 'write_deferred_translated_cache' ) );
			return $buffer;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- WP_Filesystem requires admin context for credentials; cache writes happen on frontend requests where it's unavailable.
		file_put_contents( $file, $full, LOCK_EX );

		/**
		 * Fires after the flat hash cache file ({md5}.html) is written.
		 *
		 * Mirror of `xspeed_static_file_written` for the flat cache. The PHP
		 * serve path (Cache::maybe_serve_brotli / the drop-in) serves THIS
		 * file and looks for a `{md5}.html.br` sibling — which only the Pro
		 * Brotli listener on this hook writes. Without it the .br sibling was
		 * never created and the PHP path could never serve Brotli (FBS-83039,
		 * Blocker 2): the static-tree .br (written on xspeed_static_file_written)
		 * lives in a different cache layout the PHP path never reads.
		 *
		 * @param string $file Absolute path to the flat cache file just written.
		 * @param string $full The HTML written to it.
		 */
		do_action( 'xspeed_flat_file_written', $file, $full );

		// Persist a non-default Content-Type so the HIT path can replay it
		// (cached feeds must serve application/rss+xml, not text/html).
		// Only written when the response set a content-type other than
		// the HTML default — pages don't pay for an extra file.
		self::write_meta( $key, $full );

		// Static-cache tree (xspeed-static/{host}{path}/index.html). The
		// .htaccess rewrite block serves this file directly via the web
		// server, bypassing PHP for ~3-5× lower TTFB vs the drop-in path.
		// store_static() returns silently on any path/permission issue —
		// the drop-in remains the safety net.
		//
		// Skip it entirely when mobile_separate is on: the rewrite is
		// disabled in that mode (static_rewrite_allowed()), so a static file
		// would only be dead weight — and a device-blind one at that.
		// Skip the static-tree write for responses the web server can't replay
		// correctly: a non-200 status (a cached 404 would be served as a soft
		// 200, FBS-82406) or a non-HTML content-type (a cached feed would go
		// out as text/html, FBS-82407). The web server serves these .html files
		// directly with no PHP, so there's no .meta replay — keep them on the
		// drop-in / PHP path instead, which DOES replay status + content-type.
		// The static tree cannot replay a sidecar. A file served straight by
		// the web server carries the headers baked into the rule that serves
		// the whole site — the very answer this entry exists because it
		// disagreed with. Same reasoning as the status and content-type
		// cases: what the fast path cannot replay belongs on the drop-in path.
		if ( self::static_rewrite_allowed()
			&& self::response_is_plain_html()
			&& array() === self::per_entry_edge_headers() ) {
			self::store_static( $full );
		}

		return $buffer;
	}

	/**
	 * Write the current response to the static-cache tree at
	 * `xspeed-static/{host}{request_uri}/index.html`. The web-server
	 * rewrite block points at this path so cache hits skip PHP
	 * entirely. Caller already minified/finalized $html.
	 *
	 * Path safety: $host is restricted to a `[a-zA-Z0-9.\-]` allowlist;
	 * $uri has its query string stripped, null bytes removed, '..'
	 * sequences collapsed, and after concatenation we verify the
	 * resolved real path stays inside XSPEED_CACHE_STATIC_DIR before
	 * any write. Anything off the happy path returns silently.
	 *
	 * INVARIANT — the static tree is keyed by `{host}{path}` and NOTHING
	 * else, and both generated rewrites refuse any request that carries a
	 * query string at all (`RewriteCond %{QUERY_STRING} ^$` on Apache,
	 * `if ($args)` in nginx_snippet()). So a response may only be stored
	 * here when cache_key() adds no discriminator beyond `{host}{path}`:
	 * a query-keyed entry can never be *served* from here, only mis-served
	 * as the bare path. Any future opt-in that folds a query param into the
	 * key needs a guard below, exactly like the search one.
	 */
	/**
	 * Transient holding the most recent static-tree refusal.
	 *
	 * Short-lived on purpose: it describes what the last cacheable render
	 * actually did, so a stale entry would keep warning about a page whose
	 * nonces have since been removed. A site that still refuses simply
	 * rewrites it on the next render. (#372)
	 */
	private const STATIC_SKIP_TRANSIENT = 'xspeed_static_skip';

	/**
	 * Remember why a page was kept out of the static tree, for Health.
	 *
	 * Records the URL, the reason, and — for the nonce case — the distinct
	 * nonce KEYS found, which is what makes the finding actionable: the names
	 * (`eael_login_nonce`, `post_grid_pagination_nonce`, …) trace straight back
	 * to the plugin emitting them, and it is usually a widget the site does not
	 * use on that page. Only key names are kept, never the nonce values.
	 *
	 * @param string $reason Machine-readable refusal reason.
	 * @param string $html   The response, for extracting the nonce keys.
	 */
	private static function note_static_skip( string $reason, string $html = '' ): void {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}

		$keys = array();
		if ( 'nonce' === $reason && '' !== $html ) {
			// Must recognise the SAME shapes response_has_nonce() refuses on,
			// or a page is skipped and reported with no keys at all — which is
			// most of them, since the plain `name="_wpnonce"` form field is the
			// commonest shape by far and only the JSON one was handled here.
			// The keys are the actionable half of the message, so a mismatch
			// leaves the admin with bad news and nothing to act on.
			//
			// Both alternations capture the KEY only: each value pattern sits
			// outside the capture group, so a nonce secret can never be stored.
			$found = array();
			if ( preg_match_all( '/name=["\']([a-z0-9_\-\[\]]*nonce[a-z0-9_\-\[\]]*)["\']/i', $html, $m ) ) {
				$found = array_merge( $found, $m[1] );
			}
			if ( preg_match_all( '/["\']([a-z0-9_\-]*nonce[a-z0-9_\-]*)["\']\s*:\s*["\'][a-f0-9]{8,}["\']/i', $html, $m ) ) {
				$found = array_merge( $found, $m[1] );
			}
			// The query-arg shape (`?_wpnonce=…`) has no key name to report
			// beyond the literal, so name it explicitly rather than reporting
			// nothing for a page that was genuinely refused.
			if ( preg_match( '/[?&]_wpnonce=/i', $html ) ) {
				$found[] = '_wpnonce';
			}
			$keys = array_slice( array_values( array_unique( $found ) ), 0, 10 );
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		set_transient(
			self::STATIC_SKIP_TRANSIENT,
			array(
				'reason' => $reason,
				'url'    => (string) strtok( $uri, '?' ),
				'keys'   => $keys,
				'at'     => time(),
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * The most recent static-tree refusal, or an empty array when there is none.
	 *
	 * @return array{reason:string,url:string,keys:string[],at:int}|array{}
	 */
	public static function last_static_skip(): array {
		$stored = function_exists( 'get_transient' ) ? get_transient( self::STATIC_SKIP_TRANSIENT ) : false;
		return is_array( $stored ) && ! empty( $stored['reason'] ) ? $stored : array();
	}

	private static function store_static( string $html ): void {
		// Search results are keyed by term in cache_key() (`|s=<term>`) but
		// carry the *path* of whatever URL was searched from — for the usual
		// `/?s=<term>` that path is `/`. Writing them here would file the
		// results page as `{host}/index.html` and the web server would serve
		// it to every visitor as the homepage: an unauthenticated visitor
		// poisons the front page with one request. Searches stay on the
		// drop-in, which replays the term-keyed entry correctly. (#191)
		//
		// This is a superset of the query-string check the exclusion gate
		// does: it also covers `/?%73=<term>`, which decodes to the same
		// search (the shape #109 fixed on the gate side).
		if ( self::should_cache_search() ) {
			return;
		}

		// Same hazard for the allow-listed query params: store_static()
		// strips the query and files the response under the bare path, which
		// the web server then serves to every visitor of the clean URL with
		// no PHP involved at all — so none of the engine's checks can catch
		// it later (#241). The callers already gate on this, but the guard
		// is repeated here because this tree is the most dangerous of the
		// three write sites and must not depend on its callers.
		if ( self::request_has_query_string() ) {
			return;
		}

		// A nonce-bearing page is served here with NO PHP: no TTL check and
		// no .meta replay, so the per-entry cap that keeps the drop-in honest
		// (#236) cannot reach a file once it is written. Only Cache_GC removes
		// it, and until it does the page hands every visitor the same nonce —
		// which, once that nonce dies, breaks every anonymous form on it.
		//
		// Refusing outright was the safe answer, and it cost every
		// nonce-bearing page the static tree entirely: a site whose homepage
		// carries one unused login nonce ran PHP on every request forever.
		// The nonce's own remaining life is the better gate — the page is
		// written and its deadline recorded below for GC to enforce.
		//
		// A nonce we cannot put a clock on is still refused, and that refusal
		// is still recorded: it stays completely silent otherwise, because the
		// drop-in answers HIT while Health reports the fast path active from a
		// probe that writes its OWN file and never proves real pages reach the
		// tree. (#372)
		$nonce_ttl = self::response_has_nonce( $html ) ? self::nonce_capped_ttl( $html, 0 ) : 0;
		if ( self::response_has_nonce( $html ) && $nonce_ttl < 1 ) {
			self::note_static_skip( 'nonce', $html );
			return;
		}

		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$host = self::static_host_dir( $host );
		$uri  = str_replace( "\0", '', $uri );
		$uri  = (string) strtok( $uri, '?' );
		if ( '' === $host || '' === $uri ) {
			return;
		}
		// Collapse any traversal sequences before path resolution.
		$uri = preg_replace( '#/+#', '/', $uri );
		if ( false !== strpos( $uri, '..' ) ) {
			return;
		}

		$base = rtrim( XSPEED_CACHE_STATIC_DIR, '/' );
		$dir  = $base . '/' . $host . rtrim( $uri, '/' );
		$file = $dir . '/index.html';

		// Resolve the parent against the cache root to be sure the
		// final path is inside our tree even if the OS does anything
		// funny with multi-byte sequences.
		$base_real = realpath( WP_CONTENT_DIR );
		if ( false === $base_real || 0 !== strpos( $base, $base_real ) ) {
			return;
		}

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- Same rationale as the flat-hash cache write above: WP_Filesystem isn't available on frontend requests, and the cache write must happen during shutdown.
		$written = file_put_contents( $file, $html, LOCK_EX );

		// A nonce-bearing page expires on the nonce's schedule, not the site's.
		// Nothing reads this file at serve time — the web server hands over
		// index.html without PHP — so the deadline is recorded beside it for
		// GC, which is the only thing that can enforce it. Written before the
		// action below so a listener that shells out cannot race the sweep.
		if ( false !== $written && $nonce_ttl > 0 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- same rationale as the write above.
			file_put_contents( $dir . '/.xspeed-expires', (string) ( time() + $nonce_ttl ), LOCK_EX );
		}

		if ( false !== $written ) {
			/**
			 * Fires after a static cache file (index.html) is written.
			 *
			 * The extension point for serving pre-compressed siblings:
			 * the xspeed-pro Brotli module writes `index.html.br` next to
			 * the file here so the web server's static rewrite can serve a
			 * Brotli copy to clients that advertise `Accept-Encoding: br`,
			 * falling back to GZIP / the plain file otherwise. No core
			 * behavior depends on a listener being present.
			 *
			 * @param string $file Absolute path to the static cache file just written.
			 * @param string $html The HTML written to it.
			 */
			do_action( 'xspeed_static_file_written', $file, $html );
		}
	}

	/**
	 * Write the .meta sidecar for a cache entry when the response carries
	 * anything the HIT path must replay beyond a plain 200 text/html:
	 *   - a non-HTML Content-Type (cached feeds → application/rss+xml,
	 *     sitemaps → text/xml, …), and/or
	 *   - a non-200 status (a cached 404 must serve 404, not 200).
	 *
	 * Ordinary 200 text/html pages get NO .meta file, so the common path
	 * stays a single write.
	 *
	 * @param string $key Cache key for the current request.
	 */
	/**
	 * True only for a plain 200 text/html response — the only kind the
	 * web-server static tree can serve correctly (it streams the .html with
	 * no PHP, so it can't replay a 404 status or a feed Content-Type). Used
	 * to gate store_static() so cached 404s / feeds stay on the replay-capable
	 * drop-in / PHP path. (FBS-82406, FBS-82407)
	 */
	private static function response_is_plain_html(): bool {
		$status = function_exists( 'http_response_code' ) ? (int) http_response_code() : 200;
		if ( 200 !== $status && $status > 0 ) {
			return false;
		}
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				$ct = trim( substr( $header, strlen( 'content-type:' ) ) );
				if ( '' !== $ct && false === stripos( $ct, 'text/html' ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Append the cache signature comment to a finished page.
	 *
	 * The plugin's one outward version signal: external scanners (the
	 * xspeedcache.com speed test among them) read it to detect xSpeed and
	 * its version on a cached page, the way other cache plugins sign their
	 * output. Callers apply it AFTER HTML minification — the minifier strips
	 * comments — and before every cache write, so all serve paths carry the
	 * same bytes.
	 *
	 * The generation time is baked in here, at write time, in UTC. It is the
	 * moment the cached bytes were produced — NOT the moment they were served
	 * — because all three serve paths replay the same stored file, and two of
	 * them (the nginx/`.htaccess` static rewrite) run no PHP at all and so
	 * could never stamp a serve-time value. Reading the age of a page is the
	 * point: `generated` plus the current clock tells you how stale it is.
	 * `gmdate()` (not `current_time()`) keeps the value comparable across
	 * sites regardless of the configured timezone.
	 *
	 * @param string $html Finished page HTML.
	 * @return string HTML with the signature appended (or unchanged when a
	 *                filter removed it).
	 */
	private static function signed( string $html ): string {
		$version   = defined( 'XSPEED_VERSION' ) ? XSPEED_VERSION : '';
		$generated = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		// The literal ' | xspeedcache.com' must survive intact, and what
		// precedes it is where an edition suffix lands: Pro appends itself by
		// str_replace()-ing on that exact token
		// (Pro_Plugin::sign_cache_signature). So the stamp goes AFTER it —
		// placed before, it sits between the version and the anchor and
		// composes as "generated <date> + Pro v1.1.3".
		$signature = sprintf(
			'<!-- Page cached by xSpeed Cache v%s | xspeedcache.com | generated %s -->',
			$version,
			$generated
		);

		/**
		 * Filter: xspeed_cache_signature
		 *
		 * The HTML comment appended to every cached page. Add-ons append
		 * their own edition/version here; white-label setups return '' to
		 * remove the comment entirely. Must remain a valid HTML comment (or
		 * an empty string) — it ships inside the cached body.
		 *
		 * @param string $signature The signature comment.
		 * @param string $version   The plugin version baked into it.
		 * @param string $generated The write-time timestamp baked into it,
		 *                          formatted `Y-m-d H:i:s UTC`.
		 */
		$signature = (string) apply_filters( 'xspeed_cache_signature', $signature, $version, $generated );
		if ( '' === trim( $signature ) ) {
			return $html;
		}
		return $html . "\n" . $signature;
	}

	/**
	 * Does this response carry a WordPress nonce?
	 *
	 * Anonymous nonces depend only on the tick (user 0, empty session
	 * token), so they are identical for every visitor — which is exactly why
	 * they cache "successfully" and then fail silently once the tick moves.
	 *
	 * Matches any form field whose NAME contains "nonce" — `_wpnonce`,
	 * `_wpnonce_<action>`, Tutor's `_tutor_nonce`, CF7's `_wpcf7_nonce` and
	 * WooCommerce's `woocommerce-add-to-cart-nonce` (which does NOT start
	 * with an underscore, so a `_`-anchored pattern misses it) — plus the
	 * `_wpnonce=` form used in nonce-bearing URLs. Deliberately keyed on
	 * `name=` so prose, CSS classes and data attributes don't false-positive.
	 *
	 * @param string $html Rendered response body.
	 */
	public static function response_has_nonce( string $html ): bool {
		if ( '' === $html ) {
			return false;
		}

		/*
		 * Three shapes, because a nonce reaches the page in three ways:
		 *
		 * 1. A form field name — `_wpnonce`, `woocommerce-login-nonce`, and
		 *    the GROUPED names form builders emit (`data[_wpnonce]`,
		 *    `frm[nonce]`). The character class deliberately allows `[` and
		 *    `]` so grouping does not hide the field: form builders are
		 *    exactly the kind of plugin #236 is about, and a missed page
		 *    keeps the old broken behaviour silently.
		 * 2. A query argument (`?_wpnonce=`) on a link.
		 * 3. A nonce handed to the page's own scripts rather than placed in
		 *    a visible form — `wp_localize_script()` output and inline JSON
		 *    both land as a `"nonce":"…"`-shaped pair.
		 */
		return 1 === preg_match(
			'/(name=["\'][a-z0-9_\-\[\]]*nonce[a-z0-9_\-\[\]]*["\']'
				. '|[?&]_wpnonce='
				. '|["\'][a-z0-9_\-]*nonce[a-z0-9_\-]*["\']\s*:\s*["\'][a-f0-9]{8,}["\'])/i',
			$html
		);
	}

	/**
	 * The TTL (seconds) a response may be cached for, capped to the nonce
	 * lifetime when it carries one.
	 *
	 * WordPress nonces are valid for at most `nonce_life` — 24h by default —
	 * because wp_verify_nonce() accepts the current tick and the previous
	 * one. Our own lifetime maximum is 720h and the shipped Aggressive
	 * preset is 168h, so on any site configured above 24h every anonymous
	 * front-end form carried a DEAD nonce for the majority of the cache's
	 * life and every submission was rejected — with the other plugin's error
	 * string ("Nonce not matched"), so the report never reached us (#236).
	 *
	 * `nonce_life` is the MAXIMUM a nonce can live, not the minimum, so it
	 * is the wrong number to cap with. wp_nonce_tick() buckets time into
	 * `nonce_life / 2` slices; a nonce minted x seconds into its bucket is
	 * valid for `nonce_life - x`, where x can be as large as a full bucket.
	 * Capping the entry at `nonce_life` therefore still served a dead nonce
	 * for up to half of every entry's life — 0-12h of each 24h entry,
	 * averaging 6h, re-rolled by every purge so it reads as intermittent.
	 * Capping at the guaranteed-valid remainder closes the window at every
	 * tick phase, at the cost of caching nonce-bearing pages for 12h rather
	 * than 24h.
	 *
	 * Capping is per-entry, so only nonce-bearing pages pay for it; the rest
	 * of the site keeps the configured lifetime.
	 *
	 * @param string $html    Rendered response body.
	 * @param int    $ttl     Otherwise-resolved TTL in seconds.
	 * @return int            TTL to actually use.
	 */
	/**
	 * The nonce lifetime to cap against, in seconds.
	 *
	 * `nonce_life` is a TWO-argument filter in core:
	 *
	 *     $nonce_life = apply_filters( 'nonce_life', DAY_IN_SECONDS, $action );
	 *
	 * Applying it with one argument is not merely incomplete — a callback
	 * that declares both parameters as required (the documented shape, and
	 * what a site branching per action must write) raises ArgumentCountError
	 * the moment we call it. That fatal lands in the shutdown cache write,
	 * so the visitor still sees a perfectly normal page while the sidecar is
	 * never written: the entry then keeps the FULL configured lifetime
	 * carrying a dead nonce, which is precisely the bug #236 set out to fix.
	 * Worse, the entry stays that way until a purge, even after the site
	 * removes whatever customised the lifetime.
	 *
	 * We are inspecting rendered markup, so we cannot know which action
	 * minted the nonce we found. Two consequences:
	 *
	 * 1. We pass `''` as the action. A per-action callback therefore sees
	 *    the same "unknown action" value core itself passes when a nonce is
	 *    created with no action, and can branch on it deliberately.
	 * 2. A page may carry nonces from SEVERAL actions with different
	 *    lifetimes. The entry can only have one TTL, so the safe choice is
	 *    the SHORTEST lifetime any action on the site resolves to — capping
	 *    to a longer one would serve a dead nonce for the shorter action.
	 *    Sites can narrow this with `xspeed_cache_nonce_life_actions`.
	 *
	 * @param string $html Response body being cached.
	 * @return int Nonce lifetime in seconds (0 = do not cap).
	 */
	private static function nonce_life_seconds( string $html ): int {
		/**
		 * Filter the nonce actions whose lifetimes are consulted when
		 * capping a cache entry.
		 *
		 * The default `''` is the "action unknown" case — we are reading
		 * rendered HTML, not minting a nonce. A site whose `nonce_life`
		 * callback shortens specific actions can list them here so the cap
		 * accounts for the shortest one that could appear on the page.
		 *
		 * @since 1.1.8
		 * @param string[] $actions Nonce actions to resolve.
		 * @param string   $html    The response body being cached.
		 */
		$actions = (array) apply_filters( 'xspeed_cache_nonce_life_actions', array( '' ), $html );
		if ( empty( $actions ) ) {
			$actions = array( '' );
		}

		$shortest = 0;
		foreach ( $actions as $action ) {
			// Both arguments, exactly as core passes them.
			$life = (int) apply_filters( 'nonce_life', DAY_IN_SECONDS, (string) $action );
			if ( $life < 1 ) {
				continue;
			}
			if ( 0 === $shortest || $life < $shortest ) {
				$shortest = $life;
			}
		}

		return $shortest;
	}

	public static function nonce_capped_ttl( string $html, int $ttl ): int {
		if ( ! self::response_has_nonce( $html ) ) {
			return $ttl;
		}

		$nonce_life = self::nonce_life_seconds( $html );
		if ( $nonce_life < 1 ) {
			return $ttl;
		}

		// Half of nonce_life is the GUARANTEED-valid remainder — see above.
		$guaranteed = max( 1, intdiv( $nonce_life, 2 ) );
		$capped     = ( $ttl > 0 ) ? min( $ttl, $guaranteed ) : $guaranteed;

		/**
		 * Filter the nonce-capped TTL for a cache entry.
		 *
		 * Escape hatch for a site whose nonce-shaped markup is decorative —
		 * return the uncapped $ttl to keep the configured lifetime. Most
		 * sites should leave this alone: serving a dead nonce breaks every
		 * anonymous form on the page.
		 *
		 * @param int    $capped     TTL after the nonce cap (seconds).
		 * @param int    $ttl        TTL before the cap (seconds).
		 * @param int    $nonce_life Current nonce lifetime (seconds).
		 * @param string $html       The response body being cached.
		 */
		return (int) apply_filters( 'xspeed_cache_nonce_ttl_cap', $capped, $ttl, $nonce_life, $html );
	}

	private static function write_meta( string $key, string $html = '' ): void {
		$content_type = '';
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				$content_type = trim( substr( $header, strlen( 'content-type:' ) ) );
			}
		}
		$status = function_exists( 'http_response_code' ) ? (int) http_response_code() : 200;

		$meta            = array();
		$is_default_type = ( '' === $content_type || false !== stripos( $content_type, 'text/html' ) );
		if ( ! $is_default_type ) {
			$meta['content_type'] = $content_type;
		}
		if ( 200 !== $status && $status > 0 ) {
			$meta['status'] = $status;
		}

		// Per-content TTL (seconds). The drop-in and static fast paths can't
		// call is_expired() / the xspeed_cache_max_age filter (they run before
		// WP), so persist the resolved max-age here whenever it differs from
		// the plain page TTL — e.g. the Pro feed cache's 12h vs the 24h page
		// default. The fast paths read this to expire correctly. (FBS-82407)
		// This MUST resolve the TTL the same way is_expired() does, including
		// the per-post override — the sidecar is the only channel that can
		// carry a per-entry TTL into the pre-boot fast paths. Omitting the
		// override here left an editor's "expire this post after 1h" visible
		// to the engine but invisible to the drop-in, which kept serving the
		// entry until the global lifetime elapsed (#240 AC#3). Handing the
		// filter the same base as is_expired() also keeps a filter that
		// SCALES its input (e.g. $max_age * 2) consistent between the two.
		$opts          = Settings_Manager::get( 'cache' );
		$default_ttl   = (int) $opts['cache_expiry'] * HOUR_IN_SECONDS;
		$max_age       = $default_ttl;
		$post_override = Cache_Rules::expiry_override_seconds_for_post( Cache_Rules::current_post_id() );
		if ( null !== $post_override ) {
			$max_age = $post_override;
		}
		/** This filter is documented in includes/class-cache.php */
		$ttl = (int) apply_filters( 'xspeed_cache_max_age', $max_age );

		// A response carrying a nonce may not outlive that nonce, however
		// long the site's configured lifetime is (#236). This runs AFTER the
		// max-age filter so it caps whatever the filter resolved rather than
		// being overridden by it — a Pro module lengthening the TTL must not
		// be able to reintroduce a dead nonce.
		$ttl = self::nonce_capped_ttl( $html, $ttl );

		if ( $ttl > 0 && $ttl !== $default_ttl ) {
			$meta['ttl'] = $ttl;
		}

		// This entry's edge headers, when they differ from the site-wide set
		// baked into the drop-in. The sidecar is the only channel that can
		// carry a per-page answer into the pre-boot fast path, and the drop-in
		// REPLACES the baked set with it rather than merging: the two describe
		// the same response, so merging would leave the baked lifetime in
		// place beside the hold meant to overrule it.
		$edge = self::per_entry_edge_headers();
		if ( array() !== $edge ) {
			$meta['edge_headers'] = $edge;
		}

		// Nothing to replay → no sidecar.
		if ( empty( $meta ) ) {
			return;
		}

		$payload = wp_json_encode( $meta );
		if ( false === $payload ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- our own cache dir; WP_Filesystem needs admin creds unavailable on a frontend shutdown write.
		file_put_contents( self::cache_meta_for( $key ), $payload, LOCK_EX );
	}

	/**
	 * @param string $cause Free-form human reason. Recorded in the
	 *                      Activity log to give users context (e.g.
	 *                      'post saved', 'settings change', 'manual',
	 *                      'theme switch').
	 */
	/**
	 * Purge the cache entries for ONE URL — every variant of it: the
	 * flat-hash entry (+ .meta / .html.br siblings), both device buckets
	 * (mobile_separate keys them separately), both trailing-slash forms,
	 * and the static-tree index.html (+ .br) the server rewrite serves.
	 * The rest of the cache is untouched — this is the surgical
	 * alternative to purge_all for "I just edited this one page".
	 *
	 * @param string $url   Absolute URL, or site-relative path ("/about/").
	 * @param string $cause Who asked, for the purge log. See purge_all().
	 * @return int Number of cache files removed.
	 */
	/**
	 * Post types that are not "viewable" but ARE the presentation layer.
	 *
	 * `is_post_type_viewable()` answers "does this type have a front end of
	 * its own?" — which is the right question for `shop_order`, but the
	 * wrong one for the types core uses to render every OTHER page. A
	 * template part, a global-styles record, a navigation or a synced
	 * pattern has no permalink, yet editing one changes how the whole site
	 * looks. Gating purges on viewability alone meant a Site Editor save
	 * invalidated nothing and visitors kept the old design for the full
	 * TTL — up to 30 days at the maximum lifetime. (#270 regression)
	 *
	 * @return string[]
	 */
	/**
	 * Could this post change alter anything an anonymous visitor had cached?
	 *
	 * Deleting one post fired a full purge for the post AND for every stored
	 * revision, because wp_delete_post() removes each revision through
	 * wp_delete_post() again and every one of those fires before_delete_post
	 * with post_type 'revision'. A post with six revisions cost seven whole-
	 * site sweeps, each one also announcing to LiteSpeed, purging the object
	 * cache network-wide on Redis, rewriting the stats option and running
	 * every xspeed_after_purge_all listener -- including Pro's Cloudflare
	 * purge, so seven API calls. Trashing cost two, via save_post and then
	 * trashed_post. (QA #348)
	 *
	 * The check lives here, ahead of purge_all(), so one early return covers
	 * the local sweep, the server-cache announcement and both action hooks.
	 * It deliberately does NOT live inside purge_all(): a manual, CLI or
	 * explicit caller asked for a purge and must get one.
	 *
	 * @param int    $post_id Post being saved or removed.
	 * @param mixed  $post    Post object when the hook passed one.
	 * @param string $event   'save' or 'remove'.
	 */
	private static function post_change_is_cacheable_content( $post_id, $post, string $event ): bool {
		$post_id = (int) $post_id;

		// Only `save_post` and `before_delete_post` hand over a post object.
		// `trashed_post` passes ( $post_id, $previous_status ) -- a STRING --
		// so reaching for ->post_status on the second argument finds nothing
		// and the status rule below would never fire. Read the row instead.
		if ( ! is_object( $post ) && function_exists( 'get_post' ) ) {
			$post = get_post( $post_id );
		}

		$type = is_object( $post ) && isset( $post->post_type )
			? (string) $post->post_type
			: (string) ( function_exists( 'get_post_type' ) ? get_post_type( $post_id ) : '' );
		if ( '' === $type ) {
			return false;
		}

		// A revision is a copy of content nobody can browse to.
		if ( 'revision' === $type ) {
			return false;
		}
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return false;
		}
		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		$status = is_object( $post ) && isset( $post->post_status ) ? (string) $post->post_status : '';

		// Clicking "Add New" inserts an auto-draft and fires save_post. There
		// is nothing cached of a post that has never existed publicly.
		if ( 'auto-draft' === $status ) {
			return false;
		}

		// Unknown/!viewable → nothing anonymous can see changed, UNLESS the
		// type is itself part of how pages render (#270 regression).
		if ( function_exists( 'is_post_type_viewable' )
			&& ! is_post_type_viewable( $type )
			&& ! in_array( $type, self::presentation_post_types(), true )
		) {
			return false;
		}

		// Deleting something that was already invisible changes no cached
		// page: the transition that hid it purged at the time. This is what
		// makes emptying a trash of a hundred posts cost nothing rather than
		// a hundred full sweeps.
		//
		// It also collapses trashing to a single purge: wp_trash_post() fires
		// save_post first, where the post is genuinely disappearing from
		// listings and SHOULD purge, then trashed_post, by which point the
		// row reads 'trash' and is skipped. A status we cannot read, on a row
		// that still reports a type, means assume viewable -- erring toward
		// an extra purge, never toward serving a stale page. A row that is
		// gone entirely reports no type either and was refused above.
		// 'inherit' is an INTERNAL status in core, so is_post_status_viewable()
		// says no -- but an attachment carrying it is genuinely public. Judge
		// those on the post type alone, which is already checked above.
		if ( 'remove' === $event && '' !== $status && 'inherit' !== $status
			&& function_exists( 'is_post_status_viewable' )
			&& ! is_post_status_viewable( $status )
		) {
			return false;
		}

		return true;
	}

	public static function presentation_post_types(): array {
		$types = array(
			'wp_template',      // Site Editor templates.
			'wp_template_part', // Header / footer / reusable parts.
			'wp_global_styles', // Colours, typography, spacing.
			'wp_navigation',    // Navigation block menus.
			'nav_menu_item',    // Classic menus.
			'wp_block',         // Synced patterns / reusable blocks.
		);

		/**
		 * Filter the non-viewable post types that still invalidate the cache.
		 *
		 * Add a type here when it has no front end of its own but changes
		 * how other pages render (a theme's own layout CPT, for example).
		 *
		 * @param string[] $types Post type slugs.
		 */
		return (array) apply_filters( 'xspeed_presentation_post_types', $types );
	}

	/**
	 * Describe a broad hook invalidation for response-cache adapters.
	 *
	 * Term, menu, theme and plugin changes can alter navigation, archives or
	 * markup across the site, so they require a site response-cache purge.
	 * Content saves also require this scope while their local operation is a
	 * complete bucket sweep.
	 *
	 * @return array{scope:string,intent:string,urls:array<int,string>}
	 */
	private static function invalidation_for_hook( string $hook ): array {
		$presentation = array(
			'switch_theme',
			'activated_plugin',
			'deactivated_plugin',
			'created_term',
			'edited_term',
			'delete_term',
			'wp_update_nav_menu',
		);

		return array(
			'scope'  => 'site',
			'intent' => in_array( $hook, $presentation, true ) ? 'presentation' : 'content',
			'urls'   => array(),
		);
	}


	/**
	 * save_post → purge only when the saved thing can appear on a cached page.
	 *
	 * Revisions and autosaves are never rendered. Non-viewable post types —
	 * WooCommerce's `shop_order` / `shop_order_placehold` / `shop_order_refund`
	 * / `shop_coupon`, Flamingo's `flamingo_inbound` (#229), Tutor's
	 * `tutor_enrolled` (#231) — are invisible to anonymous visitors, so
	 * writing one changes nothing that is cached. (#243)
	 *
	 * The exception is the presentation types above, which are non-viewable
	 * yet render every page — they are allow-listed BEFORE the viewability
	 * test. (#270 regression)
	 *
	 * @param int      $post_id Saved post ID.
	 * @param \WP_Post $post    Saved post object.
	 */
	public static function on_save_post( $post_id, $post = null ): void {
		if ( ! self::post_change_is_cacheable_content( $post_id, $post, 'save' ) ) {
			return;
		}

		$post_type = is_object( $post ) && isset( $post->post_type )
			? (string) $post->post_type
			: (string) get_post_type( $post_id );

		// Name the trigger rather than logging a bare numeric id — the old
		// wiring passed the post ID into $cause, so the log read
		// "Cache purged (46)" with no indication of what caused it. (#243)
		$presentation = in_array( $post_type, self::presentation_post_types(), true );
		self::purge_all(
			'post:' . $post_type,
			null,
			array(
				// purge_all() sweeps every local response in this site's bucket.
				// Without dependency tracking, the server cache must match that
				// same boundary or unrelated pages can remain stale there.
				'scope'  => 'site',
				'intent' => $presentation ? 'presentation' : 'content',
				'urls'   => array(),
			)
		);
		if ( class_exists( '\XSpeed\Minifier' ) ) {
			Minifier::purge_minified();
		}
	}

	/**
	 * Delete/trash invalidation while the post type is still available.
	 * The local and server response-cache sweeps share the same site boundary.
	 *
	 * @param int         $post_id Removed post ID.
	 * @param object|null $post    Post object supplied by core when available.
	 */
	public static function on_post_removed( $post_id, $post = null ): void {
		if ( ! self::post_change_is_cacheable_content( $post_id, $post, 'remove' ) ) {
			return;
		}

		$post_type = is_object( $post ) && isset( $post->post_type )
			? (string) $post->post_type
			: (string) get_post_type( $post_id );

		self::purge_all(
			'post-removed:' . $post_type,
			null,
			array(
				'scope'  => 'site',
				// Match on_save_post: a presentation type changes how pages
				// render rather than what they say.
				'intent' => in_array( $post_type, self::presentation_post_types(), true )
					? 'presentation'
					: 'content',
				'urls'   => array(),
			)
		);
	}

	/** Purge site responses when moderation changes visible comments. */
	public static function on_comment_status( $comment_id, $status = '' ): void {
		$comment = function_exists( 'get_comment' ) ? get_comment( (int) $comment_id ) : null;
		$post_id = is_object( $comment ) && isset( $comment->comment_post_ID ) ? (int) $comment->comment_post_ID : 0;
		if ( $post_id < 1 || ! function_exists( 'get_permalink' ) ) {
			return;
		}
		$url = get_permalink( $post_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}
		self::purge_all(
			'comment-status:' . (string) $status,
			null,
			array(
				'scope'  => 'site',
				'intent' => 'content',
				'urls'   => array(),
			)
		);
	}

	/**
	 * comment_post → purge just the commented-on URL, and only once the
	 * comment is actually visible.
	 *
	 * A comment held for moderation changes nothing on the front end, and an
	 * approved one changes exactly one page — not the whole site. Product
	 * reviews are comments and guest reviews are on by default, so under the
	 * old wiring any visitor could flush a store's entire cache, repeatedly,
	 * with no account. (#243)
	 *
	 * @param int   $comment_id  New comment ID.
	 * @param int|string $approved 1 when approved, 0 when held, 'spam'.
	 * @param array $data        Comment data.
	 */
	public static function on_comment_post( $comment_id, $approved = 0, $data = array() ): void {
		if ( 1 !== (int) $approved ) {
			return;
		}
		$post_id = is_array( $data ) && isset( $data['comment_post_ID'] ) ? (int) $data['comment_post_ID'] : 0;
		if ( $post_id < 1 ) {
			return;
		}
		$url = get_permalink( $post_id );
		if ( is_string( $url ) && '' !== $url ) {
			self::purge_url( $url, 'comment' );
		}
	}

	/**
	 * user_register / profile_update → purge only when the user can author
	 * content that appears on the front end.
	 *
	 * A customer registering at checkout changes no rendered page, and cannot
	 * change an enqueued asset — so it must not purge the cache, and must not
	 * rebuild the minified bundles. Checkout account-creation fired FOUR
	 * full-site purges plus four purge_minified() runs in a single request
	 * before this gate. (#243)
	 *
	 * @param int $user_id Affected user.
	 */
	public static function on_user_change( $user_id ): void {
		$user = function_exists( 'get_userdata' ) ? get_userdata( (int) $user_id ) : null;
		if ( ! $user ) {
			return;
		}

		// Only roles that can publish can change a rendered page. WooCommerce
		// customers and WordPress subscribers cannot.
		if ( ! user_can( $user, 'edit_posts' ) ) {
			return;
		}

		$url = get_author_posts_url( (int) $user_id );
		if ( is_string( $url ) && '' !== $url ) {
			self::purge_url( $url, 'user' );
		}
	}

	/**
	 * Purge everything a product's price / stock / sale state is rendered on.
	 *
	 * The product permalink is not enough: the shop archive and the product's
	 * category and tag archives render the same price and Sale! badge, and
	 * #242 reproduces all three going stale together.
	 *
	 * Accepts a product ID or a WC_Product. A variation resolves to its
	 * parent, which is the page that actually renders.
	 *
	 * @param int|object $product Product ID or WC_Product.
	 */
	public static function purge_product( $product ): void {
		$product_id = is_object( $product ) && method_exists( $product, 'get_id' )
			? (int) $product->get_id()
			: (int) $product;
		if ( $product_id < 1 ) {
			return;
		}

		// Variations are never rendered on their own URL.
		$parent = (int) wp_get_post_parent_id( $product_id );
		if ( $parent > 0 ) {
			$product_id = $parent;
		}

		$urls = array();

		$permalink = get_permalink( $product_id );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$urls[] = $permalink;
		}

		// The shop archive.
		if ( function_exists( 'wc_get_page_id' ) ) {
			$shop_id = (int) wc_get_page_id( 'shop' );
			if ( $shop_id > 0 ) {
				$shop_url = get_permalink( $shop_id );
				if ( is_string( $shop_url ) && '' !== $shop_url ) {
					$urls[] = $shop_url;
				}
			}
		}

		// Every category / tag archive this product appears on.
		foreach ( array( 'product_cat', 'product_tag' ) as $taxonomy ) {
			$terms = get_the_terms( $product_id, $taxonomy );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$term_url = get_term_link( $term );
				if ( is_string( $term_url ) && '' !== $term_url ) {
					$urls[] = $term_url;
				}
			}
		}

		// The front page, when it is not the shop page but still lists
		// products (a block/shortcode storefront).
		$front_id = (int) get_option( 'page_on_front' );
		if ( $front_id > 0 ) {
			$front_url = get_permalink( $front_id );
			if ( is_string( $front_url ) && '' !== $front_url ) {
				$urls[] = $front_url;
			}
		}

		/**
		 * Filter the URLs purged when a product changes.
		 *
		 * A storefront that renders products somewhere else — a landing page,
		 * a custom archive — can add its URLs here rather than falling back
		 * to purging the whole site.
		 *
		 * @param string[] $urls       URLs about to be purged.
		 * @param int      $product_id The product that changed.
		 */
		$urls = (array) apply_filters( 'xspeed_purge_product_urls', $urls, $product_id );

		foreach ( array_unique( array_filter( $urls ) ) as $url ) {
			self::purge_url( (string) $url, 'product' );
		}
	}

	/**
	 * Adapter for the WooCommerce stock actions that pass a product OBJECT
	 * where the status actions pass an ID.
	 *
	 * @param object $product WC_Product (or variation).
	 */
	public static function purge_product_object( $product ): void {
		self::purge_product( $product );
	}

	/**
	 * Re-entry guard for the purge-event contract.
	 *
	 * A listener on `xspeed_after_purge_url` legitimately purges its own
	 * layer, and a server-cache or CDN adapter that calls back into xSpeed
	 * while doing so re-enters this method — unbounded, because each pass
	 * looks like a fresh purge.
	 *
	 * A single global flag stops too much: a nested purge of a DIFFERENT URL is
	 * a real purge whose listeners must hear about it. But a per-request
	 * "already published" set stops too much in the other direction — a
	 * network purge loops every blog in one request, and on a subdirectory
	 * network they share a host, so blogs 2..N would be silently skipped. It
	 * also grows for the life of the process.
	 *
	 * So the guard tracks what is IN FLIGHT, not what has been published: a
	 * target is marked while its own dispatch is on the stack and unmarked
	 * when it returns. Re-entering the same target recurses, so it is refused;
	 * purging the same URL again later is a new event and publishes. The set
	 * is bounded by call depth rather than by how many URLs a request touches.
	 *
	 * @var array<string,bool>
	 */
	private static $purge_events_in_flight = array();

	/** Monotonic count used to detect whether a delegated purge published. */
	private static $purge_event_sequence = 0;

	/**
	 * Publish a purge event exactly once, with bounded arguments.
	 *
	 * Deliberately carries only what an integration needs to invalidate its
	 * own copy: the canonical URL (or null for a full purge), the site host,
	 * the cause label, and how many files went. No filesystem paths, no cache
	 * contents, no request headers, no user data. The URL query and caller-
	 * supplied cause may nevertheless contain sensitive text, so listeners
	 * must redact them in logs or unrelated destinations that do not need the
	 * exact cache key.
	 *
	 * A listener that throws must not take the purge down with it: the files
	 * are already gone by the time we get here, and an integration's bad day
	 * is not a reason to report a failed purge to the caller.
	 *
	 * @param string              $hook    Hook name to emit.
	 * @param array<string,mixed> $context Bounded context, see above.
	 */
	private static function dispatch_purge_event( string $hook, array $context ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}
		$target = $hook . '|' . ( isset( $context['url'] ) ? (string) $context['url'] : '' )
			. '|' . ( isset( $context['host'] ) ? (string) $context['host'] : '' );
		if ( isset( self::$purge_events_in_flight[ $target ] ) ) {
			return;
		}
		self::$purge_events_in_flight[ $target ] = true;
		++self::$purge_event_sequence;

		// Our own integrations get their own try. Sharing one with the public
		// action below meant a listener on the extension seam could throw and
		// take the contract event down with it — the mirror of the failure
		// this separation exists to prevent.
		try {
			// Built-in server-cache integrations run FIRST, and by a direct
			// call rather than as listeners on the action below.
			//
			// WordPress stops dispatching an action's remaining callbacks when
			// one of them throws. As a listener, our LiteSpeed forwarding
			// would then be skipped by any unrelated third-party callback that
			// happened to be registered earlier and blew up — and the visible
			// result is the worst kind: xSpeed reports a successful purge while
			// the server keeps serving stale HTML. Shipped behaviour must not
			// be hostage to a listener's bug.
			self::forward_to_server_caches( $context );
		} catch ( \Throwable $e ) {
			self::log_purge_listener_error( $hook, $e );
		}

		try {
			self::do_action_isolated( $hook, $context );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Swallow: see docblock. The purge succeeded regardless.
			self::log_purge_listener_error( $hook, $e );
		} finally {
			unset( self::$purge_events_in_flight[ $target ] );
		}
	}

	/**
	 * Run every listener on a purge hook, isolating each from the others.
	 *
	 * `do_action()` dispatches callbacks in one loop, so the first one to
	 * throw takes every LATER listener down with it. On a purge that meant a
	 * failing CDN integration silently cancelled the ones queued behind it —
	 * and because the throw was swallowed to keep the purge itself succeeding,
	 * the user was told the clear worked while two edges were never touched.
	 * Invisible unless WP_DEBUG happened to be on. (QA #348)
	 *
	 * Each callback gets its own try/catch here, so one integration's bad day
	 * costs only that integration. Priority order is preserved. Falls back to
	 * a plain `do_action()` when the filter registry is not the shape we
	 * expect, so an unusual environment degrades to the old behaviour rather
	 * than skipping listeners entirely.
	 *
	 * @param string $hook Hook name to emit.
	 * @param mixed  $arg  Single argument passed to each listener.
	 */
	public static function do_action_isolated( string $hook, $arg ): void {
		global $wp_filter;

		// Walking $wp_filter by hand and calling each callback directly was the
		// obvious way to do this, and it was wrong: it bypasses WordPress, so
		// `current_filter()` came back empty, `did_action()` stayed at 0, the
		// `all` hook never fired, and Query Monitor and Debug Bar could not see
		// the very contract this class publishes. A shared handler branching on
		// current_filter() picked the wrong branch. (QA #348 round 2, issue 3)
		//
		// So let do_action() dispatch — WordPress keeps its bookkeeping — and
		// isolate one level down instead: each registered callback is swapped
		// for a wrapper that runs it inside a try/catch. One listener throwing
		// then costs only that listener, which is the whole point, without
		// costing the hook its identity.
		if ( ! isset( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof \WP_Hook ) ) {
			do_action( $hook, $arg );
			return;
		}

		$hook_object = $wp_filter[ $hook ];
		$original    = $hook_object->callbacks;
		if ( ! is_array( $original ) || array() === $original ) {
			do_action( $hook, $arg );
			return;
		}

		$wrapped      = array();
		$restorations = array();
		foreach ( $original as $priority => $group ) {
			if ( ! is_array( $group ) ) {
				$wrapped[ $priority ] = $group;
				continue;
			}
			foreach ( $group as $id => $registered ) {
				if ( ! isset( $registered['function'] ) || ! is_callable( $registered['function'] ) ) {
					$wrapped[ $priority ][ $id ] = $registered;
					continue;
				}
				$callback = $registered['function'];
				$wrapper  = static function ( ...$args ) use ( $callback, $hook ) {
					try {
						return $callback( ...$args );
					} catch ( \Throwable $e ) {
						self::log_purge_listener_error( $hook, $e );
						return null;
					}
				};
				$wrapped[ $priority ][ $id ] = array(
					// Keep accepted_args: a listener registered for 0 or 1
					// arguments must still be called the way it asked.
					'accepted_args' => $registered['accepted_args'] ?? 1,
					'function'      => $wrapper,
				);
				$restorations[ $priority ][ $id ] = array(
					'original' => $registered,
					'wrapper'  => $wrapper,
				);
			}
		}

		$hook_object->callbacks = $wrapped;
		try {
			do_action( $hook, $arg );
		} finally {
			// Restore only wrappers still present. Native add/remove operations
			// performed by listeners must survive this temporary substitution.
			foreach ( $restorations as $priority => $group ) {
				foreach ( $group as $id => $restore ) {
					$current = $hook_object->callbacks[ $priority ][ $id ]['function'] ?? null;
					if ( $current === $restore['wrapper'] ) {
						$hook_object->callbacks[ $priority ][ $id ] = $restore['original'];
					}
				}
			}
		}
	}

	/**
	 * Name a listener that threw, under WP_DEBUG only.
	 *
	 * Gated like the rest of Free's diagnostics: a third-party listener
	 * throwing on every purge must not fill a production log.
	 */
	private static function log_purge_listener_error( string $hook, \Throwable $e ): void {
		// An \Error — a TypeError from one of OUR listeners, say — is a bug
		// rather than a runtime condition a third party imposed on us, and
		// swallowing it silently in production turns it into a purge that
		// quietly stops working. Those are logged whatever WP_DEBUG says;
		// third-party \Exceptions stay gated so a noisy integration cannot
		// fill a production log.
		$always = $e instanceof \Error;
		if ( ( $always || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) && function_exists( 'error_log' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- names a third-party listener that threw during a purge.
			error_log( '[xspeed] a ' . $hook . ' listener threw: ' . $e->getMessage() );
		}
	}

	/** Test seam: clear the in-flight set left behind by an aborted dispatch. */
	public static function reset_purge_events(): void {
		self::$purge_events_in_flight = array();
		self::$purge_event_sequence   = 0;
	}

	/**
	 * Hand the purge to the caches we ship integrations for.
	 *
	 * Isolated from the public action on purpose — see dispatch_purge_event().
	 * Guarded so a missing class (a partial upgrade, a stripped build) cannot
	 * turn a working purge into a fatal.
	 *
	 * @param array<string,mixed> $context Bounded purge context.
	 */
	private static function forward_to_server_caches( array $context ): void {
		if ( class_exists( __NAMESPACE__ . '\\Server_Caches' ) ) {
			Server_Caches::forward( $context );
		}
	}

	/**
	 * `host[:port]` for a cache key, from a parsed URL.
	 *
	 * The port is kept, because `cache_key()` hashes the raw `HTTP_HOST` and
	 * that carries `:8080` on any install not served from 80/443 — dropping it
	 * computed a different md5, found no file, and reported "already cold"
	 * while the page kept serving HIT.
	 *
	 * A port that is the DEFAULT for the scheme is dropped, though, because
	 * `HTTP_HOST` does not carry one: a browser sends `Host: site.com` for
	 * `https://site.com:443/`. Keeping it hashed `site.com:443` against a file
	 * stored under `site.com` — the same silent no-op in the other direction,
	 * and the one QA hit passing a canonical URL with the port spelled out.
	 * (QA #348)
	 *
	 * @param array<string,mixed> $parts Output of wp_parse_url().
	 */
	private static function host_port_of( array $parts ): string {
		if ( ! isset( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( (string) $parts['host'] );
		if ( '' === $host || ! isset( $parts['port'] ) ) {
			return $host;
		}
		$port   = (int) $parts['port'];
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			return $host;
		}
		return $host . ':' . $port;
	}

	public static function purge_url( string $url, string $cause = 'manual' ): int {
		// A URL that names nothing is not a purge of everything. An empty or
		// blank string used to fall through to the home_url() default below
		// and clear the HOMEPAGE — so a third party calling
		// `purge_url( get_permalink( $id ) )` on a post whose permalink came
		// back empty silently purged the front page instead of nothing. The
		// CLI and the MCP tool reject empties before reaching this, so only
		// direct API callers were exposed, but they are exactly the audience
		// this public contract is for. (QA #348)
		if ( '' === trim( $url ) ) {
			return 0;
		}
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fallback for early-boot contexts only.
		if ( ! is_array( $parts ) ) {
			return 0;
		}
		// Absolute URLs are accepted only for HTTP response caches. Schemes such
		// as ftp:, file: and javascript: can parse cleanly but do not name a page
		// xSpeed or a server response cache can invalidate. A leading-slash path
		// remains a supported site-relative target.
		if ( isset( $parts['scheme'] ) && ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return 0;
		}
		if ( isset( $parts['scheme'] ) && empty( $parts['host'] ) ) {
			return 0;
		}
		// Reject a string that parsed but is not a URL we can act on: no
		// scheme AND no host AND no leading-slash path means something like
		// `ht!tp://[[[` or a bare word, which parse_url() hands back as a
		// relative "path". Forwarding that produced `purge_url(/ht!tp://[[[)`
		// — a nonsense tag sent to LiteSpeed for every malformed call.
		if ( ! isset( $parts['scheme'] ) && ! isset( $parts['host'] ) ) {
			$raw = isset( $parts['path'] ) ? (string) $parts['path'] : '';
			if ( '' === $raw || '/' !== $raw[0] ) {
				return 0;
			}
		}
		// Keep the port. `cache_key()` hashes the raw `HTTP_HOST`, which
		// carries `:8080` on any install not served from 80/443 — while
		// parse_url() splits the port into its own component, so a purge that
		// used the bare host computed a different md5, found no file, and
		// reported "already cold". A silent no-op: the page kept serving HIT
		// until its TTL ran out. Intranet installs, panel hosts on :8443 and
		// proxies that forward `Host: site.com:8080` all hit this.
		// A scheme-less `site.test:443/page/` is a supported explicit-host
		// target. Infer a scheme only when it names THIS site's hostname: then
		// its explicit default port is the same origin and the same local cache
		// key. Never apply this to another host or to a non-default port.
		if ( ! isset( $parts['scheme'] ) && isset( $parts['host'], $parts['port'] ) && function_exists( 'home_url' ) ) {
			$home = function_exists( 'wp_parse_url' ) ? wp_parse_url( home_url( '/' ) ) : parse_url( home_url( '/' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- see above.
			if ( is_array( $home ) && ! empty( $home['host'] ) && ! empty( $home['scheme'] )
				&& strtolower( (string) $home['host'] ) === strtolower( (string) $parts['host'] )
			) {
				$home_scheme = strtolower( (string) $home['scheme'] );
				$port        = (int) $parts['port'];
				$home_port   = isset( $home['port'] )
					? (int) $home['port']
					: ( 'https' === $home_scheme ? 443 : ( 'http' === $home_scheme ? 80 : 0 ) );
				if ( $home_port === $port
					&& ( ( 'https' === $home_scheme && 443 === $port ) || ( 'http' === $home_scheme && 80 === $port ) )
				) {
					$parts['scheme'] = $home_scheme;
				}
			}
		}
		$host = self::host_port_of( $parts );
		if ( '' === $host && function_exists( 'home_url' ) ) {
			$home = function_exists( 'wp_parse_url' ) ? wp_parse_url( home_url( '/' ) ) : parse_url( home_url( '/' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- see above.
			if ( is_array( $home ) ) {
				$host = self::host_port_of( $home );
			}
		}
		if ( '' === $host ) {
			return 0;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$path = '/' . ltrim( $path, '/' );
		if ( false !== strpos( $path, '..' ) ) {
			return 0;
		}

		// The cache key preserves REQUEST_URI's trailing-slash form, so
		// purge both. Root stays a single '/'.
		$forms = array( $path );
		if ( '/' !== $path ) {
			$forms[] = rtrim( $path, '/' );
			$forms[] = rtrim( $path, '/' ) . '/';
		}
		$forms = array_unique( $forms );

		/*
		 * Entries live under the bucket they were written for, and this URL's
		 * site may not be the one serving THIS request (a cross-site purge on
		 * multisite, WP-CLI, or cron). Build the directory from the URL's own
		 * host AND path. (#6)
		 *
		 * Host alone is wrong on a subdirectory network: `store()` wrote to
		 * `<host>/<prefix>/`, so looking in `<host>/` found nothing and the
		 * call reported "already cold" while the page kept serving HIT — a
		 * false success, which is worse than an error. The prefix has to come
		 * from the URL being purged rather than from the current blog, because
		 * the caller is usually purging some OTHER site. (QA B2 on #166)
		 */
		$base = XSPEED_CACHE_DIR . '/' . self::bucket_for_url( $host, $path );

		$count = 0;
		foreach ( $forms as $uri ) {
			// '' = mobile_separate off; '|m' / '|d' = the device buckets.
			foreach ( array( '', '|m', '|d' ) as $device ) {
				$key  = md5( $host . $uri . $device );
				$file = $base . '/' . $key . '.html';
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
					++$count;
				}
				foreach ( array( $base . '/' . $key . '.meta', $file . '.br', self::brotli_size_sidecar( $file . '.br' ) ) as $sidecar ) {
					if ( is_file( $sidecar ) ) {
						wp_delete_file( $sidecar );
					}
				}
			}
		}

		// Static tree (served directly by the nginx/.htaccess rewrite).
		if ( defined( 'XSPEED_CACHE_STATIC_DIR' ) ) {
			// Same transform the write used — `localhost:8080` files under
			// `localhost8080`, so the bare host found nothing here either.
			$dir  = rtrim( XSPEED_CACHE_STATIC_DIR, '/' ) . '/' . self::static_host_dir( $host ) . ( '/' === $path ? '' : rtrim( $path, '/' ) );
			$file = $dir . '/index.html';
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
				++$count;
			}
			foreach ( array( $file . '.br', self::brotli_size_sidecar( $file . '.br' ) ) as $sidecar ) {
				if ( is_file( $sidecar ) ) {
					wp_delete_file( $sidecar );
				}
			}
		}

		if ( $count > 0 ) {
			Cache_Inventory::invalidate();
			Activity_Log::record(
				'cache_purge_url',
				sprintf(
					/* translators: 1: cause of the purge, 2: URL or path, 3: number of files removed. */
					__( 'Purged one URL (%1$s) — %2$s, %3$d file(s) removed', 'xspeed' ),
					$cause,
					$host . $path,
					$count
				),
				Activity_Log::INFO
			);
		}

		/**
		 * Fires after one URL's cached copy has been purged.
		 *
		 * The single-URL counterpart to `xspeed_after_purge_all`. Subscribe
		 * here to invalidate a cache xSpeed does not own — a server-level
		 * cache such as LiteSpeed's LSCache, a reverse proxy, or a CDN — for
		 * the same URL.
		 *
		 * Only fires when the purge actually ran. A malformed URL, a URL with
		 * no resolvable host, or a traversal attempt returns earlier and
		 * publishes nothing, so a listener can treat this as "xSpeed purged
		 * this URL" rather than "xSpeed was asked to". `removed` may legitimately
		 * be 0: the URL was not in xSpeed's cache, which says nothing about
		 * whether it is in yours.
		 *
		 * Fires at most once per purge. A listener that calls back into
		 * xSpeed's purge API will not re-enter this event.
		 *
		 * @since 1.2.3
		 *
		 * @param array $context {
		 *     Bounded description of the purge. URL queries and caller-supplied
		 *     causes can contain sensitive values and are not logging fields.
		 *
		 *     @type string $url     Canonical scheme://host/path[?query] of the purged URL.
		 *                            The query is preserved because caches in front
		 *                            commonly key on it; xSpeed's own sweep is
		 *                            path-based, so `removed` describes that.
		 *     @type string $host    Host (with port when non-standard).
		 *     @type string $path    Path component, leading slash.
		 *     @type string $cause   Short label for who asked. See purge_all().
		 *     @type int    $removed Number of cache files removed.
		 *     @type string $scope   Actionable adapter scope: `urls`.
		 *     @type string $intent  Why responses changed: `content`.
		 *     @type string[] $urls  Exact response URLs to invalidate.
		 * }
		 */
		$canonical_url = self::canonical_purge_url(
			$host,
			$path,
			isset( $parts['query'] ) ? (string) $parts['query'] : '',
			isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : ''
		);
		self::dispatch_purge_event(
			'xspeed_after_purge_url',
			array(
				'url'     => $canonical_url,
				'host'    => $host,
				'path'    => $path,
				'cause'   => $cause,
				'removed' => $count,
				'scope'   => 'urls',
				'intent'  => 'content',
				'urls'    => array( $canonical_url ),
			)
		);

		return $count;
	}

	/** Host this site's purge is scoped to, for the purge-event context. */
	private static function current_purge_host(): string {
		if ( ! function_exists( 'home_url' ) ) {
			return '';
		}
		$home = function_exists( 'wp_parse_url' ) ? wp_parse_url( home_url( '/' ) ) : parse_url( home_url( '/' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- host only.
		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return '';
		}
		// Same default-port normalisation as purge_url(): a site whose
		// home_url() carries `:443` (normal behind a proxy) otherwise stamps
		// every full-purge event with a host that matches none of its own
		// URLs, so the LiteSpeed forward stood down site-wide. (QA #348)
		return self::host_port_of( $home );
	}

	/**
	 * Rebuild the canonical URL a purge applied to.
	 *
	 * Built from the parts the purge itself used, so a listener is told the
	 * URL we acted on rather than the string the caller happened to pass —
	 * those differ whenever the caller supplied a site-relative path, a
	 * different scheme, or a query string the cache key ignores.
	 */
	private static function canonical_purge_url( string $host, string $path, string $query = '', string $url_scheme = '' ): string {
		// The purged URL's own scheme wins. purge_url() explicitly supports
		// cross-site purges (multisite, WP-CLI, cron), where composing the
		// current site's scheme onto another site's host builds a URL that was
		// never served — and a CDN listener then purges the wrong key and
		// reports success.
		if ( '' !== $url_scheme ) {
			return $url_scheme . '://' . $host . $path . ( '' !== $query ? '?' . $query : '' );
		}
		$scheme = function_exists( 'is_ssl' ) && is_ssl() ? 'https' : 'http';
		if ( function_exists( 'home_url' ) ) {
			$home = function_exists( 'wp_parse_url' ) ? wp_parse_url( home_url( '/' ) ) : parse_url( home_url( '/' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- scheme only.
			if ( is_array( $home ) && ! empty( $home['scheme'] ) ) {
				$scheme = (string) $home['scheme'];
			}
		}
		// The query is carried even though OUR sweep above is path-based.
		// Caches in front commonly key on the full request line — LiteSpeed
		// tags `/shop/?page=2` separately from `/shop/` — so publishing the
		// bare path would have a listener confidently purge the wrong entry
		// and report success. Telling it exactly what was asked for lets it
		// act correctly; `removed` still describes only what WE removed.
		return $scheme . '://' . $host . $path . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Sweep this site's cache files.
	 *
	 * On multisite every blog shares one cache directory, so an unscoped
	 * sweep here took the whole network cold — one subsite's settings save
	 * or post publish rebuilt every other site from PHP. Entries are stored
	 * per host (see host_dir()), and the sweep is scoped to match, so a
	 * purge originating on site-a leaves site-b's cache warm. (#6)
	 *
	 * Clears the files only: the flat tree, the static tree, the REST
	 * responses and the minified assets. The object-cache flush, the stats
	 * update, `xspeed_after_purge_all`, the `xspeed_after_purge` contract
	 * event and the log entry live in purge_all(), which is still the entry
	 * point for every existing caller. Split out so `wp xspeed purge` can
	 * report the local sweep as one line item and the object cache as
	 * another, each with its own status — see Purge_Runner.
	 *
	 * @param string|null $host Host to purge. Defaults to the current site.
	 *                          Pass '*' to sweep the ENTIRE tree — network
	 *                          admin's "purge all sites", and the migration
	 *                          of pre-#6 entries that sit in the tree root.
	 * @return array{pages:int,rest:int,assets:int,bytes:int} Entries removed
	 *               per store, and the bytes freed by the two file sweeps
	 *               that measure themselves.
	 */
	public static function purge_local( ?string $host = null ): array {
		$network_wide      = ( '*' === $host );
		self::$sweep_bytes = 0;
		// The flat tree buckets by a flattened segment (host/a-b) while the
		// static tree mirrors the URL (host/a/b), so they need separate
		// scopes — see current_host_dir() vs current_static_scope().
		$static_scope = '';
		if ( null === $host || $network_wide ) {
			$scope        = $network_wide ? '' : self::current_host_dir();
			$static_scope = $network_wide ? '' : self::current_static_scope();
		} else {
			$dir          = self::host_dir( $host );
			$scope        = '' === $dir ? 'default' : $dir;
			$static_dir   = self::static_host_dir( $host );
			$static_scope = '' === $static_dir ? 'default' : $static_dir;
		}

		$count = 0;
		if ( is_dir( XSPEED_CACHE_DIR ) ) {
			// Scoped to one host directory, or the whole tree (including the
			// legacy top-level entries written before #6) when network-wide.
			/*
			 * Network-wide sweeps go TWO levels deep, not one. A subdirectory
			 * subsite's bucket is `<host>/<prefix>/`, so globbing only
			 * `<cache>/*` reached the main site and left every subsite's
			 * entries in place. (QA D5 on #166)
			 *
			 * A scoped purge also has to cover its own nested buckets: when
			 * the main blog of a subdirectory network purges, `<host>/` is its
			 * bucket and `<host>/one/` belongs to another blog — so the scoped
			 * branch deliberately does NOT descend, which is what keeps
			 * site-level purges isolated.
			 */
			$roots = $network_wide
				? array_merge(
					array( XSPEED_CACHE_DIR ),
					array_filter( (array) glob( XSPEED_CACHE_DIR . '/*', GLOB_ONLYDIR ) ),
					array_filter( (array) glob( XSPEED_CACHE_DIR . '/*/*', GLOB_ONLYDIR ) )
				)
				: array( XSPEED_CACHE_DIR . '/' . $scope );

			foreach ( $roots as $root ) {
				/*
				 * min/ and rest/ are swept by their own purgers below; never
				 * treat them as host buckets.
				 *
				 * Checked on every path SEGMENT, not just the basename: now
				 * that the network-wide glob descends two levels it can reach
				 * `min/combined`, whose basename is `combined` and would sail
				 * past a basename-only test — deleting the combined
				 * stylesheets out from under the pages that link them.
				 */
				if ( ! $network_wide || XSPEED_CACHE_DIR !== $root ) {
					$relative = trim( str_replace( XSPEED_CACHE_DIR, '', (string) $root ), '/' );
					$segments = '' === $relative ? array() : explode( '/', $relative );
					if ( array_intersect( $segments, array( 'min', 'rest' ) ) ) {
						continue;
					}
				}
				if ( ! is_dir( $root ) ) {
					continue;
				}
				$files = glob( $root . '/*.html' );
				if ( $files ) {
					$count += count( $files );
					foreach ( $files as $f ) {
						self::sweep_delete( $f );
					}
				}
				// Remove the .meta sidecars (content-type for feeds/sitemaps)
				// alongside their .html entries. Not counted — they're not
				// cache "pages", just per-entry metadata.
				$meta = glob( $root . '/*.meta' );
				if ( $meta ) {
					foreach ( $meta as $m ) {
						self::sweep_delete( $m );
					}
				}
				// Remove precompressed siblings (e.g. <key>.html.br from the Pro
				// Brotli module). Not counted — same as .meta. Without this a
				// purge leaves stale .br bodies behind: disk bloat, and a
				// staleness window if precompression is later disabled.
				$br = glob( $root . '/*.br' );
				if ( $br ) {
					foreach ( $br as $b ) {
						self::sweep_delete( $b );
					}
				}
				// `*.br` does not match `*.br.size` — same reason as the flat-root
			// sweep above: a size record outliving its body would later be
			// read against a different sibling's bytes.
				$br_size = glob( $root . '/*.br.size' );
				if ( $br_size ) {
					foreach ( $br_size as $b ) {
						self::sweep_delete( $b );
					}
				}
			}
		}
		// Static-cache tree purge — recursive because the layout is
		// xspeed-static/{host}/{path}/index.html, so a flat glob can't
		// reach everything. Already host-segmented, so scoping is just a
		// matter of starting one level down.
		if ( is_dir( XSPEED_CACHE_STATIC_DIR ) ) {
			$static_root = $network_wide
				? XSPEED_CACHE_STATIC_DIR
				: XSPEED_CACHE_STATIC_DIR . '/' . $static_scope;
			if ( is_dir( $static_root ) ) {
				$count += self::rmtree_html( $static_root );
			}
		}
		// REST response cache (cache/xspeed/rest/*.json) — same purge
		// triggers (publish, settings change) invalidate it too.
		$rest   = Rest_Cache::purge();
		$count += $rest;

		// Minified + combined CSS/JS (cache/xspeed/min/ and min/combined/).
		// purge_all is a full filesystem sweep and must clear these too, even
		// when the Minify module is currently disabled — orphaned min/ files
		// from a feature the user later turned off must still be removed, and
		// a stale combined-<hash>.css that the regenerated page no longer
		// references otherwise 404s and breaks the frontend. (FBS-83114/83116)
		$assets = class_exists( '\\XSpeed\\Minifier' ) ? Minifier::purge_minified() : 0;

		return array(
			'pages'  => $count - $rest,
			'rest'   => $rest,
			'assets' => $assets,
			'bytes'  => self::$sweep_bytes,
		);
	}

	/**
	 * Flush the persistent object cache (Redis / Memcached).
	 *
	 * Runs regardless of whether the Object Cache module is currently
	 * enabled — a drop-in installed earlier keeps serving until flushed.
	 *
	 * @param bool $network_wide Flush every blog's entries. wp_cache_flush()
	 *                           is NETWORK-global, so on multisite the
	 *                           default prefers the blog-scoped group flush
	 *                           (WP 6.1+) — otherwise one site's purge drops
	 *                           every other site's object cache, the same bug
	 *                           #6 fixed for the page cache.
	 * @return bool Whether a flush was actually performed.
	 */
	public static function flush_object_cache( bool $network_wide = false ): bool {
		if ( ! $network_wide && is_multisite() && function_exists( 'wp_cache_flush_group' ) && function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			// Blog-scoped groups only; a shared/global group (site options,
			// user meta) is intentionally left alone.
			foreach ( array( 'options', 'posts', 'terms', 'post_meta', 'comment' ) as $group ) {
				wp_cache_flush_group( $group );
			}
			return true;
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			return (bool) wp_cache_flush();
		}
		return false;
	}

	/**
	 * Purge this site's cache: the local sweep, then the object cache, then
	 * the bookkeeping every caller expects (stats, `xspeed_after_purge_all`,
	 * inventory invalidation, purge log).
	 *
	 * @param string      $cause Who asked, for the purge log.
	 * @param string|null $host  See purge_local().
	 * @param array<string,mixed> $invalidation Public adapter policy. `scope`
	 *                                          is urls/site/network/none,
	 *                                          `intent` explains why, and
	 *                                          `urls` supplies exact targets.
	 * @return int Page + REST entries removed.
	 */
	public static function purge_all( string $cause = 'manual', ?string $host = null, array $invalidation = array() ) {
		$network_wide  = ( '*' === $host );
		$adapter_scope = isset( $invalidation['scope'] ) && is_string( $invalidation['scope'] )
			? $invalidation['scope']
			: ( $network_wide ? 'network' : 'site' );
		if ( ! in_array( $adapter_scope, array( 'urls', 'site', 'network', 'none' ), true ) ) {
			$adapter_scope = $network_wide ? 'network' : 'site';
		}
		if ( $network_wide ) {
			$adapter_scope = 'network';
		}
		$intent = isset( $invalidation['intent'] ) && is_string( $invalidation['intent'] ) && '' !== $invalidation['intent']
			? $invalidation['intent']
			: 'complete';
		$urls   = isset( $invalidation['urls'] ) && is_array( $invalidation['urls'] )
			? array_values( array_unique( array_filter( $invalidation['urls'], 'is_string' ) ) )
			: array();
		// This method always sweeps a complete local bucket. A narrower adapter
		// announcement would claim unrelated local pages stayed warm when they
		// did not, leaving their server copies stale. Until purge_all() gains
		// dependency-aware local deletion, its response scope cannot be `urls`.
		if ( 'urls' === $adapter_scope ) {
			$adapter_scope = $network_wide ? 'network' : 'site';
		}
		if ( 'site' === $adapter_scope || 'network' === $adapter_scope || 'none' === $adapter_scope ) {
			$urls = array();
		}

		$removed = self::purge_local( $host );
		$count   = $removed['pages'] + $removed['rest'];

		self::flush_object_cache( $network_wide );

		self::update_stats( array( 'last_purge' => time() ) );

		// Fire AFTER the local sweep so module listeners (Critical CSS,
		// Unused CSS, Cloudflare edge purge) run — this action had three
		// registered listeners but was never emitted. Treat it as additive
		// (CDN / edge invalidation), not the mechanism for clearing local
		// files. (FBS-83114)
		// Wrapped: this action predates the purge-event contract and has its
		// own third-party listeners. One of them throwing used to abort
		// purge_all() here, which now also means the contract event below
		// never fires and a server cache keeps serving stale HTML. The local
		// sweep is already done by this point, so swallowing is strictly safer
		// than letting a listener decide the rest of the method runs.
		try {
			// Isolated per listener: one throwing used to cancel every
			// listener queued behind it — Critical CSS, Unused CSS and the
			// Cloudflare edge purge all hang off this hook. (QA #348)
			self::do_action_isolated( 'xspeed_after_purge_all', $cause );
		} catch ( \Throwable $e ) {
			self::log_purge_listener_error( 'xspeed_after_purge_all', $e );
		}

		/**
		 * Fires after a full purge, with the same bounded context shape as
		 * `xspeed_after_purge_url`.
		 *
		 * Distinct from `xspeed_after_purge_all` on purpose. That action is
		 * the long-standing internal signal — it passes a bare `$cause` string
		 * and Free's own modules use it for local bookkeeping. This one is the
		 * documented contract for OUTSIDE integrations: same argument shape as
		 * the per-URL event, so a server-cache or CDN adapter can subscribe to
		 * both with one handler and branch on a null `url`.
		 *
		 * Fires at most once per purge, and not at all when a listener's own
		 * purge re-enters xSpeed.
		 *
		 * @since 1.2.3
		 *
		 * @param array $context {
		 *     @type null   $url     Always null — a full purge has no single URL.
		 *     @type string $host    Host swept, or '*' for the entire tree.
		 *     @type null   $path    Always null.
		 *     @type string $cause   Short label for who asked.
		 *     @type int    $removed Number of cache files removed.
		 *     @type string $scope   Adapter action: urls/site/network/none.
		 *     @type string $intent  content/presentation/complete or a caller-defined intent.
		 *     @type string[] $urls  Exact targets when scope is urls.
		 * }
		 */
		self::dispatch_purge_event(
			'xspeed_after_purge',
			array(
				'url'     => null,
				'host'    => null === $host ? self::current_purge_host() : (string) $host,
				'path'    => null,
				'cause'   => $cause,
				'removed' => $count,
				'scope'   => $adapter_scope,
				'intent'  => $intent,
				'urls'    => $urls,
			)
		);

		// The list behind the "Cached pages" card is memoized for a minute;
		// a purge has to drop it or the drill-down shows pages that no
		// longer exist.
		Cache_Inventory::invalidate();

		// Trigger of WP_CLI / hook / admin-bar purges all hit the same
		// path. Record once with the supplied cause so the dashboard
		// activity feed reads naturally.
		Activity_Log::record(
			'cache_purged',
			sprintf( 'Cache purged (%s) — %d file%s removed', $cause, $count, 1 === $count ? '' : 's' ),
			Activity_Log::INFO
		);

		return $count;
	}

	/**
	 * Purge everything after a plugin / theme / core update completes.
	 *
	 * Bound to `upgrader_process_complete`, which is the only hook an update
	 * fires — no activation hook runs, so without this the cached HTML (and
	 * the asset URLs baked into it) outlives the code that produced it.
	 *
	 * Runs for plugin, theme and core updates alike, including bulk runs and
	 * auto-updates, and purges the WHOLE network rather than the current
	 * site — see the call below. Translation updates are skipped: they
	 * change no markup a cached page depends on, and language packs update
	 * often enough that purging on them would keep a multilingual site
	 * permanently cold.
	 *
	 * Note this cannot be folded into the `$invalidate_hooks` loop above:
	 * that binds `purge_all` directly, and `purge_all( string $cause )` would
	 * then receive the WP_Upgrader instance as its cause.
	 *
	 * @param mixed $upgrader   WP_Upgrader instance (unused).
	 * @param array $hook_extra Context for the completed operation.
	 * @return void
	 */
	public static function purge_after_upgrade( $upgrader = null, $hook_extra = array() ) {
		$cleared = self::$upgrade_cleared_destination;

		if ( ! self::upgrade_produced_something( $upgrader ) ) {
			return;
		}

		if ( ! self::upgrade_should_purge( is_array( $hook_extra ) ? $hook_extra : array(), $cleared ) ) {
			return;
		}

		self::purge_for_upgrade();
	}

	/**
	 * Whether this request's upgrader removed an existing copy.
	 *
	 * @var bool
	 */
	private static $upgrade_cleared_destination = false;

	/**
	 * How many `upgrader_process_complete` dispatches are on the stack.
	 *
	 * @var int
	 */
	private static $upgrade_dispatch_depth = 0;

	/**
	 * Enter an `upgrader_process_complete` dispatch.
	 *
	 * Bound at PHP_INT_MIN, so it runs before any listener that might read
	 * the replacement signal. Public because it is a hook target.
	 *
	 * @return void
	 */
	public static function note_upgrade_dispatch(): void {
		++self::$upgrade_dispatch_depth;
	}

	/**
	 * Drop the replacement signal once every listener has read it.
	 *
	 * Bound at PHP_INT_MAX so a second upgrade in the same request starts
	 * clean, without taking the answer away from the add-on callbacks that
	 * run at the same priority as ours.
	 *
	 * Only the OUTERMOST dispatch clears it. A nested run — core's language
	 * pack upgrader, or any add-on that installs something from this hook —
	 * fires the action again, and clearing there would answer for a run that
	 * has not finished. Called directly (no dispatch on the stack) it still
	 * clears, which is what a test wants.
	 *
	 * Known limit: a nested run INHERITS the outer run's signal, because the
	 * only evidence we get is a filter that fires before the nested dispatch
	 * begins and carries no upgrader identity. So a fresh install performed
	 * from inside a replacement run reads as a replacement and purges once
	 * more than it needs to. A cold cache is the cheap direction, and the
	 * alternative — scoping the signal per upgrader — is not knowable from
	 * `upgrader_clear_destination`.
	 *
	 * @return void
	 */
	public static function forget_cleared_destination(): void {
		if ( self::$upgrade_dispatch_depth > 0 ) {
			--self::$upgrade_dispatch_depth;
		}

		if ( 0 === self::$upgrade_dispatch_depth ) {
			self::$upgrade_cleared_destination = false;
		}
	}

	/**
	 * Record that the upgrader cleared an existing destination.
	 *
	 * A pass-through listener on `upgrader_clear_destination`: WordPress only
	 * fires it when `clear_destination` was set AND something was there to
	 * remove, which is the one signal that separates an upload-and-replace
	 * from a first-time install. The filtered value is returned untouched.
	 *
	 * @param true|\WP_Error $removed Whether the destination was cleared.
	 * @return true|\WP_Error
	 */
	public static function note_cleared_destination( $removed ) {
		if ( ! is_wp_error( $removed ) ) {
			self::$upgrade_cleared_destination = true;
		}

		return $removed;
	}

	/**
	 * Did the completed run actually replace anything?
	 *
	 * `upgrader_process_complete` fires whether the run succeeded or failed —
	 * the failure branch in WP_Upgrader::run() only feeds the skin before the
	 * action fires. A run that installed nothing changed no markup, so purging
	 * for it is a cold cache bought for nothing.
	 *
	 * Deliberately conservative: this returns false ONLY when every result we
	 * can see is an error. An upgrader we cannot read, a mixed bulk run, or a
	 * missing result all fall through to purging, which is the safe direction
	 * everywhere else in this handler.
	 *
	 * @param mixed $upgrader WP_Upgrader instance, or anything else.
	 * @return bool
	 */
	public static function upgrade_produced_something( $upgrader ): bool {
		if ( ! is_object( $upgrader ) ) {
			return true;
		}

		// A bulk run collects one entry per item; `result` alone would only
		// describe the last of them.
		if ( isset( $upgrader->results ) && is_array( $upgrader->results ) && ! empty( $upgrader->results ) ) {
			foreach ( $upgrader->results as $result ) {
				if ( ! is_wp_error( $result ) && ! empty( $result ) ) {
					return true;
				}
			}
			return false;
		}

		if ( ! property_exists( $upgrader, 'result' ) ) {
			return true;
		}

		return ! is_wp_error( $upgrader->result ) && ! empty( $upgrader->result );
	}

	/**
	 * Decide whether a completed operation invalidates the cache.
	 *
	 * Split out from the handler so the decision is testable on its own:
	 * purge_all() reaches straight for glob() and unlink(), which a unit test
	 * cannot observe honestly, while every rule that matters lives here.
	 *
	 * @param array $hook_extra Context for the completed operation.
	 * @return bool
	 */
	public static function upgrade_should_purge( array $hook_extra, bool $destination_cleared = false ): bool {
		if ( ! self::upgrade_replaced_code( $hook_extra, $destination_cleared ) ) {
			return false;
		}

		// An update to xSpeed ITSELF always purges, whatever the setting says.
		// This plugin's own code is what rendered every cached page — the
		// minifier, lazy-loader, resource hints and CDN rewriter all changed
		// underneath it — so serving that HTML after an update means serving
		// output from a version that no longer exists. Minified assets make it
		// concrete rather than theoretical: their filenames are keyed on the
		// source filemtime, so they regenerate under NEW hashes while the
		// cached pages still link the old ones, and the page requests files
		// that are no longer on disk. Offering an opt-out for that would be
		// offering a broken site.
		return self::upgrade_touches_xspeed( $hook_extra ) || self::purge_on_upgrade_enabled();
	}

	/**
	 * Did this completed run replace code that renders pages?
	 *
	 * The half of the decision that has nothing to do with our settings: it
	 * asks only whether live code changed underneath the output we cached.
	 * Add-ons that keep their own derived artifacts — generated CSS, captured
	 * selectors, fingerprints — need the same answer and must not have to
	 * rebuild these rules, or they drift apart. Call it with the hook's own
	 * `$hook_extra`; the upload-and-replace signal is read from this request.
	 *
	 * Deliberately independent of the "Purge After Updates" setting. That
	 * setting governs the page cache, not whether an add-on's derived data is
	 * still valid.
	 *
	 * @param array     $hook_extra          Context for the completed operation.
	 * @param bool|null $destination_cleared Override the recorded signal; null reads this request's.
	 * @return bool
	 */
	public static function upgrade_replaced_code( array $hook_extra, ?bool $destination_cleared = null ): bool {
		$cleared = null === $destination_cleared ? self::$upgrade_cleared_destination : $destination_cleared;

		$type   = isset( $hook_extra['type'] ) ? (string) $hook_extra['type'] : '';
		$action = isset( $hook_extra['action'] ) ? (string) $hook_extra['action'] : '';

		// `upgrader_process_complete` fires for INSTALLS as well as updates.
		// A freshly installed plugin is inactive and a freshly installed theme
		// is not the active one, so neither can change a single rendered page
		// — but the first cut of this handler purged the whole tree anyway, so
		// evaluating three plugins in a row emptied the cache three times.
		//
		// 'install' alone is NOT enough to skip on, because WordPress reports
		// an upload-and-replace as an install: `Plugin_Upgrader::install()`
		// hardcodes `action => install` and `overwrite_package` does not change
		// it, so "Replace current with uploaded" and `wp plugin install <zip>
		// --force` both arrive here labelled install while genuinely replacing
		// live code. That is how a plugin distributed as a zip is updated, and
		// skipping it put back the stale markup this handler exists to clear.
		//
		// The distinguishing signal is whether the destination was cleared:
		// WP_Upgrader only fires `upgrader_clear_destination` when it removed
		// something that was already there. Installing beside nothing does not.
		if ( 'install' === $action && ! $cleared ) {
			return false;
		}

		// 'translation' is the one update type that cannot change rendered
		// markup. Anything else — including an empty type from a custom
		// updater — is treated as cache-invalidating, because guessing wrong
		// in that direction only costs a cold cache.
		if ( 'translation' === $type ) {
			return false;
		}

		return true;
	}

	/**
	 * Purge everything an update can invalidate.
	 *
	 * Network-wide ('*'), not the calling site's bucket. A plugin, theme or
	 * core update replaces code shared by EVERY site on the network, so a
	 * scoped purge would clear the site that happened to run the updater and
	 * leave every other subsite serving pre-update HTML for the whole TTL —
	 * the very bug this handler exists to fix, one level down. On single-site
	 * this is identical to the scoped call, since there is only ever one
	 * bucket.
	 *
	 * @return void
	 */
	private static function purge_for_upgrade(): void {
		self::purge_all( 'upgrade', '*' );
		Minifier::purge_minified();
	}

	/**
	 * Purge after an unattended background update run.
	 *
	 * `automatic_updates_complete` passes ONE argument, and it is not a
	 * hook_extra: it is WordPress's results array, keyed by what was updated
	 * ('core', 'plugin', 'theme', 'translation'). Handing it to
	 * purge_after_upgrade() put it in the unused $upgrader slot and left the
	 * type empty, so a night on which only a language pack updated purged
	 * every cached page — the exact case the translation exemption exists to
	 * prevent, and WordPress auto-updates language packs by default.
	 *
	 * @param array $results Update results, keyed by type.
	 * @return void
	 */
	public static function purge_after_auto_updates( $results = array() ): void {
		if ( ! self::auto_updates_should_purge( is_array( $results ) ? $results : array() ) ) {
			return;
		}

		self::purge_for_upgrade();
	}

	/**
	 * Decide whether a background update run invalidates the cache.
	 *
	 * @param array $results Update results, keyed by type.
	 * @return bool
	 */
	public static function auto_updates_should_purge( array $results ): bool {
		// An unrecognisable payload is treated as invalidating, the same
		// direction every other unknown takes here.
		if ( empty( $results ) ) {
			return true;
		}

		// Failed items are listed alongside successful ones — WP_Automatic_Updater
		// appends an entry whatever the outcome — and a night on which every
		// update failed replaced no code, so it invalidates nothing.
		$updated = array();
		foreach ( $results as $type => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				$result = is_object( $item ) && isset( $item->result ) ? $item->result : true;
				if ( ! is_wp_error( $result ) && ! empty( $result ) ) {
					$updated[] = (string) $type;
					break;
				}
			}
		}

		if ( empty( $updated ) ) {
			return false;
		}

		// Nothing but language packs: a language pack changes no markup a
		// cached page depends on, and purging on one would keep a multilingual
		// site permanently cold.
		if ( array( 'translation' ) === array_values( array_unique( $updated ) ) ) {
			return false;
		}

		return self::auto_updates_touch_xspeed( $results ) || self::purge_on_upgrade_enabled();
	}

	/**
	 * Does a background run include one of our own plugins?
	 *
	 * Same rule as a foreground self-update, read out of the results array's
	 * shape instead of a hook_extra: each plugin entry carries the update
	 * object on `->item->plugin`.
	 *
	 * @param array $results Update results, keyed by type.
	 * @return bool
	 */
	private static function auto_updates_touch_xspeed( array $results ): bool {
		if ( empty( $results['plugin'] ) || ! is_array( $results['plugin'] ) ) {
			return false;
		}

		$ours = self::self_update_plugins();
		foreach ( $results['plugin'] as $entry ) {
			$item = is_object( $entry ) && isset( $entry->item ) ? $entry->item : null;
			$file = is_object( $item ) && isset( $item->plugin ) ? (string) $item->plugin : '';
			if ( '' !== $file && in_array( $file, $ours, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is the "Purge After Updates" setting on?
	 *
	 * Gates THIRD-PARTY updates only — an xSpeed self-update ignores it, see
	 * purge_after_upgrade(). Defaults to true when the option has never been
	 * written, matching the schema default in CacheModule: an unset value on
	 * an existing install must not read as "the user turned this off".
	 *
	 * Unlike LiteSpeed, which ships the equivalent toggle OFF, this defaults
	 * ON — a cold cache costs one slow request, whereas stale HTML is a wrong
	 * page for up to the full TTL and the site owner has no way to tell why.
	 *
	 * @return bool
	 */
	private static function purge_on_upgrade_enabled(): bool {
		$opts = Settings_Manager::get( 'cache' );
		return ! array_key_exists( 'purge_on_upgrade', $opts ) || ! empty( $opts['purge_on_upgrade'] );
	}

	/**
	 * Does this completed update include xSpeed itself?
	 *
	 * Mirrors the payload shapes Plugin::maybe_restore_after_update() reads:
	 * a single update carries 'plugin', a bulk run carries 'plugins'.
	 *
	 * @param array $hook_extra Context for the completed operation.
	 * @return bool
	 */
	private static function upgrade_touches_xspeed( array $hook_extra ): bool {
		if ( ! isset( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return false;
		}

		$updated = array();
		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			// Strings only: array_intersect() stringifies what it is given, so
			// an object without __toString in a custom updater's payload would
			// be a fatal rather than a miss.
			$updated = array_filter( $hook_extra['plugins'], 'is_string' );
		} elseif ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$updated = array( $hook_extra['plugin'] );
		}

		return (bool) array_intersect( self::self_update_plugins(), $updated );
	}

	/**
	 * Plugin files whose update counts as an update to us.
	 *
	 * The self-update rule is "our own code rendered this cached HTML, so it
	 * must not survive the code being replaced". That is true of any add-on
	 * that writes into the same page: an add-on inlines critical CSS, rewrites
	 * stylesheet links and image URLs, and produces the compressed and static
	 * copies, so its update leaves exactly the stale markup this rule exists
	 * to clear. Free cannot name an add-on, so it asks instead.
	 *
	 * Filter: xspeed_self_update_plugins
	 *
	 * Add-ons add their own `plugin_basename( __FILE__ )`. Entries are matched
	 * against the plugin files WordPress reports for the completed update, so
	 * a value that is not a `dir/file.php` basename simply never matches.
	 *
	 * @param string[] $plugins Plugin basenames treated as our own.
	 * @return string[]
	 */
	private static function self_update_plugins(): array {
		$ours = array( plugin_basename( XSPEED_FILE ) );

		/** This filter is documented above. */
		$filtered = apply_filters( 'xspeed_self_update_plugins', $ours );

		// Our own file is merged back afterwards rather than trusted to survive
		// the round trip. A listener that returns null, a bare string, or a
		// list it built from scratch would otherwise drop it, and the plugin
		// would quietly stop exempting its OWN update from the setting — a
		// failure no add-on author would think to test for.
		$claimed = array_filter( is_array( $filtered ) ? $filtered : array(), 'is_string' );

		return array_values( array_unique( array_merge( $ours, array_filter( $claimed ) ) ) );
	}

	/**
	 * Invalidate caches of RENDERED output owned by other plugins.
	 *
	 * purge_all() sweeps only what xSpeed wrote. A page builder that stores
	 * rendered HTML or generated CSS of its own — Elementor's element cache
	 * and `uploads/elementor/css/`, and the equivalents in Beaver / Divi /
	 * Bricks / Oxygen — keeps whatever asset URLs were current when it was
	 * written, and no xSpeed purge has ever reached it.
	 *
	 * That only matters for rewrites that happen DURING render rather than on
	 * the finished page. Minify, combine, lazy-load and resource hints all run
	 * on `xspeed_cache_final_html` or a `template_redirect` buffer — after the
	 * builder has already stored its copy — so nothing they emit can leak.
	 * The CDN module's `wp_get_attachment_url` filter is the one that can.
	 *
	 * Called ONLY from purges where asset URLs themselves can have changed
	 * (a CDN settings write, an explicit Purge All). NOT from purge_all(),
	 * which also runs on every post publish — regenerating every builder CSS
	 * file that often would cost more than it saves, and the builder already
	 * invalidates its own copy for the post being saved.
	 *
	 * @param string $cause Who asked. Threaded through to the listeners and
	 *                      the activity log.
	 * @return string[] Labels of the caches that were actually cleared.
	 */
	public static function purge_render_caches( string $cause = 'manual' ): array {
		/**
		 * Clear render caches belonging to other plugins.
		 *
		 * A listener does its own work and appends a human-readable label for
		 * what it cleared, so the activity log can name it. Returning
		 * `$cleared` unchanged means "nothing of mine is installed" and is the
		 * correct no-op — never a failure.
		 *
		 * Detect the owning plugin by class or constant, not by an
		 * `is_plugin_active()` path check: a renamed plugin folder must not
		 * silently disable the integration.
		 *
		 * @param string[] $cleared Labels of caches cleared so far.
		 * @param string   $cause   Why the purge is happening.
		 */
		$cleared = (array) apply_filters( 'xspeed_purge_third_party_render_caches', array(), $cause );

		// Labels are strings destined for the activity feed. Anything else a
		// third-party listener returns is dropped rather than coerced — a
		// stray `0` or `null` in the log reads as a cache we cleared.
		$cleared = array_values(
			array_filter(
				$cleared,
				static function ( $label ) {
					return is_string( $label ) && '' !== trim( $label );
				}
			)
		);

		if ( ! $cleared ) {
			return $cleared;
		}

		// Logged separately from the page-cache purge above it. "I turned the
		// CDN off and the images are still wrong" is only diagnosable if the
		// feed says which OTHER plugin's cache was regenerated and when.
		Activity_Log::record(
			'cache_purged',
			sprintf( 'Render caches cleared (%s) — %s', $cause, implode( ', ', $cleared ) ),
			Activity_Log::INFO
		);

		return $cleared;
	}

	/**
	 * The per-type purge menu, LiteSpeed-style. Each entry is a cache type
	 * the user can purge individually from the admin-bar dropdown. `visible`
	 * controls whether the item shows (active + licensed module only) — it
	 * NEVER limits Purge All, which always sweeps everything on disk.
	 *
	 * Pro registers its own types (Critical CSS, Unused CSS, …) by filtering
	 * `xspeed_purge_types`, so Free degrades gracefully when Pro is absent.
	 *
	 * @return array<string,array{label:string,visible:bool}>
	 */
	public static function purge_types(): array {
		$minify_on = false;
		if ( class_exists( '\\XSpeed\\Settings_Manager' ) ) {
			$min       = Settings_Manager::get( 'minify' );
			$minify_on = ! empty( $min['minify_css'] ) || ! empty( $min['minify_js'] ) || ! empty( $min['combine_css'] ) || ! empty( $min['combine_js'] );
		}
		// Object cache is "active" when an external object-cache drop-in is in
		// use — the canonical WP signal, independent of our settings option.
		$oc_on = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();

		$types = array(
			'all'    => array(
				'label'   => __( 'Purge All', 'xspeed' ),
				'visible' => true,
			),
			'page'   => array(
				'label'   => __( 'Purge Page / Static Cache', 'xspeed' ),
				'visible' => true,
			),
			'assets' => array(
				'label'   => __( 'Purge CSS / JS Cache', 'xspeed' ),
				'visible' => $minify_on,
			),
			'object' => array(
				'label'   => __( 'Purge Object Cache', 'xspeed' ),
				'visible' => $oc_on,
			),
			'rest'   => array(
				'label'   => __( 'Purge REST Cache', 'xspeed' ),
				'visible' => true,
			),
		);

		/**
		 * Filter the admin-bar purge-type menu. Pro modules add their own
		 * (Critical CSS, Unused CSS, CDN). Adding a type here only adds a
		 * MENU item — purge_type() must know how to handle the same slug.
		 *
		 * @param array $types Map of slug => [label, visible].
		 */
		return (array) apply_filters( 'xspeed_purge_types', $types );
	}

	/**
	 * Purge a single cache type by slug. 'all' delegates to purge_all();
	 * every other slug clears just its own artifacts. Unknown slugs (e.g. a
	 * Pro type) fan out via the `xspeed_purge_type_{slug}` action so the
	 * owning module can handle it. Returns the number of items removed where
	 * countable.
	 *
	 * @param string $type  Cache type slug.
	 * @param string $cause Who asked. Threaded through so the purge log can
	 *                      tell an AI assistant's purge apart from a click —
	 *                      "the cache cleared four times today" is only
	 *                      actionable once you know what kept clearing it.
	 */
	public static function purge_type( string $type, string $cause = 'manual' ): int {
		switch ( $type ) {
			case 'all':
				$count = self::purge_all( $cause );
				// "Purge All" is the user saying they don't trust anything
				// stored anywhere — the one purge that should also reach
				// caches of rendered output we don't own. purge_all() itself
				// deliberately does NOT, because it also runs on every post
				// publish. (See Render_Caches.)
				self::purge_render_caches( $cause );
				return $count;

			case 'page':
				$count = self::purge_pages();
				self::update_stats( array( 'last_purge' => time() ) );
				Cache_Inventory::invalidate();
				self::record_partial_purge( 'page', $cause, $count );
				self::announce_purge( $cause, $count );
				return $count;

			case 'assets':
				if ( class_exists( '\\XSpeed\\Minifier' ) ) {
					Minifier::purge_minified();
				}
				// Deleting min/ without clearing the pages that link it left
				// every cached page pointing at files that no longer exist.
				// WordPress answers the missing asset by 301-ing to its
				// pretty-permalink form and serving the 404 TEMPLATE as
				// `HTTP 200 text/html`, which the browser accepts as a
				// stylesheet and parses to zero rules — no console error, no
				// network failure, no 4xx anywhere in devtools. The pages
				// stayed broken for the rest of the TTL (7 days on
				// Aggressive, up to 30), and the admin who clicked could not
				// see it: they are logged in, so their own requests bypass
				// the page cache and re-render, regenerating the assets as a
				// side effect. Only anonymous visitors were served the stale
				// HTML. (#244)
				//
				// The assets are the pages' dependency, so invalidating them
				// invalidates the pages. Same invariant Cache_GC enforces
				// with is_referenced(): never leave a cached page pointing at
				// an asset that is gone.
				$count = self::purge_pages();
				self::update_stats( array( 'last_purge' => time() ) );
				Cache_Inventory::invalidate();
				self::record_partial_purge( 'assets', $cause, $count );
				self::announce_purge( $cause, $count );
				return $count;

			case 'object':
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}
				self::record_partial_purge( 'object cache', $cause, null );
				return 0;

			case 'rest':
				$count = Rest_Cache::purge();
				self::record_partial_purge( 'REST responses', $cause, $count );
				self::announce_purge( $cause, $count );
				return $count;

			default:
				return self::purge_type_unhandled( $type, $cause );
		}
	}

	/**
	 * Delete this site's cached pages from both the flat and static trees.
	 *
	 * Extracted so the `assets` purge can reuse it: minified assets are a
	 * dependency of the cached HTML, so clearing them must clear the pages
	 * too or the pages are left referencing deleted files (#244).
	 *
	 * @return int Number of page entries removed.
	 */
	private static function purge_pages(): int {
		$count = 0;
		// Scoped to this site — see purge_all(). (#6)
		$scope     = self::current_host_dir();
		$flat_root = XSPEED_CACHE_DIR . '/' . $scope;
		if ( is_dir( $flat_root ) ) {
			foreach ( (array) glob( $flat_root . '/*.html' ) as $f ) {
				wp_delete_file( $f );
				++$count;
			}
			foreach ( (array) glob( $flat_root . '/*.meta' ) as $m ) {
				wp_delete_file( $m );
			}
			foreach ( (array) glob( $flat_root . '/*.br' ) as $b ) {
				wp_delete_file( $b );
			}
			// `*.br` does not match `*.br.size`; a size record outliving its
			// body would later be read against a DIFFERENT sibling's bytes.
			foreach ( (array) glob( $flat_root . '/*.br.size' ) as $b ) {
				wp_delete_file( $b );
			}
		}
		$static_root = XSPEED_CACHE_STATIC_DIR . '/' . self::current_static_scope();
		if ( is_dir( $static_root ) ) {
			$count += self::rmtree_html( $static_root );
		}

		return $count;
	}

	/**
	 * A purge type this class does not own — a Pro or third-party module
	 * registered it via the `xspeed_purge_types` filter, so hand it off.
	 *
	 * @param string $type  Purge-type slug.
	 * @param string $cause Who asked.
	 */
	private static function purge_type_unhandled( string $type, string $cause ): int {
		$event_sequence = self::$purge_event_sequence;
		$hook           = 'xspeed_purge_type_' . $type;
		$has_handler    = false !== has_action( $hook );
		do_action( $hook );
		self::record_partial_purge( $type, $cause, null );

		// Announce, same as the types this class owns. Pro's "Purge Critical
		// CSS" and "Purge Unused CSS" arrive here, and they change what a
		// cached page CONTAINS — critical CSS is inlined into the HTML, so a
		// server cache goes on serving pages with the old styles baked in.
		// Fixing the three Free buttons and leaving these two silent left the
		// same hole for the tier most likely to be using both plugins.
		// (QA #348 round 2, issue 2)
		//
		// Unknown slugs must not turn into a site-wide purge merely because no
		// handler exists. These are the response-changing Pro types Free knows;
		// third parties can declare another through the filter. A registered
		// handler plus this explicit response scope is the handled signal.
		$scope = in_array( $type, array( 'critical-css', 'unused-css' ), true ) ? 'site' : 'none';
		/**
		 * Declare whether a handled custom purge type changes cached responses.
		 *
		 * @since 1.2.3
		 * @param string $scope site/network/none.
		 * @param string $type  Purge-type slug.
		 */
		$scope = (string) apply_filters( 'xspeed_purge_type_response_scope', $scope, $type );
		if ( $has_handler
			&& $event_sequence === self::$purge_event_sequence
			&& in_array( $scope, array( 'site', 'network' ), true )
		) {
			self::announce_purge( $cause, 0, $scope, 'presentation' );
		}

		return 0;
	}

	/**
	 * Tell the server cache that a PARTIAL purge cleared cached responses.
	 *
	 * "Purge Page / Static Cache", "Purge CSS / JS Cache" and "Purge REST
	 * Cache" each delete cached RESPONSES for the whole site, so a cache in
	 * front of PHP is now serving copies xSpeed has just thrown away. Only
	 * "Purge All" announced itself, which left three of the four toolbar
	 * buttons doing exactly what this contract exists to prevent: clearing
	 * our copy while the server kept serving the stale one. The `assets` case
	 * was the sharpest — it deletes the minified bundles too, so LiteSpeed
	 * went on serving pages whose CSS and JS no longer exist. (QA #348)
	 *
	 * Sent as the full-purge shape (`url` null) because that is what happened:
	 * every cached page for this site went, not one address. `object` is not
	 * announced — flushing the object cache changes no rendered response a
	 * server cache could be holding.
	 *
	 * Public because Purge_Runner sweeps the local files itself, through
	 * purge_local(), rather than through purge_all() — so it has to announce
	 * on its own behalf or `wp xspeed purge` and the dashboard button clear
	 * our copy while LiteSpeed keeps serving the stale one.
	 *
	 * @param string $cause   Who asked.
	 * @param int    $removed Entries removed locally.
	 * @param string $scope   Actionable adapter scope.
	 * @param string $intent  Reason rendered responses changed.
	 */
	public static function announce_purge( string $cause, int $removed, string $scope = 'site', string $intent = 'complete' ): void {
		// Announcing is additive: the local sweep has already happened and
		// succeeded. Notification must never be able to turn a working purge
		// into a fatal, so anything the URL helpers do in an unusual context
		// (early boot, a drop-in, a bare test harness) is contained here
		// rather than propagating to the caller.
		if ( ! function_exists( 'home_url' ) || ! function_exists( 'do_action' ) ) {
			return;
		}
		try {
			self::dispatch_purge_event(
				'xspeed_after_purge',
				array(
					'url'     => null,
					'host'    => self::current_purge_host(),
					'path'    => null,
					'cause'   => $cause,
					'removed' => $removed,
					'scope'   => $scope,
					'intent'  => $intent,
					'urls'    => array(),
				)
			);
		} catch ( \Throwable $e ) {
			self::log_purge_listener_error( 'xspeed_after_purge', $e );
		}
	}

	/**
	 * Log a partial purge so the drill-down behind "Last purge" shows every
	 * clear, not only the full ones. Without this a site whose object cache
	 * is flushed on a schedule looks, from the log, like nothing happens.
	 *
	 * @param string   $what  Human label for the slice purged.
	 * @param string   $cause Who asked.
	 * @param int|null $count Items removed, when countable.
	 */
	private static function record_partial_purge( string $what, string $cause, ?int $count ): void {
		$message = null === $count
			? sprintf(
				/* translators: 1: what was purged, 2: cause of the purge. */
				__( 'Purged %1$s (%2$s)', 'xspeed' ),
				$what,
				$cause
			)
			: sprintf(
				/* translators: 1: what was purged, 2: cause of the purge, 3: number of files removed. */
				__( 'Purged %1$s (%2$s) — %3$d file(s) removed', 'xspeed' ),
				$what,
				$cause,
				$count
			);

		Activity_Log::record( 'cache_purged', $message, Activity_Log::INFO );
	}

	/**
	 * Clear the static tree only, leaving the flat cache in place.
	 *
	 * A narrower purge_all() for the case where only the web-server tree can
	 * be wrong: its files are keyed by `{host}{path}` and nothing else, so a
	 * response filed under the wrong path poisons it while the flat cache —
	 * keyed by cache_key(), discriminators included — stays correct. Avoids
	 * throwing away Critical CSS, minified bundles and the object cache to
	 * fix a static-only problem.
	 *
	 * @return int Number of index.html files removed.
	 */
	public static function purge_static_tree(): int {
		return self::rmtree_html( XSPEED_CACHE_STATIC_DIR );
	}

	/**
	 * Recursively delete every `index.html` (and its precompressed
	 * `index.html.br` sibling, if the Pro Brotli module wrote one) plus
	 * empty directories inside the static-cache tree. Used by purge_all().
	 * Returns the number of .html files removed so purge stats stay accurate
	 * across the flat + static caches — .br siblings are not counted
	 * (they're encodings of a page, not pages).
	 */
	/**
	 * Delete a cache file, adding its size to the current sweep's byte
	 * total. filesize() is silenced and re-checked because the file can
	 * vanish between the glob and the unlink — a concurrent purge, or the
	 * cache GC — and a warning there would be noise, not news.
	 *
	 * @param string $file Absolute path inside the cache tree.
	 */
	private static function sweep_delete( string $file ): void {
		$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file may be gone already; see docblock.
		if ( is_int( $size ) ) {
			self::$sweep_bytes += $size;
		}
		wp_delete_file( $file );
	}

	private static function rmtree_html( string $dir ): int {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$removed = 0;
		// SCANDIR_SORT_NONE skips alphabetic sort — we're going to walk
		// the whole tree regardless of order.
		$entries = @scandir( $dir, SCANDIR_SORT_NONE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $entries ) {
			return 0;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$removed += self::rmtree_html( $path );
				// Best-effort empty-dir cleanup; ignore failures (a
				// foreign file inside would block rmdir, which is fine).
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort empty-dir cleanup; WP_Filesystem needs admin credentials we don't have during a normal purge.
				@rmdir( $path );
				continue;
			}
			if ( substr( $entry, -5 ) === '.html' ) {
				self::sweep_delete( $path );
				++$removed;
			} elseif ( substr( $entry, -3 ) === '.br' || substr( $entry, -8 ) === '.br.size' ) {
				// Precompressed sibling (index.html.br) and the record of its
				// length. Remove both so a purge doesn't orphan stale Brotli
				// bodies, or a size record that would later be read against a
				// different sibling's bytes. Not counted.
				self::sweep_delete( $path );
			}
		}
		return $removed;
	}

	/**
	 * Drop a "silence is golden" index.php into a directory so apaches/nginx
	 * with directory listing enabled don't expose cache contents.
	 */
	public static function write_silence( $dir ) {
		$file = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- WP_Filesystem requires admin context for credentials; cache dir setup may run during a frontend page render.
			file_put_contents( $file, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * The raw xspeed_stats option as an array. Keys currently in use:
	 * 'last_purge', 'last_gc', 'gc_removed', 'gc_removed_total'.
	 */
	public static function get_stats_option(): array {
		$stats = get_option( 'xspeed_stats', array() );
		return is_array( $stats ) ? $stats : array();
	}

	/**
	 * Persist stats with autoload disabled — stats are only read in admin
	 * contexts, so there is no reason to inflate every frontend request's
	 * `wp_load_alloptions()` payload.
	 *
	 * MERGES into whatever is already stored. It used to overwrite, which
	 * was harmless while `last_purge` was the only key — with the GC keys
	 * alongside it, a purge would have wiped the GC history and vice versa.
	 */
	public static function update_stats( array $stats ) {
		if ( false === get_option( 'xspeed_stats', false ) ) {
			add_option( 'xspeed_stats', $stats, '', 'no' );
			return;
		}
		update_option( 'xspeed_stats', array_merge( self::get_stats_option(), $stats ) );
	}

	public static function get_stats() {
		$count = 0;
		$size  = 0;
		// This site's entries only — on multisite the tree is shared, so an
		// unscoped count reported the whole network's pages on every
		// subsite's dashboard. (#6)
		$flat_root = XSPEED_CACHE_DIR . '/' . self::current_host_dir();
		if ( is_dir( $flat_root ) ) {
			$files = glob( $flat_root . '/*.html' );
			if ( $files ) {
				$count = count( $files );
				foreach ( $files as $f ) {
					$size += filesize( $f );
				}
			}
		}
		// Drain the HIT-log file BEFORE reading totals. Two serve paths that
		// bypass the normal in-PHP record_hit() append one line per HIT here:
		// the nginx server-level rewrite (see nginx_snippet(), never reaches
		// PHP) and the advanced-cache.php drop-in (runs pre-WordPress, can't
		// reach Hit_Counter). Without this drain both look like a 0% hit-ratio
		// on a perfectly working cache.
		Hit_Counter::collect_nginx_log_hits();

		// Apache/LiteSpeed static-rewrite HITs are served straight from disk
		// by .htaccess and never reach PHP either — but there's no .htaccess
		// equivalent of nginx's access_log directive, so we count them by
		// scanning the web server's own access log incrementally. No-op when
		// the log isn't readable (managed hosts) — see the method docblock.
		Hit_Counter::collect_server_log_hits();

		$stats  = get_option( 'xspeed_stats', array() );
		$totals = Hit_Counter::totals_24h();
		// One read of the ground truth for both fields below: it costs a
		// stat of advanced-cache.php and a tokenize of wp-config.php, and
		// this runs on every dashboard poll.
		$serving = self::page_cache_operational();
		return array(
			'cached_pages' => $count,
			'cache_size'   => $size,
			'last_purge'   => isset( $stats['last_purge'] ) ? (int) $stats['last_purge'] : 0,
			// Rolling 24h cache performance — sourced from Hit_Counter's
			// hourly buckets. The frontend uses hit_ratio to drive the
			// CacheHero stat grid + the Health module's panel.
			'hits_24h'     => $totals['hits'],
			'misses_24h'   => $totals['misses'],
			'hit_ratio'    => $totals['ratio'],
			// Requests kept OUT of the ratio (404s + bots) — surfaced as its own
			// "absorbed N scanner/bot requests" line rather than distorting the
			// cache-performance number. (#118)
			'excluded_24h' => $totals['excluded'],
			// True when an edge cache (Cloudflare) fronts the origin, so hits are
			// absorbed before reaching PHP. The dashboard labels the ratio
			// "origin-layer only" instead of implying it's the full picture. (#118)
			'edge_cache'   => self::edge_cache_detected(),
			/*
			 * Whether the page cache is actually SERVING, as opposed to
			 * switched on in settings. The hero read the setting alone and
			 * announced "Active — serving cached HTML"; a site whose
			 * advanced-cache.php had been taken over by another cache plugin
			 * got that line while every response carried
			 * `X-XSpeed-Cache: BYPASS`. The setting is the user's intent;
			 * this is the outcome, and the dashboard needs both to explain
			 * the difference.
			 */
			'page_cache_serving' => $serving,
			/*
			 * Why not, when intent and outcome disagree. Only computed in
			 * that state — the detector sweep behind it is far more work than
			 * a stats call should do on an ordinary healthy site.
			 */
			'page_cache_blocked_reason' => ( ! $serving && ! empty( Settings::get()['cache_enabled'] ) )
				? ( self::acquisition_blocker() ?? self::not_serving_reason() )
				: null,
		);
	}

	/**
	 * Why the cache is not serving, when nothing REFUSES to enable it.
	 *
	 * acquisition_blocker() answers "may we take the field", and since a
	 * foreign drop-in became takeable it answers null on a site where another
	 * plugin is nonetheless holding that file. Intent and outcome still
	 * disagree there, and the dashboard was left reporting the symptom -- not
	 * serving -- with no reason under it, which is exactly the state a user
	 * cannot act on.
	 *
	 * So this names the holder and says what to do: enabling takes it over.
	 */
	private static function not_serving_reason(): ?string {
		$owner = self::dropin_owner();
		if ( self::DROPIN_FOREIGN !== $owner && self::DROPIN_UNREADABLE !== $owner ) {
			return null;
		}

		if ( self::DROPIN_UNREADABLE === $owner ) {
			return __( 'advanced-cache.php cannot be read, so xSpeed cannot tell whose page cache is installed.', 'xspeed' );
		}

		$label = Page_Cache_Detector::dropin_owner_label();
		return $label
			? sprintf(
				/* translators: %s: the page-caching plugin that owns advanced-cache.php. */
				__( '%s is serving the page cache. Turn the xSpeed cache off and on again to take it over.', 'xspeed' ),
				$label
			)
			: __( 'Another plugin is serving the page cache. Turn the xSpeed cache off and on again to take it over.', 'xspeed' );
	}

	/**
	 * Whether the current request should be kept OUT of the cache hit/miss
	 * ratio: a genuine 404, or a known bot / scanner. Runs at template_redirect
	 * time, so is_404() is resolved. (#118)
	 */
	private static function miss_is_excluded(): bool {
		if ( function_exists( 'is_404' ) && is_404() ) {
			return true;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		return Hit_Counter::is_bot_ua( $ua );
	}

	/**
	 * Whether an edge cache fronts this origin, so an unknown share of hits
	 * is served there and never counted here — which makes the origin ratio a
	 * partial view the dashboard has to label as such. (#118)
	 *
	 * This used to mean "the Cloudflare module is switched on", which answered
	 * no for every site fronted by anything else, and no for a site on
	 * Cloudflare that had never opened our Cloudflare panel. Both of those
	 * sites had their ratio presented as the whole story. Edge_Provider knows
	 * better and knows it per request, so ask it.
	 */
	private static function edge_cache_detected(): bool {
		return Edge_Provider::NONE !== Edge_Provider::detect()['confidence'];
	}

	/**
	 * Apply the user's enable/disable choice. Called from the REST toggle
	 * endpoint, which is gated by current_user_can( 'manage_options' ) and
	 * a verified REST nonce.
	 *
	 * This is the only path that ENABLES caching — a drop-in is never
	 * created for a user who hasn't opted in, which is the guideline that
	 * matters (a plugin must not install drop-ins or edit wp-config.php
	 * on a fresh activation). RESTORING the drop-in for a site that
	 * already has cache_enabled = true is a different act and is handled
	 * by restore_dropin_if_enabled() on activation and auto_heal() at
	 * runtime; without it every plugin update silently un-caches the site.
	 *
	 * Enabling is gated on acquisition_blocker(): if another plugin owns the
	 * drop-in, or WP_CACHE is written in a form we must not rewrite, nothing
	 * is written and the returned state carries `blocked` + a reason the
	 * caller can show. Callers must persist `cache_enabled` from the returned
	 * `enabled`, never from what they asked for.
	 *
	 * @param bool $enable User's choice.
	 * @return array{
	 *     enabled: bool,
	 *     blocked: bool,
	 *     blocked_reason: ?string,
	 *     dropin_installed: bool,
	 *     wp_cache_constant: bool,
	 *     wp_config_writable: bool,
	 *     manual_snippet: ?string
	 * }
	 */
	public static function toggle( $enable, bool $consented = true ) {
		Page_Cache_Detector::invalidate();
		$expected = Page_Cache_Detector::inspect()['revision'];
		/** Diagnostic seam; changing the expected revision can only force a safe refusal. */
		$expected = (string) apply_filters( 'xspeed_page_cache_expected_revision', $expected );
		$lock     = self::page_cache_lock();
		if ( ! is_resource( $lock ) ) {
			return self::blocked_toggle_state( __( 'Could not lock page-cache ownership. Try again.', 'xspeed' ) );
		}
		try {
			Page_Cache_Detector::invalidate();
			$fresh = Page_Cache_Detector::inspect()['revision'];
			if ( ! hash_equals( (string) $expected, (string) $fresh ) ) {
				return self::blocked_toggle_state( __( 'Page-cache ownership changed while xSpeed was checking it. Nothing was changed; try again.', 'xspeed' ) );
			}
			$state = self::toggle_unlocked( (bool) $enable, $consented );
			return $state;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/** Run the page-cache mutation while toggle() owns the scoped lock. */
	/**
	 * @param bool $consented The user asked for this in the dashboard, so a
	 *                        foreign drop-in may be taken over. False on the
	 *                        unattended paths, which stand down instead.
	 */
	private static function toggle_unlocked( bool $enable, bool $consented = true ) {
		$enable = (bool) $enable;

		if ( $enable ) {
			/*
			 * Preflight. The drop-in and the WP_CACHE define are shared,
			 * single-occupancy state; if we do not own them, no part of this
			 * runs — not the drop-in, not wp-config.php, not the rewrite
			 * block. Refusing whole is the point: a partial enable leaves the
			 * site claiming a cache it cannot serve.
			 *
			 * Every caller routes through here (REST, onboarding, MCP, CLI,
			 * the optimize runner, Pro's migration), so the gate lives here
			 * rather than being re-implemented at each entry point.
			 *
			 * Except when there is nothing to acquire. A site where we
			 * already own the drop-in and are already serving is being asked
			 * to stay as it is, and the gate answers a different question —
			 * "is the field free to take" — which a merely ACTIVE competitor
			 * makes false. So "make sure caching is on", from an AI agent,
			 * the optimize runner or Pro's migration, came back as a refusal
			 * telling the user to deactivate a plugin on a site that was
			 * caching perfectly. The dashboard never saw it, because nobody
			 * presses Enable on a cache that is already enabled.
			 *
			 * Only the GATE is skipped. The writes below still run, and every
			 * one of them is individually idempotent — which matters, because
			 * this is the path CacheModule re-bakes the drop-in through when
			 * an exclusion rule or the TTL changes (#240, #251), and the path
			 * auto_heal() restores a stripped WP_CACHE through. Returning
			 * early here left both of those doing nothing at all, silently,
			 * on exactly the healthy sites this branch is about.
			 */
			$reasserting = self::page_cache_operational() && self::DROPIN_XSPEED === self::dropin_owner();
			$blocker     = $reasserting ? null : self::acquisition_blocker();

			/*
			 * Taking over another plugin's drop-in needs the user to have
			 * asked for it. On the dashboard they did -- they clicked the
			 * switch, having been told whose file it is. The UNATTENDED
			 * callers have no such click: restore_dropin_if_enabled() runs
			 * after a plugin update and auto_heal() on an admin page load,
			 * both from nothing more than `cache_enabled` still being true.
			 *
			 * A competitor installed since that flag was set would have its
			 * page cache seized by a background repair, which is the silent
			 * acquisition this plugin refuses to perform. So those callers
			 * pass $consented = false and stand down instead.
			 */
			if ( null === $blocker && ! $consented && self::DROPIN_FOREIGN === self::dropin_owner() ) {
				// Name the owner. This string is rendered by host plugins
				// through Host::enable_page_cache(), and an unnamed refusal
				// is what made every host invent its own explanation.
				$owner_label = Page_Cache_Detector::dropin_owner_label();
				return self::blocked_toggle_state(
					$owner_label
						? sprintf(
							/* translators: %s: the page-caching plugin that owns advanced-cache.php. */
							__( '%s owns advanced-cache.php, so xSpeed left it alone. Enable the cache from the xSpeed dashboard to take it over.', 'xspeed' ),
							$owner_label
						)
						: __( 'Another plugin owns advanced-cache.php, so xSpeed left it alone. Enable the cache from the xSpeed dashboard to take it over.', 'xspeed' )
				);
			}
			if ( null !== $blocker ) {
				Activity_Log::record(
					'cache_enable_blocked',
					'Cache not enabled — ' . $blocker,
					Activity_Log::WARN
				);

				return self::blocked_toggle_state( $blocker );
			}

			$dropin_path   = WP_CONTENT_DIR . '/advanced-cache.php';
			$config_path   = self::wp_config_path();
			$dropin_before = file_exists( $dropin_path ) ? self::read_file( $dropin_path ) : null;
			$config_before = '' !== $config_path ? self::read_file( $config_path ) : null;
			$dropin_ok     = self::install_dropin();
			if ( ! $dropin_ok ) {
				$partial = self::read_file( $dropin_path );
				if ( is_string( $partial ) && xspeed_has_canonical_dropin_signature( $partial ) ) {
					self::rollback_page_cache_artifacts( $dropin_path, $dropin_before, $partial, $config_path, $config_before, null );
				}
				/*
				 * Preflight said the field was clear, so this is a filesystem
				 * failure (or a drop-in that appeared in between). Without the
				 * drop-in there is no cache to enable, and persisting
				 * cache_enabled anyway is what produced sites reporting a
				 * healthy cache while serving every request uncached.
				 */
				$reason = __( 'Could not write wp-content/advanced-cache.php. Check filesystem permissions.', 'xspeed' );
				Activity_Log::record(
					'cache_enable_blocked',
					'Cache not enabled — ' . $reason,
					Activity_Log::WARN
				);

				return array(
					'enabled'            => false,
					'blocked'            => true,
					'blocked_reason'     => $reason,
					'dropin_installed'   => false,
					'wp_cache_constant'  => false,
					'rewrite_installed'  => false,
					'wp_config_writable' => self::wp_config_writable(),
					'manual_snippet'     => null,
					'nginx_snippet'      => self::nginx_snippet(),
					'nginx_server_block' => self::full_nginx_server_block(),
				);
			}

			$dropin_written = self::read_file( $dropin_path );
			self::set_wp_cache_constant( true );
			$config_written = '' !== $config_path ? self::read_file( $config_path ) : null;
			Page_Cache_Detector::invalidate();
			$dropin_ours    = self::DROPIN_XSPEED === self::dropin_owner();
			$constant_state = self::wp_cache_define_state();
			$constant_ok    = 'true' === $constant_state;

			/*
			 * A wp-config.php we cannot write at all is a supported state, not
			 * a failed transaction. Plenty of managed hosts ship the file
			 * read-only; there the drop-in is ours and installed, the cache
			 * works the moment WP_CACHE exists, and the one line to paste
			 * comes back as `manual_snippet`. Rolling back instead left those
			 * hosts unable to turn the page cache on by any route — including
			 * when the user had already pasted the define, since the write
			 * fails on an unwritable file whatever value is already there.
			 *
			 * `undefined` ONLY. `false` looks eligible — this method would
			 * have rewritten it — but the snippet we hand back cannot work
			 * there: the file already says `define( 'WP_CACHE', false )`, the
			 * first define() call wins, and a user who pastes our line via
			 * FTP ends up with a cache that never serves AND a `duplicate`
			 * wp-config that blocks every future toggle in both directions.
			 * They have to edit the existing line, which means refusing here
			 * and saying so. `duplicate` and `dynamic` are refused by
			 * acquisition_blocker() before we get here, and if one appears in
			 * the race window it must still fail closed.
			 */
			$manual_mode = ! $constant_ok
				&& 'undefined' === $constant_state
				&& ! self::can_write_wp_config();

			if ( ! $dropin_ours || ( ! $constant_ok && ! $manual_mode ) ) {
				if ( ! self::can_write_wp_config() ) {
					$reason = 'false' === $constant_state
						? __( "wp-config.php is not writable and already contains define( 'WP_CACHE', false ). Change that line to true — adding a second one would leave the cache off and block xSpeed from changing it again.", 'xspeed' )
						: __( 'xSpeed could not verify the complete page-cache write, and wp-config.php is not writable. Its changes were rolled back.', 'xspeed' );
				} else {
					$reason = __( 'xSpeed could not verify the complete page-cache write. Its changes were rolled back.', 'xspeed' );
				}
				self::rollback_page_cache_artifacts( $dropin_path, $dropin_before, $dropin_written, $config_path, $config_before, $config_written );
				return self::blocked_toggle_state( $reason );
			}
			$wp_config_ok = $constant_ok;
			$rewrite_ok   = self::install_rewrite();
			self::ensure_hits_log_file();
			self::sync_mobile_flag();
			$snippet      = $wp_config_ok ? null : "define( 'WP_CACHE', true );";
			Settings::update( array( 'cache_enabled' => true ) );
			if ( empty( Settings::get()['cache_enabled'] ) ) {
				self::remove_rewrite();
				self::rollback_page_cache_artifacts( $dropin_path, $dropin_before, $dropin_written, $config_path, $config_before, $config_written );
				delete_option( 'xspeed_page_cache_ownership_receipt' );
				return self::blocked_toggle_state( __( 'xSpeed could not save the page-cache setting. Its file changes were rolled back.', 'xspeed' ) );
			}

			/*
			 * Only when this call actually changed something. auto_heal() runs
			 * the enable transaction on every admin_init, and an unconditional
			 * entry filled the 50-slot log with identical "Cache enabled" lines
			 * within 50 wp-admin page loads, evicting every real event — plus
			 * an option write per admin request. The sentence is also false
			 * when nothing was installed.
			 */
			if ( $dropin_written !== $dropin_before || $config_written !== $config_before ) {
				Activity_Log::record(
					'cache_enabled_event',
					$wp_config_ok
						? 'Cache enabled. Drop-in installed, WP_CACHE constant set.'
						: 'Cache enabled. Drop-in installed; wp-config.php not writable — add the WP_CACHE snippet manually.',
					$wp_config_ok ? Activity_Log::SUCCESS : Activity_Log::WARN
				);
			}

			return array(
				'enabled'            => true,
				'blocked'            => false,
				'blocked_reason'     => null,
				'dropin_installed'   => (bool) $dropin_ok,
				'wp_cache_constant'  => (bool) $wp_config_ok,
				'rewrite_installed'  => (bool) $rewrite_ok,
				'wp_config_writable' => self::wp_config_writable(),
				'manual_snippet'     => $snippet,
				'nginx_snippet'      => self::nginx_snippet(),
				// Unified server-block snippet aggregating every enabled
				// module's directives — the same value the dashboard and
				// Health insight render. The wizard shows this so all three
				// surfaces stay in lockstep. Null on non-nginx hosts.
				'nginx_server_block' => self::full_nginx_server_block(),
			);
		}

		/*
		 * Whose advanced-cache.php is on disk decides how much of the disable
		 * below may run. Read it once, before anything is touched.
		 */
		$owner    = self::dropin_owner();
		$not_ours = self::DROPIN_FOREIGN === $owner || self::DROPIN_UNREADABLE === $owner;
		if ( ! self::set_wp_cache_constant( false ) ) {
			/*
			 * The mirror of the enable path. A wp-config.php nobody can write
			 * does not trap the user in a cache they turned off: WP_CACHE on
			 * its own does nothing once advanced-cache.php is gone, and core
			 * simply skips the missing drop-in. Refusing here left the
			 * read-only managed hosts able to enable the page cache and never
			 * able to disable it again.
			 *
			 * A drop-in that is not ours reaches the same conclusion by a
			 * different road. WP_CACHE is then the switch for THEIR cache, so
			 * set_wp_cache_constant() refuses it — correctly, and permanently,
			 * because nothing the user does to xSpeed will make that file ours
			 * again. Treating that refusal as a failed disable was a trap with
			 * no exit: install any competing cache plugin while xSpeed's cache
			 * was on, and xSpeed's toggle could never be turned off again,
			 * while the dashboard went on claiming a cache that was serving
			 * nothing. Turning xSpeed off is entirely within our own state —
			 * our setting, our rewrite block — so it proceeds, and their
			 * constant and their file are left exactly as they are.
			 */
			/*
			 * Every reason set_wp_cache_constant() refuses is structural
			 * except one, and the exception is the only one worth blocking
			 * on. It will not touch a constant it cannot prove is ours; it
			 * will not rewrite a define it cannot read as a literal —
			 * duplicate, dynamic, or inside a conditional; and it cannot
			 * write a file the filesystem will not let it write. None of
			 * those improve on a retry, and all of them leave a WP_CACHE
			 * that does nothing once our drop-in is gone. What is left — our
			 * own constant, in a shape we can rewrite, in a file we can
			 * write, and the write still failed — is a real I/O failure, and
			 * that one still refuses so the user is not told a cache was
			 * turned off while it goes on serving.
			 *
			 * The proof, not the drop-in, is the test. A user who pasted our
			 * manual snippet on a locked-down host has a WP_CACHE line with
			 * no receipt on it; if their drop-in later goes missing, we can
			 * never prove that line is ours, so refusing left the toggle
			 * stuck on with no way out but enabling first and disabling
			 * again. Nothing loads a drop-in that is not there, so the line
			 * is inert either way and the disable proceeds without it.
			 */
			$leave_it = ! self::wp_cache_define_is_ours_to_remove( $owner )
				|| ! in_array( self::wp_cache_define_state(), array( 'true', 'false', 'undefined' ), true )
				|| ! self::can_write_wp_config();
			if ( ! $leave_it ) {
				return self::blocked_toggle_state( __( 'xSpeed could not safely remove its WP_CACHE setting. The cache remains enabled.', 'xspeed' ) );
			}
		}
		self::remove_dropin();
		if ( self::DROPIN_XSPEED === self::dropin_owner() ) {
			// Put WP_CACHE back, and say so if we could not. Reporting a
			// hardcoded `enabled: true` here claimed a working cache on a
			// site whose constant we had just failed to restore.
			// Put WP_CACHE back, then read the outcome off disk rather than
			// trusting the write's return value — a write can report failure
			// for a value that was already correct, and the question the
			// caller needs answered is whether the cache serves.
			self::set_wp_cache_constant( true );
			return self::blocked_toggle_state(
				self::page_cache_operational()
					? __( 'xSpeed could not remove its page-cache drop-in. The cache remains enabled.', 'xspeed' )
					: __( 'xSpeed could not remove its page-cache drop-in, and could not put WP_CACHE back. The cache is not serving; check wp-config.php before changing the page cache again.', 'xspeed' )
			);
		}
		self::remove_rewrite();
		/*
		 * The .htaccess block serves cached HTML straight off disk without
		 * ever reaching PHP, so a block we failed to remove keeps answering
		 * requests from a cache the user just turned off — and nothing else
		 * in this method can stop it. remove_rewrite() also returns false
		 * when there is no .htaccess to clean, which is the ordinary case,
		 * so ask the file rather than trust the return value.
		 */
		if ( self::rewrite_installed() ) {
			if ( $not_ours ) {
				// Nothing to roll back — under a foreign drop-in this method
				// removed no drop-in and wrote no constant, and it could not
				// put either back if it wanted to. Say what is actually left.
				return self::blocked_toggle_state( __( 'xSpeed could not remove its rewrite rules from .htaccess, which would keep serving cached pages. Remove the xSpeed block from .htaccess by hand before turning the page cache off.', 'xspeed' ) );
			}
			/*
			 * Roll the disable back. Both calls can fail — a filesystem that
			 * would not let us remove the block may not let us write the
			 * drop-in either — and discarding their results reported an
			 * enabled cache over a site left with no drop-in and no
			 * constant. Fall through to the default state so the artifact
			 * fields are read from disk rather than asserted.
			 */
			self::install_dropin();
			self::set_wp_cache_constant( true );
			// Both of those can fail — a filesystem that would not let us
			// remove the block may not let us write the drop-in either — so
			// the message follows what is on disk afterwards, not what the
			// calls returned.
			return self::blocked_toggle_state(
				self::page_cache_operational()
					? __( 'xSpeed could not remove its rewrite rules from .htaccess, which would keep serving cached pages. The cache remains enabled.', 'xspeed' )
					: __( 'xSpeed could not remove its rewrite rules from .htaccess, and could not restore the drop-in it had just removed. The cache is not serving, and the site may still return stale cached pages until the xSpeed block is removed from .htaccess by hand.', 'xspeed' )
			);
		}
		// Drop the device-bucket marker too — with the drop-in gone there's
		// nothing left to read it, and leaving it behind would dirty a fresh
		// re-enable (and leaks across test runs).
		self::sync_mobile_flag( false );
		Settings::update( array( 'cache_enabled' => false ) );
		if ( ! empty( Settings::get()['cache_enabled'] ) ) {
			if ( $not_ours ) {
				// Same as above: there is nothing of ours on disk to restore.
				return self::blocked_toggle_state( __( 'xSpeed could not save the disabled state.', 'xspeed' ) );
			}
			self::install_dropin();
			self::set_wp_cache_constant( true );
			return self::blocked_toggle_state( __( 'xSpeed could not save the disabled state. The page cache was restored.', 'xspeed' ) );
		}

		// A WP_CACHE we could not remove because wp-config.php is read-only
		// is left behind deliberately (see above) — say so rather than
		// reporting a constant that is still in the file as gone.
		$constant_left = 'true' === self::wp_cache_define_state();
		/*
		 * Say why the constant is still there, because there are now three
		 * different reasons and they call for different advice. Keyed off the
		 * same facts $leave_it was, so the log cannot drift from the decision
		 * it is describing — it did, briefly, and reported a wp-config.php as
		 * unwritable when the real reason was that we could not prove the
		 * line was ours.
		 */
		if ( self::DROPIN_UNREADABLE === $owner ) {
			$log_message = 'Cache disabled. advanced-cache.php could not be read, so it and the WP_CACHE setting were left untouched.';
		} elseif ( $not_ours ) {
			$log_message = 'Cache disabled. Another plugin owns advanced-cache.php, so its drop-in and its WP_CACHE setting were left untouched.';
		} elseif ( ! $constant_left ) {
			$log_message = 'Cache disabled. Drop-in removed.';
		} elseif ( ! self::wp_cache_define_is_ours_to_remove( $owner ) ) {
			$log_message = 'Cache disabled. WP_CACHE was left in place — it carries no proof xSpeed wrote it, and it does nothing without a drop-in.';
		} elseif ( ! self::can_write_wp_config() ) {
			$log_message = 'Cache disabled. Drop-in removed; wp-config.php not writable, so WP_CACHE was left in place (harmless without the drop-in).';
		} else {
			$log_message = 'Cache disabled. Drop-in removed; WP_CACHE was left in place (harmless without the drop-in).';
		}
		Activity_Log::record(
			'cache_disabled_event',
			$log_message,
			$constant_left ? Activity_Log::WARN : Activity_Log::INFO
		);

		return array(
			'enabled'            => false,
			'blocked'            => false,
			'blocked_reason'     => null,
			'dropin_installed'   => false,
			'wp_cache_constant'  => $constant_left,
			'rewrite_installed'  => false,
			'wp_config_writable' => self::wp_config_writable(),
			'manual_snippet'     => null,
			'nginx_snippet'      => self::nginx_snippet(),
			'nginx_server_block' => self::full_nginx_server_block(),
		);
	}

	/** Acquire the local lock that serializes page-cache ownership changes. */
	private static function page_cache_lock() {
		$path = WP_CONTENT_DIR . '/.xspeed-page-cache.lock';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- flock requires a local handle; failure is a safe blocked result.
		$lock = @fopen( $path, 'c+' );
		if ( ! is_resource( $lock ) || ! flock( $lock, LOCK_EX ) ) {
			return false;
		}
		return $lock;
	}

	/**
	 * Build the stable response shape for a refused transaction.
	 *
	 * The artifact fields report what is ON DISK, not zeros. A refusal means
	 * xSpeed changed nothing — on a site already running our cache that is
	 * exactly the state where the drop-in and WP_CACHE are both still in
	 * place and still serving hits. Hardcoding false told the dashboard the
	 * cache had been dismantled every time a refusal was returned.
	 */
	private static function blocked_toggle_state( string $reason ): array {
		/*
		 * `enabled` answers ONE question: is the page cache operational right
		 * now. Not what was asked for, and not what the option says.
		 *
		 * WordPress loads advanced-cache.php only when WP_CACHE is truthy, so
		 * those two files together are the whole answer, and reading them is
		 * the only source that cannot go stale. Both of the alternatives were
		 * tried here and both produced wrong answers on real paths: a
		 * hardcoded false told a caller the cache had gone away on a site
		 * still serving hits, and the persisted setting told a caller the
		 * cache was healthy after a rollback had just removed the artifacts
		 * — the option is not written until the end of the transaction, so
		 * mid-transaction it is stale by construction.
		 *
		 * Deliberately not a parameter. Every branch that got to choose its
		 * own answer eventually chose wrong.
		 */
		return array(
			'enabled'            => self::page_cache_operational(),
			'blocked'            => true,
			'blocked_reason'     => $reason,
			'dropin_installed'   => self::DROPIN_XSPEED === self::dropin_owner(),
			'wp_cache_constant'  => 'true' === self::wp_cache_define_state(),
			'rewrite_installed'  => self::rewrite_installed(),
			'wp_config_writable' => self::wp_config_writable(),
			'manual_snippet'     => null,
			'nginx_snippet'      => self::nginx_snippet(),
			'nginx_server_block' => self::full_nginx_server_block(),
		);
	}

	/**
	 * The wp-config.php line a user must paste, or null when none is needed.
	 *
	 * Non-null only where the drop-in is ours and WP_CACHE is not set to true
	 * in a file we can write — the read-only managed host. Everywhere else the
	 * constant is ours to manage and there is nothing to ask for.
	 */
	public static function manual_wp_cache_snippet(): ?string {
		if ( self::DROPIN_XSPEED !== self::dropin_owner() ) {
			return null;
		}
		if ( 'true' === self::wp_cache_define_state() ) {
			return null;
		}
		return self::wp_config_writable() ? null : "define( 'WP_CACHE', true );";
	}

	/**
	 * Is the page cache serving right now?
	 *
	 * Two things decide it, and `WP_CACHE` is not one of them.
	 *
	 * xSpeed serves a cached page from `template_redirect` whenever the
	 * setting is on — see the `HIT (php)` mark on that path, which exists
	 * precisely for "the drop-in isn't loaded". `advanced-cache.php` and the
	 * `WP_CACHE` constant that loads it are the FAST path: they answer before
	 * WordPress boots, which is worth a lot of milliseconds and nothing at
	 * all to the question of whether pages are being served from cache.
	 *
	 * Conflating the two reported a dead cache over a live one. On a managed
	 * host with an unwritable wp-config.php — the exact case the manual
	 * snippet exists for — one card said "Your cache works on every request",
	 * "On, but not serving", "nothing will be cached until you add this line"
	 * and "hit ratio 67%", all at once, and told the user to edit a file they
	 * have no permission to write. The released 1.2.1 reported that site as
	 * active, correctly.
	 *
	 * So: the setting, and whether anyone else holds the drop-in. A foreign
	 * drop-in answers before WordPress loads us, so ours never runs and we
	 * genuinely are not serving. An unreadable one we must assume the same of.
	 * Everything else — our drop-in, or none at all — serves.
	 *
	 * Public because it is part of the host-plugin contract — see Host. A
	 * plugin that installed xSpeed needs to be able to say whether the cache
	 * it asked for is actually serving, and no combination of settings reads
	 * answers that.
	 */
	public static function page_cache_operational(): bool {
		$settings = Settings::get();
		if ( empty( $settings['cache_enabled'] ) ) {
			return false;
		}
		$owner = self::dropin_owner();
		return self::DROPIN_FOREIGN !== $owner && self::DROPIN_UNREADABLE !== $owner;
	}

	/** Restore exact snapshots only while disk still matches our own write. */
	private static function rollback_page_cache_artifacts( string $dropin_path, ?string $dropin_before, ?string $dropin_written, string $config_path, ?string $config_before, ?string $config_written ): void {
		// Roll back only files that still carry xSpeed's just-written state.
		if ( null !== $dropin_written && hash_equals( $dropin_written, (string) self::read_file( $dropin_path ) ) ) {
			if ( null === $dropin_before ) {
				wp_delete_file( $dropin_path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- Exact compare-and-swap rollback under the scoped lock.
				file_put_contents( $dropin_path, $dropin_before );
			}
		}
		if ( '' !== $config_path && null !== $config_before && null !== $config_written && hash_equals( $config_written, (string) self::read_file( $config_path ) ) && self::wp_cache_receipt_matches_source( $config_written ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- Exact compare-and-swap rollback under the scoped lock.
			file_put_contents( $config_path, $config_before );
		}
	}

	/**
	 * Check wp-config.php writability via WP_Filesystem. Plugin Check flags
	 * direct is_writable() under WordPress.WP.AlternativeFunctions.
	 */
	private static function wp_config_writable() {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		return $wp_filesystem ? (bool) $wp_filesystem->is_writable( ABSPATH . 'wp-config.php' ) : false;
	}

	/**
	 * Nginx server-block snippet mirroring the Apache rewrite block.
	 * We never auto-write nginx config — it sits outside the WordPress
	 * root and is owned by the server admin — but the dashboard
	 * surfaces this snippet when nginx is detected so the admin can
	 * paste it once and unlock the same PHP-bypass speedup we get on
	 * Apache / LiteSpeed via .htaccess.
	 *
	 * Returns null when the server isn't nginx (no point showing it).
	 */
	/**
	 * Create wp-content/cache/xspeed/hits.log as an empty file so the
	 * server-level rewrite's `access_log` directive has somewhere to
	 * write on first request. Idempotent — touches an existing file
	 * without disturbing accumulated lines. Called from Cache::toggle()
	 * on enable and from auto_heal() when the file is missing.
	 *
	 * Permissions matter here. The file is created by PHP-FPM (often uid
	 * www-data), but the nginx process that appends HIT lines may run as a
	 * DIFFERENT uid — on multi-container hosts (e.g. xclude/Kinsta: nginx in
	 * its own container as uid `nginx`, PHP-FPM in another as `www-data`)
	 * they don't share a user at all. A default-umask 0644 file is then
	 * unwritable by nginx, the access_log write silently fails, and the
	 * dashboard shows a 0% hit ratio even though static HITs are serving.
	 * So we widen the dir to 0777 and the file to 0666 — group/other write —
	 * so whatever uid nginx runs as can append. The file holds HIT request
	 * lines and must be protected like an access log: paths and queries can
	 * contain sensitive values.
	 */
	/**
	 * Directory holding the nginx hit log. Lives under uploads/, NOT the
	 * cache dir — uninstall.php and a cache purge both delete the cache
	 * dir, which would orphan the pasted nginx `access_log` directive's
	 * parent directory and make `nginx -t` fail [emerg], taking down every
	 * vhost on the host (FBS-82478). uploads/ always exists, isn't a
	 * plugin-managed cache dir, and is never deleted on uninstall — so the
	 * directive's target dir survives both, and nginx (which creates a
	 * missing log FILE but not a missing DIR) can always open it.
	 *
	 * Falls back to the cache dir only if uploads is somehow unavailable.
	 */
	public static function hits_log_dir(): string {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );
			if ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
				return rtrim( (string) $uploads['basedir'], '/' ) . '/xspeed';
			}
		}
		return XSPEED_CACHE_DIR;
	}

	/** Absolute path to the nginx hit log file. */
	public static function hits_log_path(): string {
		return self::hits_log_dir() . '/hits.log';
	}

	/**
	 * Sync the drop-in's mobile-bucket flag file with the `mobile_separate`
	 * setting. The drop-in (advanced-cache.php) runs before WordPress loads,
	 * so it can't read the option — instead it checks for a zero-byte
	 * `.mobile-separate` marker next to the cache files. When the setting is
	 * on we touch the marker; when off we remove it. The drop-in's cache_key
	 * computation keys off the marker's presence so its '|m'/'|d' device
	 * bucket stays in lockstep with Cache::cache_key().
	 *
	 * Without this, turning on mobile_separate made Cache::store() write keys
	 * with a '|d'/'|m' suffix the drop-in never reproduced — so the drop-in's
	 * file_exists() always missed, every HIT fell through to a full WP boot,
	 * and the fast pre-WP path was silently dead.
	 *
	 * @param bool|null $enabled Force a state; null reads the current setting.
	 */
	/**
	 * Write the subdirectory-multisite path list the drop-in needs to work
	 * out which blog a request belongs to.
	 *
	 * The drop-in runs before WordPress, so it cannot call is_multisite()
	 * or get_blog_details(). It can only see REQUEST_URI — so we persist the
	 * network's blog paths (one per line, longest first) next to the cache
	 * files, exactly as sync_mobile_flag() persists the device flag. The
	 * drop-in prefix-matches the URI against that list to pick the same
	 * bucket Cache::current_host_dir() picks. (#6)
	 *
	 * No file is written for a single site or a subdomain network — there
	 * the host alone identifies the blog and the bucket carries no prefix.
	 */
	public static function sync_site_paths(): void {
		$file = XSPEED_CACHE_DIR . '/.site-paths';

		$needed = function_exists( 'is_multisite' ) && is_multisite()
			&& ( ! function_exists( 'is_subdomain_install' ) || ! is_subdomain_install() );

		if ( ! $needed ) {
			if ( file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- plain marker removal; non-fatal.
				@unlink( $file );
			}
			return;
		}

		if ( ! function_exists( 'get_sites' ) ) {
			return;
		}

		$paths = array();
		foreach ( get_sites( array( 'number' => 0 ) ) as $site ) {
			$prefix = self::path_prefix_segment( (string) $site->path );
			if ( '' !== $prefix ) {
				// Store the raw path so the drop-in can prefix-match a URI,
				// alongside the segment it maps to.
				$paths[ trim( (string) $site->path, '/' ) ] = $prefix;
			}
		}

		if ( empty( $paths ) ) {
			if ( file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
				@unlink( $file );
			}
			return;
		}

		// Longest path first so /a/b wins over /a.
		uksort(
			$paths,
			static function ( $x, $y ) {
				return strlen( (string) $y ) <=> strlen( (string) $x );
			}
		);

		$lines = array();
		foreach ( $paths as $raw => $segment ) {
			$lines[] = $raw . '|' . $segment;
		}

		if ( ! is_dir( XSPEED_CACHE_DIR ) && ! wp_mkdir_p( XSPEED_CACHE_DIR ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- read by the pre-WP drop-in; WP_Filesystem needs admin credentials unavailable here.
		file_put_contents( $file, implode( "\n", $lines ), LOCK_EX );
	}

	/**
	 * Compile `ignored_query_params` into a regex the DROP-IN can use.
	 *
	 * Tracking traffic was cached but never served fast. should_cache()
	 * learned to allow `?utm_source=…` through and cache_key() strips the
	 * query, so `/post` and `/post?utm_source=x` share one entry — but the
	 * drop-in still bailed on ANY query string, so every visitor from an
	 * email or ad campaign paid a full WordPress boot to be handed a file
	 * that was already on disk. On a marketing site that is most of the
	 * paid traffic taking the slowest path. (#13)
	 *
	 * The drop-in runs before WordPress, so it cannot read the option or
	 * call Glob_Matcher. It gets a precompiled alternation instead, written
	 * next to the cache files exactly as sync_mobile_flag() writes the
	 * device flag. Regenerated whenever cache settings are saved.
	 *
	 * Only the KEYS matter: a param whose name is on the list contributes
	 * nothing to the response, so the entry keyed without it is correct.
	 * Anything not on the list means the drop-in must stand down and let
	 * PHP decide — the file is deleted rather than left stale when the
	 * list is empty, so a missing sidecar always fails safe.
	 */
	public static function sync_query_allowlist(): void {
		$file = XSPEED_CACHE_DIR . '/.ignored-query-params';

		/*
		 * Stored read, not Settings_Manager::get() — this runs from boot(),
		 * before translation is legal (see stored_cache_opts()).
		 *
		 * A raw read applies no schema defaults, and this field's default is a
		 * long tracking-parameter list, NOT empty. Falling back to array()
		 * would strip that whole allow-list from the drop-in on any install
		 * that has never saved the Cache panel. So fall back to the schema's
		 * own default, read from the module without building its labels.
		 */
		$opts    = self::stored_cache_opts();
		$ignored = is_array( $opts['ignored_query_params'] ?? null )
			? $opts['ignored_query_params']
			: \XSpeed\Modules\Cache\CacheModule::DEFAULT_IGNORED_QUERY_PARAMS;

		$parts = array();
		foreach ( $ignored as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( '~' === $pattern[0] ) {
				// Raw regex, PHP-side dialect. Keep it — unlike a server
				// config, the drop-in runs the same PCRE engine, so the
				// pattern behaves identically. Anchored below with the rest.
				$body = substr( $pattern, 1 );
				if ( '' !== $body && false !== @preg_match( '#^(?:' . $body . ')$#', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed user pattern must be dropped, not fatal.
					$parts[] = $body;
				}
				continue;
			}
			// Glob semantics, same as Glob_Matcher: * is any run, ? is one.
			$esc     = preg_quote( $pattern, '#' );
			$esc     = str_replace( array( '\*', '\?' ), array( '.*', '.' ), $esc );
			$parts[] = $esc;
		}

		if ( empty( $parts ) ) {
			if ( file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- plain marker removal; non-fatal.
				@unlink( $file );
			}
			return;
		}

		if ( ! is_dir( XSPEED_CACHE_DIR ) && ! wp_mkdir_p( XSPEED_CACHE_DIR ) ) {
			return;
		}

		$payload = '(?:' . implode( '|', array_unique( $parts ) ) . ')';

		// Only write when the value actually changed. This runs from
		// reconcile_mobile_separate() on CacheModule::boot(), so an
		// unconditional write cost a file write and an exclusive lock on every
		// request that boots WordPress — every MISS, every BYPASS, every admin
		// screen, every REST call. sync_mobile_flag() below is the model: it
		// touches the marker only when the setting flips.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- our own sidecar; an unreadable file falls through to the write below.
		if ( is_readable( $file ) && (string) @file_get_contents( $file ) === $payload ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- read by the pre-WP drop-in; WP_Filesystem needs admin credentials unavailable here.
		file_put_contents( $file, $payload, LOCK_EX );
	}

	/**
	 * CacheModule's STORED settings, read straight from the option.
	 *
	 * `Settings_Manager::get( 'cache' )` builds CacheModule's settings schema,
	 * whose labels are declared through `__()`. The reconcile chain below runs
	 * from `CacheModule::boot()` on `plugins_loaded` — before
	 * `after_setup_theme`, the point WordPress 6.7+ treats as safe to
	 * translate — so going through the schema there fires
	 * `_load_textdomain_just_in_time` on every request AND resolves the labels
	 * against a domain that is not loaded yet.
	 *
	 * The callers here need stored values, not schema metadata, so a raw read
	 * is equivalent. It applies NO defaults or coercion: read each key with a
	 * fallback matching the schema's own default.
	 *
	 * @return array<string,mixed>
	 */
	private static function stored_cache_opts(): array {
		$stored = get_option( Settings_Manager::OPTION_PREFIX . 'cache', array() );
		return is_array( $stored ) ? $stored : array();
	}

	public static function sync_mobile_flag( $enabled = null ): void {
		if ( null === $enabled ) {
			$stored  = self::stored_cache_opts();
			$enabled = ! empty( $stored['mobile_separate'] );
		}
		$dir  = XSPEED_CACHE_DIR;
		$flag = $dir . '/.mobile-separate';
		if ( $enabled ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return;
			}
			if ( ! file_exists( $flag ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.PHP.NoSilencedErrors.Discouraged -- read by the pre-WP drop-in via file_exists(); must be a plain marker, not WP_Filesystem.
				@touch( $flag );
			}
			return;
		}
		if ( file_exists( $flag ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- plain marker removal; non-fatal.
			@unlink( $flag );
		}
	}

	/**
	 * Write / remove the `.maintenance-active` sentinel next to the cache
	 * files. The pre-WP drop-in checks for this marker and bails when present,
	 * so a page cached while the site was live is NOT served during
	 * maintenance / coming-soon mode — WordPress loads and renders the
	 * maintenance screen instead. The Pro Maintenance-Cache module drives this
	 * on the maintenance on/off transition. (FBS-82409 B1)
	 *
	 * @param bool $active True to arm the sentinel (entering maintenance),
	 *                     false to clear it (site recovered).
	 */
	public static function sync_maintenance_flag( bool $active ): void {
		$dir  = XSPEED_CACHE_DIR;
		$flag = $dir . '/.maintenance-active';
		if ( $active ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return;
			}
			if ( ! file_exists( $flag ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.PHP.NoSilencedErrors.Discouraged -- read by the pre-WP drop-in via file_exists(); must be a plain marker, not WP_Filesystem.
				@touch( $flag );
			}
			return;
		}
		if ( file_exists( $flag ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- plain marker removal; non-fatal.
			@unlink( $flag );
		}
	}

	/**
	 * Reconcile every mobile_separate-dependent artifact to the current
	 * setting. Called on boot and whenever the cache settings are saved, so
	 * flipping mobile_separate at runtime can't leave the install in a
	 * half-converted state.
	 *
	 * Three things must agree with the setting:
	 *   1. the drop-in's `.mobile-separate` flag (sync_mobile_flag()),
	 *   2. the device-blind server rewrite — present only when OFF
	 *      (static_rewrite_allowed()),
	 *   3. the now-stale static-cache tree + page cache, which were keyed
	 *      under the old scheme and would serve wrong-device HTML.
	 *
	 * No-ops when the cache is disabled — there's nothing installed to
	 * reconcile, and toggle() handles install/teardown itself.
	 */
	public static function reconcile_mobile_separate(): void {
		self::sync_mobile_flag();
		if ( defined( 'XSPEED_CACHE_DIR' ) ) {
			// Keep the drop-in's view of the network's blog paths current — a
			// site added or removed changes which bucket its URLs belong to. (#6)
			self::sync_site_paths();
			// Keep the drop-in's copy of the query allow-list current — a param
			// added in settings must reach the fast path too. (#13)
			self::sync_query_allowlist();
		}

		// The rewrite/static reconciliation below needs the plugin's path
		// constants. They're absent in early-boot / unit-test contexts where
		// only the drop-in flag matters — bail to the flag-only behavior then.
		if ( ! defined( 'XSPEED_CACHE_STATIC_DIR' ) ) {
			return;
		}

		// Only touch the rewrite + caches when caching is actually on.
		$opts = get_option( 'xspeed_options', array() );
		if ( empty( $opts['cache_enabled'] ) ) {
			return;
		}

		$rewrite_present = self::rewrite_installed();
		$rewrite_wanted  = self::static_rewrite_allowed();

		// Did the thing that actually invalidates cache KEYS change?
		// mobile_separate buckets entries as |d / |m, so flipping it makes
		// stored entries mis-bucketed and they must go. A rewrite-state
		// mismatch from anything else (e.g. mod_headers detection, a hand-
		// edited .htaccess) changes no key at all — the same files are still
		// valid, they're just served by PHP instead of by the web server.
		// Purging there is what let one WP-CLI call wipe the whole cache on
		// every bootstrap. (#138)
		//
		// Read the setting from the SAME place static_rewrite_allowed() and
		// sync_mobile_flag() do — the cache module's settings, not the
		// top-level xspeed_options — or this marker would track a key that
		// never changes and a real flip would go unnoticed.
		// Stored read — this runs from boot(); see stored_cache_opts().
		$cache_opts     = self::stored_cache_opts();
		$mobile_now     = ! empty( $cache_opts['mobile_separate'] );
		$mobile_last    = get_option( 'xspeed_last_mobile_separate', null );
		$mobile_flipped = ( null !== $mobile_last && (bool) (int) $mobile_last !== $mobile_now );

		if ( (string) (int) $mobile_now !== (string) $mobile_last ) {
			update_option( 'xspeed_last_mobile_separate', $mobile_now ? '1' : '0', false );
		}

		if ( $rewrite_present === $rewrite_wanted ) {
			// Already consistent — nothing flipped, leave caches intact so a
			// plain settings save (e.g. expiry change) doesn't blow the cache.
			return;
		}

		// Bring the rewrite into line with what this server actually supports.
		if ( $rewrite_wanted ) {
			self::install_rewrite();
		} else {
			self::remove_rewrite();
		}

		// Only discard cache contents when the device bucketing changed.
		if ( $mobile_flipped ) {
			self::purge_all( 'mobile_separate changed' );
		}
	}

	/**
	 * Whether the server-level static-rewrite fast path may be used.
	 *
	 * The rewrite serves `{host}{path}/index.html` straight from the web
	 * server, keyed only by host + path — it has no way to run our PHP
	 * device detection, so it can't tell mobile from desktop. When
	 * `mobile_separate` is on, a single static file would be shared across
	 * devices and whoever primed it wins (mobile visitors could get desktop
	 * HTML, or vice-versa). Rather than duplicate a wp_is_mobile()-equivalent
	 * UA matcher into .htaccess AND the nginx snippet (three copies that
	 * would inevitably drift), we simply DON'T engage the static rewrite when
	 * mobile_separate is on. Requests then fall through to the PHP drop-in,
	 * which buckets correctly — a small TTFB cost (~85ms vs ~30ms) paid only
	 * on mobile-separate sites, in exchange for guaranteed correctness.
	 *
	 * LiteSpeed exclusion (2026-06-16): on LiteSpeed — OpenLiteSpeed in
	 * particular — `.htaccess` CAN run our RewriteRule to serve the static
	 * file, but its `.htaccess` engine ignores `mod_headers`, so we cannot
	 * stamp the served response with `X-XSpeed-Cache: HIT`, AND there is no
	 * `.htaccess` equivalent of nginx's per-location `access_log` to record
	 * the hit. The result was a cache that worked but was invisible: no HIT
	 * header and a hit-ratio frozen near 0%. Every OTHER server gives the
	 * user a visible HIT header + a counted hit (nginx via add_header +
	 * access_log in its snippet; Apache via the `<IfModule mod_headers.c>`
	 * block in rewrite_block_lines(), WHEN that module is loaded — when it is
	 * not, Apache takes this same drop-in fallback). To keep LiteSpeed
	 * CONSISTENT with the rest, we route its hits
	 * through the PHP drop-in instead — the drop-in emits
	 * `X-XSpeed-Cache: HIT (php)` and calls Hit_Counter inline, exactly the
	 * observable behavior the other servers get. The cost is the drop-in's
	 * ~30ms TTFB vs the static path's ~10ms, paid only on LiteSpeed; in
	 * exchange the dashboard hit-ratio and the response header finally tell
	 * the truth there. (Apache keeps the static fast path — it honors the
	 * header.) See maybe_emit_lscache_headers() for the paired LSCache
	 * stand-down that stops LiteSpeed's own module from shadowing the
	 * drop-in.
	 */
	public static function static_rewrite_allowed(): bool {
		// LiteSpeed: drop-in serves hits (visible + counted) — see docblock.
		if ( Server::LITESPEED === Server::type() ) {
			return false;
		}
		// Apache without mod_headers is in EXACTLY the position LiteSpeed
		// is in above: it can run the RewriteRule and serve the static
		// file, but it cannot stamp `X-XSpeed-Cache` on the response, so
		// the hit is invisible to the user and uncountable by
		// Hit_Counter. The docblock above used to assert Apache "honors
		// mod_headers" and left it on the fast path unconditionally —
		// true only when the module is actually loaded. Fall back to the
		// drop-in when it isn't, trading ~10ms of TTFB for a hit that
		// shows up in the header and the ratio. (Field report: hit ratio
		// pinned at 0% on a working Apache cache.)
		if ( Server::APACHE === Server::type() && ! Server::apache_has_mod_headers() ) {
			return false;
		}
		// Stored read — reached from boot(); see stored_cache_opts().
		$opts = self::stored_cache_opts();
		return empty( $opts['mobile_separate'] );
	}

	/**
	 * Why the device-blind static rewrite is NOT installed, when it isn't.
	 * Returns 'mobile_separate' when Separate Mobile Cache is the blocker
	 * (the static file is one-per-URL, so it can't coexist with per-device
	 * buckets), 'no_mod_headers' when Apache can't stamp the HIT header,
	 * '' otherwise. Lets the dashboard explain the slow path instead of
	 * silently falling back to PHP serving. (FBS-83145)
	 *
	 * Every refusal in static_rewrite_allowed() that is NOT self-explanatory
	 * must have a branch here. Otherwise the Health card falls through to
	 * "Block missing — toggle Enable Cache off and on to reinstall it",
	 * advice that cannot work: the same condition that suppressed the write
	 * suppresses the reinstall, and auto_heal() strips the block again on
	 * the next admin page load. (Field report: Apache host with mod_headers
	 * unloaded sat on the slow path with no way to find out why.)
	 */
	/**
	 * Qualify a raw probe result with what we already KNOW about config.
	 *
	 * probe_static_rewrite() writes its own file under the static-cache tree
	 * and fetches that, which succeeds whenever the web server can serve a
	 * static file at all — including when static_rewrite_allowed() is false
	 * and no real page is on the static path. So `active: true` on its own is
	 * not evidence that pages are being served statically.
	 *
	 * The reachable case is nginx with Separate Mobile Cache on: the snippet
	 * lives in the server block and we cannot remove it, pages are
	 * deliberately routed to the PHP drop-in, but the probe file is still
	 * served directly.
	 *
	 * The Health panel learned this in 88b4b50; the CLI, REST and MCP paths
	 * did not, so they kept reporting "active" in exactly that configuration.
	 * Rather than repeat the reasoning at each call site, they now all come
	 * through here.
	 *
	 * Deliberately does NOT consult rewrite_installed(): on nginx the fast
	 * path is the pasted snippet and there is no .htaccess marker to find, so
	 * requiring one would report every correctly-configured nginx site as
	 * broken.
	 *
	 * @param array $probe Raw result from probe_static_rewrite().
	 * @return array{active:bool,inconclusive:bool,reason:string,block_reason:string}
	 */
	public static function qualify_rewrite_probe( array $probe ): array {
		$active       = (bool) ( $probe['active'] ?? false );
		$inconclusive = (bool) ( $probe['inconclusive'] ?? false );
		$reason       = (string) ( $probe['reason'] ?? '' );
		$block_reason = self::static_rewrite_block_reason();

		// Same observed-refusal check Health makes. This is the shared path for
		// `wp xspeed cache recheck-rewrite` and POST /cache/recheck-rewrite —
		// and, because a CLI command is automatically an MCP tool, for the
		// AI-facing surface too. Leaving it out would have fixed the dashboard
		// while the CLI kept answering that the fast path was active. (#372)
		if ( '' === $block_reason ) {
			$skip = self::last_static_skip();
			if ( ! empty( $skip['reason'] ) ) {
				$block_reason = 'skipped_' . (string) $skip['reason'];
			}
		}

		// With page caching off there is nothing to serve, so `active` can
		// never be true here whatever the raw probe says. probe_static_rewrite()
		// writes its OWN file under the static tree and fetches that, which
		// succeeds whenever the server can serve a static file at all — and on
		// nginx the snippet is server-level, so it keeps succeeding after the
		// cache is switched off.
		//
		// block_reason() used to carry this meaning by accident: it returned
		// 'mobile_separate' with caching off, and the refusal branch below
		// forced active=false. Now that it correctly reports '' (nothing can
		// block a fast path that isn't in use), this consumer has to state the
		// condition itself — otherwise `wp xspeed cache recheck-rewrite` and
		// POST /cache/recheck-rewrite claim "the web server is serving cache
		// hits directly" on a site with no cache. That is a positive false
		// claim rather than a nag, i.e. worse than the bug being fixed.
		$cache_opts = Settings::get();
		if ( empty( $cache_opts['cache_enabled'] ) ) {
			return array(
				'active'       => false,
				'inconclusive' => false,
				'reason'       => 'Page caching is off, so there is no cache for the web server to serve.',
				'block_reason' => '',
			);
		}

		// A known refusal outranks the probe, and also outranks
		// "inconclusive" — a blocked rewrite whose probe merely failed to
		// complete is still definitely blocked.
		if ( '' !== $block_reason ) {
			$active       = false;
			$inconclusive = false;
			$reason       = self::block_reason_text( $block_reason );
		}

		return array(
			'active'       => $active,
			'inconclusive' => $inconclusive,
			'reason'       => $reason,
			'block_reason' => $block_reason,
		);
	}

	/**
	 * Human-readable explanation for a static_rewrite_block_reason() code.
	 *
	 * Each one has to say what to DO about it: "mobile_separate" alone tells
	 * a user nothing, and the whole point of surfacing a refusal instead of
	 * the probe verdict is that it is actionable.
	 */
	public static function block_reason_text( string $code ): string {
		switch ( $code ) {
			case 'mobile_separate':
				return 'Separate Mobile Cache is on, which disables the device-blind static rewrite. Cache hits are served by PHP instead. If your site serves the same HTML to every device, turn it off in Cache settings for much faster hits.';
			case 'no_mod_headers':
				return "Apache's mod_headers is not loaded, so the static rewrite cannot mark its responses as cache hits. Enable mod_headers, or leave hits on the PHP path.";
			case 'skipped_nonce':
				return 'The server config is correct, but pages are not reaching the static cache because they contain nonces, so hits are served by PHP instead. A static file is served with no PHP, so a nonce baked into one could never be refreshed and every anonymous form on the page would break once it expired — keeping these pages on PHP is deliberate. Nonces usually come from plugin widgets; disabling the ones the site does not use lets its pages be served statically again.';
			default:
				return sprintf( 'The static rewrite is disabled (%s).', $code );
		}
	}

	public static function static_rewrite_block_reason(): string {
		// Nothing can be blocking the fast path when there is no cache to
		// serve from it. Without this the dashboard told users with page
		// caching switched OFF that Separate Mobile Cache "is disabling
		// faster static serving" — a fast path they were not using, about a
		// cache that did not exist. Every caller of this is a user-facing
		// explanation of why the rewrite is off, so "the cache is off" is
		// the honest answer, and it is silence. (#108)
		$opts = Settings::get();
		if ( empty( $opts['cache_enabled'] ) ) {
			return '';
		}
		if ( Server::LITESPEED === Server::type() ) {
			return ''; // Intended on LiteSpeed — not a "block".
		}
		if ( Server::APACHE === Server::type() && ! Server::apache_has_mod_headers() ) {
			return 'no_mod_headers';
		}
		$cache_opts = Settings_Manager::get( 'cache' );
		return ! empty( $cache_opts['mobile_separate'] ) ? 'mobile_separate' : '';
	}

	/**
	 * Whether migration flagged Separate Mobile Cache for user review. Set by
	 * Migration::map_mobile_separate() when a source plugin (WP Rocket / WP
	 * Super Cache / LiteSpeed) had its "separate mobile cache" option on: we
	 * import it as OFF (to keep the device-blind static fast path) but record
	 * this flag so the dashboard can invite the user to turn it back on only
	 * if their site genuinely serves different HTML per device. (FBS-83145)
	 */
	public static function mobile_separate_needs_review(): bool {
		// Same reasoning as static_rewrite_block_reason(): the invitation is
		// "turn this back on if your site needs it, to regain the fast path",
		// which is meaningless with page caching off — there is no fast path
		// to regain, and the equality probe behind the prompt would fetch
		// pages that aren't being cached. Gated here rather than at the two
		// payload call sites (Admin + Rest_Api) so `enabled`, `blocking` and
		// `needs_review` are consistently gated on the same condition. (#108)
		$opts = Settings::get();
		if ( empty( $opts['cache_enabled'] ) ) {
			return false;
		}
		$cache_opts = Settings_Manager::get( 'cache' );
		return ! empty( $cache_opts['mobile_separate_review'] );
	}

	/**
	 * Clear the review flag — called when the user has acted on the prompt
	 * (dismissed it, or turned Separate Mobile Cache on/off deliberately) so
	 * the dashboard callout doesn't nag forever. Writes the option directly
	 * (bypassing Settings_Manager) so it never touches schema fields.
	 */
	public static function clear_mobile_separate_review(): void {
		$stored = get_option( 'xspeed_module_cache', array() );
		if ( ! is_array( $stored ) || empty( $stored['mobile_separate_review'] ) ) {
			return;
		}
		unset( $stored['mobile_separate_review'] );
		update_option( 'xspeed_module_cache', $stored );
	}

	/**
	 * On-demand probe: does the homepage serve materially the same HTML to a
	 * desktop and a mobile browser? Fetches home_url() twice over loopback —
	 * once with a desktop User-Agent, once with a mobile one — strips
	 * per-request noise (nonces, CSRF tokens, session ids, inline timestamps),
	 * and compares. When identical, Separate Mobile Cache is almost certainly
	 * unnecessary and the user can turn it off to regain the static fast path.
	 *
	 * NEVER run automatically (no page-load cost) — only from the dashboard
	 * "Check now" button. Result is cached for 10 minutes so a double-click or
	 * a re-render doesn't fire two more self-requests. (FBS-83145)
	 *
	 * @return array{ identical:bool, checked:bool, reason?:string, desktop_bytes?:int, mobile_bytes?:int }
	 */
	public static function probe_mobile_equality(): array {
		$cached = get_transient( 'xspeed_mobile_equality_probe' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$home = home_url( '/' );
		$host = (string) wp_parse_url( $home, PHP_URL_HOST );
		if ( '' === $host ) {
			$result = array( 'identical' => false, 'checked' => false, 'reason' => 'home_url has no host' );
			set_transient( 'xspeed_mobile_equality_probe', $result, MINUTE_IN_SECONDS );
			return $result;
		}

		// Match WP core's own mobile detection (wp_is_mobile) so the probe
		// reflects what the site would actually branch on. iPhone Safari for
		// mobile; a current desktop Chrome UA for desktop.
		$desktop_ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
		$mobile_ua  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

		$is_local = function_exists( 'wp_get_environment_type' )
			&& in_array( wp_get_environment_type(), array( 'local', 'development' ), true );

		$fetch = static function ( string $ua ) use ( $home, $is_local ) {
			$resp = wp_remote_get(
				$home,
				array(
					'timeout'     => 5,
					'sslverify'   => ! $is_local,
					'redirection' => 2,
					// Bust any per-device cache so we compare freshly-rendered
					// HTML, and pass the device UA the site would branch on.
					'user-agent'  => $ua,
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				return null;
			}
			return (string) wp_remote_retrieve_body( $resp );
		};

		$desktop = $fetch( $desktop_ua );
		$mobile  = $fetch( $mobile_ua );

		if ( null === $desktop || null === $mobile ) {
			$result = array( 'identical' => false, 'checked' => false, 'reason' => 'could not fetch homepage twice' );
			set_transient( 'xspeed_mobile_equality_probe', $result, MINUTE_IN_SECONDS );
			return $result;
		}

		$identical = self::normalize_html_for_diff( $desktop ) === self::normalize_html_for_diff( $mobile );

		$result = array(
			'identical'     => $identical,
			'checked'       => true,
			'desktop_bytes' => strlen( $desktop ),
			'mobile_bytes'  => strlen( $mobile ),
		);
		set_transient( 'xspeed_mobile_equality_probe', $result, 10 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Strip per-request noise from HTML so a desktop-vs-mobile diff reflects
	 * real structural differences, not nonces / session ids / timestamps that
	 * change on every render. Deliberately conservative: it normalizes the
	 * handful of well-known noise sources and collapses whitespace, so a site
	 * that truly serves different markup per device still compares as different.
	 */
	private static function normalize_html_for_diff( string $html ): string {
		// Every rule here errs toward "they differ" being WRONG rather than
		// "they match" being wrong: this check only ever tells a user it is
		// SAFE to turn Separate Mobile Cache off, so a false "identical"
		// would cost them device-specific output. The risk of being too
		// conservative is milder but real — the useful answer never appears,
		// and the feature's whole pitch ("we'll prove it's safe to turn
		// off") silently never pays out. These close the gaps that made a
		// mismatch effectively guaranteed on an ordinary WordPress site. (#108)
		$patterns = array(
			// WP nonces in attribute or JSON form: data-nonce="…",
			// _wpnonce=…, "nonce":"…". The `[:=]` adjacency below misses
			// wp_nonce_field()'s own markup — `name="_wpnonce" value="ab…"`
			// puts `value=` between the key and the token — which is the
			// single most common nonce shape in WordPress, so that form is
			// matched explicitly first.
			'/name=["\']?(_wpnonce|_ajax_nonce)["\']?\s+value=["\']?[a-z0-9]{8,}/i',
			// CSP nonces on script/style tags. Base64, so uppercase and
			// +/= appear — the hex-only rules below can never match one,
			// and a CSP-enabled site therefore differed on every fetch.
			// MUST precede the generic nonce rule: that one stops at the
			// first non-alphanumeric, leaving the rest of the token behind
			// and the two responses still unequal.
			// The quotes are optional so HTML5's legal unquoted attribute
			// form (`<script nonce=AbCd+q/r=>`) is covered too — without
			// that it fell through to the generic rule, which is the exact
			// failure this rule exists to remove.
			'/\bnonce=(["\'])?[A-Za-z0-9+\/=_-]{8,}(?(1)\1)/',
			'/(_wpnonce|nonce|_ajax_nonce)["\']?\s*[:=]\s*["\']?[a-z0-9]{8,}/i',
			// Generic hex tokens: cache busters, session ids, md5/sha
			// digests. Was 16+, which left an 11-15 char gap above the
			// 10-char nonce rule.
			//
			// The token MUST contain at least one a-f letter. `[a-f0-9]`
			// also matches every decimal digit, so a bare `{10,}` erased
			// every 10+ digit INTEGER anywhere in the document — including
			// visible body text. A page whose desktop and mobile HTML
			// differed only by a per-device numeric id (an AdSense slot, an
			// A/B bucket, an analytics property) then compared as identical,
			// and the check told the user it was safe to switch off the very
			// setting keeping that output correct — the one direction this
			// function must never fail in. Decimal-only runs are left to the
			// bounded epoch rule below, which is deliberately narrower.
			//
			// Known, accepted (QA R2): a token whose letters all fall in a-f
			// reads as a digest, so a per-device `ABC1234567890` strips even
			// though it is an id, not a hash. Deliberately left open — the
			// alternatives all cost more than the bug:
			//
			//   Token shape (lowercase-only, case-uniformity, a trailing
			//   letter) cannot separate it. `ABC1234567890` and
			//   `ABCDEF012345` — an uppercase digest this rule SHOULD strip —
			//   are both all-hex, uniformly cased, letters-then-digits.
			//   Each variant fixed the id only by sparing the digest.
			//
			//   Letter density does separate them (23% letters vs 50%), but
			//   measured over 2000 md5/sha1/sha256 samples, requiring letters
			//   spread through the token leaves 21-67% of REAL digests
			//   unmatched depending on the window. Digest noise is most of
			//   what this function exists to remove, so that trade guts it.
			//
			//   Context (protecting data-* attribute values from this rule)
			//   works for ids and still strips digests in URLs, classes and
			//   query strings — but regresses a CHANGING digest inside a
			//   non-nonce data-* attribute, and needs a two-pass
			//   hold/restore. Viable if R2 is ever worth pressing; its
			//   failure at least errs toward "differ".
			//
			// An A-F-only prefix on a per-device id is rare, and the earlier
			// nonce rules already claim the data-nonce/_wpnonce shapes.
			'/\b(?=[a-f0-9]{10,}\b)[0-9]*[a-f][a-f0-9]*\b/i',
			// wp-generated unique ids (e.g. wp-block ids, aria ids).
			'/(id|for|aria-[a-z]+)="[^"]*-[0-9]{3,}"/i',
			// ISO-ish timestamps + epoch-looking numbers in query strings.
			'/\?ver=[0-9.]+/',
			'/[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.+Z-]+/',
			// Our own signature's generation stamp. The two fetches are
			// sequential and each writes its own entry, so this differs on
			// essentially every comparison — and it is space-separated, so
			// the ISO rule above (which requires a literal `T`) never
			// touches it. Without this the probe reports "differ" for every
			// site and the "safe to turn Separate Mobile Cache off" verdict
			// can never appear.
			'/generated [0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2} UTC/',
			// Raw epoch seconds. The two fetches are sequential, so any
			// template printing time() guaranteed a mismatch.
			//
			// This is the ONLY rule that may strip a decimal-only run, so
			// its bound is load-bearing rather than decorative — every digit
			// it gives away is a class of per-device id it silently erases.
			// `1[0-9]{9}` was too loose: it claimed the whole
			// 1000000000-1999999999 range (2001-2033) to cover timestamps
			// nobody serves, and took every 10-digit AdSense slot, order id
			// and SKU beginning with 1 along with it — reproducing the exact
			// false-"identical" verdict the hex rule above was tightened to
			// stop. `1[6-9]` covers 2020-2033, which is the only span a live
			// site can actually print, and collides with roughly a tenth as
			// many ids.
			//
			// Not airtight — an id beginning 16-19 still collides. Closing
			// that properly means scoping this to places a timestamp really
			// appears (an attribute value, a query parameter, a JSON value)
			// rather than bare body text; the bound is the cheap 90% of it.
			'/\b1[6-9][0-9]{8}\b/',
		);
		$html = (string) preg_replace( $patterns, 'X', $html );
		// Collapse all whitespace so trivial formatting differences don't count.
		return trim( (string) preg_replace( '/\s+/', ' ', $html ) );
	}

	public static function ensure_hits_log_file(): bool {
		// TWO writers append to this log, and an earlier fix conflated them:
		//
		//   1. nginx, via the server-level `access_log` directive in
		//      nginx_snippet() — a DIFFERENT uid, which is why the file needs
		//      to be world-writable there.
		//   2. the PHP drop-in (advanced-cache.php), on EVERY server. A hit it
		//      serves bypasses WordPress entirely, so it can't call
		//      Hit_Counter::record_hit() — appending here is the only way that
		//      hit is ever counted.
		//
		// The nginx-only early return that used to sit at the top of this
		// method was fixing something real: chmod() on a file PHP doesn't own
		// raises "Operation not permitted", and off nginx that chmod buys
		// nothing. But it took directory creation with it, so on LiteSpeed
		// (which always serves via the drop-in), on Apache without mod_headers,
		// and anywhere mobile_separate forces the drop-in path, writer 2 was
		// appending to a file whose parent directory did not exist. The append
		// is @-suppressed and documented as non-fatal, so every one of those
		// hits vanished and the dashboard ratio sat at 0% forever.
		//
		// So: create the dir + file everywhere, and keep only the chmod gated
		// to nginx.
		$dir = self::hits_log_dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$is_nginx = ( Server::NGINX === Server::type() );

		if ( $is_nginx ) {
			// Ensure the dir is traversable + writable by a different-uid nginx.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- nginx (a separate uid in multi-container setups) must be able to create/append the log; WP_Filesystem layers ownership overrides that defeat that intent.
			@chmod( $dir, 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort; the access_log just stays empty if it fails.
		}

		$path = self::hits_log_path();
		if ( ! file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- See docblock: must be a plain touch, not WP_Filesystem.
			@touch( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- non-fatal helper; failures already covered by the dir check.
		}

		if ( $is_nginx ) {
			// World-writable so a different-uid nginx can append HIT lines.
			// Off nginx the drop-in appends as the same uid that owns the file,
			// so this is unnecessary — and would emit the "Operation not
			// permitted" warnings the old early return was added to silence.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- See docblock.
			@chmod( $path, 0666 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort.
		}

		return file_exists( $path );
	}

	public static function nginx_snippet(): ?string {
		if ( Server::NGINX !== Server::type() ) {
			return null;
		}
		$rel = '/' . ltrim( str_replace( ABSPATH, '/', XSPEED_CACHE_STATIC_DIR ), '/' );
		$rel = rtrim( $rel, '/' );

		// WP-Rocket-canonical pattern: every condition lives at
		// SERVER level (outside any location block). Each one appends
		// a tag to $xspeed_no_cache; the final check is a single
		// string-equality against the unmodified default "no-cache".
		// Only when ALL conditions pass does the rewrite fire,
		// jumping the request to the static file's URL. nginx then
		// restarts location matching against the new path, where
		// regular static-file serving takes over.
		//
		// Why server-level + a single rewrite (instead of try_files
		// inside `location /`): nginx's well-documented "if is evil"
		// quirk silently disables `try_files`'s last fallback when
		// any `if` in the same location is true. Moving the `if`s
		// outside any location dodges the trap completely, because
		// server-level rewrite is the documented stable path.
		//
		// `last` (not `break`) restarts location matching — required
		// so the rewritten static-file URI gets served via the normal
		// static-file location, not re-matched against `location /`
		// where our own rewrite would loop.
		//
		// The cache existence check is the LAST condition in the
		// chain so when the file isn't cached, $xspeed_no_cache
		// gets a "-nofile" tag and the rewrite is skipped — the
		// request falls through to whatever `location /` the user
		// already had (typically `try_files $uri $uri/ /index.php?$args;`).
		// Absolute path to the hit-log file from the nginx process's
		// filesystem view. Nginx's `access_log buffer=N flush=Ns` form
		// requires a literal path — `$document_root` variables are
		// rejected — so PHP computes it. Lives under uploads/ (NOT the
		// cache dir): a cache purge or uninstall deletes the cache dir,
		// which would orphan this directive's parent directory and make
		// `nginx -t` fail [emerg] for EVERY vhost on the host
		// (FBS-82478). uploads/ survives both, so the directive can
		// never take nginx down. Works on every topology where the nginx
		// process shares a filesystem with PHP (container or host).
		$hits_abs = self::hits_log_path();

		$lines   = array();
		$lines[] = '# xSpeed static cache — paste at server level, above location / { }.';
		// Cache host must match the on-disk dir PHP writes: store_static() /
		// static_host() take HTTP_HOST and strip every char outside
		// [a-zA-Z0-9.\-] — i.e. it removes the colon but KEEPS the port digits
		// (localhost:8192 → localhost8192). nginx's own $host can't reproduce
		// that: $host has the port already stripped ENTIRELY (→ localhost), so
		// the -f check looks for localhost/... while PHP wrote localhost8192/...
		// and the rewrite never fires on a non-standard port. Derive
		// $xspeed_host from $http_host (which keeps the port) and drop just the
		// colon, so it equals the PHP dir on every port. On standard ports
		// $http_host has no colon, so $xspeed_host == $host == the bare domain.
		$lines[] = 'set $xspeed_host $http_host;'; // default: no port → unchanged (e.g. example.com)
		$lines[] = 'if ($http_host ~ "^([^:]+):(\\d+)$") { set $xspeed_host $1$2; }'; // host:port → hostport (matches PHP static_host())
		$lines[] = 'set $xspeed_no_cache "no-cache";';
		$lines[] = 'if ($request_method != GET) { set $xspeed_no_cache "$xspeed_no_cache-method"; }';
		$lines[] = 'if ($args) { set $xspeed_no_cache "$xspeed_no_cache-args"; }';
		// Cookie + user-agent exclusions, generated from the user's actual
		// settings rather than a hardcoded list. Before this, the rule
		// tested three fixed cookie names and no user agent at all, so
		// every excluded_cookies / bypass_user_agents entry applied only
		// while a page was cold — on a warm page nginx served the shared
		// anonymous copy to carts, members and bypassed bots alike. The
		// three historical names survive as a floor inside cookie_rule().
		// `~*` is case-insensitive, matching PHP's stripos()/glob checks.
		// Stored read — reached from boot(); see stored_cache_opts(). The
		// fallbacks below mirror the schema's own defaults, which a raw read
		// does not apply.
		$cache_opts  = self::stored_cache_opts();
		$cookie_rule = Server_Rules::cookie_rule(
			is_array( $cache_opts['excluded_cookies'] ?? null )
				? $cache_opts['excluded_cookies']
				: \XSpeed\Modules\Cache\CacheModule::DEFAULT_EXCLUDED_COOKIES
		);
		$lines[] = 'if ($http_cookie ~* "(' . $cookie_rule['regex'] . ')") { set $xspeed_no_cache "$xspeed_no_cache-cookie"; }';

		$ua_rule = Server_Rules::user_agent_rule(
			is_array( $cache_opts['bypass_user_agents'] ?? null ) ? $cache_opts['bypass_user_agents'] : array()
		);
		// Emitted only when the list is non-empty — an empty alternation
		// would compile to `(...)` matching every request and disable the
		// fast path entirely.
		if ( '' !== $ua_rule['regex'] ) {
			$lines[] = 'if ($http_user_agent ~* "(' . $ua_rule['regex'] . ')") { set $xspeed_no_cache "$xspeed_no_cache-ua"; }';
		}

		// URL exclusions. Without this an excluded URL was only excluded
		// while its page was cold: PHP won't write a static file for one, so
		// there is usually nothing to serve — but a page cached BEFORE the
		// rule was added still has its file on disk, and nginx serves it
		// without ever asking PHP. The exclusion then does nothing until the
		// next purge. (#169)
		//
		// Matched against $uri, not $request_uri: $uri is the decoded path
		// without the query string, which is what Cache::should_cache()
		// tests. Using $request_uri would make `/cart` fail to match
		// `/cart?x=1` inconsistently with PHP. Same empty-regex guard as the
		// UA rule above — an empty alternation matches everything.
		$url_rule = Server_Rules::url_rule(
			is_array( $cache_opts['excluded_urls'] ?? null ) ? $cache_opts['excluded_urls'] : array()
		);
		if ( '' !== $url_rule['regex'] ) {
			$lines[] = 'if ($uri ~* "(' . $url_rule['regex'] . ')") { set $xspeed_no_cache "$xspeed_no_cache-url"; }';
		}
		$lines[] = 'if (!-f "$document_root' . $rel . '/$xspeed_host$uri/index.html") { set $xspeed_no_cache "$xspeed_no_cache-nofile"; }';
		// Neither `add_header` nor `access_log` is allowed inside an `if{}`
		// at server level (nginx rejects with "directive is not allowed
		// here"). The logging therefore lives in a `location` block that
		// matches the rewritten URI after `rewrite … last;` restarts
		// location matching. Every HIT lands there exactly once, every
		// MISS / PHP-served request never matches it.
		$lines[] = 'if ($xspeed_no_cache = "no-cache") {';
		$lines[] = '    rewrite ^ ' . $rel . '/$xspeed_host$uri/index.html last;';
		$lines[] = '}';
		$lines[] = '';
		$lines[] = '# Serve + log the cached HIT — `^~` is required so this beats any regex location.';
		$lines[] = 'location ^~ ' . $rel . '/ {';
		$lines[] = '    internal;';
		// LITERAL log path (not `set $var; access_log $var`). The variable form
		// makes nginx open the log lazily per-request and SILENTLY drop the
		// line if the open fails — so on a working host hits were served
		// (X-XSpeed-Cache fires regardless) but nothing was ever written and
		// the hit ratio sat at 0%. A literal path makes nginx open the file at
		// config load and actually log every hit.
		//
		// Deleting the log FILE is still safe with a literal path: nginx
		// recreates it on the next write/reload and `nginx -t` stays green
		// (verified). The only thing that [emerg]s `nginx -t` is a missing
		// parent DIRECTORY — and the log lives under uploads/xspeed/, which
		// survives cache purge + uninstall, and which ensure_hits_log_file()
		// (run on every admin_init via auto_heal) recreates if it ever goes
		// missing. So: hits are logged, and a user deleting the log can't take
		// nginx down.
		$lines[] = '    access_log ' . $hits_abs . ' combined buffer=16k flush=5s;';
		$lines[] = '    add_header X-XSpeed-Cache "HIT (nginx)" always;';
		// Edge/CDN headers from the same seam the drop-in bakes. nginx serves
		// this path without ever starting PHP, so the answer cannot be
		// resolved per request — the pairs are resolved HERE, when the
		// snippet is generated, and a change of answer needs the snippet
		// regenerated and re-pasted to take effect.
		//
		// Skipped entirely when the static path is switched off. The only
		// reason that can fire under `bake` is mobile-split, and mobile-split
		// is also what switches the static path off — so the block would be
		// baked with a hold it can never serve, and would start serving it
		// the moment the setting is turned off and static files reappear,
		// until somebody regenerates and re-pastes. A rule that can only be
		// served once its premise is false is guaranteed to be stale.
		foreach ( self::static_rewrite_allowed() ? self::edge_headers_for( 'HIT', 'bake' ) : array() as $name => $value ) {
			$lines[] = '    add_header ' . $name . ' "' . self::quote_directive_value( $value ) . '" always;';
		}
		$lines[] = '}';
		return implode( "\n", $lines );
	}

	/**
	 * Aggregate every enabled module's nginx_directives() into one
	 * pasteable server-block snippet. Replaces the per-module "paste
	 * this snippet" notices with a single consolidated paste — every
	 * future feature toggle just regenerates this output.
	 *
	 * Returns null on non-nginx hosts (nothing to paste).
	 *
	 * Sections render in module-registration order so the layout stays
	 * predictable; each module gets a comment header `# <slug>`.
	 */
	public static function full_nginx_server_block(): ?string {
		if ( Server::NGINX !== Server::type() ) {
			return null;
		}

		$blocks = array();
		foreach ( Module_Registry::all() as $module ) {
			$directives = $module->nginx_directives();
			if ( ! is_string( $directives ) || '' === trim( $directives ) ) {
				continue;
			}
			$blocks[] = "# === " . $module->slug() . " ===\n" . rtrim( $directives );
		}

		if ( empty( $blocks ) ) {
			return null;
		}

		$header = "# xSpeed unified nginx config — paste into `server { }`, above `location / { }`; re-paste after toggling features.\n";

		return $header . "\n" . implode( "\n\n", $blocks ) . "\n";
	}

	/**
	 * Tell LiteSpeed's LSCache module to stand down on the cache-miss
	 * render path.
	 *
	 * History: this method used to emit X-LiteSpeed-Cache-Control:
	 * public,max-age=N + X-LiteSpeed-Tag, handing caching to the server's
	 * LSCache store. That delegation backfired — once LSCache cached a
	 * page it served every subsequent request from its OWN store and
	 * intercepted the request before our site-root .htaccess static
	 * rewrite could run. Net effect on LiteSpeed hosts: no X-XSpeed-Cache
	 * header, our static-cache tree never served, the HIT log never
	 * written (hit ratio frozen at 0%), and the Health probe reporting a
	 * false "cache running on PHP fallback" because it never saw an
	 * xSpeed-served response.
	 *
	 * xSpeed now owns the cache on LiteSpeed exactly as it does on Apache:
	 * our `.htaccess` mod_rewrite block serves hits straight from the
	 * static-cache tree (with the X-XSpeed-Cache header + access-log HIT
	 * accounting), and PHP/the drop-in is the fallback. To guarantee
	 * LSCache doesn't shadow that with its own copy — some LiteSpeed
	 * configs cache by default — we send an explicit `no-cache` control so
	 * the server defers to our rewrite. Skipped when the LiteSpeed Cache
	 * plugin is active (it owns its own header policy; our Conflict
	 * registry handles that coexistence separately).
	 */
	public static function maybe_emit_lscache_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		if ( Server::LITESPEED !== Server::type() ) {
			return;
		}
		// is_plugin_active() lives in wp-admin/includes/plugin.php which
		// isn't auto-loaded on front-end requests. Use the option layer
		// directly to avoid pulling in admin code from a render path.
		$active = (array) get_option( 'active_plugins', array() );
		if ( in_array( 'litespeed-cache/litespeed-cache.php', $active, true ) ) {
			return;
		}

		// Explicitly opt this response OUT of LSCache so the server can't
		// shadow our static-rewrite cache with its own internal copy.
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}

	/**
	 * Restore the drop-in + WP_CACHE constant for a site that had caching
	 * ON before this activation — and ONLY for such a site.
	 *
	 * WordPress runs an upgrade as deactivate → wipe plugin files →
	 * install → activate. The wipe takes advanced-cache.php with it, so
	 * without this the site serves 100% uncached from the moment the
	 * update finishes until the next authenticated wp-admin page load
	 * (auto_heal() is on admin_init). On a site whose admin logs in
	 * rarely that window is hours or days of silent cache loss, while
	 * the dashboard still reports cache_enabled = true. (FBS field
	 * report against 1.1.2 / Pro 1.0.5.)
	 *
	 * The `cache_enabled` guard is the whole contract: a FRESH install
	 * has the option unset, so activation writes nothing and the user
	 * still opts in explicitly through Cache::toggle() via the
	 * /cache/toggle REST endpoint. We only ever put back state the user
	 * already chose — repair, never a new install path. This is what
	 * keeps us on the right side of the "don't create drop-ins the user
	 * didn't ask for" guideline while matching what WP Rocket, W3 Total
	 * Cache and WP Super Cache all do on activation.
	 *
	 * @return bool True when a restore was performed.
	 */
	public static function restore_dropin_if_enabled(): bool {
		if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
			return false;
		}

		// The user's saved choice. Absent/false on a fresh install => no
		// drop-in is written and nothing touches wp-config.php.
		$opts = get_option( 'xspeed_options', array() );
		if ( empty( $opts['cache_enabled'] ) ) {
			return false;
		}

		$state = self::toggle( true, false );
		// A refusal reports whether the cache SERVES, which on this path can
		// be true for reasons that have nothing to do with this call — so a
		// refusal would otherwise log "drop-in restored" for a restore that
		// was declined. Restored means the transaction went through.
		$restored = empty( $state['blocked'] ) && ! empty( $state['enabled'] );

		if ( $restored ) {
			Activity_Log::record(
				'cache_dropin_restored',
				'Cache drop-in restored after a plugin update — caching was already enabled.',
				Activity_Log::SUCCESS
			);
		}

		return $restored;
	}

	/**
	 * Reconcile drop-in + WP_CACHE + rewrite block with the user's
	 * saved choice. Runs on admin_init. Cheap when nothing's wrong
	 * (one option read + a handful of file_exists / defined checks);
	 * writes only when state has drifted (typical cause: plugin
	 * upgrade wiped the drop-in, foreign plugin removed our WP_CACHE
	 * define, or someone hand-edited .htaccess).
	 *
	 * Skipped during the WP plugin updater run so we don't race
	 * the upgrader's own filesystem operations.
	 */
	public static function auto_heal(): void {
		if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
			return;
		}
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$opts = get_option( 'xspeed_options', array() );
		if ( empty( $opts['cache_enabled'] ) ) {
			return;
		}

		$state = self::toggle( true, false );
		// A refusal means something else now owns the page-cache field, or
		// the write could not be verified. Either way this is not the moment
		// to go on maintaining our rewrite block and log file.
		if ( ! empty( $state['blocked'] ) || empty( $state['enabled'] ) ) {
			return;
		}

		// Rewrite block goes last. It's what turns the static-cache
		// tree into a PHP-bypass — every cache hit served by the web
		// server directly. Without it we still cache, just at drop-in
		// speed (~85ms TTFB) instead of static-file speed (~25-40ms).
		//
		// Reconcile against mobile_separate: the rewrite is device-blind, so
		// it must be ABSENT when mobile_separate is on and PRESENT otherwise.
		// auto_heal() runs periodically, so it also repairs a rewrite that
		// was left installed before mobile_separate was switched on.
		if ( self::static_rewrite_allowed() ) {
			if ( ! self::rewrite_installed() ) {
				self::install_rewrite();
			}
		} elseif ( self::rewrite_installed() ) {
			self::remove_rewrite();
		}

		// HITs log file — nginx writes one line per HIT served directly
		// (see nginx_snippet()), Cache::get_stats() drains the file via
		// Hit_Counter::collect_nginx_log_hits(). If the file vanishes
		// (plugin upgrade wiped wp-content/cache/), nginx errors silently
		// on the access_log directive and the counter stays at 0.
		self::ensure_hits_log_file();
	}

	/**
	 * Keep the generic bypass cookie in sync with PHP's caching verdict.
	 *
	 * The server config tests exactly one cookie name (Server_Rules::
	 * BYPASS_COOKIE) forever, and PHP decides what that name means. Adding
	 * a new excluded cookie therefore needs no config change and no nginx
	 * reload — the reason this exists.
	 *
	 * Session cookie (expiry 0) so it dies with the browser session, and
	 * deliberately NOT HttpOnly-sensitive: it carries no identity, only the
	 * boolean "don't serve this visitor a shared cached page".
	 *
	 * Honest limit: this can only ever help a visitor PHP has already seen
	 * once. A bot's first request to a warm page never reaches PHP, which
	 * is why user-agent rules are still written into the server config
	 * rather than relying on this.
	 *
	 * @param bool $bypass Whether this visitor must skip the cache.
	 */
	private static function sync_bypass_cookie( bool $bypass ): void {
		if ( headers_sent() ) {
			return;
		}

		$name = Server_Rules::BYPASS_COOKIE;
		$has  = isset( $_COOKIE[ $name ] );

		// Only touch the header when the state actually changes — a
		// Set-Cookie on every request would make the response uncacheable
		// for intermediary caches and add noise to every hit.
		if ( $bypass === $has ) {
			return;
		}

		$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';

		if ( $bypass ) {
			setcookie( $name, '1', 0, $path, (string) $domain, is_ssl(), false );
			$_COOKIE[ $name ] = '1';
		} else {
			setcookie( $name, '', time() - 3600, $path, (string) $domain, is_ssl(), false );
			unset( $_COOKIE[ $name ] );
		}
	}

	/**
	 * Build the .htaccess rules that map cacheable requests to the
	 * static-cache tree. Conditions are deliberately strict: GET only,
	 * empty query string, no session/comment-author/post-password
	 * cookie, and the static file must exist on disk. Anything that
	 * fails one of these falls through to PHP and the drop-in / full
	 * WordPress path.
	 *
	 * @return string[] Lines for insert_with_markers().
	 */
	public static function rewrite_block_lines(): array {
		// Path relative to ABSPATH so the rule lives in the site-root
		// .htaccess regardless of where wp-content sits. WP_CONTENT_DIR
		// can be moved, so we compute the document-root-relative form
		// at install time and bake it into the rule.
		$rel = str_replace( ABSPATH, '/', XSPEED_CACHE_STATIC_DIR );
		$rel = '/' . ltrim( $rel, '/' );
		$rel = rtrim( $rel, '/' );

		// Cookie + user-agent exclusions generated from the live settings.
		// See the matching block in nginx_snippet() — same generator, same
		// floor, so both servers enforce an identical policy. Apache reads
		// .htaccess on every request and we already self-heal this file, so
		// Apache/LiteSpeed users get the fix on upgrade with no action.
		$cache_opts  = Settings_Manager::get( 'cache' );
		$cookie_rule = Server_Rules::cookie_rule(
			is_array( $cache_opts['excluded_cookies'] ?? null ) ? $cache_opts['excluded_cookies'] : array()
		);
		$ua_rule = Server_Rules::user_agent_rule(
			is_array( $cache_opts['bypass_user_agents'] ?? null ) ? $cache_opts['bypass_user_agents'] : array()
		);

		$lines = array(
			'<IfModule mod_rewrite.c>',
			'  RewriteEngine On',
			'  RewriteCond %{REQUEST_METHOD} ^GET$',
			'  RewriteCond %{QUERY_STRING} ^$',
			'  RewriteCond %{HTTP_COOKIE} !(' . $cookie_rule['regex'] . ') [NC]',
		);

		// Only emit the UA condition when there's something to match —
		// `!()` would negate an always-true empty match and refuse every
		// request, silently disabling the static path.
		if ( '' !== $ua_rule['regex'] ) {
			// Quoted, because RewriteCond is whitespace-delimited and real
			// user-agent fragments contain spaces ("Mozilla/5.0 (compatible").
			// Unquoted, a space adds an argument and Apache answers every
			// request with a 500 — and because .htaccess is parsed per
			// request, `httpd -t` still reports Syntax OK. Server_Rules has
			// already excluded quotes and backslashes from the alternation,
			// so the closing quote here cannot be escaped away.
			$lines[] = '  RewriteCond %{HTTP_USER_AGENT} "!(' . $ua_rule['regex'] . ')" [NC]';
		}

		$block = array_merge(
			$lines,
			array(
			// Capture REQUEST_URI without its trailing slash into %1.
			// store_static() writes `{host}{uri-without-trailing-slash}/index.html`,
			// so this normalization lets `/blog/` and `/blog` both hit
			// the same cache file without producing the double-slash
			// path that would skip the -f check below.
			'  RewriteCond %{REQUEST_URI} ^(.*?)/?$',
			'  RewriteCond %{DOCUMENT_ROOT}' . $rel . '/%{HTTP_HOST}%1/index.html -f',
			// Pattern is `^`, NOT `.`. The per-directory rewrite engine
			// strips the leading slash before matching, so the HOMEPAGE
			// request `/` arrives here as an EMPTY path. `.` requires at
			// least one character and therefore never matches the homepage
			// — on LiteSpeed (which honors this strictly) the front page
			// fell through to PHP while every inner page rewrote fine.
			// `^` matches the empty string AND any non-empty path, so it
			// covers `/` and `/blog` alike. (Confirmed on OpenLiteSpeed
			// 1.8: `.` → homepage served by PHP drop-in; `^` → served
			// directly from the static file.)
			'  RewriteRule ^ ' . $rel . '/%{HTTP_HOST}%1/index.html [E=XSPEED_STATIC_HIT:1,L]',
			'</IfModule>',
			// Mark the statically-served response as a cache HIT.
			//
			// A file served by the rewrite above bypasses PHP entirely, so
			// this directive is the ONLY thing that can identify it as
			// cached — both for the user reading response headers and for
			// Hit_Counter, which reconciles static hits from the access
			// log. Without it the cache works perfectly and reports a 0%
			// hit ratio, which reads as "the plugin is broken". (Field
			// report against 1.1.2: homepage served byte-identical from
			// the static tree, no X-XSpeed-Cache header on any response.)
			//
			// `always` so the header is set on the 200 from the rewritten
			// file, not only on the successful-response table. The
			// <IfModule> guard keeps a server without mod_headers from
			// 500ing on an unknown directive — on such a host the header
			// is silently dropped, which is exactly why
			// static_rewrite_allowed() refuses the static path there and
			// routes hits through the drop-in instead.
			'<IfModule mod_headers.c>',
			'  <FilesMatch "\\.html$">',
			'    Header always set X-XSpeed-Cache "HIT (static)"',
			'  </FilesMatch>',
			)
		);

		// Edge/CDN headers from the same seam the drop-in bakes. Like the
		// nginx snippet, the static rewrite answers without PHP, so the pairs
		// are resolved when the block is GENERATED rather than per request.
		//
		// `env=` rather than the `<FilesMatch>` scoping above, because these
		// must ride only on responses the rewrite produced. The marker header
		// stays filename-scoped: it is inert, and narrowing it would change a
		// header QA reads.
		// Same reasoning as the nginx snippet: a bake hold can only come from
		// mobile-split, and mobile-split is what turns this path off.
		$edge_lines = array();
		foreach ( self::static_rewrite_allowed() ? self::edge_headers_for( 'HIT', 'bake' ) : array() as $edge_name => $edge_value ) {
			$edge_lines = array_merge(
				$edge_lines,
				self::static_hit_directives(
					'    Header always set ' . $edge_name . ' "' . self::quote_directive_value( $edge_value ) . '"'
				)
			);
		}

		return array_merge( $block, $edge_lines, array( '</IfModule>' ) );
	}

	/**
	 * Active probe that confirms the web-server static-rewrite path is
	 * actually serving cached files. Writes a probe file with a random
	 * nonce, fetches it over HTTP at its public URL, and checks whether
	 * the response was served directly by the web server (Last-Modified
	 * + ETag headers + no X-Powered-By: PHP).
	 *
	 * Server-agnostic: same probe works for nginx (snippet pasted) and
	 * Apache / LiteSpeed (.htaccess block installed). If the rewrite
	 * isn't engaged, the request falls through to WordPress and PHP
	 * adds its own headers, which the probe detects and reports.
	 *
	 * Throttled via a 5-minute transient — we never want this running
	 * on every Health card paint.
	 *
	 * @return array{active:bool, reason:string, code?:int, php?:bool, expires?:int}
	 */
	/**
	 * @param bool $allow_probe When false (the default), return ONLY a cached
	 *   result and never make an HTTP request — so admin page loads are never
	 *   blocked by the loopback probe. The actual HTTP probe only runs when a
	 *   caller explicitly opts in (the Health tab / cron). Previously this ran
	 *   synchronously on every dashboard bootstrap, so a slow/timing-out
	 *   loopback request added up to `timeout` seconds to admin page loads on
	 *   hosts that block self-requests. (FBS-82142)
	 */
	/**
	 * Discard the cached probe result and run a fresh one.
	 *
	 * Without this there was no way to re-check: the result sat in a transient
	 * for five minutes and nothing ever deleted it, so a user who fixed their
	 * nginx config kept seeing "nginx detected — configure for max cache speed"
	 * with no means of confirming the fix worked. (FBS-84012)
	 */
	public static function recheck_static_rewrite(): array {
		delete_transient( 'xspeed_rewrite_probe' );
		return self::probe_static_rewrite( true );
	}

	public static function probe_static_rewrite( bool $allow_probe = false ): array {
		$cached = get_transient( 'xspeed_rewrite_probe' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		// No cached result yet and the caller doesn't want to pay for a live
		// HTTP probe (e.g. the admin bootstrap): report "pending" without
		// blocking. The Health tab will run the real probe on demand.
		if ( ! $allow_probe ) {
			return array( 'active' => false, 'reason' => 'probe pending', 'pending' => true );
		}

		$home = home_url( '/' );
		$host = (string) wp_parse_url( $home, PHP_URL_HOST );
		if ( '' === $host ) {
			$result = array( 'active' => false, 'reason' => 'home_url has no host' );
			set_transient( 'xspeed_rewrite_probe', $result, MINUTE_IN_SECONDS );
			return $result;
		}

		// Use a randomised path AND nonce so a stale CDN cache entry
		// from a prior probe can never make a broken install look
		// healthy. Path is namespaced under __xspeed_probe__ so the
		// directory listing stays obvious if cleanup misfires.
		$slug       = wp_generate_password( 12, false, false );
		$nonce      = wp_generate_password( 24, false, false );
		$probe_dir  = XSPEED_CACHE_STATIC_DIR . '/' . $host . '/__xspeed_probe__/' . $slug;
		$probe_file = $probe_dir . '/index.html';
		$probe_url  = trailingslashit( $home ) . '__xspeed_probe__/' . $slug . '/';

		if ( ! file_exists( $probe_dir ) ) {
			wp_mkdir_p( $probe_dir );
		}
		if ( ! is_dir( $probe_dir ) ) {
			$result = array( 'active' => false, 'reason' => 'cannot create probe dir' );
			set_transient( 'xspeed_rewrite_probe', $result, MINUTE_IN_SECONDS );
			return $result;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- WP_Filesystem requires admin credentials we may not have here; the file is in our own cache dir.
		file_put_contents( $probe_file, $nonce, LOCK_EX );

		// Verify TLS by default — disabling it site-wide is a needless MITM
		// exposure (FBS-82142). Only relax verification in local/dev
		// environments, where self-signed certs are common and there's no
		// real attacker in the loop.
		$is_local  = function_exists( 'wp_get_environment_type' )
			&& in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		$resp = wp_remote_get(
			$probe_url,
			array(
				// 3s cap so a host that hangs on loopback self-requests can't
				// stall the caller for long; the result/error is cached so we
				// don't repeat the wait every minute.
				'timeout'     => 3,
				'sslverify'   => ! $is_local,
				'redirection' => 0,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		// Best-effort cleanup so we don't accumulate probe dirs even
		// if subsequent calls all hit the transient.
		if ( file_exists( $probe_file ) ) {
			wp_delete_file( $probe_file );
		}
		if ( is_dir( $probe_dir ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort probe-dir cleanup; WP_Filesystem needs admin credentials we don't have here.
			@rmdir( $probe_dir );
		}

		if ( is_wp_error( $resp ) ) {
			$result = array(
				'active' => false,
				// The request never completed, so we learned NOTHING about the
				// rewrite. Flagged inconclusive so the UI doesn't tell the user
				// to configure a server that may already be configured — a
				// blocked loopback, a self-signed cert, or a timeout is a probe
				// failure, not a missing rewrite. (FBS-84012)
				'inconclusive' => true,
				'reason' => 'http error: ' . $resp->get_error_message(),
			);
			// Cache the failure for the full 5 minutes (not 1) so a host that
			// times out on the loopback probe isn't re-probed — and re-stalled
			// — on every page load within the window. (FBS-82142)
			set_transient( 'xspeed_rewrite_probe', $result, 5 * MINUTE_IN_SECONDS );
			return $result;
		}

		$code     = (int) wp_remote_retrieve_response_code( $resp );
		$body     = (string) wp_remote_retrieve_body( $resp );
		$ua_php   = '' !== (string) wp_remote_retrieve_header( $resp, 'x-powered-by' );
		$has_etag = '' !== (string) wp_remote_retrieve_header( $resp, 'etag' )
				 || '' !== (string) wp_remote_retrieve_header( $resp, 'last-modified' );
		$match    = trim( $body ) === $nonce;

		// "Active" = the web server served our raw nonce bytes back
		// AND emitted the static-serve markers (ETag / Last-Modified)
		// AND didn't add an X-Powered-By: PHP header. All three are
		// individually noisy; together they're conclusive.
		$active = $match && $has_etag && ! $ua_php && 200 === $code;

		/*
		 * `inconclusive` separates "we proved the rewrite isn't serving" from
		 * "the probe couldn't tell". Only the former should drive a
		 * configure-your-server banner; the latter previously rendered the
		 * same alarming copy at a user who had already configured nginx
		 * correctly, and there was no way to clear it. (FBS-84012)
		 */
		$inconclusive = false;
		if ( $active ) {
			$reason = 'static-served';
		} elseif ( 200 === $code && $match && $ua_php ) {
			$reason = 'php served the file instead of nginx/Apache (rewrite block missing)';
		} elseif ( 200 === $code && ! $match ) {
			// Something answered 200 with content that isn't our nonce — a CDN,
			// a proxy, a security plugin. That tells us nothing about the
			// origin's rewrite.
			$reason       = 'unexpected body (CDN cached an older response?)';
			$inconclusive = true;
		} elseif ( 404 === $code ) {
			$reason = 'probe URL returned 404 (rewrite block missing or wrong path)';
		} else {
			// Redirects, 403s from a WAF, 5xx — the probe never reached a
			// verdict about the rewrite itself.
			$reason       = sprintf( 'unexpected response (HTTP %d, body %d B, php=%s)', $code, strlen( $body ), $ua_php ? 'yes' : 'no' );
			$inconclusive = true;
		}

		$result = array(
			'active'       => $active,
			'inconclusive' => $inconclusive,
			'reason'       => $reason,
			'code'         => $code,
			'php'          => $ua_php,
		);
		set_transient( 'xspeed_rewrite_probe', $result, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	public static function rewrite_installed(): bool {
		$htaccess = ABSPATH . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			return false;
		}
		$existing = @file_get_contents( $htaccess ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_string( $existing ) ) {
			return false;
		}
		return false !== strpos( $existing, '# BEGIN xSpeed Static Cache' );
	}

	/**
	 * Install the static-cache rewrite block at the TOP of .htaccess.
	 *
	 * Position matters: WordPress's own block ends with
	 * `RewriteRule . /index.php [L]` which routes every non-file
	 * request to PHP. The [L] flag stops the current rewrite pass,
	 * but Apache restarts the cycle; on the second pass REQUEST_URI
	 * is /index.php and no static-file check can match. The only
	 * reliable position for a "serve static if it exists" rule is
	 * before WordPress's block.
	 *
	 * WP's insert_with_markers() always appends, so we manage the
	 * block manually: strip any prior xSpeed Static Cache markers,
	 * then write our block followed by the rest of the file.
	 */
	public static function install_rewrite(): bool {
		// The static rewrite is device-blind; never install it when
		// mobile_separate is on (see static_rewrite_allowed()).
		if ( ! self::static_rewrite_allowed() ) {
			return false;
		}
		$htaccess = ABSPATH . '.htaccess';
		$existing = file_exists( $htaccess ) ? @file_get_contents( $htaccess ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $existing ) {
			$existing = '';
		}
		// Apache/LiteSpeed only. nginx hosts: rule won't fire, drop-in
		// covers; we skip the write so we don't litter their root.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Pre-flight check before file_put_contents; WP_Filesystem requires admin credentials we don't have inside a manage_options REST request.
		if ( file_exists( $htaccess ) && ! is_writable( $htaccess ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- See above.
		if ( ! file_exists( $htaccess ) && ! is_writable( ABSPATH ) ) {
			return false;
		}

		$cleaned = self::strip_marker_block( $existing, 'xSpeed Static Cache' );
		$block   = self::marker_block( 'xSpeed Static Cache', self::rewrite_block_lines() );
		$next    = $block . ( '' === $cleaned ? '' : "\n" . $cleaned );

		/*
		 * Nothing to change. auto_heal() runs the whole enable transaction on
		 * every admin_init and this is called unconditionally from it, so
		 * without this every wp-admin request truncated and rewrote .htaccess
		 * with byte-identical content. Apache reads that file without a lock,
		 * so the truncate window is a real 500 on a busy admin, and the churn
		 * trips host file-integrity monitors.
		 */
		if ( $next === $existing ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- WP_Filesystem requires admin credentials we don't have here; toggle() runs in a REST request authorized by manage_options nonce. The target is the site's .htaccess (configuration file managed by WP core itself), not user data — wp_upload_dir() doesn't apply.
		return false !== file_put_contents( $htaccess, $next, LOCK_EX );
	}

	/**
	 * Rewrite the .htaccess block in place when — and only when — one is
	 * already installed.
	 *
	 * The block embeds the generated cookie / user-agent exclusion rules,
	 * so it goes stale the moment those settings change. install_rewrite()
	 * regenerates it from the live settings, but calling that unconditionally
	 * on every save would CREATE a block on sites that never enabled the
	 * static path — silently turning on server-level serving nobody asked
	 * for. So we refresh only what's already there.
	 *
	 * @return bool True when a block was present and rewritten.
	 */
	public static function refresh_rewrite_if_installed(): bool {
		$htaccess = ABSPATH . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			return false;
		}
		$existing = @file_get_contents( $htaccess ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort read; an unreadable file simply means nothing to refresh.
		if ( ! is_string( $existing ) || false === strpos( $existing, '# BEGIN xSpeed Static Cache' ) ) {
			return false;
		}
		return self::install_rewrite();
	}

	public static function remove_rewrite(): bool {
		$htaccess = ABSPATH . '.htaccess';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- See install_rewrite() rationale.
		if ( ! file_exists( $htaccess ) || ! is_writable( $htaccess ) ) {
			return false;
		}
		$existing = @file_get_contents( $htaccess ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $existing ) {
			return false;
		}
		$cleaned = self::strip_marker_block( $existing, 'xSpeed Static Cache' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- See install_rewrite() rationale.
		return false !== file_put_contents( $htaccess, $cleaned, LOCK_EX );
	}

	/**
	 * Strip a `# BEGIN <marker>` ... `# END <marker>` block from a
	 * .htaccess-style file, including any blank line that immediately
	 * follows it. Idempotent — returns the input unchanged if the
	 * marker isn't present.
	 */
	private static function strip_marker_block( string $contents, string $marker ): string {
		/*
		 * The body may not contain another BEGIN for this marker.
		 *
		 * `.*?` is non-greedy but still spans anything, so an ORPHANED
		 * `# BEGIN xSpeed Static Cache` — an END line lost to a hand edit or
		 * a partial write — paired with the END of the NEXT block and deleted
		 * everything between them. On a site where the orphan sits above
		 * `# BEGIN WordPress`, that takes WordPress's own rewrite rules with
		 * it and every permalink 404s. Refusing to cross a second BEGIN makes
		 * the orphan a no-op instead of a site-wide outage.
		 */
		$begin   = '# BEGIN ' . preg_quote( $marker, '/' ) . '\b';
		$pattern = '/' . $begin . '(?:(?!' . $begin . ').)*?# END ' . preg_quote( $marker, '/' ) . "\b[^\n]*\n?\n?/s";
		$out     = preg_replace( $pattern, '', $contents );
		return is_string( $out ) ? $out : $contents;
	}

	private static function marker_block( string $marker, array $lines ): string {
		$header = "# BEGIN $marker\n";
		$header .= "# The directives (lines) between \"BEGIN $marker\" and \"END $marker\" are\n";
		$header .= "# dynamically generated, and should only be modified via WordPress filters.\n";
		$header .= "# Any changes to the directives between these markers will be overwritten.\n";
		$footer  = "# END $marker\n";
		return $header . implode( "\n", $lines ) . "\n" . $footer;
	}

	/**
	 * Parse the `XSPEED_DROPIN_VERSION: N` stamp out of a drop-in's source.
	 * Returns 0 when absent (an un-stamped older copy reinstalls). Used to
	 * detect a stale installed drop-in vs the bundled source.
	 */
	private static function dropin_version( string $contents ): int {
		if ( preg_match( '/XSPEED_DROPIN_VERSION:\s*(\d+)/', $contents, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/** The advanced-cache.php drop-in is ours. */
	public const DROPIN_XSPEED = 'xspeed';
	/** Someone else's drop-in is installed. */
	public const DROPIN_FOREIGN = 'foreign';
	/** No drop-in installed. */
	public const DROPIN_NONE = 'none';
	/** A drop-in is installed and we could not read it. */
	public const DROPIN_UNREADABLE = 'unreadable';
	/**
	 * Present but holding nothing -- empty, or whitespace only. WP Rocket
	 * truncates advanced-cache.php to 0 bytes on deactivate, and calling that
	 * FOREIGN made it a permanent blocker with no owner to ask. (#391)
	 */
	public const DROPIN_ABANDONED = 'abandoned';

	/**
	 * Who owns wp-content/advanced-cache.php right now.
	 *
	 * WordPress gives every caching plugin the same single file to live in,
	 * so "is there a drop-in" and "is it ours" are completely different
	 * questions, and only the second one licenses a write. An unreadable
	 * drop-in is deliberately its own answer rather than folding into
	 * "foreign": we cannot even name what we would be destroying.
	 *
	 * @return string One of the DROPIN_* constants.
	 */
	public static function dropin_owner(): string {
		require_once XSPEED_DIR . 'includes/wp-cache-constant.php';
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( ! file_exists( $target ) ) {
			return self::DROPIN_NONE;
		}

		$contents = self::read_file( $target );
		if ( null === $contents ) {
			return self::DROPIN_UNREADABLE;
		}

		if ( xspeed_has_canonical_dropin_signature( $contents ) ) {
			return self::DROPIN_XSPEED;
		}

		// Nothing in the file means nothing owns it. Kept distinct from
		// FOREIGN so the acquisition gate can tell "someone else's cache" from
		// "a husk the last plugin left behind". (#391)
		if ( '' === trim( $contents ) ) {
			return self::DROPIN_ABANDONED;
		}

		/*
		 * The other half of the same question, and it cannot be answered from
		 * the bytes: a file we cannot attribute is a COMPETITOR only while
		 * some page cache is actually running. With every candidate switched
		 * off it is abandoned -- a hosting company's own cache, a hand-rolled
		 * one, or a plugin that was deleted without cleaning up.
		 *
		 * Asking the detector rather than re-deriving it here is the point:
		 * these two answers disagreeing is a split brain with a bad ending --
		 * acquisition_blocker() opens the gate, install_dropin() then refuses
		 * on FOREIGN, and toggle() blames the filesystem for a write it never
		 * attempted. One question, one answer. (#391, #393)
		 */
		if ( class_exists( __NAMESPACE__ . '\\Page_Cache_Detector' ) ) {
			$owner = (string) ( Page_Cache_Detector::inspect()['dropin']['owner'] ?? '' );

			// Attributable to a named plugin -> somebody's cache, whatever its
			// activation state. Only a file NOBODY can be shown to own, with
			// nothing running, is abandoned.
			if ( Page_Cache_Detector::OWNER_UNKNOWN === $owner
				&& ! Page_Cache_Detector::another_page_cache_is_active() ) {
				return self::DROPIN_ABANDONED;
			}
		}

		return self::DROPIN_FOREIGN;
	}

	/**
	 * Why xSpeed must not install its page-cache artifacts right now, or null
	 * when it may.
	 *
	 * This is the single gate in front of every write that touches shared
	 * state — the drop-in and the WP_CACHE define. Both are single-occupancy:
	 * whatever is there belongs to exactly one plugin, and taking it silently
	 * breaks that plugin's caching with no way back.
	 *
	 * Returns a user-facing string, so a REST caller can hand it straight to
	 * the dashboard instead of reporting a bare failure.
	 */
	public static function acquisition_blocker(): ?string {
		Page_Cache_Detector::invalidate();
		$verdict = Page_Cache_Detector::classify();
		$owner   = self::dropin_owner();
		// The reason we refuse, whether that reason already names a plugin,
		// and every other page cache the detector counted anywhere in the
		// verdict. See the tail of this method for why all three are needed.
		$primary       = null;
		$primary_names = false;
		$named         = array();
		foreach ( $verdict['blockers'] as $blocker ) {
			$code = (string) ( $blocker['code'] ?? '' );
			// The shared detector quite correctly reports xSpeed itself as a
			// page-cache owner. That is not a competitor to this transaction.
			//
			// Except when the two disagree about the DROP-IN. The detector
			// accepts our marker anywhere in a file's header; this plugin's
			// own check requires it to open the header, because only this
			// side authorizes overwriting and deleting. A foreign drop-in
			// that merely carries our marker further down its header is
			// attributed to us by the detector, and skipping it here dropped
			// the refusal entirely — the write then failed on the stricter
			// check and the user was told to go and fix file permissions.
			// Where they disagree, believe the stricter one.
			if ( self::PLUGIN_FILE === ( $blocker['plugin'] ?? null ) ) {
				$about_dropin = in_array(
					$code,
					array(
						Page_Cache_Detector::BLOCKER_FOREIGN_DROPIN,
						Page_Cache_Detector::BLOCKER_UNKNOWN_DROPIN,
					),
					true
				);
				if ( ! $about_dropin || self::DROPIN_XSPEED === $owner ) {
					continue;
				}
			}
			if ( Page_Cache_Detector::BLOCKER_WP_CACHE_ORPHANED === $code && self::DROPIN_XSPEED === $owner ) {
				continue;
			}
			/*
			 * Another plugin's drop-in is no longer a refusal.
			 *
			 * It used to be: whoever held advanced-cache.php kept it, and
			 * enabling was blocked with "deactivate its page cache first".
			 * That left a user who had asked for our cache with no way to get
			 * it — on a live site the only exit was deleting a file over SSH,
			 * and the message could not even say which of its two causes
			 * applied ("is active OR owns advanced-cache.php").
			 *
			 * Turning the page cache on is the instruction to serve pages
			 * from cache, and that is not possible without this file. So we
			 * take it, and the dashboard says whose file it is first —
			 * dropin_disclosure() names the owner, the user confirms, and
			 * install_dropin() writes ours over the top.
			 *
			 * A still-active competitor is deliberately NOT re-added as a
			 * blocker below: it is caught by `active_page_cache`, which the
			 * capability rule already downgrades to a note. Two page caches
			 * installed at once is the user's call to make, not ours to
			 * refuse — they just told us which one they want serving.
			 *
			 * UNREADABLE is the exception and stays a refusal: we cannot name
			 * what we would destroy, and install_dropin() refuses it too, so
			 * opening the gate here would only produce a failed write.
			 */
			$about_dropin_owner = in_array(
				$code,
				array(
					Page_Cache_Detector::BLOCKER_FOREIGN_DROPIN,
					Page_Cache_Detector::BLOCKER_UNKNOWN_DROPIN,
				),
				true
			);
			if ( $about_dropin_owner && self::DROPIN_UNREADABLE !== $owner ) {
				continue;
			}
			/*
			 * Capability is not possession. `active_page_cache` and
			 * `multiple_page_caches` both fire on a plugin that merely CAN
			 * cache pages — the detector cannot prove a competitor's page
			 * cache is off, so it counts it. As a warning that is right. As
			 * a gate it refuses a write that takes nothing from anyone.
			 *
			 * This gate guards exactly two files: advanced-cache.php and the
			 * WP_CACHE define that loads it. A plugin that does not hold the
			 * drop-in has nothing here for us to overwrite, and one that does
			 * is already refused by `foreign_dropin` / `unknown_dropin` a few
			 * lines up. So when the field is ours or empty, an active
			 * competitor is a note, not a refusal.
			 *
			 * QA found this on a live OpenLiteSpeed site keeping LiteSpeed
			 * Cache for images and CDN with its page cache off, while xSpeed
			 * served the pages. One click of the off switch and it could not
			 * be turned back on: the only way out was deactivating LiteSpeed
			 * entirely, and the message told them to "deactivate its page
			 * cache" — which they already had.
			 */
			$about_capability = in_array(
				$code,
				array(
					Page_Cache_Detector::BLOCKER_ACTIVE_PAGE_CACHE,
					Page_Cache_Detector::BLOCKER_MULTIPLE_PAGE_CACHES,
				),
				true
			);
			/*
			 * FOREIGN belongs in this list now, and it is the whole point.
			 *
			 * The rule is still "capability is not possession": these two
			 * blockers fire on any plugin that CAN cache pages, which the
			 * detector cannot prove is switched off. What changed is that a
			 * competitor holding the drop-in no longer stops us either — we
			 * take the file, having said whose it is. So there is nothing
			 * left for a merely-installed competitor to protect, and keeping
			 * the refusal here would put back the dead end by another route:
			 * "another page cache is active" on a site where the user has
			 * just told us, by name, which cache they want serving.
			 *
			 * UNREADABLE is deliberately still absent — that one refuses.
			 */
			if ( $about_capability
				&& in_array( $owner, array( self::DROPIN_XSPEED, self::DROPIN_NONE, self::DROPIN_FOREIGN, self::DROPIN_ABANDONED ), true ) ) {
				continue;
			}
			if ( Page_Cache_Detector::BLOCKER_MULTIPLE_PAGE_CACHES === $code ) {
				$others = self::other_page_cache_names( $blocker );
				if ( array() === $others ) {
					// We were the only owner counted — nothing to refuse —
					// unless the list is missing entirely, which is an older
					// detector copy we still must not talk past.
					if ( null === $primary && array() === (array) ( $blocker['plugins'] ?? array() ) ) {
						$primary = self::ownership_blocker_message( '', '' );
					}
					continue;
				}
				$named = array_values( array_unique( array_merge( $named, $others ) ) );
				if ( null === $primary ) {
					$primary       = self::multiple_page_caches_message( $others );
					$primary_names = true;
				}
				continue;
			}
			if ( null === $primary ) {
				$label         = (string) ( $blocker['label'] ?? '' );
				$primary       = self::ownership_blocker_message( $code, $label );
				$primary_names = '' !== $label;
			}
		}

		if ( null === $primary ) {
			return null;
		}
		/*
		 * The first blocker decides WHY we refuse; it does not always know
		 * WHO. The detector can only attribute a drop-in it recognises, and
		 * an unrecognised one produces "its owner cannot be proved" — the
		 * sentence a W3 Total Cache site used to get while a later blocker in
		 * the same verdict was holding the name "W3 Total Cache".
		 *
		 * So keep the reason and add the names, rather than swapping one for
		 * the other: the plugin the user must deal with is not necessarily
		 * the owner of the file we could not identify, and promoting the
		 * named blocker would have told them to deactivate a plugin that is
		 * not what is in their way.
		 */
		if ( $primary_names || array() === $named ) {
			return $primary;
		}
		if ( 1 === count( $named ) ) {
			return sprintf(
				/* translators: 1: the refusal reason, 2: a page-caching plugin's name. */
				__( '%1$s %2$s is also active on this site — deactivate its page cache before enabling xSpeed.', 'xspeed' ),
				$primary,
				$named[0]
			);
		}
		return sprintf(
			/* translators: 1: the refusal reason, 2: comma-separated page-caching plugin names. */
			__( '%1$s These page caches are also active on this site: %2$s. Deactivate them before enabling xSpeed.', 'xspeed' ),
			$primary,
			implode( ', ', $named )
		);
	}

	/** How xSpeed's own plugin file appears in the detector's catalog. */
	private const PLUGIN_FILE = 'xspeed/xspeed.php';

	/**
	 * Name the OTHER page caches behind a `multiple_page_caches` refusal.
	 *
	 * This blocker has no single owner, so the detector leaves `plugin` and
	 * `label` null and hands over the full list instead. Left unhandled it
	 * fell through to the anonymous fallback sentence — and it is the blocker
	 * an ordinary site hits most: xSpeed counts toward "multiple", so the
	 * count reaches two the moment one other page-cache plugin is activated,
	 * even one that has not written a drop-in. A site running our cache that
	 * activated LiteSpeed could not re-enable it and was told only that "the
	 * page-cache field is occupied".
	 *
	 * Returns an empty list when xSpeed was the only owner counted, or when
	 * an older detector copy sent no list at all — the caller distinguishes
	 * the two by looking at `plugins`.
	 *
	 * @param array<string,mixed> $blocker One entry from Detector::classify().
	 * @return string[]
	 */
	private static function other_page_cache_names( array $blocker ): array {
		$plugins = array_values( (array) ( $blocker['plugins'] ?? array() ) );
		$labels  = array_values( (array) ( $blocker['labels'] ?? array() ) );

		$others = array();
		foreach ( $plugins as $i => $plugin ) {
			if ( self::PLUGIN_FILE === $plugin ) {
				continue;
			}
			$others[] = isset( $labels[ $i ] ) && '' !== (string) $labels[ $i ]
				? (string) $labels[ $i ]
				: (string) $plugin;
		}
		return array_values( array_unique( $others ) );
	}

	/**
	 * The refusal sentence for a `multiple_page_caches` blocker.
	 *
	 * @param string[] $others Page caches other than xSpeed. Never empty.
	 */
	private static function multiple_page_caches_message( array $others ): string {
		if ( 1 === count( $others ) ) {
			return self::ownership_blocker_message( Page_Cache_Detector::BLOCKER_ACTIVE_PAGE_CACHE, $others[0] );
		}
		return sprintf(
			/* translators: %s: comma-separated list of page-caching plugin names. */
			__( 'More than one page cache is active on this site (%s). Turn off the other page caches before enabling xSpeed.', 'xspeed' ),
			implode( ', ', $others )
		);
	}

	private static function ownership_blocker_message( string $code, string $label ): string {
		if ( '' !== $label ) {
			return sprintf( __( '%s is active or owns advanced-cache.php. Deactivate its page cache before enabling xSpeed.', 'xspeed' ), $label );
		}
		$messages = array(
			'wp_cache_orphaned'   => __( 'WP_CACHE is true but no page-cache drop-in owner can be proved. xSpeed will not claim it.', 'xspeed' ),
			'wp_cache_duplicate'  => __( 'wp-config.php defines WP_CACHE more than once. Remove the duplicate before enabling the cache.', 'xspeed' ),
			'wp_cache_dynamic'    => __( 'WP_CACHE is set from an expression in wp-config.php. xSpeed will not rewrite it.', 'xspeed' ),
			'wp_cache_conditional' => __( 'WP_CACHE is defined inside a conditional in wp-config.php, so xSpeed cannot tell what it will be. Move it to a plain define before enabling the cache.', 'xspeed' ),
			'wp_config_unreadable' => __( 'wp-config.php cannot be read, so xSpeed cannot safely change page-cache ownership.', 'xspeed' ),
			'unknown_dropin'      => __( 'advanced-cache.php is occupied but its owner cannot be proved. xSpeed will not replace it.', 'xspeed' ),
			'unreadable_dropin'   => __( 'advanced-cache.php cannot be read, so xSpeed cannot prove its owner.', 'xspeed' ),
		);
		return $messages[ $code ] ?? __( 'The page-cache field is occupied or cannot be verified. xSpeed will not change it.', 'xspeed' );
	}

	/**
	 * How WP_CACHE is written in wp-config.php, as opposed to what it
	 * evaluates to at runtime.
	 *
	 * The literal is what matters to a writer: a value behind an expression,
	 * or two competing defines, cannot be rewritten by a regex without
	 * guessing — and a wrong guess silently disables page caching (ours or
	 * someone else's) with no error anywhere.
	 *
	 * @return string undefined | true | false | duplicate | dynamic | conditional | unreadable
	 */
	public static function wp_cache_define_state(): string {
		$path = self::wp_config_path();
		if ( '' === $path ) {
			return 'unreadable';
		}

		$config = self::read_file( $path );
		if ( null === $config ) {
			return 'unreadable';
		}

		require_once XSPEED_DIR . 'includes/wp-cache-constant.php';
		$parsed = \xspeed_parse_wp_cache_defines( $config );
		return $parsed['state'];
	}

	/**
	 * Classify the captured right-hand side of a WP_CACHE define.
	 *
	 * Hosts and older tutorials write the value several ways —
	 * `1`, `'1'`, `TRUE` — and all of them are literals a rewrite can safely
	 * replace. Only a value we cannot evaluate by looking at it (a variable, a
	 * function call, a ternary) counts as dynamic, because that is the case
	 * where rewriting means guessing.
	 *
	 * @return string true | false | dynamic
	 */
	private static function classify_wp_cache_literal( string $raw ): string {
		$literal = strtolower( trim( $raw ) );
		$literal = trim( $literal, "'\"" );

		if ( in_array( $literal, array( 'true', '1' ), true ) ) {
			return 'true';
		}
		if ( in_array( $literal, array( 'false', '0', '', 'null' ), true ) ) {
			return 'false';
		}
		return 'dynamic';
	}

	/**
	 * Read a file for an ownership decision. Null on any failure — callers
	 * treat null as "unknown", never as "empty", because an empty string
	 * would read as "no marker found" and license an overwrite.
	 */
	private static function read_file( string $path ): ?string {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Ownership check on a local file; WP_Filesystem would need credentials we must not prompt for here.
		$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed read is a valid answer ("unknown"), not an error to surface.
		return is_string( $contents ) ? $contents : null;
	}

	public static function install_dropin() {
		$source = XSPEED_DIR . 'includes/advanced-cache.php';
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( ! file_exists( $source ) ) {
			return false;
		}

		/*
		 * A drop-in we cannot READ is the one thing still refused here. Not
		 * because of who owns it — we no longer refuse on ownership — but
		 * because an unreadable file is usually a permissions problem, and
		 * writing over it would fail anyway or destroy something we were
		 * never able to look at.
		 *
		 * Everything else is ours to take. Enabling the page cache IS the
		 * user's instruction to serve the cache, and serving it means holding
		 * advanced-cache.php; the dashboard says whose file it is replacing
		 * before the click (Page_Cache_Detector::dropin_disclosure()), so the
		 * takeover is consented rather than silent.
		 */
		$owner = self::dropin_owner();
		if ( self::DROPIN_UNREADABLE === $owner ) {
			return false;
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		if ( ! $wp_filesystem ) {
			return false;
		}

		$source_contents = $wp_filesystem->get_contents( $source );
		if ( ! is_string( $source_contents ) ) {
			return false;
		}

		// Bake the absolute hit-log path into the drop-in. It runs before
		// WordPress loads, so it can't resolve wp_upload_dir() itself — we
		// substitute the @@XSPEED_HITS_LOG@@ token with the real uploads path
		// (never the cache dir; see hits_log_dir() / FBS-82478). Use a single
		// quoted PHP string literal so the installed file stays valid PHP.
		$source_contents = str_replace(
			'@@XSPEED_HITS_LOG@@',
			str_replace( "'", "\\'", self::hits_log_path() ),
			$source_contents
		);

		// Bake the cookie + user-agent exclusion rules in too. The drop-in
		// runs before WordPress loads, so it cannot read the settings — and
		// without them it served the shared anonymous page to any visitor
		// PHP had not yet seen (a first-time cart visitor, a bypassed bot).
		// The generic bypass cookie only covers repeat visitors; these two
		// regexes are what make the FIRST request correct.
		//
		// Both are already fully escaped by Server_Rules, and each is
		// embedded as a single-quoted PHP literal, so a settings value can
		// neither break the drop-in's syntax nor execute.
		$cache_opts  = Settings_Manager::get( 'cache' );
		$cookie_rule = Server_Rules::cookie_rule(
			is_array( $cache_opts['excluded_cookies'] ?? null ) ? $cache_opts['excluded_cookies'] : array()
		);
		$ua_rule = Server_Rules::user_agent_rule(
			is_array( $cache_opts['bypass_user_agents'] ?? null ) ? $cache_opts['bypass_user_agents'] : array()
		);

		$source_contents = str_replace(
			'@@XSPEED_COOKIE_RE@@',
			str_replace( "'", "\\'", $cookie_rule['regex'] ),
			$source_contents
		);
		$source_contents = str_replace(
			'@@XSPEED_UA_RE@@',
			str_replace( "'", "\\'", $ua_rule['regex'] ),
			$source_contents
		);

		/*
		 * Ours or absent — the ownership gate at the top of this method ruled
		 * out everything else. The old code path that moved a foreign drop-in
		 * into uploads/xspeed-backups and wrote ours on top is gone: it
		 * disabled the other plugin's page cache the moment an xSpeed install
		 * ran, with nothing in its own UI to explain why.
		 */

		// Bake the configured cache lifetime in. The drop-in runs before
		// WordPress loads, so it cannot read the option — it previously fell
		// back to a hardcoded 86400 for every ordinary page, because
		// write_meta() only emits a `ttl` sidecar when the value DIFFERS from
		// the page default. That made the admin's "1 to 720 hours" control a
		// no-op at the layer that actually answers the request: 12h served
		// stale for up to 2x the configured lifetime, and 168h lost the fast
		// path for 6 of every 7 days (issue #240).
		//
		// This is re-baked on every cache settings save (see CacheModule::boot),
		// exactly like the cookie / user-agent rules above.
		$expiry_hours = isset( $cache_opts['cache_expiry'] ) ? (int) $cache_opts['cache_expiry'] : 24;
		if ( $expiry_hours < 1 || $expiry_hours > 720 ) {
			$expiry_hours = 24;
		}
		$source_contents = str_replace(
			'@@XSPEED_DEFAULT_TTL@@',
			(string) ( $expiry_hours * HOUR_IN_SECONDS ),
			$source_contents
		);

		// Bake the site-wide edge answer in. Resolved in a `bake` context, so
		// nothing per-page and nothing a request header vouched for can reach
		// it: a bake runs once, in an admin or CLI request, and answers for
		// every page on the site. A page that disagrees gets a sidecar
		// instead — see per_entry_edge_headers().
		//
		// Re-baked on every cache settings save (see CacheModule::boot),
		// exactly like the cookie, user-agent and lifetime rules above.
		$source_contents = str_replace(
			"'@@XSPEED_EDGE_HEADERS@@'",
			self::edge_headers_literal( self::edge_headers_for( 'HIT', 'bake' ) ),
			$source_contents
		);

		if ( file_exists( $target ) ) {
			$existing = $wp_filesystem->get_contents( $target );
			if ( is_string( $existing ) && $existing === $source_contents ) {
				return true;
			}
		}

		return (bool) $wp_filesystem->put_contents( $target, $source_contents, FS_CHMOD_FILE );
	}

	public static function remove_dropin() {
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( ! file_exists( $target ) ) {
			return;
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		if ( ! $wp_filesystem ) {
			return;
		}

		$contents = $wp_filesystem->get_contents( $target );
		if ( is_string( $contents ) && xspeed_has_canonical_dropin_signature( $contents ) ) {
			wp_delete_file( $target );
		}
	}

	/**
	 * Where wp-config.php actually is.
	 *
	 * WordPress core supports the file one directory ABOVE ABSPATH, and
	 * plenty of installs use that layout. This used to look only in ABSPATH
	 * and bail, so on those sites the constant could never be written — while
	 * Health, which did fall back to the parent, reported the file writable
	 * and told the user to toggle the cache off and on. The advice could
	 * never work, and its fallback hint ("another plugin left WP_CACHE false
	 * behind") was wrong too: there was no define at all. (#19, QA on #174)
	 *
	 * Returns '' when no wp-config.php can be found in either location.
	 */
	public static function wp_config_path(): string {
		$candidates = array( ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' );
		foreach ( $candidates as $path ) {
			if ( file_exists( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Can we actually write the constant right now?
	 *
	 * This is the single oracle for that question — Health asks THIS rather
	 * than running its own `wp_is_writable()` test, so the message a user
	 * reads can never disagree with what the plugin will do. The two differed
	 * in both directions: on the path (above) and on the test itself, since
	 * an FTP/SSH WP_Filesystem transport can refuse a file that
	 * `wp_is_writable()` reports as writable. (#19, QA on #174)
	 */
	public static function can_write_wp_config(): bool {
		$wp_config = self::wp_config_path();
		if ( '' === $wp_config ) {
			return false;
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		return (bool) ( $wp_filesystem && $wp_filesystem->is_writable( $wp_config ) );
	}

	public static function set_wp_cache_constant( $enable ) {
		$wp_config = self::wp_config_path();
		if ( '' === $wp_config ) {
			return false;
		}

		/*
		 * WP_CACHE belongs to whoever owns the drop-in — it is the switch that
		 * makes core load that one file. Editing it while someone else's
		 * drop-in is installed either turns THEIR cache on or off; either way
		 * it is a write to another plugin's state. So: no ownership, no edit.
		 */
		$owner = self::dropin_owner();
		if ( self::DROPIN_FOREIGN === $owner || self::DROPIN_UNREADABLE === $owner ) {
			return false;
		}

		$state = self::wp_cache_define_state();
		if ( 'duplicate' === $state || 'dynamic' === $state ) {
			// Two competing defines, or a value behind an expression. A regex
			// rewrite here is a guess, and a wrong guess silently kills page
			// caching with no error anywhere.
			return false;
		}
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		if ( ! $wp_filesystem ) {
			return false;
		}

		$config = $wp_filesystem->get_contents( $wp_config );
		if ( ! is_string( $config ) ) {
			return false;
		}
		require_once XSPEED_DIR . 'includes/wp-cache-constant.php';
		$marker  = $enable ? self::wp_cache_receipt() : '';
		$updated = xspeed_rewrite_wp_cache_define( $config, (bool) $enable, $marker );
		if ( ! is_string( $updated ) ) {
			return false;
		}

		/*
		 * Removing a WP_CACHE line we cannot prove we wrote is somebody else's
		 * configuration, so a disable needs either our drop-in or our receipt.
		 *
		 * The test is on the REWRITE, not on the request: it used to run
		 * before the rewrite and refuse a disable that had nothing to remove.
		 * An ordinary site with no drop-in and no define — every fresh
		 * install — therefore failed to turn page caching off, so the
		 * onboarding wizard reported "setup needs attention" to every user who
		 * declined it and Migration reported the cache import as failed.
		 */
		if ( ! $enable && $updated !== $config
			&& self::DROPIN_XSPEED !== $owner
			&& ! self::wp_cache_receipt_matches_source( $config ) ) {
			return false;
		}

		/*
		 * Nothing to write. auto_heal() runs the whole enable transaction on
		 * every admin_init, so without this every wp-admin request rewrote
		 * wp-config.php with byte-identical content: pointless disk churn
		 * that trips host file-integrity monitors and widens the window for
		 * a concurrent write on a busy admin.
		 *
		 * It is also what makes a correct WP_CACHE on a read-only
		 * wp-config.php succeed. A managed host that ships the file
		 * unwritable, on a site where the user already pasted the define,
		 * is in the state we wanted — the writability test below is about
		 * whether we can CHANGE the file, and there is nothing to change.
		 */
		if ( $updated === $config ) {
			if ( ! $enable ) {
				// Our line is not in the file, so the receipt that proved we
				// wrote it is stale — drop it on the same terms as a real
				// removal, or uninstall keeps a claim on nothing.
				delete_option( 'xspeed_page_cache_ownership_receipt' );
			}
			return true;
		}

		if ( ! $wp_filesystem->is_writable( $wp_config ) ) {
			return false;
		}
		$written = (bool) $wp_filesystem->put_contents( $wp_config, $updated, FS_CHMOD_FILE );
		if ( $written && ! $enable ) {
			delete_option( 'xspeed_page_cache_ownership_receipt' );
		}
		return $written;
	}

	private static function wp_cache_receipt(): string {
		$receipt = get_option( 'xspeed_page_cache_ownership_receipt', '' );
		if ( is_string( $receipt ) && preg_match( '/^[a-f0-9]{32}$/', $receipt ) ) {
			return $receipt;
		}
		$receipt = substr( hash( 'sha256', XSPEED_DIR . microtime( true ) . mt_rand() ), 0, 32 );
		update_option( 'xspeed_page_cache_ownership_receipt', $receipt, false );
		return $receipt;
	}

	/**
	 * Is the WP_CACHE line in wp-config.php ours to REMOVE?
	 *
	 * Two different questions live here and only one of them matters. "Did we
	 * write it" is answered by our drop-in on disk or by our receipt comment
	 * beside the define. "Is it ours to remove" also asks what the line does
	 * NOW — and once a competitor owns advanced-cache.php, a line we wrote
	 * ourselves is the switch that loads THEIR drop-in. They had no reason to
	 * touch an already-true define, so our receipt is still sitting on it.
	 * Removing it there would stop their live page cache.
	 *
	 * So a foreign or unreadable owner is never ours to remove, whatever the
	 * receipt says, and the caller treats that as a reason to leave the line
	 * and get on with disabling our own cache — not as a reason to refuse.
	 */
	private static function wp_cache_define_is_ours_to_remove( string $owner ): bool {
		if ( self::DROPIN_FOREIGN === $owner || self::DROPIN_UNREADABLE === $owner ) {
			return false;
		}
		if ( self::DROPIN_XSPEED === $owner ) {
			return true;
		}
		$path = self::wp_config_path();
		if ( '' === $path ) {
			return false;
		}
		$config = self::read_file( $path );
		return is_string( $config ) && self::wp_cache_receipt_matches_source( $config );
	}

	private static function wp_cache_receipt_matches_source( string $source ): bool {
		$receipt = get_option( 'xspeed_page_cache_ownership_receipt', '' );
		require_once XSPEED_DIR . 'includes/wp-cache-constant.php';
		return xspeed_wp_cache_receipt_matches( $source, $receipt );
	}

	/**
	 * Admin-bar purge menu — a parent node plus one child per visible cache
	 * type (LiteSpeed-style), instead of a single "Purge All" link. Each
	 * child posts to the same admin-post handler with its type slug. The
	 * per-type items only appear for active/licensed modules; "Purge All"
	 * always shows and always sweeps everything. (FBS-83114)
	 *
	 * The parent node links to the settings page rather than a purge URL —
	 * clicking the top-level item used to wipe the whole cache instantly with
	 * no confirmation, which is far too destructive for a stray click. Purging
	 * stays available (and explicit) through the child items. (FBS-84068)
	 */
	public function admin_bar_purge( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'xspeed-purge',
				'title' => __( 'xSpeed Cache', 'xspeed' ),
				'href'  => admin_url( 'admin.php?page=' . Admin::PAGE_SLUG ),
			)
		);

		// Settings first, then the two whole-errand actions (Purge All,
		// Purge this URL), then the per-type items. The order is the one WP
		// Rocket uses, and it front-loads what people open this menu for:
		// nobody reaches for "Purge Object Cache" as often as they reach for
		// the page they are looking at.
		$wp_admin_bar->add_node(
			array(
				'id'     => 'xspeed-purge-settings',
				'parent' => 'xspeed-purge',
				'title'  => esc_html__( 'Settings', 'xspeed' ),
				'href'   => admin_url( 'admin.php?page=' . Admin::PAGE_SLUG ),
			)
		);

		$types = self::purge_types();

		// 'all' is rendered out of band so the single-URL item can sit
		// directly under it. A filter that reorders or drops it is honoured:
		// the loop below skips whatever was emitted here.
		$emitted = array();
		if ( ! empty( $types['all']['visible'] ) ) {
			$wp_admin_bar->add_node(
				array(
					'id'     => 'xspeed-purge-all',
					'parent' => 'xspeed-purge',
					'title'  => esc_html( $types['all']['label'] ),
					'href'   => self::purge_type_url( 'all' ),
				)
			);
			$emitted['all'] = true;
		}

		// Only when the current screen is about one thing — a front-end view,
		// or a published post's edit screen. On a list table or a settings
		// page there is nothing for "this" to mean, so the item stays hidden
		// rather than silently targeting the dashboard. Purge_Ui decides both
		// the label and the scope, which differ between the two contexts.
		$context = Purge_Ui::context_node();
		if ( null !== $context ) {
			$wp_admin_bar->add_node(
				array(
					'id'     => 'xspeed-purge-this-url',
					'parent' => 'xspeed-purge',
					'title'  => esc_html( $context['title'] ),
					'href'   => $context['href'],
				)
			);
		}

		foreach ( $types as $slug => $type ) {
			if ( empty( $type['visible'] ) || isset( $emitted[ $slug ] ) ) {
				continue;
			}
			$wp_admin_bar->add_node(
				array(
					'id'     => 'xspeed-purge-' . $slug,
					'parent' => 'xspeed-purge',
					'title'  => esc_html( $type['label'] ),
					'href'   => self::purge_type_url( $slug ),
				)
			);
		}
	}

	/**
	 * Nonce-protected admin-post URL for purging a single type. The nonce
	 * action is per-type so a leaked URL can't be replayed for a different
	 * scope.
	 */
	private static function purge_type_url( string $type ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=xspeed_purge&type=' . rawurlencode( $type ) ),
			'xspeed_purge_' . $type
		);
	}

	public function handle_admin_bar_purge() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'xspeed' ), 403 );
		}
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'all';
		check_admin_referer( 'xspeed_purge_' . $type );

		// Only honour known types; anything else falls back to a full purge.
		if ( ! array_key_exists( $type, self::purge_types() ) ) {
			$type = 'all';
		}
		self::purge_type( $type );

		wp_safe_redirect( self::safe_purge_redirect( wp_get_referer() ) );
		exit;
	}

	/**
	 * Resolve a safe redirect target for an admin-bar purge.
	 *
	 * The purge sends the admin back where they came from — but the referer
	 * can be a ONE-SHOT action URL (e.g. update.php?action=upload-plugin from
	 * installing a plugin zip, or any *.php?action=… that consumed a POST /
	 * temp upload). Redirecting there re-runs the action with nothing to act
	 * on, so WordPress dies — the classic "Please select a file" from
	 * File_Upload_Upgrader. Strip the transient action args so we return to a
	 * safe, re-GET-able view of the same page; fall back to the dashboard when
	 * there is no usable referer.
	 *
	 * @param string|false $referer Raw wp_get_referer() value.
	 * @return string Safe URL to redirect to.
	 */
	public static function safe_purge_redirect( $referer ): string {
		$referer = is_string( $referer ) ? $referer : '';
		if ( '' === $referer ) {
			return admin_url();
		}

		// A referer that lands on an action-processing endpoint (update.php,
		// update-core.php, plugin/theme install/upload flows) can't be safely
		// re-requested — send them to the dashboard instead of replaying it.
		$path = (string) wp_parse_url( $referer, PHP_URL_PATH );
		if ( preg_match( '#/wp-admin/(update|update-core)\.php$#', $path ) ) {
			return admin_url();
		}

		// Otherwise keep them on the same page but drop the query args that
		// would re-trigger a form action or upload on load.
		return remove_query_arg(
			array( 'action', 'action2', 'package', 'overwrite', 'plugin', 'theme', 'file', '_wpnonce', '_ajax_nonce' ),
			$referer
		);
	}
}
