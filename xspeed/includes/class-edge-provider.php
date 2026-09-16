<?php
/**
 * What cache is in front of this site, and how sure are we?
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * Engine infrastructure, sat beside Server rather than built as a Module: it
 * owns no settings, routes or UI of its own, and class-cache.php is already
 * seven thousand lines.
 *
 * The answer exists so `Cache::edge_headers_for()` can pick the vocabulary a
 * hold is said in. That is a smaller job than it sounds, and it is worth
 * being honest about why: every provider-specific set is a SUBSET of the
 * generic one, with Akamai the single exception. Naming a provider mostly
 * removes headers that were inert anyway. The protection comes from the
 * `Cache-Control` pair every set carries, not from getting the provider
 * right — which is exactly what makes it safe to guess from a request header
 * an attacker can forge.
 */
final class Edge_Provider {

	/** One provider, named, on evidence we trust. */
	public const CONFIRMED = 'confirmed';

	/** Something is in front of us; which, we cannot say. */
	public const PROXY = 'proxy';

	/** No evidence of anything. Not the same as proof there is nothing. */
	public const NONE = 'none';

	/** The provider slug meaning "I will write the headers myself". */
	public const CUSTOM = 'custom';

	/**
	 * Sent on every hold, whatever the provider and whether or not one was
	 * confirmed.
	 *
	 * `private` is the form every shared cache understands, `s-maxage=0` the
	 * numeric form for one that only parses ages, and `no-cache` makes a
	 * browser revalidate rather than reuse an un-optimized render.
	 *
	 * Never `no-store`. `no-store` is what disables bfcache in Firefox and
	 * Safari and turns the back button into a refetch, and nothing here needs
	 * it: the audience is shared caches, not the browser. It is also what
	 * closes the forged-provider case — a visitor who fakes `CF-Ray` through
	 * some other CDN changes which inert targeted header goes out and nothing
	 * else, because this pair rides along regardless.
	 */
	public const CACHE_CONTROL = 'private, no-cache, s-maxage=0';

	/**
	 * The instruction to the ORIGIN SERVER, sent on every hold alongside
	 * whatever the CDN set turns out to be.
	 *
	 * This is not a CDN header and it does not belong to the provider
	 * vocabulary below. `X-Accel-Expires` is read by nginx itself — by
	 * `fastcgi_cache` and `proxy_cache` — and nginx consumes it and strips it
	 * from the response, so it never reaches a CDN or a browser to be
	 * misread. That is why it rides along instead of narrowing: a CDN and an
	 * nginx page cache are two LAYERS, not two candidates, and a site can
	 * have both at once.
	 *
	 * It is the only thing we can say that such a cache will act on. A host
	 * that runs a full-page cache in nginx configures it with
	 * `fastcgi_ignore_headers Cache-Control Expires Set-Cookie` — that
	 * directive exists precisely so WordPress's own `Cache-Control: no-cache`
	 * cannot defeat the cache, and it means CACHE_CONTROL above is discarded
	 * unread. `X-Accel-Expires` is deliberately NOT in that ignore list, and
	 * it outranks `Cache-Control` and `Expires` when nginx decides what to
	 * store; `0` means do not store this.
	 *
	 * Every set carries it, custom lists included — see
	 * custom_hold_headers() for why that one is not an exception.
	 *
	 * Bug history: this used to live in GENERIC, which the targeted sets
	 * REPLACE rather than extend. So a site behind a CDN we successfully
	 * identified — the overwhelmingly common case on managed nginx hosting,
	 * where Cloudflare sits in front of an nginx FastCGI cache — sent the CDN
	 * its refusal and told nginx nothing at all. The edge honoured the hold
	 * and the origin cache stored the very page we had refused to cache.
	 */
	private const ORIGIN = array( 'X-Accel-Expires' => '0' );

