/**
 * Segmented choice (DESIGN.md §24.53): pick exactly one of two or three
 * equivalent ways to do the same thing.
 *
 * A group of toggle buttons in one outlined pill. The pressed one is tinted.
 * It is a choice, not navigation, so the buttons carry `aria-pressed` and
 * not tab roles, and it never changes the URL. Left and Right arrows move
 * between the options so a keyboard user does not have to Tab through them.
 */
export interface SegmentedChoiceOption {
  key: string;
  label: string;
}

export function SegmentedChoice({
  options,
  value,
  onChange,
  label,
}: {
  options: readonly SegmentedChoiceOption[];
  value: string;
  onChange: (key: string) => void;
  /** The `aria-label` on the group. */
  label: string;
}) {
  const move = (dir: 1 | -1) => {
    const i = options.findIndex((o) => o.key === value);
    if (i === -1) return;
    const next = options[(i + dir + options.length) % options.length];
    if (next) onChange(next.key);
  };
  return (
    <div
      role="group"
      aria-label={label}
      className="inline-flex self-start overflow-hidden rounded-control border border-line-strong"
      onKeyDown={(e) => {
        if (e.key === 'ArrowRight') {
          e.preventDefault();
          move(1);
        } else if (e.key === 'ArrowLeft') {
          e.preventDefault();
          move(-1);
        }
      }}
    >
      {options.map((option) => {
        const pressed = option.key === value;
        return (
          <button
            key={option.key}
            type="button"
            aria-pressed={pressed}
            onClick={() => onChange(option.key)}
            className={`px-3.5 py-1.5 text-[13px] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary ${
              pressed ? 'bg-primary-tint font-semibold text-on-tint' : 'text-ink-soft hover:text-ink'
            }`}
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}
