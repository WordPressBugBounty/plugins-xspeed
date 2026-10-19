import { useEffect, useState } from 'react';
import { Cloud, ExternalLink } from 'lucide-react';
import { api } from '../../api/client';
import { __ } from '../../i18n';
import { buildDeepLink, splitFocus } from '../deepLink';
import { requestFieldScroll } from '../scrollToField';
import { HUB_SECTION_ANCHOR } from '../mcp/McpPanel';
import { hubLocalMessage } from '../hubLocal';

/** Shape of GET /xspeed/v1/mcp/hub (Mcp_Hub::public_status). */
interface HubStatus {
  attached: boolean;
  account_email: string | null;
  hub_url: string;
  /** One-click OAuth-attach redirect (Method 2) — carries a signed nonce. */
  attach_url?: string;
  add_site_url?: string;
  /** Not publicly reachable, so the Hub refuses it (xspeed-hub#370). */
  is_local?: boolean;
}

/**
 * Zone 1 (right) — the xSpeed Hub connect card. Strictly optional: never a
 * gate, never a loud button.
 *
 * Connecting works the SAME way as the full Hub section on the Settings page
 * (src/components/settings/HubCard.tsx): "Connect to Hub" opens the site's
 * `attach_url` (the one-click OAuth attach) in a new tab. When the admin
 * returns to this tab we reconcile with the hub so the card flips to
 * "Connected" without a manual reload. The Settings link exposes the full flow
 * (token fallback for firewalled sites, disconnect). Status comes from the same
 * GET /mcp/hub route the Settings card uses.
 */
