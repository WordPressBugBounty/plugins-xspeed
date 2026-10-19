import { useEffect } from 'react';
import { SchemaModulePanel } from '../ModulePanel';
import { Tabs, TabPanels } from '../Tabs';
import { ProSlot } from '../../upsell/ProSlot';
import { ProTabBody } from '../../upsell/ProTabBody';
import { useTier } from '../../upsell/useTier';
import { useHostTabs, type HostTab } from '../useHostTabs';
import type { PanelProps } from '../customPanels';

/**
 * CSS & JavaScript host page (FBS-83633 restructure).
 *
 * The three CSS/JS optimizations were three sidebar rows; they're one
 * page now, as tabs:
 *   Minify       — strip + rewrite enqueued CSS/JS (Free, this module)
 *   Critical CSS — inline above-the-fold, defer the rest (Pro)
 *   Unused CSS   — prune a per-URL stylesheet, strip the originals (Pro)
 *
 * Minify is the host module, so its own schema form is the first tab;
 * the Pro tabs embed via ProSlot (real panel on Pro, LockedSection on
 * Free). The tab bar binds to the compound hash (#minify/<tab>).
 */

const TABS: HostTab[] = [
  { key: 'minify', slug: 'minify', self: true },
  { key: 'critical', slug: 'critical-css', panel: 'CriticalCssPanel', pro: true },
  { key: 'unused', slug: 'unused-css', panel: 'UnusedCssPanel', pro: true },
];

export function CssJsPanel({ module, onSaved, subtab, onSubtabChange, getModule }: PanelProps) {
  const tier = useTier();
  const isPro = tier === 'pro' || tier === 'trial';
  const { items, active } = useHostTabs(TABS, { module, getModule, isPro, subtab });

  useEffect(() => {
    if (subtab && !items.some((t) => t.key === subtab)) onSubtabChange?.(TABS[0].key);
  }, [subtab, items, onSubtabChange]);

  return (
    <div className="space-y-5">
      <Tabs items={items} value={active} onChange={(k) => onSubtabChange?.(k)} idBase="cssjs" />

      <TabPanels active={active} idBase="cssjs">
        {active === 'minify' && <SchemaModulePanel module={module} onSaved={onSaved} hoistedNotices />}
        {active === 'critical' && (
          <ProTabBody demo="cssjs-critical">
            <ProSlot panel="CriticalCssPanel" slug="critical-css" getModule={getModule} onSaved={onSaved} />
          </ProTabBody>
        )}
        {active === 'unused' && (
          <ProTabBody demo="cssjs-unused">
            <ProSlot panel="UnusedCssPanel" slug="unused-css" getModule={getModule} onSaved={onSaved} />
          </ProTabBody>
        )}
      </TabPanels>
    </div>
  );
}
