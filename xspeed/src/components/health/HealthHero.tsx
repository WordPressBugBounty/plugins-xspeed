import { HitRatioRing } from './HitRatioRing';
import { fmtInt, fmtBytes, fmtPercent } from '../overview/format';
import { __ } from '../../i18n';
import type { ModuleDescriptor } from '../../types';

/**
 * Redesign v2 — the Health & insights summary hero: an animated hit-ratio ring
 * beside the key metrics, including the cache-coverage % (share of cache
 * features on). Reads the bootstrap stats; sits above the existing Health host
 * page (tabs, diagnostics, Recommendations) which is unchanged.
 */
export function HealthHero({ getModule }: { getModule?: (slug: string) => ModuleDescriptor | undefined }) {
  const bootstrap = window.XSpeedConfig?.bootstrap;
  const stats = bootstrap?.status?.stats;
  const cacheEnabled = bootstrap?.settings?.cache_enabled === true;
  const ringPct = parseInt(fmtPercent(stats?.hit_ratio), 10) || 0;

  // `active`, not `settings.enabled` — object-cache has never stored an
  // `enabled` key, so coverage counted it off on a site with a working
  // persistent cache. (The `cacheEnabled` line above is the same problem,
  // already worked around by reading the bootstrap directly.) (#363)
  const isOn = (slug: string) => getModule?.(slug)?.active === true;
  const cacheFeatures = [cacheEnabled, isOn('object-cache'), isOn('browser-cache'), isOn('preloader')];
  const coverage = Math.round((cacheFeatures.filter(Boolean).length / cacheFeatures.length) * 100);
  const traffic24 = (stats?.hits_24h ?? 0) + (stats?.misses_24h ?? 0);

  const metrics = [
    { label: __('Cache coverage'), value: `${coverage}%`, hint: __('of cache features on') },
    { label: __('Cached pages'), value: fmtInt(stats?.cached_pages), hint: __('served without PHP') },
    { label: __('Cache size'), value: fmtBytes(stats?.cache_size), hint: __('on disk') },
    { label: __('Requests (24h)'), value: fmtInt(traffic24), hint: __('hits + misses') },
  ];

  return (
    <section className="rounded-card border border-line bg-paper p-5" aria-label={__('Health summary')}>
      <div className="flex flex-col sm:flex-row items-center gap-6">
        <HitRatioRing
          percent={ringPct}
          // Same disclosure as the Overview tile: with the LiteSpeed Static
          // Fast Path on, hits are served below the counter and the ring's
          // number only covers the PHP layer. (QA on #513)
          label={stats?.static_hits_uncounted ? __('Hit ratio (PHP-served only)') : __('Hit ratio')}
        />
        <div className="flex-1 grid grid-cols-2 lg:grid-cols-4 gap-4 w-full">
          {metrics.map((m) => (
            <div key={m.label}>
              <p className="text-[12px] text-ink-soft">{m.label}</p>
              <p className="mt-0.5 font-display text-[20px] font-semibold text-ink tabular-nums">{m.value}</p>
              <p className="text-[11px] text-muted">{m.hint}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
