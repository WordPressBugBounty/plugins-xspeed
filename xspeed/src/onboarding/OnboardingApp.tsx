import { useState } from 'react';
import { PlugZap, ArrowRight } from 'lucide-react';
import { __ } from '../i18n';
import { ConnectStep } from './steps/Connect';
import { ReviewStep } from './steps/Review';
import { ConfigureStep, recommendedPresetChoices } from './steps/Configure';
import { DoneStep } from './steps/Done';
import { api } from '../api/client';
import type {
  OnboardingChoices,
  OnboardingEnv,
  OnboardingApplyResult,
  Settings,
  HubStatus,
} from '../types';

type Step = 'review' | 'configure' | 'done' | 'connect';

export function deriveInitialChoices(env: OnboardingEnv): OnboardingChoices {
  // The wizard always opens on the Balanced preset — the one its own card
  // calls "Recommended for most sites" — on every run, whether or not the
  // site has been configured before.
  //
  // It used to seed from the site's live values instead, so that re-running
  // it showed the site as it actually was. That reads as reasonable and is
  // wrong in practice: stored settings almost never match a preset exactly,
  // so matchPreset() returned 'custom' (which has no card) and the screen
  // presented three inactive cards on the one page whose entire instruction
  // is "pick a starting point". The wizard is a "recommend me a
  // configuration" flow, not a settings editor — the per-module panels are
  // where you inspect and tweak what is currently set. (#284)
  //
  // Overwriting the admin's configuration is still guarded, just at the
  // right place: losses() / valueChanges() diff these choices against the
  // site's live values and the step renders a warning naming exactly which
  // enabled options Apply would switch off, before the click.
  // A plugin to name, or a refusal with none — an orphaned or doubly-defined
  // WP_CACHE blocks the enable with no conflicting plugin on the site. Either
  // way the wizard must not open with page caching ticked, or it offers a
  // switch it already knows will fail.
  const hasConflict = env.conflicts.length > 0 || Boolean(env.page_cache_blocked);

  return {
    // Spread the Balanced preset itself rather than re-typing its values.
    // These were two hand-maintained copies of the same settings, and any
    // drift between them puts matchPreset() back into 'custom'. (#284)
    //
    // cache_expiry rides along from the preset. It lives on the Cache module
    // now (the legacy Settings type no longer carries it); the wizard offers
    // a fresh choice each run and the apply endpoint writes through
    // Settings_Manager::update('cache', …).
    ...recommendedPresetChoices(),
    // A conflicting cache plugin forces page cache off, so the wizard can
    // never propose double-caching. This is the one case where the opening
    // state deliberately does not match Balanced, and the step surfaces it
    // as a named "custom" state rather than as three blank cards.
    ...(hasConflict ? { cache_enabled: false } : {}),
    // Usage analytics consent is a question, not site state, so it is never
    // seeded from storage and the wizard always asks afresh.
    //
    // Unticked: wordpress.org guideline 7 requires tracking to be opt-in, and
    // the payload includes the admin's email and name (#437, #735). Do not
    // pre-check it again.
    usage_tracking: false,
  };
}

