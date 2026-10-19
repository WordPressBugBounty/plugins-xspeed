import { useEffect, useState } from 'react';
import { api } from '../api/client';
import { __ } from '../i18n';
import { relativeTime } from './overview/format';
import type { ActivityEntry } from '../types';

function dotClass(severity: ActivityEntry['severity']): string {
  switch (severity) {
    case 'success': return 'bg-success';
    case 'warn': return 'bg-warning';
    case 'error': return 'bg-danger';
    default: return 'bg-line-strong';
  }
}

/**
 * Redesign v2 — read-only Logs surface (Tools). Lists Activity_Log entries via
 * GET /activity. No settings; purely a record of recent cache + settings
 * activity. (PR-4 may add filtering; this establishes the route + parity.)
 */
export function LogsView() {
  const [entries, setEntries] = useState<ActivityEntry[] | null>(null);

  useEffect(() => {
    let active = true;
    api.getActivity()
      .then((r) => { if (active) setEntries(r.activity ?? []); })
      .catch(() => { if (active) setEntries([]); });
    return () => { active = false; };
  }, []);

  return (
    <div>
      <p className="text-[12px] font-medium uppercase tracking-wide text-muted">{__('Tools')}</p>
      <h1 className="mt-1 font-display text-[22px] font-semibold text-ink">{__('Logs')}</h1>
      <p className="mt-1 text-[13px] text-ink-soft">{__('Recent cache and settings activity on this site.')}</p>

      <div className="mt-5 rounded-card border border-line bg-paper overflow-hidden">
        {entries === null ? (
          <div className="p-4 space-y-2" aria-busy="true">
            <span className="block h-4 rounded bg-panel animate-pulse" />
            <span className="block h-4 w-2/3 rounded bg-panel animate-pulse" />
          </div>
        ) : entries.length === 0 ? (
          <div className="p-8 text-center text-[13px] text-muted">{__('No activity recorded yet.')}</div>
        ) : (
          <ul className="divide-y divide-line">
            {entries.map((e, i) => (
              <li key={`${e.ts}-${i}`} className="flex items-center gap-3 px-4 py-2.5">
                <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${dotClass(e.severity)}`} aria-hidden="true" />
                <span className="flex-1 min-w-0 truncate text-[13.5px] text-ink">{e.message}</span>
                <span className="shrink-0 text-[11px] text-muted tabular-nums font-mono">{relativeTime(e.ts)}</span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
