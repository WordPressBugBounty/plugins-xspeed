import { useCallback, useEffect, useState } from 'react';
import { Sparkles, CheckCircle2, ArrowRight, Gauge, SlidersHorizontal, MessageSquare } from 'lucide-react';
import { api } from '../../api/client';
import { __ } from '../../i18n';
import { Modal } from '../Modal';
import { ModuleNotices } from '../ModulePanel';
import { McpPanel } from '../mcp/McpPanel';
import { McpSessionDemo } from './McpSessionDemo';
import type { ModuleDescriptor } from '../../types';

/**
 * The Overview's MCP surface — the way in to controlling xSpeed with an AI
 * agent. Two states, deliberately very different in weight:
 *
 *   DISCONNECTED → a compact two-column capability card. Nobody was finding
 *     MCP (it was three clicks deep, under AI & agents) and nobody understood
 *     what it bought them, so this states the promise, names three concrete
 *     things an agent does, and shows a miniature session doing them.
 *
 *   CONNECTED → one status row. There is nothing left to sell, so the card
 *     collapses back to status + management and stops taking space.
 *
 * Both open the REAL `McpPanel` in a drawer. There is deliberately no second
 * MCP implementation, no duplicated markup, and no client-selection layer above
 * the CLI / JSON / prompt / OAuth tabs the panel already owns.
 *
 * Status is fetched here rather than lifted out of `McpPanel`, matching how
 * `SpeedTestCard` already works on this page. It costs one extra admin GET and
 * keeps the blast radius entirely outside the working MCP implementation.
 *
 * NOTHING sensitive is rendered here. `/mcp/connection` returns the token, the
 * endpoint and the ready-to-paste config; this card reads exactly one field
 * from it — `connected` — so credentials only ever appear once the user has
 * deliberately opened the drawer.
 */

/**
 * Read the ONE field we care about, strictly.
 *
 * `true` / `false` / `null` = we do not know. A malformed or truncated body
 * (`{}`) previously collapsed to `false` and put the full "you should connect
 * this" pitch in front of somebody whose state we could not actually see.
 * Telling a connected user they are disconnected is worse than saying nothing,
 * so only a real boolean counts as an answer.
 */
function readConnected(payload: unknown): boolean | null {
  if (!payload || typeof payload !== 'object') return null;
  const value = (payload as { connected?: unknown }).connected;
  return typeof value === 'boolean' ? value : null;
}


/**
 * The three things an agent does. One line each — the session demonstrates the
 * rest, so this never restates it.
 *
 * Built per render rather than at module scope: the translation helper reads
 * `window.wp.i18n`, and a module-level array would freeze whatever happened to be
 * loaded at import time.
 */
function capabilities() {
  return [
    { Icon: Gauge, title: __('Improve performance'), body: __('PageSpeed & Core Web Vitals') },
    {
      Icon: SlidersHorizontal,
      title: __('Optimize automatically'),
      body: __('Cache, compression & more'),
    },
    {
      Icon: MessageSquare,
      title: __('Just ask'),
      body: __('Manage xSpeed through conversation'),
    },
  ];
}

const TITLE_ID = 'xspeed-mcp-card-title';

/**
 * The compact row — connected, still checking, or unreachable. Deliberately the
 * same shape as the status cards above it.
 */
