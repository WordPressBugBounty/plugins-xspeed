import { useEffect, useState } from 'react';
import { RefreshCw, ExternalLink, AlertTriangle } from 'lucide-react';
import { api } from '../../api/client';
import { __, sprintf } from '../../i18n';
import { formatBytes } from '../formatBytes';

/**
 * Drill-downs behind the four cache stat cards (DESIGN.md §24.23, issue #50).
 *
 * A stat card states a number; this answers the question the number
 * provokes. "412 cached pages" is only believable when you can open it and
 * see the 412, and "8.4 MB" is only actionable once you can see what is
 * taking the space.
 *
 * Everything here is read-only and fetched on open — a card the user never
 * clicks costs nothing, which matters because the inventory scan stats
 * every cache file on disk.
 */

export type DrilldownKey = 'pages' | 'size' | 'purge' | 'ratio';

interface InventoryEntry {
  url: string | null;
  key: string;
  bytes: number;
  compressed: number;
  age: number;
  stored_in: string[];
}

interface InventoryResponse {
  entries: InventoryEntry[];
  total: number;
  capped: boolean;
}

interface SizeBucket {
  key: string;
  label: string;
  bytes: number;
  files: number;
}

interface SizeResponse {
  buckets: SizeBucket[];
  total_bytes: number;
  total_files: number;
  compressed_bytes: number;
  pages: number;
}

interface PurgeEvent {
  ts: number;
  type: string;
  message: string;
  severity: string;
}

interface PurgeResponse {
  events: PurgeEvent[];
  last_purge: number;
  /** Daily `xspeed_gc` sweep — last run, and what it collected. */
  last_gc: number;
  gc_removed: number;
  gc_removed_total: number;
  gc_next_run: number;
}

const EMPTY_PURGE: PurgeResponse = {
  events: [],
  last_purge: 0,
  last_gc: 0,
  gc_removed: 0,
  gc_removed_total: 0,
  gc_next_run: 0,
};

