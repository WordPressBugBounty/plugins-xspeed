import { Zap } from 'lucide-react';
import { __ } from '../../i18n';
import { relativeTime } from './format';
import { ScoreCard } from './ScoreCard';
import type { Settings, Stats } from '../../types';

/**
 * Zone 1 (left) — the cache status hero (redesign v2). States the real, honest
 * state including the PHP-bypass truth, shows the site's performance score and
 * "last purged". Purge/Preload are NOT here — the persistent top bar owns those
 * headline actions now.
 *
 * The bottom slot used to hold a "cache warmth" bar, which was the 24h hit
 * ratio under a name that implied coverage — and the same number already had
 * its own stat card below the fold. It now carries the performance score,
 * which is the question this page should answer and which nothing else here
 * answered.
 */
export function HeroStatus({
  settings,
  stats,
  onNavigate,
}: {
  settings: Settings;
  stats: Stats;
  onNavigate: (slug: string) => void;
}) {
  const enabled = settings.cache_enabled;
  // The setting is what the user asked for; `page_cache_serving` is what the
  // site is doing. They come apart when another cache plugin owns
  // advanced-cache.php — and this is the page people land on, so reading the
  // setting alone announced "Cache active · Serving from the PHP cache" over a
  // site answering every request from W3 Total Cache, with the honest amber
  // state sitting one click away on the Page Cache card. An older payload
  // omits the field: that is "no verdict", not "not serving".
  const serving = stats.page_cache_serving ?? enabled;
  const stalled = enabled && !serving;

  // Only the unhealthy copy survives — the healthy "serving from X" line was
  // the heading this card no longer shows.
  const warning = !enabled
    ? __('Turn on the page cache to start serving pages instantly.')
    : stats.page_cache_blocked_reason ||
      __('Another plugin owns the page cache on this site, so xSpeed is serving nothing.');

  return (
    <section
      className="rounded-card border border-line bg-paper p-5 flex flex-col"
      aria-label={__('Cache status')}
    >
      {/* The healthy "Cache active · Serving from the PHP cache" heading is
          gone: the stat row below already reports cached pages and hit ratio,
          and the score is what this card is for now.

          The UNHEALTHY states stay. `page_cache_serving` is what the site is
          actually doing, and it comes apart from the setting when another
          plugin owns advanced-cache.php — this card once announced "Cache
          active" over a site answering every request from W3 Total Cache.
          Dropping that warning along with the heading would reintroduce
          exactly that lie, so it survives as a banner above the score. */}
      {(!enabled || stalled) && (
        <div
          className={`mb-4 flex items-start gap-2.5 rounded-lg p-3 ${
            stalled ? 'bg-warning-tint' : 'bg-panel'
          }`}
        >
          <span
            className={`inline-flex items-center justify-center w-5 h-5 rounded-full shrink-0 ${
              stalled ? 'text-warning' : 'text-faint'
            }`}
            aria-hidden="true"
          >
            <Zap size={13} />
          </span>
          <div className="min-w-0">
            <p className="text-[13px] font-semibold text-ink leading-snug">
              {stalled ? __('Cache on, but not serving') : __('Cache inactive')}
            </p>
            <p className="mt-0.5 text-[12px] text-ink-soft leading-snug">{warning}</p>
          </div>
        </div>
      )}

      <ScoreCard onOpenScore={() => onNavigate('score')} />

      <p className="mt-3 text-[12px] text-muted">
        {__('Last purged')} {stats.last_purge ? relativeTime(stats.last_purge) : __('never')}
      </p>
    </section>
  );
}