function CompactRow({
  tone,
  title,
  body,
  cta,
  onOpen,
}: {
  tone: 'success' | 'primary';
  title: string;
  body: string;
  cta: string;
  onOpen: () => void;
}) {
  return (
    <section
      id="xspeed-mcp-card"
      aria-labelledby={TITLE_ID}
      className="rounded-card border border-line bg-paper p-5"
    >
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div className="flex min-w-0 items-start gap-3">
          <span
            className={`mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-pill ${
              tone === 'success' ? 'bg-success-tint text-success' : 'bg-primary-tint text-primary'
            }`}
            aria-hidden="true"
          >
            {tone === 'success' ? <CheckCircle2 size={15} /> : <Sparkles size={15} />}
          </span>
          <div className="min-w-0">
            <h2 id={TITLE_ID} className="text-[15px] font-semibold text-ink">
              {title}
            </h2>
            <p className="mt-1 max-w-2xl text-[13px] leading-relaxed text-ink-soft">{body}</p>
          </div>
        </div>

        <button
          type="button"
          onClick={onOpen}
          className="inline-flex shrink-0 items-center gap-2 rounded-control border border-line-strong bg-paper px-4 py-2 text-[13px] font-medium leading-[1.2] text-ink transition-colors hover:bg-panel focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
        >
          {cta}
          <ArrowRight size={14} aria-hidden="true" />
        </button>
      </div>
    </section>
  );
}

