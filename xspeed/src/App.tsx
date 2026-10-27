import { useEffect, useState, useCallback, useMemo, useRef } from 'react';
import { Sidebar } from './components/Sidebar';
import { useSidebarCollapse } from './hooks/useSidebarCollapse';
import { TopBar, type Crumb } from './components/TopBar';
import { GroupLanding } from './components/GroupLanding';
import { ModuleHeader } from './components/ModuleHeader';
import { useModuleHeaderVisible } from './components/proPanels';
import { LogsView } from './components/LogsView';
import { BrandSkeleton } from './components/BrandSkeleton';
import { Toast } from './components/Toast';
import { purgeNotice, type PurgeOutcome } from './components/purgeOutcome';
import { ModulePanel } from './components/ModulePanel';
import { moduleWithSettingsOverride, refreshSettingsOverrides, type ModuleSettingsOverride } from './components/moduleSettingsOverride';
import { RewriteAlert } from './components/RewriteAlert';
import { MobileSeparateAlert } from './components/MobileSeparateAlert';
import { NginxServerBlock } from './components/NginxServerBlock';
import { CacheHero } from './components/cache/CacheHero';
import { ImpactHero } from './components/cache/ImpactHero';
import { NextBestAction } from './components/cache/NextBestAction';
import { CacheBenchmark } from './components/cache/CacheBenchmark';
import { TrendCharts } from './components/cache/TrendCharts';
import { ProAudit } from './upsell/ProAudit';
import { ProOverview } from './upsell/ProOverview';
import { Overview } from './components/overview/Overview';
import { OVERVIEW_SLUG } from './components/overview/constants';
import { __, _n, sprintf } from './i18n';
import { SearchQueryProvider, SearchHighlighter, type SearchResult } from './components/SettingsSearch';
import { CommandPalette } from './components/CommandPalette';
import { requestFieldScroll } from './components/scrollToField';
import { splitFocus } from './components/deepLink';
import { setSuggestion } from './components/fieldSuggestion';
import { parseRoute, buildHash, type Route } from './components/nav';
import { groupById, groupIdForSlug } from './components/sidebarGroups';
import { mergeProModules } from './upsell/mergeProModules';
import { useTier } from './upsell/useTier';
import { api } from './api/client';
import { useTheme } from './hooks/useTheme';
import type { Settings, Stats, ModuleDescriptor } from './types';

