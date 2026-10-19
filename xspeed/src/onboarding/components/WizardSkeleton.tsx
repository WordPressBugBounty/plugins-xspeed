import type { StepId } from './Stepper';
import { Stepper } from './Stepper';

/**
 * Loading skeleton for a wizard step whose data is still in flight (redesign
 * v2). Mirrors WizardShell's frame — the real stepper stays live on top (it's
 * cheap and orients the user), with pulsing bones standing in for the hero and
 * body until the content arrives. Reduced-motion users get static bones (the
 * pulse is `motion-safe`).
 *
 * Used by the Connect step when the Hub status loads without a bootstrap
 * snapshot; reusable for any step that gains an async dependency later.
 */
export function WizardSkeleton({ step, rows = 3 }: { step: StepId; rows?: number }) {
  return (
    <div className="max-w-3xl mx-auto my-6 sm:my-10 bg-paper border border-line rounded-card overflow-hidden flex flex-col">
      <Stepper current={step} />

      {/* Hero bones */}
      <div className="text-center max-w-2xl mx-auto px-4 sm:px-6 py-6 sm:py-8 w-full">
        <div className="mx-auto mb-4 h-11 w-11 rounded-card bg-line motion-safe:animate-pulse" />
        <div className="mx-auto h-5 w-1/2 rounded bg-line motion-safe:animate-pulse" />
        <div className="mx-auto mt-3 h-3 w-3/4 rounded bg-panel motion-safe:animate-pulse" />
      </div>

      {/* Body bones */}
      <div className="px-4 sm:px-6 py-2 space-y-3 pb-8">
        <div className="rounded-card border border-line bg-panel px-5 py-5 space-y-3">
          {Array.from({ length: rows }).map((_, i) => (
            <div key={i} className="flex items-start gap-3">
              <div className="h-4 w-4 rounded-full bg-line motion-safe:animate-pulse shrink-0" />
              <div className="h-3 rounded bg-line motion-safe:animate-pulse" style={{ width: `${80 - i * 12}%` }} />
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
