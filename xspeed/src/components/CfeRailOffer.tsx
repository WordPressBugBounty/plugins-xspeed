import { useState } from 'react';
import { useBranding } from '../hooks/useBranding';
import { CfeOfferCard } from './CfeOfferCard';
import type { Branding, ModuleDescriptor } from '../types';

const CFE_SLUG = 'cloudflare-enterprise';

/**
 * Where a dismissal is kept: this browser, for good. The add-on's own card
 * reads and writes the same key, so closing either one keeps both gone,
 * including after the site upgrades.
 */
export const CFE_OFFER_DISMISS_KEY = 'xspeed:cfe-offer-dismissed';

/**
 * The id the add-on registers its own sidebar card under
 * (`window.XSpeedPro.registerRailItem`). The sidebar does not draw a card
 * registered under it: Free's card below is the one Cloudflare Enterprise
 * card, whatever version of the add-on is installed.
 */
export const CFE_RAIL_ITEM_ID = 'cloudflare-enterprise';

/**
 * Whether to offer the add-on: only while there is something to buy.
 *
 * - Not where the xCloud purge plugin is installed: Cloudflare Enterprise
 *   already comes from the host.
 * - Yes where the module is missing (Free only, or a Pro before 1.3.0) or
 *   locked (Pro without a licence).
 * - Not once the add-on is live, or while another module does its job.
 * - When the add-on says whether anything is left to buy
 *   (`purchase_needed`), that answer. It lets the pill read "Setting up"
 *   during the DNS steps without the card coming back.
 * - Otherwise (an add-on without that field), only while the module says
 *   what is still needed (`status_label`, e.g. "Add-on needed"). The add-on
 *   clears it as soon as a key is entered, so a site that bought the add-on
 *   and has not gone live yet is not sold it again.
 *
 * An earlier rule hid the card on licensed Pro and left the slot to the
 * add-on's own card, which Pro before 1.3.0 does not have; an unlicensed
 * Pro 1.3.0 hid its card as well. Deciding here keeps the one card right
 * whichever Pro is installed.
 */
export function cfeOfferApplies(
  modules: readonly ModuleDescriptor[],
  { xcloudPurgePlugin = false }: { xcloudPurgePlugin?: boolean } = {},
): boolean {
  if (xcloudPurgePlugin) return false;
  const m = modules.find((mod) => mod.slug === CFE_SLUG);
  if (m === undefined || (m as { locked?: boolean | string }).locked) return true;
  if (m.active === true || m.blocked_by) return false;
  if (typeof m.purchase_needed === 'boolean') return m.purchase_needed;
  return typeof m.status_label === 'string' && m.status_label !== '';
}

/**
 * The price the add-on reported through its module descriptor, or null. The
 * add-on keeps it from the last status it read, so the card never has to ask
 * anyone; `CfeOfferCard` falls back to its own figure when there is none.
 */
export function cfeOfferPrice(modules: readonly ModuleDescriptor[]): string | null {
  const m = modules.find((mod) => mod.slug === CFE_SLUG);
  return typeof m?.addon_price === 'string' && m.addon_price.trim() !== '' ? m.addon_price.trim() : null;
}

function readXcloudPurgePlugin(): boolean {
  return Boolean((window as unknown as { XSpeedConfig?: { xcloudPurgePlugin?: boolean } }).XSpeedConfig?.xcloudPurgePlugin);
}

/** A white-labelled dashboard shows no xSpeed mark or name (§24.28). */
export function isWhiteLabelled(branding: Branding): boolean {
  return (branding.name !== '' && branding.name !== 'xSpeed Cache') || Boolean(branding.logo_svg);
}

/**
 * Where the add-on's own card kept a dismissal before it shared Free's key.
 * That card is no longer drawn, so a reader who closed it there must not see
 * this one come back.
 */
const LEGACY_ADDON_DISMISS_KEY = 'xspeed-pro:cfe-sidebar-offer-dismissed';

function readDismissed(): boolean {
  try {
    return (
      window.localStorage.getItem(CFE_OFFER_DISMISS_KEY) === '1' ||
      window.localStorage.getItem(LEGACY_ADDON_DISMISS_KEY) === '1'
    );
  } catch {
    return false;
  }
}

/**
 * The Cloudflare Enterprise offer in the sidebar (DESIGN.md §24.49), for a
 * site that does not have the add-on running. `CfeOfferCard` draws it, in
 * whichever form the rail has room for.
 */
export function CfeRailOffer({
  modules,
}: {
  modules: readonly ModuleDescriptor[];
}) {
  const [dismissed, setDismissed] = useState(readDismissed);
  const branding = useBranding();

  if (dismissed || !cfeOfferApplies(modules, { xcloudPurgePlugin: readXcloudPurgePlugin() }) || isWhiteLabelled(branding)) return null;

  const dismiss = () => {
    setDismissed(true);
    try {
      window.localStorage.setItem(CFE_OFFER_DISMISS_KEY, '1');
    } catch {
      // Private mode or storage off: hidden for this page view only.
    }
  };

  return <CfeOfferCard onDismiss={dismiss} price={cfeOfferPrice(modules)} />;
}
