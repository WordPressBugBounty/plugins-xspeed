<?php
/**
 * Defer jQuery and eligible inline code on the finished HTML page.
 *
 * Inline classics become data: URL scripts so the browser runs them with
 * deferred files in page order. A parse-time dependency keeps the scripts
 * above it blocking. Module groups move after the last changed classic;
 * every relocation and dependency check finishes before any replacement.
 * Ambiguous pages are returned unchanged.
 *
 * Uses xspeed_cache_final_html when the cache buffers the page, and a
 * separate output buffer on uncached requests.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-type ScriptEntry array{tag:string,offset:int,attrs:string,body:string,id:string,handle:string,verdict?:int}
 * @phpstan-type ModuleMoves array<int,string>
 * @phpstan-type CoreModule array{file:string,version:string,classic:array<int|string,mixed>,imports:list<string>}
 */
final class Defer_Inline {

	/** Leave this tag alone without stopping conversion of later scripts. */
	private const NEUTRAL = 0;
	/** An external that already carries `defer`. */
	private const DEFERRED = 1;
	/** A blocking external we may defer. */
	private const CAN_DEFER = 2;
	/** An inline block we may move into the deferred queue. */
	private const CAN_CONVERT = 3;
	/** Must run at parse time, and may read what comes before it. */
	private const PIN = 4;
	/** The page as a whole is not safe to touch. */
	private const ABORT = 5;
	/** A non-async module considered for relocation after queue selection. */
	private const MODULE = 6;
	/** A wp_localize_script() block of JSON data: it stays at parse time and pins if another block assigns its global. */
	private const LOCALIZE_DATA = 7;

	/**
	 * Largest inline body converted. A data: URL costs a third more bytes
	 * than the body, and anything this big is better left to the theme.
	 */
	private const MAX_BODY_BYTES = 65536;

	/** Script `type` values the browser runs as a classic script. */
	private const CLASSIC_TYPES = array(
		'',
		'text/javascript',
		'application/javascript',
		'application/x-javascript',
		'application/x-ecmascript',
		'text/ecmascript',
		'application/ecmascript',
		'text/jscript',
		'text/javascript1.0',
		'text/javascript1.1',
		'text/javascript1.2',
		'text/javascript1.3',
		'text/javascript1.4',
		'text/javascript1.5',
		'text/livescript',
		'text/x-ecmascript',
		'text/x-javascript',
	);

	/**
	 * Code that calls jQuery: `jQuery(`, `jQuery.`, `$(`, `$.`. The
	 * lookbehind keeps `myjQuery(` and `a.$(` out.
	 */
	private const JQUERY_CALL_RE = '#(?<![\w$.])(?:jQuery|\$)\s*[(.]#';

	/**
	 * Inline code that has to run at its place in the parse: written output,
	 * its own position, a readiness event a deferred script would miss, a
	 * consent default that must precede every tag, and A/B anti-flicker.
	 */
	private const STAYS_AT_PARSE_TIME_RE = '#document\s*\.\s*write'
		. '|currentScript'
		. '|getElementsByTagName\s*\(\s*[\'"]script'
		. '|querySelector(?:All)?\s*\(\s*[\'"]script'
		. '|document\s*\.\s*scripts\b'
		. '|readystatechange'
		. '|readyState'
		. '|[\'"]consent[\'"]\s*,\s*[\'"]default'
		. '|async-hide|_vis_opt_path_hides|abtasty|optimizely#i';

	/**
	 * Id of the script that holds jQuery's ready event until
	 * DOMContentLoaded.
	 */
	private const READY_HOLD_ID = 'xspeed-jquery-ready-hold';

	/**
	 * Holds jQuery's ready event until DOMContentLoaded. jQuery loaded
	 * after parsing fires ready on a timer straight away, and the browser
	 * runs that timer while a later deferred file is still downloading, so
	 * a `jQuery(function(){…})` ran before the plugin it calls had loaded.
	 * Measured in Chrome 155: `$.fn.plugin` undefined in the ready callback
	 * on every run. `readyWait`, not holdReady(): jQuery Migrate warns on
	 * holdReady, and jQuery 4 removed it.
	 *
	 * The held callbacks then run synchronously. jQuery 3 runs ready
	 * callbacks on a timer, and with jQuery deferred the load event can
	 * fire 1 ms after DOMContentLoaded, before that timer: a
	 * `$(window).on('load')` bound inside ready never ran (PR #819 QA, 3 of 3
	 * runs at 400 ms latency). The listener sits on window so it runs after
	 * the document's own DOMContentLoaded listeners, as jQuery's timer did.
	 * jQuery's own release timer may not have run yet when the deferred
	 * scripts ran in one go, so the hold makes that call itself; the timer
	 * then finds jQuery ready and does nothing. A callback added while the
	 * list runs joins its end, which is the order jQuery 1 and 2 used. If
	 * something else still holds ready, jQuery runs the callbacks later itself.
	 */
	private const READY_HOLD_JS = "(function(j){if(!j||typeof j.ready!=='function'||typeof j.readyWait!=='number'||j.isReady||!j.fn||typeof j.fn.ready!=='function'){return}var ready=j.fn.ready,held=[],done=false,hold=function(fn){if(done){return ready.call(this,fn)}if(typeof fn==='function'){held.push(fn)}return this};j.readyWait++;j.fn.ready=hold;window.addEventListener('DOMContentLoaded',function(){j.ready(true);if(!j.isReady){j.ready()}var now=j.readyWait<1;for(var i=0;i<held.length;i++){if(!now){ready.call(j(document),held[i]);continue}try{held[i].call(document,j)}catch(e){if(typeof j.readyException==='function'){j.readyException(e)}else{setTimeout(function(){throw e})}}}done=true;if(j.fn.ready===hold){j.fn.ready=ready}})})(window.jQuery);";

	/** Buffer level we opened, when the page cache is not buffering. */
	private static $buffer_level = null;