	/**
	 * The targeted headers that are inert wherever they are not understood,
	 * sent when no provider was confirmed.
	 *
	 * Two are deliberately absent, and the second one is the important one.
	 *
	 * `Edge-Control` is out because its behaviour on a non-Akamai proxy
	 * cannot be vouched for.
	 *
	 * `Surrogate-Control` is out because it is not inert on Cloudflare — it
	 * is destructive. Cloudflare's own documentation: "if the
	 * `Surrogate-Control` header is present within the response, Cloudflare
	 * ignores any `Cache-Control` directives, even if the `Surrogate-Control`
	 * header does not contain directives." So including it in the set we send
	 * when we are NOT sure who is listening would, on exactly the sites we
	 * failed to identify as Cloudflare, throw away the `Cache-Control` line
	 * that is doing the actual protecting. And it buys nothing in exchange:
	 * Fastly does not honour `Surrogate-Control: no-store` either — its
	 * supported parameters are `max-age`, `stale-if-error` and
	 * `stale-while-revalidate`, and what stops Fastly storing a response is
	 * `Cache-Control: private`.
	 *
	 * It is still sent to a positively identified Fastly or Varnish, where
	 * there is no Cloudflare to confuse and stock `builtin.vcl` does act on
	 * it. Being unsure is the case it must stay out of.
	 */
	private const GENERIC = array(
		'CDN-Cache-Control' => 'no-store',
	);

	/**
	 * The targeted header each provider reads, on top of CACHE_CONTROL.
	 *
	 * A provider absent from this map reads plain `Cache-Control` and nothing
	 * else, which is not a gap — it is the answer for CloudFront, Google
	 * Cloud CDN, KeyCDN, Bunny, Sucuri and Imperva alike.
	 */
	private const TARGETED = array(
		// `cf-edge-cache` is what Cloudflare APO reads. Their own plugin
		// emits it on every request, and the comment there says why: the
		// header doubles as a capability handshake, since APO is enabled for
		// a zone on seeing it. So a site running APO without that plugin gets
		// no instruction from `CDN-Cache-Control` alone.
		//
		// Only ever the refusal. The positive form — `cache,platform=wordpress`
		// — is what turns APO on for a zone, and that is the site owner's
		// decision to make in their dashboard, not ours to make from a
		// response header.
		//
		// Safe to send alongside Cloudflare's own plugin: it emits on `init`
		// (cloudflare.loader.php), we emit from mark() on `template_redirect`,
		// and header() replaces by default — so where the two disagree ours
		// is the later word. That is the right way round, because their test
		// is `! is_user_logged_in()` and ours also knows about a cart cookie,
		// an excluded URL and a device-split render.
		'cloudflare' => array( 'CDN-Cache-Control' => 'no-store', 'cf-edge-cache' => 'no-cache' ),
		// Google Cloud CDN reads CDN-Cache-Control and, where it is present,
		// uses it exclusively and ignores the standard headers beneath it.
		'google'     => array( 'CDN-Cache-Control' => 'no-store' ),
		// Not what protects the response — `Cache-Control: private` is, on
		// both. Sent anyway because stock Varnish `builtin.vcl` does act on
		// it and it costs nothing here, where Cloudflare is ruled out.
		'fastly'     => array( 'Surrogate-Control' => 'no-store' ),
		'varnish'    => array( 'Surrogate-Control' => 'no-store' ),
		// `nginx` is deliberately absent: its header is ORIGIN, which every
		// set carries anyway. Leaving an entry here would say that pinning
		// `nginx` buys something the other providers do not get, and since
		// the pin is the only way to reach that slug — detection never
		// confirms nginx from a request — that would be misleading.
		// Only meaningful on a property configured to honour origin cache
		// headers, which is not the Akamai default. Pin-only for that reason;
		// see the note on SIGNALS.
		'akamai'     => array( 'Edge-Control' => 'no-store' ),
	);

	/**
	 * Slugs that name an actual product, for matching a `CDN-Loop` token.
	 *
	 * Narrower than KNOWN on purpose: `generic` and `custom` name a policy
	 * rather than a company, and a request carrying `CDN-Loop: custom` would
	 * otherwise make us read the admin's own header box.
	 */
	private const VENDORS = array(
		'cloudflare',
		'fastly',
		'varnish',
		'akamai',
		'cloudfront',
		'keycdn',
		'bunny',
		'sucuri',
		'incapsula',
	);

	/**
	 * Providers a human is allowed to name, through the constant or the
	 * filter. `generic` means "something is there, send the blind set, stop
	 * guessing"; it is not a vendor.
	 */
	private const KNOWN = array(
		'cloudflare',
		'fastly',
		'varnish',
		'nginx',
		'akamai',
		'cloudfront',
		'google',
		'keycdn',
		'bunny',
		'sucuri',
		'incapsula',
		'generic',
		'custom',
	);

