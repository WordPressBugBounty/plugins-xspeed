import { HeroStatus } from './HeroStatus';
import { HubCard } from './HubCard';
import { AiControlCard } from './AiControlCard';
import { MetricGrid } from './MetricGrid';
import { Recommendations } from './Recommendations';
import { SpeedTestCard } from '../score/SpeedTestCard';
import { FeatureMap } from './FeatureMap';
import { ActivityStrip } from './ActivityStrip';
import { OverviewFooter } from './OverviewFooter';
import type { Settings, Stats, RewriteProbe, ModuleDescriptor } from '../../types';

/**
 * The Overview landing screen — a calm, at-a-glance map across xSpeed's
 * feature modules so a new user lands somewhere oriented instead of in a wall
 * of feature settings.
 *
 * Zones: 1 hero (cache status + Hub), 1b AI/MCP control, 2 key metrics,
 * 3 recommendations (the only tinted zone — free actionable fixes, Pro-audit notes, and a conditional
 * setup-review offer), 4 curated feature map, 5 recent activity + docs/support.
 * Recommendations/audit/activity load on demand via existing (and one new,
 * /activity) REST routes rather than bloating the bootstrap payload.
 */
export function Overview({
  settings,
  stats,
  rewriteProbe,
  modules,
  onNavigate,
  showToast,
}: {
  settings: Settings;
  stats: Stats;
  rewriteProbe: RewriteProbe | null;
  modules: ModuleDescriptor[];
  onNavigate: (slug: string) => void;
  showToast: (msg: string) => void;
}) {
  return (
    <div className="space-y-6">
      {/* Zone 1 — hero: cache status + optional Hub connect. Purge/Preload are
          in the persistent top bar now, not here. */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <HeroStatus settings={settings} stats={stats} onNavigate={onNavigate} />
        <HubCard onNavigate={onNavigate} />
      </div>

      {/* Zone 1b — MCP. Between the hero and the metrics deliberately: high
          enough that nobody has to go looking for it (it used to be three
          clicks deep, under AI & agents), but below the cache status, which is
          still what this page is primarily reporting. Opens the real MCP panel
          in a drawer rather than navigating away. */}
      <AiControlCard modules={modules} />

      {/* Zone 2 — key metrics. */}
      <MetricGrid stats={stats} />

      {/* Zone 2b — an INDEPENDENT score, next to our own numbers. This is the
          page every install lands on, which is the only place "free GTmetrix
          testing" gets discovered; on a module page it would be found by
          people who already went looking. Renders nothing when the Hub can't
          serve a test and the user can't fix that from here. */}
      <SpeedTestCard onOpenPanel={() => onNavigate('score')} />

      {/* Zone 3 — priority recommendations (the only tinted zone). */}
      <Recommendations
        settings={settings}
        stats={stats}
        rewriteProbe={rewriteProbe}
        showToast={showToast}
      />

      {/* Zone 4 — curated feature map, grouped like the sidebar. */}
      <FeatureMap modules={modules} onNavigate={onNavigate} />

      {/* Zone 5 — recent activity + docs/support. */}
      <ActivityStrip />
      <OverviewFooter />
    </div>
  );
}
