<?php
/**
 * The WP_CACHE define, and how to strip it out of wp-config.php.
 *
 * Deliberately a plain function in its own file rather than a method on
 * Cache: `uninstall.php` runs standalone — WordPress loads it without
 * bootstrapping the plugin, so no class of ours exists there. Keeping the
 * pattern in one requirable file is what stops the two call sites drifting.
 * They already had, which is the bug this fixes: the removal regex was
 * copy-pasted, and hardening one copy would have left the other broken.
 *
 * @package XSpeed
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'xspeed_has_canonical_dropin_signature' ) ) {
	/**
	 * Prove xSpeed ownership from an exact standalone PHP comment marker.
	 *
	 * A loose substring is unsafe: foreign code or data can mention the token.
	 * Tokenization excludes strings, while the anchored pattern excludes prose.
	 *
	 * @param string $source advanced-cache.php contents.
	 * @return bool
	 */
	function xspeed_has_canonical_dropin_signature( $source ) {
		if ( ! is_string( $source ) || '' === $source || ! function_exists( 'token_get_all' ) ) {
			return false;
		}
		/*
		 * ONE channel: an anchored token comment. That is what every xSpeed
		 * drop-in ever shipped carries (verified across this file's whole
		 * history).
		 *
		 * There used to be a second channel that accepted a PHP identifier
		 * named XSPEED_DROPIN, and it leaked three times: first any mention
		 * of the identifier anywhere in the file, then — once narrowed to a
		 * `const` declaration — `use const XSPEED_DROPIN` and
		 * `class Foo { const XSPEED_DROPIN = 1; }`. Each fix narrowed the
		 * shape and the next review found another one, because "a token
		 * arrangement only we would write" is not a property you can pin
		 * down by enumeration.
		 *
		 * It is gone rather than narrowed a fourth time. remove_dropin() and
		 * uninstall.php DELETE on this verdict, so a false positive destroys
		 * another plugin's live page cache; the channel bought nothing our
		 * own files ever needed.
		 */
		$comments = array();
		foreach ( token_get_all( $source ) as $token ) {
			$id = is_array( $token ) ? $token[0] : null;
			if ( T_OPEN_TAG === $id || T_WHITESPACE === $id ) {
				continue;
			}
			// A BOM, or blank bytes, before `<?php` arrive as inline HTML. An
			// editor or FTP client re-saving our own drop-in that way must not
			// turn it foreign — we would then refuse to update or remove our
			// own file, and uninstall would leave it behind.
			if ( T_INLINE_HTML === $id && '' === trim( $token[1], " \t\r\n\0\x0B\xEF\xBB\xBF" ) ) {
				continue;
			}
			if ( ! in_array( $id, array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				break; // Real code before any comment: there is no header.
			}
			foreach ( preg_split( '/\R/', $token[1] ) ?: array() as $line ) {
				$line = preg_replace( '/^\s*(?:\/\*+|\*|\/\/|#)\s*/', '', $line );
				$line = preg_replace( '/\s*\*\/\s*$/', '', (string) $line );
				if ( '' !== trim( (string) $line ) ) {
					$comments[] = trim( (string) $line );
				}
			}
			break;
		}
		// The marker must OPEN the header. A competitor that documents rival
		// markers line by line, or carries an interop note, has not handed us
		// its file — and this verdict authorizes deleting their live drop-in.
		$first = isset( $comments[0] ) ? $comments[0] : '';
		if ( '' !== $first && preg_match( '/^XSPEED_DROPIN(?:\s+(?:v(?:ersion)?\s*)?\d[A-Za-z0-9._-]*)?\s*$/i', $first ) ) {
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'xspeed_wp_cache_receipt_matches' ) ) {
	/**
	 * Current-source proof that the stored xSpeed receipt still owns the line.
	 *
	 * @param string $source  wp-config.php contents.
	 * @param string $receipt Stored ownership receipt.
	 * @return bool
	 */
	function xspeed_wp_cache_receipt_matches( $source, $receipt ) {
		return is_string( $source ) && is_string( $receipt )
			&& 1 === preg_match( '/^[a-f0-9]{32}$/', $receipt )
			&& false !== strpos( $source, 'xSpeed owner:' . $receipt );
	}
}

if ( ! function_exists( 'xspeed_alternative_block_openers' ) ) {
	/**
	 * Token indexes of the `:` that opens an alternative-syntax block.
	 *
	 * Resolved by walking each control structure's condition to its matching
	 * `)` and asking what follows, because the condition can contain anything
	 * — a `for` header always contains semicolons, a closure or `match` in an
	 * `if` contains braces, a named argument contains its own colon. An
	 * earlier version watched for a `:` after an opener keyword and let any
	 * `;` or `{` cancel it, which meant `for ( $i = 0; $i < $n; $i++ ):`
	 * never registered at all and a define inside it read as top level —
	 * licensing a rewrite of somebody's conditional configuration.
	 *
	 * `else` and `elseif` are deliberately absent: they CONTINUE the block
	 * their `if` opened, and counting them left the depth permanently
	 * inflated so every later top-level define looked conditional.
	 *
	 * @param array<int,mixed> $raw Output of token_get_all().
	 * @return array<int,bool> Indexes, as keys.
	 */
	function xspeed_alternative_block_openers( array $raw ) {
		$trivia  = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );
		$openers = array( T_IF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH );
		if ( defined( 'T_DECLARE' ) ) {
			$openers[] = T_DECLARE;
		}
		$next = static function ( $from ) use ( $raw, $trivia ) {
			$count = count( $raw );
			for ( $i = $from; $i < $count; $i++ ) {
				if ( ! is_array( $raw[ $i ] ) || ! in_array( $raw[ $i ][0], $trivia, true ) ) {
					return $i;
				}
			}
			return null;
		};

		$opens = array();
		$count = count( $raw );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $raw[ $i ] ) || ! in_array( $raw[ $i ][0], $openers, true ) ) {
				continue;
			}
			$open = $next( $i + 1 );
			if ( null === $open || '(' !== $raw[ $open ] ) {
				continue;
			}
			$depth = 0;
			$close = null;
			for ( $j = $open; $j < $count; $j++ ) {
				if ( '(' === $raw[ $j ] ) {
					++$depth;
				} elseif ( ')' === $raw[ $j ] ) {
					--$depth;
					if ( 0 === $depth ) {
						$close = $j;
						break;
					}
				}
			}
			if ( null === $close ) {
				continue;
			}
			$after = $next( $close + 1 );
			if ( null !== $after && ':' === $raw[ $after ] ) {
				$opens[ $after ] = true;
			}
		}
		return $opens;
	}
}

