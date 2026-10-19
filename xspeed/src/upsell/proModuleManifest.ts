/**
 * Static catalog of Pro modules. Free uses this to render
 * placeholder ("locked") sidebar rows so users discover what Pro
 * adds without installing the Pro plugin.
 *
 * Source of truth: each Pro module's ui_metadata() in
 * xspeed-pro/modules/<Name>/<Name>Module.php. When a new Pro module
 * ships, add it here too — the sanity test in
 * tests-js/components/iconUniqueness.test.ts AND a sidebar group
 * lookup will catch a missing/typo'd entry.
 *
 * Why mirror by hand instead of having Pro publish a JSON manifest?
 * Free needs this info BEFORE Pro is installed (it's the whole
 * point of the locked rows). Pro can't publish anything to Free
 * if it's not present. Hand-mirror is the pragmatic choice.
 */

import type { PRO_FEATURES } from './proFeatures';

export interface ProModuleManifestEntry {
  slug: string;
  label: string;
  /** lucide icon name; must be in ModuleIcon's whitelist. */
  icon: string;
  /** Short tagline shown in the LockedPanel below the title. */
  description: string;
  /**
   * The matching entry in PRO_FEATURES (used to render the upgrade
   * card body when the user clicks a locked row). Null = no detailed
   * teaser; just shows the basic locked card.
   */
  teaser: keyof typeof PRO_FEATURES | null;
  /**
   * When true, this Pro module is surfaced as a section/tab INSIDE a Free
   * host page (via <ProSlot>), not as its own sidebar row (FBS-83633). It
   * still keeps its manifest entry so the host's <LockedSection> can read
   * its label/icon/description/teaser — but mergeProModules skips it when
   * injecting locked sidebar rows, so no standalone row appears on Free.
   */
  embedded?: boolean;
  /**
   * Sold on its own, not as part of Pro. Its placeholder is added whenever
   * the module is missing, Pro installed or not: a Pro older than the
   * add-on has no module for it, and without the placeholder the add-on's
   * card and page vanished from the dashboard on those sites while the
   * sidebar offer still pointed at the page.
   */
  soldSeparately?: boolean;
}

