import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlertTriangle, Check, Loader2 } from 'lucide-react';
import { __ } from '../i18n';

/**
 * Save state shared across every settings panel. 'saving' shows the
 * spinner toast; 'saved' shows the green check toast (auto-clears
 * after 1.5s in the panel's debounce loop); 'failed' shows a red toast;
 * 'idle' shows nothing.
 */
export type SaveState = 'idle' | 'saving' | 'saved' | 'failed';

/**
 * Bottom-center save toast. Always rendered via React Portal into
 * `#xspeed-toast-host` (placed in App.tsx outside every space-y-*
 * container) so the indicator NEVER lives as a sibling of panel
 * content — Tailwind's `space-y-*` and `gap-*` selectors only see
 * the host div, never this toast, so panels can't shift when state
 * changes.
 *
 * The host element sits inside #xspeed-app so Tailwind's
 * important: '#xspeed-app' selector scoping still applies.
 *
 * Why this is the right abstraction:
 *  - Every panel had its own inline `<div>Saving…</div>` indicator
 *    with slightly different styling. Sharing the toast gives the
 *    dashboard one consistent save UX.
 *  - The portal target is created lazily on first use, so panels
 *    that mount before App.tsx has rendered the host (unlikely but
 *    possible) still work.
 */
export function SaveIndicator({ state }: { state: SaveState }) {
  const [host, setHost] = useState<HTMLElement | null>(null);
  // Remembers the last non-idle render so the fade-out keeps its
  // previous content instead of flipping to the default spinner
  // branch. See the inline comment in the JSX below.
  const lastShown = useRef<SaveState>('saving');
  if (state === 'saving' || state === 'saved' || state === 'failed') {
    lastShown.current = state;
  }

  useEffect(() => {
    if (typeof document === 'undefined') return;
    let el = document.getElementById('xspeed-toast-host');
    if (!el) {
      // Fallback: create one and append inside #xspeed-app so
      // Tailwind scoping still applies. App.tsx normally provides
      // this; this branch only fires before App mounts.
      const app = document.getElementById('xspeed-app');
      if (!app) return;
      el = document.createElement('div');
      el.id = 'xspeed-toast-host';
      app.appendChild(el);
    }
    setHost(el);
  }, []);

  if (!host) return null;
  const visible = state !== 'idle';
  // During the idle fade-out, KEEP showing the last meaningful state
  // (the green check that just appeared). Without this guard,
  // state==='idle' makes `isSaved` false, so the JSX falls into the
  // spinner branch and renders "Saving changes…" for the duration of
  // the 150ms opacity fade — which the user sees as a flicker AFTER
  // the green toast already showed "Settings saved." Caught by
  // tests-js/components/SaveSpinner.test.tsx.
  const isSaved =
    state === 'saved' ||
    (state === 'idle' && lastShown.current === 'saved');
  // Same fade-out rule as isSaved: keep the red toast on screen while it
  // fades rather than reverting to the spinner branch for 150ms.
  const isFailed =
    state === 'failed' ||
    (state === 'idle' && lastShown.current === 'failed');

  return createPortal(
    <div
      aria-hidden={!visible}
      className={`fixed bottom-6 left-1/2 -translate-x-1/2 z-50 pointer-events-none transition-opacity duration-150 ${
        visible ? 'opacity-100' : 'opacity-0'
      }`}
    >
      {/*
        A failure interrupts; a success waits its turn.

        `role="alert"` implies assertive in most screen readers, but not all —
        and the region was carrying no `aria-live` at all, so a successful save
        announced nothing either. Both are stated explicitly: issue #16 asks for
        the failure specifically to be assertive, and being explicit costs
        nothing while removing the guesswork. (#16, QA on #178)
      */}
      <div
        role={isFailed ? 'alert' : 'status'}
        aria-live={isFailed ? 'assertive' : 'polite'}
        aria-atomic="true"
        className={`flex items-center gap-2 px-4 py-2 rounded-full border shadow-md text-[12px] font-medium ${
          isFailed
            ? 'bg-rose-50 dark:bg-rose-950/60 border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-300'
            : isSaved
              ? 'bg-emerald-50 dark:bg-emerald-950/60 border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300'
              : 'bg-white dark:bg-neutral-900 border-neutral-200 dark:border-neutral-700 text-neutral-600 dark:text-neutral-300'
        }`}
      >
        {isFailed ? (
          <>
            <AlertTriangle size={14} className="text-rose-600 dark:text-rose-400" />
            <span>{__('Save failed — your change was not saved')}</span>
          </>
        ) : isSaved ? (
          <>
            <Check size={14} className="text-emerald-600 dark:text-emerald-400" />
            <span>{__('Settings saved')}</span>
          </>
        ) : (
          <>
            <Loader2 size={13} className="animate-spin text-neutral-400 dark:text-neutral-500" />
            <span>{__('Saving changes…')}</span>
          </>
        )}
      </div>
    </div>,
    host
  );
}

