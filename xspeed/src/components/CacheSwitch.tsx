import { useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlertTriangle, FileStack } from 'lucide-react';
import { Toggle } from './Toggle';
import { dropinNotice, type DropinState } from './dropinNotice';
import { __ } from '../i18n';

/**
 * Master cache state pill for the top bar (DESIGN.md §24.33).
 *
 * Reads as *state first, control second*: the label plus the switch answer
 * "is caching on?" from any route, and the switch is how you change it. That's
 * the honest affordance for a global on/off — a plain button would claim to
 * *do* something without ever showing what's true now, which is exactly what
 * the old bar got wrong (the master switch was buried in CacheHero on Overview
 * and invisible everywhere else).
 *
 * Borderless and quiet — `text-[12.5px] text-ink-soft`, the bar's shared
 * padding, no border — matching the Connect Hub chip. It reads as ambient
 * state rather than competing with the one loud action beside it.
 *
 * The container is deliberately state-INVARIANT: only the switch changes
 * between on and off. Earlier passes tinted the shell too (a filled pill, a
 * status dot, a bolder label), which made the control restyle itself on every
 * toggle while its neighbours never move — that read as a glitch, not as
 * state. The switch is already an unambiguous indicator; let it do that alone.
 *
 * Enabling applies immediately. Disabling opens an anchored confirm first:
 * it is a site-wide performance change, and a stray click on a control that
 * rides every screen should not silently deoptimize the whole site. The
 * confirm is a popover, not a `Modal` — per §23 inline disclosure is the
 * default and this is none of the three cases that earn an overlay.
 *
 * Portals into #xspeed-app (so the `important: '#xspeed-app'` Tailwind scope
 * applies) and uses position:fixed to escape the sticky header, matching
 * InfoPopover.
 */
