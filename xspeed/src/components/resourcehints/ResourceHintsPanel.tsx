import { useEffect } from 'react';
import { SchemaModulePanel } from '../ModulePanel';
import { Tabs, TabPanels } from '../Tabs';
import { ProSlot } from '../../upsell/ProSlot';
import { ProTabBody } from '../../upsell/ProTabBody';
import { useTier } from '../../upsell/useTier';
import { useHostTabs, type HostTab } from '../useHostTabs';
import type { PanelProps } from '../customPanels';

/**
 * Resource Hints host page (FBS-83633).
 *
 * Tabs (labels derived from the module labels; searchable):
 *   Hints             — preload LCP hero, preconnect fonts (Free, this module's tab_label)
 *   Speculation Rules — SmartPredict prefetches the next likely page (Pro)
 */

const TABS: HostTab[] = [
  { key: 'hints', slug: 'resource-hints', self: true },
  { key: 'speculation', slug: 'smart-predict', panel: 'SmartPredictPanel', pro: true },
];

export function ResourceHintsPanel({ module, onSaved, subtab, onSubtabChange, getModule }: PanelProps) {
  const tier = useTier();
  const isPro = tier === 'pro' || tier === 'trial';
  const { items, active } = useHostTabs(TABS, { module, getModule, isPro, subtab });

  useEffect(() => {
    if (subtab && !items.some((t) => t.key === subtab)) onSubtabChange?.(TABS[0].key);
  }, [subtab, items, onSubtabChange]);

  return (
    <div className="space-y-5">
      <Tabs items={items} value={active} onChange={(k) => onSubtabChange?.(k)} idBase="resource-hints" />

      <TabPanels active={active} idBase="resource-hints">
        {active === 'hints' && <SchemaModulePanel module={module} onSaved={onSaved} />}
        {active === 'speculation' && (
          <ProTabBody demo="hints-speculation">
            <ProSlot panel="SmartPredictPanel" slug="smart-predict" getModule={getModule} onSaved={onSaved} />
          </ProTabBody>
        )}
      </TabPanels>
    </div>
  );
}