/**
 * Helper for panels that already track their own `saving` boolean.
 * Drives the full saving → saved → idle cycle internally so custom
 * panels get the green confirmation toast too (matching the
 * schema-driven panel UX).
 *
 * Transitions:
 *  - Idle, saving flips true → state = 'saving'.
 *  - saving flips false after having been true → state = 'saved'
 *    for SAVED_HOLD_MS, then 'idle'.
 *  - **Important**: when a NEW save fires while we're still in the
 *    'saved' grace window (e.g., a quick second toggle, or the
 *    server echo causing a follow-up debounce), we KEEP showing
 *    'saved' instead of flicking back to 'saving'. The grace
 *    window simply refreshes on completion. Without this guard,
 *    rapid saves caused the toast to flicker
 *    saving → saved → saving → saved which read as broken.
 *  - First render with saving=false → state stays 'idle' (no green
 *    flash on every panel mount).
 *  - saving flips false while `error` is set → state = 'failed'.
 *
 * The `error` parameter is what makes a failed save distinguishable.
 * Without it this helper only saw the `saving` boolean, and every
 * consumer clears that in a `finally` — so a rejected save looked
 * exactly like a successful one and fired the green "Settings saved"
 * toast on top of the panel's own red error line. The toast is larger,
 * centred and animated; most users read it, believed the change stuck,
 * and navigated away having lost it. (#5)
 */
export const SAVED_HOLD_MS = 1500;
/**
 * A failure is held longer than a success — it asks the user to act.
 *
 * Exported because ModulePanel drives its own timer rather than going through
 * SaveSpinner, and hard-coded 1500 for both outcomes. Measured on the live
 * boxes: a failed save held 5001 ms on a custom panel but 1515 ms on every
 * schema-driven one — i.e. most of the dashboard got the short hold on the
 * message that actually needs reading. One constant, one rationale. (QA F4)
 */
export const FAILED_HOLD_MS = 5000;

export function SaveSpinner({ saving, error }: { saving: boolean; error?: unknown }) {
  const [state, setState] = useState<SaveState>('idle');
  // Mirror of `state` for read inside the saving-watcher effect. We
  // can't use state directly there because the effect closes over the
  // value at the moment its deps last changed, not the current value.
  const stateRef = useRef<SaveState>('idle');
  const wasSaving = useRef(false);
  const timer = useRef<number | null>(null);

  // Keep the mirror in sync with the real state, post-render.
  useEffect(() => {
    stateRef.current = state;
  }, [state]);

  // Read inside the saving-watcher effect for the same reason as
  // stateRef: the effect must see the CURRENT error, not the value it
  // closed over when `saving` last changed. A panel typically sets its
  // error and clears `saving` in the same tick, and effect ordering
  // between the two is not guaranteed.
  const errorRef = useRef<unknown>(error);
  useEffect(() => {
    errorRef.current = error;
  }, [error]);

  useEffect(() => {
    if (saving) {
      // Only flick to 'saving' when we're not already in the green
      // 'saved' window — see component docblock.
      if (stateRef.current !== 'saved') {
        if (timer.current) {
          window.clearTimeout(timer.current);
          timer.current = null;
        }
        setState('saving');
      }
      wasSaving.current = true;
      return;
    }
    if (wasSaving.current) {
      wasSaving.current = false;
      const failed = Boolean(errorRef.current);
      setState(failed ? 'failed' : 'saved');
      if (timer.current) window.clearTimeout(timer.current);
      timer.current = window.setTimeout(
        () => {
          setState('idle');
          timer.current = null;
        },
        failed ? FAILED_HOLD_MS : SAVED_HOLD_MS
      );
    }
  }, [saving, error]);

  useEffect(() => () => {
    if (timer.current) window.clearTimeout(timer.current);
  }, []);

  return <SaveIndicator state={state} />;
}
