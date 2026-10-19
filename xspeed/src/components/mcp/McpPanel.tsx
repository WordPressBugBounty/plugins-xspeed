/**
 * MCP Server panel.
 *
 * Two ways to connect, both header-based (token never in the URL, so it
 * can't leak into server/proxy logs):
 *   1. A ready-to-paste config — Claude Code CLI one-liner or portable
 *      mcpServers JSON — plus a copy-paste AI prompt so the assistant
 *      knows what it's connected to.
 *   2. A single paste-in URL with the token in the path (convenient
 *      fallback for clients that only accept a URL).
 *
 * Hardening surfaced here: a read-only connection option (writes refused),
 * and Rotate — mint a fresh token that instantly kills the old one (the
 * leaked-token remedy). All credential fields are returned only to admins
 * (manage_options) by Mcp_Pairing::public_status().
 */

import { useEffect, useState } from 'react';
import {
  Sparkles,
  RefreshCw,
  Power,
  CheckCircle2,
  RotateCw,
  ShieldCheck,
  AlertTriangle,
  ChevronDown,
  ChevronRight,
} from 'lucide-react';
import { Callout } from '../Callout';
import { CopySnippet } from '../CopySnippet';
import { InlineConfirm } from '../InlineConfirm';
import type { PanelProps } from '../customPanels';
import { api } from '../../api/client';
import { McpActivity } from './McpActivity';
import { HubCard } from '../settings/HubCard';

interface McpStatus {
  connected: boolean;
  connection_token: string;
  /** The single paste-in URL (token embedded in the path). Fallback. */
  connect_url: string;
  /** This site's MCP endpoint without the token (header-based). */
  mcp_endpoint: string;
  /** Always-on fallback via the REST namespace. */
  mcp_endpoint_rest: string;
  /** Optional hosted-broker vanity URL. */
  broker_endpoint: string;
  connected_at: number;
  scopes: string[];
  read_only: boolean;
  /** Ready-to-paste connection recipes (header-based). */
  config: { cli: string; json: string };
  /** Copy-paste instruction for the user's AI client. */
  ai_prompt: string;
  /** The tool catalog the AI can call (name + short description + write flag). */
  tools: { name: string; description: string; write: boolean }[];
}

type Tab = 'cli' | 'json' | 'prompt' | 'oauth';

// Read-only mode is fully built + enforced on the backend, but its UI is hidden
// until we're ready to release it. Flip to true to surface the pre-connect
// checkbox + the live read-only/read-write toggle. Backend + REST routes stay
// active regardless (a token minted read-only is still enforced).
const SHOW_READ_ONLY = false;

/**
 * The focus anchor a deep link uses to reach the Hub section.
 *
 * The Hub card sits near the bottom of a long panel, and the only scroller is
 * the inner `<main>` — so a link that merely selects this page leaves the
 * reader at the top with no idea the card exists. Anything that says "connect
 * to xSpeed Hub" links through `buildDeepLink({ module: 'mcp', focus:
 * HUB_SECTION_ANCHOR })`, and `scrollToField` brings the card into view.
 */
export const HUB_SECTION_ANCHOR = 'mcp:hub';

