import { useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Info } from 'lucide-react';

/**
 * Click-to-open info popover for a field label (DESIGN.md §24.7). A small
 * (i) trigger toggles a portalled panel holding a paragraph of explanatory
 * copy — for help text too long for the inline `description` line or a hover
 * Tooltip. Differs from Tooltip on purpose: click (not hover), wider body,
 * wraps, and is dismissible (outside-click / Esc) so it works on touch and
 * is readable at length.
 *
 * Portals into #xspeed-app so the `important: '#xspeed-app'` Tailwind scope
 * applies, and uses position:fixed so it escapes overflow-auto parents.
 */
export function InfoPopover({ title, body }: { title?: string; body: string }) {
  const [coords, setCoords] = useState<{ top: number; left: number; maxHeight: number } | null>(null);
  const triggerRef = useRef<HTMLButtonElement | null>(null);
  const panelRef = useRef<HTMLDivElement | null>(null);
  const labelId = useId();
  const open = coords !== null;

  const place = () => {
    const el = triggerRef.current;
    if (!el) return;
    const r = el.getBoundingClientRect();

    // Open upwards when there is more room above, and never taller than the
    // room it has. `position: fixed` escapes an overflow-auto parent, which is
    // what this component exists for — but it also means a long body simply
    // runs off the bottom of the screen with no scrollbar and no warning. The
    // reader sees a sentence cut in half and no way to reach the rest.
    const GAP = 6;
    const MARGIN = 12;
    const below = window.innerHeight - r.bottom - GAP - MARGIN;
    const above = r.top - GAP - MARGIN;
    const openUp = below < 180 && above > below;
    const room = Math.max(120, openUp ? above : below);
    const maxHeight = Math.min(room, 320);

    setCoords({
      top: openUp ? Math.max(MARGIN, r.top - GAP - maxHeight) : r.bottom + GAP,
      left: r.left,
      maxHeight,
    });
  };

  const toggle = () => (open ? setCoords(null) : place());

  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (
        !panelRef.current?.contains(e.target as Node) &&
        !triggerRef.current?.contains(e.target as Node)
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
    };
  }, [open]);

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        onClick={toggle}
        aria-expanded={open}
        aria-label={title ? `Info: ${title}` : 'More information'}
        className="inline-flex items-center justify-center text-neutral-400 hover:text-accent dark:hover:text-primary transition-colors align-middle"
      >
        <Info size={14} />
      </button>
      {open &&
        typeof document !== 'undefined' &&
        createPortal(
          <div
            ref={panelRef}
            role="dialog"
            aria-labelledby={title ? labelId : undefined}
            style={{
              position: 'fixed',
              top: coords!.top,
              left: coords!.left,
              maxHeight: coords!.maxHeight,
              zIndex: 60,
            }}
            className="w-[min(20rem,calc(100vw-2rem))] overflow-y-auto rounded-md bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-700 shadow-md p-3"
          >
            {title && (
              <div
                id={labelId}
                className="text-[12px] font-medium text-neutral-900 dark:text-neutral-50 mb-1"
              >
                {title}
              </div>
            )}
            <p className="text-[12px] leading-relaxed text-neutral-600 dark:text-neutral-400">
              {body}
            </p>
          </div>,
          document.getElementById('xspeed-app') ?? document.body
        )}
    </>
  );
}
