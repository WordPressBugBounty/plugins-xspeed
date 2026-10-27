import { useResolvedSettings } from '../useResolvedSettings';
import { useEffect, useRef, useState } from 'react';
import { CheckCircle2, RefreshCw, Trash2, AlertCircle } from 'lucide-react';
import { Callout } from '../Callout';
import { Toggle } from '../Toggle';
import { SaveSpinner } from '../SaveIndicator';
import { ProSlot } from '../../upsell/ProSlot';
import { ProTabBody } from '../../upsell/ProTabBody';
import { api } from '../../api/client';
import { useVisibleFields } from '../fieldVisibility';
import { reconcileSaved } from '../reconcileSaved';
import { renderField } from '../ModulePanel';
import { HeldBySetting } from '../DefinedInConfig';
import type { SettingOrigin } from '../../types';
import type { SchemaField } from '../../types';
import type { PanelProps } from '../customPanels';

interface CfResult {
  ok: boolean;
  status: number;
  body: Record<string, unknown> & { result?: { name?: string; plan?: { name?: string } }; message?: string };
}

export function CloudflarePanel({ module, onSaved, getModule }: PanelProps) {
  const [settings, setSettings] = useState<Record<string, unknown>>(module.settings);
  const [verify, setVerify] = useState<CfResult | null>(null);
  const [busy, setBusy] = useState<null | 'verify' | 'purge' | 'devon' | 'devoff'>(null);
  const [error, setError] = useState<string | null>(null);
  // Scoped to the SAVE path only. `error` is shared with scan / flush /
  // start-stop actions, so passing it straight to SaveSpinner would paint a
  // later successful save red because an earlier unrelated action failed. (#5)
  const [saveError, setSaveError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const debounceRef = useRef<number | null>(null);
  const initial = useRef(true);
  const lastApplied = useRef<string>(JSON.stringify(module.settings));
  useResolvedSettings(module, setSettings, lastApplied);
  // Live mirror so the save response reconciles against current edits, not
  // the snapshot it was sent with (prevents mid-flight keystroke loss).
  const settingsRef = useRef(settings);
  useEffect(() => { settingsRef.current = settings; }, [settings]);

  useEffect(() => {
    if (initial.current) {
      initial.current = false;
      return;
    }
    const serialized = JSON.stringify(settings);
    if (serialized === lastApplied.current) return;
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
      const sent = settings;
      try {
        const saved = await api.updateModuleSettings<Record<string, unknown>>(module.slug, sent);
        const { next, dirty } = reconcileSaved(sent, saved, settingsRef.current);
        setSettings(next);
        lastApplied.current = dirty ? '' : JSON.stringify(next);
        onSaved?.(next);
      } catch (e) {
        setError(e instanceof Error ? e.message : 'Save failed');
        setSaveError(e instanceof Error ? e.message : 'Save failed');
      } finally {
        setSaving(false);
      }
    }, 600);
    return () => {
      if (debounceRef.current) window.clearTimeout(debounceRef.current);
    };
  }, [settings, module.slug]);

  const set = (key: string, value: unknown) =>
    setSettings((s) => ({ ...s, [key]: value }));

  const run = async (
    label: typeof busy,
    path: string,
    method: 'POST' = 'POST',
    body?: Record<string, unknown>
  ) => {
    setBusy(label);
    setError(null);
    setNotice(null);
    try {
      const res = await api.request<CfResult>(path, {
        method,
        body: body ? JSON.stringify(body) : undefined,
      });
      if (path === '/cloudflare/verify') setVerify(res);
      if (res.ok) {
        setNotice(`Cloudflare: ${labelFor(label)} succeeded.`);
      } else {
        setError((res.body?.message as string) || `Cloudflare API returned HTTP ${res.status}.`);
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Request failed');
    } finally {
      setBusy(null);
    }
  };

  const authMethod = String(settings.auth_method ?? 'token');
  const cfEnabled = Boolean(settings.enabled);
  // Shared `dependsOn` gate (FBS-82227) + the credential slot filter for the
  // chosen auth method. This custom panel bypasses SchemaModulePanel, so it
  // routes through the same useVisibleFields helper — otherwise dependent
  // fields (auth/token/zone) would render even when Cloudflare's `enabled`
  // toggle is off. The extra filter drops the credential field that doesn't
  // match auth_method, and `auto_purge_on_update`, which is not part of the
  // Connect card — it renders in its own "Purge behavior" card below, always
  // visible (disabled while disconnected) so users can see the sync switch
  // exists before they connect. Running the filter inside the helper keeps
  // row indices contiguous so the `first` top-border lands on the real first
  // visible row.
  const visibleFields = useVisibleFields(module.schema, settings, (key) => {
    if (key === 'auto_purge_on_update') return false;
    if (authMethod === 'token' && (key === 'email' || key === 'api_key')) return false;
    if (authMethod === 'key' && key === 'api_token') return false;
    return true;
  });
  const autoPurgeSpec = module.schema?.auto_purge_on_update as SchemaField | undefined;

  return (
    <div className="space-y-4">
      {/* ── Connection: status + enable + credentials + Verify, one card.
          Setup lives above the actions that need it. ── */}
      <div>
        <div className="text-[11px] uppercase tracking-wider text-neutral-500 dark:text-neutral-400 font-medium mb-2">
          Connection
        </div>
        <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg overflow-hidden">
          <div className="px-4 sm:px-5 py-4 flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-3 min-w-0">
              {verify?.ok ? (
                <CheckCircle2 size={18} className="text-emerald-500 shrink-0" />
              ) : verify ? (
                <AlertCircle size={18} className="text-danger shrink-0" />
              ) : (
                <span className="w-2 h-2 rounded-full bg-neutral-300 dark:bg-neutral-600 shrink-0 ml-2" />
              )}
              <div className="min-w-0">
                <div className="font-medium text-neutral-900 dark:text-neutral-50">
                  {verify?.ok
                    ? `Connected: ${verify.body.result?.name ?? 'zone'}`
                    : verify
                    ? 'Verification failed'
                    : 'Not verified yet'}
                </div>
                <div className="text-[12px] text-neutral-500 dark:text-neutral-400">
                  {verify?.ok
                    ? `Plan: ${verify.body.result?.plan?.name ?? 'unknown'}`
                    : cfEnabled
                    ? 'Fill in credentials below, then click Verify.'
                    : 'Enable the integration below to enter credentials.'}
                </div>
              </div>
            </div>
            <button
              type="button"
              onClick={() => run('verify', '/cloudflare/verify')}
              disabled={busy !== null || !cfEnabled}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-line-strong text-ink hover:bg-panel transition-colors disabled:opacity-50 disabled:cursor-not-allowed shrink-0"
            >
              <RefreshCw size={13} className={busy === 'verify' ? 'animate-spin' : ''} />
              Verify
            </button>
          </div>
          {visibleFields.map(([key, spec]) => (
            <SettingRow
              key={key}
              fieldKey={key}
              spec={spec}
              origin={module.setting_origins?.[key]}
              value={settings[key]}
              onChange={(v) => set(key, v)}
              first={false}
            />
          ))}
        </div>
      </div>

      {error && <Callout tone="danger" title="Cloudflare error" body={error} />}
      {notice && !error && <Callout tone="success" title="Done" body={notice} />}

      {/* ── Actions: only meaningful once the integration is on. Kept
          visible but disabled while disconnected so the capability is
          discoverable — with a hint instead of a silent dead button. ── */}
      <div>
        <div className="text-[11px] uppercase tracking-wider text-neutral-500 dark:text-neutral-400 font-medium mb-2">
          Actions
        </div>
        <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg overflow-hidden">
          <div className={`px-4 sm:px-5 py-4 flex items-start justify-between gap-3 sm:gap-4 ${cfEnabled ? '' : 'opacity-60'}`}>
            <div className="min-w-0 pr-2">
              <div className="font-medium text-neutral-900 dark:text-neutral-50">Purge Cloudflare edge</div>
              <div className="text-[12px] text-neutral-500 dark:text-neutral-400 mt-0.5">
                {cfEnabled
                  ? 'Clear everything Cloudflare has cached for your zone.'
                  : 'Connect your zone above to run purges.'}
              </div>
            </div>
            <div className="shrink-0">
              <button
                type="button"
                onClick={() => run('purge', '/cloudflare/purge')}
                disabled={busy !== null || !cfEnabled}
                className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-primary bg-primary text-on-primary hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
              >
                {busy === 'purge' ? <RefreshCw size={13} className="animate-spin" /> : <Trash2 size={13} />}
                Purge Cloudflare
              </button>
            </div>
          </div>
          <div className={`px-4 sm:px-5 py-4 border-t border-neutral-200 dark:border-neutral-800 flex items-start justify-between gap-3 sm:gap-4 ${cfEnabled ? '' : 'opacity-60'}`}>
            <div className="min-w-0 pr-2">
              <div className="font-medium text-neutral-900 dark:text-neutral-50">Development mode</div>
              <div className="text-[12px] text-neutral-500 dark:text-neutral-400 mt-0.5">
                {cfEnabled
                  ? "Temporarily bypasses Cloudflare's cache (~3 hours) so you see changes immediately."
                  : 'Connect your zone above to toggle dev mode.'}
              </div>
            </div>
            <div className="shrink-0 flex items-center gap-1.5">
              <button
                type="button"
                onClick={() => run('devon', '/cloudflare/dev-mode', 'POST', { on: true })}
                disabled={busy !== null || !cfEnabled}
                className="text-[12px] px-2.5 py-1 rounded border border-line-strong text-ink hover:bg-panel transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {busy === 'devon' ? <RefreshCw size={12} className="inline animate-spin mr-1" /> : null}
                Turn on
              </button>
              <button
                type="button"
                onClick={() => run('devoff', '/cloudflare/dev-mode', 'POST', { on: false })}
                disabled={busy !== null || !cfEnabled}
                className="text-[12px] px-2.5 py-1 rounded border border-line-strong text-ink hover:bg-panel transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {busy === 'devoff' ? <RefreshCw size={12} className="inline animate-spin mr-1" /> : null}
                Turn off
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* ── Purge behavior: the edge-sync switch. Always rendered — hiding
          it behind the enable gate is how users (and support) missed that
          the APO sync mechanism exists at all. ── */}
      {autoPurgeSpec && (
        <div>
          <div className="text-[11px] uppercase tracking-wider text-neutral-500 dark:text-neutral-400 font-medium mb-2">
            Purge behavior
          </div>
          <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg overflow-hidden">
            <div
              data-field-anchor="cloudflare:auto_purge_on_update"
              className={`xspeed-field px-4 sm:px-5 py-4 flex items-start justify-between gap-3 sm:gap-4 ${cfEnabled ? '' : 'opacity-60'}`}
            >
              <div className="min-w-0 pr-2">
                <div className="font-medium text-neutral-900 dark:text-neutral-50">
                  {autoPurgeSpec.label ?? 'Auto-purge Cloudflare on xSpeed purge'}
                </div>
                {module.setting_origins?.auto_purge_on_update?.source === 'managed' && (
                  <HeldBySetting by={module.setting_origins.auto_purge_on_update.managed_by ?? ''} />
                )}
                <div className="text-[12px] text-neutral-500 dark:text-neutral-400 mt-0.5">
                  {autoPurgeSpec.description}
                  {!cfEnabled && ' Enable the integration above to change this.'}
                </div>
              </div>
              <div className="shrink-0">
                <Toggle
                  checked={Boolean(settings.auto_purge_on_update ?? autoPurgeSpec.default)}
                  onChange={(v) => set('auto_purge_on_update', v)}
                  label={autoPurgeSpec.label ?? 'Auto-purge Cloudflare on xSpeed purge'}
                  disabled={!cfEnabled || module.setting_origins?.auto_purge_on_update?.source === 'managed'}
                />
              </div>
            </div>
          </div>
        </div>
      )}

      <SaveSpinner saving={saving} error={saveError} />

      {/* APO is a sub-setting of the connected zone, not a sibling — it
          cannot run without a Cloudflare zone (FBS-83633). It's embedded
          here as a section: the real APO panel on Pro, a §24.12 ProDemo
          on Free. No separate sidebar row. */}
      <div className="pt-2">
        <div className="text-[11px] uppercase tracking-wider text-neutral-500 dark:text-neutral-400 font-medium mb-2">
          Automatic Platform Optimization
        </div>
        <ProTabBody demo="network-cloudflare">
          <ProSlot panel="CloudflareApoPanel" slug="cloudflare-apo" getModule={getModule} onSaved={onSaved} />
        </ProTabBody>
      </div>
    </div>
  );
}

