import { useEffect, useRef, useState, type ReactNode } from 'react';
import { AlertTriangle, Check, Copy } from 'lucide-react';
import { __ } from '../i18n';

/**
 * Record card (DESIGN.md §24.54): one DNS record a person has to type in
 * somewhere else, as the rows of that form.
 *
 * A type badge, then one row per field (Host, Target or Value, and any other
 * fact about the record), each with a Copy button. Several cards stack in a
 * `RecordCards` surface with hairlines between them. A card can carry one
 * short flag on the right: a warning (something to fix) or a confirmation.
 *
 * Copying is the whole point of the row, so the button is the loud element:
 * tinted at rest, filled on hover, and green with a tick for 1.6 s after it
 * worked. Where the clipboard is refused, a line under the row says to
 * select and copy the value by hand, so the row never looks like it worked
 * when it did not.
 */
export interface RecordCardRow {
  /** "Host", "Target", "Value", or empty for a second value under the first. */
  label: string;
  value: string;
  /** What Copy puts on the clipboard, when it is not the value shown. */
  copyValue?: string;
  /** False for a row that is a fact, not something to paste (a proxy status). */
  copy?: boolean;
}

export function RecordCards({ children }: { children: ReactNode }) {
  return (
    <div className="divide-y divide-line overflow-hidden rounded-card border border-line bg-paper">
      {children}
    </div>
  );
}

export function RecordCard({
  type,
  rows,
  flag,
  ok,
  footer,
}: {
  /** The record type, as the provider's form names it: TXT, CNAME, A. */
  type: string;
  rows: readonly RecordCardRow[];
  /** A short warning on the right of the type badge, e.g. "Value doesn't match". */
  flag?: string | null;
  /** A short confirmation on the right of the type badge, e.g. "Updated". */
  ok?: string | null;
  /** A line under the rows, e.g. "Keep the final dot." */
  footer?: ReactNode;
}) {
  return (
    <div className="px-4 pb-1.5 pt-3.5">
      <div className="mb-1 flex items-center gap-2 text-[12px] text-muted">
        <span className="rounded bg-panel px-1.5 py-px font-mono text-[11.5px] font-medium text-ink">{type}</span>
        {flag ? (
          <span className="ml-auto inline-flex items-center gap-1.5 text-[12px] font-medium text-warning">
            <AlertTriangle size={13} aria-hidden="true" />
            {flag}
          </span>
        ) : ok ? (
          <span className="ml-auto inline-flex items-center gap-1.5 text-[12px] font-medium text-success">
            <Check size={13} aria-hidden="true" />
            {ok}
          </span>
        ) : null}
      </div>
      {rows.map((row, i) => (
        <RecordRow key={`${row.label}-${i}`} row={row} first={i === 0} />
      ))}
      {footer && <div className="pb-2 pt-1 text-[11px] text-muted">{footer}</div>}
    </div>
  );
}

function RecordRow({ row, first }: { row: RecordCardRow; first: boolean }) {
  const copyable = row.copy !== false;
  return (
    <div
      className={`grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-2.5 gap-y-0.5 py-2 sm:grid-cols-[4rem_minmax(0,1fr)_auto] ${
        first ? '' : 'border-t border-dashed border-line'
      }`}
    >
      <span className="col-span-full text-[12px] text-muted sm:col-span-1">{row.label}</span>
      <span className="min-w-0 font-mono text-[13px] text-ink [overflow-wrap:anywhere]">{row.value}</span>
      {copyable ? <CopyButton value={row.copyValue ?? row.value} /> : <span />}
    </div>
  );
}

/** The copy button: tinted at rest, green with a tick once it worked. */
export function CopyButton({ value, label = __('Copy') }: { value: string; label?: string }) {
  const [state, setState] = useState<'idle' | 'copied' | 'manual'>('idle');
  const timer = useRef<number | null>(null);
  useEffect(
    () => () => {
      if (timer.current !== null) window.clearTimeout(timer.current);
    },
    [],
  );
  const settle = (next: 'copied' | 'manual') => {
    setState(next);
    if (timer.current !== null) window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => setState('idle'), next === 'copied' ? 1600 : 4000);
  };
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(value);
      settle('copied');
    } catch {
      settle('manual');
    }
  };
  return (
    <span className="relative inline-flex">
      <button
        type="button"
        onClick={() => void copy()}
        aria-label={`${label} ${value}`}
        className={`inline-flex h-[30px] items-center gap-1.5 whitespace-nowrap rounded-control px-3 text-[12.5px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary ${
          state === 'copied'
            ? 'bg-success text-on-primary'
            : 'bg-primary-tint text-on-tint hover:bg-primary hover:text-on-primary'
        }`}
      >
        {state === 'copied' ? <Check size={13} aria-hidden="true" /> : <Copy size={13} aria-hidden="true" />}
        {state === 'copied' ? __('Copied') : label}
      </button>
      {state === 'manual' && (
        <span role="status" className="absolute right-0 top-full z-10 mt-1 whitespace-nowrap rounded bg-ink px-2 py-1 text-[11px] text-paper">
          {__('Select and copy:')} {value}
        </span>
      )}
    </span>
  );
}

/**
 * The records folded away once they are in: one line with a tick and the
 * count, and a link that opens the full cards.
 */
export function CollapsedRecords({ label, onExpand }: { label: string; onExpand: () => void }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-2.5 rounded-card border border-line bg-paper px-4 py-3 text-[13.5px] text-ink">
      <span className="inline-flex items-center gap-2">
        <Check size={15} aria-hidden="true" className="text-success" />
        {label}
      </span>
      <button
        type="button"
        onClick={onExpand}
        className="text-[13px] text-ink-soft underline underline-offset-[3px] hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      >
        {__('Show records')}
      </button>
    </div>
  );
}
