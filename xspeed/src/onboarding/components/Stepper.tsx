import { Check } from 'lucide-react';

export type StepId = 'review' | 'configure' | 'done' | 'connect';

// Connect used to be a pre-wizard gate rendered before the rail even appeared.
// It now closes the wizard — the user sets caching up, sees what it did, and
// only then gets the account invite:
// Environment → Configure → Summary → Connect.
//
// Labels are deliberately not the internal step ids. The first step is `review`
// in the state machine but reads "Environment", because that's what the screen
// actually shows (WordPress/PHP versions, server, cache dir, permalinks,
// conflicts) — "Review" said nothing about it. The third is "Summary", not
// "Done": it no longer ends the flow, so Done would tell the user they had
// finished while a step remained.
const steps: { id: StepId; label: string }[] = [
  { id: 'review', label: 'Environment' },
  { id: 'configure', label: 'Configure' },
  { id: 'done', label: 'Summary' },
  { id: 'connect', label: 'Connect' },
];

/**
 * Is this site already linked to the Hub? Read from the same page bootstrap
 * OnboardingApp uses to decide whether to offer the Connect step, so the rail
 * and the flow can't disagree about how many steps there are.
 */
function hubAlreadyAttached(): boolean {
  if (typeof window === 'undefined') return false;
  return !!window.XSpeedConfig?.bootstrap?.hub?.attached;
}

export function Stepper({
  current,
  showConnect = !hubAlreadyAttached(),
}: {
  current: StepId;
  /**
   * False when this site is already attached to the Hub. Such a user never
   * reaches Connect (the Summary exits straight to the dashboard), so showing
   * it in the rail would promise a fourth step that never arrives — and would
   * label their final screen "3 of 4". (FBS-84086)
   */
  showConnect?: boolean;
}) {
  // Never hide the step the user is actually looking at. Returning from the Hub
  // lands on Connect with the site now ATTACHED, so dropping it on that basis
  // alone would erase the current step from the rail and leave nothing
  // highlighted. `showConnect` is about whether Connect is still AHEAD, so it
  // only applies when we aren't standing on it.
  const includeConnect = showConnect || current === 'connect';

  // Without the Connect step, the summary genuinely IS the end of the wizard,
  // so it takes the "Done" label back — "Summary" only exists to avoid claiming
  // the flow is over while a step is still ahead.
  const visible = includeConnect
    ? steps
    : steps
        .filter((s) => s.id !== 'connect')
        .map((s) => (s.id === 'done' ? { ...s, label: 'Done' } : s));
  const currentIndex = visible.findIndex((s) => s.id === current);

  return (
    <div className="flex items-center justify-center gap-2 sm:gap-3 py-4 sm:py-5 border-b border-line">
      {visible.map((step, i) => {
        const isComplete = i < currentIndex;
        const isActive = i === currentIndex;

        return (
          <div key={step.id} className="flex items-center gap-2 sm:gap-3">
            <div className="flex items-center gap-2">
              <span
                className={`w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-medium transition-colors ${
                  isActive
                    ? 'bg-primary text-on-primary'
                    : isComplete
                    ? 'bg-success text-white'
                    : 'bg-line text-muted'
                }`}
              >
                {isComplete ? <Check size={12} /> : i + 1}
              </span>
              <span
                className={`hidden sm:inline text-[13px] font-medium ${
                  isActive
                    ? 'text-ink'
                    : isComplete
                    ? 'text-ink-soft'
                    : 'text-faint'
                }`}
              >
                {step.label}
              </span>
            </div>
            {i < visible.length - 1 && (
              <span
                className={`w-8 sm:w-12 h-px ${
                  isComplete ? 'bg-success' : 'bg-line'
                }`}
              />
            )}
          </div>
        );
      })}
    </div>
  );
}
