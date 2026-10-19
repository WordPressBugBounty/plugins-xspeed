/**
 * The pending "recommended value" a notice CTA carried in (issue #49).
 *
 * A module-scoped store rather than React context because the producer
 * (the App's hash handler) and the consumer (one field row, several
 * components deep inside whichever panel just mounted) have no useful
 * common ancestor to thread a prop through, and the panel may not exist
 * yet when the suggestion arrives.
 *
 * Exactly one suggestion can be pending at a time. Two competing "we
 * recommend X" strips on one screen is a worse answer than the newest one
 * replacing the older.
 *
 * Deliberately NOT applied automatically. Panels autosave on change, so
 * pre-filling the control would commit a settings write to a live site the
 * moment someone followed a link. The value is shown, and Apply commits it.
 */

export interface FieldSuggestion {
  /** Module slug the field belongs to. */
  module: string;
  /** Field key within that module's schema. */
  field: string;
  /** The recommended value, as it arrived from the link (a string). */
  value: string;
}

type Listener = (suggestion: FieldSuggestion | null) => void;

let current: FieldSuggestion | null = null;
const listeners = new Set<Listener>();

function emit(): void {
  for (const listener of listeners) listener(current);
}

export function setSuggestion(suggestion: FieldSuggestion | null): void {
  current = suggestion;
  emit();
}

export function getSuggestion(): FieldSuggestion | null {
  return current;
}

/** Clear only if it is still the suggestion the caller was showing. */
export function clearSuggestion(module?: string, field?: string): void {
  if (module && field && (current?.module !== module || current?.field !== field)) return;
  current = null;
  emit();
}

export function subscribeSuggestion(listener: Listener): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

/**
 * Coerce the string a link carried into the type the schema field expects.
 *
 * Returns `undefined` when the value cannot be honestly coerced — an int
 * field handed "soon" must not receive NaN, and a bool field handed
 * "perhaps" must not silently become false.
 */
export function coerceSuggestion(raw: string, type: string): unknown {
  switch (type) {
    case 'bool':
    case 'boolean':
      if (raw === '1' || raw === 'true') return true;
      if (raw === '0' || raw === 'false') return false;
      return undefined;

    case 'int': {
      if (!/^-?\d+$/.test(raw.trim())) return undefined;
      return parseInt(raw, 10);
    }

    case 'list':
      // Links carry a comma-separated list; the field stores an array.
      return raw
        .split(',')
        .map((part) => part.trim())
        .filter(Boolean);

    default:
      return raw;
  }
}

/** Human-readable rendering of a suggested value for the strip's label. */
export function describeSuggestion(raw: string, type: string): string {
  const coerced = coerceSuggestion(raw, type);
  if (coerced === undefined) return raw;
  if (typeof coerced === 'boolean') return coerced ? 'On' : 'Off';
  if (Array.isArray(coerced)) return coerced.join(', ');
  return String(coerced);
}