if ( ! function_exists( 'xspeed_parse_wp_cache_defines' ) ) {
	/**
	 * Token-aware WP_CACHE parser.
	 *
	 * Comments and strings are tokens, so text that merely contains a fake
	 * define can never become configuration. Offsets are byte offsets into the
	 * original source and are used by the rewriter below.
	 *
	 * @param string $source wp-config.php contents.
	 * @return array{state:string,defines:array<int,array{start:int,end:int,value:string}>}
	 */
	function xspeed_parse_wp_cache_defines( $source ) {
		if ( ! is_string( $source ) || '' === $source || ! function_exists( 'token_get_all' ) ) {
			return array(
				'state'   => 'undefined',
				'defines' => array(),
			);
		}

		$raw    = token_get_all( $source );
		$tokens = array();
		$offset = 0;
		$depth  = 0;
		$alt       = 0;
		$alt_opens = xspeed_alternative_block_openers( $raw );
		$alt_close = array();
		foreach ( array( 'T_ENDIF', 'T_ENDWHILE', 'T_ENDFOR', 'T_ENDFOREACH', 'T_ENDSWITCH', 'T_ENDDECLARE' ) as $name ) {
			if ( defined( $name ) ) {
				$alt_close[] = constant( $name );
			}
		}
		foreach ( $raw as $index => $token ) {
			$id   = is_array( $token ) ? $token[0] : null;
			$text = is_array( $token ) ? $token[1] : $token;
			/*
			 * Brace depth at the START of each token. A define nested inside
			 * anything — a host's `if ( ! defined( 'WP_CACHE' ) ) { … }`, an
			 * environment switch — is not a statement we may rewrite: the
			 * literal we read is not necessarily what runs, and replacing it
			 * edits someone's conditional configuration.
			 *
			 * Only RAW `{` / `}` count. A `}` inside an interpolated string
			 * ("}{$a}", or the heredoc equivalent) arrives as a typed
			 * T_ENCAPSED_AND_WHITESPACE token whose text happens to be `}`;
			 * counting it desynced the depth and unmasked a define that
			 * really was inside someone's block. The interpolation OPENERS
			 * are typed (T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES) and are
			 * closed by a raw `}`, so they are counted to keep the pair
			 * balanced.
			 */
			/*
			 * Alternative syntax opens a block with `:` and closes it with an
			 * `endif`/`endwhile`/... keyword, no braces at all. Tracking only
			 * braces meant a define that was not the FIRST statement of such
			 * a block read as top level — the boundary token in front of it
			 * is the previous statement's `;` — so an enable rewrote a host's
			 * staging-only define in place and a disable cut it out of the
			 * block.
			 */
			if ( in_array( $id, $alt_close, true ) && $alt > 0 ) {
				--$alt;
			}
			// A BOM or stray blank bytes before `<?php` arrive as inline HTML
			// and must not be mistaken for code.
			if ( T_INLINE_HTML === $id && '' === trim( $text, " \t\r\n\0\x0B\xEF\xBB\xBF" ) ) {
				$offset += strlen( $text );
				continue;
			}
			$raw_punct = ( null === $id );
			if ( $raw_punct && '}' === $text && $depth > 0 ) {
				--$depth;
			}
			$tokens[] = array(
				'id'    => $id,
				'text'  => $text,
				'start' => $offset,
				'end'   => $offset + strlen( $text ),
				'depth' => $depth + $alt,
			);
			if ( ( $raw_punct && '{' === $text )
				|| ( defined( 'T_CURLY_OPEN' ) && T_CURLY_OPEN === $id )
				|| ( defined( 'T_DOLLAR_OPEN_CURLY_BRACES' ) && T_DOLLAR_OPEN_CURLY_BRACES === $id ) ) {
				++$depth;
			} elseif ( isset( $alt_opens[ $index ] ) ) {
				++$alt;
			}
			$offset += strlen( $text );
		}

		$is_trivia = static function ( $token ) {
			return in_array( $token['id'], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
		};
		$next = static function ( $index ) use ( $tokens, $is_trivia ) {
			for ( $index++; isset( $tokens[ $index ] ); $index++ ) {
				if ( ! $is_trivia( $tokens[ $index ] ) ) {
					return $index;
				}
			}
			return null;
		};
		$previous = static function ( $index ) use ( $tokens, $is_trivia ) {
			for ( $index--; 0 <= $index; $index-- ) {
				if ( ! $is_trivia( $tokens[ $index ] ) ) {
					return $index;
				}
			}
			return null;
		};

		$defines = array();
		$count   = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			/*
			 * `define` and `\define` are the same call, and wp-config.php files
			 * in the wild are written both ways. PHP 8 tokenises the qualified
			 * form as ONE T_NAME_FULLY_QUALIFIED token (`\define`), so matching
			 * T_STRING alone made the define invisible: the detector then read
			 * a site with WP_CACHE on as unclaimed and let an acquisition
			 * through, and the rewriter inserted a SECOND define instead of
			 * replacing the one that was there.
			 *
			 * PHP 7.4 splits it into T_NS_SEPARATOR + T_STRING, which did
			 * match — so the behaviour differed by PHP version, and there the
			 * recorded start missed the leading `\`, leaving a bare backslash
			 * in wp-config.php after a strip. `$start` covers the separator
			 * for that reason.
			 */
			$start = $tokens[ $i ]['start'];
			if ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $tokens[ $i ]['id'] ) {
				if ( '\\define' !== strtolower( $tokens[ $i ]['text'] ) ) {
					continue;
				}
			} elseif ( T_STRING === $tokens[ $i ]['id'] && 'define' === strtolower( $tokens[ $i ]['text'] ) ) {
				$prior = $previous( $i );
				if ( null !== $prior && in_array( $tokens[ $prior ]['id'], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
					continue;
				}
				if ( null !== $prior && T_NS_SEPARATOR === $tokens[ $prior ]['id'] ) {
					// PHP 7.4 splits `\define` into T_NS_SEPARATOR + T_STRING.
					// `Foo\define` splits the same way, so check what sits in
					// front of the separator: a name there means someone
					// else's function, not core's, and stepping $start back
					// over the separator would leave `Foo` dangling.
					$before = $previous( $prior );
					if ( null !== $before && in_array( $tokens[ $before ]['id'], array( T_STRING, T_NS_SEPARATOR ), true ) ) {
						continue;
					}
					$start = $tokens[ $prior ]['start'];
				}
			} else {
				continue;
			}
			$open = $next( $i );
			$name = null !== $open ? $next( $open ) : null;
			if ( null === $name || '(' !== $tokens[ $open ]['text'] || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $name ]['id'] ) {
				continue;
			}
			$constant = trim( $tokens[ $name ]['text'], "'\"" );
			$comma    = $next( $name );
			if ( 'WP_CACHE' !== strtoupper( $constant ) || null === $comma || ',' !== $tokens[ $comma ]['text'] ) {
				continue;
			}

			$depth       = 1;
			$close       = null;
			for ( $j = $comma + 1; $j < $count; $j++ ) {
				if ( '(' === $tokens[ $j ]['text'] ) {
					++$depth;
				} elseif ( ')' === $tokens[ $j ]['text'] && 0 === --$depth ) {
					$close = $j;
					break;
				}
			}
			if ( null === $close ) {
				continue;
			}
			$end_token = $next( $close );
			$end       = ( null !== $end_token && ';' === $tokens[ $end_token ]['text'] ) ? $tokens[ $end_token ]['end'] : $tokens[ $close ]['end'];
			$value = '';
			for ( $j = $comma + 1; $j < $close; $j++ ) {
				if ( ! $is_trivia( $tokens[ $j ] ) ) {
					$value .= $tokens[ $j ]['text'];
				}
			}
			/*
			 * Is this define a statement of its own, or the body of something?
			 *
			 * Brace depth alone missed the braceless forms, which are the
			 * common ones in a wp-config.php:
			 *
			 *     if ( ! defined( 'WP_CACHE' ) ) define( 'WP_CACHE', true );
			 *     if ( $host === 'staging' )
			 *         define( 'WP_CACHE', false );
			 *
			 * Both read as top level, so an enable rewrote a host's
			 * conditional in place and a disable cut the statement out —
			 * leaving a dangling `if` that silently captures whatever
			 * statement follows it. A define whose `DB_NAME` neighbour became
			 * conditional is a far worse outcome than a refusal.
			 *
			 * A real statement can only follow `<?php`, `;`, `{`, `}`, or
			 * nothing at all. Anything else — `)` closing a control
			 * condition, `else`, `do`, the `:` of alternative syntax — means
			 * we are the controlled statement. That closes the `if ( … ):
			 * … endif;` gap too.
			 */
			$before_statement = $previous( $i );
			if ( null !== $before_statement && $start !== $tokens[ $i ]['start'] ) {
				// The qualified form: step back past the separator we adopted.
				$before_statement = $previous( $before_statement );
			}
			$boundary = null === $before_statement
				|| T_OPEN_TAG === $tokens[ $before_statement ]['id']
				|| in_array( $tokens[ $before_statement ]['text'], array( ';', '{', '}' ), true );

			$defines[] = array(
				'start'       => $start,
				'end'         => $end,
				'value'       => $value,
				'conditional' => $tokens[ $i ]['depth'] > 0 || ! $boundary,
			);
			$i = $close;
		}

		if ( 0 === count( $defines ) ) {
			$state = 'undefined';
		} elseif ( 1 < count( $defines ) ) {
			$state = 'duplicate';
		} elseif ( ! empty( $defines[0]['conditional'] ) ) {
			$state = 'conditional';
		} else {
			$value = strtolower( trim( $defines[0]['value'] ) );
			if ( preg_match( '/^(?:true|1|[\'\"]1[\'\"])$/i', $value ) ) {
				$state = 'true';
			} elseif ( preg_match( '/^(?:false|0|null|[\'\"](?:0)?[\'\"])$/i', $value ) ) {
				$state = 'false';
			} else {
				$state = 'dynamic';
			}
		}
		return array(
			'state'   => $state,
			'defines' => $defines,
		);
	}
}

