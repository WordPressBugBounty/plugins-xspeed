/**
 * MCP activity log — the audit trail of what the AI actually did.
 *
 * Connecting an assistant to a live site is a trust decision, and trust
 * needs a receipt. This is that receipt: every dispatched tool call with
 * its arguments, outcome, and the transport it arrived on. Read-only
 * calls are visually quieter than writes, because "it looked at my
 * settings" and "it changed my settings" are not the same event.
 *
 * Collapsed by default — it's reference material, not something you need
 * in your face every time you open the panel. DESIGN.md §24.17.
 */

import { useEffect, useState } from 'react';
import {
  ScrollText,
  RefreshCw,
  ChevronDown,
  ChevronRight,
  CheckCircle2,
  XCircle,
  Eye,
  Pencil,
} from 'lucide-react';
import { api } from '../../api/client';
import { __ } from '../../i18n';

interface ActivityEntry {
  ts: number;
  tool: string;
  args: string;
  ok: boolean;
  error: string;
  scope: 'read' | 'write';
  channel: string;
}

interface ActivitySummary {
  total: number;
  failed: number;
  last_ts: number;
  top_tool: string;
}

interface ActivityResponse {
  entries: ActivityEntry[];
  summary: ActivitySummary;
}

/** Compact relative time — "just now", "4m ago", "2h ago", "3d ago". */
function relTime(unixSeconds: number): string {
  const s = Math.max(0, Math.floor(Date.now() / 1000) - unixSeconds);
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
}

export function McpActivity() {
  const [open, setOpen] = useState(false);
  const [data, setData] = useState<ActivityResponse | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirming, setConfirming] = useState(false);

  const load = async () => {
    setBusy(true);
    try {
      setData(await api.request<ActivityResponse>('/mcp/activity?limit=50'));
    } catch {
      setData({ entries: [], summary: { total: 0, failed: 0, last_ts: 0, top_tool: '' } });
    } finally {
      setBusy(false);
    }
  };

  // Only fetch once the section is actually opened — the panel above it
  // is the common case, and an audit read shouldn't cost a request on
  // every dashboard paint.
  useEffect(() => {
    if (open && data === null) load();
  }, [open]);

  const clear = async () => {
    setBusy(true);
    try {
      setData(await api.request<ActivityResponse>('/mcp/activity/clear', { method: 'POST' }));
    } finally {
      setBusy(false);
      setConfirming(false);
    }
  };

  const summary = data?.summary;
  const entries = data?.entries ?? [];

  return (
    <div className="mt-4 rounded-card border border-line">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="flex w-full items-center gap-2 px-3 py-2.5 text-left"
      >
        {open ? (
          <ChevronDown size={14} className="text-faint" />
        ) : (
          <ChevronRight size={14} className="text-faint" />
        )}
        <ScrollText size={14} className="text-muted" />
        <span className="text-[13px] font-medium text-ink">
          Activity log
        </span>
        {summary && summary.total > 0 && (
          <span className="text-[11px] text-faint">
            {summary.total} call{summary.total === 1 ? '' : 's'}
            {summary.failed > 0 && (
              <span className="text-danger"> · {summary.failed} failed</span>
            )}
          </span>
        )}
        <span className="ml-auto text-[11px] text-faint">
          what the AI did
        </span>
      </button>

      {open && (
        <div className="border-t border-line px-3 py-3">
          <div className="mb-2 flex items-center justify-between">
            <p className="text-[11px] text-muted">
              Every tool call an assistant dispatched to this site, newest first.
            </p>
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                onClick={load}
                disabled={busy}
                aria-label={__('Refresh activity log')}
                className="rounded border border-line p-1.5 text-muted hover:bg-panel disabled:opacity-40"
              >
                <RefreshCw size={12} className={busy ? 'animate-spin' : ''} />
              </button>
              {entries.length > 0 && (
                <button
                  type="button"
                  onClick={() => (confirming ? clear() : setConfirming(true))}
                  onBlur={() => setConfirming(false)}
                  disabled={busy}
                  className={`rounded border px-2 py-1 text-[11px] disabled:opacity-40 ${
                    confirming
                      ? 'border-danger text-danger'
                      : 'border-line text-muted hover:bg-panel'
                  }`}
                >
                  {confirming ? 'Confirm clear' : 'Clear'}
                </button>
              )}
            </div>
          </div>

          {entries.length === 0 ? (
            <p className="py-4 text-center text-[12px] text-faint">
              {data === null ? 'Loading…' : 'No tool calls recorded yet.'}
            </p>
          ) : (
            <ul className="space-y-1">
              {entries.map((entry, i) => (
                <li
                  key={`${entry.ts}-${entry.tool}-${i}`}
                  className="flex items-start gap-2 rounded-md bg-panel/50 px-2.5 py-1.5"
                >
                  {entry.ok ? (
                    <CheckCircle2
                      size={13}
                      className="mt-0.5 shrink-0 text-success"
                      aria-label={__('succeeded')}
                    />
                  ) : (
                    <XCircle
                      size={13}
                      className="mt-0.5 shrink-0 text-danger"
                      aria-label={__('failed')}
                    />
                  )}

                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1.5">
                      <code className="text-[12px] font-medium text-ink">
                        {entry.tool}
                      </code>
                      {entry.scope === 'read' ? (
                        <Eye
                          size={11}
                          className="text-faint"
                          aria-label={__('read-only call')}
                        />
                      ) : (
                        <Pencil
                          size={11}
                          className="text-warning"
                          aria-label={__('write call')}
                        />
                      )}
                    </div>
                    {entry.args !== '' && (
                      <div className="truncate text-[11px] text-muted">
                        {entry.args}
                      </div>
                    )}
                    {entry.error !== '' && (
                      <div className="text-[11px] text-danger">{entry.error}</div>
                    )}
                  </div>

                  <span className="shrink-0 text-[11px] tabular-nums text-faint">
                    {relTime(entry.ts)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
