import { ArrowRight, ArrowUpRight, Check, ExternalLink, Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import { __ } from '../i18n';

/**
 * Purchase path (DESIGN.md §24.42).
 *
 * For a feature that takes more than one purchase, in order — today only
 * Cloudflare Enterprise, an add-on that runs inside xSpeed Cache Pro. A
 * sentence saying "it takes two purchases" was read past, and one button
 * underneath it sold only one of them. This shows each purchase as its own
 * card, side by side and joined by a plus, with its own state and, where
 * there is something to do, its own action. The offer beside it says what the
 * add-on costs and what it adds, and carries the button that buys it.
 *
 * Mirrored in Pro as `src/shared/PurchasePath.tsx` with the same class
 * strings, so Free's Tailwind build (which scans Pro's source) carries them.
 * Change both together.
 */

export type PurchaseStepState = 'done' | 'next' | 'later';

export interface PurchaseStep {
  /** What is bought, as the store names it. */
  name: string;
  /** What kind of purchase it is, e.g. "Plugin", "Add-on". */
  kind: string;
  /** One or two short sentences on what it is. */
  detail: string;
  /** The tile at the top of the card. */
  icon: ReactNode;
  /** `done` is owned and active; `next` is the one to do now; `later` waits on an earlier step. */
  state: PurchaseStepState;
  /** Short status, e.g. "Active on this site", "Not installed". */
  status: string;
  /**
   * Absent on a `done` step: nothing to do. Absent on the add-on step too,
   * whose button is the offer's.
   */
  action?: { label: string; href: string; external?: boolean };
}

export interface AddonOffer {
  /** The add-on, as the store names it. */
  name: string;
  price: string;
  period: string;
  /** One line under the price, e.g. what it is added on top of. */
  note: string;
  features: string[];
  /** Absent when there is nowhere to send the buyer. */
  cta?: { label: string; href: string };
}

export interface PurchasePathProps {
  title: string;
  /** Two steps: the first is what the second runs inside. */
  steps: [PurchaseStep, PurchaseStep];
  offer: AddonOffer;
  footer?: ReactNode;
}

const CARD = 'border border-line bg-paper rounded-card p-6';

function Step({ step, n }: { step: PurchaseStep; n: number }) {
  const { action } = step;
  return (
    <div
      className={`flex-1 flex flex-col gap-3.5 rounded-card border p-[18px] ${step.state === 'next' ? 'border-primary' : 'border-line'}`}
    >
      <div className="flex items-center justify-between">
        <span
          aria-hidden="true"
          className="w-9 h-9 rounded-control bg-panel text-primary flex items-center justify-center"
        >
          {step.icon}
        </span>
        <span className="font-mono text-[11px] uppercase text-muted">
          {n} · {step.kind}
        </span>
      </div>
      <div className="flex flex-col gap-1">
        <div className="text-[15px] font-semibold text-ink">
          <span className="sr-only">Step {n}: </span>
          {step.name}
        </div>
        <div className="text-[12.5px] leading-[1.55] text-ink-soft">{step.detail}</div>
      </div>
      <div className="mt-auto pt-3.5 border-t border-line flex flex-wrap items-center justify-between gap-2.5 min-h-[36px]">
        <span
          className={`inline-flex items-center gap-1.5 rounded-pill px-2.5 py-[3px] text-[12px] font-medium ${step.state === 'done' ? 'bg-success-tint text-success' : 'bg-panel text-muted'}`}
        >
          {step.state === 'done' && <Check size={12} strokeWidth={2.6} aria-hidden="true" />}
          {step.status}
        </span>
        {step.state === 'done' && <span className="text-[12px] text-muted">{__('Done')}</span>}
        {action && (
          <a
            href={action.href}
            className="inline-flex items-center gap-1.5 h-9 px-3.5 no-underline rounded-control bg-primary hover:bg-primary-hover text-on-primary text-[13px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            {...(action.external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
          >
            {action.label}
            {action.external ? <ArrowUpRight size={14} aria-hidden="true" /> : <ArrowRight size={14} aria-hidden="true" />}
          </a>
        )}
      </div>
    </div>
  );
}

function Offer({ offer }: { offer: AddonOffer }) {
  return (
    <section aria-labelledby="xs-purchase-offer" className={`${CARD} flex flex-col gap-[18px]`}>
      <div className="flex flex-col gap-1.5">
        <h2 id="xs-purchase-offer" className="text-[11px] font-medium uppercase tracking-[0.06em] text-muted">
          {offer.name}
        </h2>
        <div className="flex items-baseline gap-1.5">
          <span className="font-display text-[44px] leading-none font-bold tracking-[-0.02em] text-ink">{offer.price}</span>
          <span className="text-[14px] text-muted">{offer.period}</span>
        </div>
        <div className="text-[12.5px] leading-[1.55] text-ink-soft">{offer.note}</div>
      </div>
      <ul className="list-none m-0 pt-4 pb-0 px-0 border-t border-line flex flex-col gap-2.5 text-[13px] text-ink">
        {offer.features.map((feature) => (
          <li key={feature} className="flex items-start gap-2.5">
            <Check size={15} strokeWidth={2.4} className="shrink-0 mt-0.5 text-primary" aria-hidden="true" />
            {feature}
          </li>
        ))}
      </ul>
      {offer.cta && (
        <a
          href={offer.cta.href}
          target="_blank"
          rel="noopener noreferrer"
          className="mt-auto h-10 no-underline flex items-center justify-center gap-2 rounded-control bg-primary hover:bg-primary-hover text-on-primary shadow-primary-glow text-[13.5px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
          {offer.cta.label}
          <ExternalLink size={14} aria-hidden="true" />
        </a>
      )}
    </section>
  );
}

export function PurchasePath({ title, steps, offer, footer }: PurchasePathProps) {
  return (
    <div className="grid gap-5 items-stretch xl:grid-cols-[minmax(0,1fr)_340px]">
      <section aria-labelledby="xs-purchase-steps" className={`${CARD} flex flex-col gap-5`}>
        <h2 id="xs-purchase-steps" className="font-display text-[16px] font-semibold text-ink">
          {title}
        </h2>
        <ol className="list-none m-0 p-0 grid gap-3 items-stretch sm:grid-cols-[minmax(0,1fr)_28px_minmax(0,1fr)] xl:grid-cols-1 min-[1440px]:grid-cols-[minmax(0,1fr)_28px_minmax(0,1fr)]">
          <li className="flex">
            <Step step={steps[0]} n={1} />
          </li>
          {/*
          The plus: the add-on is bought on top of the plugin, not instead of
          it. The steps sit side by side, except between xl and 1440px, where
          the offer column takes the room they need and they stack.
        */}
          <li aria-hidden="true" className="flex items-center justify-center">
            <span className="w-7 h-7 rounded-full border border-line-strong bg-paper text-muted flex items-center justify-center">
              <Plus size={14} />
            </span>
          </li>
          <li className="flex">
            <Step step={steps[1]} n={2} />
          </li>
        </ol>
        {footer && <div className="border-t border-line pt-4">{footer}</div>}
      </section>
      <Offer offer={offer} />
    </div>
  );
}

export interface EdgeFlowNode {
  label: string;
  detail: string;
  icon: ReactNode;
  /** The node the feature adds, drawn in the primary tint. */
  highlight?: boolean;
  /** How the arrow into this node is drawn: `dashed` for a hop taken only sometimes. */
  via?: 'solid' | 'dashed';
}

/**
 * The "How it works" strip under the purchase path: a sentence on the left,
 * the request's path on the right. The path needs about 630px on one line,
 * which the dashboard has beside the sentence from 1440px; below that it sits
 * under the sentence and wraps.
 */
export function EdgeFlow({
  eyebrow,
  title,
  body,
  nodes,
  label,
}: {
  eyebrow: string;
  title: string;
  body: string;
  nodes: EdgeFlowNode[];
  /** The whole path as one sentence, for screen readers. */
  label: string;
}) {
  return (
    <section
      aria-labelledby="xs-edge-flow"
      className={`${CARD} flex flex-col min-[1440px]:flex-row min-[1440px]:items-center min-[1440px]:justify-between gap-6 min-[1440px]:gap-8`}
    >
      <div className="flex flex-col gap-1.5 max-w-[360px] min-w-0 flex-1">
        <div className="text-[11px] font-medium uppercase tracking-[0.06em] text-muted">{eyebrow}</div>
        <h2 id="xs-edge-flow" className="font-display text-[18px] font-semibold text-ink">
          {title}
        </h2>
        <p className="m-0 text-[13px] leading-relaxed text-ink-soft">{body}</p>
      </div>
      <div role="img" aria-label={label} className="flex flex-wrap min-[1440px]:flex-nowrap min-[1440px]:shrink-0 items-center gap-2.5">
        {nodes.map((node, i) => (
          <div key={node.label} className="contents">
            {i > 0 && (
              <span aria-hidden="true" className="relative shrink-0 w-8 h-3">
                <svg width="32" height="12" viewBox="0 0 32 12" className="block stroke-faint">
                  <path
                    d="M0 6h32"
                    fill="none"
                    strokeWidth="1.5"
                    strokeDasharray={node.via === 'dashed' ? '3 3' : undefined}
                  />
                  <path d="M27 1l5 5-5 5" fill="none" strokeWidth="1.5" />
                </svg>
                {/*
                  The request's trip, drawn as a dot that follows the arrow:
                  to the edge on every loop, on to the origin only now and
                  then, which is the point of the strip. Off under reduced
                  motion, where the arrows alone still say it.
                */}
                {node.via === 'dashed' ? (
                  <span className="xs-flow-origin absolute left-0 top-[3px] w-1.5 h-1.5 rounded-full bg-primary opacity-0" />
                ) : (
                  node.highlight && (
                    <span className="xs-flow-req absolute left-0 top-[3px] w-1.5 h-1.5 rounded-full bg-primary opacity-0" />
                  )
                )}
              </span>
            )}
            <div
              className={`flex items-center gap-2.5 py-3 pl-3 pr-4 rounded-[10px] border ${node.highlight ? 'xs-flow-hit border-primary bg-primary-tint' : 'border-line bg-paper'}`}
            >
              <span
                className={`w-8 h-8 shrink-0 rounded-control flex items-center justify-center ${node.highlight ? 'bg-paper text-primary' : 'bg-panel text-ink-soft'}`}
              >
                {node.icon}
              </span>
              <span className="flex flex-col gap-px text-left">
                <span className="text-[13px] font-semibold text-ink">{node.label}</span>
                <span className={`text-[11.5px] ${node.highlight ? 'text-on-tint' : 'text-muted'}`}>{node.detail}</span>
              </span>
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}
