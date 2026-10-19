import { useEffect, useState, useCallback } from 'react';
import { Lightbulb, ArrowRight, RefreshCw, CheckCircle2, Check } from 'lucide-react';
import { api } from '../../api/client';
import { buildDeepLink } from '../deepLink';
import { __ } from '../../i18n';

/**
 * Next best action (DESIGN.md §24.15, issue #48): the top-ranked
 * deterministic recommendation with either a one-click server-side Apply
 * or a deep-link into the owning panel. Always present on the initial
 * screen — a green all-clear renders when no rule fires, so the slot
 * reads as "checked and healthy", never as missing.
 */

interface Recommendation {
  id: string;
  priority: number;
  title: string;
  detail: string;
  action: {
    type: 'apply' | 'link';
    module?: string;
    values?: Record<string, unknown>;
    /** Deep-link target for link actions (issue #49). */
    subtab?: string;
    focus?: string;
    suggest?: string;
    label: string;
  };
}

export function NextBestAction() {
  const [recs, setRecs] = useState<Recommendation[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [applied, setApplied] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await api.request<{ recommendations: Recommendation[] }>('/recommendations');
      setRecs(res.recommendations);
    } catch {
      setRecs([]);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const top = recs?.[0];

  const apply = async () => {
    if (!top) return;
    setBusy(true);
    setError(null);
    try {
      const res = await api.request<{ recommendations: Recommendation[] }>(
        '/recommendations/apply',
        { method: 'POST', body: JSON.stringify({ id: top.id }) }
      );
      setApplied(true);
      setTimeout(() => {
        setApplied(false);
        setRecs(res.recommendations);
      }, 1600);
    } catch (e) {
      setError(e instanceof Error ? e.message : __('Could not apply the fix.'));
    } finally {
      setBusy(false);
    }
  };

  if (recs === null) return null; // no skeleton — the slot appears when known.

  if (!top) {
    return (
      <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg p-4 sm:p-5 flex items-center gap-3">
        <CheckCircle2 size={18} className="text-emerald-500 shrink-0" />
        <div>
          <div className="font-medium text-neutral-900 dark:text-neutral-50">
            {__('No recommendations right now')}
          </div>
          <div className="text-[12px] text-neutral-500 dark:text-neutral-400">
            {__('Every deterministic check passes — your configuration looks healthy.')}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="border border-amber-200 dark:border-amber-900/60 bg-amber-50 dark:bg-amber-950/40 rounded-lg p-4 sm:p-5">
      <div className="flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4">
        <Lightbulb size={18} className="text-amber-600 dark:text-amber-300 shrink-0 hidden sm:block" />
        <div className="min-w-0 flex-1">
          <div className="text-[11px] uppercase tracking-wider font-medium text-amber-700 dark:text-amber-300 mb-0.5">
            {__('Next best action')}
            {recs.length > 1 && (
              <span className="normal-case tracking-normal font-normal">
                {' '}· {recs.length - 1} {__('more after this')}
              </span>
            )}
          </div>
          <div className="font-medium text-neutral-900 dark:text-neutral-50">{top.title}</div>
          <div className="text-[12px] text-amber-900/80 dark:text-amber-200/80 mt-0.5">{top.detail}</div>
          {error && <div className="text-[12px] text-red-700 dark:text-red-300 mt-1">{error}</div>}
        </div>
        <div className="shrink-0">
          {top.action.type === 'apply' ? (
            <button
              type="button"
              onClick={apply}
              disabled={busy || applied}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-accent bg-accent text-white hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-60"
            >
              {busy ? (
                <RefreshCw size={13} className="animate-spin" />
              ) : applied ? (
                <Check size={13} />
              ) : null}
              {applied ? __('Applied') : top.action.label}
            </button>
          ) : (
            <a
              href={
                top.action.module
                  ? buildDeepLink({
                      module: top.action.module,
                      subtab: top.action.subtab,
                      focus: top.action.focus,
                      suggest: top.action.suggest,
                    })
                  : '#'
              }
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-amber-300 dark:border-amber-800 text-amber-800 dark:text-amber-200 hover:bg-amber-100 dark:hover:bg-amber-900/40 transition-colors"
            >
              {top.action.label} <ArrowRight size={13} />
            </a>
          )}
        </div>
      </div>
    </div>
  );
}
