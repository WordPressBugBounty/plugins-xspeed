import { Fragment, createContext, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import type { ModuleDescriptor } from '../types';
import { hostTargetFor } from './hostMap';
import { __ } from '../i18n';
import { useProLicensed } from '../upsell/useTier';

/**
 * Read-only context for the current search query. Lets any nested
 * panel/row call useSearchQuery() and highlight matching text
 * without prop-drilling through App → ModulePanel → SchemaModulePanel
 * → SchemaRow. Default is the empty string (no query), so components
 * that render outside the App tree (Storybook, isolated tests) just
 * get no highlighting.
 */
const SearchQueryContext = createContext<string>('');

export function SearchQueryProvider({
  query,
  children,
}: {
  query: string;
  children: ReactNode;
}) {
  return <SearchQueryContext.Provider value={query}>{children}</SearchQueryContext.Provider>;
}

export function useSearchQuery(): string {
  return useContext(SearchQueryContext);
}

/**
 * Two-layer search:
 *  1. Module-level: matches the visible label + description.
 *  2. Field-level: matches any settings field's label / description /
 *     option labels. We use this when a query like "expiry" doesn't
 *     match any module name but hits a field inside one — the
 *     dashboard then jumps to that module so the field is visible.
 *
 * The matcher is plain substring (case-insensitive). No fuzzy
 * matching to keep the bundle small + behavior predictable.
 */
export interface SearchResult {
  /**
   * The module to NAVIGATE to — always a real sidebar row. For a matched
   * child module (a tab/section inside a host), this is the HOST module so
   * navigation lands on a real row; `subtab` opens the child's tab.
   */
  module: ModuleDescriptor;
  fieldKey?: string;
  fieldLabel?: string;
  /**
   * The slug of the module that actually OWNS the matched field — the
   * child's slug for a child match (its field anchor is
   * `<fieldModuleSlug>:<fieldKey>`), which differs from `module.slug`
   * (the host row we navigate to). Used to scroll+blink the right field.
   */
  fieldModuleSlug?: string;
  /**
   * The sub-tab to open within `module` (compound hash
   * `#<module.slug>/<subtab>`). Absent when the match IS the top row.
   */
  subtab?: string;
  /**
   * What to DISPLAY for this result. For a child match these are the
   * child's own label + description (so searching "brotli" shows a
   * "Brotli" result, not "Compression"); for a row match they're the
   * row's own. `parentLabel` is the host row's label, shown as context
   * ("in Compression") when the result is a sub-item.
   */
  displayLabel: string;
  displayDescription?: string;
  parentLabel?: string;
  /** True when this result is a tab/section inside a host (not a top row). */
  isChild: boolean;
}

/**
 * @param hiddenTabs `host/subtab` keys the dashboard is not showing right
 *   now. A module that lives on one of them is not offered: the click would
 *   land on the host's default tab with nothing explaining why.
 */
export function matchModules(modules: ModuleDescriptor[], query: string, hiddenTabs: ReadonlySet<string> = new Set()): SearchResult[] {
  const bySlug = new Map(modules.map((m) => [m.slug, m]));

  // Build a result for a matched module. A child module (Brotli, Critical
  // CSS, …) navigates to its HOST row + sub-tab, but DISPLAYS its own
  // label + description so it reads as its own search hit. Dedupe by
  // (row-slug, subtab) so two children of the same tab don't double up.
  const resolve = (matched: ModuleDescriptor, extra?: Pick<SearchResult, 'fieldKey' | 'fieldLabel'>): SearchResult => {
    const base = {
      displayLabel: matched.label ?? matched.slug,
      displayDescription: matched.description,
      // The field (if any) is rendered inside the matched module's panel,
      // so its anchor is keyed on the matched slug — even when we navigate
      // to a different host row.
      ...(extra?.fieldKey ? { fieldModuleSlug: matched.slug } : {}),
      ...extra,
    };
    const target = hostTargetFor(matched.slug);
    if (!target) {
      return { module: matched, isChild: false, ...base };
    }
    const host = bySlug.get(target.host);
    // Defensive: host row missing from the payload — surface the item itself.
    if (!host) return { module: matched, isChild: false, ...base };
    return {
      module: host,
      subtab: target.subtab,
      parentLabel: host.label,
      isChild: true,
      ...base,
    };
  };

  const q = query.trim().toLowerCase();
  if (!q) {
    // No query: list only real rows (children are shown via their host).
    return modules
      .filter((m) => !hostTargetFor(m.slug))
      .map((module) => ({
        module,
        isChild: false,
        displayLabel: module.label ?? module.slug,
        displayDescription: module.description,
      }));
  }
  const seen = new Set<string>();
  const push = (r: SearchResult | null) => {
    if (!r) return;
    const key = `${r.module.slug}::${r.subtab ?? ''}`;
    if (seen.has(key)) return;
    seen.add(key);
    out.push(r);
  };
  const out: SearchResult[] = [];
  for (const module of modules) {
    const target = hostTargetFor(module.slug);
    if (target && hiddenTabs.has(`${target.host}/${target.subtab}`)) continue;
    const labelHit = (module.label ?? '').toLowerCase().includes(q);
    const descHit = (module.description ?? '').toLowerCase().includes(q);
    // A host module's own tab has a short `tab_label` (e.g. "GZIP" on the
    // Compression page) — index it so the tab name is searchable too.
    const tabHit = (module.tab_label ?? '').toLowerCase().includes(q);
    if (labelHit || descHit || tabHit) {
      push(resolve(module));
      continue;
    }
    // Drill into schema fields.
    const schema = module.schema ?? {};
    let firstFieldMatch: SearchResult | null = null;
    for (const [fieldKey, spec] of Object.entries(schema)) {
      // A hidden field has no control to jump to — offering it as a result
      // would deep-link into a panel where nothing highlights.
      if (spec.hidden) continue;
      const fieldLabel = (spec.label ?? fieldKey).toString();
      const fieldDesc = (spec.description ?? '').toString();
      const optLabels = spec.option_labels ? Object.values(spec.option_labels).join(' ') : '';
      const hay = `${fieldLabel} ${fieldDesc} ${optLabels} ${fieldKey}`.toLowerCase();
      if (hay.includes(q)) {
        firstFieldMatch = resolve(module, { fieldKey, fieldLabel });
        break;
      }
    }
    if (firstFieldMatch) {
      push(firstFieldMatch);
    }
  }
  return out;
}


/**
 * Pure hook — keeps a query string + returns filtered module list.
 * Lifted so App.tsx can also know about the active query (e.g., to
 * auto-select the first result on Enter).
 */
export function useSettingsSearch(modules: ModuleDescriptor[]) {
  const [query, setQuery] = useState('');
  // Health drops its PageSpeed tab without a licensed Pro (#465).
  const licensed = useProLicensed();
  const hiddenTabs = useMemo(() => new Set(licensed ? [] : ['health/pagespeed']), [licensed]);
  const results = useMemo(() => matchModules(modules, query, hiddenTabs), [modules, query, hiddenTabs]);
  return { query, setQuery, results };
}

/**
 * Cross-panel search highlighter. Paints substring matches across a whole
 * subtree — including Pro panels we don't own — WITHOUT mutating the DOM.
 *
 * Why not wrap matches in <mark>: the previous version walked the subtree
 * and replaced text nodes with `<mark>` fragments. React also owns that
 * subtree, so a later React re-render could try to removeChild a node the
 * walker had swapped out → "Failed to execute 'removeChild' on 'Node'".
 * A single search keystroke over a live-updating panel could crash the app.
 *
 * The fix uses the CSS Custom Highlight API: we build read-only Range
 * objects for each match and register them in a named Highlight painted by
 * a `::highlight(xspeed-search)` CSS rule (see styles.css). The DOM is never
 * touched, so React and the highlighter can't fight. On the rare browser
 * without the API, highlighting is simply skipped — search still works.
 *
 * Safety rails (unchanged): form controls and <pre>/<code>/<kbd>/<svg> are
 * skipped so we never paint over inputs, code, or the ⌘J hint chip.
 */
const SKIPPED_TAGS = new Set([
  'INPUT', 'TEXTAREA', 'SELECT', 'OPTION',
  'PRE', 'CODE', 'KBD', 'SVG', 'STYLE', 'SCRIPT',
]);

const HIGHLIGHT_NAME = 'xspeed-search';

// Feature-detect once. `Highlight` + `CSS.highlights` shipped together, so
// probing one is enough. Kept loose (any) because TS lib DOM typings for the
// Highlight API are still partial across our target versions.
type CSSWithHighlights = typeof CSS & { highlights?: Map<string, unknown> };
function highlightsSupported(): boolean {
  return (
    typeof CSS !== 'undefined' &&
    !!(CSS as CSSWithHighlights).highlights &&
    typeof (globalThis as unknown as { Highlight?: unknown }).Highlight === 'function'
  );
}

/**
 * Walk the subtree READ-ONLY and return a Range for every match. Never
 * mutates a node — Ranges just point into the existing text nodes.
 */
export function collectMatchRanges(root: Element, query: string): Range[] {
  const q = query.trim();
  if (!q) return [];
  const escaped = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const re = new RegExp(escaped, 'gi');
  const ranges: Range[] = [];

  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
    acceptNode(node) {
      if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
      let p: Node | null = node.parentNode;
      while (p && p !== root) {
        if (p instanceof Element && SKIPPED_TAGS.has(p.tagName)) {
          return NodeFilter.FILTER_REJECT;
        }
        p = p.parentNode;
      }
      return NodeFilter.FILTER_ACCEPT;
    },
  });

  let node = walker.nextNode();
  while (node) {
    const text = node.nodeValue ?? '';
    re.lastIndex = 0;
    let match: RegExpExecArray | null;
    while ((match = re.exec(text)) !== null) {
      const range = document.createRange();
      range.setStart(node, match.index);
      range.setEnd(node, match.index + match[0].length);
      ranges.push(range);
      if (match[0].length === 0) re.lastIndex++; // guard zero-width matches.
    }
    node = walker.nextNode();
  }
  return ranges;
}

