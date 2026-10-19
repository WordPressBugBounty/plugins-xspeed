import { CacheExtras } from './CacheExtras';
import type { PanelProps } from '../customPanels';

/**
 * Advanced Cache page (FBS-83633) — the Cache "Advanced Cache" sub-item.
 *
 * Renders the existing cache-coverage section (CacheExtras) unchanged:
 * the What to cache / Feeds & API / Rules & bypass tabs. This just gives
 * that section its own sidebar row instead of stacking it at the foot of
 * the Page Cache page.
 */
export function CacheCoveragePanel({ onSaved, subtab, onSubtabChange, getModule }: PanelProps) {
  return (
    <CacheExtras
      getModule={(slug) => getModule?.(slug)}
      subtab={subtab}
      onSubtabChange={onSubtabChange}
      onSaved={onSaved}
    />
  );
}