function formatAge(seconds: number) {
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h`;
  return `${Math.floor(seconds / 86400)}d`;
}

function Shell({
  title,
  busy,
  onRefresh,
  children,
}: {
  title: string;
  busy: boolean;
  onRefresh: () => void;
  children: React.ReactNode;
}) {
  return (
    <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg p-4 sm:p-5">
      <div className="flex items-center justify-between mb-3">
        <div className="text-[12px] font-medium text-neutral-900 dark:text-neutral-50">{title}</div>
        <button
          type="button"
          onClick={onRefresh}
          disabled={busy}
          aria-label={__('Refresh')}
          className="text-neutral-400 dark:text-neutral-500 hover:text-neutral-700 dark:hover:text-neutral-300 disabled:opacity-40"
        >
          <RefreshCw size={13} className={busy ? 'animate-spin' : ''} />
        </button>
      </div>
      {children}
    </div>
  );
}

function Empty({ text }: { text: string }) {
  return <div className="text-[12px] text-neutral-500 dark:text-neutral-400 py-2">{text}</div>;
}

/** "Cached Pages" → the pages themselves, freshest first. */
function PagesDrilldown() {
  const [data, setData] = useState<InventoryResponse | null>(null);
  const [busy, setBusy] = useState(false);

  const load = async (fresh = false) => {
    setBusy(true);
    try {
      setData(await api.request<InventoryResponse>(`/cache/inventory?limit=50${fresh ? '&fresh=1' : ''}`));
    } catch {
      setData({ entries: [], total: 0, capped: false });
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    void load();
  }, []);

  return (
    <Shell title={__('Cached pages')} busy={busy} onRefresh={() => void load(true)}>
      {!data ? (
        <Empty text={__('Loading…')} />
      ) : data.entries.length === 0 ? (
        <Empty text={__('Nothing cached yet. Visit a page as a logged-out visitor to warm the cache.')} />
      ) : (
        <>
          {data.capped && (
            <div className="flex items-start gap-2 mb-3 text-[11px] text-amber-700 dark:text-amber-400">
              <AlertTriangle size={12} className="mt-0.5 shrink-0" />
              <span>
                {__(
                  'This cache is large enough that the scan stopped early — the list below is a recent sample, not every page.',
                )}
              </span>
            </div>
          )}
          <div className="overflow-x-auto">
            <table className="w-full text-[12px]">
              <thead>
                <tr className="text-[11px] uppercase tracking-wider text-neutral-500 dark:text-neutral-400">
                  <th className="text-left font-medium pb-2">{__('Page')}</th>
                  <th className="text-right font-medium pb-2 whitespace-nowrap">{__('Size')}</th>
                  <th className="text-right font-medium pb-2 whitespace-nowrap">{__('Age')}</th>
                  <th className="text-right font-medium pb-2 whitespace-nowrap">{__('Stored')}</th>
                </tr>
              </thead>
              <tbody>
                {data.entries.map((entry) => (
                  <tr
                    key={entry.url ?? entry.key}
                    className="border-t border-neutral-100 dark:border-neutral-800"
                  >
                    <td className="py-1.5 pr-3 max-w-0">
                      {entry.url ? (
                        <a
                          href={entry.url}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="text-neutral-800 dark:text-neutral-200 hover:underline inline-flex items-center gap-1 truncate max-w-full"
                        >
                          <span className="truncate">{entry.url}</span>
                          <ExternalLink size={10} className="shrink-0 opacity-60" />
                        </a>
                      ) : (
                        // We could not recover this entry's address from its
                        // markup. Saying so beats inventing a URL that would
                        // send someone to the wrong page.
                        <span
                          className="text-neutral-500 dark:text-neutral-400 font-mono text-[11px]"
                          title={entry.key}
                        >
                          {__('Address not recorded')}
                        </span>
                      )}
                    </td>
                    <td className="py-1.5 text-right tabular-nums text-neutral-600 dark:text-neutral-400 whitespace-nowrap">
                      {formatBytes(entry.bytes)}
                    </td>
                    <td className="py-1.5 pl-3 text-right tabular-nums text-neutral-600 dark:text-neutral-400 whitespace-nowrap">
                      {formatAge(entry.age)}
                    </td>
                    <td className="py-1.5 pl-3 text-right text-[11px] text-neutral-500 dark:text-neutral-400 whitespace-nowrap">
                      {entry.stored_in.join(' + ')}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="mt-2 text-[11px] text-neutral-500 dark:text-neutral-400">
            {__('Showing')} {data.entries.length} {__('of')} {data.total}
          </div>
        </>
      )}
    </Shell>
  );
}

/** "Cache Size" → where the bytes go. */
function SizeDrilldown() {
  const [data, setData] = useState<SizeResponse | null>(null);
  const [busy, setBusy] = useState(false);

  const load = async () => {
    setBusy(true);
    try {
      setData(await api.request<SizeResponse>('/cache/size'));
    } catch {
      setData(null);
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    void load();
  }, []);

  const used = data?.buckets.filter((b) => b.files > 0) ?? [];
  const max = used.reduce((m, b) => Math.max(m, b.bytes), 0);

  return (
    <Shell title={__('Where the cache size goes')} busy={busy} onRefresh={() => void load()}>
      {!data ? (
        <Empty text={__('Loading…')} />
      ) : used.length === 0 ? (
        <Empty text={__('Nothing on disk yet.')} />
      ) : (
        <>
          <div className="space-y-2">
            {used.map((bucket) => (
              <div key={bucket.key}>
                <div className="flex items-baseline justify-between text-[12px]">
                  <span className="text-neutral-700 dark:text-neutral-300">{bucket.label}</span>
                  <span className="tabular-nums text-neutral-600 dark:text-neutral-400">
                    {formatBytes(bucket.bytes)}
                    <span className="text-neutral-400 dark:text-neutral-500">
                      {' · '}
                      {bucket.files}
                    </span>
                  </span>
                </div>
                <div className="mt-1 h-1.5 rounded-full bg-neutral-100 dark:bg-neutral-800 overflow-hidden">
                  <div
                    className="h-full rounded-full bg-accent"
                    style={{ width: max > 0 ? `${Math.max(2, (bucket.bytes / max) * 100)}%` : '0%' }}
                  />
                </div>
              </div>
            ))}
          </div>
          {data.compressed_bytes > 0 && (
            <div className="mt-3 text-[11px] text-neutral-500 dark:text-neutral-400">
              {/* A measured floor, not an estimate — pages with no precompressed
                  copy are compressed by the web server at request time, which
                  can't be read off the disk. */}
              {__('Precompressed copies on disk:')} {formatBytes(data.compressed_bytes)}.{' '}
              {__('Pages without one are compressed by the server when requested.')}
            </div>
          )}
        </>
      )}
    </Shell>
  );
}

/** "Last Purge" → what cleared the cache, when, and why. */
function PurgeDrilldown() {
  const [data, setData] = useState<PurgeResponse | null>(null);
  const [busy, setBusy] = useState(false);

  const load = async () => {
    setBusy(true);
    try {
      setData(await api.request<PurgeResponse>('/cache/purge-log?limit=25'));
    } catch {
      setData(EMPTY_PURGE);
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    void load();
  }, []);

  const now = Math.floor(Date.now() / 1000);

  // The daily sweep is invisible otherwise: it deletes expired entries with
  // no user action to attribute them to, so without this the Cache Size just
  // drops and nothing says why.
  const gcLine = !data
    ? null
    : data.last_gc > 0
      ? sprintf(
          /* translators: 1: how long ago, 2: files removed last run, 3: files removed since install. */
          __('Automatic cleanup ran %1$s ago — %2$d file(s) removed, %3$d in total.'),
          formatAge(Math.max(0, now - data.last_gc)),
          data.gc_removed,
          data.gc_removed_total
        )
      : data.gc_next_run > 0
        ? sprintf(
            /* translators: %s: time until the first sweep. */
            __('Automatic cleanup has not run yet — first sweep in %s.'),
            formatAge(Math.max(0, data.gc_next_run - now))
          )
        : null;

  return (
    <Shell title={__('Purge history')} busy={busy} onRefresh={() => void load()}>
      {gcLine && (
        <p className="text-[11px] text-neutral-500 dark:text-neutral-400 mb-2">{gcLine}</p>
      )}
      {!data ? (
        <Empty text={__('Loading…')} />
      ) : data.events.length === 0 ? (
        <Empty text={__('No purges recorded yet.')} />
      ) : (
        <ul className="space-y-1.5">
          {data.events.map((event, i) => (
            <li
              key={`${event.ts}-${i}`}
              className="flex items-baseline gap-3 text-[12px] border-t border-neutral-100 dark:border-neutral-800 first:border-t-0 pt-1.5 first:pt-0"
            >
              <span className="tabular-nums text-[11px] text-neutral-500 dark:text-neutral-400 w-12 shrink-0">
                {formatAge(Math.max(0, now - event.ts))}
              </span>
              <span className="text-neutral-700 dark:text-neutral-300">{event.message}</span>
            </li>
          ))}
        </ul>
      )}
    </Shell>
  );
}

export function StatDrilldown({ which }: { which: DrilldownKey }) {
  if (which === 'pages') return <PagesDrilldown />;
  if (which === 'size') return <SizeDrilldown />;
  if (which === 'purge') return <PurgeDrilldown />;
  // 'ratio' has no drill-down of its own — the hit-ratio story is the trend
  // chart that already lives further down the page, so the card scrolls
  // there instead of duplicating it here.
  return null;
}
