/**
 * Catalog of Pro upsell teasers shown inside Free panels.
 *
 * Each entry is what a Free user sees at the BOTTOM of a relevant
 * panel — a non-pushy card that points at a Pro feature solving a
 * specific problem the user might run into while using Free.
 *
 * Adding a new teaser:
 *  1. Add an entry here with a stable `id` (used as React key).
 *  2. Place <ProTeaser id="…"/> at the end of the relevant Free
 *     panel's render. Multiple panels can reference the same
 *     teaser id when the feature spans (e.g., Critical CSS shown
 *     from both Minify + Cache panels).
 *
 * Keep it factual: state what Pro DOES, not "buy now to get 47%
 * faster site." Trust > pressure.
 */

/**
 * Single source of truth for the Pro upgrade / pricing URL. All upsell CTAs
 * (LockedPanel, ProTeaser, ProAudit) link here so the destination changes in
 * one place.
 */
export const PRO_UPGRADE_URL = 'https://xspeedcache.com/pricing/';

/**
 * Where the Cloudflare Enterprise add-on is sold: the store's checkout with
 * the add-on already in the cart. It runs inside Pro, so it is offered beside
 * "Upgrade to Pro", never instead of it. Checked 2026-09-16 in brand-new
 * browser profiles: the cart opens with the add-on in it.
 */
export const CFE_ADDON_URL = 'https://store.wpdeveloper.com/checkout/?download_id=1971516&price_id=1';

/**
 * What the add-on costs, as the purchase screen shows it. The store sets the
 * price, so this has to follow it: change it here and in Pro's
 * `src/shared/productNames.ts` when the store's price changes.
 */
export const CFE_ADDON_PRICE = '$5';
export const CFE_ADDON_PERIOD = 'per domain, per month';

/**
 * The names customers see for the two purchases. `CFE_ADDON_NAME` is
 * provisional (decided 2026-09-16) and expected to change, so no string spells
 * it out: they all interpolate this. Pro carries the same value in
 * `src/shared/productNames.ts`; change both together. A test fails if the
 * literal appears anywhere else in `src/`.
 */
/**
 * The customer setup guide for Cloudflare Enterprise. Pro's panel links the
 * same page, so a customer reads one guide wherever they start.
 */
export const CFE_DOCS_URL = 'https://xspeedcache.com/docs/cloudflare-enterprise/';

export const CFE_ADDON_NAME = 'Cloudflare Enterprise Add-on';
export const PRO_NAME = 'xSpeed Cache Pro';

/**
 * Single source of truth for the "Activate your license" destination: the
 * License card on the Settings landing. Every CTA in the product — Free's
 * LockedPanel / LockedSection / ProOverview / ProDemo and Pro's admin
 * notices — must land here.
 *
 * Pro's PHP notices still ship the legacy `#license` form; nav.ts resolves it
 * to this route (license is a Settings-group slug) and App canonicalizes the
 * URL on arrival, so both spellings agree.
 */
export const LICENSE_URL = 'admin.php?page=xspeed#/settings/license';

export interface ProFeature {
  id: string;
  /** A ModuleIcon name (sparkles, etc) — must be in ModuleIcon's whitelist. */
  icon: string;
  /** One short noun phrase. */
  title: string;
  /** One sentence explaining what Pro does that Free can't. */
  body: string;
  /** CTA label. Default "Upgrade to Pro" when omitted. */
  cta?: string;
}

