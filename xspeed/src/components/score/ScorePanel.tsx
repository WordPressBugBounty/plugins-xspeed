import { useCallback, useEffect, useRef, useState } from 'react';
import { Gauge, RefreshCw, AlertTriangle, ExternalLink, Lightbulb, GitCompare } from 'lucide-react';
import { api } from '../../api/client';
import { __ } from '../../i18n';
import { SchemaModulePanel } from '../ModulePanel';
import { Tabs } from '../Tabs';
import { ConnectHubModal, canConnect } from './ConnectHubModal';
import { ScoreRing, scoreTone } from '../ScoreRing';
import { ScanCard } from './ScanCard';
import type { PanelProps } from '../customPanels';

/**
 * External score card (DESIGN.md §24.25, issue #47).
 *
 * People judge a caching plugin by its PageSpeed or GTmetrix number
 * whether or not the plugin shows one, so the number belongs here next to
 * the internal TTFB benchmark rather than one browser tab away.
 *
 * Two things this deliberately does NOT do: run on its own, and run on
 * page load. Every audit here is an outbound request to a third party
 * spending someone's rate limit, so it happens when a person presses the
 * button and at no other time.
 */

interface Metrics {
  lcp: number | null;
  fcp: number | null;
  cls: number | null;
  tbt: number | null;
  si: number | null;
  ttfb: number | null;
}

interface Run {
  ok: boolean;
  provider: string;
  ts: number;
  url: string;
  strategy?: string;
  score: number | null;
  metrics: Partial<Metrics>;
  issues?: Array<{ id: string; title: string; savings_ms: number }>;
  /** GTmetrix hosts a durable report; PSI does not, so often null. */
  report_url?: string | null;
  /** What the report said could be faster (Hub-run tests). */
  opportunities?: Array<{ id: string; title: string; metrics: string[] }> | null;
  /** 'local' (this site called the provider) or 'hub'. */
  source?: string;
  error?: string;
}

interface HistoryResponse {
  runs: Run[];
  latest: Run | null;
  thresholds: Record<string, { good: number; poor: number }>;
}

interface StatusResponse {
  pending?: boolean;
  state?: string;
  latest?: Run | null;
}

/** One Hub-run test, as GET /score/hub-status reports it. */
interface HubRun {
  id: number;
  status: 'queued' | 'running' | 'done' | 'error';
  error: string | null;
  started_at: number;
}

/**
 * GET /score/hub-status.
 *
 * `available` is false whenever the Hub can't serve a test — not connected,
 * not verified, no runner configured. The panel hides the affordance rather
 * than showing an error, because the history below is still worth drawing.
 */
interface HubStatusResponse {
  available: boolean;
  reason?: string;
  message?: string;
  local?: boolean;
  runs?: HubRun[];
  quota?: { used: number; limit: number; remaining: number };
}

const hubRunActive = (r: HubRun | null | undefined): boolean =>
  r?.status === 'queued' || r?.status === 'running';

/**
 * "3 of 5 left" — built here rather than inline so the string stays one
 * translatable unit instead of three concatenated fragments.
 */
function sprintfCount(remaining: number, limit: number): string {
  return __('%1$d of %2$d left')
    .replace('%1$d', String(remaining))
    .replace('%2$d', String(limit));
}

/** Core Web Vitals chips. CLS is unitless; everything else is milliseconds. */
const CHIPS: Array<{ key: keyof Metrics; label: string; unit: 'ms' | 'raw'; title: string }> = [
  { key: 'lcp', label: 'LCP', unit: 'ms', title: 'Largest Contentful Paint — when the main content finished rendering' },
  { key: 'fcp', label: 'FCP', unit: 'ms', title: 'First Contentful Paint — when the first text or image appeared' },
  { key: 'tbt', label: 'TBT', unit: 'ms', title: 'Total Blocking Time — how long the page was unresponsive to input' },
  { key: 'cls', label: 'CLS', unit: 'raw', title: 'Cumulative Layout Shift — how much the page moved while loading' },
  { key: 'si', label: 'SI', unit: 'ms', title: 'Speed Index — how quickly the page visibly filled in' },
  { key: 'ttfb', label: 'TTFB', unit: 'ms', title: 'Time to First Byte — how long the server took to respond' },
];

