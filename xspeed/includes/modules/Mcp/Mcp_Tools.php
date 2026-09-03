<?php
/**
 * MCP tool registry — the single source of truth for the tools xSpeed
 * exposes to AI assistants.
 *
 * Each tool declares an MCP-style descriptor (name, description, JSON
 * Schema inputSchema) and a handler that runs against the Free engine.
 * Consumed by BOTH:
 *   - Mcp_Server (the per-site JSON-RPC endpoint at /xspeed/mcp), and
 *   - McpModule's REST tool routes (the optional hosted-broker path),
 * so the two transports can never drift.
 *
 * Handlers take an associative array of already-decoded arguments and
 * return either a plain array (serialized to JSON in the MCP result) or
 * a WP_Error (surfaced as an MCP tool error).
 *
 * The plugin adds ZERO cache logic here — every handler is a thin proxy
 * to Cache / Settings / Settings_Manager / Server / Admin / Pro_Audit /
 * Cache_Benchmark.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Mcp;

use XSpeed\Cache;
use XSpeed\Server;
use XSpeed\Admin;
use XSpeed\Settings;
use XSpeed\Settings_Manager;
use XSpeed\Pro_Audit;
use XSpeed\Cache_Benchmark;
use XSpeed\Tier_Registry;
use XSpeed\Database_Cleaner;

defined( 'ABSPATH' ) || exit;

final class Mcp_Tools {

	/** Valid cache purge types. */
	public const PURGE_TYPES = array( 'all', 'page', 'assets', 'object', 'rest' );

	/**
	 * Per-call read-only override. Null means "defer to the pairing token's
	 * scope" (the JSON-RPC path that predates OAuth). true/false is set by
	 * Mcp_Server when an OAuth access token (with its own scope) authorized
	 * the request, so a read-only OAuth grant is enforced even though the
	 * pairing token may be read-write (or absent).
	 *
	 * @var bool|null
	 */
	private static $read_only_override = null;

	/**
	 * Set the active credential's read-only state for the current request.
	 * Passing null clears the override (back to the pairing-token default).
	 *
	 * @param bool|null $read_only Whether the active credential is read-only.
	 */
	public static function set_read_only_override( ?bool $read_only ): void {
		self::$read_only_override = $read_only;
	}

	/**
	 * Whether the active MCP credential is limited to read-only tools. Uses
	 * the per-call override when set, else the pairing token's scope.
	 */
	private static function is_read_only(): bool {
		if ( null !== self::$read_only_override ) {
			return self::$read_only_override;
		}
		return Mcp_Pairing::is_read_only();
	}

	/**
	 * Per-call `configure` grant. Writing credential/secret fields over MCP is
	 * gated on this and it is OFF by default — even a write-scoped connection
	 * cannot rewrite an API token or password unless it was granted the
	 * explicit `configure` scope. Null means "no per-call grant" (the pairing
	 * token / JSON-RPC path), where it falls back to a filter. (#116)
	 *
	 * @var bool|null
	 */
	private static $configure_override = null;

	/**
	 * Set whether the active credential may write secret fields (the OAuth
	 * `configure` scope). Passing null clears it back to the filter default.
	 *
	 * @param bool|null $can_configure Whether the credential carries `configure`.
	 */
	public static function set_configure_override( ?bool $can_configure ): void {
		self::$configure_override = $can_configure;
	}

	/**
	 * Whether the active MCP credential may write credential/secret fields.
	 * Uses the per-call override (OAuth `configure` scope) when set; otherwise
	 * the `xspeed_mcp_allow_credential_writes` filter, which defaults to false
	 * so credential writes are off by default on every connection — including
	 * the pairing token. A site owner who wants an agent to manage credentials
	 * opts in by returning true from that filter. (#116)
	 */
	public static function can_configure(): bool {
		if ( null !== self::$configure_override ) {
			return self::$configure_override;
		}
		/**
		 * Allow MCP connections to write credential (secret) fields. Off by
		 * default; see docs/MCP-SERVER.md. Applies to pairing-token connections
		 * and any OAuth grant lacking the `configure` scope.
		 *
		 * @param bool $allow Whether credential writes over MCP are permitted.
		 */
		return (bool) apply_filters( 'xspeed_mcp_allow_credential_writes', false );
	}

	/**
	 * Full tool catalog: name => descriptor. `handler` is a callable
	 * ( array $args ) : array|\WP_Error. `write` marks tools that mutate
	 * state (used for read-only scope enforcement).
	 *
	 * @return array<string, array{description:string, inputSchema:array, handler:callable, write:bool}>
	 */
	public static function catalog(): array {
		$catalog = array(
			'get_cache_status' => array(
				'description' => 'Get cache status for this WordPress site: whether caching is enabled, cache stats (cached pages, size, hit ratio, last purge), and the detected web server.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => false,
				'handler'     => array( self::class, 'get_cache_status' ),
			),
			'list_modules'     => array(
				'description' => 'List all xSpeed modules (free and Pro) with their settings schema and status.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => false,
				'handler'     => array( self::class, 'list_modules' ),
			),
			'get_site_info'    => array(
				'description' => 'Get facts about this site and install: whether xSpeed Pro is active and licensed, plugin/WordPress/PHP versions, and the detected web server. Use this rather than inferring the tier from the module list.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => false,
				'handler'     => array( self::class, 'get_site_info' ),
			),
			'optimize_site'    => array(
				'description' => 'Make this site faster, end to end: measure, apply the recommended settings ONE AT A TIME, check the page still renders after each, and undo any change that breaks it. Returns what was applied, the site\'s last recorded score, `next_steps` (riskier settings that could help but are NOT applied automatically), and `unfixable` (problems no caching plugin can reach). ALWAYS relay all three to the user: report the score and what is still wrong, then — if `next_steps` is non-empty — describe each one WITH its stated `risk` and ASK whether to run again with aggressiveness "aggressive". Never enable aggressive settings without the user agreeing first, and never present `unfixable` items as things you can solve; they need the site owner or the host. A site where nothing was left to do is a real, good answer — say so plainly rather than apologising or retrying. Use `dry_run` to preview the plan.',
				'inputSchema' => self::object_schema(
					array(
						'aggressiveness' => array(
							'type'        => 'string',
							'enum'        => array( 'safe', 'standard', 'aggressive' ),
							'description' => 'How far to go. Defaults to standard.',
						),
						'dry_run'        => array(
							'type'        => 'boolean',
							'description' => 'Return the plan without changing anything.',
						),
					),
					array()
				),
				'write'       => true,
				'handler'     => array( self::class, 'optimize_site' ),
			),
			'run_benchmark'    => array(
				'description' => 'Run a before/after cache benchmark on the home page and return the timings. Each side reports bytes (decoded payload) and bytes_transferred (compressed wire size).',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => false,
				'handler'     => array( self::class, 'run_benchmark' ),
			),
			'get_pro_audit'    => array(
				'description' => 'Personalized list of Pro features that would benefit THIS site, from its current settings and cache stats.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => false,
				'handler'     => array( self::class, 'get_pro_audit' ),
			),
			'purge_cache'      => array(
				'description' => 'Purge the site cache. "type" selects what to purge: all, page, assets, object, or rest. Defaults to all.',
				'inputSchema' => self::object_schema(
					array(
						'type' => array(
							'type'        => 'string',
							'enum'        => self::PURGE_TYPES,
							'description' => 'What to purge. Defaults to "all".',
						),
					),
					array()
				),
				'write'       => true,
				'handler'     => array( self::class, 'purge_cache' ),
			),
			'toggle_cache'     => array(
				'description' => 'Enable or disable page caching. Installs/removes the cache drop-in and WP_CACHE constant as needed.',
				'inputSchema' => self::object_schema(
					array(
						'enabled' => array(
							'type'        => 'boolean',
							'description' => 'true to enable caching, false to disable.',
						),
					),
					array( 'enabled' )
				),
				'write'       => true,
				'handler'     => array( self::class, 'toggle_cache' ),
			),
			'get_settings'     => array(
				'description' => 'Read the settings for a given xSpeed module (e.g. "minify", "gzip"). Returns schema-validated values.',
				'inputSchema' => self::object_schema(
					array(
						'module' => array(
							'type'        => 'string',
							'description' => 'The module slug, e.g. "minify".',
						),
					),
					array( 'module' )
				),
				'write'       => false,
				'handler'     => array( self::class, 'get_settings' ),
			),
			'update_settings'  => array(
				'description' => 'Update settings for a given xSpeed module. "values" is an object of setting keys to new values; unknown keys are stripped and invalid values rejected by the module schema.',
				'inputSchema' => self::object_schema(
					array(
						'module' => array(
							'type'        => 'string',
							'description' => 'The module slug, e.g. "minify".',
						),
						'values' => array(
							'type'        => 'object',
							'description' => 'Map of setting keys to new values.',
						),
					),
					array( 'module', 'values' )
				),
				'write'       => true,
				'handler'     => array( self::class, 'update_settings' ),
			),
			// --- Promoted high-value actions: dedicated typed tools so the AI
			// calls them directly (no run_command hop). Each is a thin wrapper
			// over Cli_Bridge, so Free tools can drive Pro actions (psi, ccss)
			// without a cross-repo class reference, and none can drift from the
			// CLI. ---
			'purge_cloudflare' => array(
				'description' => 'Purge the Cloudflare edge cache for this site (requires Cloudflare connected in the Cloudflare module).',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => true,
				'handler'     => array( self::class, 'purge_cloudflare' ),
			),
			'scan_database'    => array(
				'description' => 'Preview database bloat — post revisions, auto-drafts, trashed posts, spam comments, expired transients, orphaned meta — with a count per category. Deletes NOTHING. Also returns the confirm_token that clean_database requires, so this is always the first step before any deletion.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => false,
				'handler'     => array( self::class, 'scan_database' ),
			),
			'clean_database'   => array(
				'description' => 'PERMANENTLY DELETE database bloat — post revisions, trashed posts, spam comments and the other categories enabled in the Database module settings. This is not a cache purge: it destroys real content and CANNOT be undone. Requires a confirm_token from scan_database, which shows the caller exactly what would be removed; the call is refused without one.',
				'inputSchema' => self::object_schema(
					array(
						'confirm_token' => array(
							'type'        => 'string',
							'description' => 'The token returned by scan_database. Required — it proves the caller has seen what will be deleted. Expires after 5 minutes and is invalidated if the database changes.',
						),
					),
					array( 'confirm_token' )
				),
				'write'       => true,
				'handler'     => array( self::class, 'clean_database' ),
			),
			'flush_object_cache' => array(
				'description' => 'Flush the persistent object cache (Redis / Memcached), if enabled.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => true,
				'handler'     => array( self::class, 'flush_object_cache' ),
			),
			'start_preloader'  => array(
				'description' => 'Start the cache preloader — crawls the sitemap to warm the page cache in the background.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => true,
				'handler'     => array( self::class, 'start_preloader' ),
			),
			'run_score'        => array(
				'description' => 'Run an external performance audit (PageSpeed Insights, or GTmetrix when configured) against this site and return the score plus Core Web Vitals. Available on every install. Spends the site\'s own configured API quota. Use get_score_history to read past runs without starting a new one.',
				'inputSchema' => self::object_schema(
					array(
						'target'   => array(
							'type'        => 'string',
							'description' => 'URL to audit. Defaults to the configured URL, then the home page.',
						),
						'strategy' => array(
							'type'        => 'string',
							'enum'        => array( 'mobile', 'desktop' ),
							'description' => 'mobile (default) or desktop. PageSpeed Insights only.',
						),
						'provider' => array(
							'type'        => 'string',
							'description' => 'Audit provider, when the site has more than one configured.',
						),
						'force'    => array(
							'type'        => 'boolean',
							'description' => 'Re-run even when a recent cached result exists. Use after a change you want measured immediately.',
						),
					),
					array()
				),
				// Classified `write`, deliberately. #147 asked whether a
				// read-only grant should be able to call this, since a run
				// changes no site CONFIGURATION. But it spends the site's own
				// metered PSI/GTmetrix quota and persists a Score_Store row,
				// and "read-only" should mean a call cannot cost the owner
				// anything. The gap this closes is that Free had NO typed
				// trigger at all: run_pagespeed is conditional on the Pro-only
				// `xspeed psi` command and silently drops off tools/list here,
				// leaving only run_command — also write, and a gateway to the
				// entire CLI surface. A write-scoped Hub connection now gets a
				// first-class trigger instead of the blunt instrument.
				'write'       => true,
				'handler'     => array( self::class, 'run_score' ),
			),
			'run_pagespeed'    => array(
				'description' => 'Run an external performance audit (PageSpeed Insights, or GTmetrix when configured) and return the score + Core Web Vitals. Defaults to the site home page, mobile strategy. Requires external scores to be enabled in settings — the plugin makes no outbound calls otherwise.',
				'inputSchema' => self::object_schema(
					array(
						'url'      => array(
							'type'        => 'string',
							'description' => 'URL to audit. Defaults to the site home page.',
						),
						'strategy' => array(
							'type'        => 'string',
							'enum'        => array( 'mobile', 'desktop' ),
							'description' => 'Audit strategy. Defaults to "mobile".',
						),
						'force'    => array(
							'type'        => 'boolean',
							'description' => 'Re-run even when a recent cached result exists. Use after a change you want measured immediately.',
						),
						'provider' => array(
							'type'        => 'string',
							'description' => 'Audit provider, when the site has more than one configured.',
						),
					),
					array()
				),
				// `write`, matching run_score — the two dispatch to the same
				// `xspeed psi` and cost the owner the same metered quota, so
				// classifying them oppositely let a read-only grant make a real
				// outbound audit through this one while the other refused it.
				// Aligned toward write rather than read: generate_critical_css
				// sets the precedent that spending an external quota is a write
				// even when no site configuration changes. (QA B2 on #162)
				'write'       => true,
				'handler'     => array( self::class, 'run_pagespeed' ),
			),
			'generate_critical_css' => array(
				'description' => 'Generate above-the-fold Critical CSS for the site (Pro). Calls the external generator and stores the result.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => true,
				'handler'     => array( self::class, 'generate_critical_css' ),
			),
			'get_health'       => array(
				'description' => 'Full health diagnostics: every Health check (drop-in, WP_CACHE, server rewrite, expiry-vs-preload, Set-Cookie poisoning, conflicts), cache stats, hourly hit/miss buckets, the daily hit-ratio series, and recent activity. The single best first call when diagnosing a low hit ratio.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => false,
				'handler'     => array( self::class, 'get_health' ),
			),
			'get_benchmark_history' => array(
				'description' => 'Stored benchmark runs (oldest to newest: timestamps, uncached/cached ms, savings, transfer bytes) plus recent settings-change events for correlating a change with its performance effect.',
				'inputSchema' => self::object_schema(
					array(
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Max runs to return (default 100).',
						),
					),
					array()
				),
				'write'       => false,
				'handler'     => array( self::class, 'get_benchmark_history' ),
			),
			'get_score_history' => array(
				'description' => 'Stored EXTERNAL audit runs (PageSpeed Insights / GTmetrix): score, Core Web Vitals (LCP/FCP/CLS/TBT/SI/TTFB), which tool ran it, and the report link where one exists. Failed runs are included: ok is false and error says why, with score null. Never average or trend a run whose ok is false — it measured nothing. Read-only — returns what this site already measured and never starts a new audit. Use run_score to actually run one.',
				'inputSchema' => self::object_schema(
					array(
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Max runs to return, newest first (default 100).',
						),
					),
					array()
				),
				'write'       => false,
				'handler'     => array( self::class, 'get_score_history' ),
			),
			// --- Actions promoted out of the generated `xspeed_*` aliases.
			// Each was previously reachable ONLY as an `action` string on a
			// coarse generated tool that was marked write regardless, so a
			// read-only connection lost the read ones. Typed here with an
			// honest kind so the AI stops guessing and the deny-list has one
			// name per action. ---
			'get_cache_inventory' => array(
				'description' => 'Inspect what is actually in the page cache: which pages are cached and how old they are, or where the disk usage goes. Read-only.',
				'inputSchema' => self::object_schema(
					array(
						'detail' => array(
							'type'        => 'string',
							'enum'        => array( 'pages', 'size' ),
							'description' => '"pages" lists cached pages and their age; "size" breaks down disk usage. Defaults to "pages".',
						),
						'limit'  => array(
							'type'        => 'string',
							'description' => 'Max rows to return (pages only).',
						),
					),
					array()
				),
				'write'       => false,
				'handler'     => array( self::class, 'get_cache_inventory' ),
			),
			'get_purge_log'    => array(
				'description' => 'Recent cache purges and what triggered each one. Use it to explain why a page stopped being cached. Read-only.',
				'inputSchema' => self::object_schema(
					array(
						'limit' => array(
							'type'        => 'string',
							'description' => 'Max entries to return.',
						),
					),
					array()
				),
				'write'       => false,
				'handler'     => array( self::class, 'get_purge_log' ),
			),
			'recheck_rewrite_rules' => array(
				'description' => 'Re-verify the server rewrite rules that route requests to the cache, and repair them if they drifted.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => true,
				'handler'     => array( self::class, 'recheck_rewrite_rules' ),
			),
			'set_cloudflare_dev_mode' => array(
				'description' => 'Turn Cloudflare development mode on or off. On bypasses the edge cache for ~3 hours so origin changes show immediately.',
				'inputSchema' => self::object_schema(
					array(
						'enabled' => array(
							'type'        => 'boolean',
							'description' => 'true turns development mode on, false turns it off.',
						),
					),
					array( 'enabled' )
				),
				'write'       => true,
				'handler'     => array( self::class, 'set_cloudflare_dev_mode' ),
			),
			'optimize_database' => array(
				'description' => 'Run table optimization on the WordPress database (reclaims space after cleanup). Separate from clean_database, which deletes bloat rows.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => true,
				'handler'     => array( self::class, 'optimize_database' ),
			),
			'get_object_cache_status' => array(
				'description' => 'Object cache state: whether the drop-in is installed, which backend is configured, and the server snippet needed to enable it. Read-only.',
				'inputSchema' => self::object_schema(
					array(
						'detail' => array(
							'type'        => 'string',
							'enum'        => array( 'status', 'snippet' ),
							'description' => '"status" reports the current state; "snippet" returns the server config to enable it. Defaults to "status".',
						),
					),
					array()
				),
				'write'       => false,
				'handler'     => array( self::class, 'get_object_cache_status' ),
			),
			'toggle_object_cache' => array(
				'description' => 'Enable or disable the object cache drop-in. Verify the backend with test_object_cache first — enabling against an unreachable server slows every request.',
				'inputSchema' => self::object_schema(
					array(
						'enabled' => array(
							'type'        => 'boolean',
							'description' => 'true installs the drop-in, false removes it.',
						),
					),
					array( 'enabled' )
				),
				'write'       => true,
				'handler'     => array( self::class, 'toggle_object_cache' ),
			),
			'manage_critical_css' => array(
				'description' => 'List the stored Critical CSS entries, or clear them so they regenerate. Use generate_critical_css to create them.',
				'inputSchema' => self::object_schema(
					array(
						'action' => array(
							'type'        => 'string',
							'enum'        => array( 'list', 'clear' ),
							'description' => '"list" returns what is stored; "clear" deletes it.',
						),
					),
					array( 'action' )
				),
				'write'       => true,
				'handler'     => array( self::class, 'manage_critical_css' ),
			),
			'get_preloader_status' => array(
				'description' => 'Cache preloader progress: whether a run is active, how far through the URL list it is. Read-only.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => false,
				'handler'     => array( self::class, 'get_preloader_status' ),
			),
			'stop_preloader'   => array(
				'description' => 'Stop a running cache preload. Safe mid-run — already-warmed pages stay cached.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => true,
				'handler'     => array( self::class, 'stop_preloader' ),
			),
			'purge_url'        => array(
				'description' => 'Purge the cache for ONE URL only (all its variants: device buckets, trailing-slash forms, static-tree copy). Surgical alternative to purge_cache when a single page changed.',
				'inputSchema' => self::object_schema(
					array(
						'url' => array(
							'type'        => 'string',
							'description' => 'Absolute URL or site-relative path, e.g. "https://site.com/about/" or "/about/".',
						),
					),
					array( 'url' )
				),
				'write'       => true,
				'handler'     => array( self::class, 'purge_url' ),
			),
			'test_object_cache' => array(
				'description' => 'Live connect + read/write probe of the configured Redis/Memcached backend using the saved Object Cache settings. Verifies the credentials actually work — writing settings alone does not.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => false,
				'handler'     => array( self::class, 'test_object_cache' ),
			),
			'cloudflare_verify' => array(
				'description' => 'Verify the saved Cloudflare credentials against the Cloudflare API (token/zone check). Read-only — use purge_cloudflare to purge the edge.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => false,
				'handler'     => array( self::class, 'cloudflare_verify' ),
			),
			'list_commands'    => array(
				'description' => 'List every xSpeed command that run_command can invoke (name, description, module, options). Use this to discover the full action surface beyond the curated + dedicated tools.',
				'inputSchema' => self::object_schema( array(), array() ),
				'write'       => false,
				'handler'     => array( self::class, 'list_commands' ),
			),
			'run_command'      => array(
				'description' => 'Run any xSpeed command — the full CLI surface (~50 commands across every module: cache, cloudflare, database, critical/unused CSS, pagespeed, images, migration, preloader, object cache, analytics, RUM, smart-* and more). Call list_commands first to discover names + options. Examples: run_command("cloudflare purge"), run_command("psi", {}, {"url":"https://site.com","strategy":"mobile"}). Permanently destructive commands ("database clean") additionally require a confirm_token from scan_database and are refused without one — this gateway is not a way around that confirmation.',
				'inputSchema' => self::object_schema(
					array(
						'command' => array(
							'type'        => 'string',
							'description' => 'Command name, e.g. "cloudflare purge" or "database scan" (the "xspeed " prefix is optional).',
						),
						'args'    => array(
							'type'        => 'array',
							'description' => 'Positional arguments, if the command takes any.',
							'items'       => array( 'type' => 'string' ),
						),
						'options' => array(
							'type'        => 'object',
							'description' => 'Named options / flags, e.g. { "url": "https://site.com", "strategy": "mobile", "force": true }.',
						),
						'confirm_token' => array(
							'type'        => 'string',
							'description' => 'Required ONLY for permanently destructive commands such as "database clean". Obtain it from scan_database, which previews exactly what would be deleted. Without it those commands are refused.',
						),
					),
					array( 'command' )
				),
				'write'       => true,
				'handler'     => array( self::class, 'run_command' ),
			),
		);

		// Dedicated tools that wrap a command only present when a given
		// module is active (e.g. Pro): drop them if the command isn't
		// registered, so we never advertise a tool that always fails. The
		// action stays reachable via run_command if the command exists.
		$conditional = array(
			'generate_critical_css' => 'xspeed ccss',
			'purge_cloudflare'      => 'xspeed cf',
			'cloudflare_verify'     => 'xspeed cf',
			'flush_object_cache'    => 'xspeed objcache',
			'test_object_cache'     => 'xspeed objcache',
			'start_preloader'       => 'xspeed preloader',
			'scan_database'         => 'xspeed db',
			'clean_database'        => 'xspeed db',
			'purge_url'             => 'xspeed cache',
			'get_cache_inventory'   => 'xspeed cache',
			'get_purge_log'         => 'xspeed cache',
			'recheck_rewrite_rules' => 'xspeed cache',
			'set_cloudflare_dev_mode' => 'xspeed cf',
			'optimize_database'     => 'xspeed db',
			'get_object_cache_status' => 'xspeed objcache',
			'toggle_object_cache'   => 'xspeed objcache',
			'manage_critical_css'   => 'xspeed ccss',
			'get_preloader_status'  => 'xspeed preloader',
			'stop_preloader'        => 'xspeed preloader',
		);

		/*
		 * Tools that are ALWAYS in the catalog, mapped to the command they
		 * cover. These are listed separately from $conditional because the
		 * two roles are different and used to be conflated in one map: this
		 * set only tells the alias generator "don't emit an alias for this
		 * command, a typed tool already covers it" — it must never drop a
		 * tool.
		 *
		 * That conflation is exactly what broke get_settings/update_settings:
		 * they were mapped to `xspeed settings`, a command that did not exist,
		 * so the drop loop unset them on every request and they never reached
		 * tools/list. `xspeed settings` now exists (SettingsModule), but the
		 * split is what stops the class of bug recurring — an unconditional
		 * tool can no longer be removed by a command going away. (#149/#153)
		 */
		$always = array(
			'get_settings'      => 'xspeed settings',
			'update_settings'   => 'xspeed settings',
			'run_pagespeed'     => 'xspeed psi',
			'get_health'        => 'xspeed health',
			'get_score_history' => 'xspeed score',
		);

		$commands = Cli_Bridge::commands();
		foreach ( $conditional as $tool => $command ) {
			if ( ! isset( $commands[ $command ] ) ) {
				unset( $catalog[ $tool ] );
			}
		}
		// Alias generation reads both maps; the drop loop above reads only
		// $conditional.
		$conditional = array_merge( $conditional, $always );

		/*
		 * One dedicated tool per xSpeed CLI command, generated from the same
		 * Cli_Bridge catalog the CLI registers from — so the AI can reach the
		 * long tail without the list_commands -> run_command hop, and the
		 * generated set can never drift from the CLI.
		 *
		 * Commands already covered by a typed tool above are SKIPPED. The
		 * `isset()` guard below only catches NAME collisions, and a generated
		 * name never collides — `xspeed cf` becomes `xspeed_cf`, which is not
		 * `purge_cloudflare`. So both used to ship: two tools for one action,
		 * with the generated one marked write even when it wrapped a read,
		 * and a per-tool permission on one name silently bypassable via the
		 * other. $conditional already maps every typed tool to its command;
		 * inverted, that IS the skip list.
		 */
		foreach ( self::cli_generated_tools( array_flip( $conditional ) ) as $name => $spec ) {
			if ( ! isset( $catalog[ $name ] ) ) {
				$catalog[ $name ] = $spec;
			}
		}

		return $catalog;
	}

	/**
	 * Generate one MCP tool per registered xSpeed CLI command. Each wraps
	 * Cli_Bridge::run(): the tool's `action` (the command's first positional,
	 * e.g. `verify`/`purge` for `xspeed cf`) plus any named options are passed
	 * straight through. Tool names are the command with the `xspeed ` prefix
	 * dropped and spaces -> underscores (`xspeed cf` -> `xspeed_cf`).
	 *
	 * @return array<string, array{description:string, inputSchema:array, write:bool, handler:callable}>
	 */
	private static function cli_generated_tools( array $covered = array() ): array {
		$tools = array();
		foreach ( Cli_Bridge::commands() as $command => $spec ) {
			// Already exposed as typed tools with real schemas and honest
			// read/write kinds — generating a coarse alias too would give the
			// AI two ways to do one thing and make a per-tool permission on
			// the typed name bypassable via the generated one.
			if ( isset( $covered[ $command ] ) ) {
				continue;
			}
			$tool_name = self::cli_tool_name( $command );
			if ( '' === $tool_name ) {
				continue;
			}

			// Build the input schema from the command's synopsis: positional
			// args become string properties (the first is usually the action,
			// exposed with its allowed values as an enum); assoc args become
			// named options.
			$properties = array();
			$required   = array();
			foreach ( $spec['synopsis'] as $arg ) {
				if ( ! isset( $arg['name'] ) ) {
					continue;
				}
				$arg_name = (string) $arg['name'];
				$prop     = array(
					'type'        => 'string',
					'description' => isset( $arg['description'] ) ? (string) $arg['description'] : '',
				);
				if ( isset( $arg['options'] ) && is_array( $arg['options'] ) && ! empty( $arg['options'] ) ) {
					$prop['enum'] = array_values( array_map( 'strval', $arg['options'] ) );
				}
				$properties[ $arg_name ] = $prop;
				$is_optional             = ! empty( $arg['optional'] );
				$is_flag                 = isset( $arg['type'] ) && 'flag' === $arg['type'];
				if ( ! $is_optional && ! $is_flag ) {
					$required[] = $arg_name;
				}
			}

			// Prefer the AI-facing hint. `shortdesc` is CLI help — written for
			// someone who already chose the command — so it says what the
			// output looks like, never when to reach for it. That is exactly
			// the question a model is answering when it reads tools/list, and
			// it is why 36 of these descriptions open with "Show". A module
			// that has not been given a hint yet keeps its shortdesc, so this
			// improves incrementally instead of needing all 40 at once. (#184)
			$description = '' !== ( $spec['ai_hint'] ?? '' )
				? $spec['ai_hint']
				: ( '' !== $spec['shortdesc']
					? $spec['shortdesc']
					: sprintf( 'Run the "%s" xSpeed command.', $command ) );

			list( $write, $write_actions, $read_actions ) = self::cli_write_profile( $command, $spec['synopsis'] );

			$tools[ $tool_name ] = array(
				'description'   => $description,
				'inputSchema'   => self::object_schema( $properties, $required ),
				'write'         => $write,
				// The action values that mutate state. When set, read-only
				// enforcement is per-ACTION (a read-only grant may still call
				// the tool with a read action like "status"/"scan").
				'write_actions' => $write_actions,
				// The complement — actions positively classified as reads.
				// action_writes() allowlists against THIS rather than negating
				// write_actions, so an action added to a command later is
				// refused under a read-only grant until it has been
				// classified, instead of silently becoming callable.
				'read_actions'  => $read_actions,
				'handler'       => self::cli_handler_for( $command, $spec['synopsis'] ),
			);
		}
		return $tools;
	}

	/** Derive an MCP tool name from a CLI command ("xspeed cf" -> "xspeed_cf"). */
	private static function cli_tool_name( string $command ): string {
		$command = trim( preg_replace( '/\s+/', ' ', $command ) ?? '' );
		if ( '' === $command ) {
			return '';
		}
		return str_replace( ' ', '_', $command );
	}

	/** Action verbs that only inspect state (never mutate). */
	private const CLI_READ_VERBS = array( 'status', 'scan', 'list', 'verify', 'get', 'show', 'info', 'export', 'preview', 'check', 'snippet', 'test' );

	/** Commands with NO action enum that are nonetheless pure inspection. */
	private const CLI_READ_ONLY_COMMANDS = array( 'xspeed health', 'xspeed support' );

	/**
	 * Compute the write profile for a generated command tool:
	 *   [ $write_bool, $write_actions ]
	 * where $write_actions is the list of action values that mutate state
	 * (empty when the tool has no action enum). $write_bool is the tool-level
	 * flag: true if ANY action writes (so read-only clients see it flagged),
	 * but per-action enforcement in invoke() still lets a read-only grant run
	 * the tool's read actions (e.g. `minify status` while `minify purge` is
	 * refused).
	 *
	 * @param string $command  Full command name.
	 * @param array  $synopsis Command synopsis.
	 * @return array{0:bool,1:string[],2:string[]} write flag, write actions, read actions
	 */
	/**
	 * Does THIS call mutate state, given the action the caller submitted?
	 *
	 * The tool-level `write` flag is true when ANY of a command's actions
	 * write, so read-only clients can see the tool is capable of mutating.
	 * Enforcing on that flag alone refuses the whole tool — which is how a
	 * read-only grant lost the ability to run `xspeed_minify status` even
	 * though only `purge` writes. `write_actions` records exactly which
	 * action values mutate; this is what reads it.
	 *
	 * Fails CLOSED in every ambiguous case. An action that isn't in the
	 * schema, an absent action, or a tool with no per-action profile all fall
	 * back to the coarse flag and are refused. A read-only grant may end up
	 * with less access than strictly necessary; it must never end up with
	 * more.
	 *
	 * @param array $tool The catalog entry.
	 * @param array $args The submitted arguments.
	 */
	private static function action_writes( array $tool, array $args ): bool {
		$write_actions = isset( $tool['write_actions'] ) && is_array( $tool['write_actions'] )
			? $tool['write_actions']
			: array();

		// No per-action profile — the coarse flag is all we have.
		if ( empty( $write_actions ) ) {
			return true;
		}

		$action = isset( $args['action'] ) && is_scalar( $args['action'] )
			? strtolower( trim( (string) $args['action'] ) )
			: '';

		// No action supplied: the command's own default is unknown here, so
		// treat it as a write rather than guessing.
		if ( '' === $action ) {
			return true;
		}

		// Only an action we positively recognise as read is allowed through.
		// Anything unknown is refused, so a future action added to a command
		// can't silently become callable under a read-only grant before it has
		// been classified.
		$known = array_map(
			static function ( $a ) {
				return strtolower( trim( (string) $a ) );
			},
			isset( $tool['read_actions'] ) && is_array( $tool['read_actions'] ) ? $tool['read_actions'] : array()
		);

		return ! in_array( $action, $known, true );
	}

	private static function cli_write_profile( string $command, array $synopsis ): array {
		// Command with an action enum → classify each action.
		foreach ( $synopsis as $arg ) {
			if ( isset( $arg['type'], $arg['options'] ) && 'positional' === $arg['type'] && is_array( $arg['options'] ) ) {
				$write_actions = array();
				$read_actions  = array();
				foreach ( $arg['options'] as $opt ) {
					if ( in_array( strtolower( (string) $opt ), self::CLI_READ_VERBS, true ) ) {
						$read_actions[] = (string) $opt;
					} else {
						$write_actions[] = (string) $opt;
					}
				}
				return array( ! empty( $write_actions ), $write_actions, $read_actions );
			}
		}

		// No action enum: a small allow-list of pure-inspection commands is
		// read-only; everything else defaults to write (safe — a read-only
		// grant never mutates).
		$is_read = in_array( trim( $command ), self::CLI_READ_ONLY_COMMANDS, true );
		return array( ! $is_read, array(), array() );
	}

	/**
	 * Build the handler for a generated command tool. It maps the tool's
	 * arguments back to Cli_Bridge::run(): positional synopsis args (in order)
	 * become $args; everything else is passed as named options.
	 *
	 * @param string $command  Full command name.
	 * @param array  $synopsis Command synopsis.
	 * @return callable
	 */
	private static function cli_handler_for( string $command, array $synopsis ): callable {
		// Names of the positional args, in declared order.
		$positionals = array();
		foreach ( $synopsis as $arg ) {
			if ( isset( $arg['name'] ) && ( ! isset( $arg['type'] ) || 'positional' === $arg['type'] ) ) {
				$positionals[] = (string) $arg['name'];
			}
		}

		return static function ( array $tool_args ) use ( $command, $positionals ) {
			$args  = array();
			$assoc = $tool_args;
			// Pull positionals out (in order) into $args; the rest are options.
			foreach ( $positionals as $pname ) {
				if ( array_key_exists( $pname, $assoc ) && '' !== (string) $assoc[ $pname ] ) {
					$args[] = (string) $assoc[ $pname ];
				}
				unset( $assoc[ $pname ] );
			}
			return Cli_Bridge::run( $command, $args, $assoc );
		};
	}

	/**
	 * The tool list in MCP `tools/list` shape.
	 *
	 * @return array<int, array{name:string, description:string, inputSchema:array}>
	 */
	public static function list(): array {
		$out = array();
		foreach ( self::catalog() as $name => $spec ) {
			$out[] = array(
				'name'        => $name,
				'description' => $spec['description'],
				'inputSchema' => $spec['inputSchema'],
			);
		}
		return $out;
	}

	/**
	 * Invoke a tool by name with decoded arguments.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Decoded arguments.
	 * @return array|\WP_Error Result payload or error.
	 */
	public static function invoke( string $name, array $args ) {
		$catalog = self::catalog();
		if ( ! isset( $catalog[ $name ] ) ) {
			$error = new \WP_Error(
				'xspeed_mcp_unknown_tool',
				sprintf(
					/* translators: %s: tool name. */
					__( 'Unknown tool: %s', 'xspeed' ),
					$name
				),
				array( 'status' => 404 )
			);

			// A call for a tool that doesn't exist is still something that
			// happened to this site, and a run of them is the shape of a
			// probe. Recording it is the difference between a trail that
			// shows what was ATTEMPTED and one that only shows what
			// succeeded. Scope is unknowable here, so log the conservative
			// one rather than implying the attempt was read-only.
			Mcp_Activity_Log::record( $name, $args, false, $error->get_error_message(), 'write', self::$channel );

			return $error;
		}

		// Scope enforcement: a read-only connection cannot invoke a tool that
		// mutates state. run_command is a gateway to the full CLI surface, so
		// it's treated as write regardless of the wrapped command. The active
		// credential's scope (pairing token OR OAuth access token) is carried
		// in self::$scope_override; it falls back to the pairing global for
		// callers that don't set a per-call scope.
		if ( ! empty( $catalog[ $name ]['write'] ) && self::is_read_only() && self::action_writes( $catalog[ $name ], $args ) ) {
			return new \WP_Error(
				'xspeed_mcp_read_only',
				sprintf(
					/* translators: %s: tool name. */
					__( 'This MCP connection is read-only; the "%s" tool changes state and is not permitted. Reconnect with write access to use it.', 'xspeed' ),
					$name
				),
				array( 'status' => 403 )
			);
		}

		/*
		 * Scan-before-clean, enforced at the dispatcher rather than in one
		 * handler.
		 *
		 * The guard used to live inside clean_database(). That protected a
		 * TOOL NAME, not the action: run_command("db", ["clean"]) reaches the
		 * same Cli_Bridge::run('db', ['clean']) with no token, no preview and
		 * no warning, and list_commands advertises the route to the assistant
		 * in plainer words ("Scan or clean WordPress bloat") than the tool
		 * that just refused it. Measured on a live site, that second door
		 * permanently destroyed 3,007 rows in a single call and reported
		 * success.
		 *
		 * Every tool passes through invoke(), so a confirmation checked here
		 * covers each door at once — including any future tool that wraps the
		 * same command. (#184)
		 */
		$destructive = self::destructive_action( $name, $args );
		if ( '' !== $destructive ) {
			$confirmed = self::verify_clean_token( $args );
			if ( is_wp_error( $confirmed ) ) {
				Mcp_Activity_Log::record( $name, $args, false, $confirmed->get_error_message(), 'write', self::$channel );
				return $confirmed;
			}
		}

		self::$dispatching = true;
		try {
			$result = call_user_func( $catalog[ $name ]['handler'], $args );

			// Audit every dispatched call — this is the record the admin
			// reads to answer "what did the assistant do to my site?".
			// Recorded here (not per-handler) so a new tool is covered the
			// moment it joins the catalog.
			[ $ok, $error ] = self::outcome( $result );

			$scope = empty( $catalog[ $name ]['write'] ) ? 'read' : 'write';

			Mcp_Activity_Log::record( $name, $args, $ok, $error, $scope, self::$channel );

			return $result;
		} finally {
			self::$dispatching = false;
		}
	}

	/**
	 * Read success/failure out of a handler result.
	 *
	 * Two failure shapes reach here. A handler that validates its own
	 * input returns WP_Error. A handler that delegates to Cli_Bridge gets
	 * back an ARRAY carrying `ok => false` plus `error`, because a
	 * `WP_CLI::error()` inside the shim is a controlled failure rather
	 * than an exception. Reading only the first shape logged every failed
	 * command — a refused purge, a Cloudflare call with no credentials —
	 * as a success.
	 *
	 * @param mixed $result Handler return value.
	 * @return array{0:bool,1:string}
	 */
	private static function outcome( $result ): array {
		if ( is_wp_error( $result ) ) {
			return array( false, $result->get_error_message() );
		}

		if ( is_array( $result ) && array_key_exists( 'ok', $result ) && ! $result['ok'] ) {
			$error = isset( $result['error'] ) ? (string) $result['error'] : '';
			return array( false, '' === $error ? 'Command reported failure.' : $error );
		}

		return array( true, '' );
	}

	/** @var string Transport that carried the current call (for the audit log). */
	private static $channel = 'mcp';

	/**
	 * Name the transport for subsequent invokes — the JSON-RPC endpoint and
	 * the hosted-broker REST routes share this catalog, and the audit trail
	 * should say which one a call arrived on.
	 */
	public static function set_channel( string $channel ): void {
		self::$channel = '' === $channel ? 'mcp' : $channel;
	}

	/** @var bool True while an MCP tool handler is executing. */
	private static $dispatching = false;

	/**
	 * True while a tool call is being dispatched — lets deeper layers
	 * (e.g. the settings change-log) attribute a mutation to MCP.
	 */
	public static function in_dispatch(): bool {
		return self::$dispatching;
	}

	/*
	 * Handlers — thin proxies to the Free engine. Each takes decoded tool
	 * arguments and returns an array payload (or WP_Error on bad input).
	 */

	/**
	 * Cache status, stats, and detected server.
	 *
	 * @param array $args Unused.
	 * @return array
	 */
	public static function get_cache_status( array $args ) {
		unset( $args );
		$opts = Settings::get();
		return array(
			'cache_enabled' => (bool) ( $opts['cache_enabled'] ?? false ),
			'stats'         => Cache::get_stats(),
			'server'        => Server::type(),
		);
	}

	/**
	 * Facts about this site and install, stated explicitly.
	 *
	 * The Hub's fleet dashboard needed two things no tool reported directly.
	 * It had been INFERRING Pro's presence from `list_modules` — "any entry
	 * with tier: pro" — which works only because the registry returns just
	 * available modules. That is an inference riding an implementation
	 * detail, and it breaks the day a Pro install registers zero Pro modules.
	 *
	 * `pro_active` is "the Pro plugin is loaded and API-compatible";
	 * `licensed` is a separate question, since Pro can be active but
	 * unlicensed (its modules then boot but their settings are locked). Both
	 * are reported so a consumer never has to guess which one it wanted.
	 * (#146)
	 *
	 * @param array $args Unused.
	 * @return array
	 */
	/**
	 * Is Pro licensed right now?
	 *
	 * Resolved through the `xspeed_module_descriptor` filter — the one Pro
	 * actually registers — by running a minimal Pro descriptor through it and
	 * reading back the `locked` flag Pro sets when the licence is inactive.
	 *
	 * `license` is deliberately not used as the probe slug: Pro exempts that
	 * module from locking so an expired site can still reach the screen where
	 * a new key is entered, so it would always come back unlocked.
	 */
	private static function pro_licensed(): bool {
		$probe = apply_filters(
			'xspeed_module_descriptor',
			array(
				'slug' => '__license_probe__',
				'tier' => 'pro',
			),
			null
		);

		return empty( $probe['locked'] );
	}

	public static function get_site_info( array $args ) {
		unset( $args );

		$pro_active = Tier_Registry::pro_active();

		return array(
			'pro_active'     => $pro_active,
			'pro_version'    => defined( 'XSPEED_PRO_VERSION' ) ? (string) constant( 'XSPEED_PRO_VERSION' ) : null,
			// Distinct from pro_active: Pro can be installed and running
			// while its license is expired or absent.
			//
			// NOT `apply_filters( 'xspeed_pro_licensed', true )`. That hook is
			// only ever APPLIED by Pro as an override point — no released
			// version registers it — so with nothing listening the `true`
			// default stood and this reported `licensed: true` on a fully
			// revoked licence: the exact misreport the tool exists to
			// eliminate. (QA blocker on #158)
			//
			// Ask the question the dashboard asks instead. Pro DOES register
			// `xspeed_module_descriptor` and stamps `locked => 'license'` on
			// every Pro entry when the licence is inactive, so reading that
			// back is a real signal, and it cannot drift from what the panel
			// shows because it IS what the panel shows.
			'licensed'       => $pro_active ? self::pro_licensed() : false,
			'plugin_version' => defined( 'XSPEED_VERSION' ) ? (string) constant( 'XSPEED_VERSION' ) : null,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'server'         => Server::type(),
			'multisite'      => is_multisite(),
		);
	}

	/**
	 * All registered module descriptors.
	 *
	 * @param array $args Unused.
	 * @return array
	 */
	public static function list_modules( array $args ) {
		unset( $args );
		return Admin::modules_payload();
	}

	/**
	 * Run the optimization autopilot.
	 *
	 * A thin wrapper: everything — the plan, the verification, the revert —
	 * lives in Optimize_Runner, so the CLI and this tool cannot drift into
	 * making different decisions about the same site.
	 *
	 * @param array<string,mixed> $args Tool arguments.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function optimize_site( array $args = array() ) {
		return \XSpeed\Optimize_Runner::run(
			array(
				'aggressiveness' => (string) ( $args['aggressiveness'] ?? 'standard' ),
				'dry_run'        => (bool) ( $args['dry_run'] ?? false ),
			)
		);
	}

	/**
	 * Before/after cache benchmark timings.
	 *
	 * @param array $args Unused.
	 * @return array
	 */
	public static function run_benchmark( array $args ) {
		unset( $args );
		return Cache_Benchmark::run();
	}

	/**
	 * Personalized Pro-feature suggestions for this site.
	 *
	 * @param array $args Unused.
	 * @return array
	 */
	public static function get_pro_audit( array $args ) {
		unset( $args );
		return array( 'suggestions' => Pro_Audit::run() );
	}

	/**
	 * Purge the cache by type.
	 *
	 * @param array $args { type?:string } — one of PURGE_TYPES; default all.
	 * @return array|\WP_Error
	 */
	public static function purge_cache( array $args ) {
		$type = isset( $args['type'] ) ? (string) $args['type'] : 'all';
		if ( '' === $type ) {
			$type = 'all';
		}
		if ( ! in_array( $type, self::PURGE_TYPES, true ) ) {
			return new \WP_Error(
				'xspeed_mcp_bad_type',
				sprintf(
					/* translators: %s: comma-separated list of valid purge types. */
					__( 'Invalid purge type. Expected one of: %s', 'xspeed' ),
					implode( ', ', self::PURGE_TYPES )
				),
				array( 'status' => 400 )
			);
		}
		// Named source, not the default "manual": the purge log's whole job
		// is to let an admin see that the cache cleared because an assistant
		// asked, not because someone clicked.
		$count = Cache::purge_type( $type, __( 'AI assistant', 'xspeed' ) );
		return array(
			'purged' => $type,
			'count'  => $count,
			'stats'  => Cache::get_stats(),
		);
	}

	/**
	 * Enable or disable page caching.
	 *
	 * @param array $args { enabled:bool }.
	 * @return array|\WP_Error
	 */
	public static function toggle_cache( array $args ) {
		if ( ! array_key_exists( 'enabled', $args ) ) {
			return new \WP_Error(
				'xspeed_mcp_missing_enabled',
				__( 'The "enabled" parameter is required (true or false).', 'xspeed' ),
				array( 'status' => 400 )
			);
		}
		$enabled = rest_sanitize_boolean( $args['enabled'] );
		$install = Cache::toggle( $enabled );

		// Persist cache_enabled the same way the Free /cache/toggle route
		// does (class-rest-api.php:235) — Cache::toggle handles the drop-in
		// + wp-config; Settings owns the option flag.
		//
		// From the RESULT, not from $enabled: toggle() refuses to enable when
		// another caching plugin owns the drop-in, and writing the requested
		// value regardless left the site reporting a cache it had not
		// installed — over MCP, with no human reading the response.

		return array(
			'cache_enabled'  => $install['enabled'],
			'blocked'        => ! empty( $install['blocked'] ),
			'blocked_reason' => $install['blocked_reason'] ?? null,
			'install_state'  => $install,
			'stats'          => Cache::get_stats(),
		);
	}

	/**
	 * Is this module reachable over MCP right now?
	 *
	 * Mirrors SettingsModule::module_reachable(). Registration is not
	 * enough: Module_Registry::available() only asks whether Pro is LOADED,
	 * not whether it is LICENSED, so an unlicensed Pro site had every Pro
	 * module readable and writable over MCP while the dashboard showed it
	 * locked — reachable by any agent holding a write token. (QA M2)
	 *
	 * The licence answer comes through the `xspeed_module_descriptor` filter
	 * Pro registers, so Free never names a Pro class. (NOT
	 * `xspeed_pro_licensed` — Pro only ever APPLIES that one as an override
	 * and nothing listens to it, so gating on it silently passed everything.)
	 * `license` is exempt for the same reason Pro exempts it: locking it
	 * would remove the only surface that can fix an expired licence.
	 */
	private static function settings_module_reachable( string $slug ): bool {
		$module = \XSpeed\Module_Registry::available()[ $slug ] ?? null;
		if ( ! $module ) {
			return false;
		}
		if ( \XSpeed\Module::TIER_PRO !== $module->tier() || 'license' === $slug ) {
			return true;
		}

		// Ask the SAME question the dashboard asks. `xspeed_pro_licensed` is
		// only ever APPLIED by Pro as an override hook — nothing registers it
		// — so calling it here returned the default `true` and gated nothing.
		// Pro DOES register `xspeed_module_descriptor`, and sets
		// `locked => 'license'` on every Pro entry when the licence is
		// inactive. Reusing that keeps one definition of "locked" instead of
		// a second one in Free that can drift from the panel. (QA M2)
		$entry = apply_filters(
			'xspeed_module_descriptor',
			array(
				'slug' => $slug,
				'tier' => $module->tier(),
			),
			$module
		);

		return empty( $entry['locked'] );
	}

	/**
	 * Read a module's schema-validated settings.
	 *
	 * @param array $args { module:string }.
	 * @return array|\WP_Error
	 */
	public static function get_settings( array $args ) {
		$module = isset( $args['module'] ) ? (string) $args['module'] : '';
		if ( '' === $module ) {
			return new \WP_Error(
				'xspeed_mcp_missing_module',
				__( 'The "module" parameter is required.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}
		if ( ! self::settings_module_reachable( $module ) ) {
			return new \WP_Error(
				'xspeed_mcp_unknown_module',
				sprintf(
					/* translators: %s: module slug. */
					__( 'Unknown module "%s".', 'xspeed' ),
					$module
				),
				array( 'status' => 404 )
			);
		}
		/**
		 * Filter the get_settings MCP payload for one module.
		 *
		 * Lets the module that owns the settings attach state the stored
		 * values alone cannot express — a toggle that is on but resolves to
		 * no effect on this host (Brotli without ngx_brotli), a configured
		 * generator that has never succeeded. Free never names Pro classes,
		 * so this seam is how a Pro module reaches the response an agent
		 * reads.
		 *
		 * @param array<string,mixed> $payload The response: module + settings.
		 * @param string              $module  Module slug.
		 * @param string              $action  'get' here; 'update' on writes.
		 */
		return apply_filters(
			'xspeed_mcp_settings_payload',
			array(
				'module'   => $module,
				// Public view — secret fields masked. An MCP agent must never be able
				// to read stored credentials back in plaintext. (#115)
				'settings' => Settings_Manager::get_public( $module ),
			),
			$module,
			'get'
		);
	}

	/**
	 * Update a module's settings (schema-validated).
	 *
	 * @param array $args { module:string, values:array }.
	 * @return array|\WP_Error
	 */
	public static function update_settings( array $args ) {
		$module = isset( $args['module'] ) ? (string) $args['module'] : '';
		$values = $args['values'] ?? null;
		if ( '' === $module ) {
			return new \WP_Error(
				'xspeed_mcp_missing_module',
				__( 'The "module" parameter is required.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}
		if ( ! is_array( $values ) ) {
			return new \WP_Error(
				'xspeed_mcp_bad_values',
				__( 'The "values" parameter must be an object of setting keys.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}
		if ( ! self::settings_module_reachable( $module ) ) {
			return new \WP_Error(
				'xspeed_mcp_unknown_module',
				sprintf(
					/* translators: %s: module slug. */
					__( 'Unknown module "%s".', 'xspeed' ),
					$module
				),
				array( 'status' => 404 )
			);
		}
		// Writing credentials over MCP requires the explicit `configure` grant —
		// off by default even for a write-scoped connection — so an agent can't
		// silently repoint the Cloudflare/object-cache backend at an attacker
		// endpoint. Refuse with a message naming exactly which fields need it.
		// (Settings_Manager::update also strips these as a backstop covering the
		// run_command → CLI path.) (#116)
		if ( ! self::can_configure() ) {
			$secret_fields = Settings_Manager::secret_keys_in( $module, $values );
			if ( ! empty( $secret_fields ) ) {
				return new \WP_Error(
					'xspeed_mcp_configure_required',
					sprintf(
						/* translators: 1: comma-separated field names, 2: module slug. */
						__( 'Writing credential fields (%1$s) on "%2$s" needs the "configure" scope, which is off by default. Reconnect the MCP client granting the configure scope, or set these credentials from the xSpeed dashboard.', 'xspeed' ),
						implode( ', ', $secret_fields ),
						$module
					),
					array(
						'status'         => 403,
						'refused_fields' => $secret_fields,
					)
				);
			}
		}
		// The Pro licence WRITE gate. `settings_module_reachable()` above already
		// hides locked Pro modules, but that is a VISIBILITY check answered by
		// the `xspeed_module_descriptor` filter — a different question from "may
		// this be written", and one that drifts the moment Pro changes how it
		// flags `locked`. Ask the write gate itself, the same one REST consults
		// via Module::update_settings(), so the two can't disagree.
		//
		// This is not theoretical: with the descriptor's `locked` flag removed,
		// this handler wrote `enabled: false -> true` to a module whose
		// is_license_locked() was true, because it persists through
		// Settings_Manager::update() and never reaches Module::update_settings().
		// (#185)
		$module_object = \XSpeed\Module_Registry::get( $module );
		if ( $module_object && $module_object->is_license_locked() ) {
			// Match the REST path's audit trail — a refused write is a security
			// event and must be visible in the activity log wherever it came
			// from. Module::license_write_refusal() records the same type.
			\XSpeed\Activity_Log::record(
				'license_write_refused',
				sprintf(
					/* translators: %s: module slug. */
					__( 'Refused an MCP settings write to the Pro module "%s" — no valid license.', 'xspeed' ),
					$module
				),
				\XSpeed\Activity_Log::WARN
			);

			return new \WP_Error(
				'xspeed_license_required',
				sprintf(
					/* translators: %s: module slug. */
					__( '"%s" is a Pro module and this site has no active license, so the write was refused. Nothing was changed.', 'xspeed' ),
					$module
				),
				array(
					'status' => 403,
					'module' => $module,
				)
			);
		}

		// An agent cannot tell a silent no-op from a real write, so refuse
		// instead of returning a success payload. update() walks the schema:
		// an out-of-schema key is never written and never mentioned, and an
		// in-schema key with a rejected value quietly keeps the stored one.
		// The realistic case is `cache_enabled` on the `cache` module — the
		// most natural way to ask for caching, and a complete no-op. (#206)
		$report = self::inspect_or_error( $module, $values );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		/**
		 * Filter the update_settings MCP payload for one module.
		 *
		 * The write path's twin of the get filter above — this is where a
		 * module can say "stored, but inert on this host" in the same
		 * response that reports the write, instead of returning a plain
		 * success an agent relays as "enabled". Documented in
		 * docs/guides/hooks-and-filters.md.
		 *
		 * @param array<string,mixed> $payload The response: module + settings.
		 * @param string              $module  Module slug.
		 * @param string              $action  'update' here; 'get' on reads.
		 */
		return apply_filters(
			'xspeed_mcp_settings_payload',
			array(
				'module'   => $module,
				// Return value is already masked (Settings_Manager::update returns the
				// public view), so a written secret isn't echoed back either. (#115)
				'settings' => Settings_Manager::update( $module, $values ),
			),
			$module,
			'update'
		);
	}

	/**
	 * Refuse a settings payload carrying keys that would be silently dropped.
	 *
	 * @param string              $module Module slug.
	 * @param array<string,mixed> $values Proposed values.
	 * @return true|\WP_Error True when every key would be applied.
	 */
	private static function inspect_or_error( string $module, array $values ) {
		$report = Settings_Manager::inspect_input( $module, $values );
		if ( empty( $report['unknown'] ) && empty( $report['invalid'] ) ) {
			return true;
		}

		$parts = array();
		foreach ( $report['unknown'] as $key ) {
			$detail = sprintf(
				/* translators: 1: setting key, 2: module slug. */
				__( '"%1$s" is not a setting of module "%2$s"', 'xspeed' ),
				$key,
				$module
			);
			$hint = Settings_Manager::hint_for_unknown_key( $key );
			if ( '' !== $hint ) {
				$detail .= ' — ' . $hint;
			} else {
				$near = Settings_Manager::did_you_mean( $module, $key );
				if ( ! empty( $near ) ) {
					$detail .= sprintf(
						/* translators: %s: comma-separated setting names. */
						__( ' — did you mean: %s?', 'xspeed' ),
						implode( ', ', $near )
					);
				}
			}
			$parts[] = $detail;
		}
		foreach ( $report['invalid'] as $key ) {
			$parts[] = sprintf(
				/* translators: %s: setting key. */
				__( '"%s" was rejected by the schema (wrong type, or outside the allowed range/options)', 'xspeed' ),
				$key
			);
		}

		return new \WP_Error(
			'xspeed_settings_refused',
			sprintf(
				/* translators: 1: module slug, 2: reasons. */
				__( 'Refused to update %1$s — nothing was written. %2$s', 'xspeed' ),
				$module,
				implode( '; ', $parts )
			),
			array(
				'status'          => 400,
				'refused_unknown' => $report['unknown'],
				'refused_invalid' => $report['invalid'],
				'would_apply'     => $report['applied'],
			)
		);
	}

	/**
	 * List every command run_command can invoke (the full CLI surface).
	 *
	 * @param array $args Unused.
	 * @return array
	 */
	public static function list_commands( array $args ) {
		unset( $args );
		return array( 'commands' => Cli_Bridge::catalog() );
	}

	/**
	 * Run any registered xSpeed command via the CLI bridge.
	 *
	 * @param array $args { command:string, args?:array, options?:array }.
	 * @return array|\WP_Error
	 */
	public static function run_command( array $args ) {
		$command = isset( $args['command'] ) ? (string) $args['command'] : '';
		if ( '' === $command ) {
			return new \WP_Error(
				'xspeed_mcp_missing_command',
				__( 'The "command" parameter is required.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}
		$positional = isset( $args['args'] ) && is_array( $args['args'] ) ? $args['args'] : array();
		$options    = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
		return Cli_Bridge::run( $command, $positional, $options );
	}

	/* --------------------------------------------------------------------- */
	/* Promoted action handlers — typed wrappers over Cli_Bridge.            */
	/* Delegating to the bridge lets a Free tool drive a Pro action (psi,    */
	/* ccss) with no cross-repo class reference, and keeps zero drift.       */
	/* --------------------------------------------------------------------- */

	/**
	 * Purge the Cloudflare edge cache.
	 *
	 * @param array $args Unused.
	 * @return array|\WP_Error
	 */
	public static function purge_cloudflare( array $args ) {
		unset( $args );
		return Cli_Bridge::run( 'cf', array( 'purge' ) );
	}

	/**
	 * Scan the database for bloat (no deletion).
	 *
	 * @param array $args Unused.
	 * @return array|\WP_Error
	 */
	public static function scan_database( array $args ) {
		unset( $args );
		$result = Cli_Bridge::run( 'db', array( 'scan' ) );
		if ( is_wp_error( $result ) || empty( $result['ok'] ) ) {
			return $result;
		}

		/*
		 * Mint the token clean_database will demand, and state what it covers.
		 *
		 * The scan is the only place the caller can see what is about to be
		 * destroyed, so it is the only honest place to authorise the delete.
		 * The token is bound to the CATEGORIES ENABLED and the COUNTS FOUND at
		 * this moment: if either moves before the delete lands, the token no
		 * longer describes reality and clean_database refuses. That closes the
		 * window where a scan is shown to a human, something changes, and the
		 * delete removes more than was agreed to. (#184)
		 */
		$result['confirm_token'] = self::mint_clean_token();
		$result['confirm_note']  = __( 'This preview deletes nothing. To delete what is listed, call clean_database with this confirm_token. It expires in 5 minutes and stops working if the database changes.', 'xspeed' );

		return $result;
	}

	/** Categories currently enabled for deletion, with what a scan found in each. */
	private static function clean_scope(): array {
		$enabled = array_keys( array_filter( Settings_Manager::get( 'database' ), static fn( $v ) => true === $v ) );
		sort( $enabled );

		$counts = array();
		foreach ( Database_Cleaner::scan() as $key => $row ) {
			$counts[ $key ] = is_array( $row ) ? (int) ( $row['count'] ?? 0 ) : (int) $row;
		}
		ksort( $counts );

		return array(
			'enabled' => $enabled,
			'counts'  => $counts,
		);
	}

	/**
	 * Actions that permanently destroy content and therefore require a
	 * confirm_token, keyed by canonical command name.
	 *
	 * Keyed by ACTION, not by tool name, because the same action is
	 * reachable through several tools (the typed clean_database, the
	 * run_command gateway, and any future wrapper).
	 *
	 * @return array<string, string[]>
	 */
	private static function destructive_actions(): array {
		/**
		 * Filter the command actions that require an explicit confirmation.
		 *
		 * @since 1.1.6
		 * @param array<string, string[]> $actions Action names keyed by command.
		 */
		return (array) apply_filters(
			'xspeed_mcp_destructive_actions',
			array( 'xspeed db' => array( 'clean' ) )
		);
	}

	/**
	 * Name the destructive action a call would run, or '' if it is harmless.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Decoded tool arguments.
	 * @return string Canonical "<command> <action>", or '' when not destructive.
	 */
	private static function destructive_action( string $name, array $args ): string {
		// The gateway carries the real command in its arguments; a typed tool
		// is identified by the command it is mapped to.
		if ( 'run_command' === $name ) {
			$command = isset( $args['command'] ) ? (string) $args['command'] : '';
			if ( '' === $command ) {
				return '';
			}
			$positional = isset( $args['args'] ) && is_array( $args['args'] ) ? $args['args'] : array();
			$resolved   = Cli_Bridge::classify( $command, $positional );
		} elseif ( 'clean_database' === $name ) {
			$resolved = Cli_Bridge::classify( 'db', array( 'clean' ) );
		} else {
			return '';
		}

		if ( '' === $resolved['name'] ) {
			return '';
		}

		$destructive = self::destructive_actions();
		if ( ! isset( $destructive[ $resolved['name'] ] ) ) {
			return '';
		}
		if ( ! in_array( $resolved['action'], (array) $destructive[ $resolved['name'] ], true ) ) {
			return '';
		}

		return trim( $resolved['name'] . ' ' . $resolved['action'] );
	}

	/**
	 * Verify (and consume) the confirm_token minted by scan_database.
	 *
	 * @param array $args Decoded tool arguments.
	 * @return true|\WP_Error
	 */
	private static function verify_clean_token( array $args ) {
		$token = isset( $args['confirm_token'] ) ? (string) $args['confirm_token'] : '';
		if ( '' === $token ) {
			return new \WP_Error(
				'xspeed_mcp_confirm_required',
				__( 'This permanently deletes content and cannot be undone. Call scan_database first to see exactly what would be removed, then pass the confirm_token it returns.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}

		// Single use: consumed whether or not the delete goes ahead, so one
		// approval can never authorise a second, different deletion.
		$sealed = self::consume_clean_token( $token );
		if ( '' === $sealed ) {
			return new \WP_Error(
				'xspeed_mcp_confirm_invalid',
				__( 'That confirm_token is unknown or has expired (they last 5 minutes). Run scan_database again and use the fresh token.', 'xspeed' ),
				array( 'status' => 400 )
			);
		}

		if ( ! hash_equals( $sealed, self::clean_fingerprint() ) ) {
			return new \WP_Error(
				'xspeed_mcp_confirm_stale',
				__( 'The database changed since that scan, so the preview no longer describes what would be deleted. Run scan_database again and confirm against the new result.', 'xspeed' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/** Fingerprint of the scope, so a token cannot outlive what it described. */
	private static function clean_fingerprint(): string {
		return hash( 'sha256', (string) wp_json_encode( self::clean_scope() ) );
	}

	/** Lifetime of a confirm_token, from mint to refusal. */
	private const CLEAN_TOKEN_TTL = 5 * MINUTE_IN_SECONDS;

	/** Storage key for a minted token (the token itself is never stored). */
	private static function clean_token_key( string $token ): string {
		return 'xspeed_mcp_clean_' . hash( 'sha256', $token );
	}

	/*
	 * The token is held in an OPTION, not a transient.
	 *
	 * scan_database and clean_database are two separate HTTP requests, so the
	 * token has to survive between them. With an external object cache
	 * installed, set_transient() writes to that cache ONLY and never touches
	 * the options table — so on any site whose object cache is
	 * non-persistent, flushed between requests, or simply orphaned (a stale
	 * W3TC/Redis drop-in pointing at a dead backend), the token evaporates the
	 * moment it is minted.
	 *
	 * That does not fail safe. It makes the confirmation UNSATISFIABLE:
	 * clean_database can never be authorised by any sequence of calls, and the
	 * operator's only remaining route to the feature is the admin panel. A
	 * guard that cannot be passed is a broken feature, and the pressure it
	 * creates is to remove the guard. Reproduced on a stack running W3 Total
	 * Cache's object-cache drop-in: every freshly minted token was refused as
	 * "unknown or expired" on the very next request. (#184)
	 *
	 * Options are backed by the database, so the token persists whatever the
	 * object cache does. Expiry is carried in the stored value and checked on
	 * read, since options have no TTL of their own.
	 */

	private static function mint_clean_token(): string {
		$token = wp_generate_password( 32, false );

		// autoload=no: this is read once, by one request, minutes from now.
		add_option(
			self::clean_token_key( $token ),
			wp_json_encode(
				array(
					'fingerprint' => self::clean_fingerprint(),
					'expires'     => time() + self::CLEAN_TOKEN_TTL,
				)
			),
			'',
			'no'
		);

		self::purge_expired_clean_tokens();

		return $token;
	}

	/**
	 * Read a minted token's sealed fingerprint, or '' if unknown/expired.
	 *
	 * Consumes the record either way: a token is single use, so one approval
	 * can never authorise a second, different deletion.
	 */
	private static function consume_clean_token( string $token ): string {
		$key    = self::clean_token_key( $token );
		$stored = get_option( $key );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}

		delete_option( $key );

		$data = json_decode( $stored, true );
		if ( ! is_array( $data ) || empty( $data['fingerprint'] ) ) {
			return '';
		}
		if ( ! isset( $data['expires'] ) || time() > (int) $data['expires'] ) {
			return '';
		}

		return (string) $data['fingerprint'];
	}

	/**
	 * Drop token rows nobody consumed.
	 *
	 * Options have no TTL, so an unused token would otherwise sit in
	 * wp_options forever — a scan that is never followed by a clean is the
	 * normal case, not the exception.
	 */
	private static function purge_expired_clean_tokens(): void {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no options API for "select by key prefix"; runs only when a token is minted.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'xspeed_mcp_clean_' ) . '%'
			)
		);

		foreach ( (array) $names as $name ) {
			$data = json_decode( (string) get_option( $name ), true );
			if ( ! is_array( $data ) || ! isset( $data['expires'] ) || time() > (int) $data['expires'] ) {
				delete_option( $name );
			}
		}
	}

	/**
	 * Clean database bloat (destructive).
	 *
	 * @param array $args Unused.
	 * @return array|\WP_Error
	 */
	public static function clean_database( array $args ) {
		/*
		 * The scan-before-clean confirmation is enforced in invoke(), which
		 * every tool passes through — see destructive_action(). It is NOT
		 * repeated here: the token is single-use, so checking it twice would
		 * consume it on the first check and reject the caller on the second.
		 *
		 * Reaching this line means the dispatcher already verified a token
		 * bound to a scan of the current database state. (#184)
		 */
		unset( $args );
		return Cli_Bridge::run( 'db', array( 'clean' ) );
	}

	/**
	 * Flush the persistent object cache.
	 *
	 * @param array $args Unused.
	 * @return array|\WP_Error
	 */
	public static function flush_object_cache( array $args ) {
		unset( $args );
		return Cli_Bridge::run( 'objcache', array( 'flush' ) );
	}

	/**
	 * Start the cache preloader.
	 *
	 * @param array $args Unused.
	 * @return array|\WP_Error
	 */
	public static function start_preloader( array $args ) {
		unset( $args );
		return Cli_Bridge::run( 'preloader', array( 'start' ) );
	}

	/**
	 * Full health diagnostics (checks + stats + buckets + activity).
	 * Direct typed payload — same tier as get_cache_status — so the agent
	 * gets structured tones/ids instead of parsing CLI log lines.
	 *
	 * @param array $args Unused.
	 * @return array
	 */
	public static function get_health( array $args ) {
		unset( $args );
		return array(
			'checks'    => \XSpeed\Health::checks(),
			'stats'     => Cache::get_stats(),
			'buckets'   => \XSpeed\Hit_Counter::buckets(),
			'hit_daily' => \XSpeed\Hit_Counter::daily_series( 30 ),
			'activity'  => \XSpeed\Activity_Log::entries(),
		);
	}

	/**
	 * Stored benchmark runs + settings-change events (trend data).
	 *
	 * @param array $args { limit?:int }.
	 * @return array
	 */
	public static function get_benchmark_history( array $args ) {
		$limit   = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 100;
		$changes = array();
		foreach ( \XSpeed\Activity_Log::entries() as $entry ) {
			if ( 'settings_changed' === ( $entry['type'] ?? '' ) ) {
				$changes[] = array(
					'ts'      => (int) $entry['ts'],
					'message' => (string) $entry['message'],
				);
			}
		}
		return array(
			'runs'    => Cache_Benchmark::history( $limit ),
			'changes' => $changes,
		);
	}

	/**
	 * Purge a single URL's cache entries.
	 *
	 * @param array $args { url:string }.
	 * @return array|\WP_Error
	 */
	/**
	 * Inspect what is in the page cache (pages + age, or size breakdown).
	 *
	 * @param array $args detail: pages|size, limit.
	 * @return array|\WP_Error
	 */
	public static function get_cache_inventory( array $args ) {
		$detail = isset( $args['detail'] ) ? (string) $args['detail'] : 'pages';
		$action = 'size' === $detail ? 'size' : 'inventory';
		$assoc  = array();
		if ( isset( $args['limit'] ) && '' !== $args['limit'] ) {
			$assoc['limit'] = (string) $args['limit'];
		}
		return Cli_Bridge::run( 'cache', array( $action ), $assoc );
	}

	/**
	 * Recent cache purges and their causes.
	 *
	 * @param array $args limit.
	 * @return array|\WP_Error
	 */
	public static function get_purge_log( array $args ) {
		$assoc = array();
		if ( isset( $args['limit'] ) && '' !== $args['limit'] ) {
			$assoc['limit'] = (string) $args['limit'];
		}
		return Cli_Bridge::run( 'cache', array( 'purge-log' ), $assoc );
	}

	/**
	 * Re-verify (and repair) the server rewrite rules.
	 *
	 * @param array $args Unused.
	 * @return array|\WP_Error
	 */
	public static function recheck_rewrite_rules( array $args ) {
		unset( $args );
		return Cli_Bridge::run( 'cache', array( 'recheck-rewrite' ) );
	}

	/**
	 * Turn Cloudflare development mode on or off.
	 *
	 * A boolean rather than two tools: dev-on and dev-off are one decision,
	 * and offering them separately doubles the surface for no gain.
	 *
	 * @param array $args enabled (bool, required).
	 * @return array|\WP_Error
	 */
	public static function set_cloudflare_dev_mode( array $args ) {
		if ( ! array_key_exists( 'enabled', $args ) ) {
			return new \WP_Error( 'xspeed_mcp_missing_enabled', __( 'The enabled argument is required.', 'xspeed' ), array( 'status' => 400 ) );
		}
		$on = filter_var( $args['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( null === $on ) {
			return new \WP_Error( 'xspeed_mcp_invalid_enabled', __( 'The enabled argument must be true or false.', 'xspeed' ), array( 'status' => 400 ) );
		}
		return Cli_Bridge::run( 'cf', array( $on ? 'dev-on' : 'dev-off' ) );
	}

	/**
	 * Optimize database tables (distinct from clean_database, which deletes).
	 *
	 * @param array $args Unused.
	 * @return array|\WP_Error
	 */
	public static function optimize_database( array $args ) {
		unset( $args );
		return Cli_Bridge::run( 'db', array( 'optimize' ) );
	}

	/**
	 * Object cache state, or the server snippet that enables it.
	 *
	 * @param array $args detail: status|snippet.
	 * @return array|\WP_Error
	 */
	public static function get_object_cache_status( array $args ) {
		$detail = isset( $args['detail'] ) ? (string) $args['detail'] : 'status';
		$action = 'snippet' === $detail ? 'snippet' : 'status';
		return Cli_Bridge::run( 'objcache', array( $action ) );
	}

	/**
	 * Install or remove the object-cache drop-in.
	 *
	 * @param array $args enabled (bool, required).
	 * @return array|\WP_Error
	 */
	public static function toggle_object_cache( array $args ) {
		if ( ! array_key_exists( 'enabled', $args ) ) {
			return new \WP_Error( 'xspeed_mcp_missing_enabled', __( 'The enabled argument is required.', 'xspeed' ), array( 'status' => 400 ) );
		}
		$on = filter_var( $args['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( null === $on ) {
			return new \WP_Error( 'xspeed_mcp_invalid_enabled', __( 'The enabled argument must be true or false.', 'xspeed' ), array( 'status' => 400 ) );
		}
		return Cli_Bridge::run( 'objcache', array( $on ? 'enable' : 'disable' ) );
	}

	/**
	 * List or clear stored Critical CSS.
	 *
	 * @param array $args action: list|clear.
	 * @return array|\WP_Error
	 */
	public static function manage_critical_css( array $args ) {
		$action = isset( $args['action'] ) ? (string) $args['action'] : '';
		if ( ! in_array( $action, array( 'list', 'clear' ), true ) ) {
			return new \WP_Error( 'xspeed_mcp_invalid_action', __( 'The action argument must be "list" or "clear".', 'xspeed' ), array( 'status' => 400 ) );
		}
		return Cli_Bridge::run( 'ccss', array( $action ) );
	}

	/**
	 * Preloader progress.
	 *
	 * @param array $args Unused.
	 * @return array|\WP_Error
	 */
	public static function get_preloader_status( array $args ) {
		unset( $args );
		return Cli_Bridge::run( 'preloader', array( 'status' ) );
	}

	/**
	 * Stop a running preload.
	 *
	 * @param array $args Unused.
	 * @return array|\WP_Error
	 */
	public static function stop_preloader( array $args ) {
		unset( $args );
		return Cli_Bridge::run( 'preloader', array( 'stop' ) );
	}

	/**
	 * Stored external audit runs (PSI / GTmetrix).
	 *
	 * Read-only by construction: it reads the option Score already wrote. No
	 * outbound call is made, which is what lets the Hub poll this on a
	 * schedule without spending the site owner's PSI or GTmetrix quota.
	 *
	 * @param array $args limit.
	 * @return array|\WP_Error
	 */
	public static function get_score_history( array $args ) {
		if ( ! class_exists( '\\XSpeed\\Score' ) ) {
			return new \WP_Error( 'xspeed_mcp_no_score', __( 'External scores are not available on this site.', 'xspeed' ), array( 'status' => 404 ) );
		}

		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 100;
		$limit = max( 1, min( 500, $limit ) );

		$history = \XSpeed\Score::history();

		$runs = array();
		foreach ( array_slice( $history, 0, $limit ) as $run ) {
			if ( ! is_array( $run ) ) {
				continue;
			}
			$metrics = isset( $run['metrics'] ) && is_array( $run['metrics'] ) ? $run['metrics'] : array();
			$runs[]  = array(
				'provider'   => isset( $run['provider'] ) ? (string) $run['provider'] : 'unknown',
				'ts'         => isset( $run['ts'] ) ? (int) $run['ts'] : 0,
				'url'        => isset( $run['url'] ) ? (string) $run['url'] : '',
				'strategy'   => isset( $run['strategy'] ) ? (string) $run['strategy'] : null,
				// A failed audit and a successful one that returned no score
				// both project to score null — ok is the only field that
				// tells them apart, and error says why it failed.
				'ok'         => ! empty( $run['ok'] ),
				'error'      => isset( $run['error'] ) && '' !== $run['error'] ? (string) $run['error'] : null,
				// Null, never 0: Score distinguishes "no score" from "scored
				// zero", and flattening that reports a failed audit as a
				// catastrophic result.
				'score'      => isset( $run['score'] ) && is_numeric( $run['score'] ) ? (int) $run['score'] : null,
				'metrics'    => array(
					'lcp'  => self::metric_or_null( $metrics, 'lcp' ),
					'fcp'  => self::metric_or_null( $metrics, 'fcp' ),
					'cls'  => self::metric_or_null( $metrics, 'cls' ),
					'tbt'  => self::metric_or_null( $metrics, 'tbt' ),
					'si'   => self::metric_or_null( $metrics, 'si' ),
					'ttfb' => self::metric_or_null( $metrics, 'ttfb' ),
				),
				'report_url' => self::report_url_for( $run ),
			);
		}

		return array(
			'runs'  => $runs,
			'total' => count( $history ),
		);
	}

	/**
	 * One metric as a float, or null when absent/non-numeric.
	 *
	 * @param array  $metrics Metric bag.
	 * @param string $key     Metric id.
	 */
	private static function metric_or_null( array $metrics, string $key ): ?float {
		return isset( $metrics[ $key ] ) && is_numeric( $metrics[ $key ] ) ? (float) $metrics[ $key ] : null;
	}

	/**
	 * Deep link to the provider's own report, when one exists.
	 *
	 * GTmetrix hosts a durable report per test, so its id is enough to build
	 * the link. PSI does NOT — a Lighthouse result is returned to the caller
	 * and never hosted, so there is genuinely nothing to link to and this
	 * returns null rather than inventing a URL that 404s.
	 *
	 * @param array $run One stored run.
	 */
	private static function report_url_for( array $run ): ?string {
		$provider = isset( $run['provider'] ) ? (string) $run['provider'] : '';
		if ( 'gtmetrix' !== $provider ) {
			return null;
		}
		$test_id = isset( $run['test_id'] ) ? trim( (string) $run['test_id'] ) : '';
		if ( '' === $test_id ) {
			return null;
		}
		return 'https://gtmetrix.com/reports/' . rawurlencode( $test_id );
	}

	public static function purge_url( array $args ) {
		$url = isset( $args['url'] ) ? trim( (string) $args['url'] ) : '';
		if ( '' === $url ) {
			return new \WP_Error( 'xspeed_mcp_missing_url', __( 'The url argument is required.', 'xspeed' ), array( 'status' => 400 ) );
		}
		return Cli_Bridge::run( 'cache', array( 'purge-url', $url ), array( 'cause' => __( 'AI assistant', 'xspeed' ) ) );
	}

	/**
	 * Probe the configured object-cache backend (connect + read/write).
	 *
	 * @param array $args Unused.
	 * @return array|\WP_Error
	 */
	public static function test_object_cache( array $args ) {
		unset( $args );
		return Cli_Bridge::run( 'objcache', array( 'test' ) );
	}

	/**
	 * Verify the saved Cloudflare credentials.
	 *
	 * @param array $args Unused.
	 * @return array|\WP_Error
	 */
	public static function cloudflare_verify( array $args ) {
		unset( $args );
		return Cli_Bridge::run( 'cf', array( 'verify' ) );
	}

	/**
	 * Run an external audit on any install.
	 *
	 * Shares run_pagespeed's body: that handler ALREADY falls back to
	 * `xspeed score run` when the Pro `xspeed psi` command is absent, so the
	 * engine could always do this on Free — the tool was simply dropped from
	 * the catalog before anyone could call it. The only thing missing was a
	 * name that survives on a Free install. (#147)
	 *
	 * @param array $args target / strategy / provider.
	 * @return array|\WP_Error
	 */
	public static function run_score( array $args ) {
		// `target` is the CLI's name for it (--url is a reserved WP-CLI global,
		// so the score command deliberately uses --target). Accept both here
		// and normalise, so an assistant that guessed `url` still works.
		if ( ! empty( $args['target'] ) && empty( $args['url'] ) ) {
			$args['url'] = (string) $args['target'];
		}
		return self::run_pagespeed( $args );
	}

	/**
	 * Run an external performance audit. Prefers the Pro engine when present,
	 * otherwise drives Free's own score command.
	 *
	 * @param array $args { url?:string, strategy?:string, provider?:string, force?:bool }.
	 * @return array|\WP_Error
	 */
	public static function run_pagespeed( array $args ) {
		$options = array();
		if ( ! empty( $args['url'] ) ) {
			$options['url'] = (string) $args['url'];
		}
		if ( ! empty( $args['strategy'] ) ) {
			$options['strategy'] = (string) $args['strategy'];
		}
		// Advertised in run_score's schema, and the Free score handler already
		// branches on it (ScoreModule::cli_handler reads $assoc['provider']),
		// so dropping it here meant a GTmetrix request ran a PSI audit and
		// reported ok:true — spending the wrong provider's quota with nothing
		// in the response to say so. (QA B1 on #162)
		if ( ! empty( $args['provider'] ) ) {
			$options['provider'] = (string) $args['provider'];
		}
		// Was reachable only via the generated xspeed_psi alias, which this
		// change removes — so it moves onto the typed tool rather than being
		// lost with it.
		if ( ! empty( $args['force'] ) && filter_var( $args['force'], FILTER_VALIDATE_BOOLEAN ) ) {
			$options['force'] = true;
		}

		/*
		 * Prefer the richer Pro engine when it's installed; otherwise drive
		 * Free's own score command. Same tool name either way — an assistant
		 * asking for a PageSpeed audit shouldn't have to know which tier the
		 * site runs, and the two write to the same run history.
		 *
		 * EXCEPT when a provider was named that the Pro engine cannot serve.
		 * `xspeed psi` is PageSpeed-only: it declares no --provider and
		 * discards the option, so preferring it purely because it exists made
		 * `provider: "gtmetrix"` run PSI and answer ok:true — the same silent
		 * wrong-provider bug this tool just fixed on Free, reappearing only on
		 * Pro. A site that configures GTmetrix would have stopped getting it
		 * the moment Pro activated. Free's `score` command reads $assoc
		 * ['provider'] and branches, so route there instead. (QA R1 on #162)
		 */
		$wants_non_psi = isset( $options['provider'] ) && 'psi' !== strtolower( (string) $options['provider'] );
		if ( isset( Cli_Bridge::commands()['xspeed psi'] ) && ! $wants_non_psi ) {
			return Cli_Bridge::run( 'psi', array(), $options );
		}

		// The Free `score` command reads --target, not --url: `url` is a
		// reserved WP-CLI global, so a value passed as `url` never reaches the
		// handler and the requested page is silently ignored in favour of the
		// default. Translate rather than passing it through. (#147)
		if ( isset( $options['url'] ) ) {
			$options['target'] = $options['url'];
			unset( $options['url'] );
		}
		return Cli_Bridge::run( 'score', array( 'run' ), $options );
	}

	/**
	 * Generate Critical CSS (Pro).
	 *
	 * @param array $args Unused.
	 * @return array|\WP_Error
	 */
	public static function generate_critical_css( array $args ) {
		unset( $args );
		return Cli_Bridge::run( 'ccss', array( 'generate' ) );
	}

	/**
	 * Build a JSON Schema object node.
	 *
	 * @param array    $properties Property map.
	 * @param string[] $required   Required property names.
	 */
	private static function object_schema( array $properties, array $required ): array {
		$schema = array(
			'type'       => 'object',
			'properties' => (object) $properties,
		);
		if ( ! empty( $required ) ) {
			$schema['required'] = array_values( $required );
		}
		return $schema;
	}
}