	/**
	 * Request headers that name their provider outright, in probe order.
	 *
	 * `CF-Ray` is checked by shape rather than presence because it is the one
	 * most likely to be forwarded verbatim by an unrelated CDN.
	 *
	 * Akamai is absent on purpose. `Akamai-Origin-Hop` appears nowhere in
	 * Akamai's documentation — their documented way for an origin to
	 * recognise an edge request is an opt-in cookie, configured per property
	 * — so detecting on it would be a guess dressed as a confirmation. And
	 * Akamai does not honour origin cache headers by default in any case:
	 * a property must have "Honor origin Cache-Control and Expires" turned on
	 * before anything we send matters. So Akamai is reachable by pin only,
	 * where a human has confirmed both facts for their own property.
	 *
	 * @var array<int,array{key:string,provider:string,pattern?:string}>
	 */
	private const SIGNALS = array(
		array( 'key' => 'HTTP_CF_RAY', 'provider' => 'cloudflare', 'pattern' => '/^[0-9a-f]{16}-[A-Z]{3}$/' ),
		array( 'key' => 'HTTP_CF_CONNECTING_IP', 'provider' => 'cloudflare' ),
		array( 'key' => 'HTTP_X_VARNISH', 'provider' => 'varnish' ),
		// AWS documents this one explicitly: "CloudFront adds the header to
		// the viewer request before forwarding the request to your origin."
		array( 'key' => 'HTTP_X_AMZ_CF_ID', 'provider' => 'cloudfront' ),
		// Documented as sent to the origin on every pull.
		array( 'key' => 'HTTP_CDN_SERVERID', 'provider' => 'bunny' ),
		array( 'key' => 'HTTP_X_SUCURI_CLIENTIP', 'provider' => 'sucuri' ),
		array( 'key' => 'HTTP_INCAP_CLIENT_IP', 'provider' => 'incapsula' ),
		array( 'key' => 'HTTP_X_PULL', 'provider' => 'keycdn', 'pattern' => '/keycdn/i' ),
	);

	/**
	 * Headers that prove something is in front of us without naming it well
	 * enough to act on.
	 *
	 * These were all in the list above, confirming a provider, until the
	 * vendor documentation was actually read. None of them is documented as a
	 * header the CDN adds to every origin request:
	 *
	 * `Fastly-FF` is the Fastly-to-Fastly marker, which in practice means
	 * shielding rather than "this request came from Fastly"; Fastly documents
	 * no header it adds to every origin request at all. `Fastly-Client-IP`
	 * and `Fastly-SSL` are in the same position.
	 *
	 * Confirming Fastly wrongly is not free, which is why these moved rather
	 * than being left alone: a confirmed Fastly is sent `Surrogate-Control`,
	 * and `Surrogate-Control` on a site actually behind Cloudflare makes
	 * Cloudflare discard every `Cache-Control` directive in the response.
	 * The guess would take out the header doing the protecting.
	 *
	 * @var string[]
	 */
	private const PROXY_SIGNALS = array(
		'HTTP_FASTLY_FF',
		'HTTP_FASTLY_CLIENT_IP',
		'HTTP_FASTLY_SSL',
	);

	/**
	 * Resolved answers, keyed by context.
	 *
	 * Memoised and never persisted. `Server::type()` writes its answer to an
	 * option because SERVER_SOFTWARE comes from the web server and can be
	 * trusted; a request header cannot, so nothing here outlives the request.
	 *
	 * @var array<string,array{provider:string,confidence:string,source:string}>
	 */
	private static $memo = array();

	/**
	 * Who is in front of us.
	 *
	 * @param string $context `request`, `store` or `bake`.
	 * @return array{provider:string,confidence:string,source:string}
	 */
	public static function detect( string $context = 'request' ): array {
		if ( isset( self::$memo[ $context ] ) ) {
			return self::$memo[ $context ];
		}

		self::$memo[ $context ] = self::resolve( $context );

		return self::$memo[ $context ];
	}

	/**
	 * The hold pairs for a provider, before sanitising.
	 *
	 * @param string $provider A slug from KNOWN, or '' for the blind set.
	 * @return array<string,string>
	 */
	public static function hold_headers( string $provider ): array {
		if ( self::CUSTOM === $provider ) {
			return self::custom_hold_headers();
		}

		$targeted = self::TARGETED[ $provider ] ?? null;

		if ( null === $targeted ) {
			// Either a provider that reads plain Cache-Control and nothing
			// else, or no provider at all. The two differ in how much we
			// send, not in what protects the page.
			$targeted = ( '' === $provider || 'generic' === $provider ) ? self::GENERIC : array();
		}

		// ORIGIN rides along rather than narrowing — see the constant. The
		// CDN set answers "what is in front of the site"; ORIGIN answers
		// "what is on the site's own box", and identifying the first tells
		// us nothing about the second.
		return array_merge( $targeted, self::ORIGIN, array( 'Cache-Control' => self::CACHE_CONTROL ) );
	}

