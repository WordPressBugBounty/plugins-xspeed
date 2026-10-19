import { useEffect, useLayoutEffect, useRef, useState, type ReactElement } from 'react';
import { LayoutDashboard, ArrowUpRight, Lock, KeyRound, BadgeCheck, ChevronsLeft, X } from 'lucide-react';
import { ModuleIcon } from './ModuleIcon';
import { Tooltip } from './Tooltip';
import { SIDEBAR_GROUPS } from './sidebarGroups';
import { OVERVIEW_SLUG } from './overview/constants';
import { __ } from '../i18n';
import { useBranding } from '../hooks/useBranding';
import { useProState } from '../upsell/useTier';
import { BrandLogo } from './BrandLogo';
import { RailFormProvider, RailItemBoundary, railFormFor, useRailItems, type RailForm } from './railItems';
import { CFE_RAIL_ITEM_ID, CfeRailOffer } from './CfeRailOffer';
import type { ModuleDescriptor } from '../types';

/**
 * Redesign v2 sidebar — a deep-indigo gradient rail. Overview is pinned at the
 * top (default route); below it the 7 IA groups render as FLAT links (no module
 * children — clicking a group opens its landing page). Active item gets the
 * solid primary fill + glow. An "Upgrade" entry is pinned at the bottom.
 *
 * Search moved out of the rail into the top-bar ⌘K command palette
 * (CommandPalette), so the rail is now purely navigation. The old multi-expand
 * accordion (useSidebarGroups) is retired — groups are landing pages now.
 *
 * Three widths, one component (DESIGN.md §24.34):
 *
 *   - **Expanded** (default, >=768px) — 15rem, icons + labels.
 *   - **Collapsed** (>=768px, user preference) — 4.5rem, icons only, labels
 *     revealed as hover tooltips. Persisted, because it is a workspace
 *     preference rather than view state.
 *   - **Drawer** (<768px) — off-canvas, opened from the top bar's menu button
 *     and closed on navigate. Below that width a 15rem fixed rail left a
 *     ~110px content column on a 375px screen, which is not a narrow layout
 *     but an unusable one.
 *
 * The collapse control is centred on the rail's trailing edge and only
 * materialises on hover / keyboard focus, so the rail stays quiet until the
 * user reaches for it.
 */
const DESKTOP_QUERY = '(min-width: 768px)';
/** Below this the add-on card is never bigger than a one-line row (§24.49). */
const WIDE_QUERY = '(min-width: 1024px)';

/**
 * Tracks a media query. Defaults to true so a first paint without
 * matchMedia lays out as a wide desktop.
 */
function useMedia(query: string): boolean {
  const [matches, setMatches] = useState(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return true;
    return window.matchMedia(query).matches;
  });
  useEffect(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return;
    const mq = window.matchMedia(query);
    const sync = () => setMatches(mq.matches);
    sync();
    // Safari < 14 only has the deprecated addListener.
    if (typeof mq.addEventListener === 'function') {
      mq.addEventListener('change', sync);
      return () => mq.removeEventListener('change', sync);
    }
    mq.addListener?.(sync);
    return () => mq.removeListener?.(sync);
  }, [query]);
  return matches;
}

/**
 * The rail's own height, which picks the add-on card's form. The rail's
 * height is set by the dashboard frame, never by its content, so a card
 * changing form cannot feed back into the measurement.
 */
function useHeight(ref: { current: HTMLElement | null }): number {
  const [height, setHeight] = useState(Number.POSITIVE_INFINITY);
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    // 0 means not laid out yet (or hidden), not a 0px rail: keep the last
    // real height rather than dropping the card to its smallest form.
    const measure = (h: number) => {
      if (h > 0) setHeight(h);
    };
    measure(el.getBoundingClientRect().height);
    if (typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver((entries) => {
      const entry = entries[entries.length - 1];
      if (entry) measure(entry.contentRect.height);
    });
    ro.observe(el);
    return () => ro.disconnect();
  }, [ref]);
  return height;
}