export function SearchHighlighter({
  query,
  children,
}: {
  query: string;
  children: ReactNode;
}) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const root = ref.current;
    if (!root || !highlightsSupported()) return;
    const cssHighlights = (CSS as CSSWithHighlights).highlights!;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const HighlightCtor = (globalThis as any).Highlight as new (...ranges: Range[]) => unknown;

    let raf = 0;
    // Rebuild the highlight set from the current DOM. Because this only
    // creates Ranges (never edits nodes), running it after every React
    // mutation is safe — no reconciliation conflict is possible.
    const apply = () => {
      const ranges = collectMatchRanges(root, query);
      if (ranges.length === 0) {
        cssHighlights.delete(HIGHLIGHT_NAME);
        return;
      }
      cssHighlights.set(HIGHLIGHT_NAME, new HighlightCtor(...ranges));
    };
    apply();

    // Re-run after panel content changes (async lists, dropdowns, nav).
    // Throttled to one rAF so a burst of mutations only recomputes once.
    const observer = new MutationObserver(() => {
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(apply);
    });
    observer.observe(root, { childList: true, subtree: true, characterData: true });
    return () => {
      observer.disconnect();
      cancelAnimationFrame(raf);
      cssHighlights.delete(HIGHLIGHT_NAME);
    };
  }, [query]);

  return <div ref={ref} className="contents">{children}</div>;
}

/**
 * Wraps occurrences of `query` in <mark> for visual highlight. Splits
 * with case-insensitive regex but preserves the original casing of the
 * text. Returns JSX (not a string) so callers can render it directly.
 *
 * Returns the input text unchanged when query is empty or never hits.
 */
export function highlight(text: string, query: string): ReactNode {
  if ( ! text ) return text;
  const q = query.trim();
  if ( ! q ) return text;

  // Escape regex metacharacters so a query like `*.pdf` doesn't
  // explode at compile time.
  const escaped = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const re = new RegExp(`(${escaped})`, 'gi');
  const parts = text.split(re);
  if (parts.length === 1) {
    return text; // no match — fast-path the common case.
  }
  return (
    <>
      {parts.map((part, i) =>
        // Odd indices in the split-result are the captured matches.
        i % 2 === 1 ? (
          <mark
            key={i}
            className="bg-warning-tint text-inherit rounded-sm"
          >
            {part}
          </mark>
        ) : (
          <Fragment key={i}>{part}</Fragment>
        )
      )}
    </>
  );
}
