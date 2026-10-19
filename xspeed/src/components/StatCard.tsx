import type { LucideIcon } from 'lucide-react';
import { ChevronDown } from 'lucide-react';

/**
 * A stat card. Display-only by default; pass `onClick` to make it the
 * opener for a drill-down (DESIGN.md §24.23).
 *
 * When it is actionable it becomes a real <button> rather than a div with a
 * handler — the whole card is the hit target, and it has to be reachable by
 * keyboard and announced as expandable, not as decoration.
 */
export function StatCard({
  label,
  value,
  icon: Icon,
  onClick,
  expanded,
  hint,
}: {
  label: string;
  value: string;
  icon?: LucideIcon;
  /** Makes the card actionable. Omit for a plain readout. */
  onClick?: () => void;
  /** Drives the chevron + aria-expanded when the card opens a panel. */
  expanded?: boolean;
  /** Accessible description of what opening the card shows. */
  hint?: string;
}) {
  const body = (
    <>
      <div className="flex items-center justify-between mb-3">
        <div className="text-[11px] uppercase tracking-wider text-neutral-500 dark:text-neutral-400 font-medium">
          {label}
        </div>
        {onClick ? (
          <ChevronDown
            size={14}
            className={`text-neutral-400 dark:text-neutral-500 transition-transform ${
              expanded ? 'rotate-180' : ''
            }`}
          />
        ) : (
          Icon && <Icon size={14} className="text-neutral-400 dark:text-neutral-500" />
        )}
      </div>
      <div className="text-[22px] font-semibold text-neutral-900 dark:text-neutral-50 leading-none">
        {value}
      </div>
    </>
  );

  const shell =
    'border bg-white dark:bg-neutral-900 rounded-lg p-5 text-left w-full transition-colors';

  if (!onClick) {
    return <div className={`${shell} border-neutral-200 dark:border-neutral-800`}>{body}</div>;
  }

  return (
    <button
      type="button"
      onClick={onClick}
      aria-expanded={expanded ?? false}
      aria-label={hint ? `${label} — ${hint}` : label}
      className={`${shell} cursor-pointer hover:border-neutral-300 dark:hover:border-neutral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent ${
        expanded
          ? 'border-accent dark:border-accent'
          : 'border-neutral-200 dark:border-neutral-800'
      }`}
    >
      {body}
    </button>
  );
}
