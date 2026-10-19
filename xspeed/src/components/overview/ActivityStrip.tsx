import { useEffect, useState } from 'react';
import { api } from '../../api/client';
import { __ } from '../../i18n';
import { relativeTime } from './format';
import type { ActivityEntry } from '../../types';

const MAX_SHOWN = 6;

function dotClass(severity: ActivityEntry['severity']): string {
  switch (severity) {
    case 'success':
      return 'bg-success';
    case 'warn':
      return 'bg-warning';
    case 'error':
      return 'bg-red-500';
    default:
      return 'bg-line-strong';
  }
}

/**
 * Zone 5 — the recent-activity strip. Reads the activity log (GET /activity)
 * and lists the most recent events (settings changes, purges, cache toggles).
 * Renders nothing when the log is empty so it never shows a dead header.
 */
export function ActivityStrip() {
  const [entries, setEntries] = useState<ActivityEntry[] | null>(null);

  useEffect(() => {
    let active = true;
    api
      .getActivity()
      .then((r) => {
        if (active) setEntries(r.activity ?? []);
      })
      .catch(() => {
        if (active) setEntries([]);
      });
    return () => {
      active = false;
    };
  }, []);

  // Loading or empty → render nothing (the docs/support footer still shows).
  if (!entries || entries.length === 0) return null;

  return (
    <section aria-label={__('Recent activity')}>
      <h2 className="text-[14px] font-semibold text-ink mb-3">
        {__('Recent activity')}
      </h2>
      <ul className="rounded-card border border-line bg-paper divide-y divide-line">
        {entries.slice(0, MAX_SHOWN).map((e, i) => (
          <li key={`${e.ts}-${i}`} className="flex items-center gap-3 px-3 py-2">
            <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${dotClass(e.severity)}`} aria-hidden="true" />
            <span className="flex-1 min-w-0 truncate text-[13px] text-ink-soft">
              {e.message}
            </span>
            <span className="shrink-0 text-[11px] text-muted tabular-nums">
              {relativeTime(e.ts)}
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}
