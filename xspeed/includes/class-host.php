<?php
/**
 * Host — everything another WPDeveloper plugin needs from xSpeed.
 *
 * EmbedPress, Templately and Essential Addons can install and activate xSpeed
 * for a user. They used to do it by copy-vendoring a detector and a settings
 * writer out of this repo, which meant every one of them carried a second
 * implementation of rules that live here, and a fix had to be re-copied into
 * each before it reached anybody.
 *
 * They do not need any of that now: xSpeed always installs, and works out for
 * itself how to come up (Settings::set_defaults()). What is left is this
 * class — one place a host may call into, so nothing has to reach into Cache
 * or Page_Cache_Detector and nothing has to be duplicated.
 *
 * The one thing NOT here is provenance, because a host records it before any
 * of this code exists:
 *
 *     update_option( 'xspeed_installed_by', 'essential-addons' );
 *     // then install + activate as normal
 *
 * See IMPLEMENTATION.md §6.2d.
 *
 * @package XSpeed
 */

namespace XSpeed;

defined( 'ABSPATH' ) || exit;

final class Host {

	/**
	 * Is xSpeed installed on this site at all?
	 *
	 * A host asks before offering to install it. Reads the plugin list rather
	 * than a constant, because the question is asked from a request where
	 * xSpeed may not be loaded.
	 */
	public static function is_installed(): bool {
		self::load_plugin_functions();
		if ( ! function_exists( 'get_plugins' ) ) {
			return false;
		}
		return array_key_exists( self::PLUGIN_FILE, (array) get_plugins() );
	}

	/** Is it active — on this site, or network-wide? */
	public static function is_active(): bool {
		self::load_plugin_functions();
		return function_exists( 'is_plugin_active' ) && (bool) is_plugin_active( self::PLUGIN_FILE );
	}

	/**
	 * Does anything already own the page cache?
	 *
	 * A host does NOT have to consult this before installing — xSpeed installs
	 * beside anything and comes up with everything switched off. It is here
	 * for a host that wants to say what it found, or to decide whether to
	 * bother asking the user about page caching at all.
	 */
	public static function page_cache_is_free(): bool {
		return Page_Cache_Detector::is_field_clear();
	}

	/**
	 * Who owns the page cache, as a name to put in a sentence, or null.
	 *
	 * Prefers whoever owns the drop-in, since that is proof; falls back to the
	 * first active plugin that could be caching, which is the honest answer
	 * for something like LiteSpeed that caches at server level.
	 */
	public static function page_cache_owner(): ?string {
		$owner = Page_Cache_Detector::dropin_owner_label();
		if ( is_string( $owner ) && '' !== $owner ) {
			return $owner;
		}
		/*
		 * The catalog counts xSpeed among the page caches, correctly, so it
		 * turns up here on any site running us. It is not the answer a host
		 * is after: if we owned the drop-in the branch above would already
		 * have said so, and without it we are one more plugin that COULD be
		 * caching. Name a competitor first and fall back to ourselves.
		 */
		$active = Page_Cache_Detector::active_page_caches();
		$others = array_values( array_filter( $active, static fn ( $label ) => 'xSpeed Cache' !== $label ) );
		if ( array() !== $others ) {
			return (string) $others[0];
		}
		return empty( $active ) ? null : (string) reset( $active );
	}

	/** Is xSpeed's page cache actually serving right now? */
	public static function page_cache_is_live(): bool {
		return Cache::page_cache_operational();
	}

