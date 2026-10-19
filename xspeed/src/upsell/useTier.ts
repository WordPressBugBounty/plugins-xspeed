/**
 * Reads the current site's tier from window.XSpeedConfig so Free
 * panels can hide their Pro teasers when Pro is already installed.
 *
 * Source of truth: PHP side reads Tier_Registry::pro_active() (which
 * checks XSPEED_PRO_API constant). Result is injected into
 * XSpeedConfig.tier as one of 'free' | 'pro' | 'trial'.
 *
 * Reactive: subscribes to the `xspeed:tier-changed` event that Pro's
 * LicensePanel fires (via window.XSpeedPro.setTier) after a license
 * activate/deactivate. Without this, tier was read once at render and
 * never changed, so the locked Pro panels stayed locked until a full
 * page reload re-ran PHP with the new tier.
 *
 * `'trial'` is reserved for future use — the call site handles it so it
 * never needs retouching when trial activation ships.
 */
import { useEffect, useState } from 'react';
import { TIER_EVENT } from '../components/proPanels';

export type Tier = 'free' | 'pro' | 'trial';

/**
 * Three-state Pro status (from XSpeedConfig.proState), so gated UI shows the
 * right CTA:
 *   'not_installed' — Pro plugin absent → "Upgrade to Pro"
 *   'unlicensed'    — Pro active, no valid license → "Activate license"
 *   'active'        — Pro active + licensed → (modules unlocked)
 */
export type ProState = 'not_installed' | 'unlicensed' | 'active';

function readTier(): Tier {
  if (typeof window === 'undefined') return 'free';
  const cfg = (window as unknown as { XSpeedConfig?: { tier?: Tier } }).XSpeedConfig;
  return cfg?.tier ?? 'free';
}

function readProState(): ProState {
  if (typeof window === 'undefined') return 'not_installed';
  const cfg = (window as unknown as { XSpeedConfig?: { proState?: ProState } }).XSpeedConfig;
  if (cfg?.proState) return cfg.proState;
  // Back-compat: if proState isn't present, fall back to the tier — a 'pro'
  // tier implies active; anything else implies Pro isn't installed.
  return isPro() ? 'active' : 'not_installed';
}

export function useTier(): Tier {
  const [tier, setTier] = useState<Tier>(readTier);
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const handler = () => setTier(readTier());
    window.addEventListener(TIER_EVENT, handler);
    return () => window.removeEventListener(TIER_EVENT, handler);
  }, []);
  return tier;
}

export function isPro(): boolean {
  const t = readTier();
  return t === 'pro' || t === 'trial';
}

/**
 * Reactive three-state Pro status. Re-reads on the same `xspeed:tier-changed`
 * event Pro fires after a license activate/deactivate, so the gated panels
 * flip between "Upgrade" and "Activate license" without a page reload.
 */
export function useProState(): ProState {
  const [state, setState] = useState<ProState>(readProState);
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const handler = () => setState(readProState());
    window.addEventListener(TIER_EVENT, handler);
    return () => window.removeEventListener(TIER_EVENT, handler);
  }, []);
  return state;
}

/**
 * Whether Pro is loaded AND licensed. Tier is 'pro' whenever the Pro plugin
 * is loaded, licensed or not; the Pro state says whether the license is
 * valid, and reports an expired license inside its grace window as 'active'.
 */
export function useProLicensed(): boolean {
  const tier = useTier();
  const proState = useProState();
  return (tier === 'pro' || tier === 'trial') && proState === 'active';
}
