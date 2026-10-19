import { Ban, Lock } from 'lucide-react';
import { __ } from '../i18n';
import { InfoPopover } from './InfoPopover';
import { proDemoFor } from '../upsell/proDemos';
import { useProState } from '../upsell/useTier';
import type { ModuleDescriptor } from '../types';

type HeaderState = 'on' | 'off' | 'locked' | 'blocked' | null;

function headerState(m: ModuleDescriptor): HeaderState {
  if (m.locked) return 'locked';
  // Taken over by something else on the site: the same word the group card uses.
  if (m.blocked_by) return 'blocked';
  // The descriptor's `active`, so this pill agrees with the sidebar badge
  // and the Overview card. Reading `settings.enabled` showed NO pill on the
  // Page Cache, CSS & JavaScript and Media pages -- none of those modules
  // stores that key -- so the page you opened to check a feature's state was
  // the one page that would not tell you. (#363)
  if (typeof m.active === 'boolean') return m.active ? 'on' : 'off';
  return null;
}

/**
 * What stands between the customer and the feature, when that is more than
 * "Pro": a locked row whose demo sells a second purchase names both, and a
 * module can name its own (`status_label`, e.g. an add-on licence it lacks).
 */
function NeedsPill({ label, addon = false }: { label: string; addon?: boolean }) {
  // A module's own status_label is a separate purchase (an add-on licence),
  // so it takes the add-on colours (§24.50), not Pro's teal lock (§24.10).
  // While Pro itself is unlicensed the label starts with Pro, so the caller
  // passes addon=false and it takes the lock.
  if (addon) {
    return (
      <span className="inline-flex items-center rounded-pill px-2.5 py-0.5 text-[12px] font-medium bg-addon-tint text-addon">
        {label}
      </span>
    );
  }
  return (
    <span className="inline-flex items-center gap-1 rounded-pill px-2.5 py-0.5 text-[12px] font-medium bg-primary-tint text-on-tint">
      <Lock size={12} aria-hidden="true" /> {label}
    </span>
  );
}

function StatusPill({ state }: { state: HeaderState }) {
  if (state === 'blocked')
    return (
      <span className="inline-flex items-center gap-1 rounded-pill px-2.5 py-0.5 text-[12px] font-medium bg-warning-tint text-warning">
        <Ban size={12} aria-hidden="true" /> {__('Not in use')}
      </span>
    );
  if (state === 'locked')
    return (
      <span className="inline-flex items-center gap-1 rounded-pill px-2.5 py-0.5 text-[12px] font-medium bg-primary-tint text-primary">
        <Lock size={12} aria-hidden="true" /> {__('Pro')}
      </span>
    );
  if (state === 'on')
    return (
      <span className="inline-flex items-center gap-1.5 rounded-pill px-2.5 py-0.5 text-[12px] font-medium bg-success-tint text-success">
        <span className="w-1.5 h-1.5 rounded-full bg-success" aria-hidden="true" /> {__('On')}
      </span>
    );
  if (state === 'off')
    return <span className="rounded-pill px-2.5 py-0.5 text-[12px] font-medium bg-panel text-muted">{__('Off')}</span>;
  return null;
}

/**
 * Redesign v2 — the module-settings page header (eyebrow + title + status pill
 * + description). Rendered above the module's existing panel content; the panel
 * itself is unchanged (parity). Breadcrumb lives in the top bar.
 */
export function ModuleHeader({ module, eyebrow }: { module: ModuleDescriptor; eyebrow?: string }) {
  const proState = useProState();
  const purchase = module.locked ? proDemoFor(module.slug)?.purchase : undefined;
  const needs = purchase
    ? proState === 'unlicensed'
      ? purchase.badge.unlicensed
      : purchase.badge.missing
    : !module.locked && module.status_label
      ? module.status_label
      : null;
  return (
    <div>
      {eyebrow && <p className="text-[12px] font-medium uppercase tracking-wide text-muted">{eyebrow}</p>}
      <div className="mt-1 flex items-center gap-3 flex-wrap">
        <h1 className="font-display text-[22px] font-semibold text-ink">{module.label}</h1>
        <span className="inline-flex items-center gap-1">
          {needs ? (
            <NeedsPill label={needs} addon={!purchase && !(proState === 'unlicensed' && module.tier === 'pro')} />
          ) : (
            <StatusPill state={headerState(module)} />
          )}
          {/* Why the pill says what it says. Only for a real on/off state —
              a locked Pro row explains itself, and a module with no on/off
              has no verdict to justify. */}
          {!needs && module.active_reason && typeof module.active === 'boolean' && !module.locked && (
            <InfoPopover
              title={module.active === true ? __('Why this is on') : __('Why this is off')}
              body={module.active_reason}
            />
          )}
        </span>
      </div>
      {module.description && <p className="mt-1 text-[13px] leading-relaxed text-ink-soft max-w-2xl">{module.description}</p>}
    </div>
  );
}
