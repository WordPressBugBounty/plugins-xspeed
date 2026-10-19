/**
 * Reconcile a debounced-autosave response against the live edit state.
 *
 * The autosave panels debounce-send the current settings, then echo the
 * server's returned copy back into local state. That echo is stale by the
 * time it lands: a save takes a few seconds, and any field the user edits
 * WHILE the request is in flight would be clobbered when the response
 * overwrites state with the older snapshot. (Reported: typing in an input
 * "resets" mid-save.)
 *
 * This merges the two safely, per key:
 *   - If the user's CURRENT value differs from what we SENT, they edited it
 *     mid-flight → keep the current value (their newer keystrokes win).
 *   - Otherwise adopt the server's SAVED value (accepts normalization /
 *     coercion / defaults the backend applied to untouched fields).
 *
 * Keys present only in `saved` (server-added, e.g. a computed default) are
 * adopted. Keys the user newly added mid-flight (present in `current`, absent
 * from `sent`) are preserved.
 *
 * `dirty` reports whether any field was preserved over the echo — the caller
 * uses it to decide if another save is needed so the mid-flight edit actually
 * persists (the first save only carried the older value).
 */
export function reconcileSaved(
  sent: Record<string, unknown>,
  saved: Record<string, unknown>,
  current: Record<string, unknown>
): { next: Record<string, unknown>; dirty: boolean } {
  const next: Record<string, unknown> = { ...saved };
  let dirty = false;

  // Start from the union of keys so nothing is dropped.
  const keys = new Set([
    ...Object.keys(saved),
    ...Object.keys(current),
    ...Object.keys(sent),
  ]);

  for (const key of keys) {
    const wasEditedInFlight = !shallowEqual(current[key], sent[key]);
    if (wasEditedInFlight) {
      // User changed this field after we sent the request — keep their value.
      next[key] = current[key];
      dirty = true;
    } else if (Object.prototype.hasOwnProperty.call(saved, key)) {
      next[key] = saved[key];
    } else {
      // Untouched by the user and not returned by the server: keep as-is.
      next[key] = current[key];
    }
  }

  return { next, dirty };
}

/**
 * Value-equality good enough for settings fields (primitives, and the
 * shallow arrays/objects our schema produces). Falls back to JSON for
 * non-primitives so an array like `['a','b']` compares by content, not
 * reference — otherwise every save would look "edited in flight".
 */
function shallowEqual(a: unknown, b: unknown): boolean {
  if (a === b) return true;
  if (a === null || b === null || a === undefined || b === undefined) return false;
  if (typeof a !== 'object' || typeof b !== 'object') return false;
  try {
    return JSON.stringify(a) === JSON.stringify(b);
  } catch {
    return false;
  }
}
