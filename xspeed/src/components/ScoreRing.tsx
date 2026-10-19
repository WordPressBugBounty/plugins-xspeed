import { __ } from '../i18n';

/**
 * Score ring — a 0-100 score drawn as an arc (DESIGN.md §24.31).
 *
 * Extracted from ScorePanel so the Overview hero and the Score panel draw
 * the same ring from one implementation rather than two that drift. The
 * geometry is the original's: a 36x36 viewBox with r=15.9155, whose
 * circumference is ~100, so `strokeDasharray="<score>, 100"` maps a score
 * directly onto the arc with no arithmetic.
 *
 * A null score renders the track alone with an em dash. That is deliberate
 * and load-bearing: a zero-length arc with "0" in the middle reads as "this
 * site scored zero" when the truth is "nothing has been measured yet".
 */

/** Stroke colour for the arc — Lighthouse's own 90/50 bands. */
export function scoreStroke(score: number | null): string {
  if (score === null) return 'stroke-line';
  if (score >= 90) return 'stroke-success';
  if (score >= 50) return 'stroke-warning';
  return 'stroke-danger';
}

/** Text colour for the numeral, paired with scoreStroke(). */
export function scoreTone(score: number | null): string {
  if (score === null) return 'text-faint';
  if (score >= 90) return 'text-success';
  if (score >= 50) return 'text-warning';
  return 'text-danger';
}

export function ScoreRing({
  score,
  size = 64,
  label,
  thickness = 2.5,
}: {
  score: number | null;
  /** Rendered width/height in px. The viewBox is fixed, so this just scales. */
  size?: number;
  /** Accessible name. Falls back to a generic one when the caller has no context. */
  label?: string;
  /** Stroke width in viewBox units. Heavier reads better at display sizes. */
  thickness?: number;
}) {
  const px = { width: size, height: size };
  // Guard the arc against out-of-range values from an external provider:
  // a score above 100 would overshoot the dash array and wrap the circle.
  const clamped = score === null ? null : Math.max(0, Math.min(100, score));

  return (
    <div
      className="relative shrink-0"
      style={px}
      role="img"
      aria-label={
        label ??
        (clamped === null
          ? __('No score measured yet')
          : `${__('Performance score')}: ${clamped} ${__('out of 100')}`)
      }
    >
      <svg viewBox="0 0 36 36" className="-rotate-90" style={px} aria-hidden="true">
        <circle cx="18" cy="18" r="15.9155" fill="none" strokeWidth={thickness} className="stroke-line" />
        {clamped !== null && (
          <circle
            cx="18"
            cy="18"
            r="15.9155"
            fill="none"
            strokeWidth={thickness}
            strokeLinecap="round"
            strokeDasharray={`${clamped}, 100`}
            className={scoreStroke(clamped)}
          />
        )}
      </svg>
      <div
        className={`absolute inset-0 flex items-center justify-center font-semibold tabular-nums ${scoreTone(clamped)}`}
        style={{ fontSize: Math.round(size * 0.34) }}
        aria-hidden="true"
      >
        {clamped === null ? '—' : clamped}
      </div>
    </div>
  );
}
