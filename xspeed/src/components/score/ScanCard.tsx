import { useCallback, useEffect, useRef, useState } from 'react';
import { Radar, Loader2, ExternalLink, Globe, Lock, AlertTriangle, Monitor, Smartphone } from 'lucide-react';
import { api } from '../../api/client';
import { __ } from '../../i18n';
import { ScoreRing } from '../ScoreRing';

/**
 * xSpeed Scan — a full graded site report, run from the dashboard
 * (DESIGN.md §24.32).
 *
 * NOT a second PageSpeed card. The scan grades four weighted dimensions
 * over ~20 checks and returns what to fix ranked by how many points each
 * recovers; a Lighthouse result is one check inside it (18 of 100 points).
 * The two numbers are different scales, so this card never puts them
 * side by side as if they were comparable — the Lighthouse figure appears
 * as a labelled measurement, not as a score.
 *
 * Privacy is stated before the button, not after. The engine publishes
 * every report it stores to a per-host feed anyone can enumerate by
 * domain; until it can express "unlisted", even a Hub-connected site gets
 * a public report, and saying otherwise would be a promise we cannot keep.
 */

interface ScanFix {
  id: string;
  name: string;
  status: 'fail' | 'partial';
  evidence: string;
  remediation: string;
  recoverable: number;
  /** Absent on reports stored before the engine's fix was kept. */
  fix?: { tier: string; feature: string; prompt: string } | null;
}

interface ScanDimension {
  score: number | null;
  earned: number | null;
  weight: number | null;
}

interface ScanResult {
  scan_id: string;
  ts: number;
  url: string;
  score: number | null;
  grade: string;
  level: number | null;
  level_name: string;
  partial: boolean;
  dimensions: Record<string, ScanDimension>;
  measured: {
    lighthouse: number | null;
    lighthouse_desktop: number | null;
    lighthouse_mobile: number | null;
    ttfb_ms: number | null;
    lcp_ms: number | null;
    cls: number | null;
    tbt_ms: number | null;
    cache_hit: boolean | null;
  };
  fixes: ScanFix[];
  report_url: string;
  visibility: string;
}

interface ScanStatus {
  status: 'idle' | 'running' | 'complete';
  scan_id?: string;
  step?: string;
  latest: ScanResult | null;
  /** 'unreadable': a report was stored but could not be read back (#468). */
  stored?: 'none' | 'ok' | 'unreadable';
  visibility: string;
  private_supported: boolean;
}

/**
 * Human label for an engine dimension key, one translation call per label:
 * the POT extractor only reads string literals, so translating a lookup
 * table's value left all four out of the POT. Unknown keys show as-is.
 */
function dimensionLabel(key: string): string {
  switch (key) {
    case 'speed':
      return __('Core Web Vitals');
    case 'delivery':
      return __('Server & caching');
    case 'assets':
      return __('Assets');
    case 'stack':
      return __('Platform');
    default:
      return key;
  }
}

/** A scan takes 20-60s; the engine answers a poll fast even while running. */
const POLL_MS = 5000;

function DimensionBar({ label, d }: { label: string; d: ScanDimension }) {
  const score = d.score ?? 0;
  const tone = score >= 90 ? 'bg-success' : score >= 50 ? 'bg-warning' : 'bg-danger';
  return (
    <div>
      <div className="flex items-baseline justify-between text-[12px]">
        <span className="text-ink-soft">{label}</span>
        <span className="text-muted tabular-nums">
          <span className="font-semibold text-ink">{d.score ?? '—'}</span>
          {d.weight !== null && (
            <span className="text-[11px]">
              {' '}
              · {d.earned ?? '—'}/{d.weight} {__('pts')}
            </span>
          )}
        </span>
      </div>
      <div className="mt-1 h-1.5 rounded-pill bg-panel overflow-hidden">
        <div className={`h-full rounded-pill ${tone}`} style={{ width: `${Math.max(0, Math.min(100, score))}%` }} />
      </div>
    </div>
  );
}

