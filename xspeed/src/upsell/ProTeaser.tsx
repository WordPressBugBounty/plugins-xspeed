import { ArrowRight, Lock } from 'lucide-react';
import { ModuleIcon } from '../components/ModuleIcon';
import { PRO_FEATURES, PRO_UPGRADE_URL } from './proFeatures';
import { useTier } from './useTier';
import { __ } from '../i18n';

/**
 * In-context upsell card placed at the bottom of relevant Free panels.
 *
 * Render contract:
 *  - One <ProTeaser id="…"/> per panel where it's contextually
 *    relevant (Lazy Load → webp-avif; CDN → cloudflare-apo; etc.).
 *  - When tier === 'pro' or 'trial' the component renders null so
 *    Pro users never see upsell noise.
 *  - When the referenced id is missing from the catalog the
 *    component logs a warning + renders null — failsafe, never
 *    breaks the surrounding panel.
 *
 * Tone: state what Pro does, not "buy now." The CTA is "Learn more"
 * not "Upgrade!!!"; the upgrade link opens in a new tab so the user
 * keeps their dashboard context.
 *
 * NOTE: The upgrade URL is the shared PRO_UPGRADE_URL (pricing page).
 * Once we ship in-product checkout / trial activation it'll become a
 * proper handler.
 */
export function ProTeaser({ id }: { id: keyof typeof PRO_FEATURES }) {
  const tier = useTier();
  if (tier !== 'free') return null;

  const feature = PRO_FEATURES[id];
  if (!feature) {
    if (typeof console !== 'undefined') {
      // eslint-disable-next-line no-console
      console.warn(`[xSpeed] Unknown ProTeaser id: "${String(id)}"`);
    }
    return null;
  }

  return (
    <div className="relative overflow-hidden border border-line bg-primary-tint rounded-card p-4 sm:p-5">
      {/* Pro badge in the corner — restrained, not loud. */}
      <div className="absolute top-3 right-3 flex items-center gap-1 text-[10px] uppercase tracking-wider font-medium text-on-tint">
        <Lock size={11} aria-hidden="true" />
        <span>{__('Pro')}</span>
      </div>

      <div className="flex items-start gap-3 pr-12">
        <span className="shrink-0 mt-0.5 text-warning">
          <ModuleIcon name={feature.icon} size={18} />
        </span>
        <div className="min-w-0">
          <div className="text-[14px] font-medium text-ink">
            {feature.title}
          </div>
          <p className="text-[12px] text-ink-soft mt-1 leading-relaxed">
            {feature.body}
          </p>
          <a
            href={PRO_UPGRADE_URL}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1 mt-2.5 text-[12px] font-medium text-primary hover:text-primary-hover transition-colors"
          >
            {feature.cta ?? __('Learn more about Pro')}
            <ArrowRight size={12} />
          </a>
        </div>
      </div>
    </div>
  );
}