export function App() {
  const tier = useTier();

  // Live per-module settings overrides — keeps optimistic saves visible when
  // navigating away and back (see the reset-on-mount panel effect).
  const [settingsOverrides, setSettingsOverrides] = useState<Record<string, ModuleSettingsOverride>>({});

  const [baseModules, setBaseModules] = useState<ModuleDescriptor[]>(
    () => window.XSpeedConfig?.bootstrap?.modules ?? []
  );

  const refreshModules = useCallback((fresh: ModuleDescriptor[]) => {
    setSettingsOverrides((previous) => refreshSettingsOverrides(fresh, previous));
    setBaseModules(fresh);
  }, []);

  // Refresh descriptors when Pro fires xspeed:tier-changed (license flip) or
  // xspeed:modules-changed (an add-on license or a module's state changed).
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const handler = () => {
      api.getModules().then(refreshModules).catch(() => undefined);
    };
    window.addEventListener('xspeed:tier-changed', handler);
    window.addEventListener('xspeed:modules-changed', handler);
    return () => {
      window.removeEventListener('xspeed:tier-changed', handler);
      window.removeEventListener('xspeed:modules-changed', handler);
    };
  }, [refreshModules]);

  const modules: ModuleDescriptor[] = useMemo(() => {
    const base = mergeProModules(baseModules, tier);
    return base.map((m) =>
      moduleWithSettingsOverride(m, settingsOverrides[m.slug])
    );
  }, [tier, settingsOverrides, baseModules]);

  const onModuleSaved = useCallback((slug: string, saved: Record<string, unknown>) => {
    setSettingsOverrides((prev) => ({
      ...prev,
      [slug]: {
        values: saved,
        origins: baseModules.find((m) => m.slug === slug)?.setting_origins ?? {},
      },
    }));

    /*
     * Re-read the descriptors so the on/off surfaces update without a
     * reload. The override above patches `settings`, but every count and
     * pill now reads `active` (#363), which the server computes -- page
     * caching's switch is a global option, Minify's is "any flag on" -- so
     * it is NOT derivable from the settings echo the save returns.
     *
     * Recomputing it client-side would duplicate that per-module logic in
     * the UI and drift the moment a module changes shape, which is the
     * exact bug #363 fixed. Asking the server keeps one source of truth;
     * the cost is one small GET per save.
     *
     * Failure is silent on purpose: the save itself already succeeded and
     * the panel has reported that. A refresh that fails leaves the badge
     * stale until the next save or reload -- the pre-existing behaviour,
     * not a regression, and not worth an error toast over a save that
     * actually landed.
     */
    api.getModules().then(refreshModules).catch(() => undefined);
    if (slug === 'white-label') {
      api.getBranding()
        .then((branding) => {
          const xp = (window as unknown as { XSpeedPro?: { setBranding?: (b: typeof branding) => void } }).XSpeedPro;
          xp?.setBranding?.(branding);
        })
        .catch(() => undefined);
    }
  }, [baseModules, refreshModules]);

  /**
   * Honour the focus/suggest half of a deep link (the notice-CTA contract,
   * issue #49) — lands the user ON a control with a recommended value. Runs on
   * mount, on hashchange, and after an in-app CTA nav; kept in one place.
   */
  const applyFocus = useCallback((focus?: string, suggest?: string) => {
    const parts = splitFocus(focus);
    if (!parts) return;
    requestFieldScroll(parts.module, parts.field);
    setSuggestion(suggest === undefined ? null : { module: parts.module, field: parts.field, value: suggest });
  }, []);

  // ---- Routing (redesign v2): #/overview · #/<group> · #/<group>/<module> --
  const [route, setRoute] = useState<Route>(() => parseRoute(window.location.hash));

  // Canonicalize a legacy `#slug` hash on first load + honour any focus link.
  useEffect(() => {
    const initial = parseRoute(window.location.hash);
    if (initial.legacy) {
      const next = buildHash(initial);
      if (window.location.hash !== next) history.replaceState(null, '', next);
    }
    applyFocus(initial.focus, initial.suggest);
    // once
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Reflect route → URL hash without firing hashchange (replaceState).
  useEffect(() => {
    const next = buildHash(route);
    if (typeof window !== 'undefined' && window.location.hash !== next) {
      history.replaceState(null, '', next);
    }
  }, [route]);

  // React to EXTERNAL hash changes (back/forward, WP-admin submenu, notice CTA
  // opened in another tab). replaceState above never triggers this.
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const handler = () => {
      const r = parseRoute(window.location.hash);
      setRoute(r);
      applyFocus(r.focus, r.suggest);
    };
    window.addEventListener('hashchange', handler);
    return () => window.removeEventListener('hashchange', handler);
  }, [applyFocus]);

  const mainRef = useRef<HTMLElement>(null);

  // Keying the scroll reset on page identity means re-clicking the nav row
  // you are already on no longer changes pageKey, so it stopped scrolling to
  // the top — a convention users rely on. Scroll explicitly on the nav
  // action itself; when the row DOES change page, the pageKey effect below
  // would scroll anyway, so this is idempotent rather than a second path.
  // (#207 F3)
  const scrollMainToTop = useCallback(() => {
    mainRef.current?.scrollTo({ top: 0 });
  }, []);
  const navigateGroup = useCallback((groupId: string) => {
    scrollMainToTop();
    if (groupId === OVERVIEW_SLUG) {
      setRoute({ view: 'overview' });
      return;
    }
    /*
     * A leaf group is one module wearing a group's clothes, so clicking it
     * opens the module rather than a landing page listing one card.
     *
     * Resolved here rather than in the sidebar because this is where routes
     * are decided, and a sidebar that knew about leaf groups would be a
     * second place that had to agree about what a group id means.
     */
    const group = groupById(groupId);
    if (group?.leaf && group.slugs.length === 1) {
      setRoute({ view: 'module', group: groupId, module: group.slugs[0]!, subtab: '' });
      return;
    }
    setRoute({ view: 'group', group: groupId });
  }, [scrollMainToTop]);
  const navigateModule = useCallback((slug: string, subtab = '') => {
    const group = groupIdForSlug(slug);
    if (!group) return;
    scrollMainToTop();
    setRoute({ view: 'module', group, module: slug, subtab });
  }, [scrollMainToTop]);
  const navigatePro = useCallback(() => setRoute({ view: 'pro' }), []);

  const activeGroup = route.view === 'overview' ? OVERVIEW_SLUG : route.group ?? OVERVIEW_SLUG;
  const activeModuleSlug = route.view === 'module' ? route.module ?? null : null;
  const subtab = route.view === 'module' ? route.subtab ?? '' : '';
  const activeModule = useMemo(
    () => (activeModuleSlug ? modules.find((m) => m.slug === activeModuleSlug) ?? null : null),
    [modules, activeModuleSlug]
  );
  // A panel that draws its own full-page flow can ask for no page header.
  const moduleHeaderVisible = useModuleHeaderVisible(activeModuleSlug ?? '');

  // Reset scroll when the visited page changes. Keyed on the page identity
  // (view/group/module), not the whole route object: a subtab switch (e.g.
  // the Health tabs) or a focus change is in-page state and must keep the
  // reader's scroll position.
  const pageKey = `${route.view}:${route.group ?? ''}:${route.module ?? ''}`;
  useEffect(() => {
    mainRef.current?.scrollTo({ top: 0 });
  }, [pageKey]);

  const bootstrap = window.XSpeedConfig?.bootstrap;
  const [settings, setSettings] = useState<Settings | null>(bootstrap?.settings ?? null);
  const [stats, setStats] = useState<Stats | null>(bootstrap?.status?.stats ?? null);
  const [rewriteProbe, setRewriteProbe] = useState(bootstrap?.status?.rewrite_probe ?? null);
  const [mobileSeparate, setMobileSeparate] = useState(bootstrap?.status?.mobile_separate ?? null);
  const [nginxServerBlock, setNginxServerBlock] = useState<string | null>(bootstrap?.status?.nginx_server_block ?? null);
  // Leftover advanced-cache.php that enabling would replace. Seeded from the
  // bootstrap so the very first click on the top-bar switch already knows —
  // the one moment this warning exists for is a leftover drop-in about to be
  // overwritten, and waiting for a status fetch is exactly when it would be
  // missing.
  const [cacheDropin, setCacheDropin] = useState(bootstrap?.status?.dropin ?? null);
  const [toast, setToast] = useState<string | null>(null);
  const [purging, setPurging] = useState(false);
  const [purgeOutcome, setPurgeOutcome] = useState<PurgeOutcome | null>(null);
  const purgeOutcomeTimer = useRef<number | null>(null);
  const mountedRef = useRef(true);
  const [preloading, setPreloading] = useState(false);
  const [cacheBusy, setCacheBusy] = useState(false);
  const { collapsed, toggle: toggleCollapse } = useSidebarCollapse();
  const [navOpen, setNavOpen] = useState(false);
  const [hubConnected, setHubConnected] = useState(false);
  const { theme, toggle: toggleTheme } = useTheme();
  // Settings search lives in the ⌘K command palette now (not the sidebar).
  const [paletteOpen, setPaletteOpen] = useState(false);

  const goToResult = useCallback((r: SearchResult) => {
    navigateModule(r.module.slug, r.subtab ?? '');
    if (r.fieldKey) requestFieldScroll(r.fieldModuleSlug ?? r.module.slug, r.fieldKey);
  }, [navigateModule]);

  // ⌘K / Ctrl-K opens the palette. Captured + stopped so WordPress's own admin
  // command palette (if present on the page) can't also fire.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        e.stopPropagation();
        setPaletteOpen((v) => !v);
      }
    };
    window.addEventListener('keydown', onKey, { capture: true });
    return () => window.removeEventListener('keydown', onKey, { capture: true } as EventListenerOptions);
  }, []);

  // Default 2s suits a confirmation ("Cache purged"). A message that asks
  // the user to DO something needs longer than it takes to read it — the
  // wp-config snippet used to vanish in the same two seconds, with no copy
  // button and no way back to it. (#19)
  //
  // `urgent` picks the screen-reader register, not the styling: a refusal
  // interrupts because the user just acted and did not get what they asked
  // for, while a confirmation waits for a pause.
  const toastTimer = useRef<number | null>(null);
  const [toastUrgent, setToastUrgent] = useState(false);
  // `sweep` drains a hairline under a purge result; `toastSeq` replays that
  // hairline for a second purge with the same sentence, without remounting the
  // live region (a region inserted with its text already in it may go unread).
  const [toastSweep, setToastSweep] = useState(false);
  const [toastSeq, setToastSeq] = useState(0);
  const showToast = useCallback((msg: string, ms = 2000, urgent = false, sweep = false) => {
    if (toastTimer.current) window.clearTimeout(toastTimer.current);
    setToastUrgent(urgent);
    setToastSweep(sweep);
    setToastSeq((n) => n + 1);
    setToast(msg);
    toastTimer.current = window.setTimeout(() => {
      setToast(null);
      toastTimer.current = null;
    }, ms);
  }, []);
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      if (toastTimer.current) window.clearTimeout(toastTimer.current);
      if (purgeOutcomeTimer.current) window.clearTimeout(purgeOutcomeTimer.current);
    };
  }, []);

  // Hub-connection state for the top bar chip.
  useEffect(() => {
    api.request<{ attached?: boolean }>('/mcp/hub')
      .then((s) => setHubConnected(s?.attached === true))
      .catch(() => undefined);
  }, []);

  // Background refresh when bootstrap data wasn't injected.
  useEffect(() => {
    if (settings && stats) return;
    Promise.all([api.getSettings(), api.getStatus()])
      .then(([s, status]) => {
        setSettings(s);
        setStats(status.stats);
        setRewriteProbe(status.rewrite_probe ?? null);
        setMobileSeparate(status.mobile_separate ?? null);
        setNginxServerBlock(status.nginx_server_block ?? null);
        setCacheDropin(status.dropin ?? null);
      })
      .catch(() => showToast(__('Failed to load')));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const toggleCache = useCallback(
    async (enabled: boolean) => {
      if (!settings) return;
      // One toggle at a time. The switch now rides every route alongside the
      // Overview hero's own control, so two can be clicked before the first
      // request lands -- and the loser's response would reconcile state the
      // winner had already moved on from.
      if (cacheBusy) return;
      setCacheBusy(true);
      const previous = settings;
      setSettings({ ...settings, cache_enabled: enabled });
      // The serving verdict in `stats` predates the toggle, and the hero
      // compares it against the setting just changed above — left in place,
      // an enable flashes "Cache on, but not serving · Another plugin owns
      // the page cache" for the whole round-trip. Undefined means "no
      // verdict yet": every consumer falls back to the setting until the
      // response below lands with the post-toggle truth.
      setStats((s) =>
        s ? { ...s, page_cache_serving: undefined, page_cache_blocked_reason: null } : s
      );
      try {
        const res = await api.toggleCache(enabled);
        setSettings((s) => (s ? { ...s, cache_enabled: res.enabled } : s));
        // The Cache module's `active` is computed from this global switch,
        // so the rail badge and the Overview pill go stale unless the
        // descriptors are re-read. Uses the server's post-toggle truth
        // (res.enabled), not the requested value: a blocked toggle writes
        // nothing, and the badge must reflect what actually happened. (#363)
        api.getModules().then(setBaseModules).catch(() => undefined);
        setStats(res.stats);
        setNginxServerBlock(res.install_state.nginx_server_block ?? null);
        // The replacement the user just agreed to has happened: the drop-in
        // is ours now, so there is nothing left to disclose. Clearing it from
        // the toggle's own response rather than waiting for the next status
        // fetch is what stops the prompt reappearing on an immediate
        // off-then-on. A refused toggle wrote nothing, so it keeps its state.
        if (!res.blocked && res.install_state.dropin_installed) setCacheDropin(null);
        // The server prints the "page cache is off" notice above the app, and
        // it stayed after the switch turned the cache on until a reload.
        if (res.enabled) document.getElementById('xspeed-cache-off-notice')?.remove();
        if (res.blocked) {
          // Nothing was written. Say why — the toggle snapping back with a
          // generic failure gives the user no idea another cache plugin owns
          // the drop-in.
          showToast(res.blocked_reason ?? __('Cache could not be enabled.'), 10000, true);
        } else if (enabled && !res.install_state.wp_cache_constant && res.install_state.manual_snippet) {
          // Point at the durable copy rather than trying to fit a code
          // line into a toast: Health → WP_CACHE constant now carries the
          // snippet with a copy button, and stays there until it's done.
          showToast(
            __('Cache enabled, but nothing will be cached until WP_CACHE is added to wp-config.php — copy the line from Health.'),
            10000
          );
        } else if (enabled && res.install_state.nginx_snippet) {
          showToast('Cache enabled. nginx detected — re-run the setup wizard to copy the server-block snippet for max speed.');
        } else {
          showToast(enabled ? __('Cache enabled') : __('Cache disabled'));
        }
      } catch {
        setSettings(previous);
        showToast(enabled ? __('Failed to enable cache') : __('Failed to disable cache'));
      } finally {
        setCacheBusy(false);
      }
    },
    [settings, showToast, cacheBusy]
  );

  const purge = useCallback(async (type: string) => {
    if (purging) return;
    if (purgeOutcomeTimer.current) window.clearTimeout(purgeOutcomeTimer.current);
    setPurgeOutcome(null);
    setPurging(true);
    let outcome: PurgeOutcome = 'failed';
    try {
      const res = await api.purge(type);
      setStats(res.stats);
      const notice = purgeNotice(res.report);
      outcome = notice.outcome;
      showToast(notice.message, notice.ms, notice.urgent, notice.outcome === 'cleared');
    } catch (err) {
      const detail = err instanceof Error ? err.message : '';
      /* translators: %s: the error the request returned, e.g. "HTTP 500". */
      showToast(detail ? sprintf(__('Purge failed: %s'), detail) : __('Purge failed'), 10000, true);
    } finally {
      if (mountedRef.current) {
        setPurging(false);
        setPurgeOutcome(outcome);
        purgeOutcomeTimer.current = window.setTimeout(() => {
          setPurgeOutcome(null);
          purgeOutcomeTimer.current = null;
        }, 2000);
      }
    }
  }, [purging, showToast]);

  // CacheHero hands this to onClick, so it must not take the event as a type.
  const purgeAll = useCallback(() => purge('all'), [purge]);

  const preload = useCallback(async () => {
    if (preloading) return;
    setPreloading(true);
    try {
      const state = await api.preload();
      // A crawl that queued nothing is a FAILURE, not a quiet success. The
      // route answers 200 with total: 0 and the reason in sitemap_error when
      // the sitemap is unreachable, and this used to ignore the body entirely
      // and toast "Preload started" regardless. #142 made the zero-queue
      // crawl visible in the CLI and the panel; this is the same fix at the
      // third entry point. (#256)
      const total = Number(state?.total ?? 0);
      if (total > 0) {
        showToast(
          sprintf(
            /* translators: %d: number of pages queued for warming. */
            _n('Preloading %d page', 'Preloading %d pages', total),
            total
          )
        );
        return;
      }
      const reason = typeof state?.sitemap_error === 'string' ? state.sitemap_error.trim() : '';
      showToast(
        reason
          /* translators: %s: why the crawl found nothing, e.g. a sitemap fetch error. */
          ? sprintf(__('Preload found no pages to warm: %s'), reason)
          : __('Preload found no pages to warm'),
        10000,
        true
      );
    } catch (err) {
      // The server's own message names the fix — a fresh install answers 409
      // "Enable the preloader before starting a crawl." Discarding it for a
      // generic string was the whole of defect 2.
      const detail = err instanceof Error ? err.message : '';
      showToast(
        detail
          /* translators: %s: the reason the request failed. */
          ? sprintf(__('Preload could not start: %s'), detail)
          : __('Preload could not start'),
        10000,
        true
      );
    } finally {
      if (mountedRef.current) setPreloading(false);
    }
  }, [preloading, showToast]);

  const refreshStatus = useCallback(async () => {
    try {
      const status = await api.getStatus();
      setStats(status.stats);
      setRewriteProbe(status.rewrite_probe ?? null);
      setMobileSeparate(status.mobile_separate ?? null);
      setNginxServerBlock(status.nginx_server_block ?? null);
      setCacheDropin(status.dropin ?? null);
    } catch {
      // Non-fatal.
    }
  }, []);

  // Mirror the hash of the snippet the user just copied to their user meta, so
  // the "can't verify" verdict still has evidence on a second browser where
  // localStorage knows nothing. Fire-and-forget: nothing on screen depends on
  // it, and a user who copied the block should never see an error for it.
  const mirrorCopiedHash = useCallback((hash: string) => {
    api
      .request('/cache/nginx-copied-hash', {
        method: 'POST',
        body: JSON.stringify({ hash }),
      })
      .catch(() => {});
  }, []);

  // Connect Hub (top bar chip). Hub now lives under AI & agents (the mcp host
  // page), relocated off Settings in PR 4.
  const connectHub = useCallback(() => navigateModule('mcp'), [navigateModule]);

  // Breadcrumb for the top bar.
  const crumb: Crumb = useMemo(() => {
    if (route.view === 'overview') return { current: __('Overview') };
    if (route.view === 'group') return { current: groupById(route.group ?? '')?.label ?? '' };
    // module (or logs)
    const group = groupById(route.group ?? '');
    const current =
      route.module === 'logs' ? __('Logs') : activeModule?.label ?? route.module ?? '';
    return {
      backLabel: group?.label,
      onBack: () => navigateGroup(route.group ?? OVERVIEW_SLUG),
      current,
    };
  }, [route, activeModule, navigateGroup]);

  const sidebar = (
    <Sidebar
      activeGroup={activeGroup}
      onNavigate={navigateGroup}
      onUpgrade={navigatePro}
      onManageLicense={() => navigateModule('license')}
      modules={modules}
      collapsed={collapsed}
      onToggleCollapse={toggleCollapse}
      mobileOpen={navOpen}
      onCloseMobile={() => setNavOpen(false)}
    />
  );

  const topBar = (
    <TopBar
      crumb={crumb}
      onOpenSearch={() => setPaletteOpen(true)}
      onOpenNav={() => setNavOpen(true)}
      onPurge={purge}
      onToggleCache={toggleCache}
      cacheDropin={cacheDropin}
      onConnectHub={connectHub}
      purging={purging}
      purgeOutcome={purgeOutcome}
      cacheEnabled={settings?.cache_enabled ?? false}
      cacheBusy={cacheBusy}
      cacheReady={!!settings}
      hubConnected={hubConnected}
      theme={theme}
      onToggleTheme={toggleTheme}
    />
  );

  if (!settings || !stats) {
    return (
      <SearchQueryProvider query="">
        {sidebar}
        <div className="flex-1 flex flex-col min-w-0">
          {topBar}
          <main className="flex-1 flex bg-canvas">
            <BrandSkeleton />
          </main>
        </div>
      </SearchQueryProvider>
    );
  }

  // Resolve module view → content (falls back to the group landing if the
  // slug doesn't resolve, so a stale/bad hash never blanks the screen).
  const moduleFallback = route.view === 'module' && route.module !== 'logs' && !activeModule;

  return (
    <SearchQueryProvider query="">
      {sidebar}
      <div className="flex-1 flex flex-col min-w-0">
        {topBar}
        <main ref={mainRef} className="flex-1 overflow-auto bg-canvas">
          <SearchHighlighter query="">
            <div className="px-4 sm:px-6 lg:px-8 py-5 sm:py-6">
              {route.view === 'overview' && (
                <Overview
                  settings={settings}
                  stats={stats}
                  rewriteProbe={rewriteProbe}
                  modules={modules}
                  onNavigate={navigateModule}
                  showToast={showToast}
                />
              )}

              {route.view === 'pro' && <ProOverview />}

              {(route.view === 'group' || moduleFallback) && (
                <GroupLanding
                  groupId={route.group ?? 'cache'}
                  modules={modules}
                  onOpen={navigateModule}
                />
              )}

              {route.view === 'module' && route.module === 'logs' && <LogsView />}

              {route.view === 'module' && activeModule && (
                <div className="space-y-6">
                  {moduleHeaderVisible && (
                    <ModuleHeader module={activeModule} eyebrow={groupById(route.group ?? '')?.label} />
                  )}
                  {activeModule.slug === 'cache' && (
                    <>
                      <RewriteAlert probe={rewriteProbe} onOpenHealth={() => navigateModule('health')} />
                      <MobileSeparateAlert mobile={mobileSeparate} onOpenCacheSettings={() => navigateModule('cache')} />
                      <ImpactHero stats={stats} />
                      <NextBestAction />
                      <CacheHero settings={settings} stats={stats} onToggle={toggleCache} onPurge={purgeAll} dropin={cacheDropin} />
                      <NginxServerBlock
                        snippet={nginxServerBlock}
                        topology={rewriteProbe?.topology}
                        rules={rewriteProbe?.rules ?? null}
                        focus={route.focus}
                        onCopiedHash={mirrorCopiedHash}
                      />
                      <ProAudit />
                      <CacheBenchmark />
                      <TrendCharts />
                    </>
                  )}
                  <ModulePanel
                    module={activeModule}
                    subtab={subtab}
                    onSubtabChange={(st) =>
                      setRoute((r) => (r.view === 'module' ? { ...r, subtab: st } : r))
                    }
                    getModule={(slug) => modules.find((m) => m.slug === slug)}
                    onSaved={(saved) => {
                      onModuleSaved(activeModule.slug, saved);
                      refreshStatus();
                    }}
                  />
                </div>
              )}
            </div>
          </SearchHighlighter>
        </main>
      </div>
      {toast && <Toast message={toast} urgent={toastUrgent} sweep={toastSweep} sweepKey={toastSeq} />}
      <div id="xspeed-toast-host" />
      <CommandPalette
        open={paletteOpen}
        onClose={() => setPaletteOpen(false)}
        modules={modules}
        onSelectResult={goToResult}
        onPurge={purgeAll}
        onPreload={preload}
      />
    </SearchQueryProvider>
  );
}
