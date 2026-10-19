import { useState, useRef, useEffect, type ReactElement, cloneElement } from 'react';
import { createPortal } from 'react-dom';

/**
 * Tooltip for icon-only controls. Replaces the native `title` attribute
 * with a design-system-styled popover that:
 *  - appears 200ms after hover or focus, hides 60ms after leave/blur
 *  - positions to the RIGHT of the trigger via position:fixed +
 *    getBoundingClientRect(). Using fixed (not absolute-within-a-
 *    parent) is what lets the tooltip escape parents with
 *    `overflow: auto/hidden` — like the sidebar's <nav> scroller,
 *    which used to clip every module-row tooltip.
 *  - portals into #xspeed-app so Tailwind's `important: '#xspeed-app'`
 *    scoping still applies to the popover's classes.
 *
 * Keyboard accessibility: appears on focus, hides on blur — same
 * timing as hover.
 */
export function Tooltip({
  label,
  hint,
  children,
  disabled = false,
}: {
  label: string;
  hint?: string;
  children: ReactElement;
  disabled?: boolean;
}) {
  const [coords, setCoords] = useState<{ top: number; left: number } | null>(null);
  const triggerRef = useRef<HTMLElement | null>(null);
  const showTimer = useRef<number | null>(null);
  const hideTimer = useRef<number | null>(null);

  useEffect(() => () => {
    if (showTimer.current) window.clearTimeout(showTimer.current);
    if (hideTimer.current) window.clearTimeout(hideTimer.current);
  }, []);

  if (disabled) return children;

  const setRef = (el: HTMLElement | null) => {
    triggerRef.current = el;
    // Forward to any existing ref the child had (not common in our
    // codebase but cheap to be polite).
    const childRef = (children as ReactElement & { ref?: unknown }).ref;
    if (typeof childRef === 'function') {
      (childRef as (el: HTMLElement | null) => void)(el);
    } else if (childRef && typeof childRef === 'object') {
      (childRef as { current: HTMLElement | null }).current = el;
    }
  };

  const reveal = () => {
    if (!triggerRef.current) return;
    const rect = triggerRef.current.getBoundingClientRect();
    setCoords({
      top: rect.top + rect.height / 2,
      left: rect.right + 8, // 8px gap from the trigger's right edge.
    });
  };

  const show = () => {
    if (hideTimer.current) {
      window.clearTimeout(hideTimer.current);
      hideTimer.current = null;
    }
    if (showTimer.current) return;
    showTimer.current = window.setTimeout(() => {
      reveal();
      showTimer.current = null;
    }, 200);
  };

  const hide = () => {
    if (showTimer.current) {
      window.clearTimeout(showTimer.current);
      showTimer.current = null;
    }
    hideTimer.current = window.setTimeout(() => {
      setCoords(null);
      hideTimer.current = null;
    }, 60);
  };

  const childProps = children.props as {
    onMouseEnter?: (e: React.MouseEvent) => void;
    onMouseLeave?: (e: React.MouseEvent) => void;
    onFocus?: (e: React.FocusEvent) => void;
    onBlur?: (e: React.FocusEvent) => void;
    [k: string]: unknown;
  };
  const cloned = cloneElement(children, {
    ref: setRef,
    onMouseEnter: (e: React.MouseEvent) => {
      show();
      childProps.onMouseEnter?.(e);
    },
    onMouseLeave: (e: React.MouseEvent) => {
      hide();
      childProps.onMouseLeave?.(e);
    },
    onFocus: (e: React.FocusEvent) => {
      show();
      childProps.onFocus?.(e);
    },
    onBlur: (e: React.FocusEvent) => {
      hide();
      childProps.onBlur?.(e);
    },
    title: undefined,
  } as Partial<React.HTMLAttributes<HTMLElement>>);

  return (
    <>
      {cloned}
      {coords && typeof document !== 'undefined' &&
        createPortal(
          <div
            role="tooltip"
            style={{
              position: 'fixed',
              top: coords.top,
              left: coords.left,
              transform: 'translateY(-50%)',
              zIndex: 60,
              pointerEvents: 'none',
            }}
            className="whitespace-nowrap rounded-md bg-neutral-900 dark:bg-neutral-800 text-white text-[11px] px-2 py-1 shadow-md border border-neutral-800 dark:border-neutral-700"
          >
            <span className="block">{label}</span>
            {hint && (
              <span className="block text-[10px] text-neutral-400 mt-0.5">
                {hint}
              </span>
            )}
          </div>,
          document.getElementById('xspeed-app') ?? document.body
        )}
    </>
  );
}
