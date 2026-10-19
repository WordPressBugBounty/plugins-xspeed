import { useMemo } from 'react';
import type { TabItem } from './Tabs';
import type { ModuleDescriptor } from '../types';
import { PRO_MODULE_MANIFEST } from '../upsell/proModuleManifest';

/**
 * Free-side label mirror for Pro child slugs. When Pro is DEACTIVATED, its
 * modules aren't registered, so `getModule(slug)` is undefined — without
 * this the tab would fall back to the raw slug ("smart-image") and the tab
 * label would differ from the Pro-active state ("AI Suggestions"). The
 * manifest carries every Pro module's label on Free precisely so the tab
 * bar reads identically in both states ("the sidebar doesn't change on
 * upgrade").
 */
const MANIFEST_LABELS: Record<string, string> = Object.fromEntries(
  PRO_MODULE_MANIFEST.map((m) => [m.slug, m.label]),
);

/**
 * Host-tab spec (FBS-83633). A host page (Media, Compression, …) declares
 * its tabs by the MODULE each opens, not by a hardcoded label — so the tab
 * text always equals that module's label. One source of truth: rename a
 * module's label and its sidebar name, its tab, and its search hit all move
 * together. No hardcoded tab strings to drift out of sync.
 */
export interface HostTab {
  /** Stable tab key (compound-hash suffix). */
  key: string;
  /** The module slug this tab opens (its label becomes the tab label). */
  slug: string;
  /**
   * The host module's OWN tab (the first tab = this page's base module).
   * Its label comes from the host module's `tab_label` (a short name for
   * the page's own view) rather than the module label (which is the page
   * title). Falls back to the page label if `tab_label` is unset.
   */
  self?: boolean;
  /** A Pro tab (shows a crown when the site is on Free). */
  pro?: boolean;
  /** Pro panel registry name (informational; the panel wires ProSlot). */
  panel?: string;
}

/**
 * Resolve host tabs to `TabItem`s: label from the module descriptor,
 * locked flag from tier. `active` is the current tab clamped to a valid
 * key (falls back to the first tab).
 */
export function useHostTabs(
  tabs: HostTab[],
  opts: {
    /** The host module descriptor (drives the `self` tab's label). */
    module: ModuleDescriptor;
    getModule?: (slug: string) => ModuleDescriptor | undefined;
    isPro: boolean;
    subtab?: string;
  },
): { items: TabItem[]; active: string } {
  const { module, getModule, isPro, subtab } = opts;

  const items = useMemo<TabItem[]>(() => {
    return tabs.map((t) => {
      let label: string;
      if (t.self) {
        // The host's own tab: prefer its short `tab_label`, else the page
        // label (module.label).
        label = (module.tab_label as string | undefined) ?? module.label ?? t.slug;
      } else {
        // Child tab: label IS the child module's label (single source).
        // When Pro is deactivated the module isn't registered, so fall back
        // to the Free-side manifest label — NOT the raw slug — so the tab
        // reads the same in both tier states.
        const child = getModule?.(t.slug);
        label = child?.label ?? MANIFEST_LABELS[t.slug] ?? t.slug;
      }
      return { key: t.key, label, locked: !!t.pro && !isPro };
    });
  }, [tabs, module, getModule, isPro]);

  const active = subtab && items.some((t) => t.key === subtab) ? subtab : (tabs[0]?.key ?? '');
  return { items, active };
}
