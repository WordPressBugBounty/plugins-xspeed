<?php
/**
 * Server_Rules — translate the user's cache-exclusion settings into rules
 * the web server itself can enforce.
 *
 * Why this exists: our speed win comes from letting nginx / Apache serve a
 * cached page without ever starting PHP. But that means PHP's exclusion
 * checks (Cache::should_cache()) never run on a warm page. Before this
 * class, the server rules hardcoded three cookie names and tested no user
 * agent at all, so every `excluded_cookies` / `bypass_user_agents` entry
 * the user typed applied only while a page was cold — the settings screen
 * said the rule was active, and on a warm page it was not.
 *
 * Generating server config from user input is the dangerous part: a bad
 * rule in .htaccess is a 500 on the whole site, and a bad rule in nginx
 * config makes `nginx -t` fail, which can take down every vhost on the
 * box. So the rules here are deliberately conservative:
 *
 *   - Only plain names and simple `*` wildcards are emitted, fully escaped.
 *   - Raw-regex (`~`) patterns are SKIPPED and counted, never passed
 *     through — we cannot vouch for arbitrary user PCRE inside a server
 *     config, and the two regex dialects differ anyway.
 *   - The three historical cookie names are always merged in as a floor,
 *     so a corrupt or empty setting can never produce rules weaker than
 *     what shipped before.
 *
 * PHP remains the authority. A missing, stale, or hand-broken server
 * config can only ever cost speed, never correctness: anything the server
 * declines to serve falls through to PHP, which re-applies the full rule
 * list (including the `~` regex patterns skipped here).
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Server_Rules {

	/**
	 * Cookie names that must always bypass the server-served fast path,
	 * regardless of settings. These are the three the rules hardcoded
	 * before this class existed; keeping them as a floor means a broken
	 * or empty `excluded_cookies` value can never make caching LESS safe
	 * than it was.
	 *
	 * @var string[]
	 */
	public const COOKIE_FLOOR = array(
		'wordpress_logged_in',
		'comment_author',
		'wp-postpass_',
	);

	/**
	 * The conventional "never serve this visitor from cache" cookie.
	 *
	 * No WordPress core code sets it — it exists purely as a slot for
	 * plugins, and it is already in the default bypass rules of SpinupWP,
	 * GridPane, RunCloud and most reference nginx configs. PHP sets it
	 * (see Cache::maybe_set_bypass_cookie()) whenever it decides a visitor
	 * must not be served from cache, so the server can enforce the whole
	 * rule list by testing this ONE name — which means adding a new
	 * excluded cookie needs no config change and no nginx reload.
	 *
	 * Limit, stated plainly because it belongs in the docs too: this only
	 * covers visitors PHP has seen at least once. That is essentially
	 * every real case (a cart cookie is set by an add-to-cart request, a
	 * login cookie by wp-login.php), but it cannot cover user-agent rules
	 * — a bot's very first request to a warm page never reaches PHP. That
	 * is why the UA rules are still emitted into the config.
	 */
	public const BYPASS_COOKIE = 'wordpress_no_cache';

	/**
	 * Cap on how many patterns we emit into a server config. A pathological
	 * settings value shouldn't produce a multi-kilobyte regex that slows
	 * every request or trips nginx's config limits.
	 */
	private const MAX_PATTERNS = 100;

	/**
	 * Longest single pattern we'll emit. Anything longer is treated as
	 * unsupported and counted as skipped.
	 */
	private const MAX_PATTERN_LEN = 120;

	/**
	 * Characters we are willing to put inside an emitted server rule.
	 *
	 * This is a config-syntax guard, not a regex guard. The emitted line is
	 * `if ( $http_cookie ~* "(...)" )` on nginx and a whitespace-delimited
	 * `RewriteCond %{HTTP_COOKIE} !(...) [NC]` on Apache, so a `"` closes
	 * nginx's string early (`nginx -t` fails, taking every vhost on the box
	 * with it) and a bare space adds an argument to RewriteCond (HTTP 500 on
	 * every request — and `.htaccess` is parsed per-request, so `httpd -t`
	 * still reports Syntax OK).
	 *
	 * `preg_quote()` does not help: it escapes for PCRE, not for the config
	 * dialect, and `\"` is still a `"` to nginx's tokenizer.
	 *
	 * Anything outside this set is unrepresentable, so we skip it and let the
	 * caller report it as "enforced by PHP only" — the same degradation `~`
	 * and `[` already get. Cookie names are `token` per RFC 6265 and cannot
	 * legally contain a quote or a space, so no valid cookie exclusion is
	 * lost. User-agent entries legitimately contain spaces; those are handled
	 * by `user_agent_rule()`, which quotes per target syntax rather than
	 * skipping.
	 */
	private const SAFE_PATTERN_CHARS = '/^[A-Za-z0-9_\-.*?\/]+$/';

	/**
	 * User-agent entries may additionally contain a space, because real UA
	 * strings ("Mozilla/5.0 (compatible; Googlebot")) are full of them and
	 * skipping every one would gut the feature. Spaces are made safe by the
	 * emitters, which quote the UA condition; everything else that could
	 * break a config line is still excluded.
	 *
	 * Parentheses, `+`, `:`, `;` and `,` are included for the same reason —
	 * real UA strings are full of them ("Mozilla/5.0 (compatible;
	 * Googlebot/2.1; +http://…)"). They are inert inside the quoted condition
	 * both emitters produce, and `preg_quote()` escapes them before they
	 * reach the regex, so neither the config parser nor PCRE sees syntax.
	 */
	private const SAFE_UA_CHARS = '/^[A-Za-z0-9_\-.*?\/ ()+:;,]+$/';

	/**
	 * Build the cookie-name alternation for a server rule.
	 *
	 * @param string[] $patterns Raw `excluded_cookies` setting.
	 * @return array{regex:string,skipped:int} Regex body (no delimiters,
	 *         no anchors) plus the count of patterns we could not express.
	 */
	public static function cookie_rule( array $patterns ): array {
		$built = self::build_alternation( $patterns );

		// Merge the floor + the generic bypass cookie in, deduplicated.
		// These are literal names, so they need escaping exactly like any
		// other — `wp-postpass_` contains a `-`, harmless in a regex but
		// escaped anyway so the treatment is uniform and future names
		// can't surprise us.
		$floor = array();
		foreach ( array_merge( self::COOKIE_FLOOR, array( self::BYPASS_COOKIE ) ) as $name ) {
			$floor[] = preg_quote( $name, '' );
		}

		$parts = array_values( array_unique( array_merge( $floor, $built['parts'] ) ) );

		return array(
			'regex'   => implode( '|', $parts ),
			'skipped' => $built['skipped'],
		);
	}

	/**
	 * Build the user-agent alternation for a server rule.
	 *
	 * PHP matches user agents with a case-insensitive SUBSTRING test (see
	 * Cache::should_cache()), not a glob — so each entry becomes a plain
	 * escaped literal and the rule is applied case-insensitively by the
	 * caller. An empty list yields an empty regex, and callers must then
	 * omit the rule entirely rather than emit one that matches everything.
	 *
	 * @param string[] $patterns Raw `bypass_user_agents` setting.
	 * @return array{regex:string,skipped:int}
	 */
	public static function user_agent_rule( array $patterns ): array {
		$parts   = array();
		$skipped = 0;

		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			// Raw regex is PHP-side only — see the class docblock.
			if ( '~' === $pattern[0] ) {
				++$skipped;
				continue;
			}
			if ( strlen( $pattern ) > self::MAX_PATTERN_LEN ) {
				++$skipped;
				continue;
			}
			// Anything that could break out of the emitted config line is
			// unrepresentable — see SAFE_UA_CHARS.
			if ( ! preg_match( self::SAFE_UA_CHARS, $pattern ) ) {
				++$skipped;
				continue;
			}
			if ( count( $parts ) >= self::MAX_PATTERNS ) {
				++$skipped;
				continue;
			}
			// UA matching is substring in PHP, so every character is a
			// literal here — including `*`, which PHP does NOT treat as a
			// wildcard on this setting.
			$parts[] = preg_quote( $pattern, '' );
		}

		return array(
			'regex'   => implode( '|', array_values( array_unique( $parts ) ) ),
			'skipped' => $skipped,
		);
	}

	/**
	 * Build the URL alternation for a server rule.
	 *
	 * The nginx snippet mirrored the cookie and user-agent exclusions but
	 * not this one, so an excluded URL was only excluded while its page was
	 * cold. That is mostly masked — PHP refuses to write a static file for
	 * an excluded URL, so there is usually nothing for nginx to serve — but
	 * it bites whenever a page was cached BEFORE the rule existed: the file
	 * is already on disk, nginx never consults PHP, and the exclusion is
	 * silently ignored until the next purge. (#169)
	 *
	 * `excluded_urls` uses the same glob/substring dialect as the cookie
	 * list, so build_alternation() does the work — including skipping the
	 * `~raw regex` entries, which are PHP-side only. An empty list yields an
	 * empty regex and the caller must omit the rule entirely rather than
	 * emit one that matches every request.
	 *
	 * @param string[] $patterns Raw `excluded_urls` setting.
	 * @return array{regex:string,skipped:int}
	 */
	public static function url_rule( array $patterns ): array {
		$built = self::build_alternation( $patterns );

		return array(
			'regex'   => implode( '|', $built['parts'] ),
			'skipped' => $built['skipped'],
		);
	}

	/**
	 * Translate a list of glob/substring patterns into escaped regex
	 * alternation parts, mirroring Glob_Matcher's semantics as closely as
	 * a server config can.
	 *
	 * Glob_Matcher rules we reproduce:
	 *   - a bare substring is "contains"      → emitted as an escaped literal
	 *   - `*` is any run of characters        → emitted as `.*`
	 *   - `?` is exactly one character        → emitted as `.`
	 *   - `\*` is a literal asterisk          → escaped literal
	 *
	 * Rules we deliberately do NOT reproduce, counting them as skipped:
	 *   - `~raw regex` (dialect mismatch, unvalidated user input)
	 *   - `[abc]` character classes (rare here, and the escaping rules
	 *     differ between Apache and nginx enough to be risky)
	 *
	 * Note the anchoring difference: Glob_Matcher anchors a pattern once
	 * it contains a glob metacharacter. We do NOT anchor, because these
	 * alternations are matched against the whole Cookie header (which
	 * holds many `name=value` pairs), so an anchored rule would never fire.
	 * Erring toward "matches more" is the safe direction here — the cost
	 * of a false positive is a cache bypass (slower), while a false
	 * negative is serving a page we promised not to (wrong).
	 *
	 * @param string[] $patterns
	 * @return array{parts:string[],skipped:int}
	 */
	private static function build_alternation( array $patterns ): array {
		$parts   = array();
		$skipped = 0;

		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( '~' === $pattern[0] ) {
				++$skipped;
				continue;
			}
			if ( strpos( $pattern, '[' ) !== false ) {
				++$skipped;
				continue;
			}
			// Anything that could break out of the emitted config line is
			// unrepresentable — see SAFE_PATTERN_CHARS.
			if ( ! preg_match( self::SAFE_PATTERN_CHARS, $pattern ) ) {
				++$skipped;
				continue;
			}
			if ( strlen( $pattern ) > self::MAX_PATTERN_LEN ) {
				++$skipped;
				continue;
			}
			if ( count( $parts ) >= self::MAX_PATTERNS ) {
				++$skipped;
				continue;
			}

			$parts[] = self::glob_to_server_regex( $pattern );
		}

		return array(
			'parts'   => array_values( array_unique( $parts ) ),
			'skipped' => $skipped,
		);
	}

	/**
	 * Escape a single glob pattern into a regex fragment safe to embed in
	 * both an nginx `~*` test and an Apache RewriteCond.
	 *
	 * Everything is escaped by default; only unescaped `*` and `?` are
	 * promoted to their regex equivalents. This is the function that keeps
	 * a cookie name like `my.cookie[1]` from becoming an active pattern.
	 */
	private static function glob_to_server_regex( string $glob ): string {
		$out    = '';
		$len    = strlen( $glob );
		$escape = false;

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $glob[ $i ];

			if ( $escape ) {
				$out   .= preg_quote( $ch, '' );
				$escape = false;
				continue;
			}
			if ( '\\' === $ch ) {
				$escape = true;
				continue;
			}
			if ( '*' === $ch ) {
				$out .= '.*';
				continue;
			}
			if ( '?' === $ch ) {
				$out .= '.';
				continue;
			}
			$out .= preg_quote( $ch, '' );
		}

		// A trailing lone backslash would leave $escape set; emit nothing
		// for it rather than an unterminated escape that breaks the regex.
		return $out;
	}

	/**
	 * How many patterns across both lists cannot be enforced by the web
	 * server, so the UI can say so plainly instead of implying every rule
	 * is active at the edge.
	 *
	 * @param array $cache_opts The `xspeed_module_cache` settings array.
	 */
	public static function unsupported_count( array $cache_opts ): int {
		$cookies = is_array( $cache_opts['excluded_cookies'] ?? null ) ? $cache_opts['excluded_cookies'] : array();
		$uas     = is_array( $cache_opts['bypass_user_agents'] ?? null ) ? $cache_opts['bypass_user_agents'] : array();

		$c = self::cookie_rule( $cookies );
		$u = self::user_agent_rule( $uas );

		return (int) $c['skipped'] + (int) $u['skipped'];
	}
}
