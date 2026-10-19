import { useEffect, useState } from 'react';
import { LifeBuoy, RefreshCw, ExternalLink, Copy, CheckCircle2 } from 'lucide-react';
import { Callout } from '../Callout';
import { useBranding } from '../../hooks/useBranding';
import { api } from '../../api/client';
import type { ModuleDescriptor } from '../../types';

interface SnapshotResp {
  snapshot: Record<string, string | number | boolean | string[]>;
  markdown: string;
}

export function SupportPanel({ module }: { module: ModuleDescriptor }) {
  const [data, setData] = useState<SnapshotResp | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const branding = useBranding();

  const supportUrl = String(module.settings.support_url ?? 'https://xspeedcache.com/support/');

  const refresh = async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await api.request<SnapshotResp>('/support/snapshot');
      setData(res);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Snapshot failed');
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => { refresh(); }, []);

  const handleCopy = async () => {
    if (!data) return;
    try {
      await navigator.clipboard.writeText(data.markdown);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    } catch {
      setError('Clipboard blocked. Select the text below and copy manually.');
    }
  };

  return (
    <div className="space-y-4">
      <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg p-4 sm:p-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3 min-w-0">
            <LifeBuoy size={18} className="text-accent shrink-0" />
            <div className="min-w-0">
              <div className="font-medium text-neutral-900 dark:text-neutral-50">Help &amp; Support</div>
              <div className="text-[12px] text-neutral-500 dark:text-neutral-400">
                Open a ticket with your system snapshot already attached so we can help faster.
              </div>
            </div>
          </div>
          {!branding.hide_help_links && (
            <a
              href={supportUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-accent bg-accent text-white hover:border-primary-hover hover:bg-primary-hover transition-colors"
            >
              <ExternalLink size={13} />
              Open ticket
            </a>
          )}
        </div>
      </div>

      {error && <Callout tone="danger" title="Support snapshot error" body={error} />}

      <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg p-4 sm:p-5">
        <div className="flex items-center justify-between gap-3 mb-3">
          <div>
            <div className="font-medium text-neutral-900 dark:text-neutral-50">System snapshot</div>
            <div className="text-[12px] text-neutral-500 dark:text-neutral-400">
              Paste this into your support ticket. License key is masked — first 4 + last 4 only.
            </div>
          </div>
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={refresh}
              disabled={busy}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition-colors disabled:opacity-50"
            >
              <RefreshCw size={13} className={busy ? 'animate-spin' : ''} />
              Refresh
            </button>
            <button
              type="button"
              onClick={handleCopy}
              disabled={!data}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition-colors disabled:opacity-50"
            >
              {copied ? <CheckCircle2 size={13} className="text-emerald-500" /> : <Copy size={13} />}
              {copied ? 'Copied' : 'Copy markdown'}
            </button>
          </div>
        </div>
        {data ? (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-[12px]">
            {Object.entries(data.snapshot).map(([k, v]) => (
              <div key={k} className="flex justify-between gap-3 border-b border-neutral-100 dark:border-neutral-800 pb-1">
                <span className="text-neutral-500 dark:text-neutral-400 font-mono">{k}</span>
                <span className="text-neutral-900 dark:text-neutral-100 text-right truncate font-mono">
                  {Array.isArray(v) ? v.join(', ') : typeof v === 'boolean' ? (v ? 'yes' : 'no') : String(v)}
                </span>
              </div>
            ))}
          </div>
        ) : (
          <div className="text-[12px] text-neutral-500 dark:text-neutral-400 italic">Loading snapshot…</div>
        )}
      </div>

      {/* Credit line. White-label can replace it (footer_credit) or, with
          hide_help_links on, suppress the default attribution entirely. */}
      {(() => {
        const credit = branding.footer_credit?.trim();
        if (credit) {
          return (
            <div className="text-[11px] text-neutral-400 dark:text-neutral-500 text-center pt-1">
              {credit}
            </div>
          );
        }
        if (branding.hide_help_links) {
          return null;
        }
        return (
          <div className="text-[11px] text-neutral-400 dark:text-neutral-500 text-center pt-1">
            Built by{' '}
            <a
              href="https://wpdeveloper.com"
              target="_blank"
              rel="noopener noreferrer"
              className="hover:text-accent"
            >
              WPDeveloper
            </a>
          </div>
        );
      })()}
    </div>
  );
}
