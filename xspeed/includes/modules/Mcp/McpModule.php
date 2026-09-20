<?php
/**
 * MCP module — the site side of xSpeed's MCP integration.
 *
 * PRIMARY path (no hosted infra): the plugin speaks the MCP protocol
 * DIRECTLY at this site's own URL. The user pastes their own site's MCP
 * endpoint + connection token into their AI client:
 *
 *     https://thissite.com/xspeed/mcp          (pretty, via rewrite)
 *     https://thissite.com/wp-json/xspeed/v1/mcp   (always-on fallback)
 *
 * The MCP JSON-RPC handling lives in Mcp_Server; the tool catalog in
 * Mcp_Tools. Auth is the per-site connection token (Mcp_Auth /
 * Mcp_Pairing). See IMPLEMENTATION.md §17.
 *
 * OPTIONAL path (hosted broker, api.xspeedcache.com): the same tool
 * catalog is also exposed as token-authenticated REST routes under
 * /xspeed/v1/mcp/tool/* so a hosted broker can proxy to it for a single
 * shared vanity URL. Not required for the product to work.
 *
 * Admin-only management routes (manage_options) drive the dashboard
 * "Connect AI" panel: /mcp/connection, /mcp/connect, /mcp/disconnect.
 *
 * These routes register DIRECTLY on rest_api_init (NOT via Rest_Manager,
 * whose wrap_permission() forces a current_user_can() gate that MCP's
 * token-only calls can never satisfy).
 *
 * Tier: Free. The ONLY gate is possession of the per-site connection
 * token, which an admin (manage_options) must explicitly mint via
 * Connect. A fresh install ships with no token → every MCP call is 401
 * until the admin opts in. Adds ZERO cache logic.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

use XSpeed\Activity_Log;
use XSpeed\Module;
use XSpeed\Onboarding;

defined( 'ABSPATH' ) || exit;

final class McpModule extends Module {

	public const SLUG    = 'mcp';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	/**
	 * Every rewrite rule this module registers, in registration order.
	 *
	 * Single source of truth: add_rewrite() registers these, and the self-heal
	 * guard re-flushes when any is missing from the stored table. They were two
	 * hand-maintained lists before, which is a silent drift risk — a rule
	 * dropped from one and not the other leaves the guard restoring a rule
	 * nothing registers, or never firing for one that is registered.
	 *
	 * @var string[] Rewrite regexes. The query each maps to is built in
	 *               add_rewrite(), which also fixes their order.
	 */
	public const REWRITE_RULES = array(
		'^xspeed/mcp/([a-f0-9]{64})/?$',
		'^xspeed/mcp/?$',
		'^xspeed/mcp/attach/?$',
		// OAuth discovery. RFC 9728 §3.1 / RFC 8414 §3.1 put the
		// `.well-known` segment BEFORE the resource/issuer path, and both of
		// our identifiers are /xspeed/mcp — so this pair of URLs, and only
		// this pair, is ours. It names our own path explicitly: a catch-all
		// tail here also matched other MCP plugins' discovery URLs on the
		// same site and answered them with our metadata, which broke their
		// connectors. The bare root form is registered conditionally and so
		// lives apart, in ROOT_DISCOVERY_RULE.
		'^\.well-known/oauth-(protected-resource|authorization-server)/xspeed/mcp/?$',
		'^xspeed/authorize/?$',
	);

	/**
	 * The root-form discovery rule — registered CONDITIONALLY, which is why
	 * it is not in REWRITE_RULES.
	 *
	 * It is the address a client built to the 2025-03-26 MCP spec looks at,
	 * and the only address it looks at; current clients read the
	 * protected-resource document first and follow it to the path form. So
	 * dropping it outright would cut off older clients on every site,
	 * including the single-plugin sites where the collision #266 exists to
	 * fix never happened. We answer it while it is uncontested and stand
	 * down the moment another plugin's rule claims it —
	 * root_discovery_contested().
	 */
	public const ROOT_DISCOVERY_RULE = '^\.well-known/oauth-(protected-resource|authorization-server)/?$';

	/**
	 * Rules earlier builds registered that we never register again under any
	 * condition. The self-heal guard flushes once when it finds OUR copy of
	 * one still in the stored table.
	 *
	 * The catch-all below shipped in an intermediate build and matched every
	 * path-suffixed discovery URL on the site, including other MCP plugins'
	 * (#264). Nothing brings it back, so its removal is unconditional —
	 * unlike ROOT_DISCOVERY_RULE, which is a rule we still register when the
	 * root URL is uncontested and therefore cannot live in this list.
	 *
	 * Ownership is read from the rule's TARGET, never from the regex alone:
	 * a sibling may register the same regex for its own document, its rule
	 * comes back from every flush, and a guard that treated that as stale
	 * would flush on every request forever.
	 *
	 * @var string[]
	 */
	public const RETIRED_REWRITE_RULES = array(
		'^\.well-known/oauth-(protected-resource|authorization-server)(?:/.*)?/?$',
	);

	/** REST namespace shared with Free. Public: Mcp_Server builds the
	 * discovery fallback URL from it. */
	public const NS = 'xspeed/v1';

	/** Query var flagging a pretty /xspeed/mcp request. */
	private const QUERY_VAR = 'xspeed_mcp';

	/** Query var carrying the token when embedded in the URL path. */
	private const TOKEN_QUERY_VAR = 'xspeed_mcp_token';

	/** Query var flagging a /.well-known/ OAuth discovery request. */
	private const WELLKNOWN_QUERY_VAR = 'xspeed_mcp_wellknown';

	/**
	 * Query var flagging the browser-facing OAuth authorize page. This is
	 * served OUTSIDE the REST API on purpose: a REST route only honors cookie
	 * auth when a REST nonce accompanies it, but a browser arriving from
	 * wp-login carries the cookie with NO nonce — so is_user_logged_in() would
	 * be false there and the consent screen would loop back to login forever.
	 * A normal front-end URL (rewrite + parse_request) sees standard cookie
	 * auth, so the logged-in admin check works.
	 */
	private const AUTHORIZE_QUERY_VAR = 'xspeed_mcp_authorize';

	/** Front-end path of the browser-facing authorize page. */
	private const AUTHORIZE_PATH = 'xspeed/authorize';

	/** Query var flagging the pretty /xspeed/mcp/attach callback. */
	private const ATTACH_QUERY_VAR = 'xspeed_mcp_attach';

	public function ui_metadata(): array {
		return array(
			'label'        => __( 'MCP Server', 'xspeed' ),
			'icon'         => 'Sparkles',
			'description'  => __( 'Let Claude or another AI agent run this site — purge, check stats, change settings. This is the only thing you connect an AI to, and it is free with no API key.', 'xspeed' ),
			'custom_panel' => 'McpPanel',
		);
	}

	/**
	 * MCP pairing state lives in xspeed_module_mcp but is managed by
	 * Mcp_Pairing, not the schema engine. Empty schema so the base class
	 * doesn't auto-register generic settings routes.
	 */
	public function settings_schema(): array {
		return array();
	}

	/**
	 * All MCP routes register directly (see class docblock). Returning an
	 * empty array keeps Rest_Manager out of the token-auth path entirely.
	 */
	public function rest_routes(): array {
		return array();
	}

	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );

		// Pretty per-site endpoint: /xspeed/mcp → MCP JSON-RPC handler.
		// `wp_loaded`, not `init`: add_rewrite() decides whether to claim the
		// root discovery URL by looking at the rewrite table, and on `init` that
		// view is incomplete -- a sibling MCP plugin hooked at the same priority
		// but loaded after us has not registered yet. Root then looks
		// uncontested, we register our rule, and the self-heal guard concludes
		// nothing is stale, so it never flushes. That is a fixed point: the
		// table never converges, and because our rule is the one WordPress
		// matches, the sibling never sees the request either.
		//
		// By `wp_loaded` every init callback on every request type has run, so
		// the contested check sees the sibling and the guard flushes once.
		// WP_Rewrite::flush_rules() already defers itself to `wp_loaded`, so
		// nothing is lost by deciding here, and did_action('wp_loaded') is
		// truthy inside this callback, so the flush lands in time for
		// parse_request in the same request. (#266 QA)
		// Priority 0: still after every `init` callback, but ahead of the
		// widely copied `add_action( 'wp_loaded', 'flush_rewrite_rules' )`
		// snippet. If such a plugin flushed first it would write a table
		// without our rules, our guard would find them missing and flush
		// again -- two flushes and two option writes on every request.
		add_action( 'wp_loaded', array( $this, 'add_rewrite' ), 0 );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		// Priority 1: a sibling MCP plugin that also claims /.well-known/ gets
		// to answer first at the default priority 10, and whoever answers
		// first calls exit(). Running early means the URL is decided by WHOSE
		// path it is, not by which plugin happened to load last.
		add_action( 'parse_request', array( $this, 'maybe_handle_pretty_endpoint' ), 1 );

		// Hub redirect-return: after the user approves on the Hub, it sends the
		// browser back to a plugin admin URL carrying ?xspeed_connected=1 plus
		// the account email + the SAME signed nonce we minted. We verify our own
		// nonce and mark this admin attached — no server-to-server callback
		// needed, so it works for local/firewalled sites too.
		add_action( 'admin_init', array( $this, 'maybe_handle_hub_return' ) );

		// An attached admin who is DELETED (or removed from the blog) never
		// runs disconnect(), so the site-level attached mirror would report
		// hub:true forever. deleted_user fires after both wp_delete_user()
		// and wpmu_delete_user() drop the user, so a plain recompute is
		// honest there. remove_user_from_blog is core's only removal action
		// and fires BEFORE removal, so its handler clears the departing
		// user's record before recomputing (see Mcp_Hub::handle_user_removed).
		add_action( 'deleted_user', array( Mcp_Hub::class, 'refresh_site_attached' ) );
		add_action( 'remove_user_from_blog', array( Mcp_Hub::class, 'handle_user_removed' ) );
	}

	/**
	 * Handle the browser landing back from the Hub after a connect. Idempotent
	 * and safe to run on every admin page load: it only acts when the return
	 * markers are present and the nonce verifies.
	 */
	public function maybe_handle_hub_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- auth is the signed HMAC nonce below, not a WP nonce; this is a read-only routing check.
		$nonce = isset( $_GET['xspeed_hub_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['xspeed_hub_nonce'] ) ) : '';
		$email = isset( $_GET['xspeed_hub_email'] ) ? sanitize_email( wp_unslash( $_GET['xspeed_hub_email'] ) ) : '';

		/*
		 * Trigger on the signed nonce, not on `xspeed_connected`.
		 *
		 * The Hub bounces the browser back with xspeed_hub_nonce +
		 * xspeed_hub_email, but it does NOT always append xspeed_connected —
		 * that marker only survives when the return_url we handed it carried
		 * one. Gating on it meant a real, correctly-signed return was ignored:
		 * the attach was never recorded, the params were never stripped, and
		 * the card kept showing "Not connected" while the nonce sat in the
		 * address bar. The nonce is the actual proof of a genuine round trip,
		 * so it is what this handler keys on. (FBS-84086)
		 */
		if ( '' === $nonce && empty( $_GET['xspeed_connected'] ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Verify OUR own signed nonce (proves the round-trip went through the
		// Hub with a token we minted), then record the connection.
		if ( '' !== $nonce ) {
			$verified = Mcp_Hub::verify_attach_nonce( $nonce );
			if ( null !== $verified ) {
				$uid = isset( $verified['user_id'] ) ? (int) $verified['user_id'] : get_current_user_id();
				Mcp_Hub::mark_attached( $email, $uid ?: null );
			}
		}

		// ALWAYS strip the one-time return markers from the URL and redirect to
		// the clean address. These params are single-use; if they persist in the
		// browser URL, a later reload re-triggers the "just connected" path and
		// flashes a stale connected state even after the user has disconnected.
		$clean = remove_query_arg( array( 'xspeed_connected', 'xspeed_hub_nonce', 'xspeed_hub_email' ) );

		// The setup wizard keeps its current step in component state, so a
		// redirect remounts it at step 1 — dumping the user back at the START of
		// onboarding immediately after they finished its LAST step. Carry a
		// durable hint so the wizard resumes on Connect instead. It's a plain
		// step marker, not an auth signal (the nonce above did that job), and
		// it's safe to leave in the URL: re-loading it just re-opens the same
		// step rather than re-running the connect path. (PM feedback)
		if ( false !== strpos( (string) $clean, 'page=' . Onboarding::PAGE_SLUG ) ) {
			$clean = add_query_arg( 'xspeed_step', 'connect', $clean );
		}

		wp_safe_redirect( $clean );
		exit;
	}

	/**
	 * Flush rewrites once when the module first boots so /xspeed/mcp works
	 * without a manual permalink re-save. Cheap: gated on a one-shot flag.
	 */
	public function activate(): void {
		$this->add_rewrite();
		flush_rewrite_rules( false );
	}

	public function deactivate(): void {
		flush_rewrite_rules( false );
	}

	// -- Pretty endpoint: /xspeed/mcp --

	public function add_rewrite(): void {
		// The stored table is WordPress's routing table AND the only durable
		// record of which plugin owns which discovery URL, so both the
		// conditional registration below and the self-heal guard at the
		// bottom read the SAME snapshot of it. Deciding twice from two reads
		// is how a guard ends up flushing away a rule it just registered.
		$stored_rules = get_option( 'rewrite_rules' );

		// Token-in-URL form: /xspeed/mcp/<token> — a single string the user
		// pastes into their AI client (no separate token field). The bare
		// /xspeed/mcp still works with a Bearer/header token.
		add_rewrite_rule(
			'^xspeed/mcp/([a-f0-9]{64})/?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::TOKEN_QUERY_VAR . '=$matches[1]',
			'top'
		);
		add_rewrite_rule( '^xspeed/mcp/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );

		// Pretty attach-callback endpoint: /xspeed/mcp/attach — the hub POSTs
		// the signed nonce here to verify + fetch the token. Uses the plugin's
		// own rewrite (consistent with the MCP URL, survives hosts that block
		// /wp-json). Placed BEFORE the token rule would never match "attach"
		// (that rule requires 64 hex chars), so ordering is safe.
		add_rewrite_rule( '^xspeed/mcp/attach/?$', 'index.php?' . self::ATTACH_QUERY_VAR . '=1', 'top' );

		// OAuth discovery documents. RFC 9728 §3.1 / RFC 8414 §3.1 place the
		// `.well-known` segment BEFORE the resource/issuer path, and both of
		// our canonical identifiers are the MCP endpoint URL, so our
		// documents live at:
		//   /.well-known/oauth-protected-resource/xspeed/mcp
		//   /.well-known/oauth-authorization-server/xspeed/mcp
		//
		// Matched EXACTLY. A `(?:/.*)?` tail covers our URLs in one rule, but
		// also matches every OTHER plugin's discovery URL on the same site —
		// and WordPress matches rewrite rules in table order rather than by
		// specificity, so a sibling's own exact rule never gets reached. Its
		// clients then receive OUR metadata, find a resource and issuer that
		// do not match what they are connecting to, and abort before the
		// login screen.
		add_rewrite_rule(
			'^\\.well-known/oauth-(protected-resource|authorization-server)/xspeed/mcp/?$',
			'index.php?' . self::WELLKNOWN_QUERY_VAR . '=$matches[1]',
			'top'
		);

		// The bare root form, ONLY while no other plugin claims it. A client
		// written to the 2025-03-26 MCP spec looks there and nowhere else, so
		// giving it up unconditionally would break those clients on every
		// site — including the single-plugin sites where the collision never
		// happened. When a sibling's rule is present the URL is theirs and we
		// register nothing, which is the case #266 is about. Current clients
		// read the protected-resource document first and follow it wherever
		// it points, so they are unaffected either way. The document served
		// at root carries the LEGACY
		// host-only issuer, because that is the identifier a client used to
		// derive that URL (RFC 8414 §3.3).
		$root_contested = self::root_discovery_contested( $stored_rules );
		if ( ! $root_contested ) {
			add_rewrite_rule(
				self::ROOT_DISCOVERY_RULE,
				'index.php?' . self::WELLKNOWN_QUERY_VAR . '=$matches[1]',
				'top'
			);
		}

		// Browser-facing OAuth consent page — served OUTSIDE REST so cookie
		// auth (is_user_logged_in) works after the wp-login round-trip.
		add_rewrite_rule( '^xspeed/authorize/?$', 'index.php?' . self::AUTHORIZE_QUERY_VAR . '=1', 'top' );

		// Self-heal: flush once if the stored rewrite table disagrees with the
		// rules we just registered. Checking only the first rule is not
		// enough — a site flushed under an older build (which had /xspeed/mcp
		// but not the later /xspeed/authorize + /.well-known rules) keeps that
		// first rule, so the guard never fires and OAuth discovery 404s
		// forever. Guard on the full set so any newly-added rule triggers a
		// re-flush.
		//
		// The guard must mirror the registration decisions EXACTLY, or it
		// never reaches a fixed point:
		//
		//   - Uncontested root: we register it, so the table must hold it
		//     with OUR target. A flush produces exactly that, and the next
		//     request reads the same table and stays uncontested — our own
		//     target never counts as a sibling's.
		//   - Contested root: we register nothing, so OUR copy must be gone.
		//     A flush regenerates the sibling's rule (they register it every
		//     request) but not ours, so the next request is quiet.
		//
		// Ownership is read from the TARGET in both directions. Keying on the
		// regex alone is what produced a flush on every request forever when
		// a sibling held that regex: their rule comes back from every flush.
		//
		// This runs on `wp_loaded` for every request, so the first request after an
		// upgrade flushes once and the guard is quiet from then on. It cannot
		// move to Plugin::maybe_upgrade() — that is admin-only and runs at
		// plugins_loaded 21, i.e. BEFORE init, so a flush there would write a
		// table without our rules and this guard would flush a second time.
		if ( ! is_array( $stored_rules ) ) {
			return;
		}

		$stale    = false;
		$retiring = false;
		foreach ( self::REWRITE_RULES as $rule ) {
			if ( ! isset( $stored_rules[ $rule ] ) ) {
				$stale = true;
				break;
			}
		}

		// Our copy of the root rule must be present exactly when we register
		// it. Present-and-unwanted is the #266 upgrade; absent-and-wanted is
		// an older table, or a sibling that has since gone away.
		$root_is_ours = isset( $stored_rules[ self::ROOT_DISCOVERY_RULE ] )
			&& self::is_our_rule_target( $stored_rules[ self::ROOT_DISCOVERY_RULE ] );
		if ( $root_is_ours === $root_contested ) {
			$stale    = true;
			$retiring = $root_contested;
		}

		// Not an identity move — nothing a site owner can act on — so this
		// one flushes quietly.
		foreach ( self::RETIRED_REWRITE_RULES as $rule ) {
			if ( isset( $stored_rules[ $rule ] ) && self::is_our_rule_target( $stored_rules[ $rule ] ) ) {
				$stale = true;
				break;
			}
		}

		if ( ! $stale ) {
			return;
		}

		if ( $retiring ) {
			// The one moment the identity move is observable to a site owner,
			// and it happens on a front-end request with no UI attached. Fires
			// once: after the flush our rule is gone, so the next request
			// finds nothing to hand over.
			Activity_Log::record(
				'mcp_discovery_moved',
				__( 'Another plugin now claims the site-wide OAuth discovery address, so xSpeed handed it over. Its own is /.well-known/oauth-protected-resource/xspeed/mcp — AI assistants already connected may ask for approval once more.', 'xspeed' ),
				Activity_Log::INFO
			);
		}

		flush_rewrite_rules( false );
	}

	/**
	 * Whether another plugin's rewrite rule already routes the ROOT discovery
	 * URLs, making them theirs rather than ours.
	 *
	 * Read from two views of the rewrite table, because neither alone is
	 * complete at `init`:
	 *
	 *   - the STORED table, which is what WordPress actually routes with and
	 *     the only view that survives the request. If a sibling registered
	 *     the same regex after us at the last flush, its target is what is
	 *     stored, and that is precisely "the sibling owns this URL now".
	 *   - the IN-MEMORY rules registered so far this request, which catches a
	 *     sibling that hooks `init` earlier than we do and has therefore not
	 *     reached the stored table yet.
	 *
	 * A sibling that registers LATER than us used to be the one case neither
	 * view saw, and it did NOT resolve itself: the guard is what triggers a
	 * flush, so a guard reading an incomplete view simply never fires. That
	 * is why add_rewrite() now runs on `wp_loaded` rather than `init` — by
	 * then every plugin has registered, whatever its load order.
	 *
	 * @param mixed $stored The stored rewrite table, if already read.
	 */
	public static function root_discovery_contested( $stored = null ): bool {
		$tables = array();
		if ( is_array( $stored ) ) {
			$tables[] = $stored;
		} elseif ( null === $stored ) {
			$option = get_option( 'rewrite_rules' );
			if ( is_array( $option ) ) {
				$tables[] = $option;
			}
		}

		if ( isset( $GLOBALS['wp_rewrite'] ) && is_object( $GLOBALS['wp_rewrite'] ) ) {
			foreach ( array( 'extra_rules_top', 'extra_rules' ) as $prop ) {
				if ( isset( $GLOBALS['wp_rewrite']->$prop ) && is_array( $GLOBALS['wp_rewrite']->$prop ) ) {
					$tables[] = $GLOBALS['wp_rewrite']->$prop;
				}
			}
		}

		foreach ( $tables as $rules ) {
			foreach ( $rules as $pattern => $target ) {
				if ( ! self::is_a_wellknown_rule( (string) $pattern ) || self::is_our_rule_target( $target ) ) {
					continue;
				}
				foreach ( array( 'protected-resource', 'authorization-server' ) as $doc ) {
					$probe = '.well-known/oauth-' . $doc;
					if ( preg_match( '#' . str_replace( '#', '\\#', (string) $pattern ) . '#', $probe ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Whether a rewrite regex was written FOR a .well-known discovery URL,
	 * as opposed to merely matching one.
	 *
	 * WordPress's own page rule -- `(.?.+?)/?$` => `index.php?pagename=...`
	 * -- is in the stored table of every site using pretty permalinks, and
	 * it matches `.well-known/oauth-protected-resource` exactly as it
	 * matches every other path on the site. Reading that as a sibling's
	 * claim would report root as contested EVERYWHERE: the root document
	 * would be retired on every install, including the single-plugin sites
	 * this change exists to leave alone, and each of them would log a
	 * hand-over that never happened.
	 *
	 * A rule that routes these URLs on purpose spells the segment out, so
	 * that is the signal. Backslashes are stripped first because the regex
	 * carries them as escapes (`^\\.well-known/...`) and a rule is free to
	 * escape the hyphen too.
	 *
	 * @param string $pattern The stored rewrite regex.
	 */
	private static function is_a_wellknown_rule( string $pattern ): bool {
		return false !== stripos( str_replace( '\\', '', $pattern ), 'well-known' );
	}

	/**
	 * Whether a stored rewrite target was written by this module.
	 *
	 * Every rule we register resolves to `index.php?<one of our query
	 * vars>=…`, and no other plugin sets those. Used to tell OUR leftover
	 * copy of a retired rule from a sibling's rule that happens to share the
	 * regex — only the first is ours to flush away.
	 *
	 * @param mixed $target The stored rewrite target.
	 */
	private static function is_our_rule_target( $target ): bool {
		if ( ! is_string( $target ) ) {
			return false;
		}

		foreach ( array( self::QUERY_VAR, self::TOKEN_QUERY_VAR, self::WELLKNOWN_QUERY_VAR, self::AUTHORIZE_QUERY_VAR, self::ATTACH_QUERY_VAR ) as $var ) {
			if ( false !== strpos( $target, $var . '=' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * True when a request for our discovery URL can reach WordPress at all.
	 *
	 * Since maybe_handle_pretty_endpoint() claims the document by REQUEST
	 * PATH, a sibling plugin winning the rewrite match no longer matters —
	 * we answer either way. What still breaks the pretty URL is there being
	 * no rewrite for it in the first place (plain permalinks), because then
	 * nothing routes the path to index.php and parse_request never runs.
	 *
	 * Blind to upstream interception: a host that owns the /.well-known/
	 * prefix (an nginx ACME block, an edge redirect rule) answers before
	 * WordPress loads, and WP cannot see that. Use the
	 * `xspeed_mcp_resource_metadata_url` filter on such hosts.
	 */
	public static function wellknown_rewrites_active(): bool {
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || array() === $rules ) {
			return false;
		}

		// Any rule that routes our discovery path to index.php will do — ours
		// or a sibling's — because the path check inside the handler decides
		// the outcome once the request lands.
		//
		// Probe the URL the 401 challenge actually advertises: the
		// path-suffixed form, which is the canonical identity since #266.
		// Probing root would answer a different question — whether ANY plugin
		// routes the contested URL — and on a site where a sibling owns it
		// that answer says nothing about whether our own document is
		// reachable.
		$probe = '.well-known/oauth-protected-resource/' . Mcp_Pairing::SITE_ENDPOINT_PATH;
		foreach ( $rules as $pattern => $target ) {
			if ( preg_match( '#' . str_replace( '#', '\\#', $pattern ) . '#', $probe ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string[] $vars Registered query vars.
	 * @return string[]
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::TOKEN_QUERY_VAR;
		$vars[] = self::WELLKNOWN_QUERY_VAR;
		$vars[] = self::AUTHORIZE_QUERY_VAR;
		$vars[] = self::ATTACH_QUERY_VAR;
		return $vars;
	}

	/**
	 * Which discovery document the CURRENT request path asks for, if any.
	 *
	 * Claims only URLs that are unambiguously ours, mirroring the rewrite
	 * rules exactly: the RFC 9728 §3.1 / RFC 8414 §3.1 path-suffixed form
	 * naming our own resource and issuer (`/xspeed/mcp`). A suffix belonging
	 * to a sibling plugin is deliberately NOT claimed — answering
	 * `/.well-known/oauth-protected-resource/betterlinks/mcp` with xSpeed
	 * metadata is the same bug that broke this site, just pointed the other
	 * way.
	 *
	 * The bare root form is claimed only while no other plugin's rewrite rule
	 * claims it. Leaving the suffix optional here took the root document from
	 * a sibling even on a build that had stopped registering its own root
	 * rule, so the path check is exact.
	 *
	 * The TABLE is what hands root over -- add_rewrite() stops registering
	 * the rule and flushes it away. This claim only releases it, and only
	 * while a sibling's rule actually owns the URL: once WordPress has
	 * routed the request to OUR query var, no other plugin's handler can see
	 * it, so releasing it would abandon the request to the front page rather
	 * than pass it on. Note that is about the winning rule's TARGET, not its
	 * regex -- a sibling can hold the same pattern. The document served at root carries the LEGACY host-only
	 * issuer, the identifier a client used to derive that URL. (#266)
	 *
	 * @param string $matched_query The query the matched rule resolved to.
	 * @return array{doc:string,issuer:string} Doc name ('' when not ours)
	 *                                         and the identity to stamp on it.
	 */
	private function wellknown_claim_from_path( string $matched_query = '' ): array {
		$none = array(
			'doc'    => '',
			'issuer' => '',
		);

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
		if ( '' === $uri ) {
			return $none;
		}

		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		// Sites in a subdirectory carry that prefix on every request.
		$home = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		$path = trim( $path, '/' );

		// trim() above already dropped a trailing slash, so `/xspeed/mcp/`
		// still matches — and so does the root form with one.
		$ours = '#^\.well-known/oauth-(protected-resource|authorization-server)'
			. '/' . preg_quote( Mcp_Pairing::SITE_ENDPOINT_PATH, '#' ) . '$#';
		if ( preg_match( $ours, $path, $m ) ) {
			return array(
				'doc'    => $m[1],
				'issuer' => Mcp_OAuth::issuer(),
			);
		}

		// Releasing root is only safe when somebody else can pick it up. If
		// OUR rule is what WordPress matched, nobody can: the sibling's query
		// var is unset, so its handler never runs, and the request falls
		// through to the front page -- a 301 to the homepage where dev
		// returns JSON. Answering with the legacy document is the pre-#266
		// behaviour. (#266 QA)
		//
		// Keyed on the query the matched rule RESOLVED TO -- not on
		// $wp->query_vars, and not on which regex matched.
		//
		// query_vars is wrong because the var is public and WP::parse_request
		// lets $_GET override anything a rule set, so `?xspeed_mcp_wellknown=1`
		// would let anyone force our metadata onto a URL a sibling owns.
		//
		// matched_rule is wrong because the rewrite table is keyed BY regex:
		// a sibling that registered this same pattern replaces our entry and
		// the key still reads as ours, while the target behind it is theirs.
		// That is a live case here -- is_our_rule_target() exists for it --
		// and keying on the rule would answer for the sibling, which is the
		// bug this whole change is about.
		//
		// matched_query is built from the winning rule's TARGET (class-wp.php,
		// before the parse_request action) and $_GET never touches it. If it
		// sets our query var, our rule genuinely won. (#266 QA)
		$routed_to_us = 1 === preg_match(
			'#(?:^|&)' . preg_quote( self::WELLKNOWN_QUERY_VAR, '#' ) . '=#',
			$matched_query
		);
		$root         = '#^\.well-known/oauth-(protected-resource|authorization-server)$#';
		if ( preg_match( $root, $path, $m ) && ( $routed_to_us || ! self::root_discovery_contested() ) ) {
			return array(
				'doc'    => $m[1],
				'issuer' => Mcp_OAuth::legacy_issuer(),
			);
		}

		return $none;
	}

	/**
	 * Serve the MCP endpoint on the pretty path. Runs on parse_request so
	 * it fires before the main query, and short-circuits WP entirely.
	 *
	 * @param \WP $wp The WP request object.
	 */
	public function maybe_handle_pretty_endpoint( $wp ): void {
		// OAuth discovery documents.
		//
		// Read the doc name from the REQUEST PATH, and ONLY from the path.
		// `add_rewrite_rule( …, 'top' )` only means "top at the moment it
		// runs", so whichever MCP plugin hooks `init` last ends up first in
		// the table — an order set by plugin load order, which no plugin
		// controls. A sibling's catch-all
		// (`…(protected-resource|authorization-server)(?:/.*)?/?$`) then wins
		// the match and our query var is never set, even though the URL is
		// unambiguously ours. Observed live with two different plugins on one
		// site. parse_request runs AFTER matching, so the path is the one
		// signal no sibling rule can take away from us.
		//
		// The query VAR is deliberately never consulted. It is public, so
		// $_GET can set it on any URL, and answering from it would put our
		// metadata on somebody else's address — the whole bug. Which rewrite
		// RULE matched is a different thing: WordPress decides it, the query
		// string cannot influence it, and it is only read to tell "a sibling
		// owns this URL" from "we own it and nobody else can answer".
		$matched_query = is_object( $wp ) && isset( $wp->matched_query ) ? (string) $wp->matched_query : '';
		$claim         = $this->wellknown_claim_from_path( $matched_query );
		$doc   = $claim['doc'];
		if ( '' !== $doc ) {
			$data = 'authorization-server' === $doc
				? Mcp_OAuth::authorization_server_metadata( $claim['issuer'] )
				: Mcp_OAuth::protected_resource_metadata( $claim['issuer'] );
			status_header( 200 );
			header( 'Content-Type: application/json; charset=utf-8' );
			// Public and cacheable, but short: this document IS the server's
			// identity, and a cached copy outliving an issuer change is the
			// one failure a client cannot recover from on its own. (#266)
			header( 'Cache-Control: public, max-age=300' );
			echo wp_json_encode( $data );
			exit;
		}

		// Pretty attach-callback: /xspeed/mcp/attach. The hub POSTs the signed
		// nonce; we verify it and return this site's URL + token. Auth is the
		// nonce itself (admin-minted, HMAC-signed), so no credential needed.
		if ( ! empty( $wp->query_vars[ self::ATTACH_QUERY_VAR ] ) ) {
			$body  = json_decode( (string) file_get_contents( 'php://input' ), true );
			$nonce = is_array( $body ) && isset( $body['nonce'] ) ? (string) $body['nonce'] : '';
			$result = Mcp_Hub::verify_attach_nonce( $nonce );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: no-store' );
			if ( null === $result ) {
				status_header( 403 );
				echo wp_json_encode( array( 'error' => 'invalid_or_expired_attach_request' ) );
			} else {
				status_header( 200 );
				echo wp_json_encode( $result );
			}
			exit;
		}

		// Browser-facing OAuth consent page (cookie auth applies here).
		if ( ! empty( $wp->query_vars[ self::AUTHORIZE_QUERY_VAR ] ) ) {
			$this->handle_authorize_page();
			return;
		}

		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		$request = new \WP_REST_Request( 'POST', '/xspeed/v1/mcp' );
		$request->set_header( 'content-type', 'application/json' );
		// Carry the auth headers + raw body from the live PHP request.
		foreach ( array( 'authorization', Mcp_Auth::TOKEN_HEADER ) as $h ) {
			$val = self::server_header( $h );
			if ( null !== $val ) {
				$request->set_header( $h, $val );
			}
		}
		// Token embedded in the URL path (/xspeed/mcp/<token>) — surface it
		// as the standard token header so Mcp_Server validates it the same
		// way. A header/Bearer token (if also sent) still takes precedence.
		$path_token = isset( $wp->query_vars[ self::TOKEN_QUERY_VAR ] )
			? (string) $wp->query_vars[ self::TOKEN_QUERY_VAR ]
			: '';
		if ( '' !== $path_token && '' === (string) $request->get_header( Mcp_Auth::TOKEN_HEADER ) && '' === (string) $request->get_header( 'authorization' ) ) {
			$request->set_header( Mcp_Auth::TOKEN_HEADER, $path_token );
		}
		$request->set_body( file_get_contents( 'php://input' ) );

		$response = Mcp_Server::handle( $request );
		$this->emit_json( $response );
	}

	// -- REST registration --

	public function register_rest(): void {
		// --- MCP JSON-RPC endpoint (fallback path via wp-json) -----------
		// permission_callback is __return_true because Mcp_Server does its
		// own token auth and must reply with a JSON-RPC 401, not a bare WP
		// permission failure.
		register_rest_route(
			self::NS,
			'/mcp',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_mcp' ),
				'permission_callback' => '__return_true',
			)
		);

		// --- Public scan signals -----------------------------------------
		// One tiny unauthenticated JSON body for external audit tools (the
		// speed scanner on xspeedcache.com): plugin version, whether the MCP
		// server is connected, and whether the site is attached to xSpeed
		// Hub. Everything except `hub` is already publicly discoverable —
		// the cache signature carries the version and /mcp answers 401 when
		// connected — and `hub` is a bare boolean. No tokens, accounts or
		// emails leave through this route.
		register_rest_route(
			self::NS,
			'/signals',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_signals' ),
				'permission_callback' => '__return_true',
			)
		);

		// --- Admin-only management routes (dashboard) --------------------
		register_rest_route(
			self::NS,
			'/mcp/connection',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_connection' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/mcp/activity',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_activity' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'limit' => array(
						'type'        => 'integer',
						'required'    => false,
						'default'     => 50,
						'description' => 'Maximum entries to return (newest first).',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/mcp/activity/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_activity_clear' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/mcp/connect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_connect' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'read_only' => array(
						'type'        => 'boolean',
						'required'    => false,
						'default'     => false,
						'description' => 'Grant read-only access (no purge/toggle/settings changes).',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/mcp/rotate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_rotate' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'read_only' => array(
						'type'        => 'boolean',
						'required'    => false,
						'description' => 'Optionally set read-only on the new token; omit to keep current scopes.',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/mcp/access',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_access' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'read_only' => array(
						'type'        => 'boolean',
						'required'    => true,
						'description' => 'Switch the live connection to read-only (true) or read & write (false), keeping the same token.',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/mcp/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_disconnect' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		// --- xSpeed Hub (multi-site) attach routes ------------------------
		register_rest_route(
			self::NS,
			'/mcp/hub',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_hub_status' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/mcp/hub/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_hub_token' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/mcp/hub/attached',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_hub_attached' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'account_email' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'The hub account email this site was attached to.',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/mcp/hub/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_hub_disconnect' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		// OAuth-attach callback: the hub calls this with the signed nonce the
		// plugin issued. Auth is the nonce itself (no pre-shared token), so
		// permission_callback is open — the handler validates the nonce.
		register_rest_route(
			self::NS,
			'/mcp/attach',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_hub_attach_callback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'nonce' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'The signed attach nonce the plugin issued.',
					),
				),
			)
		);

		// --- OAuth 2.1 authorization server (the "paste a URL only" path) -
		// Discovery, dynamic client registration, and the token endpoint are
		// all public (permission enforced inside): a client must reach them
		// BEFORE it holds any credential. The authorize endpoint gates on a
		// logged-in admin inside its handler (anonymous → wp-login redirect).
		register_rest_route(
			self::NS,
			'/mcp/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_oauth_register' ),
				'permission_callback' => '__return_true',
			)
		);
		// NOTE: /authorize is deliberately NOT a REST route — it is served as a
		// normal front-end page at /xspeed/authorize (see handle_authorize_page)
		// so cookie auth works after the wp-login round-trip.
		register_rest_route(
			self::NS,
			'/mcp/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_oauth_token' ),
				'permission_callback' => '__return_true',
			)
		);

		// --- OAuth discovery, REST fallback ------------------------------
		// The canonical documents live at /.well-known/… via rewrite rules.
		// Many hosts own that prefix for ACME/Let's Encrypt (an nginx
		// `location ^~ /.well-known` block, or an edge redirect rule), which
		// swallows the request before WordPress ever runs — the pretty URL
		// then 404s or redirects to the homepage no matter how the plugin is
		// configured, and OAuth discovery dead-ends with no way back.
		// Serving the same two documents under /wp-json puts them on a path
		// no ACME tooling claims, so discovery still completes there.
		register_rest_route(
			self::NS,
			'/mcp/.well-known/oauth-protected-resource',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_protected_resource_metadata' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/mcp/.well-known/oauth-authorization-server',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_authorization_server_metadata' ),
				'permission_callback' => '__return_true',
			)
		);

		// --- MCP-token-only tool routes (optional hosted-broker path) ----
		$tool_perm = array( Mcp_Auth::class, 'permission' );
		register_rest_route(
			self::NS,
			// [a-z0-9_-]+ — the HYPHEN is the one that matters, not the digit.
			// Generated tool names carry their module slug verbatim, and 33 of
			// the 92 in the catalog have a hyphenated slug
			// (xspeed_cache-404_status, xspeed_migration-pro_apply,
			// xspeed_smart-predict_status …). Every one of those returned
			// rest_no_route through the broker path. The earlier widening to
			// [a-z0-9_]+ un-blocked nothing: the only digit-bearing name is
			// cache-404, whose problem was the hyphen. (QA on #158) */
			'/mcp/tool/(?P<tool>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_tool' ),
					'permission_callback' => $tool_perm,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_tool' ),
					'permission_callback' => $tool_perm,
				),
			)
		);
	}

	/**
	 * Capability gate for the admin-only management routes.
	 *
	 * @return bool
	 */
	public function admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// -- Handlers ----------------------------------------------------------

	/**
	 * MCP JSON-RPC over the wp-json fallback path.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function rest_mcp( \WP_REST_Request $request ) {
		$response = Mcp_Server::handle( $request );
		// Advertise the MCP protocol version on the wp-json transport too, so
		// both endpoints behave identically to a strict Streamable-HTTP client.
		$response->header( 'MCP-Protocol-Version', Mcp_Server::PROTOCOL_VERSION );
		return $response;
	}

	/**
	 * GET /signals — the public scan-signals body. See the route
	 * registration for what may (and may not) leave through it.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_signals() {
		$signals = array(
			'xspeed' => XSPEED_VERSION,
			'mcp'    => '' !== Mcp_Pairing::site_token(),
			'hub'    => Mcp_Hub::site_attached(),
		);

		/**
		 * Filter the public scan signals.
		 *
		 * Lets an add-on append its own public facts (e.g. its version
		 * under `pro`). Values returned here are served UNAUTHENTICATED —
		 * never add tokens, accounts, emails, or paths.
		 *
		 * @param array<string,mixed> $signals The signals body.
		 */
		$signals = (array) apply_filters( 'xspeed_scan_signals', $signals );

		return rest_ensure_response( $signals );
	}

	/**
	 * GET /mcp/connection — pairing status for the dashboard.
	 *
	 * @param \WP_REST_Request $request Unused.
	 * @return \WP_REST_Response
	 */
	public function rest_connection( \WP_REST_Request $request ) {
		unset( $request );
		return rest_ensure_response( Mcp_Pairing::public_status() );
	}

	/**
	 * GET /mcp/activity — the audit trail of AI tool calls.
	 *
	 * @param \WP_REST_Request $request Carries the optional limit.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_activity( \WP_REST_Request $request ) {
		$limit = (int) $request->get_param( 'limit' );

		return rest_ensure_response(
			array(
				'entries' => Mcp_Activity_Log::entries( $limit > 0 ? $limit : 50 ),
				'summary' => Mcp_Activity_Log::summary(),
			)
		);
	}

	/**
	 * POST /mcp/activity/clear — wipe the audit trail.
	 *
	 * @param \WP_REST_Request $request Unused.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_activity_clear( \WP_REST_Request $request ) {
		unset( $request );
		$cleared = Mcp_Activity_Log::clear();

		return rest_ensure_response(
			array(
				'cleared' => $cleared,
				'entries' => Mcp_Activity_Log::entries(),
				'summary' => Mcp_Activity_Log::summary(),
			)
		);
	}

	/**
	 * POST /mcp/connect — mint a connection token.
	 *
	 * @param \WP_REST_Request $request Unused.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_connect( \WP_REST_Request $request ) {
		$read_only = (bool) $request->get_param( 'read_only' );
		$result    = Mcp_Pairing::connect( $read_only );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * POST /mcp/rotate — mint a fresh token, invalidating the old one.
	 *
	 * @param \WP_REST_Request $request Carries optional read_only.
	 * @return \WP_REST_Response
	 */
	public function rest_rotate( \WP_REST_Request $request ) {
		$read_only = null;
		if ( null !== $request->get_param( 'read_only' ) ) {
			$read_only = (bool) $request->get_param( 'read_only' );
		}
		return rest_ensure_response( Mcp_Pairing::rotate( $read_only ) );
	}

	/**
	 * POST /mcp/access — change the live connection's read-only state WITHOUT
	 * minting a new token (the paired client keeps working; only its allowed
	 * tools change). This is what the dashboard's read-only toggle calls.
	 *
	 * @param \WP_REST_Request $request Carries the required read_only bool.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_access( \WP_REST_Request $request ) {
		$read_only = (bool) $request->get_param( 'read_only' );
		$result    = Mcp_Pairing::set_read_only( $read_only );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * POST /mcp/disconnect — revoke the connection token.
	 *
	 * @param \WP_REST_Request $request Unused.
	 * @return \WP_REST_Response
	 */
	public function rest_disconnect( \WP_REST_Request $request ) {
		unset( $request );
		return rest_ensure_response( Mcp_Pairing::disconnect() );
	}

	// -- xSpeed Hub (multi-site) handlers ----------------------------------

	/**
	 * GET /mcp/hub — hub-link status + the Method-1 paste-in values.
	 *
	 * @param \WP_REST_Request $request Unused.
	 * @return \WP_REST_Response
	 */
	public function rest_hub_status( \WP_REST_Request $request ) {
		// Self-heal from the Hub (source of truth) so the connected badge is
		// reliable even if the attach callback never fired. Force a fresh check
		// when the panel asks via the X-XSpeed-Reconcile header (e.g. the admin
		// returned to the tab after connecting).
		$force = '1' === (string) $request->get_header( 'x_xspeed_reconcile' );
		Mcp_Hub::reconcile_with_hub( $force );
		return rest_ensure_response( Mcp_Hub::public_status() );
	}

	/**
	 * POST /mcp/hub/token — ensure a site_token exists and return the
	 * paste-in values (this site's URL + token) for the hub's Add-site form.
	 *
	 * @param \WP_REST_Request $request Unused.
	 * @return \WP_REST_Response
	 */
	public function rest_hub_token( \WP_REST_Request $request ) {
		unset( $request );
		return rest_ensure_response( Mcp_Hub::generate_token() );
	}

	/**
	 * POST /mcp/hub/attached — record which hub account this site is
	 * attached to (bookkeeping for the panel's status line).
	 *
	 * @param \WP_REST_Request $request Carries account_email.
	 * @return \WP_REST_Response
	 */
	public function rest_hub_attached( \WP_REST_Request $request ) {
		$email = sanitize_email( (string) $request->get_param( 'account_email' ) );
		return rest_ensure_response( Mcp_Hub::mark_attached( $email ) );
	}

	/**
	 * POST /mcp/hub/disconnect — clear the local hub-link bookkeeping.
	 *
	 * @param \WP_REST_Request $request Unused.
	 * @return \WP_REST_Response
	 */
	public function rest_hub_disconnect( \WP_REST_Request $request ) {
		unset( $request );
		return rest_ensure_response( Mcp_Hub::disconnect() );
	}

	/**
	 * POST /mcp/attach — the OAuth-attach callback. The hub presents the
	 * signed nonce the plugin issued; on success we return this site's URL +
	 * token so the hub can record it. Nonce is the auth (admin-minted,
	 * HMAC-signed, time-bound), so no pre-shared token is required.
	 *
	 * @param \WP_REST_Request $request Carries the nonce.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_hub_attach_callback( \WP_REST_Request $request ) {
		$nonce  = (string) $request->get_param( 'nonce' );
		$result = Mcp_Hub::verify_attach_nonce( $nonce );
		if ( null === $result ) {
			return new \WP_Error(
				'xspeed_attach_invalid',
				__( 'Invalid or expired attach request.', 'xspeed' ),
				array( 'status' => 403 )
			);
		}
		// A valid nonce proves this is a real hub-initiated attach, so record it
		// now — the hub passes the account email so the panel can show
		// "Connected via <email>". The nonce carries the minting admin's user
		// id (no WP session exists in this server-to-server call), so the state
		// is recorded PER-USER — each admin sees their own connection.
		$account_email = sanitize_email( (string) $request->get_param( 'account_email' ) );
		$user_id       = isset( $result['user_id'] ) ? (int) $result['user_id'] : 0;
		Mcp_Hub::mark_attached( $account_email, $user_id ?: null );

		// The hub only needs the credential; don't leak the internal user id.
		unset( $result['user_id'] );
		return rest_ensure_response( $result );
	}

	// -- OAuth 2.1 handlers ------------------------------------------------

	/**
	 * GET /mcp/.well-known/oauth-protected-resource — RFC 9728 metadata.
	 *
	 * Byte-identical to what the /.well-known rewrite serves; both call the
	 * same builder so the two locations can never drift.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_protected_resource_metadata(): \WP_REST_Response {
		return $this->discovery_response( Mcp_OAuth::protected_resource_metadata() );
	}

	/**
	 * GET /mcp/.well-known/oauth-authorization-server — RFC 8414 metadata.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_authorization_server_metadata(): \WP_REST_Response {
		return $this->discovery_response( Mcp_OAuth::authorization_server_metadata() );
	}

	/**
	 * Wrap a discovery document in a public, cacheable REST response.
	 *
	 * @param array<string,mixed> $data The metadata document.
	 * @return \WP_REST_Response
	 */
	private function discovery_response( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, 200 );
		// Same short window as the /.well-known/ emit site, same reason: the
		// document carries the server's identity. (#266)
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * POST /mcp/oauth/register — RFC 7591 dynamic client registration.
	 *
	 * @param \WP_REST_Request $request JSON body with redirect_uris.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_oauth_register( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$result = Mcp_OAuth::register_client( $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 201 );
	}

	/**
	 * The browser-facing OAuth authorize page (served at /xspeed/authorize via
	 * a rewrite, NOT the REST API — see AUTHORIZE_QUERY_VAR). Reads request
	 * params from the superglobals because this is a normal front-end request
	 * where cookie auth populates is_user_logged_in().
	 *
	 * GET renders the consent screen (requires a logged-in admin; anonymous
	 * users go to wp-login and return here). POST is the nonce-checked consent
	 * submission: Approve issues a code and 302s to the client's redirect_uri;
	 * Deny 302s back with error=access_denied. Always emits its own response
	 * (HTML page or redirect) and exits.
	 */
	public function handle_authorize_page(): void {
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) );
		// Params come from GET on the consent link and POST on the form submit.
		// Nonce is verified below before any POST value is acted on.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$source = $is_post ? $_POST : $_GET;
		// phpcs:enable
		$params = array();
		foreach ( array( 'client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'scope', 'state', 'approve', 'deny', '_xspeed_oauth_nonce' ) as $k ) {
			$params[ $k ] = isset( $source[ $k ] ) ? sanitize_text_field( wp_unslash( $source[ $k ] ) ) : '';
		}

		// Validate the OAuth params before touching the session.
		$req = Mcp_OAuth::validate_authorize_request( $params );
		if ( is_wp_error( $req ) ) {
			$data         = $req->get_error_data();
			$redirectable = is_array( $data ) && ! empty( $data['redirectable'] );
			// Only redirect the error back when redirect_uri is verified valid;
			// otherwise show a page (never bounce to an unverified URL).
			if ( $redirectable && '' !== $params['redirect_uri'] ) {
				$this->redirect_error( $params['redirect_uri'], $req->get_error_code(), $req->get_error_message(), $params['state'] );
			}
			$this->emit_oauth_error_page( $req->get_error_message() );
		}

		// Require a logged-in admin. Anonymous → wp-login, back to this URL.
		if ( ! is_user_logged_in() ) {
			$this->redirect_to_login();
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->emit_oauth_error_page(
				__( 'You must be an administrator to authorize an AI agent to control this site.', 'xspeed' )
			);
		}

		// POST = consent form submitted.
		if ( $is_post ) {
			if ( ! wp_verify_nonce( $params['_xspeed_oauth_nonce'], 'xspeed_oauth_consent' ) ) {
				$this->emit_oauth_error_page( __( 'Security check failed. Please try connecting again.', 'xspeed' ) );
			}
			if ( '' === $params['approve'] ) {
				$this->redirect_error( $req['redirect_uri'], 'access_denied', 'The user denied the request.', $req['state'] );
			}
			$code = Mcp_OAuth::issue_code( $req, get_current_user_id() );
			$this->redirect_success( $req['redirect_uri'], $code, $req['state'] );
		}

		// GET = render the consent screen.
		$this->emit_consent_screen( $req );
	}

	/**
	 * POST /mcp/oauth/token — exchange a code (or refresh token) for tokens.
	 *
	 * @param \WP_REST_Request $request Form-encoded or JSON token request.
	 * @return \WP_REST_Response
	 */
	public function rest_oauth_token( \WP_REST_Request $request ) {
		// Token requests are application/x-www-form-urlencoded per OAuth, but
		// accept JSON too. get_body_params() covers the form case.
		$body = $request->get_body_params();
		if ( empty( $body ) ) {
			$json = $request->get_json_params();
			$body = is_array( $json ) ? $json : array();
		}
		$body = array_map( 'strval', $body );

		$result = Mcp_OAuth::exchange_token( $body );
		if ( is_wp_error( $result ) ) {
			$data     = $result->get_error_data();
			$response = new \WP_REST_Response(
				array(
					'error'             => isset( $data['error'] ) ? $data['error'] : 'invalid_request',
					'error_description' => isset( $data['error_description'] ) ? $data['error_description'] : $result->get_error_message(),
				),
				isset( $data['status'] ) ? (int) $data['status'] : 400
			);
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}
		$response = new \WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * Token-authenticated tool route for the hosted broker. Maps a broker
	 * tool call (e.g. GET /mcp/tool/get_cache_status) onto the shared
	 * Mcp_Tools catalog, so the broker path and the JSON-RPC path never
	 * drift. GET params + JSON body both feed the tool's arguments.
	 */
	public function rest_tool( \WP_REST_Request $request ) {
		$tool = (string) $request->get_param( 'tool' );
		$args = $request->get_json_params();
		if ( ! is_array( $args ) ) {
			$args = array();
		}
		// Merge query params (e.g. ?module=minify) so GET tools work too.
		foreach ( $request->get_query_params() as $k => $v ) {
			if ( 'tool' !== $k && ! array_key_exists( $k, $args ) ) {
				$args[ $k ] = $v;
			}
		}

		Mcp_Tools::set_channel( 'broker' );
		$result = Mcp_Tools::invoke( $tool, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	// -- Helpers --

	// -- OAuth browser-response helpers ------------------------------------

	/** The absolute URL of the current authorize request (for login return). */
	private function current_authorize_url(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- reconstructing the current URL for a login round-trip; escaped at use.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return home_url( $uri );
	}

	/** Send an anonymous visitor to wp-login, returning to this authorize URL. */
	private function redirect_to_login(): void {
		wp_safe_redirect( wp_login_url( $this->current_authorize_url() ) );
		exit;
	}

	/** 302 back to the client with the authorization code (+ state). */
	private function redirect_success( string $redirect_uri, string $code, string $state ): void {
		$args = array( 'code' => $code );
		if ( '' !== $state ) {
			$args['state'] = $state;
		}
		// Not wp_safe_redirect: redirect_uri is a client-registered off-site
		// callback, already validated against the client's registered set.
		wp_redirect( add_query_arg( $args, $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- validated OAuth redirect_uri.
		exit;
	}

	/** 302 back to the client with an OAuth error (+ state). */
	private function redirect_error( string $redirect_uri, string $error, string $description, string $state ): void {
		$args = array(
			'error'             => $error,
			'error_description' => $description,
		);
		if ( '' !== $state ) {
			$args['state'] = $state;
		}
		wp_redirect( add_query_arg( array_map( 'rawurlencode', $args ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- validated OAuth redirect_uri.
		exit;
	}

	/**
	 * Render the consent screen. Minimal self-contained HTML (no admin
	 * chrome — this is a client-facing OAuth page). Approve/Deny post back
	 * to the same authorize URL with a nonce.
	 *
	 * @param array<string,string> $req Validated authorize params.
	 */
	private function emit_consent_screen( array $req ): void {
		$read_only  = Mcp_OAuth::scope_is_read_only( $req['scope'] );
		$access     = $read_only
			? __( 'Read-only — inspect cache status and settings.', 'xspeed' )
			: __( 'Read & write — purge caches, toggle caching, and change settings.', 'xspeed' );
		$client     = '' !== $req['client_name'] ? $req['client_name'] : __( 'An AI agent', 'xspeed' );
		$action_url = Mcp_OAuth::authorize_url();
		$nonce      = wp_create_nonce( 'xspeed_oauth_consent' );
		$user       = wp_get_current_user();

		// Preserve every OAuth param so the POST re-validates identically.
		$hidden = '';
		foreach ( array( 'client_id', 'redirect_uri', 'code_challenge', 'scope', 'state' ) as $k ) {
			$val     = 'scope' === $k ? $req['scope'] : ( $req[ $k ] ?? '' );
			$hidden .= sprintf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $k ), esc_attr( (string) $val ) );
		}
		// code_challenge_method + response_type are re-asserted for validation.
		$hidden .= '<input type="hidden" name="code_challenge_method" value="S256" />';
		$hidden .= '<input type="hidden" name="response_type" value="code" />';

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );

		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html__( 'Authorize AI access', 'xspeed' ) . '</title>';
		echo '<style>'
			. 'body{font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}'
			. '.card{background:#1e293b;border:1px solid #334155;border-radius:16px;max-width:440px;padding:32px;box-shadow:0 10px 40px rgba(0,0,0,.4)}'
			. 'h1{font-size:20px;margin:0 0 4px}.sub{color:#94a3b8;font-size:13px;margin:0 0 24px}'
			. '.row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #334155;font-size:13px}'
			. '.row span:first-child{color:#94a3b8}.row span:last-child{font-weight:600;text-align:right;max-width:60%;word-break:break-word}'
			. '.actions{display:flex;gap:12px;margin-top:24px}'
			. 'button{flex:1;padding:12px;border-radius:10px;border:0;font-size:14px;font-weight:600;cursor:pointer}'
			. '.approve{background:#f5cd47;color:#1b2533}.deny{background:transparent;color:#94a3b8;border:1px solid #334155}'
			. '</style></head><body><div class="card">';
		echo '<h1>' . esc_html__( 'Connect to xSpeed', 'xspeed' ) . '</h1>';
		/* translators: %s: AI client name. */
		echo '<p class="sub">' . esc_html( sprintf( __( '%s wants to manage the cache on this site.', 'xspeed' ), $client ) ) . '</p>';
		echo '<div class="row"><span>' . esc_html__( 'Site', 'xspeed' ) . '</span><span>' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</span></div>';
		echo '<div class="row"><span>' . esc_html__( 'Signed in as', 'xspeed' ) . '</span><span>' . esc_html( $user->user_login ) . '</span></div>';
		echo '<div class="row"><span>' . esc_html__( 'Access', 'xspeed' ) . '</span><span>' . esc_html( $access ) . '</span></div>';
		echo '<form method="post" action="' . esc_url( $action_url ) . '">';
		echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr() above.
		echo '<input type="hidden" name="_xspeed_oauth_nonce" value="' . esc_attr( $nonce ) . '" />';
		echo '<div class="actions">';
		echo '<button class="deny" name="deny" value="1">' . esc_html__( 'Deny', 'xspeed' ) . '</button>';
		echo '<button class="approve" name="approve" value="1">' . esc_html__( 'Approve', 'xspeed' ) . '</button>';
		echo '</div></form></div></body></html>';
		exit;
	}

	/** Render a standalone OAuth error page (no redirect). */
	private function emit_oauth_error_page( string $message ): void {
		status_header( 400 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Authorization error', 'xspeed' ) . '</title>';
		echo '<style>body{font:15px/1.5 -apple-system,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}'
			. '.card{background:#1e293b;border:1px solid #334155;border-radius:16px;max-width:440px;padding:32px;text-align:center}</style></head><body>';
		echo '<div class="card"><h1>' . esc_html__( 'Could not authorize', 'xspeed' ) . '</h1><p>' . esc_html( $message ) . '</p></div></body></html>';
		exit;
	}

	/** Read an inbound HTTP header from $_SERVER (for the pretty path). */
	private static function server_header( string $name ): ?string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- token compared constant-time downstream; raw header needed verbatim.
		return isset( $_SERVER[ $key ] ) ? wp_unslash( $_SERVER[ $key ] ) : null;
	}

	/** Emit a WP_REST_Response as a JSON HTTP response and stop. */
	private function emit_json( \WP_REST_Response $response ): void {
		status_header( $response->get_status() );
		// MCP Streamable HTTP: advertise the protocol version we speak so a
		// strict client can pin it. We answer JSON (a spec-permitted response
		// type); we never open an SSE stream, so no session header is needed.
		header( 'MCP-Protocol-Version: ' . Mcp_Server::PROTOCOL_VERSION );
		// Forward any headers the handler set (notably WWW-Authenticate on a
		// 401, which drives the OAuth discovery flow). rest_do_request applies
		// these automatically; the pretty-endpoint path must do it by hand.
		foreach ( $response->get_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}
		$data = $response->get_data();
		if ( null !== $data ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( $data );
		}
		exit;
	}

	// -- WP-CLI mirror --

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed mcp status',
				'callback'  => array( $this, 'cli_status' ),
				'shortdesc' => 'Show MCP connection status and the paste-in endpoint URL.',
				'synopsis'  => array(),
			),
			array(
				'name'      => 'xspeed mcp activity',
				'callback'  => array( $this, 'cli_activity' ),
				'shortdesc' => 'List recent MCP tool calls (the AI audit trail).',
				'synopsis'  => array(
					array(
						'name'        => 'limit',
						'type'        => 'assoc',
						'optional'    => true,
						'description' => 'Maximum entries to show (default 20).',
					),
					array(
						'name'        => 'clear',
						'type'        => 'flag',
						'optional'    => true,
						'description' => 'Wipe the audit trail instead of listing it.',
					),
				),
			),
			array(
				'name'      => 'xspeed mcp connect',
				'callback'  => array( $this, 'cli_connect' ),
				'shortdesc' => 'Generate a connection token for this site\'s MCP endpoint.',
				'synopsis'  => array(
					array(
						'name'        => 'read-only',
						'type'        => 'flag',
						'optional'    => true,
						'description' => 'Grant read-only access (no purge/toggle/settings changes).',
					),
				),
			),
			array(
				'name'      => 'xspeed mcp rotate',
				'callback'  => array( $this, 'cli_rotate' ),
				'shortdesc' => 'Mint a fresh MCP token, immediately invalidating the previous one.',
				'synopsis'  => array(
					array(
						'name'        => 'read-only',
						'type'        => 'flag',
						'optional'    => true,
						'description' => 'Make the new token read-only.',
					),
				),
			),
			array(
				'name'      => 'xspeed mcp disconnect',
				'callback'  => array( $this, 'cli_disconnect' ),
				'shortdesc' => 'Revoke this site\'s MCP connection token.',
				'synopsis'  => array(),
			),
		);
	}

	/**
	 * `wp xspeed mcp status` — print connection status + endpoint URL.
	 *
	 * @param array $args  Positional args (unused).
	 * @param array $assoc Associative args (unused).
	 */
	public function cli_status( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$s = Mcp_Pairing::public_status();
		\WP_CLI::log( sprintf( '%-18s %s', 'connected', $s['connected'] ? 'yes' : 'no' ) );
		if ( $s['connected'] ) {
			\WP_CLI::log( sprintf( '%-18s %s', 'access', $s['read_only'] ? 'read-only' : 'read-write' ) );
			\WP_CLI::log( sprintf( '%-18s %s', 'connect_url', $s['connect_url'] ) );
			\WP_CLI::log( sprintf( '%-18s %s', 'scopes', implode( ',', $s['scopes'] ) ) );
		} else {
			\WP_CLI::log( sprintf( '%-18s %s', 'mcp_endpoint', Mcp_Pairing::site_endpoint() ) );
		}
	}

	/**
	 * `wp xspeed mcp activity` — read (or clear) the AI audit trail.
	 *
	 * @param array $args  Positional args (unused).
	 * @param array $assoc --limit=<n>, --clear.
	 */
	public function cli_activity( array $args, array $assoc ): void {
		unset( $args );

		if ( ! empty( $assoc['clear'] ) ) {
			if ( ! Mcp_Activity_Log::clear() ) {
				// Reached via MCP run_command — the assistant is asking to
				// erase the record of its own calls. Mcp_Activity_Log::clear()
				// declines and logs the attempt; say so plainly.
				\WP_CLI::error( 'The MCP activity log cannot be cleared from an MCP tool call. Clear it from the xSpeed dashboard or from WP-CLI on the server.' );
				return;
			}
			\WP_CLI::success( 'MCP activity log cleared.' );
			return;
		}

		$limit   = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 20;
		$summary = Mcp_Activity_Log::summary();
		$entries = Mcp_Activity_Log::entries( $limit > 0 ? $limit : 20 );

		\WP_CLI::log( sprintf( '%-18s %d', 'total_calls', $summary['total'] ) );
		\WP_CLI::log( sprintf( '%-18s %d', 'failed', $summary['failed'] ) );
		\WP_CLI::log( sprintf( '%-18s %s', 'top_tool', '' === $summary['top_tool'] ? '-' : $summary['top_tool'] ) );

		if ( empty( $entries ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'No MCP tool calls recorded yet.' );
			return;
		}

		\WP_CLI::log( '' );
		foreach ( $entries as $entry ) {
			\WP_CLI::log(
				sprintf(
					'%s  %-22s %-5s %-6s %s%s',
					gmdate( 'Y-m-d H:i:s', $entry['ts'] ),
					$entry['tool'],
					$entry['scope'],
					$entry['ok'] ? 'ok' : 'FAIL',
					$entry['args'],
					'' === $entry['error'] ? '' : ' — ' . $entry['error']
				)
			);
		}
	}

	/**
	 * `wp xspeed mcp connect` — mint a token and print the paste-in URL.
	 *
	 * @param array $args  Positional args (unused).
	 * @param array $assoc Associative args (unused).
	 */
	public function cli_connect( array $args, array $assoc ): void {
		unset( $args );
		$read_only = ! empty( $assoc['read-only'] );
		$result    = Mcp_Pairing::connect( $read_only );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}
		\WP_CLI::success( 'Connected' . ( Mcp_Pairing::is_read_only() ? ' (read-only).' : '.' ) . ' Paste this single URL into your AI client:' );
		\WP_CLI::log( '  ' . Mcp_Pairing::connect_url() );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Or, header-based (token stays out of the URL):' );
		\WP_CLI::log( '  ' . Mcp_Pairing::config_snippets()['cli'] );
	}

	/**
	 * `wp xspeed mcp rotate` — mint a new token, revoking the old one.
	 *
	 * @param array $args  Positional args (unused).
	 * @param array $assoc Associative args ({ read-only?:flag }).
	 */
	public function cli_rotate( array $args, array $assoc ): void {
		unset( $args );
		$read_only = array_key_exists( 'read-only', $assoc ) ? ! empty( $assoc['read-only'] ) : null;
		Mcp_Pairing::rotate( $read_only );
		\WP_CLI::success( 'Rotated. The previous token is now invalid. New paste-in URL:' );
		\WP_CLI::log( '  ' . Mcp_Pairing::connect_url() );
	}

	/**
	 * `wp xspeed mcp disconnect` — revoke the connection token.
	 *
	 * @param array $args  Positional args (unused).
	 * @param array $assoc Associative args (unused).
	 */
	public function cli_disconnect( array $args, array $assoc ): void {
		unset( $args, $assoc );
		Mcp_Pairing::disconnect();
		\WP_CLI::success( 'Disconnected and revoked the MCP token.' );
	}

	/**
	 * MCP is on when a connection token exists -- it has no `enabled`
	 * setting, so the sidebar counted the AI group as empty on a site with
	 * a live read-write AI connection. Reads the same
	 * `Mcp_Pairing::public_status()` the CLI and the panel do, so the count
	 * cannot disagree with the badge on the panel. (#363)
	 */
	public function is_active(): ?bool {
		$status = Mcp_Pairing::public_status();
		return ! empty( $status['connected'] );
	}

	/**
	 * MCP has no on/off setting -- it is on when a connection exists.
	 */
	public function active_reason(): ?string {
		return $this->is_active()
			? __( 'An AI assistant is connected to this site. This module counts as on whenever a connection token exists, rather than having its own on/off setting.', 'xspeed' )
			: __( 'No AI assistant is connected. This module counts as on once you connect one.', 'xspeed' );
	}
}