if ( ! function_exists( 'xspeed_drop_receipt_comment' ) ) {
	/**
	 * Remove the `// xSpeed owner:<hex>` receipt that trails a WP_CACHE
	 * define, taking the whole comment with it.
	 *
	 * The parser's offsets stop at the `;`, so the receipt we wrote sits
	 * outside every rewrite and every removal. Consuming only as far as the
	 * hex left anything a user had appended to OUR comment standing as bare
	 * code:
	 *
	 *     define( 'WP_CACHE', true ); // xSpeed owner:abc123 do not remove
	 *
	 * became `do not remove` at top level — a parse error in wp-config.php,
	 * so the site white-screens. A `//` comment runs to end of line by
	 * definition, so consuming to end of line removes exactly the comment
	 * token and nothing else. It also clears the stacked receipts left by
	 * the historic append bug, which all share that one line.
	 *
	 * @param string $tail Source immediately after the define's `;`.
	 * @return string
	 */
	function xspeed_drop_receipt_comment( $tail ) {
		if ( ! is_string( $tail ) ) {
			return $tail;
		}
		return (string) preg_replace( '#^[ \t]*//[ \t]*xSpeed owner:[a-f0-9]*[^\r\n]*#i', '', $tail, 1 );
	}
}

if ( ! function_exists( 'xspeed_rewrite_wp_cache_define' ) ) {
	/**
	 * Return rewritten source, or null when the source is ambiguous.
	 *
	 * @param string $source wp-config.php contents.
	 * @param bool   $enable Desired literal state.
	 * @param string $marker Optional xSpeed ownership receipt.
	 * @return string|null
	 */
	function xspeed_rewrite_wp_cache_define( $source, $enable, $marker = '' ) {
		$parsed = xspeed_parse_wp_cache_defines( $source );
		if ( in_array( $parsed['state'], array( 'duplicate', 'dynamic', 'conditional' ), true ) ) {
			return null;
		}
		$suffix      = '' !== $marker ? ' // xSpeed owner:' . preg_replace( '/[^a-f0-9]/i', '', $marker ) : '';
		$replacement = $enable ? "define( 'WP_CACHE', true );" . $suffix : '';
		if ( ! empty( $parsed['defines'] ) ) {
			$define = $parsed['defines'][0];
			/*
			 * The parser's `end` is the `;` of the define, so the ownership
			 * receipt WE wrote last time sits just past it and is not part of
			 * what gets replaced. Rewriting therefore used to append a second
			 * receipt to the same line, and a third, and a fourth: the enable
			 * transaction runs on every admin_init, so the line grew by 26
			 * bytes per wp-admin request. Disabling had the mirror bug — the
			 * define went, the receipt comment stayed behind forever.
			 *
			 * Consume it here so a rewrite is idempotent and a removal is
			 * complete. Only our own comment, only when it directly follows
			 * the statement.
			 */
			$tail = substr( $source, $define['end'] );
			$tail = xspeed_drop_receipt_comment( $tail );
			return substr( $source, 0, $define['start'] ) . $replacement . $tail;
		}
		if ( ! $enable ) {
			return $source;
		}
		$position = strpos( $source, '<?php' );
		if ( false === $position ) {
			return null;
		}
		$position += 5;
		return substr( $source, 0, $position ) . "\n" . $replacement . substr( $source, $position );
	}
}

