import { getProPanel, usePanelsVersion } from '../components/proPanels';
import { SchemaModulePanel } from '../components/ModulePanel';
import { MissingPanel } from '../components/MissingPanel';
import { useTier } from './useTier';
import { LockedSection } from './LockedSection';
import { PRO_MODULE_MANIFEST } from './proModuleManifest';
import type { ModuleDescriptor } from '../types';

/**
 * Embed a Pro sub-panel inside a Free host page (FBS-83633 restructure).
 *
 * A host page (Compression / Cloudflare / Health) renders the same
 * tabs/sections regardless of tier — this slot fills a Pro sub-part
 * with the real registered Pro panel when the site is licensed, and a
 * <LockedSection> teaser otherwise. That is what makes "the sidebar
 * doesn't change on upgrade" true: nothing appears or disappears; a
 * section unlocks in place.
 *
 * Resolution order:
 *   1. tier !== pro         → LockedSection (upsell / activate-license)
 *   2. sibling descriptor missing → LockedSection (defensive)
 *   3. Pro panel registered → the Pro panel, fed its OWN module descriptor
 *   4. Pro panel unregistered → MissingPanel (placeholder until the page
 *      has loaded, then a danger Callout)
 *
 * `slug` is the Pro module slug (e.g. 'analytics') used to look up its
 * descriptor + its LockedSection copy. `panel` is the registered panel
 * NAME (the module's `custom_panel`, e.g. 'AnalyticsPanel'); omit it for
 * a schema-only Pro module (e.g. Brotli) — the slot then renders the
 * module's schema fields directly, the same form ModulePanel would.
 */
export function ProSlot({
  panel,
  slug,
  getModule,
  onSaved,
  title,
  description,
  icon,
}: {
  panel?: string;
  slug: string;
  /** Sibling-descriptor lookup, threaded from the host via PanelProps. */
  getModule?: (slug: string) => ModuleDescriptor | undefined;
  onSaved?: (saved: Record<string, unknown>) => void;
  /**
   * LockedSection copy overrides for a slug with no manifest entry
   * (a Pro slug with no PRO_MODULE_MANIFEST entry).
   * Ignored once the real panel renders.
   */
  title?: string;
  description?: string;
  icon?: string;
}) {
  // Re-render when a Pro panel registers after mount (Pro bundle boots
  // after Free's) or when the tier flips (license activate/deactivate).
  usePanelsVersion();
  const tier = useTier();

  const manifest = PRO_MODULE_MANIFEST.find((m) => m.slug === slug);
  const locked = (
    <LockedSection
      icon={icon ?? manifest?.icon ?? 'Sparkles'}
      title={title ?? manifest?.label ?? slug}
      description={description ?? manifest?.description}
      teaser={manifest?.teaser ?? undefined}
    />
  );

  if (tier !== 'pro' && tier !== 'trial') return locked;

  const descriptor = getModule?.(slug);
  if (!descriptor) return locked;

  // Registered custom panel wins; otherwise fall back to the schema
  // renderer for schema-only Pro modules (e.g. Brotli, which ships no
  // React panel on either side).
  const Pro = panel ? getProPanel(panel) : undefined;
  if (Pro) return <Pro module={descriptor} onSaved={onSaved} />;
  if (!panel) return <SchemaModulePanel module={descriptor} onSaved={onSaved} />;
  // A panel name was given but is not registered. Before the page has
  // loaded, Pro's script has not run yet: placeholder. After, it never
  // will: error. usePanelsVersion re-renders us if it arrives either way.
  return <MissingPanel />;
}
