import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

export interface MenuItem {
  label: string;
  /** One line under the label, for items that need explaining. */
  hint?: string;
  icon?: ReactNode;
  onSelect: () => void;
  disabled?: boolean;
}

/**
 * A button that opens a short list of actions (DESIGN.md §24.40). The same
 * primitive as the Hub's, so a split button behaves alike in both places.
 *
 * Escape, an outside click or a choice closes it; opening focuses the first
 * enabled item; arrow keys move between items. The list is portalled into
 * #xspeed-app, not <body>, for the reason Select gives: the Tailwind scope is
 * that element, and outside it every utility is dropped. It is placed from the
 * trigger's rect with position:fixed, so the sticky top bar cannot clip it.
 */
export function Menu({
  trigger,
  items,
  label,
  align = 'right',
  onOpen,
}: {
  /** Render prop for the trigger, given the open state, a toggle and its ARIA attributes. */
  trigger: (p: { open: boolean; toggle: () => void; ariaProps: Record<string, unknown> }) => ReactNode;
  items: MenuItem[];
  label: string;
  align?: 'left' | 'right';
  /** Called each time the menu opens, e.g. to refresh what it lists. */
  onOpen?: () => void;
}) {
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const list = useRef<HTMLDivElement>(null);
  const [pos, setPos] = useState<{ top: number; left?: number; right?: number } | null>(null);

  // Scrolling or resizing closes it rather than letting it drift from its trigger.
  useLayoutEffect(() => {
    if (!open) {
      setPos(null);
      return;
    }
    const r = root.current?.getBoundingClientRect();
    if (r) {
      setPos({
        top: r.bottom + 4,
        ...(align === 'right' ? { right: window.innerWidth - r.right } : { left: r.left }),
      });
    }
    const shut = () => setOpen(false);
    window.addEventListener('resize', shut);
    window.addEventListener('scroll', shut, true);
    return () => {
      window.removeEventListener('resize', shut);
      window.removeEventListener('scroll', shut, true);
    };
  }, [open, align]);

  /** Close and put focus back on the trigger, so keyboard users stay put. */
  const close = (refocus: boolean) => {
    setOpen(false);
    if (refocus) root.current?.querySelector<HTMLElement>('[aria-haspopup="menu"]')?.focus();
  };

  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      const t = e.target as Node;
      if (root.current?.contains(t) || list.current?.contains(t)) return;
      setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') close(true);
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  // Focus the first item once the list is placed, and again when the items
  // change under it (a refreshed list replaces the buttons).
  useEffect(() => {
    if (open && pos) list.current?.querySelector<HTMLButtonElement>('button:not(:disabled)')?.focus();
  }, [open, pos, items.length]);

  const onListKey = (e: React.KeyboardEvent) => {
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    e.preventDefault();
    const buttons = [...(list.current?.querySelectorAll<HTMLButtonElement>('button:not(:disabled)') ?? [])];
    if (buttons.length === 0) return;
    const i = buttons.indexOf(document.activeElement as HTMLButtonElement);
    const next = e.key === 'ArrowDown' ? (i + 1) % buttons.length : (i - 1 + buttons.length) % buttons.length;
    buttons[next]?.focus();
  };

  const host = typeof document !== 'undefined' ? document.getElementById('xspeed-app') : null;

  return (
    <div ref={root} className="relative flex">
      {trigger({
        open,
        toggle: () =>
          setOpen((v) => {
            if (!v) onOpen?.();
            return !v;
          }),
        ariaProps: { 'aria-haspopup': 'menu', 'aria-expanded': open, 'aria-label': label },
      })}
      {open &&
        host &&
        createPortal(
          <div
            ref={list}
            role="menu"
            aria-label={label}
            onKeyDown={onListKey}
            style={{
              position: 'fixed',
              top: pos?.top ?? -9999,
              left: pos?.left,
              right: pos?.right,
              visibility: pos ? 'visible' : 'hidden',
              zIndex: 60,
            }}
            className="min-w-[220px] max-w-[calc(100vw-32px)] overflow-hidden rounded-control border border-line bg-paper py-1 shadow-md"
          >
            {items.map((item) => (
              <button
                key={item.label}
                type="button"
                role="menuitem"
                disabled={item.disabled}
                onClick={() => {
                  close(true);
                  item.onSelect();
                }}
                className="flex w-full items-start gap-2 px-3 py-2 text-left text-[13px] text-ink hover:bg-panel focus:bg-panel focus:outline-none disabled:opacity-50"
              >
                {item.icon && <span className="mt-0.5 shrink-0 text-muted">{item.icon}</span>}
                <span className="min-w-0">
                  <span className="block">{item.label}</span>
                  {item.hint && <span className="block text-[11.5px] text-muted">{item.hint}</span>}
                </span>
              </button>
            ))}
          </div>,
          host
        )}
    </div>
  );
}
