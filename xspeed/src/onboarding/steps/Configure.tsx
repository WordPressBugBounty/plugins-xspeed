import { useMemo, useState } from 'react';
import {
  Settings as SettingsIcon,
  ArrowRight,
  ArrowLeft,
  Shield,
  Zap,
  Flame,
  AlertTriangle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Toggle } from '../../components/Toggle';
import {
  HeroHeader,
  PrimaryButton,
  GhostLink,
  WizardShell,
} from '../components/WizardShell';
import type { OnboardingChoices, OnboardingEnv } from '../../types';

const expiryOptions = [
  { value: 12, label: '12 hours' },
  { value: 24, label: '24 hours' },
  { value: 24 * 7, label: '7 days' },
  { value: 24 * 30, label: '30 days' },
];

type PresetId = 'conservative' | 'balanced' | 'aggressive' | 'custom';

interface Preset {
  id: PresetId;
  label: string;
  description: string;
  icon: LucideIcon;
  choices: Partial<OnboardingChoices>;
}

/**
 * Every settings key a preset can define — all of OnboardingChoices except
 * `usage_tracking`, which is a consent question rather than site state.
 */
type PresetChoices = Omit<OnboardingChoices, 'usage_tracking'>;

/**
 * The Balanced preset's settings, hoisted into a named constant so the
 * wizard's fresh-install defaults and this card's highlight read the SAME
 * object.
 *
 * They used to be two hand-maintained copies. Any drift between them made
 * matchPreset() fall through to 'custom' — which has no card — leaving the
 * Configure step with all three presets unselected. The explicit
 * PresetChoices annotation means dropping a key here is a compile error
 * rather than a silently unselected screen. (#284)
 */
const BALANCED_CHOICES: PresetChoices = {
  cache_enabled: true,
  minify_html: true,
  minify_css: true,
  minify_js: false,
  defer_js: false,
  gzip_enabled: true,
  lazy_images: true,
  browser_cache: true,
  resource_hints: true,
  cache_expiry: 24 * 7,
};

/**
 * Three opinionated starting points. Picking one fills the toggles
 * below; the user can still flip individual settings afterward (which
 * transitions the active preset to "custom" so we never show a
 * misleading "Balanced" pill against off-template state).
 *
 * Defaults are deliberately conservative — even "Aggressive" doesn't
 * touch behaviorally risky things like delay-JS or remove-unused-CSS,
 * which we leave for the per-module panels with proper exclude lists.
 */
const PRESETS: Preset[] = [
  {
    id: 'conservative',
    label: 'Conservative',
    description: 'Page cache + GZIP only. No minify, no lazy-load, no browser cache headers. Safest on themes with finicky CSS / JS.',
    icon: Shield,
    // Annotated for the same reason BALANCED_CHOICES is: an omitted key is
    // not "leave it alone", it is "keep whatever the previous preset set".
    // resource_hints went missing here when hints were wired into the wizard
    // (Balanced and Aggressive got the key, Conservative did not), so picking
    // Conservative left LCP preload + preconnect ON against a card that reads
    // "Page cache + GZIP only". (#438)
    choices: {
      cache_enabled: true,
      minify_html: false,
      minify_css: false,
      minify_js: false,
      defer_js: false,
      gzip_enabled: true,
      lazy_images: false,
      browser_cache: false,
      resource_hints: false,
      cache_expiry: 12,
    } satisfies PresetChoices,
  },
  {
    id: 'balanced',
    label: 'Balanced',
    description: 'Cache + GZIP + safe minify (HTML/CSS only) + lazy images + browser cache headers + LCP image preload & font preconnect, with a 7-day cache lifetime. Recommended for most sites.',
    icon: Zap,
    choices: BALANCED_CHOICES,
  },
  {
    id: 'aggressive',
    label: 'Aggressive',
    description: 'Everything Balanced does, plus JS minify + defer non-critical JS. Test after enabling — some themes break.',
    icon: Flame,
    // Balanced plus the two risky toggles — spelled as a delta so the shared
    // keys cannot drift apart from Balanced.
    choices: { ...BALANCED_CHOICES, minify_js: true, defer_js: true },
  },
];

