import { useEffect, useRef, useState } from 'react';
import { CheckCircle2, Check } from 'lucide-react';
import { __ } from '../../i18n';

/**
 * The miniature "ask your agent to optimize the site" session on the Overview's
 * MCP card. Pure decoration with no inputs: it takes no props, reads no state,
 * and knows nothing about MCP, the connection status or the drawer. The card
 * knows nothing about its timing in return.
 *
 * That total absence of a seam is why it lives in its own file. It also makes it
 * directly testable — exercising the animation through the card meant standing
 * up an API mock and interleaving fake timers with a fetch promise just to watch
 * a gauge count, which is how three separate test failures were manufactured
 * before this split.
 */

/**
 * The illustrative session, indexed by step.
 *
 *   0 idle · 1 prompt · 2 analyzing · 3-5 tasks clearing, score climbing
 *
 * The numbers are an EXAMPLE and the panel says so on screen at every step —
 * see the "Example session" chip, which is outside the fade so it is visible
 * from the opening 72 through the settled 98. Never 100, and nothing here
 * claims a site will reach any particular score.
 */
const SESSION_SCORES = [72, 72, 72, 86, 94, 98];
/** Index of the final frame. Derived, so adding a frame can't desync the copy. */
const SETTLED_STEP = SESSION_SCORES.length - 1;
const SESSION_GAIN = SESSION_SCORES[SETTLED_STEP] - SESSION_SCORES[0]; // +26

/** Cumulative ms from the top of the loop. The last entry resets it. */
const SESSION_TIMELINE: { at: number; step: number }[] = [
  { at: 600, step: 1 },
  { at: 1600, step: 2 },
  { at: 2700, step: 3 },
  { at: 3600, step: 4 },
  { at: 4500, step: 5 },
  { at: 9000, step: 0 },
];

/*
 * Matches the 900ms gap between frames on purpose. At 1100ms each tween was
 * still running when the next frame landed, so its cleanup cancelled it partway
 * and the gauge slid 72 → 98 without ever displaying 86 or 94 — the two
 * intermediate values the approved design actually shows.
 */
const COUNT_MS = 900;
const RING_R = 21;
const RING_C = 2 * Math.PI * RING_R; // 131.95

const REDUCED_MOTION = '(prefers-reduced-motion: reduce)';
/*
 * The panel is CSS-hidden below 640px (see AiControlCard). Hidden is not the
 * same as free: without this the timeline and its animation frames kept running
 * behind `display: none`, burning a wake-up every 900ms on exactly the devices
 * least able to spare it.
 *
 * Phrased as max-width on purpose. Every query here answers `false` where
 * `matchMedia` is unavailable, so a max-width question degrades to "not narrow"
 * — the safe default — where a min-width one would degrade to "narrow" and
 * silently disable the animation everywhere.
 */
const NARROW = '(max-width: 639.98px)';

function mediaMatches(query: string): boolean {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false;
  return window.matchMedia(query).matches;
}

/**
 * Tracks a media query and keeps tracking it if the user changes the setting or
 * resizes mid-session. jsdom ships no `matchMedia`, hence the guards — the
 * component has to render in Vitest without a polyfill.
 */
function useMediaQuery(query: string): boolean {
  const [matches, setMatches] = useState(() => mediaMatches(query));

  useEffect(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return;
    const mq = window.matchMedia(query);
    const onChange = () => setMatches(mq.matches);
    onChange(); // resync in case it changed between render and effect
    mq.addEventListener?.('change', onChange);
    return () => mq.removeEventListener?.('change', onChange);
  }, [query]);

  return matches;
}

/**
 * The miniature MCP session: a prompt, an analysis, three tasks clearing, and a
 * PageSpeed ring climbing 72 → 86 → 94 → 98.
 *
 * Under `prefers-reduced-motion` it renders the settled frame and never starts
 * a timer — not merely "animates faster". Everything else on the card is
 * static; this panel is the only thing that moves.
 */
