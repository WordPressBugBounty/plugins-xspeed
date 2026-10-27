import type { ModuleDescriptor, SettingOrigin } from '../types';

export interface ModuleSettingsOverride {
  values: Record<string, unknown>;
  origins: Record<string, SettingOrigin>;
}

const owned = (origin?: SettingOrigin) => origin?.source === 'managed' || origin?.source === 'constant';

/** Server-owned fields must follow the server, including when ownership ends. */
export function resolveOwnedSettings(
  values: Record<string, unknown>,
  server: Record<string, unknown>,
  before: Record<string, SettingOrigin>,
  after: Record<string, SettingOrigin>,
): Record<string, unknown> {
  const next = { ...values };
  for (const key of new Set([...Object.keys(before), ...Object.keys(after)])) {
    if (owned(before[key]) || owned(after[key])) next[key] = server[key];
  }
  return next;
}

export function moduleWithSettingsOverride(module: ModuleDescriptor, override?: ModuleSettingsOverride): ModuleDescriptor {
  return override ? {
    ...module,
    settings: resolveOwnedSettings(override.values, module.settings, override.origins, module.setting_origins ?? {}),
  } : module;
}

export function refreshSettingsOverrides(
  modules: ModuleDescriptor[],
  overrides: Record<string, ModuleSettingsOverride>,
): Record<string, ModuleSettingsOverride> {
  const next = { ...overrides };
  for (const module of modules) {
    const override = overrides[module.slug];
    if (!override) continue;
    next[module.slug] = {
      values: moduleWithSettingsOverride(module, override).settings,
      origins: module.setting_origins ?? {},
    };
  }
  return next;
}
