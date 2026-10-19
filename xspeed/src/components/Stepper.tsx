import { __ } from '../i18n';

/**
 * Stepper (DESIGN.md §24.52): where a person is in a short, ordered flow.
 *
 * One row of named stages with a dot each. Stages before the current one are
 * done, the current one is emphasised, the rest are still to come. It reports
 * position and nothing else: there is no click, because the stage is decided
 * by what has happened, not by what the person wants to look at.
 */
export interface StepperItem {
  key: string;
  label: string;
}

export function Stepper({
  items,
  current,
  label = __('Progress'),
}: {
  items: readonly StepperItem[];
  /** The key of the stage in progress. Everything before it is done. */
  current: string;
  /** The `aria-label` on the `nav`. */
  label?: string;
}) {
  const at = Math.max(
    0,
    items.findIndex((item) => item.key === current),
  );
  return (
    <nav aria-label={label} className="text-[12px]">
      <ol className="m-0 flex list-none flex-wrap items-center gap-2 p-0">
        {items.map((item, i) => {
          const done = i < at;
          const now = i === at;
          return (
            <li key={item.key} className="flex items-center gap-2">
              <span
                aria-current={now ? 'step' : undefined}
                data-state={done ? 'done' : now ? 'current' : 'upcoming'}
                className={`inline-flex items-center gap-1.5 ${
                  now ? 'font-semibold text-ink' : done ? 'text-ink-soft' : 'text-muted'
                }`}
              >
                <span
                  aria-hidden="true"
                  className={`h-[7px] w-[7px] shrink-0 rounded-full ${
                    done ? 'bg-success' : now ? 'bg-primary ring-[3px] ring-primary-tint' : 'bg-line-strong'
                  }`}
                />
                {item.label}
                {done && <span className="sr-only"> ({__('done')})</span>}
              </span>
              {i < items.length - 1 && <span aria-hidden="true" className="h-px w-4 bg-line-strong" />}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
