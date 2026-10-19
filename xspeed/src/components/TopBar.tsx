import { RefreshCw, Check, AlertTriangle, Cloud, Sun, Moon, ChevronLeft, Search, Menu } from 'lucide-react';
import { CacheSwitch } from './CacheSwitch';
import { StatusChip } from './StatusChip';
import { StatusItemBoundary, useStatusItems } from './statusItems';
import type { DropinState } from './dropinNotice';
import type { PurgeOutcome } from './purgeOutcome';
import { __ } from '../i18n';

/** The ⌘K / Ctrl-K label, chosen once from the platform. */
const isMac =
  typeof navigator !== 'undefined' &&
  /Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent || '');
const SEARCH_SHORTCUT = isMac ? '⌘K' : 'Ctrl K';

export interface Crumb {
  /** Parent group label, when the current page is nested (module page). */
  backLabel?: string;
  /** Navigate to the parent (group landing). */
  onBack?: () => void;
  /** The current page label. */
  current: string;
}

/**
 * Persistent top bar (redesign v2) — sits above every screen. Left: breadcrumb
 * with a back chip (only on nested pages). Right: the master cache switch, the
 * one loud action (Purge all), any add-on status chips, a quiet Hub status
 * chip, and the dark toggle.
 * These stay put on every route so the headline state and action are always
 * one click away.
 *
 * Preload deliberately does NOT live here. A persistent bar earns its space
 * with controls that are relevant from anywhere; preload is a minutes-long
 * crawler with its own progress UI on its own page, so it reads as a peer of
 * Purge while behaving nothing like one. It stays reachable from the ⌘K
 * palette and the Preloader page. What replaced it is the thing the bar was
 * actually missing: whether caching is on at all.
 */
export function TopBar({
  crumb,
  onOpenSearch,
  onOpenNav,
  onPurge,
  onToggleCache,
  onConnectHub,
  purging = false,
  purgeOutcome = null,
  cacheEnabled = false,
  cacheBusy = false,
  cacheReady = true,
  cacheDropin = null,
  hubConnected = false,
  theme,
  onToggleTheme,
}: {
  crumb: Crumb;
  /** Open the ⌘K command palette. */
  onOpenSearch: () => void;
  /** Open the off-canvas nav drawer. Below `md` only. */
  onOpenNav?: () => void;
  onPurge: () => void;
  /**
   * Flip the master page-cache switch. Disabling always confirms first;
   * enabling confirms only when it would replace a leftover drop-in.
   */
  onToggleCache: (enabled: boolean) => void;
  onConnectHub: () => void;
  purging?: boolean;
  /** The last purge's result, shown on the button for a moment (§24.38). */
  purgeOutcome?: PurgeOutcome | null;
  cacheEnabled?: boolean;
  cacheBusy?: boolean;
  /** Settings have loaded, so `cacheEnabled` is the site's real state. */
  cacheReady?: boolean;
  /** Leftover advanced-cache.php that enabling would replace, or null. */
  cacheDropin?: DropinState | null;
  hubConnected?: boolean;
  theme: 'light' | 'dark';
  onToggleTheme: () => void;
}) {
  return (
    <header className="sticky top-0 z-30 h-14 shrink-0 flex items-center justify-between gap-3 px-4 sm:px-6 border-b border-line bg-paper">
      {/* Breadcrumb */}
      <nav aria-label={__('Breadcrumb')} className="flex items-center gap-2 min-w-0">
        {/* Drawer trigger — the only way to reach navigation below `md`,
            where the rail is off-canvas. */}
        {onOpenNav && (
          <button
            type="button"
            onClick={onOpenNav}
            aria-label={__('Open navigation')}
            className="md:hidden shrink-0 -ml-1 inline-flex items-center justify-center w-8 h-8 rounded-control text-ink-soft hover:text-ink hover:bg-panel transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          >
            <Menu size={18} aria-hidden="true" />
          </button>
        )}
        {crumb.backLabel && (
          <button
            type="button"
            onClick={crumb.onBack}
            className="inline-flex items-center gap-1 shrink-0 rounded-control px-2 py-1 text-[13px] text-ink-soft hover:text-ink hover:bg-panel transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          >
            <ChevronLeft size={15} aria-hidden="true" />
            {crumb.backLabel}
          </button>
        )}
        {crumb.backLabel && <span className="text-faint shrink-0" aria-hidden="true">/</span>}
        <span className="truncate text-[14px] font-medium text-ink">{crumb.current}</span>
      </nav>

      {/* Actions */}
      <div className="flex items-center gap-2 shrink-0">
        {/* ⌘K search trigger — opens the command palette. */}
        <button
          type="button"
          onClick={onOpenSearch}
          aria-label={__('Search')}
          aria-keyshortcuts="Meta+K Control+K"
          className="inline-flex items-center gap-2 rounded-control border border-line px-2.5 py-1.5 text-[12.5px] text-muted hover:text-ink hover:bg-panel transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
          <Search size={14} aria-hidden="true" />
          <span className="hidden md:inline">{__('Search')}</span>
          <kbd className="hidden md:inline-flex items-center h-[18px] px-1 rounded text-[10px] font-medium bg-panel text-muted">
            {SEARCH_SHORTCUT}
          </kbd>
        </button>
        <CacheSwitch
          enabled={cacheEnabled}
          onChange={onToggleCache}
          busy={cacheBusy}
          ready={cacheReady}
          dropin={cacheDropin}
        />
        <PurgeButton onPurge={onPurge} purging={purging} outcome={purging ? null : purgeOutcome} />
        {/* The status chips, hidden below `sm` as one group. `contents` keeps
            the wrapper out of the flex row, so each chip is spaced by the
            bar's own gap and an item that renders nothing leaves no gap. */}
        <div className="hidden sm:contents">
          <StatusItems />
          <StatusChip
            tone={hubConnected ? 'ok' : 'neutral'}
            label={hubConnected ? __('Hub connected') : __('Connect Hub')}
            icon={<Cloud size={14} aria-hidden="true" />}
            onClick={onConnectHub}
          />
        </div>
        <button
          type="button"
          onClick={onToggleTheme}
          aria-label={theme === 'dark' ? __('Switch to light mode') : __('Switch to dark mode')}
          className="inline-flex items-center justify-center w-8 h-8 rounded-control text-ink-soft hover:text-ink hover:bg-panel transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
          {theme === 'dark' ? <Sun size={16} aria-hidden="true" /> : <Moon size={16} aria-hidden="true" />}
        </button>
      </div>
    </header>
  );
}