export const PRO_MODULE_MANIFEST: ProModuleManifestEntry[] = [
  /*
   * Its own sidebar row, not embedded.
   *
   * The `edge` group exists for it and has no Free module in it, so without
   * this entry the group has nothing to draw and disappears — which is what
   * happened when the row was hidden to fix a dead-end click. Hiding it
   * removed the upsell along with the defect; a locked row is the fix, and it
   * is what every other paid feature already does here.
   */
  {
    slug: 'cloudflare-enterprise',
    label: 'Cloudflare Enterprise',
    icon: 'Globe',
    // The two purchases are the locked panel's main block (§24.42), so the
    // header describes the feature and does not repeat them.
    description: "Serve your site from Cloudflare's network. No Cloudflare account needed.",
    teaser: 'cloudflare-enterprise',
    soldSeparately: true,
  },
  {
    slug: 'pagespeed',
    label: 'PageSpeed',
    icon: 'Gauge',
    description: 'Run Google PageSpeed Insights audits without leaving the dashboard. Scores + CWV cached 24h.',
    teaser: 'pagespeed',
    embedded: true,
  },
  {
    slug: 'recommendations',
    label: 'Recommendations',
    icon: 'Lightbulb',
    description: 'Rule-based engine surfaces the 5 things most likely to improve your score.',
    teaser: 'recommendations',
    embedded: true,
  },
  {
    slug: 'rum',
    label: 'Real-User Monitoring',
    icon: 'Eye',
    description: 'Sample LCP / FCP / CLS / INP / TTFB from real visitors. Per-URL percentile charts.',
    teaser: 'rum',
    embedded: true,
  },
  {
    slug: 'images',
    label: 'Image Optimization',
    icon: 'Replace',
    description: 'Convert new JPEG/PNG uploads to WebP and AVIF; serve modern formats to supporting browsers.',
    teaser: 'webp-avif',
    embedded: true,
  },
  {
    slug: 'cloudflare-apo',
    label: 'Cloudflare APO',
    icon: 'Zap',
    description: 'Toggle Automatic Platform Optimization, edge cache level, and TTL. No separate dashboard.',
    teaser: 'cloudflare-apo',
    embedded: true,
  },
  {
    slug: 'feed-cache',
    label: 'Feed Caching',
    icon: 'Rss',
    description: 'Cache RSS / Atom / comment feeds so polling aggregators skip a full render. Correct feed content-type preserved; purges on new posts.',
    teaser: 'feed-cache',
    embedded: true,
  },
  {
    slug: 'cache-404',
    label: '404 Caching',
    icon: 'FileX',
    description: 'Serve one cached 404 so bot / scanner floods on unknown URLs don\'t each cost a render. Short-lived, shared entry, purged on publish.',
    teaser: 'cache-404',
    embedded: true,
  },
  {
    slug: 'search-cache',
    label: 'Search Caching',
    icon: 'Search',
    description: 'Cache internal search-results pages (/?s=…) so popular repeated searches skip a full query + render. Keyed per term, short-lived, purged on publish.',
    teaser: 'search-cache',
    embedded: true,
  },
  {
    slug: 'rest-cache',
    label: 'REST API Cache',
    icon: 'Webhook',
    description: 'Cache read-only REST responses with a TTL you set per route — built for headless sites and app backends. Auth\'d and write requests never cache.',
    teaser: 'rest-cache',
    embedded: true,
  },
  {
    slug: 'maintenance-cache',
    label: 'Maintenance',
    icon: 'Wrench',
    description: 'Skip caching while the site is in maintenance / coming-soon mode, and auto-purge when it goes live again.',
    teaser: null,
    embedded: true,
  },
  {
    slug: 'prewarm-schedule',
    label: 'On a Schedule',
    icon: 'CalendarClock',
    description: 'Warm target URLs ahead of posts, sales, and campaigns so the spike hits a warm cache. One-off, recurring, and auto-warm on scheduled-post publish.',
    teaser: 'prewarm-schedule',
    embedded: true,
  },
  {
    slug: 'predictive-warm',
    label: 'Auto (AI)',
    icon: 'TrendingUp',
    description: 'Proactively warm the hottest URLs — ranked by real traffic, recency, and content — so popular and about-to-be-popular pages always serve from cache.',
    teaser: 'predictive-warm',
    embedded: true,
  },
  {
    slug: 'brotli-compression',
    label: 'Brotli',
    icon: 'Shrink',
    description: 'Serve Brotli where supported — smaller than GZIP — with automatic GZIP fallback.',
    teaser: 'brotli',
    embedded: true,
  },
  {
    slug: 'custom-rules',
    label: 'Custom Cache Rules',
    icon: 'Filter',
    description: 'Bypass cache or override expiry by URL glob, post type, category, tag, or author.',
    teaser: 'custom-rules',
    embedded: true,
  },
  {
    slug: 'analytics',
    label: 'Analytics',
    icon: 'BarChart3',
    description: 'Hit-ratio trends, slowest URLs from RUM samples, recent activity timeline.',
    teaser: 'analytics',
    embedded: true,
  },
  {
    slug: 'critical-css',
    label: 'Critical CSS',
    icon: 'Sparkles',
    description: 'Inline above-the-fold styles, defer the rest. Pluggable generator endpoint.',
    teaser: 'critical-css',
    embedded: true,
  },
  {
    slug: 'unused-css',
    label: 'Unused CSS',
    icon: 'Scissors',
    description: 'Per-URL pruned stylesheet inlined; original <link> tags stripped.',
    teaser: 'unused-css',
    embedded: true,
  },
  // Its own card on the Optimization landing, so not embedded.
  {
    slug: 'script-manager',
    label: 'Asset Manager',
    icon: 'LayoutDashboard',
    description: 'Stop chosen stylesheets and scripts from loading on chosen pages.',
    teaser: 'script-manager',
  },
  // Its own card on the Optimization landing, beside Fonts.
  {
    slug: 'self-host-fonts',
    label: 'Self-hosted Google Fonts',
    icon: 'FileType',
    description: 'Serves Google Fonts from your own site, so visitors make no requests to Google.',
    teaser: 'self-host-fonts',
  },
  // The three Settings cards. Not `embedded` — each is its own card on the
  // Settings landing, so Free needs a locked placeholder descriptor for each
  // (mergeProModules only injects non-embedded entries).
  {
    slug: 'license',
    label: 'License',
    icon: 'KeyRound',
    description: 'Activate xSpeed Pro and manage automatic updates.',
    teaser: null,
  },
  {
    slug: 'white-label',
    label: 'Branding',
    icon: 'Tag',
    description: 'Rebrand the dashboard chrome — name, footer credit, hide help links.',
    teaser: 'white-label',
  },
  {
    slug: 'multisite',
    label: 'Multisite',
    icon: 'Network',
    description: 'One dashboard across every site in a WP Multisite network with network-wide purge.',
    teaser: 'multisite',
  },
  {
    slug: 'ai-provider',
    label: 'AI Provider',
    icon: 'Sparkles',
    description:
      'Your own OpenAI, Anthropic, Gemini or OpenRouter key, used by Pro features that think for themselves — Adaptive Cache, Speculation Rules and AI Suggestions. Not needed to connect Claude — that is MCP Server.',
    teaser: null,
  },
  {
    slug: 'smart-predict',
    label: 'Speculation Rules',
    icon: 'Wand2',
    description: 'Predict each visitor\'s next page and prefetch it via Chrome\'s Speculation Rules API.',
    teaser: null,
    embedded: true,
  },
  {
    slug: 'performance-health',
    label: 'Performance Health',
    icon: 'HeartPulse',
    description: 'Core Web Vitals health monitor with AI anomaly detection and a 7-day regression baseline.',
    teaser: null,
    embedded: true,
  },
  {
    slug: 'smart-image',
    label: 'AI Suggestions',
    icon: 'Sparkles',
    description: 'AI-recommended format + quality settings, applied into Image Optimization in one click.',
    teaser: null,
    embedded: true,
  },
  {
    slug: 'adaptive-cache',
    label: 'Adaptive Cache',
    icon: 'Lightbulb',
    description: 'AI-tuned cache TTL based on 24-hour traffic patterns, applied into the Cache module in one click.',
    teaser: null,
  },
];
