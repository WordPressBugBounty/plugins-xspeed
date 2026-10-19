import { ArrowRight, KeyRound, Check, Lock } from 'lucide-react';
import { ModuleIcon } from '../components/ModuleIcon';
import { PRO_UPGRADE_URL, LICENSE_URL } from './proFeatures';
import { proDemoFor, type ProDemoMock } from './proDemos';
import { useProState } from './useTier';
import { __ } from '../i18n';

/**
 * Demonstration upsell (DESIGN.md §24.12).
 *
 * The richer sibling of §24.11 LockedSection. Where LockedSection names a
 * single Pro sub-feature, ProDemo *shows a whole cluster working*: an
 * inert mock of the Pro UI (toggles with stats, a mini chart, a code
 * preview, or format badges), a checklist of concrete capabilities, and
 * ONE state-aware CTA. It replaces the stack of near-identical lock cards
 * that used to appear when a Free tab hosted several Pro sub-parts, so the
 * page reads as an invitation instead of repeated nagging.
 *
 * Pure presentation — no state, no API, no interactivity beyond the CTA
 * link. The two CTA states match LockedSection exactly (via useProState):
 * unlicensed → "Activate your license"; not-installed → "Upgrade to Pro",
 * both in the one primary-tint treatment. Content comes from the
 * PRO_DEMOS catalog by `id`.
 */
export function ProDemo({ id }: { id: string }) {
  const demo = proDemoFor(id);
  const proState = useProState();
  const unlicensed = proState === 'unlicensed';
  if (!demo) return null;

  // Redesign v2 — one calm primary-tint treatment for both states, matching
  // LockedPanel/LockedSection so every Pro surface reads alike.
  const theme = {
    frame: 'border-line bg-primary-tint',
    iconWrap: 'bg-paper border-line text-primary',
    badge: 'text-on-tint bg-paper border-line',
    cta: 'bg-primary hover:bg-primary-hover text-on-primary',
    accent: 'text-primary',
    check: 'text-primary',
  };

  return (
    <div className={`border rounded-card overflow-hidden ${theme.frame}`}>
      {/* Header */}
      <div className="flex items-start gap-3 p-4 sm:p-5 pb-3">
        <div
          className={`shrink-0 w-9 h-9 rounded-full border flex items-center justify-center ${theme.iconWrap}`}
        >
          <ModuleIcon name={demo.icon} size={17} />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2 mb-0.5">
            <h3 className="text-[14px] font-semibold text-ink">
              {demo.title}
            </h3>
            <span
              className={`inline-flex items-center gap-1 text-[10px] uppercase tracking-wider font-medium border rounded-full px-1.5 py-0.5 ${theme.badge}`}
            >
              {unlicensed ? <KeyRound size={9} /> : <Lock size={9} />}
              <span>{unlicensed ? __('License') : __('Pro')}</span>
            </span>
          </div>
          <p className="text-[12px] text-ink-soft leading-relaxed">
            {demo.subtitle}
          </p>
        </div>
      </div>

      {/* Mock preview */}
      <div className="px-4 sm:px-5">
        <Mock mock={demo.mock} accent={theme.accent} />
      </div>

      {/* Checklist + CTA */}
      <div className="p-4 sm:p-5 pt-4">
        <ul className="space-y-2">
          {demo.points.map((p, i) => (
            <li key={i} className="flex items-start gap-2.5">
              <Check size={15} className={`shrink-0 mt-0.5 ${theme.check}`} strokeWidth={2.5} />
              <span className="text-[12px] text-ink-soft leading-relaxed">
                {p.text}
              </span>
            </li>
          ))}
        </ul>

        {unlicensed ? (
          <a
            href={LICENSE_URL}
            className={`inline-flex items-center gap-1.5 mt-4 px-3.5 py-2 rounded-md text-[12px] font-medium transition-colors ${theme.cta}`}
          >
            {__('Activate your license')}
            <ArrowRight size={12} />
          </a>
        ) : (
          <a
            href={PRO_UPGRADE_URL}
            target="_blank"
            rel="noopener noreferrer"
            className={`inline-flex items-center gap-1.5 mt-4 px-3.5 py-2 rounded-md text-[12px] font-medium transition-colors ${theme.cta}`}
          >
            {__('Upgrade to Pro')}
            <ArrowRight size={12} />
          </a>
        )}
      </div>
    </div>
  );
}