	/** Set when a regex in this pass failed; the page is then left alone. */
	private static $regex_failed = false;

	/**
	 * Core's script module manifest by module ID: null until read, false when unusable.
	 *
	 * @var array<string,CoreModule>|false|null
	 */
	private static $core_modules = null;

	/** Clear per-request state. Tests only. */
	public static function reset_state(): void {
		self::$buffer_level = null;
		self::$regex_failed = false;
		self::$core_modules = null;
	}

	/**
	 * Open our own buffer on requests the page cache does not buffer, so
	 * logged-in visitors and cache-excluded URLs get the same page.
	 */
	public static function boot(): void {
		add_action(
			'template_redirect',
			static function (): void {
				if ( wp_doing_ajax() || wp_doing_cron()
					|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
					|| ( defined( 'WP_CLI' ) && WP_CLI )
					|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
					|| is_feed() || is_robots() || is_embed() ) {
					return;
				}
				// The page cache hands us the page through its filter.
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

	/**
	 * ob_start() callback. Converts only when the whole page arrives in one
	 * piece: after a mid-request flush part of it is already on the wire,
	 * and converting the rest could put a consumer ahead of its library.
	 *
	 * @param string $buffer Output chunk.
	 * @param int    $phase  PHP_OUTPUT_HANDLER_* bitmask.
	 */
	public static function filter_buffer( string $buffer, int $phase = PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL ): string {
		$whole = ( $phase & PHP_OUTPUT_HANDLER_START ) && ( $phase & ( PHP_OUTPUT_HANDLER_FINAL | PHP_OUTPUT_HANDLER_END ) );
		return $whole ? self::process( $buffer ) : $buffer;
	}

	/** Flush only the buffer we opened. */
	public static function close_buffer(): void {
		if ( null !== self::$buffer_level && ob_get_level() === self::$buffer_level ) {
			ob_end_flush();
			self::$buffer_level = null;
		}
	}

	/**
	 * Filter: `xspeed_cache_final_html` (priority 25, after both Delay JS
	 * sweeps). Also called from our own buffer.
	 *
	 * @param string        $html    Complete page HTML.
	 * @param string[]|null $headers Response headers; null reads headers_list().
	 */
	public static function process( $html, ?array $headers = null ): string {
		if ( ! is_string( $html ) || '' === $html ) {
			return (string) $html;
		}
		$opts = Settings_Manager::get( 'minify' );
		if ( empty( $opts['defer_js'] ) || empty( $opts['defer_inline_js'] ) ) {
			return $html;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_customize_preview() ) {
			return $html;
		}
		// The login page, however it was loaded, as Defer and Delay JS skip it.
		// A custom login URL can require wp-login.php from template_redirect,
		// after this class's own buffer opened.
		if ( ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) || did_action( 'login_init' ) ) {
			return $html;
		}
		if ( false === stripos( $html, '<html' ) || false === stripos( $html, '<script' ) ) {
			return $html;
		}
		$headers = null === $headers ? headers_list() : $headers;
		if ( ! self::is_html_response( $headers ) || self::csp_governs_scripts( $headers, $html ) ) {
			return $html;
		}

		self::$regex_failed = false;
		$out                = self::rewrite( $html );
		// A failed match reads as "no match", which could convert a script
		// that writes into the page. Any failure leaves the page as it was.
		return ( null === $out || self::$regex_failed ) ? $html : $out;
	}

	/**
	 * Decide every script on the page, then defer the suffix that is safe.
	 *
	 * @param string $html Complete page HTML.
	 * @return string|null Null when the page is left alone.
	 */
	private static function rewrite( string $html ): ?string {
		$scripts = self::scan( $html );
		if ( null === $scripts ) {
			return null;
		}

		$printed = array();
		$last_pin = -1;
		$assigned = array();
		foreach ( $scripts as $i => $script ) {
			$verdict                  = self::classify( $script, $printed );
			$scripts[ $i ]['verdict'] = $verdict;
			if ( in_array( $verdict, array( self::LOCALIZE_DATA, self::PIN ), true ) ) {
				foreach ( self::extra_globals( $script ) as $name ) {
					$assigned[ $name ][ $i ] = true;
				}
			}
			if ( self::ABORT === $verdict ) {
				return null;
			}
			if ( self::PIN === $verdict ) {
				$last_pin = $i;
			}
			if ( '' !== $script['handle'] ) {
				$printed[ $script['handle'] ] = true;
			}
		}

		// Inline code above a localize block runs before that data at parse
		// time. In the deferred queue it would run after it, so it could
		// overwrite the data or see it too early. Page order stays up to the block.
		foreach ( $assigned as $name => $owners ) {
			$last_owner = max( array_keys( $owners ) );
			foreach ( $scripts as $i => $script ) {
				if ( $i >= $last_owner ) {
					break;
				}
				if ( self::CAN_CONVERT === $script['verdict'] && self::matches( '#(?<![\w$])' . preg_quote( (string) $name, '#' ) . '(?![\w$])#', $script['body'] ) ) {
					$scripts[ $last_owner ]['verdict'] = self::PIN;
					$last_pin = max( $last_pin, $last_owner );
					break;
				}
			}
		}
		if ( self::$regex_failed ) {
			return null;
		}

		// When blocks share a global, what a consumer reads depends on where
		// it runs relative to each of them, so nothing up to the last one moves.
		foreach ( $assigned as $owners ) {
			if ( count( $owners ) > 1 ) {
				foreach ( array_keys( $owners ) as $i ) {
					$scripts[ $i ]['verdict'] = self::PIN;
					$last_pin = max( $last_pin, $i );
				}
			}
		}

		// An inline event handler that calls jQuery runs whenever its event
		// fires, possibly before deferred jQuery has: it pins like a script.
		$handler_at = self::last_jquery_handler_offset( $html, $scripts );
		if ( null === $handler_at ) {
			return null;
		}
		foreach ( $scripts as $i => $script ) {
			if ( $script['offset'] < $handler_at ) {
				$last_pin = max( $last_pin, $i );
			}
		}

		$from = self::settle_associations( $scripts, $last_pin );
		if ( null === $from ) {
			return $html;
		}

		$last = null;
		foreach ( $scripts as $i => $script ) {
			if ( $i >= $from && in_array( $script['verdict'], array( self::CAN_DEFER, self::CAN_CONVERT ), true ) ) {
				$last = $i;
			}
		}
		if ( null === $last ) {
			return $html;
		}
		$moves = self::module_moves( $html, $scripts, $last );
		if ( null === $moves ) {
			return null;
		}
		foreach ( $scripts as $i => $script ) {
			if ( $i < $last && self::DEFERRED === $script['verdict']
				&& ! self::owned( $script ) && ! self::registered( $script ) ) {
				return null;
			}
		}
		$jquery = array();
		$has_hold = false;
		foreach ( $scripts as $i => $script ) {
			if ( self::is_jquery_core( $script ) ) {
				$jquery[] = $i;
			}
			$has_hold = $has_hold || self::READY_HOLD_ID === $script['id'];
		}
		if ( count( $jquery ) > 1 ) {
			return null;
		}
		$hold_after = ! $has_hold ? self::jquery_core_index( $scripts, $from ) : null;
		$relocated = '';
		foreach ( $moves as $index => $replacement ) {
			$relocated .= $scripts[ $index ]['tag'];
		}

		// Every decision uses the original page. Replacements cannot affect later decisions.
		for ( $i = count( $scripts ) - 1; $i >= 0; $i-- ) {
			$script = $scripts[ $i ];
			$tag = $script['tag'];
			if ( isset( $moves[ $i ] ) ) {
				$tag = $moves[ $i ];
			} elseif ( $i >= $from && self::CAN_DEFER === $script['verdict'] ) {
				$tag = '<script defer="defer"' . substr( $script['tag'], 7 );
			} elseif ( $i >= $from && self::CAN_CONVERT === $script['verdict'] ) {
				$tag = self::converted( $script );
			}
			if ( $i === $hold_after ) {
				$tag .= '<script defer="defer" src="data:text/javascript;base64,' . base64_encode( self::READY_HOLD_JS ) . '" id="' . self::READY_HOLD_ID . '"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The finished HTML needs the ready hold immediately after jQuery; enqueueing has already finished.
			}
			if ( $i === $last ) {
				$tag .= $relocated;
			}
			$html = substr_replace( $html, $tag, $script['offset'], strlen( $script['tag'] ) );
		}

		return $html;
	}

	/**
	 * Index of jQuery's own file when it runs in the deferred queue.
	 *
	 * @param array<int,array<string,mixed>> $scripts Entries from scan(), with verdicts.
	 * @param int                            $from    First index in the queue.
	 */
	private static function jquery_core_index( array $scripts, int $from ): ?int {
		foreach ( $scripts as $i => $script ) {
			if ( self::DEFERRED !== $script['verdict'] && ! ( $i >= $from && self::CAN_DEFER === $script['verdict'] ) ) {
				continue;
			}
			if ( self::is_jquery_core( $script ) ) {
				return $i;
			}
		}
		return null;
	}

	/** @param array<string,mixed> $script Scanned script. */
	private static function is_jquery_core( array $script ): bool {
		$names = self::attr_names( $script['attrs'] );
		return isset( $names['src'] ) && ( 'jquery-core' === $script['handle']
			|| self::matches( '#/jquery(?:-\d[\w.]*)?(?:\.min)?\.js(?:[?\#]|$)#i', self::attr_value( $script['attrs'], 'src' ) ) );
	}

	/** @param array<string,mixed> $script Scanned script. */
	private static function owned( array $script ): bool {
		return 0 === strpos( $script['id'], 'xspeed-' );
	}

	/** @param array<string,mixed> $script Scanned script. */
	private static function registered( array $script ): bool {
		$scripts = function_exists( 'wp_scripts' ) ? wp_scripts() : null;
		return '' !== $script['handle'] && $scripts instanceof \WP_Scripts && isset( $scripts->registered[ $script['handle'] ] );
	}

	/**
	 * A new pin can leave another parent blocking above the queue. Repeat
	 * until no associated block needs to stay with such a parent.
	 *
	 * @param array<int,array<string,mixed>> $scripts Scanned scripts with verdicts.
	 * @param int                           $last_pin Last parse-time dependency.
	 */
	private static function settle_associations( array &$scripts, int $last_pin ): ?int {
		$parents = array();
		foreach ( $scripts as $i => $script ) {
			if ( '' !== $script['id'] ) {
				$parents[ $script['id'] ][] = $i;
			}
		}
		do {
			$changed = false;
			$from = null;
			foreach ( $scripts as $i => $script ) {
				if ( $i > $last_pin && in_array( $script['verdict'], array( self::DEFERRED, self::CAN_DEFER ), true ) ) {
					$from = $i;
					break;
				}
			}
			foreach ( $scripts as $i => $script ) {
				if ( self::PIN === $script['verdict'] || ! self::matches( '#^(.+-js)-(?:before|extra)$#', $script['id'] ) ) {
					continue;
				}
				$names = self::attr_names( $script['attrs'] );
				$type = strtolower( trim( self::attr_value( $script['attrs'], 'type' ) ) );
				if ( isset( $names['src'] ) || self::parked( $names, $type ) || ! in_array( $type, self::CLASSIC_TYPES, true ) || isset( $names['nomodule'] ) ) {
					continue;
				}
				$parent_id = (string) preg_replace( '#-(?:before|extra)$#', '', $script['id'] );
				foreach ( $parents[ $parent_id ] ?? array() as $parent_index ) {
					$parent = $scripts[ $parent_index ];
					$parent_names = self::attr_names( $parent['attrs'] );
					$parent_type = strtolower( trim( self::attr_value( $parent['attrs'], 'type' ) ) );
					if ( self::parked( $parent_names, $parent_type ) || isset( $parent_names['async'] ) || isset( $parent_names['defer'] )
						|| isset( $parent_names['nomodule'] ) || ! in_array( $parent_type, self::CLASSIC_TYPES, true ) ) {
						continue;
					}
					$blocking = in_array( $parent['verdict'], array( self::PIN, self::NEUTRAL, self::LOCALIZE_DATA ), true )
						|| ( self::CAN_DEFER === $parent['verdict'] && ( null === $from || $parent_index < $from ) );
					if ( $blocking ) {
						$scripts[ $i ]['verdict'] = self::PIN;
						$last_pin = max( $last_pin, $i );
						$changed = true;
						break;
					}
				}
			}
		} while ( $changed );
		return $from;
	}

	/** @param array<string,true> $names Attribute names. @param string $type Script type. */
	private static function parked( array $names, string $type ): bool {
		return isset( $names['data-xs-delay'] ) || isset( $names['data-xs-src'] ) || 'text/xspeed-delayed' === $type;
	}

	/**
	 * Globals a data-only localize block assigns; none for any other script.
	 *
	 * @param array<string,mixed> $script Scanned inline script.
	 * @return string[]
	 */
	private static function extra_globals( array $script ): array {
		// A browser ignores the body of a script that has a src.
		if ( isset( self::attr_names( $script['attrs'] )['src'] )
			|| ! self::matches( '#-js-extra$#', $script['id'] ) || self::matches( self::JQUERY_CALL_RE, $script['body'] ) ) {
			return array();
		}
		$body = trim( $script['body'] );
		$open_cdata = '/* <![CDATA[ */';
		$close_cdata = '/* ]]> */';
		if ( 0 === strpos( $body, $open_cdata ) && $close_cdata === substr( $body, -strlen( $close_cdata ) ) ) {
			$body = trim( substr( $body, strlen( $open_cdata ), -strlen( $close_cdata ) ) );
		}
		$lines = explode( "\n", $body );
		$source = '//# sourceURL=' . rawurlencode( $script['id'] );
		if ( $source === trim( end( $lines ) ) ) {
			array_pop( $lines );
		}
		$reserved = explode( ' ', 'await break case catch class const continue debugger default delete do else enum export extends false finally for function if implements import in instanceof interface let new null package private protected public return static super switch this throw true try typeof var void while with yield' );
		$globals = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( ! preg_match( '#^var\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(.+);$#', $line, $match ) ) {
				self::check_regex();
				return array();
			}
			if ( in_array( $match[1], $reserved, true ) ) {
				return array();
			}
			json_decode( trim( $match[2] ) );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return array();
			}
			$globals[] = $match[1];
		}
		return $globals;
	}

