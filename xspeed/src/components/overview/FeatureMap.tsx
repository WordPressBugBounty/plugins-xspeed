import { useState } from 'react';
import { Lock, ArrowRight } from 'lucide-react';
import { ModuleIcon } from '../ModuleIcon';
import { AddonTag } from '../AddonTag';
import { useProState } from '../../upsell/useTier';
import { groupSidebarModules } from '../sidebarGroups';
import { __ } from '../../i18n';
import type { ModuleDescriptor } from '../../types';

/** How many modules to show per group in the curated (collapsed) view. */
const CURATED_PER_GROUP = 3;

/**
 * The curated "Your setup" columns — a focused snapshot, NOT the full map:
 * the four groups a site owner tunes day-to-day, each with its top modules +
 * live state. Health & insights, AI & agents, and Settings are deliberately
 * left out here (they live in the sidebar / "browse all"). Expanding reveals
 * every group as the full feature map.
 */
const CURATED_GROUP_IDS = ['cache', 'performance', 'network', 'tools'];

type FeatureState = 'on' | 'off' | 'locked' | 'needs' | null;

function featureState(m: ModuleDescriptor, proUnlicensed: boolean): FeatureState {
  if (m.locked) return 'locked';
  // The add-on tag is only for a separate purchase (§24.50). While Pro itself
  // is unlicensed, a Pro module's label starts with Pro, so it takes the lock.
  if (proUnlicensed && m.tier === 'pro' && m.status_label) return 'locked';
  // Something left to buy or activate (e.g. "Add-on needed"). The module's
  // own page header already shows this in place of On/Off; the row has to
  // agree with it, or a module that cannot run yet reads as merely "Off".
  if (m.status_label) return 'needs';
  // Same source as the sidebar's "N on" badge: the descriptor's `active`,
  // which each module computes server-side. Reading `settings.enabled` here
  // showed no pill at all for page caching, Minify, Lazy and MCP — none of
  // them store that key — so the card said nothing about the features most
  // likely to be on. (#363)
  if (typeof m.active === 'boolean') return m.active ? 'on' : 'off';
  return null;
}

function StatePill({ state }: { state: FeatureState }) {
  if (state === 'locked') {
    return (
      <span className="inline-flex items-center gap-1 text-[11px] text-ink-soft">
        <Lock size={11} aria-hidden="true" />
        {__('Pro')}
      </span>
    );
  }
  if (state === 'on') {
    return (
      <span className="inline-flex items-center gap-1.5 text-[11px] text-success">
        <span className="w-1.5 h-1.5 rounded-full bg-success" aria-hidden="true" />
        {__('On')}
      </span>
    );
  }
  if (state === 'off') {
    return <span className="text-[11px] text-muted">{__('Off')}</span>;
  }
  return null;
}

/**
 * Zone 4 — "Your setup". A CURATED snapshot: four day-to-day groups (Cache,
 * Optimization, Network, Tools), each showing its top few modules with a live
 * On / Off / Pro state pill that deep-links to the module. "Browse all N
 * features →" expands to the full feature map (every group, every module).
 * Reuses groupSidebarModules so grouping/order never drifts from the sidebar.
 */
export function FeatureMap({
  modules,
  onNavigate,
}: {
  modules: ModuleDescriptor[];
  onNavigate: (slug: string) => void;
}) {
  const [showAll, setShowAll] = useState(false);
  const proUnlicensed = useProState() === 'unlicensed';
  const allGroups = groupSidebarModules(modules);
  // Curated view: only the four everyday groups, in their canonical order.
  const groups = showAll
    ? allGroups
    : CURATED_GROUP_IDS.map((id) => allGroups.find((g) => g.id === id)).filter(
        (g): g is NonNullable<typeof g> => !!g,
      );
  /*
   * Count what the expansion actually renders, not what arrived in the
   * payload (#361). `groupSidebarModules` deliberately drops embedded Pro
   * modules -- they live as a section inside a host page via ProSlot, and
   * are excluded from the "Other" orphan bucket precisely so they don't
   * reappear as standalone rows. Counting `modules.length` promised rows
   * that could never render: "Browse all 46" opened a list of 26.
   *
   * Deriving both from `allGroups` is the same rule the grouping already
   * follows -- one source, so the label and the list cannot drift apart.
   */
  const total = allGroups.reduce((n, g) => n + g.modules.length, 0);

  return (
    <section aria-label={__('Your setup')}>
      <div className="flex items-center justify-between mb-3">
        <h2 className="text-[14px] font-semibold text-ink">
          {__('Your setup')}
        </h2>
        {total > 0 && (
          <button
            type="button"
            onClick={() => setShowAll((v) => !v)}
            className="inline-flex items-center gap-1 text-[12px] text-ink-soft hover:text-ink underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded"
            aria-expanded={showAll}
          >
            {showAll ? __('Show less') : `${__('Browse all')} ${total} ${__('features')}`}
            {!showAll && <ArrowRight size={12} aria-hidden="true" />}
          </button>
        )}
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-5 gap-y-5">
        {groups.map((group) => {
          const shown = showAll ? group.modules : group.modules.slice(0, CURATED_PER_GROUP);
          if (shown.length === 0) return null;
          return (
            <div key={group.id}>
              <div className="flex items-center gap-2 mb-1.5 px-1">
                <ModuleIcon
                  name={group.icon}
                  size={13}
                  className="text-muted"
                />
                <span className="text-[11px] font-semibold uppercase tracking-wide text-muted">
                  {group.label}
                </span>
              </div>
              <div className="rounded-card border border-line bg-paper divide-y divide-line overflow-hidden">
                {shown.map((m) => (
                  <button
                    key={m.slug}
                    type="button"
                    onClick={() => onNavigate(m.slug)}
                    className="w-full flex items-center gap-3 px-3 py-2 text-left hover:bg-panel transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-inset"
                  >
                    <ModuleIcon
                      name={m.icon}
                      size={15}
                      className="shrink-0 text-ink-soft"
                    />
                    {featureState(m, proUnlicensed) === 'needs' && m.status_label ? (
                      // Under the name, not beside it: beside it the tag
                      // left "Cloudflare Enterprise" as "Cloud…".
                      <span className="flex-1 min-w-0 flex flex-col items-start gap-0.5">
                        <span className="max-w-full truncate text-[13px] text-ink-soft">{m.label}</span>
                        <AddonTag label={m.status_label} />
                      </span>
                    ) : (
                      <>
                        <span className="flex-1 min-w-0 truncate text-[13px] text-ink-soft">
                          {m.label}
                        </span>
                        <StatePill state={featureState(m, proUnlicensed)} />
                      </>
                    )}
                  </button>
                ))}
              </div>
            </div>
          );
        })}
      </div>
    </section>
  );
}