/**
 * Add-on status chips (DESIGN.md §24.48), in registration order. Each sits in
 * its own boundary: TopBar is on every screen, so an item that throws must
 * cost only itself.
 */
function StatusItems() {
  const items = useStatusItems();
  return (
    <>
      {items.map(({ id, Component, generation }) => (
        <StatusItemBoundary key={`${id}:${generation}`} id={id}>
          <Component />
        </StatusItemBoundary>
      ))}
    </>
  );
}

/**
 * Purge all, and for a moment afterwards what it did: a check and "Cleared",
 * or the amber callout colours and "Partly cleared" when a store refused. The
 * toast carries the detail; the button only answers "did my click work". The
 * button keeps its natural width — the result label shows for only a moment,
 * so a brief nudge of the bar beats an oversized button at rest.
 */
function PurgeButton({
  onPurge,
  purging,
  outcome,
}: {
  onPurge: () => void;
  purging: boolean;
  outcome: PurgeOutcome | null;
}) {
  const warn = outcome === 'partial' || outcome === 'failed';
  const label =
    outcome === 'cleared'
      ? __('Cleared')
      : outcome === 'empty'
        ? __('Already empty')
        : outcome === 'partial'
          ? __('Partly cleared')
          : outcome === 'failed'
            ? __('Purge failed')
            : __('Purge all');
  const tone = warn
    ? 'border-amber-200 dark:border-amber-900/60 bg-amber-50 dark:bg-amber-950/40 text-amber-900 dark:text-amber-100'
    : 'border-transparent bg-primary text-on-primary shadow-primary-glow hover:bg-primary-hover';

  return (
    <button
      type="button"
      onClick={onPurge}
      disabled={purging}
      aria-busy={purging || undefined}
      className={`inline-flex items-center justify-center gap-2 rounded-control border px-3 py-1.5 text-[13px] font-medium transition-colors disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 ${tone}`}
    >
      {outcome === null ? (
        <RefreshCw size={14} className={purging ? 'xspeed-anim-spin animate-spin' : ''} aria-hidden="true" />
      ) : warn ? (
        <AlertTriangle
          key={outcome}
          size={14}
          className="xspeed-anim-pop text-amber-600 dark:text-amber-400"
          aria-hidden="true"
        />
      ) : (
        <Check key={outcome} size={14} className="xspeed-anim-pop" aria-hidden="true" />
      )}
      {label}
    </button>
  );
}