	/**
	 * Preflight the module group before changing any bytes.
	 *
	 * @param string                        $html Complete page.
	 * @param array<int,array<string,mixed>> $scripts Scanned scripts.
	 * @param int                           $last Last script being converted or deferred.
	 * @return array<int,string>|null Module index to its old-position replacement; null aborts.
	 */
	private static function module_moves( string $html, array $scripts, int $last ): ?array {
		$indexes = array();
		$ids = array();
		foreach ( $scripts as $i => $script ) {
			if ( '' !== $script['id'] ) {
				$ids[ $script['id'] ] = ( $ids[ $script['id'] ] ?? 0 ) + 1;
			}
			if ( $i < $last && self::MODULE === $script['verdict'] ) {
				$indexes[] = $i;
			}
		}
		if ( ! $indexes ) {
			return array();
		}
		$first = $indexes[0];
		for ( $i = $first; $i <= $last; $i++ ) {
			$script = $scripts[ $i ];
			if ( self::DEFERRED === $script['verdict'] && self::READY_HOLD_ID !== $script['id']
				&& ( 1 !== ( $ids[ $script['id'] ] ?? 0 ) || ! Minify_Filters::was_deferred_by_xspeed( $script['tag'] ) ) ) {
				return null;
			}
		}
		$markup = self::resource_tags( $html );
		if ( null === $markup ) {
			return null;
		}
		$base = '';
		foreach ( $markup as $tag ) {
			if ( 'base' !== $tag['name'] || ! isset( self::attr_names( $tag['attrs'] )['href'] ) ) {
				continue;
			}
			// A base encountered between the two positions changes relative imports.
			if ( '' !== $base || $tag['offset'] >= $scripts[ $first ]['offset'] ) {
				return null;
			}
			$base = self::module_url( self::attr_value( $tag['attrs'], 'href' ), '' );
			if ( null === $base ) {
				return null;
			}
		}
		$moves = array();
		$fixed = array();
		$moved = array();
		$preloads = array();
		foreach ( $markup as $tag ) {
			if ( 'link' === $tag['name'] && self::matches( '#(?:^|\s)modulepreload(?:\s|$)#i', self::attr_value( $tag['attrs'], 'rel' ) ) ) {
				$preloads[] = $tag;
			}
		}
		foreach ( $indexes as $i ) {
			$script = $scripts[ $i ];
			$names = self::attr_names( $script['attrs'] );
			$src = self::attr_value( $script['attrs'], 'src' );
			if ( isset( $names['blocking'] ) || Minify_Filters::script_opts_out( '<script' . $script['attrs'] . '>' )
				|| Minify_Filters::keeps_script_in_place( $script['handle'], isset( $names['src'] ) ? $src : $script['body'] ) ) {
				// It stays where it is, so a module moved from above it would now run after it.
				$urls = $moves ? null : self::fixed_core_module( $script, $scripts, $base );
				if ( null === $urls ) {
					return null;
				}
				$fixed = array_merge( $fixed, $urls );
				continue;
			}
			if ( ! isset( $names['src'] ) ) {
				if ( self::matches( self::STAYS_AT_PARSE_TIME_RE, $script['body'] )
					|| ( '' !== $script['id'] && false !== strpos( $script['body'], $script['id'] )
						&& self::matches( '#getElementById|querySelector#', $script['body'] )
						&& self::matches( '#parentNode|parentElement|previousSibling|previousElementSibling|nextSibling|nextElementSibling|compareDocumentPosition|getBoundingClientRect|offset(?:Top|Left|Parent)#', $script['body'] ) ) ) {
					return null;
				}
				$moves[ $i ] = '';
				continue;
			}
			$url = self::module_url( $src, $base );
			if ( null === $url ) {
				return null;
			}
			$replacement = '<link rel="modulepreload" href="' . esc_attr( $url ) . '"';
			foreach ( array( 'crossorigin', 'integrity', 'referrerpolicy', 'fetchpriority' ) as $attribute ) {
				if ( isset( $names[ $attribute ] ) ) {
					$replacement .= ' ' . $attribute . '="' . esc_attr( self::attr_value( $script['attrs'], $attribute ) ) . '"';
				}
			}
			$replacement .= '>';
			foreach ( $preloads as $preload ) {
				if ( $preload['offset'] < $script['offset'] && $url === self::module_url( self::attr_value( $preload['attrs'], 'href' ), $base )
					&& self::preload_compatible( $script['attrs'], $preload['attrs'] ) ) {
					$replacement = '';
					break;
				}
			}
			$moves[ $i ] = $replacement;
			$moved[] = $url;
			if ( '' !== $replacement ) {
				$preloads[] = array( 'attrs' => substr( $replacement, 5, -1 ), 'offset' => $script['offset'] );
			}
		}
		// One module instance cannot both stay before the queue and move after it.
		if ( array_intersect( $fixed, $moved ) ) {
			return null;
		}
		return $moves;
	}

