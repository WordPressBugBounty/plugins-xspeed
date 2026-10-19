import { useState } from 'react';
import { Trash2, FileStack } from 'lucide-react';
import { Toggle } from '../Toggle';
import { dropinNotice, type DropinState } from '../dropinNotice';
import { StatCard } from '../StatCard';
import { StatDrilldown, type DrilldownKey } from './StatDrilldown';
import { __ } from '../../i18n';
import type { Settings, Stats } from '../../types';
import { formatBytes } from '../formatBytes';

function formatRatio(stats: Stats) {
  const total = (stats.hits_24h ?? 0) + (stats.misses_24h ?? 0);
  if (total === 0) return '—';
  return `${Math.round((stats.hit_ratio ?? 0) * 100)}%`;
}

function formatRelative(ts: number) {
  if (!ts) return 'Never';
  const diff = Math.floor(Date.now() / 1000) - ts;
  if (diff < 60) return `${diff}s ago`;
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  return `${Math.floor(diff / 86400)}d ago`;
}

export function CacheHero({
  settings,
  stats,
  onToggle,
  onPurge,
  dropin = null,
}: {
  settings: Settings;
  stats: Stats;
  onToggle: (v: boolean) => void;
  onPurge: () => void;
  /**
   * Leftover advanced-cache.php that enabling would replace, or null. Same
   * contract as the top-bar switch, and deliberately the same words — see
   * dropinNotice(). A user who declined the prompt in one place must not be
   * able to walk into the same replacement unannounced from the other.
   */
  dropin?: DropinState | null;
}) {
  // One drill-down at a time. Two open panels turn a four-card summary into
  // a wall, which is the thing the summary exists to avoid.
  const [open, setOpen] = useState<DrilldownKey | null>(null);

  // Enabling would replace a leftover drop-in, and the user has not yet
  // agreed to that. Disclosed INLINE rather than as a floating popover: this
  // is a card with room beneath the control, and DESIGN.md §23 makes inline
  // disclosure the default.
  const [confirming, setConfirming] = useState(false);
  const notice = dropinNotice(dropin);

  const handleToggle = (next: boolean) => {
    if (next && notice) {
      setConfirming(true);
      return;
    }
    setConfirming(false);
    onToggle(next);
  };

  // The setting is what the user asked for; `page_cache_serving` is what the
  // site is doing. They come apart when another cache plugin takes
  // advanced-cache.php, and reading the setting alone announced "Active —
  // serving cached HTML" over a site answering every request with
  // `X-XSpeed-Cache: BYPASS`. An older payload omits the field entirely —
  // that is "no verdict", not "not serving", so fall back to the setting.
  const serving = stats.page_cache_serving ?? settings.cache_enabled;
  const stalled = settings.cache_enabled && !serving;
  const toggle = (key: DrilldownKey) => setOpen((current) => (current === key ? null : key));

  // Hit ratio's story is the 7/30-day trend that already renders further
  // down this page. Scrolling there beats duplicating the chart in a panel.
  const showTrend = () => {
    const el = document.querySelector<HTMLElement>('[data-xspeed-anchor="hit-trend"]');
    el?.scrollIntoView({ block: 'start', behavior: 'smooth' });
  };

  return (
    <div className="space-y-6">
      {/* Focus target for the "Enable page caching" recommendation (issue
          #49) — the CTA lands on this toggle, not on the panel that has it
          somewhere. `xspeed-field` carries the scroll-margin + blink. */}
      <div
        data-field-anchor="cache:cache_enabled"
        className="xspeed-field border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4"
      >
        <div className="flex items-center gap-3 min-w-0">
          <span
            className={`w-2 h-2 rounded-full shrink-0 ${
              stalled
                ? 'bg-amber-500'
                : settings.cache_enabled
                  ? 'bg-emerald-500'
                  : 'bg-neutral-300 dark:bg-neutral-600'
            }`}
          />
          <div className="min-w-0">
            <div className="font-medium text-neutral-900 dark:text-neutral-50">Enable Cache</div>
            <div
              className={`text-[12px] ${
                stalled ? 'text-amber-700 dark:text-amber-500' : 'text-neutral-500 dark:text-neutral-400'
              }`}
            >
              {stalled
                ? 'On, but not serving — nothing is being cached'
                : settings.cache_enabled
                  ? 'Active — serving cached HTML'
                  : 'Inactive'}
            </div>
          </div>
        </div>
        <div className="flex items-center justify-end gap-3 shrink-0">
          <button
            type="button"
            onClick={onPurge}
            className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition-colors"
          >
            <Trash2 size={13} />
            Purge
          </button>
          <Toggle
            checked={settings.cache_enabled}
            onChange={handleToggle}
            size="lg"
            label="Enable Cache"
          />
        </div>
      </div>

      {/* What enabling is about to overwrite. Shown only while the user has
          not answered; confirming hands off to the same onToggle the switch
          would have called directly. */}
      {confirming && notice ? (
        <div
          role="alertdialog"
          aria-labelledby="xspeed-dropin-confirm-title"
          className="border border-line bg-info-tint rounded-lg p-4"
        >
          <div className="flex items-start gap-2.5">
            <FileStack
              size={16}
              className="text-info shrink-0 mt-0.5"
              aria-hidden="true"
            />
            <div className="min-w-0 flex-1">
              <div
                id="xspeed-dropin-confirm-title"
                className="font-medium text-on-tint text-[13px]"
              >
                {notice.title}
              </div>
              <p className="text-[12px] text-ink-soft mt-1">
                {notice.body}
              </p>
              <div className="mt-3 flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => {
                    setConfirming(false);
                    onToggle(true);
                  }}
                  style={{ height: 32, boxSizing: 'border-box' }}
                  className="flex items-center gap-1.5 whitespace-nowrap text-[12px] px-3 rounded-md !border !border-solid !border-primary bg-primary text-on-primary font-medium hover:bg-primary-hover hover:!border-primary-hover transition-colors"
                >
                  {notice.confirmLabel}
                </button>
                <button
                  type="button"
                  onClick={() => setConfirming(false)}
                  style={{ height: 32, boxSizing: 'border-box' }}
                  className="flex items-center gap-1.5 whitespace-nowrap text-[12px] px-3 rounded-md !border !border-solid !border-line bg-paper text-ink hover:bg-panel transition-colors"
                >
                  {__('Cancel')}
                </button>
              </div>
            </div>
          </div>
        </div>
      ) : null}

      {/* The reason, when the server has one. Without it "not serving" is a
          symptom the user cannot act on — this is the same sentence the
          toggle returns when it refuses, so the dashboard and the API agree. */}
      {stalled && stats.page_cache_blocked_reason ? (
        <div className="border border-amber-300 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 rounded-lg p-4 text-[13px] text-amber-900 dark:text-amber-200">
          {stats.page_cache_blocked_reason}
        </div>
      ) : null}

      <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <StatCard
          label="Cached Pages"
          value={String(stats.cached_pages)}
          onClick={() => toggle('pages')}
          expanded={open === 'pages'}
          hint={__('show which pages are cached')}
        />
        <StatCard
          label="Cache Size"
          value={formatBytes(stats.cache_size)}
          onClick={() => toggle('size')}
          expanded={open === 'size'}
          hint={__('show what is taking the space')}
        />
        <StatCard
          label="Last Purge"
          value={formatRelative(stats.last_purge)}
          onClick={() => toggle('purge')}
          expanded={open === 'purge'}
          hint={__('show what cleared the cache and why')}
        />
        <StatCard
          label={stats.edge_cache ? __('Hit Ratio · 24h · origin') : __('Hit Ratio · 24h')}
          value={formatRatio(stats)}
          onClick={showTrend}
          hint={__('jump to the hit-ratio trend')}
        />
      </div>

      {/* #118 reporting half: explain a low origin ratio behind a CDN, and
          surface the scanner/bot traffic that was kept out of the ratio, so the
          number reads honestly instead of looking like a broken cache. */}
      {(stats.edge_cache || stats.static_hits_uncounted || (stats.excluded_24h ?? 0) > 0) && (
        <div className="text-[12px] text-neutral-500 dark:text-neutral-400 space-y-1 -mt-2">
          {stats.edge_cache && (
            <p>
              {__(
                'An edge cache (Cloudflare) is in front, so this hit ratio is origin-layer only — hits served at the edge never reach the origin and aren’t counted here.'
              )}
            </p>
          )}
          {stats.static_hits_uncounted && stats.static_hits_uncounted_reason !== 'apache_static' && (
            <p>
              {__(
                'LiteSpeed Static Fast Path is on: cache hits are served by the web server with no PHP, carry no X-XSpeed-Cache header, and are not counted here — so a low ratio reflects the trade you chose, not a broken cache.'
              )}
            </p>
          )}
          {stats.static_hits_uncounted_reason === 'apache_static' && (
            <p>
              {__(
                'Apache serves most cached pages straight from disk, without PHP, and its access log records the page address rather than the cached file, so those hits cannot be counted. This ratio covers PHP-served pages only, and a low number does not mean the cache is failing.'
              )}
            </p>
          )}
          {(stats.excluded_24h ?? 0) > 0 && (
            <p>
              {__('Absorbed')}{' '}
              <span className="tabular-nums text-neutral-700 dark:text-neutral-300 font-medium">
                {(stats.excluded_24h ?? 0).toLocaleString()}
              </span>{' '}
              {__('bot, 404 and cache-warming requests in 24h — excluded from the hit ratio.')}
            </p>
          )}
        </div>
      )}

      {open && <StatDrilldown which={open} />}
    </div>
  );
}
