import { useEffect, useState } from 'react';
import { api } from '../../api/client';
import { __ } from '../../i18n';
import { fmtInt, fmtPercent, fmtBytes } from './format';
import type { Stats } from '../../types';

/**
 * Zone 2 — the key metrics row. FOUR honest numbers: hit ratio, cached pages,
 * and cache size come straight from Cache::get_stats; "Avg saved" is the real
 * ms-per-request delta from the latest cache benchmark run
 * (Cache_Benchmark::history, savings_ms) — shown as a dash until a benchmark
 * has been run, never fabricated.
 */
export function MetricGrid({ stats }: { stats: Stats }) {
  // Latest benchmark run's real savings (ms). null → no run yet, or cache off.
  const [savedMs, setSavedMs] = useState<number | null | undefined>(undefined);

  useEffect(() => {
    let active = true;
    api
      .request<{ runs?: Array<{ savings_ms?: number | null }> }>('/cache/benchmark/history?limit=1')
      .then((r) => {
        if (!active) return;
        setSavedMs(r.runs && r.runs.length > 0 ? r.runs[0].savings_ms ?? null : null);
      })
      .catch(() => {
        if (active) setSavedMs(null);
      });
    return () => {
      active = false;
    };
  }, []);

  const savedValue =
    savedMs === undefined
      ? '—'
      : savedMs === null || savedMs <= 0
      ? '—'
      : savedMs >= 950
      ? `${(savedMs / 1000).toFixed(1)}s`
      : `${Math.round(savedMs)} ms`;

  const metrics: { label: string; value: string; hint: string }[] = [
    {
      label: __('Hit ratio'),
      value: fmtPercent(stats.hit_ratio),
      // LiteSpeed Static Fast Path serves hits the counter can't see, so a
      // bare low percentage here read as a broken cache — the Overview is
      // the first screen, and the explanatory note only lived on the Cache
      // page (QA on #513). Say what the number covers right on the tile.
      hint: stats.static_hits_uncounted
        ? __('PHP-served only — static hits are not counted')
        : __('last 24 hours'),
    },
    { label: __('Cached pages'), value: fmtInt(stats.cached_pages), hint: __('served without PHP') },
    { label: __('Cache size'), value: fmtBytes(stats.cache_size), hint: __('on disk') },
    { label: __('Avg saved'), value: savedValue, hint: __('per page load') },
  ];

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4" aria-label={__('Key metrics')}>
      {metrics.map((m) => (
        <div key={m.label} className="rounded-card border border-line bg-paper p-4">
          <p className="text-[13px] text-ink-soft">{m.label}</p>
          <p className="mt-1 font-display text-[24px] font-semibold text-ink tabular-nums">{m.value}</p>
          <p className="mt-0.5 text-[11px] text-muted">{m.hint}</p>
        </div>
      ))}
    </div>
  );
}
