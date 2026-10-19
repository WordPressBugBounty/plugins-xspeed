import { useEffect } from 'react';
import { SchemaModulePanel } from '../ModulePanel';
import { Tabs, TabPanels } from '../Tabs';
import { ProSlot } from '../../upsell/ProSlot';
import { ProTabBody } from '../../upsell/ProTabBody';
import { useTier } from '../../upsell/useTier';
import { useHostTabs, type HostTab } from '../useHostTabs';
import type { PanelProps } from '../customPanels';

/**
 * Compression host page (FBS-83633 restructure).
 *
 * GZIP and Brotli are one decision with a fallback chain, so they share a
 * page — as tabs (labels derived from the module labels; searchable):
 *   GZIP (this module's tab_label) / Brotli (Pro, schema-only).
 */

const TABS: HostTab[] = [
  { key: 'gzip', slug: 'gzip', self: true },
  { key: 'brotli', slug: 'brotli-compression', pro: true },
];

export function CompressionPanel({ module, onSaved, subtab, onSubtabChange, getModule }: PanelProps) {
  const tier = useTier();
  const isPro = tier === 'pro' || tier === 'trial';
  const { items, active } = useHostTabs(TABS, { module, getModule, isPro, subtab });

  useEffect(() => {
    if (subtab && !items.some((t) => t.key === subtab)) onSubtabChange?.(TABS[0].key);
  }, [subtab, items, onSubtabChange]);

  return (
    <div className="space-y-5">
      <Tabs items={items} value={active} onChange={(k) => onSubtabChange?.(k)} idBase="compression" />

      <TabPanels active={active} idBase="compression">
        {active === 'gzip' && <SchemaModulePanel module={module} onSaved={onSaved} hoistedNotices />}
        {active === 'brotli' && (
          <ProTabBody demo="compression-brotli">
            <ProSlot slug="brotli-compression" getModule={getModule} onSaved={onSaved} />
          </ProTabBody>
        )}
      </TabPanels>
    </div>
  );
}
