<?php
/**
 * Asset_Combiner — concatenates enqueued local CSS / JS into a single
 * combined file per type. Hooked from LegacyMinifier when the
 * `combine_css` / `combine_js` toggles are on.
 *
 * Algorithm (CSS):
 *   1. wp_enqueue_scripts @ 999 — walk WP_Styles->queue, partition into
 *      local + external. External (full http(s):// to other origins,
 *      data: URIs, protocol-relative pointing elsewhere) stay enqueued
 *      as-is; local handles get pulled out of the queue.
 *   2. Build cache key = md5(JSON({handle => [src, mtime]})). When the
 *      combined file already exists for that key, skip generation.
 *   3. Otherwise: read each source body, resolve recursive @import
 *      statements (depth-limited), rewrite url(...) paths to absolute,
 *      concat with a small `/* xspeed: HANDLE *​/` header per chunk for
 *      debug-traceability, write to XSPEED_CACHE_DIR/min/combined/.
 *   4. Register the combined file as a single new handle
 *      `xspeed-combined-css` and re-add it to the queue. The original
 *      handles stay registered (so other plugins that look them up
 *      still find their metadata) but are pulled from the queue —
 *      they won't print <link> tags.
 *
 * JS path is the same, minus @import (no JS analogue) and url()
 * rewriting (JS strings are too varied to safely rewrite). External
 * + async + deferred scripts (deferred via WP_Scripts->add_data
 * 'strategy' OR the script_loader_tag filter from Minify_Filters)
 * stay un-combined.
 *
 * Cache lives in {$min_dir}/combined/ — separate from the per-file
 * minify cache so purge can target them independently if needed.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Asset_Combiner {

	public const MAX_IMPORT_DEPTH = 3;

	/**
	 * Path to the combine cache dir. Created on first write.
	 */
	public static function cache_dir(): string {
		return trailingslashit( XSPEED_CACHE_DIR ) . 'min/combined';
	}

	/**
	 * URL prefix matching cache_dir(). Built from content_url, not by
	 * string-replacing filesystem paths (see class-minifier.php for the
	 * same rationale).
	 *
	 * The scheme is forced to match the page's — `content_url()` derives
	 * its scheme from `is_ssl()`, which returns false behind a TLS-
	 * terminating reverse proxy / load balancer (common on managed hosts),
	 * so it can hand back an `http://` URL on an `https` page. The browser
	 * then blocks the combined stylesheet as mixed content and the whole
	 * page renders unstyled. Re-scheme the URL to the site's actual scheme
	 * so the <link> always matches the page. (FBS-83633)
	 */
	public static function cache_url(): string {
		$url = trailingslashit( content_url( 'cache/xspeed' ) ) . 'min/combined';
		// Match the site's registered scheme (home_url), NOT is_ssl() —
		// which set_url_scheme() would consult with no explicit scheme, and
		// which is the very signal that misreports behind a proxy.
		$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME ) ?: 'https';
		return set_url_scheme( $url, $scheme );
	}

	/**
	 * Combine local enqueued styles into one file.
	 *
	 * @deprecated Superseded by Css_Combine_Buffer, which combines the
	 * finished HTML instead of the enqueue queue. This path is no longer
	 * hooked: whatever it wrote at priority 999, WordPress edited afterwards —
	 * core's wp_maybe_inline_styles() inlines any queued handle with a `path`
	 * and blanks its src, which discarded the combined URL and took the sheets
	 * this method had already blanked with it. See Css_Combine_Buffer's header
	 * for the live trace. (#195)
	 *
	 * Kept callable because tests/e2e/48- and 49- drive it directly to pin the
	 * FBS-83114/83116/83633/83653 regressions. Remove once those specs are
	 * ported onto the buffer engine.
	 */
	public static function combine_styles(): void {
		global $wp_styles;
		if ( ! $wp_styles instanceof \WP_Styles || empty( $wp_styles->queue ) ) {
			return;
		}

		// Group combinable handles by media type. Historically every sheet
		// whose media wasn't all/screen was dropped from combining — but on
		// page-builder sites (Elementor + Essential Addons + BetterDocs) a large
		// share of the stylesheets carry responsive/print media, so dropping
		// them starved the `all` bucket below the 2-handle floor and the whole
		// combine step silently no-op'd (the page shipped 60 separate <link>s
		// even with combine_css ON). Instead we bucket PER media type and emit
		// one combined file per group with the correct `media` attribute, so
		// nothing is dropped and the combinable majority always merges. (FBS-83653)
		$buckets = self::collect_local_handles( $wp_styles );
		foreach ( $buckets as $media => $bucket ) {
			if ( count( $bucket ) < 2 ) {
				continue; // nothing to gain from combining a single file in this group.
			}
			self::combine_media_group( $wp_styles, $media, $bucket );
		}
	}

	/**
	 * Combine one media group's handles into a single stylesheet and wire it
	 * onto the group's carrier handle.
	 *
	 * @param string                     $media  The media attribute for this group ('all', 'print', …).
	 * @param array<string,array<mixed>> $bucket handle => info map.
	 */
	private static function combine_media_group( \WP_Styles $wp_styles, string $media, array $bucket ): void {
		$key = self::cache_key( $bucket );
		$dir = self::cache_dir();
		$out_file = $dir . '/combined-' . $key . '.css';
		$out_url  = self::cache_url() . '/combined-' . $key . '.css';

		if ( ! file_exists( $out_file ) ) {
			self::ensure_dir( $dir );
			$contents = '';
			foreach ( $bucket as $handle => $info ) {
				$body      = self::read_local_file( $info['path'] );
				if ( '' === $body ) {
					continue;
				}
				$body      = self::resolve_imports( $body, $info['url'], 0 );
				$body      = self::rewrite_url_paths( $body, $info['url'] );
				// No per-handle banner comment: it is a debugging aid with no
				// runtime value, and the minifier below preserves a comment
				// that opens a chunk, so each one survived as a `/* xspeed */`
				// stub that Lighthouse still counts as removable bytes.
				// The handle list lives in the cache key, not in the payload.
				$contents .= $body . "\n";
			}

			// Minify the JOIN, not just the parts (issue #331).
			//
			// Every input arrives here already minified, but the join itself
			// is not: a `/* xspeed: <handle> */` banner per part, a newline
			// after each, and whatever non-bang comments the sources kept.
			// Nothing downstream removes them — Minifier::rewrite_style()
			// deliberately skips anything under /cache/xspeed/ (re-minifying
			// our own output produced a second hash whose URL 404'd after a
			// purge), so this file was the end of the line and shipped as-is.
			//
			// The visible cost was small (~2 KB) but the scoring cost was not:
			// Lighthouse's `unminified-css` is near-binary, so one failing
			// file drops the audit to 0.5 — and the only offender on the page
			// was the artifact we generated, which then docked the site on
			// xSpeed Scan's own A2 check while the UI reported minify as on.
			$contents = self::minify_css_body( $contents );

			// Atomic write: file_put_contents with LOCK_EX so concurrent
			// renders don't race.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- WP_Filesystem requires admin context, unavailable on frontend.
			file_put_contents( $out_file, $contents, LOCK_EX );
		} else {
			self::mark_in_use( $out_file );
		}

		// Point the FIRST combined handle at the combined file and blank the
		// rest. This is deliberate — we do NOT enqueue a fresh
		// `xspeed-combined-css` handle, because WordPress would print it at
		// the tail of the queue, AFTER any non-combinable stylesheets
		// (media-query sheets like woocommerce-smallscreen, wc-blocks-*,
		// external fonts) that originally sat between/after the combined
		// handles. That reorders the cascade and breaks layout — e.g. the
		// WooCommerce/Astra grid + sidebar widths get overridden by rules
		// that should have lower priority. By reusing the first combined
		// handle's own queue slot for the combined <link>, the merged CSS
		// prints exactly where the earliest source stylesheet used to be,
		// preserving cascade order. (FBS-83114/83116)
		//
		// The remaining combined handles keep their registration + queue
		// membership (src blanked) so their wp_add_inline_style() data still
		// prints — WordPress only emits inline data for handles still in the
		// print queue, and some themes (Astra) attach that dynamic CSS on a
		// hook LATER than this priority-999 pass, so we can't harvest it now.
		// Dropping it is what made "combine CSS break the site".
		// The carrier is the FIRST bucket handle that WordPress hasn't already
		// printed. A block theme (Twenty Twenty-Five, etc.) prints some of its
		// per-block style handles BEFORE this priority-999 pass, marking them
		// `done`; pointing a done handle at the combined file emits no <link>
		// at all — the merged CSS silently vanishes and the whole site renders
		// unstyled. Skipping done handles guarantees the carrier still prints.
		// If every bucket handle is already done, register a dedicated combined
		// handle so the CSS is never lost (cascade tail is far better than no
		// styles). (FBS-83633)
		$done       = (array) $wp_styles->done;
		$carrier_set = false;
		foreach ( $bucket as $handle => $info ) {
			$reg = $wp_styles->registered[ $handle ] ?? null;
			if ( ! $reg instanceof \_WP_Dependency ) {
				continue;
			}
			if ( ! $carrier_set && ! in_array( $handle, $done, true ) ) {
				// Carry the combined file on this (not-yet-printed) handle's slot.
				$reg->src    = $out_url;
				$reg->ver    = $key;
				$reg->args   = $media;
				$carrier_set = true;
			} else {
				// Inline-only carrier: no <link>, keep inline CSS printable.
				$reg->src = false;
				$reg->ver = null;
			}
		}

		// Fallback: every bucket handle was already printed, so no carrier
		// could emit the combined <link>. Register + enqueue a dedicated
		// handle so the merged CSS still loads (appended at the tail — not
		// cascade-ideal, but infinitely better than a fully unstyled page).
		if ( ! $carrier_set ) {
			$combined_handle = 'xspeed-combined-css-' . $media;
			wp_register_style( $combined_handle, $out_url, array(), $key, $media );
			wp_enqueue_style( $combined_handle );
		}
	}

	/**
	 * Combine local enqueued scripts into one file.
	 */
	public static function combine_scripts(): void {
		global $wp_scripts;
		if ( ! $wp_scripts instanceof \WP_Scripts || empty( $wp_scripts->queue ) ) {
			return;
		}

		$bucket = self::collect_local_script_handles( $wp_scripts );
		if ( count( $bucket ) < 2 ) {
			return;
		}

		// Split by print group — head (0) and footer (1) get their own bundle.
		//
		// Carrying everything on ONE carrier meant the whole bucket inherited
		// that handle's placement, and the first handle in dependency order is
		// almost always jquery-core, which WordPress registers with no group
		// data at all — i.e. the HEAD. Every footer script absorbed alongside
		// it was therefore hoisted into the head and executed as one
		// synchronous blob before first paint: correctness-safer than the old
		// forced footer, but render-blocking, and the exact inverse of what a
		// speed plugin should ship. Bucketing by group is the same move
		// combine_styles() already makes for media types. (#289, PR #290 review)
		foreach ( self::split_by_group( $wp_scripts, $bucket ) as $group => $group_bucket ) {
			if ( count( $group_bucket ) < 2 ) {
				continue; // nothing to gain from combining a single file.
			}
			self::combine_script_group( $wp_scripts, (int) $group, $group_bucket );
		}
	}

	/**
	 * Partition a bucket into WordPress's print groups: 0 = head, 1 = footer.
	 *
	 * Reads $wp_scripts->groups, NOT the declared `extra['group']`, because
	 * the declared value is not authoritative: WordPress promotes a
	 * footer-registered dependency of a head script into the head. all_deps()
	 * populates the effective values and prints nothing, so resolving them
	 * here keeps our split consistent with what WordPress would have done on
	 * its own. (PR #290 review)
	 *
	 * @param array<string,array<mixed>> $bucket handle => info map.
	 * @return array<int,array<string,array<mixed>>> group => bucket.
	 */
	private static function split_by_group( \WP_Scripts $wp_scripts, array $bucket ): array {
		// Resolve effective groups for everything queued. Safe to call at
		// wp_enqueue_scripts: it walks dependencies and fills ->groups
		// without emitting a single tag.
		$wp_scripts->all_deps( $wp_scripts->queue, false );

		$groups = array();
		foreach ( $bucket as $handle => $info ) {
			$group = isset( $wp_scripts->groups[ $handle ] ) ? (int) $wp_scripts->groups[ $handle ] : 0;
			$groups[ $group ][ $handle ] = $info;
		}

		return $groups;
	}

	/**
	 * Build and attach one combined file for a single print group.
	 *
	 * @param int                        $group  0 = head, 1 = footer.
	 * @param array<string,array<mixed>> $bucket handle => info map for this group.
	 */
	private static function combine_script_group( \WP_Scripts $wp_scripts, int $group, array $bucket ): void {
		$key = self::cache_key( $bucket );
		$dir = self::cache_dir();
		// Group in the filename so a head and a footer bundle can never
		// collide on one cache key.
		$out_file = $dir . '/combined-g' . $group . '-' . $key . '.js';
		$out_url  = self::cache_url() . '/combined-g' . $group . '-' . $key . '.js';

		if ( ! file_exists( $out_file ) ) {
			self::ensure_dir( $dir );
			$contents = '';
			foreach ( $bucket as $handle => $info ) {
				$body = self::read_local_file( $info['path'] );
				if ( '' === $body ) {
					continue;
				}
				$contents .= "/* xspeed: $handle */\n" . $body . "\n;\n";
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- WP_Filesystem unavailable on frontend.
			file_put_contents( $out_file, $contents, LOCK_EX );
		} else {
			self::mark_in_use( $out_file );
		}

		self::attach_to_carrier( $wp_scripts, $bucket, $out_url, $key );
	}

	/**
	 * carrier handle => the handles whose src it now serves.
	 *
	 * @var array<string,string[]>
	 */
	private static $carriers = array();

	/** Whether the print-time sweep is hooked. */
	private static $late_sweep_hooked = false;

	/** Payload fingerprints already re-homed, so a second sweep is a no-op. */
	private static $rehomed = array();

	/**
	 * Point the combined file at the FIRST not-yet-printed bucket handle and
	 * blank the rest, instead of dequeuing everything and appending a fresh
	 * handle.
	 *
	 * The old approach registered `xspeed-combined-js` with `array()` deps and
	 * a hard-coded `$in_footer = true`, then dequeued the originals. Three
	 * things went wrong with that:
	 *
	 *  1. No dependency edges. The bundle declared no relationship to the
	 *     handles that stayed in the queue (external, async/deferred,
	 *     localized), so WordPress was free to print it in any order relative
	 *     to them.
	 *  2. Forced to the footer. Every head script in the bucket was relocated
	 *     behind any inline <script> in the head or body that expected it.
	 *  3. dequeue() leaves a handle REGISTERED and re-enqueueable, so anything
	 *     enqueuing it later printed it a second time — while its code was
	 *     already inside the bundle.
	 *
	 * Together those produce the reported break: `jquery-core` gets absorbed
	 * into a footer bundle, something still prints `jquery.min.js` in the
	 * head, and the second jQuery replaces the first — discarding every plugin
	 * the bundle had attached to it. `jQuery.fn.waypoint` becomes undefined
	 * even though the library loaded, and Elementor's module layer initialises
	 * twice. Measured on a fixture of that stack: five handles present both
	 * inside the bundle and as their own tag. (#289)
	 *
	 * Carrying the file on an existing handle fixes all three at once — the
	 * merged script keeps that handle's queue position, its dependency edges
	 * and its head/footer placement, and nothing is dequeued so nothing can be
	 * re-enqueued behind our back. This is what combine_styles() has always
	 * done; the JS path never got it.
	 *
	 * @param array<string,array<mixed>> $bucket   handle => info map, in dependency order.
	 * @param string                     $out_url  URL of the combined file.
	 * @param string                     $key      Cache key, used as the version.
	 */
	private static function attach_to_carrier( \WP_Scripts $wp_scripts, array $bucket, string $out_url, string $key ): void {
		$done        = (array) $wp_scripts->done;
		$carrier_set = false;
		$carrier     = '';
		$absorbed    = array();

		foreach ( $bucket as $handle => $info ) {
			$reg = $wp_scripts->registered[ $handle ] ?? null;
			if ( ! $reg instanceof \_WP_Dependency ) {
				continue;
			}

			if ( ! $carrier_set && ! in_array( $handle, $done, true ) ) {
				// Carry the bundle on this handle's slot. Its deps, its queue
				// position and its in_footer flag all stay exactly as the
				// enqueuing plugin set them.
				$reg->src    = $out_url;
				$reg->ver    = $key;
				$carrier     = $handle;
				$carrier_set = true;
				continue;
			}

			// Every other absorbed handle keeps its registration and its queue
			// membership — only the src is blanked, so no second <script src>
			// is emitted while any wp_add_inline_script() / wp_localize_script()
			// data attached to it still prints. Dequeuing instead would drop
			// that data on the floor and leave the handle re-enqueueable.
			$reg->src   = false;
			$reg->ver   = null;
			$absorbed[] = $handle;
		}

		// No carrier means every handle in this bucket had ALREADY printed —
		// so its code has already executed in the browser.
		//
		// Emitting the bundle anyway would re-run all of it, including a
		// second jQuery: precisely the double-execution this method exists to
		// prevent, and deterministic rather than occasional. The CSS path can
		// afford its equivalent fallback because a duplicate stylesheet is
		// merely redundant; a duplicate script re-initialises everything.
		//
		// So we do nothing: the page keeps the individual files it already
		// printed — no combining benefit for this bucket, but correct.
		// (PR #290 review)
		if ( ! $carrier_set ) {
			return;
		}

		// Remember what this carrier swallowed, so a payload attached to an
		// absorbed handle AFTER we ran can still be re-homed onto the bundle
		// at print time. See sweep_late_inline() for why that is needed.
		self::$carriers[ $carrier ] = $absorbed;

		if ( ! self::$late_sweep_hooked ) {
			self::$late_sweep_hooked = true;
			// Priority 0 on both print hooks: ahead of WP emitting the queue,
			// and ahead of Defer_Js rewriting the tags it is about to print.
			add_action( 'wp_print_scripts', array( __CLASS__, 'sweep_late_inline' ), 0 );
			add_action( 'wp_print_footer_scripts', array( __CLASS__, 'sweep_late_inline' ), 0 );
		}
	}

	/**
	 * Re-home inline payloads that arrived after the bundle was built.
	 *
	 * combine_scripts() runs on wp_enqueue_scripts, and combinable_script_info()
	 * refuses any handle that ALREADY carries inline data — so at that moment a
	 * page builder has attached nothing. Elementor adds elementorFrontendConfig
	 * from Frontend::wp_footer(), thousands of hook-ticks later, onto a handle
	 * whose src we have since blanked.
	 *
	 * That payload is not lost: blanking `src` (rather than dequeuing) leaves
	 * the handle registered, so WordPress still prints it. But it prints at the
	 * ABSORBED handle's queue position, which is behind the carrier — and a
	 * `before` payload exists precisely to run ahead of the code that reads it.
	 * The config therefore landed after the bundle that consumes it, and the
	 * script initialised against an undefined global.
	 *
	 * Moving a late `before` payload onto the carrier restores that contract.
	 * `after` payloads are left alone: their position behind the code is
	 * already correct wherever they print.
	 *
	 * Idempotent by fingerprint, so running on both print hooks is safe. (#246)
	 */
	public static function sweep_late_inline(): void {
		global $wp_scripts;
		if ( ! $wp_scripts instanceof \WP_Scripts || empty( self::$carriers ) ) {
			return;
		}

		foreach ( self::$carriers as $carrier => $absorbed ) {
			if ( ! isset( $wp_scripts->registered[ $carrier ] ) ) {
				continue;
			}
			foreach ( $absorbed as $handle ) {
				$reg = $wp_scripts->registered[ $handle ] ?? null;
				if ( ! $reg instanceof \_WP_Dependency || empty( $reg->extra['before'] ) ) {
					continue;
				}
				if ( ! is_array( $reg->extra['before'] ) ) {
					continue;
				}

				foreach ( $reg->extra['before'] as $payload ) {
					// WP seeds `before` with a leading empty string; skip it
					// rather than emitting a blank <script>.
					if ( ! is_string( $payload ) || '' === trim( $payload ) ) {
						continue;
					}
					$fingerprint = md5( $payload );
					if ( isset( self::$rehomed[ $fingerprint ] ) ) {
						continue;
					}
					self::$rehomed[ $fingerprint ] = true;
					wp_add_inline_script( $carrier, $payload, 'before' );
				}

				// Clear the source so the payload is not ALSO printed at the
				// absorbed handle's own position, after the bundle.
				unset( $reg->extra['before'] );
			}
		}
	}

	/**
	 * Walk WP_Styles->queue, return the handles whose src is a local file we
	 * can safely combine, grouped BY media type so each media gets its own
	 * combined file. Shape:
	 *   [ media => [ handle => [ 'url' => …, 'path' => …, 'mtime' => int, 'src' => … ] ] ].
	 * '' and 'screen' media fold into the 'all' group.
	 */
	private static function collect_local_handles( \WP_Styles $wp_styles ): array {
		$groups = array();
		foreach ( $wp_styles->queue as $handle ) {
			if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
				continue;
			}
			$reg = $wp_styles->registered[ $handle ];
			$src = (string) ( $reg->src ?? '' );
			if ( '' === $src ) {
				continue;
			}
			// Leave WordPress core block styles alone. Block themes (Twenty
			// Twenty-*, and any FSE theme) load per-block CSS conditionally and
			// print/track these handles through their own separated-styles
			// pipeline, often BEFORE this pass. Pulling them into a combined
			// file fights that pipeline and leaves the page unstyled. These are
			// already tiny + conditionally loaded, so there's little to gain.
			// Matches `wp-block-*` handles and any src under wp-includes/blocks/
			// or the block-library dist dir. (FBS-83633)
			if (
				0 === strpos( $handle, 'wp-block-' )
				|| false !== strpos( $src, '/wp-includes/blocks/' )
				|| false !== strpos( $src, '/block-library/' )
			) {
				continue;
			}
			$abs = self::to_absolute_url( $src );
			$info = self::local_info( $abs );
			if ( null === $info ) {
				continue; // external or unresolvable — leave in queue.
			}
			// Bucket by media type. '' and 'screen' fold into 'all' (both mean
			// "the on-screen document"); every other media value (print,
			// max-width queries, …) gets its own group so we can emit one
			// combined file per media with the right attribute — instead of
			// dropping non-'all' sheets and starving the combinable bucket on
			// builder sites. (FBS-83653)
			$media = (string) ( $reg->args ?? 'all' );
			if ( '' === $media || 'screen' === $media ) {
				$media = 'all';
			}
			$groups[ $media ][ $handle ] = $info + array( 'src' => $src );
		}
		return $groups;
	}

	private static function collect_local_script_handles( \WP_Scripts $wp_scripts ): array {
		// `queue` holds only what was explicitly enqueued — never the
		// dependencies WP resolves at print time. Walking it alone combined
		// `admin-bar` while silently dropping its `hoverintent-js` dep, so the
		// bundle called a function that was never in it:
		// "hoverintent is not a function", and every admin-bar hover menu died
		// for logged-in visitors. Expand deps first, then emit in dependency
		// order. (#204)
		$expanded = self::expand_with_deps( $wp_scripts );

		$out = array();
		foreach ( $expanded as $handle ) {
			$info = self::combinable_script_info( $wp_scripts, $handle );
			if ( null === $info ) {
				continue;
			}
			$out[ $handle ] = $info;
		}

		// A script whose dependency could NOT be combined (inline data,
		// async/defer, external CDN) has to stay in the queue itself —
		// otherwise combining it drops the same dependency a second way.
		return self::drop_dependents_of_missing( $wp_scripts, $out );
	}

	/**
	 * The queue plus every registered dependency it pulls in, in dependency
	 * order (a handle always follows everything it depends on).
	 *
	 * Depth-first post-order over `WP_Scripts::$registered[$handle]->deps`.
	 * `$seen` guards a malformed cyclic registration — a cycle can't be
	 * ordered, so the handle is emitted once and the walk unwinds rather than
	 * recursing forever. (#204)
	 *
	 * @param \WP_Scripts $wp_scripts Script registry.
	 * @return string[] Handles, dependencies first.
	 */
	private static function expand_with_deps( \WP_Scripts $wp_scripts ): array {
		$ordered = array();
		$state   = array(); // handle => 1 visiting, 2 done.

		$visit = static function ( string $handle ) use ( &$visit, &$ordered, &$state, $wp_scripts ): void {
			if ( isset( $state[ $handle ] ) ) {
				return; // already emitted, or we're inside a cycle.
			}
			$state[ $handle ] = 1;
			if ( isset( $wp_scripts->registered[ $handle ] ) ) {
				foreach ( (array) $wp_scripts->registered[ $handle ]->deps as $dep ) {
					$visit( (string) $dep );
				}
			}
			$state[ $handle ] = 2;
			$ordered[]        = $handle;
		};

		foreach ( $wp_scripts->queue as $handle ) {
			$visit( (string) $handle );
		}

		return $ordered;
	}

	/**
	 * Info for a handle that can safely go in the combined bundle, or null
	 * when it must be left in the queue.
	 *
	 * @param \WP_Scripts $wp_scripts Script registry.
	 * @param string      $handle     Script handle.
	 * @return array<string,mixed>|null
	 */
	private static function combinable_script_info( \WP_Scripts $wp_scripts, string $handle ): ?array {
		if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
			return null;
		}
		$reg = $wp_scripts->registered[ $handle ];
		$src = (string) ( $reg->src ?? '' );
		if ( '' === $src ) {
			// A dependency-only alias (e.g. `jquery`) carries no file of its
			// own; nothing to concatenate, and its own deps were already
			// walked, so it isn't a blocker.
			return null;
		}
		// Skip scripts that carry inline-after data (they expect
		// to run at their original spot).
		if ( ! empty( $reg->extra['after'] ) || ! empty( $reg->extra['before'] ) || ! empty( $reg->extra['data'] ) ) {
			return null;
		}
		// Skip async / defer-via-strategy.
		$strategy = $reg->extra['strategy'] ?? '';
		if ( 'async' === $strategy || 'defer' === $strategy ) {
			return null;
		}
		$abs  = self::to_absolute_url( $src );
		$info = self::local_info( $abs );
		if ( null === $info ) {
			return null;
		}
		return $info + array( 'src' => $src );
	}

	/**
	 * Remove any handle whose dependency isn't in the bucket, transitively.
	 *
	 * Combining a script but not its dependency is exactly the #204 failure:
	 * the bundle runs code whose prerequisite never loaded. When a dep can't
	 * be combined — it carries inline data, is async/defer, or lives on a CDN
	 * — the safe move is to leave the dependent in the queue too, where WP
	 * prints both in the right order.
	 *
	 * A handle with no `src` (a pure alias like `jquery`) is not a blocker:
	 * it contributes no code, and its own deps were expanded separately.
	 *
	 * @param \WP_Scripts          $wp_scripts Script registry.
	 * @param array<string,mixed>  $bucket     handle => info, dependency-ordered.
	 * @return array<string,mixed> Filtered bucket, order preserved.
	 */
	private static function drop_dependents_of_missing( \WP_Scripts $wp_scripts, array $bucket ): array {
		// Iterate to a fixed point: dropping A can orphan B that depends on A.
		do {
			$dropped = false;
			foreach ( $bucket as $handle => $info ) {
				if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
					continue;
				}
				foreach ( (array) $wp_scripts->registered[ $handle ]->deps as $dep ) {
					$dep = (string) $dep;
					if ( isset( $bucket[ $dep ] ) ) {
						continue; // dep is coming along.
					}
					$dep_reg = $wp_scripts->registered[ $dep ] ?? null;
					if ( $dep_reg && '' === (string) ( $dep_reg->src ?? '' ) ) {
						continue; // alias handle, contributes no code.
					}
					unset( $bucket[ $handle ] );
					$dropped = true;
					break;
				}
			}
		} while ( $dropped );

		return $bucket;
	}

	/**
	 * Convert a possibly-relative `src` into an absolute URL.
	 *
	 * Public because Css_Combine_Buffer resolves the same URLs from parsed
	 * HTML rather than from the enqueue queue; the logic is identical and a
	 * second copy would drift. (#195)
	 */
	public static function to_absolute_url( string $src ): string {
		if ( '' === $src ) {
			return '';
		}
		if ( 0 === strpos( $src, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $src;
		}
		if ( 0 === strpos( $src, '/' ) ) {
			$home = home_url();
			$home = (string) preg_replace( '#/$#', '', $home );
			return $home . $src;
		}
		return $src;
	}

	/**
	 * Resolve an absolute URL to a local filesystem path + mtime, or
	 * return null if the URL isn't on this site / outside web root.
	 *
	 * @return array{url:string,path:string,mtime:int}|null
	 */
	public static function local_info( string $url ): ?array {
		if ( '' === $url ) {
			return null;
		}
		$home = home_url();
		if ( 0 !== strpos( $url, $home ) ) {
			return null;
		}
		// Strip query / fragment for filesystem lookup; keep them in
		// the URL we hash against.
		$clean = strtok( $url, '?' );
		if ( ! is_string( $clean ) ) {
			return null;
		}
		$path = ABSPATH . ltrim( str_replace( $home, '', $clean ), '/' );
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		return array(
			'url'   => $url,
			'path'  => $path,
			'mtime' => (int) filemtime( $path ),
		);
	}

	/**
	 * Minify a concatenated CSS body in memory (issue #331).
	 *
	 * In memory on purpose: the parts have already had their `url(...)`
	 * references rewritten by rewrite_url_paths() against each source's own
	 * location, so handing the text to the file-based minifier — which
	 * rebases relative URLs against the target path — would rewrite them a
	 * second time and break every font and background image in the bundle.
	 *
	 * Fails open. A minifier exception, or output that came back empty or
	 * implausibly short, returns the original text: shipping a slightly
	 * larger stylesheet is a rounding error, shipping a truncated one
	 * unstyles the site. Same reasoning as Minifier::minify_file()'s own
	 * guard against mid-template-literal truncation.
	 */
	public static function minify_css_body( string $css ): string {
		if ( '' === trim( $css ) || ! class_exists( '\\MatthiasMullie\\Minify\\CSS' ) ) {
			return $css;
		}

		try {
			$minifier = new \MatthiasMullie\Minify\CSS();
			$minifier->add( $css );
			$out = (string) $minifier->minify();
		} catch ( \Throwable $e ) {
			return $css;
		}

		// A minifier that returns nothing, or that claims a >95% saving on
		// already-minified inputs, has failed rather than succeeded.
		if ( '' === trim( $out ) || strlen( $out ) < ( strlen( $css ) / 20 ) ) {
			return $css;
		}

		return $out;
	}

	private static function cache_key( array $bucket ): string {
		$signature = array();
		foreach ( $bucket as $handle => $info ) {
			$signature[ $handle ] = array( $info['src'] ?? '', $info['mtime'] ?? 0 );
		}
		return md5( wp_json_encode( $signature ) );
	}

	private static function read_local_file( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem unavailable on frontend; we already validated existence + readability.
		$body = file_get_contents( $path );
		return is_string( $body ) ? $body : '';
	}

	/**
	 * Recursively inline `@import url(...)` (and `@import "...";`)
	 * statements. Cycles detected via depth limit; cross-origin imports
	 * are left alone.
	 */
	public static function resolve_imports( string $css, string $base_url, int $depth ): string {
		if ( $depth > self::MAX_IMPORT_DEPTH ) {
			return $css;
		}
		return (string) preg_replace_callback(
			'#@import\s+(?:url\s*\(\s*)?["\']?([^"\')]+)["\']?\s*\)?\s*([^;]*);#i',
			static function ( $m ) use ( $base_url, $depth ) {
				$target = trim( (string) $m[1] );
				$media  = trim( (string) $m[2] );
				$abs    = self::resolve_relative( $target, $base_url );
				$info   = self::local_info( $abs );
				if ( null === $info ) {
					return $m[0]; // external or unresolvable; leave as-is.
				}
				$body = self::read_local_file( $info['path'] );
				if ( '' === $body ) {
					return $m[0];
				}
				$body = self::rewrite_url_paths( $body, $info['url'] );
				$body = self::resolve_imports( $body, $info['url'], $depth + 1 );
				if ( '' !== $media ) {
					return '@media ' . $media . " {\n" . $body . "\n}\n";
				}
				return $body;
			},
			$css
		);
	}

	/**
	 * Rewrite every `url(...)` whose argument is a relative path so it
	 * becomes absolute (resolved against the source file's URL). The
	 * combined file lives at a different location, so relative paths
	 * would otherwise break.
	 *
	 * Skips: absolute URLs (http://, https://, //), data: URIs,
	 * `#fragment-only`, blob:, javascript: (which shouldn't appear in
	 * CSS but won't crash).
	 */
	public static function rewrite_url_paths( string $css, string $base_url ): string {
		return (string) preg_replace_callback(
			'#url\(\s*(["\']?)([^"\')]+)\1\s*\)#i',
			static function ( $m ) use ( $base_url ) {
				$quote = $m[1];
				$raw   = trim( (string) $m[2] );
				if ( '' === $raw ) {
					return $m[0];
				}
				if (
					0 === strpos( $raw, 'data:' )
					|| 0 === strpos( $raw, 'blob:' )
					|| 0 === strpos( $raw, '#' )
					|| 0 === strpos( $raw, 'http://' )
					|| 0 === strpos( $raw, 'https://' )
					|| 0 === strpos( $raw, '//' )
				) {
					return $m[0];
				}
				$abs = self::resolve_relative( $raw, $base_url );
				return 'url(' . $quote . $abs . $quote . ')';
			},
			$css
		);
	}

	/**
	 * Resolve a relative URL (no scheme, no leading /) against a base
	 * URL. Public so tests can exercise it directly.
	 */
	public static function resolve_relative( string $target, string $base_url ): string {
		// Order matters — '//' is a prefix of '/' so the protocol-relative
		// check must happen BEFORE the leading-slash anchor.
		if ( 0 === strpos( $target, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $target;
		}
		if ( 0 === strpos( $target, '/' ) ) {
			$parts = wp_parse_url( $base_url );
			if ( ! is_array( $parts ) ) {
				return $target;
			}
			$origin = ( $parts['scheme'] ?? 'http' ) . '://' . ( $parts['host'] ?? '' );
			if ( isset( $parts['port'] ) ) {
				$origin .= ':' . $parts['port'];
			}
			return $origin . $target;
		}
		if ( 0 === strpos( $target, 'http://' ) || 0 === strpos( $target, 'https://' ) ) {
			return $target;
		}
		// Relative. Strip filename from base, resolve.
		$base_path = (string) wp_parse_url( $base_url, PHP_URL_PATH );
		$base_dir  = rtrim( str_replace( basename( $base_path ), '', $base_path ), '/' );
		$parts     = wp_parse_url( $base_url );
		$origin    = ( $parts['scheme'] ?? 'http' ) . '://' . ( $parts['host'] ?? '' );
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}
		// Collapse ../
		$joined  = $base_dir . '/' . $target;
		$segments = array();
		foreach ( explode( '/', $joined ) as $seg ) {
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $seg;
		}
		return $origin . '/' . implode( '/', $segments );
	}

	private static function ensure_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$silence = $dir . '/index.php';
		if ( ! file_exists( $silence ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- bootstrap-time helper, WP_Filesystem unavailable.
			file_put_contents( $silence, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Record that a combined file is STILL IN USE, by refreshing its mtime.
	 *
	 * The combiner only writes a file when it does not already exist, so a
	 * stylesheet in continuous use kept its original mtime forever. Cache GC
	 * collects `min/` on a 30-day max-age measured from mtime, so it read a
	 * file served on every page load as "untouched for a month" and deleted
	 * it — leaving every cached page pointing at a 404. (#190)
	 *
	 * This is the cheap half of the fix: it keeps a live asset LOOKING young,
	 * which is what the age heuristic needed all along. The real guarantee is
	 * `Cache_GC`'s reachability check — an asset a cached page references is
	 * never collected whatever its age — because mtime cannot help an asset
	 * whose page is a static HIT that never runs PHP.
	 *
	 * Rate-limited to once a day per file: this runs on every render, and a
	 * touch() per request would be pointless filesystem traffic when the
	 * threshold is measured in days.
	 */
	private static function mark_in_use( string $file ): void {
		$now  = time();
		$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a racing purge can unlink between the exists check and here; false is handled.
		if ( false === $mtime || ( $now - $mtime ) < DAY_IN_SECONDS ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- refreshing our own cache file's mtime; WP_Filesystem has no touch() and is unavailable on the frontend.
		@touch( $file, $now ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort liveness hint; a failure is not worth an error on a page render.
	}
}
