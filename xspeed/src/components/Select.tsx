import {
  useCallback,
  useEffect,
  useId,
  useLayoutEffect,
  useRef,
  useState,
} from 'react';
import { createPortal } from 'react-dom';
import { ChevronDown, Check } from 'lucide-react';

/**
 * Styled single-select dropdown. Replaces the native <select> wherever a
 * list is too long for a pill row — see DESIGN.md §24.39.
 *
 * Why a custom select instead of native:
 *   - Native <select> doesn't respect Tailwind's dark-mode classes
 *     on the option list in any browser, only on the trigger.
 *   - Option hints need two lines per row, which native options cannot do.
 *   - Native selects ignore z-index, so they break panels rendered
 *     inside a scroll container.
 *
 * Portals the menu out of any overflow:hidden ancestor (the same fix
 * Tooltip uses) and positions it with getBoundingClientRect on
 * the trigger. Closes on outside click + Escape. Keyboard support:
 * Enter/Space/ArrowDown to open, arrows + Home/End to navigate,
 * Enter/Space to commit, Escape to close.
 */

export interface SelectOption {
  value: string;
  label: string;
  hint?: string;
}

interface SelectProps {
  value: string;
  options: SelectOption[];
  onChange: (value: string) => void;
  placeholder?: string;
  ariaLabel: string;
  className?: string;
  disabled?: boolean;
}

