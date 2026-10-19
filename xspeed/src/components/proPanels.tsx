import type { ComponentType } from 'react';
import { useEffect, useState, useSyncExternalStore } from 'react';
import type { PanelProps } from './customPanels';
import type { Branding } from '../types';
import { registerStatusItem, type StatusItemComponent } from './statusItems';
import { registerRailItem, useRailForm, type RailForm, type RailItemComponent } from './railItems';
import { CfeOfferCard } from './CfeOfferCard';

/**
 * Runtime registry for Pro custom panels.
 *
 * The Pro plugin (xspeed-pro) ships its own Vite bundle that registers
 * panels via `window.XSpeedPro.registerPanel(slug, Component)` after
 * Free's bundle has booted. We can't statically import Pro source from
 * Free (one bundle, separate plugins, decoupled), so this registry
 * lives at runtime.
 *
 * Lookup contract — ModulePanel.tsx checks `customPanels` first (Free
 * built-ins), then falls back to `proPanels[slug]`. Adding a panel
 * after first render is supported: subscribe with usePanelsVersion()
 * which bumps on a custom event, forcing affected components to
 * re-read the registry.
 */
export type PanelComponent = ComponentType<PanelProps>;

const REGISTRY: Record<string, PanelComponent> = {};
const CHANGE_EVENT = 'xspeed:panels-changed';
// Bumped on every registration. usePanelsVersion reads it as a snapshot, so a
// registration that lands before the component subscribes is still seen.
let registryVersion = 0;
// Fired when the site's tier flips (license activate / deactivate) so the
// dashboard re-evaluates which Pro panels are unlocked WITHOUT a full page
// reload. Pro's LicensePanel calls window.XSpeedPro.setTier() on success.
const TIER_EVENT = 'xspeed:tier-changed';
// Fired when white-label branding changes (Pro White-Label save) so the
// dashboard chrome — sidebar name + logo, footer credit — re-renders WITHOUT
// a full page reload. Pro's White-Label panel calls window.XSpeedPro.setBranding().
const BRANDING_EVENT = 'xspeed:branding-changed';
// Fired when something other than the tier changed what the server reports
// about modules (an add-on's own license, a domain going live) so the
// dashboard re-reads the descriptors and every pill and count follows.
// Pro calls window.XSpeedPro.refreshModules().
const MODULES_EVENT = 'xspeed:modules-changed';

type Tier = 'free' | 'pro' | 'trial';

export type { Branding };

export function getProPanel(slug: string): PanelComponent | undefined {
  return REGISTRY[slug];
}

interface XSpeedProGlobal {
  panels: Record<string, PanelComponent>;
  registerPanel: (slug: string, component: PanelComponent) => void;
  /** A top-bar status chip (DESIGN.md §24.48). Same id replaces. */
  registerStatusItem: (id: string, component: StatusItemComponent) => void;
  /** A card in the expanded sidebar, above the tier slot (DESIGN.md §24.49). Same id replaces. */
  registerRailItem: (id: string, component: RailItemComponent) => void;
  /**
   * The form a rail card should draw in (DESIGN.md §24.49). A card with no
   * icon form returns null for `icon`.
   */
  useRailForm: () => RailForm;
  /**
   * Free's Cloudflare Enterprise card, in every rail form. The add-on's own
   * card renders this with its purchase link and dismissal, so the two cards
   * look the same and resize the same way.
   */
  CfeOfferCard: typeof CfeOfferCard;
  setTier: (tier: Tier) => void;
  /** Re-read the module descriptors without a tier change. */
  refreshModules: () => void;
  setBranding: (branding: Branding) => void;
  /**
   * Hide or show the page header (eyebrow, title, status pill, description)
   * above one module's panel. For a panel that draws a full-page flow of its
   * own and would otherwise repeat the title. Shown again by passing true,
   * and by default.
   */
  setModuleHeader: (slug: string, visible: boolean) => void;
}