	/**
	 * Module URLs of a fixed core module and everything it imports, when core's
	 * own manifest proves that none of them reads classic script output. Such a
	 * module keeps its bytes and its place, so the queue can move past it.
	 *
	 * @param array<string,mixed>            $script  The fixed module.
	 * @param array<int,array<string,mixed>> $scripts Scanned scripts.
	 * @param string                         $base    Document base, or empty.
	 * @return list<string>|null Null when core cannot vouch for every module.
	 */
	private static function fixed_core_module( array $script, array $scripts, string $base ): ?array {
		$names = self::attr_names( $script['attrs'] );
		$modules = self::core_modules();
		if ( ! isset( $names['src'] ) || isset( $names['async'] ) || null === $modules ) {
			return null;
		}
		$url = self::module_url( self::attr_value( $script['attrs'], 'src' ), $base );
		$root = null === $url ? null : self::core_module_at( $url, $modules );
		$map = self::core_import_map( $scripts );
		if ( null === $root || null === $map || ( isset( $map[ $root ] ) && self::module_url( $map[ $root ], $base ) !== $url ) ) {
			return null;
		}
		// Dynamic imports count too: they can load while classic code is still due.
		$urls = array( $root => $url );
		$queue = array( $root );
		while ( $queue ) {
			$module = $modules[ array_shift( $queue ) ];
			if ( array() !== $module['classic'] ) {
				return null;
			}
			foreach ( $module['imports'] as $import ) {
				if ( isset( $urls[ $import ] ) ) {
					continue;
				}
				$target = isset( $map[ $import ] ) ? self::module_url( $map[ $import ], $base ) : null;
				if ( null === $target || $import !== self::core_module_at( $target, $modules ) ) {
					return null;
				}
				$urls[ $import ] = $target;
				$queue[] = $import;
			}
		}
		// Translations and other classic code printed for these modules must run first.
		foreach ( $scripts as $other ) {
			if ( $other['offset'] === $script['offset'] || in_array( $other['verdict'], array( self::NEUTRAL, self::LOCALIZE_DATA ), true ) ) {
				continue;
			}
			foreach ( array_keys( $urls ) as $id ) {
				if ( false !== strpos( $other['id'], $id ) ) {
					return null;
				}
			}
		}
		return array_values( $urls );
	}

