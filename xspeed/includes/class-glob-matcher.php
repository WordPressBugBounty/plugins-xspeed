<?php
/**
 * Glob_Matcher — translate user-friendly glob patterns to PCRE for the
 * cache engine's exclusion checks.
 *
 * Supported syntax (shell-style glob, NOT full PCRE):
 *   *     → match any run of characters (greedy), including '/'
 *   ?     → match exactly one character
 *   [abc] → character class
 *   \*    → literal asterisk (escape)
 *
 * Compiles each user pattern once, caches the compiled regex in a
 * static array for the request, then matches with a single preg_match.
 *
 * A bare substring (no glob metacharacters) keeps the legacy "contains"
 * semantics: `/cart` matches `/cart`, `/cart/items`, `/foo/cart/bar`.
 * Adding ANY of `* ? [` switches the pattern to anchored glob mode:
 * `/cart/*` matches `/cart/items` but NOT `/foo/cart/bar`.
 *
 * RAW REGEX: a pattern prefixed with `~` is treated as a raw PCRE
 * (unanchored) — `~utm_[a-z0-9_-]+` matches like a real regex. This lets
 * users paste LiteSpeed / WP Rocket exclusion lists (which are regex)
 * verbatim by adding the `~` marker. Invalid or over-long regex patterns
 * are rejected safely (never match, never fatal, never match-everything).
 *
 * NAME MODE (`matches_name()` / `any_match_name()`) matches an identifier —
 * a query parameter name — instead of a URL, and there "contains" is the
 * wrong contract: `ref` would swallow `preference`, `product_ref` and
 * `referrer`, collapsing genuinely different pages onto one cache key. In
 * name mode a bare pattern is an exact, case-insensitive match, and BOTH
 * the glob and `~regex` forms are anchored, so `~utm_[a-z0-9_-]+` matches
 * `utm_source` but not `my_utm_source`. URL matching keeps the contains
 * semantics above — a path exclusion of `/cart` is meant to catch
 * `/cart/items`.
 *
 * Cookie names deliberately do NOT use name mode: the shipped defaults are
 * prefixes of hash-suffixed real cookies (`comment_author` must match
 * `comment_author_<hash>`), so tightening them would serve shared cached
 * pages to commenters and logged-in-adjacent visitors.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Glob_Matcher {

	/**
	 * @var array<string,string> pattern => compiled regex
	 */
	private static $compiled = array();

	/**
	 * Does any of `$patterns` match `$subject`?
	 *
	 * @param string[] $patterns
	 */
	public static function any_match( array $patterns, string $subject ): bool {
		foreach ( $patterns as $p ) {
			$p = (string) $p;
			if ( '' === $p ) {
				continue;
			}
			if ( self::matches( $p, $subject ) ) {
				return true;
			}
		}
		return false;
	}

	public static function matches( string $pattern, string $subject ): bool {
		$regex = self::compile( $pattern );
		// Anchored glob (regex returned starts with '#^') vs substring
		// (regex returned starts with '#'). Both use preg_match the
		// same way; the anchoring is baked into the pattern.
		return 1 === preg_match( $regex, $subject );
	}

	/**
	 * Does any of `$patterns` match the identifier `$name`?
	 *
	 * Name mode: whole-string matching for every pattern form. See the class
	 * docblock for why an identifier must not use contains semantics.
	 *
	 * @param string[] $patterns
	 */
	public static function any_match_name( array $patterns, string $name ): bool {
		foreach ( $patterns as $p ) {
			$p = (string) $p;
			if ( '' === $p ) {
				continue;
			}
			if ( self::matches_name( $p, $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Match one pattern against an identifier, whole-string and
	 * case-insensitively.
	 */
	public static function matches_name( string $pattern, string $name ): bool {
		return 1 === preg_match( self::compile_name( $pattern ), $name );
	}

	/**
	 * Compile a pattern for name mode. Cached separately from URL mode —
	 * the same pattern compiles to a different regex in each — under a key
	 * no user pattern can produce, since a NUL byte cannot survive the
	 * settings sanitizer.
	 */
	public static function compile_name( string $pattern ): string {
		$cache_key = "\0name:" . $pattern;
		if ( isset( self::$compiled[ $cache_key ] ) ) {
			return self::$compiled[ $cache_key ];
		}

		if ( '' !== $pattern && '~' === $pattern[0] ) {
			// Anchored, unlike URL mode: an unanchored `~utm_[a-z0-9_-]+`
			// still contains-matches `my_utm_source`, which is the very
			// over-match name mode exists to stop.
			$regex = self::compile_regex( substr( $pattern, 1 ), true );
		} elseif ( preg_match( '/(?<!\\\\)[*?\[]/', $pattern ) ) {
			$regex = '#^' . self::glob_to_regex( $pattern ) . '$#i';
		} else {
			// Bare pattern → exact name. Resolve `\X` → `X` first so an
			// escaped metacharacter matches itself.
			$resolved = preg_replace( '/\\\\(.)/', '$1', $pattern );
			$regex    = '#^' . preg_quote( (string) $resolved, '#' ) . '$#i';
		}

		self::$compiled[ $cache_key ] = $regex;
		return $regex;
	}

	/**
	 * Compile a user pattern to a PCRE delimited with `#`. Cached
	 * for the request lifetime.
	 */
	public static function compile( string $pattern ): string {
		if ( isset( self::$compiled[ $pattern ] ) ) {
			return self::$compiled[ $pattern ];
		}
		// Raw-regex mode: a leading `~` marks the rest as a PCRE pattern.
		// We validate it once and store either the usable regex or a
		// never-matching sentinel, so a malformed user pattern degrades to
		// "matches nothing" instead of fataling or matching everything.
		if ( '' !== $pattern && '~' === $pattern[0] ) {
			$regex                      = self::compile_regex( substr( $pattern, 1 ) );
			self::$compiled[ $pattern ] = $regex;
			return $regex;
		}
		// Decide mode based on UNESCAPED glob metacharacters only.
		// `\*` alone keeps the pattern in substring mode (with escapes
		// resolved); `/cart/*` flips to anchored glob mode.
		$has_unescaped_glob = (bool) preg_match( '/(?<!\\\\)[*?\[]/', $pattern );
		if ( $has_unescaped_glob ) {
			$regex = '#^' . self::glob_to_regex( $pattern ) . '$#';
		} else {
			// Substring "contains" mode. Resolve `\X` → `X` first so
			// `\*` matches a literal asterisk anywhere in the subject.
			$resolved = preg_replace( '/\\\\(.)/', '$1', $pattern );
			$regex    = '#' . preg_quote( (string) $resolved, '#' ) . '#';
		}
		self::$compiled[ $pattern ] = $regex;
		return $regex;
	}

	/**
	 * A delimited PCRE that can never match any input — used as the safe
	 * fallback for invalid / over-long user regex patterns. `(?!)` is the
	 * empty negative lookahead: it fails at every position.
	 */
	private const NEVER = '#(?!)#';

	/**
	 * Validate + delimit a user-supplied raw regex (the part after `~`).
	 * Returns a `#…#` delimited PCRE (unanchored, so it matches like a
	 * "contains" regex — anchored and case-insensitive in name mode), or the
	 * NEVER sentinel when the pattern is empty, too long, or not a valid
	 * PCRE. We never let a bad pattern through: a regex that errors at match
	 * time would otherwise emit warnings on every cached request.
	 *
	 * @param string $body     The pattern after the `~` marker.
	 * @param bool   $anchored Wrap in `^(?:…)$` and match case-insensitively.
	 */
	private static function compile_regex( string $body, bool $anchored = false ): string {
		// Cap length to keep compile + match cheap and bound backtracking
		// exposure from pathological user input.
		if ( '' === $body || strlen( $body ) > 200 ) {
			return self::NEVER;
		}
		$escaped = str_replace( '#', '\\#', $body );
		// Group before anchoring so a top-level alternation (`a|b`) anchors
		// as a whole rather than binding `^` to the first branch only.
		$regex = $anchored ? '#^(?:' . $escaped . ')$#i' : '#' . $escaped . '#';
		// Validate by compiling against an empty subject. preg_match returns
		// false on a malformed pattern; suppress the warning it emits.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- intentional: invalid user regex must degrade to never-match, not warn.
		if ( false === @preg_match( $regex, '' ) ) {
			return self::NEVER;
		}
		return $regex;
	}

	/**
	 * Translate glob syntax → regex body (no delimiters, no anchors).
	 * Mirrors fnmatch's FNM_PATHNAME-disabled semantics: `*` matches
	 * across `/` so `/cart/*` correctly catches `/cart/items/sub`.
	 */
	private static function glob_to_regex( string $glob ): string {
		$out      = '';
		$in_class = false;
		$len      = strlen( $glob );
		$escape   = false;

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $glob[ $i ];

			if ( $escape ) {
				$out   .= preg_quote( $ch, '#' );
				$escape = false;
				continue;
			}

			if ( '\\' === $ch ) {
				$escape = true;
				continue;
			}

			if ( $in_class ) {
				if ( ']' === $ch ) {
					$out     .= ']';
					$in_class = false;
				} else {
					// Inside a character class, dash + letters are passed
					// through; we still preg_quote dangerous chars.
					$out .= preg_quote( $ch, '#' );
				}
				continue;
			}

			switch ( $ch ) {
				case '*':
					$out .= '.*';
					break;
				case '?':
					$out .= '.';
					break;
				case '[':
					$out     .= '[';
					$in_class = true;
					break;
				default:
					$out .= preg_quote( $ch, '#' );
			}
		}

		return $out;
	}

	/**
	 * Test-only: clear the compile cache. Production code never needs
	 * this (PHP request lifetime handles it).
	 */
	public static function reset_cache(): void {
		self::$compiled = array();
	}
}
