import { useCallback, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { __ } from '../i18n';
import { useEscapeWpStackingContext } from '../hooks/useEscapeWpStackingContext';

/**
 * Modal — a focused interruption the user has to answer or dismiss.
 *
 * DESIGN.md §24.26. Reach for it only when the task genuinely blocks what the
 * user was doing (connect before we can run your test); anything that can live
 * inline should live inline.
 *
 * Accessibility, all of which a hand-rolled div gets wrong:
 *
 *  - `role="dialog"` + `aria-modal` + a label, so a screen reader announces it
 *    as a dialog rather than reading it as part of the page behind.
 *  - Focus moves INTO the dialog on open and RETURNS to the trigger on close,
 *    so a keyboard user isn't dumped at the top of the document.
 *  - Tab is trapped inside. Without this, tabbing walks off into the page
 *    behind the scrim, which is invisible but still focusable.
 *  - Escape closes, bound at the document level so it works wherever focus is.
 *  - Background scroll is locked, or the page creeps under the dialog on
 *    trackpads.
 *
 * Portalled into `#xspeed-app`, NOT `document.body`. Tailwind here is built
 * with `important: '#xspeed-app'`, which prefixes every utility with that
 * ancestor selector — so a node outside it gets NO styling at all. Portalling
 * to the body rendered this unstyled and inline, below the page footer, which
 * is exactly how it shipped broken once. `InfoPopover` portals the same way
 * for the same reason.
 *
 * The portal still matters: it escapes any ancestor with `overflow: hidden`
 * or a `transform`, either of which would clip a fixed child or break its
 * positioning outright.
 *
 * Two presentations, ONE implementation. `variant="drawer"` slides a tall panel
 * in from the right instead of centring a card; everything above — portal,
 * focus trap, focus return, Escape, scroll lock, press-started-outside — is
 * identical and is exactly why this is a variant rather than a second overlay
 * component. A second implementation would mean a second focus trap to keep
 * correct forever. Defaults are unchanged, so existing callers are untouched.
 */
export function Modal({
  open,
  onClose,
  title,
  children,
  /** Hide the ✕ and ignore backdrop clicks — for a step that must resolve. */
  dismissible = true,
  labelledBy,
  variant = 'center',
  size = 'md',
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  children: React.ReactNode;
  dismissible?: boolean;
  labelledBy?: string;
  /** `center` = the original card. `drawer` = right-hand slide-over. */
  variant?: 'center' | 'drawer';
  /** Panel width. `md` is the historical `max-w-md` — do not change it. */
  size?: 'md' | 'lg' | 'xl' | 'workspace';
}) {
  const dialogRef = useRef<HTMLDivElement>(null);
  /** The element focused before we opened, so we can hand focus back. */
  const restoreRef = useRef<HTMLElement | null>(null);

  const close = useCallback(() => {
    if (dismissible) onClose();
  }, [dismissible, onClose]);

  // Remember the trigger, then move focus into the dialog.
  useEffect(() => {
    if (!open) return;
    restoreRef.current = document.activeElement as HTMLElement | null;

    // rAF so the node exists and the browser has painted before we focus it —
    // focusing during the same frame is unreliable in Safari.
    const raf = window.requestAnimationFrame(() => {
      const first = dialogRef.current?.querySelector<HTMLElement>(FOCUSABLE);
      (first ?? dialogRef.current)?.focus();
    });

    return () => {
      window.cancelAnimationFrame(raf);
      // Returning focus is what makes Escape feel like "back" rather than
      // "lost". Guard isConnected: the trigger may have unmounted.
      const el = restoreRef.current;
      if (el && el.isConnected) el.focus();
    };
  }, [open]);

  // Escape at the document level — works even when focus is on the scrim.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        close();
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, close]);

  // Lock background scroll. Restores the previous value rather than clearing,
  // so we don't stomp on a lock something else set.
  useEffect(() => {
    if (!open) return;
    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = previous;
    };
  }, [open]);

  // Escape WP's stacking context — see the hook for why this is needed.
  useEscapeWpStackingContext(open);

  if (!open) return null;

  /** Keep Tab inside the dialog. */
  const onKeyDown = (e: React.KeyboardEvent) => {
    if (e.key !== 'Tab') return;
    const nodes = dialogRef.current?.querySelectorAll<HTMLElement>(FOCUSABLE);
    if (!nodes || nodes.length === 0) return;
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    if (!first || !last) return;

    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  };

  const drawer = variant === 'drawer';
  // `md` reproduces the original class exactly, so a caller that passes neither
  // prop renders byte-identically to before this variant existed.
  const width = { md: 'max-w-md', lg: 'max-w-2xl', xl: 'max-w-4xl', workspace: 'max-w-5xl' }[size];

  return createPortal(
    <div
      className={
        drawer
          // items-stretch + justify-end pins the panel to the right edge, full
          // height. No padding: the drawer meets the viewport edges, where the
          // centred card deliberately keeps a gutter.
          ? 'fixed inset-0 z-[100000] flex items-stretch justify-end pt-[var(--wp-admin--admin-bar--height,32px)]'
          : 'fixed inset-0 z-[100000] flex items-center justify-center p-4'
      }
      onMouseDown={(e) => {
        // Close only when the press STARTS outside the panel, so a drag that
        // began on text inside doesn't dismiss when released on the scrim.
        if (dialogRef.current && !dialogRef.current.contains(e.target as Node)) {
          close();
        }
      }}
    >
      <div className="fixed inset-0 bg-black/40" aria-hidden="true" />

      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-label={labelledBy ? undefined : title}
        aria-labelledby={labelledBy}
        tabIndex={-1}
        onKeyDown={onKeyDown}
        className={
          drawer
            // Rounded on the leading edge only — the trailing edge is the
            // viewport. min-h-0 + flex-col is what lets the BODY scroll while
            // the header stays put; without it the whole panel scrolls and the
            // close button leaves the screen on tall content.
            ? `relative flex h-full w-full ${width} flex-col overflow-hidden rounded-l-card border-l border-y border-line bg-paper shadow-xl focus:outline-none`
            : `relative w-full ${width} rounded-card border border-line bg-paper shadow-xl focus:outline-none`
        }
      >
        <div
          className={
            drawer
              ? 'flex items-start gap-3 border-b border-line px-5 py-4 shrink-0'
              : 'flex items-start gap-3 px-5 pt-5'
          }
        >
          <h2 className="flex-1 text-[15px] font-medium text-ink">{title}</h2>
          {dismissible && (
            <button
              type="button"
              onClick={onClose}
              aria-label={__('Close')}
              className="-mr-1 -mt-1 shrink-0 rounded p-1 text-muted hover:text-ink hover:bg-panel transition-colors"
            >
              <X size={16} />
            </button>
          )}
        </div>

        <div className={drawer ? 'min-h-0 flex-1 overflow-y-auto overscroll-contain p-5' : 'px-5 pb-5 pt-3'}>
          {children}
        </div>
      </div>
    </div>,
    // Fall back to body only if the app root is somehow absent — an unstyled
    // dialog is bad, but no dialog at all is worse.
    document.getElementById('xspeed-app') ?? document.body,
  );
}

/**
 * What counts as focusable for the trap.
 *
 * `:not([disabled])` matters: a disabled button is skipped by the browser's
 * own Tab order, so including it here would trap focus on an element the user
 * can never reach.
 */
const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
