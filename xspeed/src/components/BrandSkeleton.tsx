import { BrandLogo } from './BrandLogo';
import { __ } from '../i18n';

/**
 * Boot skeleton (DESIGN.md §20).
 *
 * Shown in `<main>` while the dashboard's bootstrap JSON (settings + stats)
 * is still loading — the sidebar already renders, this fills the content
 * pane. Instead of a bare "Loading…" line it shows the brand mark gently
 * pulsing above a couple of shimmer bars that stand in for the incoming
 * title + content, so the wait reads as "xSpeed is starting" rather than
 * "nothing happened yet".
 *
 * Uses <BrandLogo>, so a white-label logo pulses here too. `prefers-
 * reduced-motion` disables the pulse (see styles.css). Pure presentation.
 */
export function BrandSkeleton() {
  return (
    <div
      className="flex-1 flex flex-col items-center justify-center gap-5 px-6"
      role="status"
      aria-label={__('Loading')}
    >
      {/* Brand mark — the focal point of the skeleton. */}
      <BrandLogo className="w-12 h-12 rounded-card shrink-0 motion-safe:animate-pulse" />

      {/* Shimmer bars standing in for the title + first rows. */}
      <div className="w-full max-w-[220px] space-y-2.5">
        <div className="h-3 rounded bg-line motion-safe:animate-pulse mx-auto w-1/2" />
        <div className="h-2.5 rounded bg-panel motion-safe:animate-pulse w-full" />
        <div className="h-2.5 rounded bg-panel motion-safe:animate-pulse w-5/6 mx-auto" />
      </div>

      <span className="sr-only">{__('Loading…')}</span>
    </div>
  );
}