	/**
	 * The pairs a site owner wrote out themselves.
	 *
	 * For an edge we do not know about, or one whose operator wants
	 * different wording than ours. Written one `Name: value` per line,
	 * because that is what the thing being configured actually looks like and
	 * anyone reaching for this has read their CDN's docs in that form.
	 *
	 * Two rules keep it from being a way to make things worse. An empty or
	 * unusable box falls back to the blind set rather than to silence: an
	 * operator who picked "custom" asked for MORE control over the hold, not
	 * for the hold to stop. And the baseline `Cache-Control` is added when
	 * they did not write one of their own, because that pair is what protects
	 * the page when the targeted header is not understood — but theirs wins
	 * if they did write one, since overriding an explicit choice is how a
	 * settings field becomes a lie.
	 *
	 * Not sanitised here: Cache::edge_headers_for() puts everything through
	 * sanitize_edge_headers() on the way out, so a value carrying CR/LF is
	 * dropped at the one place that cannot be bypassed.
	 *
	 * @return array<string,string>
	 */
	private static function custom_hold_headers(): array {
		// Stored as a list of lines, which is how every other multi-line
		// field in the schema is shaped. A plain string is accepted too, so a
		// wp-config filter or a CLI write does not have to know that.
		$raw = self::setting( 'edge_custom_headers' );
		if ( is_array( $raw ) ) {
			$raw = implode( "\n", array_filter( $raw, 'is_string' ) );
		}
		$custom = self::parse_header_lines( is_string( $raw ) ? $raw : '' );

		if ( array() === $custom ) {
			// `custom` selected but nothing written yet. That is not an
			// instruction, so fall back to the blind set exactly — ORIGIN
			// included, or choosing `custom` and leaving the box empty would
			// quietly drop the origin-cache refusal.
			return array_merge( self::GENERIC, self::ORIGIN, array( 'Cache-Control' => self::CACHE_CONTROL ) );
		}

		/*
		 * A list the operator wrote still gets both baselines added under
		 * it, for the same reason: choosing `custom` asks for more control
		 * over what the CDN is told, not for the site's own origin cache to
		 * start keeping pages we refused.
		 *
		 * This was originally left as the operator's exact word, and a live
		 * xCloud site showed why that was wrong. Writing out the three
		 * headers a Cloudflare hold sends — and leaving `X-Accel-Expires`
		 * out, because nothing in the UI is shaped to make you think of it —
		 * is enough for nginx to store the first un-optimized render and
		 * replay it for the full `fastcgi_cache_valid` window. It seals
		 * itself in: the stored copy still carries `no-store`, so the CDN
		 * keeps bypassing and sends every visitor to the origin that is
		 * serving the stale page.
		 *
		 * Either baseline is still overridable by naming it. Someone who
		 * writes `X-Accel-Expires: 30` means 30, the same way someone who
		 * writes their own `Cache-Control` means that.
		 */

		$has_cache_control = false;
		$has_origin        = false;
		foreach ( array_keys( $custom ) as $name ) {
			if ( 0 === strcasecmp( $name, 'Cache-Control' ) ) {
				$has_cache_control = true;
			}
			if ( 0 === strcasecmp( $name, 'X-Accel-Expires' ) ) {
				$has_origin = true;
			}
		}

		if ( ! $has_origin ) {
			$custom = array_merge( $custom, self::ORIGIN );
		}
		if ( ! $has_cache_control ) {
			$custom = array_merge( $custom, array( 'Cache-Control' => self::CACHE_CONTROL ) );
		}

		return $custom;
	}

	/**
	 * Read `Name: value` lines into pairs.
	 *
	 * Blank lines and `#` comments are skipped so someone can annotate their
	 * own box. A line with no colon is dropped rather than guessed at.
	 *
	 * @return array<string,string>
	 */
	public static function parse_header_lines( string $raw ): array {
		$pairs = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$colon = strpos( $line, ':' );
			if ( false === $colon || 0 === $colon ) {
				continue;
			}
			$name  = trim( substr( $line, 0, $colon ) );
			$value = trim( substr( $line, $colon + 1 ) );
			if ( '' === $name || '' === $value ) {
				continue;
			}
			$pairs[ $name ] = $value;
		}

