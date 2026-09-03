<?php
/**
 * CSS combining on the finished HTML.
 *
 * The enqueue-stage combiner (Asset_Combiner::combine_styles) walked
 * WP_Styles->queue at priority 999 and rewrote handles: point one handle at the
 * merged file, blank the rest. That cannot be made correct, because WordPress
 * keeps editing the queue after we are done.
 *
 * The reported break (#195, WooCommerce + Kadence) was not a flaw in our
 * bucketing or carrier choice. Traced on a live install:
 *
 *   prio 998  kadence-global  src='.../global.min.css'
 *   prio 999  kadence-global  src=false          <- us, blanking a non-carrier
 *
 * ...and then core's `wp_maybe_inline_styles()` runs. It inlines any queued
 * handle carrying a `path` data key and sets `src = false` on it
 * (wp-includes/script-loader.php:3188). Our carrier was
 * `classic-theme-styles`, which core registers WITH a path — so core read that
 * handle's ORIGINAL file, inlined it, and discarded the combined URL we had
 * just written there. The merged <link> never printed and the five sheets we
 * had blanked were gone. Six stylesheets became one, and the site rendered
 * unstyled.
 *
 * No carrier-selection rule survives that: core rewrites the handle after us.
 * So combining moves to the finished HTML, where what we read is what shipped.
 * This is the layer LiteSpeed combines at, for the same reason.
 *
 * What that buys, beyond fixing the break:
 *
 *   - Document order is visible, so the cascade can be preserved exactly.
 *   - Sheets printed by plugins outside the queue are seen (they were
 *     invisible to a queue walker, and got duplicated).
 *   - `data-no-optimize` / `data-optimized` opt-outs work, matching what
 *     LiteSpeed and Autoptimize already honor.
 *   - The swap path is a pure string transform, so it is unit-testable —
 *     the enqueue version needed a full WP bootstrap and never had a test.
 *
 * The cascade rule: only CONTIGUOUS runs of same-media local sheets merge. A
 * sheet we cannot combine (external, opted out, excluded) ends the run, and
 * everything after it starts a new one. Nothing is ever hoisted past anything
 * else, which is the property the old combiner could not offer.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Css_Combine_Buffer {

	/** Minimum sheets in a run before merging is worth a request. */
	private const MIN_RUN = 2;

	/** Our own output-buffer nesting level, when we had to open one. */
	private static ?int $buffer_level = null;

	/** Set once we have transformed a page, so we never do it twice. */
	private static bool $done = false;

	/**
	 * Make sure SOMETHING will hand us the finished HTML.
	 *
	 * `xspeed_cache_final_html` is the preferred route — the page cache
	 * already buffers, so we transform once and the result is baked into the
	 * cache file. But that filter fires only on a cacheable MISS. With the
	 * page cache off, or on an excluded URL (`/cart`, `/checkout` — precisely
	 * where a WooCommerce layout break hurts most), it never fires at all and
	 * combining would silently stop working.
	 *
	 * So: open our own buffer when the cache is not going to give us one, and
	 * no-op when it is. `$done` guarantees a page is transformed once whichever
	 * path gets there first.
	 */
	public static function boot(): void {
		add_action(
			'template_redirect',
			static function (): void {
				if ( is_admin() || wp_doing_ajax() || wp_doing_cron()
					|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
					|| ( defined( 'WP_CLI' ) && WP_CLI )
					|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
					// Combining a builder editor's CSS reorders the cascade the
					// editor's own UI depends on. (#281)
					|| Builder_Editor::is_active() ) {
					return;
				}
				// The page cache is buffering and will call us through its
				// filter; a second buffer would just copy the page again.
				if ( class_exists( '\\XSpeed\\Cache' ) && Cache::is_buffering() ) {
					return;
				}
				ob_start( array( __CLASS__, 'filter_buffer' ) );
				self::$buffer_level = ob_get_level();
				add_action( 'shutdown', array( __CLASS__, 'close_buffer' ), 0 );
			},
			1
		);
	}

	/** ob_start() callback — transform once, pass everything else through. */
	public static function filter_buffer( string $buffer ): string {
		return self::process( $buffer );
	}

	/**
	 * Clear the once-per-request guard.
	 *
	 * Only tests need this: a request is a fresh process, but a test run
	 * exercises many documents through one loaded class.
	 */
	public static function reset(): void {
		self::$done = false;
	}

	/** Flush only the buffer we opened. */
	public static function close_buffer(): void {
		if ( null !== self::$buffer_level && ob_get_level() >= self::$buffer_level ) {
			ob_end_flush();
			self::$buffer_level = null;
		}
	}

	/**
	 * Combine stylesheet links in a finished HTML document.
	 *
	 * Returns the input unchanged when there is nothing to gain, so a caller
	 * can hand us any page unconditionally.
	 *
	 * @param string $html Complete page HTML.
	 */
	public static function process( string $html ): string {
		if ( '' === $html || false === stripos( $html, '<link' ) ) {
			return $html;
		}
		// Both entry points can fire on one request (our buffer wraps the
		// page, the cache filter also runs). Transforming twice would be
		// harmless but wasteful — and would re-parse a document whose sheets
		// we already marked data-optimized.
		if ( self::$done ) {
			return $html;
		}

		// Only <head> is in scope. A <link> in the body is either a late
		// plugin injection or markup we do not own, and moving it changes
		// paint order for something that already chose to be there.
		$head_end = stripos( $html, '</head>' );
		if ( false === $head_end ) {
			return $html;
		}
		$head = substr( $html, 0, $head_end );

		$runs = self::runs( $head );
		if ( empty( $runs ) ) {
			return $html;
		}

		$new_head = $head;
		foreach ( $runs as $run ) {
			$merged = self::merge_run( $run );
			if ( null === $merged ) {
				continue;
			}
			// Replace the FIRST tag of the run with the combined link and drop
			// the rest. Reusing the first slot is what keeps the merged CSS
			// exactly where the earliest sheet was, preserving the cascade.
			$first = true;
			foreach ( $run['tags'] as $tag ) {
				$new_head = self::replace_once( $new_head, $tag, $first ? $merged : '' );
				$first    = false;
				// Async CSS parks a <noscript> fallback immediately after each
				// sheet it defers. The sheet it points at is now inside the
				// combined file, so leaving the fallback behind would reload
				// every original for no-JS visitors — the combine undone for
				// exactly the audience least able to afford it. Drop it with
				// its sheet; merge_run() rebuilds one for the combined <link>.
				$new_head = self::drop_noscript_for( $new_head, $tag );
			}
		}

		if ( $new_head === $head ) {
			return $html;
		}
		self::$done = true;
		return $new_head . substr( $html, $head_end );
	}

	/**
	 * Split the head into contiguous runs of combinable same-media sheets.
	 *
	 * @return array<int,array{media:string,async:bool,tags:string[],urls:string[]}>
	 */
	private static function runs( string $head ): array {
		// Blank out conditional comments and inline <style> so neither is
		// parsed into, and so an inline block BREAKS a run: it may carry
		// overrides that must keep their position between two sheets.
		$scan = self::mask( $head );

		if ( ! preg_match_all( '#<link\b[^>]*>#i', $scan, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$excludes = self::excludes();
		$runs     = array();
		$open     = -1;      // index in $runs of the run still being extended.
		$prev_end = null;

		foreach ( $m[0] as $hit ) {
			$offset = (int) $hit[1];
			$tag    = substr( $head, $offset, strlen( (string) $hit[0] ) );

			if ( ! self::is_stylesheet( $tag ) ) {
				continue;
			}

			$url   = self::attr( $tag, 'href' );
			$media = self::media_of( $tag );
			$async = self::is_async_style( $tag );
			$local = '' !== $url ? self::local_path( $url ) : null;

			$combinable = null !== $local
				&& ! self::opted_out( $tag )
				&& ! self::excluded( $url, $excludes );

			// Anything of substance BETWEEN two sheets ends the run: an inline
			// <style> or a conditional block may carry overrides whose position
			// relative to these sheets is load-bearing. Masked regions are NUL
			// in $scan, so their presence is the test.
			$gap = null === $prev_end ? '' : substr( $scan, $prev_end, $offset - $prev_end );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- testing whether the gap between two <link>s holds anything at all; wp_strip_all_tags() also trims and would hide a whitespace-only gap, which is exactly the case that must NOT break a run.
			$gap_breaks = '' !== $gap && ( false !== strpos( $gap, "\0" ) || '' !== trim( strip_tags( $gap ) ) );

			$prev_end = $offset + strlen( $tag );

			if ( ! $combinable ) {
				$open = -1;   // an uncombinable sheet ends the run it sits in.
				continue;
			}

			// Async'd and render-blocking sheets never share a run: merging
			// them would either make a blocking sheet non-blocking or drag an
			// async'd one back onto the critical path. Grouping on the flag
			// keeps each combined file honest about how it loads. (#330)
			$extend = $open >= 0 && ! $gap_breaks
				&& $runs[ $open ]['media'] === $media
				&& $runs[ $open ]['async'] === $async;
			if ( $extend ) {
				$runs[ $open ]['tags'][] = $tag;
				$runs[ $open ]['urls'][] = $local;
				continue;
			}

			$runs[] = array(
				'media' => $media,
				'async' => $async,
				'tags'  => array( $tag ),
				'urls'  => array( $local ),
			);
			$open   = count( $runs ) - 1;
		}

		return array_values(
			array_filter(
				$runs,
				static fn( $r ) => count( $r['tags'] ) >= self::MIN_RUN
			)
		);
	}

	/**
	 * Build the combined file for one run and return its <link>, or null when
	 * nothing could be read.
	 *
	 * @param array{media:string,async:bool,tags:string[],urls:string[]} $run Run to merge.
	 */
	private static function merge_run( array $run ): ?string {
		$key  = md5( implode( '|', array_map( static fn( $p ) => $p . ':' . (int) @filemtime( $p ), $run['urls'] ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file contributes 0 to the key; handled below.
		$dir  = Asset_Combiner::cache_dir();
		$file = $dir . '/combined-' . $key . '.css';
		$url  = Asset_Combiner::cache_url() . '/combined-' . $key . '.css';

		if ( ! file_exists( $file ) ) {
			$css = '';
			foreach ( $run['urls'] as $path ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local stylesheet during page render; WP_Filesystem needs admin context.
				$body = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable sheet is skipped, not fatal.
				if ( '' === $body ) {
					continue;
				}
				$src   = self::path_to_url( $path );
				$body  = self::strip_file_prelude( $body );
				$body  = Asset_Combiner::resolve_imports( $body, $src, 0 );
				$body  = Asset_Combiner::rewrite_url_paths( $body, $src );
				// Close any comment this file left open BEFORE it can reach the
				// join. The `/* xspeed */` marker used to absorb this by
				// accident — an unterminated `/*` swallowed the marker instead
				// of the next stylesheet — but that made a debugging comment
				// load-bearing, and it stopped working the moment the join was
				// minified (issue #331). Neutralising it at the source is what
				// actually holds.
				$body  = self::close_open_comment( $body );
				// The marker stays: it is the separator that keeps a file
				// ending mid-declaration from fusing its last selector onto the
				// next file's first one. The minifier strips it from the
				// artifact, so it costs nothing in the shipped bytes.
				$css  .= "/* xspeed */\n" . $body . "\n";
			}
			if ( '' === trim( $css ) ) {
				return null;
			}
			$css = self::hoist_imports( $css );

			// Minify AFTER hoisting: @import rules are only legal at the top
			// of a stylesheet, so hoist_imports() has to see the un-minified
			// text first. Minifying the join is what issue #331 was about —
			// the inputs arrive minified but the concatenation did not, and
			// Minifier::rewrite_style() deliberately skips anything under
			// /cache/xspeed/, so this file was the end of the line. One
			// failing file drops Lighthouse's near-binary `unminified-css`
			// audit to 0.5, and the only offender on the page was ours.
			$css = Asset_Combiner::minify_css_body( $css );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- WP_Filesystem requires admin context, unavailable on the frontend.
			file_put_contents( $file, $css, LOCK_EX );
		}

		// Carry Async CSS across the merge (issue #330). Every sheet in the
		// run was async'd — that is a condition of grouping them, enforced in
		// runs() — so the combined file has to be non-render-blocking too, or
		// combining would quietly cancel the other feature instead of the
		// other way round. Same media="print" + onload swap async_style_tag
		// emits, applied once to the one <link> that replaces them all.
		if ( ! empty( $run['async'] ) ) {
			$restore = 'all' === $run['media'] ? 'all' : $run['media'];
			// The <noscript> fallback is rebuilt for the combined file, so a
			// visitor without JS still gets the CSS — one request now instead
			// of one per original sheet.
			$async_markup = '<link rel="stylesheet" href="%1$s" data-optimized="1" media="print" onload="this.media=\'%2$s\'" data-xs-async="%2$s" />'
				. '<noscript><link rel="stylesheet" href="%1$s" media="%2$s" /></noscript>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- see the note on the non-async return below; this replaces finished-HTML <link>s.
			return sprintf( $async_markup, esc_url( $url ), esc_attr( $restore ) );
		}

		$media = 'all' === $run['media'] ? '' : sprintf( ' media="%s"', esc_attr( $run['media'] ) );

		// data-optimized marks it ours, so a second pass — or another
		// optimizer honoring the same convention — leaves it alone.
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- this REPLACES already-enqueued <link>s in the finished HTML; wp_enqueue_style() cannot run here (the page is rendered) and is the layer whose late rewrites caused #195.
		return sprintf(
			'<link rel="stylesheet" href="%s" data-optimized="1"%s />',
			esc_url( $url ),
			$media
		);
	}

	/* ------------------------------------------------------------------ */
	/* Parsing helpers                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Replace conditional comments and inline <style> with NUL padding of the
	 * same length, so offsets still line up with the original string.
	 */
	private static function mask( string $head ): string {
		return (string) preg_replace_callback(
			// <noscript> is masked for the same reason as the rest: the <link>
			// inside it is a FALLBACK, not a sheet the document loads. Async
			// CSS emits one after every sheet it defers, so leaving them
			// visible both doubled the link count and broke every run into
			// single sheets — which is why combining silently stopped the
			// moment async_css was switched on (#330). The combined <link>
			// gets its own fallback rebuilt in merge_run().
			'#<!--\[if.*?\[endif\]-->|<style\b[^>]*>.*?</style>|<noscript\b[^>]*>.*?</noscript>|<!--.*?-->#is',
			static function ( $m ) {
				// <noscript> is padded with SPACES, not NULs. Both are hidden
				// from the link scanner, but the gap test below treats a NUL as
				// "something load-bearing sits between these two sheets" and
				// ends the run. An Async CSS fallback is not an override — it
				// is a copy of the sheet we just read — so it must not break
				// contiguity, or every async'd sheet ends up alone in its own
				// run and nothing ever merges (#330).
				if ( 0 === stripos( $m[0], '<noscript' ) ) {
					return str_repeat( ' ', strlen( $m[0] ) );
				}
				return str_repeat( "\0", strlen( $m[0] ) );
			},
			$head
		);
	}

	private static function is_stylesheet( string $tag ): bool {
		return (bool) preg_match( '#\brel\s*=\s*["\']?stylesheet["\']?#i', $tag );
	}

	private static function attr( string $tag, string $name ): string {
		if ( preg_match( '#\b' . preg_quote( $name, '#' ) . '\s*=\s*["\']([^"\']*)["\']#i', $tag, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * The media this sheet really applies to.
	 *
	 * '' and 'screen' both mean the on-screen document.
	 *
	 * An async'd sheet is the special case (issue #330). Async CSS rewrites
	 * `media="all"` to `media="print"` and restores the original from an
	 * onload handler, parking it in `data-xs-async`. Reading the literal
	 * `media` attribute therefore filed every async'd sheet into a `print`
	 * bucket of its own, no run ever reached MIN_RUN, and combining silently
	 * stopped: the reported page went from 5 stylesheets to 22 while
	 * `get_settings` still reported `combine_css: true` — two features that
	 * the UI presents as independent, one quietly cancelling the other.
	 *
	 * `data-xs-async` holds the media the sheet will have a moment after
	 * load, which is the one that decides whether two sheets belong together.
	 *
	 * Two spellings, one meaning. Free's Async CSS parks the media in
	 * `data-xs-async`; Pro's Critical CSS defers the remaining sheets itself
	 * and parks it in `data-xspeed-async`. Reading only Free's spelling filed
	 * every Pro-deferred sheet as genuine `media="print"`, merged them into a
	 * print-only bundle and dropped the swap — a bare, unstyled page from two
	 * switches (#335 review, issue 1). Neither side owns the attribute name,
	 * so both are read here.
	 */
	private static function media_of( string $tag ): string {
		$async = strtolower( self::async_media( $tag ) );
		if ( '' !== $async ) {
			return ( 'screen' === $async ) ? 'all' : $async;
		}
		$media = strtolower( self::attr( $tag, 'media' ) );
		return ( '' === $media || 'screen' === $media ) ? 'all' : $media;
	}

	/**
	 * The media parked on an async'd sheet, whichever attribute holds it.
	 *
	 * @return string '' when the sheet is not async'd.
	 */
	private static function async_media( string $tag ): string {
		foreach ( self::async_attrs() as $attr ) {
			$value = self::attr( $tag, $attr );
			if ( '' !== $value ) {
				return $value;
			}
		}

		// No attribute of ours, but the swap handler is the technique itself
		// and says the same thing: this sheet is parked under `print` and
		// becomes something else on load. Any plugin using the standard
		// print/swap idiom is read correctly rather than merged into a
		// print-only bundle and stripped of its handler (#335 review, issue 3).
		if ( preg_match( '#\bonload\s*=\s*(["\'])\s*this\.media\s*=\s*(["\'])([^"\']*)\2#i', $tag, $m ) ) {
			return $m[3];
		}

		return '';
	}

	/**
	 * Attributes that park a sheet's real media while it loads.
	 *
	 * `data-xs-async` is Free's; `data-xspeed-async` is Pro's Critical CSS.
	 * Filterable so a third deferring layer can declare itself rather than
	 * being merged into a print-only bundle.
	 *
	 * @return string[]
	 */
	private static function async_attrs(): array {
		$attrs = apply_filters( 'xspeed_async_css_attributes', array( 'data-xs-async', 'data-xspeed-async' ) );
		return array_filter( array_map( 'strval', (array) $attrs ) );
	}

	/** True when an async layer — Free's or Pro's — has already transformed this link. */
	private static function is_async_style( string $tag ): bool {
		foreach ( self::async_attrs() as $attr ) {
			if ( preg_match( '#\b' . preg_quote( $attr, '#' ) . '\s*=#i', $tag ) ) {
				return true;
			}
		}
		return '' !== self::async_media( $tag );
	}

	private static function opted_out( string $tag ): bool {
		return (bool) preg_match( '#\bdata-(no-optimize|optimized)\b#i', $tag );
	}

	/** @return string[] */
	private static function excludes(): array {
		/**
		 * Filter: xspeed_combine_css_excludes
		 *
		 * Substrings matched against each stylesheet URL. A sheet that matches
		 * keeps its own <link> and breaks the run around it, so the cascade
		 * either side of it is untouched.
		 *
		 * @param string[] $excludes Substrings to leave alone.
		 */
		$list = apply_filters( 'xspeed_combine_css_excludes', array() );
		return is_array( $list ) ? array_filter( array_map( 'strval', $list ) ) : array();
	}

	/** @param string[] $excludes */
	private static function excluded( string $url, array $excludes ): bool {
		foreach ( $excludes as $needle ) {
			if ( '' !== $needle && false !== strpos( $url, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Absolute filesystem path for a same-origin stylesheet URL, or null when
	 * it is external, unreadable, or not a file we own.
	 */
	private static function local_path( string $url ): ?string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES ) );
		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return null;
		}
		$clean = strtok( $url, '?' );
		if ( false === $clean ) {
			return null;
		}
		$info = Asset_Combiner::local_info( Asset_Combiner::to_absolute_url( $clean ) );
		return is_array( $info ) && ! empty( $info['path'] ) ? (string) $info['path'] : null;
	}

	/** Inverse of local_path, for @import + url() resolution. */
	private static function path_to_url( string $path ): string {
		$root = defined( 'ABSPATH' ) ? rtrim( ABSPATH, '/' ) : '';
		if ( '' !== $root && 0 === strpos( $path, $root ) ) {
			return rtrim( home_url(), '/' ) . str_replace( $root, '', $path );
		}
		return $path;
	}

	/**
	 * Move any surviving `@import` to the top of the combined file.
	 *
	 * `resolve_imports()` inlines every import it can resolve, but a REMOTE
	 * one (a Google Fonts URL, a CDN stylesheet) cannot be inlined and is
	 * deliberately left in place. Standalone that is correct. In a combined
	 * file it lands mid-stream, and the CSS spec only honours `@import` before
	 * any style rule — so the browser silently drops it and that stylesheet
	 * never loads at all.
	 *
	 * Hoisting keeps them working. It does change their position relative to
	 * the merged rules, but an import that is ignored outright is strictly
	 * worse than one that loads early: ignored means the font or vendor sheet
	 * is simply absent.
	 */
	private static function hoist_imports( string $css ): string {
		if ( false === stripos( $css, '@import' ) ) {
			return $css;
		}

		$imports = array();
		$body    = (string) preg_replace_callback(
			// A semicolon inside the rule does NOT end it. `[^;]+` stopped at
			// the first one, and a Google Fonts v2 URL puts semicolons in the
			// query string — `?family=Open+Sans:wght@400;500;600;700` is the
			// markup Google's own embed code hands you. The rule was cut in
			// half: a truncated @import got hoisted and the remainder was left
			// as loose garbage, so the browser dropped the import and the
			// webfont never loaded. (#277)
			//
			// So consume the parts an @import is actually made of — quoted
			// strings, url(...) including its own contents, and the media
			// query — and only then take the terminating `;`. An unterminated
			// @import at EOF is matched too, since browsers accept it.
			// The alternation covers, in order: a quoted string, a url(...)
			// with its contents, ANY other parenthesised group (a media
			// query's `(min-width:600px)`), and finally any character that is
			// none of those and not the terminator.
			'#@import\s+(?:"[^"]*"|\'[^\']*\'|url\(\s*(?:"[^"]*"|\'[^\']*\'|[^)]*)\s*\)|\([^)]*\)|[^;\'"()])+\s*;?#i',
			static function ( $m ) use ( &$imports ) {
				$rule = trim( (string) $m[0] );
				// Normalise a missing terminator so the hoisted block is valid
				// even when the source relied on EOF to end the rule.
				if ( '' !== $rule && ';' !== substr( $rule, -1 ) ) {
					$rule .= ';';
				}
				$imports[] = $rule;
				return '';
			},
			$css
		);

		if ( empty( $imports ) ) {
			return $css;
		}
		// Preserve source order, and drop duplicates — the same font import
		// appearing in three merged sheets should be fetched once.
		return implode( "\n", array_unique( $imports ) ) . "\n" . $body;
	}

	/**
	 * Drop the bytes that are only legal at the START of a stylesheet.
	 *
	 * A UTF-8 BOM and an `@charset` rule are both position-sensitive: a
	 * browser strips a LEADING BOM and honours a FIRST-LINE `@charset`, but
	 * either one appearing mid-file is just a stray token — and it invalidates
	 * the rule immediately after it.
	 *
	 * Kadence ships `woocommerce.min.css` with a BOM (`ef bb bf`). Standalone
	 * that is fine. Concatenated third into a combined file it killed the rule
	 * that followed — `.kadence-shop-top-row`, the flex container for the
	 * WooCommerce shop toolbar — so "Showing all 4 results", the sorting
	 * dropdown and the grid/list toggles collapsed into three stacked rows on
	 * /shop, while every other page looked fine. (QA on #195)
	 *
	 * The combined file needs no `@charset` of its own: it is served with a
	 * `Content-Type: text/css` charset from the webserver, which outranks an
	 * in-file rule.
	 */
	/**
	 * Close a comment the stylesheet left open.
	 *
	 * A `/*` with no closing `*​/` comments out everything after it. In a
	 * combined file that is every subsequent stylesheet — one malformed vendor
	 * file silently blanks the rest of the page's CSS.
	 *
	 * String literals are skipped, so `content: "/*"` is not mistaken for an
	 * opener. Pure — unit-tested.
	 */
	public static function close_open_comment( string $css ): string {
		$len       = strlen( $css );
		$in_string = '';
		$i         = 0;

		while ( $i < $len ) {
			$ch = $css[ $i ];

			if ( '' !== $in_string ) {
				if ( '\\' === $ch ) {
					$i += 2;
					continue;
				}
				if ( $ch === $in_string ) {
					$in_string = '';
				}
				++$i;
				continue;
			}

			if ( '"' === $ch || "'" === $ch ) {
				$in_string = $ch;
				++$i;
				continue;
			}

			if ( '/' === $ch && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				$close = strpos( $css, '*/', $i + 2 );
				if ( false === $close ) {
					// Unterminated: close it at the end of this file so the
					// next one in the bundle is still parsed.
					return $css . '*/';
				}
				$i = $close + 2;
				continue;
			}

			++$i;
		}

		return $css;
	}

	private static function strip_file_prelude( string $css ): string {
		// BOM first — an @charset can sit behind one.
		if ( 0 === strncmp( $css, "\xEF\xBB\xBF", 3 ) ) {
			$css = substr( $css, 3 );
		}
		// Only a LEADING @charset is meaningful, so only that one is dropped;
		// the string "@charset" inside a rule or comment is left alone.
		return (string) preg_replace( '/^\s*@charset\s+["\'][^"\']*["\']\s*;/i', '', $css );
	}

	/** str_replace, but only the first occurrence. */
	/**
	 * Remove the `<noscript>` fallback that Async CSS emitted for one sheet.
	 *
	 * Matched by the sheet's own href so only its fallback goes — a page can
	 * carry many, and an unrelated one must survive. Whitespace between the
	 * link and its noscript is tolerated; anything else means this is not the
	 * pair we think it is, and nothing is removed.
	 */
	private static function drop_noscript_for( string $head, string $tag ): string {
		$href = self::attr( $tag, 'href' );
		if ( '' === $href ) {
			return $head;
		}
		return (string) preg_replace(
			'#<noscript\b[^>]*>\s*<link\b[^>]*' . preg_quote( $href, '#' ) . '[^>]*>\s*</noscript>#i',
			'',
			$head,
			1
		);
	}

	private static function replace_once( string $haystack, string $needle, string $replace ): string {
		$pos = strpos( $haystack, $needle );
		if ( false === $pos ) {
			return $haystack;
		}
		return substr_replace( $haystack, $replace, $pos, strlen( $needle ) );
	}
}
