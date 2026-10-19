import { Check, AlertTriangle, ArrowRight, Sliders, BookOpen } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { HeroHeader, PrimaryButton, WizardShell } from '../components/WizardShell';
import { CacheBenchmark } from '../../components/cache/CacheBenchmark';
import { CopySnippet } from '../../components/CopySnippet';
import { NginxServerBlock } from '../../components/NginxServerBlock';
import { BrandLogo } from '../../components/BrandLogo';
import { useBranding } from '../../hooks/useBranding';
import type { OnboardingChoices, OnboardingApplyResult } from '../../types';

export function DoneStep({
  choices,
  applyResult,
  onDashboard,
  onNext,
}: {
  choices: OnboardingChoices;
  applyResult: OnboardingApplyResult | null;
  /**
   * Leave the wizard for the dashboard — used by the inline shortcut cards.
   * The optional hash deep-links to a panel (`#slug` / `#slug/subtab`) so a
   * card lands on the page its label promises. (FBS-83991)
   */
  onDashboard: (hash?: string) => void;
  /**
   * Advance to the account Connect step, which follows this summary. When it's
   * omitted (already-attached site, nothing left to offer) the footer falls
   * back to leaving for the dashboard.
   */
  onNext?: () => void;
}) {
  const branding = useBranding();
  const hasCustomLogo = !!branding.logo_svg?.trim();
  const enabled = applyResult?.settings.cache_enabled ?? choices.cache_enabled;
  const installState = applyResult?.install_state;
  const blockedReason = installState?.blocked ? installState.blocked_reason : null;
  const needsManual =
    enabled &&
    installState &&
    !installState.wp_cache_constant &&
    installState.manual_snippet;

  const summary: string[] = [];
  if (enabled) summary.push('Page cache');
  if (choices.minify_html) summary.push('HTML minify');
  if (choices.minify_css) summary.push('CSS minify');
  if (choices.minify_js) summary.push('JS minify');
  if (choices.defer_js) summary.push('Defer JS');
  if (choices.gzip_enabled) summary.push('GZIP');
  if (choices.lazy_images) summary.push('Lazy images');
  if (choices.browser_cache) summary.push('Browser cache');
  // Total optimizations we could have toggled from the wizard.
  const totalPossible = 8;
  const expiryLabel =
    choices.cache_expiry >= 24 * 7
      ? `${Math.round(choices.cache_expiry / (24 * 7))}-week`
      : choices.cache_expiry >= 24
      ? `${Math.round(choices.cache_expiry / 24)}-day`
      : `${choices.cache_expiry}-hour`;

  return (
    <WizardShell
      step="done"
      hero={
        <HeroHeader
          icon={Check}
          logo={hasCustomLogo ? <BrandLogo className="w-8 h-8 rounded" /> : undefined}
          title={blockedReason ? `${branding.name} setup needs attention` : `${branding.name} is ready`}
          subtitle={
            blockedReason
              ? 'Your other preferences were saved, but page caching was not enabled.'
              : enabled
              ? 'Caching is active. Your site is now faster for non-logged-in visitors.'
              : 'Your preferences are saved. Enable cache from the dashboard whenever you are ready.'
          }
        />
      }
      footer={
        <>
          <span />
          {/* The account invite follows this summary, so the primary action
              continues into it rather than leaving the wizard. Sites that are
              already attached get no Connect step, so it exits instead.
              Wrapped: onDashboard takes a hash, and a bare handler would
              hand it the click event (#325). */}
          <PrimaryButton onClick={onNext ?? (() => onDashboard())}>
            {onNext ? 'Continue' : 'Go to Dashboard'}
            <ArrowRight size={14} />
          </PrimaryButton>
        </>
      }
    >
      {blockedReason && (
        <div role="alert" className="border border-line bg-warning-tint rounded-card p-4 sm:p-5">
          <div className="flex items-start gap-2.5">
            <AlertTriangle size={17} className="mt-0.5 shrink-0 text-warning" aria-hidden="true" />
            <div>
              <div className="text-[13px] font-medium text-warning mb-1">Page cache was not enabled</div>
              <p className="text-[12px] text-warning">{blockedReason}</p>
            </div>
          </div>
        </div>
      )}

      {summary.length > 0 && (
        <div className="border border-line bg-paper rounded-card p-4 sm:p-5">
          <div className="grid grid-cols-2 gap-3 mb-3">
            <div>
              <div className="text-[11px] uppercase tracking-wider text-muted font-medium">
                Optimizations active
              </div>
              <div className="text-2xl font-semibold text-success tabular-nums mt-1">
                {summary.length}<span className="text-faint text-[14px] font-normal"> / {totalPossible}</span>
              </div>
            </div>
            <div>
              <div className="text-[11px] uppercase tracking-wider text-muted font-medium">
                Cache lifetime
              </div>
              <div className="text-2xl font-semibold text-ink tabular-nums mt-1">
                {expiryLabel}
              </div>
            </div>
          </div>
          <div className="text-[11px] uppercase tracking-wider text-muted font-medium mb-2">
            Enabled
          </div>
          <div className="flex flex-wrap gap-1.5">
            {summary.map((item) => (
              <span
                key={item}
                className="text-[12px] px-2 py-0.5 rounded bg-success-tint text-success border border-line"
              >
                {item}
              </span>
            ))}
          </div>
        </div>
      )}

      <CacheBenchmark compact />

      {needsManual && (
        <div className="border border-line bg-warning-tint rounded-card p-4 sm:p-5">
          <div className="text-[13px] font-medium text-warning mb-1.5">
            One manual step
          </div>
          <p className="text-[12px] text-warning mb-2">
            Your <code>wp-config.php</code> isn't writable, so we couldn't set <code>WP_CACHE</code>. Add this line above the "That's all, stop editing!" comment:
          </p>
          <CopySnippet content={installState!.manual_snippet!} tone="amber" />
        </div>
      )}

      {/* nginx server config — the SAME unified, state-aware snippet the
          dashboard and Health insight render (Cache::full_nginx_server_block),
          via the SAME shared component. The blue intro keeps the first-run
          "why this matters" framing; the snippet itself comes from
          NginxServerBlock so all three surfaces stay in lockstep. */}
      {enabled && installState?.nginx_server_block && (
        <div className="space-y-3">
          <div className="border border-line bg-info-tint rounded-card p-4 sm:p-5">
            <div className="text-[13px] font-medium text-info mb-1.5">
              nginx detected — one-time server config
            </div>
            <p className="text-[12px] text-info">
              {branding.name} can't write nginx config (it lives outside WordPress). Paste the snippet below into your site's <code>server &#123; &#125;</code> block, then reload nginx — cache hits will be served without booting PHP at all (~5-15ms TTFB). Without this you still get the PHP drop-in path (~85ms).
            </p>
          </div>
          <NginxServerBlock snippet={installState.nginx_server_block} />
        </div>
      )}

      {/* "View your dashboard" used to sit here, but it and "Fine-tune
          performance" both called onDashboard — two labels, one destination,
          which reads as a bug. The primary button above already covers
          "take me to the dashboard", so the duplicate card is gone and each
          remaining card has a destination that matches its label. (FBS-83991) */}
      <div className={`grid grid-cols-1 gap-3 ${branding.hide_help_links ? 'sm:grid-cols-1' : 'sm:grid-cols-2'}`}>
        <NextCard
          icon={Sliders}
          title="Fine-tune performance"
          body="HTML/CSS/JS minify, lazy-load, browser cache."
          onClick={() => onDashboard('minify')}
        />
        {/* The docs link is an external help link — suppressed when the
            white-label "hide help / external links" option is on. (FBS-82222) */}
        {!branding.hide_help_links && (
          <NextCard
            icon={BookOpen}
            title="Read the docs"
            body="Per-module guides, troubleshooting, conflict matrix."
            // wpdeveloper.com/docs/xspeed 404s — the docs live on the product
            // site. (FBS-83991)
            href="https://xspeedcache.com/docs"
          />
        )}
      </div>
    </WizardShell>
  );
}

function NextCard({
  icon: Icon,
  title,
  body,
  onClick,
  href,
}: {
  icon: LucideIcon;
  title: string;
  body: string;
  onClick?: () => void;
  href?: string;
}) {
  const cls = 'text-left border border-line bg-paper rounded-card p-4 sm:p-5 hover:border-primary transition-colors block';
  const inner = (
    <>
      <Icon size={16} className="text-primary mb-2" />
      <div className="text-[13px] font-medium text-ink">{title}</div>
      <div className="text-[12px] text-muted mt-1">{body}</div>
    </>
  );
  if (href) {
    return (
      <a href={href} target="_blank" rel="noopener noreferrer" className={cls}>
        {inner}
      </a>
    );
  }
  return (
    <button type="button" onClick={onClick} className={cls}>
      {inner}
    </button>
  );
}
