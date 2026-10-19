import { useEffect } from 'react';
import { SchemaModulePanel } from '../ModulePanel';
import { Tabs, TabPanels } from '../Tabs';
import { ProSlot } from '../../upsell/ProSlot';
import { ProTabBody } from '../../upsell/ProTabBody';
import { useTier } from '../../upsell/useTier';
import { useHostTabs, type HostTab } from '../useHostTabs';
import type { PanelProps } from '../customPanels';

/**
 * Media Optimization host page (FBS-83633 restructure).
 *
 * Everything a page LOADS as media — images, iframes, videos — on one
 * page, as tabs (one visible at a time). Tab labels come from the module
 * labels (single source of truth), so they stay in sync with the sidebar
 * + are searchable automatically:
 *   Lazy Loading (this module's tab_label) / Image Optimization (Pro) /
 *   AI Suggestions (Pro)
 *
 * Fonts used to be a fourth tab here. It now has exactly one home — its
 * own Optimization card at #/performance/fonts (#86) — and `nav.ts`
 * redirects the retired `lazy/fonts` route to it.
 */

const TABS: HostTab[] = [
  { key: 'lazy', slug: 'lazy', self: true },
  { key: 'images', slug: 'images', panel: 'ImagesPanel', pro: true },
  { key: 'smart', slug: 'smart-image', panel: 'SmartImagePanel', pro: true },
];

export function MediaPanel({ module, onSaved, subtab, onSubtabChange, getModule }: PanelProps) {
  const tier = useTier();
  const isPro = tier === 'pro' || tier === 'trial';
  const { items, active } = useHostTabs(TABS, { module, getModule, isPro, subtab });

  useEffect(() => {
    if (subtab && !items.some((t) => t.key === subtab)) onSubtabChange?.(TABS[0].key);
  }, [subtab, items, onSubtabChange]);

  return (
    <div className="space-y-5">
      <Tabs items={items} value={active} onChange={(k) => onSubtabChange?.(k)} idBase="media" />

      <TabPanels active={active} idBase="media">
        {active === 'lazy' && <SchemaModulePanel module={module} onSaved={onSaved} />}
        {active === 'images' && (
          <ProTabBody demo="media-formats">
            <ProSlot panel="ImagesPanel" slug="images" getModule={getModule} onSaved={onSaved} />
          </ProTabBody>
        )}
        {active === 'smart' && (
          <ProTabBody demo="media-smart">
            <ProSlot panel="SmartImagePanel" slug="smart-image" getModule={getModule} onSaved={onSaved} />
          </ProTabBody>
        )}
      </TabPanels>
    </div>
  );
}