export function CacheSwitch({
  enabled,
  onChange,
  busy = false,
  ready = true,
  dropin = null,
}: {
  enabled: boolean;
  onChange: (v: boolean) => void;
  /** Toggle in flight — the switch is inert until the server answers. */
  busy?: boolean;
  /**
   * Settings have loaded. Until they have, `enabled` is a placeholder rather
   * than the site's real state, so the switch must not assert it: it renders
   * inert and unset instead of claiming "caching is off" on a site where it
   * is on, and swallowing the click that says otherwise.
   */
  ready?: boolean;
  /**
   * Drop-in state, when a leftover advanced-cache.php means enabling would
   * replace a file rather than write a fresh one. Null (the common case)
   * enables on one click with no prompt.
   */
  dropin?: DropinState | null;
}) {
  // Which question the popover is asking. `disable` is the long-standing
  // "you are about to deoptimize the site" confirm; `install` is the
  // enable-time disclosure that we are about to overwrite someone's file.
  // One popover, two tones — they share all the focus and dismiss handling
  // below, which is the entire reason this is a mode rather than a second
  // component.
  const [intent, setIntent] = useState<'disable' | 'install'>('disable');
  const [coords, setCoords] = useState<{ top: number; right: number } | null>(null);
  const pillRef = useRef<HTMLDivElement | null>(null);
  const panelRef = useRef<HTMLDivElement | null>(null);
  const confirmRef = useRef<HTMLButtonElement | null>(null);
  // What had focus before the confirm stole it, so dismissing can give it
  // back rather than dropping the keyboard user at the top of the document.
  const returnFocusRef = useRef<HTMLElement | null>(null);
  const titleId = useId();
  const confirming = coords !== null;
  // Null whenever there is nothing to say before enabling, which is also
  // what makes the toggle immediate.
  const notice = dropinNotice(dropin);
  // An install prompt needs its copy to exist. A status refetch can land
  // while the popover is open and clear the drop-in (another tab enabled
  // caching, say) — then there is nothing left to ask about, and rendering
  // half a prompt is worse than closing it.
  const installing = confirming && 'install' === intent && null !== notice;

  const ask = (mode: 'disable' | 'install') => {
    const el = pillRef.current;
    if (!el) return;
    returnFocusRef.current =
      document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const r = el.getBoundingClientRect();
    setIntent(mode);
    // Right-aligned: the pill sits in a right-anchored cluster, so growing
    // leftward keeps the panel on screen at narrow widths.
    setCoords({ top: r.bottom + 6, right: Math.max(8, window.innerWidth - r.right) });
  };

  const handle = (next: boolean) => {
    if (busy || !ready) return;
    if (!next) {
      ask('disable');
      return;
    }
    // Enabling is normally immediate — there is nothing to disclose about
    // writing a file nobody else wants. It only pauses when a leftover
    // drop-in is about to be REPLACED, which is a change to something the
    // user already has rather than an addition.
    if (notice) ask('install');
    else onChange(true);
  };

  // Move focus to the destructive action so the confirm is operable from the
  // keyboard the moment it opens, and Escape/outside-click dismiss it.
  useEffect(() => {
    if (!confirming) return;
    confirmRef.current?.focus();
    const onDown = (e: MouseEvent) => {
      if (
        !panelRef.current?.contains(e.target as Node) &&
        !pillRef.current?.contains(e.target as Node)
      ) {
        setCoords(null);
      }
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setCoords(null);
    };
    const onScroll = () => setCoords(null);
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', onScroll);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
      window.removeEventListener('scroll', onScroll, true);
      window.removeEventListener('resize', onScroll);
      // Hand focus back to whatever opened this. Every dismissal path
      // unmounts the button that currently holds focus, so without this the
      // next Tab resumes from the top of the document instead of the switch.
      returnFocusRef.current?.focus?.();
      returnFocusRef.current = null;
    };
  }, [confirming]);

  // A toggle that lands from elsewhere (Overview's CacheHero, a palette
  // action) must not leave a stale confirm hanging over the new state.
  //
  // Which state is stale depends on what the popover is asking: a disable
  // confirm is moot once caching is already off, and an install prompt is
  // moot once it is already on. The old unconditional `!enabled` test closed
  // the wrong one — enabling from CacheHero left the top bar still asking
  // permission to install a drop-in that was by then installed.
  useEffect(() => {
    setCoords((c) => (c === null ? c : (installing ? enabled : !enabled) ? null : c));
  }, [enabled, installing]);

  return (
    <>
      <div
        ref={pillRef}
        className={`hidden sm:inline-flex items-center gap-2 px-3 py-1.5 text-[12.5px] text-ink-soft transition-colors ${
          busy || !ready ? 'opacity-60' : ''
        }`}
      >
        <Toggle
          checked={ready && enabled}
          onChange={handle}
          size="sm"
          label={
            !ready
              ? __('Caching — loading')
              : enabled
                ? __('Turn caching off')
                : __('Turn caching on')
          }
        />
        <span className="whitespace-nowrap">{__('Caching')}</span>

      </div>

      {/* An install intent whose notice has gone renders nothing rather than
          falling through to the disable copy — that would ask "turn caching
          off?" about a click that asked to turn it on. */}
      {confirming &&
        ('install' !== intent || installing) &&
        typeof document !== 'undefined' &&
        createPortal(
          <div
            ref={panelRef}
            role="alertdialog"
            aria-labelledby={titleId}
            style={{ position: 'fixed', top: coords!.top, right: coords!.right, zIndex: 60 }}
            className={`w-[min(19rem,calc(100vw-2rem))] rounded-md border shadow-md p-3 ${
              installing
                ? 'border-line bg-info-tint'
                : 'border-danger/30 bg-danger-tint'
            }`}
          >
            <div className="flex items-start gap-2.5">
              {installing ? (
                <FileStack
                  size={15}
                  className="text-info shrink-0 mt-0.5"
                  aria-hidden="true"
                />
              ) : (
                <AlertTriangle
                  size={15}
                  className="text-danger shrink-0 mt-0.5"
                  aria-hidden="true"
                />
              )}
              <div className="min-w-0 flex-1">
                <div
                  id={titleId}
                  className={`text-[12.5px] font-medium ${
                    installing ? 'text-on-tint' : 'text-ink'
                  }`}
                >
                  {installing ? notice!.title : __('Turn caching off?')}
                </div>
                <p
                  className={`mt-1 text-[12px] leading-relaxed ${
                    'text-ink-soft'
                  }`}
                >
                  {installing
                    ? notice!.body
                    : __('Every page will be served uncached until you turn it back on.')}
                </p>
                <div className="mt-3 flex items-center gap-2">
                  <button
                    ref={confirmRef}
                    type="button"
                    onClick={() => {
                      setCoords(null);
                      onChange(!!installing);
                    }}
                    className={`h-8 px-3 rounded-md text-[12px] font-medium ${
                      installing ? 'text-on-primary' : 'text-white'
                    } transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 ${
                      installing
                        ? 'bg-primary hover:bg-primary-hover focus-visible:ring-primary'
                        : 'bg-danger hover:brightness-110 focus-visible:ring-danger'
                    }`}
                  >
                    {installing ? notice!.confirmLabel : __('Turn off')}
                  </button>
                  <button
                    type="button"
                    onClick={() => setCoords(null)}
                    className={`h-8 px-3 rounded-md text-[12px] border bg-paper transition-colors focus-visible:outline-none focus-visible:ring-2 ${
                      installing
                        ? 'border-line text-ink hover:bg-panel focus-visible:ring-primary'
                        : 'border-line text-ink hover:bg-panel focus-visible:ring-danger'
                    }`}
                  >
                    {__('Cancel')}
                  </button>
                </div>
              </div>
            </div>
          </div>,
          document.getElementById('xspeed-app') ?? document.body
        )}
    </>
  );
}
