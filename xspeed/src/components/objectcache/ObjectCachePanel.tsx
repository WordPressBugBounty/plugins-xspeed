import { useResolvedSettings } from '../useResolvedSettings';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Server, RefreshCw, Trash2, Copy, CheckCircle2, AlertCircle, Power, Plug, Zap } from 'lucide-react';
import { Toggle } from '../Toggle';
import { Callout } from '../Callout';
import { SaveSpinner } from '../SaveIndicator';
import { api } from '../../api/client';
import { reconcileSaved } from '../reconcileSaved';
import { renderField } from '../ModulePanel';
import { DefinedInConfig, HeldBySetting } from '../DefinedInConfig';
import { OverrideConfirm } from '../OverrideConfirm';
import { InlineConfirm } from '../InlineConfirm';
import type { SchemaField, SettingOrigin } from '../../types';
import type { PanelProps } from '../customPanels';

interface DetectResponse {
  drop_in_installed: boolean;
  /** Installed AND carrying our tag — false when another plugin owns it. */
  drop_in_is_ours?: boolean;
  drop_in_path: string;
  drop_in_label: string;
  backend: string;
  wp_cache_active: boolean;
  class_available: Record<string, boolean>;
  /**
   * Present only when the backend connects but REFUSES WRITES — a key prefix
   * outside an ACL namespace (NOPERM) is the case that matters. Everything
   * else here reads healthy in that state, which is why the panel said
   * "Redis ready" over a cache that stored nothing. (#398)
   */
  write_probe?: { ok: false; message: string; at: number };
  /**
   * Who owns a foreign drop-in and how a switch to xSpeed would go. `refuse`
   * means we cannot switch safely and `reason` says where to turn it off.
   * (#686)
   */
  owner?: {
    state: 'none' | 'ours' | 'foreign';
    label: string;
    plugin: string;
    strategy: 'deactivate' | 'feature_off' | 'replace' | 'refuse' | '';
    reason: string;
    plan: string[];
  } | null;
  /** The plugin a switch replaced, which Disable can put back. */
  previous_owner?: { label: string } | null;
}

interface TestResponse {
  ok: boolean;
  backend: string;
  message: string;
  latency_ms: number | null;
}

interface EnableResponse {
  ok: boolean;
  message: string;
  needs_takeover?: boolean;
  /** Disable only: whether the previous plugin came back, when asked to. */
  restored?: boolean | null;
  steps: Record<string, boolean>;
  test?: TestResponse;
  detect: DetectResponse;
}

/*
 * Saved only by an explicit action, never by autosave. A browser password
 * manager reads this pair as a login form and fills in the site's WordPress
 * login on page load. Autosave posted it a second later, xSpeed wrote it into
 * wp-config.php, and Redis refused it. (#700)
 */
const CREDENTIAL_KEYS = ['redis_user', 'redis_password'] as const;

function withoutCredentials(s: Record<string, unknown>): string {
  const rest = { ...s };
  for (const key of CREDENTIAL_KEYS) delete rest[key];
  return JSON.stringify(rest);
}

function credentialsOf(s: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(CREDENTIAL_KEYS.map((key) => [key, s[key]]));
}

