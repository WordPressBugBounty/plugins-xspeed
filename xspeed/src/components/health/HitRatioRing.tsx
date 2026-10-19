import { useEffect, useState } from 'react';

const R = 52;
const C = 2 * Math.PI * R; // ~326.7

/**
 * Animated hit-ratio ring (redesign v2). Fills from empty to the target
 * percentage on mount via a stroke-dashoffset transition (reduced-motion users
 * simply see the final value — no motion class involved).
 */
export function HitRatioRing({ percent, label }: { percent: number; label: string }) {
  const pct = Math.max(0, Math.min(100, Math.round(percent)));
  const [shown, setShown] = useState(false);
  useEffect(() => {
    const t = window.requestAnimationFrame(() => setShown(true));
    return () => window.cancelAnimationFrame(t);
  }, []);
  const offset = shown ? C * (1 - pct / 100) : C;

  return (
    <div className="relative inline-flex items-center justify-center" role="img" aria-label={`${label}: ${pct}%`}>
      <svg width="128" height="128" viewBox="0 0 128 128">
        <circle cx="64" cy="64" r={R} fill="none" stroke="var(--panel)" strokeWidth="10" />
        <circle
          cx="64"
          cy="64"
          r={R}
          fill="none"
          stroke="var(--primary)"
          strokeWidth="10"
          strokeLinecap="round"
          strokeDasharray={C}
          strokeDashoffset={offset}
          transform="rotate(-90 64 64)"
          style={{ transition: 'stroke-dashoffset 900ms ease-out' }}
        />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <span className="font-display text-[26px] font-semibold text-ink tabular-nums">{pct}%</span>
        <span className="text-[11px] text-muted">{label}</span>
      </div>
    </div>
  );
}
