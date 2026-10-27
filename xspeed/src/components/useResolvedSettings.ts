import { useEffect, useRef, type Dispatch, type MutableRefObject, type SetStateAction } from 'react';
import type { ModuleDescriptor } from '../types';
import { resolveOwnedSettings } from './moduleSettingsOverride';

/** Refresh ownership without discarding pending edits or saving a held value. */
export function useResolvedSettings(
  module: ModuleDescriptor,
  setValues: Dispatch<SetStateAction<Record<string, unknown>>>,
  lastApplied: MutableRefObject<string>,
) {
  const previousOrigins = useRef(module.setting_origins ?? {});
  useEffect(() => {
    const before = previousOrigins.current;
    const after = module.setting_origins ?? {};
    previousOrigins.current = after;
    const previousApplied = lastApplied.current;
    if (lastApplied.current) {
      lastApplied.current = JSON.stringify(resolveOwnedSettings(JSON.parse(lastApplied.current), module.settings, before, after));
    }
    setValues((current) => {
      const next = resolveOwnedSettings(current, module.settings, before, after);
      // A new baseline must also cancel a debounce for an edit that now equals the held value.
      if (JSON.stringify(next) === JSON.stringify(current) && previousApplied === lastApplied.current) return current;
      return next;
    });
  }, [module.settings, module.setting_origins, setValues, lastApplied]);
}