export function ObjectCachePanel({ module, onSaved }: PanelProps) {
  const [settings, setSettings] = useState<Record<string, unknown>>(module.settings);
  // Provenance moves when a field is overridden, so it lives in state rather
  // than being read straight off the descriptor. (#398)
  const [origins, setOrigins] = useState<Record<string, SettingOrigin>>(
    module.setting_origins ?? {}
  );
  const descriptorOrigins = useRef(module.setting_origins);
  descriptorOrigins.current = module.setting_origins;
  useEffect(() => {
    setOrigins(module.setting_origins ?? {});
  }, [module.setting_origins]);
  const [overrideBusy, setOverrideBusy] = useState<string | null>(null);
  const [detect, setDetect] = useState<DetectResponse | null>(null);
  const [snippet, setSnippet] = useState<string>('');
  const [busy, setBusy] = useState<null | 'detect' | 'flush' | 'snippet' | 'test' | 'enable' | 'disable' | 'credentials'>(null);
  const [error, setError] = useState<string | null>(null);
  // Scoped to the SAVE path only. `error` is shared with scan / flush /
  // start-stop actions, so passing it straight to SaveSpinner would paint a
  // later successful save red because an earlier unrelated action failed. (#5)
  const [saveError, setSaveError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [test, setTest] = useState<TestResponse | null>(null);
  const [manualNeeded, setManualNeeded] = useState(false);
  // Which consequential action is waiting on an inline confirmation. (#686)
  const [confirming, setConfirming] = useState<null | 'takeover' | 'disable'>(null);
  const [copied, setCopied] = useState(false);
  const [saving, setSaving] = useState(false);

  const debounceRef = useRef<number | null>(null);
  const initial = useRef(true);
  // Compared without the credentials, which only saveCredentials() writes.
  const lastApplied = useRef<string>(withoutCredentials(module.settings));
  const appliedCredentials = useRef<Record<string, unknown>>(credentialsOf(module.settings));
  useResolvedSettings(module, setSettings, lastApplied);
  // The hook writes held keys back into the baseline; a held credential must
  // not re-enter it, or autosave sees a change that only saveCredentials() owns.
  useEffect(() => {
    if (lastApplied.current) lastApplied.current = withoutCredentials(JSON.parse(lastApplied.current));
  }, [module.settings, module.setting_origins]);
  // Live mirror so the save response reconciles against current edits, not
  // the snapshot it was sent with (prevents mid-flight keystroke loss).
  const settingsRef = useRef(settings);
  useEffect(() => { settingsRef.current = settings; }, [settings]);

  // Guards the post-revert detect refresh: an admin who reverts and navigates
  // straight away must not have a late response set state on a dead instance.
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);

  const refresh = async () => {
    const originsAtRequest = descriptorOrigins.current;
    setBusy('detect');
    setError(null);
    try {
      // Provenance is re-read here, not just detect + snippet. Enabling writes
      // XSPEED_OC_* constants into wp-config.php, which PINS fields that were
      // editable a moment ago — without this the panel keeps offering them,
      // and the next autosave gets a 409 on a field it presented as free.
      // (#398)
      const [d, s, o] = await Promise.all([
        api.request<DetectResponse>('/object-cache/detect'),
        api.request<{ snippet: string }>('/object-cache/snippet'),
        api
          .request<Record<string, SettingOrigin>>('/object-cache/origins')
          .catch(() => null),
      ]);
      setDetect(d);
      setSnippet(s.snippet);
      if (o && descriptorOrigins.current === originsAtRequest) setOrigins(o);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Detection failed');
    } finally {
      setBusy(null);
    }
  };

  useEffect(() => {
    refresh();
  }, []);

  useEffect(() => {
    if (initial.current) {
      initial.current = false;
      return;
    }
    const serialized = withoutCredentials(settings);
    if (serialized === lastApplied.current) return;
    if (busy === 'credentials') return;
    if (debounceRef.current) window.clearTimeout(debounceRef.current);
    debounceRef.current = window.setTimeout(async () => {
      setSaving(true);
      setSaveError(null);
      // Clear the PANEL error too, not just the save-scoped one. Without this
      // a failure's red callout outlived the save that fixed it: the 5s toast
      // expired, the next save succeeded and showed the green "Settings
      // saved" toast, and the stale "…error / HTTP 503" callout was still
      // sitting above the fields — the screen contradicting itself. Pro's
      // useAutosave already clears on success; these four panels were the
      // odd ones out. (QA F1)
      setError(null);
      // The stored credentials ride along, so a draft in those two fields
      // waits for saveCredentials().
      const sent = { ...settings, ...appliedCredentials.current };
      let saved_ok = false;
      try {
        const saved = await api.updateModuleSettings<Record<string, unknown>>(module.slug, sent);
        appliedCredentials.current = credentialsOf(saved);
        const { next } = reconcileSaved(sent, saved, settingsRef.current);
        setSettings(next);
        // The draft credentials always differ from `sent`, so reconcileSaved's
        // `dirty` would be true on every save. Only an edit to another field
        // made while this request was in flight needs a second save.
        const editedInFlight = withoutCredentials(settingsRef.current) !== withoutCredentials(sent);
        lastApplied.current = editedInFlight ? '' : withoutCredentials(next);
        // Keep the shared module snapshot current so navigating away and
        // back re-seeds from the saved values, not stale bootstrap data.
        onSaved?.({ ...next, ...appliedCredentials.current });
        saved_ok = true;
      } catch (e) {
        setError(e instanceof Error ? e.message : 'Save failed');
        setSaveError(e instanceof Error ? e.message : 'Save failed');
      } finally {
        setSaving(false);
      }

      // Snippet derives from settings — refresh it after a save, but OUTSIDE
      // the save's try. Inside it, a failing GET /object-cache/snippet set
      // saveError and fired the red "Save failed — your change was not saved"
      // toast on a save that had returned 200 and persisted: the settings were
      // stored, the toast said they weren't. The snippet is a display concern,
      // so its failure belongs in the panel's own `error`, never in the
      // save-scoped signal. (QA F3, B2 from round 1)
      //
      // Skipped when the save itself failed: the snippet would be derived from
      // settings that were never stored, and overwriting the save's own error
      // message with a snippet one would hide the thing the user needs to see.
      if ( ! saved_ok ) {
        return;
      }
      try {
        const s = await api.request<{ snippet: string }>('/object-cache/snippet');
        setSnippet(s.snippet);
      } catch (e) {
        // Always NAME the thing that failed. `e.message` here is the transport
        // error — "HTTP 503" — which on its own tells the user nothing about
        // what broke, and sits in the panel looking like the save failed. The
        // status code is still useful, so it rides along after the cause.
        const detail = e instanceof Error ? e.message : '';
        setError(
          detail
            ? `Could not refresh the wp-config snippet (${detail}). Your settings were saved.`
            : 'Could not refresh the wp-config snippet. Your settings were saved.'
        );
      }
    }, 600);
    return () => {
      if (debounceRef.current) window.clearTimeout(debounceRef.current);
    };
  }, [settings, module.slug, busy]);

  const set = (key: string, value: unknown) =>
    setSettings((s) => ({ ...s, [key]: value }));

  const setOverride = async (key: string, on: boolean) => {
    setOverrideBusy(key);
    setError(null);
    try {
      const res = await api.request<{
        settings: Record<string, unknown>;
        origins: Record<string, SettingOrigin>;
      }>('/object-cache/override', {
        method: 'POST',
        body: JSON.stringify({ field: key, override: on }),
      });
      setOrigins(res.origins ?? {});
      // Reverting restores the constant's value, so take the server's copy
      // rather than leaving the box showing what it showed a moment ago.
      // Mark it applied first: these values came FROM the server, so letting
      // the autosave effect see them as a local edit would post them straight
      // back — and on a revert that write targets a field the server has just
      // re-pinned, which it then refuses. (#398)
      if (res.settings) {
        setSettings((s) => {
          // Only the field that moved — see the note in ModulePanel: merging
          // the whole bag and marking it applied silently drops an edit the
          // user has typed but not yet flushed. (#398)
          const moved = res.settings[key];
          // The server's value is the stored one now, so the credentials
          // autosave sends back must follow, or every later save posts the
          // value the override held to a field that is pinned again. (#700)
          if (moved !== undefined && (CREDENTIAL_KEYS as readonly string[]).includes(key)) {
            appliedCredentials.current = { ...appliedCredentials.current, [key]: moved };
          }
          if (moved === undefined || Object.is(moved, s[key])) return s;
          const next = { ...s, [key]: moved };
          if (withoutCredentials(s) === lastApplied.current) {
            lastApplied.current = withoutCredentials(next);
          }
          return next;
        });
      }
      setNotice(
        on
          ? 'This setting is now managed here.'
          : 'Reverted to the value in wp-config.php.'
      );
      // A revert removes our define and hands the field back, which can revive
      // a cache that was refusing writes — so the status card and its write
      // probe have to be re-read, or the panel keeps showing the failure that
      // this click just fixed. Awaited rather than fired and forgotten: the
      // recheck can take a second, and a late response landing after a
      // subsequent save would overwrite the fresher state with a stale
      // failure. `mounted` guards navigating away mid-flight. (#398)
      if (!on) {
        try {
          const d = await api.request<DetectResponse>('/object-cache/detect');
          if (mounted.current) setDetect(d);
        } catch {
          /* The revert itself succeeded; a stale card is not worth an error. */
        }
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setOverrideBusy(null);
    }
  };

  const handleFlush = async () => {
    setBusy('flush');
    setError(null);
    setNotice(null);
    try {
      const res = await api.request<{ ok: boolean }>('/object-cache/flush', { method: 'POST' });
      setNotice(res.ok ? 'Object cache flushed.' : 'Flush returned false — cache backend may not be active.');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Flush failed');
    } finally {
      setBusy(null);
    }
  };

  const handleTest = async () => {
    setBusy('test');
    setError(null);
    setNotice(null);
    setTest(null);
    try {
      const res = await api.request<TestResponse>('/object-cache/test-connection', {
        method: 'POST',
        body: JSON.stringify(settings),
      });
      setTest(res);
      if (!res.ok) setError(res.message);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Connection test failed');
    } finally {
      setBusy(null);
    }
  };

  // The server tests the new pair against Redis before it stores anything,
  // and answers 422 with the reason when Redis refuses it. (#700)
  const saveCredentials = async () => {
    // This save sends every field, so a pending autosave has nothing left to
    // do, and firing during the test would post the old pair. (#700)
    if (debounceRef.current) window.clearTimeout(debounceRef.current);
    setBusy('credentials');
    setError(null);
    setNotice(null);
    try {
      const sent = settingsRef.current;
      const saved = await api.updateModuleSettings<Record<string, unknown>>(module.slug, sent);
      appliedCredentials.current = credentialsOf(saved);
      const { next } = reconcileSaved(sent, saved, settingsRef.current);
      lastApplied.current = withoutCredentials(next);
      setSettings(next);
      onSaved?.(next);
      setNotice('Redis accepted the new username and password, and they are saved.');
      await refresh();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Save failed');
    } finally {
      setBusy(null);
    }
  };

  const discardCredentials = () => {
    setSettings((s) => ({ ...s, ...appliedCredentials.current }));
  };

  const handleEnable = async (takeover = false) => {
    setBusy('enable');
    setError(null);
    setNotice(null);
    setManualNeeded(false);
    setConfirming(null);
    try {
      // `takeover` is an action, not a setting; the server takes it out of
      // the body before saving the rest as the settings row.
      const res = await api.request<EnableResponse>('/object-cache/enable', {
        method: 'POST',
        body: JSON.stringify(takeover ? { ...settings, takeover: true } : settings),
      });
      setTest(res.test ?? null);
      if (res.ok) {
        setNotice(res.message);
        setDetect(res.detect);
      } else {
        setError(res.message);
        // Drop-in installed but wp-config not writable → surface manual snippet.
        if (res.steps.drop_in && !res.steps.wp_config) setManualNeeded(true);
      }
      // The enable call saved whatever the fields held, credentials too.
      appliedCredentials.current = credentialsOf(settingsRef.current);
      await refresh();
      // The header's On/Off pill reads the module list, which only a save
      // re-reads; enabling or switching changes it without a save. (#687)
      onSaved?.(settingsRef.current);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Enable failed');
    } finally {
      setBusy(null);
    }
  };

  const handleDisable = async (restore = false) => {
    setBusy('disable');
    setError(null);
    setNotice(null);
    setConfirming(null);
    try {
      const res = await api.request<EnableResponse>('/object-cache/disable', {
        method: 'POST',
        body: JSON.stringify({ restore }),
      });
      // Disabling worked even when the previous plugin could not come back;
      // that half is reported as the problem it is.
      if (res.restored === false) setError(res.message);
      else setNotice(res.message);
      setTest(null);
      setManualNeeded(false);
      await refresh();
      onSaved?.(settingsRef.current);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Disable failed');
    } finally {
      setBusy(null);
    }
  };

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(snippet);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    } catch {
      setError('Clipboard write blocked — select + copy the snippet manually.');
    }
  };

  const backend = String(settings.backend ?? 'redis');
  const credentialsDraft =
    backend === 'redis' &&
    CREDENTIAL_KEYS.some((key) => !Object.is(settings[key] ?? '', appliedCredentials.current[key] ?? ''));
  // A socket path has no port, and the server ignores redis_port for one, so
  // showing it would invite an edit that changes nothing. Same rule as
  // Redis_Client::socket_path(). (#566)
  const redisSocket = /^(\/|unix:)/i.test(String(settings.redis_host ?? '').trim());
  const fields = useMemo(() => Object.entries(module.schema), [module.schema]);
  const visible = fields.filter(([key]) => {
    if (backend === 'redis' && (key === 'memcached_host' || key === 'memcached_port')) return false;
    if (backend === 'redis' && redisSocket && key === 'redis_port') return false;
    if (backend === 'memcached' && (key === 'redis_host' || key === 'redis_port' || key === 'redis_user' || key === 'redis_password' || key === 'redis_database')) return false;
    return true;
  });

  // A drop-in owned by ANOTHER plugin (W3TC, Redis Object Cache, LiteSpeed)
  // makes wp_cache_active true while xSpeed is running nothing. Reading that
  // as "Active" claimed a cache we do not own, contradicted the Off pill, and
  // put a Disable button on someone else's file.
  const foreignDropin = !!detect?.drop_in_installed && !detect?.drop_in_is_ours;
  const ownerLabel = detect?.owner?.label || detect?.drop_in_label || 'another plugin';
  // A switch is offered unless the server said it cannot be done safely
  // (a hosting platform's drop-in, a must-use plugin, a plugin with no
  // object-cache setting we can turn off). (#686)
  const canSwitch = foreignDropin && !!detect?.owner && detect.owner.strategy !== 'refuse' && detect.owner.strategy !== '';
  const previousOwner = detect?.drop_in_is_ours ? detect?.previous_owner ?? null : null;

  const status = detect
    ? foreignDropin
      ? {
          tone: 'warn' as const,
          label: `Managed by ${ownerLabel}`,
          sub:
            detect.owner?.strategy === 'refuse'
              ? detect.owner.reason
              : detect.owner?.strategy === 'replace'
                ? `wp-content/object-cache.php was left by ${ownerLabel}, which is not active. Switch to xSpeed to replace it.`
                : `${ownerLabel} owns wp-content/object-cache.php. Switch to xSpeed to take it over; Disable can put it back later.`,
        }
      : // A backend that connects but refuses writes is indistinguishable from
      // a healthy one on every other signal — drop-in installed, wp_cache_active
      // true, "Redis ready". Reading it as "Active" is what let a site pay for a
      // cache that stored nothing, with only the CLI saying so. (#398)
      detect.write_probe && !detect.write_probe.ok
        ? {
            tone: 'warn' as const,
            label: 'Connected, but storing nothing',
            sub: `${detect.write_probe.message} Writes are being refused, so nothing is cached. Check the Cache Key Prefix, or use the host’s value.`,
          }
        : detect.wp_cache_active
          ? { tone: 'ok' as const, label: `Active: ${detect.drop_in_label || detect.backend}`, sub: `Backend: ${detect.backend}` }
          : { tone: 'warn' as const, label: 'No persistent object cache', sub: 'WordPress is using its built-in in-memory cache (resets every request).' }
    : null;

  return (
    <div className="space-y-4">
      <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg p-4 sm:p-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3 min-w-0">
            {status?.tone === 'ok' ? (
              <CheckCircle2 size={18} className="text-emerald-500 shrink-0" />
            ) : status?.tone === 'warn' ? (
              <AlertCircle size={18} className="text-amber-500 shrink-0" />
            ) : (
              <Server size={18} className="text-neutral-400 shrink-0" />
            )}
            <div className="min-w-0">
              <div className="font-medium text-neutral-900 dark:text-neutral-50">
                {status ? status.label : 'Detecting…'}
              </div>
              <div className="text-[12px] text-neutral-500 dark:text-neutral-400">
                {status?.sub ?? ''}
              </div>
            </div>
          </div>
          <div className="flex items-center gap-2 shrink-0">
            <button
              type="button"
              onClick={refresh}
              disabled={busy !== null}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition-colors disabled:opacity-50"
            >
              <RefreshCw size={13} className={busy === 'detect' ? 'animate-spin' : ''} />
              Re-detect
            </button>
            {detect?.drop_in_is_ours && (
              <button
                type="button"
                onClick={handleFlush}
                disabled={busy !== null}
                className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition-colors disabled:opacity-50"
              >
                {busy === 'flush' ? <RefreshCw size={13} className="animate-spin" /> : <Trash2 size={13} />}
                Flush
              </button>
            )}
          </div>
        </div>
        {detect && (() => {
          // Readiness for the SELECTED backend — what actually matters.
          // xSpeed connects via the native extension when present, else its own
          // built-in client, so a missing extension is NEVER a blocker.
          const ext =
            backend === 'redis'
              ? Boolean(detect.class_available.Redis)
              : Boolean(detect.class_available.Memcached || detect.class_available.Memcache);
          const name = backend === 'redis' ? 'Redis' : 'Memcached';
          const extName = backend === 'redis' ? 'phpredis' : 'memcached';
          return (
            <div className="mt-3 flex items-center gap-2 text-[12px]">
              <span className="px-2 py-0.5 rounded border border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300 whitespace-nowrap">
                {name} ready
              </span>
              <span className="text-neutral-500 dark:text-neutral-400">
                {ext
                  ? `Native ${extName} extension detected — fastest path.`
                  : 'xSpeed uses its built-in client — no extension to install.'}
              </span>
            </div>
          );
        })()}
      </div>

      {error && <Callout tone="danger" title="Error" body={error} />}
      {notice && !error && <Callout tone="success" title="Done" body={notice} />}

      <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg p-4 sm:p-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="min-w-0">
            <div className="font-medium text-neutral-900 dark:text-neutral-50 flex items-center gap-2">
              <Zap size={15} className="text-accent" />
              One-click setup
            </div>
            <div className="text-[12px] text-neutral-500 dark:text-neutral-400 mt-0.5">
              {foreignDropin
                ? canSwitch
                  ? detect?.owner?.strategy === 'replace'
                    ? `${ownerLabel} is not active, but its object-cache.php is still in wp-content. Switching keeps a copy, removes it, and installs xSpeed's.`
                    : `${ownerLabel} handles object caching now. Switching backs up its file, turns it off, then installs xSpeed's, and undoes everything if a step fails.`
                  : detect?.owner?.reason || `${ownerLabel} owns the object-cache drop-in. Turn its object cache off there first.`
                : detect?.drop_in_is_ours
                  ? 'Object cache is installed. Disable to remove the drop-in and clean wp-config.php.'
                  : `Test the ${backend} connection, then enable — xSpeed writes the drop-in and wp-config.php for you. No manual steps.`}
            </div>
            {test && (
              <div
                className={`mt-2 inline-flex items-center gap-1.5 text-[12px] px-2 py-1 rounded ${
                  test.ok
                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
                    : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300'
                }`}
              >
                {test.ok ? <CheckCircle2 size={13} /> : <AlertCircle size={13} />}
                {test.message}
                {test.latency_ms != null && test.ok ? ` (${test.latency_ms} ms)` : ''}
              </div>
            )}
          </div>
          <div className="flex items-center gap-2 shrink-0">
            <button
              type="button"
              onClick={handleTest}
              // Focus target for the "Object cache is configured but not
              // persisting" recommendation (issue #49).
              data-field-anchor="object-cache:connection_test"
              disabled={busy !== null}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition-colors disabled:opacity-50"
            >
              {busy === 'test' ? <RefreshCw size={13} className="animate-spin" /> : <Plug size={13} />}
              Test connection
            </button>
            {foreignDropin ? (
              // Hidden while the confirmation is open, which carries its own
              // "Switch to xSpeed" button.
              canSwitch && confirming !== 'takeover' ? (
                <button
                  type="button"
                  onClick={() => setConfirming('takeover')}
                  disabled={busy !== null || confirming !== null}
                  className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-accent bg-accent text-white hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  {busy === 'enable' ? <RefreshCw size={13} className="animate-spin" /> : <Power size={13} />}
                  Switch to xSpeed
                </button>
              ) : null
            ) : detect?.drop_in_is_ours ? (
              <button
                type="button"
                onClick={() => (previousOwner ? setConfirming('disable') : handleDisable(false))}
                disabled={busy !== null}
                className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors disabled:opacity-50"
              >
                {busy === 'disable' ? <RefreshCw size={13} className="animate-spin" /> : <Power size={13} />}
                Disable
              </button>
            ) : (
              <button
                type="button"
                onClick={() => handleEnable(false)}
                disabled={busy !== null}
                className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-accent bg-accent text-white hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
              >
                {busy === 'enable' ? <RefreshCw size={13} className="animate-spin" /> : <Power size={13} />}
                Enable object cache
              </button>
            )}
          </div>
        </div>
        {confirming === 'takeover' && detect?.owner ? (
          <div className="mt-3">
            <InlineConfirm
              tone="warn"
              title={
                detect.owner.strategy === 'replace'
                  ? `Replace the object-cache.php left by ${ownerLabel}?`
                  : `Switch object caching from ${ownerLabel} to xSpeed?`
              }
              body={
                <ol className="list-decimal pl-4 space-y-0.5">
                  {detect.owner.plan.map((step) => (
                    <li key={step}>{step}</li>
                  ))}
                </ol>
              }
              confirmLabel="Switch to xSpeed"
              busy={busy === 'enable'}
              onConfirm={() => handleEnable(true)}
              onCancel={() => setConfirming(null)}
            />
          </div>
        ) : null}
        {confirming === 'disable' && previousOwner ? (
          <div className="mt-3">
            <InlineConfirm
              tone="warn"
              title="Disable xSpeed's object cache?"
              body={`xSpeed took over from ${previousOwner.label}. Restore puts its object-cache.php back and turns it on again; Disable only leaves the site on WordPress's built-in cache.`}
              confirmLabel={`Disable and restore ${previousOwner.label}`}
              altLabel="Disable only"
              onAlt={() => handleDisable(false)}
              busy={busy === 'disable'}
              onConfirm={() => handleDisable(true)}
              onCancel={() => setConfirming(null)}
            />
          </div>
        ) : null}
      </div>

      <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg overflow-hidden">
        {visible.map(([key, spec], i) => (
          <SettingRow
            key={key}
            fieldKey={key}
            spec={spec}
            value={settings[key]}
            onChange={(v) => set(key, v)}
            origin={origins[key]}
            overrideBusy={overrideBusy === key}
            onSetOverride={(on) => setOverride(key, on)}
            first={i === 0}
          />
        ))}
      </div>

      {credentialsDraft && (
        <div
          className="border border-amber-200 dark:border-amber-800 bg-amber-50/40 dark:bg-amber-900/10 rounded-lg p-4 sm:p-5 flex flex-wrap items-center justify-between gap-3"
          data-field-anchor="object-cache:credentials"
        >
          <div className="min-w-0">
            <div className="font-medium text-neutral-900 dark:text-neutral-50">Redis username or password changed</div>
            <div className="text-[12px] text-neutral-500 dark:text-neutral-400 mt-0.5">
              Not saved yet. xSpeed tests them against Redis first and keeps the current connection if Redis refuses them. If you did not type them, your browser filled them in: discard them.
            </div>
          </div>
          <div className="flex items-center gap-2 shrink-0">
            <button
              type="button"
              onClick={discardCredentials}
              disabled={busy !== null}
              className="text-[13px] px-3 py-1.5 rounded border border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition-colors disabled:opacity-50"
            >
              Discard
            </button>
            <button
              type="button"
              onClick={saveCredentials}
              disabled={busy !== null}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-accent bg-accent text-white hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
            >
              {busy === 'credentials' ? <RefreshCw size={13} className="animate-spin" /> : <Plug size={13} />}
              Save and test connection
            </button>
          </div>
        </div>
      )}

      {manualNeeded && (
      <div className="border border-amber-200 dark:border-amber-800 bg-amber-50/40 dark:bg-amber-900/10 rounded-lg p-4 sm:p-5">
        <div className="flex items-center justify-between gap-3 mb-3">
          <div>
            <div className="font-medium text-neutral-900 dark:text-neutral-50">Manual step needed — wp-config.php isn’t writable</div>
            <div className="text-[12px] text-neutral-500 dark:text-neutral-400 mt-0.5">
              The drop-in was installed, but xSpeed couldn’t edit wp-config.php automatically. Paste this above the “That’s all, stop editing!” comment to finish.
            </div>
          </div>
          <button
            type="button"
            onClick={handleCopy}
            disabled={!snippet}
            className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition-colors disabled:opacity-50"
          >
            {copied ? <CheckCircle2 size={13} className="text-emerald-500" /> : <Copy size={13} />}
            {copied ? 'Copied' : 'Copy'}
          </button>
        </div>
        <pre className="text-[11px] leading-relaxed font-mono whitespace-pre-wrap break-all bg-neutral-50 dark:bg-neutral-950 text-neutral-700 dark:text-neutral-300 border border-neutral-200 dark:border-neutral-800 rounded p-3 max-h-72 overflow-auto">
{snippet || '// Adjust the settings above to generate a snippet.'}
        </pre>
      </div>
      )}

      <SaveSpinner saving={saving} error={saveError} />
    </div>
  );
}

