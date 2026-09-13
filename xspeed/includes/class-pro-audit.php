<?php
/**
 * Pro_Audit — scans the current Free configuration + cache stats and
 * surfaces Pro features that would specifically help THIS site.
 *
 * Powers the dashboard's "Run Pro audit" button. The point isn't to
 * list every Pro feature; it's to make each suggestion personal
 * ("Cache hit ratio is 38% → Pro Recommendations would tell you why")
 * so the user converts because Pro solves a problem they actually
 * see, not because we shouted "BUY NOW."
 *
 * Pure read-only. Returns an ordered list of suggestions:
 *
 *   [ id, severity ('high'|'med'|'low'), reason, fact ]
 *
 *   - id      → matches a key in PRO_FEATURES (the React catalog),
 *               so the panel renders title/body without duplicating
 *               copy here.
 *   - severity controls sort order + visual tone.
 *   - reason  → one-sentence explanation specific to this site's
 *               state. Already-baked numbers/percentages so the
 *               React side just prints it.
 *   - fact    → optional shorter inline stat (e.g. "38%") for the
 *               result card's chip.
 *
 * Adding a rule: drop another `if (…) $out[] = …` block in run().
 * Rules are independent — keep them small + concrete + factual.
 *
 * An add-on adds one from outside via `xspeed_pro_audit_suggestions`
 * instead — see contributed() for the shape it has to return and why
 * that path is not gated by already_active().
 *
 * If the suggestion maps to a single Pro module, add it to
 * BACKING_MODULE and guard the rule with `! self::already_active($id)`.
 * A rule that fires on site state alone keeps nagging a customer who
 * already bought and enabled the feature — and any consumer rendering
 * the audit as a call-to-action (the Hub's "Enable" button) then shows
 * a control that can never clear. (#187)
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Pro_Audit {

	public const SEVERITY_HIGH = 'high';
	public const SEVERITY_MED  = 'med';
	public const SEVERITY_LOW  = 'low';

	/**
	 * Bounds on what `xspeed_pro_audit_suggestions` may add.
	 *
	 * The audit is rendered in a dashboard card and returned verbatim by the
	 * MCP `get_pro_audit` tool, so an add-on that appends 500 rows or a
	 * paragraph of prose degrades both. The caps are generous against any
	 * honest use — no native rule comes close — and exist so a buggy
	 * contributor can't make the payload the problem.
	 */
	private const MAX_CONTRIBUTED = 10;
	private const MAX_REASON_LEN  = 400;
	private const MAX_FACT_LEN    = 32;

	/**
	 * Snapshot of state every rule needs. Computed once per audit run
	 * so we don't read the same option 8 times.
	 *
	 * @param array|null $totals_override Test injection — Brain Monkey
	 *                                    can't mock static class methods,
	 *                                    so tests synthesize the 24h
	 *                                    counter shape here directly.
	 *                                    Production callers leave null.
	 *
	 * @return array<string,mixed>
	 */
	private static function snapshot( ?array $totals_override = null ): array {
		$opts = static function ( string $slug ): array {
			return (array) get_option( 'xspeed_module_' . $slug, array() );
		};
		if ( null !== $totals_override ) {
			$totals = $totals_override;
		} elseif ( class_exists( '\\XSpeed\\Hit_Counter' ) ) {
			$totals = Hit_Counter::totals_24h();
		} else {
			$totals = array( 'hits' => 0, 'misses' => 0, 'excluded' => 0, 'ratio' => 0.0 );
		}
		$cloudflare = $opts( 'cloudflare' );
		return array(
			'cache'         => $opts( 'cache' ),
			'minify'        => $opts( 'minify' ),
			'lazy'          => $opts( 'lazy' ),
			'gzip'          => $opts( 'gzip' ),
			'browser_cache' => $opts( 'browser-cache' ),
			'cloudflare'    => $cloudflare,
			'cdn'           => $opts( 'cdn' ),
			'database'      => $opts( 'database' ),
			'preloader'     => $opts( 'preloader' ),
			'heartbeat'     => $opts( 'heartbeat' ),
			'cache_enabled' => class_exists( '\\XSpeed\\Settings' )
				? ! empty( Settings::get()['cache_enabled'] )
				: false,
			// An edge cache (Cloudflare) in front means the origin hit ratio is
			// only the origin layer — hits served at the edge never reach PHP —
			// so a low number is an attribution artefact, not a cache problem.
			// Rule 2 must not fire an upsell off it. (#118)
			'edge_cache'    => ! empty( $cloudflare['enabled'] ),
			'totals_24h'    => array(
				'hits'     => (int) ( $totals['hits'] ?? 0 ),
				'misses'   => (int) ( $totals['misses'] ?? 0 ),
				// 404s + bots, kept out of the ratio denominator. (#118)
				'excluded' => (int) ( $totals['excluded'] ?? 0 ),
				'total'    => (int) ( $totals['hits'] ?? 0 ) + (int) ( $totals['misses'] ?? 0 ),
				'ratio'    => (float) ( $totals['ratio'] ?? 0.0 ),
			),
		);
	}

	/**
	 * Which Pro module backs each suggestion id. A suggestion whose module
	 * is installed AND switched on is not an upsell any more — the site
	 * already has the thing we'd be selling. (#187)
	 *
	 * Ids without an entry (analytics fallback, white-label, …) are always
	 * eligible; absence here means "no single module answers this".
	 */
	private const BACKING_MODULE = array(
		'webp-avif'    => 'images',
		'rum'          => 'rum',
		'critical-css' => 'critical-css',
	);

	/*
	 * `cloudflare-apo` is deliberately NOT here.
	 *
	 * APO's on/off state lives at Cloudflare — `Cloudflare_Apo::status()`
	 * is a live GET against their API, and our own options carry only the
	 * values we PUSH to it (cache_level, browser_ttl). Nothing local
	 * records whether it is on. The audit runs on every dashboard load and
	 * on Free installs with no credentials, so it must not make a network
	 * call to find out.
	 *
	 * The first cut mapped it to an `enabled` key that the module never
	 * writes, which read as "always eligible" — the right OUTCOME by
	 * accident, via a check that could never fire. Stating the limitation
	 * is better than a guard that looks like it works. If a cached
	 * APO-state option is added later, give it a probe below and restore
	 * the mapping. (#187 review)
	 */

	/**
	 * Ids whose module records its on/off state somewhere other than a
	 * plain `enabled` flag.
	 *
	 * The first cut of this guard read `enabled` for all of them. Only
	 * `rum` and `critical-css` have that key — `images` is switched on PER
	 * FORMAT (`webp` / `avif`), so a site with conversion fully on was
	 * still told to buy image conversion, and the Hub kept drawing an
	 * Enable button that could never clear. (#187 review)
	 *
	 * A closure per id, receiving that module's EFFECTIVE options — schema
	 * defaults merged over the stored row, which is what the module actually
	 * runs on. Reading the raw row instead missed every setting still at its
	 * default, and Images defaults `webp` to true. (#187 QA round 2)
	 *
	 * @return array<string, callable(array):bool>
	 */
	private static function activity_probes(): array {
		return array(
			// Conversion is per format — either one means new uploads are
			// being converted, which is the thing the suggestion sells.
			'webp-avif' => static function ( array $o ): bool {
				return ! empty( $o['webp'] ) || ! empty( $o['avif'] );
			},
		);
	}

	/**
	 * Is the Pro module backing this suggestion already active?
	 *
	 * Resolved by module SLUG, never by referencing a Pro class: the audit
	 * runs on Free installs where those classes do not exist, and Free never
	 * names Pro. Settings_Manager returns an empty array for a slug that is
	 * not registered, so Free resolves to "not active" and keeps suggesting.
	 */
	private static function already_active( string $id ): bool {
		$slug = self::BACKING_MODULE[ $id ] ?? '';
		if ( '' === $slug ) {
			return false;
		}

		/*
		 * Suppression is only honest while Pro is installed AND licensed.
		 *
		 * Deactivating Pro leaves its settings rows behind, and nothing
		 * clears them — Pro has no uninstall routine for them, so deleting
		 * the plugin does not help either. Reading those rows directly meant
		 * a Free-only site with the same history was silently never shown
		 * the RUM and Critical CSS suggestions again. An expired licence is
		 * the same shape with a sharper edge: the feature is gated OFF and
		 * genuinely not running, which is exactly the moment a renewal
		 * prompt is most useful. (#187 review)
		 *
		 * `xspeed_pro_state` is the signal Pro already reports to Free for
		 * the gated UI; it needs no Pro class reference here.
		 */
		if ( 'active' !== self::pro_state() ) {
			return false;
		}

		/*
		 * Read the module's EFFECTIVE settings, not its stored row.
		 *
		 * A raw get_option() sees only what someone has explicitly saved. A
		 * module's schema defaults are what it actually runs on until then —
		 * Pro's Images module defaults `webp` to true, so a site that bought
		 * Pro and never opened the Images panel is converting images while
		 * its option row has no `webp` key at all. The probe read false and
		 * the audit told that customer to buy image conversion: the exact
		 * complaint in #187, reproduced on a different feature. Opening the
		 * panel and pressing Save with no changes made the suggestion vanish,
		 * which is the tell that the check was reading the wrong thing rather
		 * than a genuine "off". (#187 QA round 2)
		 *
		 * Settings_Manager::get() merges schema defaults over the stored row.
		 * It returns array() for a module that is not registered, so on Free —
		 * where the Pro module does not exist — this still resolves to "not
		 * active" and every suggestion keeps firing. Safe to call here because
		 * the pro_state() gate above means Pro is loaded by this point.
		 */
		$opts   = Settings_Manager::get( $slug );
		$probes = self::activity_probes();
		if ( isset( $probes[ $id ] ) ) {
			return (bool) $probes[ $id ]( $opts );
		}

		return ! empty( $opts['enabled'] );
	}

	/**
	 * Suggestions contributed by an add-on, normalised to the native shape.
	 *
	 * The rules in run() only know what the Free engine can see: options and
	 * cache counters. An add-on that owns a feature knows things about it that
	 * no option records — that a generator has never once succeeded, that a
	 * conversion is producing files bigger than the ones it replaces — and
	 * before this filter it had nowhere to say so. The finding stayed inside
	 * that add-on's own panel, and `get_pro_audit`, which is what an agent
	 * reads when it asks "what is wrong with this site", never heard about it.
	 *
	 * DELIBERATELY NOT GATED BY already_active(). That guard exists to stop the
	 * audit nagging someone to buy a feature they already own (#187), which is
	 * an upsell concern: an upsell for a feature that is already on can never
	 * be acted on. A contribution is the opposite kind of message — it comes
	 * FROM the feature, and the feature has to be switched on to have anything
	 * to report. Inheriting the suppression would silence exactly the case
	 * worth hearing: a feature that is on and misbehaving. Do not "tidy up" by
	 * routing this through already_active(). (xspeed-pro#86)
	 *
	 * Contributors may only ADD. The filter is seeded with an empty array and
	 * the native list is passed as read-only context, so nothing a contributor
	 * returns can delete or rewrite a native suggestion. Contributions are
	 * appended after the native rules and then go through the same dedupe and
	 * severity sort, so a contribution takes over a native id only by being
	 * strictly more severe — never by merely arriving later.
	 *
	 * @param array<int,array<string,mixed>> $native Suggestions the native
	 *                                               rules produced, as context.
	 * @return array<int,array{id:string,severity:string,reason:string,fact?:string}>
	 */
	private static function contributed( array $native ): array {
		/**
		 * Filter: xspeed_pro_audit_suggestions
		 *
		 * Extra suggestions to append to the audit. Seeded with an empty
		 * array — append your own entries and return the array; the native
		 * suggestions are passed separately as read-only context, so nothing
		 * returned here can remove or rewrite one.
		 *
		 * Each entry: id (string, required — a feature slug; matches a key in
		 * the React PRO_FEATURES catalog when one exists, otherwise the id
		 * itself is shown as the title), severity ('high'|'med'|'low',
		 * defaults to 'low'), reason (string, required — one sentence,
		 * already-baked numbers, no markup), fact (string, optional — a short
		 * inline stat for the card's chip). Anything else is dropped.
		 *
		 * @param array $suggestions Contributed suggestions (empty on entry).
		 * @param array $native      The native suggestions, as context.
		 */
		try {
			$raw = apply_filters( 'xspeed_pro_audit_suggestions', array(), $native );
		} catch ( \Throwable $e ) {
			// A contributor that throws costs the audit its contributions,
			// not the audit. Every native finding is already computed by the
			// time this runs, and the audit is what the dashboard card and
			// the MCP `get_pro_audit` tool both read — a seam that lets a
			// broken add-on take those down is a liability to the thing it
			// extends.
			//
			// The whole round is lost, not just the thrower's entry: the
			// throw unwinds through apply_filters(), so a well-behaved
			// contributor that ran earlier has no partial result left to
			// salvage. Nothing to do about that from out here, but it is
			// the reason this says "contributions" and not "its
			// contribution".
			//
			// core's apply_filters() pops $wp_current_filter AFTER the
			// callbacks return, so a throw leaves our hook name on the
			// stack: current_filter() would keep answering
			// 'xspeed_pro_audit_suggestions' for the rest of the request,
			// and core's own lazy-loading branches on that. Pop it back,
			// and only if it is still ours to pop. (WP_Hook's nesting_level
			// leaks the same way and cannot be reached from here; it is
			// scoped to this one hook, which we are done with.)
			if ( isset( $GLOBALS['wp_current_filter'] )
				&& is_array( $GLOBALS['wp_current_filter'] )
				&& end( $GLOBALS['wp_current_filter'] ) === 'xspeed_pro_audit_suggestions' ) {
				array_pop( $GLOBALS['wp_current_filter'] );
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Swallowing a fatal without a word makes a broken add-on
				// indistinguishable from one with nothing to report.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[xspeed] xspeed_pro_audit_suggestions contributor threw: ' . $e->getMessage() );
			}
			return array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $row ) {
			if ( count( $out ) >= self::MAX_CONTRIBUTED ) {
				break;
			}
			$clean = self::normalize_suggestion( $row );
			if ( null !== $clean ) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	/**
	 * Coerce one contributed entry into the exact shape run() emits, or drop it.
	 *
	 * Everything downstream — the dedupe, the severity sort, the React card,
	 * the MCP tool's response — is written against `{id, severity, reason,
	 * fact?}` and nothing re-checks it. An unknown severity alone is enough to
	 * break the panel, which indexes a style map by it. So this is a whitelist,
	 * not a merge: unrecognised keys are dropped rather than passed through, and
	 * an entry that can't supply an id and a reason is dropped whole. A missing
	 * or invalid severity is not fatal, but it settles at 'low' — a contributor
	 * who won't say how bad it is doesn't get to outrank anyone.
	 *
	 * @param mixed $row Whatever the filter returned in this slot.
	 * @return array{id:string,severity:string,reason:string,fact?:string}|null
	 */
	private static function normalize_suggestion( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$id = sanitize_key( self::as_text( $row['id'] ?? null ) );
		if ( '' === $id ) {
			return null;
		}

		$reason = self::as_text( $row['reason'] ?? null );
		$reason = trim( (string) sanitize_text_field( $reason ) );
		if ( '' === $reason ) {
			return null;
		}

		$severity = self::as_text( $row['severity'] ?? null );
		if ( ! in_array( $severity, array( self::SEVERITY_HIGH, self::SEVERITY_MED, self::SEVERITY_LOW ), true ) ) {
			$severity = self::SEVERITY_LOW;
		}

		$clean = array(
			'id'       => $id,
			'severity' => $severity,
			'reason'   => self::clamp( $reason, self::MAX_REASON_LEN ),
		);

		$fact = trim( (string) sanitize_text_field( self::as_text( $row['fact'] ?? null ) ) );
		if ( '' !== $fact ) {
			$clean['fact'] = self::clamp( $fact, self::MAX_FACT_LEN );
		}

		return $clean;
	}

	/**
	 * A string, or '' for anything that isn't honestly one.
	 *
	 * Booleans are excluded on purpose: `(string) true` is "1", which would
	 * sail through an is_scalar() check and land a suggestion whose reason
	 * reads "1".
	 *
	 * @param mixed $value Raw value from a contributed entry.
	 */
	private static function as_text( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		return '';
	}

	/**
	 * Trim to a hard character budget, ellipsis included in the budget.
	 *
	 * @param string $text  Text to bound.
	 * @param int    $limit Maximum length of the result.
	 */
	private static function clamp( string $text, int $limit ): string {
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text ) <= $limit ) {
				return $text;
			}
			return rtrim( mb_substr( $text, 0, $limit - 1 ) ) . '…';
		}
		return self::clamp_without_mbstring( $text, $limit );
	}

	/**
	 * clamp() on a site with no mbstring.
	 *
	 * strlen()/substr() count BYTES, and using them here got the budget wrong
	 * in both directions at once. Too tight: 400 bytes of accented Latin is
	 * under 200 characters, so a reason well inside its allowance came back
	 * truncated. And unsafe: a byte cut can land inside a character, and
	 * wp_json_encode() answers false for the WHOLE response rather than
	 * mangling one word — the client loses every finding in the audit.
	 *
	 * PCRE counts characters in `/u` mode without mbstring, so the budget
	 * stays a character budget. Both patterns are bounded by `$limit`, so
	 * neither walks a long string or builds an array of it.
	 *
	 * @param string $text  Text to bound.
	 * @param int    $limit Maximum length of the result.
	 */
	private static function clamp_without_mbstring( string $text, int $limit ): string {
		$limit = max( 1, $limit );

		// preg_match() returns false — not 0 — when the subject is not valid
		// UTF-8, which is how the byte fallback below is reached.
		$within = preg_match( '/^.{0,' . $limit . '}$/us', $text );
		if ( 1 === $within ) {
			return $text;
		}
		if ( 0 === $within && 1 === preg_match( '/^.{0,' . ( $limit - 1 ) . '}/us', $text, $m ) ) {
			return rtrim( $m[0] ) . '…';
		}

		// Not valid UTF-8 to begin with — a contributor sent bytes we cannot
		// count. Bytes are all there is, but the result still has to encode.
		if ( strlen( $text ) <= $limit ) {
			return $text;
		}
		return rtrim( self::whole_characters( substr( $text, 0, $limit - 1 ) ) ) . '…';
	}

	/**
	 * Drop a trailing partial UTF-8 character.
	 *
	 * A byte cut can land inside a multibyte character and leave a dangling
	 * fragment. That makes the string invalid UTF-8, and wp_json_encode()
	 * answers false for the WHOLE response — the client loses every finding,
	 * not one accented word. At most three bytes come off.
	 *
	 * @param string $bytes Byte-cut text.
	 */
	private static function whole_characters( string $bytes ): string {
		// `//u` is an empty pattern with the UTF-8 modifier: it matches
		// anything, and fails outright when the subject is not valid UTF-8.
		while ( '' !== $bytes && 1 !== preg_match( '//u', $bytes ) ) {
			$bytes = substr( $bytes, 0, -1 );
		}
		return $bytes;
	}

	/**
	 * Pro's own report of its licence state: 'not_installed' | 'unlicensed'
	 * | 'active'. Mirrors Admin::pro_state(), which is private to that
	 * class; only 'active' means Pro's features are actually running.
	 */
	private static function pro_state(): string {
		$present = class_exists( '\\XSpeed\\Tier_Registry' ) && Tier_Registry::pro_active();
		$default = $present ? 'active' : 'not_installed';

		/** This filter is documented in includes/class-admin.php */
		$state = (string) apply_filters( 'xspeed_pro_state', $default );

		return in_array( $state, array( 'not_installed', 'unlicensed', 'active' ), true ) ? $state : $default;
	}

	/**
	 * @param array|null $totals_override See snapshot(). Production
	 *                                    callers pass nothing.
	 *
	 * @return array<int,array{id:string,severity:string,reason:string,fact?:string}>
	 */
	public static function run( ?array $totals_override = null ): array {
		$s   = self::snapshot( $totals_override );
		$out = array();

		// Rule 1 — Cloudflare connected but APO not in use.
		// High signal: user already pays the Cloudflare overhead, APO
		// is the highest-leverage Pro feature they can flip on next.
		//
		// Deliberately NOT guarded by already_active(): APO's real state
		// lives at Cloudflare, not in an option here, and finding out means
		// a live API call — which this audit runs on every dashboard load,
		// including Free installs with no credentials. The guard used to be
		// called here anyway; it could never fire (no BACKING_MODULE entry),
		// so it produced the right outcome by accident while reading as
		// though the case were handled. See the note beside BACKING_MODULE.
		// (#187 QA round 2)
		if ( ! empty( $s['cloudflare']['enabled'] ) ) {
			$out[] = array(
				'id'       => 'cloudflare-apo',
				'severity' => self::SEVERITY_HIGH,
				'reason'   => 'Cloudflare is already connected. Pro adds Automatic Platform Optimization, which edge-caches your HTML — typically cuts TTFB in half.',
				'fact'     => 'Cloudflare on',
			);
		}

		// Rule 2 — Low cache hit ratio with meaningful traffic.
		// "Meaningful" = > 50 hits over 24h; below that the ratio is
		// statistical noise and we'd suggest based on bad data. The ratio is
		// now computed over real traffic only (404s + bots excluded, #118), and
		// we skip it entirely when an edge cache fronts the origin — behind
		// Cloudflare a low origin ratio means hits are served at the edge, not
		// that the cache is failing, so firing a "your cache is bad" upsell off
		// it is selling against a measurement artefact.
		if ( empty( $s['edge_cache'] )
			&& $s['totals_24h']['total'] >= 50
			&& $s['totals_24h']['ratio'] < 0.5 ) {
			$pct   = (int) round( $s['totals_24h']['ratio'] * 100 );
			$out[] = array(
				'id'       => 'recommendations',
				'severity' => self::SEVERITY_HIGH,
				'reason'   => sprintf(
					'Cache hit ratio is %d%% over the last 24h. Pro Recommendations identifies which URLs miss the cache and why, with one-click fixes.',
					$pct
				),
				'fact'     => $pct . '% hit',
			);
		}

		// Rule 3 — Lazy-load enabled but no auto WebP/AVIF.
		// User cares about images (lazy on) → next gain is format.
		if ( ! empty( $s['lazy']['lazy_images'] ) && ! self::already_active( 'webp-avif' ) ) {
			$out[] = array(
				'id'       => 'webp-avif',
				'severity' => self::SEVERITY_MED,
				'reason'   => 'Images are lazy-loaded. Pro auto-converts new JPEG/PNG uploads to WebP and AVIF — typically 25-35% smaller at the same visual quality.',
			);
		}

		// Rule 4 — High traffic without RUM data.
		// Real-user metrics matter more than synthetic Lighthouse when
		// the site has actual visitors.
		if ( $s['totals_24h']['total'] >= 100 && ! self::already_active( 'rum' ) ) {
			$views = number_format( $s['totals_24h']['total'] );
			$out[] = array(
				'id'       => 'rum',
				'severity' => self::SEVERITY_MED,
				'reason'   => sprintf(
					'You served %s requests in 24h. Pro RUM samples actual LCP, CLS and INP from those visitors — Lighthouse only simulates one device, one connection.',
					$views
				),
				'fact'     => $views . ' / 24h',
			);
		}

		// Rule 5 — HTML minify on but JS minify off (theme-safe stance).
		// Suggest Critical CSS as the next gain that doesn't touch JS.
		if ( ! empty( $s['minify']['minify_html'] ) && empty( $s['minify']['minify_js'] )
			&& ! self::already_active( 'critical-css' ) ) {
			$out[] = array(
				'id'       => 'critical-css',
				'severity' => self::SEVERITY_MED,
				'reason'   => 'JS minify is off (good — high theme-conflict risk). Pro Critical CSS delivers similar first-paint gains without touching JavaScript.',
			);
		}

		// Rule 6 — Database cleanup on manual schedule.
		// Only fire when the user has actually configured the Database
		// module (has saved options). Empty option = user hasn't
		// touched it; don't suggest scheduling something they might
		// never use.
		if ( ! empty( $s['database'] ) && 'manual' === ( $s['database']['schedule'] ?? 'manual' ) ) {
			$out[] = array(
				'id'       => 'recommendations',
				'severity' => self::SEVERITY_LOW,
				'reason'   => 'Database cleanup is set to manual. Pro Recommendations engine auto-schedules cleanups based on smart triggers (after publish, before backup).',
			);
		}

		// Rule 7 — Agency / professional usage signal.
		// >= 5 enabled modules suggests serious use → white-label is
		// what they'd actually want next.
		$enabled = 0;
		foreach ( array( 'minify', 'gzip', 'lazy', 'browser_cache', 'cloudflare', 'cdn', 'preloader' ) as $k ) {
			if ( ! empty( $s[ $k ]['enabled'] ) ) {
				$enabled++;
			}
		}
		if ( $s['cache_enabled'] ) {
			$enabled++;
		}
		if ( $enabled >= 5 ) {
			$out[] = array(
				'id'       => 'white-label',
				'severity' => self::SEVERITY_LOW,
				'reason'   => sprintf(
					'You\'ve configured %d modules — looks like agency work. Pro White-Label rebrands the dashboard chrome for client handoff.',
					$enabled
				),
				'fact'     => $enabled . ' modules',
			);
		}

		// Add-on contributions. Collected after the native rules and before
		// the fallback: a site whose only real finding comes from an add-on
		// should get that finding, not the generic filler underneath it.
		$out = array_merge( $out, self::contributed( $out ) );

		// Fallback — never return an empty audit. Analytics is the
		// safe always-relevant suggestion (every site has cache
		// activity to chart).
		if ( empty( $out ) ) {
			$out[] = array(
				'id'       => 'analytics',
				'severity' => self::SEVERITY_LOW,
				'reason'   => 'See which pages benefit most from caching, where your slow URLs are, and your hit-ratio over time.',
			);
		}

		// Dedupe by id, keeping the highest-severity rule per feature.
		// Rules independently suggest the same feature for different
		// reasons; pick the strongest reason to show.
		$by_id = array();
		$order = array( self::SEVERITY_HIGH => 0, self::SEVERITY_MED => 1, self::SEVERITY_LOW => 2 );
		foreach ( $out as $row ) {
			$id = $row['id'];
			if ( ! isset( $by_id[ $id ] ) ) {
				$by_id[ $id ] = $row;
				continue;
			}
			$existing_rank = $order[ $by_id[ $id ]['severity'] ] ?? 9;
			$new_rank      = $order[ $row['severity'] ] ?? 9;
			if ( $new_rank < $existing_rank ) {
				$by_id[ $id ] = $row;
			}
		}

		$out = array_values( $by_id );
		usort( $out, static function ( $a, $b ) use ( $order ) {
			return ( $order[ $a['severity'] ] ?? 9 ) <=> ( $order[ $b['severity'] ] ?? 9 );
		} );
		return $out;
	}
}
