/**
 * Child-module → host-page map (FBS-83633).
 *
 * The restructure moved most modules OFF the sidebar and INTO host pages
 * as tabs/sections. A module that isn't its own sidebar row still needs
 * to be *findable*: when search matches "brotli" or "critical css", the
 * result must resolve to the host row that actually renders it (plus the
 * sub-tab to open), not to a row that doesn't exist.
 *
 * This is the single source of truth for that mapping. Each entry maps a
 * child module slug to the host row's slug and the sub-tab key within it
 * (the compound hash becomes `#<host>/<subtab>`). Keep it in sync with
 * the host panels' TAB_ITEMS keys.
 *
 * A slug NOT in this map is assumed to be its own sidebar row.
 */
export interface HostTarget {
  /** The sidebar row (host module slug) that renders this child. */
  host: string;
  /** The sub-tab key to open within the host (compound hash suffix). */
  subtab?: string;
}

export const CHILD_TO_HOST: Record<string, HostTarget> = {
  // CSS & JavaScript (host: minify)
  'critical-css': { host: 'minify', subtab: 'critical' },
  'unused-css': { host: 'minify', subtab: 'unused' },
  // Compression (host: gzip)
  'brotli-compression': { host: 'gzip', subtab: 'brotli' },
  // Media Optimization (host: lazy). `fonts` is deliberately absent — it is
  // its own Optimization card, not a tab here (#86).
  images: { host: 'lazy', subtab: 'images' },
  'smart-image': { host: 'lazy', subtab: 'smart' },
  // Resource Hints (host: resource-hints)
  'smart-predict': { host: 'resource-hints', subtab: 'speculation' },
  // Preloader (host: preloader)
  'prewarm-schedule': { host: 'preloader', subtab: 'scheduled' },
  'predictive-warm': { host: 'preloader', subtab: 'predictive' },
  // Cloudflare (host: cloudflare — APO is an in-panel section)
  'cloudflare-apo': { host: 'cloudflare' },
  // Health (host: health)
  analytics: { host: 'health', subtab: 'cache' },
  rum: { host: 'health', subtab: 'visitors' },
  pagespeed: { host: 'health', subtab: 'pagespeed' },
  'performance-health': { host: 'health', subtab: 'pagespeed' },
  recommendations: { host: 'health' },
  // Cache coverage — hosted in the "Advanced Cache" row (cache-coverage),
  // NOT the Page Cache page (FBS-83633).
  'cache-404': { host: 'cache-coverage', subtab: 'coverage' },
  'search-cache': { host: 'cache-coverage', subtab: 'coverage' },
  'feed-cache': { host: 'cache-coverage', subtab: 'beyond' },
  'rest-cache': { host: 'cache-coverage', subtab: 'beyond' },
  'custom-rules': { host: 'cache-coverage', subtab: 'rules' },
  'maintenance-cache': { host: 'cache-coverage', subtab: 'rules' },
  // AI & Agents rows (ai-provider, mcp, ai-privacy, adaptive-cache) are
  // their OWN sidebar rows, so they're not in this map — search surfaces
  // each directly. The other AI features (smart-predict, smart-image,
  // performance-health) live in their functional pages and are mapped
  // above (Resource Hints / Media / Health).
  // The Settings rows (license, white-label, multisite) are their OWN cards
  // on the Settings landing, so they're deliberately absent here too.
};

/** Resolve a slug to its host target, or null if it is its own row. */
export function hostTargetFor(slug: string): HostTarget | null {
  return CHILD_TO_HOST[slug] ?? null;
}