if ( ! function_exists( 'xspeed_strip_wp_cache_define' ) ) {
	/**
	 * Remove every `define( 'WP_CACHE', … );` statement from wp-config source.
	 *
	 * The old pattern hardcoded a lowercase `true` with no `/i`, so
	 * `TRUE`, `True`, `1`, `'1'` and `"1"` never matched — disabling the
	 * cache (or uninstalling) silently left the constant behind. Those
	 * spellings are common: several hosts' one-click stacks and older
	 * tutorials write `TRUE` or `1`, and any plugin that previously owned
	 * the constant may have written it in its own style. The orphan then
	 * confuses the next caching plugin the site installs — W3 Total Cache
	 * and WP Rocket both branch on it — and makes a "clean uninstall" not
	 * clean. (#9)
	 *
	 * Uses the token parser above, so comments and string contents are ignored
	 * while any real spelling is removed:
	 * true / TRUE / 1 / '1' / false / 0, with or without inner spaces.
	 * `false` is removed too — leaving `define( 'WP_CACHE', false );`
	 * behind is the same orphan problem, just quieter.
	 *
	 * Only our own statement shape is targeted. A commented-out line keeps
	 * its `#`/`//` prefix and is left alone by the leading-boundary match;
	 * a define built dynamically (concatenation, a variable) is not a
	 * literal statement and is out of scope for a regex — those are
	 * vanishingly rare in wp-config.php and unsafe to rewrite blind.
	 *
	 * @param string $config Raw wp-config.php contents.
	 * @return string Contents with the define(s) removed.
	 */
	function xspeed_strip_wp_cache_define( $config ) {
		if ( ! is_string( $config ) || '' === $config ) {
			return $config;
		}

		$parsed = xspeed_parse_wp_cache_defines( $config );
		foreach ( array_reverse( $parsed['defines'] ) as $define ) {
			// A define inside someone's conditional is theirs, and cutting the
			// statement out of the block is how a `{ … }` loses its body.
			if ( ! empty( $define['conditional'] ) ) {
				continue;
			}
			// Take our ownership receipt comment with the statement it
			// annotates; leaving `// xSpeed owner:…` in an uninstalled site's
			// wp-config.php is the same orphan this function exists to stop.
			$tail   = xspeed_drop_receipt_comment( substr( $config, $define['end'] ) );
			$config = substr( $config, 0, $define['start'] ) . $tail;
		}
		return $config;
	}
}
