import type { ModuleDescriptor } from '../types';
import { PRO_MODULE_MANIFEST } from './proModuleManifest';

/**
 * Append placeholder Pro modules to the live module list when Pro
 * isn't installed. The placeholders carry `locked: true` so the
 * sidebar can grey them out + the right pane renders LockedPanel.
 *
 * An add-on sold separately (`soldSeparately`) gets its placeholder on any
 * tier while its module is missing, so a Pro too old to have it still shows
 * the add-on's card and page.
 *
 * If a Pro module slug is already in `modules` (because Pro IS
 * installed and registered it), the manifest entry is skipped —
 * never duplicate.
 *
 * Pure function so it tests easily.
 */
export function mergeProModules(
  modules: ModuleDescriptor[],
  tier: 'free' | 'pro' | 'trial',
): ModuleDescriptor[] {
  const presentSlugs = new Set(modules.map((m) => m.slug));
  const additions: ModuleDescriptor[] = PRO_MODULE_MANIFEST
    // Skip already-present slugs (Pro installed) AND embedded slugs
    // (surfaced inside a host page via ProSlot, not as their own row —
    // FBS-83633). Embedded entries keep their manifest data for the
    // host's LockedSection; they just don't get a sidebar row.
    .filter((entry) => !presentSlugs.has(entry.slug) && !entry.embedded)
    .filter((entry) => tier === 'free' || entry.soldSeparately)
    .map((entry) => ({
      slug: entry.slug,
      tier: 'pro' as const,
      version: '0.0.0', // unused for locked rows
      label: entry.label,
      icon: entry.icon,
      description: entry.description,
      settings: {},
      schema: {},
      locked: true,
    }));
  return [...modules, ...additions];
}
