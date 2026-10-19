import type { ComponentType } from 'react';
import type { ModuleDescriptor } from '../types';
import { HealthPanel } from './health/HealthPanel';
import { PreloaderHost } from './preloader/PreloaderHost';
import { DatabaseCleanerPanel } from './database/DatabaseCleanerPanel';
import { CloudflarePanel } from './cloudflare/CloudflarePanel';
import { ObjectCachePanel } from './objectcache/ObjectCachePanel';
import { MigrationPanel } from './migration/MigrationPanel';
import { SupportPanel } from './support/SupportPanel';
import { McpPanel } from './mcp/McpPanel';
import { CompressionPanel } from './compression/CompressionPanel';
import { CssJsPanel } from './cssjs/CssJsPanel';
import { MediaPanel } from './media/MediaPanel';
import { ResourceHintsPanel } from './resourcehints/ResourceHintsPanel';
import { CacheCoveragePanel } from './cache/CacheCoveragePanel';
import { ScorePanel } from './score/ScorePanel';

/**
 * Props every panel (schema-driven or custom) receives. `onSaved`
 * fires after a successful settings persist so App can keep the shared
 * module snapshot current (prevents the "needs page reload" staleness)
 * and refresh dashboard-level status. Custom panels that don't persist
 * settings can ignore it.
 */
export interface PanelProps {
  module: ModuleDescriptor;
  onSaved?: (saved: Record<string, unknown>) => void;
  /**
   * Active sub-tab key from the compound URL hash (`#slug/subtab`), and
   * a setter to change it. Threaded through by ModulePanel so a
   * tab-hosting panel (FBS-83633 restructure) can bind its active tab to
   * the URL — deep-linkable + back/forward-aware. Panels without tabs
   * ignore both. Empty string = the panel's own default tab.
   */
  subtab?: string;
  onSubtabChange?: (key: string) => void;
  /**
   * Look up a sibling module descriptor by slug from the shared module
   * snapshot. Host pages (FBS-83633 restructure) that embed a Pro
   * sub-panel as a tab/section need that Pro module's OWN descriptor
   * (its settings/schema/rest base), not the host's. Returns undefined
   * when the slug isn't in the payload (e.g. a Pro module on Free that
   * has no locked placeholder). Flat panels ignore this.
   */
  getModule?: (slug: string) => ModuleDescriptor | undefined;
}

/**
 * Registry of custom React panels that modules can opt into via
 * `ui_metadata.custom_panel` (see SETTINGS.md §6.2 — the documented
 * escape hatch for non-schema-driven surfaces).
 *
 * Adding a custom panel = (1) register here, (2) document the
 * non-schema rationale in DESIGN.md §24.x. Don't reach for this
 * just because a schema-driven layout is awkward; the right answer
 * is usually a richer field type.
 */
export const customPanels: Record<string, ComponentType<PanelProps>> = {
  HealthPanel,
  PreloaderHost,
  DatabaseCleanerPanel,
  CloudflarePanel,
  ObjectCachePanel,
  MigrationPanel,
  SupportPanel,
  McpPanel,
  CompressionPanel,
  CssJsPanel,
  MediaPanel,
  ResourceHintsPanel,
  CacheCoveragePanel,
  ScorePanel,
};