export function AiControlCard({ modules }: { modules: ModuleDescriptor[] }) {
  const [connected, setConnected] = useState<boolean | null>(null);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);

  const mcp = modules.find((m) => m.slug === 'mcp');

  /**
   * The single status read. A failed request resolves to `null` (unknown)
   * rather than rejecting: a status we could not fetch is not an error state
   * for the Overview, it just isn't an answer.
   */
  const readStatus = useCallback(async (): Promise<boolean | null> => {
    try {
      return readConnected(await api.request<unknown>('/mcp/connection'));
    } catch {
      return null;
    }
  }, []);

  useEffect(() => {
    let active = true;
    // Skip the request entirely when the module isn't present — the card
    // renders nothing in that case, so the fetch would be wasted.
    if (!mcp) {
      setLoading(false);
      return;
    }
    void readStatus().then((value) => {
      if (!active) return;
      setConnected(value);
      setLoading(false);
    });
    return () => {
      active = false;
    };
  }, [mcp, readStatus]);

  // Re-read on tab return, so connecting in another tab doesn't leave the card
  // claiming "not connected". Same pattern as HubCard.
  useEffect(() => {
    if (!mcp) return;
    const refresh = () => {
      if (document.visibilityState !== 'visible') return;
      void readStatus().then(setConnected);
    };
    document.addEventListener('visibilitychange', refresh);
    return () => document.removeEventListener('visibilitychange', refresh);
  }, [mcp, readStatus]);

  /**
   * Re-read when the drawer closes.
   *
   * The drawer is where Connect, Rotate and Disconnect live, so whatever the
   * card is showing is very likely stale the moment it shuts. `visibilitychange`
   * cannot cover this — the tab never lost focus — which is how the card ended
   * up claiming "AI connection active" seconds after the user disconnected
   * inside the drawer, and the reverse after connecting.
   */
  const closeDrawer = useCallback(() => {
    setOpen(false);
    void readStatus().then(setConnected);
  }, [readStatus]);

  // The MCP module can be absent (deactivated, or filtered out by tier). A
  // card whose only action 404s is worse than no card — same rule SpeedTestCard
  // follows when the Hub can't serve a test.
  if (!mcp) return null;

  const openDrawer = () => setOpen(true);

  const drawer = open && (
    <Modal
      open
      onClose={closeDrawer}
      title={__('MCP Server')}
      variant="drawer"
      size="workspace"
    >
      {/* ModulePanel hoists these above every module panel; rendering
          McpPanel bare would silently drop them. Same component, same
          data — no drawer-specific notice logic. */}
      <ModuleNotices notices={mcp.notices} />
      <McpPanel module={mcp} />
    </Modal>
  );

  /*
   * Three states, and only a confirmed `false` earns the discovery card:
   *
   *   true    → compact "AI connection active"
   *   false   → the full discovery card, below
   *   null    → unknown (still loading, or the read failed / came back
   *             malformed) → compact and deliberately non-committal
   *
   * Flashing a big "connect me" pitch at somebody who is already connected —
   * or whose state we simply cannot see — is a worse failure than being terse.
   */
  if (loading || connected !== false) {
    const isConnected = connected === true;
    return (
      <>
        <CompactRow
          tone={isConnected ? 'success' : 'primary'}
          title={isConnected ? __('AI connection active') : __('Control xSpeed with AI')}
          body={
            loading
              ? __('Checking your AI connection…')
              : isConnected
                ? __(
                    'MCP is connected and ready — your AI agent can purge, tune and benchmark this site.',
                  )
                : // Unknown, NOT disconnected. Says what we know (nothing) and
                  // where to look, without claiming a state.
                  __("We couldn't read this site's AI connection status. Open MCP to check.")
          }
          cta={isConnected ? __('Manage MCP') : __('Open MCP')}
          onOpen={openDrawer}
        />
        {drawer}
      </>
    );
  }

  return (
    <>
      {/*
        Paper surface + a --primary-derived hairline and corner wash, NOT a
        tinted band: --primary-tint and --info-tint are the same hex, so a
        tinted card here was pixel-identical to the Recommendations band below
        and read as another recommendation. This is the HeroStatus/Hub
        treatment — a first-class capability — at roughly 2x the height of the
        connected row, which is the whole budget it gets.
      */}
      <section
        id="xspeed-mcp-card"
        aria-labelledby={TITLE_ID}
        className="xspeed-mcp-card relative grid grid-cols-1 gap-5 overflow-hidden rounded-card border bg-paper p-4 lg:grid-cols-[minmax(0,1fr)_320px]"
      >
        <div className="flex min-w-0 flex-col">
          <div className="flex items-center gap-2.5">
            <span
              className="inline-flex h-[26px] w-[26px] shrink-0 items-center justify-center rounded-pill bg-primary-tint text-primary"
              aria-hidden="true"
            >
              <Sparkles size={14} />
            </span>
            {/* Space Grotesk 18/600 — the same face and size as "Cache active",
                so this reads as a sibling capability, not a promotion. */}
            <h2
              id={TITLE_ID}
              className="font-display text-[18px] font-semibold leading-tight text-ink"
            >
              {__('Control and optimize your site with AI')}
            </h2>
          </div>

          <p className="mt-1.5 text-[12.5px] leading-normal text-ink-soft">
            {__(
              'Connect your AI agent through MCP — then just ask it to analyze and optimize your site.',
            )}
          </p>

          {/*
            Columns are sized to their content, not three equal thirds: equal
            columns take their width from the widest entry, so "Just ask" wasted
            ~50px while the longest description wrapped onto a second line — and
            a second line costs the card 16px it does not have. Stacks on narrow
            widths, where three columns would be unreadable anyway.
          */}
          <ul className="m-0 mt-2.5 grid list-none grid-cols-1 gap-3 p-0 lg:grid-cols-[max-content_max-content_minmax(0,1fr)]">
            {capabilities().map(({ Icon, title, body }) => (
              <li key={title} className="flex min-w-0 flex-col gap-[3px] break-words">
                <span className="flex items-center gap-1.5">
                  <Icon size={13} className="shrink-0 text-primary" aria-hidden="true" />
                  <span className="text-[12px] font-semibold leading-[1.35] text-ink lg:whitespace-nowrap">
                    {title}
                  </span>
                </span>
                <p className="text-[11.5px] leading-[1.42] text-muted">{body}</p>
              </li>
            ))}
          </ul>

          {/* One action. The drawer is the setup experience, so nothing else on
              the card competes with it. */}
          <div className="mt-auto pt-2.5">
            <button
              type="button"
              onClick={openDrawer}
              className="inline-flex max-w-full items-center gap-2 rounded-control bg-primary px-[14px] py-2 text-[13px] font-medium leading-[1.2] text-on-primary shadow-primary-glow transition-colors hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
            >
              {__('Connect with MCP')}
              <ArrowRight size={14} aria-hidden="true" />
            </button>
          </div>
        </div>

        <McpSessionDemo />
      </section>

      {drawer}
    </>
  );
}
