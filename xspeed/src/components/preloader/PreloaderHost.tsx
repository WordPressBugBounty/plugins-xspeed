import { useEffect } from 'react';
import { PreloaderPanel } from './PreloaderPanel';
import { Tabs, TabPanels } from '../Tabs';
import { ProSlot } from '../../upsell/ProSlot';
import { ProTabBody } from '../../upsell/ProTabBody';
import { useTier } from '../../upsell/useTier';
import { useHostTabs, type HostTab } from '../useHostTabs';
import type { PanelProps } from '../customPanels';

/**
 * Preloader host page (FBS-83633 restructure).
 *
 * All three tabs do the same job — warm the cache before a visitor
 * arrives — with different triggers:
 *   Manual     — crawl the sitemap now (Free, this module)
 *   Scheduled  — warm URLs ahead of a launch / sale / campaign (Pro)
 *   Predictive — warm the hottest URLs by traffic + recency (Pro)
 *
 * The existing PreloaderPanel (the manual crawl-control UI) is the
 * Manual tab; the Pro tabs embed via ProSlot. Tab state binds to the
 * compound hash (#preloader/<tab>).
 */

const TABS: HostTab[] = [
  { key: 'manual', slug: 'preloader', self: true },
  { key: 'scheduled', slug: 'prewarm-schedule', panel: 'PrewarmSchedulePanel', pro: true },
  { key: 'predictive', slug: 'predictive-warm', panel: 'PredictiveWarmPanel', pro: true },
];

export function PreloaderHost({ module, onSaved, subtab, onSubtabChange, getModule }: PanelProps) {
  const tier = useTier();
  const isPro = tier === 'pro' || tier === 'trial';
  const { items, active } = useHostTabs(TABS, { module, getModule, isPro, subtab });

  useEffect(() => {
    if (subtab && !items.some((t) => t.key === subtab)) onSubtabChange?.(TABS[0].key);
  }, [subtab, items, onSubtabChange]);

  return (
    <div className="space-y-5">
      <Tabs items={items} value={active} onChange={(k) => onSubtabChange?.(k)} idBase="preloader" />

      <TabPanels active={active} idBase="preloader">
        {active === 'manual' && <PreloaderPanel module={module} onSaved={onSaved} />}
        {active === 'scheduled' && (
          <ProTabBody demo="preloader-scheduled">
            <ProSlot panel="PrewarmSchedulePanel" slug="prewarm-schedule" getModule={getModule} onSaved={onSaved} />
          </ProTabBody>
        )}
        {active === 'predictive' && (
          <ProTabBody demo="preloader-predictive">
            <ProSlot panel="PredictiveWarmPanel" slug="predictive-warm" getModule={getModule} onSaved={onSaved} />
          </ProTabBody>
        )}
      </TabPanels>
    </div>
  );
}
