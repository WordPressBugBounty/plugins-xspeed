import type { MouseEventHandler, ReactElement } from 'react';

/**
 * The quiet top-bar chip (DESIGN.md §24.48): the Hub chip's shape, reused by
 * add-on status items so every chip in the bar reads the same.
 *
 * The dot is decorative. Whatever it signals has to be in the label or the
 * title, because a screen reader never hears the colour.
 */
export type StatusChipTone = 'ok' | 'warn' | 'fail' | 'neutral';

const DOT: Record<StatusChipTone, string | null> = {
  ok: 'bg-success',
  warn: 'bg-warning',
  fail: 'bg-danger',
  neutral: null,
};

const CHIP =
  'inline-flex items-center gap-1.5 rounded-pill px-3 py-1.5 text-[12.5px] text-ink-soft';
const INTERACTIVE =
  'hover:text-ink hover:bg-panel transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary';

export interface StatusChipProps {
  label: string;
  tone: StatusChipTone;
  /** Tooltip, and the chip's accessible description. */
  title?: string;
  /** Renders an `<a>`. Takes precedence over `onClick`'s `<button>`. */
  href?: string;
  onClick?: MouseEventHandler<HTMLElement>;
  /** A lucide icon element, e.g. `<Cloud size={14} aria-hidden="true" />`. */
  icon?: ReactElement;
}

export function StatusChip({ label, tone, title, href, onClick, icon }: StatusChipProps) {
  const dot = DOT[tone];
  const body = (
    <>
      {icon}
      {label}
      {dot && <span data-tone={tone} className={`w-1.5 h-1.5 rounded-full ${dot}`} aria-hidden="true" />}
    </>
  );
  // The label is the accessible name, so the title attribute becomes the
  // description on its own. An sr-only span would be read into the name.
  if (href) {
    return (
      <a href={href} onClick={onClick} title={title} className={`${CHIP} ${INTERACTIVE}`}>
        {body}
      </a>
    );
  }
  if (onClick) {
    return (
      <button type="button" onClick={onClick} title={title} className={`${CHIP} ${INTERACTIVE}`}>
        {body}
      </button>
    );
  }
  return (
    <span title={title} className={CHIP}>
      {body}
    </span>
  );
}
