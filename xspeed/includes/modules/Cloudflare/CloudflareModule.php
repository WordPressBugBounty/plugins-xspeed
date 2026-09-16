<?php
/**
 * Cloudflare module — connect a CF zone for purge + dev-mode toggles.
 *
 * Free tier (this module): API token / global key auth, zone
 * verification, manual purge, auto purge on xSpeed's own purge, dev
 * mode toggle.
 *
 * Pro tier (xspeed-pro): APO toggle, edge cache rules, edge cache TTL.
 * Per FEATURES.md "Cloudflare Integration" §8-10.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Cloudflare;

defined( 'ABSPATH' ) || exit;

use XSpeed\Cloudflare;
use XSpeed\Module;

final class CloudflareModule extends Module {

	public const SLUG    = 'cloudflare';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.1.0';

	/**
	 * Where the last connection-health result is cached: the outcome of the
	 * most recent verify (token + zone reachable) or purge (Cache-Purge
	 * permission actually works). Read by ui_notices() to show a persistent
	 * warning when Cloudflare is silently failing. (#119)
	 */
	private const HEALTH_OPTION = 'xspeed_cloudflare_health';

	/** Cron event that does the edge call for a batch of purged URLs. */
	private const PURGE_URLS_EVENT = 'xspeed_cloudflare_purge_urls';

	/** Cron event for the zone-wide fallback when a batch is too large. */
	private const PURGE_ALL_EVENT = 'xspeed_cloudflare_purge_edge_all';

	/**
	 * Above this many URLs, purge the zone instead of naming every page.
	 *
	 * The batch travels as the cron event's ARGUMENT, and the cron table is
	 * an autoloaded option, so an unbounded batch is an unbounded payload in
	 * `alloptions` for as long as the event is pending. A bulk product
	 * import, or an `xspeed_purge_product_urls` filter that expands to a few
	 * hundred URLs, is enough. Past the threshold the zone purge is one call
	 * with no payload, and it is what the site would have got from
	 * `purge_all()` anyway.
	 */
	private const MAX_DEFERRED_URLS = 100;

	/**
	 * URLs purged this request, awaiting a batched call at shutdown.
	 *
	 * Keyed blog id => URL => true. By URL so the same page arriving twice —
	 * a post and the archive that lists it can resolve to the same address —
	 * is sent once. By BLOG because one module instance serves the whole
	 * process: a `Cache::purge_url()` raised inside `switch_to_blog()` would
	 * otherwise land in a batch sent against whatever blog happened to be
	 * current at shutdown, merging several sites' URLs into one zone with one
	 * site's token, and writing the health record and activity log to the
	 * wrong site too. Nothing does that today — Pro's network purge goes
	 * through `purge_all()` — but the re-entry guard in Cache anticipates a
	 * network purge that loops blogs in one request.
	 *
	 * @var array<int,array<string,true>>
	 */
	private array $pending_edge_urls = array();

	public function ui_metadata(): array {
		return array(
			'label'        => __( 'Cloudflare', 'xspeed' ),
			'icon'         => 'Cloud',
			'description'  => __( 'Connect a Cloudflare zone for automatic edge purging when xSpeed clears its cache, plus a dev-mode toggle.', 'xspeed' ),
			'custom_panel' => 'CloudflarePanel',
		);
	}

	/**
	 * @inheritDoc
	 *
	 * Nothing exempt. It is inert without Cloudflare credentials, and where
	 * credentials exist the user set them up for a CDN rather than for the page
	 * cache we stood down from — but "inert today" is a weak reason to leave a
	 * switch on that nobody asked for, and on a site where the host DID take
	 * the page cache it is not inert at all.
	 */
	public function conflict_safe_exempt(): array {
		return array();
	}

	public function settings_schema(): array {
		return array(
			'enabled' => array(
				'type'        => 'bool',
				'default'     => false,
				'label'       => __( 'Enable Cloudflare integration', 'xspeed' ),
				'description' => __( 'Use the credentials below to verify your zone and run purges.', 'xspeed' ),
			),
			'auth_method' => array(
				'type'          => 'enum',
				'default'       => 'token',
				'options'       => array( 'token', 'key' ),
				'option_labels' => array(
					'token' => 'API Token',
					'key'   => 'Global API Key',
				),
				'label'         => __( 'Authentication', 'xspeed' ),
				'description'   => __( 'API Tokens (scoped, recommended) or the legacy Global API Key with your account email.', 'xspeed' ),
				'dependsOn'     => array( 'field' => 'enabled' ),
			),
			'api_token' => array(
				'type'        => 'secret',
				'default'     => '',
				'label'       => __( 'API Token', 'xspeed' ),
				'description' => __( 'Create a token at dash.cloudflare.com → My Profile → API Tokens. Needs "Zone → Cache Purge" + "Zone Settings" permissions.', 'xspeed' ),
				// Only the token auth branch (and only while CF is enabled, via
				// the transitive gate on auth_method → enabled).
				'dependsOn'   => array( 'field' => 'auth_method', 'value' => 'token' ),
			),
			'email' => array(
				'type'        => 'string',
				'default'     => '',
				'label'       => __( 'Account Email', 'xspeed' ),
				'description' => __( 'Only used when Authentication is set to Global API Key.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'auth_method', 'value' => 'key' ),
			),
			'api_key' => array(
				'type'        => 'secret',
				'default'     => '',
				'label'       => __( 'Global API Key', 'xspeed' ),
				'description' => __( 'Found at dash.cloudflare.com → My Profile → API Tokens → Global API Key.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'auth_method', 'value' => 'key' ),
			),
			'zone_id' => array(
				'type'        => 'string',
				'default'     => '',
				'label'       => __( 'Zone ID', 'xspeed' ),
				'description' => __( 'The 32-character hex Zone ID from your domain overview page.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
			'auto_purge_on_update' => array(
				'type'        => 'bool',
				'default'     => true,
				'label'       => __( 'Auto-purge Cloudflare on xSpeed purge', 'xspeed' ),
				'description' => __( 'When xSpeed clears its own cache (post save, settings change, manual purge), trigger a Cloudflare purge too.', 'xspeed' ),
				'dependsOn'   => array( 'field' => 'enabled' ),
			),
		);
	}

	/**
	 * Encrypt the pre-1.1.0 plaintext credentials on upgrade. api_token /
	 * api_key became `secret`-typed fields (encrypted at rest); this converts
	 * any already-stored plaintext in one pass. Idempotent — encrypt_for_storage
	 * skips a value that already carries the cipher marker. (#115)
	 */
	public function migrations(): array {
		return array(
			'1.1.0' => static function ( array $opts ): array {
				foreach ( array( 'api_token', 'api_key' ) as $key ) {
					if ( isset( $opts[ $key ] ) && is_string( $opts[ $key ] ) && '' !== $opts[ $key ] ) {
						$opts[ $key ] = \XSpeed\Settings_Manager::encrypt_for_storage( $opts[ $key ] );
					}
				}
				return $opts;
			},
		);
	}

	public function rest_routes(): array {
		$default = parent::rest_routes();
		return array_merge(
			$default,
			array(
				array(
					'path'     => '/verify',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_verify' ),
				),
				array(
					'path'     => '/purge',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_purge' ),
				),
				array(
					'path'     => '/dev-mode',
					'methods'  => 'POST',
					'callback' => array( $this, 'rest_dev_mode' ),
				),
			)
		);
	}

	public function conflicts(): array {
		return array(
			array(
				'plugin'   => 'cloudflare/cloudflare.php',
				'feature'  => 'cloudflare.purge',
				'strategy' => \XSpeed\Conflict_Registry::STRATEGY_WARN,
				'reason'   => 'The official Cloudflare plugin also auto-purges; keep auto-purge enabled in only one to avoid double API calls.',
			),
		);
	}

	public function boot(): void {
		/*
		 * Deferred to `init`. This module reads its own settings to decide
		 * what to hook, and reading settings builds settings_schema(), whose
		 * labels are declared through __(). boot() runs on `plugins_loaded`,
		 * before `after_setup_theme` — the point WordPress 6.7+ treats as the
		 * earliest safe moment to translate — so doing that here fires
		 * _load_textdomain_just_in_time on every request AND resolves the
		 * labels against a domain that is not loaded yet.
		 *
		 * Everything below hooks actions that fire after `init`, so running
		 * one hook later is equivalent.
		 */
		add_action( 'init', array( $this, 'boot_on_init' ) );
	}

	/**
	 * Leave no queued edge calls behind.
	 *
	 * A batch scheduled seconds before the module was switched off would
	 * otherwise fire against a zone the site no longer manages, and the
	 * event would sit in the cron table with no listener after that.
	 */
	public function deactivate(): void {
		// `wp_unschedule_hook()`, not `wp_clear_scheduled_hook()`. The latter
		// keys on `md5( serialize( $args ) )` and defaults `$args` to an
		// empty array, so it only ever clears the no-arguments key. Every
		// event this module schedules carries the URL batch as its argument,
		// so clear_scheduled_hook cleared nothing at all here.
		wp_unschedule_hook( self::PURGE_URLS_EVENT );
		wp_unschedule_hook( self::PURGE_ALL_EVENT );
	}

	/**
	 * The real boot body — see boot() for why it runs on `init`.
	 */
	public function boot_on_init(): void {
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) ) {
			return;
		}
		if ( ! empty( $opts['auto_purge_on_update'] ) ) {
			// xSpeed fires this action whenever it purges its own
			// cache (see Cache::purge_all). Listening here keeps
			// CF in sync without any new wiring elsewhere.
			add_action( 'xspeed_after_purge_all', array( $this, 'on_xspeed_purge' ), 10, 0 );
			add_action( 'xspeed_after_purge_url', array( $this, 'on_xspeed_purge_url' ), 10, 1 );
			add_action( self::PURGE_URLS_EVENT, array( $this, 'purge_edge_urls' ), 10, 1 );
			add_action( self::PURGE_ALL_EVENT, array( $this, 'purge_edge_all' ), 10, 0 );
		}
	}

	public function on_xspeed_purge(): void {
		/*
		 * `wp xspeed purge` purges the edge itself, as its own reported line
		 * item, and the page step it runs first fires this action. Without
		 * this guard the zone is purged twice per command, and the SECOND
		 * call's outcome — the one nobody reported — is what lands in the
		 * health record the panel reads.
		 *
		 * Gated on covers(), not merely is_running(): on `--type=page` the
		 * action still fires but no edge target runs, so standing down there
		 * would leave the zone stale with nothing in the report to say so.
		 * That run is exactly the one this listener exists for.
		 */
		if ( class_exists( '\\XSpeed\\Purge_Runner' ) && \XSpeed\Purge_Runner::covers( 'cloudflare' ) ) {
			return;
		}
		if ( true !== $this->can_purge_edge() ) {
			return;
		}
		$this->purge_edge( 'auto-purge' );
	}

	/**
	 * Mirror a single-URL purge at the edge.
	 *
	 * NOT about post edits — `on_save_post()` calls `purge_all()`, so those
	 * have always reached Cloudflare through the full-purge listener above.
	 * What reaches `purge_url()` is the narrower set: the two admin purge
	 * buttons, an approved comment, a user change, a WooCommerce product or
	 * stock change, `--url` on the CLI and REST, and MCP. Every one of those
	 * cleared xSpeed's copy and left Cloudflare's, so the page stayed stale
	 * at the edge until its lifetime ran out or somebody pressed Purge All —
	 * which is a whole-zone purge to fix one page.
	 *
	 * Single-file purge is also the cheap call, which is the opposite of how
	 * it looks. Cloudflare's tightest documented purge limit is the one on
	 * purge-everything, hostname, tag and prefix; file purges are metered
	 * separately and far more generously. The `purge_all` listener above is
	 * the one near a limit, not this.
	 *
	 * @param array<string,mixed> $context The event payload. See the
	 *                                     `xspeed_after_purge_url` docblock.
	 */
	public function on_xspeed_purge_url( $context ): void {
		if ( ! is_array( $context ) || 'urls' !== ( $context['scope'] ?? '' ) ) {
			return;
		}
		$urls = array_filter( array_map( 'strval', (array) ( $context['urls'] ?? array() ) ) );
		if ( array() === $urls ) {
			return;
		}
		// Same guard as the full-purge listener: `wp xspeed purge` reports
		// the edge as its own line item, and purging here as well would make
		// the outcome nobody reported the one that lands in the health record.
		if ( class_exists( '\\XSpeed\\Purge_Runner' ) && \XSpeed\Purge_Runner::covers( 'cloudflare' ) ) {
			return;
		}
		if ( true !== $this->can_purge_edge() ) {
			return;
		}

		// Collected and sent once, not one API call per URL. `Purge_Ui`'s
		// post purge and the WooCommerce product path both fire a handful of
		// these in a loop, and a round trip each would be a wait each.
		if ( array() === $this->pending_edge_urls ) {
			add_action( 'shutdown', array( $this, 'flush_edge_url_purges' ), 20 );
		}
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		foreach ( $urls as $url ) {
			$this->pending_edge_urls[ $blog ][ $url ] = true;
		}
	}

	/**
	 * Hand whatever `on_xspeed_purge_url()` collected to cron.
	 *
	 * Three of the callers are ordinary visitor traffic — an approved
	 * comment, a user registration, a WooCommerce stock change during
	 * checkout — and none of them made an outbound request before this
	 * listener existed. Doing the HTTPS inline would put a blocking round
	 * trip to Cloudflare on the end of a shopper's checkout, once per
	 * request, with the timeout as the worst case. So the batch is scheduled
	 * and the request ends.
	 *
	 * Inline when there is nothing to defer to: cron cannot defer to itself,
	 * and a CLI run exits before a spawned cron request would be served.
	 * Both are contexts where a blocking call is the right answer anyway.
	 *
	 * Deliberately not what WP Rocket does — its Cloudflare add-on calls
	 * `purge_files()` straight from `after_rocket_clean_post`, so a visitor
	 * leaving a comment waits on Cloudflare. LiteSpeed sidesteps it by never
	 * purging Cloudflare per URL at all. Deferring is the same thing
	 * `Preloader` and `Cookie_Inspector` already do here for the same
	 * reason: outbound HTTP belongs in a later request, not on the one that
	 * happened to trigger it.
	 *
	 * Three ways a batch can still be lost, all silent because the health
	 * record is only written inside the flush: a PHP fatal (WordPress's own
	 * fatal handler is registered before `shutdown_action_hook` and ends the
	 * process first), another plugin calling `exit` from a `shutdown`
	 * callback at a priority below 20, and a `purge_url()` raised during
	 * `shutdown` ABOVE priority 20, which re-arms a hook that has already
	 * dispatched. Rare, but this is the note that saves the next person
	 * debugging "the edge kept a stale page" from rediscovering them.
	 *
	 * Public because it is a `shutdown` callback; not part of the module's
	 * contract.
	 */
	public function flush_edge_url_purges(): void {
		$batches                 = $this->pending_edge_urls;
		$this->pending_edge_urls = array();
		$current                 = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		foreach ( $batches as $blog => $keyed ) {
			$urls = array_keys( $keyed );
			if ( array() === $urls ) {
				continue;
			}
			// Each batch is scheduled and sent as the site that raised it,
			// because the cron table, the settings, the health record and the
			// activity log are all per-site.
			$switched = (int) $blog !== $current && function_exists( 'switch_to_blog' );
			if ( $switched ) {
				switch_to_blog( (int) $blog );
			}
			try {
				$this->dispatch_edge_url_batch( $urls );
			} finally {
				// A throwing adapter must not leave the rest of shutdown
				// running as the wrong site.
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/** Schedule one site's batch, or send it now where there is nothing to defer to. */
	private function dispatch_edge_url_batch( array $urls ): void {
		// Too many to name. Purge the zone instead of carrying every URL in
		// an autoloaded option, and say so, because a zone purge costs more
		// origin traffic than the page purges it replaces and nobody should
		// have to infer that it happened.
		if ( count( $urls ) > self::max_deferred_urls() ) {
			$this->dispatch_edge_purge_all( count( $urls ) );
			return;
		}

		if ( ! self::must_purge_inline() && function_exists( 'wp_schedule_single_event' ) ) {
			// `$wp_error = true`, because the bare form returns false for two
			// opposite situations and only one of them is a failure.
			//
			// Scheduling at `time()` puts the timestamp in the past by the
			// time core compares it, which sets core's `$min_timestamp` to 0
			// (wp-includes/cron.php) — so ANY identical event anywhere in the
			// cron table, however old, counts as a duplicate and the call
			// returns false. Two comments on the same post produce
			// byte-identical args, so the second one would have taken the
			// inline fallback: a blocking call to Cloudflare on a visitor's
			// request, which is the exact thing this deferral exists to
			// avoid, while the already-queued event fired anyway and sent
			// the batch twice.
			//
			// A duplicate means the work is already queued. That is success.
			$scheduled = wp_schedule_single_event( time(), self::PURGE_URLS_EVENT, array( $urls ), true );
			if ( true === $scheduled ) {
				return;
			}
			if ( is_wp_error( $scheduled ) && 'duplicate_event' === $scheduled->get_error_code() ) {
				return;
			}
			// Anything else — a filter vetoing the event, a broken cron
			// table — is a real refusal, and dropping the purge silently
			// would leave the edge stale with nothing to say so.
		}

		$this->purge_edge_urls( $urls );
	}

	/** Is this a context with no later request to defer the edge call to? */
	private static function must_purge_inline(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		return function_exists( 'wp_doing_cron' ) && wp_doing_cron();
	}

	/**
	 * How many URLs may ride along in a deferred batch.
	 *
	 * Filterable because the right answer depends on how long a site's cron
	 * backlog sits: the cost is the payload's time in `alloptions`, not the
	 * URL count itself.
	 */
	private static function max_deferred_urls(): int {
		if ( ! function_exists( 'apply_filters' ) ) {
			return self::MAX_DEFERRED_URLS;
		}

		/**
		 * Filter the batch size above which a zone purge replaces named URLs.
		 *
		 * @param int $max URLs per deferred batch.
		 */
		$max = (int) apply_filters( 'xspeed_cloudflare_max_deferred_purge_urls', self::MAX_DEFERRED_URLS );

		// A filter of zero would send every single-page purge to the zone.
		return $max > 0 ? $max : self::MAX_DEFERRED_URLS;
	}

	/** Queue the zone-wide fallback, or run it now where cron cannot. */
	private function dispatch_edge_purge_all( int $url_count ): void {
		if ( class_exists( '\\XSpeed\\Activity_Log' ) ) {
			\XSpeed\Activity_Log::record(
				'cache_purged',
				sprintf(
					/* translators: %d: number of URLs that changed at once. */
					__( 'Purging the whole Cloudflare zone: %d URLs changed at once, too many to purge individually', 'xspeed' ),
					$url_count
				)
			);
		}

		if ( ! self::must_purge_inline() && function_exists( 'wp_schedule_single_event' ) ) {
			// No arguments, so every oversized batch in a request collapses
			// onto one event. `duplicate_event` is the wanted outcome here,
			// not a failure.
			$scheduled = wp_schedule_single_event( time(), self::PURGE_ALL_EVENT, array(), true );
			if ( true === $scheduled ) {
				return;
			}
			if ( is_wp_error( $scheduled ) && 'duplicate_event' === $scheduled->get_error_code() ) {
				return;
			}
		}

		$this->purge_edge_all();
	}

	/**
	 * Purge the whole zone, as the fallback for an oversized batch.
	 *
	 * Public because it is the `PURGE_ALL_EVENT` cron callback. Re-checks the
	 * connection for the same reason the URL batch does: this runs in a later
	 * request than the one that queued it.
	 */
	public function purge_edge_all(): void {
		if ( true !== $this->can_purge_edge() ) {
			return;
		}
		$this->purge_edge( 'auto-purge' );
	}

	/**
	 * Purge a batch of URLs at the edge and record the outcome.
	 *
	 * Public because it is the `PURGE_URLS_EVENT` cron callback.
	 *
	 * @param string[] $urls
	 */
	public function purge_edge_urls( $urls ): void {
		$urls = array_values( array_filter( array_map( 'strval', (array) $urls ) ) );
		if ( array() === $urls ) {
			return;
		}
		// Re-checked here rather than trusted from collect time: a scheduled
		// batch runs in a later request, and the credentials or the switch
		// may have changed between the two.
		if ( true !== $this->can_purge_edge() ) {
			// Said out loud, because otherwise "the credentials were removed
			// between queueing and running" and "the purge succeeded" look
			// identical from the panel, and the pages stay stale at the edge
			// either way.
			if ( class_exists( '\\XSpeed\\Activity_Log' ) ) {
				\XSpeed\Activity_Log::record(
					'cache_purge_skipped',
					sprintf(
						/* translators: %d: number of URLs. */
						_n(
							'Skipped a queued Cloudflare purge of %d URL: the connection is no longer available',
							'Skipped a queued Cloudflare purge of %d URLs: the connection is no longer available',
							count( $urls ),
							'xspeed'
						),
						count( $urls )
					),
					\XSpeed\Activity_Log::WARN
				);
			}
			return;
		}

		$result = Cloudflare::purge_urls( $this->get_settings(), $urls );
		$ok     = ! empty( $result['ok'] );
		$reason = $ok ? '' : $this->message_of( $result );

		// Recorded for the same reason the full purge is: a token that passes
		// verify can still lack "Zone → Cache Purge", and a silent auth
		// failure here means stale pages at the edge with nothing to say so.
		$this->record_health( $ok, 'purge', $reason );

		if ( ! class_exists( '\\XSpeed\\Activity_Log' ) ) {
			return;
		}
		if ( $ok ) {
			\XSpeed\Activity_Log::record(
				'cache_purged',
				sprintf(
					/* translators: %d: number of URLs purged. */
					_n(
						'Purged %d URL from the Cloudflare edge cache',
						'Purged %d URLs from the Cloudflare edge cache',
						count( $urls ),
						'xspeed'
					),
					count( $urls )
				),
				\XSpeed\Activity_Log::INFO
			);
			return;
		}
		\XSpeed\Activity_Log::record(
			'cloudflare_purge_failed',
			sprintf(
				/* translators: 1: number of URLs, 2: failure reason. */
				__( 'Cloudflare URL purge failed (%1$d URL(s)): %2$s', 'xspeed' ),
				count( $urls ),
				$reason ? $reason : __( 'unknown error', 'xspeed' )
			),
			\XSpeed\Activity_Log::WARN
		);
	}

	/**
	 * Whether this site can purge its Cloudflare zone right now.
	 *
	 * @return true|string True, or the reason it cannot — for the skip line
	 *                     in `wp xspeed purge`, which has to explain itself
	 *                     rather than silently do nothing.
	 */
	public function can_purge_edge() {
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) ) {
			return __( 'the Cloudflare integration is switched off', 'xspeed' );
		}
		if ( ! $this->has_credentials( $opts ) ) {
			return __( 'no zone ID or API credentials are configured', 'xspeed' );
		}

		return true;
	}

	/**
	 * Purge the whole zone and record the outcome.
	 *
	 * The one edge-purge path: the auto-purge listener, `wp xspeed cf purge`
	 * and `wp xspeed purge` all land here, so the health record and the
	 * activity log say the same thing whichever one ran.
	 *
	 * @param string $cause Who asked.
	 * @return array{ok:bool,reason:string,status:int,body:mixed} The engine
	 *               result plus a normalised `reason`, so the `cf` command can
	 *               still print the raw body it always has.
	 */
	public function purge_edge( string $cause = 'manual' ): array {
		$result = Cloudflare::purge_all( $this->get_settings() );
		$ok     = ! empty( $result['ok'] );
		$reason = $ok ? '' : $this->message_of( $result );

		// A GET /zones verify can pass with a token that still lacks the
		// "Zone → Cache Purge" permission, so the real purge is the only
		// authoritative signal for purge capability. Record it either way so
		// a silent auth failure becomes a visible, unresolved warning on the
		// module rather than an entry buried in the activity log. (#119)
		$this->record_health( $ok, 'purge', $reason );

		if ( class_exists( '\\XSpeed\\Activity_Log' ) ) {
			if ( $ok ) {
				\XSpeed\Activity_Log::record(
					'cache_purged',
					sprintf(
						/* translators: %s: what asked for the purge. */
						__( 'Purged the Cloudflare edge cache (%s)', 'xspeed' ),
						$cause
					),
					\XSpeed\Activity_Log::INFO
				);
			} else {
				\XSpeed\Activity_Log::record(
					'cloudflare_purge_failed',
					sprintf(
						/* translators: 1: what asked for the purge, 2: failure reason. */
						__( 'Cloudflare purge failed (%1$s): %2$s', 'xspeed' ),
						$cause,
						$reason ? $reason : __( 'unknown error', 'xspeed' )
					),
					\XSpeed\Activity_Log::WARN
				);
			}
		}

		return array(
			'ok'     => $ok,
			'reason' => $reason,
			'status' => (int) ( $result['status'] ?? 0 ),
			'body'   => $result['body'] ?? array(),
		);
	}

	/**
	 * Persist any settings sent with the save, then verify the credentials
	 * immediately so an invalid or newly-changed token surfaces on the panel
	 * instead of failing silently the next time xSpeed purges. Response shape
	 * is unchanged (flat settings) so the autosave client is unaffected. (#119)
	 */
	public function rest_update_settings( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$settings = $this->update_settings( is_array( $params ) ? $params : array() );
		$this->verify_and_record();
		return rest_ensure_response( $settings );
	}

	public function rest_verify( \WP_REST_Request $request ) {
		$res = Cloudflare::verify( $this->get_settings() );
		$this->record_health( ! empty( $res['ok'] ), 'verify', $this->message_of( $res ) );
		return rest_ensure_response( $res );
	}

	public function rest_purge( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$opts = $this->get_settings();
		if ( isset( $params['urls'] ) && is_array( $params['urls'] ) && ! empty( $params['urls'] ) ) {
			return rest_ensure_response( Cloudflare::purge_urls( $opts, $params['urls'] ) );
		}
		return rest_ensure_response( Cloudflare::purge_all( $opts ) );
	}

	public function rest_dev_mode( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$on     = ! empty( $params['on'] );
		return rest_ensure_response( Cloudflare::set_dev_mode( $this->get_settings(), $on ) );
	}

	/**
	 * Persistent callouts on the Cloudflare panel: a hard warning when the
	 * connection is enabled but silently failing (bad token, or a purge that
	 * was rejected for lack of the Cache-Purge permission), and a soft warning
	 * when it's enabled but not fully configured yet. (#119)
	 */
	public function ui_notices(): array {
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) ) {
			return array();
		}
		if ( ! $this->has_credentials( $opts ) ) {
			return array(
				array(
					'tone'  => 'warn',
					'title' => __( 'Cloudflare is not fully configured.', 'xspeed' ),
					'body'  => __( 'Add your API token (or Global API Key + account email) and the Zone ID, then press Verify. Until then auto-purge does nothing.', 'xspeed' ),
				),
			);
		}
		$health = get_option( self::HEALTH_OPTION, null );
		if ( is_array( $health ) && array_key_exists( 'ok', $health ) && false === $health['ok'] ) {
			$context = isset( $health['context'] ) ? (string) $health['context'] : 'verify';
			$message = isset( $health['message'] ) ? (string) $health['message'] : '';
			$suffix  = '' !== $message ? ': ' . $message : '';
			if ( 'purge' === $context ) {
				return array(
					array(
						'tone'  => 'danger',
						'title' => __( 'Cloudflare purge is failing.', 'xspeed' ),
						'body'  => sprintf(
							/* translators: %s: the Cloudflare API error message, or empty. */
							__( 'The last edge purge was rejected by Cloudflare%s. Confirm the API token includes the "Zone → Cache Purge" permission for this zone — a token that can read the zone can still lack purge rights.', 'xspeed' ),
							$suffix
						),
					),
				);
			}
			return array(
				array(
					'tone'  => 'danger',
					'title' => __( 'Cloudflare credentials were rejected.', 'xspeed' ),
					'body'  => sprintf(
						/* translators: %s: the Cloudflare API error message, or empty. */
						__( 'The saved credentials could not verify this zone%s. Auto-purge will not work until this is fixed.', 'xspeed' ),
						$suffix
					),
				),
			);
		}
		return array();
	}

	/** Verify the current credentials and cache the outcome (save-time hook). */
	private function verify_and_record(): void {
		$opts = $this->get_settings();
		if ( empty( $opts['enabled'] ) || ! $this->has_credentials( $opts ) ) {
			// Nothing to verify — drop any stale health so an old failure notice
			// doesn't linger after the user disables or clears the integration.
			delete_option( self::HEALTH_OPTION );
			return;
		}
		$res = Cloudflare::verify( $opts );
		$this->record_health( ! empty( $res['ok'] ), 'verify', $this->message_of( $res ) );
	}

	/** Cache the last verify/purge outcome for ui_notices(). */
	private function record_health( bool $ok, string $context, string $message ): void {
		update_option(
			self::HEALTH_OPTION,
			array(
				'ok'         => $ok,
				'context'    => $context,
				'message'    => $message,
				'checked_at' => time(),
			),
			false
		);
	}

	/** Whether the current auth branch has all the fields it needs. */
	private function has_credentials( array $opts ): bool {
		if ( empty( $opts['zone_id'] ) ) {
			return false;
		}
		$method = isset( $opts['auth_method'] ) ? (string) $opts['auth_method'] : 'token';
		if ( 'key' === $method ) {
			return ! empty( $opts['api_key'] ) && ! empty( $opts['email'] );
		}
		return ! empty( $opts['api_token'] );
	}

	/** Human-readable failure reason from a Cloudflare engine result. */
	private function message_of( array $res ): string {
		if ( ! empty( $res['ok'] ) ) {
			return '';
		}
		$body = isset( $res['body'] ) && is_array( $res['body'] ) ? $res['body'] : array();
		if ( ! empty( $body['message'] ) ) {
			return (string) $body['message'];
		}
		if ( ! empty( $body['errors'][0]['message'] ) ) {
			return (string) $body['errors'][0]['message'];
		}
		return 'HTTP ' . ( isset( $res['status'] ) ? (string) $res['status'] : '0' );
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed cf',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Cloudflare verify / purge / dev-mode helpers.',
				'ai_hint'   => 'Cloudflare operations: verify the API credentials work, purge the edge cache, or toggle development mode. Use when a change is live on the origin but visitors still see the old version — that is usually the edge, not the local cache.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'verify', 'purge', 'dev-on', 'dev-off' ),
						'optional' => false,
					),
				),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$opts   = $this->get_settings();
		$action = $args[0] ?? 'verify';
		switch ( $action ) {
			case 'verify':
				$res = Cloudflare::verify( $opts );
				break;
			case 'purge':
				// Through purge_edge() so a CLI purge records the same health
				// and activity-log entries as an auto-purge or `wp xspeed
				// purge`. Calling the engine directly left the panel's health
				// record showing whatever the last NON-CLI call found.
				$res = $this->purge_edge( 'CLI' );
				break;
			case 'dev-on':
				$res = Cloudflare::set_dev_mode( $opts, true );
				break;
			case 'dev-off':
				$res = Cloudflare::set_dev_mode( $opts, false );
				break;
			default:
				\WP_CLI::error( "Unknown action: $action" );
				return;
		}
		\WP_CLI::log( 'HTTP ' . $res['status'] . ' — ' . ( $res['ok'] ? 'ok' : 'failed' ) );
		\WP_CLI::log( wp_json_encode( $res['body'] ) );

		// A failed call must exit non-zero, or the MCP bridge reports the
		// whole invocation as ok:true and an agent reads a rejected token
		// or an empty Zone ID as a successful verification.
		if ( empty( $res['ok'] ) ) {
			$detail = '';
			if ( is_array( $res['body'] ) && ! empty( $res['body']['message'] ) ) {
				$detail = ': ' . $res['body']['message'];
			}
			\WP_CLI::error( sprintf( '%s failed (HTTP %s)%s', $action, $res['status'], $detail ) );
		}
	}
}
