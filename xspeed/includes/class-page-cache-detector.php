<?php
/**
 * Page_Cache_Detector — read-only answer to "who owns the page cache on this
 * site right now, and would installing xSpeed's drop-in take it from them?"
 *
 * Nothing in this class writes. It reads the active-plugin list, the two
 * wp-content drop-ins, wp-config.php's WP_CACHE define, and the artifacts
 * catalogued in Cache_Plugin_Catalog, then classifies the result into one
 * explicit ownership state. Callers decide what to do with that; the detector
 * never decides for them and never touches a file.
 *
 * Why a state and not a boolean: "another cache plugin is active" and "another
 * cache plugin's page cache is live" are different facts, and so is "there is
 * a drop-in here and we have no idea whose it is". Collapsing them into
 * `$conflicts ? no : yes` is what let xSpeed back up and overwrite a foreign
 * advanced-cache.php.
 *
 * Hard rules this class keeps:
 *   - never include, require, or execute a foreign plugin file;
 *   - never treat a loose substring ("cache") as proof of ownership;
 *   - an unreadable or unrecognized shared artifact is a BLOCKER, never a pass.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Page_Cache_Detector {

	/** Nothing owns the page cache; the field is clear. */
	public const STATE_UNCLAIMED = 'unclaimed';
	/** Our own drop-in is installed. */
	public const STATE_XSPEED_OWNED = 'xspeed-owned';
	/** An identified foreign page cache is installed and serving. */
	public const STATE_FOREIGN_LIVE = 'foreign-live';
	/** Foreign artifacts remain but nothing is wired up to serve from them. */
	public const STATE_FOREIGN_RESIDUAL = 'foreign-residual';
	/** A page-cache-capable plugin is active with no drop-in evidence — it may cache at server level (LiteSpeed) or have caching switched off. Unprovable either way from here. */
	public const STATE_POSSIBLE_LIVE = 'possible-live';
	/** More than one foreign page cache is in play. */
	public const STATE_CONTESTED = 'contested';
	/** A drop-in (or a live WP_CACHE) exists that we cannot attribute to anyone. */
	public const STATE_UNKNOWN_OCCUPIED = 'unknown-occupied';
	/** We could not read what we needed to decide. */
	public const STATE_UNAVAILABLE = 'unavailable';

	/** Drop-in owner classifications. */
	public const OWNER_NONE    = 'none';
	public const OWNER_XSPEED  = 'xspeed';
	public const OWNER_FOREIGN = 'foreign';
	public const OWNER_UNKNOWN = 'unknown';

	/*
	 * Blocker codes, not sentences.
	 *
	 * The detector says what it found; the caller says it in its own words and
	 * its own textdomain. A code also survives being stored in a transaction
	 * record and compared later, which a translated string does not.
	 */

	/** An identified foreign plugin owns advanced-cache.php. */
	public const BLOCKER_FOREIGN_DROPIN = 'foreign_dropin';
	/** advanced-cache.php is there and readable, but nobody claims it. */
	public const BLOCKER_UNKNOWN_DROPIN = 'unknown_dropin';
	/** advanced-cache.php is there and cannot be read. */
	public const BLOCKER_UNREADABLE_DROPIN = 'unreadable_dropin';
	/** A page-cache-capable plugin is active with no drop-in evidence. */
	public const BLOCKER_ACTIVE_PAGE_CACHE = 'active_page_cache';
	/** More than one plugin owns, or could own, the page cache. */
	public const BLOCKER_MULTIPLE_PAGE_CACHES = 'multiple_page_caches';
	/** WP_CACHE is true with no drop-in to explain it. */
	public const BLOCKER_WP_CACHE_ORPHANED = 'wp_cache_orphaned';
	/** wp-config.php defines WP_CACHE more than once. */
	public const BLOCKER_WP_CACHE_DUPLICATE = 'wp_cache_duplicate';
	/** WP_CACHE's value is an expression we cannot evaluate by reading it. */
	public const BLOCKER_WP_CACHE_DYNAMIC = 'wp_cache_dynamic';
	/** WP_CACHE is defined inside a conditional. */
	public const BLOCKER_WP_CACHE_CONDITIONAL = 'wp_cache_conditional';
	/** wp-config.php could not be read at all. */
	public const BLOCKER_WP_CONFIG_UNREADABLE = 'wp_config_unreadable';

	/** Note codes. Informational; a note never blocks. */
	public const NOTE_OBJECT_CACHE_PRESENT = 'object_cache_present';
	public const NOTE_RESIDUAL_CACHE_FILES = 'residual_cache_files';

	/**
	 * @var array|null Memoized report for this request.
	 */
	private static $report = null;

	/**
	 * Full evidence report. Read-only; safe to call from a REST GET, the
	 * health card, or CLI.
	 *
	 * @return array{
	 *     scope:string,
	 *     multisite:bool,
	 *     plugins:array<int,array>,
	 *     dropin:array,
	 *     object_dropin:array,
	 *     wp_cache:array,
	 *     revision:string
	 * }
	 */
	public static function inspect( bool $fresh = true ): array {
		if ( ! $fresh && null !== self::$report ) {
			return self::$report;
		}

		$multisite = function_exists( 'is_multisite' ) && is_multisite();
		$report = array(
			'scope'         => $multisite ? 'site-and-network' : 'site',
			'multisite'     => $multisite,
			'plugins'       => self::inspect_plugins(),
			'dropin'        => self::inspect_dropin(),
			'object_dropin' => self::inspect_object_dropin(),
			'wp_cache'      => self::inspect_wp_cache(),
		);

		/*
		 * A fingerprint of everything the decision rests on. An acquisition
		 * transaction records the revision it inspected and re-checks it
		 * immediately before writing, so a plugin activated (or a drop-in
		 * dropped in) between the two loses the race instead of getting
		 * silently overwritten.
		 */
		$report['revision'] = self::revision_of( $report );

		self::$report = $report;
		return $report;
	}

	/**
	 * Mandatory fresh evidence path for a caller that may write afterwards.
	 *
	 * Acquisition code must use this method immediately before comparing the
	 * revision and touching shared page-cache state. The named method makes a
	 * safety rescan visible at the call site; inspect() is fresh by default too.
	 */
	public static function inspect_fresh(): array {
		return self::inspect( true );
	}

	/**
	 * Per-plugin evidence. One row per CATALOGUED plugin with at least one
	 * signal present — an inactive plugin that left artifacts behind still
	 * gets a row, with `active` false.
	 *
	 * @return array<int,array>
	 */
	private static function inspect_plugins(): array {
		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/plugins' : '' );

		$out = array();
		foreach ( Cache_Plugin_Catalog::all() as $file => $entry ) {
			$signals        = self::signals_for( $entry );
			$installed      = '' !== $plugin_dir && is_file( $plugin_dir . '/' . $file );
			$network_active = function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $file );
			$active         = function_exists( 'is_plugin_active' ) ? (bool) is_plugin_active( $file ) : false;
			$site_active    = function_exists( 'get_option' ) && in_array( $file, (array) get_option( 'active_plugins', array() ), true );
			if ( $active && ! $network_active ) {
				$site_active = true;
			}
			$active = $active || $site_active || $network_active;
			if ( ! $active && empty( $signals ) ) {
				continue;
			}

			$out[] = array(
				'plugin'          => $file,
				'label'           => $entry['label'],
				'active'          => $active,
				'installed'       => $installed,
				'site_active'     => $site_active,
				'network_active'  => $network_active,
				'activation_scope' => $site_active && $network_active ? 'site-and-network' : ( $network_active ? 'network' : ( $site_active ? 'site' : 'inactive' ) ),
				'capabilities'    => $entry['capabilities'],
				'page_cache'      => in_array( Cache_Plugin_Catalog::CAP_PAGE_CACHE, $entry['capabilities'], true ),
				'signals'         => $signals,
			);
		}
		return $out;
	}

	/**
	 * Which of a catalog entry's signals are present. Constants and classes
	 * are checked without autoloading; options are read through get_option;
	 * paths are stat'd under wp-content. No file is opened.
	 *
	 * @return string[] e.g. ['constant:W3TC_DIR', 'path:cache/page_enhanced']
	 */
	private static function signals_for( array $entry ): array {
		$signals = $entry['signals'] ?? array();
		$found   = array();

		foreach ( (array) ( $signals['constants'] ?? array() ) as $constant ) {
			if ( defined( $constant ) ) {
				$found[] = 'constant:' . $constant;
			}
		}
		foreach ( (array) ( $signals['classes'] ?? array() ) as $class ) {
			// Second arg false: never trigger an autoloader for foreign code.
			if ( class_exists( $class, false ) ) {
				$found[] = 'class:' . $class;
			}
		}
		foreach ( (array) ( $signals['options'] ?? array() ) as $option ) {
			if ( function_exists( 'get_option' ) ) {
				$value = get_option( $option, null );
				if ( null !== $value && false !== $value ) {
					$found[] = 'option:' . $option;
				}
			}
		}
		foreach ( (array) ( $signals['paths'] ?? array() ) as $path ) {
			if ( defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/' . ltrim( (string) $path, '/' ) ) ) {
				$found[] = 'path:' . $path;
			}
		}

		return $found;
	}

	/**
	 * advanced-cache.php state: does it exist, whose is it, and what does it
	 * hash to. The hash is the compare-and-swap token for an acquisition —
	 * a writer that finds a different hash than it inspected must abort.
	 */
	private static function inspect_dropin(): array {
		$target = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/advanced-cache.php' : '';
		$state  = array(
			'path'     => $target,
			'exists'   => false,
			'owner'    => self::OWNER_NONE,
			'plugin'   => null,
			'label'    => null,
			'hash'     => null,
			'readable' => true,
		);

		if ( '' === $target || ! file_exists( $target ) ) {
			return $state;
		}

		$state['exists'] = true;
		$contents        = self::read( $target );
		if ( null === $contents ) {
			// Present but unreadable. That is strictly worse than a known
			// foreign drop-in — we cannot even name what we would destroy.
			$state['readable'] = false;
			$state['owner']    = self::OWNER_UNKNOWN;
			return $state;
		}

		$state['hash'] = hash( 'sha256', $contents );

		if ( self::has_xspeed_signature( $contents ) ) {
			$state['owner'] = self::OWNER_XSPEED;
			$state['label'] = 'xSpeed';
			return $state;
		}

		$owner = Cache_Plugin_Catalog::identify_dropin( $contents );
		if ( null !== $owner ) {
			$entry           = Cache_Plugin_Catalog::get( $owner );
			$state['owner']  = self::OWNER_FOREIGN;
			$state['plugin'] = $owner;
			$state['label']  = self::dropin_label( $entry, $owner );
			return $state;
		}

		$state['owner'] = self::OWNER_UNKNOWN;
		return $state;
	}

	/**
	 * object-cache.php state. Informational only: a persistent object cache
	 * sits beside a page cache rather than competing with it, so this must
	 * never block a page-cache install — it is reported so the UI can say
	 * "Redis is here and we left it alone".
	 */
	private static function inspect_object_dropin(): array {
		$target = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/object-cache.php' : '';
		$state  = array(
			'path'     => $target,
			'exists'   => false,
			'readable' => true,
			'plugin'   => null,
			'label'    => null,
			'hash'     => null,
		);

		if ( '' === $target || ! file_exists( $target ) ) {
			return $state;
		}

		$state['exists'] = true;
		$contents        = self::read( $target );
		if ( null === $contents ) {
			$state['readable'] = false;
			return $state;
		}

		$state['hash'] = hash( 'sha256', $contents );
		$owner         = Cache_Plugin_Catalog::identify_object_dropin( $contents );
		if ( null !== $owner ) {
			$entry           = Cache_Plugin_Catalog::get( $owner );
			$state['plugin'] = $owner;
			$state['label']  = self::dropin_label( $entry, $owner );
		}

		return $state;
	}

	/**
	 * What a DROP-IN is allowed to say about its owner.
	 *
	 * A plugin row can be labelled precisely — it is either on disk or it is
	 * not. A drop-in cannot: Swift Performance Lite and the commercial build
	 * write the same banner, so attributing one to the catalog entry that
	 * happens to sort first reported a commercial install as "Lite". Entries
	 * with that problem declare a `family` label covering both, and this is
	 * the only place it is used.
	 *
	 * @param array|null $entry  Catalog entry, or null when there is none.
	 * @param string     $plugin Plugin file, used as the last-resort label.
	 */
	private static function dropin_label( ?array $entry, string $plugin ): string {
		if ( null === $entry ) {
			return $plugin;
		}
		return (string) ( $entry['family'] ?? $entry['label'] ?? $plugin );
	}

	/**
	 * WP_CACHE as written in wp-config.php, plus the runtime value.
	 *
	 * The literal matters more than the runtime constant: a define wrapped in
	 * a conditional, or two competing defines, cannot be safely rewritten by a
	 * regex, and a writer that tries anyway can silently disable another
	 * plugin's cache (or its own).
	 *
	 * state is one of: undefined | true | false | duplicate | dynamic |
	 * unreadable.
	 */
	private static function inspect_wp_cache(): array {
		$runtime = defined( 'WP_CACHE' ) ? (bool) constant( 'WP_CACHE' ) : null;
		$path    = Cache::wp_config_path();
		$blank   = array(
			'path'     => $path,
			'readable' => false,
			'state'    => 'unreadable',
			'runtime'  => $runtime,
			'defines'  => 0,
			'hash'     => null,
		);

		if ( '' === $path ) {
			return $blank;
		}

		$config = self::read( $path );
		if ( null === $config ) {
			return $blank;
		}

		/*
		 * One parser, shared with the writer.
		 *
		 * The detector used to carry its own token scan. Two scans of the same
		 * grammar drift, and the pair that must never disagree is exactly this
		 * one: the reader decides whether a rewrite is safe and the writer
		 * performs it. xspeed_parse_wp_cache_defines() is a plain function in
		 * its own file so both can reach it before either class loads.
		 */
		if ( ! function_exists( 'xspeed_parse_wp_cache_defines' ) ) {
			require_once __DIR__ . '/wp-cache-constant.php';
		}
		$parsed = xspeed_parse_wp_cache_defines( $config );

		return array(
			'path'     => $path,
			'readable' => true,
			'state'    => (string) $parsed['state'],
			'runtime'  => $runtime,
			'defines'  => count( $parsed['defines'] ),
			'hash'     => hash( 'sha256', $config ),
		);
	}

	/**
	 * Is it safe to install, activate, or promote a page cache right now?
	 *
	 * The one call most callers need. True only when nothing owns the page
	 * cache and nothing about the site's state is unreadable or ambiguous.
	 * `foreign-residual` passes because the artifacts left behind are inert —
	 * no drop-in, no active plugin — and refusing there would strand every
	 * site that ever tried another cache plugin.
	 */
	public static function is_field_clear(): bool {
		$verdict = self::classify();

		if ( ! empty( $verdict['blockers'] ) ) {
			return false;
		}

		return in_array(
			$verdict['state'],
			array( self::STATE_UNCLAIMED, self::STATE_FOREIGN_RESIDUAL ),
			true
		);
	}

	/**
	 * Labels of every ACTIVE plugin that can write a page cache.
	 *
	 * For a screen that wants to say what it found rather than only that it
	 * found something.
	 *
	 * @return string[]
	 */
	public static function active_page_caches(): array {
		$out = array();
		foreach ( self::inspect()['plugins'] as $plugin ) {
			if ( $plugin['page_cache'] && $plugin['active'] ) {
				$out[] = (string) $plugin['label'];
			}
		}
		return $out;
	}

	/**
	 * Who owns wp-content/advanced-cache.php, as a plugin label, or null when
	 * nobody does — or when we cannot tell.
	 */
	public static function dropin_owner_label(): ?string {
		$dropin = self::inspect()['dropin'];
		return is_string( $dropin['label'] ) ? $dropin['label'] : null;
	}

	/**
	 * Classify the report into one ownership state plus the reasons behind it.
	 *
	 * Blockers and notes are CODES with the evidence attached, never rendered
	 * sentences — see the BLOCKER_* constants. Cache::ownership_blocker_message()
	 * is what turns one into words.
	 *
	 * @param array|null $report Report from inspect(); re-inspected when null.
	 * @return array{state:string,blockers:array<int,array>,notes:array<int,array>,revision:string}
	 */
	public static function classify( ?array $report = null ): array {
		$report   = $report ?? self::inspect();
		$blockers = array();
		$notes    = array();

		$dropin        = $report['dropin'];
		$object_dropin = $report['object_dropin'];
		$wp_cache      = $report['wp_cache'];

		if ( $object_dropin['exists'] ) {
			// Informational only. A persistent object cache sits BESIDE a page
			// cache; it competes for nothing and must never block.
			$notes[] = array(
				'code'   => self::NOTE_OBJECT_CACHE_PRESENT,
				'plugin' => $object_dropin['plugin'],
				'label'  => $object_dropin['label'],
			);
		}

		foreach ( self::residual_plugins( $report['plugins'] ) as $plugin ) {
			$notes[] = array(
				'code'   => self::NOTE_RESIDUAL_CACHE_FILES,
				'plugin' => $plugin['plugin'],
				'label'  => $plugin['label'],
			);
		}

		/*
		 * Everything that owns, or could own, the page cache — keyed by PLUGIN
		 * FILE so one plugin counts once. A live competitor is normally both
		 * active and the drop-in's owner; counting those as two put the
		 * ordinary single-competitor site in `contested` and told the user
		 * "more than one page-caching plugin is in play" about one plugin.
		 */
		$owners = array();
		$active = array();
		foreach ( $report['plugins'] as $plugin ) {
			if ( $plugin['page_cache'] && $plugin['active'] ) {
				$active[ (string) $plugin['plugin'] ] = $plugin;
				$owners[ (string) $plugin['plugin'] ] = (string) $plugin['label'];
			}
		}
		if ( self::OWNER_FOREIGN === $dropin['owner'] ) {
			$key            = (string) ( $dropin['plugin'] ?? $dropin['label'] );
			$owners[ $key ] = (string) $dropin['label'];
			$blockers[]     = array(
				'code'   => self::BLOCKER_FOREIGN_DROPIN,
				'plugin' => $dropin['plugin'],
				'label'  => $dropin['label'],
			);
		} elseif ( self::OWNER_UNKNOWN === $dropin['owner'] ) {
			$blockers[] = array(
				'code'   => $dropin['readable'] ? self::BLOCKER_UNKNOWN_DROPIN : self::BLOCKER_UNREADABLE_DROPIN,
				'plugin' => null,
				'label'  => null,
			);
		}

		$wp_cache_blocker = array(
			'unreadable'  => self::BLOCKER_WP_CONFIG_UNREADABLE,
			'duplicate'   => self::BLOCKER_WP_CACHE_DUPLICATE,
			'dynamic'     => self::BLOCKER_WP_CACHE_DYNAMIC,
			'conditional' => self::BLOCKER_WP_CACHE_CONDITIONAL,
		);
		if ( isset( $wp_cache_blocker[ $wp_cache['state'] ] ) ) {
			$blockers[] = array(
				'code'   => $wp_cache_blocker[ $wp_cache['state'] ],
				'plugin' => null,
				'label'  => null,
			);
		}

		// Order matters: the most specific unsafe state wins.
		if ( 'unreadable' === $wp_cache['state'] || ! $dropin['readable'] ) {
			return self::verdict( self::STATE_UNAVAILABLE, $blockers, $notes, $report );
		}

		if ( count( $owners ) > 1 || ( self::OWNER_XSPEED === $dropin['owner'] && ! empty( $active ) ) ) {
			/*
			 * `plugin` and `label` stay null — "multiple" has no single owner
			 * to name. `plugins` and `labels` carry every owner found, in the
			 * same key order, so a consumer can subtract ITSELF and name what
			 * is left. Without them every contested site read the same
			 * anonymous sentence.
			 */
			$blockers[] = array(
				'code'    => self::BLOCKER_MULTIPLE_PAGE_CACHES,
				'plugin'  => null,
				'label'   => null,
				'plugins' => array_keys( $owners ),
				'labels'  => array_values( $owners ),
			);
			return self::verdict( self::STATE_CONTESTED, $blockers, $notes, $report );
		}

		if ( self::OWNER_UNKNOWN === $dropin['owner'] ) {
			return self::verdict( self::STATE_UNKNOWN_OCCUPIED, $blockers, $notes, $report );
		}

		if ( self::OWNER_FOREIGN === $dropin['owner'] ) {
			return self::verdict( self::STATE_FOREIGN_LIVE, $blockers, $notes, $report );
		}

		if ( self::OWNER_XSPEED === $dropin['owner'] ) {
			return self::verdict( self::STATE_XSPEED_OWNED, $blockers, $notes, $report );
		}

		if ( ! empty( $active ) ) {
			$first      = reset( $active );
			$blockers[] = array(
				'code'   => self::BLOCKER_ACTIVE_PAGE_CACHE,
				'plugin' => $first['plugin'],
				'label'  => $first['label'],
			);
			return self::verdict( self::STATE_POSSIBLE_LIVE, $blockers, $notes, $report );
		}

		/*
		 * A WP_CACHE we cannot rewrite is not a clear field. `duplicate`,
		 * `dynamic` and `conditional` already added a blocker above, but the
		 * ladder used to fall past them to `unclaimed` — a state documented as
		 * "field clear: yes". is_field_clear() was safe either way because it
		 * checks blockers first, but a caller branching on the STATE read a
		 * doubly-defined or expression-valued config as a clean site.
		 */
		if ( in_array( $wp_cache['state'], array( 'duplicate', 'dynamic', 'conditional' ), true ) ) {
			return self::verdict( self::STATE_UNKNOWN_OCCUPIED, $blockers, $notes, $report );
		}

		/*
		 * No drop-in and nothing active, but WP_CACHE is true — something
		 * enabled page caching and we cannot say what. Review, not "clear".
		 */
		if ( 'true' === $wp_cache['state'] ) {
			$blockers[] = array(
				'code'   => self::BLOCKER_WP_CACHE_ORPHANED,
				'plugin' => null,
				'label'  => null,
			);
			return self::verdict( self::STATE_UNKNOWN_OCCUPIED, $blockers, $notes, $report );
		}

		foreach ( $notes as $note ) {
			if ( self::NOTE_RESIDUAL_CACHE_FILES === $note['code'] ) {
				// Residual foreign artifacts with nothing live: safe to
				// proceed, worth saying out loud.
				return self::verdict( self::STATE_FOREIGN_RESIDUAL, $blockers, $notes, $report );
			}
		}

		return self::verdict( self::STATE_UNCLAIMED, $blockers, $notes, $report );
	}

	/**
	 * Inactive page-cache plugins whose artifacts are their OWN.
	 *
	 * Builds that share a signal set — Swift Performance Lite and the
	 * commercial build share their constants, options row and cache dir —
	 * would otherwise each be reported as having "left cache files behind",
	 * including the one that was never on this site. Blaming an active
	 * sibling was already handled; a deactivated Lite with the commercial
	 * build absent still produced two notes, because nothing was active to
	 * explain the signals away (PR #295 review).
	 *
	 * So a signal is credited to a plugin only when no plugin with stronger
	 * standing also carries it — active over installed-but-inactive over not
	 * on disk at all — and only a page-cache plugin can explain a page-cache
	 * artifact. Two absent twins sharing every signal cannot be told apart
	 * and are both reported; that is the honest answer.
	 *
	 * @param array<int,array> $plugins Rows from inspect_plugins().
	 * @return array<int,array> The rows that earn a residual note.
	 */
	private static function residual_plugins( array $plugins ): array {
		$standing = static function ( array $plugin ): int {
			if ( $plugin['active'] ) {
				return 2;
			}
			return ! empty( $plugin['installed'] ) ? 1 : 0;
		};

		$out = array();
		foreach ( $plugins as $plugin ) {
			if ( ! $plugin['page_cache'] || $plugin['active'] || empty( $plugin['signals'] ) ) {
				continue;
			}

			$explained = array();
			foreach ( $plugins as $other ) {
				if ( ! $other['page_cache'] || $other['plugin'] === $plugin['plugin'] || $standing( $other ) <= $standing( $plugin ) ) {
					continue;
				}
				$explained = array_merge( $explained, (array) $other['signals'] );
			}

			if ( array_diff( (array) $plugin['signals'], $explained ) ) {
				$out[] = $plugin;
			}
		}

		return $out;
	}

	private static function verdict( string $state, array $blockers, array $notes, array $report ): array {
		return array(
			'state'    => $state,
			'blockers' => self::unique_entries( $blockers ),
			'notes'    => self::unique_entries( $notes ),
			'revision' => (string) ( $report['revision'] ?? '' ),
		);
	}

	/**
	 * Deduplicate blocker/note entries by their whole shape.
	 *
	 * array_unique() compares string casts, which is a notice-and-nonsense
	 * combination for arrays, and SORT_REGULAR compares loosely enough to
	 * collapse findings about two different plugins. Encoding the entry is the
	 * only comparison that means "the same finding about the same plugin".
	 *
	 * @param array<int,array> $entries
	 * @return array<int,array>
	 */
	private static function unique_entries( array $entries ): array {
		$seen = array();
		$out  = array();
		foreach ( $entries as $entry ) {
			$key = (string) wp_json_encode( $entry );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $entry;
		}
		return $out;
	}

	/**
	 * May xSpeed install its page-cache artifacts right now?
	 *
	 * True only for states where nothing else owns, or could own, the page
	 * cache. `foreign-residual` passes because the artifacts are inert — no
	 * drop-in, no active plugin — and refusing there would strand every site
	 * that ever tried another cache plugin.
	 */
	public static function can_acquire( ?array $verdict = null ): bool {
		$verdict = $verdict ?? self::classify();

		/*
		 * Both conditions, not either. A blocker can be raised in an otherwise
		 * clear state — a duplicate or expression-valued WP_CACHE define is
		 * nobody's page cache, but it is also not something a regex may
		 * rewrite, so the field being empty does not make the write safe.
		 */
		if ( ! empty( $verdict['blockers'] ) ) {
			return false;
		}

		return in_array(
			$verdict['state'],
			array( self::STATE_UNCLAIMED, self::STATE_XSPEED_OWNED, self::STATE_FOREIGN_RESIDUAL ),
			true
		);
	}

	/**
	 * Fingerprint of the evidence, so a writer can prove nothing moved
	 * between inspection and the write.
	 */
	public static function revision_of( array $report ): string {
		$material = $report;
		unset( $material['revision'] );
		return hash( 'sha256', (string) wp_json_encode( $material ) );
	}

	/** Exact xSpeed banner line, never a loose substring in code or data. */
	private static function has_xspeed_signature( string $contents ): bool {
		foreach ( token_get_all( $contents ) as $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( preg_match( '/^(?:\\s*[\\/#*]+\\s*)XSPEED_DROPIN\\s*$/mi', $token[1] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read a file for inspection. Returns null on any failure — callers treat
	 * null as "unknown", never as "empty".
	 */
	private static function read( string $path ): ?string {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only inspection of a local file; WP_Filesystem would need credentials we must not prompt for on a GET.
		$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed read is a valid answer here ("unknown"), not an error to surface.
		return is_string( $contents ) ? $contents : null;
	}

	/**
	 * Drop the memoized report. Anything that changes plugin state or writes
	 * a drop-in must call this.
	 */
	public static function invalidate(): void {
		self::$report = null;
		Cache_Plugin_Catalog::invalidate();
	}

	/**
	 * Bootstrap hooks. Call once from Plugin::init().
	 */
	public static function boot(): void {
		add_action( 'activated_plugin', array( __CLASS__, 'invalidate' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'invalidate' ) );
	}
}
