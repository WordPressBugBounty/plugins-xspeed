import type { ModuleDescriptor } from '../types';
import { __ } from '../i18n';
import { PRO_MODULE_MANIFEST } from '../upsell/proModuleManifest';

/**
 * Pro module slugs that live INSIDE a host page (as a tab/section via
 * ProSlot), not as their own sidebar row (FBS-83633). Derived from the
 * manifest's `embedded` flag so the two never drift. Used to keep these
 * modules out of the "Other" orphan bucket on Pro, where they ARE real
 * registered modules in the payload.
 */
const EMBEDDED_SLUGS = new Set(
  PRO_MODULE_MANIFEST.filter((m) => m.embedded).map((m) => m.slug),
);

/**
 * Sidebar grouping. Buckets every known module under a domain header
 * so the sidebar reads as a navigable map instead of a 25-entry flat
 * list.
 *
 * Add a new module → add its slug to the right group's `slugs`. Slugs
 * not in any group fall into a defensive "Other" bucket so a typo or
 * a newly-registered Pro module from a different repo doesn't
 * disappear from the UI.
 */
export interface SidebarGroup {
  /** Stable id used by the in-memory accordion state. */
  id: string;
  /** Uppercase section header shown above the modules. */
  label: string;
  /**
   * Parent icon for the group header (Cloudflare-style: parent items
   * carry an icon, sub-items don't). Must be a name from the
   * ModuleIcon whitelist — unknown names fall back to Square.
   */
  icon: string;
  /** Module slugs that belong to this group, in display order. */
  slugs: string[];
  /** Eyebrow/subtitle copy shown on the group landing page (redesign v2). */
  description?: string;
  /**
   * Show each card's description in full instead of clamping it to two lines.
   *
   * Writing card copy to fit two lines only holds in English: every
   * description is translated, and a rendering that runs 30-40%
   * longer loses the half that carries the meaning — which on this group is
   * the half that stops people believing they need an AI Provider key to
   * connect Claude. Being opted in per group while the rest are reviewed.
   */
  fullDescription?: boolean;
  /**
   * Leaf group: renders as a single standalone, directly-clickable row
   * (the header IS the item) instead of an accordion with children — no
   * chevron, no nesting. Used for one-off top-level entries like Support
   * that read better as a flat item than a one-child section. A leaf
   * group should list exactly one slug; only the first is used as the
   * row's target.
   */
  leaf?: boolean;
}

/**
 * Redesign v2 IA — 7 top-level groups, each a landing page of cards (Option B:
 * a card opens the real host page with its existing tabs/sections; embedded Pro
 * features live as sections there, not standalone cards). The sidebar renders
 * these as flat links; clicking a group opens its landing (#/<id>). `slugs` is
 * the ordered list of cards the landing shows. `description` is the eyebrow copy.
 *
 * Notes on placement (confirmed): cache-coverage ("Advanced cache") stays in
 * Cache (it hosts the Pro caches — 404/feed/search/REST/rules/maintenance);
 * adaptive-cache moved AI→Cache; score joins Health; fonts is an Optimization
 * card; support folds into Settings; Tools gains a read-only `logs` surface
 * (Activity_Log — a pseudo-slug, not a registered module); Hub rides with mcp
 * under AI & agents. Cloudflare Enterprise is a card in Network, beside the
 * CDN and the Cloudflare zone it sits with (moved there from its own
 * top-level row, 2026-09-16). `leaf` stays supported for any future group of
 * one.
 */
