<?php
/**
 * Migration module — one-click settings import from other caching
 * plugins (WP Rocket, W3 Total Cache, WP Super Cache).
 *
 * Tier: Pro per FEATURES.md "Migration" — both rows tagged Pro
 * (cross-plugin importer is a non-trivial value-add; LiteSpeed
 * doesn't have one).
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\Migration;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;
use XSpeed\Migration;

final class MigrationModule extends Module {

	public const SLUG    = 'migration';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	public function ui_metadata(): array {
		return array(
			'label'        => 'Migration',
			'icon'         => 'Import',
			'description'  => 'Import settings from WP Rocket, W3 Total Cache, or WP Super Cache.',
			'custom_panel' => 'MigrationPanel',
		);
	}

	public function settings_schema(): array {
		return array();
	}

	/**
	 * Per-user meta key recording which detected source the user dismissed
	 * the migration notice for. Keyed by source so dismissing the LiteSpeed
	 * prompt doesn't hide a later WP Rocket prompt.
	 */
	private const DISMISS_META = 'xspeed_migration_notice_dismissed';

	/** Query arg used by the one-click dismiss link. */
	private const DISMISS_ARG = 'xspeed_dismiss_migration';

	/**
	 * Per-user list of source ids the user has already SEEN (by opening the
	 * Migration panel). Seen sources don't count toward the sidebar badge —
	 * the badge means "new importable plugins you haven't looked at yet", so
	 * it clears once the user visits the page. A plugin installed LATER is
	 * still un-seen, so it re-badges.
	 */
	private const SEEN_META = 'xspeed_migration_seen_sources';

	public function boot(): void {
		// Dashboard nudge: when another caching plugin is detected, offer a
		// one-click import — the same "we noticed you use X" prompt other
		// plugins show. Renders on standard WP admin screens (NOT xSpeed's
		// own pages, where the Migration panel already covers it).
		/*
		 * Priority 1 put this ABOVE everything, including the page title.
		 *
		 * On plugins.php (and most core screens) `admin_notices` fires before
		 * the `.wrap` div and the <h1>, so a very early notice is emitted into
		 * the gap under the screen-options bar — outside the page's own
		 * container, floating above the heading it belongs to. Hooking last
		 * instead keeps it inside the normal notice flow, below the title and
		 * above the rest, without leaving the notice system.
		 */
		/*
		 * Every screen EXCEPT plugins.php, which gets the later hook below —
		 * otherwise this fires first and wins the render-once guard, putting
		 * the notice back above the title on exactly the screen we moved it
		 * off.
		 *
		 * Priority 1 so it leads the notice stack: other plugins hook at the
		 * default 10, and a migration offer buried under three review prompts
		 * and an upsell is one nobody reads. This is safe here in a way it was
		 * not on plugins.php — on the screens that keep this hook, the notice
		 * area is the page's own, so being first within it means first inside
		 * the layout rather than adrift above the heading.
		 */
		if ( ! $this->is_plugins_screen() ) {
			add_action( 'admin_notices', array( $this, 'maybe_render_notice' ), 1 );
		}
		/*
		 * plugins.php renders its <h1> AFTER admin_notices has already fired,
		 * so nothing hooked there can sit below the title — the notice lands
		 * in the gap under the screen-options bar instead, above the heading
		 * it belongs to. This screen offers `pre_current_active_plugins`,
		 * which runs inside `.wrap` just after the title, so the notice is
		 * moved there and the admin_notices copy stands down (see the guard in
		 * maybe_render_notice, which renders once per request).
		 */
		// Priority 1 for the same reason: this hook carries WordPress's own
		// "Plugin activated." messages too, and ours should lead them.
		add_action( 'pre_current_active_plugins', array( $this, 'maybe_render_notice' ), 1 );

		/*
		 * Core's "WordPress x.y is available" nag is hooked to admin_notices
		 * at priority 3, so on plugins.php — where our card moved to the
		 * later `pre_current_active_plugins` to stay under the title — the nag
		 * prints first and ours lands beneath it.
		 *
		 * Re-hook the nag to the same later action so the two share a
		 * container and the order is ours, then core's. It is re-added rather
		 * than dropped: suppressing a core update prompt to win a slot would
		 * trade the user's security notice for our marketing, which is not a
		 * trade we get to make.
		 */
		if ( $this->is_plugins_screen() ) {
			add_action( 'admin_init', array( $this, 'reorder_core_update_nag' ) );
		}
		add_action( 'admin_init', array( $this, 'handle_dismiss' ) );
		// Migrate straight from the notice. The Import control used to be a
		// LINK to the dashboard, so "Import" meant "go to another screen and
		// find the button again" — the migration never started from the place
		// that offered it. This endpoint runs the same apply() the panel and
		// the CLI use, so all three behave identically.
		add_action( 'wp_ajax_xspeed_migrate_now', array( $this, 'ajax_migrate_now' ) );
		// Sidebar attention badge: surface the count of importable plugins on
		// the Migration nav item so the user knows there's an action to take.
		add_filter( 'xspeed_module_descriptor', array( $this, 'add_sidebar_badge' ), 10, 2 );
	}

	/**
	 * Badge the Migration module's sidebar item with the count of importable
	 * caching plugins the user hasn't SEEN or dismissed yet — surfaces at a
	 * glance how many NEW sources they could migrate from. Opening the panel
	 * marks sources seen (see rest_status), so the badge clears after a visit.
	 * Other modules untouched.
	 *
	 * @param array  $entry  Module descriptor being built.
	 * @param object $module The module instance.
	 * @return array
	 */
	public function add_sidebar_badge( array $entry, $module ): array {
		if ( ( $entry['slug'] ?? '' ) !== self::SLUG ) {
			return $entry;
		}
		$uid       = get_current_user_id();
		$dismissed = (array) get_user_meta( $uid, self::DISMISS_META, true );
		$seen      = (array) get_user_meta( $uid, self::SEEN_META, true );
		$count     = 0;
		foreach ( $this->detected_sources( $dismissed ) as $s ) {
			if ( ! in_array( $s['id'], $seen, true ) ) {
				++$count;
			}
		}
		if ( $count > 0 ) {
			$entry['badge'] = $count;
		}
		return $entry;
	}

	/**
	 * Render the migration nudge on the dashboard when exactly one importable
	 * source is detected and the user hasn't dismissed it. Kept deliberately
	 * conservative: skipped on xSpeed's own screens, for users without
	 * manage_options, and once dismissed.
	 */
	public function maybe_render_notice(): void {
		// Two hooks feed this on plugins.php — admin_notices for every other
		// screen, pre_current_active_plugins so this one lands below the
		// title. Whichever fires first wins; the second is a no-op rather
		// than a duplicate card.
		static $rendered = false;
		if ( $rendered ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Don't double up on xSpeed's own pages — the Migration panel is right there.
		if ( class_exists( '\\XSpeed\\Admin' ) && \XSpeed\Admin::is_plugin_page() ) {
			return;
		}

		$dismissed = (array) get_user_meta( get_current_user_id(), self::DISMISS_META, true );
		$detected  = $this->detected_sources( $dismissed );
		if ( empty( $detected ) ) {
			return;
		}

		$brand     = $this->branding_name();
		$base_url  = admin_url( 'admin.php?page=xspeed' );
		// The dashboard selects the panel from the URL hash. The hash must be
		// the LAST thing in the URL — any query arg (e.g. ?source=…) has to go
		// BEFORE the '#', or it becomes part of the fragment ("migration?source=…")
		// which no module slug matches, so the app falls back to the first
		// panel (#cache). That was the "Import goes to #cache" bug.
		$panel_url = $base_url . '#migration';
		$dismiss_url = wp_nonce_url(
			add_query_arg( self::DISMISS_ARG, 'all' ),
			'xspeed_dismiss_migration_all'
		);
		$count = count( $detected );

		// Branded card. All inline-styled (admin-notice context has no
		// bundled stylesheet) but mapped to DESIGN.md tokens: accent #2563eb,
		// neutral text #1e293b / #475569, rounded-lg, comfortable padding.
		$heading = sprintf(
			/* translators: %d: number of detected caching plugins. */
			_n(
				'Migrate to %1$s — %2$d caching plugin detected',
				'Migrate to %1$s — %2$d caching plugins detected',
				$count,
				'xspeed'
			),
			$brand,
			$count
		);

		$rendered    = true;
		$brand_color = $this->brand_color();
		$nonce       = wp_create_nonce( 'xspeed_migrate_now' );

		/*
		 * The guarantee is the headline; the product name is secondary.
		 *
		 * The old notice led with the mechanism ("Import your existing
		 * settings instead of configuring everything by hand…") and spent
		 * three lines on caveats before offering anything — the reader met the
		 * hedging before the offer. It also put the source rows in a sub-card
		 * even when there was only one, and its Import control was an `<a>`
		 * that NAVIGATED to the dashboard: pressing the button in the notice
		 * did not migrate, it moved you to a screen where you had to find the
		 * button again.
		 *
		 * The consequence ("migrating deactivates X") sits under the buttons
		 * rather than inside a dialog after the click.
		 */
		$single = 1 === $count ? $detected[0] : null;

		/**
		 * Filter the "book a call" destination shown beside the migrate CTA.
		 *
		 * Returning '' hides the button — a white-label build or a reseller
		 * who cannot staff the call has to be able to drop it. It ships with a
		 * destination so the control is real out of the box rather than a
		 * promise nobody answers.
		 *
		 * @param string $url Booking URL, or '' to hide the control.
		 */
		$call_url = (string) apply_filters(
			'xspeed_migration_call_url',
			'https://xspeedcache.com/support/'
		);

		/**
		 * Filter the speed-guarantee wording, or remove it.
		 *
		 * Returning '' drops the badge and falls back to a plain benefit
		 * headline — white-label builds and resellers who cannot honour a
		 * guarantee must be able to switch it off.
		 *
		 * @param string $label Badge text.
		 */
		$guarantee = (string) apply_filters( 'xspeed_migration_guarantee_label', __( 'Speed guarantee', 'xspeed' ) );

		echo '<style>'
			. '.xspeed-mig{position:relative;padding:0!important;border:1px solid #e2e8f0!important;'
			. 'border-left:4px solid ' . esc_attr( $brand_color ) . '!important;background:#fff;}'
			. '.xspeed-mig__in{display:flex;align-items:flex-start;gap:16px;padding:18px 44px 18px 20px;flex-wrap:wrap;}'
			. '.xspeed-mig__ico{flex:0 0 44px;width:44px;height:44px;border-radius:10px;display:inline-flex;'
			. 'align-items:center;justify-content:center;background:' . esc_attr( $brand_color ) . ';color:#fff;}'
			. '.xspeed-mig__body{flex:1 1 420px;min-width:0;}'
			. '.xspeed-mig__hrow{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 5px;}'
			. '.xspeed-mig__h{margin:0;font-size:17px;font-weight:600;color:#0f172a;line-height:1.35;letter-spacing:-.01em;}'
			. '.xspeed-mig__badge{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;'
			. 'padding:4px 10px;border-radius:999px;background:' . esc_attr( $brand_color ) . '14;color:' . esc_attr( $brand_color ) . ';}'
			. '.xspeed-mig__p{margin:0!important;font-size:13.5px!important;color:#475569!important;line-height:1.55;}'
			. '.xspeed-mig__meta{margin:7px 0 0!important;font-size:12px!important;color:#94a3b8!important;}'
			. '.xspeed-mig__act{flex:0 0 auto;display:flex;flex-direction:column;align-items:flex-end;gap:8px;margin-left:auto;}'
			. '.xspeed-mig__btns{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end;}'
			. '.xspeed-mig__cta{background:' . esc_attr( $brand_color ) . '!important;border:1px solid ' . esc_attr( $brand_color ) . '!important;'
			. 'color:#fff!important;box-shadow:none!important;height:44px!important;line-height:1!important;padding:0 22px!important;'
			. 'display:inline-flex!important;align-items:center;gap:8px;border-radius:8px!important;font-size:14px!important;font-weight:600;}'
			. '.xspeed-mig__cta:hover{filter:brightness(1.12);}'
			. '.xspeed-mig__cta[disabled]{opacity:.6;cursor:default;}'
			. '.xspeed-mig__ghost{display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 16px;'
			. 'border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155!important;font-size:13.5px;text-decoration:none!important;box-shadow:none;}'
			. '.xspeed-mig__ghost:hover{border-color:#94a3b8;color:#0f172a;}'
			. '.xspeed-mig__sub{font-size:12px!important;color:#94a3b8!important;text-align:right;}'
			. '.xspeed-mig__sub a{color:#64748b!important;text-decoration:none!important;box-shadow:none;}'
			. '.xspeed-mig__sub a:hover{text-decoration:underline!important;}'
			. '.xspeed-mig__x{position:absolute;top:11px;right:11px;width:28px;height:28px;border:0;'
			. 'background:transparent;color:#94a3b8!important;cursor:pointer;padding:0;text-decoration:none!important;box-shadow:none;'
			. 'border-radius:6px;display:inline-flex;align-items:center;justify-content:center;transition:color .15s;}'
			. '.xspeed-mig__x:hover{color:#0f172a!important;}'
			. '.xspeed-mig__rows{display:flex;flex-direction:column;gap:8px;padding:0 20px 18px;}'
			. '.xspeed-mig__row{display:flex;align-items:center;justify-content:space-between;gap:12px;'
			. 'padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;}'
			. '.xspeed-mig__spin{display:inline-block;width:14px;height:14px;border:2px solid #ffffff66;'
			. 'border-top-color:#fff;border-radius:50%;animation:xspeed-mig-spin .7s linear infinite;}'
			. '@keyframes xspeed-mig-spin{to{transform:rotate(360deg)}}'
			. '</style>';

		echo '<div class="notice xspeed-mig" data-nonce="' . esc_attr( $nonce ) . '">';

		// Close control, top-right, as an ordinary dismiss link so it still
		// works with JavaScript unavailable.
		echo '<a href="' . esc_url( $dismiss_url ) . '" class="xspeed-mig__x" aria-label="'
			. esc_attr__( 'Dismiss this notice', 'xspeed' ) . '">'
			// Drawn glyph rather than the "&times;" character: at 12px the
			// entity renders as stray punctuation, off-centre and weight-
			// mismatched against the rest of the card.
			. '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>'
			. '</a>';

		echo '<div class="xspeed-mig__in">';

		$logo = $this->branding_logo();
		echo '<span class="xspeed-mig__ico">';
		echo '' !== $logo
			? $logo // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized inline SVG from branding, escaped at source.
			: $this->brand_mark(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own bundled icon.svg, read from disk and size-capped in brand_mark().
		echo '</span>';

		echo '<div class="xspeed-mig__body">';

		echo '<div class="xspeed-mig__hrow">';
		echo '<span class="xspeed-mig__h">' . esc_html(
			'' !== $guarantee
				? __( "Guaranteed faster load times — or we'll tune it for you, free.", 'xspeed' )
				: __( 'Faster load times, without configuring anything by hand.', 'xspeed' )
		) . '</span>';
		if ( '' !== $guarantee ) {
			echo '<span class="xspeed-mig__badge">'
				. '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>'
				. esc_html( $guarantee ) . '</span>';
		}
		echo '</div>';

		// Product name secondary, benefits folded into one sentence.
		echo '<p class="xspeed-mig__p">' . esc_html(
			sprintf(
				/* translators: %s: brand name, e.g. xSpeed Cache. */
				__( '%s imports your settings in one click, then handles caching and optimization with AI-powered control.', 'xspeed' ),
				$brand
			)
		) . '</p>';

		if ( null !== $single ) {
			$mapped = (int) ( $single['mapped_count'] ?? 0 );
			echo '<p class="xspeed-mig__meta">' . esc_html(
				sprintf(
					/* translators: 1: detected plugin name, 2: number of settings. */
					_n(
						'%1$s detected · %2$d setting ready to import',
						'%1$s detected · %2$d settings ready to import',
						$mapped,
						'xspeed'
					),
					$single['label'],
					$mapped
				)
			) . '</p>';
		} else {
			echo '<p class="xspeed-mig__meta">' . esc_html( $heading ) . '</p>';
		}
		echo '</div>'; // .body

		echo '<div class="xspeed-mig__act">';
		echo '<div class="xspeed-mig__btns">';
		if ( '' !== $call_url ) {
			echo '<a href="' . esc_url( $call_url ) . '" class="xspeed-mig__ghost" target="_blank" rel="noopener noreferrer">'
				. '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>'
				. esc_html__( 'Prefer we handle it? Book a call', 'xspeed' ) . '</a>';
		}
		if ( null !== $single ) {
			echo '<button type="button" class="button button-primary xspeed-mig__cta" data-source="' . esc_attr( $single['id'] ) . '"'
				. ' data-deactivate="' . ( ! empty( $single['active'] ) ? '1' : '0' ) . '">'
				. esc_html(
					sprintf(
						/* translators: %s: brand name. */
						__( 'Migrate to %s', 'xspeed' ),
						$brand
					)
				)
				. '</button>';
		}
		echo '</div>';

		// The consequence sits UNDER the buttons, before the click — not in a
		// dialog after it.
		if ( null !== $single ) {
			echo '<div class="xspeed-mig__sub">';
			if ( ! empty( $single['active'] ) ) {
				echo esc_html(
					sprintf(
						/* translators: %s: detected plugin name. */
						__( 'Migrating deactivates %s', 'xspeed' ),
						$single['label']
					)
				) . ' &nbsp; ';
			}
			echo '<a href="' . esc_url( $panel_url ) . '">' . esc_html__( 'View migration details', 'xspeed' ) . '</a>';
			echo '</div>';
		}
		echo '</div>'; // .act

		echo '</div>'; // .in

		// Several sources: one row each, since there is no single obvious
		// action to promote.
		if ( null === $single ) {
			echo '<div class="xspeed-mig__rows">';
			foreach ( $detected as $s ) {
				$mapped = (int) ( $s['mapped_count'] ?? 0 );
				echo '<div class="xspeed-mig__row">';
				echo '<span style="font-size:13px;color:#1e293b;"><strong>' . esc_html( $s['label'] ) . '</strong> '
					. '<span style="color:#94a3b8;">' . esc_html(
						sprintf(
							/* translators: %d: number of settings imported. */
							_n( 'imports %d setting', 'imports %d settings', $mapped, 'xspeed' ),
							$mapped
						)
					) . '</span></span>';
				echo '<button type="button" class="button button-primary xspeed-mig__cta" data-source="' . esc_attr( $s['id'] ) . '"'
					. ' data-deactivate="' . ( ! empty( $s['active'] ) ? '1' : '0' ) . '" style="height:36px!important;padding:0 16px!important;font-size:13px!important;">'
					. esc_html__( 'Migrate', 'xspeed' ) . '</button>';
				echo '</div>';
			}
			echo '</div>';
		}

		$this->print_notice_script();

		echo '</div>';
	}

	/**
	 * Detected sources that are still actionable — not dismissed and not
	 * already imported — richest first. These are what the dashboard notice
	 * and the sidebar badge count: "new caching plugins you could migrate
	 * from". Once imported, a source drops out.
	 *
	 * @param string[] $dismissed Dismissed source ids ('all' hides every one).
	 * @return array<int,array{id:string,label:string,mapped_count:int}>
	 */
	private function detected_sources( array $dismissed = array() ): array {
		if ( in_array( 'all', $dismissed, true ) ) {
			return array();
		}
		// Migration is an optional collaborator, not a hard dependency: this
		// runs on `admin_notices`, which fires on EVERY admin screen. If
		// includes/class-migration.php is unreadable — a partial plugin
		// update, a stale opcache file map, a bad deploy — the autoloader
		// no-ops silently and the static call below fatals, taking wp-admin
		// down with it. That includes plugins.php, so the owner cannot even
		// deactivate us to recover. Degrade to "no sources detected" instead.
		if ( ! class_exists( '\\XSpeed\\Migration' ) ) {
			return array();
		}
		$out = array();
		foreach ( Migration::status() as $s ) {
			if ( empty( $s['detected'] ) || ! empty( $s['imported'] ) || in_array( $s['id'], $dismissed, true ) ) {
				continue;
			}
			$out[] = $s;
		}
		// Order by the honest mapped count (what we actually import).
		usort( $out, static fn( $a, $b ) => (int) $b['mapped_count'] <=> (int) $a['mapped_count'] );
		return $out;
	}

	/**
	 * The bundled xSpeed mark, inlined so it can take the tile's colour.
	 *
	 * `assets/icon.svg` is `fill="currentColor"`, which an `<img>` cannot
	 * recolour — it would paint black on the brand tile. Inlining lets the
	 * parent's `color` drive the fill, the same trick the admin menu and the
	 * boot skeleton use on their own surfaces (DESIGN.md §24.28).
	 *
	 * Read from disk rather than duplicated here: the mark is a tracked build
	 * output and a second copy in PHP is the drift that section warns about.
	 * Falls back to a bolt glyph if the file is missing (a partial deploy),
	 * because a notice with no icon is better than a fatal.
	 */
	private function brand_mark(): string {
		$path = XSPEED_DIR . 'assets/icon.svg';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own bundled asset; WP_Filesystem needs credentials unavailable when rendering a notice.
		$svg = is_readable( $path ) ? (string) @file_get_contents( $path ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable file falls back below.
		if ( '' === $svg || false === strpos( $svg, '<svg' ) ) {
			return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>';
		}
		// Size it for the tile; the file ships at 20x20.
		return (string) preg_replace(
			'/\swidth="[^"]*"\s+height="[^"]*"/',
			' width="24" height="24"',
			$svg,
			1
		);
	}

	/** Inline brand logo SVG when white-label supplies one; else empty. */
	private function branding_logo(): string {
		$brand = apply_filters( 'xspeed_branding', array() );
		return isset( $brand['logo_svg'] ) && is_string( $brand['logo_svg'] ) ? $brand['logo_svg'] : '';
	}

	/**
	 * Move core's update nag below our notice on plugins.php.
	 *
	 * Only on this screen, only that one callback, and only when it is
	 * actually registered — every other notice on every other screen is left
	 * exactly where its owner put it. The nag still renders; it renders
	 * second.
	 */
	public function reorder_core_update_nag(): void {
		if ( ! has_action( 'admin_notices', 'update_nag' ) ) {
			return;
		}
		remove_action( 'admin_notices', 'update_nag', 3 );
		add_action( 'pre_current_active_plugins', 'update_nag', 2 );
	}

	/**
	 * Are we rendering plugins.php (or its network equivalent)?
	 *
	 * Checked from `boot()`, which runs before `admin_init`, so `get_current_screen()`
	 * is not available yet — the global WordPress sets while resolving the
	 * request is, and it is what admin-side code keys on this early.
	 */
	private function is_plugins_screen(): bool {
		global $pagenow;
		return 'plugins.php' === $pagenow;
	}

	/**
	 * Human name for a module slug, for copy the user reads.
	 *
	 * The notice showed the raw slug ("object-cache"), which is an internal
	 * identifier — it tells someone nothing about which feature needs their
	 * attention. Unknown slugs fall back to a title-cased form rather than
	 * being dropped, so a Pro module added later still reads sensibly.
	 */
	private static function module_label( string $slug ): string {
		$labels = array(
			'cache'         => __( 'Page Cache', 'xspeed' ),
			'minify'        => __( 'Minify', 'xspeed' ),
			'lazy'          => __( 'Lazy Load', 'xspeed' ),
			'preloader'     => __( 'Preloader', 'xspeed' ),
			'browser-cache' => __( 'Browser Cache', 'xspeed' ),
			'object-cache'  => __( 'Object Cache', 'xspeed' ),
			'gzip'          => __( 'Compression', 'xspeed' ),
			'bloat'         => __( 'Bloat Removal', 'xspeed' ),
			'cdn'           => __( 'CDN', 'xspeed' ),
		);
		if ( isset( $labels[ $slug ] ) ) {
			return $labels[ $slug ];
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	/**
	 * Migrate from the notice, without leaving the screen.
	 *
	 * The notice's Import control was an `<a href>` pointing at the dashboard,
	 * so pressing it navigated to the Migration panel and asked the user to
	 * find the button again. The offer and the action lived on different
	 * screens.
	 *
	 * Dispatches through rest_apply() rather than reimplementing it: the
	 * import, the deactivation gate, the activity record and the partial-
	 * failure reporting are all decided in one place, so this path cannot
	 * drift from the panel, WP-CLI or MCP. Deactivation stays the caller's
	 * explicit choice and is passed through as such. (#189)
	 */
	public function ajax_migrate_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to migrate settings.', 'xspeed' ) ), 403 );
		}
		check_ajax_referer( 'xspeed_migrate_now', 'nonce' );

		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		if ( '' === $source ) {
			wp_send_json_error( array( 'message' => __( 'No source selected.', 'xspeed' ) ), 400 );
		}
		$deactivate = ! empty( $_POST['deactivate'] );

		// Same request shape rest_apply() reads, so one implementation serves
		// the panel, the CLI, MCP and this notice.
		$request = new \WP_REST_Request( 'POST', '/xspeed/v1/migration/apply' );
		$request->set_body( (string) wp_json_encode(
			array(
				'source'            => $source,
				'deactivate_source' => (bool) $deactivate,
			)
		) );
		$request->set_header( 'content-type', 'application/json' );

		$result = $this->rest_apply( $request );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$data = $result instanceof \WP_REST_Response ? $result->get_data() : (array) $result;

		// Report the SAME three outcomes the panel distinguishes: clean,
		// partial, and "imported but the old plugin is still running". A
		// notice that only ever said "Done" would hide a half-applied import.
		$failed = array();
		foreach ( (array) ( $data['results'] ?? array() ) as $slug => $r ) {
			if ( empty( $r['ok'] ) ) {
				// Human label, not the raw slug — "Object Cache", not
				// "object-cache" — and the message trimmed of its own
				// terminator so the sentence does not end in "6379..".
				$msg      = trim( (string) ( $r['message'] ?? '' ) );
				$msg      = '' !== $msg ? rtrim( $msg, '. ' ) : __( 'it could not be enabled', 'xspeed' );
				// Strip the engine's "Could not enable: " prefix; the sentence
				// around it already says that.
				$msg      = (string) preg_replace( '/^could not enable:\s*/i', '', $msg );
				$failed[] = self::module_label( (string) $slug ) . ' — ' . $msg;
			}
		}

		wp_send_json_success(
			array(
				'label'       => (string) ( $data['source_label'] ?? '' ),
				'deactivated' => ! empty( $data['deactivated'] ),
				'refused'     => (string) ( $data['refused_message'] ?? '' ),
				'failed'      => $failed,
				'panel_url'   => admin_url( 'admin.php?page=xspeed' ) . '#migration',
			)
		);
	}

	/**
	 * The notice's own behaviour: migrate in place, then report the outcome
	 * where the offer was.
	 *
	 * Inline rather than an enqueued file because admin_notices renders on
	 * every admin screen and this is a few lines — a separate request for it
	 * would cost more than it saves. No jQuery: the notice must work on
	 * screens that do not load it.
	 */
	private function print_notice_script(): void {
		$ajax = esc_url_raw( admin_url( 'admin-ajax.php' ) );
		?>
<script>
(function () {
	var box = document.currentScript && document.currentScript.closest('.xspeed-mig');
	if (!box) { return; }
	var nonce = box.getAttribute('data-nonce') || '';

	/**
	 * Render the outcome in the SAME card shape as the offer — icon tile,
	 * heading, body, meta — rather than dropping to a bare paragraph. The
	 * result is the last thing the user sees from this feature; a plain
	 * sentence where a designed card was reads as something having gone
	 * wrong even when nothing did.
	 *
	 * `tone` colours only the tile and the left rule:
	 *   ok      — the import succeeded. Also covers the case where an
	 *             optional module could not enable itself (no Redis for the
	 *             object cache, say): the migration did what it was asked,
	 *             and the server's own limits are not a failure of it. That
	 *             detail rides along in the meta line as "Skipped: …",
	 *             which is a footnote rather than a task.
	 *   partial — kept for a genuinely mixed result.
	 *   error   — nothing was imported
	 */
	function say(tone, title, body, meta) {
		var colour = tone === 'error' ? '#dc2626' : (tone === 'partial' ? '#d97706' : '#16a34a');
		var glyph  = tone === 'error'
			? '<path d="M18 6 6 18M6 6l12 12"/>'
			: (tone === 'partial'
				? '<path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>'
				: '<path d="M20 6 9 17l-5-5"/>');

		box.style.borderLeftColor = colour;
		box.innerHTML =
			'<div class="xspeed-mig__in">' +
				'<span class="xspeed-mig__ico" style="background:' + colour + '">' +
					'<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
					'stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' + glyph + '</svg>' +
				'</span>' +
				'<div class="xspeed-mig__body">' +
					'<div class="xspeed-mig__hrow"><span class="xspeed-mig__h">' + title + '</span></div>' +
					(body ? '<p class="xspeed-mig__p">' + body + '</p>' : '') +
					(meta ? '<p class="xspeed-mig__meta">' + meta + '</p>' : '') +
				'</div>' +
			'</div>';
	}

	box.addEventListener('click', function (e) {
		var btn = e.target.closest('.xspeed-mig__cta');
		if (!btn) { return; }
		e.preventDefault();
		if (btn.disabled) { return; }

		var label = btn.textContent;
		btn.disabled = true;
		btn.innerHTML = '<span class="xspeed-mig__spin"></span> <?php echo esc_js( __( 'Migrating…', 'xspeed' ) ); ?>';

		var body = new URLSearchParams();
		body.set('action', 'xspeed_migrate_now');
		body.set('nonce', nonce);
		body.set('source', btn.getAttribute('data-source') || '');
		body.set('deactivate', btn.getAttribute('data-deactivate') || '0');

		fetch(<?php echo wp_json_encode( $ajax ); ?>, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) {
					throw new Error((res && res.data && res.data.message) || '<?php echo esc_js( __( 'Migration failed.', 'xspeed' ) ); ?>');
				}
				var d = res.data || {};
				var name = d.label || '<?php echo esc_js( __( 'the source plugin', 'xspeed' ) ); ?>';
				var panel = '<a href="' + d.panel_url + '"><?php echo esc_js( __( 'Review imported settings', 'xspeed' ) ); ?></a>';

				// Three outcomes, because a flat "Done" would hide a
				// half-applied import or a plugin still running.
				if (d.failed && d.failed.length) {
					// The import SUCCEEDED — one optional group could not be
					// enabled (no Redis for the object cache, say). Reporting
					// that in red read as a failed migration and sent people
					// looking for a problem that was not there.
					say(
						'ok',
						name + ' <?php echo esc_js( __( 'settings are now running in xSpeed.', 'xspeed' ) ); ?>',
						(d.deactivated
							? '<?php echo esc_js( __( 'It has been switched off, so the two page caches cannot conflict.', 'xspeed' ) ); ?>'
							: (d.refused || '<?php echo esc_js( __( 'It is still active — switch it off when you are ready.', 'xspeed' ) ); ?>')),
						'<?php echo esc_js( __( 'Skipped:', 'xspeed' ) ); ?> ' + d.failed.join('; ') + '. ' + panel
					);
					return;
				}
				if (d.deactivated) {
					say(
						'ok',
						name + ' <?php echo esc_js( __( 'settings are now running in xSpeed.', 'xspeed' ) ); ?>',
						'<?php echo esc_js( __( 'It has been switched off, so the two page caches cannot conflict.', 'xspeed' ) ); ?>',
						panel
					);
					return;
				}
				say(
					'ok',
					name + ' <?php echo esc_js( __( 'settings are now running in xSpeed.', 'xspeed' ) ); ?>',
					d.refused || '<?php echo esc_js( __( 'It is still active — running two page caches conflicts, so switch it off when you are ready.', 'xspeed' ) ); ?>',
					panel
				);
			})
			.catch(function (err) {
				btn.disabled = false;
				btn.textContent = label;
				say(
					'error',
					'<?php echo esc_js( __( 'Migration could not finish.', 'xspeed' ) ); ?>',
					(err.message || '') + ' <?php echo esc_js( __( 'Nothing was changed — you can try again.', 'xspeed' ) ); ?>',
					''
				);
			});
	});
})();
</script>
		<?php
	}

	/** Persist the per-source dismissal when the user clicks our Dismiss link. */
	public function handle_dismiss(): void {
		if ( ! isset( $_GET[ self::DISMISS_ARG ] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$source = sanitize_key( wp_unslash( $_GET[ self::DISMISS_ARG ] ) );
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'xspeed_dismiss_migration_' . $source ) ) {
			return;
		}
		$uid       = get_current_user_id();
		$dismissed = (array) get_user_meta( $uid, self::DISMISS_META, true );
		if ( ! in_array( $source, $dismissed, true ) ) {
			$dismissed[] = $source;
			update_user_meta( $uid, self::DISMISS_META, $dismissed );
		}
		// Redirect to drop the query args so a reload doesn't re-trigger.
		wp_safe_redirect( remove_query_arg( array( self::DISMISS_ARG, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * The single most relevant detected source to nudge about, or null.
	 * Picks the detected source with the most settings (the richest import),
	 * skipping any the user has already dismissed — so dismissing the top
	 * prompt surfaces the next source rather than going silent while another
	 * importable plugin is still present. Only one prompt at a time keeps the
	 * dashboard uncluttered.
	 *
	 * @param string[] $dismissed Source ids the user has dismissed.
	 * @return array{id:string,label:string,value_count:int}|null
	 */
	private function top_detected_source( array $dismissed = array() ): ?array {
		// Same optional-collaborator guard as detected_sources(): this feeds
		// admin-render paths, so a missing class must degrade to "nothing to
		// prompt about" rather than fatal. See detected_sources().
		if ( ! class_exists( '\\XSpeed\\Migration' ) ) {
			return null;
		}
		$best = null;
		foreach ( Migration::status() as $s ) {
			if ( empty( $s['detected'] ) || in_array( $s['id'], $dismissed, true ) ) {
				continue;
			}
			if ( null === $best || (int) $s['value_count'] > (int) $best['value_count'] ) {
				$best = $s;
			}
		}
		return $best;
	}

	/** Brand name honoring Pro white-label, falling back to "xSpeed". */
	private function branding_name(): string {
		$brand = apply_filters( 'xspeed_branding', array() );
		return isset( $brand['name'] ) && '' !== $brand['name'] ? (string) $brand['name'] : 'xSpeed';
	}

	/**
	 * Brand/logo color for the notice accent + Import buttons. White-label
	 * sites can set `brand_color` via the xspeed_branding filter; otherwise
	 * we use the xSpeed logo color (near-black), not the design blue accent —
	 * the notice should match the on-screen logo. (FBS-82379)
	 */
	private function brand_color(): string {
		$brand = apply_filters( 'xspeed_branding', array() );
		$color = isset( $brand['brand_color'] ) ? (string) $brand['brand_color'] : '';
		// Falls back to the product's own primary — the muted teal that
		// `--primary` carries in assets/theme.css. This notice renders on
		// stock admin screens, OUTSIDE #xspeed-app, so the CSS variable does
		// not resolve here and the value has to be literal. Keep the two in
		// step: a drifting hex is a notice that stops looking like the plugin.
		return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color ) ? $color : '#2AA7A0';
	}

	public function rest_routes(): array {
		return array(
			array(
				'path'     => '/status',
				'methods'  => 'GET',
				'callback' => array( $this, 'rest_status' ),
			),
			array(
				'path'     => '/preview',
				'methods'  => 'POST',
				'callback' => array( $this, 'rest_preview' ),
			),
			array(
				'path'     => '/apply',
				'methods'  => 'POST',
				'callback' => array( $this, 'rest_apply' ),
			),
		);
	}

	public function rest_status( \WP_REST_Request $request ) {
		$status = Migration::status();
		// Opening the Migration panel triggers this call — treat it as the
		// user having SEEN every currently-detected source, which clears the
		// sidebar count badge. Record the detected ids against the user.
		$this->mark_sources_seen( $status );
		return rest_ensure_response( array( 'sources' => $status ) );
	}

	/**
	 * Record the currently-detected source ids as seen for this user, so the
	 * sidebar badge stops counting them. Merges with any prior seen set.
	 *
	 * @param array $status Output of Migration::status().
	 */
	private function mark_sources_seen( array $status ): void {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return;
		}
		$seen = (array) get_user_meta( $uid, self::SEEN_META, true );
		$add  = array();
		foreach ( $status as $s ) {
			if ( ! empty( $s['detected'] ) ) {
				$add[] = $s['id'];
			}
		}
		$merged = array_values( array_unique( array_merge( $seen, $add ) ) );
		if ( $merged !== $seen ) {
			update_user_meta( $uid, self::SEEN_META, $merged );
		}
	}

	public function rest_preview( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$source = isset( $params['source'] ) ? (string) $params['source'] : '';
		$full   = Migration::preview_with_notes( $source );
		if ( null === $full ) {
			return new \WP_Error( 'xspeed_pro_mig_no_source', 'Source not detected or unknown.', array( 'status' => 404 ) );
		}
		// `notes` carries the lossy-conversion warnings the panel renders
		// beside the plan, so a value we had to round is stated rather than
		// presented as an exact import. (#224 F2)
		return rest_ensure_response(
			array(
				'patch' => $full['patch'],
				'notes' => $full['notes'],
			)
		);
	}

	public function rest_apply( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$source = isset( $params['source'] ) ? (string) $params['source'] : '';
		if ( '' === $source ) {
			return new \WP_Error( 'xspeed_pro_mig_no_source', 'Provide a source id.', array( 'status' => 400 ) );
		}

		/*
		 * Deactivating the source is the CALLER's decision, and it defaults to
		 * NO. (#189)
		 *
		 * This used to happen unconditionally: the request carried only
		 * `source`, so the server could not distinguish "the user clicked
		 * through our warning" from any other POST to this route. The only
		 * guard rail was an InlineConfirm in the React client, which is the
		 * wrong layer for a destructive action — and WP-CLI and MCP, hitting
		 * the same product action, did the opposite and left the plugin on.
		 *
		 * Defaulting to false rather than true is what makes the documented
		 * contract true again (docs/user/advanced-migration.md said migration
		 * "never changes" the old plugin) and matches the house rule that we
		 * never modify another plugin's state on our own initiative. The panel
		 * now passes deactivate:true explicitly after its confirm, so the
		 * common path is unchanged for users.
		 */
		$deactivate = ! empty( $params['deactivate_source'] );

		$results = Migration::apply( $source );

		$deactivated  = false;
		$source_label = '';
		foreach ( Migration::status() as $s ) {
			if ( $s['id'] === $source ) {
				$source_label = (string) $s['label'];
				break;
			}
		}

		/*
		 * Did the import actually cover anything?
		 *
		 * `applied` lists the meaningful keys the import attempted, so it is the
		 * right signal for the activity and Health records below. Deactivation
		 * has a stronger gate: every attempted result must also report `ok`, so a
		 * partial import never switches the source off. (#189, #224)
		 */
		$imported_something = false;
		foreach ( (array) $results as $info ) {
			if ( is_array( $info ) && ! empty( $info['applied'] ) ) {
				$imported_something = true;
				break;
			}
		}

		$refused          = '';
		$refused_message  = '';
		$import_completed = Migration::completed_successfully( (array) $results );
		// Gate the handover on the modules that MATTER, not on every one.
		// completed_successfully() is all-or-nothing, so a host without Redis
		// — where the object cache can never enable — vetoed the deactivation
		// the notice had already promised, and the user was left running two
		// page caches. safe_to_hand_over() ignores the optional extras and
		// still refuses if page caching itself did not take. (#189)
		if ( $deactivate && Migration::safe_to_hand_over( (array) $results ) ) {
			if ( ! $this->can_deactivate( $source ) ) {
				// Not an error: the import succeeded and is the thing the user
				// asked for. Report the refusal so the panel can say why the
				// plugin is still on rather than silently implying it is off.
				$refused = 'insufficient_capability';

				// Name WHO can do it, not just that the caller cannot. On a
				// network-activated source the answer is specifically a network
				// administrator, and a site admin has no way to work that out
				// from a bare capability code. (#189 AC5)
				$file            = Migration::plugin_file( $source );
				$network_scoped  = is_multisite() && '' !== $file && is_plugin_active_for_network( $file );
				$refused_message = $network_scoped
					? sprintf(
						/* translators: %s: source plugin label. */
						__( '%s is activated across the whole network, so only a network administrator can switch it off. Your settings were imported — ask a network administrator to deactivate it.', 'xspeed' ),
						$source_label
					)
					: sprintf(
						/* translators: %s: source plugin label. */
						__( 'Your account can change settings but not switch plugins off, so %s is still active. Your settings were imported — ask an administrator to deactivate it.', 'xspeed' ),
						$source_label
					);
			} else {
				$deactivated = $this->deactivate_source( $source );
				if ( $deactivated ) {
					// Switching the source off runs ITS teardown, which
					// removes WP_CACHE and can take the shared drop-in file
					// with it — leaving our own page cache configured-on but
					// not actually serving. Re-assert both. (#219)
					$this->restore_own_environment();
				}
			}
		}

		// The user declined (or was refused) and the source is still running.
		// Record it so the warning OUTLIVES this screen — see pending_source().
		// Called on every import, not just the declining ones: the helper
		// checks the plugin's live state and clears itself when it is off, so
		// a later "import and switch" also resolves an earlier warning.
		if ( $imported_something ) {
			Migration::remember_active_source( $source, $source_label );
		}

		if ( class_exists( '\\XSpeed\\Activity_Log' ) && ! empty( $results ) ) {
			\XSpeed\Activity_Log::record(
				'migration_applied',
				$deactivated
					? sprintf( 'Imported settings from %1$s and deactivated it.', $source_label )
					: sprintf( 'Imported settings from %s.', $source_label ),
				\XSpeed\Activity_Log::INFO
			);
		}

		return rest_ensure_response(
			array(
				'results'      => $results,
				'deactivated'  => $deactivated,
				'source_label' => $source_label,
				// Empty unless we were asked to deactivate and declined to.
				// The panel needs to distinguish "you didn't ask" from "you
				// asked and you may not", or it would report the source as
				// still active with no explanation.
				'refused'         => $refused,
				// A ready-to-show sentence naming who CAN do it. The panel
				// prints this verbatim rather than mapping codes to copy, so
				// the network-vs-site distinction stays in one place.
				'refused_message' => $refused_message,
			)
		);
	}

	/**
	 * May the CURRENT user switch this source plugin off?
	 *
	 * The route itself only requires `manage_options` (the module default),
	 * which is right for importing settings — that writes nothing but our own
	 * options. Deactivating somebody else's plugin is a different act, and WP
	 * core guards its own plugins screen with `activate_plugins`, escalating
	 * to `manage_network_plugins` for a network-active plugin.
	 *
	 * Without this check a subsite Administrator — who has manage_options but
	 * neither of those — could deactivate a NETWORK-ACTIVE caching plugin
	 * across every site in the network with one REST call. Reproduced on a
	 * live multisite install for #189; core would have refused the same user
	 * on wp-admin/plugins.php.
	 *
	 * @param string $source Source id.
	 */
	private function can_deactivate( string $source ): bool {
		$file = Migration::plugin_file( $source );
		if ( '' === $file ) {
			return false;
		}

		foreach ( array( 'plugin.php' ) as $inc ) {
			require_once ABSPATH . 'wp-admin/includes/' . $inc;
		}

		// Network-active plugins are a network-level object: deactivating one
		// affects every site, so it needs the network capability regardless of
		// how much power the caller holds on this one site.
		if ( is_multisite() && is_plugin_active_for_network( $file ) ) {
			return current_user_can( 'manage_network_plugins' );
		}

		return current_user_can( 'activate_plugins' );
	}

	/**
	 * Deactivate the source caching plugin (network-wide on multisite).
	 * Returns true only if it was active and is now off.
	 *
	 * Callers MUST gate this on can_deactivate() — it performs no capability
	 * check of its own, because the CLI path resolves permission differently
	 * (a WP-CLI operator is root by definition and has no current user).
	 *
	 * @param string $source Source id.
	 * @return bool
	 */
	private function deactivate_source( string $source ): bool {
		// One home for this map, shared with Migration::status()'s active
		// flag. A private copy here could drift and deactivate a plugin the
		// panel had reported as inactive. (#189)
		$file = Migration::plugin_file( $source );
		if ( '' === $file ) {
			return false;
		}
		// deactivate_plugins() fires each plugin's deactivation hook, and some
		// (e.g. WP Super Cache) call admin-only helpers like get_home_path()
		// in theirs. Those live in wp-admin/includes/file.php — NOT loaded
		// during a REST request — so without these includes the deactivation
		// hook fatals with "undefined function get_home_path()". Load the
		// admin plumbing first so any source plugin's teardown runs cleanly.
		foreach ( array( 'plugin.php', 'file.php', 'misc.php' ) as $inc ) {
			require_once ABSPATH . 'wp-admin/includes/' . $inc;
		}
		if ( ! is_plugin_active( $file ) ) {
			return false;
		}

		/*
		 * Be EXPLICIT about scope rather than leaving $network_wide at null.
		 *
		 * Core evaluates `( false !== $network_wide ) && is_plugin_active_for_network()`,
		 * and `false !== null` is true — so the default silently takes the
		 * network-wide branch. That is the correct scope for a network-active
		 * plugin (a per-site deactivation would not turn it off anyway), but
		 * it should be a decision we state, not a fact of PHP's comparison
		 * rules. can_deactivate() has already required the matching
		 * capability for whichever branch this picks. (#189)
		 */
		$network_wide = is_multisite() && is_plugin_active_for_network( $file );
		deactivate_plugins( $file, false, $network_wide );

		return ! is_plugin_active( $file );
	}

	/**
	 * Put our own drop-in and WP_CACHE back after the source plugin's
	 * teardown, when we are the one that should own them.
	 *
	 * A source plugin's deactivation routine cleans up "the page cache
	 * environment" without checking whose it is. W3 Total Cache is the
	 * clearest case: PgCache_Environment.php strips EVERY
	 * `define( 'WP_CACHE', … )` line from wp-config.php with a blanket
	 * regex, so it deletes the line xSpeed wrote when the wizard enabled
	 * caching. wp-content/advanced-cache.php survives, but WordPress never
	 * loads it without the constant, and the cache silently degrades to the
	 * slow in-PHP path — measured at 78ms vs 16ms TTFB on an otherwise
	 * identical request.
	 *
	 * Only runs when the user has caching ON, and only re-asserts what we
	 * already own, so it cannot resurrect a cache the user turned off.
	 * (#218, #219)
	 */
	private function restore_own_environment(): void {
		if ( ! class_exists( '\\XSpeed\\Cache' ) || ! class_exists( '\\XSpeed\\Settings' ) ) {
			return;
		}

		$opts = \XSpeed\Settings::get();
		if ( empty( $opts['cache_enabled'] ) ) {
			return;
		}

		\XSpeed\Cache::toggle( true );
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed migrate',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Import settings from another caching plugin.',
				'ai_hint'   => 'Import settings from another caching plugin (WP Rocket, W3 Total Cache, LiteSpeed, WP Super Cache). Use when a site is switching to xSpeed and the user does not want to reconfigure by hand.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'status', 'preview', 'apply' ),
						'optional' => true,
					),
					array(
						'type'     => 'assoc',
						'name'     => 'source',
						'optional' => true,
					),
					array(
						'type'        => 'flag',
						'name'        => 'deactivate-source',
						'description' => 'After a successful import, also deactivate the source plugin. Off by default: running two page caches at once breaks both, but switching off another plugin is your call, not ours.',
						'optional'    => true,
					),
				),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$action = $args[0] ?? 'status';
		switch ( $action ) {
			case 'status':
				foreach ( Migration::status() as $s ) {
					\WP_CLI::log( sprintf( '%-20s %s %d values', $s['id'], $s['detected'] ? 'DETECTED' : 'missing ', $s['value_count'] ) );
				}
				return;
			case 'preview':
				$src = (string) ( $assoc['source'] ?? '' );
				$p   = Migration::preview( $src );
				if ( null === $p ) {
					\WP_CLI::error( 'Source not detected or unknown: ' . $src );
				}
				\WP_CLI::log( wp_json_encode( $p, JSON_PRETTY_PRINT ) );
				return;
			case 'apply':
				$src = (string) ( $assoc['source'] ?? '' );
				$r   = Migration::apply( $src );
				if ( empty( $r ) ) {
					\WP_CLI::error( 'Nothing imported.' );
				}
				foreach ( $r as $mod => $info ) {
					/*
					 * "failed" was a lie. `ok` is update_option()'s return,
					 * which is false when the stored value did not CHANGE — so
					 * re-importing settings already in place printed
					 * "failed" for every module beside the list of fields it
					 * had just imported correctly. Report what actually
					 * happened instead. (#189)
					 */
					$applied = (array) ( $info['applied'] ?? array() );
					if ( empty( $applied ) ) {
						$state = 'nothing to import';
					} elseif ( ! empty( $info['ok'] ) ) {
						$state = 'imported';
					} else {
						$state = 'already up to date';
					}
					\WP_CLI::log( sprintf( '%-20s %-18s %s', $mod, $state, implode( ',', $applied ) ) );
				}

				/*
				 * Same contract as REST: deactivate only when asked. This path
				 * used to never deactivate AND never say so, so an operator
				 * (or an AI through MCP `run_command`) finished with two page
				 * caches live on the site and nothing in the output to say it.
				 * That is the failure mode the troubleshooting docs describe
				 * as breaking caching for both plugins. (#189)
				 *
				 * No capability check here: a WP-CLI caller is root by
				 * definition and there is no current user to test. The gate
				 * that matters is on the REST route, which is the one a
				 * browser can reach.
				 */
				// `applied`, not `ok` — see rest_apply() for why ok:false is a
				// normal outcome of a successful re-import.
				$imported_something = false;
				foreach ( (array) $r as $info ) {
					if ( is_array( $info ) && ! empty( $info['applied'] ) ) {
						$imported_something = true;
						break;
					}
				}

				// WP-CLI normalises --deactivate-source to a 'deactivate-source'
				// key; accept the underscore spelling too so MCP callers passing
				// options as JSON don't have to guess which one we mean.
				$want_off = ! empty( $assoc['deactivate-source'] ) || ! empty( $assoc['deactivate_source'] );
				$file     = Migration::plugin_file( $src );

				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				$still_on = '' !== $file && is_plugin_active( $file );

				if ( $want_off && $imported_something && $still_on ) {
					if ( $this->deactivate_source( $src ) ) {
						\WP_CLI::log( sprintf( 'Deactivated %s.', $src ) );
						$still_on = false;
					} else {
						\WP_CLI::warning( sprintf( 'Could not deactivate %s.', $src ) );
					}
				}

				if ( $still_on ) {
					\WP_CLI::warning(
						sprintf(
							'%s is still active. Two page caches running together fight over the drop-in and can break caching for both — deactivate it, or re-run with --deactivate-source.',
							$src
						)
					);
				}

				// Same persistent record as the REST path, so a CLI or MCP
				// import that leaves the source running also raises the Health
				// warning — the three surfaces must end in the same state for
				// the same input. (#189 AC4, AC10)
				if ( $imported_something ) {
					$label = '';
					foreach ( Migration::status() as $s ) {
						if ( $s['id'] === $src ) {
							$label = (string) $s['label'];
							break;
						}
					}
					Migration::remember_active_source( $src, $label );
				}

				\WP_CLI::success( 'Import complete.' );
				return;
			default:
				// Without this, an unrecognised action fell out of the switch
				// and returned success with no output — indistinguishable from
				// "ran fine, nothing to report", and ok:true over MCP.
				\WP_CLI::error(
					sprintf(
						'Unknown action "%s". Expected: status | preview --source=<id> | apply --source=<id>.',
						$action
					)
				);
		}
	}
}