export function OnboardingApp() {
  const cfg = window.XSpeedConfig;
  const env = cfg.bootstrap?.env as OnboardingEnv;
  const settings = cfg.bootstrap?.settings as Settings;
  // The site's live values for every toggle the wizard can write. These no
  // longer seed the opening state — the wizard always starts on Balanced —
  // but the Configure step diffs against them to warn, before Apply, about
  // any currently-enabled option the chosen preset would switch off. Absent
  // on an older bootstrap payload, in which case there is nothing to diff.
  const current = (cfg.bootstrap?.current ?? null) as Partial<OnboardingChoices> | null;
  const initialHub = (cfg.bootstrap?.hub ?? null) as HubStatus | null;

  // The wizard opens on the environment check. Account connect used to be a
  // pre-wizard gate shown FIRST, which asked people to sign up for an account
  // before the plugin had demonstrated any value. It now closes the wizard,
  // once caching is configured and working — so the invite lands as "keep
  // managing this from anywhere" rather than a signup wall.
  //
  // Exception: returning from the Hub. The step lives in component state, so
  // the redirect back would remount the wizard at step 1 — dropping the user at
  // the START of onboarding right after they finished its LAST step. The PHP
  // return handler (McpModule::maybe_handle_hub_return) consumes the one-time
  // `xspeed_connected` markers and hands us `?xspeed_step=connect` instead, so
  // we resume where they left off and useHubConnect's reconcile flips the step
  // to its connected state.
  const [step, setStep] = useState<Step>(() => {
    if (typeof window === 'undefined') return 'review';
    const requested = new URL(window.location.href).searchParams.get('xspeed_step');
    return requested === 'connect' ? 'connect' : 'review';
  });
  const [choices, setChoices] = useState<OnboardingChoices>(() =>
    deriveInitialChoices(env)
  );
  const [applyResult, setApplyResult] = useState<OnboardingApplyResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const dashboardUrl = cfg.dashboardUrl || '';

  // `hash` deep-links straight to a module panel (App.tsx parses `#slug`
  // / `#slug/subtab`), so a wizard card can land on the page its label
  // promises rather than dropping everyone on the default panel. (FBS-83991)
  const goDashboard = async (hash?: string) => {
    // Idempotent — safe to call even if already marked complete.
    try {
      await api.onboardingComplete();
    } catch {
      /* ignore — completion flag is non-critical */
    }
    if (dashboardUrl) {
      window.location.href = hash ? `${dashboardUrl}#${hash}` : dashboardUrl;
    }
  };

  const handleApply = async (c: OnboardingChoices) => {
    setBusy(true);
    setError(null);
    setChoices(c);
    try {
      const result = await api.onboardingApply(c);
      setApplyResult(result);
      await api.onboardingComplete();
      setStep('done');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to apply settings');
    } finally {
      setBusy(false);
    }
  };

  const handleSkip = async () => {
    try {
      await api.onboardingComplete();
    } catch {
      /* ignore */
    }
    if (dashboardUrl) window.location.href = dashboardUrl;
  };

  if (!env || !settings) {
    return (
      <div className="max-w-md mx-auto my-10 rounded-card border border-line bg-paper p-8 text-center">
        <div className="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-panel text-muted">
          <PlugZap size={22} />
        </div>
        <h1 className="font-display text-[18px] font-semibold text-ink">
          {__('Onboarding data unavailable')}
        </h1>
        <p className="mt-1.5 text-[13px] text-muted">
          {__("We couldn't load the setup wizard. Your site is unaffected — you can configure everything from the dashboard.")}
        </p>
        <a
          href={dashboardUrl}
          className="mt-5 inline-flex items-center gap-1.5 rounded-control bg-primary px-4 py-2 text-[13px] font-medium text-on-primary hover:bg-primary-hover transition-colors"
        >
          {__('Go to the dashboard')}
          <ArrowRight size={14} />
        </a>
      </div>
    );
  }

  return (
    <>
      {step === 'review' && (
        <ReviewStep env={env} onNext={() => setStep('configure')} />
      )}
      {step === 'configure' && (
        <ConfigureStep
          env={env}
          initial={choices}
          current={current}
          onApply={handleApply}
          onSkip={handleSkip}
          onBack={() => setStep('review')}
          busy={busy}
        />
      )}
      {step === 'done' && (
        <DoneStep
          choices={choices}
          applyResult={applyResult}
          onDashboard={goDashboard}
          // An attached site has nothing left to connect, and a local one
          // cannot connect (xspeed-hub#370), so for both the summary is the
          // end and the footer exits to the dashboard.
          onNext={initialHub?.attached || initialHub?.is_local ? undefined : () => setStep('connect')}
        />
      )}
      {step === 'connect' && (
        <ConnectStep
          initialHub={initialHub}
          // Wrapped: goDashboard's argument is a hash, and passing it bare
          // as a click handler turned the event into `#[object Object]` (#325).
          onNext={() => goDashboard()}
          onSkip={() => goDashboard()}
        />
      )}
      {error && (
        <div className="fixed bottom-6 left-1/2 -translate-x-1/2 bg-danger-tint border border-line text-danger text-[12px] px-4 py-2 rounded-full shadow-md z-50">
          {error}
        </div>
      )}
    </>
  );
}
