/**
 * xSpeed Hub card — the multi-site attach section of the Settings page.
 *
 * xSpeed Hub lets one AI connection manage caching across ALL of a user's
 * sites. This card is where a site owner attaches THIS site to their
 * xspeedcache.com account (§7.3). Two methods:
 *
 *   Method 1 — Token: Generate token → copy this site's URL + token →
 *   paste into the xspeedcache.com "Add site" form.
 *   Method 2 — OAuth (one click): redirect to the hub to log in + approve.
 *
 * Redesign v2 renders it inside the MCP host page (AI & agents) so every
 * AI-connection surface lives together. It stays a distinct account-level
 * concern (which xspeedcache.com account owns this site), separate from the
 * per-site connection formats above it: the MCP panel connects ONE AI client
 * to THIS site; the Hub connects ONE AI connection to EVERY site in the
 * account.
 */

import { useEffect, useState } from 'react';
import { Boxes, RefreshCw, KeyRound, ExternalLink, CheckCircle2, Power, Sparkles } from 'lucide-react';
import { Callout } from '../Callout';
import { hubLocalMessage } from '../hubLocal';
import { CopySnippet } from '../CopySnippet';
import { InlineConfirm } from '../InlineConfirm';
import { api } from '../../api/client';
import { __ } from '../../i18n';

/** One reason not to disconnect right now, supplied by whatever knows it. */
interface DisconnectBlocker {
  code: string;
  message: string;
}

interface HubStatus {
  attached: boolean;
  account_email: string;
  attached_at: number;
  site_url: string;
  site_token: string;
  hub_url: string;
  add_site_url: string;
  /** One-click OAuth-attach redirect (Method 2) — carries a signed nonce. */
  attach_url: string;
  /** Not publicly reachable, so the Hub refuses it (xspeed-hub#370). */
  is_local?: boolean;
  /**
   * Reasons disconnecting costs something, from
   * `xspeed_hub_disconnect_blockers`. Read here so the warning appears BEFORE
   * the click; the server refuses the same call independently.
   */
  disconnect_blockers?: DisconnectBlocker[];
}

interface HubCardProps {
  /** Whether the Hub tab is currently the active tab. */
  active: boolean;
}

