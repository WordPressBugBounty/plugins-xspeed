<?php
/**
 * Cache_Plugin_Catalog — the one description of every caching /
 * optimization plugin xSpeed knows about.
 *
 * Before this existed the same plugins were described twice: once in
 * Conflict_Registry::matrix() (per-feature refuse/warn strategy) and once in
 * Server::conflicts() (a flat label list for the health card). The two lists
 * drifted — Swift Performance was in neither, and Server::conflicts() counted
 * Autoptimize as a caching conflict even though it only minifies. Both are now
 * projections of this catalog, so a plugin is added in exactly one place.
 *
 * Each entry carries:
 *   - label        : human-readable name.
 *   - capabilities : what the plugin actually DOES (page-cache, minify,
 *                    object-cache, …). Kept separate from `strategy` because
 *                    "is this a page cache?" and "may xSpeed enable minify
 *                    alongside it?" are different questions.
 *   - strategy     : per-feature refuse | warn | allow, consumed by
 *                    Conflict_Registry.
 *   - signals      : read-only evidence Page_Cache_Detector looks for beyond
 *                    the active-plugin list — constants, classes, options,
 *                    files under wp-content, and the token its
 *                    advanced-cache.php drop-in identifies itself by.
 *
 * Signals exist because "plugin file is in active_plugins" is not the same
 * question as "is this plugin's page cache live right now". A deactivated
 * plugin can leave a working drop-in behind; an active one can have page
 * caching switched off. Nothing here loads or executes foreign plugin code.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Cache_Plugin_Catalog {

	public const CAP_PAGE_CACHE   = 'page-cache';
	public const CAP_OBJECT_CACHE = 'object-cache';
	public const CAP_MINIFY       = 'minify';
	public const CAP_LAZY_LOAD    = 'lazy-load';
	public const CAP_IMAGE_OPT    = 'image-opt';
	public const CAP_CDN          = 'cdn';
	public const CAP_PRELOAD      = 'preload';

	/**
	 * @var array|null Memoized (filtered) catalog for this request.
	 */
	private static $cache = null;

	/**
	 * The catalog. Keys are plugin main-file paths exactly as they appear in
	 * the `active_plugins` option — folder-only checks would false-positive on
	 * a plugin that is merely present on disk.
	 *
	 * @return array<string,array>
	 */
	private static function entries(): array {
		return array(
			/*
			 * xSpeed itself. The catalog answers "does this site already have a
			 * page cache, and whose is it?" — and xSpeed is one. Leaving it out
			 * made a site running our own drop-in report an unattributable one,
			 * and left the plugin unnamed in a refusal that counted it.
			 *
			 * Drop-in token only, deliberately: no constants, options or paths.
			 * Those are residual-evidence signals, and xSpeed's are present on
			 * any site running it — including this suite — which would report a
			 * site as holding leftovers from the very plugin asking. An ACTIVE
			 * xSpeed is already caught by is_plugin_active() on the key.
			 */
			'xspeed/xspeed.php'                            => array(
				'label'        => 'xSpeed Cache',
				'capabilities' => array( self::CAP_PAGE_CACHE ),
				'dropin'       => array( 'XSPEED_DROPIN' ),
			),
			'wp-rocket/wp-rocket.php'                      => array(
				'label'        => 'WP Rocket',
				'capabilities' => array( self::CAP_PAGE_CACHE, self::CAP_MINIFY, self::CAP_PRELOAD, self::CAP_CDN, self::CAP_LAZY_LOAD ),
				'strategy'     => array(
					'cache.page'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.html'     => Conflict_Registry::STRATEGY_REFUSE,
					'minify.css'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.js'       => Conflict_Registry::STRATEGY_REFUSE,
					'preload.crawler' => Conflict_Registry::STRATEGY_REFUSE,
					'cdn.rewrite'     => Conflict_Registry::STRATEGY_REFUSE,
					'images.lazyload' => Conflict_Registry::STRATEGY_REFUSE,
				),
				'signals'      => array(
					'constants'     => array( 'WP_ROCKET_VERSION' ),
					'paths'         => array( 'cache/wp-rocket' ),
					'dropin_tokens'            => array( 'WP Rocket', 'WP_ROCKET' ),
					'dropin_identifier_tokens' => array( 'WP_ROCKET_ADVANCED_CACHE' ),
				),
			),
			'litespeed-cache/litespeed-cache.php'          => array(
				'label'        => 'LiteSpeed Cache',
				'capabilities' => array( self::CAP_PAGE_CACHE, self::CAP_OBJECT_CACHE, self::CAP_MINIFY, self::CAP_PRELOAD, self::CAP_CDN, self::CAP_LAZY_LOAD ),
				'strategy'     => array(
					'cache.page'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.html'     => Conflict_Registry::STRATEGY_REFUSE,
					'minify.css'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.js'       => Conflict_Registry::STRATEGY_REFUSE,
					'preload.crawler' => Conflict_Registry::STRATEGY_REFUSE,
					'cdn.rewrite'     => Conflict_Registry::STRATEGY_WARN,
					'images.lazyload' => Conflict_Registry::STRATEGY_REFUSE,
				),
				'signals'      => array(
					'constants'     => array( 'LSCWP_V', 'LSCWP_DIR' ),
					'options'       => array( 'litespeed.conf.cache' ),
					'paths'         => array( 'litespeed', 'cache/litespeed' ),
					'dropin_tokens' => array( 'LiteSpeed_Cache', 'LSCWP' ),
				),
			),
			'w3-total-cache/w3-total-cache.php'            => array(
				'label'        => 'W3 Total Cache',
				'capabilities' => array( self::CAP_PAGE_CACHE, self::CAP_OBJECT_CACHE, self::CAP_MINIFY, self::CAP_PRELOAD, self::CAP_CDN ),
				'strategy'     => array(
					'cache.page'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.html'     => Conflict_Registry::STRATEGY_REFUSE,
					'minify.css'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.js'       => Conflict_Registry::STRATEGY_REFUSE,
					'preload.crawler' => Conflict_Registry::STRATEGY_REFUSE,
					'cdn.rewrite'     => Conflict_Registry::STRATEGY_WARN,
				),
				'signals'      => array(
					'constants'     => array( 'W3TC_DIR', 'W3TC_VERSION' ),
					'paths'         => array( 'w3tc-config/master.php', 'cache/page_enhanced' ),
					'dropin_tokens' => array( 'W3TC', 'w3-total-cache' ),
				),
			),
			'wp-super-cache/wp-cache.php'                  => array(
				'label'        => 'WP Super Cache',
				'capabilities' => array( self::CAP_PAGE_CACHE, self::CAP_PRELOAD ),
				'strategy'     => array(
					'cache.page'      => Conflict_Registry::STRATEGY_REFUSE,
					'preload.crawler' => Conflict_Registry::STRATEGY_REFUSE,
				),
				'signals'      => array(
					'constants'     => array( 'WPCACHEHOME' ),
					'paths'         => array( 'wp-cache-config.php', 'cache/supercache' ),
					'dropin_tokens' => array( 'WP SUPER CACHE', 'wp-cache-phase1' ),
				),
			),
			'wp-fastest-cache/wpFastestCache.php'          => array(
				'label'        => 'WP Fastest Cache',
				'capabilities' => array( self::CAP_PAGE_CACHE, self::CAP_MINIFY, self::CAP_PRELOAD, self::CAP_LAZY_LOAD ),
				'strategy'     => array(
					'cache.page'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.html'     => Conflict_Registry::STRATEGY_REFUSE,
					'minify.css'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.js'       => Conflict_Registry::STRATEGY_REFUSE,
					'preload.crawler' => Conflict_Registry::STRATEGY_REFUSE,
					'images.lazyload' => Conflict_Registry::STRATEGY_REFUSE,
				),
				'signals'      => array(
					'classes'       => array( 'WpFastestCache' ),
					'options'       => array( 'WpFastestCache' ),
					'paths'         => array( 'cache/all', 'cache/wpfc-minified' ),
					'dropin_tokens' => array( 'WpFastestCache', 'wpFastestCache' ),
				),
			),
			/*
			 * Swift Performance ships as two distinct plugin files (Lite and
			 * the commercial build) that share one options row. It was missing
			 * from both of the old lists — an active Swift page cache looked
			 * like a clear field.
			 */
			'swift-performance-lite/performance.php'       => array(
				'label'        => 'Swift Performance Lite',
				// Both builds ship the same drop-in banner, so a drop-in can
				// prove the product and never the edition. `family` is what a
				// drop-in attribution is allowed to say out loud.
				'family'       => 'Swift Performance',
				'capabilities' => array( self::CAP_PAGE_CACHE, self::CAP_MINIFY, self::CAP_PRELOAD, self::CAP_CDN, self::CAP_LAZY_LOAD ),
				'strategy'     => array(
					'cache.page'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.html'     => Conflict_Registry::STRATEGY_REFUSE,
					'minify.css'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.js'       => Conflict_Registry::STRATEGY_REFUSE,
					'preload.crawler' => Conflict_Registry::STRATEGY_REFUSE,
					'cdn.rewrite'     => Conflict_Registry::STRATEGY_WARN,
					'images.lazyload' => Conflict_Registry::STRATEGY_REFUSE,
				),
				'signals'      => array(
					'constants'     => array( 'SWIFT_PERFORMANCE_VER', 'SWIFT_PERFORMANCE_DIR' ),
					'options'       => array( 'swift_performance_options' ),
					'paths'         => array( 'cache/swift-performance' ),
					'dropin_tokens' => array( 'Swift Performance', 'swift_performance' ),
				),
			),
			'swift-performance/performance.php'            => array(
				'label'        => 'Swift Performance',
				// Both builds ship the same drop-in banner, so a drop-in can
				// prove the product and never the edition. `family` is what a
				// drop-in attribution is allowed to say out loud.
				'family'       => 'Swift Performance',
				'capabilities' => array( self::CAP_PAGE_CACHE, self::CAP_MINIFY, self::CAP_PRELOAD, self::CAP_CDN, self::CAP_LAZY_LOAD ),
				'strategy'     => array(
					'cache.page'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.html'     => Conflict_Registry::STRATEGY_REFUSE,
					'minify.css'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.js'       => Conflict_Registry::STRATEGY_REFUSE,
					'preload.crawler' => Conflict_Registry::STRATEGY_REFUSE,
					'cdn.rewrite'     => Conflict_Registry::STRATEGY_WARN,
					'images.lazyload' => Conflict_Registry::STRATEGY_REFUSE,
				),
				'signals'      => array(
					'constants'     => array( 'SWIFT_PERFORMANCE_VER', 'SWIFT_PERFORMANCE_DIR' ),
					'options'       => array( 'swift_performance_options' ),
					'paths'         => array( 'cache/swift-performance' ),
					'dropin_tokens' => array( 'Swift Performance', 'swift_performance' ),
				),
			),
			'cache-enabler/cache-enabler.php'              => array(
				'label'        => 'Cache Enabler',
				'capabilities' => array( self::CAP_PAGE_CACHE, self::CAP_PRELOAD ),
				'strategy'     => array(
					'cache.page'      => Conflict_Registry::STRATEGY_REFUSE,
					'preload.crawler' => Conflict_Registry::STRATEGY_REFUSE,
				),
				'signals'      => array(
					'constants'     => array( 'CACHE_ENABLER_DIR', 'CACHE_ENABLER_VERSION' ),
					'classes'       => array( 'Cache_Enabler_Engine' ),
					'paths'         => array( 'cache/cache-enabler' ),
					'dropin_tokens'            => array( 'Cache_Enabler', 'cache-enabler' ),
					'dropin_identifier_tokens' => array( 'Cache_Enabler_Engine', 'CACHE_ENABLER_DIR' ),
				),
			),
			'comet-cache/comet-cache.php'                  => array(
				'label'        => 'Comet Cache',
				'capabilities' => array( self::CAP_PAGE_CACHE ),
				'strategy'     => array(
					'cache.page' => Conflict_Registry::STRATEGY_REFUSE,
				),
				'signals'      => array(
					'paths'         => array( 'cache/comet-cache' ),
					'dropin_tokens' => array( 'comet-cache', 'ZenCache', 'Quick Cache' ),
				),
			),
			'hummingbird-performance/wp-hummingbird.php'   => array(
				'label'        => 'Hummingbird',
				'capabilities' => array( self::CAP_PAGE_CACHE, self::CAP_MINIFY, self::CAP_LAZY_LOAD, self::CAP_PRELOAD, self::CAP_CDN ),
				'strategy'     => array(
					'cache.page'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.html'     => Conflict_Registry::STRATEGY_REFUSE,
					'minify.css'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.js'       => Conflict_Registry::STRATEGY_REFUSE,
					'images.lazyload' => Conflict_Registry::STRATEGY_REFUSE,
					'preload.crawler' => Conflict_Registry::STRATEGY_WARN,
					'cdn.rewrite'     => Conflict_Registry::STRATEGY_WARN,
				),
				'signals'      => array(
					'constants'     => array( 'WPHB_ADVANCED_CACHE', 'WPHB_VERSION' ),
					'paths'         => array( 'wphb-cache' ),
					'dropin_tokens' => array( 'Hummingbird', 'WPHB' ),
				),
			),
			'sg-cachepress/sg-cachepress.php'              => array(
				'label'        => 'SG Optimizer',
				'capabilities' => array( self::CAP_PAGE_CACHE, self::CAP_MINIFY, self::CAP_PRELOAD, self::CAP_LAZY_LOAD ),
				'strategy'     => array(
					'cache.page'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.html'     => Conflict_Registry::STRATEGY_REFUSE,
					'minify.css'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.js'       => Conflict_Registry::STRATEGY_REFUSE,
					'preload.crawler' => Conflict_Registry::STRATEGY_REFUSE,
					'images.lazyload' => Conflict_Registry::STRATEGY_WARN,
				),
				'signals'      => array(
					'constants'     => array( 'SiteGround_Optimizer\\VERSION' ),
					'dropin_tokens' => array( 'SG CachePress', 'SiteGround' ),
				),
			),
			'breeze/breeze.php'                            => array(
				'label'        => 'Breeze',
				'capabilities' => array( self::CAP_PAGE_CACHE, self::CAP_MINIFY, self::CAP_PRELOAD, self::CAP_CDN ),
				'strategy'     => array(
					'cache.page'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.html'     => Conflict_Registry::STRATEGY_REFUSE,
					'minify.css'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.js'       => Conflict_Registry::STRATEGY_REFUSE,
					'preload.crawler' => Conflict_Registry::STRATEGY_REFUSE,
					'cdn.rewrite'     => Conflict_Registry::STRATEGY_WARN,
				),
				'signals'      => array(
					'constants'     => array( 'BREEZE_VERSION', 'BREEZE_CACHE_DIR' ),
					'paths'         => array( 'cache/breeze-minification' ),
					'dropin_tokens' => array( 'Breeze', 'breeze-cache' ),
				),
			),
			/*
			 * Minification only. It used to appear in Server::conflicts(),
			 * whose health row reads "deactivate before enabling xSpeed cache
			 * to avoid double-caching" — advice that was simply wrong for a
			 * plugin that never writes a page cache.
			 */
			'autoptimize/autoptimize.php'                  => array(
				'label'        => 'Autoptimize',
				'capabilities' => array( self::CAP_MINIFY ),
				'strategy'     => array(
					'minify.html' => Conflict_Registry::STRATEGY_REFUSE,
					'minify.css'  => Conflict_Registry::STRATEGY_REFUSE,
					'minify.js'   => Conflict_Registry::STRATEGY_REFUSE,
				),
				'signals'      => array(
					'constants' => array( 'AUTOPTIMIZE_PLUGIN_VERSION' ),
					'paths'     => array( 'cache/autoptimize' ),
				),
			),
			'flying-press/flying-press.php'                => array(
				'label'        => 'FlyingPress',
				'capabilities' => array( self::CAP_PAGE_CACHE, self::CAP_MINIFY, self::CAP_PRELOAD, self::CAP_CDN, self::CAP_LAZY_LOAD ),
				'strategy'     => array(
					'cache.page'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.html'     => Conflict_Registry::STRATEGY_REFUSE,
					'minify.css'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.js'       => Conflict_Registry::STRATEGY_REFUSE,
					'preload.crawler' => Conflict_Registry::STRATEGY_REFUSE,
					'cdn.rewrite'     => Conflict_Registry::STRATEGY_REFUSE,
					'images.lazyload' => Conflict_Registry::STRATEGY_REFUSE,
				),
				'signals'      => array(
					'constants'     => array( 'FLYING_PRESS_VERSION', 'FLYING_PRESS_CACHE_DIR' ),
					'paths'         => array( 'cache/flying-press' ),
					'dropin_tokens' => array( 'FlyingPress', 'flying-press' ),
				),
			),
			'nitropack/main.php'                           => array(
				'label'        => 'NitroPack',
				'capabilities' => array( self::CAP_PAGE_CACHE, self::CAP_MINIFY, self::CAP_PRELOAD, self::CAP_CDN, self::CAP_LAZY_LOAD ),
				'strategy'     => array(
					'cache.page'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.html'     => Conflict_Registry::STRATEGY_REFUSE,
					'minify.css'      => Conflict_Registry::STRATEGY_REFUSE,
					'minify.js'       => Conflict_Registry::STRATEGY_REFUSE,
					'preload.crawler' => Conflict_Registry::STRATEGY_REFUSE,
					'cdn.rewrite'     => Conflict_Registry::STRATEGY_REFUSE,
					'images.lazyload' => Conflict_Registry::STRATEGY_REFUSE,
				),
				'signals'      => array(
					'constants'     => array( 'NITROPACK_VERSION' ),
					'dropin_tokens' => array( 'NitroPack', 'nitropack' ),
				),
			),
			'wp-optimize/wp-optimize.php'                  => array(
				'label'        => 'WP-Optimize',
				'capabilities' => array( self::CAP_PAGE_CACHE, self::CAP_MINIFY ),
				'strategy'     => array(
					'cache.page'  => Conflict_Registry::STRATEGY_WARN,
					'minify.html' => Conflict_Registry::STRATEGY_WARN,
					'minify.css'  => Conflict_Registry::STRATEGY_WARN,
					'minify.js'   => Conflict_Registry::STRATEGY_WARN,
				),
				'signals'      => array(
					'constants'     => array( 'WPO_VERSION', 'WPO_CACHE_DIR' ),
					'paths'         => array( 'cache/wpo-cache' ),
					'dropin_tokens' => array( 'WP-Optimize', 'WPO_CACHE' ),
				),
			),
			/*
			 * Boost gained a real page cache (its own advanced-cache.php) after
			 * the original matrix was written, which is why the page-cache
			 * capability and the cache.page warning are here now.
			 */
			'jetpack-boost/jetpack-boost.php'              => array(
				'label'        => 'Jetpack Boost',
				'capabilities' => array( self::CAP_PAGE_CACHE, self::CAP_MINIFY, self::CAP_LAZY_LOAD ),
				'strategy'     => array(
					'cache.page'      => Conflict_Registry::STRATEGY_WARN,
					'minify.css'      => Conflict_Registry::STRATEGY_WARN,
					'minify.js'       => Conflict_Registry::STRATEGY_WARN,
					'images.lazyload' => Conflict_Registry::STRATEGY_WARN,
				),
				'signals'      => array(
					'paths'         => array( 'boost-cache' ),
					'dropin_tokens' => array( 'Jetpack Boost', 'jetpack-boost' ),
				),
			),
			'wp-asset-clean-up/wpacu.php'                  => array(
				'label'        => 'Asset CleanUp',
				'capabilities' => array( self::CAP_MINIFY ),
				'strategy'     => array(
					'minify.css' => Conflict_Registry::STRATEGY_WARN,
					'minify.js'  => Conflict_Registry::STRATEGY_WARN,
				),
			),
			'ewww-image-optimizer/ewww-image-optimizer.php' => array(
				'label'        => 'EWWW Image Optimizer',
				'capabilities' => array( self::CAP_IMAGE_OPT ),
				'strategy'     => array(
					'images.optimize' => Conflict_Registry::STRATEGY_REFUSE,
				),
			),
			'shortpixel-image-optimiser/wp-shortpixel.php' => array(
				'label'        => 'ShortPixel',
				'capabilities' => array( self::CAP_IMAGE_OPT ),
				'strategy'     => array(
					'images.optimize' => Conflict_Registry::STRATEGY_REFUSE,
				),
			),
			'wp-smushit/wp-smush.php'                      => array(
				'label'        => 'Smush',
				'capabilities' => array( self::CAP_IMAGE_OPT, self::CAP_LAZY_LOAD ),
				'strategy'     => array(
					'images.optimize' => Conflict_Registry::STRATEGY_REFUSE,
					'images.lazyload' => Conflict_Registry::STRATEGY_WARN,
				),
			),
			/*
			 * Object cache only. It owns object-cache.php, never
			 * advanced-cache.php, so it conflicts with nothing we do and must
			 * never block a page-cache install. Catalogued so the detector can
			 * NAME the object-cache drop-in instead of reporting an unknown
			 * file sitting in wp-content.
			 */
			'redis-cache/redis-cache.php'                  => array(
				'label'        => 'Redis Object Cache',
				'capabilities' => array( self::CAP_OBJECT_CACHE ),
				'strategy'     => array(),
				'signals'      => array(
					'constants'            => array( 'WP_REDIS_VERSION' ),
					'object_dropin_tokens' => array( 'Redis Object Cache', 'WP_Redis' ),
				),
			),
			'memcached/object-cache.php'                   => array(
				'label'        => 'Memcached Object Cache',
				'capabilities' => array( self::CAP_OBJECT_CACHE ),
				'strategy'     => array(),
				'signals'      => array(
					'object_dropin_tokens' => array( 'Memcached', 'memcache' ),
				),
			),
		);
	}

	/**
	 * The catalog, filterable so a Pro module (or a site) can describe a
	 * plugin we don't ship knowledge of. Memoized per request.
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$catalog = apply_filters( 'xspeed_cache_plugin_catalog', self::entries() );
		if ( ! is_array( $catalog ) ) {
			$catalog = self::entries();
		}

		// Normalize so consumers never have to null-check an optional key.
		foreach ( $catalog as $file => $entry ) {
			$catalog[ $file ] = array(
				'label'        => (string) ( $entry['label'] ?? $file ),
				// The label to use when only a drop-in identifies the owner
				// and the drop-in cannot tell two builds apart. Defaults to
				// the precise label, so an entry without twins needs nothing.
				'family'       => (string) ( $entry['family'] ?? $entry['label'] ?? $file ),
				'capabilities' => array_values( (array) ( $entry['capabilities'] ?? array() ) ),
				'strategy'     => (array) ( $entry['strategy'] ?? array() ),
				'signals'      => (array) ( $entry['signals'] ?? array() ),
			);
		}

		self::$cache = $catalog;
		return $catalog;
	}

	/**
	 * One entry, or null when the plugin isn't catalogued.
	 */
	public static function get( string $plugin_file ): ?array {
		$all = self::all();
		return $all[ $plugin_file ] ?? null;
	}

	/**
	 * Every catalogued plugin declaring a capability.
	 *
	 * @return array<string,array> Keyed by plugin file, same shape as all().
	 */
	public static function with_capability( string $capability ): array {
		return array_filter(
			self::all(),
			static function ( array $entry ) use ( $capability ) {
				return in_array( $capability, $entry['capabilities'], true );
			}
		);
	}

	/**
	 * Does this plugin write a page cache? The question behind every
	 * "can xSpeed take the drop-in" decision — as opposed to "does it
	 * overlap with some xSpeed feature", which is what `strategy` answers.
	 */
	public static function is_page_cache( string $plugin_file ): bool {
		$entry = self::get( $plugin_file );
		return null !== $entry && in_array( self::CAP_PAGE_CACHE, $entry['capabilities'], true );
	}

	/**
	 * Match an advanced-cache.php body against the catalog's drop-in tokens.
	 * Returns the plugin file of the owner, or null when nothing matches —
	 * null means "unknown drop-in", NOT "safe to overwrite".
	 *
	 * Catalog tokens are candidates, not proof by themselves. A token only
	 * identifies an owner when it appears as an anchored comment banner or an
	 * exact PHP identifier. Text embedded in an arbitrary string/comment must
	 * not let one plugin claim another plugin's shared drop-in.
	 */
	public static function identify_dropin( string $contents ): ?string {
		return self::identify_by_tokens( $contents, 'dropin_tokens' );
	}

	/**
	 * Same, for object-cache.php. Separate key because a plugin can own one
	 * drop-in and not the other (Redis owns object-cache.php only).
	 */
	public static function identify_object_dropin( string $contents ): ?string {
		$hit = self::identify_by_tokens( $contents, 'object_dropin_tokens' );
		if ( null !== $hit ) {
			return $hit;
		}
		// Several page-cache plugins ship both drop-ins and identify them with
		// the same token, so fall back to the page-cache token list.
		return self::identify_by_tokens( $contents, 'dropin_tokens' );
	}

	private static function identify_by_tokens( string $contents, string $key ): ?string {
		if ( '' === $contents ) {
			return null;
		}

		$evidence = self::signature_evidence( $contents );
		$owners   = array();
		foreach ( self::all() as $file => $entry ) {
			foreach ( (array) ( $entry['signals'][ $key ] ?? array() ) as $token ) {
				if ( self::has_anchored_signature( (string) $token, $evidence ) ) {
					$owners[ $file ] = true;
					break;
				}
			}
			if ( isset( $owners[ $file ] ) ) {
				continue;
			}
			foreach ( (array) ( $entry['signals'][ self::identifier_key( $key ) ] ?? array() ) as $token ) {
				if ( self::declares_identifier( (string) $token, $evidence ) ) {
					$owners[ $file ] = true;
					break;
				}
			}
		}

		if ( 1 === count( $owners ) ) {
			return (string) array_key_first( $owners );
		}
		if ( count( $owners ) > 1 ) {
			return self::resolve_twins( array_keys( $owners ), $key );
		}
		return null;
	}

	/**
	 * More than one catalog entry claimed the drop-in.
	 *
	 * Two genuinely different plugins both anchoring a banner is not reliable
	 * attribution: the detector treats that drop-in as unknown and refuses to
	 * replace it. But two BUILDS of one product — Swift Performance Lite and
	 * the commercial build — declare the same tokens by design, and a drop-in
	 * they wrote is theirs, not unknown (PR #295 review). When every claimant
	 * declares the identical token list, the one on disk wins; failing that,
	 * the first in catalog order.
	 *
	 * @param string[] $owners Plugin files that matched.
	 * @return string|null
	 */
	private static function resolve_twins( array $owners, string $key ): ?string {
		$catalog = self::all();
		$sets    = array();
		foreach ( $owners as $file ) {
			$tokens = array_merge(
				(array) ( $catalog[ $file ]['signals'][ $key ] ?? array() ),
				(array) ( $catalog[ $file ]['signals'][ self::identifier_key( $key ) ] ?? array() )
			);
			sort( $tokens );
			$sets[] = implode( "\0", $tokens );
		}
		if ( 1 !== count( array_unique( $sets ) ) ) {
			return null;
		}

		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/plugins' : '' );
		if ( '' !== $plugin_dir ) {
			$installed = array_values(
				array_filter(
					$owners,
					static function ( $file ) use ( $plugin_dir ) {
						return is_file( $plugin_dir . '/' . $file );
					}
				)
			);
			if ( 1 === count( $installed ) ) {
				return (string) $installed[0];
			}
		}

		return (string) $owners[0];
	}

	/**
	 * Extract non-executing, structured signature locations from PHP source.
	 *
	 * @return array{comment_lines:string[],identifiers:string[]}
	 */
	private static function signature_evidence( string $contents ): array {
		$comments    = array();
		$identifiers = array();

		$tokens = token_get_all( $contents );
		foreach ( $tokens as $i => $token ) {
			// The constant a `define( 'NAME', … )` declares is structured
			// evidence too: WP Rocket's drop-in announces itself with
			// define( 'WP_ROCKET_ADVANCED_CACHE', true ) and nothing else on
			// some builds. The name is quoted, so the identifier scan below
			// never sees it.
			if ( self::is_global_define_call( $tokens, $i ) ) {
				$open = self::next_code_index( $tokens, $i + 1 );
				$name = null === $open || '(' !== $tokens[ $open ] ? null : self::next_code_index( $tokens, $open + 1 );
				if ( null !== $name && is_array( $tokens[ $name ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $name ][0] ) {
					$declared = trim( $tokens[ $name ][1], '"\'' );
					if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $declared ) ) {
						$identifiers[] = strtolower( $declared );
					}
				}
			}
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( T_STRING === $token[0] || ( defined( 'T_NAME_QUALIFIED' ) && T_NAME_QUALIFIED === $token[0] ) || ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $token[0] ) ) {
				foreach ( explode( '\\', trim( $token[1], '\\' ) ) as $identifier ) {
					if ( '' !== $identifier ) {
						$identifiers[] = strtolower( $identifier );
					}
				}
			}
		}

		// Comments from the HEADER only — the run of comments before the
		// first line of code. A banner further down is a mention, not a
		// declaration. Same rule as the portable detector's; the two must
		// agree, because they answer the same question about the same file.
		foreach ( $tokens as $token ) {
			$id = is_array( $token ) ? $token[0] : null;
			if ( T_OPEN_TAG === $id || T_WHITESPACE === $id ) {
				continue;
			}
			// A BOM before `<?php` arrives as inline HTML, not code.
			if ( T_INLINE_HTML === $id && '' === trim( $token[1], " \t\r\n\0\x0B\xEF\xBB\xBF" ) ) {
				continue;
			}
			if ( ! in_array( $id, array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				break;
			}
			foreach ( preg_split( '/\\R/', $token[1] ) ?: array() as $line ) {
				$line = preg_replace( '/^\\s*(?:\/\\*+|\\*|\/\/|#)\\s*/', '', $line );
				$line = preg_replace( '/\\s*\\*\\/\\s*$/', '', (string) $line );
				if ( '' !== trim( (string) $line ) ) {
					$comments[] = trim( (string) $line );
				}
			}
		}

		return array(
			'comment_lines' => $comments,
			'identifiers'   => array_values( array_unique( $identifiers ) ),
		);
	}

	/** `dropin_tokens` -> `dropin_identifier_tokens`. */
	private static function identifier_key( string $key ): string {
		return str_replace( '_tokens', '_identifier_tokens', $key );
	}

	/**
	 * Is the token at $i a call to the GLOBAL define()?
	 *
	 * The same rule as the portable detector's helper of this name, and it
	 * has to be: matching `T_STRING === 'define'` alone missed
	 * `\define( 'X', true )`, which PHP 8 tokenizes as one
	 * T_NAME_FULLY_QUALIFIED — so the two copies attributed the same
	 * drop-in differently, and only on PHP 8.
	 */
	private static function is_global_define_call( array $tokens, int $i ): bool {
		$token = $tokens[ $i ];
		if ( ! is_array( $token ) ) {
			return false;
		}
		if ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $token[0] ) {
			return 0 === strcasecmp( $token[1], '\\define' );
		}
		if ( T_STRING !== $token[0] || 0 !== strcasecmp( $token[1], 'define' ) ) {
			return false;
		}
		$previous = self::previous_code_index( $tokens, $i );
		if ( null === $previous || ! is_array( $tokens[ $previous ] ) ) {
			return true;
		}
		if ( in_array( $tokens[ $previous ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true )
			|| ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $tokens[ $previous ][0] ) ) {
			return false;
		}
		if ( T_NS_SEPARATOR === $tokens[ $previous ][0] ) {
			// `Foo\define` on PHP 7 (T_STRING before the separator), or
			// `namespace\define` (T_NAMESPACE before it).
			$before = self::previous_code_index( $tokens, $previous );
			return null === $before || ! is_array( $tokens[ $before ] ) || ! in_array( $tokens[ $before ][0], array( T_STRING, T_NAMESPACE ), true );
		}
		return true;
	}

	/** Index of the nearest code token before $before, skipping trivia. */
	private static function previous_code_index( array $tokens, int $before ): ?int {
		for ( $i = $before - 1; $i >= 0; $i-- ) {
			if ( ! is_array( $tokens[ $i ] ) || ! in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $i;
			}
		}
		return null;
	}

	/** Index of the nearest code token at or after $start, skipping trivia. */
	private static function next_code_index( array $tokens, int $start ): ?int {
		$count = count( $tokens );
		for ( $i = $start; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || ! in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * A banner in the file's HEADER naming the candidate, and nothing else.
	 *
	 * The banner must be the whole semantic line, optionally followed by a
	 * version — a sentence such as "compatible with WP Rocket" is not an
	 * ownership signature — or a `@package <candidate>` docblock tag, which
	 * is how W3 Total Cache's drop-in names itself and the only place it
	 * does.
	 */
	private static function has_anchored_signature( string $candidate, array $evidence ): bool {
		$candidate = trim( $candidate );
		if ( '' === $candidate ) {
			return false;
		}

		$token   = preg_quote( $candidate, '/' ) . '(?:\\s+(?:v(?:ersion)?\\s*)?\\d[A-Za-z0-9._-]*)?';
		$pattern = '/^(?:@package\\s+)?' . $token . '\\s*$/i';
		foreach ( $evidence['comment_lines'] as $line ) {
			if ( preg_match( $pattern, $line ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A constant or class the file's CODE uses, anywhere in it.
	 *
	 * Much weaker than a header banner: an identifier of the right name in
	 * any arrangement matches, a `defined( 'X' )` guard included. Opt-in per
	 * entry, via `<key>_identifier_tokens`, for the drop-ins that name
	 * themselves no other way — Cache Enabler's header is a prose sentence,
	 * and some WP Rocket builds declare only WP_ROCKET_ADVANCED_CACHE.
	 */
	private static function declares_identifier( string $candidate, array $evidence ): bool {
		$candidate = trim( $candidate );
		if ( '' === $candidate || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $candidate ) ) {
			return false;
		}
		return in_array( strtolower( $candidate ), $evidence['identifiers'], true );
	}

	/**
	 * Drop the memoized catalog. Called when the filter's inputs can have
	 * changed (plugin activation, module registration).
	 */
	public static function invalidate(): void {
		self::$cache = null;
	}
}
