import { useEffect, useState } from 'react';
import { Timer, Zap } from 'lucide-react';
import { api } from '../../api/client';
import { __ } from '../../i18n';
import type { Stats } from '../../types';

/**
 * Impact hero (DESIGN.md §24.14, issue #42): the initial screen's proof
 * of impact. Computes real visitor time saved from data the plugin
 * already stores — served hits × the latest benchmark's per-request
 * saving — for 24h / 7d / 30d windows. Empty state prompts a benchmark
 * run (the saving multiplier comes from it).
 */

interface RunRow {
  ts: number;
  savings_ms: number | null;
  cache_enabled: boolean;
}

interface DayRow {
  date: string;
  hits: number;
}

function fmtDuration(ms: number): string {
  const s = ms / 1000;
  if (s < 60) return `${s.toFixed(1)}s`;
  if (s < 3600) return `${(s / 60).toFixed(1)} ${__('min')}`;
  return `${(s / 3600).toFixed(1)} ${__('hrs')}`;
}

export function ImpactHero({ stats }: { stats: Stats }) {
  const [savingsMs, setSavingsMs] = useState<number | null | undefined>(undefined);
  const [days, setDays] = useState<DayRow[]>([]);

  useEffect(() => {
    (async () => {
      try {
        const [history, daily] = await Promise.all([
          api.request<{ runs: RunRow[] }>('/cache/benchmark/history'),
          api.request<{ days: DayRow[] }>('/stats/hit-daily?days=30'),
        ]);
        // Latest run with a real measured saving.
        const usable = history.runs.filter((r) => r.cache_enabled && r.savings_ms != null && r.savings_ms > 0);
        setSavingsMs(usable.length ? (usable[usable.length - 1].savings_ms as number) : null);
        setDays(daily.days);
      } catch {
        setSavingsMs(null);
      }
    })();
  }, []);

  if (savingsMs === undefined) return null;

  const hits24 = stats.hits_24h ?? 0;

  // Empty state: no benchmark delta to multiply by yet.
  if (savingsMs === null || hits24 === 0) {
    return (
      <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg p-4 sm:p-5 flex items-center gap-3">
        <Timer size={18} className="text-neutral-400 dark:text-neutral-500 shrink-0" />
        <div className="text-[12px] text-neutral-500 dark:text-neutral-400">
          {savingsMs === null
            ? __('Run the benchmark once and this card shows how much combined wait time the cache saves your visitors.')
            : __('Serving cached pages… impact numbers appear once today’s hits accumulate.')}
        </div>
      </div>
    );
  }

  const sumHits = (n: number) => days.slice(-n).reduce((acc, d) => acc + (d.hits ?? 0), 0);
  const windows = [
    { label: __('last 24h'), hits: hits24 },
    { label: __('7 days'), hits: sumHits(7) },
    { label: __('30 days'), hits: sumHits(30) },
  ];

  return (
    <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg p-4 sm:p-5">
      <div className="flex items-center gap-2 mb-1">
        <Zap size={16} className="text-accent shrink-0" />
        <span className="text-[11px] uppercase tracking-wider font-medium text-neutral-500 dark:text-neutral-400">
          {__('Real visitor impact')}
        </span>
      </div>
      <div className="text-neutral-900 dark:text-neutral-50">
        {__('xSpeed served')}{' '}
        <span className="font-semibold tabular-nums">{hits24.toLocaleString()}</span>{' '}
        {__('cached pages in 24h — saving your visitors')}{' '}
        <span className="font-semibold text-emerald-600 dark:text-emerald-400 tabular-nums">
          {fmtDuration(hits24 * savingsMs)}
        </span>{' '}
        {__('of combined wait time')}{' '}
        <span className="text-neutral-500 dark:text-neutral-400 text-[13px]">
          ({Math.round(savingsMs)}ms {__('faster per page')})
        </span>
      </div>
      <div className="flex flex-wrap gap-x-5 gap-y-1 mt-2 text-[12px] text-neutral-500 dark:text-neutral-400">
        {windows.slice(1).map((w) =>
          w.hits > 0 ? (
            <span key={w.label}>
              {w.label}:{' '}
              <span className="tabular-nums text-neutral-700 dark:text-neutral-300 font-medium">
                {fmtDuration(w.hits * savingsMs)}
              </span>{' '}
              {__('saved across')} {w.hits.toLocaleString()} {__('hits')}
            </span>
          ) : null
        )}
      </div>
    </div>
  );
}
