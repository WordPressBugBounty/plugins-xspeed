import { useEffect, useState } from 'react';
import { Lightbulb, Check } from 'lucide-react';
import { __ } from '../i18n';
import {
  clearSuggestion,
  coerceSuggestion,
  describeSuggestion,
  getSuggestion,
  subscribeSuggestion,
} from './fieldSuggestion';
import type { SchemaField } from '../types';

/**
 * The "we recommend X" strip a notice CTA leaves on its target field
 * (DESIGN.md §24.24, issue #49).
 *
 * The strip exists instead of pre-filling the control because panels
 * autosave: filling the input would commit a settings change to a live
 * site the instant someone followed a link. So the value is stated, and
 * one click applies it — which is what "prefilled with a one-click Apply"
 * has to mean when the form has no Save button.
 */

interface Strip {
  /** Rendered value, e.g. "24" or "On". */
  display: string;
  /** Coerced value ready for the field's onChange. */
  value: unknown;
  apply: () => void;
  dismiss: () => void;
}

/**
 * Subscribe one field row to the pending suggestion.
 *
 * Returns null unless a suggestion is pending FOR THIS FIELD, its value
 * coerces cleanly to the field's type, and it actually differs from what
 * is already stored — recommending the current value is noise.
 */
export function useFieldSuggestion(
  moduleSlug: string,
  fieldKey: string,
  spec: SchemaField,
  current: unknown,
  onChange: (v: unknown) => void,
): Strip | null {
  const [pending, setPending] = useState(() => getSuggestion());

  useEffect(() => subscribeSuggestion(setPending), []);

  if (!pending || pending.module !== moduleSlug || pending.field !== fieldKey) return null;

  const coerced = coerceSuggestion(pending.value, spec.type);
  // A value we cannot honestly coerce (an int field handed "soon") is
  // dropped rather than shown as a recommendation that would write junk.
  if (coerced === undefined) return null;
  // …and one the SCHEMA would reject is dropped too. Without this, a link
  // could offer `cache_expiry=99999` (max 720), render "Recommended: 99999"
  // with a live Apply, and then have Settings_Manager silently keep the old
  // value — a control that says it did something it didn't.
  if (!withinSchema(coerced, spec)) return null;
  if (JSON.stringify(coerced) === JSON.stringify(current)) return null;

  const display = describeSuggestion(pending.value, spec.type);
  // A recommendation with nothing to show ("Recommended:" and a live Apply)
  // is how `?suggest=,` wiped every cache exclusion in QA. If we cannot name
  // the value, we do not offer to write it.
  if (display.trim() === '') return null;

  return {
    display,
    value: coerced,
    apply: () => {
      onChange(coerced);
      clearSuggestion(moduleSlug, fieldKey);
    },
    dismiss: () => clearSuggestion(moduleSlug, fieldKey),
  };
}

/**
 * Would the module schema accept this value?
 *
 * The coercer alone can't tell — it never sees the field spec, so it has no
 * idea about `min`, `max`, or an enum's `options`. Checking here keeps the
 * strip's promise honest: if it renders, Apply writes what it says.
 */
function withinSchema(value: unknown, spec: SchemaField): boolean {
  if (spec.type === 'int') {
    if (typeof value !== 'number') return false;
    if (spec.min !== undefined && value < spec.min) return false;
    if (spec.max !== undefined && value > spec.max) return false;
    return true;
  }
  if (spec.type === 'enum') {
    const options = Array.isArray(spec.options) ? spec.options : [];
    return options.length > 0 && options.includes(String(value));
  }
  if (spec.type === 'list') {
    // An empty list is a deletion, not a recommendation. Offering to blank
    // the cache exclusions from a link is destructive, not helpful.
    return Array.isArray(value) && value.length > 0;
  }
  if (spec.type === 'url') {
    const s = String(value ?? '').trim();
    return s !== '' && /^https?:\/\//i.test(s);
  }
  return true;
}

export function FieldSuggestionStrip({ strip }: { strip: Strip }) {
  return (
    <div className="mt-2 flex flex-wrap items-center gap-2 rounded border border-amber-200 dark:border-amber-900/60 bg-amber-50 dark:bg-amber-950/40 px-2.5 py-1.5">
      <Lightbulb size={13} className="text-amber-600 dark:text-amber-400 shrink-0" />
      <span className="text-[12px] text-amber-900 dark:text-amber-200">
        {__('Recommended:')}{' '}
        <span className="font-medium">{strip.display}</span>
      </span>
      <button
        type="button"
        onClick={strip.apply}
        className="ml-auto inline-flex items-center gap-1 text-[12px] px-2 py-1 rounded bg-accent text-white hover:bg-primary-hover transition-colors"
      >
        <Check size={12} />
        {__('Apply')}
      </button>
      <button
        type="button"
        onClick={strip.dismiss}
        className="text-[12px] text-amber-800 dark:text-amber-300 hover:underline"
      >
        {__('Dismiss')}
      </button>
    </div>
  );
}
