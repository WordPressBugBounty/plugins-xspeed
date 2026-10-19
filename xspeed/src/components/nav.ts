/**
 * Redesign v2 routing — a 3-level hash scheme wrapped AROUND the existing
 * module machinery (App still resolves a module slug → the same ModulePanel).
 *
 *   #/overview                     the default landing
 *   #/<group>                      a group landing (card grid)
 *   #/<group>/<module>[/<subtab>]  a module settings page (+ host-page tab)
 *   …?focus=<mod>:<field>&suggest= the notice-CTA deep-link (issue #49) rides on
 *                                  top of any of the above, unchanged.
 *
 * Legacy `#slug` / `#slug/subtab` links (bookmarks, WP-admin submenus, Health
 * notice CTAs) are still understood: parseRoute resolves them via the module's
 * group and flags `legacy` so App can canonicalize the URL to the new form
 * without dropping focus/subtab.
 */

import { GROUP_IDS, groupIdForSlug } from './sidebarGroups';
import { parseDeepLink } from './deepLink';

export interface Route {
  view: 'overview' | 'group' | 'module' | 'pro';
  group?: string;
  /** Module slug (or the pseudo-slug 'logs'). */
  module?: string;
  subtab?: string;
  focus?: string;
  suggest?: string;
  /** True when the source hash used the legacy `#slug` form → App rewrites it. */
  legacy?: boolean;
}

export function buildHash(r: Route): string {
  if (r.view === 'overview') return '#/overview';
  if (r.view === 'pro') return '#/pro';
  if (r.view === 'group') return `#/${r.group}`;
  let h = `#/${r.group}/${r.module}`;
  if (r.subtab) h += `/${r.subtab}`;
  return h;
}

/** Pull focus/suggest from a `?a=b&c=d` query fragment (either hash form). */
function parseQuery(query: string): { focus?: string; suggest?: string } {
  if (!query) return {};
  const p = new URLSearchParams(query);
  return { focus: p.get('focus') || undefined, suggest: p.get('suggest') ?? undefined };
}

/**
 * Legacy `#slug` hashes for modules that own no page of their own.
 *
 * A module whose controls render inside ANOTHER module's panel has a real,
 * registered slug but no route — `groupIdForSlug()` returns undefined and
 * parseRoute drops the user on Overview with no error. Adding such a slug to
 * `sidebarGroups.ts` would be worse than the bug: the module has an empty
 * settings_schema() and no custom panel, so the card would open a blank page.
 * The alias points at the page that actually renders its features instead.
 *
 * `migration-pro` is the case here: it is deliberately `hidden`, its controls
 * live in Free's Migration panel, and `Admin::modules_payload()` filters
 * hidden modules out entirely — so the slug never even reaches the React app.
 * (#85)
 */
const LEGACY_ALIASES: Record<string, { module: string; subtab?: string }> = {
  'migration-pro': { module: 'migration' },
};

/**
 * Retired routes → their new home. Fonts left the Media page for its own
 * Optimization card (#86), so `lazy/fonts` (either hash form) must land on
 * the Fonts page instead of silently falling back to the Lazy Loading tab.
 */
function retarget(r: Route): Route {
  if (r.module === 'lazy' && r.subtab === 'fonts') {
    return { ...r, group: groupIdForSlug('fonts') ?? r.group, module: 'fonts', subtab: '' };
  }
  return r;
}

/**
 * Parse a location.hash into a Route. Pure/structural — App validates that a
 * `module` actually exists and falls back to the group landing otherwise.
 */
export function parseRoute(rawHash: string): Route {
  const raw = (rawHash || '').replace(/^#/, '');
  const qIndex = raw.indexOf('?');
  const path = qIndex === -1 ? raw : raw.slice(0, qIndex);
  const query = qIndex === -1 ? '' : raw.slice(qIndex + 1);
  const { focus, suggest } = parseQuery(query);

  // New form: leading slash.
  if (path.startsWith('/')) {
    const segs = path.slice(1).split('/').filter(Boolean);
    if (segs.length === 0 || segs[0] === 'overview') {
      return { view: 'overview', focus, suggest };
    }
    // `#/pro` — the Pro discovery/comparison page (a peer of Overview, not a
    // sidebar group). Reached from the sidebar Upgrade button + locked rows.
    if (segs[0] === 'pro') {
      return { view: 'pro', focus, suggest };
    }
    if (GROUP_IDS.includes(segs[0])) {
      if (segs.length === 1) return { view: 'group', group: segs[0], focus, suggest };
      return retarget({ view: 'module', group: segs[0], module: segs[1], subtab: segs[2] || '', focus, suggest });
    }
    return { view: 'overview', focus, suggest };
  }

  // Legacy form: `#slug` / `#slug/subtab` (+ focus/suggest handled by parseDeepLink).
  const legacyTarget = parseDeepLink(rawHash);
  const rawSlug = legacyTarget.module;
  if (!rawSlug || rawSlug === 'overview') {
    return { view: 'overview', focus: legacyTarget.focus, suggest: legacyTarget.suggest, legacy: true };
  }
  // Resolve host-panel modules to the page that renders them, before the
  // group lookup that would otherwise fail and fall through to Overview.
  // Own keys only: `#constructor` and the other Object.prototype names are
  // not aliases (they already fell through to Overview; this says so).
  const alias = Object.prototype.hasOwnProperty.call(LEGACY_ALIASES, rawSlug)
    ? LEGACY_ALIASES[rawSlug]
    : undefined;
  const slug = alias ? alias.module : rawSlug;
  if (alias?.subtab && !legacyTarget.subtab) {
    legacyTarget.subtab = alias.subtab;
  }
  const group = groupIdForSlug(slug);
  if (group) {
    return retarget({
      view: 'module',
      group,
      module: slug,
      subtab: legacyTarget.subtab ?? '',
      focus: legacyTarget.focus,
      suggest: legacyTarget.suggest,
      legacy: true,
    });
  }
  // Unknown legacy slug → land on Overview.
  return { view: 'overview', focus: legacyTarget.focus, suggest: legacyTarget.suggest, legacy: true };
}