export function McpSessionDemo() {
  const reduce = useMediaQuery(REDUCED_MOTION);
  const narrow = useMediaQuery(NARROW);
  /** Reduced motion or out of sight: render the end state, schedule nothing. */
  const still = reduce || narrow;
  const startAtEnd = () => mediaMatches(REDUCED_MOTION) || mediaMatches(NARROW);

  const [step, setStep] = useState(() => (startAtEnd() ? SETTLED_STEP : 0));
  const [score, setScore] = useState(() => SESSION_SCORES[startAtEnd() ? SETTLED_STEP : 0]);
  const scoreRef = useRef(score);

  // The loop. One timer at a time, re-armed on each frame, so nothing keeps
  // firing after unmount (the card unmounts as soon as MCP connects).
  useEffect(() => {
    if (still) {
      setStep(SETTLED_STEP);
      scoreRef.current = SESSION_SCORES[SETTLED_STEP];
      setScore(SESSION_SCORES[SETTLED_STEP]);
      return;
    }

    let i = 0;
    let timer = 0;
    const run = () => {
      const prev = i === 0 ? 0 : SESSION_TIMELINE[i - 1].at;
      const wait = SESSION_TIMELINE[i].at - prev;
      timer = window.setTimeout(() => {
        setStep(SESSION_TIMELINE[i].step);
        i = (i + 1) % SESSION_TIMELINE.length;
        run();
      }, wait);
    };
    run();
    return () => window.clearTimeout(timer);
  }, [still]);

  // Count the score up. The ring is driven from this same value with no CSS
  // transition of its own, so digits and arc are always on the same frame.
  useEffect(() => {
    const target = SESSION_SCORES[step];
    const from = scoreRef.current;
    if (from === target) return;

    const snap = () => {
      scoreRef.current = target;
      setScore(target);
    };
    /*
     * Only ever ANIMATE upward. Two things move the score backwards — the loop
     * resetting 98 -> 72, and the restart after reduced motion is switched back
     * off — and tweening either one shows a performance score visibly getting
     * worse, which is the opposite of what this illustrates. Both snap instead.
     */
    if (target < from || still || typeof requestAnimationFrame !== 'function') {
      snap();
      return;
    }

    let raf = 0;
    const started = performance.now();
    const tick = (now: number) => {
      const k = Math.min(1, (now - started) / COUNT_MS);
      const eased = 1 - Math.pow(1 - k, 3);
      const value = Math.round(from + (target - from) * eased);
      scoreRef.current = value;
      setScore(value);
      if (k < 1) raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, [step, still]);

  const reveal = (shown: boolean) =>
    shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-[3px]';

  return (
    <div className="relative hidden min-w-0 sm:block" data-testid="mcp-session" data-step={step}>
      {/* Decorative lift behind the panel — the only glow on the card. */}
      <div
        className="xspeed-mcp-glow pointer-events-none absolute -inset-x-3 -inset-y-4"
        aria-hidden="true"
      />
      {/*
        aria-hidden: this is an illustration of a feature, not information. A
        screen reader announcing "Optimize my site for better PageSpeed,
        analyzing site, cache optimized, 98" would sound like a report on THIS
        site's actual state, which is exactly the misreading the visible
        "Example session" chip exists to prevent.
      */}
      <div
        className="xspeed-mcp-well relative flex h-full flex-col gap-2 rounded-control border bg-paper px-[11px] py-[9px]"
        aria-hidden="true"
      >
        {/* The prompt. Truncated rather than wrapped: a longer translation
            must not push the card past its height budget. */}
        <div className={`flex justify-end transition-all duration-[450ms] ${reveal(step >= 1)}`}>
          <span className="inline-block max-w-[92%] truncate rounded-[9px_9px_3px_9px] bg-primary-tint px-[9px] py-[5px] text-[11.5px] leading-[1.35] text-on-tint">
            {__('Optimize my site for better PageSpeed.')}
          </span>
        </div>

        {/* The status row is always laid out so its "Example session" chip is on
            screen in EVERY frame, including the opening 72. Only the row's
            contents fade in. */}
        <div className="flex min-h-[17px] items-center gap-1.5 text-[11px] text-muted">
          <span
            className={`inline-flex min-w-0 items-center gap-1.5 transition-all duration-[450ms] ${reveal(step >= 2)}`}
          >
            {step >= SETTLED_STEP ? (
              <>
                <CheckCircle2 size={11} className="shrink-0 text-success" />
                <span className="truncate">{__('Applied 3 optimizations')}</span>
              </>
            ) : (
              <>
                <span className="xspeed-anim-boltpulse h-1.5 w-1.5 shrink-0 rounded-pill bg-primary" />
                <span className="truncate">{__('Analyzing site…')}</span>
              </>
            )}
          </span>
          <span className="ml-auto shrink-0 rounded-pill border border-line-strong bg-paper px-[7px] text-[9.5px] leading-[1.5] text-muted">
            {__('Example session')}
          </span>
        </div>

        <div className="grid grid-cols-[minmax(0,1fr)_74px] items-center gap-2.5">
          <div className="flex flex-col gap-[5px]">
            {[
              __('Cache optimized'),
              __('Compression enabled'),
              __('Core Web Vitals checked'),
            ].map((label, i) => {
              const done = step >= 3 + i;
              return (
                <div
                  key={label}
                  className={`flex items-center gap-1.5 text-[11px] text-ink-soft transition-opacity duration-[450ms] ${
                    done ? 'opacity-100' : 'opacity-30'
                  }`}
                >
                  <span
                    className={`inline-flex h-[13px] w-[13px] shrink-0 items-center justify-center rounded-pill border transition-colors duration-300 ${
                      done
                        ? 'border-success-tint bg-success-tint text-success'
                        : 'border-line-strong bg-paper text-transparent'
                    }`}
                  >
                    <Check size={9} strokeWidth={3.2} />
                  </span>
                  <span className="truncate">{label}</span>
                </div>
              );
            })}
          </div>

          <div className="flex flex-col items-center gap-0.5">
            <div className="relative h-12 w-12">
              <svg width="48" height="48" viewBox="0 0 48 48" className="block -rotate-90">
                <circle
                  cx="24"
                  cy="24"
                  r={RING_R}
                  fill="none"
                  strokeWidth="4.5"
                  className="stroke-line"
                />
                <circle
                  cx="24"
                  cy="24"
                  r={RING_R}
                  fill="none"
                  strokeWidth="4.5"
                  strokeLinecap="round"
                  /* No CSS transition: `score` is already eased frame by frame,
                     and a transition on top made the arc lag the digits and
                     animate backwards on every reset. */
                  className="stroke-primary"
                  strokeDasharray={RING_C}
                  strokeDashoffset={RING_C - (RING_C * score) / 100}
                />
              </svg>
              <span className="absolute inset-0 flex items-center justify-center font-mono text-[14px] font-medium tabular-nums text-ink">
                {score}
              </span>
            </div>
            <span className="flex items-baseline gap-1 leading-none">
              <span className="text-[9.5px] text-muted">{__('PageSpeed')}</span>
              <span
                className={`font-mono text-[9.5px] text-success transition-opacity duration-[450ms] ${
                  step >= SETTLED_STEP ? 'opacity-100' : 'opacity-0'
                }`}
              >
                +{SESSION_GAIN}
              </span>
            </span>
          </div>
        </div>
      </div>
    </div>
  );
}
