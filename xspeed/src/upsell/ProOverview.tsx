import { Lock, ArrowRight, KeyRound, Check, BadgeCheck } from 'lucide-react';
import { ModuleIcon } from '../components/ModuleIcon';
import { PRO_FEATURES, PRO_UPGRADE_URL, LICENSE_URL } from './proFeatures';
import { useProState } from './useTier';
import { __ } from '../i18n';

/**
 * The `#/pro` page — a calm, discovery-led overview of what xSpeed Pro adds
 * (redesign v2). Reached from the sidebar "Upgrade to Pro" button and the
 * top-bar. Not a sidebar group: a peer of Overview.
 *
 * Read-only, honest framing, ONE primary CTA. The card grid is the same
 * PRO_FEATURES catalog the in-panel teasers draw from, so there's a single
 * source of truth for what Pro does. When Pro is installed-but-unlicensed the
 * CTA becomes "Activate your license" (points at Pro's own License panel)
 * instead of the external pricing page.
 */
export function ProOverview() {
  const proState = useProState();
  const unlicensed = proState === 'unlicensed';
  // `proState` is three-state, but this screen only branched on `unlicensed` —
  // so a LICENSED customer fell into the same else as a Free visitor and was
  // sold a product they already own, via an external pricing link. The rail
  // footer and this page are the product's two licensing-status surfaces, so
  // they now share one vocabulary: Upgrade to Pro / Activate License /
  // Pro Licensed.
  const licensed = proState === 'active';
  const features = Object.values(PRO_FEATURES);

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      {/* Hero — badge + headline + one primary CTA. */}
      <section className="relative overflow-hidden rounded-card border border-line bg-primary-tint p-6 sm:p-8">
        <span className="inline-flex items-center gap-1 text-[10px] uppercase tracking-wider font-medium border border-line bg-paper text-on-tint rounded-full px-2 py-0.5">
          {licensed ? <BadgeCheck size={10} /> : unlicensed ? <KeyRound size={10} /> : <Lock size={10} />}
          <span>
            {licensed
              ? __('Pro Licensed')
              : unlicensed
                ? __('Pro — license needed')
                : __('xSpeed Pro')}
          </span>
        </span>
        <h1 className="mt-3 font-display text-[26px] font-semibold text-ink">
          {licensed
            ? __('Pro is licensed on this site.')
            : __('Everything in Free, plus the heavy lifting.')}
        </h1>
        <p className="mt-2 max-w-2xl text-[14px] leading-relaxed text-ink-soft">
          {licensed
            ? __('Every feature below is unlocked and available from its own page. This screen stays as a reference for what Pro adds — manage your license from the License page.')
            : unlicensed
              ? __('xSpeed Pro is installed — activate your license to unlock every feature below. Your current settings stay exactly as they are.')
              : __('Pro layers deeper optimization, real-user insight, and automation on top of the cache you already run. Nothing you have set up changes.')}
        </p>
        <div className="mt-5">
          {licensed ? (
            /* Nothing left to buy or activate. A quiet route to the License
               page instead of a CTA — same destination the rail footer uses,
               so licensing status has exactly one home. */
            <a
              href={LICENSE_URL}
              className="inline-flex items-center gap-1.5 px-4 py-2 rounded-control text-[13px] font-medium border border-line-strong text-ink no-underline hover:bg-panel transition-colors"
            >
              {__('Manage license')}
              <ArrowRight size={14} />
            </a>
          ) : unlicensed ? (
            <a
              href={LICENSE_URL}
              className="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-control text-[14px] font-medium bg-primary hover:bg-primary-hover text-on-primary no-underline shadow-primary-glow transition-colors"
            >
              {__('Activate License')}
              <ArrowRight size={15} />
            </a>
          ) : (
            <a
              href={PRO_UPGRADE_URL}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-control text-[14px] font-medium bg-primary hover:bg-primary-hover text-on-primary no-underline shadow-primary-glow transition-colors"
            >
              {__('Upgrade to Pro')}
              <ArrowRight size={15} />
            </a>
          )}
        </div>
      </section>

      {/* What Pro adds — the full catalog, factual one-liners. */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        {features.map((f) => (
          <div key={f.id} className="rounded-card border border-line bg-paper p-4">
            <div className="flex items-start gap-3">
              <div className="shrink-0 w-9 h-9 rounded-full border border-line bg-primary-tint text-primary flex items-center justify-center">
                <ModuleIcon name={f.icon} size={17} />
              </div>
              <div className="min-w-0">
                <h3 className="text-[14px] font-semibold text-ink">{f.title}</h3>
                <p className="mt-1 text-[12px] leading-relaxed text-ink-soft">{f.body}</p>
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Honest footer — Free stays Free. Meaningless once licensed: that
          customer is not on the Free tier and does not need reassuring about
          what happens to it. */}
      {!licensed && (
        <div className="flex items-center justify-center gap-1.5 text-[12px] text-muted">
          <Check size={13} className="text-success" />
          <span>{__('Your Free tier keeps working exactly as it does today.')}</span>
        </div>
      )}
    </div>
  );
}