		return $pairs;
	}

	/**
	 * What the request alone says, ignoring every pin above it.
	 *
	 * Exists so a pinned provider can be checked against reality. A setting
	 * is the one signal that both outranks detection and gets frozen into a
	 * baked artifact, so a site that moves from one CDN to another keeps
	 * asserting the old one until someone notices. Nothing can re-check it
	 * automatically — that would defeat the point of pinning — but Health can
	 * say the two disagree, which is enough for someone to act on.
	 *
	 * @return array{provider:string,confidence:string,source:string}
	 */
	public static function sniffed(): array {
		if ( ! isset( self::$memo['__sniff'] ) ) {
			self::$memo['__sniff'] = self::sniff();
		}

		return self::$memo['__sniff'];
	}

	/**
	 * Was this answer a deliberate "send nothing", rather than "we saw
	 * nothing"?
	 *
	 * Both come back as `none`, and the difference decides whether a hold
	 * fires anyway. Someone who switched this off gets silence on every
	 * reason; a site where detection simply found no marker still gets the
	 * holds that protect somebody's data, because an unseen proxy is not an
	 * absent one.
	 *
	 * @param array{provider:string,confidence:string,source:string} $answer From detect().
	 */
	public static function is_off( array $answer ): bool {
		return self::NONE === ( $answer['confidence'] ?? '' )
			&& in_array( $answer['source'] ?? '', array( 'setting', 'constant', 'filter' ), true );
	}

	/** Drop the memo. Tests, and the CLI after a setting write. */
	public static function forget(): void {
		self::$memo = array();
	}

	/**
	 * @return array{provider:string,confidence:string,source:string}
	 */
	private static function resolve( string $context ): array {
		/**
		 * Filter: xspeed_edge_provider
		 *
		 * Last word, because it is the most specific override there is: it
		 * runs in PHP, it can read the constant and the setting below it, and
		 * decide. Return a slug to pin one, `'off'` to send nothing, or null
		 * to let detection run.
		 *
		 * @param string|null $provider Provider slug, 'off', or null.
		 * @param string      $context  `request`, `store` or `bake`.
		 */
		$filtered = apply_filters( 'xspeed_edge_provider', null, $context );
		$pinned   = self::pin( is_string( $filtered ) ? $filtered : '', 'filter' );
		if ( null !== $pinned ) {
			return $pinned;
		}

		// The only override that reaches the pre-plugin fast path, since
		// advanced-cache.php runs before any filter exists — and the shape a
		// managed host needs, one line in a templated wp-config.php rather
		// than an mu-plugin shipped to every site in the fleet.
		if ( defined( 'XSPEED_EDGE_PROVIDER' ) ) {
			$pinned = self::pin( (string) constant( 'XSPEED_EDGE_PROVIDER' ), 'constant' );
			if ( null !== $pinned ) {
				return $pinned;
			}
		}

		$pinned = self::pin( (string) ( self::setting( 'edge_provider' ) ?? 'auto' ), 'setting' );
		if ( null !== $pinned ) {
			return $pinned;
		}

		// Free's own Cloudflare module, or Cloudflare's official plugin. A
		// grey-clouded zone makes this a false positive, which costs one
		// inert header; the constant and the filter exist for the reverse
		// case, another CDN in front of a connected Cloudflare.
		//
		// The Cdn module's hostname is deliberately not a signal: a pull zone
		// serves assets and never sees the HTML this is about.
		$cloudflare = get_option( 'xspeed_module_cloudflare', array() );
		if ( is_array( $cloudflare ) && ! empty( $cloudflare['enabled'] ) ) {
			return self::answer( 'cloudflare', self::CONFIRMED, 'plugin' );
		}
		$active = (array) get_option( 'active_plugins', array() );
		if ( in_array( 'cloudflare/cloudflare.php', $active, true ) ) {
			return self::answer( 'cloudflare', self::CONFIRMED, 'plugin' );
		}

		// Everything below reads the inbound request, so it is skipped
		// outside `request`. A bake runs once in an admin or CLI request and
		// answers for every page on the site; a sidecar is written from one
		// visitor's request and replayed to every later visitor of that page.
		// Neither may carry a provider that only a forgeable header vouched
		// for, and a CLI bake has no headers to read in any case.
		if ( 'request' !== $context ) {
			return self::answer( '', self::NONE, 'context' );
		}

		return self::sniff();
	}

	/**
	 * @return array{provider:string,confidence:string,source:string}
	 */
	private static function sniff(): array {
		foreach ( self::SIGNALS as $signal ) {
			$value = self::server_header( $signal['key'] );
			if ( '' === $value ) {
				continue;
			}
			if ( isset( $signal['pattern'] ) && ! preg_match( $signal['pattern'], $value ) ) {
				// Present but the wrong shape. Something forwarded a header
				// it does not own, which says a proxy is there without
				// saying which.
				return self::answer( '', self::PROXY, 'request' );
			}
			return self::answer( $signal['provider'], self::CONFIRMED, 'request' );
		}

		// RFC 8586. The only standards-track signal here, and the only one
		// whose absence means anything, since a conforming intermediary must
		// append itself.
		$loop = strtolower( self::server_header( 'HTTP_CDN_LOOP' ) );
		if ( '' !== $loop ) {
			// Vendors only. KNOWN also holds `generic` and `custom`, which
			// name a policy rather than a company — a request carrying
			// `CDN-Loop: custom` would otherwise make us read the admin's own
			// header box and report "custom (request)" back to them.
			foreach ( self::VENDORS as $provider ) {
				if ( false !== strpos( $loop, $provider ) ) {
					return self::answer( $provider, self::CONFIRMED, 'request' );
				}
			}
			return self::answer( '', self::PROXY, 'request' );
		}

		foreach ( self::PROXY_SIGNALS as $key ) {
			if ( '' !== self::server_header( $key ) ) {
				return self::answer( '', self::PROXY, 'request' );
			}
		}

		// `Via` names a provider for exactly one of these. Bunny documents
		// `Via: BunnyCDN` on requests to the origin. CloudFront does NOT:
		// its own header table says it FORWARDS the viewer's `Via` to the
		// origin and sets its own only on the response to the viewer, so
		// matching `cloudfront` here would key on something a visitor can
		// type. Google Cloud CDN documents no origin-side header at all.
		$via = strtolower( self::server_header( 'HTTP_VIA' ) );
		if ( false !== strpos( $via, 'bunnycdn' ) ) {
			return self::answer( 'bunny', self::CONFIRMED, 'request' );
		}
		if ( '' !== $via ) {
			return self::answer( '', self::PROXY, 'request' );
		}

		// True-Client-IP is sent by Akamai AND by Cloudflare Enterprise, so
		// on its own it names nothing. It still proves someone is in front.
		if ( '' !== self::server_header( 'HTTP_TRUE_CLIENT_IP' ) ) {
			return self::answer( '', self::PROXY, 'request' );
		}

		// A host page cache in front of PHP leaves no marker of its own. The
		// forwarding headers are the only trace, and they are why nginx is
		// never `confirmed` from a request: X-Forwarded-For says a proxy
		// exists, not that it caches.
		if ( Server::is_behind_proxy() ) {
			return self::answer( '', self::PROXY, 'request' );
		}

		return self::answer( '', self::NONE, 'request' );
	}

	/**
	 * Accept a slug a human named, or reject it and let detection continue.
	 *
	 * An unrecognised value is ignored rather than treated as `generic`: a
	 * typo in wp-config.php should not silently become a different policy
	 * from the one that was typed.
	 *
	 * @return array{provider:string,confidence:string,source:string}|null
	 */
	private static function pin( string $value, string $source ): ?array {
		$value = strtolower( trim( $value ) );
		if ( 'off' === $value ) {
			return self::answer( '', self::NONE, $source );
		}
		if ( in_array( $value, self::KNOWN, true ) ) {
			return self::answer( $value, self::CONFIRMED, $source );
		}

		return null;
	}

	/**
	 * @return array{provider:string,confidence:string,source:string}
	 */
	private static function answer( string $provider, string $confidence, string $source ): array {
		return array(
			'provider'   => $provider,
			'confidence' => $confidence,
			'source'     => $source,
		);
	}

	/**
	 * One value from the Cache module's stored options.
	 *
	 * Read from the option directly rather than through Settings_Manager:
	 * this runs on the serve path, where the module registry may not have
	 * been built yet.
	 *
	 * @return mixed
	 */
	private static function setting( string $key ) {
		$stored = get_option( Settings_Manager::OPTION_PREFIX . 'cache', array() );

		return is_array( $stored ) ? ( $stored[ $key ] ?? null ) : null;
	}

	private static function server_header( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading transport metadata about who forwarded the request; there is no form here to nonce.
		return isset( $_SERVER[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ) : '';
	}
}
