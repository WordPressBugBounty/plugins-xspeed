<?php
/**
 * Asset minifier — HTML, CSS, JS.
 *
 * Uses matthiasmullie/minify for CSS/JS. Local enqueued assets are minified
 * once, cached on disk, and the loader URL is rewritten to point at the
 * cached file.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

class Minifier {

	const MIN_SUBDIR = 'min';

	/**
	 * Absolute path to the minified-cache directory. Always derived from
	 * XSPEED_CACHE_DIR (the plugin's own cache root) — never assembled from
	 * arbitrary URL fragments.
	 */
	public static function min_dir() {
		return trailingslashit( XSPEED_CACHE_DIR ) . self::MIN_SUBDIR;
	}

	/**
	 * Public URL of the minified-cache directory. Built from content_url() +
	 * the known relative path, not by string-replacing WP_CONTENT_DIR out of
	 * a filesystem path (which would assume the filesystem layout matches
	 * the URL layout — it does not on Bedrock-style installs, multisite with
	 * mapped domains, or any setup with a relocated wp-content).
	 */
	private static function min_url() {
		// XSPEED_CACHE_DIR lives under wp-content (defined in xspeed.php as
		// WP_CONTENT_DIR . '/cache/xspeed'), so the URL is content_url() +
		// the known suffix. We do not derive URLs from arbitrary filesystem
		// paths anywhere in this plugin.
		$url = trailingslashit( content_url( 'cache/xspeed' ) ) . self::MIN_SUBDIR;
		// Force the site's scheme: content_url() derives its scheme from
		// is_ssl(), which is false behind a TLS-terminating reverse proxy, so
		// it can emit an http:// URL on an https page — the browser then blocks
		// the minified stylesheet as mixed content and the page renders
		// unstyled. Match home_url()'s registered scheme instead. (FBS-83633)
		$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME ) ?: 'https';
		return set_url_scheme( $url, $scheme );
	}

	public function __construct() {
		// Only run on the frontend — never minify wp-admin, AJAX, REST or cron
		// asset URLs. Page caching already handles the logged-in case for
		// the HTML response; minify scope is the public frontend.
		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// A page-builder editing screen is a front-end URL, so none of the
		// guards above catch it. Optimizing it breaks the editor outright --
		// Combine JS reorders the builder's own dependency graph and the
		// toolbar never renders. There is no speed to win on a logged-in,
		// uncacheable editing request anyway. (#281)
		if ( Builder_Editor::is_active() ) {
			return;
		}

		// Settings now live in the per-module option (xspeed_module_minify),
		// owned by XSpeed\Modules\Minify\MinifyModule. We read through
		// Settings_Manager so schema-validated values are returned even
		// if the option was hand-edited.
		$opts = Settings_Manager::get( 'minify' );

		if ( ! empty( $opts['minify_css'] ) ) {
			add_filter( 'style_loader_src', array( __CLASS__, 'rewrite_style' ), 10, 2 );
		}
		if ( ! empty( $opts['minify_js'] ) ) {
			add_filter( 'script_loader_src', array( __CLASS__, 'rewrite_script' ), 10, 2 );
		}

		// Phase 4.1a — filter-only "smarter minifier" features. Each is
		// gated on its own toggle so users can enable any subset.
		if ( ! empty( $opts['remove_query_strings'] ) ) {
			add_filter( 'style_loader_src',  array( Minify_Filters::class, 'strip_version_query' ), 20 );
			add_filter( 'script_loader_src', array( Minify_Filters::class, 'strip_version_query' ), 20 );
		}
		if ( ! empty( $opts['defer_js'] ) ) {
			add_filter( 'script_loader_tag', array( Minify_Filters::class, 'defer_script_tag' ), 20, 3 );
		}
		if ( ! empty( $opts['delay_js'] ) ) {
			// Delay applies a transform that's mutually exclusive with
			// plain defer — when both are on, delay wins (the bootstrap
			// will re-attach as a regular <script> on interaction).
			add_filter( 'script_loader_tag', array( Minify_Filters::class, 'delay_script_tag' ), 30, 3 );
			add_action( 'wp_footer',         array( Minify_Filters::class, 'print_delay_bootstrap' ), 1000 );
			// script_loader_tag only fires for wp_enqueue_script()'d assets.
			// Analytics / pixel / chat-widget tags printed straight into
			// wp_head bypass it, and those are usually the heaviest scripts
			// on the page — so sweep the finished buffer too. Runs before
			// minify_html (same filter, default priority) and is baked into
			// the cache file, so it replays on static hits where PHP never
			// boots.
			add_filter( 'xspeed_cache_final_html', array( Minify_Filters::class, 'delay_raw_script_tags' ), 20 );
		}
		if ( ! empty( $opts['async_css'] ) ) {
			add_filter( 'style_loader_tag',  array( Minify_Filters::class, 'async_style_tag' ), 20, 2 );
		}

		/*
		 * CSS combining runs on the FINISHED HTML, not the enqueue queue.
		 *
		 * The queue-walking version could not be made correct: whatever it
		 * wrote at priority 999, WordPress edited afterwards. Core's
		 * wp_maybe_inline_styles() inlines any queued handle carrying a `path`
		 * and sets src=false on it, which silently threw away the combined URL
		 * and took the sheets we had blanked with it — six stylesheets became
		 * one and the site rendered unstyled. See Css_Combine_Buffer's header
		 * for the full trace. (#195)
		 *
		 * Two entry points, because the page cache's filter is not always
		 * available: `xspeed_cache_final_html` fires only on a cacheable MISS,
		 * so on a site with the cache off — or on an excluded URL like /cart —
		 * combining would silently stop working. Css_Combine_Buffer::boot()
		 * opens its own buffer in exactly those cases and no-ops otherwise, so
		 * the page is transformed once either way.
		 */
		if ( ! empty( $opts['combine_css'] ) ) {
			add_filter( 'xspeed_cache_final_html', array( Css_Combine_Buffer::class, 'process' ), 5 );
			Css_Combine_Buffer::boot();
		}
		if ( ! empty( $opts['combine_js'] ) ) {
			// JS stays on the enqueue path for now: dependency order,
			// async/defer and wp_add_inline_script make it a different
			// problem, and the reported break is CSS-only. Moving it is worth
			// its own change rather than doubling the blast radius here.
			add_action( 'wp_enqueue_scripts', array( Asset_Combiner::class, 'combine_scripts' ), 999 );
		}
	}

	/**
	 * HTML elements that participate in an inline formatting context, where
	 * whitespace between two of them renders as a visible space.
	 *
	 * Deliberately excludes <br> (nothing to separate) and replaced/embedded
	 * inline elements that sit alone. Anything not listed is treated as block
	 * level, where inter-tag whitespace collapses to nothing and is safe to
	 * strip. (FBS-84090)
	 */
	private const INLINE_TAGS = array(
		'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'del', 'dfn',
		'em', 'i', 'ins', 'kbd', 'label', 'mark', 'q', 'rp', 'rt', 'ruby',
		's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u',
		'var', 'wbr', 'img', 'button', 'select', 'output',
	);

	/** True when $tag renders inline, so whitespace beside it is visible. */
	private static function is_inline( string $tag ): bool {
		return in_array( strtolower( $tag ), self::INLINE_TAGS, true );
	}

	/**
	 * Why minification is being skipped, when it is. '' when it will run.
	 *
	 * minify_html can read "on" in every settings surface while producing
	 * byte-identical HTML, because the guard below silently returns the
	 * input. Field report: a live site showed `minify_html: on` with 3,856
	 * indented lines in the delivered HTML and nothing anywhere explaining
	 * the contradiction — the setting looked broken rather than suppressed.
	 * Callers that report status MUST consult this so the refusal is
	 * visible. (Same class as Cache::static_rewrite_block_reason().)
	 *
	 * @return string 'wp_debug', 'filter', or ''.
	 */
	public static function skip_reason(): string {
		$debug_skip = defined( 'WP_DEBUG' ) && WP_DEBUG;
		if ( ! apply_filters( 'xspeed_skip_minify', $debug_skip ) ) {
			return '';
		}
		// Distinguish the built-in WP_DEBUG rule from a third party
		// filtering the escape hatch — the fixes are different.
		return $debug_skip ? 'wp_debug' : 'filter';
	}

	public static function minify_html( $html ) {
		if ( '' !== self::skip_reason() ) {
			return $html;
		}

		$placeholders = array();
		$pattern      = '#<(pre|textarea|script|style)\b[^>]*>.*?</\1>#is';
		$html         = preg_replace_callback(
			$pattern,
			function ( $m ) use ( &$placeholders ) {
				$key = '__XSPEED_PH_' . count( $placeholders ) . '__';
				// The placeholder pass exists to protect content whose
				// whitespace is significant (<pre>, <textarea>) and to keep
				// the tag-boundary regex off script bodies. <style> and
				// <script> were grouped in with them, so protection became a
				// permanent exemption: on builder sites where most CSS is
				// inline, a page with minify ON shipped fully indented. Minify
				// the BODY here, before it's stashed, so the outer passes
				// still never see it. (#2)
				$placeholders[ $key ] = self::minify_inline_block( $m[0], strtolower( $m[1] ) );
				return $key;
			},
			$html
		);

		$html = preg_replace( '/<!--(?!\[if).*?-->/s', '', $html );
		$html = preg_replace( '/\s+/', ' ', $html );

		/*
		 * Collapse whitespace BETWEEN TAGS — but never where it is visible.
		 *
		 * Whitespace separating two INLINE elements is a real, rendered space:
		 * WooCommerce emits `</del> <ins>` for a sale price, and that single
		 * character is the gap between "$32.50" and "$29.50". Stripping it
		 * printed "$32.50$29.50" run together, and only with cache on — the
		 * un-minified page was fine. (FBS-84090)
		 *
		 * So the strip only applies when at least one side is a BLOCK-level
		 * (or non-rendered) tag, where the whitespace collapses away anyway.
		 * Inline-to-inline boundaries keep their single space.
		 */
		$html = preg_replace_callback(
			// left tag name (may be a closing tag) … whitespace … right tag name
			'#</?([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*>\s+<(/?)([a-zA-Z][a-zA-Z0-9-]*)#',
			static function ( $m ) {
				// Keep the space only when BOTH sides are inline elements —
				// that is the one case where it is actually rendered.
				$keep = self::is_inline( $m[1] ) && self::is_inline( $m[3] );
				$open = substr( $m[0], 0, strrpos( $m[0], '<' ) ); // through the left tag's '>'
				return rtrim( $open ) . ( $keep ? ' ' : '' ) . '<' . $m[2] . $m[3];
			},
			$html
		);
		$html = trim( $html );

		foreach ( $placeholders as $key => $original ) {
			$html = str_replace( $key, $original, $html );
		}

		return $html;
	}

	public static function rewrite_style( $src, $handle ) {
		unset( $handle );
		return self::rewrite_asset( $src, 'css' );
	}

	public static function rewrite_script( $src, $handle ) {
		$rewritten = self::rewrite_asset( $src, 'js' );

		// Remember the pre-minify URL for this handle. script_loader_tag
		// runs later and only ever sees the rewritten src (a hashed
		// /cache/xspeed/min/<key>.js path), so a user's URL-substring
		// delay/exclusion target would never match once minification is
		// on. Minify_Filters::original_src() gives those checks the URL
		// the user actually wrote their target against. (FBS field report)
		if ( is_string( $handle ) && '' !== $handle && is_string( $src ) && $src !== $rewritten ) {
			Minify_Filters::remember_original_src( $handle, $src );
		}

		return $rewritten;
	}

	/**
	 * Replace a local CSS/JS URL with a cached, minified equivalent.
	 *
	 * @param string $src      Original asset URL.
	 * @param string $type     'css' or 'js'.
	 * @return string          Possibly rewritten URL.
	 */
	private static function rewrite_asset( $src, $type ) {
		if ( ! is_string( $src ) || '' === $src ) {
			return $src;
		}

		// Skip already-minified files.
		if ( false !== strpos( $src, '.min.' ) ) {
			return $src;
		}

		// Skip anything we already produced. The Asset_Combiner minifies the
		// combined body itself before writing combined-<hash>.css under
		// min/combined/ (issue #331 — that used to be asserted here but was
		// not actually true, so the artifact shipped unminified), and enqueues it
		// as `xspeed-combined-css`; the per-file minifier used to re-minify
		// that combined output into a SECOND file (min/<hash2>.css) with its
		// own mtime-derived hash. The served HTML then pinned that second
		// hash, so a purge/regeneration (which changes the combined file's
		// mtime -> a new hash2) left the cached page pointing at a file that
		// no longer existed -> 404 -> unstyled/broken frontend. Leaving our
		// own cache output untouched keeps a single, stable URL end-to-end.
		if ( false !== strpos( $src, '/cache/xspeed/' ) ) {
			return $src;
		}

		// Resolve to a local path; bail if external or unresolvable.
		$path = self::url_to_path( $src );
		if ( ! $path || ! is_readable( $path ) ) {
			return $src;
		}

		// Build a cache filename keyed on path + mtime so edits invalidate.
		$mtime = filemtime( $path );
		$key   = md5( $path . '|' . $mtime );
		$cache = self::cache_path( $key, $type );

		if ( ! file_exists( $cache ) ) {
			$ok = self::minify_file( $path, $cache, $type );
			if ( ! $ok ) {
				return $src;
			}
		}

		// Return a URL to the cached file. Built from known constants — never
		// from str_replace on a filesystem path (which would assume the FS
		// layout mirrors the URL layout).
		return self::min_url() . '/' . $key . '.' . $type;
	}

	private static function minify_file( $source_path, $target_path, $type ) {
		if ( ! class_exists( '\\MatthiasMullie\\Minify\\CSS' ) ) {
			return false;
		}

		// Path-traversal guard: refuse to write anywhere outside our cache
		// dir, even if a malicious filter ever produced a poisoned key.
		$cache_root = self::min_dir();
		self::ensure_dir( $cache_root );
		$real_root  = realpath( $cache_root );
		$real_dir   = realpath( dirname( $target_path ) );
		if ( ! $real_root || ! $real_dir || 0 !== strpos( $real_dir, $real_root ) ) {
			return false;
		}

		try {
			if ( 'css' === $type ) {
				// Passing the TARGET path makes matthiasmullie/minify rebase every
				// relative url(...) / @import against the minified file's location.
				// Without it, a stylesheet moved from e.g.
				// .../font-awesome/css/all.css to cache/xspeed/min/<key>.css keeps
				// its original url(../webfonts/…) — which then resolves against the
				// cache dir and 404s (missing FontAwesome/eicons/WooCommerce fonts).
				$minifier = new \MatthiasMullie\Minify\CSS( $source_path );
				$minified = $minifier->minify( $target_path );
				return '' !== $minified && file_exists( $target_path );
			}

			$minifier = new \MatthiasMullie\Minify\JS( $source_path );
			$minified = $minifier->minify();

			// Sanity check: paren/brace/bracket/backtick balance must be preserved.
			// matthiasmullie/minify can silently truncate mid-template-literal on
			// complex modern JS — bail rather than ship a broken file.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem requires admin context; minification runs on frontend page renders. Source already validated as readable on line 121.
			$source = file_get_contents( $source_path );
			if ( false === $source || ! self::balanced( $source, $minified ) ) {
				return false;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- WP_Filesystem requires admin context; minification runs on frontend page renders.
			$bytes = file_put_contents( $target_path, $minified );
			return false !== $bytes && file_exists( $target_path );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Cheap structural sanity check between source + minified bodies.
	 *
	 * Counts paired-delimiter tokens (parens, braces, brackets, backticks)
	 * in each and bails when the counts disagree — matthiasmullie/minify
	 * has been observed to silently truncate inside template literals on
	 * complex modern JS (see commit history), shipping a body that LOOKS
	 * minified but is structurally broken and crashes the page at parse.
	 *
	 * Backticks are paired (open + close = same token), so the count
	 * itself must match exactly. Strings inside the source can contain
	 * literal `{` / `}` / `[` / `]` that throw off the count by the same
	 * amount in both bodies (since they survive minification as-is), so
	 * the equality check is robust to that noise.
	 */
	/**
	 * Minify the body of one captured inline block, or return it untouched.
	 *
	 * Only `<style>` and JavaScript `<script>` bodies are eligible:
	 *
	 *  - `<pre>` / `<textarea>` — whitespace is rendered, never touch it.
	 *  - `<script>` with a non-JS `type` — `application/ld+json`,
	 *    `text/template`, `text/x-handlebars` and anything unrecognised are
	 *    data or markup, not code. Minifying JSON-LD would corrupt structured
	 *    data; minifying a template would eat the markup it holds. An unknown
	 *    type is treated as non-JS on purpose: guessing wrong breaks the page,
	 *    while skipping only forgoes a few bytes.
	 *  - `<script src="...">` — the body is empty; the file path already goes
	 *    through minify_file().
	 *
	 * Every result is checked with balanced(), the same structural guard the
	 * file path uses, so a body the library truncates is shipped as-is rather
	 * than broken. (#2)
	 *
	 * @param string $block Full matched tag, opening tag through closing tag.
	 * @param string $tag   Lowercased tag name.
	 * @return string Minified block, or $block unchanged.
	 */
	private static function minify_inline_block( string $block, string $tag ): string {
		if ( 'style' !== $tag && 'script' !== $tag ) {
			return $block; // pre / textarea — significant whitespace.
		}
		if ( ! class_exists( '\\MatthiasMullie\\Minify\\CSS' ) ) {
			return $block;
		}

		// Split into opening tag / body / closing tag. Anything that doesn't
		// match this shape isn't something we should be rewriting.
		if ( ! preg_match( '#^(<' . $tag . '\b[^>]*>)(.*)(</' . $tag . '\s*>)$#is', $block, $parts ) ) {
			return $block;
		}
		list( , $open, $body, $close ) = $parts;

		if ( '' === trim( $body ) ) {
			return $block;
		}

		// Refuse a body that is already structurally broken. balanced() only
		// compares source against minified, so it passes when BOTH are equally
		// unbalanced — `function x( {` minifies to `function x({`, same counts,
		// guard satisfied, broken code reformatted. Rewriting a body we can't
		// parse risks turning a page that happens to work into one that does
		// not, for no gain. (#2 AC: a syntactically broken block is left
		// untouched.)
		if ( ! self::self_consistent( $body ) ) {
			return $block;
		}

		if ( 'script' === $tag ) {
			// An external script has no body worth minifying.
			if ( preg_match( '#\bsrc\s*=#i', $open ) ) {
				return $block;
			}
			// No type, or an explicitly JavaScript type, is code. Everything
			// else is data/markup — see the docblock.
			$js_types = array(
				'text/javascript',
				'application/javascript',
				'application/ecmascript',
				'text/ecmascript',
				'module',
			);
			if ( preg_match( '#\btype\s*=\s*["\']?([^"\'\s>]+)#i', $open, $type_match ) ) {
				if ( ! in_array( strtolower( trim( $type_match[1] ) ), $js_types, true ) ) {
					return $block;
				}
			}
		}

		try {
			$minifier = 'style' === $tag
				? new \MatthiasMullie\Minify\CSS()
				: new \MatthiasMullie\Minify\JS();
			$minifier->add( $body );
			$minified = $minifier->minify();
		} catch ( \Throwable $e ) {
			return $block;
		}

		// A minifier that returns nothing for a non-empty body has failed, not
		// succeeded — shipping '' would silently delete the rule set.
		if ( ! is_string( $minified ) || '' === trim( $minified ) ) {
			return $block;
		}
		if ( ! self::balanced( $body, $minified ) ) {
			return $block;
		}

		return $open . $minified . $close;
	}

	/**
	 * Does a body's own paired delimiters balance?
	 *
	 * balanced() is a RELATIVE check — source against minified — so it cannot
	 * see input that was already broken: an unbalanced body minifies to an
	 * equally unbalanced one and the counts still agree. This is the absolute
	 * check, applied to the source alone before we touch it.
	 *
	 * Deliberately naive: it counts tokens without parsing, so a brace inside
	 * a string or comment skews it. That only ever makes it MORE conservative —
	 * a false negative skips minification, which costs bytes, while a false
	 * positive would ship broken code. (#2)
	 *
	 * @param string $body Inline block body.
	 */
	private static function self_consistent( string $body ): bool {
		$pairs = array(
			'{' => '}',
			'(' => ')',
			'[' => ']',
		);
		foreach ( $pairs as $open => $close ) {
			if ( substr_count( $body, $open ) !== substr_count( $body, $close ) ) {
				return false;
			}
		}
		// Backticks and quotes pair with themselves, so an odd count means an
		// unterminated literal.
		foreach ( array( '`' ) as $token ) {
			if ( 0 !== substr_count( $body, $token ) % 2 ) {
				return false;
			}
		}
		return true;
	}

	private static function balanced( string $source, string $minified ): bool {
		$pairs = array( '(', ')', '{', '}', '[', ']', '`' );
		foreach ( $pairs as $token ) {
			if ( substr_count( $source, $token ) !== substr_count( $minified, $token ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolve a local asset URL to a filesystem path using a strict allowlist
	 * of "URL prefix → filesystem prefix" pairs registered with WordPress.
	 *
	 * We never assume `site_url()` maps to `ABSPATH` (the WordPress root can
	 * live above the document root in Bedrock-style installs, behind a proxy,
	 * or on multisite with mapped domains). Each branch resolves through a
	 * known WP API (plugins, themes, content, includes) and validates that
	 * `realpath()` of the result still lives under the expected base — so a
	 * crafted `..`-laden URL cannot escape into the filesystem.
	 *
	 * @param string $url Asset URL (may be protocol-relative or absolute).
	 * @return string|false Absolute filesystem path on success, false otherwise.
	 */
	private static function url_to_path( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		// Drop query string + fragment.
		$clean = strtok( $url, '?#' );

		// Normalise protocol-relative + scheme variants of the host so we
		// match regardless of whether the asset URL came in over http/https.
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( 0 === strpos( $clean, '//' ) ) {
			$clean = 'https:' . $clean;
		}
		if ( $site_host ) {
			$asset_host = wp_parse_url( $clean, PHP_URL_HOST );
			if ( $asset_host && $asset_host !== $site_host ) {
				return false; // External asset — never touch.
			}
		}

		$candidates = array(
			array( plugins_url(),                  WP_PLUGIN_DIR ),
			array( get_stylesheet_directory_uri(), get_stylesheet_directory() ),
			array( get_template_directory_uri(),   get_template_directory() ),
			array( content_url(),                  WP_CONTENT_DIR ),
			array( includes_url(),                 ABSPATH . WPINC ),
		);

		foreach ( $candidates as $pair ) {
			list( $url_base, $path_base ) = $pair;
			if ( ! $url_base || ! $path_base ) {
				continue;
			}
			$url_base = rtrim( $url_base, '/' );
			if ( 0 !== strpos( $clean, $url_base . '/' ) && $clean !== $url_base ) {
				continue;
			}

			$relative  = ltrim( substr( $clean, strlen( $url_base ) ), '/' );
			$candidate = trailingslashit( $path_base ) . $relative;

			$real_base = realpath( $path_base );
			$real      = realpath( $candidate );
			if ( ! $real_base || ! $real ) {
				return false;
			}
			// Guard against `..`-traversal: resolved path must stay inside
			// the registered base.
			if ( 0 !== strpos( $real, $real_base ) ) {
				return false;
			}
			return $real;
		}

		return false;
	}

	private static function cache_path( $key, $type ) {
		return self::min_dir() . '/' . $key . '.' . $type;
	}

	private static function ensure_dir( $dir ) {
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			Cache::write_silence( $dir );
		}
	}

	public static function purge_minified() {
		self::rmtree_files( self::min_dir() );
	}

	/**
	 * Recursively delete every file under $dir (and the emptied
	 * subdirectories), keeping $dir itself. The previous glob('$dir/*')
	 * was non-recursive and no-ops on directories, so combined assets in
	 * min/combined/ were never cleared — a purge left a stale
	 * combined-<hash>.css the regenerated page no longer referenced.
	 * (FBS-83114 / FBS-83116)
	 */
	private static function rmtree_files( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*' ) as $path ) {
			if ( is_dir( $path ) ) {
				self::rmtree_files( $path );
				@rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup of our own cache subdir; WP_Filesystem is unavailable on the frontend purge path.
				continue;
			}
			wp_delete_file( $path );
		}
	}
}
