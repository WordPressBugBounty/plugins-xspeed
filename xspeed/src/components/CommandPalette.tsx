import { useEffect, useMemo, useRef, useState } from 'react';
import { Search, CornerDownLeft, RefreshCw, Rocket } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { ModuleIcon } from './ModuleIcon';
import { useSettingsSearch, highlight, type SearchResult } from './SettingsSearch';
import { groupById, groupIdForSlug } from './sidebarGroups';
import { __ } from '../i18n';
import { useEscapeWpStackingContext } from '../hooks/useEscapeWpStackingContext';
import type { ModuleDescriptor } from '../types';

/** A global action (not a module/setting) offered by the palette. */
interface Command {
  id: string;
  label: string;
  icon: LucideIcon;
  run: () => void;
}

type Item =
  | { kind: 'command'; command: Command }
  | { kind: 'result'; result: SearchResult };

/**
 * ⌘K command palette (redesign v2). Replaces the sidebar search box: a modal
 * that jumps to any module / setting / action. It REUSES the existing settings
 * search index (useSettingsSearch → matchModules) — no new index — and adds a
 * couple of global actions (Purge all, Preload) wired to the real handlers.
 *
 * Accessibility: role="dialog" + aria-modal; the input keeps DOM focus while a
 * roving aria-activedescendant marks the active option (combobox/listbox
 * pattern); ↑/↓ move, ↵ opens, esc closes; focus is trapped to the input and
 * restored to the opener on close.
 */
