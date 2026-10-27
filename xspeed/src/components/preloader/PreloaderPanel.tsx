import { useResolvedSettings } from '../useResolvedSettings';
import { useEffect, useRef, useState } from 'react';
import { Play, Square as StopIcon, RefreshCw } from 'lucide-react';
import { Toggle } from '../Toggle';
import { Callout } from '../Callout';
import { SaveSpinner } from '../SaveIndicator';
import { api } from '../../api/client';
import { reconcileSaved } from '../reconcileSaved';
import { useVisibleFields } from '../fieldVisibility';
import type { SchemaField, PreloaderState } from '../../types';
import type { PanelProps } from '../customPanels';
import { renderField } from '../ModulePanel';
import { HeldBySetting } from '../DefinedInConfig';
import type { SettingOrigin } from '../../types';

const POLL_INTERVAL_MS = 4000;

export function PreloaderPanel({ module, onSaved }: PanelProps) {
  const [settings, setSettings] = useState<Record<string, unknown>>(module.settings);
  const [state, setState] = useState<PreloaderState | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Scoped to the SAVE path only. `error` is shared with scan / flush /
  // start-stop actions, so passing it straight to SaveSpinner would paint a
  // later successful save red because an earlier unrelated action failed. (#5)
  const [saveError, setSaveError] = useState<string | null>(null);
  const [actioning, setActioning] = useState<null | 'start' | 'stop'>(null);
  const debounceRef = useRef<number | null>(null);
  const initial = useRef(true);
  const lastApplied = useRef<string>(JSON.stringify(module.settings));
  useResolvedSettings(module, setSettings, lastApplied);
  // Live mirror so the save response reconciles against current edits, not
  // the snapshot it was sent with (prevents mid-flight keystroke loss).
  const settingsRef = useRef(settings);
  useEffect(() => { settingsRef.current = settings; }, [settings]);

  // Fetch status on mount + poll while a crawl is running.
  useEffect(() => {
    let cancelled = false;
    const fetchStatus = async () => {
      try {
        const s = await api.request<PreloaderState>('/preloader/status');
        if (!cancelled) setState(s);
      } catch {
        /* swallow — polling errors aren't shown */
      }
    };
    fetchStatus();
    const interval = window.setInterval(() => {
      if (state?.running) fetchStatus();
    }, POLL_INTERVAL_MS);
    return () => {
      cancelled = true;
      window.clearInterval(interval);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state?.running]);

  // Debounced settings save.
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

  // Run the schema through the shared `dependsOn` gate rather than iterating
  // it directly — a bare Object.entries() renders every sub-field even when
  // `enabled` is off (issue #199), the same trap the Cloudflare panel hit.
  const fields = useVisibleFields(module.schema, settings);

  const handleStart = async () => {
    setActioning('start');
    setError(null);
    try {
      const s = await api.request<PreloaderState>('/preloader/start', { method: 'POST' });
      setState(s);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to start');
    } finally {
      setActioning(null);
    }
  };
  const handleStop = async () => {
    setActioning('stop');
    try {
      const s = await api.request<PreloaderState>('/preloader/stop', { method: 'POST' });
      setState(s);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to stop');
    } finally {
      setActioning(null);
    }
  };

  const enabled = Boolean(settings.enabled);
  const percent = state && state.total > 0 ? Math.round((state.processed / state.total) * 100) : 0;

  return (
    <div className="space-y-4">
      <StatusCard
        state={state}
        enabled={enabled}
        percent={percent}
        actioning={actioning}
        onStart={handleStart}
        onStop={handleStop}
      />

      {error && (
        <Callout tone="danger" title="Preloader error" body={error} />
      )}

      <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg overflow-hidden">
        {fields.map(([key, spec], i) => (
          <SettingRow
            key={key}
            fieldKey={key}
            spec={spec}
            origin={module.setting_origins?.[key]}
            value={settings[key]}
            onChange={(v) => set(key, v)}
            first={i === 0}
            postTypes={state?.post_types ?? []}
          />
        ))}
      </div>

      <SaveSpinner saving={saving} error={saveError} />
    </div>
  );
}

function StatusCard({
  state,
  enabled,
  percent,
  actioning,
  onStart,
  onStop,
}: {
  state: PreloaderState | null;
  enabled: boolean;
  percent: number;
  actioning: null | 'start' | 'stop';
  onStart: () => void;
  onStop: () => void;
}) {
  const isRunning = Boolean(state?.running);
  const hasHistory = Boolean(state && (state.total > 0 || state.finished_at > 0));
  // A crawl that couldn't read the sitemap. Previously this rendered the
  // pre-crawl copy ("No crawl yet…") *after* the crawl had already run and
  // failed, so the user had every reason to think the cache was warming. (#142)
  const sitemapError = state?.sitemap_error ?? '';
  const usedFallback = state?.source === 'fallback';
  const crawlFailed = Boolean(sitemapError) && !usedFallback && state?.total === 0;
  // Said beside the total, so a crawl that left URLs out never reads as
  // "covered everything". (#239)
  const skipped = Object.values(state?.skipped ?? {}).reduce((a, n) => a + (Number(n) || 0), 0);

  return (
    <div className="border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg p-4 sm:p-5">
      <div className="flex items-center justify-between gap-4 mb-3">
        <div className="flex items-center gap-3 min-w-0">
          <span
            className={`w-2 h-2 rounded-full shrink-0 ${
              isRunning ? 'bg-accent animate-pulse' : enabled ? 'bg-emerald-500' : 'bg-neutral-300 dark:bg-neutral-600'
            }`}
          />
          <div className="min-w-0">
            <div className="font-medium text-neutral-900 dark:text-neutral-50">
              {isRunning ? 'Crawling…' : crawlFailed ? "Couldn't start" : enabled ? 'Ready' : 'Disabled'}
            </div>
            <div
              className={`text-[12px] ${
                crawlFailed
                  ? 'text-amber-700 dark:text-amber-400'
                  : 'text-neutral-500 dark:text-neutral-400'
              }`}
            >
              {state ? (
                crawlFailed
                  ? sitemapError
                  : hasHistory
                  ? `${state.processed} / ${state.total} URLs warmed${skipped > 0 ? ` · ${skipped} skipped` : ''}${state.devices?.includes('mobile') ? ' · desktop and phone copies' : ''}${state.errors.length > 0 ? ` · ${state.errors.length} error${state.errors.length === 1 ? '' : 's'}` : ''}`
                  : enabled
                  ? 'No crawl yet. Hit "Start now" to warm the cache.'
                  : 'Enable below, then start a crawl.'
              ) : (
                'Loading…'
              )}
            </div>
            {/*
              Shown while the crawl is RUNNING too, not just once it ends. A
              large site is hours of ticks, and gating this on !isRunning meant
              the user watched an unexplained crawl the whole way through and
              learned why only at the end. The state carries the reason from
              the first tick — only the tense changes. (QA F4 on #155)
            */}
            {usedFallback && (
              <div className="text-[12px] text-amber-700 dark:text-amber-400 mt-0.5">
                {isRunning
                  ? 'Warming from site content — the sitemap couldn’t be read.'
                  : 'Warmed from site content — the sitemap couldn’t be read.'}
              </div>
            )}
          </div>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          {isRunning ? (
            <button
              type="button"
              onClick={onStop}
              disabled={actioning !== null}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition-colors disabled:opacity-50"
            >
              <StopIcon size={13} />
              Stop
            </button>
          ) : (
            <button
              type="button"
              onClick={onStart}
              disabled={!enabled || actioning !== null}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-accent bg-accent text-white hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
            >
              {actioning === 'start' ? <RefreshCw size={13} className="animate-spin" /> : <Play size={13} />}
              Start now
            </button>
          )}
        </div>
      </div>

      {(isRunning || hasHistory) && state && state.total > 0 && (
        <div className="mt-3">
          <div className="h-1.5 w-full bg-neutral-100 dark:bg-neutral-800 rounded-full overflow-hidden">
            <div
              className="h-full bg-accent transition-all duration-300"
              style={{ width: `${percent}%` }}
            />
          </div>
          {state.last_url && (
            <div className="mt-2 text-[11px] text-neutral-500 dark:text-neutral-400 font-mono truncate">
              Last: {state.last_url}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function SettingRow({
  origin,
  fieldKey,
  spec,
  value,
  onChange,
  first,
  postTypes,
}: {
  origin?: SettingOrigin;
  fieldKey: string;
  spec: SchemaField;
  value: unknown;
  onChange: (v: unknown) => void;
  first: boolean;
  postTypes: Array<{ name: string; label: string }>;
}) {
  const label = spec.label ?? fieldKey;
  const heldBy = origin?.source === 'managed' ? origin.managed_by : undefined;
  if (fieldKey === 'excluded_post_types') {
    return (
      <PostTypeExclusions
        label={label}
        description={spec.description}
        value={Array.isArray(value) ? value.map(String) : []}
        onChange={onChange}
        postTypes={postTypes}
        heldBy={heldBy}
      />
    );
  }
  return (
    <div
      className={`px-4 sm:px-5 py-4 flex items-center justify-between gap-3 sm:gap-4 ${
        first ? '' : 'border-t border-neutral-200 dark:border-neutral-800'
      }`}
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

/**
 * The post types a crawl leaves out, as a checkbox per public post type.
 * A stored type that is no longer registered stays listed, so it can be
 * cleared. (#239)
 */
function PostTypeExclusions({
  label,
  description,
  value,
  onChange,
  postTypes,
  heldBy,
}: {
  label: string;
  description?: string;
  value: string[];
  onChange: (v: unknown) => void;
  postTypes: Array<{ name: string; label: string }>;
  heldBy?: string | null;
}) {
  const known = new Set(postTypes.map((t) => t.name));
  const options = [
    ...postTypes,
    ...value.filter((v) => !known.has(v)).map((v) => ({ name: v, label: v })),
  ];
  const toggle = (name: string, on: boolean) =>
    onChange(on ? [...value.filter((v) => v !== name), name] : value.filter((v) => v !== name));

  return (
    <div className="px-4 sm:px-5 py-4 border-t border-neutral-200 dark:border-neutral-800">
      <div className="font-medium text-neutral-900 dark:text-neutral-50">{label}</div>
      {heldBy && <HeldBySetting by={heldBy} />}
      {description && (
        <div className="text-[12px] text-neutral-500 dark:text-neutral-400 mt-0.5">{description}</div>
      )}
      <fieldset disabled={!!heldBy} className="min-w-0">
        {options.length === 0 ? (
          <div className="mt-2 text-[12px] text-neutral-500 dark:text-neutral-400">Loading post types…</div>
        ) : (
          <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1.5" role="group" aria-label={label}>
            {options.map((t) => (
              <label key={t.name} className="inline-flex items-center gap-1.5 text-[13px] text-neutral-700 dark:text-neutral-300">
                <input
                  type="checkbox"
                  checked={value.includes(t.name)}
                  onChange={(e) => toggle(t.name, e.target.checked)}
                />
                {t.label}
                <span className="text-[11px] text-neutral-500 dark:text-neutral-400 font-mono">{t.name}</span>
              </label>
            ))}
          </div>
        )}
      </fieldset>
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
    case 'int':
      return (
        <input
          type="number"
          min={spec.min}
          max={spec.max}
          value={value === undefined || value === null ? '' : Number(value)}
          onChange={(e) => onChange(Number(e.target.value))}
          className="w-20 text-right"
          aria-label={label}
        />
      );
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
                    ? 'bg-accent border-accent text-white'
                    : 'border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800'
                }`}
              >
                {lbl}
              </button>
            );
          })}
        </div>
      );
    case 'string':
    case 'url':
      return (
        <input
          type={spec.type === 'url' ? 'url' : 'text'}
          value={value === undefined || value === null ? '' : String(value)}
          onChange={(e) => onChange(e.target.value)}
          className="w-56"
          aria-label={label}
        />
      );
    default:
      // Delegate anything this switch doesn't specialize — `secret` included —
      // to the shared renderer, so a new field type can never render as
      // nothing here (the QA B1 round-2 failure mode).
      return renderField(spec, value, onChange, label);
  }
}