function rate(
  metric: string,
  value: number | null | undefined,
  thresholds: HistoryResponse['thresholds'],
): 'good' | 'needs-improvement' | 'poor' | 'unknown' {
  const t = thresholds[metric];
  // "We have no measurement" must not render as "poor" — that is a false
  // alarm someone will spend an afternoon chasing.
  if (value === null || value === undefined || !t) return 'unknown';
  if (value <= t.good) return 'good';
  if (value <= t.poor) return 'needs-improvement';
  return 'poor';
}


/**
 * Verdict as a dot rather than a tinted block behind the number.
 *
 * Six filled chips read as six warnings even when every one of them passes —
 * the colour is doing the talking and the value gets lost. A dot says the same
 * thing in a tenth of the ink.
 */
const DOT_TONE: Record<string, string> = {
  good: 'bg-success',
  'needs-improvement': 'bg-warning',
  poor: 'bg-danger',
  unknown: 'bg-line-strong',
};

function formatValue(value: number | null | undefined, unit: 'ms' | 'raw'): string {
  if (value === null || value === undefined) return '—';
  return unit === 'raw' ? value.toFixed(3) : `${Math.round(value)} ms`;
}

/**
 * Score over time, drawn as an inline SVG.
 *
 * No chart library: the bundle is already over budget, and a polyline over
 * N points does not justify a dependency. Oldest → newest, left to right,
 * which is how people read a trend.
 */
function Sparkline({ runs }: { runs: Run[] }) {
  const points = runs
    .filter((r) => r.ok && typeof r.score === 'number')
    .slice(0, 12)
    .reverse()
    .map((r) => r.score as number);

  // Two points is the minimum that can show a direction.
  if (points.length < 2) return null;

  const w = 120;
  const h = 28;
  // Fixed 0-100 domain, not min/max of the data: an auto-scaled axis turns a
  // 2-point wobble into a cliff and makes a flat site look volatile.
  const path = points
    .map((v, i) => `${(i / (points.length - 1)) * w},${h - (v / 100) * h}`)
    .join(' ');

  const first = points[0] ?? 0;
  const last = points[points.length - 1] ?? 0;
  const delta = last - first;

  return (
    <div className="flex items-center gap-2">
      <svg width={w} height={h} viewBox={`0 0 ${w} ${h}`} aria-hidden="true" className="overflow-visible">
        <polyline
          points={path}
          fill="none"
          strokeWidth="1.5"
          className={delta >= 0 ? 'stroke-success' : 'stroke-danger'}
          strokeLinejoin="round"
          strokeLinecap="round"
        />
      </svg>
      <span
        className={`text-[11px] tabular-nums ${
          delta > 0 ? 'text-success' : delta < 0 ? 'text-danger' : 'text-muted'
        }`}
        title={__('Change across the runs shown')}
      >
        {delta > 0 ? '+' : ''}
        {delta === 0 ? __('no change') : delta}
      </span>
    </div>
  );
}

