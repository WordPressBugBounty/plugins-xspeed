<?php
/**
 * Resource Hints processor — pure HTML transformer for resource hints.
 *
 * Given a fully-rendered page and the Preload module's options, it:
 *   1. Ranks every eligible <img> by the largest declared size it can read —
 *      width×height attributes, else the widest srcset candidate — boosted by
 *      the author's own priority signals (fetchpriority="high", an explicit
 *      loading="eager") and lightly weighted by document position, and emits
 *      a <link rel="preload" as="image" fetchpriority="high"> for the top N
 *      in the <head>, carrying srcset/sizes as imagesrcset/imagesizes so the
 *      browser can pick the right candidate — then adds fetchpriority="high"
 *      to the <img> itself. Ranking by size rather than document position:
 *      the first images on a real page are usually header chrome, not the
 *      hero (#96). Images inside <footer>/<nav>/<aside>, images the theme
 *      explicitly lazy-loads, and tiny images never compete (FBS-84576).
 *   2. Emits <link rel="preconnect"> for detected web-font hosts
 *      (fonts.googleapis.com + fonts.gstatic.com) and any user-supplied
 *      hosts, deduped.
 *
 * Kept as a pure static so the test suite can drive it without booting the
 * module or WordPress hooks (mirrors Lazy_Loader::process_html). All output
 * is escaped at build time; callers echo the result verbatim into the body.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Resource_Hints_Processor {

	/**
	 * Transform the page HTML, injecting preload + preconnect hints.
	 *
	 * @param string               $html Fully-rendered page HTML.
	 * @param array<string,mixed>  $opts Preload module settings.
	 * @return string Rewritten HTML (unchanged when disabled or no match).
	 */
	public static function process( string $html, array $opts ): string {
		if ( empty( $opts['enabled'] ) ) {
			return $html;
		}

		// Only touch real HTML documents. A JSON/XML/feed body that happens
		// to reach here should pass through untouched.
		if ( false === stripos( $html, '<html' ) && false === stripos( $html, '<body' ) && false === stripos( $html, '<head' ) ) {
			return $html;
		}

		$hints = '';

		if ( ! empty( $opts['preconnect'] ) || ! empty( $opts['preconnect_hosts'] ) ) {
			$hints .= self::build_preconnect( $html, (array) ( $opts['preconnect_hosts'] ?? array() ), ! empty( $opts['preconnect'] ) );
		}

		if ( ! empty( $opts['lcp_preload'] ) ) {
			$count      = max( 0, (int) ( $opts['lcp_image_count'] ?? 1 ) );
			$exclusions = array_filter( array_map( 'strval', (array) ( $opts['lcp_exclusions'] ?? array() ) ) );
			[ $html, $preload ] = self::build_lcp_preload( $html, $count, $exclusions );
			$hints .= $preload;
		}

		// The manual list runs AFTER the automatic pick so it can deduplicate
		// against it: a URL both name should carry ONE hint, not two. It exists
		// for the image the detector cannot see — most often a hero section's
		// CSS background-image, where the LCP element is a <div> with none of
		// the width/height/fetchpriority signals the scorer reads. On the site
		// that surfaced this, 3.3s of a 5.3s mobile LCP was pure discovery
		// delay for exactly such an image. (FBS-84578)
		$manual = array_filter( array_map( 'strval', (array) ( $opts['preload_images'] ?? array() ) ) );
		if ( ! empty( $manual ) ) {
			$hints .= self::build_manual_image_preloads( $manual, $hints );
		}

		// Full-page eager promotion for Lazy-excluded heroes (FBS-83553 H2). The
		// Lazy module only filters the_content/thumbnail/avatar/widget, so a
		// theme/builder hero printed OUTSIDE those keeps WP core's
		// loading="lazy". This pass runs over the whole document, so it can reach
		// those heroes: for any <img> matching an exclusion pattern, strip lazy +
		// set fetchpriority=high. NOTE: this mutates $html even when no <head>
		// hints are emitted, so it must apply before the empty-$hints early-out.
		$eager = array_filter( array_map( 'strval', (array) ( $opts['eager_excluded_images'] ?? array() ) ) );
		if ( ! empty( $eager ) ) {
			$html = self::promote_excluded_images( $html, $eager );
		}

		if ( '' === $hints ) {
			return $html;
		}

		return self::inject_into_head( $html, $hints );
	}

	/**
	 * How many entries of the manual preload list are honoured. Preloading
	 * competes with the page for its top network priority — a long list
	 * inverts the benefit, so the cap is deliberately small.
	 */
	private const MAX_MANUAL_PRELOADS = 3;

	/**
	 * One `<link rel="preload" as="image">` per manual list entry.
	 *
	 * Entries are full URLs or site-relative paths. Anything that is neither
	 * (a data: URI, a bare word) is skipped rather than guessed at, and a URL
	 * the automatic pick already emitted is skipped too — one hint per image,
	 * whoever names it first.
	 *
	 * @param array<int,string> $urls           The configured list.
	 * @param string            $existing_hints Hints already built this request.
	 */
	private static function build_manual_image_preloads( array $urls, string $existing_hints ): string {
		/**
		 * Filter the manual image-preload list before it is emitted.
		 *
		 * @param array<int,string> $urls Configured URLs, in panel order.
		 */
		$urls = (array) apply_filters( 'xspeed_preload_images', $urls );

		$out  = '';
		$seen = array();
		foreach ( $urls as $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				continue;
			}
			// A full URL or a site-relative path; nothing else is guessable.
			$is_absolute = (bool) preg_match( '#^https?://#i', $url );
			$is_relative = '' !== $url && '/' === $url[0] && ( strlen( $url ) < 2 || '/' !== $url[1] );
			if ( ! $is_absolute && ! $is_relative ) {
				continue;
			}
			$href = esc_url( $url );
			if ( '' === $href || isset( $seen[ $href ] ) || false !== strpos( $existing_hints, 'href="' . $href . '"' ) ) {
				continue;
			}
			$seen[ $href ] = true;
			$out          .= '<link rel="preload" as="image" href="' . $href . '" fetchpriority="high">';
			if ( count( $seen ) >= self::MAX_MANUAL_PRELOADS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Strip core `loading="lazy"` and add `fetchpriority="high"` +
	 * `decoding="async"` on every <img> whose tag matches one of the given
	 * exclusion substrings. Mirrors what Lazy_Loader does for an excluded image
	 * inside the_content, but page-wide so heroes outside it are covered too.
	 * (FBS-83553 H2)
	 *
	 * @param string   $html       Full page HTML.
	 * @param string[] $exclusions Substring patterns identifying above-the-fold heroes.
	 */
	private static function promote_excluded_images( string $html, array $exclusions ): string {
		return (string) preg_replace_callback(
			'#<img\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>#i',
			static function ( array $m ) use ( $exclusions ) {
				$tag = $m[0];
				foreach ( $exclusions as $needle ) {
					if ( '' !== $needle && false !== stripos( $tag, $needle ) ) {
						$tag = (string) preg_replace( '#\s*\bloading=(["\'])\s*lazy\s*\1#i', '', $tag );
						$tag = self::set_fetchpriority( $tag );
						if ( ! preg_match( '#\bdecoding=#i', $tag ) ) {
							$tag = (string) preg_replace( '#<img\b#i', '<img decoding="async"', $tag, 1 );
						}
						return $tag;
					}
				}
				return $tag;
			},
			$html
		);
	}

	/**
	 * Build preconnect <link>s for detected font hosts + user hosts.
	 * Deduped and idempotent (skips hosts already preconnected in $html).
	 *
	 * @param string   $html      Page HTML (scanned for font stylesheets).
	 * @param string[] $user_hosts Extra hosts to always preconnect.
	 * @param bool     $auto_fonts Whether to auto-add font hosts.
	 * @return string preconnect <link> markup.
	 */
	private static function build_preconnect( string $html, array $user_hosts, bool $auto_fonts ): string {
		$hosts = array();

		if ( $auto_fonts && false !== stripos( $html, 'fonts.googleapis.com' ) ) {
			// The stylesheet is on googleapis; the font files stream from
			// gstatic — preconnect both, gstatic needs crossorigin.
			$hosts['https://fonts.googleapis.com'] = false;
			$hosts['https://fonts.gstatic.com']    = true;
		}

		foreach ( $user_hosts as $host ) {
			$host = trim( (string) $host );
			if ( '' === $host ) {
				continue;
			}
			// Cross-origin hosts get crossorigin by default; harmless for
			// same-scheme document hosts and required for fonts/fetch.
			$hosts[ untrailingslashit( $host ) ] = true;
		}

		$out = '';
		foreach ( $hosts as $host => $crossorigin ) {
			// Idempotency: skip a host already preconnected in the document.
			if ( preg_match( '#rel=["\']preconnect["\'][^>]*' . preg_quote( $host, '#' ) . '#i', $html )
				|| preg_match( '#' . preg_quote( $host, '#' ) . '[^>]*rel=["\']preconnect["\']#i', $html ) ) {
				continue;
			}
			$out .= sprintf(
				'<link rel="preconnect" href="%s"%s>' . "\n",
				esc_url( $host ),
				$crossorigin ? ' crossorigin' : ''
			);
		}

		return $out;
	}

	/**
	 * Find the first $count eligible <img> tags, add fetchpriority="high"
	 * to each, and return the matching <link rel=preload as=image> markup.
	 *
	 * @param string   $html       Page HTML.
	 * @param int      $count      How many top images to preload.
	 * @param string[] $exclusions Substring patterns that exempt an <img>.
	 * @return array{0:string,1:string} [rewritten html, preload markup]
	 */
	private static function build_lcp_preload( string $html, int $count, array $exclusions ): array {
		if ( $count < 1 ) {
			return array( $html, '' );
		}

		$preload = '';

		// Snapshot of already-present preload markup, for idempotency: a second
		// pass (e.g. cache-off ob_start over an already-processed body) must not
		// re-emit a <link> for an image we preloaded before.
		$existing = $html;

		// PASS 1 — collect every eligible <img> and score it.
		//
		// This used to preload the first N eligible tags in DOCUMENT ORDER.
		// Position is not a proxy for rendered size: on real pages the first
		// images are header chrome, breadcrumbs or badge rows, and the actual
		// LCP element is a hero further down. Preloading the wrong image gains
		// nothing — it just adds a high-priority request competing with the
		// one that matters, and the feature reported success either way. The
		// marker list and size gate were heuristics layered on top of the
		// wrong primitive rather than replacing it. (#96)
		$candidates  = array();
		$skip_ranges = self::chrome_container_ranges( $html );
		if ( preg_match_all( '#<img\b[^>]*>#i', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $index => $match ) {
				[ $tag, $offset ] = $match;

				// Skip anything the user excluded.
				$excluded = false;
				foreach ( $exclusions as $needle ) {
					if ( '' !== $needle && false !== stripos( $tag, $needle ) ) {
						$excluded = true;
						break;
					}
				}
				if ( $excluded ) {
					continue;
				}

				// An image inside <footer>/<nav>/<aside> is site chrome by
				// construction — a footer brand strip or FAQ illustration can
				// never be the LCP element, whatever size it declares. On the
				// FBS-84576 repro these decoys outranked the real hero three
				// times on one layout.
				if ( self::offset_in_ranges( $offset, $skip_ranges ) ) {
					continue;
				}

				// An image the theme explicitly lazy-loads is never the
				// intended LCP — the author has already said "this can wait".
				// Preloading it would contradict the markup and steal
				// bandwidth from the image that matters. (FBS-84576)
				if ( 'lazy' === strtolower( self::attr( $tag, 'loading' ) ) ) {
					continue;
				}

				// Resolve the EFFECTIVE image URL. Page builders + JS lazy
				// loaders park a placeholder (a data: URI or a 1px spacer) in
				// `src` and the real URL in `data-src`, so the hero the browser
				// actually paints is behind data-src. (FBS-83553 H1)
				[ $src, $srcset, $sizes ] = self::effective_image_src( $tag );
				if ( '' === $src ) {
					continue; // no real URL (pure data-URI spacer, no data-src).
				}

				// Chrome markers / explicit opt-out / obviously-tiny images
				// never compete. (FBS-83553 H1 "logo before hero".)
				if ( self::looks_too_small( $tag ) ) {
					continue;
				}

				$candidates[] = array(
					'tag'    => $tag,
					'src'    => $src,
					'srcset' => $srcset,
					'sizes'  => $sizes,
					'score'  => self::weighted_score( self::lcp_score( $tag, $srcset ), $tag, $index ),
					'order'  => $index,
				);
			}
		}

		// PASS 1b — the same for CSS background images.
		//
		// On a page builder the hero is usually a background-image on the
		// section, not an <img>, so an <img>-only candidate set never contained
		// the element that actually paints as LCP. It preloaded whatever <img>
		// happened to be there — measured at 0ms against the feature switched
		// off, while spending a high-priority fetch on the critical path — or,
		// on a page with no <img> at all, emitted nothing. (#247)
		foreach ( self::background_candidates( $html, $exclusions, $skip_ranges ) as $bg ) {
			$candidates[] = $bg;
		}

		if ( empty( $candidates ) ) {
			return array( $html, '' );
		}

		// Rank by score, biggest first. Document order breaks ties, so two
		// equally-sized images (or two of unknown size) keep the previous
		// first-wins behaviour — the change only matters when we can actually
		// tell one is larger.
		usort(
			$candidates,
			static function ( array $a, array $b ) {
				if ( $a['score'] === $b['score'] ) {
					return $a['order'] <=> $b['order'];
				}
				return $b['score'] <=> $a['score'];
			}
		);

		$winners = array_slice( $candidates, 0, $count );

		// PASS 2 — emit the preload links and promote the winning tags.
		$chosen = array();
		foreach ( $winners as $w ) {
			// Idempotency: if this src is already the target of a
			// rel="preload" as="image" link, still promote the tag but don't
			// emit a duplicate <link>.
			$already = (bool) preg_match(
				'#rel=["\']preload["\'][^>]*as=["\']image["\'][^>]*' . preg_quote( $w['src'], '#' ) . '#i',
				$existing
			);
			if ( ! $already ) {
				$preload .= self::preload_link( $w['src'], $w['srcset'], $w['sizes'] );
			}
			// Only <img> winners are promoted in PASS 2 — there is no
			// fetchpriority/loading attribute to fix on a background element,
			// and its `order` is offset past every <img> index precisely so it
			// can never select one for rewriting.
			if ( empty( $w['background'] ) ) {
				$chosen[ $w['order'] ] = true;
			}
		}

		// Rewrite only the winning tags. Counting occurrences rather than
		// matching on tag text, because the same markup can legitimately
		// appear more than once on a page and only the ranked instance should
		// be promoted.
		$seen = -1;
		$html = preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( array $m ) use ( &$seen, $chosen ) {
				++$seen;
				if ( ! isset( $chosen[ $seen ] ) ) {
					return $m[0];
				}
				// Add fetchpriority="high" AND remove any loading="lazy" the
				// theme / WP core left on the LCP image. fetchpriority="high"
				// with loading="lazy" is contradictory — the browser can still
				// defer a lazy image, so preloading it while it stays lazy wins
				// nothing. Stripping lazy is what actually lets the preload land.
				return self::promote_lcp_img( $m[0] );
			},
			$html
		);

		return array( (string) $html, $preload );
	}

	/**
	 * Collect CSS `background-image` heroes as LCP candidates.
	 *
	 * Only INLINE `style` attributes are read. A background declared in an
	 * external stylesheet is invisible here by design: resolving it would mean
	 * fetching and parsing CSS from inside an output-buffer pass, and the URL a
	 * selector resolves to depends on cascade order we cannot evaluate from
	 * markup. Builders that put the hero in a generated per-post stylesheet are
	 * therefore still unserved — worth doing, but not at this cost. (#247)
	 *
	 * Scores are the element's declared pixel area so a background competes
	 * against an <img> in the SAME units — the whole point being that the
	 * bigger of the two should win regardless of which kind it is.
	 *
	 * @param string                    $html        Full page HTML.
	 * @param string[]                  $exclusions  Substring patterns the user excluded.
	 * @param array<int,array{0:int,1:int}> $skip_ranges Byte ranges of chrome containers.
	 * @return array<int,array{tag:string,src:string,srcset:string,sizes:string,score:float,order:int,background:bool}>
	 */
	private static function background_candidates( string $html, array $exclusions, array $skip_ranges ): array {
		if ( ! preg_match_all( '#<(?:div|section|header|figure|a|span|li|main|article|aside)\b[^>]*\sstyle\s*=\s*(["\']).*?\1[^>]*>#is', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$found = array();
		foreach ( $matches[0] as $index => $match ) {
			[ $tag, $offset ] = $match;

			// The same chrome-container gate as <img>: a background painted
			// inside <footer>/<nav>/<aside> is never the hero. (FBS-84576)
			if ( self::offset_in_ranges( $offset, $skip_ranges ) ) {
				continue;
			}

			$style = self::attr( $tag, 'style' );
			if ( '' === $style || false === stripos( $style, 'background' ) ) {
				continue;
			}

			$src = self::background_url( $style );
			if ( '' === $src ) {
				continue;
			}

			foreach ( $exclusions as $needle ) {
				if ( '' !== $needle && false !== stripos( $tag, $needle ) ) {
					continue 2;
				}
			}

			// Same chrome/opt-out gates as <img>. A logo painted as a background
			// is no more the hero than a logo in an <img>.
			if ( self::looks_too_small( $tag ) ) {
				continue;
			}

			$area = self::style_area( $style );
			if ( 0 === $area ) {
				// Nothing readable. Deliberately non-zero for the same reason
				// UNKNOWN_SIZE_SCORE is: an unmeasurable background must still
				// beat nothing on a page that declares no sizes at all, while
				// losing to anything we can actually measure.
				$area = self::UNKNOWN_SIZE_SCORE;
			}

			$found[] = array(
				'tag'        => $tag,
				'src'        => $src,
				'srcset'     => '',
				'sizes'      => '',
				'score'      => (float) $area,
				// Offset so a background never ties ahead of an <img> that
				// appeared earlier in the document; ties still break on order.
				'order'      => 100000 + $index,
				'background' => true,
			);
		}

		return $found;
	}

	/**
	 * Pull a real image URL out of a `background`/`background-image` declaration.
	 *
	 * Returns '' for anything with nothing to fetch: a gradient (which is a
	 * background-image but not a resource), a data: URI, or `none`.
	 */
	private static function background_url( string $style ): string {
		// Decode BEFORE parsing. Builders emit the url() quotes HTML-encoded
		// inside a style attribute (url(&quot;/hero.jpg&quot;)), and `&quot;`
		// carries a semicolon — so splitting the declaration on `;` first
		// truncated the value to `url(&quot` and found no URL at all.
		$style = html_entity_decode( $style, ENT_QUOTES );

		if ( ! preg_match( '#background(?:-image)?\s*:\s*((?:[^;\'"]|"[^"]*"|\'[^\']*\')+)#i', $style, $decl ) ) {
			return '';
		}
		if ( ! preg_match( '#url\(\s*(["\']?)(.*?)\1\s*\)#is', $decl[1], $m ) ) {
			return '';
		}
		$url = trim( $m[2] );
		if ( '' === $url || 0 === stripos( $url, 'data:' ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Declared pixel area from an inline style, or 0 when it can't be read.
	 *
	 * Only px is honoured. A percentage or viewport unit resolves against a
	 * containing block we cannot see from markup, and guessing one produced the
	 * wrong winner more often than declining to.
	 */
	private static function style_area( string $style ): int {
		$w = self::style_px( $style, 'width' );
		$h = self::style_px( $style, 'height' );
		if ( $w > 0 && $h > 0 ) {
			return $w * $h;
		}
		if ( $w > 0 ) {
			return (int) round( $w * $w * self::ASSUMED_ASPECT_RATIO );
		}
		return 0;
	}

	/** One px-valued CSS length from an inline style, or 0. */
	private static function style_px( string $style, string $prop ): int {
		if ( preg_match( '#(?:^|;)\s*' . preg_quote( $prop, '#' ) . '\s*:\s*(\d+(?:\.\d+)?)px#i', $style, $m ) ) {
			return (int) round( (float) $m[1] );
		}
		return 0;
	}

	/**
	 * How likely is this <img> to be the LCP element? Higher wins.
	 *
	 * Rendered area is the best available proxy, and we can only read what the
	 * markup declares:
	 *
	 *   1. `width` × `height` attributes — the real area, when present.
	 *   2. The largest `srcset` / `data-srcset` candidate width, squared into a
	 *      pseudo-area. A responsive hero usually omits width/height but ships
	 *      a 1600w+ candidate, which says more about its size than its
	 *      position ever did.
	 *   3. Nothing readable → a neutral score, so the image still competes
	 *      (matching looks_too_small()'s "don't guess" rule) but loses to any
	 *      image we CAN measure as larger.
	 *
	 * @param string $tag    The full <img> tag.
	 * @param string $srcset Resolved srcset (may come from data-srcset).
	 */
	private static function lcp_score( string $tag, string $srcset ): float {
		$w = self::attr( $tag, 'width' );
		$h = self::attr( $tag, 'height' );
		if ( '' !== $w && '' !== $h && is_numeric( $w ) && is_numeric( $h ) ) {
			return (float) ( (int) $w * (int) $h );
		}

		$widest = self::widest_srcset_width( $srcset );
		if ( $widest > 0 ) {
			// Estimate an AREA, not a square. Squaring the width compared a
			// pseudo-area against a real one and overstated the width-only
			// candidate by roughly the inverse of its aspect ratio, so a
			// 1024w sidebar thumbnail (1 048 576) beat a declared 1200×600
			// hero (720 000) — a regression on exactly the mixed pages that
			// document order used to get right, since the hero usually comes
			// first. Assuming a 16:9 box keeps both sides in the same units.
			return round( $widest * $widest * self::ASSUMED_ASPECT_RATIO );
		}

		return (float) self::UNKNOWN_SIZE_SCORE;
	}

	/**
	 * Fold the author's own priority signals and document position into an
	 * area score. (FBS-84576)
	 *
	 * Boosts are ADDITIVE, in area units, so they can rescue an image whose
	 * size the markup doesn't declare: a hero with no width/height and no
	 * `w`-descriptor srcset scores UNKNOWN_SIZE_SCORE, and multiplying that
	 * by any factor still loses to a 548×136 logo that declares itself. This
	 * is exactly the live miss — the real hero carried loading="eager"
	 * fetchpriority="high" and lost to three dimension-declaring decoys.
	 *
	 *   - fetchpriority="high" is the strongest signal there is: the author
	 *     (or WP core's own LCP detection) has already named this image the
	 *     hero. Worth a hero-sized area.
	 *   - An EXPLICIT loading="eager" is a weaker but deliberate "load me
	 *     now" (the default is eager, so writing it out is a choice).
	 *
	 * Position is a light multiplicative weight — earlier is better, but the
	 * spread is capped well under 5× so it can only break near-ties, never
	 * outrank a genuinely larger image further down (the logo-vs-hero case).
	 *
	 * @param float  $score Base area score from lcp_score() / style_area().
	 * @param string $tag   The candidate's full tag (for the signal attrs).
	 * @param int    $order Document-order index of the candidate.
	 */
	private static function weighted_score( float $score, string $tag, int $order ): float {
		if ( 'high' === strtolower( self::attr( $tag, 'fetchpriority' ) ) ) {
			$score += self::FETCHPRIORITY_HIGH_BOOST;
		}
		if ( 'eager' === strtolower( self::attr( $tag, 'loading' ) ) ) {
			$score += self::EAGER_BOOST;
		}
		return $score * self::position_weight( $order );
	}

	/**
	 * Document-position weight: 1.25 for the first image, easing to 1.0 by
	 * the tenth. The whole spread is 25%, far under the 5× area difference it
	 * must never override — it exists only to keep the old first-wins
	 * behaviour for images we can't tell apart.
	 */
	private static function position_weight( int $order ): float {
		return 1.0 + 0.25 * max( 0.0, 1.0 - $order / 10 );
	}

	/**
	 * Area-unit boost for fetchpriority="high" — roughly a 940×530 hero, so
	 * an explicitly-marked image outranks any mid-page decoy even when its
	 * own size is unreadable, while a genuinely huge unmarked image can still
	 * beat a marked small one.
	 */
	private const FETCHPRIORITY_HIGH_BOOST = 500000.0;

	/**
	 * Area-unit boost for an explicit loading="eager" — roughly 420×240,
	 * enough to break ties in favour of the author's intent without letting
	 * an eager logo outrank a plain hero.
	 */
	private const EAGER_BOOST = 100000.0;

	/**
	 * Byte ranges of <footer>/<nav>/<aside> regions. Nesting-aware per tag
	 * name (a nav inside a nav extends the range); an unclosed open tag
	 * poisons through to the end of the document, which errs on the side of
	 * not preloading — the safe direction, since a wrong preload is worse
	 * than none. (FBS-84576)
	 *
	 * @return array<int,array{0:int,1:int}> [start, end] byte offsets.
	 */
	private static function chrome_container_ranges( string $html ): array {
		$ranges = array();
		foreach ( array( 'footer', 'nav', 'aside' ) as $name ) {
			// (?=[\s/>]) rather than \b: a word boundary sits before the `-`
			// of a custom element, so `<nav\b` would swallow `<nav-menu>`.
			if ( ! preg_match_all( '#<(/?)' . $name . '(?=[\s/>])[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$depth = 0;
			$start = 0;
			foreach ( $m[0] as $i => $match ) {
				$closing = '' !== $m[1][ $i ][0];
				if ( ! $closing ) {
					if ( 0 === $depth ) {
						$start = $match[1];
					}
					++$depth;
				} elseif ( $depth > 0 ) {
					--$depth;
					if ( 0 === $depth ) {
						$ranges[] = array( $start, $match[1] );
					}
				}
			}
			if ( $depth > 0 ) {
				$ranges[] = array( $start, strlen( $html ) );
			}
		}
		return $ranges;
	}

	/** Does a byte offset fall inside any of the given [start, end] ranges? */
	private static function offset_in_ranges( int $offset, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( $offset > $range[0] && $offset < $range[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Score for an image whose size we can't read at all.
	 *
	 * Deliberately non-zero: an unmeasurable image must still beat nothing and
	 * still be preloadable on a page where no image declares its size. But it
	 * sits below a 200×200 declared area (40 000), so anything we CAN measure
	 * as a plausible hero outranks a guess.
	 */
	private const UNKNOWN_SIZE_SCORE = 1;

	/**
	 * Height-to-width ratio assumed when only a `w` descriptor is readable.
	 *
	 * 9/16 — the commonest hero/banner shape, and close enough that a
	 * width-only candidate is compared against a declared w×h area on the
	 * same scale rather than being systematically inflated.
	 */
	private const ASSUMED_ASPECT_RATIO = 9 / 16;

	/**
	 * Largest `w` descriptor in a srcset, or 0 when there isn't one.
	 *
	 * Only `w` descriptors are read. An `x` descriptor (`hero.jpg 2x`)
	 * describes pixel density, not layout width, so it says nothing about
	 * rendered area.
	 */
	private static function widest_srcset_width( string $srcset ): int {
		if ( '' === $srcset ) {
			return 0;
		}
		$widest = 0;
		foreach ( explode( ',', $srcset ) as $candidate ) {
			if ( preg_match( '#(\d+)w\s*$#', trim( $candidate ), $m ) ) {
				$widest = max( $widest, (int) $m[1] );
			}
		}
		return $widest;
	}

	/**
	 * Assemble one <link rel="preload" as="image" fetchpriority="high">.
	 *
	 * The href/srcset run through the `xspeed_lcp_preload_url` /
	 * `xspeed_lcp_preload_srcset` filters first. This is the coordination point
	 * with format-negotiating layers (Pro's Images module wraps the LCP <img> in
	 * a <picture> with a WebP/AVIF <source>, so the browser paints e.g.
	 * hero.png.webp, NOT the hero.png this preload would otherwise point at —
	 * making the high-priority preload a wasted download while the real LCP
	 * resource goes un-preloaded). By filtering the URL, a webp/avif layer can
	 * redirect the preload to the format it will actually serve, WITHOUT Free
	 * knowing that layer exists. (FBS-83553 H3)
	 */
	private static function preload_link( string $src, string $srcset, string $sizes ): string {
		// Resolve the `type` from the ORIGINAL image URL (before rewriting), so a
		// negotiating layer can key off the source .jpg/.png — after rewriting,
		// the URL is already a .webp and the derivation would no-op.
		$original = $src;
		/**
		 * Filter an explicit `type` for the preload link (e.g. "image/webp").
		 * Empty = omit. A typed image preload is only fetched by browsers that
		 * accept that type, so pairing a webp href with type="image/webp" is safe
		 * even though the markup is baked into a shared cache file.
		 *
		 * @param string $type Defaults to '' (no type attribute).
		 * @param string $src  The ORIGINAL (pre-rewrite) preload URL.
		 */
		$type = (string) apply_filters( 'xspeed_lcp_preload_type', '', $original );
		/**
		 * Filter the LCP preload href. Return a modern-format sibling (webp/avif)
		 * when one will actually be served for this image.
		 *
		 * @param string $src The original image URL chosen for preload.
		 */
		$src = (string) apply_filters( 'xspeed_lcp_preload_url', $src );
		if ( '' !== $srcset ) {
			/** @param string $srcset The original srcset chosen for preload. */
			$srcset = (string) apply_filters( 'xspeed_lcp_preload_srcset', $srcset );
		}

		$attrs = sprintf( 'href="%s"', esc_url( $src ) );

		if ( '' !== $srcset ) {
			// Preserve the responsive candidate set so the browser preloads
			// the same file it would have chosen from the <img>.
			$attrs .= sprintf( ' imagesrcset="%s"', esc_attr( html_entity_decode( $srcset, ENT_QUOTES ) ) );
			if ( '' !== $sizes ) {
				$attrs .= sprintf( ' imagesizes="%s"', esc_attr( html_entity_decode( $sizes, ENT_QUOTES ) ) );
			}
		}

		if ( '' !== $type ) {
			$attrs .= sprintf( ' type="%s"', esc_attr( $type ) );
		}

		return sprintf( '<link rel="preload" as="image" %s fetchpriority="high">' . "\n", $attrs );
	}

	/**
	 * Extract a single/double-quoted attribute value from a tag. Returns ''
	 * when the attribute is absent.
	 */
	private static function attr( string $tag, string $name ): string {
		// Anchor on a real attribute boundary, not `\b`. A word boundary sits
		// between the `-` and the `w` of `data-width`, so `\bwidth=` matched
		// inside it: a lazy-loaded hero carrying `data-width="50"
		// data-height="50"` was scored 50×50 and rejected by
		// looks_too_small() — defeating the feature on exactly the images the
		// data-src/data-srcset handling exists to support. Requiring
		// whitespace (or the start of the string) before the name means only
		// a genuine attribute matches.
		if ( preg_match( '#(?:^|\s)' . preg_quote( $name, '#' ) . '\s*=\s*(["\'])(.*?)\1#is', $tag, $m ) ) {
			return trim( $m[2] );
		}
		return '';
	}

	/**
	 * Resolve the URL/srcset/sizes the browser will actually paint for an
	 * <img>, seeing through JS-lazy placeholders. When `src` is a data: URI (a
	 * builder/lazy-loader placeholder), fall back to `data-src`; likewise carry
	 * `data-srcset`/`data-sizes` when the plain ones are absent. Returns
	 * ['', '', ''] when there's no real raster URL to preload. (FBS-83553 H1)
	 *
	 * @return array{0:string,1:string,2:string} [src, srcset, sizes]
	 */
	private static function effective_image_src( string $tag ): array {
		$src = self::attr( $tag, 'src' );
		if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
			$data_src = self::attr( $tag, 'data-src' );
			if ( '' !== $data_src && 0 !== stripos( $data_src, 'data:' ) ) {
				$src = $data_src;
			}
		}
		if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
			return array( '', '', '' );
		}
		$srcset = self::attr( $tag, 'srcset' );
		if ( '' === $srcset ) {
			$srcset = self::attr( $tag, 'data-srcset' );
		}
		$sizes = self::attr( $tag, 'sizes' );
		if ( '' === $sizes ) {
			$sizes = self::attr( $tag, 'data-sizes' );
		}
		return array( $src, $srcset, $sizes );
	}

	/**
	 * At/below this (px) in BOTH width and height, an image is treated as a
	 * logo/icon/avatar rather than an LCP hero. 200px clears real content heroes
	 * (which are typically ≥ 400px wide) while catching site logos and avatars
	 * — including the 150×150 logo the picker used to mistakenly preload.
	 */
	private const MIN_LCP_DIMENSION = 200;

	/**
	 * Class/role/filename markers that identify site chrome (logo, icon,
	 * avatar, spinner, emoji) which should never be treated as the LCP hero,
	 * regardless of declared size.
	 */
	private const NON_HERO_MARKERS = array( 'logo', 'icon', 'avatar', 'gravatar', 'spinner', 'emoji', 'site-icon', 'custom-logo' );

	/**
	 * Below this declared area (px²) an image is a badge/thumb/divider, never
	 * an LCP hero — 10 000 is a 100×100 square, or a 500×20 strip. Applied
	 * only when BOTH dimensions are readable. (FBS-84576)
	 */
	private const MIN_LCP_AREA = 10000;

	/**
	 * Is this <img> too small / too chrome-like to be the LCP hero? True when
	 * either (a) it carries a logo/icon/avatar marker, (b) an explicit
	 * `data-no-lcp` opt-out, or (c) BOTH width and height are present and
	 * both are ≤ the dimension threshold, or their area is under
	 * MIN_LCP_AREA. Missing dimensions are NOT guessed — an image whose size
	 * we can't read still competes. (FBS-83553 H1 "logo before hero".)
	 */
	private static function looks_too_small( string $tag ): bool {
		if ( false !== stripos( $tag, 'data-no-lcp' ) ) {
			return true;
		}
		// Marker check against class / id / src (covers "custom-logo", a
		// "…/logo.png" filename, role="img" avatars, etc.).
		$haystack = strtolower( self::attr( $tag, 'class' ) . ' ' . self::attr( $tag, 'id' ) . ' ' . self::attr( $tag, 'src' ) );
		foreach ( self::NON_HERO_MARKERS as $marker ) {
			if ( false !== strpos( $haystack, $marker ) ) {
				return true;
			}
		}
		$w = self::attr( $tag, 'width' );
		$h = self::attr( $tag, 'height' );
		if ( '' === $w || '' === $h || ! is_numeric( $w ) || ! is_numeric( $h ) ) {
			return false; // unknown size — don't guess; let it compete.
		}
		if ( (int) $w * (int) $h < self::MIN_LCP_AREA ) {
			return true;
		}
		return (int) $w <= self::MIN_LCP_DIMENSION && (int) $h <= self::MIN_LCP_DIMENSION;
	}

	/**
	 * Promote an <img> to the LCP element: force fetchpriority="high" and
	 * strip any loading="lazy" so the browser loads it immediately. Both are
	 * idempotent. `loading="lazy"` is REMOVED rather than flipped to "eager"
	 * because eager is the default; a bare tag with fetchpriority="high" is
	 * the canonical high-priority-image form.
	 */
	private static function promote_lcp_img( string $tag ): string {
		$tag = self::set_fetchpriority( $tag );
		// Drop loading="lazy" (WP core adds it by default). Leave other
		// loading values (e.g. an explicit eager) intact — only lazy hurts.
		$tag = preg_replace( '#\s*\bloading=(["\'])\s*lazy\s*\1#i', '', $tag );
		return (string) $tag;
	}

	/**
	 * Add fetchpriority="high" to an <img> tag. Idempotent — an existing
	 * fetchpriority value is normalised to high rather than duplicated.
	 */
	private static function set_fetchpriority( string $tag ): string {
		if ( preg_match( '#\bfetchpriority=(["\']).*?\1#i', $tag ) ) {
			return (string) preg_replace( '#\bfetchpriority=(["\']).*?\1#i', 'fetchpriority="high"', $tag, 1 );
		}
		// Insert right after "<img".
		return (string) preg_replace( '#<img\b#i', '<img fetchpriority="high"', $tag, 1 );
	}

	/**
	 * Inject the assembled hint markup into <head>. Prefers to land right
	 * before the first stylesheet so the preloads are discovered before the
	 * render-blocking CSS. Falls back to after <head>, then prepend.
	 */
	private static function inject_into_head( string $html, string $hints ): string {
		// Before the first <link rel="stylesheet"> if there is one.
		if ( preg_match( '#<link\b[^>]*rel=["\']stylesheet["\'][^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos = $m[0][1];
			return substr( $html, 0, $pos ) . $hints . substr( $html, $pos );
		}
		// Otherwise right after the opening <head ...>.
		if ( preg_match( '#<head\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos = $m[0][1] + strlen( $m[0][0] );
			return substr( $html, 0, $pos ) . "\n" . $hints . substr( $html, $pos );
		}
		// No head at all — prepend (degenerate documents).
		return $hints . $html;
	}
}
