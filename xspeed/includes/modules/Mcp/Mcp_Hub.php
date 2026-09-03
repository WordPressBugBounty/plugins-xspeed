<?php
/**
 * xSpeed Hub — the site side of the multi-site hub attach flow.
 *
 * The Hub (xspeedcache.com) lets one AI connection manage many sites. A
 * site is always attached FROM the plugin (the admin has proof of
 * ownership here). Two methods, both surfaced in the MCP Server panel's
 * "xSpeed Hub" card:
 *
 *   Method 1 — Token: the plugin surfaces this site's URL + its MCP
 *   `site_token`; the admin pastes both into their xspeedcache.com
 *   account. The Hub then presents that token back to us on every call
 *   (X-XSpeed-MCP-Token, validated by Mcp_Auth) — identical to the
 *   per-site broker credential, so nothing new is trusted.
 *
 *   Method 2 — OAuth (one click): redirect to the Hub to log in + approve.
 *   Lands with the OAuth front door (Phase 4). Not wired here yet.
 *
 * This class holds ONLY the hub-link bookkeeping (which account this site
 * reports being attached to, for the panel's status line). The credential
 * itself is the existing Mcp_Pairing::site_token() — we do not mint a
 * second secret.
 *
 * State lives in its own option so it is orthogonal to the per-site
 * pairing state (disconnecting one never disturbs the other).
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

defined( 'ABSPATH' ) || exit;

use XSpeed\Score_Store;

final class Mcp_Hub {

	/** Option key holding hub-link state (separate from pairing state). */
	public const OPTION = 'xspeed_module_mcp_hub';

	/** Per-user meta key holding THIS admin's hub connection state. A WP site
	 * can have many admins, each managing it from their own hub account, so
	 * the connection is per-user, not site-wide. */
	public const USER_META = 'xspeed_hub_link';

	/**
	 * Site-level mirror of "any admin attached" — '1'/'0'. Maintained by
	 * every attach/detach path so the public scan-signals route answers from
	 * one option row instead of scanning users. See site_attached().
	 */
	public const SITE_ATTACHED_OPTION = 'xspeed_hub_site_attached';

	/** Default hub dashboard base — where the user manages their account. */
	public const DEFAULT_HUB_URL = 'https://app.xspeedcache.com';

	/**
	 * The hub dashboard base URL. Overridable via the XSPEED_HUB_URL
	 * constant (wp-config.php) and the `xspeed_hub_url` filter so dev /
	 * self-hosted deployments can point elsewhere.
	 */
	public static function hub_url(): string {
		$url = self::DEFAULT_HUB_URL;
		if ( defined( 'XSPEED_HUB_URL' ) && is_string( constant( 'XSPEED_HUB_URL' ) ) && '' !== constant( 'XSPEED_HUB_URL' ) ) {
			$url = (string) constant( 'XSPEED_HUB_URL' );
		}
		/** Filter the xSpeed Hub base URL. */
		$url = (string) apply_filters( 'xspeed_hub_url', $url );
		return untrailingslashit( $url );
	}

	/**
	 * Hub-link state for the CURRENT admin (per-user). Falls back to the legacy
	 * site-wide option for sites attached before the per-user migration, so an
	 * existing connection still shows until re-attached.
	 *
	 * @param int|null $user_id Which user (defaults to the current user).
	 * @return array{attached:bool,account_email:string,attached_at:int}
	 */
	public static function state( ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		$stored  = $user_id ? get_user_meta( $user_id, self::USER_META, true ) : array();

		// Backward-compat: fall back to the old site-wide option if this user
		// has no per-user record yet (pre-1.1 attach).
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			$legacy = get_option( self::OPTION, array() );
			$stored = is_array( $legacy ) ? $legacy : array();
		}

		return array(
			'attached'      => ! empty( $stored['attached'] ),
			'account_email' => isset( $stored['account_email'] ) ? (string) $stored['account_email'] : '',
			'attached_at'   => isset( $stored['attached_at'] ) ? (int) $stored['attached_at'] : 0,
		);
	}

	/**
	 * Site-level Hub answer: is ANY admin on this site attached?
	 *
	 * `state()` is per-user because the attach credential belongs to the
	 * admin who approved it — but "is this SITE managed through the Hub" is
	 * a site-level fact, and it is what the public scan-signals route
	 * reports. The answer is a mirror option maintained by every attach and
	 * detach path, so the unauthenticated route reads one option row and
	 * never scans users. A bounded user scan was the first implementation
	 * and it answered WRONGLY: WP_User_Query orders by user_login, so an
	 * attached admin sorting past the bound was invisible.
	 *
	 * Sites attached before the mirror existed have no option row yet; that
	 * one absent-row case recomputes (over only the users carrying the
	 * hub-link meta — a handful of admins, never the whole user table) and
	 * writes the mirror, so the scan runs once per site ever.
	 *
	 * @return bool
	 */
	public static function site_attached(): bool {
		$legacy = get_option( self::OPTION, array() );
		if ( is_array( $legacy ) && ! empty( $legacy['attached'] ) ) {
			return true;
		}
		$mirror = get_option( self::SITE_ATTACHED_OPTION, false );
		if ( false !== $mirror ) {
			return '1' === $mirror;
		}
		return self::refresh_site_attached();
	}

	/**
	 * Recompute the site-level attached mirror from the per-user records and
	 * persist it. Called by every path that changes attachment state, and
	 * lazily by site_attached() for pre-mirror installs.
	 *
	 * @return bool The recomputed answer.
	 */
	public static function refresh_site_attached(): bool {
		$attached = false;
		$legacy   = get_option( self::OPTION, array() );
		if ( is_array( $legacy ) && ! empty( $legacy['attached'] ) ) {
			$attached = true;
		} else {
			// Unbounded over users CARRYING the hub-link meta (the JOIN
			// restricts to those rows — a handful of admins, not the user
			// table). Deliberately no 'number' cap: a cap plus WP_User_Query's
			// user_login ordering is exactly the wrong-answer bug this mirror
			// replaced.
			$user_ids = get_users(
				array(
					'meta_key' => self::USER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- runs only on attach/detach and once for pre-mirror installs; scans only rows carrying this meta.
					'fields'   => 'ids',
				)
			);
			foreach ( $user_ids as $user_id ) {
				$stored = get_user_meta( (int) $user_id, self::USER_META, true );
				if ( is_array( $stored ) && ! empty( $stored['attached'] ) ) {
					$attached = true;
					break;
				}
			}
		}
		update_option( self::SITE_ATTACHED_OPTION, $attached ? '1' : '0', false );
		return $attached;
	}

	/**
	 * A user is being removed from this site (multisite Users → Remove).
	 *
	 * remove_user_from_blog is core's ONLY removal action and it fires
	 * BEFORE WP drops the user — there is no post-removal hook — so a plain
	 * recompute here would still count the departing admin and keep the
	 * mirror stale. Clear their own hub-link record first (the right
	 * cleanup regardless: their attachment to this site is ending), then
	 * recompute over whoever remains, so a second attached admin keeps the
	 * site reading attached.
	 *
	 * @param int $user_id The user being removed from the site.
	 */
	public static function handle_user_removed( $user_id ): void {
		delete_user_meta( (int) $user_id, self::USER_META );
		self::refresh_site_attached();
	}

	/**
	 * Public snapshot for the dashboard "xSpeed Hub" card.
	 *
	 * Includes the paste-in values for Method 1 (this site's URL + token)
	 * and a link to the hub dashboard. The token is admin-only (the whole
	 * REST route is gated by manage_options in McpModule).
	 *
	 * @return array<string,mixed>
	 */
	public static function public_status( ?int $user_id = null ): array {
		$state = self::state( $user_id );
		return array(
			'attached'       => $state['attached'],
			'account_email'  => $state['account_email'],
			'attached_at'    => $state['attached_at'],
			'site_url'       => home_url( '/' ),
			// Method 1 paste-in credential — the existing per-site token.
			// Empty until generate_token() (or a per-site Connect) mints one.
			'site_token'     => Mcp_Pairing::site_token(),
			'hub_url'        => self::hub_url(),
			// Where the user goes to paste the URL + token (Add site form).
			'add_site_url'   => self::hub_url() . '/sites/add',
			// Method 2 (OAuth attach) — one-click redirect with a fresh nonce.
			'attach_url'     => self::attach_url(),
			// Non-public site? Connecting still works (token returns via the
			// browser redirect), but Hub-initiated AI control needs a public
			// URL — surfaced as an honest note on the Connect surfaces.
			'is_local'       => self::is_local_site(),
		);
	}

	/**
	 * Method 1 — ensure a site_token exists and return the paste-in values.
	 *
	 * Reuses Mcp_Pairing::connect() so the Hub credential is the SAME token
	 * the per-site path uses (no second secret, no drift). Idempotent: if a
	 * token already exists it is reused, not rotated, so an already-attached
	 * hub keeps working.
	 *
	 * @return array<string,mixed> Public status including site_url + site_token.
	 */
	public static function generate_token(): array {
		if ( '' === Mcp_Pairing::site_token() ) {
			// Mint (read-write by default) so the token exists to hand over.
			Mcp_Pairing::connect( false );
		}
		return self::public_status();
	}

	/**
	 * Method 2 (OAuth attach) — the one-click flow.
	 *
	 * The admin clicks "Connect via OAuth" in the Hub tab. We mint a
	 * short-lived signed nonce and redirect the browser to the hub's
	 * /attach page carrying { site_url, nonce }. The hub (after the user
	 * logs into their account) calls BACK to this site's
	 * /xspeed/v1/mcp/attach with the nonce; verify_attach_nonce() checks
	 * it and hands the hub the site_token. Because the nonce is HMAC-signed
	 * with this site's secret AND minting is admin-only, only a site admin
	 * can start an attach — no token is ever pasted or shown.
	 */

	/** Nonce validity window (seconds). */
	private const NONCE_TTL = 600;

	/** Per-site signing secret for attach nonces (derived from WP salts). */
	private static function nonce_secret(): string {
		return wp_hash( 'xspeed_hub_attach|' . self::site_url_canonical() );
	}

	/** Canonical site URL used in the nonce + sent to the hub. */
	private static function site_url_canonical(): string {
		return untrailingslashit( home_url( '/' ) );
	}

	/**
	 * Heuristic: is this site NOT publicly reachable from the internet? A local /
	 * dev / firewalled site can still CONNECT (the token comes back through the
	 * admin's own browser redirect), but the Hub's servers can't reach it back,
	 * so Hub-initiated AI control won't work until it's on a public URL. We use
	 * this only to show an honest heads-up on the Connect surfaces — never to
	 * block connecting.
	 *
	 * True when WP reports a local environment, or the host is a well-known dev
	 * TLD / localhost / a private or loopback IP.
	 */
	public static function is_local_site(): bool {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		$host = strtolower( $host );

		if ( 'localhost' === $host ) {
			return true;
		}
		// Common local/dev TLDs used by local WP stacks (sandbox .sb, Local by
		// Flywheel .local, *.test, *.dev, *.example, *.invalid).
		foreach ( array( '.sb', '.test', '.local', '.localhost', '.dev', '.example', '.invalid' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		// Loopback / private-range IP literal (10/8, 172.16/12, 192.168/16, 127/8).
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}

		/*
		 * WP_ENVIRONMENT_TYPE is deliberately NOT trusted on its own.
		 *
		 * It describes a WORKFLOW — local / development / staging /
		 * production — not whether the internet can reach this site. Plenty
		 * of real, publicly served sites are marked 'local' by their stack:
		 * our own xsdev.1wp.site does exactly that, and told every visitor
		 * "this site looks local" on a public HTTPS domain.
		 *
		 * The hostname above is the honest signal. This only corroborates it,
		 * for a site whose name gives nothing away (an IP-less internal
		 * hostname on a private network, say) — and only when the name is
		 * also not a public FQDN.
		 */
		if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
			// A dotted name that resolves publicly is reachable whatever the
			// environment type claims; a bare hostname ("wordpress", "web")
			// is not resolvable from outside and genuinely is local.
			return false === strpos( $host, '.' );
		}

		return false;
	}

	/**
	 * Mint a signed, time-bound attach nonce. Format: <ts>.<uid>.<hmac>.
	 * Admin-only (the REST route that calls this is gated by manage_options).
	 * The minting admin's user ID is embedded so the (WP-userless) attach
	 * callback can record the connection PER-USER — each admin sees their own
	 * "Connected via <their account>" status.
	 */
	public static function mint_attach_nonce(): string {
		/*
		 * Deliberately does NOT create a credential.
		 *
		 * This used to call Mcp_Pairing::connect( false ) here so a site_token
		 * would exist "to hand over on the callback". But this runs on a READ:
		 * public_status() embeds attach_url(), attach_url() mints a nonce, and
		 * public_status() is what the dashboard bootstrap, the Overview, the
		 * MCP drawer and GET /mcp/hub all call. The result was that merely
		 * opening xSpeed established a live read-write MCP connection nobody
		 * asked for — the site reported `connected` before the user had gone
		 * anywhere near an AI client.
		 *
		 * The token is only ever CONSUMED in verify_attach_nonce(), which runs
		 * when the Hub calls back after the user has clicked through, signed in
		 * and approved. Minting it there keeps this function pure and keeps
		 * credential creation on a path the user actually walked. The nonce
		 * itself needs no token: nonce_secret() is derived from the site URL.
		 */
		$ts   = time();
		$uid  = get_current_user_id();
		$hmac = hash_hmac( 'sha256', $ts . '.' . $uid, self::nonce_secret() );
		return $ts . '.' . $uid . '.' . $hmac;
	}

	/**
	 * Verify an attach nonce (constant-time, within TTL). On success returns
	 * the paste-in values (site_url + site_token) plus the minting admin's
	 * user ID; on failure returns null. Called by the token-authless
	 * /mcp/attach route.
	 *
	 * @return array{site_url:string,site_token:string,user_id:int}|null
	 */
	public static function verify_attach_nonce( string $nonce ): ?array {
		$parts = explode( '.', $nonce, 3 );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		list( $ts, $uid, $hmac ) = $parts;
		if ( ! ctype_digit( (string) $ts ) || ! ctype_digit( (string) $uid ) ) {
			return null;
		}
		if ( abs( time() - (int) $ts ) > self::NONCE_TTL ) {
			return null; // expired
		}
		$expected = hash_hmac( 'sha256', $ts . '.' . $uid, self::nonce_secret() );
		if ( ! hash_equals( $expected, (string) $hmac ) ) {
			return null; // bad signature
		}

		/*
		 * Only NOW mint the credential the callback hands over — after a valid,
		 * unexpired, correctly-signed nonce has proved the user went through the
		 * Hub and approved. This is the one point in the attach flow where the
		 * user has unambiguously asked to connect, so it is where the token is
		 * created; minting it earlier (at nonce time) meant a page render could
		 * do it. An invalid nonce returns above without minting.
		 *
		 * connect() reuses an existing token, so a re-attach or a duplicate
		 * callback is idempotent and never rotates a paired client's secret.
		 */
		if ( '' === Mcp_Pairing::site_token() ) {
			Mcp_Pairing::connect( false );
		}

		return array(
			'site_url'   => self::site_url_canonical(),
			'site_token' => Mcp_Pairing::site_token(),
			'user_id'    => (int) $uid,
		);
	}

	/**
	 * The URL to redirect the admin to for OAuth attach. Carries the site
	 * URL + a fresh nonce; the hub reads these, logs the user in, and calls
	 * back to confirm.
	 */
	/**
	 * Self-heal: ask the Hub whether THIS site (by its own token) is attached,
	 * and reconcile the local per-user state. This makes the "Connected" badge
	 * reliable even if the attach callback never fired (failed/slow/cached) —
	 * the Hub is the source of truth. Cached in a short transient so the panel
	 * doesn't make an outbound call on every render.
	 *
	 * @param bool $force Skip the cache (e.g. right after a Connect attempt).
	 */
	public static function reconcile_with_hub( bool $force = false ): void {
		$token = Mcp_Pairing::site_token();
		if ( '' === $token ) {
			return; // no token minted yet → definitely not attached
		}

		$cache_key = 'xspeed_hub_reconcile';
		if ( ! $force && false !== get_transient( $cache_key ) ) {
			return; // reconciled recently
		}

		$url  = add_query_arg(
			array( 'site_url' => rawurlencode( self::site_url_canonical() ) ),
			self::hub_url() . '/api/site/attached'
		);
		$resp = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array( 'X-XSpeed-Site-Token' => $token ),
			)
		);
		// Cache for 5 min regardless — don't hammer the Hub on transient errors.
		set_transient( $cache_key, 1, 5 * MINUTE_IN_SECONDS );

		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return; // leave local state as-is on any error
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) ) {
			return;
		}

		$uid = get_current_user_id();
		if ( ! empty( $body['attached'] ) ) {
			// The Hub says attached — mark THIS admin connected if not already.
			$state = self::state( $uid );
			if ( empty( $state['attached'] ) ) {
				self::mark_attached( (string) ( $body['account_email'] ?? '' ), $uid );
			}
		} elseif ( $uid ) {
			// The Hub says NOT attached (e.g. removed on the dashboard) — clear
			// any stale local "connected" so the badge doesn't lie.
			$state = self::state( $uid );
			if ( ! empty( $state['attached'] ) ) {
				delete_user_meta( $uid, self::USER_META );
				self::refresh_site_attached();
			}
		}
	}

	/**
	 * One-click attach redirect (Method 2). The Hub logs the user in, approves,
	 * calls back to this site's /attach route to record the link, then bounces
	 * the browser to `return_url` so the user lands back in the plugin without
	 * navigating manually.
	 *
	 * @param string $return_url Where the Hub should send the browser after a
	 *                           successful attach. Defaults to the dashboard.
	 *                           Callers pass the wizard URL during onboarding so
	 *                           the user returns mid-flow. Must be a local admin
	 *                           URL — we never hand the Hub an off-site redirect.
	 */
	public static function attach_url( string $return_url = '' ): string {
		$args = array(
			'site_url' => self::site_url_canonical(),
			'nonce'    => self::mint_attach_nonce(),
		);
		// Prefill hint only — the current admin's email, so a brand-new user
		// can create/sign into their Hub account in one click without typing.
		// The Hub NEVER trusts this for auth; it only pre-populates the field
		// and still requires the user to verify (magic-link / Google).
		$email = self::current_admin_email();
		if ( '' !== $email ) {
			$args['email'] = $email;
		}
		// Where to send the user after they approve. Constrained to a local
		// admin URL so a tampered value can't turn this into an open redirect.
		$args['return_url'] = self::safe_return_url( $return_url );
		return self::hub_url() . '/attach?' . http_build_query( $args );
	}

	/**
	 * Sanitize a caller-supplied return URL down to a safe, local admin URL.
	 * Falls back to the dashboard for anything off-site or empty, so the value
	 * we hand the Hub can never become an open redirect back into this site.
	 */
	private static function safe_return_url( string $return_url ): string {
		$default = admin_url( 'admin.php?page=' . \XSpeed\Admin::PAGE_SLUG );
		if ( '' === $return_url ) {
			return $default;
		}
		// wp_validate_redirect() returns the fallback for any host not in the
		// allowed list (defaults to this site's host), so an attacker-supplied
		// absolute URL to another domain collapses to the dashboard.
		return wp_validate_redirect( $return_url, $default );
	}

	/** The logged-in admin's email (used only as a Hub sign-in prefill hint). */
	private static function current_admin_email(): string {
		$user = wp_get_current_user();
		if ( $user && ! empty( $user->user_email ) && is_email( $user->user_email ) ) {
			return (string) $user->user_email;
		}
		$admin = get_option( 'admin_email' );
		return is_string( $admin ) && is_email( $admin ) ? $admin : '';
	}

	/**
	 * Record that this site is attached to a hub account, PER USER. Called
	 * from the attach callback with the minting admin's user id (from the
	 * nonce), so each admin gets their own status. Bookkeeping only.
	 *
	 * @param string   $account_email The hub account the site was attached to.
	 * @param int|null $user_id       The admin who attached (defaults to current).
	 */
	public static function mark_attached( string $account_email, ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		if ( $user_id ) {
			update_user_meta(
				$user_id,
				self::USER_META,
				array(
					'attached'      => true,
					'account_email' => sanitize_email( $account_email ),
					'attached_at'   => time(),
				)
			);
		}
		// Attaching makes the site-level answer unconditionally yes.
		update_option( self::SITE_ATTACHED_OPTION, '1', false );
		// Bust the reconcile cache so a reconnect reflects immediately (not the
		// stale 'not attached' cached during the disconnected window).
		delete_transient( 'xspeed_hub_reconcile' );
		return self::public_status( $user_id );
	}

	/**
	 * Disconnect the CURRENT admin from the hub: clear their per-user link.
	 * Other admins' connections are untouched. Does NOT rotate the site_token
	 * (still used by the per-site connection); to fully cut off the hub the
	 * user rotates the token, which the Hub's stored copy then fails on.
	 */
	public static function disconnect(): array {
		$user_id = get_current_user_id();
		$state   = $user_id ? self::state( $user_id ) : array();
		$email   = isset( $state['account_email'] ) ? (string) $state['account_email'] : '';

		// Detach from the Hub for THIS admin's account only (multi-admin: other
		// admins who attached keep their link). The site token proves ownership;
		// account_email scopes the removal.
		$token = Mcp_Pairing::site_token();
		if ( '' !== $token && '' !== $email ) {
			wp_remote_post(
				self::hub_url() . '/api/site/detach',
				array(
					'timeout' => 8,
					'headers' => array(
						'Content-Type'        => 'application/json',
						'X-XSpeed-Site-Token' => $token,
					),
					'body'    => wp_json_encode(
						array(
							'site_url'      => self::site_url_canonical(),
							'account_email' => $email,
						)
					),
				)
			);
		}

		if ( $user_id ) {
			delete_user_meta( $user_id, self::USER_META );
		}

		/*
		 * Also clear the legacy site-wide option. state() falls back to it when
		 * a user has no per-user record, so deleting only the user meta left
		 * that fallback intact — disconnect() returned attached:true and the
		 * card stayed "Connected", making the button look broken. Anyone who
		 * attached before 1.1 (or via the redirect-return handler, which writes
		 * the option) hit this. (FBS-84086)
		 *
		 * The option is a single site-wide record, not per-admin, so there is
		 * no other admin's link being discarded here — the per-user meta above
		 * is what scopes multi-admin, and each admin's own meta is untouched.
		 */
		delete_option( self::OPTION );

		// Other admins may still be attached — recompute rather than assume no.
		self::refresh_site_attached();

		// Bust the reconcile cache so the next status read reflects reality
		// immediately (not the stale 'attached' cached before disconnect).
		delete_transient( 'xspeed_hub_reconcile' );
		return self::public_status( $user_id );
	}

	/**
	 * Ask the Hub to run a GTmetrix test for this site.
	 *
	 * The Hub owns the GTmetrix account, the credits and the quota — this site
	 * only proves who it is, with the same site_token it uses everywhere else.
	 * That is the whole point of the feature: the site owner needs no GTmetrix
	 * account and no API key.
	 *
	 * Returns the Hub's decoded body on success (a run row plus the remaining
	 * allowance). On failure returns a WP_Error whose CODE is stable and
	 * machine-readable, so the UI can respond to "you're out of tests this
	 * month" differently from "this site isn't verified" instead of printing
	 * whatever sentence came back.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function gtmetrix_test() {
		return self::gtmetrix_request( 'POST', '/api/site/gtmetrix/test' );
	}

	/**
	 * Recent Hub-run tests for this site, plus the remaining allowance.
	 *
	 * Polled while a run is in flight, and read once on load so the button can
	 * show the count before anyone presses anything.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function gtmetrix_runs() {
		$result = self::gtmetrix_request( 'GET', '/api/site/gtmetrix/runs' );
		if ( ! is_wp_error( $result ) ) {
			self::store_hub_results( $result );
		}
		return $result;
	}

	/**
	 * Copy any finished Hub runs into THIS SITE's own score history.
	 *
	 * The Hub stores the result too, but that is its copy, not ours. Without
	 * this the plugin would have to ask the Hub every time it wanted to draw
	 * a score it already paid for — and a site that later disconnects would
	 * lose its history entirely. The run belongs to the site.
	 *
	 * Idempotent: the Hub reports a finished run on every poll after it
	 * completes, so each result is matched on provider + timestamp and stored
	 * once.
	 *
	 * @param array<string,mixed> $payload Decoded /site/gtmetrix/runs body.
	 */
	private static function store_hub_results( array $payload ): void {
		$runs = isset( $payload['runs'] ) && is_array( $payload['runs'] ) ? $payload['runs'] : array();
		if ( empty( $runs ) ) {
			return;
		}

		foreach ( $runs as $run ) {
			if ( ! is_array( $run ) || 'done' !== ( $run['status'] ?? '' ) ) {
				continue;
			}
			$r = isset( $run['result'] ) && is_array( $run['result'] ) ? $run['result'] : array();
			if ( empty( $r ) ) {
				continue;
			}

			// The Hub works in milliseconds; the plugin's history is seconds.
			$ts        = isset( $r['ran_at'] ) ? (int) round( ( (int) $r['ran_at'] ) / 1000 ) : 0;
			$remote_id = isset( $run['id'] ) ? (string) $run['id'] : '';
			// Keyed on the Hub's run id, not the timestamp: a retry and the
			// original delivery can differ by milliseconds and both looked
			// new, so one test appeared twice in the history.
			if ( $ts <= 0 || '' === $remote_id || Score_Store::exists_remote( $remote_id ) ) {
				continue;
			}

			Score_Store::insert(
				array(
					'ok'         => true,
					'provider'   => 'gtmetrix',
					'ts'         => $ts,
					'url'        => (string) ( $r['url'] ?? '' ),
					'strategy'   => (string) ( $r['strategy'] ?? 'desktop' ),
					'score'      => $r['score'] ?? null,
					'metrics'    => array(
						'lcp'  => $r['lcp'] ?? null,
						'fcp'  => $r['fcp'] ?? null,
						'cls'  => $r['cls'] ?? null,
						'tbt'  => $r['tbt'] ?? null,
						'si'   => $r['si'] ?? null,
						'ttfb' => $r['ttfb'] ?? null,
					),
					'report_url' => $r['report_url'] ?? null,
					'remote_id'  => $remote_id,
					// What the report said to fix — stored here so the panel
					// can show it without sending anyone to GTmetrix's page.
					'opportunities' => $r['opportunities'] ?? null,
				),
				'hub'
			);
		}
	}

	/**
	 * Shared transport for the two calls above.
	 *
	 * Kept private and shared because the interesting part — turning an HTTP
	 * failure into a stable error code — must behave identically for both. A
	 * divergence there would show up as the UI handling a quota error on one
	 * path and not the other.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path under the hub base URL.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function gtmetrix_request( string $method, string $path ) {
		$token = Mcp_Pairing::site_token();
		if ( '' === $token ) {
			return new \WP_Error(
				'not_connected',
				__( 'Connect this site to xSpeed Hub to run a free GTmetrix test.', 'xspeed' )
			);
		}

		$site_url = self::site_url_canonical();
		$args     = array(
			// A GTmetrix test takes a minute, but the Hub answers as soon as it
			// has ACCEPTED the job — this waits for that handshake only.
			'timeout' => 15,
			'headers' => array( 'X-XSpeed-Site-Token' => $token ),
		);

		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( array( 'site_url' => $site_url ) );
			$resp                            = wp_remote_post( self::hub_url() . $path, $args );
		} else {
			$resp = wp_remote_get(
				add_query_arg( array( 'site_url' => rawurlencode( $site_url ) ), self::hub_url() . $path ),
				$args
			);
		}

		if ( is_wp_error( $resp ) ) {
			return new \WP_Error(
				'hub_unreachable',
				__( 'Could not reach xSpeed Hub. Please try again.', 'xspeed' )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$body = is_array( $body ) ? $body : array();

		if ( $code >= 200 && $code < 300 ) {
			return $body;
		}

		// Prefer the Hub's own error code — it is already stable and specific
		// (site_not_verified, gtmetrix_quota_exceeded, gtmetrix_run_active,
		// gtmetrix_not_configured). Fall back to the status class so an
		// unexpected response still produces something the UI can branch on.
		$code_key = isset( $body['error'] ) && is_string( $body['error'] ) ? $body['error'] : '';
		if ( '' === $code_key ) {
			$code_key = 401 === $code ? 'not_connected' : 'hub_error';
		}

		$message = isset( $body['message'] ) && is_string( $body['message'] ) && '' !== $body['message']
			? $body['message']
			: __( 'The test could not be started.', 'xspeed' );

		// Carry the quota numbers through on a 429 so the panel can say
		// "0 of 5 left" rather than just refusing.
		$data = array( 'status' => $code );
		foreach ( array( 'used', 'limit', 'quota', 'run' ) as $key ) {
			if ( isset( $body[ $key ] ) ) {
				$data[ $key ] = $body[ $key ];
			}
		}

		return new \WP_Error( $code_key, $message, $data );
	}
}