export function installProPanelRegistry(): void {
  if (typeof window === 'undefined') return;
  if ((window as unknown as { XSpeedPro?: unknown }).XSpeedPro) return;
  (window as unknown as { XSpeedPro: XSpeedProGlobal }).XSpeedPro = {
    panels: REGISTRY,
    registerPanel(slug, component) {
      REGISTRY[slug] = component;
      registryVersion += 1;
      window.dispatchEvent(new CustomEvent(CHANGE_EVENT, { detail: { slug } }));
    },
    registerStatusItem,
    registerRailItem,
    useRailForm,
    CfeOfferCard,
    // Pro calls this after a license activate/deactivate so Free's
    // useTier() re-reads and the locked Pro panels unlock/relock live.
    setTier(tier) {
      const cfg = (window as unknown as { XSpeedConfig?: { tier?: Tier; proState?: string } }).XSpeedConfig;
      if (cfg) {
        cfg.tier = tier;
        // Keep the Pro state in step, or panels that read it (Health's
        // PageSpeed tab) stay locked until a reload. Only Pro calls this, so
        // Pro is installed: a free tier here means unlicensed.
        cfg.proState = tier === 'free' ? 'unlicensed' : 'active';
      }
      window.dispatchEvent(new CustomEvent(TIER_EVENT, { detail: { tier } }));
    },
    refreshModules() {
      window.dispatchEvent(new CustomEvent(MODULES_EVENT));
    },
    // Pro's White-Label panel calls this after saving so the dashboard
    // chrome (sidebar name + logo, footer) re-renders live — no reload.
    setBranding(branding) {
      const cfg = (window as unknown as { XSpeedConfig?: { branding?: Branding } }).XSpeedConfig;
      if (cfg) cfg.branding = branding;
      window.dispatchEvent(new CustomEvent(BRANDING_EVENT, { detail: { branding } }));
    },
    setModuleHeader,
  };
}

// Modules whose panel asked for no page header. The panel owns the flag:
// it hides the header while it draws a flow with its own title, and shows
// it again on its other screens and when it unmounts.
const HIDDEN_HEADERS = new Set<string>();
let headersVersion = 0;
const HEADER_EVENT = 'xspeed:module-header-changed';

function setModuleHeader(slug: string, visible: boolean): void {
  const had = HIDDEN_HEADERS.has(slug);
  if (visible) HIDDEN_HEADERS.delete(slug);
  else HIDDEN_HEADERS.add(slug);
  if (had === !visible) return;
  headersVersion += 1;
  window.dispatchEvent(new CustomEvent(HEADER_EVENT, { detail: { slug, visible } }));
}

function subscribeHeaders(onChange: () => void): () => void {
  window.addEventListener(HEADER_EVENT, onChange);
  return () => window.removeEventListener(HEADER_EVENT, onChange);
}

/** Whether the page header above this module's panel is shown. */
export function useModuleHeaderVisible(slug: string): boolean {
  useSyncExternalStore(subscribeHeaders, () => headersVersion, () => headersVersion);
  return !HIDDEN_HEADERS.has(slug);
}

export { TIER_EVENT, BRANDING_EVENT, MODULES_EVENT, HEADER_EVENT };

/**
 * Hook that returns a counter bumped every time a Pro panel registers.
 * Read it (no need to use its value) to make a component re-render
 * when new Pro panels arrive after first mount.
 *
 * It is a useSyncExternalStore subscription, not a listener added in
 * useEffect. The effect version lost registrations: React renders Free's
 * app while Pro's script is still downloading, the component reads the
 * registry (panel not there yet), and Pro's registerPanel() event can fire
 * before the effect has attached its listener. Nothing re-rendered after
 * that, so a registered panel stayed invisible. On a cold load that showed
 * the module's raw settings fields in place of its panel (report 3), and
 * after #386 "This screen did not load". useSyncExternalStore re-reads the
 * version when it subscribes and re-renders if it moved in between.
 */
function subscribePanels(onChange: () => void): () => void {
  window.addEventListener(CHANGE_EVENT, onChange);
  return () => window.removeEventListener(CHANGE_EVENT, onChange);
}

function panelsSnapshot(): number {
  return registryVersion;
}

export function usePanelsVersion(): number {
  return useSyncExternalStore(subscribePanels, panelsSnapshot, panelsSnapshot);
}

/**
 * True once every script on the page has run: `document.readyState` is
 * 'complete', or the window `load` event has fired since mount.
 *
 * Why this exists: Free's bundle installs `window.XSpeedPro` and renders
 * first; Pro's script runs afterwards and calls registerPanel(). So on
 * every normal page load there is a window in which a Pro module's
 * custom panel is not registered YET. Until the page has loaded, a
 * missing panel is expected and must not read as an error. After it, no
 * more scripts are coming, so a panel that is still missing never will be.
 *
 * ModulePanel and ProSlot both decide "missing or still loading" through
 * this one hook (via MissingPanel), so the two call sites cannot disagree.
 */
export function isPageLoaded(): boolean {
  return typeof document === 'undefined' || document.readyState === 'complete';
}

export function usePageLoaded(): boolean {
  const [loaded, setLoaded] = useState(isPageLoaded);
  useEffect(() => {
    if (loaded) return;
    // readyState may have flipped between render and effect.
    if (isPageLoaded()) {
      setLoaded(true);
      return;
    }
    const handler = () => setLoaded(true);
    window.addEventListener('load', handler);
    return () => window.removeEventListener('load', handler);
  }, [loaded]);
  return loaded;
}
