import { useEffect, useRef, useState, type ReactNode } from 'react';
import { RotateCcw } from 'lucide-react';
import { Select } from './Select';
import { Toggle } from './Toggle';
import { Callout } from './Callout';
import { MediaField } from './MediaField';
import { InfoPopover } from './InfoPopover';
import { DefinedInConfig } from './DefinedInConfig';
import { OverrideConfirm } from './OverrideConfirm';
import { customPanels, type PanelProps } from './customPanels';
import { getProPanel, usePanelsVersion } from './proPanels';
import { MissingPanel } from './MissingPanel';
import { SaveIndicator, FAILED_HOLD_MS, SAVED_HOLD_MS, type SaveState } from './SaveIndicator';
import { LockedPanel } from '../upsell/LockedPanel';
import { api } from '../api/client';
import { useVisibleFields } from './fieldVisibility';
import { reconcileSaved } from './reconcileSaved';
import { FieldSuggestionStrip, useFieldSuggestion } from './FieldSuggestionStrip';
import { SecretField } from './SecretField';
import type { ModuleDescriptor, SchemaField, SettingOrigin } from '../types';
import { durationHint } from '../duration';
import { __ } from '../i18n';

/**
 * Schema-driven settings panel for any registered Module.
 *
 * The component is generic: it walks the module's schema, renders the
 * matching primitive per field type, and persists changes through the
 * per-module REST endpoint with 600ms debounce (same UX as the legacy
 * SettingsSection — single shared autosave pattern keeps the design
 * system coherent as more modules ship).
 *
 * Adding a new field type = adding a `case` to renderField + a coercer
 * in `coerceFromInput`. Don't add module-specific branches here; that's
 * what the `component: 'Custom'` panel slot is for (future work).
 */

/**
 * The module's dynamic callouts (ui_notices). Rendered for EVERY module —
 * schema-driven and custom-panel alike — so a module that declares a
 * custom_panel (Cloudflare, Object Cache, …) doesn't silently drop its notices.
 * A custom panel that already renders its own notices should not also receive
 * this; today none do. (QA B4 — the #119 "purge failing" warning was generated
 * but never displayed because CloudflarePanel is a custom panel.)
 */
export function ModuleNotices({ notices }: { notices?: ModuleDescriptor['notices'] }) {
  if (!notices || notices.length === 0) return null;
  return (
    <>
      {notices.map((n, i) => (
        <Callout key={i} tone={n.tone} title={n.title} body={n.body} snippet={n.snippet} action={n.action} />
      ))}
    </>
  );
}

export function ModulePanel({ module, onSaved, subtab, onSubtabChange, getModule }: PanelProps) {
  // Subscribe to Pro panel registration events so a late-arriving Pro
  // panel triggers a re-resolution. The hook is unconditional —
  // ModulePanel must keep its hook order stable across module slugs.
  usePanelsVersion();

  // Locked Pro modules (injected from the manifest when Pro isn't
  // installed) bypass schema + custom-panel routing entirely and
  // render the upgrade card instead.
  if (module.locked) {
    return <LockedPanel module={module} />;
  }

  // Custom panel escape hatch (SETTINGS.md §6.2). Check Free built-ins
  // first, then the runtime Pro registry. A module that declares a custom
  // panel never gets the schema renderer: see MissingPanel. `subtab`/`onSubtabChange`
  // bind a host page's active tab to the compound URL hash; `getModule`
  // lets a host embed a sibling Pro sub-panel (FBS-83633). Flat panels
  // ignore all three.
  if (module.custom_panel) {
    const shared = { onSaved, subtab, onSubtabChange, getModule };
    const Panel = customPanels[module.custom_panel] ?? getProPanel(module.custom_panel);
    if (Panel) {
      // Notices are hoisted above the custom panel so every module surfaces
      // them (QA B4). Empty notices render nothing, so this is a no-op for the
      // many custom panels that don't declare any.
      return (
        <div className="space-y-4">
          <ModuleNotices notices={module.notices} />
          <BlockedPanel module={module}>
            <Panel module={module} {...shared} />
          </BlockedPanel>
        </div>
      );
    }
    // Declared but not registered. Never fall back to the schema fields:
    // they look like a deliberate screen (report 5.3). Until the page has
    // loaded this is Pro's script not having run yet; after, it is an error.
    return (
      <div className="space-y-4">
        <ModuleNotices notices={module.notices} />
        <MissingPanel />
      </div>
    );
  }
  return (
    <BlockedPanel module={module}>
      <SchemaModulePanel module={module} onSaved={onSaved} />
    </BlockedPanel>
  );
}