/** The add-on column's frame for each card form, matching the card's own spacing (§24.49). */
const RAIL_CARD_COLUMN: Record<RailForm, string> = {
  full: 'px-3 pb-3',
  compact: 'px-3 pb-3 pt-2',
  row: 'px-3 py-2 border-t',
  icon: 'px-3 py-3',
};

export function Sidebar({
  activeGroup,
  onNavigate,
  onUpgrade,
  onManageLicense,
  modules = [],
  collapsed = false,
  onToggleCollapse,
  mobileOpen = false,
  onCloseMobile,
}: {
  /** 'overview' or a group id. */
  activeGroup: string;
  /** Navigate to Overview or a group landing. */
  onNavigate: (target: string) => void;
  onUpgrade: () => void;
  /** Open the License page — the single home for license management. */
  onManageLicense: () => void;
  modules?: ModuleDescriptor[];
  /** Icons-only rail. Ignored below `md`, where the drawer governs instead. */
  collapsed?: boolean;
  onToggleCollapse?: () => void;
  /** Drawer visibility below `md`. */
  mobileOpen?: boolean;
  onCloseMobile?: () => void;
}) {
  const branding = useBranding();
  const proState = useProState();
  const railItems = useRailItems();

  /*
   * Tracks Tailwind's `md` breakpoint, because one thing genuinely cannot be
   * expressed in CSS here: `inert` is an attribute, not a style, so "the
   * drawer is closed AND we are below md" has to be known in JS.
   *
   * Defaults to true (desktop) so a server-rendered or first paint never
   * marks a permanent rail inert.
   */
  const isDesktop = useMedia(DESKTOP_QUERY);
  const isWide = useMedia(WIDE_QUERY);
  const asideRef = useRef<HTMLElement>(null);
  const railHeight = useHeight(asideRef);
  const railForm = railFormFor({ height: railHeight, collapsed, desktop: isDesktop, wide: isWide });

  // Escape closes the drawer — the same dismissal contract every other
  // overlay in the dashboard honours.
  useEffect(() => {
    if (!mobileOpen || !onCloseMobile) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onCloseMobile();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [mobileOpen, onCloseMobile]);

  /*
   * Muted "N on" badge per group.
   *
   * Reads the descriptor's `active`, which each module computes server-side
   * from whatever its real state is. This used to read `settings.enabled`
   * directly, which only exists on some modules -- so page caching (its
   * switch is the global `cache_enabled`), Minify and Lazy (on when any flag
   * is set) and MCP (on when connected) were all invisible to the count. A
   * site running all four read "Cache 2 / Optimization 1" and left the
   * plugin's headline feature out of its own badge. (#363)
   *
   * `active === null` means the module has no meaningful on/off, so it is
   * excluded rather than counted as off.
   */
  const onCount = (slugs: string[]): number =>
    slugs.reduce((n, slug) => {
      const m = modules.find((mod) => mod.slug === slug);
      return n + (m?.active === true ? 1 : 0);
    }, 0);

  /*
   * How many modules in the group have a real on/off state at all — the
   * denominator behind the badge. Modules reporting `active: null` (status
   * panels like Health) are excluded, exactly as they are from the count,
   * so "3 of 4" never counts something that can't be switched.
   *
   * This is the same pair the group's landing page prints as "N of M on",
   * which is what the badge navigates to. Keeping the two in step is the
   * point: a bare "3" reads as a quantity of *something* unspecified, and
   * the tooltip is what names the unit as modules rather than settings.
   */
  /** Slugs this install actually has, whatever tier they are. */
  const installed = new Set(modules.map((m) => m.slug));

  const stateful = (slugs: string[]): number =>
    slugs.reduce((n, slug) => {
      const m = modules.find((mod) => mod.slug === slug);
      return n + (typeof m?.active === 'boolean' ? 1 : 0);
    }, 0);

  // `min-h-9` pins the row to its expanded height (36px). Padding alone does
  // not: an expanded row is sized by the 13.5px label's line-height, and a
  // collapsed one only by the 16px icon, so hiding the text shrank every row
  // by 4px and the whole rail re-flowed vertically on toggle. Only the width
  // should change.
  /*
   * The rows are STATE-INVARIANT. Same width (`w-full`), same padding
   * (`px-3`), same alignment, collapsed or not — so there is nothing for the
   * browser to re-lay-out when `collapsed` flips, and the icon sits at the
   * same x on every frame while the rail's width animates around it.
   *
   * Everything tried before this moved, and measurement is what proved it:
   *   - `md:w-10` → `w-full` snapped the row 40px → 216px on frame one.
   *   - `md:mx-auto` flipped centring to left-alignment, sliding the icon.
   *   - Animating the padding instead still moved it 36px → 24px, and worse,
   *     rows resolved their padding at slightly different times: mid-flight
   *     the icons measured 31, 28, 27, 26, 25 — the column visibly tearing
   *     apart, which is the "items shipping" this exists to stop.
   *
   * The rail is 64px collapsed and the row keeps `px-3`, so the icon is at
   * x=24 in both states. Not the optical centre of 64px, but identical in
   * both — and holding still beats being centred, because the eye reads
   * movement long before it reads a few pixels of asymmetry.
   */
  const railItem =
    'group flex items-center gap-3 w-full min-h-9 overflow-hidden whitespace-nowrap rounded-control px-3 py-2 text-[13.5px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/40';

  /*
   * Collapsed rows are icon-only, so the label has to come back on hover.
   * Uses the shared Tooltip (DESIGN.md §24.4) rather than a native `title`:
   * `title` waits ~1s, renders in the OS style, and never appears for a
   * keyboard user tabbing the rail. Tooltip is 200ms, themed, and fires on
   * focus too. It also positions to the RIGHT via position:fixed, which is
   * what lets it escape the <nav>'s overflow-auto — a native tip was being
   * clipped against the rail edge and truncating ("Optimizati…").
   *
   * `disabled` when expanded: the label is already on screen, so a tooltip
   * repeating it would be noise.
   */
  const withTip = (label: string, node: ReactElement) => (
    <Tooltip label={label} disabled={!collapsed}>
      {node}
    </Tooltip>
  );

  // The tier slot follows the same geometry contract as a nav row: pinned
  // height, clipped label, centred square when collapsed. It was the most
  // visibly broken thing mid-transition because it carries both a label and
  // a trailing arrow, and neither had anywhere to go in a 64px rail.
  const tierItem =
    'flex items-center gap-2.5 w-full min-h-9 overflow-hidden whitespace-nowrap rounded-control px-3 py-2 text-[13px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/40';

  // Navigating from the drawer must close it; on desktop there is nothing to
  // close. One handler so no call site can forget half of it.
  //
  // `goTo` is the same contract for targets that are not sidebar groups --
  // the tier slot's license/upgrade routes. They used to call their callbacks
  // directly, which loaded the destination underneath a drawer and scrim that
  // stayed open over it.
  const go = (target: string) => {
    onNavigate(target);
    onCloseMobile?.();
  };

  const goTo = (run: () => void) => () => {
    run();
    onCloseMobile?.();
  };

  return (
    <>
      {/* Scrim — drawer only. Clicking outside is the expected way out, and
          it must not exist on desktop where the rail is permanent. */}
      {mobileOpen && (
        <button
          type="button"
          aria-label={__('Close navigation')}
          onClick={onCloseMobile}
          className="md:hidden fixed inset-x-0 bottom-0 top-[var(--wp-admin--admin-bar--height,46px)] z-40 bg-black/50"
        />
      )}
      <aside
        /*
         * `inert` when the drawer is closed BELOW md. Translating it
         * off-screen hides it visually but leaves every control tabbable, so
         * keyboard focus walked into an invisible drawer and screen readers
         * announced navigation for a panel that was not open. `inert` removes
         * the subtree from focus and the a11y tree in one attribute.
         *
         * Driven by a media query rather than `mobileOpen` alone: above md
         * there is no drawer, the rail is permanent, and marking it inert
         * would disable the whole sidebar.
         */
        {...(!isDesktop && !mobileOpen ? { inert: '' } : {})}
        ref={asideRef}
        className={`group/rail relative shrink-0 flex flex-col text-rail-text transition-[width] duration-200
          max-md:fixed max-md:top-[var(--wp-admin--admin-bar--height,46px)] max-md:bottom-0 max-md:left-0 max-md:z-50 max-md:w-60 max-md:transition-transform
          ${mobileOpen ? 'max-md:translate-x-0' : 'max-md:-translate-x-full'}
          ${collapsed ? 'md:w-16' : 'md:w-60'}`}
        style={{ background: 'linear-gradient(180deg, var(--rail-top), var(--rail-bottom))' }}
      >
      {/* Brand */}
      <div
        className="h-14 shrink-0 flex items-center gap-2.5 overflow-hidden whitespace-nowrap px-4 border-b"
        style={{ borderColor: 'var(--rail-edge)' }}
      >
        {/* onDark: the rail is dark in BOTH themes (DESIGN.md §2.4), so the
            mark must invert unconditionally rather than on the theme. */}
        <BrandLogo className="w-6 h-6 rounded shrink-0" onDark />
        <span
          className={`font-display font-semibold text-[15px] text-white truncate transition-opacity duration-200 ${
            collapsed ? 'md:opacity-0' : 'opacity-100'
          }`}
        >
          {branding.name}
        </span>
        {/* Drawer needs its own way out. The scrim closes it, but a scrim is
            a discovered affordance, not an announced one — and on a phone the
            rail covers most of the screen, so "how do I get rid of this" must
            have a visible answer. Desktop has no drawer, hence md:hidden. */}
        {onCloseMobile && (
          <button
            type="button"
            onClick={onCloseMobile}
            aria-label={__('Close navigation')}
            className="md:hidden ml-auto shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-control text-rail-text hover:text-white hover:bg-white/[.06] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/40"
          >
            <X size={16} aria-hidden="true" />
          </button>
        )}
      </div>

      {/* Collapse control — centred on the trailing edge, revealed on hover or
          keyboard focus. Desktop only: below `md` the drawer is the mechanism
          and a collapse toggle there would fight it.

          Rendered always (not conditionally) so focus-visible can reveal it —
          a control that only exists on :hover is unreachable by keyboard. */}
      {onToggleCollapse && (
        <button
          type="button"
          onClick={onToggleCollapse}
          aria-label={collapsed ? __('Expand sidebar') : __('Collapse sidebar')}
          aria-expanded={!collapsed}
          /* `!shadow-md`: preflight is off, so WordPress's
             `.wp-core-ui button { box-shadow: none }` outranks the utility
             on specificity and the chip renders flat without it. */
          title={collapsed ? __('Expand sidebar') : __('Collapse sidebar')}
          className="hidden md:flex absolute top-1/2 -right-3 z-10 -translate-y-1/2 w-6 h-6 items-center justify-center rounded-full border border-line bg-paper text-ink-soft !shadow-md opacity-0 pointer-events-none transition-opacity duration-150 hover:text-ink group-hover/rail:opacity-100 group-hover/rail:pointer-events-auto focus-visible:opacity-100 focus-visible:pointer-events-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
          <ChevronsLeft
            size={14}
            aria-hidden="true"
            className={`transition-transform duration-200 ${collapsed ? 'rotate-180' : ''}`}
          />
        </button>
      )}

      <nav aria-label={__('Primary')} className="flex-1 min-h-0 overflow-y-auto p-3 space-y-1">
        {/* Overview — pinned default route */}
        {withTip(
          __('Overview'),
          <button
            type="button"
            onClick={() => go(OVERVIEW_SLUG)}
            aria-current={activeGroup === OVERVIEW_SLUG ? 'page' : undefined}
            className={
              activeGroup === OVERVIEW_SLUG
                ? `${railItem} bg-primary text-on-primary shadow-primary-glow`
                : `${railItem} text-rail-text hover:text-white hover:bg-white/[.06]`
            }
          >
            <LayoutDashboard size={16} className="shrink-0" aria-hidden="true" />
            <span
              className={`flex-1 text-left truncate transition-opacity duration-200 ${
                collapsed ? 'md:opacity-0' : 'opacity-100'
              }`}
            >
              {__('Overview')}
            </span>
          </button>
        )}

        <div className="pt-2" />

        {/*
          * A group whose modules are none of them installed is not drawn.
          *
          * `SIDEBAR_GROUPS` is the curated superset — it names Pro slugs so
          * the order never re-sorts when Pro activates — and every other
          * group has at least one Free module in it, so this changed nothing
          * until a group arrived with no Free module at all. The old top-level
          * Cloudflare Enterprise group was that group (since folded into
          * Network): on a Free-only install it drew a row leading to an empty
          * landing. The guard stays for the next group of Pro-only slugs.
          *
          * Presence, not enablement: a module that is installed but switched
          * off still belongs in the rail, which is where you go to switch it
          * on. `groupSidebarModules` already reasons this way for the feature
          * map; the rail was drawing from the raw list.
          */}
        {SIDEBAR_GROUPS.filter((g) => g.slugs.some((slug) => installed.has(slug))).map((g) => {
          const active = activeGroup === g.id;
          const count = onCount(g.slugs);
          const total = stateful(g.slugs);
          const countHint =
            count > 0
              ? total === 1
                ? `${count} ${__('of')} ${total} ${__('module on')}`
                : `${count} ${__('of')} ${total} ${__('modules on')}`
              : undefined;
          return (
            // Collapsed, the badge is hidden with the label, so the count
            // rides along as the tooltip's hint line rather than being lost.
            <Tooltip key={g.id} label={g.label} hint={countHint} disabled={!collapsed}>
            <button
              type="button"
              onClick={() => go(g.id)}
              aria-current={active ? 'page' : undefined}
              className={
                active
                  ? `${railItem} bg-primary text-on-primary shadow-primary-glow`
                  : `${railItem} text-rail-text hover:text-white hover:bg-white/[.06]`
              }
            >
              <ModuleIcon name={g.icon} size={16} className="shrink-0" />
              <span
              className={`flex-1 text-left truncate transition-opacity duration-200 ${
                collapsed ? 'md:opacity-0' : 'opacity-100'
              }`}
            >
                {g.label}
              </span>
              {count > 0 && (
                <span
                  className={`shrink-0 text-[11px] tabular-nums transition-opacity duration-200 ${
                    collapsed ? 'md:opacity-0' : 'opacity-100'
                  } ${active ? 'text-on-primary' : 'text-rail-text'}`}
                  /* Names the unit. On its own the number could be read as
                     settings rather than modules — the group has ~4 modules
                     but dozens of settings, so the two readings differ a lot
                     (Optimization is "4 modules" or "9 settings"). */
                  title={
                    total === 1
                      ? `${count} ${__('of')} ${total} ${__('module on')}`
                      : `${count} ${__('of')} ${total} ${__('modules on')}`
                  }
                >
                  {count}
                </span>
              )}
            </button>
            </Tooltip>
          );
        })}
      </nav>

      {/* Add-on cards (§24.49). Each card draws itself in the form the rail
          has room for (`railForm`): full, compact, a one-line row, or an icon
          in the collapsed rail. The column never shrinks; the nav above it
          does, and scrolls, so a card never covers a nav row or the tier
          slot. The column hides itself when no child has content, the
          normal case: an add-on may wrap its card in an element of its own,
          so plain `:empty` would never match and leave a blank band.
          The Cloudflare Enterprise offer is Free's own and the only one drawn:
          it has to reach sites where no add-on code is loaded, and sites with
          a Pro too old to have a card of its own. A card the add-on registers
          under the same id is skipped, so a site never shows two. */}
      <div
        data-rail-form={railForm}
        className={`relative shrink-0 flex flex-col gap-2 ${RAIL_CARD_COLUMN[railForm]} [&:not(:has(>:not(:empty)))]:hidden`}
        style={railForm === 'row' ? { borderColor: 'var(--rail-edge)' } : undefined}
      >
        <RailFormProvider value={railForm}>
          <CfeRailOffer modules={modules} />
          {railItems.filter(({ id }) => id !== CFE_RAIL_ITEM_ID).map(({ id, Component, generation }) => (
            <RailItemBoundary key={`${id}:${generation}`} id={id}>
              <Component />
            </RailItemBoundary>
          ))}
        </RailFormProvider>
      </div>

      {/* Tier slot — pinned bottom. A persistent, state-aware row rather than
          an Upgrade button that vanishes: the slot keeps its place in the
          layout, and every state has one clear destination.

          The three states are NOT cosmetic variants of a CTA. Only
          `not_installed` is selling anything; `unlicensed` is a paid customer
          mid-setup (showing them "Upgrade to Pro" was both wrong and a little
          insulting), and `active` is pure status. Weight drops accordingly —
          filled → filled → borderless — so the licensed state reads as a
          label that happens to be clickable, not as an ad. */}
      <div className="p-3 border-t" style={{ borderColor: 'var(--rail-edge)' }}>
        {proState === 'active' ? (
          <Tooltip label={__('Pro Licensed')} hint={__('Manage your license')} disabled={!collapsed}>
          <button
            type="button"
            onClick={goTo(onManageLicense)}
            /* Expanded only: the label already reads "Pro Licensed", so the
               title carries what it does NOT say. Collapsed, the Tooltip
               above takes over and this would double up. */
            title={collapsed ? undefined : __('Manage your license')}
            className={`${tierItem} text-rail-text hover:text-white hover:bg-white/[.06]`}
          >
            <BadgeCheck size={15} className="shrink-0 text-success" aria-hidden="true" />
            <span
              className={`flex-1 text-left transition-opacity duration-200 ${
                collapsed ? 'md:opacity-0' : 'opacity-100'
              }`}
            >
              {__('Pro Licensed')}
            </span>
            <ArrowUpRight
              size={14}
              className={`shrink-0 transition-opacity duration-200 ${
                collapsed ? 'md:opacity-0' : 'opacity-50'
              }`}
              aria-hidden="true"
            />
          </button>
          </Tooltip>
        ) : (
          <Tooltip
            label={proState === 'unlicensed' ? __('Activate License') : __('Upgrade to Pro')}
            disabled={!collapsed}
          >
          <button
            type="button"
            onClick={goTo(proState === 'unlicensed' ? onManageLicense : onUpgrade)}
            className={`${tierItem} text-white bg-white/[.06] hover:bg-white/[.1]`}
          >
            {/* The Pro badge glyphs (§24.10) — lock to upgrade, key to
                activate — in the rail's primary teal. `text-on-tint` is for
                light tints and is too dark on the deep-sea rail. */}
            {proState === 'unlicensed' ? (
              <KeyRound size={15} className="shrink-0 text-primary" aria-hidden="true" />
            ) : (
              <Lock size={15} className="shrink-0 text-primary" aria-hidden="true" />
            )}
            <span
              className={`flex-1 text-left transition-opacity duration-200 ${
                collapsed ? 'md:opacity-0' : 'opacity-100'
              }`}
            >
              {proState === 'unlicensed' ? __('Activate License') : __('Upgrade to Pro')}
            </span>
            <ArrowUpRight
              size={14}
              className={`shrink-0 transition-opacity duration-200 ${
                collapsed ? 'md:opacity-0' : 'opacity-70'
              }`}
              aria-hidden="true"
            />
          </button>
          </Tooltip>
        )}
      </div>
      </aside>
    </>
  );
}
