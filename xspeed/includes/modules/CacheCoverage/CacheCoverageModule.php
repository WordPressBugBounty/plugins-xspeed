<?php
/**
 * Cache Coverage — a sidebar row that gathers the Pro features which
 * extend Page Cache beyond the default HTML-for-anonymous-visitors
 * behaviour (FBS-83633).
 *
 * A thin Free container: it owns no settings. Its panel renders the
 * existing coverage section (CacheExtras) with its three tabs —
 * What to cache / Feeds & API / Rules & bypass — each embedding the
 * Pro module that owns those settings. Being a Free container, the row
 * is never a locked dead-end; the individual tabs unlock in place.
 *
 * This lives as its own Cache sub-item (rather than stacked at the foot
 * of the Page Cache page) so the coverage features are reachable
 * directly from the sidebar.
 *
 * @package XSpeed
 */

declare(strict_types=1);

namespace XSpeed\Modules\CacheCoverage;

defined( 'ABSPATH' ) || exit;

use XSpeed\Module;

final class CacheCoverageModule extends Module {

	public const SLUG    = 'cache-coverage';
	public const TIER    = self::TIER_FREE;
	public const VERSION = '1.0.0';

	public function ui_metadata(): array {
		return array(
			'label'        => 'Advanced Cache',
			'icon'         => 'Layers',
			'description'  => 'Cache 404s, search, feeds, and the REST API, plus custom rules and maintenance bypass.',
			'custom_panel' => 'CacheCoveragePanel',
		);
	}

	/** Container owns no settings; each tab persists through its own module. */
	public function settings_schema(): array {
		return array();
	}

	public function cli_commands(): array {
		return array(
			array(
				'name'      => 'xspeed cache-coverage',
				'callback'  => array( $this, 'cli_handler' ),
				'shortdesc' => 'Show which cache-coverage features are available (404 / search / feed / REST / rules / maintenance).',
				'ai_hint'   => 'Which page types beyond normal posts/pages can be cached here (404s, search, feeds, REST, maintenance)? Use when asked why a particular kind of URL is never cached.',
				'synopsis'  => array(),
			),
		);
	}

	public function cli_handler( array $args, array $assoc ): void {
		$features = array(
			'cache-404'         => '404 Caching',
			'search-cache'      => 'Search Caching',
			'feed-cache'        => 'Feed Caching',
			'rest-cache'        => 'REST API Cache',
			'custom-rules'      => 'Custom Cache Rules',
			'maintenance-cache' => 'Maintenance Mode',
		);
		foreach ( $features as $slug => $label ) {
			$present = \XSpeed\Module_Registry::has( $slug ) ? 'available' : 'locked';
			\WP_CLI::log( sprintf( '%-20s %-14s %s', $slug, $present, $label ) );
		}
	}
}
