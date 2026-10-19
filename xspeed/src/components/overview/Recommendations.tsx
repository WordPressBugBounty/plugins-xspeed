import { useEffect, useState } from 'react';
import { ArrowRight, Wand2, CalendarCheck, CheckCircle2, Zap } from 'lucide-react';
import { api } from '../../api/client';
import { useTier } from '../../upsell/useTier';
import { __ } from '../../i18n';
import type { Settings, Stats, RewriteProbe, Recommendation, ProSuggestion } from '../../types';
import { buildDeepLink } from '../deepLink';
import { AddonTag } from '../AddonTag';

/** Where the "book expert setup" offer points — the expert-setup booking page. */
const SETUP_REVIEW_URL = 'https://xspeedcache.com/support#book-a-call';

/**
 * Zone 3 — the priority recommendations zone (the only tinted zone). Renders
 * the free, actionable "next best action" recommendations, folds Pro-audit
 * findings in as calm secondary notes (Free only), and — only when the site
 * looks poorly configured — offers a free setup review as the last item.
 * Outline/ghost actions only, no upsell nag.
 */
export function Recommendations({
  settings,
  stats,
  rewriteProbe,
  showToast,
}: {
  settings: Settings;
  stats: Stats;
  rewriteProbe: RewriteProbe | null;
  // onNavigate removed: link recommendations are real anchors now, so this
  // component no longer needs to drive navigation imperatively.
  showToast: (msg: string) => void;
}) {
  const tier = useTier();
  const [recs, setRecs] = useState<Recommendation[] | null>(null);
  const [pro, setPro] = useState<ProSuggestion[]>([]);
  const [loading, setLoading] = useState(true);
  const [applying, setApplying] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    const jobs: Promise<void>[] = [
      api
        .getRecommendations({ contributed: true })
        .then((r) => {
          if (active) setRecs(r.recommendations ?? []);
        })
        .catch(() => {
          if (active) setRecs([]);
        }),
    ];
    if (tier === 'free') {
      jobs.push(
        api
          .getProAudit()
          .then((r) => {
            if (active) setPro(r.suggestions ?? []);
          })
          .catch(() => undefined),
      );
    }
    Promise.all(jobs).finally(() => {
      if (active) setLoading(false);
    });
    return () => {
      active = false;
    };
  }, [tier]);

  /**
   * Deep link for a `link` recommendation.
   *
   * Built with the shared helper rather than read off a `hash` field: the
   * server sends `module` / `subtab` / `focus` / `suggest` (Deep_Link::action),
   * and the old code guarded on an `action.hash` that has never existed — so
   * every link recommendation was a no-op click. Going through buildDeepLink
   * also preserves the `focus` half, which is what lands the user ON the
   * control rather than merely on the page that contains it (§24.24).
   */
  const linkHref = (rec: Recommendation): string =>
    rec.action.module
      ? buildDeepLink({
          module: rec.action.module,
          subtab: rec.action.subtab,
          focus: rec.action.focus,
          suggest: rec.action.suggest,
        })
      : '#';

  const runAction = async (rec: Recommendation) => {
    const { action } = rec;
    if (action.type === 'apply') {
      if (applying) return;
      setApplying(rec.id);
      try {
        const res = await api.applyRecommendation(rec.id);
        // The apply route answers with the native list only, so read the
        // card's own list again or contributed entries would vanish until
        // the next load.
        const full = await api.getRecommendations({ contributed: true }).catch(() => null);
        setRecs(full?.recommendations ?? res.recommendations ?? []);
        showToast(__('Applied'));
      } catch {
        showToast(__('Could not apply that fix'));
      } finally {
        setApplying(null);
      }
    }
  };

  // "Poorly configured" heuristics for the conditional expert offer.
  const traffic = (stats.hits_24h ?? 0) + (stats.misses_24h ?? 0);
  // A low ratio is not evidence of a poorly configured site while the
  // LiteSpeed Static Fast Path is on — its hits are served below the
  // counter, so the number is structurally low by choice. (QA on #513)
  const lowRatio = traffic >= 50 && (stats.hit_ratio ?? 0) < 0.5 && !stats.static_hits_uncounted;
  const rewriteInactive = rewriteProbe ? rewriteProbe.active === false : false;
  const showExpertOffer = !settings.cache_enabled || rewriteInactive || lowRatio;

  const hasContent = (recs && recs.length > 0) || pro.length > 0 || showExpertOffer;

  return (
    <section
      className="rounded-card border border-line bg-info-tint p-4 sm:p-5"
      aria-label={__('Recommended for your site')}
    >
      <h2 className="flex items-center gap-1.5 text-[14px] font-semibold text-on-tint mb-3">
        <Zap size={15} className="text-primary" aria-hidden="true" />
        {__('Recommended for your site')}
      </h2>

      {loading ? (
        <div className="space-y-2" aria-busy="true">
          <span className="block h-10 rounded-lg bg-paper animate-pulse" />
          <span className="block h-10 w-5/6 rounded-lg bg-paper animate-pulse" />
        </div>
      ) : !hasContent ? (
        <p className="flex items-center gap-2 text-[13px] text-ink-soft">
          <CheckCircle2 size={15} className="text-success" aria-hidden="true" />
          {__("You're all set — no recommendations right now.")}
        </p>
      ) : (
        <div className="space-y-2">
          {/* Free, actionable recommendations. */}
          {(recs ?? []).map((rec) => (
            <div
              key={rec.id}
              className="flex items-start gap-3 rounded-lg border border-line bg-paper px-3 py-2.5"
            >
              <div className="min-w-0 flex-1">
                <p className="text-[13px] font-medium text-ink">
                  {rec.title}
                  {rec.tag && <AddonTag label={rec.tag} className="ml-2 align-[1px]" />}
                </p>
                <p className="mt-0.5 text-[12px] leading-relaxed text-ink-soft">
                  {rec.detail}
                </p>
              </div>
              {/* A link action is an anchor, not a button: it carries a real
                  href, so it deep-links exactly like a notice CTA (§24.24) and
                  supports middle-click / open-in-new-tab. Apply stays a button
                  because it POSTs. This is the same split NextBestAction uses
                  (§24.15) — the two slots render the same payload, so they
                  should consume it the same way. */}
              {rec.action.type === 'link' ? (
                <a
                  href={linkHref(rec)}
                  className="shrink-0 inline-flex items-center gap-1.5 rounded border border-line-strong px-2.5 py-1 text-[12px] font-medium text-ink-soft hover:bg-panel transition-colors no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                >
                  <ArrowRight size={13} />
                  {rec.action.label}
                </a>
              ) : (
                <button
                  type="button"
                  onClick={() => runAction(rec)}
                  disabled={applying === rec.id}
                  className="shrink-0 inline-flex items-center gap-1.5 rounded border border-line-strong px-2.5 py-1 text-[12px] font-medium text-ink-soft hover:bg-panel transition-colors disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                >
                  <Wand2 size={13} className={applying === rec.id ? 'animate-pulse' : ''} />
                  {rec.action.label}
                </button>
              )}
            </div>
          ))}

          {/* Pro-audit findings — calm, secondary notes (no CTA button). */}
          {pro.length > 0 && (
            <div className="pt-1">
              <p className="px-1 pb-1 text-[11px] font-semibold uppercase tracking-wide text-muted">
                {__('Also worth a look')}
              </p>
              <div className="space-y-1.5">
                {pro.map((s) => (
                  <div key={s.id} className="flex items-start gap-2.5 px-1">
                    <span
                      className={`mt-1.5 w-1.5 h-1.5 rounded-full shrink-0 ${
                        s.severity === 'high'
                          ? 'bg-warning'
                          : s.severity === 'med'
                          ? 'bg-warning'
                          : 'bg-line-strong'
                      }`}
                      aria-hidden="true"
                    />
                    <p className="text-[12px] leading-relaxed text-ink-soft">
                      {s.reason}
                      {s.fact && (
                        <span className="ml-1.5 text-muted">· {s.fact}</span>
                      )}
                    </p>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Conditional expert offer — last item, only when poorly configured. */}
          {showExpertOffer && (
            <div className="flex items-start gap-3 rounded-lg border border-line bg-paper px-3 py-2.5">
              <CalendarCheck
                size={16}
                className="mt-0.5 shrink-0 text-ink-soft"
                aria-hidden="true"
              />
              <div className="min-w-0 flex-1">
                <p className="text-[13px] font-medium text-ink">
                  {__('Want a hand?')}
                </p>
                <p className="mt-0.5 text-[12px] leading-relaxed text-ink-soft">
                  {__('Book a free setup review with our team.')}
                </p>
              </div>
              <a
                href={SETUP_REVIEW_URL}
                target="_blank"
                rel="noopener noreferrer"
                className="shrink-0 inline-flex items-center gap-1.5 rounded border border-line-strong px-2.5 py-1 text-[12px] font-medium text-ink-soft hover:bg-panel transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
              >
                {__('Book')}
              </a>
            </div>
          )}
        </div>
      )}
    </section>
  );
}
