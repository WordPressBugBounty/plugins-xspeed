import { useId, useLayoutEffect, useRef, type ReactNode } from 'react';
import { Lock } from 'lucide-react';
import { __ } from '../i18n';

/**
 * Shared underline tab strip (DESIGN.md §24.10).
 *
 * Extracted from the inlined pattern in McpPanel so the sidebar
 * restructure (FBS-83633) can turn former sidebar rows into tabs inside
 * a host page without re-implementing tab chrome per panel.
 *
 * Controlled component: the parent owns the active-tab string. That's
 * deliberate — host panels bind `value`/`onChange` to the compound URL
 * hash (`#slug/subtab`, see App.tsx) so a tab is deep-linkable and
 * survives back/forward. For a purely local tab set, hold the state in
 * a `useState` and pass it straight through.
 *
 * A11y: the strip is a `tablist`; each button is a `tab` with
 * `aria-selected` + roving `tabIndex`, and ← / → move between tabs
 * (WAI-ARIA tabs pattern). Panels render their own content region; the
 * `panelId`/`tabId` helpers keep `aria-controls`/`aria-labelledby`
 * wired if the caller wants them.
 */

export interface TabItem {
  /** Stable key used in the URL hash + as the aria id seed. */
  key: string;
  /** Visible label. */
  label: ReactNode;
  /**
   * Locked tabs carry the inline Pro marker — a lock, with "Pro" for screen
   * readers — and are not dimmed; clicking still selects them (the host
   * panel shows a <LockedSection> body).
   */
  locked?: boolean;
}

export function Tabs({
  items,
  value,
  onChange,
  idBase,
}: {
  items: TabItem[];
  value: string;
  onChange: (key: string) => void;
  /** Optional stable id seed so tab/panel aria ids are deterministic. */
  idBase?: string;
}) {
  const autoId = useId();
  const base = idBase ?? autoId;

  function move(dir: 1 | -1) {
    const i = items.findIndex((t) => t.key === value);
    if (i === -1) return;
    const next = items[(i + dir + items.length) % items.length];
    onChange(next.key);
  }

  return (
    <div
      role="tablist"
      className="flex items-center gap-1 border-b border-neutral-200 dark:border-neutral-800"
      onKeyDown={(e) => {
        if (e.key === 'ArrowRight') {
          e.preventDefault();
          move(1);
        } else if (e.key === 'ArrowLeft') {
          e.preventDefault();
          move(-1);
        }
      }}
    >
      {items.map((t) => {
        const selected = t.key === value;
        return (
          <button
            key={t.key}
            id={tabId(base, t.key)}
            role="tab"
            type="button"
            aria-selected={selected}
            aria-controls={panelId(base, t.key)}
            tabIndex={selected ? 0 : -1}
            onClick={() => onChange(t.key)}
            className={`text-[12px] px-3 py-1.5 -mb-px border-b-2 transition-colors ${
              selected
                ? 'border-accent text-neutral-900 dark:text-neutral-50 font-medium'
                : 'border-transparent text-neutral-500 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200'
            }`}
          >
            <span className="inline-flex items-center gap-1.5">
              {t.label}
              {t.locked && (
                <>
                  <Lock size={11} className="shrink-0 text-on-tint" aria-hidden="true" />
                  <span className="sr-only">{__('Pro')}</span>
                </>
              )}
            </span>
          </button>
        );
      })}
    </div>
  );
}

/**
 * Wrapper for the tab BODIES under a Tabs strip (§24.10).
 *
 * Tab bodies are conditionally mounted, and the incoming tab's panels often
 * fetch before they reach full height — so for a few frames the scroll pane
 * gets shorter, the browser clamps its scrollTop, and the viewport visibly
 * jumps ("tab shake"). This wrapper reserves height across the switch so the
 * pane can never momentarily collapse.
 *
 * The reservation is deliberately TRANSIENT and scoped to one switch. An
 * earlier version ratcheted to the tallest content ever seen on the page and
 * released it only on unmount, which padded every shorter tab to the tallest
 * one's height for as long as you stayed: on Health — which opens on its
 * tallest tab — the very first click on Visitors inherited the full
 * reservation, leaving a screen of blank space below the real content. It
 * also latched transient NARROW-layout heights, so resizing wide again left
 * the extra height stuck with no tab switch involved at all.
 *
 * Instead: hold the outgoing tab's height only while the incoming one
 * settles, then release. `active` is what tells us a switch happened —
 * without it there is no way to distinguish "new tab still loading" from
 * "this tab is genuinely short". (#207 F1, F2)
 */
export function TabPanels({
  children,
  className,
  active,
  idBase,
}: {
  children: ReactNode;
  className?: string;
  active?: string;
  /**
   * The same `idBase` passed to <Tabs>. Supplying it lets this wrapper carry
   * the `role="tabpanel"` / `id` / `aria-labelledby` half of the ARIA
   * contract. Every tab button already sets `aria-controls`, but no element
   * carried the generated id, so the reference dangled and axe flagged
   * `aria-valid-attr-value`. (#207)
   */
  idBase?: string;
}) {
  const ref = useRef<HTMLDivElement>(null);

  // Capture the outgoing height synchronously, BEFORE the browser paints the
  // incoming tab — that is the frame the clamp would otherwise happen in.
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;

    const reserved = el.offsetHeight;
    if (reserved > 0) {
      el.style.minHeight = `${reserved}px`;
    }

    if (typeof ResizeObserver === 'undefined') {
      el.style.minHeight = '';
      return;
    }

    // Release as soon as the incoming content has reached at least the
    // reserved height, or after a short grace period if it is genuinely
    // shorter. Either way the reservation never outlives the switch.
    let done = false;
    const release = () => {
      if (done) return;
      done = true;
      window.clearTimeout(timer);
      ro.disconnect();
      if (ref.current) ref.current.style.minHeight = '';
    };
    const ro = new ResizeObserver(() => {
      if (el.scrollHeight >= reserved) release();
    });
    ro.observe(el);
    const timer = window.setTimeout(release, 600);

    return () => {
      window.clearTimeout(timer);
      ro.disconnect();
      if (ref.current) ref.current.style.minHeight = '';
    };
  }, [active]);

  const aria =
    idBase && active
      ? {
          role: 'tabpanel' as const,
          id: panelId(idBase, active),
          'aria-labelledby': tabId(idBase, active),
          tabIndex: 0,
        }
      : {};

  return (
    <div ref={ref} className={className} {...aria}>
      {children}
    </div>
  );
}

/** Deterministic id for a tab button — pair with panelId for aria wiring. */
export function tabId(base: string, key: string): string {
  return `xspeed-tab-${base}-${key}`;
}

/** Deterministic id for a tab's content region. */
export function panelId(base: string, key: string): string {
  return `xspeed-tabpanel-${base}-${key}`;
}