/**
 * A module that something else on the site has taken over from.
 *
 * It stands down on the server whatever its own switch says (see
 * Module::blocked_by()), so its controls are shown for reference and cannot
 * be used: a switch that looks live and does nothing is worse than one that
 * is plainly off. The reason comes from whoever blocked it and says what to do.
 */
export function BlockedPanel({ module, children }: { module: ModuleDescriptor; children: ReactNode }) {
  if (!module.blocked_by) return <>{children}</>;
  return (
    <div className="space-y-4">
      <Callout tone="warn" title={__('Not in use on this site')} body={module.blocked_by} />
      <fieldset disabled aria-disabled="true" className="min-w-0 opacity-60">
        {children}
      </fieldset>
    </div>
  );
}

/**
 * `hoistedNotices` — set by a custom panel that is rendered THROUGH
 * ModulePanel, which already printed the module's notices above it. Without
 * it a host panel that renders SchemaModulePanel for one of its tabs (CssJs,
 * Compression, Cloudflare) shows every notice twice: once hoisted, once
 * inside the tab. Latent until a module with a custom_panel first declared
 * notices — MinifyModule in #2.
 */
export function SchemaModulePanel({
  module,
  onSaved,
  hoistedNotices = false,
}: {
  module: ModuleDescriptor;
  onSaved?: (saved: Record<string, unknown>) => void;
  hoistedNotices?: boolean;
}) {
  const [values, setValues] = useState<Record<string, unknown>>(module.settings);
  const [state, setState] = useState<SaveState>('idle');
  const [error, setError] = useState<string | null>(null);

  const initial = useRef(true);
  const debounceRef = useRef<number | null>(null);
  const savedTimerRef = useRef<number | null>(null);
  const lastApplied = useRef<string>(JSON.stringify(module.settings));
  // Mirror of `state` so the async save callback below can check
  // the current value without closing over a stale one (deps don't
  // include state).
  const stateRef = useRef<SaveState>('idle');
  useEffect(() => { stateRef.current = state; }, [state]);
  // Mirror of `values` so the async save callback can read the LIVE edit
  // state when the response lands — not the snapshot it closed over. This is
  // what lets reconcileSaved keep keystrokes made while the save was in flight.
  const valuesRef = useRef(values);
  useEffect(() => { valuesRef.current = values; }, [values]);

  // Reset when navigating between modules.
  useEffect(() => {
    setValues(module.settings);
    lastApplied.current = JSON.stringify(module.settings);
    initial.current = true;
    setState('idle');
    setError(null);
  }, [module.slug]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (initial.current) {
      initial.current = false;
      return;
    }
    const serialized = JSON.stringify(values);
    if (serialized === lastApplied.current) return;

    if (debounceRef.current) window.clearTimeout(debounceRef.current);
    debounceRef.current = window.setTimeout(async () => {
      // Skip the spinner flicker when we're still inside the 1.5s
      // 'saved' grace window from a previous save. The new save still
      // runs; it just doesn't visually revert the toast to 'saving'.
      if (stateRef.current !== 'saved') {
        setState('saving');
      }
      setError(null);
      const sent = values;
      try {
        const saved = await api.updateModuleSettings(module.slug, sent);
        // Reconcile against the LIVE state, not `sent`: if the user edited a
        // field while this save was in flight, keep their newer value instead
        // of letting the stale server echo reset it. `dirty` means a follow-up
        // save is needed so that mid-flight edit actually persists.
        const { next, dirty } = reconcileSaved(sent, saved as Record<string, unknown>, valuesRef.current);
        setValues(next);
        lastApplied.current = dirty ? '' : JSON.stringify(next);
        setState('saved');
        if (savedTimerRef.current) window.clearTimeout(savedTimerRef.current);
        savedTimerRef.current = window.setTimeout(() => setState('idle'), SAVED_HOLD_MS);
        // Notify App with the saved values so (a) the shared module
        // snapshot stays current — navigating away and back no longer
        // re-seeds this panel from stale bootstrap data — and (b)
        // dashboard-level status (the consolidated nginx server-block
        // snippet + rewrite probe) refreshes without a full page reload.
        // A module toggle can add/remove its section from the snippet,
        // so the Cache panel must regenerate it. (Cards 82070 + reload UX.)
        onSaved?.(saved);
      } catch (e) {
        setError(e instanceof Error ? e.message : 'Save failed');
        /*
         * 'failed', not 'idle'.
         *
         * SaveIndicator has a red, assertively-announced failure branch, but
         * this panel dropped straight back to idle — so on every schema-driven
         * panel a rejected save showed nothing at all. Silence reads as
         * success: the toggle stays flipped, no toast appears, and the user
         * navigates away believing the change was stored. Confirmed live by
         * forcing a 500 on the REST route. (#5 / #16, QA on #172 and #178)
         *
         * The red toast holds for FAILED_HOLD_MS — the same constant
         * SaveSpinner uses, because "a failure asks the user to act" is not a
         * property of which panel you happen to be on. This path hard-coded
         * 1500 and so gave most of the dashboard a 3.3x shorter hold than the
         * custom panels. (QA F4) The inline error stays until the next save.
         */
        setState('failed');
        if (savedTimerRef.current) window.clearTimeout(savedTimerRef.current);
        savedTimerRef.current = window.setTimeout(() => setState('idle'), FAILED_HOLD_MS);
      }
    }, 600);

    return () => {
      if (debounceRef.current) window.clearTimeout(debounceRef.current);
    };
  }, [values, module.slug]);

  // Only render fields whose `dependsOn` condition is satisfied by the
  // CURRENT values (shared gate — see fieldVisibility.ts). A field with no
  // dependency always shows. A hidden field's stored value is left untouched
  // (we never clear it), so toggling the parent back on restores the child
  // exactly as it was — and a stale value is never written just because the
  // parent is off. (FBS-82227)
  const fields = useVisibleFields(module.schema, values);
  const set = (key: string, value: unknown) =>
    setValues((v) => ({ ...v, [key]: value }));

  // Provenance arrives in the descriptor, but overriding changes it, so it
  // lives in state: the row has to stop reading "Defined in wp-config.php"
  // the moment the field is handed over, without a page reload. (#398)
  const [origins, setOrigins] = useState(module.setting_origins ?? {});
  const [overrideBusy, setOverrideBusy] = useState<string | null>(null);
  useEffect(() => {
    setOrigins(module.setting_origins ?? {});
  }, [module.setting_origins]);

  const setOverride = async (key: string, on: boolean) => {
    setOverrideBusy(key);
    try {
      const res = await api.request<{
        settings: Record<string, unknown>;
        origins: Record<string, SettingOrigin>;
      }>(`/${module.slug}/override`, {
        method: 'POST',
        body: JSON.stringify({ field: key, override: on }),
      });
      setOrigins(res.origins ?? {});
      // The effective value changes with the source — reverting restores the
      // constant's value — so take the server's settings rather than leaving
      // the box showing what it showed a moment ago. Mark it applied first:
      // these came FROM the server, and letting the autosave effect read them
      // as a local edit would post them back at a field the server has just
      // re-pinned, which it then refuses. (#398)
      if (res.settings) {
        setValues((v) => {
          // Take ONLY the field that moved. Merging the server's whole
          // settings bag and marking it applied discards a save the user has
          // already typed but not yet flushed: the autosave effect re-runs,
          // its cleanup clears the armed debounce, and the applied-guard then
          // returns early — the edit stays on screen and is never posted.
          // Silence reading as success is the failure this panel is most
          // careful about. (#398)
          const moved = res.settings[key];
          if (moved === undefined || Object.is(moved, v[key])) return v;
          const next = { ...v, [key]: moved };
          // Neutralise only when nothing else is pending, so an in-flight edit
          // still reaches the server on its own debounce.
          if (JSON.stringify(v) === lastApplied.current) {
            lastApplied.current = JSON.stringify(next);
          }
          return next;
        });
      }
      setError(null);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setOverrideBusy(null);
    }
  };

  // No cross-sell teaser at the bottom of a Free panel: after the
  // FBS-83633 restructure every Pro feature lives in its OWN page as a
  // §24.12 demonstration (Critical CSS on CSS & JavaScript, WebP/AVIF on
  // Media, …). Appending e.g. a Critical CSS teaser to the Page Cache
  // panel put an upsell on the wrong page and duplicated the demo that
  // already exists where the feature belongs.

  // SaveIndicator portals out to #xspeed-toast-host (in App), so it's
  // never a layout sibling here. Panel content can be a single
  // space-y-4 wrapper as if the indicator didn't exist.
  return (
    <div className="space-y-4">
      <SaveIndicator state={state} />
      {!hoistedNotices && <ModuleNotices notices={module.notices} />}
      <div className="border border-line bg-paper rounded-card overflow-hidden">
        {fields.map(([key, spec], i) => (
          <SchemaRow
            key={key}
            moduleSlug={module.slug}
            fieldKey={key}
            spec={spec}
            value={values[key]}
            onChange={(v) => set(key, v)}
            origin={origins[key]}
            overrideBusy={overrideBusy === key}
            onSetOverride={(on) => setOverride(key, on)}
            first={i === 0}
          />
        ))}
      </div>
      {error && (
        <div className="text-[12px] text-danger">{error}</div>
      )}
    </div>
  );
}

