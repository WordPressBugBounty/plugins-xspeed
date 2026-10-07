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
			[ $html, $preload ] = self::build_lcp_preload( $html, $count, $exclusions, (string) ( $opts['page_url'] ?? '' ) );
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
	 * Strip core `loading="lazy"` and add `decoding="async"` on every <img>
	 * whose tag matches one of the given exclusion substrings. Mirrors what
	 * Lazy_Loader does for an excluded image inside the_content, but page-wide
	 * so heroes outside it are covered too. (FBS-83553 H2)
	 *
	 * Only the first visible match outside <footer>/<nav>/<aside> gets
	 * `fetchpriority="high"`, and only when no image in the page holds it yet.
	 * Every match used to get it, so a pattern naming the header and footer
	 * logo, or a hero in a closed accordion, put several images at High. (#558)
	 *
	 * @param string   $html       Full page HTML.
	 * @param string[] $exclusions Substring patterns identifying above-the-fold heroes.
	 */
	private static function promote_excluded_images( string $html, array $exclusions ): string {
		$img_re  = '#<img\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>#i';
		$claimed = (bool) preg_match( '#<img\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*?(?<![-\w])fetchpriority\s*=\s*["\']?high\b#i', $html );
		$skip    = $claimed ? array() : array_merge( self::chrome_container_ranges( $html ), Lazy_Loader::hidden_ranges( $html ) );

		$result = preg_replace_callback(
			$img_re,
			static function ( array $m ) use ( $exclusions, &$claimed, $skip ) {
				[ $tag, $offset ] = $m[0];
				foreach ( $exclusions as $needle ) {
					if ( '' !== $needle && false !== stripos( $tag, $needle ) ) {
						$tag = (string) preg_replace( '#\s*\bloading=(["\'])\s*lazy\s*\1#i', '', $tag );
						if ( ! $claimed && ! self::offset_in_ranges( (int) $offset, $skip ) && ! Lazy_Loader::tag_is_hidden( $tag, 'img' ) ) {
							$tag     = self::set_fetchpriority( $tag );
							$claimed = true;
						}
						if ( ! preg_match( '#\bdecoding=#i', $tag ) ) {
							$tag = (string) preg_replace( '#<img\b#i', '<img decoding="async"', $tag, 1 );
						}
						return $tag;
					}
				}
				return $tag;
			},
			$html,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);

		return is_string( $result ) ? $result : $html;
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
	 * @param string   $page_url   The page being served, for the candidate seam.
	 * @return array{0:string,1:string} [rewritten html, preload markup]
	 */
	private static function build_lcp_preload( string $html, int $count, array $exclusions, string $page_url = '' ): array {
		if ( $count < 1 ) {
			return array( $html, '' );
		}

		/**
		 * Supply the LCP preload candidate for this page instead of the
		 * automatic pick.
		 *
		 * The automatic pick reads the served HTML, so it cannot see a hero set
		 * as a background in an external stylesheet, and it skips an <img> that
		 * WordPress core marked lazy. An extension that has measured the page in
		 * a browser can answer instead. Return null to leave the automatic pick
		 * in charge; return an array to replace it:
		 *
		 *  - `url`    (string) the image to preload; an empty string means the
		 *             page has no LCP image, so nothing is preloaded;
		 *  - `srcset` (string, optional) and `sizes` (string, optional), emitted
		 *             as `imagesrcset` / `imagesizes`;
		 *  - `kind`   (string, optional) `img` or `background`, passed to the URL
		 *             filters below. An image no <img> on the page carries is a
		 *             background regardless;
		 *  - `media`  (string, optional) a media query for the preload link.
		 *
		 * Or a list of up to three such arrays, each with its own `media`, when
		 * phones and desktops paint different images: each device then preloads
		 * its own. Any `media` (also on a single answer) sets the matched <img>s
		 * to `loading="lazy"`, so a device never downloads the other one's hero.
		 *
		 * Anything else (an empty list, a URL that is not a string, a URL
		 * esc_url() refuses, a relative URL such as `hero.png`, a `media` that
		 * is not a string, over 200 characters or holding `<`, `>` or `"`) is
		 * not understood, and the automatic pick runs.
		 *
		 * The `<img>` carrying that URL, if the page has one, gets
		 * `fetchpriority="high"` and loses `loading="lazy"`. The URL filters
		 * below (`xspeed_lcp_preload_url` and friends) still apply.
		 *
		 * @param array|null $candidate Null = no opinion.
		 * @param string     $page_url  The page being served; empty outside a request.
		 */
		$supplied = self::supplied_candidates( apply_filters( 'xspeed_lcp_preload_candidate', null, $page_url ) );
		if ( null !== $supplied ) {
			return self::build_supplied_preload( $html, $supplied );
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
		// Markup hidden on arrival (a closed <details>, `hidden`, inline
		// display:none) cannot paint as LCP either. Preloading the hero of a
		// closed accordion spent the page's one High fetch on it. (#558)
		$skip_ranges = array_merge( self::chrome_container_ranges( $html ), Lazy_Loader::hidden_ranges( $html ) );
		// <img> indexes that can't be the LCP however they are marked: chrome,
		// hidden, or a logo/icon. Only these give up a stray `high`. (#558)
		$ruled_out = array();
		if ( preg_match_all( '#<img\b[^>]*>#i', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $index => $match ) {
				[ $tag, $offset ] = $match;

				// Skip anything the user excluded.
				if ( self::matches_any( $tag, $exclusions ) ) {
					continue;
				}

				// An image inside <footer>/<nav>/<aside> is site chrome by
				// construction — a footer brand strip or FAQ illustration can
				// never be the LCP element, whatever size it declares. On the
				// FBS-84576 repro these decoys outranked the real hero three
				// times on one layout.
				if ( self::offset_in_ranges( $offset, $skip_ranges ) || Lazy_Loader::tag_is_hidden( $tag, 'img' ) ) {
					$ruled_out[ $index ] = true;
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
					$ruled_out[ $index ] = true;
					continue;
				}

				$candidates[] = array(
					'tag'    => $tag,
					'src'    => $src,
					'srcset' => $srcset,
					'sizes'  => $sizes,
					'score'  => self::weighted_score( self::lcp_score( $tag, $srcset ), $tag, $index ),
					'order'  => $index,
					'offset' => $offset,
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

		// PASS 1c — <video poster="…">. A full-screen hero video paints its
		// poster first, and that first frame IS the LCP; measured on a live
		// page, a preloaded poster cut the LCP load delay from 1.5 s to 21 ms.
		foreach ( self::video_poster_candidates( $html, $exclusions, $skip_ranges ) as $vp ) {
			$candidates[] = $vp;
		}

		// PASS 1d — background rules in inline <style> blocks. Page builders
		// put the hero's background-image in generated per-post CSS printed
		// inline (Elementor's `.elementor-N .elementor-element-X` rules), not
		// in a style attribute — so PASS 1b never saw the element that
		// actually paints as LCP on five of seven measured sites. External
		// stylesheets stay out for the same reasons as before (#247): fetching
		// CSS from an output-buffer pass costs more than the preload saves.
		foreach ( self::style_block_candidates( $html, $exclusions, $skip_ranges ) as $sb ) {
			$candidates[] = $sb;
		}

		// A background video with no poster, ahead of every candidate, is the
		// hero. Nothing in its box is preloadable, and every image after it
		// sits lower on the page. Preloading the first of those spent the one
		// high-priority fetch on an image below the fold, ahead of the CSS.
		// Those images also give up a stray `high` from the lazy pass.
		$video_at = self::background_video_offset( $html, $skip_ranges );
		if ( null !== $video_at ) {
			foreach ( $candidates as $i => $c ) {
				if ( $c['offset'] > $video_at ) {
					if ( empty( $c['background'] ) ) {
						$ruled_out[ $c['order'] ] = true;
					}
					unset( $candidates[ $i ] );
				}
			}
		}

		if ( empty( $candidates ) ) {
			if ( null !== $video_at ) {
				$html = self::demote_images( $html, $ruled_out );
			}
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
				$preload .= self::preload_link( $w['src'], $w['srcset'], $w['sizes'], empty( $w['background'] ) ? 'img' : 'background' );
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
		//
		// When an <img> wins, it is the page's one High image, so an image
		// that can't be the LCP (a logo, an icon, a hidden panel, chrome)
		// gives up any `high` it carries. That is usually the lazy pass handing
		// its slot to the first image in the_content. An image that merely scored
		// lower, or that the user kept out of the pick, keeps its hint: the
		// ranking can be wrong, and core or the theme may have named the real
		// hero. (#558)
		$demote = ! empty( $chosen ) || null !== $video_at ? $ruled_out : array();
		$seen   = -1;
		$html   = preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( array $m ) use ( &$seen, $chosen, $demote ) {
				++$seen;
				if ( ! isset( $chosen[ $seen ] ) ) {
					if ( isset( $demote[ $seen ] ) && 'high' === strtolower( self::attr( $m[0], 'fetchpriority' ) ) ) {
						return (string) preg_replace( '#\s*(?<![-\w])fetchpriority\s*=\s*(["\']?)high\1#i', '', $m[0], 1 );
					}
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
	 * Byte offset of the first visible background video with no poster, or
	 * null when the page has none.
	 *
	 * @param string                        $html        Full page HTML.
	 * @param array<int,array{0:int,1:int}> $skip_ranges Chrome and hidden spans.
	 */
	private static function background_video_offset( string $html, array $skip_ranges ): ?int {
		$body = stripos( $html, '<body' );
		if ( ! preg_match_all( '#<video\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE, false === $body ? 0 : $body ) ) {
			return null;
		}
		foreach ( $m[0] as [ $tag, $offset ] ) {
			if ( self::offset_in_ranges( $offset, $skip_ranges ) || Lazy_Loader::tag_is_hidden( $tag, 'video' ) ) {
				continue;
			}
			if ( Lazy_Loader::is_background_video_without_poster( $tag ) ) {
				return $offset;
			}
		}
		return null;
	}

	/**
	 * Strip fetchpriority="high" from the <img> tags at the given indexes.
	 *
	 * @param string            $html    Page HTML.
	 * @param array<int,bool>   $indexes <img> indexes, in document order.
	 */
	private static function demote_images( string $html, array $indexes ): string {
		if ( empty( $indexes ) ) {
			return $html;
		}
		$seen = -1;
		$out  = preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( array $m ) use ( &$seen, $indexes ) {
				++$seen;
				if ( isset( $indexes[ $seen ] ) && 'high' === strtolower( self::attr( $m[0], 'fetchpriority' ) ) ) {
					return (string) preg_replace( '#\s*(?<![-\w])fetchpriority\s*=\s*(["\']?)high\1#i', '', $m[0], 1 );
				}
				return $m[0];
			},
			$html
		);
		return null === $out ? $html : $out;
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
				'offset'     => $offset,
				'background' => true,
			);
		}

		return $found;
	}

	/**
	 * Collect `<video poster="…">` first frames as LCP candidates.
	 *
	 * The poster is what the viewer sees until (and unless) the video plays —
	 * on a background-video hero, delayed by the Lazy module, it is the ONLY
	 * frame the initial paint has. Scored like a background: the element's
	 * declared inline-style area, or the unknown-size floor, with the order
	 * offset past every <img> so a poster never ties ahead of one.
	 *
	 * @param string                        $html        Full page HTML.
	 * @param string[]                      $exclusions  Substring patterns the user excluded.
	 * @param array<int,array{0:int,1:int}> $skip_ranges Byte ranges of chrome containers.
	 * @return array<int,array{tag:string,src:string,srcset:string,sizes:string,score:float,order:int,background:bool}>
	 */
	private static function video_poster_candidates( string $html, array $exclusions, array $skip_ranges ): array {
		if ( ! preg_match_all( '#<video\b[^>]*\bposter\s*=\s*(["\'])(.*?)\1[^>]*>#i', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$found = array();
		foreach ( $matches[0] as $index => $match ) {
			[ $tag, $offset ] = $match;
			$src              = trim( html_entity_decode( $matches[2][ $index ][0], ENT_QUOTES ) );
			if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
				continue;
			}
			if ( self::offset_in_ranges( $offset, $skip_ranges ) ) {
				continue;
			}
			foreach ( $exclusions as $needle ) {
				if ( '' !== $needle && false !== stripos( $tag, $needle ) ) {
					continue 2;
				}
			}
			if ( self::looks_too_small( $tag ) ) {
				continue;
			}

			$area = self::style_area( self::attr( $tag, 'style' ) );
			if ( 0 === $area ) {
				$area = self::UNKNOWN_SIZE_SCORE;
			}

			$found[] = array(
				'tag'        => $tag,
				'src'        => $src,
				'srcset'     => '',
				'sizes'      => '',
				'score'      => (float) $area,
				'order'      => 100000 + $index,
				'offset'     => $offset,
				'background' => true,
			);
		}

		return $found;
	}

	/**
	 * How many <style>-block background rules are considered per page. The
	 * scan is linear, but each rule costs one class-lookup pass over the
	 * body, so a pathological page (thousands of generated rules) is capped
	 * rather than trusted.
	 */
	private const STYLE_RULE_BUDGET = 40;

	/**
	 * Collect background-image rules from inline <style> blocks whose
	 * selector matches an element in the body.
	 *
	 * The match is deliberately narrow: the rule's RIGHTMOST simple selector
	 * must carry a class or id, and the first element in the body bearing it
	 * (outside chrome containers) is taken as the painted element. Rules
	 * inside @media (or any other at-rule block) are skipped — a desktop-only
	 * background preloaded on mobile is a wasted high-priority fetch, and the
	 * markup gives no viewport to resolve the query against.
	 *
	 * @param string                        $html        Full page HTML.
	 * @param string[]                      $exclusions  Substring patterns the user excluded.
	 * @param array<int,array{0:int,1:int}> $skip_ranges Byte ranges of chrome containers.
	 * @return array<int,array{tag:string,src:string,srcset:string,sizes:string,score:float,order:int,background:bool}>
	 */
	private static function style_block_candidates( string $html, array $exclusions, array $skip_ranges ): array {
		if ( ! preg_match_all( '#<style\b[^>]*>(.*?)</style\s*>#is', $html, $blocks ) ) {
			return array();
		}

		$found  = array();
		$budget = self::STYLE_RULE_BUDGET;
		foreach ( $blocks[1] as $css ) {
			if ( $budget <= 0 ) {
				break;
			}
			$css = self::strip_at_rule_blocks( $css );
			if ( false === stripos( $css, 'url(' ) ) {
				continue;
			}
			// One flat rule at a time: selector list up to '{', body to '}'.
			if ( ! preg_match_all( '#(?:^|})\s*([^{}]{1,512})\{([^{}]*)\}#s', $css, $rules, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $rules as $rule ) {
				if ( $budget <= 0 ) {
					break 2;
				}
				if ( false === stripos( $rule[2], 'url(' ) ) {
					continue;
				}
				$src = self::background_url( $rule[2] );
				if ( '' === $src ) {
					continue;
				}
				--$budget;
				// First selector of the list, rightmost compound of it.
				$selector = trim( (string) strtok( $rule[1], ',' ) );
				$parts    = preg_split( '#[\s>+~]+#', $selector );
				$last     = (string) end( $parts );
				// The last class or id token of that compound. Pseudo-classes
				// (:hover, ::before) mean the background is not the initial
				// paint, so they disqualify the rule.
				if ( false !== strpos( $last, ':' ) ) {
					continue;
				}
				if ( ! preg_match( '#([.\#])([-\w]+)$#', $last, $tok ) ) {
					continue;
				}
				$el = '.' === $tok[1]
					? self::first_element_with_class( $html, $tok[2], $skip_ranges )
					: self::first_element_with_id( $html, $tok[2], $skip_ranges );
				if ( null === $el ) {
					continue;
				}
				[ $tag, $offset ] = $el;
				foreach ( $exclusions as $needle ) {
					if ( '' !== $needle && false !== stripos( $tag, $needle ) ) {
						continue 2;
					}
				}
				if ( self::looks_too_small( $tag ) ) {
					continue;
				}
				$area = self::style_area( self::attr( $tag, 'style' ) );
				if ( 0 === $area ) {
					$area = self::UNKNOWN_SIZE_SCORE;
				}
				$found[] = array(
					'tag'        => $tag,
					'src'        => $src,
					'srcset'     => '',
					'sizes'      => '',
					'score'      => (float) $area,
					// Offset past the inline-style backgrounds: a rule-matched
					// background is one inference step less certain, so it must
					// never tie ahead of one read straight off the element.
					'order'      => 200000 + $offset,
					'offset'     => $offset,
					'background' => true,
				);
			}
		}

		return $found;
	}

	/**
	 * CSS with every at-rule BLOCK (@media, @supports, @container, …) removed,
	 * by brace depth — a regex cannot pair nested braces. Flat at-rules
	 * (@import, @charset) have no block and pass through harmlessly.
	 */
	private static function strip_at_rule_blocks( string $css ): string {
		$out = '';
		$len = strlen( $css );
		$i   = 0;
		while ( $i < $len ) {
			$at = strpos( $css, '@', $i );
			if ( false === $at ) {
				return $out . substr( $css, $i );
			}
			$brace = strpos( $css, '{', $at );
			$semi  = strpos( $css, ';', $at );
			$out  .= substr( $css, $i, $at - $i );
			if ( false === $brace || ( false !== $semi && $semi < $brace ) ) {
				// Flat at-rule — skip to its semicolon (or end).
				$i = false === $semi ? $len : $semi + 1;
				continue;
			}
			// Block at-rule — skip to its matching close brace.
			$depth = 1;
			$i     = $brace + 1;
			while ( $i < $len && $depth > 0 ) {
				$c = $css[ $i ];
				if ( '{' === $c ) {
					++$depth;
				} elseif ( '}' === $c ) {
					--$depth;
				}
				++$i;
			}
		}
		return $out;
	}

	/**
	 * The first element in the BODY carrying $class (outside chrome ranges),
	 * as [tag, offset], or null. Body-only, so a head <meta> can never match
	 * and a hit's offset is comparable with the chrome ranges.
	 *
	 * @return array{0:string,1:int}|null
	 */
	private static function first_element_with_class( string $html, string $class, array $skip_ranges ): ?array {
		$body = stripos( $html, '<body' );
		$from = false === $body ? 0 : $body;
		if ( ! preg_match_all( '#<[a-z][^>]*\bclass\s*=\s*(["\'])[^"\']*(?<![-\w])' . preg_quote( $class, '#' ) . '(?![-\w])[^"\']*\1[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE, $from ) ) {
			return null;
		}
		foreach ( $m[0] as $match ) {
			if ( ! self::offset_in_ranges( $match[1], $skip_ranges ) ) {
				return array( $match[0], $match[1] );
			}
		}
		return null;
	}

	/**
	 * The first element carrying id="$id" (outside chrome ranges), as
	 * [tag, offset], or null.
	 *
	 * @return array{0:string,1:int}|null
	 */
	private static function first_element_with_id( string $html, string $id, array $skip_ranges ): ?array {
		$body = stripos( $html, '<body' );
		$from = false === $body ? 0 : $body;
		if ( ! preg_match( '#<[a-z][^>]*\bid\s*=\s*(["\'])' . preg_quote( $id, '#' ) . '\1[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE, $from ) ) {
			return null;
		}
		if ( self::offset_in_ranges( $m[0][1], $skip_ranges ) ) {
			return null;
		}
		return array( $m[0][0], $m[0][1] );
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

	/**
	 * Does a tag contain any of the given substring patterns?
	 *
	 * @param string[] $patterns
	 */
	private static function matches_any( string $tag, array $patterns ): bool {
		foreach ( $patterns as $needle ) {
			if ( '' !== $needle && false !== stripos( $tag, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/** Does a byte offset fall inside any of the given [start, end] ranges? */
	private static function offset_in_ranges( int $offset, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			// >= on the start: a closed <details> span from
			// Lazy_Loader::hidden_ranges() opens right at the first image
			// after its <summary>.
			if ( $offset >= $range[0] && $offset < $range[1] ) {
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
	private static function preload_link( string $src, string $srcset, string $sizes, string $kind = 'img', string $media = '' ): string {
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
		 * @param string $kind `img` for an <img>, `background` for a CSS
		 *                     background or a video poster. Only an <img> can be
		 *                     wrapped in <picture>; a background is requested by
		 *                     the URL its stylesheet names.
		 */
		$type = (string) apply_filters( 'xspeed_lcp_preload_type', '', $original, $kind );
		/**
		 * Filter the LCP preload href. Return a modern-format sibling (webp/avif)
		 * when one will actually be served for this image.
		 *
		 * @param string $src  The original image URL chosen for preload.
		 * @param string $kind `img` or `background`, as for xspeed_lcp_preload_type.
		 */
		$src = (string) apply_filters( 'xspeed_lcp_preload_url', $src, $kind );
		if ( '' !== $srcset ) {
			/**
			 * @param string $srcset The original srcset chosen for preload.
			 * @param string $kind   `img` or `background`.
			 */
			$srcset = (string) apply_filters( 'xspeed_lcp_preload_srcset', $srcset, $kind );
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

		if ( '' !== $media ) {
			$attrs .= sprintf( ' media="%s"', esc_attr( $media ) );
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

	/** Most candidates one answer may carry (one per device class). */
	private const MAX_SUPPLIED_CANDIDATES = 3;

	/**
	 * The filter's answer as a list of well-formed candidates, or null when
	 * it is no answer or not one this understands (then the automatic pick
	 * runs). An empty list means "this page has no LCP image".
	 *
	 * @param mixed $answer What `xspeed_lcp_preload_candidate` returned.
	 * @return array<int,array{url:string,srcset:string,sizes:string,kind:string,media:string}>|null
	 */
	private static function supplied_candidates( $answer ): ?array {
		if ( ! is_array( $answer ) || empty( $answer ) ) {
			return null;
		}
		$items = array_key_exists( 'url', $answer ) ? array( $answer ) : $answer;
		if ( array_values( $items ) !== $items || count( $items ) > self::MAX_SUPPLIED_CANDIDATES ) {
			return null;
		}
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! array_key_exists( 'url', $item ) || ! is_string( $item['url'] ) ) {
				return null;
			}
			$url = trim( $item['url'] );
			if ( '' === $url ) {
				// "No LCP image" only makes sense as the whole answer.
				if ( 1 === count( $items ) ) {
					return array();
				}
				return null;
			}
			// Absolute, protocol-relative or root-relative. A bare `hero.png`
			// passed esc_url() as `http://hero.png`: a high-priority fetch to
			// a host that does not exist.
			if ( '' === esc_url( $url ) || ! preg_match( '#^(?:https?:)?/#i', $url ) ) {
				return null;
			}
			// A media rule this cannot use rejects the answer. Dropping only
			// the rule kept the preload and sent it to every device.
			$media = $item['media'] ?? '';
			if ( ! is_string( $media ) || strlen( $media ) > 200 || preg_match( '#[<>"]#', $media ) ) {
				return null;
			}
			$text  = static fn ( $v, int $max ): string => ( is_string( $v ) && strlen( $v ) <= $max ) ? trim( $v ) : '';
			$out[] = array(
				'url'    => $url,
				'srcset' => $text( $item['srcset'] ?? '', 4096 ),
				'sizes'  => $text( $item['sizes'] ?? '', 512 ),
				'kind'   => 'background' === ( $item['kind'] ?? '' ) ? 'background' : 'img',
				'media'  => trim( $media ),
			);
		}
		return $out;
	}

	/**
	 * Preload what an extension supplied, and promote the <img> it names.
	 *
	 * The answer is measured, not guessed, so every other <img> gives up a
	 * stray `fetchpriority="high"`: two High images compete with the one
	 * that paints first.
	 *
	 * @param string                                                                           $html       Page HTML.
	 * @param array<int,array{url:string,srcset:string,sizes:string,kind:string,media:string}> $candidates From supplied_candidates().
	 * @return array{0:string,1:string} [rewritten html, preload markup]
	 */
	private static function build_supplied_preload( string $html, array $candidates ): array {
		// Scoped when any preload carries a media rule, even a single one:
		// Pro sends one desktop-only candidate when the phone's LCP is text,
		// and promoting that <img> made phones download a hero they hide.
		$scoped = '' !== implode( '', array_column( $candidates, 'media' ) );
		$skip   = array_merge( self::chrome_container_ranges( $html ), Lazy_Loader::hidden_ranges( $html ) );

		// Which <img> index each candidate promotes: the first one, outside
		// site chrome and hidden markup, whose src or one of whose srcset
		// URLs IS the candidate. A substring match picked an earlier image
		// whose srcset merely contained the name.
		// Two candidates may claim the same <img>: a phone and a desktop size
		// of one responsive image. The second one is still an <img>, not a
		// background.
		$promote = array();
		$matched = array();
		if ( preg_match_all( '#<img\b[^>]*>#i', $html, $imgs, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $candidates as $c => $candidate ) {
				foreach ( $imgs[0] as $index => $match ) {
					[ $tag, $offset ] = $match;
					if ( self::offset_in_ranges( $offset, $skip ) || Lazy_Loader::tag_is_hidden( $tag, 'img' ) ) {
						continue;
					}
					[ $src, $img_srcset, $img_sizes ] = self::effective_image_src( $tag );
					if ( ! self::same_url( $src, $candidate['url'] ) && ! self::srcset_has( $img_srcset, $candidate['url'] ) ) {
						continue;
					}
					$promote[ $index ] = true;
					$matched[ $c ]     = true;
					// The <img> may already be served as a format sibling the
					// answer did not name (`hero.png.webp` for `hero.png`: an
					// image plugin swapped it for this browser). Preload what
					// the <img> will fetch, or the page downloads both.
					if ( self::format_original( $src ) !== $src ) {
						$candidates[ $c ]['url']    = html_entity_decode( $src, ENT_QUOTES );
						$candidates[ $c ]['srcset'] = html_entity_decode( $img_srcset, ENT_QUOTES );
						$candidates[ $c ]['sizes']  = $img_sizes;
					}
					// An answer that names only the URL still gets the <img>'s
					// responsive set: without it the preload fetches the full
					// file and the browser then downloads the size it shows.
					if ( '' === $candidates[ $c ]['srcset'] && '' !== $img_srcset ) {
						$candidates[ $c ]['srcset'] = $img_srcset;
						$candidates[ $c ]['sizes']  = $img_sizes;
					}
					break;
				}
			}
		}

		$seen = -1;
		$html = (string) preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( array $m ) use ( &$seen, $promote, $scoped ) {
				++$seen;
				if ( isset( $promote[ $seen ] ) ) {
					// Scoped to a device: make the <img> lazy, so the device
					// that hides it never downloads it. Keeping whatever
					// `loading` it had was not enough: Lazy Load's default
					// "load the first image straight away" had already made
					// it eager. The device that shows it gets it from its own
					// preload, which already fetched it early.
					return $scoped ? self::force_lazy( self::set_fetchpriority( $m[0] ) ) : self::promote_lcp_img( $m[0] );
				}
				if ( 'high' === strtolower( self::attr( $m[0], 'fetchpriority' ) ) ) {
					return (string) preg_replace( '#\s*(?<![-\w])fetchpriority\s*=\s*(["\']?)high\1#i', '', $m[0], 1 );
				}
				return $m[0];
			},
			$html
		);

		$preload = '';
		foreach ( $candidates as $c => $candidate ) {
			// Idempotency, as for the automatic pick: a second pass over an
			// already-processed body must not emit the link twice. Compared
			// with `&amp;` decoded, the way the markup spells a query.
			$already = false;
			if ( preg_match_all( '#<link\b[^>]*rel=["\']preload["\'][^>]*>#i', $html, $links ) ) {
				foreach ( $links[0] as $link ) {
					if ( false !== strpos( html_entity_decode( $link, ENT_QUOTES ), $candidate['url'] ) ) {
						$already = true;
						break;
					}
				}
			}
			if ( $already ) {
				continue;
			}
			// An image the page shows through no <img> is a background,
			// whatever the answer said: nothing here can wrap it in <picture>.
			$kind     = isset( $matched[ $c ] ) ? $candidate['kind'] : 'background';
			$preload .= self::preload_link( $candidate['url'], $candidate['srcset'], $candidate['sizes'], $kind, $candidate['media'] );
		}
		return array( $html, $preload );
	}

	/** Set `loading="lazy"` on an <img>, replacing any other loading value. */
	private static function force_lazy( string $tag ): string {
		$tag = (string) preg_replace( '#\s*(?<![-\w])loading\s*=\s*(["\']?)[a-z]*\1#i', '', $tag, 1 );
		return (string) preg_replace( '#<img\b#i', '<img loading="lazy"', $tag, 1 );
	}

	/** Two URLs name the same image: `&amp;` decoded, and a root-relative one compared by path. */
	private static function same_url( string $a, string $b ): bool {
		$a = html_entity_decode( $a, ENT_QUOTES );
		$b = html_entity_decode( $b, ENT_QUOTES );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b ) {
			return true;
		}
		// A format sibling is the same image: `hero.png.webp` is `hero.png`.
		$a = self::format_original( $a );
		$b = self::format_original( $b );
		if ( $a === $b ) {
			return true;
		}
		$rel = static function ( string $u ): string {
			if ( 0 === strpos( $u, '/' ) && 0 !== strpos( $u, '//' ) ) {
				return $u;
			}
			$path  = (string) wp_parse_url( $u, PHP_URL_PATH );
			$query = (string) wp_parse_url( $u, PHP_URL_QUERY );
			return $path . ( '' !== $query ? '?' . $query : '' );
		};
		return ( 0 === strpos( $a, '/' ) || 0 === strpos( $b, '/' ) ) && $rel( $a ) === $rel( $b );
	}

	/**
	 * The original of a modern-format sibling that appends its extension,
	 * `hero.png.webp` → `hero.png`, the naming ShortPixel, Imagify and other
	 * image plugins use. Any other URL comes back unchanged.
	 */
	private static function format_original( string $url ): string {
		return (string) preg_replace( '#(\.(?:jpe?g|png))\.(?:webp|avif)(?=$|\?)#i', '$1', $url );
	}

	/** Whether one of the URLs in a srcset IS $url (not merely contains it). */
	private static function srcset_has( string $srcset, string $url ): bool {
		foreach ( explode( ',', $srcset ) as $entry ) {
			$candidate = strtok( trim( $entry ), " \t\n" );
			if ( false !== $candidate && self::same_url( $candidate, $url ) ) {
				return true;
			}
		}
		return false;
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
		// Only a supplied candidate's preload carries `media`. Every other
		// page keeps exactly the placement it had before the seam existed.
		if ( false !== strpos( $hints, ' media="' ) ) {
			$pos = self::after_viewport_offset( $html );
			if ( null !== $pos ) {
				return substr( $html, 0, $pos ) . "\n" . $hints . substr( $html, $pos );
			}
		}
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

	/**
	 * Where a media-scoped preload goes: before the first stylesheet, as
	 * usual, but never ahead of <meta name="viewport">. Null when the head
	 * could not be read, and the usual placement is used instead.
	 *
	 * A preload's `media` is evaluated when the parser reaches the link, and
	 * until the viewport meta is parsed a phone lays out at its default
	 * desktop width. A `(min-width: 768px)` preload placed earlier matched on
	 * phones too and fetched the desktop hero at high priority.
	 *
	 * Reads only the head, tag by tag, so a viewport meta or a stylesheet
	 * written inside a script string, a comment or an attribute value is
	 * never taken for a real one. That put the hints inside a script, which
	 * then threw a SyntaxError. A pattern over the whole page did the same
	 * job but could give up on a large inline block, and then searched the
	 * raw markup.
	 *
	 * @return int|null Byte offset in $html.
	 */
	private static function after_viewport_offset( string $html ) {
		$len        = strlen( $html );
		$i          = 0;
		$head_start = null;
		$stylesheet = null;
		$viewport   = null;
		while ( $i < $len ) {
			$lt = strpos( $html, '<', $i );
			if ( false === $lt ) {
				break;
			}
			if ( 0 === substr_compare( $html, '<!--', $lt, 4 ) ) {
				// `<!-->` and `<!--->` close at once, so look from the second dash.
				$close = strpos( $html, '-->', $lt + 2 );
				if ( false === $close ) {
					return null;
				}
				$i = $close + 3;
				continue;
			}
			if ( ! preg_match( '#\G<(/?)([a-z][a-z0-9-]*)#i', $html, $t, 0, $lt ) ) {
				$i = $lt + 1;
				continue;
			}
			$gt = self::tag_end( $html, $lt + strlen( $t[0] ) );
			if ( null === $gt ) {
				return null;
			}
			$name = strtolower( $t[2] );
			$tag  = substr( $html, $lt, $gt + 1 - $lt );
			$i    = $gt + 1;

			if ( '/' === $t[1] ) {
				if ( 'head' === $name ) {
					break;
				}
				continue;
			}
			if ( 'head' === $name ) {
				$head_start = $i;
				continue;
			}
			if ( 'body' === $name ) {
				break;
			}
			if ( in_array( $name, array( 'script', 'style', 'noscript', 'template', 'title', 'textarea' ), true ) ) {
				$close = stripos( $html, '</' . $name, $i );
				if ( false === $close ) {
					return null;
				}
				$i = $close;
				continue;
			}
			if ( null === $head_start ) {
				continue;
			}
			if ( 'link' !== $name && 'meta' !== $name ) {
				continue;
			}
			$attrs = self::tag_attrs( $tag );
			if ( null === $stylesheet && 'link' === $name && 'stylesheet' === strtolower( $attrs['rel'] ?? '' ) ) {
				if ( null !== $viewport ) {
					return $lt;
				}
				$stylesheet = $lt;
				continue;
			}
			if ( null === $viewport && 'meta' === $name && 'viewport' === strtolower( $attrs['name'] ?? '' ) ) {
				if ( null !== $stylesheet ) {
					return $i;
				}
				$viewport = $i;
			}
		}
		if ( null !== $stylesheet ) {
			return $stylesheet;
		}
		return $viewport ?? $head_start;
	}

	/**
	 * A tag's attributes, read in order so a quoted value is consumed whole:
	 * `content="name=viewport"` is a content attribute, not a name.
	 *
	 * @return array<string,string> Lowercase name => trimmed value; the first of a repeated name wins.
	 */
	private static function tag_attrs( string $tag ): array {
		$out = array();
		preg_match_all( '#\s([^\s=/>"\']+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?#', $tag, $m, PREG_SET_ORDER );
		foreach ( $m as $a ) {
			$key = strtolower( $a[1] );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = trim( trim( $a[2] ?? '', '"\'' ) );
			}
		}
		return $out;
	}

	/**
	 * Offset of the `>` that ends a tag whose attributes start at $from,
	 * stepping over quoted values so `content="a>b"` does not end it early.
	 *
	 * @return int|null
	 */
	private static function tag_end( string $html, int $from ) {
		$len   = strlen( $html );
		$quote = '';
		for ( $j = $from; $j < $len; $j++ ) {
			$c = $html[ $j ];
			if ( '' !== $quote ) {
				if ( $c === $quote ) {
					$quote = '';
				}
			} elseif ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '>' === $c ) {
				return $j;
			}
		}
		return null;
	}
}