export function ScanCard() {
  const [state, setState] = useState<ScanStatus | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Set false on unmount so a response still in flight cannot schedule a
  // timer after cleanup has run, leaving a hidden card polling every 5s and
  // setting state on an unmounted component.
  const alive = useRef(true);

  const load = useCallback(async () => {
    try {
      const s = await api.request<ScanStatus>('/score/scan-status');
      if (!alive.current) return;
      setState(s);
      // Keep polling only while a scan is actually in flight. The server
      // owns the pending marker, so this loop cannot outlive it.
      if (s.status === 'running') {
        timer.current = setTimeout(load, POLL_MS);
      } else {
        setBusy(false);
      }
    } catch {
      if (!alive.current) return;
      // A read failure is not worth an error banner over a stale result:
      // the last good scan is still on screen and still true.
      //
      // But clearing `busy` alone is not enough: `running` is
      // `status === 'running' || busy`, so a transient failure mid-scan left
      // the button disabled on "Scanning…" forever, even once the scan had
      // finished server-side. Drop the stale running status too, so the card
      // falls back to its last good result and the user can scan again.
      setBusy(false);
      setState((s) => (s && s.status === 'running' ? { ...s, status: 'idle' } : s));
    }
  }, []);

  useEffect(() => {
    alive.current = true;
    void load();
    return () => {
      alive.current = false;
      if (timer.current) clearTimeout(timer.current);
    };
  }, [load]);

  const start = async () => {
    setBusy(true);
    setError('');
    try {
      await api.request('/score/scan', { method: 'POST', body: JSON.stringify({ fresh: true }) });
      timer.current = setTimeout(load, POLL_MS);
    } catch (e) {
      setError(e instanceof Error ? e.message : __('The scan could not be started.'));
      setBusy(false);
    }
  };

  const latest = state?.latest ?? null;
  const running = state?.status === 'running' || busy;
  // Connected AND the engine can honour it. Connection alone is not enough,
  // and claiming privacy on connection alone would be the lie this guards.
  const isPrivate = state?.visibility === 'private' && state?.private_supported === true;

  return (
    <section className="rounded-card border border-line bg-paper p-5" aria-label={__('xSpeed Scan')}>
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div className="flex items-start gap-3 min-w-0">
          <span
            className="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-primary-tint text-primary shrink-0"
            aria-hidden="true"
          >
            <Radar size={17} />
          </span>
          <div className="min-w-0">
            <h3 className="font-display text-[15px] font-semibold text-ink">{__('xSpeed Scan')}</h3>
            <p className="mt-0.5 text-[12px] text-ink-soft">
              {__('A full graded report: Core Web Vitals, caching, assets and platform.')}
            </p>
          </div>
        </div>

        <button
          type="button"
          onClick={start}
          disabled={running}
          className="shrink-0 inline-flex items-center gap-2 rounded-control bg-primary px-3.5 py-2 text-[13px] font-medium text-on-primary hover:opacity-90 disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
          {running ? <Loader2 size={14} className="animate-spin" aria-hidden="true" /> : <Radar size={14} aria-hidden="true" />}
          {running ? __('Scanning…') : latest ? __('Scan again') : __('Run a scan')}
        </button>
      </div>

      {/* Where the report goes — said BEFORE the button is pressed, not
          after. On an unconnected site this publishes a report about the
          user's site to a list anyone can read. */}
      <div
        className={`mt-3 flex items-start gap-2 rounded-lg p-2.5 text-[12px] ${
          isPrivate ? 'bg-success-tint text-success' : 'bg-panel text-ink-soft'
        }`}
      >
        {isPrivate ? (
          <Lock size={13} className="mt-0.5 shrink-0" aria-hidden="true" />
        ) : (
          <Globe size={13} className="mt-0.5 shrink-0" aria-hidden="true" />
        )}
        <span>
          {/* The public case names the remedy, not just the consequence:
              connecting the Hub is what makes a report private, and a
              warning that does not say so leaves the user with no move.
              The private case says "unlisted", never "only you can see it"
              -- the report URL stays reachable to anyone holding it. */}
          {isPrivate
            ? __('Connected to xSpeed Hub — this report stays unlisted, not published under your domain.')
            : __('This report will be publicly visible at xspeedcache.com, listed under your domain. Connect xSpeed Hub to keep your reports unlisted.')}
        </span>
      </div>

      {error && (
        <div className="mt-3 flex items-start gap-2 rounded-lg bg-danger-tint p-2.5 text-[12px] text-danger">
          <AlertTriangle size={13} className="mt-0.5 shrink-0" aria-hidden="true" />
          <span>{error}</span>
        </div>
      )}

      {!latest && !running && state?.stored === 'unreadable' && (
        <div className="mt-3 flex items-start gap-2 rounded-lg bg-warning-tint p-2.5 text-[12px] text-warning">
          <AlertTriangle size={13} className="mt-0.5 shrink-0" aria-hidden="true" />
          <span>
            {__('The last report could not be read back from the database and was discarded. Run the scan again.')}
          </span>
        </div>
      )}

      {running && state?.step && (
        <p className="mt-3 text-[12px] text-muted">{state.step}…</p>
      )}

      {latest && (
        <div className="mt-4 rounded-lg border border-line p-4">
          {/*
            The HEADLINE is the Lighthouse DESKTOP score. The rubric used to
            lead, and it grades harshly by design — a site scoring 82/58 on
            Lighthouse wore a "59 · Grade D (Slow)" headline and read as
            broken. Lighthouse is the number users already know from
            PageSpeed, and desktop is the kinder-but-honest half of the
            pair; mobile sits right beside it, and the rubric grade stays as
            a labelled secondary line so the two scales are never conflated.
            When the scan has no Lighthouse measurement, the rubric ring
            returns as the only number there is.
          */}
          <div className="flex flex-wrap items-center gap-x-6 gap-y-4">
            {(() => {
              const lhDesktop = latest.measured.lighthouse_desktop;
              // The per-device field, NOT `measured.lighthouse` -- that one is
              // whichever device was graded (desktop on a default scan), so
              // reading it here showed the desktop number labelled "mobile",
              // and gave a desktop-only scan a mobile score it never measured.
              const lhMobile = latest.measured.lighthouse_mobile;
              const headline = lhDesktop ?? lhMobile;
              if (headline === null) {
                return (
                  <div className="flex items-center gap-3">
                    <ScoreRing score={latest.score} size={64} thickness={3.5} />
                    <div>
                      <div className="text-[13px] font-semibold text-ink">
                        {__('Grade')} {latest.grade || '—'}
                      </div>
                      <div className="text-[11px] text-muted">
                        {__('xSpeed Scan, out of 100')}
                      </div>
                    </div>
                  </div>
                );
              }
              return (
                <>
                  <div className="flex items-center gap-3">
                    <ScoreRing score={headline} size={64} thickness={3.5} />
                    <div>
                      <div className="flex items-center gap-1.5 text-[13px] font-semibold text-ink">
                        {lhDesktop !== null ? (
                          <Monitor size={13} aria-hidden="true" className="text-muted" />
                        ) : (
                          <Smartphone size={13} aria-hidden="true" className="text-muted" />
                        )}
                        {lhDesktop !== null ? __('Desktop') : __('Mobile')}
                      </div>
                      <div className="text-[11px] text-muted">
                        {__('Lighthouse performance, out of 100')}
                      </div>
                    </div>
                  </div>

                  <div className="text-[12px] text-muted">
                    {lhDesktop !== null && lhMobile !== null && (
                      <div className="flex items-center gap-1.5">
                        <Smartphone size={12} aria-hidden="true" />
                        <span className="font-semibold text-ink tabular-nums">{lhMobile}</span>
                        <span>{__('mobile')}</span>
                      </div>
                    )}
                    {/* The scan verdict, demoted but labelled — a stricter
                        in-house scale, so it must never read as "the" score.
                        Grade only, no level name: "(Slow)" next to a healthy
                        Lighthouse number read as a verdict on the site. */}
                    <div className="mt-0.5 text-[11px]">
                      {__('Grade')} {latest.grade || '—'}
                      {' · '}
                      {__('xSpeed Scan')} {latest.score ?? '—'}/100
                    </div>
                  </div>
                </>
              );
            })()}
          </div>

          {latest.partial && (
            <p className="mt-3 text-[12px] text-warning">
              {__('Partial scan — some checks could not run, so the score covers less than the full rubric.')}
            </p>
          )}

          <div className="mt-4 grid gap-3 sm:grid-cols-2">
            {Object.entries(latest.dimensions).map(([key, d]) => (
              <DimensionBar key={key} label={dimensionLabel(key)} d={d} />
            ))}
          </div>

          {latest.fixes.length > 0 && (
            <div className="mt-5">
              <h4 className="text-[12px] font-semibold text-ink">
                {__('What to fix next')}
                <span className="ml-1.5 font-normal text-muted">
                  {__('ranked by points recovered')}
                </span>
              </h4>
              <ul className="mt-2 divide-y divide-line">
                {latest.fixes.map((f) => (
                  <li key={f.id} className="py-2 flex items-start gap-3">
                    <span className="shrink-0 mt-0.5 rounded-pill bg-primary-tint px-1.5 py-0.5 text-[11px] font-semibold text-primary tabular-nums">
                      +{f.recoverable}
                    </span>
                    <div className="min-w-0">
                      <p className="text-[12px] font-medium text-ink">{f.name}</p>
                      <p className="text-[11px] text-muted">{f.evidence}</p>
                      {f.remediation && (
                        <p className="mt-0.5 text-[11px] text-ink-soft">{f.remediation}</p>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {latest.report_url && (
            <a
              href={latest.report_url}
              target="_blank"
              rel="noopener noreferrer"
              className="mt-4 inline-flex items-center gap-1 text-[12px] font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded-sm"
            >
              {__('Full report')}
              <ExternalLink size={12} aria-hidden="true" />
            </a>
          )}
        </div>
      )}
    </section>
  );
}