export const SIDEBAR_GROUPS: SidebarGroup[] = [
  {
    id: 'cache',
    label: __('Cache'),
    icon: 'Zap',
    description: __('Page, object and browser caching, plus preload.'),
    slugs: ['cache', 'preloader', 'browser-cache', 'object-cache', 'cache-coverage', 'adaptive-cache'],
  },
  {
    id: 'performance',
    label: __('Optimization'),
    icon: 'Gauge',
    description: __('Minify, compress, lazy-load, fonts and hints.'),
    slugs: ['minify', 'gzip', 'lazy', 'resource-hints', 'fonts', 'self-host-fonts', 'turbo-render', 'bloat', 'heartbeat', 'script-manager'],
  },
  {
    id: 'network',
    label: __('Network'),
    icon: 'Network',
    description: __('CDN, your own Cloudflare zone and Cloudflare Enterprise.'),
    slugs: ['cdn', 'cloudflare', 'cloudflare-enterprise'],
  },
  {
    id: 'insights',
    label: __('Health & insights'),
    icon: 'HeartPulse',
    description: __('Speed score, diagnostics and analytics.'),
    slugs: ['health', 'score'],
  },
  {
    id: 'tools',
    label: __('Tools'),
    icon: 'Sliders',
    description: __('Database cleanup, migration and logs.'),
    slugs: ['database', 'migration', 'logs'],
  },
  {
    id: 'ai-agents',
    label: __('AI & agents'),
    icon: 'Sparkles',
    // MCP leads: it is the only card that connects an AI agent, it is Free,
    // and it needs no API key. Leading with the locked AI Provider card read
    // as "connect a provider first", which is the support question this order
    // exists to stop. Hub is not a card here — it lives inside the MCP panel.
    description: __('Connect an AI agent to this site, and control what AI features may use.'),
    fullDescription: true,
    slugs: ['mcp', 'ai-provider', 'ai-privacy'],
  },
  {
    id: 'settings',
    label: __('Settings'),
    icon: 'Settings',
    description: __('License, branding, multisite and support.'),
    // License / Branding / Multisite used to be tabs of a `settings`
    // container card (three clicks to the license form). They're now
    // cards in their own right, which also makes the legacy `#license`
    // deep link Pro's admin notices use resolvable — see nav.ts.
    slugs: ['license', 'white-label', 'multisite', 'privacy', 'support'],
  },
];

/** Ordered group ids (sidebar + routing order). */
export const GROUP_IDS: string[] = SIDEBAR_GROUPS.map((g) => g.id);

/** Group lookup by id. */
export function groupById(id: string): SidebarGroup | undefined {
  return SIDEBAR_GROUPS.find((g) => g.id === id);
}

/**
 * The group id that owns a module slug (first match wins) — used to resolve
 * legacy `#slug` deep links into the new `#/<group>/<slug>` scheme and to
 * highlight the active group. `logs` resolves to Tools even though it has no
 * registered module.
 */
export function groupIdForSlug(slug: string): string | undefined {
  return SIDEBAR_GROUPS.find((g) => g.slugs.includes(slug))?.id;
}

/**
 * Take the (possibly filtered) list of modules + map it into ordered
 * groups. A group with no resolved modules is dropped so the user
 * never sees an empty section header.
 *
 * Modules whose slug isn't in any group fall into a tail "Other"
 * group — keeps unknown modules visible without us having to know
 * about them ahead of time.
 */
export interface RenderedGroup {
  id: string;
  label: string;
  icon: string;
  modules: ModuleDescriptor[];
  /** Mirrors SidebarGroup.leaf — render as one standalone clickable row. */
  leaf?: boolean;
}

export function groupSidebarModules(
  modules: ModuleDescriptor[],
  // Retained for call-site compatibility. The subitem order is now FIXED —
  // always the curated order declared in each group's `slugs` — regardless of
  // whether Pro is installed, so the menu never re-sorts on Pro activation.
  // Previously an inactive-Pro build sank the locked Pro placeholders to the
  // bottom of each group, which made the order differ from the Pro-active
  // build. The PM wants one consistent order: the curated ("Pro") order.
  _proActive = true
): RenderedGroup[] {
  const bySlug = new Map(modules.map((m) => [m.slug, m]));
  const seen = new Set<string>();
  const out: RenderedGroup[] = [];

  for (const group of SIDEBAR_GROUPS) {
    const found: ModuleDescriptor[] = [];
    for (const slug of group.slugs) {
      const m = bySlug.get(slug);
      if (m) {
        found.push(m);
        seen.add(slug);
      }
    }
    if (found.length > 0) {
      // `found` is already in the curated `group.slugs` order — keep it as-is
      // in every Pro state (no free/pro re-partition).
      out.push({ id: group.id, label: group.label, icon: group.icon, modules: found, leaf: group.leaf });
    }
  }

  // Embedded Pro modules (surfaced inside a host page via ProSlot —
  // FBS-83633) are intentionally in no group. On Pro they're real
  // registered modules present in the payload, so without this they'd
  // fall into the "Other" bucket and reappear as standalone rows,
  // undoing the restructure. Exclude them from the orphan sweep.
  const orphans = modules.filter((m) => !seen.has(m.slug) && !EMBEDDED_SLUGS.has(m.slug));
  if (orphans.length > 0) {
    out.push({ id: 'other', label: 'Other', icon: 'Square', modules: orphans });
  }

  return out;
}
