# src/ — the admin UI (React + TypeScript, Vite)

This builds into `assets/admin.js` and `assets/admin.css`, which are committed. Edit here, never in `assets/`. Tests live in `tests-js/` (Vitest). This folder is excluded from the wordpress.org zip.

| Where | What lives there | Read before changing |
|---|---|---|
| `main.tsx`, `App.tsx` | `main.tsx` mounts the dashboard or onboarding (PHP sets the class); `App.tsx` is the dashboard shell and routing | [guides/build-pipeline](../docs/guides/build-pipeline.md) (the mount split) |
| `components/` | Shared primitives (Sidebar, Toggle, Toast, ModulePanel, StatCard, Callout, Tabs, Modal, Select…). `moduleSettingsOverride.ts` reconciles saved values on descriptor refresh; `useResolvedSettings.ts` applies held values in mounted panels. **Reuse them before adding one.** | [DESIGN.md](../DESIGN.md) §7–§16 and §24.x |
| `components/<module>/` | One subfolder per module panel: `cache/`, `cloudflare/`, `compression/`, `cssjs/`, `database/`, `health/`, `mcp/`, `media/`, `migration/`, `objectcache/`, `preloader/`, `resourcehints/`, `settings/`, `support/` | the module's page in [docs/user/](../docs/user/) |
| `onboarding/` | The setup wizard (`steps/` and `components/`) | IMPLEMENTATION.md |
| `upsell/` | Pro surfaces: ProAudit, LockedPanel, `useTier`, `proModuleManifest`, `mergeProModules`. Pro panels mount into these; Free code never names Pro. | [architecture/free-pro-coupling](../docs/architecture/free-pro-coupling.md) |
| `api/client.ts` | The typed fetch wrapper (REST nonce and JSON) | [api/rest](../docs/api/rest.md) |
| `hooks/` | `useTheme`, `useBranding`, `useSidebarCollapse`, `useHubConnect`… | — |
| `types.ts`, `i18n.ts`, `styles.css`, `icon.svg` | Shared types, translations (the `xspeed` textdomain), Tailwind scoped to `#xspeed-app`, and the brand mark (also `public/icon.svg`) | [build-pipeline § Translations](../docs/guides/build-pipeline.md#translations), DESIGN.md §24.28 |

**UI rules.**
- Use only DESIGN.md tokens, with no raw hex outside the token table.
- Pair every light-mode colour with `dark:`, except the §2 role tokens (`line`, `line-strong`, `ink`, `ink-soft`, `panel`, `primary`, `primary-hover`, `on-primary`), which flip themselves.
- Check at 375, 768 and 1280 px. Axe-core a11y is a manual check for now.
- `admin.js` is over its 80 KB gzipped budget. `React.lazy()` doesn't split a chunk here, so the lever is moving code onto a screen that doesn't load it.
