import { Lock, ArrowRight, ExternalLink, KeyRound, Cloud, Server, User, BookOpen } from 'lucide-react';
import { BrandLogo } from '../components/BrandLogo';
import { ModuleIcon } from '../components/ModuleIcon';
import { PRO_MODULE_MANIFEST } from './proModuleManifest';
import { PRO_FEATURES, PRO_UPGRADE_URL, LICENSE_URL, CFE_DOCS_URL } from './proFeatures';
import { proDemoFor } from './proDemos';
import { ProDemo } from './ProDemo';
import { EdgeFlow, PurchasePath } from './PurchasePath';
import { useProState } from './useTier';
import { __ } from '../i18n';
import type { ModuleDescriptor } from '../types';

const FLOW_ICONS = {
  User: <User size={16} />,
  Cloud: <Cloud size={16} />,
  Server: <Server size={16} />,
};

/**
 * Right-pane content when the user clicks a locked Pro module row
 * in the sidebar. Shows what the feature does + an upgrade CTA.
 *
 * Mounted by ModulePanel's dispatcher when `module.locked === true`.
 * Pure read-only — no settings, no API calls, no state. The whole
 * point is to convert the click into "show me what Pro adds" rather
 * than "fail silently because this module isn't registered."
 */
export function LockedPanel({ module }: { module: ModuleDescriptor }) {
  const manifest = PRO_MODULE_MANIFEST.find((m) => m.slug === module.slug);
  const feature = manifest?.teaser ? PRO_FEATURES[manifest.teaser] : null;
  const proState = useProState();

  // Pro installed but unlicensed → a license-activation CTA (blue/info), NOT
  // an upsell. Only when Pro isn't installed do we show the amber "Upgrade".
  const unlicensed = proState === 'unlicensed';

  // A standalone locked row that HAS a §24.12 demonstration (keyed by slug)
  // gets the same "show, don't just name" treatment as an embedded Pro tab —
  // the mock + checklist + CTA — instead of the bare crown hero card. We keep
  // the honest "your Free settings stay unchanged" footer below it.
  const demo = proDemoFor(module.slug);

  /*
   * A feature that takes a second purchase on top of Pro: the purchase path
   * (§24.42) is the whole screen, because the one thing it must get across is
   * that "Upgrade to Pro" alone does not switch it on. The offer beside the
   * steps says what the add-on adds, and the strip under them how it works.
   */
  if (demo?.purchase) {
    const p = demo.purchase;
    return (
      <div className="space-y-5">
        <PurchasePath
          title={p.title}
          steps={[
            /*
             * Licensed Pro on a placeholder page means a Pro older than the
             * add-on: it has no module for it (an add-on sold separately keeps
             * its placeholder on every tier, see mergeProModules). Pro is there,
             * so the step asks for an update, not a purchase.
             */
            proState === 'active'
              ? {
                  ...p.pro,
                  icon: <BrandLogo tone="primary" className="w-5 h-5 shrink-0" />,
                  state: 'next',
                  status: __('Update needed'),
                  action: { label: __('Update Pro'), href: 'plugins.php' },
                }
              : unlicensed
              ? {
                  ...p.pro,
                  icon: <BrandLogo tone="primary" className="w-5 h-5 shrink-0" />,
                  state: 'next',
                  status: __('License not active'),
                  action: { label: __('Activate License'), href: LICENSE_URL },
                }
              : {
                  ...p.pro,
                  icon: <BrandLogo tone="primary" className="w-5 h-5 shrink-0" />,
                  state: 'next',
                  status: __('Not installed'),
                  action: { label: __('Get Pro'), href: PRO_UPGRADE_URL, external: true },
                },
            { ...p.addon, icon: <Cloud size={18} />, state: 'later', status: __('No license key') },
          ]}
          offer={p.offer}
          footer={
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
              <a
                href={LICENSE_URL}
                className="inline-flex items-center gap-1.5 h-7 text-[13px] font-medium text-primary no-underline hover:underline"
              >
                <KeyRound size={14} aria-hidden="true" />
                {__('Already bought both? Enter your license keys')}
              </a>
              {/* Setup ends in DNS changes at the customer's provider; the guide
                  shows that before anyone buys. */}
              <a
                href={CFE_DOCS_URL}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 h-7 text-[13px] font-medium text-primary no-underline hover:underline"
              >
                <BookOpen size={14} aria-hidden="true" />
                {__('Read the setup guide')}
                <ExternalLink size={12} aria-hidden="true" />
              </a>
            </div>
          }
        />
        <EdgeFlow
          eyebrow={p.flow.eyebrow}
          title={p.flow.title}
          body={p.flow.body}
          label={p.flow.label}
          nodes={p.flow.nodes.map((node) => ({ ...node, icon: FLOW_ICONS[node.icon] }))}
        />
      </div>
    );
  }

  if (demo) {
    return (
      <div className="space-y-4">
        <ProDemo id={module.slug} />
        <div className="text-[12px] text-muted text-center px-4">
          {unlicensed
            ? __('Activating your license unlocks this module + every other locked row in the sidebar. Your current settings stay unchanged.')
            : __('Pro unlocks this module + every other locked row in the sidebar. Your current Free settings stay unchanged.')}
        </div>
      </div>
    );
  }

  // Theme tokens per state. Unlicensed = a restrained, mostly-neutral card
  // (flat surface, no gradient) with blue reserved for the badge, icon, CTA,
  // and link — it's an "activate", not a loud upsell. Not-installed keeps the
  // amber upsell treatment (also flat now).
  // Redesign v2 — one calm, discovery-led treatment for both states (no amber
  // nag): a subtle primary-tint card, a lock/key pill, and ONE primary CTA.
  const theme = {
    card: 'border-line bg-primary-tint',
    iconWrap: 'bg-paper border-line text-primary',
    badge: 'text-on-tint bg-paper border-line',
    cta: 'bg-primary hover:bg-primary-hover text-on-primary',
    link: 'text-primary',
  };

  return (
    <div className="space-y-4">
      {/* Hero card framing the feature. The badge/color reflects whether the
          user needs to UPGRADE (Pro not installed) or ACTIVATE (installed,
          unlicensed). The badge rhymes with the sidebar row so it reads as
          "this is the row you clicked, here's the full story." */}
      <div
        className={`relative overflow-hidden border rounded-card p-6 sm:p-8 ${theme.card}`}
      >
        <div className="flex items-start gap-4">
          <div
            className={`shrink-0 w-12 h-12 rounded-full border flex items-center justify-center ${theme.iconWrap}`}
          >
            <ModuleIcon name={module.icon} size={22} />
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 mb-1">
              <span
                className={`inline-flex items-center gap-1 text-[10px] uppercase tracking-wider font-medium border rounded-full px-2 py-0.5 ${theme.badge}`}
              >
                {unlicensed ? <KeyRound size={10} /> : <Lock size={10} />}
                <span>{unlicensed ? __('Pro — license needed') : __('Pro feature')}</span>
              </span>
            </div>
            <h2 className="text-[18px] font-semibold text-ink">
              {module.label}
            </h2>
            {module.description && (
              <p className="text-[13px] text-ink-soft mt-2 leading-relaxed">
                {module.description}
              </p>
            )}
            {unlicensed ? (
              <>
                <p className="text-[13px] text-ink-soft mt-3 leading-relaxed">
                  {__(
                    'xSpeed Pro is installed — activate your license to unlock this module and every other Pro feature.',
                  )}
                </p>
                <a
                  href={LICENSE_URL}
                  className={`inline-flex items-center gap-1.5 mt-4 px-4 py-2 rounded-md text-[13px] font-medium transition-colors ${theme.cta}`}
                >
                  {__('Activate your license')}
                  <ArrowRight size={13} />
                </a>
              </>
            ) : (
              <>
                <a
                  href={PRO_UPGRADE_URL}
                  target="_blank"
                  rel="noopener noreferrer"
                  className={`inline-flex items-center gap-1.5 mt-4 px-4 py-2 rounded-md text-[13px] font-medium transition-colors ${theme.cta}`}
                >
                  {__('Upgrade to Pro')}
                  <ArrowRight size={13} />
                </a>
                <a
                  href={PRO_UPGRADE_URL}
                  target="_blank"
                  rel="noopener noreferrer"
                  className={`inline-flex items-center gap-1 ml-3 text-[12px] hover:underline ${theme.link}`}
                >
                  {__('Compare plans')}
                  <ExternalLink size={11} />
                </a>
              </>
            )}
          </div>
        </div>
      </div>

      {/* Detail card (optional) — pulls from PRO_FEATURES so we
          can elaborate without duplicating copy across two files. */}
      {feature && (
        <div className="border border-line bg-paper rounded-card p-4 sm:p-5">
          <div className="text-[11px] uppercase tracking-wider text-muted font-medium mb-2">
            {__('What this adds')}
          </div>
          <p className="text-[13px] text-ink-soft leading-relaxed">
            {feature.body}
          </p>
        </div>
      )}

      {/* Honest framing — make sure people know the Free tier they
          already have stays the same. Reduces "is Pro a trap?" doubt. */}
      <div className="text-[12px] text-muted text-center px-4">
        {unlicensed
          ? __('Activating your license unlocks this module + every other locked row in the sidebar. Your current settings stay unchanged.')
          : __('Pro unlocks this module + every other locked row in the sidebar. Your current Free settings stay unchanged.')}
      </div>
    </div>
  );
}