export function CommandPalette({
  open,
  onClose,
  modules,
  onSelectResult,
  onPurge,
  onPreload,
}: {
  open: boolean;
  onClose: () => void;
  modules: ModuleDescriptor[];
  onSelectResult: (r: SearchResult) => void;
  onPurge: () => void;
  onPreload: () => void;
}) {
  const { query, setQuery, results } = useSettingsSearch(modules);
  const [active, setActive] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLUListElement>(null);
  const dialogRef = useRef<HTMLDivElement>(null);
  const restoreRef = useRef<HTMLElement | null>(null);

  const commands: Command[] = useMemo(
    () => [
      { id: 'purge', label: __('Purge all'), icon: RefreshCw, run: onPurge },
      { id: 'preload', label: __('Preload'), icon: Rocket, run: onPreload },
    ],
    [onPurge, onPreload],
  );

  // Merge matching global actions (top) with the reused module/setting results.
  const items: Item[] = useMemo(() => {
    const q = query.trim().toLowerCase();
    const cmds = commands.filter((c) => !q || c.label.toLowerCase().includes(q));
    return [
      ...cmds.map((command) => ({ kind: 'command' as const, command })),
      ...results.slice(0, 40).map((result) => ({ kind: 'result' as const, result })),
    ];
  }, [commands, results, query]);

  // Open/close lifecycle: reset query + selection, focus the input, remember
  // the opener so focus can be restored on close.
  useEffect(() => {
    if (open) {
      restoreRef.current = (document.activeElement as HTMLElement) ?? null;
      setQuery('');
      setActive(0);
      // Focus after paint so the input exists.
      const t = window.requestAnimationFrame(() => inputRef.current?.focus());
      return () => window.cancelAnimationFrame(t);
    }
    restoreRef.current?.focus?.();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  // Clamp the active index whenever the list shrinks.
  useEffect(() => {
    setActive((i) => Math.min(i, Math.max(0, items.length - 1)));
  }, [items.length]);

  // Keep the active option scrolled into view.
  useEffect(() => {
    if (!open) return;
    listRef.current
      ?.querySelector<HTMLElement>('[aria-selected="true"]')
      ?.scrollIntoView({ block: 'nearest' });
  }, [active, open]);

  // Escape closes — bound at the document level while open so it works no
  // matter where focus is (not only when focus is inside the dialog).
  useEffect(() => {
    if (!open) return;
    const onEsc = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        onClose();
      }
    };
    document.addEventListener('keydown', onEsc);
    return () => document.removeEventListener('keydown', onEsc);
  }, [open, onClose]);

  // Escape WP's stacking context — see the hook for why this is needed.
  useEscapeWpStackingContext(open);

  if (!open) return null;

  const run = (item: Item) => {
    if (item.kind === 'command') item.command.run();
    else onSelectResult(item.result);
    onClose();
  };

  const onKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      onClose();
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActive((i) => (items.length ? (i + 1) % items.length : 0));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActive((i) => (items.length ? (i - 1 + items.length) % items.length : 0));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const item = items[active];
      if (item) run(item);
    } else if (e.key === 'Tab') {
      // Trap focus — the input is the only focusable control.
      e.preventDefault();
    }
  };

  // Keep the active row scrolled into view.
  const optionId = (i: number) => `xspeed-cmdk-opt-${i}`;

  return (
    <div
      /* z-[100000] matches Modal: WP's admin menu sits at 9990, so anything
         lower paints under it once #wpbody stops trapping us. (#283) */
      className="fixed inset-0 z-[100000] flex items-start justify-center px-4 pt-[12vh]"
      onMouseDown={(e) => {
        // Close on a mousedown that STARTS outside the panel (backdrop/scrim).
        // Using contains() — not target===currentTarget — so the scrim child
        // still counts as "outside"; a drag-select that starts in the input
        // stays inside the panel and does NOT close.
        if (dialogRef.current && !dialogRef.current.contains(e.target as Node)) {
          onClose();
        }
      }}
    >
      {/* Scrim */}
      <div className="absolute inset-0 bg-black/40" aria-hidden="true" />

      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-label={__('Command palette')}
        className="relative w-full max-w-xl rounded-card border border-line bg-paper shadow-xl overflow-hidden"
        onKeyDown={onKeyDown}
      >
        {/* Search header — evenly padded, hairline divider below. */}
        <div className="flex items-center gap-3 px-4 py-3 border-b border-line">
          <Search size={18} className="shrink-0 text-muted" aria-hidden="true" />
          <input
            ref={inputRef}
            type="text"
            role="combobox"
            aria-expanded="true"
            aria-controls="xspeed-cmdk-list"
            aria-activedescendant={items.length ? optionId(active) : undefined}
            aria-label={__('Search settings, modules, actions')}
            value={query}
            onChange={(e) => {
              setQuery(e.target.value);
              setActive(0);
            }}
            placeholder={__('Search settings, modules, actions…')}
            className="w-full bg-transparent py-0.5 text-[15px] text-ink placeholder-muted focus:outline-none"
          />
          <kbd className="shrink-0 hidden sm:inline-flex items-center h-[20px] px-1.5 rounded text-[10px] font-medium bg-panel text-muted">
            esc
          </kbd>
        </div>

        {/* Results */}
        {items.length === 0 ? (
          <div className="px-4 py-8 text-center text-[13px] text-muted">
            {__('No matches')}
          </div>
        ) : (
          <ul
            ref={listRef}
            id="xspeed-cmdk-list"
            role="listbox"
            aria-label={__('Results')}
            className="max-h-[52vh] overflow-y-auto py-1.5"
          >
            {items.map((item, i) => {
              const isActive = i === active;
              const rowCls = `flex items-center gap-3 px-4 py-2 cursor-pointer ${
                isActive ? 'bg-primary-tint' : ''
              }`;
              if (item.kind === 'command') {
                const Icon = item.command.icon;
                return (
                  <li
                    key={`cmd-${item.command.id}`}
                    id={optionId(i)}
                    role="option"
                    aria-selected={isActive}
                    className={rowCls}
                    onMouseEnter={() => setActive(i)}
                    onMouseDown={(e) => { e.preventDefault(); run(item); }}
                  >
                    <span className="shrink-0 inline-flex h-6 w-6 items-center justify-center rounded-control bg-panel text-ink-soft">
                      <Icon size={14} aria-hidden="true" />
                    </span>
                    <span className="flex-1 min-w-0 truncate text-[13px] text-ink">
                      {highlight(item.command.label, query)}
                    </span>
                    <span className="shrink-0 text-[11px] text-muted">{__('Action')}</span>
                    {isActive && <CornerDownLeft size={13} className="shrink-0 text-muted" aria-hidden="true" />}
                  </li>
                );
              }
              const r = item.result;
              const groupLabel =
                r.parentLabel ?? groupById(groupIdForSlug(r.module.slug) ?? '')?.label ?? '';
              return (
                <li
                  key={`res-${r.module.slug}:${r.subtab ?? ''}:${r.fieldKey ?? ''}`}
                  id={optionId(i)}
                  role="option"
                  aria-selected={isActive}
                  className={rowCls}
                  onMouseEnter={() => setActive(i)}
                  onMouseDown={(e) => { e.preventDefault(); run(item); }}
                >
                  <span className="shrink-0 inline-flex h-6 w-6 items-center justify-center rounded-control bg-panel text-ink-soft">
                    <ModuleIcon name={r.module.icon} size={14} />
                  </span>
                  <span className="flex-1 min-w-0 truncate text-[13px] text-ink">
                    {highlight(r.displayLabel, query)}
                    {r.fieldLabel && (
                      <span className="text-muted"> · {highlight(r.fieldLabel, query)}</span>
                    )}
                  </span>
                  {groupLabel && <span className="shrink-0 text-[11px] text-muted">{groupLabel}</span>}
                  {isActive && <CornerDownLeft size={13} className="shrink-0 text-muted" aria-hidden="true" />}
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </div>
  );
}
