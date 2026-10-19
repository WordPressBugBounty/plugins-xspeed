/**
 * useHubConnect — shared xSpeed Hub connection state.
 *
 * Owns the load / silent-reconcile / generate-token lifecycle for the Hub
 * link, plus the "refresh when the admin returns to this browser tab"
 * behavior (they complete the Connect flow in a NEW tab, then come back — we
 * re-reconcile with the hub so the "Connected" state appears without a manual
 * reload).
 *
 * Consumed by BOTH surfaces so they can't drift:
 *   - The score panel's "connect to the Hub" modal
 *     (src/components/score/ConnectHubModal.tsx)
 *   - The onboarding wizard's first "Connect your account" step
 *     (src/onboarding/steps/Connect.tsx)
 *
 * The Settings Hub card (src/components/settings/HubCard.tsx) is NOT one of
 * them — it carries its own copy of this lifecycle, which is why the
 * `return_url` override below is written out a second time there.
 *
 * The onboarding step passes `initial` (from XSpeedConfig.bootstrap.hub) so it
 * renders the correct state on first paint with no round-trip; `active` gates
 * the lazy load for the panel tab (which mounts hidden).
 */

import { useCallback, useEffect, useState, useSyncExternalStore } from 'react';
import { api } from '../api/client';
import type { HubStatus } from '../types';

/**
 * Disconnect is deliberately NOT here. It used to be, unused, POSTing
 * /mcp/hub/disconnect with no confirmation — a second door to a call that
 * tells the Hub to drop this site. There is one implementation now, in the
 * Hub card, and it goes through the blocker check. Anything that needs to
 * disconnect moves that one, it does not grow a second.
 */
export type HubBusy = null | 'load' | 'token';

/**
 * Module-level shared status store. Every useHubConnect() instance (the
 * dashboard header chip, the Account panel, the MCP Hub card, the onboarding
 * Connect step) reads + writes the SAME status here, so a connect/disconnect
 * updates ALL of them in the same render — no lag where the chip flips a beat
 * after the card. `busy`/`error` stay per-instance (they're about a specific
 * surface's in-flight action), but `status` (the connection truth) is global.
 */
// Seed the shared store from the page bootstrap so the connected/not-connected
// state is correct on the VERY FIRST paint — no "not connected → connected"
// flash, no skeleton, no initial fetch. Both the dashboard (Admin bootstrap)
// and the wizard (Onboarding bootstrap) ship `bootstrap.hub`. Null when the MCP
// module is unavailable — then the hook lazily fetches /mcp/hub as before.
const bootstrapHub =
  typeof window !== 'undefined'
    ? ((window.XSpeedConfig?.bootstrap?.hub ?? null) as HubStatus | null)
    : null;

let sharedStatus: HubStatus | null = bootstrapHub;
// `loaded` is true when we already have a status to show. A bootstrapped status
// means we're loaded from the first render (the common case); without it we
// show the not-connected default and confirm via the lazy fetch.
let sharedLoaded = bootstrapHub != null;
// True while a /mcp/hub load is in flight — so the several instances mounting
// at once don't each fire their own fetch (one loads, all share the result).
let sharedLoading = false;
// One-shot init guard: seed-from-initial or the single confirming load runs
// exactly ONCE per page load, across ALL hook instances — not per-instance
// (which raced and caused inconsistent connected/not-connected flips).
let sharedInitialized = false;
const statusListeners = new Set<() => void>();

function publishStatus(next: HubStatus | null): void {
  sharedStatus = next;
  sharedLoaded = true;
  statusListeners.forEach((fn) => fn());
}
function subscribeStatus(fn: () => void): () => void {
  statusListeners.add(fn);
  return () => statusListeners.delete(fn);
}

interface UseHubConnectOptions {
  /**
   * Whether this surface is currently visible. The panel's Hub tab mounts
   * hidden and only wants to load once it becomes active; the wizard step is
   * always active. Defaults to true.
   */
  active?: boolean;
  /**
   * Pre-fetched status (onboarding bootstrap). When provided the hook starts
   * `loaded` with no initial fetch — first paint is instant. Live updates
   * still flow through reload()/reconcile on tab return.
   */
  initial?: HubStatus | null;
}