export const PRO_FEATURES: Record<string, ProFeature> = {
  'cloudflare-enterprise': {
    id: 'cloudflare-enterprise',
    icon: 'Globe',
    title: 'Cloudflare CDN',
    // What it does, not what it costs: the price is the store's to say and
    // changes without a release of this plugin.
    body: `Serve your whole site from Cloudflare's network, with pages cached worldwide, a firewall, rate limiting and image optimization. You do not need a Cloudflare account. Needs ${PRO_NAME} and the ${CFE_ADDON_NAME}, bought separately.`,
  },
  'critical-css': {
    id: 'critical-css',
    icon: 'Sparkles',
    title: 'Critical CSS',
    body: 'Inline above-the-fold styles and defer the rest. Cuts first paint on theme-heavy pages by 30-60%.',
  },
  'unused-css': {
    id: 'unused-css',
    icon: 'Scissors',
    title: 'Remove Unused CSS',
    body: 'Per-URL pruned stylesheet inlined; original <link> tags stripped. Pairs with Critical CSS.',
  },
  'script-manager': {
    id: 'script-manager',
    icon: 'LayoutDashboard',
    title: 'Asset Manager',
    body: 'Load a plugin\'s stylesheets and scripts only on the pages that use them, such as a contact form\'s files only on the contact page. Refuses rules that would break a script that depends on them.',
  },
  'self-host-fonts': {
    id: 'self-host-fonts',
    icon: 'FileType',
    title: 'Self-hosted Google Fonts',
    body: 'Downloads the Google Fonts your pages use and serves them from your own site, so visitors make no requests to Google. A font that fails to download keeps loading from Google.',
  },
  'webp-avif': {
    id: 'webp-avif',
    icon: 'Replace',
    title: 'Auto WebP / AVIF on upload',
    body: 'New JPEG/PNG uploads get .webp + .avif siblings; browsers that support modern formats get them automatically.',
  },
  'cloudflare-apo': {
    id: 'cloudflare-apo',
    icon: 'Zap',
    title: 'Cloudflare APO + edge cache',
    body: 'Toggle Automatic Platform Optimization right from here, set the zone cache level and browser cache TTL, no separate dashboard.',
  },
  'feed-cache': {
    id: 'feed-cache',
    icon: 'Rss',
    title: 'Feed caching',
    body: 'Cache RSS / Atom / comment feeds so aggregators polling your feed skip a full render — with the correct feed content-type preserved and auto-purge on new posts.',
  },
  'cache-404': {
    id: 'cache-404',
    icon: 'FileX',
    title: '404 caching',
    body: 'Serve one short-lived cached 404 so bot and scanner floods on nonexistent URLs don\'t each trigger a full render. Shared entry, correct 404 status, purged when you publish.',
  },
  'search-cache': {
    id: 'search-cache',
    icon: 'Search',
    title: 'Search caching',
    body: 'Cache internal search-results pages (/?s=…) so popular repeated searches skip a full query + render. Keyed per term, short-lived, and purged when you publish new content.',
  },
  'rest-cache': {
    id: 'rest-cache',
    icon: 'Webhook',
    title: 'REST API per-route cache',
    body: 'Cache read-only REST responses with a TTL you set per route — ideal for headless front-ends and app backends. Authenticated and write requests are never cached.',
  },
  'prewarm-schedule': {
    id: 'prewarm-schedule',
    icon: 'CalendarClock',
    title: 'Scheduled prewarming',
    body: 'Warm target URLs ahead of a post going live, a sale, or a campaign so the traffic spike hits a warm cache. One-off, recurring, and auto-warm when a scheduled post publishes.',
  },
  'predictive-warm': {
    id: 'predictive-warm',
    icon: 'TrendingUp',
    title: 'Predictive warming',
    body: 'Proactively warm the pages most likely to be visited next — ranked by real traffic, recency, and content — so your hottest URLs are always served from cache, not a cold render.',
  },
  brotli: {
    id: 'brotli',
    icon: 'Shrink',
    title: 'Brotli compression',
    body: 'Serve Brotli where the browser and server support it — typically 15-25% smaller than GZIP — with automatic GZIP fallback.',
  },
  rum: {
    id: 'rum',
    icon: 'Eye',
    title: 'Real-User Monitoring',
    body: 'See actual LCP / CLS / INP from your visitors’ browsers, not synthetic Lighthouse. Per-URL percentiles.',
  },
  pagespeed: {
    id: 'pagespeed',
    icon: 'Gauge',
    title: 'PageSpeed Insights, in-dashboard',
    body: 'Run Google PSI on any URL without leaving xSpeed. Scores + Core Web Vitals + cached for 24h.',
  },
  recommendations: {
    id: 'recommendations',
    icon: 'Lightbulb',
    title: 'Smart recommendations',
    body: 'Pro audits your current settings + server state and surfaces the 5 things most likely to move your score.',
  },
  analytics: {
    id: 'analytics',
    icon: 'BarChart3',
    title: 'Per-URL cache analytics',
    body: 'Slowest pages, hit-ratio chart, recent activity timeline. Powered by the RUM data you’re already collecting.',
  },
  migration: {
    id: 'migration',
    icon: 'Import',
    title: 'One-click migration',
    body: 'Import settings from WP Rocket, W3 Total Cache, or WP Super Cache. No manual reconfiguration.',
  },
  'white-label': {
    id: 'white-label',
    icon: 'Tag',
    title: 'White-label dashboard',
    body: 'Rebrand the plugin name, footer credit, and help links for client work.',
  },
  multisite: {
    id: 'multisite',
    icon: 'Network',
    title: 'Multisite network view',
    body: 'One dashboard across every site in your network with per-site cache state and network-wide purge.',
  },
  'custom-rules': {
    id: 'custom-rules',
    icon: 'Filter',
    title: 'Pattern-based cache rules',
    body: 'Bypass cache or override expiry by URL glob, post type, category, tag, or author — beyond per-post settings.',
  },
};
