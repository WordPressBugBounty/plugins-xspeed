import { useState } from 'react';
import { Sparkles, RefreshCw, ArrowRight, ChevronUp, Lock } from 'lucide-react';
import { ModuleIcon } from '../components/ModuleIcon';
import { PRO_FEATURES, PRO_UPGRADE_URL } from './proFeatures';
import { useTier } from './useTier';
import { api } from '../api/client';
import { __ } from '../i18n';

interface Suggestion {
  id: string;
  severity: 'high' | 'med' | 'low';
  reason: string;
  fact?: string;
}

interface AuditResponse {
  suggestions: Suggestion[];
}

/**
 * "Run Pro audit" widget. Calls /audit/pro, renders a personalized
 * list of Pro features that would help THIS site based on current
 * Free settings + 24h cache stats. Hidden when Pro is active.
 *
 * This is the highest-leverage upsell surface in the dashboard — the
 * user sees specific reasons tied to their actual site state instead
 * of generic marketing copy.
 */
export function ProAudit() {
  const tier = useTier();
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<AuditResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState(false);

  if (tier !== 'free') return null;

  const run = async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await api.request<AuditResponse>('/audit/pro');
      setResult(res);
      setOpen(true);
    } catch (e) {
      setError(e instanceof Error ? e.message : __('Audit failed'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="border border-line bg-primary-tint rounded-card p-4 sm:p-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3 min-w-0">
          <span className="shrink-0 w-9 h-9 rounded-full bg-paper border border-line flex items-center justify-center text-warning">
            <Sparkles size={18} />
          </span>
          <div className="min-w-0">
            <div className="flex items-center gap-1.5">
              <span className="font-medium text-ink">
                {__('What would Pro do for your site?')}
              </span>
              <span className="inline-flex items-center gap-1 text-[10px] uppercase tracking-wider font-medium text-on-tint">
                <Lock size={10} aria-hidden="true" />
                {__('Pro')}
              </span>
            </div>
            <div className="text-[12px] text-ink-soft mt-0.5">
              {__('Scans your current settings + 24h cache stats and shows exactly which Pro features would help.')}
            </div>
          </div>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          {result && (
            <button
              type="button"
              onClick={() => setOpen((o) => !o)}
              className="flex items-center gap-1 text-[12px] text-ink-soft hover:text-ink transition-colors"
            >
              <ChevronUp size={13} className={`transition-transform ${open ? '' : 'rotate-180'}`} />
              {open ? __('Hide') : __('Show')}
            </button>
          )}
          <button
            type="button"
            onClick={run}
            disabled={busy}
            className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded-md border border-primary bg-primary hover:border-primary-hover hover:bg-primary-hover text-on-primary font-medium transition-colors disabled:opacity-50"
          >
            {busy ? <RefreshCw size={13} className="animate-spin" /> : <Sparkles size={13} />}
            {result ? __('Re-run') : __('Run Pro audit')}
          </button>
        </div>
      </div>

      {error && (
        <div className="mt-3 text-[12px] text-danger">{error}</div>
      )}

      {result && open && (
        <div className="mt-4 space-y-2">
          {result.suggestions.map((s) => (
            <SuggestionCard key={`${s.id}-${s.severity}`} suggestion={s} />
          ))}
          <div className="text-center pt-2">
            <a
              href={PRO_UPGRADE_URL}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1.5 px-4 py-2 rounded-md bg-primary hover:bg-primary-hover text-on-primary text-[13px] font-medium transition-colors"
            >
              {__('Upgrade to Pro')}
              <ArrowRight size={13} />
            </a>
          </div>
        </div>
      )}
    </div>
  );
}

const SEVERITY_STYLES: Record<Suggestion['severity'], { dot: string; chip: string; label: string }> = {
  high: { dot: "bg-danger",  chip: "bg-danger-tint text-danger border-line", label: 'High' },
  med:  { dot: "bg-warning", chip: "bg-warning-tint text-warning border-line", label: 'Medium' },
  low:  { dot: "bg-info",    chip: "bg-info-tint text-info border-line", label: 'Low' },
};

function SuggestionCard({ suggestion }: { suggestion: Suggestion }) {
  const feature = PRO_FEATURES[suggestion.id];
  const sev = SEVERITY_STYLES[suggestion.severity];
  return (
    <div className="border border-line bg-paper rounded-md p-3 sm:p-4 flex items-start gap-3">
      <span className={`shrink-0 w-2 h-2 rounded-full mt-2.5 ${sev.dot}`} aria-hidden />
      <span className="shrink-0 text-warning mt-0.5">
        {feature && <ModuleIcon name={feature.icon} size={16} />}
      </span>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-[13px] font-medium text-ink">
            {feature?.title ?? suggestion.id}
          </span>
          <span className={`text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded border ${sev.chip}`}>
            {sev.label}
          </span>
          {suggestion.fact && (
            <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-panel text-ink-soft">
              {suggestion.fact}
            </span>
          )}
        </div>
        <p className="text-[12px] text-ink-soft mt-1 leading-relaxed">
          {suggestion.reason}
        </p>
      </div>
    </div>
  );
}