/** The inert preview. A faux "locked glass" pane over a faithful UI sketch. */
function Mock({ mock, accent }: { mock: ProDemoMock; accent: string }) {
  return (
    <div className="relative rounded-card border border-line bg-panel p-3.5 overflow-hidden">
      {/* A quiet lock chip pinned top-right marks the pane as a preview. */}
      <div className="absolute right-2 top-2 z-10 flex items-center gap-1 rounded-full bg-paper border border-line px-1.5 py-0.5 text-[9px] uppercase tracking-wider text-faint">
        <Lock size={8} />
        {__('Preview')}
      </div>
      <div aria-hidden="true" className="select-none">
        {mock.kind === 'toggles' && <TogglesMock mock={mock} accent={accent} />}
        {mock.kind === 'chart' && <ChartMock mock={mock} accent={accent} />}
        {mock.kind === 'code' && <CodeMock mock={mock} />}
        {mock.kind === 'formats' && <FormatsMock mock={mock} accent={accent} />}
      </div>
    </div>
  );
}

/** Rows of faux switches, each with a small stat chip. */
function TogglesMock({ mock, accent }: { mock: ProDemoMock; accent: string }) {
  return (
    <div className="space-y-2">
      {(mock.rows ?? []).map((r, i) => (
        <div
          key={i}
          className="flex items-center gap-3 rounded-md bg-paper border border-line px-3 py-2"
        >
          {/* Faux toggle (matches Toggle's on-state geometry). */}
          <span
            className={`relative inline-flex shrink-0 w-9 h-5 items-center rounded-full ${
              r.on ? 'bg-primary' : 'bg-line-strong'
            }`}
          >
            <span
              className={`inline-block w-4 h-4 rounded-full bg-white shadow-sm transform ${
                r.on ? 'translate-x-[18px]' : 'translate-x-0.5'
              }`}
            />
          </span>
          <span className="text-[12px] font-medium text-ink flex-1 min-w-0 truncate">
            {r.label}
          </span>
          {r.stat && (
            <span className={`text-[11px] font-medium tabular-nums ${accent}`}>{r.stat}</span>
          )}
        </div>
      ))}
    </div>
  );
}

/** A headline number + a small labelled bar block. */
function ChartMock({ mock, accent }: { mock: ProDemoMock; accent: string }) {
  const bars = mock.bars ?? [];
  return (
    <div className="flex items-end gap-4">
      {mock.headline && (
        <div className="shrink-0">
          <div className={`text-[26px] font-bold leading-none tabular-nums ${accent}`}>
            {mock.headline}
          </div>
          {mock.headlineNote && (
            <div className="text-[10px] text-muted mt-1 max-w-[130px] leading-snug">
              {mock.headlineNote}
            </div>
          )}
        </div>
      )}
      <div className="flex-1 flex items-end justify-around gap-2 h-[64px]">
        {bars.map((b, i) => {
          const last = i === bars.length - 1;
          return (
            <div key={i} className="flex flex-col items-center gap-1 flex-1 h-full justify-end">
              <div
                className={`w-full rounded-t ${
                  last ? 'bg-primary' : 'bg-line-strong'
                }`}
                style={{ height: `${Math.max(6, b.value)}%` }}
              />
              <span className="text-[9px] text-muted truncate max-w-full">
                {b.label}
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
}

/** A monospace rule/snippet preview. */
function CodeMock({ mock }: { mock: ProDemoMock }) {
  return (
    <div className="font-mono text-[11px] leading-relaxed text-ink-soft space-y-0.5 overflow-x-auto">
      {(mock.code ?? []).map((line, i) => (
        <div key={i} className="whitespace-pre">
          {line}
        </div>
      ))}
    </div>
  );
}

/** Format badges (JPEG → WebP → AVIF) with a headline saving. */
function FormatsMock({ mock, accent }: { mock: ProDemoMock; accent: string }) {
  const badges = mock.badges ?? [];
  return (
    <div className="flex items-center justify-between gap-4">
      <div className="flex items-center gap-1.5 flex-wrap">
        {badges.map((b, i) => {
          const last = i === badges.length - 1;
          return (
            <span key={i} className="flex items-center gap-1.5">
              <span
                className={`rounded-md border px-2 py-1 text-[11px] font-semibold ${
                  last
                    ? 'border-primary/40 bg-primary/10 text-primary'
                    : 'border-line bg-paper text-muted'
                }`}
              >
                {b}
              </span>
              {!last && <span className="text-faint text-[11px]">→</span>}
            </span>
          );
        })}
      </div>
      {mock.headline && (
        <div className="text-right shrink-0">
          <div className={`text-[22px] font-bold leading-none tabular-nums ${accent}`}>
            {mock.headline}
          </div>
          {mock.headlineNote && (
            <div className="text-[10px] text-muted mt-1 max-w-[130px] leading-snug">
              {mock.headlineNote}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