function formatWhen(ts: number): string {
  const diff = Math.floor(Date.now() / 1000) - ts;
  if (diff < 60) return __('just now');
  if (diff < 3600) return `${Math.floor(diff / 60)}m ${__('ago')}`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ${__('ago')}`;
  return `${Math.floor(diff / 86400)}d ${__('ago')}`;
}

export function ScorePanel({ module, onSaved, subtab, onSubtabChange }: PanelProps) {
  const [data, setData] = useState<HistoryResponse | null>(null);
  const [busy, setBusy] = useState(false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const pollTimer = useRef<number | null>(null);

  // --- Hub-powered GTmetrix ---
  const [hub, setHub] = useState<HubStatusResponse | null>(null);
  const [hubRun, setHubRun] = useState<HubRun | null>(null);
  const [hubBusy, setHubBusy] = useState(false);
  const [connectOpen, setConnectOpen] = useState(false);
  /** Two runs picked from the history, oldest-first is not assumed. */
  const [cmp, setCmp] = useState<number[]>([]);
  const hubTimer = useRef<number | null>(null);

  /** The site has its own key — we use it, and spend nothing. */
  const ownKey =
    module.settings?.provider === 'gtmetrix' &&
    typeof module.settings?.gtmetrix_api_key === 'string' &&
    module.settings.gtmetrix_api_key !== '';
  /** The Hub can serve a test right now. */
  const hubReady = hub?.available === true && (hub.quota?.remaining ?? 0) > 0;
  /**
   * Lead with GTmetrix whenever the Hub could serve this site — connected
   * already, or one Connect away.
   *
   * Gating on `available` alone was wrong: an unconnected site kept the old
   * "External score" title and the old disabled button, so the panel said
   * nothing about the free test while a strip underneath offered it. The
   * heading and the primary action have to agree.
   */
  const hubFirst = !ownKey && (hub?.available === true || (hub ? canConnect(hub.reason) : false));

  const loadHistory = useCallback(async () => {
    try {
      setData(await api.request<HistoryResponse>('/score/history'));
    } catch {
      setData({ runs: [], latest: null, thresholds: {} });
    }
  }, []);

  useEffect(() => {
    void loadHistory();
  }, [loadHistory]);

  /** Hub availability + remaining allowance. Never throws — see the route. */
  const loadHub = useCallback(async () => {
    try {
      const s = await api.request<HubStatusResponse>('/score/hub-status');
      setHub(s);
      setHubRun(s.runs?.[0] ?? null);
    } catch {
      // A failure here means "no Hub affordance", not an error banner.
      setHub({ available: false });
    }
  }, []);

  useEffect(() => {
    void loadHub();
  }, [loadHub]);

  // GTmetrix queues its tests, so a run finishes minutes after the button
  // press. Poll while one is in flight, and stop the moment it resolves —
  // an orphaned interval keeps hitting a third-party API forever.
  const poll = useCallback(async () => {
    try {
      const status = await api.request<StatusResponse>('/score/status');
      if (!status.pending) {
        setPending(false);
        await loadHistory();
        return;
      }
      pollTimer.current = window.setTimeout(() => void poll(), 6000);
    } catch (e) {
      setPending(false);
      setError(e instanceof Error ? e.message : __('Could not check the test status.'));
    }
  }, [loadHistory]);

  useEffect(() => {
    return () => {
      if (pollTimer.current) window.clearTimeout(pollTimer.current);
    };
  }, []);

  /*
   * Poll a Hub-run test.
   *
   * Separate from poll() above because it watches a different thing: that one
   * asks THIS SITE whether its own GTmetrix job finished, this one asks the
   * Hub. Merging them would mean one loop with two meanings.
   */
  const pollHub = useCallback(async () => {
    try {
      const s = await api.request<HubStatusResponse>('/score/hub-status');
      setHub(s);
      const latestRun = s.runs?.[0] ?? null;
      setHubRun(latestRun);

      if (hubRunActive(latestRun)) {
        hubTimer.current = window.setTimeout(() => void pollHub(), 5000);
        return;
      }
      if (latestRun?.status === 'error' && latestRun.error) setError(latestRun.error);
      // Done (or gone) — the Hub has written the result, so pull it in.
      await loadHistory();
    } catch {
      // Stop rather than hammer: the give-up is silent because the run may
      // well still be fine, and the history refresh below will show it.
      await loadHistory();
    }
  }, [loadHistory]);

  // Clear the Hub poll on unmount — an orphaned interval keeps calling the
  // Hub after the user has navigated away.
  useEffect(() => {
    return () => {
      if (hubTimer.current) window.clearTimeout(hubTimer.current);
    };
  }, []);

  /** Start a Hub-run GTmetrix test. */
  const runViaHub = useCallback(async () => {
    setHubBusy(true);
    setError(null);
    try {
      const res = await api.request<{ run: HubRun; quota: HubStatusResponse['quota'] }>(
        '/score/hub-test',
        { method: 'POST', body: JSON.stringify({}) },
      );
      setHubRun(res.run);
      setHub((h) => (h ? { ...h, quota: res.quota ?? h.quota } : h));
      hubTimer.current = window.setTimeout(() => void pollHub(), 5000);
    } catch (e) {
      setError(e instanceof Error ? e.message : __('The test could not be started.'));
    } finally {
      setHubBusy(false);
    }
  }, [pollHub]);

  /**
   * The Test button's single entry point.
   *
   * Not connected → raise the modal instead of failing. The modal calls
   * runViaHub() itself once the user is through, so the test they asked for
   * still happens.
   */
  const onTestClick = useCallback(() => {
    if (hub?.available) {
      void runViaHub();
      return;
    }
    setConnectOpen(true);
  }, [hub?.available, runViaHub]);

  const run = async () => {
    setBusy(true);
    setError(null);
    try {
      const result = await api.request<Run & { pending?: boolean }>('/score/run', {
        method: 'POST',
        body: JSON.stringify({}),
      });
      if (result.pending) {
        setPending(true);
        pollTimer.current = window.setTimeout(() => void poll(), 6000);
      } else {
        await loadHistory();
        if (!result.ok && result.error) setError(result.error);
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : __('The test could not be started.'));
    } finally {
      setBusy(false);
    }
  };

  const latest = data?.latest ?? null;
  const thresholds = data?.thresholds ?? {};

  /*
   * Three tabs, not one 1150px stack.
   *
   * The page previously ran scan results, provider results, history and
   * five raw settings down a single column, so configuration sat at the
   * same visual level as data and you scrolled past API-key fields to
   * reach a score. These are three different jobs: read the graded
   * report, run a provider audit, configure the providers.
   *
   * `subtab` binds to the URL hash (#score/settings), so a tab is
   * deep-linkable and survives back/forward.
   */
  // Two tabs, not three: the settings live UNDER the test they configure
  // (#425 follow-up). A separate Settings tab made the panel read as "the
  // test is off until you visit the other tab" — the exact funnel the
  // one-press flow removed. Old #score/settings deep links land on the
  // merged tab.
  const tab = (subtab || 'scan') === 'settings' ? 'test' : subtab || 'scan';
  const setTab = onSubtabChange ?? (() => {});

  /*
   * The `enabled` toggle is not rendered. The press is the consent — the
   * run flips it on server-side — so a visible switch would only restate
   * the confusion the merge removes. The setting itself survives as the
   * gate for non-press callers (optimize runs, GTmetrix polling).
   */
  const settingsModule = {
    ...module,
    schema: Object.fromEntries(Object.entries(module.schema).filter(([, spec]) => !spec.hidden)),
  };

  return (
    <div className="space-y-4">
      <Tabs
        idBase="score"
        value={tab}
        onChange={setTab}
        items={[
          { key: 'scan', label: __('xSpeed Scan') },
          { key: 'test', label: __('Speed test') },
        ]}
      />

      {tab === 'scan' && <ScanCard />}

      <div className={tab === 'test' ? 'border border-line bg-paper rounded-lg p-4 sm:p-5' : 'hidden'}>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="flex items-center gap-3 min-w-0">
            <Gauge size={18} className="text-accent shrink-0" />
            <div className="min-w-0">
              {/* One name, both paths. "External score" was engineering
                  vocabulary — it named where the number comes from, not
                  what the user gets. */}
              <div className="font-medium text-ink">
                {__('Performance score')}
              </div>
              <div className="text-[12px] text-muted">
                {latest
                  ? `${latest.provider === 'gtmetrix' ? 'GTmetrix' : 'PageSpeed Insights'} · ${formatWhen(latest.ts)}`
                  : hubFirst
                    ? __('Performance score and Core Web Vitals.')
                    : __('No audit run yet.')}
              </div>
            </div>
          </div>

          {/*
            One button, never two.
            - The site has its own key → the existing path, on their quota.
            - Otherwise → the Hub, which needs no key at all. Not connected
              yet? The click raises the modal instead of failing, and the test
              still runs once they're through.
          */}
          {hubFirst ? (
            <div className="flex items-center gap-2.5">
              {hub?.quota && (
                <span
                  className="text-[11px] text-muted"
                  title={__('Free tests included with your plan')}
                >
                  {sprintfCount(hub.quota.remaining, hub.quota.limit)}
                </span>
              )}
              <button
                type="button"
                onClick={onTestClick}
                disabled={hubBusy || hubRunActive(hubRun) || (hub?.available === true && !hubReady)}
                title={
                  hub?.available && (hub.quota?.remaining ?? 0) === 0
                    ? __('You have used this month’s free tests.')
                    : __('Run a GTmetrix test on this site')
                }
                className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-accent bg-accent text-white hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-50"
              >
                {(hubBusy || hubRunActive(hubRun)) && (
                  <RefreshCw size={13} className="animate-spin" />
                )}
                {hubRunActive(hubRun) ? __('Testing…') : __('Test with GTmetrix')}
              </button>
            </div>
          ) : (
            /* Never disabled by the settings toggle: pressing Test IS the
               opt-in — the run flips the toggle on server-side (#425). */
            <button
              type="button"
              onClick={run}
              disabled={busy || pending}
              title={__('Nothing is sent anywhere until you press this.')}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-accent bg-accent text-white hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-50"
            >
              {(busy || pending) && <RefreshCw size={13} className="animate-spin" />}
              {pending ? __('Running…') : __('Test now')}
            </button>
          )}
        </div>

        {error && (
          <div className="mt-3 flex items-start gap-2 text-[12px] text-danger">
            <AlertTriangle size={13} className="mt-0.5 shrink-0" />
            <span>{error}</span>
          </div>
        )}

        {latest && (
          <div className="mt-4 rounded-lg border border-line p-4">
            <div className="flex flex-wrap items-center gap-x-6 gap-y-4">
              {/* The score as a ring rather than a bare number: it reads as
                  "79 out of 100" at a glance, which a numeral alone does not. */}
              <div className="flex items-center gap-3">
                <ScoreRing score={latest.score} size={64} />
                <div>
                  <div className="text-[12px] font-medium text-ink">
                    {__('Performance')}
                  </div>
                  <div className="text-[11px] text-muted">
                    {/* Just the strategy: the card header above already
                        carries the provider and the time. */}
                    {latest.strategy || __('score out of 100')}
                  </div>
                </div>
              </div>

              {/* Core Web Vitals first, then the supporting timings — the
                  order Google ranks on, not the order we happen to store. */}
              <div className="grid flex-1 grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-3">
                {CHIPS.map((chip) => {
                  const value = latest.metrics?.[chip.key] ?? null;
                  const verdict = rate(chip.key, value, thresholds);
                  return (
                    <div key={chip.key} className="min-w-0" title={chip.title}>
                      <div className="flex items-center gap-1.5">
                        {/* A dot carries the verdict, so the number stays
                            readable instead of sitting on a tinted block. */}
                        <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${DOT_TONE[verdict]}`} />
                        <span className="text-[10px] uppercase tracking-wider text-muted">
                          {chip.label}
                        </span>
                      </div>
                      <div className="mt-0.5 text-[14px] font-medium tabular-nums text-ink">
                        {formatValue(value, chip.unit)}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>

            <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-line pt-3 text-[11px] text-muted">
              {latest.url && <span className="truncate max-w-[22rem]">{latest.url}</span>}
              {latest.report_url && (
                <a
                  href={latest.report_url}
                  target="_blank"
                  rel="noreferrer"
                  /* Named for where it goes. "Full report" implied ours; this
                     link is the provider's own page. */
                  className="inline-flex items-center gap-1 hover:text-accent"
                >
                  {__('View on GTmetrix')}
                  <ExternalLink size={11} />
                </a>
              )}
            </div>
          </div>
        )}

        {latest?.opportunities && latest.opportunities.length > 0 && (
          <div className="mt-4 border-t border-line pt-3">
            <div className="mb-1.5 flex items-center gap-1.5 text-[12px] font-medium text-ink">
              <Lightbulb size={13} className="text-accent" />
              {__('What to improve')}
            </div>
            <ul className="space-y-1">
              {latest.opportunities.slice(0, 6).map((o) => (
                <li
                  key={o.id}
                  className="flex items-baseline justify-between gap-3 text-[12px] text-ink-soft"
                >
                  <span className="min-w-0">{o.title}</span>
                  {o.metrics.length > 0 && (
                    <span className="shrink-0 text-[10px] uppercase tracking-wide text-faint">
                      {o.metrics.join(' · ')}
                    </span>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}

        {latest?.issues && latest.issues.length > 0 && (
          <ul className="mt-4 space-y-1">
            {latest.issues.slice(0, 3).map((issue) => (
              <li
                key={issue.id}
                className="flex items-baseline justify-between gap-3 text-[12px] text-ink-soft"
              >
                <span className="min-w-0 truncate">{issue.title}</span>
                <span className="tabular-nums text-muted shrink-0">
                  −{Math.round(issue.savings_ms)} ms
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>

      {/* History belongs with the provider audit that produced it. */}
      {tab === 'test' && data && data.runs.length > 1 && (
        <div className="border border-line bg-paper rounded-lg p-4 sm:p-5">
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <div className="text-[12px] font-medium text-ink">
              {__('Run history')}
            </div>
            {/* The trend, where it belongs: next to the runs it is drawn
                from, not as a separate card claiming its own space. */}
            <Sparkline runs={data.runs} />
          </div>

          {cmp.length === 2 && (() => {
            const a = data.runs.find((r) => r.ts === cmp[0]);
            const b = data.runs.find((r) => r.ts === cmp[1]);
            if (!a || !b) return null;
            // Order by time so "before → after" is always literally true,
            // whichever two rows were clicked first.
            const [before, after] = a.ts <= b.ts ? [a, b] : [b, a];
            const rows: Array<[string, number | null, number | null, 'ms' | 'raw' | 'score']> = [
              [__('Performance'), before.score, after.score, 'score'],
              ['LCP', before.metrics?.lcp ?? null, after.metrics?.lcp ?? null, 'ms'],
              ['TBT', before.metrics?.tbt ?? null, after.metrics?.tbt ?? null, 'ms'],
              ['CLS', before.metrics?.cls ?? null, after.metrics?.cls ?? null, 'raw'],
              ['TTFB', before.metrics?.ttfb ?? null, after.metrics?.ttfb ?? null, 'ms'],
            ];
            return (
              <div className="mb-3 rounded border border-line p-3">
                <div className="mb-2 flex items-center justify-between text-[11px] text-muted">
                  <span>
                    {formatWhen(before.ts)} → {formatWhen(after.ts)}
                  </span>
                  <button
                    type="button"
                    onClick={() => setCmp([])}
                    className="text-accent hover:underline"
                  >
                    {__('Clear')}
                  </button>
                </div>
                <table className="w-full border-collapse text-left">
                  <thead>
                    <tr className="text-[10px] uppercase tracking-wider text-faint">
                      <th scope="col" className="py-1 font-medium">{__('Metric')}</th>
                      <th scope="col" className="w-20 py-1 text-right font-medium">{__('Before')}</th>
                      <th scope="col" className="w-20 py-1 text-right font-medium">{__('After')}</th>
                      <th scope="col" className="w-24 py-1 text-right font-medium">{__('Change')}</th>
                    </tr>
                  </thead>
                  <tbody>
                  {rows.map(([label, bv, av, unit]) => {
                    // Higher is better for the score and worse for every
                    // timing — without that the arrow would lie on half the
                    // rows.
                    const higherBetter = unit === 'score';
                    const delta = bv !== null && av !== null ? av - bv : null;
                    const better =
                      delta === null || delta === 0 ? null : higherBetter ? delta > 0 : delta < 0;
                    return (
                      <tr key={label} className="border-t border-line">
                        <td className="py-1 text-[12px] text-muted">
                          {label}
                        </td>
                        <td className="py-1 text-right text-[12px] tabular-nums text-muted">
                          {unit === 'score' ? (bv ?? '—') : formatValue(bv, unit === 'raw' ? 'raw' : 'ms')}
                        </td>
                        <td className="py-1 text-right text-[12px] tabular-nums text-ink">
                          {unit === 'score' ? (av ?? '—') : formatValue(av, unit === 'raw' ? 'raw' : 'ms')}
                        </td>
                        <td
                          className={`py-1 text-right text-[12px] tabular-nums ${
                            better === true
                              ? 'text-success'
                              : better === false
                                ? 'text-danger'
                                : 'text-faint'
                          }`}
                        >
                          {delta === null
                            ? '—'
                            : delta === 0
                              ? __('no change')
                              : `${delta > 0 ? '+' : '−'}${unit === 'score' ? Math.abs(delta) : formatValue(Math.abs(delta), unit === 'raw' ? 'raw' : 'ms')}`}
                          {better === true && ' ▲'}
                          {better === false && ' ▼'}
                        </td>
                      </tr>
                    );
                  })}
                  </tbody>
                </table>
              </div>
            );
          })()}

          {data.runs.length > 1 && cmp.length < 2 && (
            <p className="mb-2 text-[11px] text-muted">
              {cmp.length === 1
                ? __('Pick one more run to compare.')
                : __('Pick two runs to compare them.')}
            </p>
          )}
          {/* A table: each metric labelled ONCE in the header rather than
              repeated on every row, and the numbers line up in columns. */}
          <div className="overflow-x-auto">
            <table className="w-full min-w-[34rem] border-collapse text-left">
              <thead>
                <tr className="border-b border-line text-[10px] uppercase tracking-wider text-faint">
                  <th scope="col" className="w-8 py-1.5 font-medium">
                    <span className="sr-only">{__('Compare')}</span>
                  </th>
                  <th scope="col" className="w-20 py-1.5 font-medium">{__('When')}</th>
                  <th scope="col" className="py-1.5 font-medium">{__('Provider')}</th>
                  <th scope="col" className="w-12 py-1.5 text-right font-medium">{__('Score')}</th>
                  <th scope="col" className="w-20 py-1.5 text-right font-medium" title="Largest Contentful Paint">LCP</th>
                  <th scope="col" className="w-16 py-1.5 text-right font-medium" title="Total Blocking Time">TBT</th>
                  <th scope="col" className="w-14 py-1.5 text-right font-medium" title="Cumulative Layout Shift">CLS</th>
                  <th scope="col" className="w-20 py-1.5 text-right font-medium" title="Time to First Byte">TTFB</th>
                </tr>
              </thead>
              <tbody>
                {data.runs.slice(0, 10).map((entry, i) => (
                  <tr
                    key={`${entry.ts}-${i}`}
                    className={`border-b border-line last:border-b-0 ${
                      cmp.includes(entry.ts) ? 'bg-panel' : ''
                    }`}
                  >
                    <td className="py-1.5">
                      <button
                        type="button"
                        onClick={() =>
                          setCmp((prev) =>
                            prev.includes(entry.ts)
                              ? prev.filter((t) => t !== entry.ts)
                              : [...prev, entry.ts].slice(-2),
                          )
                        }
                        aria-pressed={cmp.includes(entry.ts)}
                        title={__('Compare this run')}
                        className={`rounded p-0.5 ${
                          cmp.includes(entry.ts)
                            ? 'text-accent'
                            : 'text-faint hover:text-accent'
                        }`}
                      >
                        <GitCompare size={12} />
                      </button>
                    </td>
                    <td className="py-1.5 text-[11px] text-muted">
                      {formatWhen(entry.ts)}
                    </td>
                    <td className="min-w-0 py-1.5 text-[12px] text-muted">
                      {/* A failed run stays in the list. Hiding it would make
                          "we tried and the provider refused" look like nobody
                          ever ran a test. */}
                      <span className="flex items-center gap-1.5">
                        <span className="truncate">
                          {entry.ok
                            ? entry.provider === 'gtmetrix'
                              ? 'GTmetrix'
                              : 'PageSpeed'
                            : entry.error}
                        </span>
                        {entry.ok && entry.report_url && (
                          <a
                            href={entry.report_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="shrink-0 text-faint hover:text-accent"
                            aria-label={__('Open the full report')}
                          >
                            <ExternalLink size={11} />
                          </a>
                        )}
                      </span>
                    </td>
                    <td
                      className={`py-1.5 text-right text-[13px] font-medium tabular-nums ${scoreTone(entry.score)}`}
                    >
                      {entry.ok ? (entry.score ?? '—') : '—'}
                    </td>
                    <td className="py-1.5 text-right text-[11px] tabular-nums text-muted">
                      {formatValue(entry.metrics?.lcp ?? null, 'ms')}
                    </td>
                    <td className="py-1.5 text-right text-[11px] tabular-nums text-muted">
                      {formatValue(entry.metrics?.tbt ?? null, 'ms')}
                    </td>
                    <td className="py-1.5 text-right text-[11px] tabular-nums text-muted">
                      {formatValue(entry.metrics?.cls ?? null, 'raw')}
                    </td>
                    <td className="py-1.5 text-right text-[11px] tabular-nums text-muted">
                      {formatValue(entry.metrics?.ttfb ?? null, 'ms')}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <ConnectHubModal
        open={connectOpen}
        onClose={() => setConnectOpen(false)}
        local={hub?.local === true}
        onConnected={() => {
          // Refresh availability, then run the test they originally asked
          // for. The modal has already closed itself by now.
          void loadHub().then(() => void runViaHub());
        }}
      />

      {/* Configuration, below the test and history it configures. Rendered
          by the schema panel so it behaves like every other setting in the
          app; the `enabled` toggle is deliberately absent (see above). */}
      {tab === 'test' && <SchemaModulePanel module={settingsModule} onSaved={onSaved} />}
    </div>
  );
}
