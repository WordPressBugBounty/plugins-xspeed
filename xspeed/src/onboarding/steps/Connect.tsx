/**
 * Onboarding Step 4 — "Connect to xSpeed Hub". The LAST screen of the wizard.
 *
 * Runs after the environment review, the configure step AND the summary, so
 * the user has set caching up and seen exactly what it did before being invited
 * to connect anything. (It used to be step 1, which put an account wall in front
 * of a user who hadn't yet seen the plugin work.) A benefit-driven invite to
 * connect this site to the free xSpeed Hub, so one AI connection can manage
 * caching across all their sites. Framed as "connect" — the free account
 * auto-creates behind the scenes; we never ask the user to "sign up".
 *
 * It reuses the EXISTING Hub attach plumbing via useHubConnect — the same
 * one-click `attach_url` (which carries the admin email so connecting is one
 * click) that powers the MCP panel's Hub card. Nothing new is minted here.
 *
 * Skippable: caching works fully without the Hub, so a quiet "I'll connect
 * later" link leaves for the dashboard. When the user completes the flow in the
 * new tab and returns, the tab-return reconcile in useHubConnect flips this
 * step to its Connected state automatically. See DESIGN.md §24.x.
 */

import { useState } from 'react';
import { Sparkles, ArrowRight, Check, KeyRound, ExternalLink, RefreshCw, Zap, Globe, Gift } from 'lucide-react';
import { HeroHeader, PrimaryButton, GhostLink, WizardShell } from '../components/WizardShell';
import { WizardSkeleton } from '../components/WizardSkeleton';
import { Callout } from '../../components/Callout';
import { CopySnippet } from '../../components/CopySnippet';
import { BrandLogo } from '../../components/BrandLogo';
import { useHubConnect } from '../../hooks/useHubConnect';
import { hubLocalMessage } from '../../components/hubLocal';
import { __, sprintf } from '../../i18n';
import type { HubStatus } from '../../types';

