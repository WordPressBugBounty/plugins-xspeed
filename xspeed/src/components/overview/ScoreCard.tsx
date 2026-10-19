import { useEffect, useState } from 'react';
import { Monitor, Smartphone, ArrowRight } from 'lucide-react';
import { __ } from '../../i18n';
import { api } from '../../api/client';
import { ScoreRing } from '../ScoreRing';
import { relativeTime } from './format';

/**
 * Performance score for the Overview hero (DESIGN.md §24.31).
 *
 * Read-only by construction: it reads the score the site has already
 * recorded and NEVER starts a scan. A scan is an external call that takes
 * up to two minutes and spends provider quota, so it stays an explicit
 * action on the Score panel; this card links there rather than owning it.
 *
 * Both sides of the toggle are fed by the xSpeed Scan, which measures
 * mobile AND desktop on every run. The Score history is the fallback for a
 * site that has never scanned; there a run records one strategy, and in
 * practice that is always mobile, so its desktop side can be empty while
 * the scan's is not.
 */

/** One run, narrowed to the fields this card reads. */
interface ScoreRun {
  ok: boolean;
  ts: number;
  score: number | null;
  strategy?: string;
  error?: string;
}

interface HistoryResponse {
  runs: ScoreRun[];
}

/**
 * The scan's own Lighthouse pair, narrowed to what this card reads.
 *
 * One scan measures BOTH strategies, so this is the only source that can
 * fill both sides of the toggle from a single run. `/score/history` cannot:
 * a Score run records one strategy, and in practice that is always mobile,
 * which is why the desktop side sat empty while the report plainly showed a
 * desktop number.
 */
interface ScanStatusResponse {
  latest?: {
    measured?: {
      lighthouse?: number | null;
      lighthouse_desktop?: number | null;
      lighthouse_mobile?: number | null;
    } | null;
    ts?: number;
  } | null;
  /** 'unreadable': a report was stored but could not be read back (#468). */
  stored?: 'none' | 'ok' | 'unreadable';
}

type Strategy = 'desktop' | 'mobile';

/** A score older than this is shown, but flagged as no longer current. */
const STALE_AFTER_DAYS = 30;

/**
 * What one strategy's history amounts to. `latest` is the newest run of
 * any kind; `lastGood` is the newest run that actually produced a number.
 * They differ whenever the most recent attempt failed — a quota error or
 * an unreachable provider — and that gap is exactly what the card has to
 * communicate rather than collapse into a bare em dash.
 */
function summarize(runs: ScoreRun[], strategy: Strategy) {
  // Runs predating the strategy field are mobile: that was the only
  // strategy the scan ran, so attributing them to desktop would invent a
  // desktop history that never existed.
  const mine = runs.filter((r) => (r.strategy || 'mobile') === strategy);
  const latest = mine[0] ?? null;
  const lastGood = mine.find((r) => r.ok && typeof r.score === 'number') ?? null;
  const failedSinceGood = latest !== null && !latest.ok && lastGood !== null;
  const stale =
    lastGood !== null &&
    Date.now() / 1000 - lastGood.ts > STALE_AFTER_DAYS * 86400;

  return { latest, lastGood, failedSinceGood, stale, hasAny: mine.length > 0 };
}

function StrategyToggle({
  value,
  onChange,
}: {
  value: Strategy;
  onChange: (s: Strategy) => void;
}) {
  const opts: Array<{ id: Strategy; label: string; Icon: typeof Monitor }> = [
    { id: 'desktop', label: __('Desktop'), Icon: Monitor },
    { id: 'mobile', label: __('Mobile'), Icon: Smartphone },
  ];

  return (
    <div
      className="inline-flex rounded-control border border-line p-0.5"
      role="group"
      aria-label={__('Score strategy')}
    >
      {opts.map(({ id, label, Icon }) => {
        const active = value === id;
        return (
          <button
            key={id}
            type="button"
            onClick={() => onChange(id)}
            aria-pressed={active}
            // Icon-only: the hero slot is tight, and the selected strategy is
            // already named in the detail line under the ring. The label lives
            // on as the accessible name and the tooltip.
            aria-label={label}
            title={label}
            // Without labels the only "which one is on?" cue is this chip, so
            // it carries the primary tint rather than a near-invisible paper
            // swap: at 13px an icon two shades of grey apart reads as neither.
            className={`inline-flex items-center justify-center rounded-[5px] p-1 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary ${
              active
                ? 'bg-primary-tint text-primary'
                : 'text-faint hover:text-ink-soft'
            }`}
          >
            <Icon size={13} aria-hidden="true" />
          </button>
        );
      })}
    </div>
  );
}

