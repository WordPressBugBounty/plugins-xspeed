import { useEffect, useState } from 'react';
import { BRANDING_EVENT } from '../components/proPanels';
import type { Branding } from '../types';

const DEFAULTS: Branding = {
  name: 'xSpeed Cache',
  footer_credit: null,
  hide_help_links: false,
  logo_svg: null,
};

function readBranding(): Branding {
  const b = window.XSpeedConfig?.branding;
  return b ? { ...DEFAULTS, ...b } : DEFAULTS;
}

/**
 * Reactive branding. Reads `window.XSpeedConfig.branding` and re-renders
 * when Pro's White-Label panel fires `xspeed:branding-changed` (via
 * window.XSpeedPro.setBranding) — so the sidebar name + logo and footer
 * update live on save, no full page reload (FBS white-label-onboarding).
 */
export function useBranding(): Branding {
  const [branding, setBranding] = useState<Branding>(readBranding);
  useEffect(() => {
    const handler = () => setBranding(readBranding());
    window.addEventListener(BRANDING_EVENT, handler);
    return () => window.removeEventListener(BRANDING_EVENT, handler);
  }, []);
  return branding;
}