export function ConnectStep({
  initialHub,
  onNext,
  onSkip,
}: {
  /** Hub snapshot from XSpeedConfig.bootstrap.hub — instant first paint. */
  initialHub?: HubStatus | null;
  /** Leave the wizard for the dashboard — this is the final step. */
  onNext: () => void;
  onSkip: () => void;
}) {
  const { status, connectUrl, error, attached, hasToken, busy, loaded, generateToken } = useHubConnect({
    active: true,
    initial: initialHub,
  });
  const [showToken, setShowToken] = useState(false);

  // ---- Loading state: no bootstrap Hub snapshot and the first status fetch
  // is still in flight. Show a skeleton rather than a half-built invite whose
  // Connect URL isn't ready yet. ----
  if (!loaded && !attached) {
    return <WizardSkeleton step="connect" rows={3} />;
  }

  // ---- Connected state: the site is linked; celebrate + continue. ----
  if (attached) {
    return (
      <WizardShell
        step="connect"
        hero={
          <HeroHeader
            icon={Check}
            title={__('Connected to xSpeed Hub')}
            subtitle={
              status?.account_email
                ? sprintf(__('Connected as %s. Your caching is ready to manage from anywhere.'), status.account_email)
                : __('Your caching is ready to manage from anywhere.')
            }
          />
        }
        footer={
          <>
            <span className="text-[12px] text-faint">
              {__("You're all set")}
            </span>
            {/* Connect closes the wizard, so this leaves for the dashboard
                rather than advancing to another screen. */}
            <PrimaryButton onClick={onNext}>
              {__('Go to Dashboard')}
              <ArrowRight size={14} />
            </PrimaryButton>
          </>
        }
      >
        <div className="rounded-card border border-line bg-success-tint px-4 py-3.5">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-success mb-2.5">
            {__('Now unlocked')}
          </p>
          <ul className="space-y-2">
            {[
              __('One AI connection controls the cache on every site you connect'),
              __('Purge, benchmark, preload and tune settings by asking your AI'),
              __('Manage this site from the central xSpeed Hub dashboard'),
            ].map((line) => (
              <li key={line} className="flex items-start gap-2 text-[12.5px] text-ink-soft">
                <Check size={14} className="text-success mt-0.5 shrink-0" />
                {line}
              </li>
            ))}
          </ul>
        </div>
      </WizardShell>
    );
  }

  // ---- Local site: the Hub refuses it (xspeed-hub#370). The wizard skips
  // this step for a local site; this covers landing here through the
  // `xspeed_step=connect` return marker. ----
  if (status?.is_local) {
    return (
      <WizardShell
        step="connect"
        hero={<HeroHeader icon={Globe} title={__('This site needs a public URL')} subtitle={hubLocalMessage()} />}
        footer={
          <>
            <span />
            <PrimaryButton onClick={onNext}>
              {__('Go to Dashboard')}
              <ArrowRight size={14} />
            </PrimaryButton>
          </>
        }
      >
        {null}
      </WizardShell>
    );
  }

  // ---- Invite state: promote connecting the (optional) Hub. ----
  // Layout mirrors RankMath's connect screen — centered hero, ONE soft panel
  // of benefits with a "learn more" link, then a single prominent centered CTA
  // — but built from our design tokens. See DESIGN.md §24.21.
  //
  // Copy is warm + benefit-first but strictly honest: everything named is a
  // SHIPPED capability (control/operate the cache in plain language + a single
  // Hub dashboard). "Connect" framing only — the free account auto-creates on
  // attach, so we never say "create account" / "sign up".
  return (
    <WizardShell
      step="connect"
      hero={
        <HeroHeader
          logo={<BrandLogo className="w-11 h-11 rounded-card" />}
          title={__('Run your site just by asking')}
          subtitle={__('Connect this site to your free xSpeed Hub — then manage caching here and on every other site straight from ChatGPT, Claude, or any AI assistant. Free, forever.')}
        />
      }
      footer={
        // Connect closes the wizard, so both controls exit to the dashboard —
        // the connect CTA above opens the Hub in a NEW tab, which would
        // otherwise leave this screen with no way out.
        <>
          <GhostLink onClick={onSkip}>{__("I'll connect later")}</GhostLink>
          <PrimaryButton onClick={onNext}>
            {__('Go to Dashboard')}
            <ArrowRight size={14} />
          </PrimaryButton>
        </>
      }
    >
      {error && <Callout tone="danger" title={__('xSpeed Hub error')} body={error} />}

      {/* Benefit panel — a lead line + three scannable rows. Warm + inviting,
          strictly honest (every row is a shipped capability). */}
      <div className="rounded-card bg-panel border border-line px-5 sm:px-8 py-5 sm:py-6">
        <p className="text-[13px] font-medium text-ink mb-4">
          {__('Your Hub is home base for all your sites. Once connected, you can:')}
        </p>
        <ul className="space-y-3.5">
          <li className="flex items-start gap-3">
            <Zap size={16} className="text-primary mt-0.5 shrink-0" />
            <p className="text-[13px] leading-relaxed text-ink-soft">
              <span className="font-medium text-ink">{__('Just say what you want')}</span>
              {' — '}
              <em className="not-italic text-ink">{__('“clear the cache on my store,” “warm up the homepage,” “how fast is my blog?”')}</em>
              {' — '}
              {__('and it happens.')}
            </p>
          </li>
          <li className="flex items-start gap-3">
            <Globe size={16} className="text-primary mt-0.5 shrink-0" />
            <p className="text-[13px] leading-relaxed text-ink-soft">
              <span className="font-medium text-ink">{__('Run every site from one place')}</span>
              {' — '}
              {__('no logging into each dashboard separately.')}
            </p>
          </li>
          <li className="flex items-start gap-3">
            <Gift size={16} className="text-primary mt-0.5 shrink-0" />
            <p className="text-[13px] leading-relaxed text-ink-soft">
              <span className="font-medium text-ink">{__('Get more over time')}</span>
              {' — '}
              {__('new Hub features arrive automatically, free.')}
            </p>
          </li>
        </ul>
        <p className="text-[13px] mt-4">
          <a
            href={`${status?.hub_url ?? 'https://xspeedcache.com'}`}
            target="_blank"
            rel="noreferrer"
            className="text-primary hover:underline"
          >
            {__('See everything it does.')}
          </a>
        </p>
      </div>

      {/* Single prominent centered CTA — our button tokens, not a halo pill. */}
      <div className="flex flex-col items-center gap-3 pt-2">
        <a
          href={connectUrl}
          className="inline-flex items-center justify-center gap-2 px-8 py-3 rounded-md text-[14px] font-semibold bg-primary text-on-primary hover:bg-primary-hover transition-colors shadow-sm"
        >
          <Sparkles size={16} />
          {__('Connect to xSpeed Hub')}
        </a>
        <div className="flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
          {[__('Free forever'), __('Under a minute'), __('No card, no catch')].map((t) => (
            <span
              key={t}
              className="inline-flex items-center gap-1.5 text-[11.5px] text-muted"
            >
              <Check size={13} className="text-success" />
              {t}
            </span>
          ))}
        </div>
      </div>

      {/* Token fallback for firewalled / headless sites that can't redirect. */}
      <div className="border-t border-line pt-3 text-center">
        <button
          type="button"
          onClick={() => {
            if (!hasToken) void generateToken();
            setShowToken((v) => !v);
          }}
          className="inline-flex items-center gap-1.5 text-[11.5px] text-faint hover:text-ink-soft transition-colors"
        >
          {busy === 'token' ? <RefreshCw size={12} className="animate-spin" /> : <KeyRound size={12} />}
          {__('No redirect? Connect with a token instead.')}
        </button>
        {showToken && (
          <div className="mt-3 space-y-2 text-left">
            <p className="text-[12px] text-muted">
              {__('Paste this site’s URL + token into the')}{' '}
              <a
                href={status?.add_site_url}
                target="_blank"
                rel="noreferrer"
                className="text-primary hover:underline inline-flex items-center gap-0.5"
              >
                {__('Add site form')} <ExternalLink size={11} />
              </a>{' '}
              {__('on your xSpeed Hub account.')}
            </p>
            <div>
              <div className="text-[11px] font-medium text-muted mb-1">{__('Site URL')}</div>
              <CopySnippet content={status?.site_url ?? ''} />
            </div>
            <div>
              <div className="text-[11px] font-medium text-muted mb-1">{__('Token')}</div>
              <CopySnippet content={status?.site_token ?? ''} tone="blue" />
            </div>
          </div>
        )}
      </div>
    </WizardShell>
  );
}