	/**
	 * Core's script module manifest, read once per request.
	 *
	 * @return array<string,CoreModule>|null
	 */
	private static function core_modules(): ?array {
		if ( null === self::$core_modules ) {
			self::$core_modules = self::read_core_modules( ABSPATH . WPINC . '/assets/script-modules-packages.php' ) ?? false;
		}
		return false === self::$core_modules ? null : self::$core_modules;
	}

	/**
	 * Every entry, keyed by the module ID core registers it under
	 * (wp_default_script_modules()). Anything unexpected makes the whole file unusable.
	 *
	 * @param string $path Manifest file.
	 * @return array<string,CoreModule>|null
	 */
	private static function read_core_modules( string $path ): ?array {
		if ( ! is_file( $path ) ) {
			return null;
		}
		$manifest = include $path;
		if ( ! is_array( $manifest ) || ! $manifest ) {
			return null;
		}
		$modules = array();
		foreach ( $manifest as $file => $entry ) {
			if ( ! is_string( $file ) || ! self::matches( '#^[a-z0-9][a-z0-9._/-]*\.js$#D', $file ) || false !== strpos( $file, '..' )
				|| ! is_array( $entry ) || ! isset( $entry['dependencies'] ) || ! is_array( $entry['dependencies'] )
				|| ! isset( $entry['version'] ) || ! is_string( $entry['version'] ) || '' === $entry['version']
				|| ! is_array( $entry['module_dependencies'] ?? array() ) ) {
				return null;
			}
			$imports = array();
			foreach ( $entry['module_dependencies'] ?? array() as $dependency ) {
				if ( ! is_array( $dependency ) || ! isset( $dependency['id'] ) || ! is_string( $dependency['id'] )
					|| ! in_array( $dependency['import'] ?? null, array( 'static', 'dynamic' ), true ) ) {
					return null;
				}
				$imports[] = $dependency['id'];
			}
			$id = '@wordpress/' . preg_replace( '~(?:/index)?(?:\.min)?\.js$~D', '', $file, 1 );
			if ( isset( $modules[ $id ] ) ) {
				return null;
			}
			$modules[ $id ] = array(
				'file'    => $file,
				'version' => $entry['version'],
				'classic' => $entry['dependencies'],
				'imports' => $imports,
			);
		}
		return $modules;
	}