export function useHubConnect({ active = true, initial }: UseHubConnectOptions = {}) {
  // NOTE: the shared store is seeded ONCE at module load from
  // `window.XSpeedConfig.bootstrap.hub` (see `bootstrapHub` above). We do NOT
  // mutate the store during render here — that caused tearing / inconsistent
  // state under React 18. An explicit `initial` (onboarding) seeds the store in
  // an effect below, not during render.

  // status + loaded come from the SHARED store — every instance re-renders
  // together the instant any of them publishes a new status.
  const status = useSyncExternalStore(subscribeStatus, () => sharedStatus);
  const loaded = useSyncExternalStore(subscribeStatus, () => sharedLoaded);

  // busy + error stay per-instance: they describe THIS surface's in-flight
  // action (a spinner on the button the user actually clicked), not the shared
  // connection truth.
  const [busy, setBusy] = useState<HubBusy>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    // Only one instance actually fetches; the shared store fans the result out.
    if (sharedLoading) return;
    sharedLoading = true;
    setBusy('load');
    setError(null);
    try {
      publishStatus(await api.request<HubStatus>('/mcp/hub'));
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load xSpeed Hub status.');
    } finally {
      sharedLoading = false;
      setBusy(null);
    }
  }, []);

  // Silent refresh (no spinner). `reconcile` forces the plugin to re-check the
  // hub (source of truth). Sent as a header (not a query param) so it works on
  // plain-permalink REST URLs that already carry ?rest_route=.
  const reload = useCallback(async (reconcile = false) => {
    try {
      const init = reconcile ? { headers: { 'X-XSpeed-Reconcile': '1' } } : {};
      publishStatus(await api.request<HubStatus>('/mcp/hub', init));
    } catch {
      /* keep current status on a transient error */
    }
  }, []);

  const generateToken = useCallback(async () => {
    setBusy('token');
    setError(null);
    try {
      publishStatus(await api.request<HubStatus>('/mcp/hub/token', { method: 'POST' }));
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Could not generate a token.');
    } finally {
      setBusy(null);
    }
  }, []);

  // One-time initialization per PAGE LOAD (guarded so it runs once across all
  // instances, not per-instance — that was the source of the inconsistency).
  //   1. If an explicit `initial` was passed (onboarding) and the store is
  //      still empty, seed it — no fetch, instant correct paint.
  //   2. Otherwise confirm the current value against the server with ONE silent
  //      load. The bootstrap is usually correct, so this rarely changes
  //      anything; when it does (e.g. a stale cached page), it corrects to
  //      reality. Only one network call, once — no races.
  useEffect(() => {
    if (sharedInitialized || !active) return;
    sharedInitialized = true;
    if (initial != null && !sharedLoaded) {
      publishStatus(initial);
      return;
    }
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active]);

  // Returned from the Hub after approving? The Hub bounces the browser back to
  // ?xspeed_connected=1 (return_url). Force one reconcile so the connected state
  // appears immediately, THEN strip the one-time markers from the URL so a later
  // reload can't re-trigger this "just connected" path and flash a stale state.
  // (The PHP handler normally redirects these away first; this is a fallback for
  // the SPA case where they linger in the address bar.)
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    if (url.searchParams.get('xspeed_connected') === '1') {
      void reload(true);
      url.searchParams.delete('xspeed_connected');
      url.searchParams.delete('xspeed_hub_nonce');
      url.searchParams.delete('xspeed_hub_email');
      window.history.replaceState(null, '', url.toString());
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Auto-refresh when the admin returns to this browser tab — e.g. after
  // completing the Connect flow in the new tab it opened. Forces a fresh
  // reconcile so the "Connected" state appears without a manual reload.
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
  }, [active, loaded, reload]);

  // The connect link, with `return_url` forced to the CURRENT page so the Hub
  // sends the user back exactly where they started (the onboarding wizard vs.
  // the dashboard Account panel) — plus an `xspeed_connected=1` marker so the
  // landing page reconciles + shows the connected state. Overriding here (not
  // server-side) means the URL is correct wherever the hook is used, and it's
  // never clobbered by a generic /mcp/hub reload.
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

  return {
    status,
    connectUrl,
    busy,
    error,
    loaded,
    attached: Boolean(status?.attached),
    hasToken: Boolean(status?.site_token),
    load,
    reload,
    generateToken,
    setError,
  };
}
