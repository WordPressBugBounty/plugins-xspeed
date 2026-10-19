import { useEffect, useRef, useState } from 'react';
import { ExternalLink, Gauge, Loader2, Check, Info } from 'lucide-react';
import { Modal } from '../Modal';
import { useHubConnect } from '../../hooks/useHubConnect';
import { __ } from '../../i18n';
import { hubLocalMessage } from '../hubLocal';

/**
 * "Connect to run a free GTmetrix test" — shown when someone presses Test on a
 * site that isn't attached to the Hub yet.
 *
 * The point of the modal is that it appears AT THE MOMENT OF INTENT. A passive
 * banner explaining that connecting would unlock free tests is easy to ignore;
 * a prompt raised by the user's own click is not.
 *
 * The flow finishes the job it interrupted: once the Hub reports the site
 * attached, this calls `onConnected()` and the test the user originally asked
 * for starts on its own. Making them press Test a second time would turn the
 * modal into a toll gate.
 *
 * No polling here. `useHubConnect` is backed by a shared store and already
 * re-reconciles on tab return (`visibilitychange` + `focus`), so when the user
 * finishes connecting in the other tab and comes back, `attached` flips and
 * the effect below fires.
 */
/**
 * Reasons the Hub can't serve a test that CONNECTING would fix.
 *
 * `not_connected` is the plugin's own answer when it holds no token at all.
 * `unauthorized` is the HUB's answer when a token exists but doesn't match a
 * site it knows — a revoked connection, a site re-pointed at a different Hub,
 * or a token minted and never used. That second case is common and looks
 * identical to the user: they are not connected, and pressing Connect fixes
 * it. Treating only `not_connected` as invitable hid the feature entirely on
 * any site with a stale token.
 *
 * `site_not_verified` is deliberately NOT here — connecting again won't help;
 * the Hub has to be able to reach the site.
 */
export const CONNECTABLE_REASONS = ['not_connected', 'unauthorized'];

/** Would pressing Connect plausibly fix this? */
export function canConnect(reason: string | undefined): boolean {
  return reason !== undefined && CONNECTABLE_REASONS.includes(reason);
}

export function ConnectHubModal({
  open,
  onClose,
  onConnected,
  /** True when the site's own URL isn't publicly reachable. */
  local = false,
}: {
  open: boolean;
  onClose: () => void;
  onConnected: () => void;
  local?: boolean;
}) {
  const { connectUrl, attached, busy, error, generateToken } = useHubConnect({ active: open });
  // `busy` is a phase ('load' | 'token'), not a flag — only the token mint
  // should disable this button. Disabling on 'load' would grey it out during
  // the routine status refresh.
  const minting = busy === 'token';
  const [opened, setOpened] = useState(false);
  /** Latch, so a re-render can't fire the auto-run twice. */
  const firedRef = useRef(false);

  // Reset when the modal is dismissed, so a later press starts clean.
  useEffect(() => {
    if (!open) {
      setOpened(false);
      firedRef.current = false;
    }
  }, [open]);

  /*
   * Attached → finish what the user asked for.
   *
   * Gated on `opened` so this only fires for someone who connected FROM here.
   * Without it, opening the modal on an already-attached site (a stale render,
   * a race with the reconcile) would silently start a test nobody pressed —
   * and that spends a credit.
   */
  useEffect(() => {
    if (!open || !attached || !opened || firedRef.current) return;
    firedRef.current = true;
    // A beat on "Connected" so the state change is legible rather than a flash.
    const t = window.setTimeout(() => {
      onConnected();
      onClose();
    }, 900);
    return () => window.clearTimeout(t);
  }, [open, attached, opened, onConnected, onClose]);

  const connect = async () => {
    setOpened(true);
    const url = connectUrl || (await generateToken());
    if (url) window.open(url, '_blank', 'noopener');
  };

  return (
    <Modal open={open} onClose={onClose} title={__('Run a GTmetrix test')}>
      {attached && opened ? (
        <div className="flex items-center gap-2 py-2 text-[13px] text-ink">
          <Check size={16} className="text-success" />
          {__('Connected — starting your test…')}
        </div>
      ) : (
        <>
          {!local && (
            <div className="flex gap-3">
              <Gauge size={18} className="mt-0.5 shrink-0 text-accent" />
              <div className="space-y-2 text-[13px] leading-relaxed text-ink-soft">
                <p>
                  {__('Connect this site to xSpeed Hub to run GTmetrix tests from here.')}
                </p>
                <p className="text-muted">
                  {__('It takes a few seconds.')}
                </p>
              </div>
            </div>
          )}

          {/* The Hub refuses a local site (xspeed-hub#370), so say so here
              rather than offer a Connect that cannot succeed. */}
          {local && (
            <div className="flex gap-2 rounded border border-line bg-panel px-3 py-2 text-[12px] text-muted">
              <Info size={14} className="mt-0.5 shrink-0" />
              <span>
                {hubLocalMessage()} {__('Your own GTmetrix API key works here; see the settings below.')}
              </span>
            </div>
          )}

          {error && <p className="mt-3 text-[12px] text-danger">{error}</p>}

          <div className="mt-4 flex items-center justify-end gap-2">
            <button
              type="button"
              onClick={onClose}
              className="rounded px-3 py-1.5 text-[13px] text-muted hover:text-ink hover:bg-panel transition-colors"
            >
              {local ? __('Close') : __('Not now')}
            </button>
            {!local && (
              <button
                type="button"
                onClick={() => void connect()}
                disabled={minting}
                className="inline-flex items-center gap-1.5 rounded border border-accent bg-accent px-3 py-1.5 text-[13px] text-white hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-50"
              >
                {minting ? <Loader2 size={13} className="animate-spin" /> : <ExternalLink size={13} />}
                {opened ? __('Waiting for connection…') : __('Connect')}
              </button>
            )}
          </div>

          {opened && (
            <p className="mt-2 text-right text-[11px] text-muted">
              {__('Finish in the tab that opened, then come back here.')}
            </p>
          )}
        </>
      )}
    </Modal>
  );
}
