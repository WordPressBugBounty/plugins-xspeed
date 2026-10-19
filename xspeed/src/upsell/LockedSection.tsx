import { Lock, ArrowRight, KeyRound } from 'lucide-react';
import { ModuleIcon } from '../components/ModuleIcon';
import { PRO_FEATURES, PRO_UPGRADE_URL, LICENSE_URL } from './proFeatures';
import { useProState } from './useTier';
import { __ } from '../i18n';

/**
 * In-page locked block (DESIGN.md §24.11).
 *
 * The section-level sibling of LockedPanel. LockedPanel is the whole
 * right pane when a locked Pro *row* is clicked; LockedSection is a
 * compact card rendered *inside* a Free host page — as the body of a
 * Pro tab, or a Pro section within a merged page (FBS-83633).
 *
 * This is the mechanism that makes "the sidebar doesn't change on
 * upgrade" true: a host page always renders the same tabs/sections; a
 * Pro sub-part shows a LockedSection on Free and the real Pro panel
 * once licensed. No sidebar row appears or disappears.
 *
 * Two states, same as LockedPanel, in one primary-tint treatment:
 * Pro-installed-but-unlicensed → KeyRound + "License", "Activate your
 * license"; Pro-not-installed → Lock + "Pro", "Upgrade to Pro". Pure
 * read-only.
 */
export function LockedSection({
  icon,
  title,
  description,
  teaser,
}: {
  /** ModuleIcon name for the leading glyph. */
  icon: string;
  /** Section heading (usually the Pro feature's label). */
  title: string;
  /** One-line summary of what the section does. */
  description?: string;
  /** Key into PRO_FEATURES for the elaborated "what this adds" copy. */
  teaser?: string;
}) {
  const feature = teaser ? PRO_FEATURES[teaser] : null;
  const proState = useProState();
  const unlicensed = proState === 'unlicensed';

  // Redesign v2 — one calm primary-tint treatment (no amber nag). Same lock/key
  // pill language as LockedPanel so embedded and full-pane locks read alike.
  const theme = {
    card: 'border-line bg-primary-tint',
    iconWrap: 'bg-paper border-line text-primary',
    badge: 'text-on-tint bg-paper border-line',
    cta: 'bg-primary hover:bg-primary-hover text-on-primary',
  };

  return (
    <div className={`border rounded-card p-4 sm:p-5 ${theme.card}`}>
      <div className="flex items-start gap-3">
        <div
          className={`shrink-0 w-9 h-9 rounded-full border flex items-center justify-center ${theme.iconWrap}`}
        >
          <ModuleIcon name={icon} size={17} />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2 mb-0.5">
            <h3 className="text-[14px] font-semibold text-ink">
              {title}
            </h3>
            <span
              className={`inline-flex items-center gap-1 text-[10px] uppercase tracking-wider font-medium border rounded-full px-1.5 py-0.5 ${theme.badge}`}
            >
              {unlicensed ? <KeyRound size={9} /> : <Lock size={9} />}
              <span>{unlicensed ? __('License') : __('Pro')}</span>
            </span>
          </div>
          {description && (
            <p className="text-[12px] text-ink-soft leading-relaxed">
              {description}
            </p>
          )}
          {feature && (
            <p className="text-[12px] text-muted mt-2 leading-relaxed">
              {feature.body}
            </p>
          )}
          {unlicensed ? (
            <a
              href={LICENSE_URL}
              className={`inline-flex items-center gap-1.5 mt-3 px-3 py-1.5 rounded-md text-[12px] font-medium transition-colors ${theme.cta}`}
            >
              {__('Activate your license')}
              <ArrowRight size={12} />
            </a>
          ) : (
            <a
              href={PRO_UPGRADE_URL}
              target="_blank"
              rel="noopener noreferrer"
              className={`inline-flex items-center gap-1.5 mt-3 px-3 py-1.5 rounded-md text-[12px] font-medium transition-colors ${theme.cta}`}
            >
              {__('Upgrade to Pro')}
              <ArrowRight size={12} />
            </a>
          )}
        </div>
      </div>
    </div>
  );
}