	/**
	 * Turn xSpeed's page cache on, for a host acting on a user's request.
	 *
	 * Null means the cache is SERVING. A string is the reason it is not,
	 * already worded and translated — render it, do not re-interpret it. The
	 * refusal is not an error: installing beside another cache plugin and being
	 * told so is the designed outcome, and the site is fine.
	 *
	 * Two ways to not be serving, and only one of them is a refusal. On a host
	 * that ships wp-config.php read-only the drop-in installs and WP_CACHE
	 * cannot be written, so toggle() reports success with a line for the user
	 * to paste — and a caller reading only `blocked` announced a working cache
	 * that answers every request uncached. status()['page_cache_manual_snippet']
	 * carries that line.
	 *
	 * Only ever call this because a user asked. Activation deliberately leaves
	 * page caching off, and a host that turns it on unprompted is making a
	 * decision about shared WordPress state on the user's behalf.
	 */
	public static function enable_page_cache(): ?string {
		$state = Cache::toggle( true );

		if ( ! empty( $state['blocked'] ) ) {
			return is_string( $state['blocked_reason'] ) && '' !== $state['blocked_reason']
				? $state['blocked_reason']
				: __( 'xSpeed could not enable the page cache on this site.', 'xspeed' );
		}

		if ( ! empty( $state['manual_snippet'] ) ) {
			return sprintf(
				/* translators: %s: the PHP line to add to wp-config.php. */
				__( 'xSpeed installed its page cache, but wp-config.php is not writable. Add this line to it to start serving: %s', 'xspeed' ),
				(string) $state['manual_snippet']
			);
		}

		return null;
	}

	/** The slug of whoever installed xSpeed, or '' when the user did. */
	public static function installed_by(): string {
		return Settings::installed_by();
	}

	/**
	 * Everything a host needs to say whether the install came up right.
	 *
	 * One call, because the alternative is every consumer assembling the same
	 * four reads and drawing its own conclusion from them — which is how three
	 * plugins ended up with three different sentences for one situation.
	 *
	 * `profile` is how a FRESH install came up and is written once, at
	 * activation: `recommended` on a clear site, `conflict-safe` when
	 * something else owned the page cache, `''` when xSpeed was already
	 * installed and nothing was decided. It does not change afterwards — it
	 * records a decision, not the current settings.
	 *
	 * `page_cache_blocked_reason` is non-null only when the cache is NOT live
	 * and there is a nameable reason. A live cache and a clear field both give
	 * null, so it is safe to render whenever it is there.
	 *
	 * `page_cache_manual_snippet` is the wp-config.php line to paste, non-null
	 * only on a host that ships that file read-only. A cache that is not live
	 * and has no blocked reason is almost always this.
	 *
	 * @return array{
	 *     installed:bool,
	 *     active:bool,
	 *     installed_by:string,
	 *     profile:string,
	 *     page_cache_live:bool,
	 *     page_cache_owner:?string,
	 *     page_cache_blocked_reason:?string,
	 *     page_cache_manual_snippet:?string
	 * }
	 */
	public static function status(): array {
		$live = self::page_cache_is_live();

		return array(
			'installed'                  => self::is_installed(),
			'active'                     => self::is_active(),
			'installed_by'               => self::installed_by(),
			'profile'                    => Settings::install_profile(),
			'page_cache_live'            => $live,
			'page_cache_owner'           => self::page_cache_owner(),
			'page_cache_blocked_reason'  => $live ? null : Cache::acquisition_blocker(),
			/*
			 * Not gated on `$live`. Serving and "there is a line to paste"
			 * are independent: on a managed host with an unwritable
			 * wp-config.php the cache serves every hit from
			 * template_redirect while WP_CACHE never landed, so the pre-boot
			 * path is still missing and the user can still act on it. The
			 * helper returns null whenever nothing is needed.
			 */
			'page_cache_manual_snippet'  => Cache::manual_wp_cache_snippet(),
		);
	}

	/**
	 * Pull in get_plugins()/is_plugin_active(), which are admin-only.
	 *
	 * A host can ask these from a front-end or REST request, where the file is
	 * not loaded; and the callers above degrade rather than fatal if it is not
	 * there at all, because a missing answer must not take the site down.
	 */
	private static function load_plugin_functions(): void {
		if ( function_exists( 'get_plugins' ) && function_exists( 'is_plugin_active' ) ) {
			return;
		}
		$file = ABSPATH . 'wp-admin/includes/plugin.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	/** How xSpeed appears in the plugin list. */
	private const PLUGIN_FILE = 'xspeed/xspeed.php';
}