	/**
	 * The one core module served at this URL, from the site or the configured CDN.
	 *
	 * @param string                            $url     Normalised module URL.
	 * @param array<string,CoreModule> $modules Core manifest.
	 */
	private static function core_module_at( string $url, array $modules ): ?string {
		$cdn = null;
		if ( Module_Registry::is_available( 'cdn' ) ) {
			$cdn = Settings_Manager::get( 'cdn' );
			$cdn = is_array( $cdn ) && ! empty( $cdn['enabled'] ) && ! empty( $cdn['cdn_url'] ) ? $cdn : null;
		}
		$found = null;
		foreach ( $modules as $id => $module ) {
			$file = $module['file'];
			// Core ships these minified only; the rest follow SCRIPT_DEBUG.
			$files = 0 === strpos( $file, 'vips/' ) || 'video-conversion/worker.js' === $file
				? array( str_replace( '.js', '.min.js', $file ) )
				: array( $file, str_replace( '.js', '.min.js', $file ) );
			foreach ( $files as $served ) {
				$expected = self::module_url( includes_url( 'js/dist/script-modules/' . $served ) . '?ver=' . $module['version'], '' );
				if ( null === $expected || ( $expected !== $url
					&& ( null === $cdn || self::module_url( Cdn_Rewriter::rewrite_url( $expected, $cdn ), '' ) !== $url ) ) ) {
					continue;
				}
				if ( null !== $found || ! is_file( ABSPATH . WPINC . '/js/dist/script-modules/' . $served ) ) {
					return null;
				}
				$found = $id;
			}
		}
		return $found;
	}

	/**
	 * The page's import map, when it is the single, unscoped map core prints.
	 *
	 * @param array<int,array<string,mixed>> $scripts Scanned scripts.
	 * @return array<string,string>|null Specifier to URL; empty without a map; null when unrecognised.
	 */
	private static function core_import_map( array $scripts ): ?array {
		$imports = null;
		foreach ( $scripts as $script ) {
			if ( 'importmap' !== strtolower( trim( self::attr_value( $script['attrs'], 'type' ) ) ) ) {
				continue;
			}
			if ( null !== $imports || isset( self::attr_names( $script['attrs'] )['src'] ) ) {
				return null;
			}
			$body = trim( (string) $script['body'] );
			$map = json_decode( $body, true );
			if ( ! is_array( $map ) || array( 'imports' ) !== array_keys( $map ) || ! is_array( $map['imports'] )
				|| wp_json_encode( $map, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) !== $body ) {
				return null;
			}
			foreach ( $map['imports'] as $specifier => $target ) {
				if ( ! is_string( $specifier ) || ! is_string( $target ) || '/' === substr( $specifier, -1 ) ) {
					return null;
				}
			}
			$imports = $map['imports'];
		}
		return $imports ?? array();
	}