function SettingRow({
  fieldKey,
  spec,
  value,
  onChange,
  origin,
  overrideBusy,
  onSetOverride,
  first,
}: {
  fieldKey: string;
  spec: SchemaField;
  value: unknown;
  onChange: (v: unknown) => void;
  origin?: SettingOrigin;
  overrideBusy?: boolean;
  onSetOverride?: (on: boolean) => void;
  first: boolean;
}) {
  const label = spec.label ?? fieldKey;
  // Pinned by a wp-config.php constant: show the effective value, read-only,
  // and name the constant. DESIGN.md §24.35.
  const pinnedTo = origin?.source === 'constant' ? origin.constant : null;
  // Held by another setting (DESIGN.md §24.56): read-only like a pin.
  const heldBy = origin?.source === 'managed' ? origin.managed_by ?? null : null;
  // Taken back deliberately — editable, but still say what it shadows. §24.36.
  const overriding = origin?.overriding ?? null;
  const [confirming, setConfirming] = useState(false);
  return (
    <div className={`px-4 sm:px-5 py-4 ${first ? '' : 'border-t border-neutral-200 dark:border-neutral-800'}`}>
      <div className="flex items-start justify-between gap-3 sm:gap-4">
        <div className="min-w-0 pr-2">
          <div className="font-medium text-neutral-900 dark:text-neutral-50">{label}</div>
          {spec.description && (
            <div className="text-[12px] text-neutral-500 dark:text-neutral-400 mt-0.5">{spec.description}</div>
          )}
          {(pinnedTo || overriding) && (
            <DefinedInConfig
              constant={(pinnedTo ?? overriding) as string}
              overridden={!pinnedTo}
              busy={overrideBusy}
              onOverride={onSetOverride ? () => setConfirming(true) : undefined}
              onRevert={onSetOverride ? () => onSetOverride(false) : undefined}
            />
          )}
          {heldBy && <HeldBySetting by={heldBy} />}
        </div>
        <div className="shrink-0">{renderControl(spec, value, onChange, label, !!pinnedTo || !!heldBy)}</div>
      </div>
      {/* Full row width, beneath the label/control pair — the label column is
          narrow and the card needs room for its copy and two buttons. */}
      {confirming && pinnedTo && (
        <div className="mt-3">
          <OverrideConfirm
            constant={pinnedTo}
            busy={overrideBusy}
            onConfirm={() => {
              setConfirming(false);
              onSetOverride?.(true);
            }}
            onCancel={() => setConfirming(false)}
          />
        </div>
      )}
    </div>
  );
}

