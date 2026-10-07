<?php
/**
 * Recommendations — the "Next best action" engine (issue #48).
 *
 * Deterministic rules only (zero AI cost): each rule inspects plugin
 * state and, when it applies, emits ONE ranked recommendation with either
 * a one-click server-side fix (`apply` action → routed through
 * Settings_Manager::update, so it validates against the schema AND lands
 * in the change log like any other write) or a deep-link (`link` action →
 * the dashboard's #hash router).
 *
 * evaluate() is pure — state in, ranked recommendations out — so every
 * rule is unit-testable without WordPress. state() gathers the live
 * inputs, reading ONLY cached probe verdicts (never paying for an HTTP
 * probe on a dashboard paint).
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Recommendations {

	/**
	 * Bounds on what `xspeed_recommendations` may add.
	 *
	 * The list renders in the Overview card and the cache page's next-best
	 * action, and the REST route returns it as is. The caps are well above any
	 * honest use and keep a buggy contributor from flooding either screen.
	 */
	private const MAX_CONTRIBUTED = 5;
	private const MAX_TITLE_LEN   = 120;
	private const MAX_DETAIL_LEN  = 400;
	private const MAX_LABEL_LEN   = 40;
	private const MAX_TAG_LEN     = 24;
	private const FILTER          = 'xspeed_recommendations';

	/**
	 * Evaluate all rules against a state snapshot. Pure — unit-tested.
	 *
	 * @param array $state See state() for the shape.
	 * @return array<int,array{id:string,priority:int,title:string,detail:string,action:array<string,mixed>}>
	 *         Sorted most-important-first (ascending priority).
	 */
	public static function evaluate( array $state ): array {
		$out = array();

		$cache_enabled = ! empty( $state['cache_enabled'] );
		$hits          = (int) ( $state['hits_24h'] ?? 0 );
		$misses        = (int) ( $state['misses_24h'] ?? 0 );
		$traffic       = $hits + $misses;
		$ratio         = (float) ( $state['hit_ratio'] ?? 0 );

		// 0. Cache off entirely — nothing else matters until this is on.
		if ( ! $cache_enabled ) {
			$out[] = array(
				'id'       => 'cache_disabled',
				'priority' => 5,
				'title'    => __( 'Enable page caching', 'xspeed' ),
				'detail'   => __( 'Caching is off, so every visit renders the full page. Turning it on is the single biggest speed win.', 'xspeed' ),
				'action'   => array_merge(
					array( 'type' => 'link' ),
					// Lands ON the enable toggle, not merely on the panel
					// that contains it (issue #49).
					// No `suggest` here: cache_enabled is deliberately outside
					// the cache schema (it drives the drop-in install), so the
					// Apply strip — which only renders on a schema row — could
					// never appear for it. Offering a value nothing can apply
					// is worse than offering none.
					Deep_Link::action( __( 'Go to Cache settings', 'xspeed' ), 'cache', 'cache_enabled' )
				),
			);
			// Later rules assume a running cache; report just this one.
			return $out;
		}

		// 1. Expiry shorter than the preloader interval (issue #31): pages
		// expire before the next crawl re-warms them — the classic silent
		// hit-ratio killer. One-click fix raises expiry to the interval.
		$interval = Health::PRELOAD_INTERVALS[ (string) ( $state['preload_schedule'] ?? '' ) ] ?? null;
		$expiry   = (int) ( $state['cache_expiry'] ?? 0 );
		if ( ! empty( $state['preloader_enabled'] ) && null !== $interval && $expiry > 0 && $expiry < $interval ) {
			$out[] = array(
				'id'       => 'expiry_preload_mismatch',
				'priority' => 10,
				'title'    => __( 'Raise Cache Expiry to match your preload schedule', 'xspeed' ),
				'detail'   => sprintf(
					/* translators: 1: current expiry hours, 2: preload interval hours. */
					__( 'Pages expire after %1$dh but the preloader only re-warms them every %2$dh — most visits hit a cold cache.', 'xspeed' ),
					$expiry,
					$interval
				),
				'action'   => array(
					'type'   => 'apply',
					'module' => 'cache',
					'values' => array( 'cache_expiry' => $interval ),
					'label'  => sprintf(
						/* translators: %d: recommended expiry in hours. */
						__( 'Raise expiry to %dh', 'xspeed' ),
						$interval
					),
				),
			);
		}

		// 2. A plugin is setting cookies on anonymous pages (issue #33):
		// CDN edge caches BYPASS any response carrying Set-Cookie.
		$cookies = isset( $state['poison_cookies'] ) && is_array( $state['poison_cookies'] ) ? $state['poison_cookies'] : array();
		if ( ! empty( $cookies ) ) {
			$first   = $cookies[0];
			$culprit = ! empty( $first['plugin'] ) ? (string) $first['plugin'] : __( 'A plugin', 'xspeed' );
			$out[]   = array(
				'id'       => 'set_cookie_poisoning',
				'priority' => 15,
				'title'    => __( 'A plugin is blocking CDN edge caching', 'xspeed' ),
				'detail'   => sprintf(
					/* translators: 1: plugin name, 2: cookie name. */
					__( '%1$s sets the "%2$s" cookie on anonymous pages, which makes CDNs skip their edge cache for all HTML.', 'xspeed' ),
					$culprit,
					(string) ( $first['name'] ?? '' )
				),
				'action'   => array_merge(
					array( 'type' => 'link' ),
					// Health is a read-only panel — there is no control to
					// focus, so this one carries the destination only.
					Deep_Link::action( __( 'See details in Health', 'xspeed' ), 'health' )
				),
			);
		}

		// 3. nginx detected but the server-level rewrite isn't serving hits.
		if ( 'nginx' === ( $state['server'] ?? '' ) && false === ( $state['rewrite_active'] ?? null ) ) {
			$out[] = array(
				'id'       => 'nginx_snippet_missing',
				'priority' => 20,
				'title'    => __( 'Apply the nginx server snippet', 'xspeed' ),
				'detail'   => __( 'nginx can serve cache hits directly (~5-15ms TTFB, PHP bypassed) once the snippet is in your server block.', 'xspeed' ),
				'action'   => array_merge(
					array( 'type' => 'link' ),
					Deep_Link::action( __( 'Get the snippet', 'xspeed' ), 'cache', 'nginx_snippet' )
				),
			);
		}

		// 4. Preloader off while the hit ratio is poor — with real traffic.
		if ( empty( $state['preloader_enabled'] ) && $traffic >= 50 && $ratio < 0.5 ) {
			$out[] = array(
				'id'       => 'preloader_off_low_ratio',
				'priority' => 25,
				'title'    => __( 'Turn on the preloader', 'xspeed' ),
				'detail'   => sprintf(
					/* translators: %d: hit ratio percent. */
					__( 'Your hit ratio is %d%% — most visitors hit a cold cache. The preloader crawls your sitemap so pages are warm before anyone asks.', 'xspeed' ),
					(int) round( $ratio * 100 )
				),
				'action'   => array(
					'type'   => 'apply',
					'module' => 'preloader',
					'values' => array( 'enabled' => true ),
					'label'  => __( 'Enable preloader', 'xspeed' ),
				),
			);
		}

		// 5. Object cache configured but not actually persisting.
		if ( ! empty( $state['objcache_enabled'] ) && empty( $state['objcache_persistent'] ) ) {
			$out[] = array(
				'id'       => 'object_cache_degraded',
				'priority' => 30,
				'title'    => __( 'Object cache is configured but not persisting', 'xspeed' ),
				'detail'   => __( 'The backend is not connected, so every request falls back to the database. Run the connection test to see why.', 'xspeed' ),
				'action'   => array_merge(
					array( 'type' => 'link' ),
					Deep_Link::action( __( 'Test the connection', 'xspeed' ), 'object-cache', 'connection_test' )
				),
			);
		}

		// 6. Cloudflare enabled but missing credentials/zone — configured in
		// name only; purges will silently do nothing.
		if ( ! empty( $state['cloudflare_enabled'] ) && empty( $state['cloudflare_ready'] ) ) {
			$out[] = array(
				'id'       => 'cloudflare_unverified',
				'priority' => 35,
				'title'    => __( 'Finish connecting Cloudflare', 'xspeed' ),
				'detail'   => __( 'The Cloudflare module is on but has no verified credentials or zone, so edge purges cannot work.', 'xspeed' ),
				'action'   => array_merge(
					array( 'type' => 'link' ),
					Deep_Link::action( __( 'Open Cloudflare settings', 'xspeed' ), 'cloudflare', 'api_token' )
				),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);
		return $out;
	}

	/**
	 * Gather the live state the rules read. Cached probe verdicts only —
	 * this runs on dashboard paints and must never block on HTTP.
	 *
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		$opts       = Settings::get();
		$cache_opts = Settings_Manager::get( 'cache' );
		$pre_opts   = Settings_Manager::get( 'preloader' );
		$cf_opts    = Settings_Manager::get( 'cloudflare' );
		$oc_opts    = Settings_Manager::get( 'object-cache' );
		$totals     = Hit_Counter::totals_24h();
		$probe      = Cache::probe_static_rewrite( false );
		$cookie     = Cookie_Inspector::probe( false );

		$oc_detect     = Object_Cache::detect();
		$oc_persistent = ! empty( $oc_detect['persistent'] ) || ( ! empty( $oc_detect['wp_cache_active'] ) && empty( $oc_detect['degraded'] ) );

		return array(
			'cache_enabled'       => ! empty( $opts['cache_enabled'] ),
			'cache_expiry'        => (int) ( $cache_opts['cache_expiry'] ?? 0 ),
			'preloader_enabled'   => ! empty( $pre_opts['enabled'] ),
			'preload_schedule'    => (string) ( $pre_opts['schedule'] ?? 'manual' ),
			'server'              => Server::type(),
			// null = probe pending/unknown (rule stays silent); false = probed inactive.
			'rewrite_active'      => ! empty( $probe['pending'] ) ? null : (bool) ( $probe['active'] ?? false ),
			'poison_cookies'      => ! empty( $cookie['checked'] ) ? $cookie['cookies'] : array(),
			'hits_24h'            => (int) $totals['hits'],
			'misses_24h'          => (int) $totals['misses'],
			'hit_ratio'           => (float) $totals['ratio'],
			'objcache_enabled'    => ! empty( $oc_opts['enabled'] ),
			'objcache_persistent' => $oc_persistent,
			'cloudflare_enabled'  => ! empty( $cf_opts['enabled'] ),
			'cloudflare_ready'    => ! empty( $cf_opts['zone_id'] ) && ( ! empty( $cf_opts['api_token'] ) || ( ! empty( $cf_opts['api_key'] ) && ! empty( $cf_opts['email'] ) ) ),
		);
	}

	/** Ranked recommendations for the live site. */
	public static function all(): array {
		return self::evaluate( self::state() );
	}

	/**
	 * The live list with add-on entries folded in, for the Overview card.
	 *
	 * Kept apart from all() on purpose. all() feeds the cache page's next best
	 * action, `wp xspeed recommend` and apply(), which answer "what should I
	 * fix on this site"; an add-on's entry can be an offer, and an offer must
	 * not take the slot of the site's real next fix or read as one in the CLI.
	 */
	public static function all_with_contributed(): array {
		$state = self::state();
		return self::with_contributed( self::evaluate( $state ), $state );
	}

	/**
	 * Add the entries other plugins contribute, then re-rank.
	 *
	 * Contributors may only add. The filter starts from an empty array and
	 * gets the native list as read-only context, so nothing it returns can
	 * remove or rewrite a native entry, and an id a native rule already uses
	 * is dropped rather than replacing it. Only `link` actions are accepted:
	 * an `apply` action would POST to an endpoint that cannot resolve an id it
	 * does not own (that is what `xspeed_apply_recommendation` is for).
	 *
	 * @param array<int,array<string,mixed>> $native Ranked native entries.
	 * @param array<string,mixed>            $state  What the rules read, as context.
	 * @return array<int,array<string,mixed>> Native and contributed, ranked.
	 */
	public static function with_contributed( array $native, array $state ): array {
		/**
		 * Filter: xspeed_recommendations
		 *
		 * Extra recommendations for the Overview card. Starts empty; append
		 * entries and return the array.
		 *
		 * Each entry: id (string, required, unique), title (string, required),
		 * detail (string, required, one or two sentences, no markup), priority
		 * (int 0-100, lower ranks first, default 50), tag (string, optional, a
		 * short label such as "Add-on" shown beside the title) and action
		 * (required: `type` 'link', `label`, `module` slug, optional `subtab`
		 * and `focus`). Anything else is dropped.
		 *
		 * @param array $contributed Contributed entries (empty on entry).
		 * @param array $native      The native entries, as context.
		 * @param array $state       What the native rules read, as context.
		 */
		$depth = isset( $GLOBALS['wp_current_filter'] ) && is_array( $GLOBALS['wp_current_filter'] )
			? count( $GLOBALS['wp_current_filter'] )
			: 0;
		try {
			$raw = apply_filters( self::FILTER, array(), $native, $state );
		} catch ( \Throwable $e ) {
			// A contributor that throws costs the round its contributions,
			// not the card. apply_filters() leaves our name on the
			// current-filter stack when a callback throws, plus any filter
			// the contributor ran itself, so cut back to where it was.
			if ( isset( $GLOBALS['wp_current_filter'] ) && is_array( $GLOBALS['wp_current_filter'] ) ) {
				array_splice( $GLOBALS['wp_current_filter'], $depth );
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[xspeed] xspeed_recommendations contributor threw: ' . $e->getMessage() );
			}
			return $native;
		}
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return $native;
		}

		$taken = array();
		foreach ( $native as $rec ) {
			$taken[ (string) ( $rec['id'] ?? '' ) ] = true;
		}

		$out   = $native;
		$added = 0;
		foreach ( $raw as $row ) {
			if ( $added >= self::MAX_CONTRIBUTED ) {
				break;
			}
			$entry = self::normalise_contributed( $row );
			if ( null === $entry || isset( $taken[ $entry['id'] ] ) ) {
				continue;
			}
			$taken[ $entry['id'] ] = true;
			$out[]                 = $entry;
			++$added;
		}

		// Ties keep their position, so a contribution never jumps a native
		// entry it ties with. PHP 7.4's sort is not stable, hence the index.
		$order = array_flip( array_keys( $out ) );
		uksort(
			$out,
			static function ( $a, $b ) use ( $out, $order ) {
				return array( $out[ $a ]['priority'], $order[ $a ] ) <=> array( $out[ $b ]['priority'], $order[ $b ] );
			}
		);
		return array_values( $out );
	}

	/**
	 * One contributed entry in the native shape, or null when it is unusable.
	 *
	 * @param mixed $row What the contributor returned.
	 * @return array<string,mixed>|null
	 */
	private static function normalise_contributed( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$text = static function ( $value, int $max ): string {
			if ( ! is_string( $value ) ) {
				return '';
			}
			$value = trim( wp_strip_all_tags( $value ) );
			return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
		};

		$id     = is_string( $row['id'] ?? null ) ? sanitize_key( $row['id'] ) : '';
		$title  = $text( $row['title'] ?? null, self::MAX_TITLE_LEN );
		$detail = $text( $row['detail'] ?? null, self::MAX_DETAIL_LEN );
		$action = is_array( $row['action'] ?? null ) ? $row['action'] : array();
		$label  = $text( $action['label'] ?? null, self::MAX_LABEL_LEN );
		$module = is_string( $action['module'] ?? null ) ? sanitize_key( $action['module'] ) : '';
		if ( '' === $id || '' === $title || '' === $detail || 'link' !== ( $action['type'] ?? '' ) || '' === $label || '' === $module ) {
			return null;
		}

		$link = array(
			'type'   => 'link',
			'label'  => $label,
			'module' => $module,
		);
		foreach ( array( 'subtab', 'focus' ) as $key ) {
			if ( is_string( $action[ $key ] ?? null ) && '' !== $action[ $key ] ) {
				$link[ $key ] = $text( $action[ $key ], 80 );
			}
		}

		$entry = array(
			'id'       => $id,
			// (int) 'high' is 0, which would rank the entry above every native fix.
			'priority' => is_numeric( $row['priority'] ?? null ) ? max( 0, min( 100, (int) $row['priority'] ) ) : 50,
			'title'    => $title,
			'detail'   => $detail,
			'action'   => $link,
		);
		$tag = $text( $row['tag'] ?? null, self::MAX_TAG_LEN );
		if ( '' !== $tag ) {
			$entry['tag'] = $tag;
		}
		return $entry;
	}

	/**
	 * One-click apply: re-evaluate, find the recommendation, and run its
	 * settings write through Settings_Manager (schema-validated + logged
	 * as a change annotation like every other write).
	 *
	 * @param string $id Recommendation id.
	 * @return array|\WP_Error The refreshed recommendation list on success.
	 */
	public static function apply( string $id ) {
		foreach ( self::all() as $rec ) {
			if ( $rec['id'] !== $id ) {
				continue;
			}
			$action = $rec['action'];
			if ( 'apply' !== ( $action['type'] ?? '' ) ) {
				return new \WP_Error(
					'xspeed_rec_not_applicable',
					__( 'This recommendation links to a settings screen; it has no one-click fix.', 'xspeed' ),
					array( 'status' => 400 )
				);
			}
			Settings_Manager::update( (string) $action['module'], (array) $action['values'] );
			return array(
				'applied'         => $id,
				'recommendations' => self::all(),
			);
		}
		/**
		 * Last chance to resolve a recommendation id this engine doesn't own.
		 *
		 * Free and Pro each ship a recommendation engine with its OWN id
		 * namespace — Free uses underscores (`nginx_snippet_missing`), Pro
		 * uses hyphens (`cache-disabled`) — but only Free's ids reached this
		 * method, so EVERY Pro Apply button returned 404 and the failure was
		 * swallowed by the UI. This seam lets Pro claim its own ids rather
		 * than duplicating the endpoint. (#198)
		 *
		 * Return an array (the same shape this method returns on success) or
		 * a WP_Error to claim the id; return null to decline it.
		 *
		 * @param array|\WP_Error|null $handled Result from a previous filter, or null.
		 * @param string               $id      The recommendation id being applied.
		 */
		$handled = apply_filters( 'xspeed_apply_recommendation', null, $id );
		if ( is_array( $handled ) || is_wp_error( $handled ) ) {
			return $handled;
		}

		return new \WP_Error(
			'xspeed_rec_unknown',
			__( 'Unknown or no-longer-applicable recommendation.', 'xspeed' ),
			array( 'status' => 404 )
		);
	}
}