/**
 * Human labels for the toggles the wizard writes, used to name exactly
 * what Apply is about to switch off. "minify_js" means nothing to the
 * person who is about to lose it.
 */
const TOGGLE_LABELS: Partial<Record<keyof OnboardingChoices, string>> = {
  cache_enabled: 'Enable Page Cache',
  minify_html: 'Minify HTML',
  minify_css: 'Minify CSS',
  minify_js: 'Minify JS',
  defer_js: 'Defer JavaScript',
  gzip_enabled: 'Enable GZIP Compression',
  lazy_images: 'Lazy-load Images',
  browser_cache: 'Browser cache headers',
  resource_hints: 'LCP image preload + preconnect',
};

/**
 * Which currently-DISABLED options this Apply would switch ON.
 *
 * The mirror of losses(). It did not matter while the wizard opened showing
 * the site's own settings — nothing was silently turned on, because the
 * toggles started where the site was. Now that the wizard always opens on a
 * preset, Apply writes the whole preset, and the banner is the only thing
 * standing between the admin and a change they did not ask for. Reporting
 * only one direction meant an option they had deliberately switched off
 * (lazy images, say) came back on with no mention at all. (#284 B2)
 */
function gains(
  current: Partial<OnboardingChoices> | null | undefined,
  next: OnboardingChoices,
): string[] {
  if (!current) return [];
  return (Object.keys(TOGGLE_LABELS) as (keyof OnboardingChoices)[])
    .filter((key) => current[key] === false && next[key] === true)
    .map((key) => TOGGLE_LABELS[key] as string);
}

/**
 * Which currently-enabled options this Apply would switch OFF.
 *
 * The wizard is re-runnable and its Apply writes every key unconditionally,
 * so without this the admin got no signal that continuing would undo their
 * configuration — the completion screen even reported success. Naming the
 * options makes the cost visible before the click, not after.
 */
function losses(
  current: Partial<OnboardingChoices> | null | undefined,
  next: OnboardingChoices,
): string[] {
  if (!current) return [];
  return (Object.keys(TOGGLE_LABELS) as (keyof OnboardingChoices)[])
    .filter((key) => current[key] === true && next[key] !== true)
    .map((key) => TOGGLE_LABELS[key] as string);
}

/**
 * Render an expiry in the units the wizard's own buttons use.
 *
 * Days, never weeks. The buttons offer "7 days" and "30 days", so rendering
 * 168 as "1 week" put the same value on screen three different ways at once
 * — "1 week" in the banner, "7 days" on the button, "7-day" in the preset
 * card — and left the reader to work out they were the same number. (#284 B4)
 */
function expiryLabel(hours: number): string {
  if (hours >= 24 && hours % 24 === 0) {
    const days = hours / 24;
    return `${days} day${days === 1 ? '' : 's'}`;
  }
  return `${hours} hour${hours === 1 ? '' : 's'}`;
}

/**
 * Which non-boolean values this Apply would overwrite.
 *
 * losses() only walks TOGGLE_LABELS, so it is blind to cache_expiry — and
 * the Cache schema allows 1–720 hours while the wizard offers exactly four
 * presets. Any other stored value (a perfectly legal `48`) matches no
 * button, renders nothing as active, and is silently rewritten to whichever
 * preset the user clicks. That is the same silent overwrite this screen
 * exists to prevent, just for the one field that isn't a toggle, so it needs
 * naming too — with value-change wording rather than on→off.
 */
function valueChanges(
  current: Partial<OnboardingChoices> | null | undefined,
  next: OnboardingChoices,
): string[] {
  if (!current) return [];
  const from = current.cache_expiry;
  const to = next.cache_expiry;
  if (typeof from !== 'number' || typeof to !== 'number' || from === to) return [];
  return [`Cache expiry: ${expiryLabel(from)} → ${expiryLabel(to)}`];
}

