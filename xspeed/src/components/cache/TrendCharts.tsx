import { useEffect, useState } from 'react';
import { TrendingUp, RefreshCw, History } from 'lucide-react';
import { api } from '../../api/client';
import { __ } from '../../i18n';

/**
 * Trend charts (DESIGN.md §24.13): hit-ratio over 7/30 days with the ≥80%
 * target band, and the benchmark-run history — both overlaid with
 * settings-change markers so the dashboard tells a causal story
 * ("expiry raised → hit ratio climbed").
 *
 * Data: GET /stats/hit-daily and GET /cache/benchmark/history — both
 * return their series plus the recent `settings_changed` activity events.
 */

interface DayRow {
  date: string;
  hits: number;
  misses: number;
  ratio: number;
}

interface RunRow {
  ts: number;
  uncached_ms: number;
  cached_ms: number;
  savings_ms: number | null;
  savings_pct: number | null;
}

interface ChangeEvent {
  ts: number;
  message: string;
}

export function TrendCharts() {
  const [days, setDays] = useState<DayRow[] | null>(null);
  const [runs, setRuns] = useState<RunRow[] | null>(null);
  const [changes, setChanges] = useState<ChangeEvent[]>([]);
  const [windowDays, setWindowDays] = useState<7 | 30>(30);
  const [busy, setBusy] = useState(false);

  const load = async () => {
    setBusy(true);
    try {
      const [daily, history] = await Promise.all([
        api.request<{ days: DayRow[]; changes: ChangeEvent[] }>('/stats/hit-daily?days=30'),
        api.request<{ runs: RunRow[]; changes: ChangeEvent[] }>('/cache/benchmark/history'),
      ]);
      setDays(daily.days);
      setRuns(history.runs);
      setChanges(daily.changes.length ? daily.changes : history.changes);
    } catch {
      setDays([]);
      setRuns([]);
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const visibleDays = (days ?? []).slice(-windowDays);
  const hasHitData = visibleDays.length >= 2;
  const hasRunData = (runs ?? []).length >= 2;

  return (
    // Anchor for the Hit Ratio stat card, which scrolls here rather than
    // rendering a second copy of this chart in a drill-down panel.
    <div
      data-xspeed-anchor="hit-trend"
      className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg p-4 sm:p-5 scroll-mt-6"
    >
      <div className="flex flex-wrap items-center justify-between gap-3 mb-3">
        <div className="flex items-center gap-3 min-w-0">
          <TrendingUp size={18} className="text-accent shrink-0" />
          <div className="min-w-0">
            <div className="font-medium text-neutral-900 dark:text-neutral-50">{__('Trends')}</div>
            <div className="text-[12px] text-neutral-500 dark:text-neutral-400">
              {__('Hit ratio and benchmark history, with your settings changes marked.')}
            </div>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <div className="flex rounded border border-neutral-200 dark:border-neutral-700 overflow-hidden text-[12px]">
            {([7, 30] as const).map((w) => (
              <button
                key={w}
                type="button"
                onClick={() => setWindowDays(w)}
                className={
                  // The wrapper is overflow-hidden, so an outline drawn
                  // outside the button is clipped away entirely. Ring inward.
                  // The active button is FILLED with primary, so a primary
                  // ring on it is invisible -- it rings in the text colour.
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset ' +
                  (windowDays === w
                    ? 'focus-visible:ring-on-primary px-2.5 py-1 bg-accent text-white'
                    : 'focus-visible:ring-primary px-2.5 py-1 text-neutral-600 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800')
                }
              >
                {w}d
              </button>
            ))}
          </div>
          <button
            type="button"
            onClick={load}
            disabled={busy}
            aria-label={__('Refresh trends')}
            className="p-1.5 rounded border border-neutral-200 dark:border-neutral-700 text-neutral-500 dark:text-neutral-400 hover:bg-neutral-50 dark:hover:bg-neutral-800 disabled:opacity-40"
          >
            <RefreshCw size={13} className={busy ? 'animate-spin' : ''} />
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        {/* Hit ratio trend + ≥80% target band */}
        <div className="border border-neutral-200 dark:border-neutral-800 rounded p-3">
          <div className="text-[11px] uppercase tracking-wider text-neutral-500 dark:text-neutral-400 font-medium mb-2">
            {__('Hit ratio')} · {windowDays}d
          </div>
          {hasHitData ? (
            <RatioChart rows={visibleDays} changes={changes} />
          ) : (
            <EmptyChart
              text={__('Trends appear after a couple of days of traffic. The daily series is being collected now.')}
            />
          )}
        </div>

        {/* Benchmark history */}
        <div className="border border-neutral-200 dark:border-neutral-800 rounded p-3">
          <div className="text-[11px] uppercase tracking-wider text-neutral-500 dark:text-neutral-400 font-medium mb-2">
            {__('Benchmark: cached vs uncached (ms)')}
          </div>
          {hasRunData ? (
            <RunsChart rows={runs ?? []} changes={changes} />
          ) : (
            <EmptyChart text={__('Run the benchmark at least twice to see a trend.')} />
          )}
        </div>
      </div>

      {/* Recent changes — the annotation legend */}
      {changes.length > 0 && (
        <div className="mt-3">
          <div className="flex items-center gap-1.5 text-[11px] uppercase tracking-wider text-neutral-500 dark:text-neutral-400 font-medium mb-1.5">
            <History size={11} /> {__('Recent changes')}
          </div>
          <ul className="space-y-0.5">
            {changes.slice(0, 5).map((c) => (
              <li key={`${c.ts}-${c.message}`} className="text-[12px] text-neutral-600 dark:text-neutral-300 truncate">
                <span className="text-neutral-400 dark:text-neutral-500 tabular-nums mr-2">
                  {new Date(c.ts * 1000).toLocaleDateString()}
                </span>
                {c.message}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

const W = 280;
const H = 80;
const PAD = 4;

function x_at(i: number, count: number): number {
  if (count <= 1) return PAD;
  return PAD + (i * (W - 2 * PAD)) / (count - 1);
}

/** Vertical dashed markers for settings changes inside the charted span. */
function ChangeMarkers({
  changes,
  t0,
  t1,
}: {
  changes: ChangeEvent[];
  t0: number;
  t1: number;
}) {
  if (t1 <= t0) return null;
  return (
    <>
      {changes
        .filter((c) => c.ts >= t0 && c.ts <= t1)
        .map((c) => {
          const x = PAD + ((c.ts - t0) / (t1 - t0)) * (W - 2 * PAD);
          return (
            <line
              key={`${c.ts}-${c.message}`}
              x1={x}
              x2={x}
              y1={PAD}
              y2={H - PAD}
              className="stroke-amber-500/70"
              strokeWidth="1"
              strokeDasharray="2 3"
            >
              <title>{c.message}</title>
            </line>
          );
        })}
    </>
  );
}

function RatioChart({ rows, changes }: { rows: DayRow[]; changes: ChangeEvent[] }) {
  const y_at = (ratio: number) => H - PAD - ratio * (H - 2 * PAD);
  const points = rows.map((r, i) => `${x_at(i, rows.length)},${y_at(r.ratio)}`).join(' ');
  const bandTop = y_at(1);
  const bandBottom = y_at(0.8);
  const t0 = Date.parse(rows[0]?.date ?? '') / 1000;
  const t1 = Date.parse(rows[rows.length - 1]?.date ?? '') / 1000 + 86399;
  const latest = rows[rows.length - 1];

  return (
    <div>
      <svg viewBox={`0 0 ${W} ${H}`} className="w-full h-20" role="img" aria-label={__('Hit ratio trend')}>
        {/* ≥80% target band */}
        <rect x={PAD} y={bandTop} width={W - 2 * PAD} height={bandBottom - bandTop} className="fill-emerald-500/10" />
        <line x1={PAD} x2={W - PAD} y1={bandBottom} y2={bandBottom} className="stroke-emerald-500/40" strokeWidth="1" strokeDasharray="3 3" />
        {Number.isFinite(t0) && Number.isFinite(t1) && <ChangeMarkers changes={changes} t0={t0} t1={t1} />}
        <polyline points={points} fill="none" className="stroke-accent" strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
        {rows.map((r, i) => (
          <circle key={r.date} cx={x_at(i, rows.length)} cy={y_at(r.ratio)} r="2" className="fill-accent">
            <title>{`${r.date} — ${(r.ratio * 100).toFixed(1)}% (${r.hits} hits / ${r.misses} misses)`}</title>
          </circle>
        ))}
      </svg>
      {latest && (
        <div className="mt-1 text-[12px] text-neutral-500 dark:text-neutral-400">
          {__('Latest')}:{' '}
          <span className={latest.ratio >= 0.8 ? 'text-emerald-600 dark:text-emerald-400 font-medium' : 'text-amber-600 dark:text-amber-400 font-medium'}>
            {(latest.ratio * 100).toFixed(1)}%
          </span>{' '}
          · {__('target ≥80%')}
        </div>
      )}
    </div>
  );
}

function RunsChart({ rows, changes }: { rows: RunRow[]; changes: ChangeEvent[] }) {
  const max = Math.max(...rows.map((r) => r.uncached_ms), 1);
  const y_at = (ms: number) => H - PAD - (ms / max) * (H - 2 * PAD);
  const line = (pick: (r: RunRow) => number) =>
    rows.map((r, i) => `${x_at(i, rows.length)},${y_at(pick(r))}`).join(' ');
  const t0 = rows[0]?.ts ?? 0;
  const t1 = rows[rows.length - 1]?.ts ?? 0;
  const latest = rows[rows.length - 1];

  return (
    <div>
      <svg viewBox={`0 0 ${W} ${H}`} className="w-full h-20" role="img" aria-label={__('Benchmark trend')}>
        {t1 > t0 && <ChangeMarkers changes={changes} t0={t0} t1={t1} />}
        <polyline points={line((r) => r.uncached_ms)} fill="none" className="stroke-amber-500" strokeWidth="1.5" strokeDasharray="4 3" />
        <polyline points={line((r) => r.cached_ms)} fill="none" className="stroke-emerald-500" strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
        {rows.map((r, i) => (
          <circle key={r.ts} cx={x_at(i, rows.length)} cy={y_at(r.cached_ms)} r="2" className="fill-emerald-500">
            <title>{`${new Date(r.ts * 1000).toLocaleString()} — ${Math.round(r.cached_ms)}ms cached vs ${Math.round(r.uncached_ms)}ms uncached`}</title>
          </circle>
        ))}
      </svg>
      <div className="mt-1 flex items-center gap-3 text-[12px] text-neutral-500 dark:text-neutral-400">
        <span className="inline-flex items-center gap-1">
          <span className="inline-block w-3 h-0.5 bg-emerald-500 rounded" /> {__('cached')}
        </span>
        <span className="inline-flex items-center gap-1">
          <span className="inline-block w-3 h-0.5 bg-amber-500 rounded" /> {__('uncached')}
        </span>
        {latest?.savings_ms != null && (
          <span className="ml-auto tabular-nums">−{Math.round(latest.savings_ms)}ms</span>
        )}
      </div>
    </div>
  );
}

function EmptyChart({ text }: { text: string }) {
  return (
    <div className="h-20 flex items-center justify-center text-center text-[12px] text-neutral-400 dark:text-neutral-500 px-4">
      {text}
    </div>
  );
}
