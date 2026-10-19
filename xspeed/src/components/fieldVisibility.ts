import { useMemo } from 'react';
import type { SchemaField, FieldDependency } from '../types';

/**
 * Shared `dependsOn` visibility gate (FBS-82227).
 *
 * This is the ONE place the conditional-field rule lives. Both the
 * schema-driven `ModulePanel` and every hand-rolled custom panel
 * (`customPanels.ts`) must run their schema fields through
 * `useVisibleFields` / `dependencyMet` so a `dependsOn` declared in a
 * module's PHP schema actually hides/shows the child field.
 *
 * The Cloudflare custom panel originally bypassed the gate (it iterated
 * `module.schema` directly), so its `dependsOn` declarations were dead at
 * runtime — QA caught it. Centralizing the gate here closes that trap for
 * ALL current and future custom panels: reach for `useVisibleFields`, not a
 * bare `Object.entries(module.schema)`.
 */

/**
 * Is a field's `dependsOn` satisfied by the current values? A field with no
 * dependency always renders. Otherwise the dependency tree is evaluated
 * recursively — see SchemaField.dependsOn for the three forms (single
 * condition, `all` AND-group, `any` OR-group).
 */
export function dependencyMet(
  spec: SchemaField,
  values: Record<string, unknown>,
  schema: Record<string, SchemaField>
): boolean {
  if (!spec.dependsOn) return true;
  return conditionMet(spec.dependsOn, values, schema, new Set());
}

/**
 * Evaluate one dependency node (a leaf condition or an all/any group).
 *
 * A leaf `{ field, value? }` is satisfied when the named sibling is itself
 * visible (transitive gate) AND its value matches — strict-equals `value`
 * when `value` is present, else truthy. The transitive step makes a chain
 * like enabled → auth_method → api_token collapse entirely when `enabled`
 * is off, without every leaf restating the root gate. `seen` guards a
 * malformed self-/cyclic reference (treated as satisfied so it can't lock
 * the whole panel).
 */
export function conditionMet(
  dep: FieldDependency,
  values: Record<string, unknown>,
  schema: Record<string, SchemaField>,
  seen: Set<string>
): boolean {
  if ('all' in dep) {
    return dep.all.every((d) => conditionMet(d, values, schema, seen));
  }
  if ('any' in dep) {
    return dep.any.some((d) => conditionMet(d, values, schema, seen));
  }
  // Leaf condition.
  if (seen.has(dep.field)) return true; // cycle guard
  const parentSpec = schema[dep.field];
  if (parentSpec?.dependsOn) {
    const next = new Set(seen);
    next.add(dep.field);
    if (!conditionMet(parentSpec.dependsOn, values, schema, next)) return false;
  }
  const parent = values[dep.field];
  if (Object.prototype.hasOwnProperty.call(dep, 'value')) {
    return parent === dep.value;
  }
  return Boolean(parent);
}

/**
 * Return the `[key, spec]` entries of a module's schema that should render,
 * given the current field values — `dependsOn`-gated, memoized.
 *
 * `extraFilter` lets a panel drop additional fields for reasons unrelated to
 * `dependsOn` (e.g. Cloudflare hiding the credential slot that doesn't match
 * the chosen auth method, or ObjectCache hiding redis vs memcached fields).
 * It runs AFTER the dependency gate. Filtering here — rather than inside the
 * render `.map` — keeps row indices contiguous so a "first row" top-border
 * lands on the real first visible field.
 */
export function useVisibleFields(
  schema: Record<string, SchemaField>,
  values: Record<string, unknown>,
  extraFilter?: (key: string, spec: SchemaField) => boolean
): Array<[string, SchemaField]> {
  return useMemo(
    () =>
      Object.entries(schema).filter(
        ([key, spec]) =>
          dependencyMet(spec, values, schema) &&
          (extraFilter ? extraFilter(key, spec) : true)
      ),
    // extraFilter is intentionally omitted: callers pass an inline closure
    // that changes identity every render; the values/schema deps already
    // capture every input the closure reads.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [schema, values]
  );
}
