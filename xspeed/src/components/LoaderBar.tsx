/**
 * Loader bar and live dot (DESIGN.md §24.55).
 *
 * `LoaderBar` is a thin indeterminate bar for a wait that reports no
 * progress: something is being prepared and the page will move on by itself.
 * `LiveDot` is the pulsing dot beside a status line that keeps updating on
 * its own; `done` turns it solid and green. The motion is in
 * `public/theme.css` (`.xspeed-loader-bar`, `.xspeed-live-dot`), where the
 * reduced-motion rule turns both still.
 */
export function LoaderBar({ label }: { label: string }) {
  return <div className="xspeed-loader-bar" role="progressbar" aria-label={label} aria-busy="true" />;
}

export function LiveDot({ done = false }: { done?: boolean }) {
  return <span aria-hidden="true" className={`xspeed-live-dot${done ? ' is-done' : ''}`} />;
}
