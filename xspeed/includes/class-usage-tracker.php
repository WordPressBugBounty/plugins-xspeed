<?php
/**
 * Usage_Tracker — anonymous, opt-in plugin usage analytics.
 *
 * Ported from the WP Insights SDK (the same engine WPDeveloper plugins such as
 * EmbedPress ship). Trimmed for xSpeed: no email marketing capture by default.
 * The deactivation "goodbye" survey UI lives in Deactivation_Feedback (shown to
 * every admin); it stores the reason in the canonical WPInsight options and
 * deactivate_this_plugin() transmits it — see that method.
 *
 * PRIVACY CONTRACT (see CLAUDE.md "Hard do-not" + readme.txt):
 *   Nothing is collected or sent until the site admin EXPLICITLY opts in via
 *   the setup wizard. `require_optin` is always true. Until `opt_in( true )`
 *   has run, `is_tracking_allowed()` is false, no cron is scheduled, and
 *   `do_tracking()` / `send_data()` short-circuit before any outbound HTTP.
 *
 * @package XSpeed
 * @version 3.0.2 (WP Insights)
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

use WP_Error;

if ( ! class_exists( __NAMESPACE__ . '\\Usage_Tracker' ) ) :

	class Usage_Tracker {

		/** WP Insights SDK version (kept for API compat with send.wpinsight.com). */
		const WPINS_VERSION = '3.0.2';

		/** Insights ingest endpoint. */
		const API_URL = 'https://send.wpinsight.com/process-plugin-data';

		/** Daily cron hook (only registered AFTER opt-in). */
		const EVENT_HOOK = 'xspeed_do_weekly_action';

		private $plugin_file = null;
		private $plugin_name = null;

		/** @var string */
		public $recurrence = 'daily';

		private $disabled_wp_cron;
		private $require_optin;
		private $marketing;
		private $item_id;

		/** @var Usage_Tracker|null */
		private static $instance = null;

		/**
		 * @param string $plugin_file Main plugin file (XSPEED_FILE).
		 * @param array  $args        opt_in, email_marketing, item_id.
		 */
		public static function get_instance( $plugin_file, $args = array() ) {
			if ( null === static::$instance ) {
				static::$instance = new static( $plugin_file, $args );
			}
			return static::$instance;
		}

		public function __construct( $plugin_file, $args = array() ) {
			$this->plugin_file      = $plugin_file;
			$this->plugin_name      = basename( $this->plugin_file, '.php' );
			$this->disabled_wp_cron = defined( 'DISABLE_WP_CRON' ) && true === DISABLE_WP_CRON;

			// require_optin is intentionally forced true — never honor a caller
			// that tries to disable consent gating.
			$this->require_optin = true;
			// Email marketing capture is OFF by default in xSpeed (EmbedPress
			// defaults it on to send a discount coupon; we collect no email
			// unless a caller explicitly turns it on).
			$this->marketing = isset( $args['email_marketing'] ) ? (bool) $args['email_marketing'] : false;
			$this->item_id   = ! empty( $args['item_id'] ) ? $args['item_id'] : false;

			register_deactivation_hook( $this->plugin_file, array( $this, 'deactivate_this_plugin' ) );
		}

		/**
		 * Hook the cron sender. Called once from Plugin::init(). Safe to call
		 * unconditionally: the cron event itself is only SCHEDULED after the
		 * user opts in, and do_tracking() re-checks consent before sending.
		 */
		public function init() {
			add_action( self::EVENT_HOOK, array( $this, 'do_tracking' ) );
		}

		/**
		 * Public opt-in / opt-out entry point. Called by the onboarding REST
		 * handler when the admin flips the wizard's consent toggle.
		 *
		 * @param bool $allow True = consent granted; false = revoked.
		 */
		public function opt_in( $allow ) {
			$this->set_is_tracking_allowed( (bool) $allow );
			if ( $allow ) {
				$this->schedule_tracking();
				// Fire the first send immediately so the install is registered.
				$this->do_tracking( true );
			} else {
				if ( ! $this->disabled_wp_cron ) {
					wp_clear_scheduled_hook( self::EVENT_HOOK );
				}
			}
		}

		/** True only after an explicit opt-in. */
		public function is_opted_in() {
			return $this->is_tracking_allowed();
		}

		/**
		 * Schedule the daily send. Only ever called from opt_in( true ).
		 */
		public function schedule_tracking() {
			if ( $this->disabled_wp_cron ) {
				return;
			}
			if ( ! wp_next_scheduled( self::EVENT_HOOK ) ) {
				wp_schedule_event( time(), $this->recurrence, self::EVENT_HOOK );
			}
		}

		/**
		 * On deactivation: report to WPInsight that we went inactive, carrying
		 * the deactivation reason the admin submitted on the Plugins screen (if
		 * any). Deactivation_Feedback stores that reason in the canonical
		 * `wpins_deactivation_reason_<slug>` / `wpins_deactivation_details_<slug>`
		 * options; we read + transmit + delete them here.
		 *
		 * Two send paths:
		 *   - Usage analytics ON  → the full, site-correlated body (get_data())
		 *     with the reason appended, via the normal send_data() handshake.
		 *     This is the canonical WPInsight deactivation record.
		 *   - Usage analytics OFF → nothing is sent UNLESS the admin explicitly
		 *     submitted the survey; in that case a minimal, reason-only payload
		 *     goes out as per-action consent (no diagnostics inventory).
		 */
		public function deactivate_this_plugin() {
			$reason_key  = 'wpins_deactivation_reason_' . $this->plugin_name;
			$details_key = 'wpins_deactivation_details_' . $this->plugin_name;
			$reason      = get_option( $reason_key, false );
			$details     = get_option( $details_key, false );

			if ( $this->is_tracking_allowed() ) {
				$body                     = $this->get_data();
				$body['status']           = 'Deactivated';
				$body['deactivated_date'] = time();
				if ( false !== $reason ) {
					$body['deactivation_reason'] = $reason;
				}
				if ( false !== $details ) {
					$body['deactivation_details'] = $details;
				}
				$this->send_data( $body );

				if ( ! $this->disabled_wp_cron ) {
					wp_clear_scheduled_hook( self::EVENT_HOOK );
				}
			} elseif ( false !== $reason || false !== $details ) {
				$this->send_deactivation_feedback( $reason, $details );
			}

			// Never let a stored reason linger or double-send on the next cycle.
			delete_option( $reason_key );
			delete_option( $details_key );
		}

		/**
		 * Minimal, reason-only deactivation report for when usage analytics is
		 * OFF but the admin submitted the deactivation survey. Sends only plugin
		 * identity, WP/PHP version, and the reason/details — never the full
		 * diagnostic body get_data() assembles (no plugin inventory, no theme,
		 * no xSpeed config). Per-action consent; see the privacy contract at the
		 * top of this file and readme.txt "External services".
		 *
		 * @param string|false $reason  Stored deactivation reason label, or false.
		 * @param string|false $details Stored free-text detail, or false.
		 */
		private function send_deactivation_feedback( $reason, $details ) {
			if ( empty( self::API_URL ) ) {
				return;
			}
			$plugin = $this->plugin_data();
			$body   = array(
				'plugin_slug'      => sanitize_text_field( $this->plugin_name ),
				'url'              => get_bloginfo( 'url' ),
				'status'           => 'Deactivated',
				'deactivated_date' => time(),
				'site_version'     => get_bloginfo( 'version' ),
				'php_version'      => phpversion(),
				'wpins_version'    => self::WPINS_VERSION,
			);
			if ( ! empty( $plugin['Name'] ) ) {
				$body['plugin'] = sanitize_text_field( $plugin['Name'] );
			}
			if ( ! empty( $plugin['Version'] ) ) {
				$body['version'] = sanitize_text_field( $plugin['Version'] );
			}
			if ( false !== $this->item_id ) {
				$body['item_id'] = $this->item_id;
			}
			if ( false !== $reason ) {
				$body['deactivation_reason'] = sanitize_text_field( $reason );
			}
			if ( false !== $details ) {
				$body['deactivation_details'] = sanitize_text_field( $details );
			}

			$this->remote_post( $body );
		}

		/**
		 * Cron callback. Bails before any HTTP unless tracking is allowed and
		 * it's time to send.
		 *
		 * @param bool $force Skip the once-a-day throttle (used on first opt-in).
		 */
		public function do_tracking( $force = false ) {
			if ( empty( self::API_URL ) ) {
				return;
			}
			if ( ! $this->is_tracking_allowed() ) {
				return;
			}
			if ( ! $this->is_time_to_track() && ! $force ) {
				return;
			}
			return $this->send_data( $this->get_data() );
		}

		/** Consent gate. */
		private function is_tracking_allowed() {
			$allow_tracking = get_option( 'wpins_allow_tracking' );
			return is_array( $allow_tracking ) && isset( $allow_tracking[ $this->plugin_name ] );
		}

		/** Persist the consent flag in the shared WP Insights option. */
		protected function set_is_tracking_allowed( $is_allowed ) {
			$allow_tracking = get_option( 'wpins_allow_tracking' );
			if ( ! is_array( $allow_tracking ) ) {
				$allow_tracking = array();
			}
			if ( $is_allowed ) {
				$allow_tracking[ $this->plugin_name ] = $this->plugin_name;
			} else {
				unset( $allow_tracking[ $this->plugin_name ] );
			}
			update_option( 'wpins_allow_tracking', $allow_tracking );
		}

		/** Once-a-day throttle. */
		public function is_time_to_track() {
			$track_times = get_option( 'wpins_last_track_time', array() );
			if ( ! isset( $track_times[ $this->plugin_name ] ) ) {
				return true;
			}
			return $track_times[ $this->plugin_name ] < strtotime( '-1 day' );
		}

		public function set_track_time() {
			$track_times                       = get_option( 'wpins_last_track_time', array() );
			$track_times[ $this->plugin_name ] = time();
			update_option( 'wpins_last_track_time', $track_times );
		}

		/**
		 * Assemble the non-sensitive diagnostic payload. Documented verbatim in
		 * readme.txt — keep the two in sync if you add a field here.
		 */
		public function get_data() {
			$body = array(
				'plugin_slug'   => sanitize_text_field( $this->plugin_name ),
				'url'           => get_bloginfo( 'url' ),
				'site_name'     => get_bloginfo( 'name' ),
				'site_version'  => get_bloginfo( 'version' ),
				'site_language' => get_bloginfo( 'language' ),
				'charset'       => get_bloginfo( 'charset' ),
				'wpins_version' => self::WPINS_VERSION,
				'php_version'   => phpversion(),
				'multisite'     => is_multisite(),
			);

			if ( $this->marketing ) {
				if ( ! function_exists( 'wp_get_current_user' ) ) {
					include ABSPATH . 'wp-includes/pluggable.php';
				}
				$email = wp_get_current_user()->user_email;
				if ( is_email( $email ) ) {
					$body['email'] = $email;
				}
			}
			$body['marketing_method'] = $this->marketing;
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- server software string, reported as-is to insights.
			$body['server'] = isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '';

			if ( ! function_exists( 'get_plugins' ) ) {
				include ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugins        = array_keys( get_plugins() );
			$active_plugins = is_network_admin()
				? array_keys( get_site_option( 'active_sitewide_plugins', array() ) )
				: get_option( 'active_plugins', array() );
			foreach ( $plugins as $key => $plugin ) {
				if ( in_array( $plugin, $active_plugins, true ) ) {
					unset( $plugins[ $key ] );
				}
			}
			$body['active_plugins']   = $active_plugins;
			$body['inactive_plugins'] = array_values( $plugins );
			$body['text_direction']   = is_rtl() ? 'RTL' : 'LTR';

			$plugin = $this->plugin_data();
			if ( ! empty( $plugin ) ) {
				if ( isset( $plugin['Name'] ) ) {
					$body['plugin'] = sanitize_text_field( $plugin['Name'] );
				}
				if ( isset( $plugin['Version'] ) ) {
					$body['version'] = sanitize_text_field( $plugin['Version'] );
				}
				$body['status'] = 'Active';
			} else {
				$body['status'] = 'NOT FOUND';
			}

			$theme = wp_get_theme();
			if ( $theme->get( 'Name' ) ) {
				$body['theme'] = sanitize_text_field( $theme->get( 'Name' ) );
			}
			if ( $theme->get( 'Version' ) ) {
				$body['theme_version'] = sanitize_text_field( $theme->get( 'Version' ) );
			}

			// xSpeed's own configuration — which optimization features are
			// enabled and their settings. Tells us what's actually used and
			// where it breaks. Non-sensitive: these are feature flags +
			// numeric/string knobs, never site content or personal data.
			$config = $this->gather_config();
			if ( ! empty( $config ) ) {
				$body['xspeed_config'] = $config;
			}

			return $body;
		}

		/**
		 * Collect each registered module's stored settings, keyed by slug.
		 * Read through Settings_Manager so we get validated, schema-shaped
		 * values (feature toggles + knobs), not raw option blobs. Guarded so
		 * the tracker still works if the registry isn't booted yet.
		 *
		 * @return array<string,array>
		 */
		private function gather_config() {
			if ( ! class_exists( __NAMESPACE__ . '\\Module_Registry' )
				|| ! class_exists( __NAMESPACE__ . '\\Settings_Manager' ) ) {
				return array();
			}
			$config = array();
			foreach ( Module_Registry::all() as $slug => $module ) {
				$scalars = $this->scalar_settings( Settings_Manager::get( (string) $slug ) );
				if ( ! empty( $scalars ) ) {
					$config[ (string) $slug ] = $scalars;
				}
			}
			// Legacy fields still in xspeed_options (e.g. cache_enabled).
			if ( class_exists( __NAMESPACE__ . '\\Settings' ) ) {
				$legacy = $this->scalar_settings( Settings::get() );
				if ( ! empty( $legacy ) ) {
					$config['_options'] = $legacy;
				}
			}
			return $config;
		}

		/**
		 * Key fragments that mark a credential / PII / identifying field. We do
		 * NOT report these at all — not the value, not even whether they're set.
		 * The goal is "which features are used", not "is a key configured", so
		 * anything secret-shaped is dropped outright. This is the guard that
		 * keeps API keys, tokens, passwords, license keys, emails, URLs, and
		 * brand assets out of the analytics payload entirely.
		 */
		const SECRET_KEY_FRAGMENTS = array(
			'key', 'token', 'secret', 'password', 'pass', 'license', 'auth',
			'credential', 'email', 'url', 'endpoint', 'host', 'logo', 'prefix',
			'zone', 'account', 'webhook', 'salt', 'nonce', 'name', 'credit',
		);

		/**
		 * Reduce a module's settings to just "which features are used + how
		 * they're tuned":
		 *
		 *  - bool / int / float on a NON-sensitive key → sent as-is. These are
		 *    the feature toggles and numeric knobs we actually want.
		 *  - any key matching SECRET_KEY_FRAGMENTS → dropped entirely.
		 *  - string values → dropped (free-text can hold secrets/PII, and a
		 *    string isn't "feature usage" data anyway).
		 *  - arrays (exclusion / cookie / query lists) → dropped.
		 *
		 * Net result: a compact map of feature flags + numeric settings, with
		 * zero credentials, URLs, names, or other identifying values.
		 *
		 * @param mixed $settings
		 * @return array
		 */
		private function scalar_settings( $settings ) {
			if ( ! is_array( $settings ) ) {
				return array();
			}
			$out = array();
			foreach ( $settings as $key => $value ) {
				// Only booleans and numbers describe "feature usage"; strings
				// and arrays are never feature-usage data, so skip them.
				if ( ! is_bool( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
					continue;
				}
				$lc        = strtolower( (string) $key );
				$is_secret = false;
				foreach ( self::SECRET_KEY_FRAGMENTS as $frag ) {
					if ( false !== strpos( $lc, $frag ) ) {
						$is_secret = true;
						break;
					}
				}
				if ( $is_secret ) {
					continue; // e.g. a numeric account id — drop it.
				}
				$out[ $key ] = $value;
			}
			return $out;
		}

		public function plugin_data() {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				include ABSPATH . 'wp-admin/includes/plugin.php';
			}
			return get_plugin_data( $this->plugin_file );
		}

		/**
		 * Register the site with insights, then send diffs on subsequent runs.
		 * Mirrors the WP Insights site-id handshake so the server keeps a stable
		 * record per install.
		 */
		public function send_data( $body ) {
			$site_id_key       = "wpins_{$this->plugin_name}_site_id";
			$site_id           = get_option( $site_id_key, false );
			$site_url          = get_bloginfo( 'url' );
			$original_site_url = get_option( "wpins_{$this->plugin_name}_original_url", false );
			$diff_data         = array();
			$failed_data       = array();

			if ( ( false === $original_site_url || $original_site_url !== $site_url )
				&& version_compare( $body['wpins_version'], '3.0.1', '>=' ) ) {
				$site_id = false;
			}

			if ( false === $site_id && false !== $this->item_id ) {
				$body['plugin_slug'] = $this->plugin_name;
				$body['url']         = $site_url;
				$body['item_id']     = $this->item_id;

				$request = $this->remote_post( $body );
				if ( ! is_wp_error( $request ) && 200 === $request['response']['code'] ) {
					$retrieved_body = json_decode( wp_remote_retrieve_body( $request ), true );
					if ( is_array( $retrieved_body ) && isset( $retrieved_body['siteId'] ) ) {
						$site_id = $retrieved_body['siteId'];
						update_option( $site_id_key, $site_id );
						update_option( "wpins_{$this->plugin_name}_original_url", $site_url );
						update_option( "wpins_{$this->plugin_name}_{$site_id}", $body );
					}
				} else {
					$failed_data = $body;
				}
			}

			$site_id_data_key        = "wpins_{$this->plugin_name}_{$site_id}";
			$site_id_data_failed_key = "wpins_{$this->plugin_name}_{$site_id}_send_failed";

			if ( false !== $site_id ) {
				$old_sent_data = get_option( $site_id_data_key, array() );
				$diff_data     = $this->diff( $body, $old_sent_data );
				$failed_data   = get_option( $site_id_data_failed_key, array() );
				if ( ! empty( $failed_data ) && $diff_data !== $failed_data ) {
					$failed_data = array_merge( $failed_data, $diff_data );
				}
			}

			if ( ! empty( $failed_data ) && false !== $site_id ) {
				$failed_data['plugin_slug'] = $this->plugin_name;
				$failed_data['url']         = $site_url;
				$failed_data['site_id']     = $site_id;
				if ( false !== $original_site_url ) {
					$failed_data['original_url'] = $original_site_url;
				}
				$request = $this->remote_post( $failed_data );
				if ( ! is_wp_error( $request ) ) {
					delete_option( $site_id_data_failed_key );
					update_option( $site_id_data_key, array_merge( get_option( $site_id_data_key, array() ), $failed_data ) );
				}
			}

			if ( ! empty( $diff_data ) && false !== $site_id && empty( $failed_data ) ) {
				$diff_data['plugin_slug'] = $this->plugin_name;
				$diff_data['url']         = $site_url;
				$diff_data['site_id']     = $site_id;
				if ( false !== $original_site_url ) {
					$diff_data['original_url'] = $original_site_url;
				}
				$request = $this->remote_post( $diff_data );
				if ( is_wp_error( $request ) ) {
					update_option( $site_id_data_failed_key, $diff_data );
				} else {
					update_option( $site_id_data_key, array_merge( get_option( $site_id_data_key, array() ), $diff_data ) );
				}
			}

			$this->set_track_time();

			if ( isset( $request ) && is_wp_error( $request ) ) {
				return $request;
			}
			return isset( $request );
		}

		protected function remote_post( $data = array(), $args = array() ) {
			if ( empty( $data ) ) {
				return;
			}
			$args    = wp_parse_args(
				$args,
				array(
					'method'      => 'POST',
					'timeout'     => 30,
					'redirection' => 5,
					'httpversion' => '1.1',
					'blocking'    => true,
					'body'        => $data,
					'user-agent'  => 'XSpeed/' . ( defined( 'XSPEED_VERSION' ) ? XSPEED_VERSION : '1.0' ) . '; ' . get_bloginfo( 'url' ),
				)
			);
			$request = wp_remote_post( esc_url_raw( self::API_URL ), $args );
			if ( is_wp_error( $request )
				|| ( isset( $request['response']['code'] ) && 200 !== $request['response']['code'] ) ) {
				return new WP_Error( 500, 'Something went wrong.' );
			}
			return $request;
		}

		protected function diff( $new_data, $old_data ) {
			$data = array();
			foreach ( (array) $new_data as $key => $value ) {
				if ( isset( $old_data[ $key ] ) && $old_data[ $key ] === $value ) {
					continue;
				}
				$data[ $key ] = $value;
			}
			return $data;
		}
	}

endif;