export function Select({
  value,
  options,
  onChange,
  placeholder = 'Select…',
  ariaLabel,
  className = '',
  disabled = false,
}: SelectProps) {
  const [open, setOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);
  const [position, setPosition] = useState<{
    top: number;
    left: number;
    minWidth: number;
    maxWidth: number;
    maxHeight: number;
  } | null>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const listboxId = useId();

  const selected = options.find((o) => o.value === value) ?? null;

  const reposition = useCallback(() => {
    if (!triggerRef.current) return;
    const r = triggerRef.current.getBoundingClientRect();

    // The menu is at LEAST as wide as the trigger and may grow past it, up
    // to whatever room is left before the viewport edge. Pinning it to the
    // trigger's width truncated every option that did not fit a w-56 field
    // — "Amazon Cl…", "Sucuri (nee…" — which is the one thing a picker must
    // not do, since the label is the only way to tell the options apart.
    const maxWidth = Math.max(r.width, window.innerWidth - r.left - 16);

    // Open upwards when there is more room above. A field near the bottom of
    // a long settings page has almost no space below it, and a menu pinned
    // under the trigger is then clipped by the viewport — the visible part
    // showed three of fifteen providers with no way to reach the rest.
    const GAP = 4;
    const MARGIN = 12;
    const below = window.innerHeight - r.bottom - GAP - MARGIN;
    const above = r.top - GAP - MARGIN;
    const openUp = below < 200 && above > below;
    const room = Math.max(120, openUp ? above : below);

    setPosition({
      top: openUp ? Math.max(MARGIN, r.top - GAP - Math.min(room, 288)) : r.bottom + GAP,
      left: r.left,
      minWidth: r.width,
      maxWidth,
      // Never taller than the room it has. The list scrolls inside itself
      // rather than running off the screen.
      maxHeight: Math.min(room, 288),
    });
  }, []);

  useLayoutEffect(() => {
    if (open) reposition();
  }, [open, reposition]);

  useEffect(() => {
    if (disabled) setOpen(false);
  }, [disabled]);

  // Re-position on viewport / ancestor scroll while open.
  useEffect(() => {
    if (!open) return;
    const onResize = () => reposition();
    const onScroll = () => reposition();
    window.addEventListener('resize', onResize);
    window.addEventListener('scroll', onScroll, true);
    return () => {
      window.removeEventListener('resize', onResize);
      window.removeEventListener('scroll', onScroll, true);
    };
  }, [open, reposition]);

  // Close on outside click.
  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      const target = e.target as Node | null;
      if (!target) return;
      if (triggerRef.current?.contains(target)) return;
      if (menuRef.current?.contains(target)) return;
      setOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, [open]);

  // When opening, initialize highlight to selected + focus the menu
  // so arrow keys / Escape fire on the listbox.
  useEffect(() => {
    if (!open) return;
    const idx = options.findIndex((o) => o.value === value);
    setActiveIndex(idx >= 0 ? idx : 0);
    // Defer focus until after the portal renders.
    const id = window.setTimeout(() => menuRef.current?.focus(), 0);
    return () => window.clearTimeout(id);
  }, [open, options, value]);

  const commit = (idx: number) => {
    if (disabled) return;
    const opt = options[idx];
    if (!opt) return;
    onChange(opt.value);
    setOpen(false);
    triggerRef.current?.focus();
  };

  const onTriggerKey = (e: React.KeyboardEvent<HTMLButtonElement>) => {
    if (disabled) return;
    if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      setOpen(true);
    }
  };

  const onMenuKey = (e: React.KeyboardEvent<HTMLDivElement>) => {
    if (disabled) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      setOpen(false);
      triggerRef.current?.focus();
      return;
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActiveIndex((i) => Math.min(options.length - 1, i + 1));
      return;
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActiveIndex((i) => Math.max(0, i - 1));
      return;
    }
    if (e.key === 'Home') {
      e.preventDefault();
      setActiveIndex(0);
      return;
    }
    if (e.key === 'End') {
      e.preventDefault();
      setActiveIndex(options.length - 1);
      return;
    }
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      commit(activeIndex);
    }
  };

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        role="combobox"
        aria-haspopup="listbox"
        aria-expanded={open ? 'true' : 'false'}
        aria-controls={listboxId}
        aria-label={ariaLabel}
        disabled={disabled}
        onClick={() => !disabled && setOpen((o) => !o)}
        onKeyDown={onTriggerKey}
        // Free's preflight resets buttons with `border: 0` (shorthand)
        // which wipes border-style too. `!border` only sets width;
        // without `!border-solid` no line is painted. Both flags are
        // required.
        style={{ height: 36, minHeight: 36, maxHeight: 36, boxSizing: 'border-box' }}
        className={`w-full flex items-center justify-between gap-2 text-left text-[13px] px-3 rounded-md !border !border-solid !border-neutral-400 dark:!border-neutral-600 bg-white dark:bg-neutral-900 text-neutral-900 dark:text-neutral-50 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${className}`}
      >
        <span className="truncate min-w-0">
          {selected ? (
            <>
              <span>{selected.label}</span>
              {selected.hint && (
                <span className="text-neutral-500 dark:text-neutral-400"> — {selected.hint}</span>
              )}
            </>
          ) : (
            <span className="text-neutral-500 dark:text-neutral-400">{placeholder}</span>
          )}
        </span>
        <ChevronDown
          size={14}
          className={`shrink-0 text-neutral-500 dark:text-neutral-400 transition-transform ${open ? 'rotate-180' : ''}`}
        />
      </button>

      {open && position && (() => {
        // Portal INTO #xspeed-app so Free's Tailwind scope
        // (important: '#xspeed-app') still applies. document.body
        // would strip every utility because it lives outside the
        // important selector. position:fixed gets us out of any
        // overflow:hidden ancestor without needing to leave the
        // app subtree.
        const host = typeof document !== 'undefined'
          ? document.getElementById('xspeed-app')
          : null;
        if (!host) return null;
        return createPortal(
          <div
            ref={menuRef}
            role="listbox"
            id={listboxId}
            tabIndex={-1}
            onKeyDown={onMenuKey}
            style={{
              position: 'fixed',
              top: position.top,
              left: position.left,
              minWidth: position.minWidth,
              maxWidth: position.maxWidth,
              maxHeight: position.maxHeight,
              width: 'max-content',
              zIndex: 60,
            }}
            className="overflow-y-auto rounded-md border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 shadow-lg py-1 focus:outline-none"
          >
            {options.map((opt, i) => {
              const active = i === activeIndex;
              const isSelected = opt.value === value;
              return (
                <div
                  key={opt.value}
                  role="option"
                  aria-selected={isSelected ? 'true' : 'false'}
                  onMouseEnter={() => { if (!disabled) setActiveIndex(i); }}
                  onMouseDown={(e) => {
                    e.preventDefault();
                    if (!disabled) commit(i);
                  }}
                  className={`flex items-start gap-2 px-3 py-2 cursor-pointer text-[13px] ${
                    active
                      ? 'bg-accent/10 dark:bg-accent/20 text-neutral-900 dark:text-neutral-50'
                      : 'text-neutral-800 dark:text-neutral-200'
                  }`}
                >
                  <span className="shrink-0 w-4 mt-0.5">
                    {isSelected && <Check size={14} className="text-accent" />}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block">{opt.label}</span>
                    {opt.hint && (
                      <span className="block text-[11px] text-neutral-500 dark:text-neutral-400 truncate">
                        {opt.hint}
                      </span>
                    )}
                  </span>
                </div>
              );
            })}
          </div>,
          host
        );
      })()}
    </>
  );
}
