import { useState, type ReactNode } from 'react';
import { AlertTriangle, Loader2 } from 'lucide-react';
import type { CalloutTone } from './Callout';

/**
 * Inline confirmation for destructive actions. Replaces `window.confirm`
 * across Pro panels because DESIGN.md §23 explicitly forbids modals /
 * dialogs — inline disclosure only. Reuses the same color tokens as the
 * `danger`-toned Callout so it visually rhymes with our other surfaces.
 *
 * Shape: a toned card with a title, optional body, and a pair of actions
 * (Cancel + Confirm). Mount it inline where the destructive button used to
 * live; flip a parent `confirming` state to render it, clear the state on
 * either action.
 *
 * `tone` defaults to `danger` — the original and still the common case. A
 * consequential-but-REVERSIBLE choice takes `warn` instead: taking a
 * wp-config.php setting over (§24.36) is worth stopping for, but painting it
 * red would rank it with deleting data and teach people to click through the
 * colour that should mean "this one you cannot undo". (#398)
 */
/**
 * Per-tone class sets. Mirrors the equivalent Callout tones (§24.x) so the
 * two surfaces rhyme rather than each inventing a palette.
 */
const TONE = {
  danger: {
    card: 'border-red-200 dark:border-red-900/60 bg-red-50 dark:bg-red-950/40',
    icon: 'text-red-600 dark:text-red-400',
    title: 'text-red-900 dark:text-red-200',
    body: 'text-red-800/90 dark:text-red-200/80',
    confirm:
      '!border-red-600 dark:!border-red-500 bg-red-600 dark:bg-red-500 text-white hover:bg-red-700 hover:!border-red-700 dark:hover:bg-red-600',
    secondary:
      '!border-red-300 dark:!border-red-800 bg-white dark:bg-neutral-900 text-red-800 dark:text-red-200 hover:bg-red-100 dark:hover:bg-red-900/40',
    alt: '!border-red-300 dark:!border-red-800 bg-white dark:bg-neutral-900 text-red-900 dark:text-red-100 hover:bg-red-100 dark:hover:bg-red-900/40',
  },
  warn: {
    card: 'border-amber-200 dark:border-amber-900/60 bg-amber-50 dark:bg-amber-950/40',
    icon: 'text-amber-600 dark:text-amber-400',
    title: 'text-amber-900 dark:text-amber-200',
    body: 'text-amber-800/90 dark:text-amber-200/80',
    confirm:
      '!border-amber-600 dark:!border-amber-500 bg-amber-600 dark:bg-amber-500 text-white hover:bg-amber-700 hover:!border-amber-700 dark:hover:bg-amber-600',
    secondary:
      '!border-amber-300 dark:!border-amber-800 bg-white dark:bg-neutral-900 text-amber-800 dark:text-amber-200 hover:bg-amber-100 dark:hover:bg-amber-900/40',
    alt: '!border-amber-300 dark:!border-amber-800 bg-white dark:bg-neutral-900 text-amber-900 dark:text-amber-100 hover:bg-amber-100 dark:hover:bg-amber-900/40',
  },
} as const;

interface InlineConfirmProps {
  title: string;
  body?: ReactNode;
  /**
   * When set, Confirm stays disabled until a checkbox carrying this sentence
   * is ticked. See DESIGN.md §24.41.
   *
   * For a consequence the person has to act on THEMSELVES afterwards, or one
   * we cannot undo for them. A body paragraph can be skimmed; this cannot.
   * Leave it undefined for ordinary destructive confirmations — a checkbox on
   * every one of them teaches people to tick without reading.
   */
  requireAck?: string;
  confirmLabel: string;
  cancelLabel?: string;
  busy?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
  /**
   * Optional SECOND way forward, rendered between confirm and cancel.
   *
   * For choices that are not "do it / don't": the migration import offers
   * "Import and switch" (deactivates the old plugin) alongside "Import
   * settings only" (keeps it running). Without this the only way to decline
   * the destructive half was Cancel, which also abandoned the import the user
   * actually wanted. Styled as a neutral secondary so the recommended path
   * stays visually primary. (#189)
   */
  altLabel?: string;
  onAlt?: () => void;
  /** Visual weight. Only `danger` and `warn` are used; defaults to danger. */
  tone?: Extract<CalloutTone, 'danger' | 'warn'>;
}

export function InlineConfirm({
  title,
  body,
  requireAck,
  confirmLabel,
  cancelLabel = 'Cancel',
  busy = false,
  onConfirm,
  onCancel,
  altLabel,
  onAlt,
  tone = 'danger',
}: InlineConfirmProps) {
  const [acked, setAcked] = useState(false);
  const locked = Boolean(requireAck) && !acked;
  const t = TONE[tone];
  return (
    <div
      role="alertdialog"
      aria-labelledby="xspeed-inline-confirm-title"
      className={`border ${t.card} rounded-lg p-4 sm:p-5`}
    >
      <div className="flex items-start gap-2.5">
        <AlertTriangle size={16} className={`${t.icon} shrink-0 mt-0.5`} />
        <div className="min-w-0 flex-1">
          <div
            id="xspeed-inline-confirm-title"
            className={`font-medium ${t.title} text-[13px]`}
          >
            {title}
          </div>
          {body && (
            <p className={`text-[12px] ${t.body} mt-1`}>{body}</p>
          )}
          {requireAck && (
            <label className="mt-2 flex items-start gap-2 text-[12px] text-red-800/90 dark:text-red-200/80">
              <input
                type="checkbox"
                checked={acked}
                onChange={(e) => setAcked(e.target.checked)}
                disabled={busy}
                className="mt-0.5 shrink-0"
              />
              <span>{requireAck}</span>
            </label>
          )}
          <div className="mt-3 flex items-center gap-2">
            <button
              type="button"
              onClick={onConfirm}
              disabled={busy || locked}
              style={{ height: 32, boxSizing: 'border-box' }}
              className={`flex items-center gap-1.5 whitespace-nowrap text-[12px] px-3 rounded-md !border !border-solid ${t.confirm} transition-colors disabled:opacity-50`}
            >
              {busy && <Loader2 size={12} className="animate-spin" />}
              {confirmLabel}
            </button>
            {altLabel && onAlt && (
              <button
                type="button"
                onClick={onAlt}
                disabled={busy}
                style={{ height: 32, boxSizing: 'border-box' }}
                className={`flex items-center gap-1.5 whitespace-nowrap text-[12px] px-3 rounded-md !border !border-solid ${t.alt} font-medium transition-colors disabled:opacity-50`}
              >
                {altLabel}
              </button>
            )}
            <button
              type="button"
              onClick={onCancel}
              disabled={busy}
              style={{ height: 32, boxSizing: 'border-box' }}
              className={`flex items-center gap-1.5 whitespace-nowrap text-[12px] px-3 rounded-md !border !border-solid ${t.secondary} transition-colors disabled:opacity-50`}
            >
              {cancelLabel}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
