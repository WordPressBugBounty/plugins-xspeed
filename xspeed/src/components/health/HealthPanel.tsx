import { useEffect } from 'react';
import { HealthCard } from './HealthCard';
import { HealthHero } from './HealthHero';
import { Tabs, TabPanels, type TabItem } from '../Tabs';
import { ProSlot } from '../../upsell/ProSlot';
import { ProTabBody } from '../../upsell/ProTabBody';
import { useProLicensed, useTier } from '../../upsell/useTier';
import type { PanelProps } from '../customPanels';

/**
 * Health host page (FBS-83633 restructure).
 *
 * The old sidebar had 5 "Insights" rows (Health, Analytics, RUM,
 * PageSpeed, Performance Health) + Recommendations — but only three real
 * data sources: xSpeed's own cache data, the visitor RUM beacon, and the
 * Google PageSpeed API. The menu was describing the code's structure, not
 * the user's mental model. This collapses them into one page:
 *
 *   - a Recommendations action card at the top (config scan → apply)
 *   - three tabs matching the three data sources:
 *       Cache     — xSpeed cache diagnostics (Free) + Analytics trends (Pro)
 *       Visitors  — RUM beacon + real-user metrics (Pro)
 *       PageSpeed — deeper PSI reporting + CWV health (Pro). Free's own
 *                   on-demand audit is the "External Score" card in this
 *                   group, not a second copy in here (#111).
 *
 * Each Pro sub-part is a ProSlot: the real Pro panel when licensed, a
 * LockedSection teaser otherwise. The tab bar binds to the compound URL
 * hash (#health/<tab>) so a tab is deep-linkable.
 *
 * The PageSpeed tab is the exception: it is shown only when Pro is active
 * and licensed (#465). Its locked teaser, whether "Upgrade to Pro" or
 * "Activate license", sold a PageSpeed audit one click away from the free
 * one under Speed Test, so an unlicensed site gets no tab rather than an
 * upsell for something it already has.
 */

const TAB_ITEMS: TabItem[] = [
  // "Overview" not "Cache" — a "Cache" tab under Health collides with the
  // Cache sidebar page. These tabs are conceptual groupings of several
  // modules (each searchable by its own label), so they stay curated.
  { key: 'cache', label: 'Overview' },
  { key: 'visitors', label: 'Visitors', locked: true },
  { key: 'pagespeed', label: 'PageSpeed', locked: true },
];
const DEFAULT_TAB = 'cache';

export function HealthPanel({ module, onSaved, subtab, onSubtabChange, getModule }: PanelProps) {
  const tier = useTier();
  const isPro = tier === 'pro' || tier === 'trial';
  const licensed = useProLicensed();

  // Without a licensed Pro the PageSpeed tab is dropped, not locked (#465):
  // its teaser duplicated the free Speed Test. Pro unlocks the remaining
  // tab locks (Visitors/PageSpeed are real, not teasers).
  const items = TAB_ITEMS.filter((t) => licensed || t.key !== 'pagespeed').map((t) => ({
    ...t,
    locked: t.locked && !isPro,
  }));
  const valid = new Set(items.map((t) => t.key));

  // Active tab from the compound hash, clamped to a visible key so a stale
  // or hand-typed #health/xyz — or a #health/pagespeed link opened on a
  // site without a licensed Pro — falls back to the default rather than a blank body.
  const active = subtab && valid.has(subtab) ? subtab : DEFAULT_TAB;

  // Normalize an out-of-range hash back to the default once, so the URL
  // and the rendered tab agree (and back/forward stays sane).
  useEffect(() => {
    if (subtab && subtab !== active) onSubtabChange?.(DEFAULT_TAB);
  }, [subtab, active, onSubtabChange]);

  return (
    <div className="space-y-5">
      {/* Redesign v2 — hit-ratio ring + key metrics (incl. cache coverage). */}
      <HealthHero getModule={getModule} />

      {/* Recommendations — an action surface (scan config, one-click
          apply), so it's a card at the top, not a tab. */}
      <ProSlot panel="RecommendationsPanel" slug="recommendations" getModule={getModule} onSaved={onSaved} />

      <Tabs items={items} value={active} onChange={(k) => onSubtabChange?.(k)} idBase="health" />

      <TabPanels active={active} idBase="health">
        {active === 'cache' && (
          <div className="space-y-5">
            {/* xSpeed's own cache diagnostics — Free. */}
            <HealthCard module={module} />
            {/* Analytics trend charts read the same cache data — Pro. */}
            <ProSlot panel="AnalyticsPanel" slug="analytics" getModule={getModule} onSaved={onSaved} />
          </div>
        )}

        {active === 'visitors' && (
          <ProTabBody demo="health-visitors">
            <div className="space-y-5">
              {/* RUM beacon config + per-metric real-user summary — Pro. */}
              <ProSlot panel="RumPanel" slug="rum" getModule={getModule} onSaved={onSaved} />
            </div>
          </ProTabBody>
        )}

        {active === 'pagespeed' && (
          <div className="space-y-5">
            {/* Free's own audit surface (ScorePanel) is deliberately NOT here —
                it owns the "External Score" card in this group, and rendering
                it here too gave the same settings form and run history two
                homes (#111). Pro's deeper reporting is what this tab is for. */}
            {/* Deeper PSI reporting — Pro. */}
            <ProSlot panel="PageSpeedPanel" slug="pagespeed" getModule={getModule} onSaved={onSaved} />
            {/* CWV composite health + AI anomaly read — Pro. */}
            <ProSlot panel="PerformanceHealthPanel" slug="performance-health" getModule={getModule} onSaved={onSaved} />
          </div>
        )}
      </TabPanels>
    </div>
  );
}