function matchPreset(c: OnboardingChoices): PresetId {
  const choices = c as unknown as Record<string, unknown>;
  const matches = PRESETS.find(
    (p) => Object.entries(p.choices).every(([k, v]) => choices[k] === v)
  );
  return matches?.id ?? 'custom';
}

/**
 * The preset a fresh install starts on, and the one whose card says
 * "Recommended for most sites".
 *
 * Exported so deriveInitialChoices() seeds from the SAME object the card
 * matches against. They used to be two hand-maintained copies of the same
 * values, which is a standing invitation for them to drift apart — and when
 * they do, matchPreset() returns 'custom', which has no card, so the screen
 * renders all three presets inactive. (#284)
 */
export const RECOMMENDED_PRESET_ID: PresetId = 'balanced';

/**
 * The recommended preset's settings, as the single source both the wizard's
 * defaults and the card highlight read.
 *
 * Annotated `PresetChoices` rather than cast to it: dropping a key from
 * BALANCED_CHOICES below is then a compile error here, instead of seeding
 * `undefined` into a boolean field and silently reintroducing #284.
 */
export function recommendedPresetChoices(): PresetChoices {
  return { ...BALANCED_CHOICES };
}

export function ConfigureStep({
  env,
  initial,
  current,
  onApply,
  onSkip,
  onBack,
  busy,
}: {
  env: OnboardingEnv;
  initial: OnboardingChoices;
  /** The site's live values, for the "this will turn off…" warning. */
  current?: Partial<OnboardingChoices> | null;
  onApply: (choices: OnboardingChoices) => void;
  onSkip: () => void;
  onBack?: () => void;
  busy: boolean;
}) {
  const [choices, setChoices] = useState<OnboardingChoices>(initial);
  const set = <K extends keyof OnboardingChoices>(key: K, value: OnboardingChoices[K]) =>
    setChoices((c) => ({ ...c, [key]: value }));
  const applyPreset = (p: Preset) =>
    setChoices((c) => ({ ...c, ...p.choices }));

  const activePreset = useMemo(() => matchPreset(choices), [choices]);
  const willTurnOff = useMemo(() => losses(current, choices), [current, choices]);
  const willTurnOn = useMemo(() => gains(current, choices), [current, choices]);
  const willChange = useMemo(() => valueChanges(current, choices), [current, choices]);

  // Include the stored expiry when it isn't one of the four presets, so it
  // renders as the active option instead of leaving them all unselected.
  const expiryPresets = useMemo(() => {
    const stored = choices.cache_expiry;
    if (typeof stored !== 'number' || expiryOptions.some((o) => o.value === stored)) {
      return expiryOptions;
    }
    return [...expiryOptions, { value: stored, label: expiryLabel(stored) }].sort(
      (a, b) => a.value - b.value,
    );
  }, [choices.cache_expiry]);

  return (
    <WizardShell
      step="configure"
      hero={
        <HeroHeader
          icon={SettingsIcon}
          title="Pick a starting point"
          subtitle="Choose a preset to fill the toggles below — or skip and configure each one yourself. You can change everything later."
        />
      }
      footer={
        <>
          {onBack && (
            <GhostLink onClick={onBack}>
              <ArrowLeft size={14} /> Back
            </GhostLink>
          )}
          <GhostLink onClick={onSkip}>Skip — I'll configure manually</GhostLink>
          <PrimaryButton onClick={() => onApply(choices)} disabled={busy}>
            {busy ? 'Applying…' : 'Apply & Continue'}
            {!busy && <ArrowRight size={14} />}
          </PrimaryButton>
        </>
      }
    >
      {(willTurnOff.length > 0 || willTurnOn.length > 0 || willChange.length > 0) && (
        <div
          role="status"
          className="flex gap-2.5 rounded-card border border-warning bg-warning-tint p-3.5 text-[13px] text-ink"
        >
          <AlertTriangle size={16} className="mt-px shrink-0 text-warning" aria-hidden="true" />
          <div className="m-0">
            {willTurnOff.length > 0 && (
              <p className="m-0">
                Applying will turn off <strong>{willTurnOff.join(', ')}</strong>, which{' '}
                {willTurnOff.length === 1 ? 'is' : 'are'} currently on. Switch{' '}
                {willTurnOff.length === 1 ? 'it' : 'them'} back on below to keep{' '}
                {willTurnOff.length === 1 ? 'it' : 'them'}.
              </p>
            )}
            {willTurnOn.length > 0 && (
              <p className={willTurnOff.length > 0 ? 'm-0 mt-1.5' : 'm-0'}>
                Applying will turn on <strong>{willTurnOn.join(', ')}</strong>, which{' '}
                {willTurnOn.length === 1 ? 'is' : 'are'} currently off.
              </p>
            )}
            {willChange.length > 0 && (
              <p className={willTurnOff.length > 0 || willTurnOn.length > 0 ? 'm-0 mt-1.5' : 'm-0'}>
                Applying will also change <strong>{willChange.join(', ')}</strong>.
              </p>
            )}
          </div>
        </div>
      )}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
        {PRESETS.map((p) => {
          const Icon = p.icon;
          const active = activePreset === p.id;
          return (
            <button
              key={p.id}
              type="button"
              onClick={() => applyPreset(p)}
              className={`text-left rounded-card p-3.5 border transition-colors ${
                active
                  ? 'border-primary bg-primary-tint'
                  : 'border-line bg-paper hover:border-line-strong'
              }`}
              aria-pressed={active}
            >
              <Icon size={16} className={active ? 'text-primary' : 'text-muted'} />
              <div className={`text-[13px] font-medium mt-1.5 ${active ? 'text-primary' : 'text-ink'}`}>
                {p.label}
              </div>
              <div className="text-[11px] text-muted mt-0.5 leading-snug">
                {p.description}
              </div>
            </button>
          );
        })}
      </div>

      {/*
        The wizard always opens on Balanced, so this is now reached in one
        way only: a conflicting cache plugin forces page cache off, which no
        preset matches. 'custom' is a real state with no card, so without
        this the screen would show three inactive presets and no explanation.
        (It also covers the user unticking a toggle by hand.) (#284)
      */}
      {activePreset === 'custom' && (
        <div className="text-[11px] text-muted -mt-1">
          These toggles don't match any preset. Pick one above to replace them, or
          keep adjusting individually.
        </div>
      )}

      <div className="border border-line bg-paper rounded-card overflow-hidden">
        <Row
          first
          label="Enable Page Cache"
          hint="Serve static HTML to non-logged-in visitors. The single biggest speed win."
          checked={choices.cache_enabled}
          onChange={(v) => set('cache_enabled', v)}
        />
        <Row
          label="Minify HTML"
          hint="Strip whitespace and comments from HTML output."
          checked={choices.minify_html}
          onChange={(v) => set('minify_html', v)}
        />
        <Row
          label="Minify CSS"
          hint="Compress and rewrite enqueued local stylesheets."
          checked={choices.minify_css}
          onChange={(v) => set('minify_css', v)}
        />
        <Row
          label="Minify JS"
          hint="Compress enqueued local scripts. Higher conflict risk than HTML/CSS — turn off if scripts break."
          checked={choices.minify_js}
          onChange={(v) => set('minify_js', v)}
        />
        <Row
          label="Defer JavaScript"
          hint="Add defer to enqueued <script> tags so they don't block first paint. Safest paired with Minify JS off; flip on after testing."
          checked={choices.defer_js}
          onChange={(v) => set('defer_js', v)}
        />
        <Row
          label="Enable GZIP Compression"
          hint={
            env.server.gzip_mode === 'auto'
              ? 'Reduces transfer size. We will write the .htaccess rules.'
              : `Reduces transfer size. On ${env.server.type === 'nginx' ? 'nginx' : 'this server'} you'll need to paste a snippet into your server config — we'll show it after.`
          }
          checked={choices.gzip_enabled}
          onChange={(v) => set('gzip_enabled', v)}
        />
        <Row
          label="Lazy-load Images"
          hint="Defer off-screen images + iframes until they scroll into view. Keeps the first image eager so your LCP isn't deferred."
          checked={choices.lazy_images}
          onChange={(v) => set('lazy_images', v)}
        />
        <Row
          label="Browser cache headers"
          hint="Send Cache-Control so returning visitors fetch nothing. 1-year immutable for static assets, 1-hour for HTML."
          checked={choices.browser_cache}
          onChange={(v) => set('browser_cache', v)}
        />
        <Row
          label="LCP image preload + preconnect"
          hint="Preload the largest above-the-fold image and preconnect to font hosts so the main content paints sooner. Safe on every theme — only adds resource hints, never removes anything."
          checked={choices.resource_hints ?? true}
          onChange={(v) => set('resource_hints', v)}
        />
      </div>

      <div className="border border-line bg-paper rounded-card p-4 sm:p-5">
        <label
          htmlFor="xspeed-onb-expiry"
          className="block text-[13px] font-medium text-ink mb-1.5"
        >
          Cache Expiry
        </label>
        <p className="text-[12px] text-muted mb-2.5">
          How long cached pages live before regenerating.
        </p>
        <div className="flex flex-wrap gap-2">
          {/*
            The Cache schema allows 1–720 hours but the wizard offers four
            presets, so a legitimate stored value like 48 matched no button
            and left every one unselected — the admin could not tell what was
            currently configured. Surfacing it as an extra option keeps the
            current setting visible and selectable, so re-running the wizard
            stays a no-op.
          */}
          {expiryPresets.map((opt) => {
            const active = choices.cache_expiry === opt.value;
            return (
              <button
                key={opt.value}
                type="button"
                onClick={() => set('cache_expiry', opt.value)}
                className={`text-[13px] px-3 py-1.5 rounded border transition-colors ${
                  active
                    ? 'bg-primary border-primary text-on-primary'
                    : 'border-line text-ink-soft hover:bg-panel'
                }`}
              >
                {opt.label}
              </button>
            );
          })}
        </div>
      </div>

      {/* Usage data — styled like the other config sections (no accent
          icon / focal treatment) so it reads as one option among many.
          Off until the user turns it on (#735). DESIGN.md §24.9 ConsentCard. */}
      <div className="border border-line bg-paper rounded-card p-4 sm:p-5">
        <div className="flex items-start justify-between gap-3 sm:gap-4">
          <div className="min-w-0 pr-2">
            <div className="text-[13px] font-medium text-ink mb-1.5">
              Help improve xSpeed
            </div>
            <p className="text-[12px] text-muted leading-snug">
              When you turn this on, WPDeveloper receives your email address and
              display name. After that, once a day: the site URL and name,
              WordPress &amp; PHP versions, theme, plugins, server type and your
              xSpeed settings. Never page content. Change it anytime from
              Settings → Privacy &amp; usage data.
            </p>
          </div>
          <div className="shrink-0">
            <Toggle
              checked={choices.usage_tracking}
              onChange={(v) => set('usage_tracking', v)}
              label="Share usage data"
            />
          </div>
        </div>
      </div>
    </WizardShell>
  );
}

function Row({
  label,
  hint,
  checked,
  onChange,
  first = false,
}: {
  label: string;
  hint: string;
  checked: boolean;
  onChange: (v: boolean) => void;
  first?: boolean;
}) {
  return (
    <div
      className={`px-4 sm:px-5 py-4 flex items-center justify-between gap-3 sm:gap-4 ${
        first ? '' : 'border-t border-line'
      }`}
    >
      <div className="min-w-0 pr-2">
        <div className="font-medium text-ink">{label}</div>
        <div className="text-[12px] text-muted mt-0.5">{hint}</div>
      </div>
      <div className="shrink-0">
        <Toggle checked={checked} onChange={onChange} label={label} />
      </div>
    </div>
  );
}
