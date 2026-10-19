import { useCallback, useEffect, useRef, useState } from 'react';
import { Gauge, RefreshCw, ArrowRight } from 'lucide-react';
import { api } from '../../api/client';
import { __ } from '../../i18n';
import { ConnectHubModal, canConnect } from './ConnectHubModal';

/**
 * "Run a free GTmetrix test" on the cache dashboard.
 *
 * This is the discovery surface. Someone who has just installed the plugin
 * lands on the cache page — not two clicks deep in a sidebar entry — so this
 * is where "we'll run an independent speed test for you, free" actually gets
 * seen. An unconnected visitor gets the invitation; a connected one gets the
 * button and their remaining count.
 *
 * Renders NOTHING when the Hub can't serve a test and the site isn't in a
 * position to connect (no runner configured, site not verified). A dead card
 * on the main dashboard is worse than no card.
 *
 * The panel at Speed test → module remains the full surface: history,
 * comparison, settings, and the own-API-key path. This is deliberately just
 * the one action.
 */

interface HubRun {
  id: number;
  status: 'queued' | 'running' | 'done' | 'error';
  error: string | null;
}

interface HubStatus {
  available: boolean;
  reason?: string;
  local?: boolean;
  runs?: HubRun[];
  quota?: { used: number; limit: number; remaining: number };
}

const active = (r: HubRun | null | undefined): boolean =>
  r?.status === 'queued' || r?.status === 'running';

export function SpeedTestCard({ onOpenPanel }: { onOpenPanel?: () => void }) {
  const [hub, setHub] = useState<HubStatus | null>(null);
  const [run, setRun] = useState<HubRun | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [connectOpen, setConnectOpen] = useState(false);
  const timer = useRef<number | null>(null);

  const load = useCallback(async () => {
    try {
      const s = await api.request<HubStatus>('/score/hub-status');
      setHub(s);
      setRun(s.runs?.[0] ?? null);
    } catch {
      setHub({ available: false });
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  // Clear on unmount — an orphaned interval keeps calling the Hub after the
  // user has navigated to another module.
  useEffect(() => {
    return () => {
      if (timer.current) window.clearTimeout(timer.current);
    };
  }, []);

  const poll = useCallback(async () => {
    try {
      const s = await api.request<HubStatus>('/score/hub-status');
      setHub(s);
      const latest = s.runs?.[0] ?? null;
      setRun(latest);
      if (active(latest)) {
        timer.current = window.setTimeout(() => void poll(), 5000);
      } else if (latest?.status === 'error' && latest.error) {
        setError(latest.error);
      }
    } catch {
      // Give up quietly; the run may still be fine and the panel will show it.
    }
  }, []);

  const start = useCallback(async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await api.request<{ run: HubRun; quota: HubStatus['quota'] }>('/score/hub-test', {
        method: 'POST',
        body: JSON.stringify({}),
      });
      setRun(res.run);
      setHub((h) => (h ? { ...h, quota: res.quota ?? h.quota } : h));
      timer.current = window.setTimeout(() => void poll(), 5000);
    } catch (e) {
      setError(e instanceof Error ? e.message : __('The test could not be started.'));
    } finally {
      setBusy(false);
    }
  }, [poll]);

  // Nothing to show until we know, and nothing to show when the Hub is out of
  // the picture for a reason the user can't act on here.
  if (!hub) return null;
  const canInvite = !hub.available && canConnect(hub.reason);
  if (!hub.available && !canInvite) return null;

  const spent = hub.available && (hub.quota?.remaining ?? 0) === 0;

  return (
    <>
      <div className="border border-line bg-paper rounded-lg p-4 sm:p-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3 min-w-0">
            <Gauge size={18} className="text-accent shrink-0" />
            <div className="min-w-0">
              <div className="font-medium text-ink">
                {__('GTmetrix test')}
              </div>
              <div className="text-[12px] text-muted">
                {spent
                  ? __('No tests left this month.')
                  : __('Performance score and Core Web Vitals for your home page.')}
              </div>
            </div>
          </div>

          <div className="flex items-center gap-2.5">
            {hub.available && hub.quota && (
              <span className="text-[11px] text-muted">
                {__('%1$d of %2$d left')
                  .replace('%1$d', String(hub.quota.remaining))
                  .replace('%2$d', String(hub.quota.limit))}
              </span>
            )}
            <button
              type="button"
              onClick={() => (hub.available ? void start() : setConnectOpen(true))}
              disabled={busy || active(run) || spent}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-accent bg-accent text-white hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-50"
            >
              {(busy || active(run)) && <RefreshCw size={13} className="animate-spin" />}
              {active(run) ? __('Testing…') : __('Run test')}
            </button>
          </div>
        </div>

        {error && (
          <p className="mt-3 text-[12px] text-danger">{error}</p>
        )}

        {/* Results live in the panel, so point at it rather than duplicating
            the history here. */}
        {run?.status === 'done' && onOpenPanel && (
          <button
            type="button"
            onClick={onOpenPanel}
            className="mt-3 inline-flex items-center gap-1 text-[12px] text-accent hover:underline"
          >
            {__('View the result')}
            <ArrowRight size={12} />
          </button>
        )}
      </div>

      <ConnectHubModal
        open={connectOpen}
        onClose={() => setConnectOpen(false)}
        local={hub.local === true}
        onConnected={() => {
          void load().then(() => void start());
        }}
      />
    </>
  );
}