export function HubCard({ active }: HubCardProps) {
  const [status, setStatus] = useState<HubStatus | null>(null);
  const [busy, setBusy] = useState<null | 'load' | 'token' | 'disconnect'>(null);
  const [error, setError] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [showToken, setShowToken] = useState(false);

  const load = async () => {
    setBusy('load');
    setError(null);
    try {
      setStatus(await api.request<HubStatus>('/mcp/hub'));
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load xSpeed Hub status.');
    } finally {
      setBusy(null);
      setLoaded(true);
    }
  };

  // Silent refresh (no spinner) — used when the admin returns to the tab.
  // `reconcile` forces the plugin to re-check the hub (source of truth). Sent
  // as a header (not a query param) so it works on plain-permalink REST URLs
  // that already carry ?rest_route=.
  const reload = async (reconcile = false) => {
    try {
      const init = reconcile ? { headers: { 'X-XSpeed-Reconcile': '1' } } : {};
      setStatus(await api.request<HubStatus>('/mcp/hub', init));
    } catch {
      /* keep current status on a transient error */
    }
  };

  // Lazy-load ONCE, the first time the tab becomes active. The component
  // stays mounted after that (parent hides it with CSS, not unmount), so
  // re-selecting the tab is instant — no refetch, no blink.
  useEffect(() => {
    if (active && !loaded && busy !== 'load') {
      load();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active]);

  // Auto-refresh when the admin returns to this browser tab — e.g. after
  // completing the Connect flow in the new tab it opened. Forces a fresh
  // reconcile with the hub so the "Connected" badge appears without a manual
  // reload. Only while this panel tab is active + already loaded once.
  useEffect(() => {
    const onVisible = () => {
      if (document.visibilityState === 'visible' && active && loaded) {
        void reload(true);
      }
    };
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('focus', onVisible);
    return () => {
      document.removeEventListener('visibilitychange', onVisible);
      window.removeEventListener('focus', onVisible);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active, loaded]);

  const generateToken = async () => {
    setBusy('token');
    setError(null);
    try {
      setStatus(await api.request<HubStatus>('/mcp/hub/token', { method: 'POST' }));
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Could not generate a token.');
    } finally {
      setBusy(null);
    }
  };

  /**
   * `acknowledge` is sent ONLY when blockers were shown and the admin ticked
   * the box. Sending it unconditionally would hand the server a confirmation
   * nobody gave.
   */
  const disconnect = async (acknowledge: boolean) => {
    setConfirming(false);
    setBusy('disconnect');
    setError(null);
    try {
      setStatus(
        await api.request<HubStatus>('/mcp/hub/disconnect', {
          method: 'POST',
          body: JSON.stringify({ acknowledge }),
        }),
      );
    } catch (e) {
      /*
       * A refusal here is almost always the veto, and leaving it as a bare
       * failure is a dead end.
       *
       * This card decides whether to send an acknowledgement from the status
       * it loaded, and the server recomputes the blockers when the call
       * arrives. The two disagree whenever something started blocking after
       * the card rendered: the card sends no acknowledgement, the server
       * refuses, and the card still believes there is nothing to acknowledge,
       * so the confirm reopens without a checkbox and the button can never
       * get through. Reported as "disconnect does nothing any more".
       *
       * Re-read the status and reopen. The blockers arrive with it, the
       * checkbox appears, and the second press carries the acknowledgement.
       */
      await load();
      setConfirming(true);
      setError(
        e instanceof Error && e.message
          ? e.message
          : __('Disconnect was refused. Check what stops working, then confirm again.'),
      );
    } finally {
      setBusy(null);
    }
  };

  // First load in flight: show a small placeholder (not null) so the tab
  // panel has stable height and doesn't collapse/jump.
  if (!loaded) {
    return (
      <div className="flex items-center gap-2 text-[12px] text-faint py-6">
        <RefreshCw size={13} className="animate-spin" /> Loading xSpeed Hub…
      </div>
    );
  }

  const attached = Boolean(status?.attached);
  // A local site cannot connect; it is told why instead (xspeed-hub#370).
  const cannotConnect = !attached && Boolean(status?.is_local);

  /**
   * The server-built attach_url defaults its return_url to the plain dashboard,
   * so approving on the Hub dropped the user on the Cache page instead of back
   * here. Point it at the CURRENT page — same override useHubConnect applies for
   * the onboarding step — and mark it so the return handler recognises the
   * round trip. (FBS-84086)
   */
  const connectUrl = (() => {
    const base = status?.attach_url;
    if (!base || typeof window === 'undefined') return base;
    try {
      const u = new URL(base);
      const here = new URL(window.location.href);
      here.searchParams.set('xspeed_connected', '1');
      u.searchParams.set('return_url', here.toString());
      return u.toString();
    } catch {
      return base;
    }
  })();
  const hasToken = Boolean(status?.site_token);
  const blockers = status?.disconnect_blockers ?? [];

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3 min-w-0">
          <Boxes size={18} className="text-primary shrink-0" />
          <div className="min-w-0">
            <div className="font-medium text-ink">
              xSpeed Hub
            </div>
            <div className="text-[12px] text-muted">
              Control caching on all your sites from one AI connection.
            </div>
          </div>
        </div>
        {attached && (
          <span className="inline-flex items-center gap-1.5 text-[12px] text-success shrink-0">
            <CheckCircle2 size={14} />
            {/* Status only — the account it's connected as is stated in the
                callout below, so the chip doesn't repeat the email. */}
            Connected
          </span>
        )}
      </div>

      {/* Connected: primary action is to manage the fleet at the Hub. */}
      {attached && (
        <div className="rounded-md border border-line bg-success-tint px-3 py-2.5 flex flex-wrap items-center justify-between gap-2">
          {/* The one place the connected account is named. The header chip says
              only "Connected", so the email isn't stated twice. */}
          <p className="text-[12px] text-ink-soft">
            This site is managed at xSpeed Hub{status?.account_email ? <> — signed in as <strong>{status.account_email}</strong></> : ''}.
          </p>
          <a
            href={status?.hub_url}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-primary bg-primary text-on-primary hover:border-primary-hover hover:bg-primary-hover transition-colors shrink-0"
          >
            <ExternalLink size={13} /> Go to dashboard
          </a>
        </div>
      )}

      {cannotConnect && <Callout tone="info" title={__('This site needs a public URL')} body={hubLocalMessage()} />}

      {/* Explainer strip */}
      {!cannotConnect && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-2 text-[12px]">
          {[
            ['1', 'Connect this site', 'One click — sign in and approve'],
            ['2', 'Connect AI once', 'Add one connection from your xSpeed Hub dashboard'],
            ['3', 'Control every site', '“Purge cache on acme” — done'],
          ].map(([n, title, body]) => (
            <div
              key={n}
              className="rounded-md border border-line bg-panel px-3 py-2"
            >
              <div className="flex items-center gap-1.5 font-medium text-ink">
                <span className="inline-flex h-4 w-4 items-center justify-center rounded-full bg-primary text-on-primary text-[10px]">
                  {n}
                </span>
                {title}
              </div>
              <div className="text-muted mt-0.5">{body}</div>
            </div>
          ))}
        </div>
      )}

      {error && <Callout tone="danger" title="xSpeed Hub error" body={error} />}

      {/* Method 2 — OAuth attach (PRIMARY). One click: redirect to the hub,
          approve, done — no token pasting. The attach_url carries a signed,
          admin-minted nonce the hub verifies via a callback to this site. */}
      {!attached && !cannotConnect && (
        <div className="space-y-1.5">
          <a
            href={connectUrl}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-primary bg-primary text-on-primary hover:border-primary-hover hover:bg-primary-hover transition-colors"
          >
            <Sparkles size={13} /> Connect via xSpeed Hub
          </a>
          <p className="text-[12px] text-muted">
            One click — sign in to your account and approve. No token to copy.
          </p>
        </div>
      )}

      {/* Method 1 — Token (FALLBACK). For firewalled / headless sites that
          can't complete the redirect: paste the URL + token manually. */}
      {!attached && !cannotConnect && (
        <div className="border-t border-line pt-3">
          <button
            type="button"
            onClick={() => {
              if (!hasToken) generateToken();
              setShowToken((v) => !v);
            }}
            className="flex items-center gap-1.5 text-[12px] text-muted hover:text-ink"
          >
            <KeyRound size={12} />
            Can&apos;t redirect? Add with a token instead
          </button>
          {showToken && (
            <div className="mt-3 space-y-2">
              <p className="text-[12px] text-muted">
                Paste this site&apos;s URL + token into the{' '}
                <a
                  href={status?.add_site_url}
                  target="_blank"
                  rel="noreferrer"
                  className="text-primary hover:underline inline-flex items-center gap-0.5"
                >
                  Add site form <ExternalLink size={11} />
                </a>{' '}
                on xspeedcache.com.
              </p>
              <div>
                <div className="text-[11px] font-medium text-muted mb-1">
                  Site URL
                </div>
                <CopySnippet content={status?.site_url ?? ''} />
              </div>
              <div>
                <div className="text-[11px] font-medium text-muted mb-1">
                  Token
                </div>
                <CopySnippet content={status?.site_token ?? ''} tone="blue" />
              </div>
              <p className="text-[12px] text-muted">
                Same token as your MCP connection — treat it like a password.
              </p>
            </div>
          )}
        </div>
      )}

      {/* Disconnect (only when attached) */}
      {attached && (
        <div className="border-t border-line pt-3">
          {confirming ? (
            <InlineConfirm
              title={__('Disconnect this site from xSpeed Hub?')}
              body={
                <>
                  {blockers.map((b) => (
                    <span key={b.code} className="block font-medium mb-1">
                      {b.message}
                    </span>
                  ))}
                  <span className="block">
                    {__(
                      'This tells the hub to drop your account\u2019s link to this site, then clears it here. Other admins who connected keep theirs, and caching on this site is unaffected. To cut the hub off completely, Rotate the token above so its stored copy stops working.',
                    )}
                  </span>
                </>
              }
              requireAck={
                blockers.length > 0
                  ? __('I understand what stops working when this site disconnects.')
                  : undefined
              }
              confirmLabel={__('Disconnect')}
              busy={busy === 'disconnect'}
              onConfirm={() => disconnect(blockers.length > 0)}
              onCancel={() => setConfirming(false)}
            />
          ) : (
            <button
              type="button"
              onClick={() => setConfirming(true)}
              disabled={busy !== null}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-line text-danger hover:bg-danger-tint transition-colors disabled:opacity-50"
            >
              <Power size={13} /> Disconnect from Hub
            </button>
          )}
        </div>
      )}
    </div>
  );
}