function renderControl(
  spec: SchemaField,
  value: unknown,
  onChange: (v: unknown) => void,
  label: string,
  readOnly = false
) {
  switch (spec.type) {
    case 'bool':
      return <Toggle checked={Boolean(value)} onChange={onChange} label={label} disabled={readOnly} />;
    // No local `int` case: this panel hand-rolled its own number input, which
    // silently opted Connection Timeout out of anything the shared renderer
    // adds — the duration hint (#159) among them. Falling through to
    // renderField keeps one implementation of the int control. (QA F2)
    case 'enum':
      return (
        <div className="flex flex-wrap gap-1.5">
          {(spec.options ?? []).map((opt) => {
            const active = value === opt;
            const lbl = spec.option_labels?.[opt] ?? opt;
            return (
              <button
                key={opt}
                type="button"
                onClick={() => onChange(opt)}
                disabled={readOnly}
                className={`text-[12px] px-2.5 py-1 rounded border transition-colors ${
                  active
                    ? 'bg-accent border-accent text-white'
                    : 'border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800'
                } ${readOnly ? 'opacity-60 cursor-not-allowed' : ''}`}
              >
                {lbl}
              </button>
            );
          })}
        </div>
      );
    case 'string':
      return (
        <input
          type="text"
          value={value === undefined || value === null ? '' : String(value)}
          onChange={(e) => onChange(e.target.value)}
          disabled={readOnly}
          className={`w-56${readOnly ? ' opacity-60 cursor-not-allowed' : ''}`}
          autoComplete="off"
          // Password managers skip fields carrying these. Chrome ignores
          // autocomplete="off" on a text field above a password field. (#700)
          data-1p-ignore="true"
          data-lpignore="true"
          data-bwignore="true"
          data-form-type="other"
          aria-label={label}
        />
      );
    default:
      // Everything else — including `secret` (redis_password since #115) —
      // renders through the shared renderer. The old `return null` here is
      // how the password input vanished from this panel (QA B1 round 2).
      return renderField(spec, value, onChange, label, readOnly);
  }
}