export function McpPanel({ module: _module }: PanelProps) {
  const [status, setStatus] = useState<McpStatus | null>(null);
  const [busy, setBusy] = useState<null | 'load' | 'connect' | 'disconnect' | 'rotate'>(null);
  const [error, setError] = useState<string | null>(null);
  const [readOnly, setReadOnly] = useState(false);
  const [tab, setTab] = useState<Tab>('cli');
  const [toolsOpen, setToolsOpen] = useState(false);
  // Which destructive action is awaiting inline confirmation (design system
  // uses InlineConfirm, not window.confirm — DESIGN.md §23 forbids modals).
  const [confirming, setConfirming] = useState<null | 'rotate' | 'disconnect'>(null);

  const load = async () => {
    setBusy('load');
    setError(null);
    try {
      setStatus(await api.request<McpStatus>('/mcp/connection'));
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load MCP status.');
    } finally {
      setBusy(null);
    }
  };

  useEffect(() => {
    load();
  }, []);

  const connect = async () => {
    setBusy('connect');
    setError(null);
    try {
      setStatus(
        await api.request<McpStatus>('/mcp/connect', {
          method: 'POST',
          body: JSON.stringify({ read_only: readOnly }),
        }),
      );
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Could not connect to xSpeed MCP.');
    } finally {
      setBusy(null);
    }
  };

  const rotate = async () => {
    setConfirming(null);
    setBusy('rotate');
    setError(null);
    try {
      setStatus(await api.request<McpStatus>('/mcp/rotate', { method: 'POST' }));
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Token rotation failed.');
    } finally {
      setBusy(null);
    }
  };

  // Switch a live connection between read-only and read & write WITHOUT minting a
  // new token, so an already-paired client keeps working — only its allowed tools
  // change. (Rotate, by contrast, invalidates the token; that's the leak remedy.)
  const setAccess = async (nextReadOnly: boolean) => {
    setBusy('rotate');
    setError(null);
    try {
      setStatus(
        await api.request<McpStatus>('/mcp/access', {
          method: 'POST',
          body: JSON.stringify({ read_only: nextReadOnly }),
        }),
      );
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Could not change access level.');
    } finally {
      setBusy(null);
    }
  };

  const disconnect = async () => {
    setConfirming(null);
    setBusy('disconnect');
    setError(null);
    try {
      setStatus(await api.request<McpStatus>('/mcp/disconnect', { method: 'POST' }));
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Disconnect failed.');
    } finally {
      setBusy(null);
    }
  };

  const connected = Boolean(status?.connected);

  return (
    <div className="space-y-4">
      {/* Status header card */}
      <div className="border border-line bg-paper rounded-card p-4 sm:p-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3 min-w-0">
            {connected ? (
              <CheckCircle2 size={18} className="text-success shrink-0" />
            ) : (
              <Power size={18} className="text-faint shrink-0" />
            )}
            <div className="min-w-0">
              <div className="flex items-center gap-2 font-medium text-ink">
                {connected ? 'Connected' : 'Not connected'}
                {SHOW_READ_ONLY && connected && status?.read_only && (
                  <span className="inline-flex items-center gap-1 text-[11px] font-normal px-1.5 py-0.5 rounded bg-warning-tint text-warning">
                    <ShieldCheck size={11} /> read-only
                  </span>
                )}
              </div>
              {/* Disconnected is where the support question lands ("do I need
                  an AI Provider key first?"), so the lead answers it. Connected
                  has nothing left to sell. */}
              <div className="text-[12px] text-muted">
                {connected
                  ? 'Claude and other MCP-compatible AI agents can work on this site.'
                  : 'Connect once and your AI agent can work on this site. No API key and no AI Provider needed — those are only for Pro features that use your own model.'}
              </div>
            </div>
          </div>
          <div className="flex items-center gap-2 shrink-0">
            <button
              type="button"
              onClick={load}
              disabled={busy !== null}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-line text-ink-soft hover:bg-panel transition-colors disabled:opacity-50"
            >
              <RefreshCw size={13} className={busy === 'load' ? 'animate-spin' : ''} />
              Refresh
            </button>
            {connected ? (
              <>
                {SHOW_READ_ONLY && (
                  <button
                    type="button"
                    onClick={() => setAccess(!status?.read_only)}
                    disabled={busy !== null || confirming !== null}
                    title={
                      status?.read_only
                        ? 'Allow the AI to change things (purge, toggle, edit settings)'
                        : 'Limit the AI to inspecting status & settings only'
                    }
                    className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-line text-ink-soft hover:bg-panel transition-colors disabled:opacity-50"
                  >
                    <ShieldCheck size={13} />
                    {status?.read_only ? 'Allow writes' : 'Make read-only'}
                  </button>
                )}
                <button
                  type="button"
                  onClick={() => setConfirming('rotate')}
                  disabled={busy !== null || confirming !== null}
                  title="Mint a fresh token and invalidate the current one"
                  className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-line text-ink-soft hover:bg-panel transition-colors disabled:opacity-50"
                >
                  {busy === 'rotate' ? <RefreshCw size={13} className="animate-spin" /> : <RotateCw size={13} />}
                  Rotate
                </button>
                <button
                  type="button"
                  onClick={() => setConfirming('disconnect')}
                  disabled={busy !== null || confirming !== null}
                  className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-line text-danger hover:bg-danger-tint transition-colors disabled:opacity-50"
                >
                  {busy === 'disconnect' ? <RefreshCw size={13} className="animate-spin" /> : <Power size={13} />}
                  Disconnect
                </button>
              </>
            ) : (
              <button
                type="button"
                onClick={connect}
                disabled={busy !== null}
                className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-primary bg-primary text-on-primary hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
              >
                {busy === 'connect' ? <RefreshCw size={13} className="animate-spin" /> : <Sparkles size={13} />}
                Connect
              </button>
            )}
          </div>
        </div>

        {/* Read-only toggle, shown before connecting */}
        {SHOW_READ_ONLY && !connected && status !== null && (
          <label className="mt-4 flex items-center gap-2 text-[12px] text-ink-soft cursor-pointer select-none">
            <input
              type="checkbox"
              checked={readOnly}
              onChange={(e) => setReadOnly(e.target.checked)}
              className="h-4 w-4 shrink-0 accent-primary"
            />
            Read-only connection — the AI can inspect status &amp; settings but cannot purge, toggle
            caching, or change anything.
          </label>
        )}
      </div>

      {/* Inline confirmation for destructive actions (design system uses
          InlineConfirm, not window.confirm — DESIGN.md §23 forbids modals). */}
      {confirming === 'rotate' && (
        <InlineConfirm
          title="Rotate the MCP token?"
          body="A fresh token is minted and the current one is invalidated immediately. Any AI agent using the old token stops working until you paste the new one. If this site is connected to xSpeed Hub, the Hub gets the new token automatically."
          confirmLabel="Rotate token"
          busy={busy === 'rotate'}
          onConfirm={rotate}
          onCancel={() => setConfirming(null)}
        />
      )}
      {confirming === 'disconnect' && (
        <InlineConfirm
          title="Disconnect MCP?"
          body="This revokes the token and every OAuth grant immediately — any AI agent using this site stops working until you reconnect."
          confirmLabel="Disconnect"
          busy={busy === 'disconnect'}
          onConfirm={disconnect}
          onCancel={() => setConfirming(null)}
        />
      )}

      {error && <Callout tone="danger" title="MCP error" body={error} />}

      {/* Tabbed config. The strip is ALWAYS shown once status loads; the
          connect-format tabs prompt to Connect first when disconnected.
          (Multi-site attach lives in Settings → xSpeed Hub.) */}
      {status && (
        <div className="border border-line bg-paper rounded-card p-4 sm:p-5 space-y-3">
          <div>
            <div className="font-medium text-ink">
              Add this MCP server to your AI client
            </div>
            <div className="text-[12px] text-muted mt-0.5">
              Pick the format your client uses. The token travels in an{' '}
              <code className="font-mono text-[11px]">Authorization</code> header, so it never
              lands in a URL or server log.
            </div>
          </div>

          {/* Tabs */}
          <div className="flex items-center gap-1 border-b border-line">
            {([
              ['cli', 'Claude Code (CLI)'],
              ['json', 'JSON config'],
              ['prompt', 'AI prompt'],
              ['oauth', 'Remote (OAuth)'],
            ] as [Tab, string][]).map(([key, label]) => (
              <button
                key={key}
                type="button"
                onClick={() => setTab(key)}
                className={`flex items-center gap-1.5 text-[12px] px-3 py-1.5 -mb-px border-b-2 transition-colors ${
                  tab === key
                    ? 'border-primary text-ink font-medium'
                    : 'border-transparent text-muted hover:text-ink'
                }`}
              >
                {label}
              </button>
            ))}
          </div>

          {/* Connect-format tabs need a live token; prompt to Connect when
              the site isn't connected yet. */}
          {!connected && (
            <div className="flex items-start gap-2 rounded-md border border-line bg-panel px-3 py-2 text-[12px] text-muted">
              <Sparkles size={14} className="mt-0.5 shrink-0 text-primary" />
              <span>
                Click <strong className="font-medium text-ink-soft">Connect</strong>{' '}
                above to mint this site&apos;s token, then copy your connection config here.
              </span>
            </div>
          )}

          {tab === 'cli' && connected && (
            <div className="space-y-2">
              <div className="text-[12px] text-muted">
                Run this once in your terminal — it registers xSpeed with Claude Code:
              </div>
              <CopySnippet content={status.config.cli} tone="blue" />
            </div>
          )}

          {tab === 'json' && connected && (
            <div className="space-y-2">
              <div className="text-[12px] text-muted">
                Add this to your client&apos;s MCP config (e.g. Claude Desktop&apos;s config file):
              </div>
              <CopySnippet content={status.config.json} tone="blue" />
            </div>
          )}

          {tab === 'prompt' && connected && (
            <div className="space-y-2">
              <div className="flex items-start gap-2 rounded-md border border-line bg-warning-tint px-3 py-2 text-[12px] text-warning">
                <AlertTriangle size={14} className="mt-0.5 shrink-0" />
                <span>
                  <strong className="font-semibold">Not recommended.</strong> This pastes your token
                  into a chat, so the AI provider may see and store it. Prefer the{' '}
                  <strong className="font-medium">Claude Code (CLI)</strong>,{' '}
                  <strong className="font-medium">JSON config</strong>, or{' '}
                  <strong className="font-medium">Remote (OAuth)</strong> methods, which keep the
                  token out of the conversation.
                </span>
              </div>
              <div className="text-[12px] text-muted">
                Paste this to your AI agent so it knows what it&apos;s connected to and how to act:
              </div>
              <CopySnippet content={status.ai_prompt} />
            </div>
          )}

          {tab === 'oauth' && connected && (
            <div className="space-y-2">
              <div className="text-[12px] text-muted">
                For clients that connect by URL only (e.g. claude.ai connectors). Paste
                just the server URL — no token field:
              </div>
              <CopySnippet content={status.mcp_endpoint} tone="blue" />
              <div className="flex items-start gap-2 text-[12px] text-muted pt-1">
                <span className="text-primary shrink-0">•</span>
                <span>
                  No token needed. Your client opens a browser page where you{' '}
                  <strong className="font-medium text-ink-soft">
                    approve access
                  </strong>{' '}
                  (you must be signed in here as an administrator). Approval grants the same{' '}
                  {status.read_only ? 'read-only' : 'read & write'} access as the token above.
                </span>
              </div>
            </div>
          )}

          {/* Password-safety footer for the per-site connect formats. */}
          {connected && (
            <div className="flex items-start gap-2 text-[12px] text-muted pt-1">
              <span className="text-warning shrink-0">•</span>
              <span>
                Treat the token like a password — it lets an AI control this site.{' '}
                <strong className="font-medium text-ink-soft">Rotate</strong>{' '}
                if it may have leaked;{' '}
                <strong className="font-medium text-ink-soft">Disconnect</strong>{' '}
                to revoke entirely.
                {status.scopes.length > 0 && <> Granted scopes: {status.scopes.join(', ')}.</>}
              </span>
            </div>
          )}
        </div>
      )}

      {/* Connected: the tool catalog the AI can call (collapsible) */}
      {connected && status && status.tools.length > 0 && (
        <div className="border border-line bg-paper rounded-card p-4 sm:p-5">
          <button
            type="button"
            onClick={() => setToolsOpen((v) => !v)}
            aria-expanded={toolsOpen ? 'true' : 'false'}
            className="flex w-full items-center gap-2 rounded text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1"
          >
            {toolsOpen ? (
              <ChevronDown size={16} className="shrink-0 text-muted" />
            ) : (
              <ChevronRight size={16} className="shrink-0 text-muted" />
            )}
            <span className="font-medium text-ink">
              Tools your AI agent can call
            </span>
            <span className="text-[12px] text-faint">
              ({status.tools.length})
            </span>
          </button>
          {toolsOpen && (
            <>
              <div className="text-[12px] text-muted mt-2">
                {status.read_only ? (
                  <>
                    This is a <strong className="font-medium">read-only</strong> connection, so the{' '}
                    <span className="text-warning">write</span> tools below are
                    refused.
                  </>
                ) : (
                  <>Each tool maps to an xSpeed action. Write tools can change this site.</>
                )}
              </div>
              <ul className="mt-3 divide-y divide-line">
                {status.tools.map((t) => (
              <li key={t.name} className="flex items-start gap-3 py-2">
                <code className="font-mono text-[11px] text-ink shrink-0 mt-0.5">
                  {t.name}
                </code>
                <span
                  className={`shrink-0 mt-0.5 text-[10px] font-medium px-1.5 py-0.5 rounded ${
                    t.write
                      ? 'bg-warning-tint text-warning'
                      : 'bg-panel text-muted'
                  }`}
                >
                  {t.write ? 'write' : 'read'}
                </span>
                <span className="text-[12px] text-muted">
                  {t.description}
                </span>
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>
      )}

      {/* Disconnected: what connecting does */}
      {!connected && status !== null && (
        <div className="border border-line bg-paper rounded-card p-4 sm:p-5">
          <div className="font-medium text-ink">
            What your AI agent can do
          </div>
          <div className="text-[12px] text-muted mt-1">
            Once connected, your AI can read cache status &amp; stats, purge the cache, toggle
            caching on or off, and read or change module settings — all scoped to this site.
            {SHOW_READ_ONLY && (
              <> Choose a read-only connection above to allow inspection only.</>
            )}{' '}
            Rotate or disconnect any time to revoke access.
          </div>
        </div>
      )}

      {/* xSpeed Hub — multi-site attach. Relocated here from Settings in
          redesign v2 so every AI-connection surface lives together: the MCP
          block above connects ONE AI client to THIS site; the Hub connects one
          AI connection to EVERY site in the account. Always visible, so
          `active` is true and HubCard loads once on mount.

          The wrapper carries the deep-link anchor rather than HubCard itself,
          because the card a link should land on is the whole bordered section,
          heading included — scrolling its inner content to centre would put
          the title off screen. `xspeed-field` supplies the scroll-margin and
          the blink the anchor is scrolled with. */}
      <div
        data-field-anchor={HUB_SECTION_ANCHOR}
        className="xspeed-field border border-line bg-paper rounded-card p-4 sm:p-5"
      >
        <HubCard active />
      </div>

      {/* Audit trail — what the assistant actually did. Rendered whenever a
          connection exists (or ever existed), since past calls stay
          readable after a disconnect. */}
      <McpActivity />
    </div>
  );
}
