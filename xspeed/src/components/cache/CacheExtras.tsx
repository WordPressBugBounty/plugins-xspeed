import { useEffect } from 'react';
import { Tabs, TabPanels, type TabItem } from '../Tabs';
import { ProSlot } from '../../upsell/ProSlot';
import { ProDemo } from '../../upsell/ProDemo';
import { useTier } from '../../upsell/useTier';
import type { ModuleDescriptor } from '../../types';

/**
 * Cache coverage tabs (FBS-83633 Phase 4 + Phase 5 de-clutter).
 *
 * The Pro features that extend Page Cache beyond the default HTML-for-
 * anonymous-visitors behaviour. They were six separate locked sidebar
 * rows; now they're tabs INSIDE the Cache page (one group visible at a
 * time so the page doesn't stack six panels):
 *   Coverage    — 404 Caching, Search Caching
 *   Beyond HTML — Feed Caching, REST API Cache
 *   Cache Rules — Custom Cache Rules, Maintenance-mode bypass
 *
 * Rendered below the core Cache settings. Tab state binds to the
 * compound hash (#cache/<tab>) via the same subtab plumbing the
 * ModulePanel hosts use — App threads it in.
 */

const TAB_ITEMS: TabItem[] = [
  // User-facing labels (not the engine's internal names): what the cache
  // covers, the non-HTML responses it can cache, and the bypass rules.
  { key: 'coverage', label: 'What to cache', locked: true },
  { key: 'beyond', label: 'Feeds & API', locked: true },
  { key: 'rules', label: 'Rules & bypass', locked: true },
];
const DEFAULT_TAB = 'coverage';
const VALID = new Set(TAB_ITEMS.map((t) => t.key));

export function CacheExtras({
  getModule,
  onSaved,
  subtab,
  onSubtabChange,
}: {
  getModule: (slug: string) => ModuleDescriptor | undefined;
  onSaved?: (saved: Record<string, unknown>) => void;
  subtab?: string;
  onSubtabChange?: (key: string) => void;
}) {
  const tier = useTier();
  const isPro = tier === 'pro' || tier === 'trial';
  const items = TAB_ITEMS.map((t) => ({ ...t, locked: t.locked && !isPro }));
  const active = subtab && VALID.has(subtab) ? subtab : DEFAULT_TAB;

  useEffect(() => {
    if (subtab && !VALID.has(subtab)) onSubtabChange?.(DEFAULT_TAB);
  }, [subtab, onSubtabChange]);

  return (
    <div className="space-y-4">
      <Tabs items={items} value={active} onChange={(k) => onSubtabChange?.(k)} idBase="cache-extras" />

      <TabPanels active={active} idBase="cache-extras">
        {/* Free/unlicensed sees ONE demonstration section per tab instead of
            a stack of lock cards; Pro renders the real sub-panels. */}
        {active === 'coverage' &&
          (isPro ? (
            <div className="space-y-4">
              <ProSlot slug="cache-404" getModule={getModule} onSaved={onSaved} />
              <ProSlot slug="search-cache" getModule={getModule} onSaved={onSaved} />
            </div>
          ) : (
            <ProDemo id="coverage-what" />
          ))}
        {active === 'beyond' &&
          (isPro ? (
            <div className="space-y-4">
              <ProSlot slug="feed-cache" getModule={getModule} onSaved={onSaved} />
              <ProSlot panel="RestCachePanel" slug="rest-cache" getModule={getModule} onSaved={onSaved} />
            </div>
          ) : (
            <ProDemo id="coverage-beyond" />
          ))}
        {active === 'rules' &&
          (isPro ? (
            <div className="space-y-4">
              <ProSlot panel="CustomRulesPanel" slug="custom-rules" getModule={getModule} onSaved={onSaved} />
              <ProSlot panel="MaintenanceCachePanel" slug="maintenance-cache" getModule={getModule} onSaved={onSaved} />
            </div>
          ) : (
            <ProDemo id="coverage-rules" />
          ))}
      </TabPanels>
    </div>
  );
}