	/** @param string $script Script attributes. @param string $link Preload attributes. */
	private static function preload_compatible( string $script, string $link ): bool {
		$script_names = self::attr_names( $script );
		$link_names = self::attr_names( $link );
		foreach ( array( 'crossorigin', 'integrity', 'referrerpolicy', 'fetchpriority' ) as $name ) {
			if ( isset( $script_names[ $name ] ) !== isset( $link_names[ $name ] ) || self::attr_value( $script, $name ) !== self::attr_value( $link, $name ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Relative paths need an explicit, stable base. Root-relative URLs need only the site origin.
	 *
	 * @param string $url Decoded attribute value.
	 * @param string $base Known absolute base, or empty when the document URL is unknown.
	 */
	private static function module_url( string $url, string $base ): ?string {
		if ( '' === $url || self::matches( '#[\x00-\x20\\\\]#', $url ) ) {
			return null;
		}
		if ( 0 === strpos( $url, '//' ) || '/' === $url[0] ) {
			$origin = wp_parse_url( '' !== $base ? $base : home_url( '/' ) );
			if ( ! is_array( $origin ) || empty( $origin['scheme'] ) || empty( $origin['host'] ) ) {
				return null;
			}
			$url = 0 === strpos( $url, '//' ) ? $origin['scheme'] . ':' . $url
				: $origin['scheme'] . '://' . $origin['host'] . ( isset( $origin['port'] ) ? ':' . $origin['port'] : '' ) . $url;
		} elseif ( ! self::matches( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
			if ( '' === $base || '?' === $url[0] || '#' === $url[0] ) {
				return null;
			}
			$base_parts = wp_parse_url( $base );
			if ( ! is_array( $base_parts ) || empty( $base_parts['scheme'] ) || empty( $base_parts['host'] ) ) {
				return null;
			}
			$base_path = $base_parts['path'] ?? '/';
			$url = $base_parts['scheme'] . '://' . $base_parts['host']
				. ( isset( $base_parts['port'] ) ? ':' . $base_parts['port'] : '' )
				. substr( $base_path, 0, (int) strrpos( $base_path, '/' ) + 1 ) . $url;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true )
			|| empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		$port = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . strtolower( $parts['host'] )
			. ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ? '' : ':' . $port )
			. ( $parts['path'] ?? '/' )
			. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' )
			. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/**
	 * Base and link tags outside script bodies, comments and inert containers.
	 *
	 * @param string $html Complete page.
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function resource_tags( string $html ): ?array {
		$found = preg_match_all( '#<!--.*?-->|<(script|template|noscript|textarea|style|title|xmp|iframe)\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>.*?</\1\s*>|<(base|link)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>#is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL );
		if ( false === $found ) {
			return null;
		}
		$tags = array();
		foreach ( $matches as $match ) {
			if ( isset( $match[2][0] ) ) {
				$tags[] = array( 'name' => strtolower( $match[2][0] ), 'attrs' => $match[3][0], 'offset' => $match[0][1] );
			}
		}
		return $tags;
	}

	/**
	 * Every <script> element outside comments and inert containers, in
	 * document order. One left-to-right pass, so a `<!--` inside a script
	 * body is not a comment and a `<script>` inside <template> is not a
	 * script.
	 *
	 * @param string $html Complete page HTML.
	 * @return array<int,array<string,mixed>>|null Null on a regex failure.
	 */
	private static function scan( string $html ): ?array {
		$found = preg_match_all(
			'#<!--.*?-->'
			. '|<(template|noscript|textarea|style|title|xmp|iframe)\b[^>]*>.*?</\1\s*>'
			. '|<script\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>(.*?)</script\s*>#is',
			$html,
			$m,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL
		);
		if ( false === $found ) {
			return null;
		}
		$scripts = array();
		foreach ( $m as $match ) {
			if ( ! isset( $match[2][0] ) ) {
				continue;
			}
			$attrs  = (string) $match[2][0];
			$id     = self::attr_value( $attrs, 'id' );
			$handle = preg_match( '#^(.+)-js$#', $id, $h ) ? $h[1] : '';

			$scripts[] = array(
				'tag'    => (string) $match[0][0],
				'offset' => (int) $match[0][1],
				'attrs'  => $attrs,
				'body'   => (string) $match[3][0],
				'id'     => $id,
				'handle' => $handle,
			);
		}
		return $scripts;
	}

	/**
	 * What one script means for the deferred queue.
	 *
	 * @param array<string,mixed> $script  Entry from scan().
	 * @param array<string,true>  $printed Handles whose external tag came earlier.
	 */
	private static function classify( array $script, array $printed ): int {
		$attrs = (string) $script['attrs'];
		$names = self::attr_names( $attrs );
		$type  = strtolower( trim( self::attr_value( $attrs, 'type' ) ) );
		self::check_regex();

		// Delay replay waits for DOMContentLoaded, after this deferred queue.
		if ( isset( $names['data-xs-delay'] ) || isset( $names['data-xs-src'] ) || 'text/xspeed-delayed' === $type ) {
			return self::delay_replay_waits_for_dom_ready() ? self::NEUTRAL : self::ABORT;
		}

		$external = isset( $names['src'] );
		$body     = (string) $script['body'];

		if ( 'module' === $type ) {
			return isset( $names['async'] ) ? self::PIN : self::MODULE;
		}
		if ( ! in_array( $type, self::CLASSIC_TYPES, true ) || isset( $names['nomodule'] ) ) {
			return self::NEUTRAL;
		}

		if ( $external ) {
			if ( isset( $names['async'] ) ) {
				return self::owned( $script ) ? self::NEUTRAL : self::PIN;
			}
			if ( isset( $names['defer'] ) ) {
				return self::DEFERRED;
			}
			if ( Minify_Filters::script_opts_out( '<script' . $attrs . '>' ) ) {
				return self::PIN;
			}
			// Our own scripts (beacons and loaders) read nothing another
			// script defines, so one left at parse time pins nothing.
			if ( 0 === strpos( (string) $script['handle'], 'xspeed-' ) ) {
				return self::NEUTRAL;
			}
			$src = self::attr_value( $attrs, 'src' );
			if ( 0 === stripos( $src, 'data:' ) ) {
				return self::PIN;
			}
			if ( Minify_Filters::keeps_script_in_place( (string) $script['handle'], $src ) ) {
				return self::stays_independent( (string) $script['handle'], $printed ) ? self::NEUTRAL : self::PIN;
			}
			return self::CAN_DEFER;
		}

		if ( '' === trim( $body ) || 0 === strpos( (string) $script['id'], 'xspeed-' ) ) {
			return self::NEUTRAL;
		}
		if ( Minify_Filters::script_opts_out( '<script' . $attrs . '>' ) ) {
			return self::PIN;
		}
		if ( strlen( $body ) > self::MAX_BODY_BYTES || self::matches( self::STAYS_AT_PARSE_TIME_RE, $body ) ) {
			return self::PIN;
		}
		if ( Minify_Filters::keeps_script_in_place( '', $body ) ) {
			return self::PIN;
		}
		if ( self::extra_globals( $script ) ) {
			return self::LOCALIZE_DATA;
		}
		return self::CAN_CONVERT;
	}

	/**
	 * Whether an excluded script can stay at parse time below deferred ones:
	 * WordPress knows its dependencies and none of them was printed before
	 * it. One nobody registered could read anything, so it pins.
	 *
	 * @param string             $handle  Script handle, '' for a raw tag.
	 * @param array<string,true> $printed Handles printed earlier.
	 */
	private static function stays_independent( string $handle, array $printed ): bool {
		if ( '' === $handle || ! function_exists( 'wp_scripts' ) ) {
			return false;
		}
		$scripts = wp_scripts();
		if ( ! $scripts instanceof \WP_Scripts || ! isset( $scripts->registered[ $handle ] ) ) {
			return false;
		}
		$stack = (array) ( $scripts->registered[ $handle ]->deps ?? array() );
		$seen  = array();
		while ( $stack ) {
			$dep = (string) array_pop( $stack );
			if ( isset( $seen[ $dep ] ) ) {
				continue;
			}
			$seen[ $dep ] = true;
			if ( isset( $printed[ $dep ] ) ) {
				return false;
			}
			if ( isset( $scripts->registered[ $dep ] ) ) {
				$stack = array_merge( $stack, (array) ( $scripts->registered[ $dep ]->deps ?? array() ) );
			}
		}
		return true;
	}

	/**
	 * Offset of the last element whose inline event handler calls jQuery,
	 * outside scripts, comments and inert containers; -1 when none.
	 *
	 * @param string                         $html    Complete page HTML.
	 * @param array<int,array<string,mixed>> $scripts Entries from scan().
	 * @return int|null Null on a regex failure.
	 */
	private static function last_jquery_handler_offset( string $html, array $scripts ): ?int {
		if ( ! preg_match( '#\son[a-z]+\s*=#i', $html ) ) {
			return -1;
		}
		// Blank out script bodies so handler-looking strings in code don't count.
		foreach ( $scripts as $script ) {
			$html = substr_replace( $html, str_repeat( ' ', strlen( $script['tag'] ) ), $script['offset'], strlen( $script['tag'] ) );
		}
		$found = preg_match_all( '#<!--.*?-->|<(?:template|noscript|textarea|style|title|xmp|iframe)\b.*?</(?:template|noscript|textarea|style|title|xmp|iframe)\s*>|\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL );
		if ( false === $found ) {
			return null;
		}
		$last = -1;
		foreach ( $m as $match ) {
			if ( isset( $match[1][0] ) && preg_match( self::JQUERY_CALL_RE, html_entity_decode( (string) $match[1][0], ENT_QUOTES ) ) ) {
				$last = (int) $match[0][1];
			}
		}
		return $last;
	}

	/**
	 * The inline block as a deferred classic script carrying its body in a
	 * data: URL. Base64, not percent-encoding: a fixed third larger and no
	 * escaping edge cases. With no charset in the URL the browser decodes it
	 * as the page's own encoding, which is what the body was written in.
	 *
	 * @param array<string,mixed> $script Entry from scan().
	 */
	private static function converted( array $script ): string {
		// `type` is classic anyway, and `async`/`defer` did nothing on an
		// inline tag but would reorder an external one.
		$kept = (string) preg_replace_callback(
			'#\s+([^\s=/>]+)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?#s',
			static function ( array $match ): string {
				return in_array( strtolower( $match[1] ), array( 'type', 'async', 'defer' ), true ) ? '' : $match[0];
			},
			(string) $script['attrs']
		);
		self::check_regex();
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Replace this exact inline position after enqueueing has finished.
		return '<script defer="defer" src="data:text/javascript;base64,'
			. base64_encode( (string) $script['body'] ) . '"' . $kept . '></script>'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data: URL transport for the page's own inline script, not obfuscation.
	}

	/**
	 * Whether Delay JS holds its replay until the real DOMContentLoaded,
	 * which fires only after every deferred script has run.
	 */
	private static function delay_replay_waits_for_dom_ready(): bool {
		return true;
	}

	/**
	 * Whether the response is HTML. No Content-Type header counts as HTML,
	 * which is what PHP sends by default.
	 *
	 * @param string[] $headers Response headers.
	 */
	private static function is_html_response( array $headers ): bool {
		foreach ( $headers as $header ) {
			if ( 0 === stripos( (string) $header, 'content-type:' ) ) {
				return false !== stripos( (string) $header, 'html' );
			}
		}
		return true;
	}

	/**
	 * Whether a Content-Security-Policy could block a data: script.
	 *
	 * Any enforced policy with a script directive counts, even one that
	 * lists `data:`, and so does a nonce on any script: a nonce means a
	 * policy exists, and the web server or a CDN may be the one sending it.
	 *
	 * @param string[] $headers Response headers.
	 * @param string   $html    Complete page HTML.
	 */
	private static function csp_governs_scripts( array $headers, string $html ): bool {
		$directive = '#(?:^|[;\s,:])(?:script-src|script-src-elem|default-src)\b#i';
		foreach ( $headers as $header ) {
			if ( preg_match( '#^content-security-policy\s*:(.*)$#is', (string) $header, $m ) && preg_match( $directive, $m[1] ) ) {
				return true;
			}
		}
		if ( preg_match_all( '#<meta\b[^>]*http-equiv\s*=\s*["\']?content-security-policy[^>]*>#i', $html, $metas ) ) {
			foreach ( $metas[0] as $meta ) {
				if ( preg_match( $directive, self::attr_value( $meta, 'content' ) ) ) {
					return true;
				}
			}
		}
		return (bool) preg_match( '#<script\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*\snonce\s*=#i', $html );
	}

	/**
	 * Attribute names on a tag, lowercased, as a set.
	 *
	 * @param string $attrs Attribute string.
	 * @return array<string,true>
	 */
	private static function attr_names( string $attrs ): array {
		return array_fill_keys( array_keys( self::attributes( $attrs ) ), true );
	}

	/**
	 * One attribute's value, '' when absent.
	 *
	 * @param string $attrs Attribute string or opening tag.
	 * @param string $name  Attribute name.
	 */
	private static function attr_value( string $attrs, string $name ): string {
		return self::attributes( $attrs )[ $name ] ?? '';
	}

	/**
	 * Read complete attributes so a name inside another value cannot match.
	 * Duplicate attributes make the page ambiguous and stop the pass.
	 *
	 * @param string $attrs Attribute string or opening tag.
	 * @return array<string,string>
	 */
	private static function attributes( string $attrs ): array {
		$values = array();
		$found = preg_match_all( '#\s+([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?#s', $attrs, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL );
		if ( false === $found ) {
			self::$regex_failed = true;
			return $values;
		}
		foreach ( $matches as $match ) {
			$name = strtolower( $match[1] );
			if ( array_key_exists( $name, $values ) ) {
				self::$regex_failed = true;
			}
			$values[ $name ] = html_entity_decode( $match[2] ?? $match[3] ?? $match[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $values;
	}

	/**
	 * preg_match() that records a failure instead of reading it as "no".
	 *
	 * @param string $re      Pattern.
	 * @param string $subject Subject.
	 */
	private static function matches( string $re, string $subject ): bool {
		$hit = preg_match( $re, $subject );
		if ( false === $hit ) {
			self::$regex_failed = true;
		}
		return 1 === $hit;
	}

	/** Record a failure of the last preg_* call, ours or a helper's. */
	private static function check_regex(): void {
		if ( PREG_NO_ERROR !== preg_last_error() ) {
			self::$regex_failed = true;
		}
	}
}
