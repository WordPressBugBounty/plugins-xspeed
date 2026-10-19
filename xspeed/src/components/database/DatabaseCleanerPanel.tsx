import { useEffect, useMemo, useRef, useState } from 'react';
import { Database as DbIcon, RefreshCw, Trash2, Zap } from 'lucide-react';
import { Callout } from '../Callout';
import { InlineConfirm } from '../InlineConfirm';
import { Toggle } from '../Toggle';
import { SaveSpinner } from '../SaveIndicator';
import { ProTeaser } from '../../upsell/ProTeaser';
import { api } from '../../api/client';
import { reconcileSaved } from '../reconcileSaved';
import type { SchemaField } from '../../types';
import type { PanelProps } from '../customPanels';
import { renderField } from '../ModulePanel';
import { __, sprintf } from '../../i18n';

interface ScanRow {
  label: string;
  count: number;
}
interface ScanResponse {
  scan: Record<string, ScanRow>;
  labels: Record<string, string>;
}
interface CleanResponse {
  cleaned: Record<string, number>;
  /** Per requested type, what still matches after the run. */
  remaining?: Record<string, number>;
  /** True when the run hit its time limit before every type finished. */
  partial?: boolean;
  scan: Record<string, ScanRow>;
}

export function DatabaseCleanerPanel({ module, onSaved }: PanelProps) {
  const [settings, setSettings] = useState<Record<string, unknown>>(module.settings);
  const [scan, setScan] = useState<Record<string, ScanRow> | null>(null);
  const [labels, setLabels] = useState<Record<string, string>>({});
  const [selected, setSelected] = useState<Record<string, boolean>>({});
  const [busy, setBusy] = useState<null | 'scan' | 'clean' | 'optimize'>(null);
  const [error, setError] = useState<string | null>(null);
  // Scoped to the SAVE path only. `error` is shared with scan / flush /
  // start-stop actions, so passing it straight to SaveSpinner would paint a
  // later successful save red because an earlier unrelated action failed. (#5)
  const [saveError, setSaveError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmingClean, setConfirmingClean] = useState(false);
  const [saving, setSaving] = useState(false);
  const debounceRef = useRef<number | null>(null);
  const initial = useRef(true);
  const lastApplied = useRef<string>(JSON.stringify(module.settings));
  // Live mirror so the save response reconciles against current edits, not
  // the snapshot it was sent with (prevents mid-flight keystroke loss).
  const settingsRef = useRef(settings);
  useEffect(() => { settingsRef.current = settings; }, [settings]);

  const refreshScan = async () => {
    setBusy('scan');
    setError(null);
    try {
      const res = await api.request<ScanResponse>('/database/scan');
      setScan(res.scan);
      setLabels(res.labels);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Scan failed');
    } finally {
      setBusy(null);
    }
  };

  useEffect(() => {
    refreshScan();
  }, []);

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

  const selectedKeys = useMemo(
    () => Object.keys(selected).filter((k) => selected[k] && (scan?.[k]?.count ?? 0) > 0),
    [selected, scan]
  );
  const totalSelected = useMemo(
    () => selectedKeys.reduce((sum, k) => sum + (scan?.[k]?.count ?? 0), 0),
    [selectedKeys, scan]
  );

  const toggleSelect = (key: string) =>
    setSelected((s) => ({ ...s, [key]: !s[key] }));

  const selectAll = () => {
    if (!scan) return;
    const all: Record<string, boolean> = {};
    Object.entries(scan).forEach(([k, row]) => {
      if (row.count > 0) all[k] = true;
    });
    setSelected(all);
  };
  const clearAll = () => setSelected({});

  const handleClean = async () => {
    if (selectedKeys.length === 0) return;
    setConfirmingClean(false);
    setBusy('clean');
    setError(null);
    setNotice(null);
    try {
      const res = await api.request<CleanResponse>('/database/clean', {
        method: 'POST',
        body: JSON.stringify({ types: selectedKeys }),
      });
      setScan(res.scan);
      const removed = Object.values(res.cleaned).reduce((a, b) => a + b, 0);
      const remaining = res.remaining ?? {};
      const left = Object.values(remaining).reduce((a, b) => a + b, 0);
      const done = `Removed ${removed.toLocaleString()} row${removed === 1 ? '' : 's'}.`;
      if (res.partial && left > 0) {
        // Each clean is time-boxed on the server so a large trash can't
        // outlive the request. Keep what's left selected, so "Clean
        // selected" picks up where this run stopped.
        setNotice(`${done} ${sprintf(__('%s left — Clean again to continue.'), left.toLocaleString())}`);
        const next: Record<string, boolean> = {};
        Object.entries(remaining).forEach(([k, n]) => {
          if (n > 0) next[k] = true;
        });
        setSelected(next);
      } else {
        setNotice(done);
        setSelected({});
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Cleanup failed');
    } finally {
      setBusy(null);
    }
  };

  const handleOptimize = async () => {
    setBusy('optimize');
    setError(null);
    setNotice(null);
    try {
      const res = await api.request<{ optimized: Record<string, boolean> }>(
        '/database/optimize',
        { method: 'POST' }
      );
      const count = Object.keys(res.optimized).length;
      setNotice(`Optimized ${count} table${count === 1 ? '' : 's'}.`);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Optimize failed');
    } finally {
      setBusy(null);
    }
  };

  const fields = useMemo(() => Object.entries(module.schema), [module.schema]);
  const rows = scan ? Object.entries(scan) : [];
  const totalRows = rows.reduce((s, [, row]) => s + row.count, 0);

  return (
    <div className="space-y-4">
      <div className="border border-line bg-paper rounded-card p-4 sm:p-5">
        <div className="flex items-center justify-between gap-4 mb-4">
          <div className="flex items-center gap-3 min-w-0">
            <DbIcon size={18} className="text-primary shrink-0" />
            <div className="min-w-0">
              <div className="font-medium text-ink">
                Database bloat
              </div>
              <div className="text-[12px] text-muted">
                {scan
                  ? totalRows === 0
                    ? 'Nothing to clean — your database is tidy.'
                    : `${totalRows.toLocaleString()} row${totalRows === 1 ? '' : 's'} across ${rows.filter(([, r]) => r.count > 0).length} categor${rows.filter(([, r]) => r.count > 0).length === 1 ? 'y' : 'ies'}`
                  : 'Scanning…'}
              </div>
            </div>
          </div>
          <div className="flex items-center gap-2 shrink-0">
            <button
              type="button"
              onClick={refreshScan}
              disabled={busy !== null}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-line text-ink-soft hover:bg-panel transition-colors disabled:opacity-50"
            >
              <RefreshCw size={13} className={busy === 'scan' ? 'animate-spin' : ''} />
              Rescan
            </button>
          </div>
        </div>

        {rows.length > 0 && (
          <div className="border border-line rounded overflow-hidden">
            {rows.map(([key, row], i) => {
              const isSel = Boolean(selected[key]);
              const disabled = row.count === 0;
              return (
                <label
                  key={key}
                  className={`flex items-center justify-between gap-3 px-3 py-2.5 cursor-pointer ${i === 0 ? '' : 'border-t border-line'} ${disabled ? 'opacity-50 cursor-not-allowed' : 'hover:bg-panel/40'}`}
                >
                  <span className="flex items-center gap-2.5 min-w-0">
                    <input
                      type="checkbox"
                      checked={isSel}
                      disabled={disabled}
                      onChange={() => toggleSelect(key)}
                      className="accent-primary"
                    />
                    <span className="text-[13px] text-ink truncate">
                      {labels[key] ?? row.label}
                    </span>
                  </span>
                  <span className="text-[12px] tabular-nums text-muted shrink-0">
                    {row.count.toLocaleString()}
                  </span>
                </label>
              );
            })}
          </div>
        )}

        <div className="flex flex-wrap items-center justify-between gap-2 mt-3">
          <div className="flex items-center gap-2 text-[12px]">
            <button
              type="button"
              onClick={selectAll}
              className="text-primary hover:underline"
              disabled={!scan || totalRows === 0}
            >
              Select all
            </button>
            <span className="text-line-strong">·</span>
            <button
              type="button"
              onClick={clearAll}
              className="text-muted hover:underline"
              disabled={selectedKeys.length === 0}
            >
              Clear
            </button>
          </div>
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={handleOptimize}
              disabled={busy !== null}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-line text-ink-soft hover:bg-panel transition-colors disabled:opacity-50"
            >
              <Zap size={13} className={busy === 'optimize' ? 'animate-pulse' : ''} />
              Optimize tables
            </button>
            <button
              type="button"
              onClick={() => setConfirmingClean(true)}
              disabled={busy !== null || selectedKeys.length === 0}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-primary bg-primary text-on-primary hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
            >
              {busy === 'clean' ? <RefreshCw size={13} className="animate-spin" /> : <Trash2 size={13} />}
              Clean selected{totalSelected > 0 ? ` (${totalSelected.toLocaleString()})` : ''}
            </button>
          </div>
        </div>

        {/* Inline confirm for the destructive clean (design system uses
            InlineConfirm, never window.confirm). */}
        {confirmingClean && (
          <div className="mt-3">
            <InlineConfirm
              title={`Permanently delete ${totalSelected.toLocaleString()} row${totalSelected === 1 ? '' : 's'}?`}
              body={
                __("This removes the selected rows from your database. It can't be undone — take a backup first if you're unsure.") +
                // Trashed media goes the way core's Empty Trash takes it: the
                // files in uploads/ are deleted with the row. Say so here,
                // the one place a person decides.
                (selectedKeys.includes('trashed_posts')
                  ? ' ' + __('Trashed media files are deleted from your uploads folder too.')
                  : '')
              }
              confirmLabel="Delete rows"
              busy={busy === 'clean'}
              onConfirm={handleClean}
              onCancel={() => setConfirmingClean(false)}
            />
          </div>
        )}

        {/* Bolt-swept run bar while the cleanup executes. */}
        {busy === 'clean' && (
          <div className="mt-3 h-1.5 rounded-pill bg-panel overflow-hidden" role="progressbar" aria-label={__('Cleaning')}>
            <div
              className="h-full w-full rounded-pill xspeed-anim-sweep"
              style={{ backgroundImage: 'linear-gradient(90deg, var(--primary) 0%, #5BD6CE 50%, var(--primary) 100%)' }}
            />
          </div>
        )}
      </div>

      {error && <Callout tone="danger" title="Database error" body={error} />}
      {notice && !error && <Callout tone="success" title="Done" body={notice} />}

      {fields.length > 0 && (
        <div className="border border-line bg-paper rounded-card overflow-hidden">
          {fields.map(([key, spec], i) => (
            <SettingRow
              key={key}
              fieldKey={key}
              spec={spec}
              value={settings[key]}
              labels={labels}
              onChange={(v) => set(key, v)}
              first={i === 0}
            />
          ))}
        </div>
      )}

      <SaveSpinner saving={saving} error={saveError} />
      <ProTeaser id="migration" />
    </div>
  );
}

function SettingRow({
  fieldKey,
  spec,
  value,
  labels,
  onChange,
  first,
}: {
  fieldKey: string;
  spec: SchemaField;
  value: unknown;
  labels: Record<string, string>;
  onChange: (v: unknown) => void;
  first: boolean;
}) {
  const label = spec.label ?? fieldKey;
  const isTypeList = fieldKey === 'included_types';
  return (
    <div
      className={`px-4 sm:px-5 py-4 ${first ? '' : 'border-t border-line'}`}
    >
      <div className="flex items-start justify-between gap-3 sm:gap-4">
        <div className="min-w-0 pr-2">
          <div className="font-medium text-ink">{label}</div>
          {spec.description && (
            <div className="text-[12px] text-muted mt-0.5">
              {spec.description}
            </div>
          )}
        </div>
        {!isTypeList && (
          <div className="shrink-0">{renderControl(spec, value, onChange, label)}</div>
        )}
      </div>
      {isTypeList && Object.keys(labels).length > 0 && (
        <TypeListPicker
          value={Array.isArray(value) ? (value as string[]) : []}
          labels={labels}
          onChange={onChange}
        />
      )}
    </div>
  );
}

function TypeListPicker({
  value,
  labels,
  onChange,
}: {
  value: string[];
  labels: Record<string, string>;
  onChange: (v: unknown) => void;
}) {
  const toggle = (key: string) => {
    const next = value.includes(key) ? value.filter((v) => v !== key) : [...value, key];
    onChange(next);
  };
  return (
    <div className="flex flex-wrap gap-1.5 mt-3">
      {Object.entries(labels).map(([key, lbl]) => {
        const active = value.includes(key);
        return (
          <button
            key={key}
            type="button"
            onClick={() => toggle(key)}
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
    default:
      // Delegate anything this switch doesn't specialize — `secret` included —
      // to the shared renderer, so a new field type can never render as
      // nothing here (the QA B1 round-2 failure mode).
      return renderField(spec, value, onChange, label);
  }
}
