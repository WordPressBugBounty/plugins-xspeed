import { useState } from 'react';
import { Zap, RefreshCw, AlertCircle, Clock, Database } from 'lucide-react';
import { api } from '../../api/client';
import { __ } from '../../i18n';

interface BenchmarkResp {
  url: string;
  without_cache: { ttfb_ms: number; time_ms: number; bytes: number; status: number };
  with_cache: { ttfb_ms: number; time_ms: number; bytes: number; status: number; was_hit: boolean };
  savings_pct: number | null;
  savings_ms: number | null;
  cache_enabled: boolean;
}

/**
 * Side-by-side "before cache vs after cache" comparison. Hits the
 * synthetic /cache/benchmark endpoint which fetches home_url() twice
 * (one with X-XSpeed-Bypass: 1 to force a fresh render, one without
 * to hit the cache) and returns timings.
 *
 * Rendered on the wizard Done step + Cache panel header. The numbers
 * are directional, not authoritative — RUM is the real measurement.
 */
export function CacheBenchmark({ compact = false }: { compact?: boolean }) {
  const [data, setData] = useState<BenchmarkResp | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const run = async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await api.request<BenchmarkResp>('/cache/benchmark');
      setData(res);
    } catch (e) {
      setError(e instanceof Error ? e.message : __('Benchmark failed'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg p-4 sm:p-5">
      <div className="flex flex-wrap items-center justify-between gap-3 mb-3">
        <div className="flex items-center gap-3 min-w-0">
          <Zap size={18} className="text-accent shrink-0" />
          <div className="min-w-0">
            <div className="font-medium text-neutral-900 dark:text-neutral-50">
              {__('Cache impact benchmark')}
            </div>
            <div className="text-[12px] text-neutral-500 dark:text-neutral-400">
              {__('Compare your homepage with and without cache.')}
            </div>
          </div>
        </div>
        <button
          type="button"
          onClick={run}
          disabled={busy}
          className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-accent bg-accent text-white hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
        >
          {busy ? (
            <RefreshCw size={13} className="animate-spin" />
          ) : (
            <Zap size={13} />
          )}
          {data ? __('Re-run') : __('Run benchmark')}
        </button>
      </div>

      {error && (
        <div className="text-[13px] text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900/60 rounded p-2.5 flex items-start gap-2 mb-3">
          <AlertCircle size={14} className="shrink-0 mt-0.5" />
          {error}
        </div>
      )}

      {data && (
        <>
          <div className="grid grid-cols-2 gap-3">
            <Card
              label={__('Without cache')}
              tone="warn"
              ms={data.without_cache.time_ms}
              bytes={data.without_cache.bytes}
            />
            <Card
              label={__('With cache')}
              tone="ok"
              ms={data.with_cache.time_ms}
              bytes={data.with_cache.bytes}
            />
          </div>

          {data.savings_pct !== null && data.savings_ms !== null && (
            <div className="mt-3 text-center text-[13px]">
              {data.savings_pct > 5 ? (
                <span className="text-emerald-700 dark:text-emerald-300 font-medium">
                  ⚡ {__('Cache is')} <span className="tabular-nums">{data.savings_pct.toFixed(0)}%</span> {__('faster')}
                  {' '}({data.savings_ms.toFixed(0)} ms {__('saved per request')})
                </span>
              ) : (
                <span className="text-neutral-500 dark:text-neutral-400">
                  {__('Negligible difference — page is cheap to render or cache is cold.')}
                </span>
              )}
            </div>
          )}

          {!data.cache_enabled && (
            <div className="mt-3 text-[12px] text-amber-700 dark:text-amber-300 text-center">
              {__('Cache is disabled — both runs hit the same uncached path. Enable Cache to see a real difference.')}
            </div>
          )}

          {!compact && (
            <div className="mt-3 text-[11px] text-neutral-400 text-center font-mono truncate">
              {data.url}
            </div>
          )}
        </>
      )}

      {!data && !busy && !error && (
        <div className="text-[12px] text-neutral-500 dark:text-neutral-400 text-center py-3">
          {__('Click "Run benchmark" to compare load times.')}
        </div>
      )}
    </div>
  );
}

function Card({
  label,
  tone,
  ms,
  bytes,
}: {
  label: string;
  tone: 'ok' | 'warn';
  ms: number;
  bytes: number;
}) {
  const numColor =
    tone === 'ok'
      ? 'text-emerald-600 dark:text-emerald-400'
      : 'text-amber-600 dark:text-amber-400';
  const dotColor =
    tone === 'ok' ? 'bg-emerald-500' : 'bg-amber-500';
  return (
    <div className="border border-neutral-200 dark:border-neutral-800 rounded p-3">
      <div className="flex items-center gap-2 mb-2">
        <span className={`w-2 h-2 rounded-full ${dotColor}`} />
        <span className="text-[11px] uppercase tracking-wider text-neutral-500 dark:text-neutral-400 font-medium">
          {label}
        </span>
      </div>
      <div className={`text-2xl font-semibold tabular-nums ${numColor}`}>
        {Math.round(ms)}<span className="text-[13px] font-normal text-neutral-500 dark:text-neutral-400 ml-1">ms</span>
      </div>
      <div className="flex items-center gap-2 mt-2 text-[11px] text-neutral-500 dark:text-neutral-400">
        <Clock size={11} />
        <span className="tabular-nums">{Math.round(ms)} ms</span>
        <span className="text-neutral-300 dark:text-neutral-700">·</span>
        <Database size={11} />
        <span className="tabular-nums">{(bytes / 1024).toFixed(1)} KB</span>
      </div>
    </div>
  );
}