export function HubCard({ onNavigate }: { onNavigate: (slug: string) => void }) {
  const [status, setStatus] = useState<HubStatus | null>(null);
  const [loading, setLoading] = useState(true);

  const load = (reconcile = false) =>
    api
      .request<HubStatus>('/mcp/hub', reconcile ? { headers: { 'X-XSpeed-Reconcile': '1' } } : {})
      .then((s) => setStatus(s))
      .catch(() => undefined);

  useEffect(() => {
    let active = true;
    api
      .request<HubStatus>('/mcp/hub')
      .then((s) => {
        if (active) setStatus(s);
      })
      .catch(() => {
        if (active) setStatus(null);
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, []);

  // Auto-refresh when the admin returns to this browser tab — e.g. after
  // completing the Connect flow in the new tab it opened — forcing a fresh
  // reconcile with the hub so the "Connected" state appears without a reload.
  // Mirrors the Settings HubCard behavior.
  useEffect(() => {
    const onVisible = () => {
      if (document.visibilityState === 'visible') void load(true);
    };
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('focus', onVisible);
    return () => {
      document.removeEventListener('visibilitychange', onVisible);
      window.removeEventListener('focus', onVisible);
    };
  }, []);

  const cardCls =
    'rounded-card border border-line bg-paper p-5 flex flex-col';
  const outlineBtn =
    'inline-flex items-center gap-2 rounded border border-line-strong px-3 py-1.5 text-[13px] font-medium text-ink-soft hover:bg-panel transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary';

  if (loading) {
    return (
      <section className={cardCls} aria-busy="true" aria-label={__('xSpeed Hub')}>
        <div className="flex items-center gap-2.5">
          <Cloud size={18} className="text-muted" aria-hidden="true" />
          <span className="h-4 w-28 rounded bg-panel animate-pulse" />
        </div>
        <div className="mt-3 space-y-2">
          <span className="block h-3 w-full rounded bg-panel animate-pulse" />
          <span className="block h-3 w-2/3 rounded bg-panel animate-pulse" />
        </div>
      </section>
    );
  }

  const attached = status?.attached === true;

  /**
   * Open the AI & agents page AND bring the Hub section into view.
   *
   * `onNavigate` scrolls the page back to the top, and the Hub card is the
   * last section of a long panel — so navigating alone left the reader
   * looking at the MCP token block, hunting for a card this button had just
   * promised them. `requestFieldScroll` retries until the anchor mounts, so
   * it lands after that reset rather than racing it.
   */
  const goToHubSection = () => {
    onNavigate('mcp');
    const target = splitFocus(HUB_SECTION_ANCHOR);
    if (target) requestFieldScroll(target.module, target.field);
  };

  /**
   * The attach link, with the return trip aimed at the Hub section.
   *
   * The server's default `return_url` is the bare dashboard URL, which opens
   * the SPA on Overview — the page the user was already on when they left.
   * Sending them back to the Hub card instead is the whole point of the round
   * trip. `xspeed_connected=1` marks the return so the landing page
   * reconciles and shows the connected state.
   */
  const connectUrl = (() => {
    const base = status?.attach_url;
    if (!base || typeof window === 'undefined') return base;
    try {
      const u = new URL(base);
      const here = new URL(window.location.href);
      here.searchParams.set('xspeed_connected', '1');
      here.hash = buildDeepLink({ module: 'mcp', focus: HUB_SECTION_ANCHOR });
      u.searchParams.set('return_url', here.toString());
      return u.toString();
    } catch {
      return base;
    }
  })();

  return (
    <section className={cardCls} aria-label={__('xSpeed Hub')}>
      <div className="flex items-center gap-2.5">
        <Cloud size={18} className="text-ink-soft" aria-hidden="true" />
        <h2 className="text-[15px] font-semibold text-ink">
          {__('xSpeed Hub')}
        </h2>
        {attached && (
          <span className="ml-auto inline-flex items-center gap-1.5 text-[12px] text-success">
            <span className="w-2 h-2 rounded-full bg-success" aria-hidden="true" />
            {__('Connected')}
          </span>
        )}
      </div>

      {!attached && status?.is_local ? (
        // A local site cannot connect (xspeed-hub#370). The MCP server on
        // the AI & agents page works here, so that is where this points.
        <>
          <p className="mt-1.5 text-[13px] leading-relaxed text-ink-soft">{hubLocalMessage()}</p>
          <div className="mt-auto pt-4">
            <button type="button" onClick={() => onNavigate('mcp')} className={outlineBtn}>
              {__('Connect an AI client')}
            </button>
          </div>
        </>
      ) : attached ? (
        <>
          <p className="mt-1.5 text-[13px] leading-relaxed text-ink-soft">
            {status?.account_email
              ? `${__('Connected as')} ${status.account_email}. ${__('Control caching remotely and from AI agents.')}`
              : __('Control caching remotely, drive it from AI agents, and manage your sites in one place.')}
          </p>
          <div className="mt-auto pt-4">
            <a href={status?.hub_url} target="_blank" rel="noopener noreferrer" className={outlineBtn}>
              {__('Open Hub dashboard')}
              <ExternalLink size={14} />
            </a>
          </div>
        </>
      ) : (
        <>
          <p className="mt-1.5 text-[13px] leading-relaxed text-ink-soft">
            {__('Connect to control caching remotely, drive it from AI agents, and manage all your sites in one place.')}
          </p>
          <div className="mt-auto pt-4 flex flex-wrap items-center gap-3">
            {connectUrl ? (
              // One-click OAuth attach in a new tab — identical to the Settings
              // Hub card's primary "Connect via xSpeed Hub" action.
              <a href={connectUrl} target="_blank" rel="noopener noreferrer" className={outlineBtn}>
                {__('Connect to Hub')}
                <ExternalLink size={14} />
              </a>
            ) : (
              // Fallback if the attach URL isn't available: send them to the
              // full Hub section on the AI & agents (MCP) page.
              <button type="button" onClick={goToHubSection} className={outlineBtn}>
                {__('Connect to Hub')}
              </button>
            )}
            <button
              type="button"
              onClick={goToHubSection}
              className="text-[13px] text-ink-soft hover:text-ink underline underline-offset-2"
            >
              {__('More options')}
            </button>
          </div>
        </>
      )}
    </section>
  );
}