function SchemaRow({
  moduleSlug,
  fieldKey,
  spec,
  value,
  onChange,
  origin,
  overrideBusy,
  onSetOverride,
  first,
}: {
  moduleSlug: string;
  fieldKey: string;
  spec: SchemaField;
  value: unknown;
  onChange: (v: unknown) => void;
  origin?: SettingOrigin;
  overrideBusy?: boolean;
  onSetOverride?: (on: boolean) => void;
  first: boolean;
}) {
  const label = spec.label ?? humanize(fieldKey);
  // A constant pins this value: the resolver reads wp-config.php whatever the
  // option row says, so an editable control here would offer to change a value
  // the site would go on ignoring. Show the effective value, read-only, and
  // name the constant. DESIGN.md §24.35.
  const pinnedTo = origin?.source === 'constant' ? origin.constant : null;
  // Deliberately taken back from wp-config: editable again, but still say what
  // it is shadowing and offer the way back. DESIGN.md §24.36.
  const overriding = origin?.overriding ?? null;
  const [confirming, setConfirming] = useState(false);
  // Anchor for scroll-to-field on search-result click (see App.tsx). The
  // `xspeed-field` class carries scroll-margin + the blink target.
  const anchor = `${moduleSlug}:${fieldKey}`;
  const suggestion = useFieldSuggestion(moduleSlug, fieldKey, spec, value, onChange);
  const description = spec.description;
  const rangeHint =
    spec.type === 'int' && (spec.min !== undefined || spec.max !== undefined)
      ? `Range: ${spec.min ?? '-∞'} – ${spec.max ?? '∞'}`
      : null;

  // Highlighting in panel content is handled by the SearchHighlighter
  // wrapper in App.tsx — it walks the rendered DOM once + applies
  // <mark> across all panels (Free + Pro custom + schema). Per-row
  // highlight() calls used to live here; removed to avoid double-wrap.

  // List fields need a full-width textarea below the label; everything
  // else is a tidy label-left / control-right row.
  const isStacked = spec.type === 'list';

  const border = first ? '' : 'border-t border-line';

  if (isStacked) {
    return (
      <div data-field-anchor={anchor} className={`xspeed-field px-4 sm:px-5 py-4 ${border}`}>
        <div className="mb-1.5">
          <div className="font-medium text-ink inline-flex items-center gap-1.5">
            <span>{label}</span>
            {spec.info && <InfoPopover title={spec.info_title} body={spec.info} />}
          </div>
          <FieldHint spec={spec} />
        </div>
        {renderField(spec, value, onChange, label, !!pinnedTo)}
        {(pinnedTo || overriding) && (
          <DefinedInConfig
            constant={(pinnedTo ?? overriding) as string}
            overridden={!pinnedTo}
            busy={overrideBusy}
            onOverride={onSetOverride ? () => setConfirming(true) : undefined}
            onRevert={onSetOverride ? () => onSetOverride(false) : undefined}
          />
        )}
        {confirming && pinnedTo && (
          <div className="mt-2">
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
        {!pinnedTo && suggestion && <FieldSuggestionStrip strip={suggestion} />}
      </div>
    );
  }

  return (
    <div
      data-field-anchor={anchor}
      className={`xspeed-field px-4 sm:px-5 py-4 ${border}`}
    >
      <div className="flex items-center justify-between gap-3 sm:gap-4">
      <div className="min-w-0 max-w-[70%] pr-2">
        <div className="font-medium text-ink inline-flex items-center gap-1.5">
          <span>{label}</span>
          {spec.info && <InfoPopover title={spec.info_title} body={spec.info} />}
        </div>
        <FieldHint spec={spec} />
        {!description && rangeHint && (
          <div className="text-[12px] text-ink-soft mt-0.5">
            {rangeHint}
          </div>
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
      </div>
        <div className="shrink-0">{renderField(spec, value, onChange, label, !!pinnedTo)}</div>
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
      {/* Full-width beneath the label/control row — a recommendation needs
          room for its value and two buttons, which the right-hand control
          column doesn't have. */}
      {!pinnedTo && suggestion && <FieldSuggestionStrip strip={suggestion} />}
    </div>
  );
}

/**
 * The muted 12px hint under a field label: `description`, optionally
 * followed by a docs link.
 *
 * The link is its own schema key (`doc_url`) rather than markup inside
 * `description` because descriptions render as text — an <a> in the PHP
 * schema would be shown escaped, not followed (#111). Keeping the URL out
 * of the sentence also keeps the sentence translatable on its own.
 */
export function FieldHint({ spec }: { spec: SchemaField }) {
  if (!spec.description && !spec.doc_url) return null;
  return (
    <div className="text-[12px] text-ink-soft mt-0.5">
      {spec.description}
      {spec.doc_url && (
        <>
          {spec.description ? ' ' : ''}
          <a
            href={spec.doc_url}
            target="_blank"
            rel="noopener noreferrer"
            className="text-accent hover:underline"
          >
            Check the documentation
          </a>
        </>
      )}
    </div>
  );
}

/**
 * "One pattern per line" textarea.
 *
 * Kept as its own component because it needs local state. The previous
 * implementation derived `value` from the stored array on every render
 * (`array.join('\n')`) while `onChange` normalized the typed text with
 * `.split('\n').map(trim).filter(Boolean)` — so the blank line Enter creates
 * was deleted by `filter(Boolean)` in the same keystroke, before React could
 * render it back. The caret could never reach a blank line, and pressing
 * Return appeared to do nothing. Pasting worked, because every line arrives
 * non-empty at once; only typing was broken. (#94)
 *
 * So the raw text lives here while the field has focus, and normalization —
 * trim, drop blanks, dedupe — happens on blur. Same shape as Pro's
 * PrewarmSchedulePanel textarea, which never had the bug.
 *
 * Reach: this is driven by `renderField()`, so a list field inherits the
 * default-reference box and Reset only on schema-driven panels. A module
 * declaring `custom_panel` renders its own controls and bypasses this —
 * `database.included_types` does exactly that, on purpose: it is a closed
 * set of eight categories shown as checkboxes, which is better UX than a
 * free-text box for a fixed vocabulary. If you add a list field to a
 * custom panel and want this treatment, route it through `renderField()`.
 */
export function ListField({
  value,
  label,
  onChange,
  defaultValue,
}: {
  value: unknown;
  label: string;
  onChange: (v: string[]) => void;
  /**
   * The schema default, shown verbatim in a read-only box beside the input
   * and restored by Reset. A reset button alone leaves the user blind —
   * they can restore the defaults but can't see what they'd be restoring,
   * or how far their list has drifted from stock. (#131)
   */
  defaultValue?: unknown;
}) {
  const stored = Array.isArray(value) ? value.map(String) : [];
  const [draft, setDraft] = useState<string | null>(null);

  const defaults = Array.isArray(defaultValue) ? defaultValue.map(String) : [];
  const defaultText = defaults.join('\n');
  // Compare against what is COMMITTED, not the draft: mid-typing the two
  // can transiently match and the button would flicker.
  const isDefault =
    stored.length === defaults.length && stored.every((v, i) => v === defaults[i]);

  // While typing, show the draft verbatim (blank lines and all). Otherwise
  // mirror the stored array, so an external change (reset, save reconcile,
  // another tab) still lands in the field.
  const text = draft ?? stored.join('\n');

  // True while an IME candidate window is open (see onCompositionStart).
  const composing = useRef(false);

  /**
   * Push the in-progress lines upstream verbatim, so an unmount without a
   * blur still persists what is on screen. Blanks and duplicates are kept —
   * they round-trip through commit()'s normalization on blur, and filtering
   * them here is what silently dropped lines mid-typing.
   */
  const pushRaw = (raw: string) => {
    const arr = raw.split('\n').map((s) => s.trim());
    if (arr.length !== stored.length || arr.some((v, i) => v !== stored[i])) {
      onChange(arr);
    }
  };

  const commit = (raw: string) => {
    const arr = raw
      .split('\n')
      .map((s) => s.trim())
      .filter(Boolean);
    // Dedup — same behavior the v1 sanitize_urls had.
    const next = Array.from(new Set(arr));
    setDraft(null);
    // Only push when something actually changed, so a focus/blur with no
    // edit doesn't mark the panel dirty.
    if (next.length !== stored.length || next.some((v, i) => v !== stored[i])) {
      onChange(next);
    }
  };

  const editor = (
    <textarea
      value={text}
      rows={8}
      onCompositionStart={() => {
        composing.current = true;
      }}
      onCompositionEnd={(e) => {
        composing.current = false;
        pushRaw(e.currentTarget.value);
      }}
      onChange={(e) => {
        const raw = e.target.value;
        setDraft(raw);
        // Autosave is driven by `values` changing (600ms debounce), so a
        // blur-only commit would lose an edit if the user typed and then
        // navigated away without leaving the field.
        //
        // Push the RAW split lines — no dedupe, no blank filter. Normalizing
        // here dropped data twice over: typing `foo` under an existing `foo`
        // collapsed to one line at the moment the prefixes matched (pause
        // there and that is what persists), and deleting back to a trailing
        // blank line suppressed the push entirely, leaving the truncated
        // previous value stored. The intermediate value is transient anyway
        // — commit() normalizes on blur — so deduping it bought nothing.
        //
        // IME composition (Japanese/Chinese) fires onChange per candidate;
        // those intermediates are not real edits, so we hold off until
        // compositionend rather than starting a save per keystroke.
        if (!composing.current) {
          pushRaw(raw);
        }
      }}
      onBlur={(e) => commit(e.target.value)}
      className="w-full rounded-control border border-line bg-paper px-3 py-2 font-mono text-[12px] text-ink focus:outline-none focus:ring-2 focus:ring-primary"
      aria-label={label}
    />
  );

  // No `default` declared in the schema at all (as opposed to an explicitly
  // empty one) — keep the plain full-width editor rather than inventing a
  // reference box with nothing to reference.
  if (defaultValue === undefined) return editor;

  const resetId = `reset-${label.replace(/\W+/g, '-').toLowerCase()}`;

  return (
    <div className="space-y-1.5">
      {/* Side by side on desktop, stacked on narrow viewports so neither box
          gets squeezed to uselessness. */}
      <div className="grid gap-3 md:grid-cols-2">
        <div>
          {/* Both columns carry a header row of the same height, so the two
              boxes line up on the same baseline. Reset sits with the editable
              list it acts on, not with the read-only reference. */}
          <div className="mb-1 flex min-h-[22px] items-center justify-between gap-2">
            <span className="text-[11.5px] font-medium text-muted">Current value:</span>
            <button
              type="button"
              id={resetId}
              onClick={() => {
                setDraft(null);
                onChange([...defaults]);
              }}
              disabled={isDefault}
              className="inline-flex items-center gap-1 rounded-control border border-line px-2 py-0.5 text-[11.5px] text-ink-soft transition-colors hover:bg-panel disabled:cursor-default disabled:opacity-40"
              title={
                isDefault
                  ? 'This field already matches its default'
                  : 'Replace this list with the default'
              }
            >
              <RotateCcw size={11} aria-hidden="true" />
              Reset
            </button>
          </div>
          {editor}
        </div>
        <div>
          <div className="mb-1 flex min-h-[22px] items-center gap-2">
            <span className="text-[11.5px] font-medium text-muted">Default value:</span>
          </div>
          {defaults.length > 0 ? (
            <textarea
              value={defaultText}
              rows={8}
              readOnly
              tabIndex={-1}
              aria-label={`${label} — default value`}
              // Muted ground so it reads as reference material, not a second
              // input the user is expected to fill in.
              className="w-full cursor-default resize-none rounded-control border border-line bg-panel px-3 py-2 font-mono text-[12px] text-muted focus:outline-none"
            />
          ) : (
            // Still rendered when the default is empty: the layout stays put
            // field to field, and the blank reads as deliberate rather than
            // as something that failed to load.
            //
            // Height comes from a real `rows={8}` textarea underneath, so it
            // matches the editor exactly instead of being approximated. A
            // fixed height missed by ~7px (the font's line-height is not the
            // 1.5 the arithmetic assumed), and the earlier `h-full` +
            // min-height overflowed the section outright: inside a stretched
            // grid row `h-full` resolves to the row box, which the min-height
            // then pushed past the editor.
            <div className="relative">
              <textarea
                rows={8}
                readOnly
                tabIndex={-1}
                aria-hidden="true"
                className="w-full resize-none rounded-control border border-dashed border-line bg-panel px-3 py-2 font-mono text-[12px] focus:outline-none"
              />
              <span className="pointer-events-none absolute inset-0 flex items-center justify-center px-3 text-center text-[11.5px] text-faint">
                No defaults — empty by design.
              </span>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

/**
 * The shared schema-field control renderer. Exported so custom panels that
 * carry their own type switch (Cloudflare, Object Cache, Database, Preloader)
 * can DELEGATE their `default:` case here instead of returning null — that
 * `return null` is how the `secret` type shipped invisible in every own-switch
 * panel while the shared renderer worked (QA B1, round 2). A type this switch
 * doesn't know yet renders nothing here too, but now that's ONE place to fix.
 */
export function renderField(
  spec: SchemaField,
  value: unknown,
  onChange: (v: unknown) => void,
  label: string,
  /**
   * Render the control read-only. Set when a wp-config.php constant pins the
   * value (DESIGN.md §24.35): the field still shows what the site is actually
   * using, but cannot offer to change it, because the resolver would keep
   * reading the constant. Defaults false, so every existing caller is
   * unaffected.
   */
  readOnly = false
) {
  switch (spec.type) {
    case 'bool':
      return (
        <Toggle
          checked={Boolean(value)}
          onChange={(v) => onChange(v)}
          label={label}
          disabled={readOnly}
        />
      );
    case 'int': {
      // A duration field carries its unit in the schema, so the bare number
      // box gets a live translation beside it — "1800" → "= 30 minutes",
      // updating as the user types. Driven by spec.unit rather than the
      // label, which is translated. (#159)
      const hint = spec.unit ? durationHint(Number(value), spec.unit) : '';
      return (
        <div className="flex flex-col items-end">
          <input
            type="number"
            value={value === undefined || value === null ? '' : Number(value)}
            min={spec.min}
            max={spec.max}
            onChange={(e) => onChange(Number(e.target.value))}
            disabled={readOnly}
            className={`w-24 shrink-0 text-right rounded-control border border-line bg-paper px-2.5 py-1.5 text-[13px] text-ink focus:outline-none focus:ring-2 focus:ring-primary ${
              readOnly ? 'opacity-60 cursor-not-allowed' : ''
            }`}
            aria-label={label}
          />
          {spec.unit && (
            // BELOW the input, right-aligned under it. Not to the left: the
            // settings row is `justify-between`, so a hint on that side
            // detaches from the control and reads as a stray fragment mid-row.
            // Not inline-right either — the control column is already flush
            // right, so a growing hint would push the input leftward and the
            // row would shift on every keystroke. (QA F1)
            //
            // The span is always rendered when the field HAS a unit, empty or
            // not: reserving the line stops the row growing the first time a
            // hint appears, and a live region must exist before its content
            // changes to be announced at all.
            <span
              aria-live="polite"
              className="mt-1 block h-4 text-right text-[11.5px] leading-4 text-muted"
            >
              {hint}
            </span>
          )}
        </div>
      );
    }
    case 'enum': {
      // A pill row reads well for three or four choices and falls apart
      // past that: the control column has no width cap, so a long row
      // squeezes the label column until it wraps one word per line. Hand
      // anything longer to a native select, which is also what someone
      // picking their CDN out of a vendor list expects.
      const options = spec.options ?? [];
      if (options.length > 6) {
        return (
          <Select
            value={value === undefined || value === null ? '' : String(value)}
            options={options.map((opt) => ({
              value: opt,
              label: spec.option_labels?.[opt] ?? humanize(opt),
            }))}
            onChange={onChange}
            ariaLabel={label}
            className="w-56"
          />
        );
      }
      return (
        <div className="flex flex-wrap gap-1.5">
          {(spec.options ?? []).map((opt) => {
            const active = value === opt;
            const optLabel = spec.option_labels?.[opt] ?? humanize(opt);
            return (
              <button
                key={opt}
                type="button"
                onClick={() => onChange(opt)}
                disabled={readOnly}
                className={`text-[12px] px-2.5 py-1 rounded border transition-colors ${
                  active
                    ? 'bg-primary border-primary text-on-primary'
                    : 'border-line text-ink-soft hover:bg-panel'
                } ${readOnly ? 'opacity-60 cursor-not-allowed' : ''}`}
              >
                {optLabel}
              </button>
            );
          })}
        </div>
      );
    }
    case 'string':
    case 'url':
      return (
        <input
          type={spec.type === 'url' ? 'url' : 'text'}
          value={value === undefined || value === null ? '' : String(value)}
          onChange={(e) => onChange(e.target.value)}
          disabled={readOnly}
          className={`w-56 rounded-control border border-line bg-paper px-2.5 py-1.5 text-[13px] text-ink focus:outline-none focus:ring-2 focus:ring-primary ${
            readOnly ? 'opacity-60 cursor-not-allowed' : ''
          }`}
          aria-label={label}
        />
      );
    case 'secret':
      // Credential field — always the shared SecretField (§24.27). The box
      // starts EMPTY with the server's masked hint as placeholder; seeding it
      // with the mask was QA B8 (click-and-type appended, and the bullet run
      // made the server write-preserve, silently discarding the new key).
      return <SecretField value={value} onChange={onChange} label={label} readOnly={readOnly} />;
    case 'media':
      return (
        <MediaField
          value={value === undefined || value === null ? '' : String(value)}
          onChange={(url) => onChange(url)}
          label={label}
        />
      );
    case 'list':
      return (
        <ListField
          value={value}
          label={label}
          onChange={onChange}
          defaultValue={spec.default}
        />
      );
    default:
      return null;
  }
}

function humanize(key: string): string {
  return key
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

