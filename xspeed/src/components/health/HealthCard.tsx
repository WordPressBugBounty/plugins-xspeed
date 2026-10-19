import { useEffect, useState } from 'react';
import { Activity } from 'lucide-react';
import { HealthRow } from '../HealthRow';
import { CopySnippet } from '../CopySnippet';
import { InfoPopover } from '../InfoPopover';
import { api } from '../../api/client';
import type { ModuleDescriptor } from '../../types';
import { __ } from '../../i18n';

interface HealthCheck {
  id: string;
  tone: 'ok' | 'warn' | 'fail' | 'info';
  label: string;
  detail: string;
  // Optional copy-pasteable block rendered under the detail line.
  // Currently only the nginx-server-config check ships one; future
  // checks (CDN headers, server snippet for IIS) will reuse the
  // same field rather than each rolling their own panel.
  snippet?: string;
}

interface ActivityEntry {
  ts: number;
  type: string;
  message: string;
  severity: 'info' | 'warn' | 'error' | 'success';
}

interface HealthBucket {
  ts: number;
  hits: number;
  misses: number;
}

interface HealthPayload {
  checks: HealthCheck[];
  stats: {
    cached_pages: number;
    cache_size: number;
    last_purge: number;
    hits_24h: number;
    misses_24h: number;
    hit_ratio: number;
  };
  buckets: HealthBucket[];
  activity: ActivityEntry[];
}

function formatRelative(ts: number) {
  if (!ts) return 'Never';
  const diff = Math.floor(Date.now() / 1000) - ts;
  if (diff < 60) return `${diff}s ago`;
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  return `${Math.floor(diff / 86400)}d ago`;
}

const severityDot: Record<ActivityEntry['severity'], string> = {
  info: 'bg-neutral-300 dark:bg-neutral-600',
  warn: 'bg-amber-500',
  error: 'bg-red-500',
  success: 'bg-emerald-500',
};

export function HealthCard({ module: _module }: { module: ModuleDescriptor }) {
  const [data, setData] = useState<HealthPayload | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api
      .getModuleSettings<HealthPayload>('health')
      .then(setData)
      .catch((e) => setError(e instanceof Error ? e.message : 'Failed to load'));
  }, []);

  if (error) {
    return (
      <div className="text-[12px] text-red-600 dark:text-red-400">{error}</div>
    );
  }

  if (!data) {
    return (
      <div className="text-[13px] text-neutral-500 dark:text-neutral-400">Loading…</div>
    );
  }

  const { checks, stats, buckets, activity } = data;

  return (
    <div className="space-y-4">
      <HitRatioCard stats={stats} buckets={buckets} />

      <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg overflow-hidden">
        {checks.map((c, i) => (
          <div key={c.id}>
            <HealthRow
              first={i === 0}
              tone={c.tone}
              label={c.label}
              detail={c.detail}
              labelAfter={
                c.id === 'static_rewrite_nginx' ? (
                  <InfoPopover
                    title="Good to know"
                    body="You added this snippet to your nginx config yourself, so it's yours to manage — xSpeed never touches it. If you ever need to uninstall xSpeed, just remove this snippet from your nginx config first to keep everything tidy and your server running happily."
                  />
                ) : undefined
              }
            />
            {c.snippet && (
              <div className="px-4 pb-4 pt-1 border-b border-neutral-200 dark:border-neutral-800 last:border-b-0">
                <CopySnippet content={c.snippet} />
              </div>
            )}
          </div>
        ))}
      </div>

      <ActivityList activity={activity} />
    </div>
  );
}

function HitRatioCard({ stats, buckets }: { stats: HealthPayload['stats']; buckets: HealthBucket[] }) {
  const total24 = stats.hits_24h + stats.misses_24h;
  const ratioPct = total24 > 0 ? Math.round(stats.hit_ratio * 100) : 0;
  return (
    <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg p-5">
      <div className="flex items-center justify-between mb-3">
        <div className="text-[11px] uppercase tracking-wider text-neutral-500 dark:text-neutral-400 font-medium">
          Hit ratio · 24h
        </div>
        <Activity size={14} className="text-neutral-400 dark:text-neutral-500" />
      </div>
      <div className="flex items-baseline gap-3 mb-3">
        <div className="text-[28px] font-semibold text-neutral-900 dark:text-neutral-50 leading-none">
          {total24 > 0 ? `${ratioPct}%` : '—'}
        </div>
        <div className="text-[12px] text-neutral-500 dark:text-neutral-400">
          {total24 > 0
            ? `${stats.hits_24h} hits · ${stats.misses_24h} misses`
            : 'No traffic captured in the last 24h yet.'}
        </div>
      </div>
      <Sparkline buckets={buckets} />
    </div>
  );
}

function Sparkline({ buckets }: { buckets: HealthBucket[] }) {
  if (!buckets.length) {
    return <div className="h-8" />;
  }
  const w = 480;
  const h = 32;
  const max = Math.max(1, ...buckets.map((b) => b.hits + b.misses));
  const stepX = w / Math.max(buckets.length - 1, 1);
  const pts = buckets
    .map((b, i) => {
      const total = b.hits + b.misses;
      const y = h - (total / max) * (h - 4) - 2;
      return `${(i * stepX).toFixed(1)},${y.toFixed(1)}`;
    })
    .join(' ');
  return (
    <svg
      viewBox={`0 0 ${w} ${h}`}
      preserveAspectRatio="none"
      className="w-full h-8 text-accent"
      role="img"
      aria-label={__('24-hour cache request volume')}
    >
      <polyline
        points={pts}
        fill="none"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function ActivityList({ activity }: { activity: ActivityEntry[] }) {
  if (activity.length === 0) {
    return (
      <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg p-5 text-center text-[13px] text-neutral-500 dark:text-neutral-400">
        No activity yet.
      </div>
    );
  }
  return (
    <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg overflow-hidden">
      <div className="px-4 sm:px-5 py-3 border-b border-neutral-200 dark:border-neutral-800">
        <div className="font-medium text-[13px] text-neutral-900 dark:text-neutral-50">
          Recent activity
        </div>
        <div className="text-[12px] text-neutral-500 dark:text-neutral-400 mt-0.5">
          Most recent {activity.length} event{activity.length === 1 ? '' : 's'} captured by the cache engine.
        </div>
      </div>
      <ul className="divide-y divide-neutral-200 dark:divide-neutral-800">
        {activity.map((e, i) => (
          <li key={`${e.ts}-${i}`} className="px-4 sm:px-5 py-3 flex items-start gap-3">
            <span className={`w-1.5 h-1.5 rounded-full mt-2 shrink-0 ${severityDot[e.severity]}`} />
            <div className="min-w-0 flex-1 flex items-baseline justify-between gap-3">
              <div className="text-[13px] text-neutral-700 dark:text-neutral-300 min-w-0 truncate">
                {e.message}
              </div>
              <div className="text-[11px] text-neutral-500 dark:text-neutral-400 shrink-0">
                {formatRelative(e.ts)}
              </div>
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}