function labelFor(b: 'verify' | 'purge' | 'devon' | 'devoff' | null): string {
  switch (b) {
    case 'verify': return 'verify';
    case 'purge': return 'purge';
    case 'devon': return 'dev mode on';
    case 'devoff': return 'dev mode off';
    default: return 'action';
  }
}

function SettingRow({
  origin,
  fieldKey,
  spec,
  value,
  onChange,
  first,
}: {
  origin?: SettingOrigin;
  fieldKey: string;
  spec: SchemaField;
  value: unknown;
  onChange: (v: unknown) => void;
  first: boolean;
}) {
  const label = spec.label ?? fieldKey;
  const heldBy = origin?.source === 'managed' ? origin.managed_by : undefined;
  return (
    // Anchor so a notice/recommendation CTA can land on this control
    // (issue #49). Custom panels render no anchors by default, which made
    // the Cloudflare CTA scroll to nothing and give up silently.
    <div
      data-field-anchor={`cloudflare:${fieldKey}`}
      className={`xspeed-field px-4 sm:px-5 py-4 flex items-start justify-between gap-3 sm:gap-4 ${first ? '' : 'border-t border-neutral-200 dark:border-neutral-800'}`}
    >
      <div className="min-w-0 pr-2">
        <div className="font-medium text-neutral-900 dark:text-neutral-50">{label}</div>
          {heldBy && <HeldBySetting by={heldBy} />}
        {spec.description && (
          <div className="text-[12px] text-neutral-500 dark:text-neutral-400 mt-0.5">{spec.description}</div>
        )}
      </div>
      <fieldset disabled={!!heldBy} className="shrink-0 min-w-0">{renderControl(spec, value, onChange, label)}</fieldset>
    </div>
  );
}

function renderControl(
  spec: SchemaField,
  value: unknown,
  onChange: (v: unknown) => void,
  label: string
) {
  switch (spec.type) {
    case 'bool':
      return <Toggle checked={Boolean(value)} onChange={onChange} label={label} />;
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
                className={`text-[12px] px-2.5 py-1 rounded border transition-colors ${
                  active
                    ? 'bg-primary border-primary text-on-primary'
                    : 'border-line text-ink-soft hover:bg-panel'
                }`}
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
          className="w-64"
          autoComplete="off"
          aria-label={label}
        />
      );
    default:
      // Everything else — including `secret` (api_token / api_key since #115)
      // — renders through the shared renderer. This default used to return
      // null, which is how retyping the credentials to `secret` made their
      // inputs vanish from this panel (QA B1 round 2).
      return renderField(spec, value, onChange, label);
  }
}
