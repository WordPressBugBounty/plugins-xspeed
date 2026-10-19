import { Lock, Activity, Ban } from 'lucide-react';
import { ModuleIcon } from './ModuleIcon';
import { groupById } from './sidebarGroups';
import { __ } from '../i18n';
import type { ModuleDescriptor } from '../types';

type CardState = 'on' | 'off' | 'locked' | 'blocked' | null;

interface LandingCard {
  slug: string;
  label: string;
  description: string;
  icon: string;
  state: CardState;
  /** Why the module stands down, when something else has taken over its job. */
  blockedBy?: string | null;
}

export function moduleState(m: ModuleDescriptor): CardState {
  if (m.locked) return 'locked';
  // Something else on the site has taken over this job, so the module is not
  // merely off: switching it on would be refused. Said on the card, so the
  // group page does not look like three ordinary switches.
  if (m.blocked_by) return 'blocked';
  // The descriptor's `active`, same as the sidebar badge and the Overview
  // pills. Reading `settings.enabled` here made this page disagree with the
  // rail that navigates to it: the badge said "Cache 3" while the landing
  // counted 2, because page caching stores its switch in the global option
  // and has no `enabled` key of its own. (#363)
  if (typeof m.active === 'boolean') return m.active ? 'on' : 'off';
  return null;
}

function StatePill({ state }: { state: CardState }) {
  if (state === 'blocked')
    return (
      <span className="inline-flex items-center gap-1 rounded-pill px-2 py-0.5 text-[11px] font-medium bg-warning-tint text-warning">
        <Ban size={11} aria-hidden="true" /> {__('Not in use')}
      </span>
    );
  if (state === 'locked')
    return (
      <span className="inline-flex items-center gap-1 rounded-pill px-2 py-0.5 text-[11px] font-medium bg-primary-tint text-primary">
        <Lock size={11} aria-hidden="true" /> {__('Pro')}
      </span>
    );
  if (state === 'on')
    return (
      <span className="inline-flex items-center gap-1.5 rounded-pill px-2 py-0.5 text-[11px] font-medium bg-success-tint text-success">
        <span className="w-1.5 h-1.5 rounded-full bg-success" aria-hidden="true" /> {__('On')}
      </span>
    );
  if (state === 'off')
    return <span className="rounded-pill px-2 py-0.5 text-[11px] font-medium bg-panel text-muted">{__('Off')}</span>;
  return null;
}

/**
 * Add-ons whose landing card glows until they are in use (DESIGN.md §24.51).
 * Cloudflare Enterprise launched in 1.4.0 as its own purchase and read as just
 * another Network card. A card that is on, or stood down because something
 * else does its job, is drawn plainly.
 */
const HIGHLIGHTED_ADDONS = ['cloudflare-enterprise'];

export function isHighlightedAddon(slug: string, state: CardState): boolean {
  return HIGHLIGHTED_ADDONS.includes(slug) && (state === 'locked' || state === 'off');
}

/**
 * Redesign v2 group landing (PR 1). Renders the group's cards from the real
 * module registry (Option B: a card opens the module's existing host page).
 * The PR-2 design polish (auto-fill grid, hover-lift spec) layers on top; this
 * establishes the route + parity so every module stays reachable.
 */
export function GroupLanding({
  groupId,
  modules,
  onOpen,
}: {
  groupId: string;
  modules: ModuleDescriptor[];
  onOpen: (slug: string) => void;
}) {
  const group = groupById(groupId);
  if (!group) return null;

  const cards: LandingCard[] = group.slugs
    .map((slug): LandingCard | null => {
      if (slug === 'logs') {
        return { slug, label: __('Logs'), description: __('Recent cache and settings activity.'), icon: 'Activity', state: null };
      }
      const m = modules.find((mod) => mod.slug === slug);
      if (!m) return null;
      return {
        slug,
        label: m.label ?? slug,
        description: m.description ?? '',
        icon: m.icon ?? 'Square',
        state: moduleState(m),
        blockedBy: m.blocked_by ?? null,
      };
    })
    .filter((c): c is LandingCard => c !== null);

  // A blocked module counts as off: it is not running.
  const total = cards.filter((c) => c.state !== null).length;
  const on = cards.filter((c) => c.state === 'on').length;

  return (
    <div>
      <p className="text-[12px] font-medium uppercase tracking-wide text-muted">{group.label}</p>
      <div className="mt-1 flex items-baseline gap-3 flex-wrap">
        <h1 className="font-display text-[22px] font-semibold text-ink">{group.description ?? group.label}</h1>
        {total > 0 && (
          <span className="text-[13px] text-muted tabular-nums">
            {/* translators: on / total enabled features in this group */}
            {on} {__('of')} {total} {__('on')}
          </span>
        )}
      </div>

      <div className="mt-5 grid gap-4 grid-cols-[repeat(auto-fill,minmax(260px,1fr))]">
        {cards.map((c) => {
          const glow = isHighlightedAddon(c.slug, c.state);
          return (
            <button
              key={c.slug}
              type="button"
              onClick={() => onOpen(c.slug)}
              title={c.blockedBy ?? undefined}
              className={`flex flex-col items-stretch justify-start text-left rounded-card border border-line bg-paper p-4 transition-all hover:border-line-strong hover:-translate-y-0.5 hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary${
                c.state === 'blocked' ? ' opacity-60' : ''
              }${glow ? ' xs-addon-glow' : ''}`}
            >
              <div className="flex items-start justify-between gap-2">
                <span
                  className={`inline-flex items-center justify-center w-9 h-9 rounded-control ${
                    glow ? 'bg-addon-tint text-addon' : 'bg-panel text-ink-soft'
                  }`}
                >
                  {c.icon === 'Activity' ? <Activity size={17} /> : <ModuleIcon name={c.icon} size={17} />}
                </span>
                <StatePill state={c.state} />
              </div>
              <p className="mt-3 text-[15px] font-semibold text-ink">{c.label}</p>
              {c.description && (
                <p
                  className={`mt-1 text-[13px] leading-relaxed text-ink-soft${
                    group.fullDescription ? '' : ' line-clamp-2'
                  }`}
                >
                  {c.description}
                </p>
              )}
            </button>
          );
        })}
      </div>
    </div>
  );
}