export function ScoreCard({ onOpenScore }: { onOpenScore: () => void }) {
  const [runs, setRuns] = useState<ScoreRun[] | null>(null);
  const [scan, setScan] = useState<ScanStatusResponse['latest'] | null>(null);
  const [scanLost, setScanLost] = useState(false);
  // Desktop leads: it is the default the product wants people to read first.
  const [strategy, setStrategy] = useState<Strategy>('desktop');

  useEffect(() => {
    let live = true;
    // Both sources, because neither alone fills the card: the scan measures
    // both strategies in one run but only exists once someone has scanned;
    // the history has depth but records one strategy per run.
    api
      .request<HistoryResponse>('/score/history')
      // A site that has never scanned, or has the module off, is not an
      // error state — it is an empty one. Both land as "no runs".
      .then((d) => live && setRuns(Array.isArray(d.runs) ? d.runs : []))
      .catch(() => live && setRuns([]));
    api
      .request<ScanStatusResponse>('/score/scan-status')
      .then((d) => {
        if (!live) return;
        setScan(d.latest ?? null);
        setScanLost(d.stored === 'unreadable');
      })
      .catch(() => live && setScan(null));
    return () => {
      live = false;
    };
  }, []);

  const { lastGood, failedSinceGood, stale, hasAny } = summarize(runs ?? [], strategy);

  /*
   * The scan is preferred for the number itself: it measures mobile AND
   * desktop on every run, so the toggle has two real sides. The Score
   * history stays the fallback -- it is the only source on a site that has
   * never run a scan, and the only one with history behind it.
   *
   * Both are Lighthouse performance on the same 0-100 scale, so choosing
   * between them is a freshness question, not a units one. The scan's own
   * rubric grade is a DIFFERENT scale and is deliberately not read here.
   */
  // Both sides read their OWN device's field. `measured.lighthouse` is the
  // graded run, which is desktop on a default scan, so using it for the
  // mobile side reported desktop as mobile.
  const scanScore =
    strategy === 'desktop'
      ? scan?.measured?.lighthouse_desktop ?? null
      : scan?.measured?.lighthouse_mobile ?? null;
  const usingScan = scanScore !== null;
  const score = usingScan ? scanScore : lastGood?.score ?? null;
  const scoreTs = usingScan ? scan?.ts ?? null : lastGood?.ts ?? null;
  const loading = runs === null;

  // The quiet supporting line: which strategy, how old, and whether the
  // newest attempt failed. Dot-separated facts rather than a sentence.
  const meta = (() => {
    if (loading) return __('Loading…');
    if (score === null && scanLost) return __('Last report unreadable');
    if (score === null) return hasAny ? __('No completed scan') : __('Never scanned');
    // Just strategy and age. The failed/stale caveat is the subline's job —
    // saying it in both places cost the meta line its room and truncated it.
    const age = scoreTs === null ? '' : ` · ${relativeTime(scoreTs)}`;
    return `${strategy === 'desktop' ? __('desktop') : __('mobile')}${age}`;
  })();

  // The bar's target. A score is "good" at 90 in Lighthouse's own banding, so
  // that is the goalpost the bar measures against rather than a bare 100 —
  // filling a bar to 82/100 implies 18 points of work when the real gap is 8.
  const GOOD = 90;
  const pct = score === null ? 0 : Math.min(100, (score / GOOD) * 100);

  const headline = (() => {
    if (loading) return __('Checking your score');
    if (score === null) return __('No score yet');
    if (score >= GOOD) return __('Your site is fast');
    return __('Room to go faster');
  })();

  const subline = (() => {
    if (loading) return __('Reading your latest result.');
    // The scan answers first when it supplied the number: its caveats are
    // its own, and the history's failed/stale state says nothing about it.
    if (usingScan) return __('Lighthouse performance, from your latest xSpeed Scan.');
    if (score === null && scanLost) return __('The last report could not be read back from the database. Run the scan again.');
    if (score === null && !hasAny) return __('See how this site performs for real visitors.');
    if (score === null) return __('The last scan did not complete.');
    if (failedSinceGood) return __('Showing your last completed scan.');
    if (stale) return __('Over a month old — may not reflect the site now.');
    return __('Measured on your home page by an external audit.');
  })();

  return (
    <div className="mt-auto flex items-center gap-5">
      {/* Ring leads, at display size with its own caption — the score is the
          headline number of this card, not an ornament beside the text. */}
      <div className="flex flex-col items-center gap-1 shrink-0">
        <ScoreRing
          score={loading ? null : score}
          size={72}
          thickness={3.5}
          label={
            score === null
              ? __('No performance score measured yet')
              : `${__('Performance score')}: ${score} ${__('out of 100')}`
          }
        />
        <span className="text-[10px] font-medium uppercase tracking-wide text-muted">
          {__('Score')}
        </span>
      </div>

      <div className="min-w-0 flex-1">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="font-display text-[15px] font-semibold text-ink leading-snug">
              {headline}
            </p>
            <p className="mt-0.5 text-[12px] text-ink-soft leading-snug">{subline}</p>
          </div>
          <StrategyToggle value={strategy} onChange={setStrategy} />
        </div>

        {/* The bar is always present, with its content adapting — a slot that
            appears and disappears with the data makes the card jump height
            between the two toggle sides, and the empty rail still reads as
            "there is a scale here, you are not on it yet". */}
        <div
          className="mt-2.5 h-1.5 rounded-pill bg-panel overflow-hidden"
          role="progressbar"
          aria-valuenow={score ?? undefined}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-label={__('Performance score')}
        >
          {/* Brand, not a second colour band. The ring beside it already
              carries Lighthouse's 90/50 verdict (DESIGN.md §24.31), so
              repeating it here spent the card's only brand surface saying
              something the reader has just been told. The bar's job is
              PROGRESS toward the 90 goalpost -- a length, not a judgement --
              and the `good 90+` label below still names the target. */}
          {score !== null && (
            <div
              className="h-full rounded-pill bg-primary"
              style={{ width: `${pct}%` }}
            />
          )}
        </div>
        <div className="mt-1 flex items-center justify-between text-[11px]">
          <span className="text-muted">
            {__('now')}{' '}
            <span className="font-semibold text-ink tabular-nums">
              {score === null ? '—' : score}
            </span>
          </span>
          <span className="text-muted">
            {__('good')} <span className="font-semibold text-success">{GOOD}+</span>
          </span>
        </div>

        {/* Meta line — the supporting facts, deliberately quiet. */}
        <div className="mt-1.5 flex items-center gap-2 text-[11px] text-muted">
          <span className="truncate">{meta}</span>
          {!loading && (
            <button
              type="button"
              onClick={onOpenScore}
              className="shrink-0 ml-auto inline-flex items-center gap-1 font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded-sm"
            >
              {/* Keyed on the score actually SHOWN, not on the Score
                  history alone. The scan feeds this card too, so asking
                  `lastGood` here offered "Run a scan" on the desktop side
                  while a scan-measured desktop number sat directly above
                  it -- and "View details" on mobile, from the same data. */}
              {score === null ? __('Run a scan') : __('View details')}
              <ArrowRight size={11} aria-hidden="true" />
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
