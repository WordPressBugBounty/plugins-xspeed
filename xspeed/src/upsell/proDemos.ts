/**
 * Pro "demonstration" upsell catalog (FBS-83633).
 *
 * The problem this solves: several Free host pages had *clusters* of
 * Pro sub-features (e.g. the Cache "What to cache" tab = 404 Caching +
 * Search Caching). Rendering each as its own <LockedSection> stacked
 * three or four near-identical amber "Upgrade to Pro" cards on one
 * screen — it read as nagware, not as an invitation.
 *
 * A ProDemo replaces that whole cluster with ONE richer section that
 * *shows* the feature instead of just naming it: a faithful, inert mock
 * of the Pro UI (toggles with live-looking stats, a mini chart, a code
 * preview…), a checklist of concrete capabilities, and a single CTA.
 * The mock is pure presentation — no state, no API — so a Free user can
 * picture the feature working before they buy.
 *
 * A demo is keyed by a stable `id` and pulls its copy from the same
 * PRO_FEATURES / PRO_MODULE_MANIFEST catalogs the LockedSection uses, so
 * nothing drifts. <ProDemo id="…"/> renders it; the CTA state (upgrade
 * vs activate-license) comes from useProState(), same as LockedSection.
 *
 * Keep it factual — describe what Pro DOES. The mock illustrates; it
 * never invents a metric we can't stand behind.
 */

import { CFE_ADDON_NAME, CFE_ADDON_PERIOD, CFE_ADDON_PRICE, CFE_ADDON_URL, PRO_NAME } from './proFeatures';

export type MockKind =
  | 'toggles' // rows of feature toggles, each with a small stat chip
  | 'chart' // a mini sparkline/bars block with a headline number
  | 'code' // a monospace preview (e.g. a rule / snippet)
  | 'formats'; // format badges (WebP / AVIF style)

/** A single toggle row in a `toggles` mock. */
export interface MockToggleRow {
  label: string;
  /** Whether the faux switch reads as "on". */
  on: boolean;
  /** Small right-aligned stat chip, e.g. "1,204 served". Optional. */
  stat?: string;
}

/** A labelled bar in a `chart` mock. */
export interface MockBar {
  label: string;
  /** 0–100 height percentage. */
  value: number;
}

export interface ProDemoMock {
  kind: MockKind;
  /** `toggles`: the rows. */
  rows?: MockToggleRow[];
  /** `chart`: the bars + a headline stat above them. */
  bars?: MockBar[];
  headline?: string;
  headlineNote?: string;
  /** `code`: preformatted lines. */
  code?: string[];
  /** `formats`: the badge labels, e.g. ['WebP', 'AVIF']. */
  badges?: string[];
}

/** One checklist item under the mock. */
export interface ProDemoPoint {
  /** ModuleIcon name for the leading glyph. */
  icon: string;
  /** Short capability statement. */
  text: string;
}

export interface ProDemo {
  id: string;
  /** Section heading (the cluster's name, not an engine term). */
  title: string;
  /** One-line framing of the problem the cluster solves. */
  subtitle: string;
  /** Header glyph (ModuleIcon name). */
  icon: string;
  /** The inert preview to render above the checklist. */
  mock: ProDemoMock;
  /** Concrete capabilities, one line each. */
  points: ProDemoPoint[];
  /**
   * A feature that takes a second purchase on top of Pro. Its locked row
   * renders the §24.42 purchase path — Pro, then this — instead of the
   * preview mock, because "Upgrade to Pro" alone does not switch it on.
   */
  purchase?: {
    /** Heading over the two steps. */
    title: string;
    /** The header pill, by what is missing: Pro itself, or only its licence. */
    badge: { missing: string; unlicensed: string };
    pro: { name: string; kind: string; detail: string };
    addon: { name: string; kind: string; detail: string };
    offer: {
      name: string;
      price: string;
      period: string;
      note: string;
      features: string[];
      cta: { label: string; href: string };
    };
    /** The "How it works" strip. Node icons are lucide names. */
    flow: {
      eyebrow: string;
      title: string;
      body: string;
      label: string;
      nodes: { label: string; detail: string; icon: 'User' | 'Cloud' | 'Server'; highlight?: boolean; via?: 'solid' | 'dashed' }[];
    };
  };
}

export const PRO_DEMOS: Record<string, ProDemo> = {
  /*
   * The edge · a standalone locked row of its own.
   *
   * Sold separately from Pro and set up from inside the dashboard, so the
   * demonstration has to answer "what would I be buying" for someone who has
   * never heard of it. The price comes from `CFE_ADDON_PRICE`, which has to
   * follow the store's.
   */
  'cloudflare-enterprise': {
    id: 'cloudflare-enterprise',
    title: 'Your whole site, served from Cloudflare',
    subtitle:
      'Cloudflare caches your pages worldwide, not only images and scripts, so a visitor far from your server gets the page from a city near them. You do not need a Cloudflare account.',
    icon: 'Globe',
    mock: {
      kind: 'toggles',
      rows: [
        { label: 'Page caching', on: true, stat: 'on' },
        { label: 'Firewall', on: true, stat: 'on' },
        { label: 'Rate limiting', on: true, stat: 'on' },
        { label: 'Image optimization', on: false, stat: 'available' },
      ],
    },
    points: [
      { icon: 'Globe', text: 'Pages come from Cloudflare’s network, so the distance to your server no longer decides how fast the site feels.' },
      { icon: 'Lock', text: 'A firewall and rate limiting in front of WordPress. You do not need a Cloudflare account or to move your DNS.' },
      { icon: 'Check', text: 'When you publish, Cloudflare is cleared as well as this site, so you do not wait for a cache to expire.' },
    ],
    // Sold separately and needs Pro: two purchases, in that order.
    purchase: {
      title: 'You need two things',
      badge: { missing: 'Pro and add-on needed', unlicensed: 'Pro license and add-on needed' },
      pro: { name: PRO_NAME, kind: 'Plugin', detail: 'The plugin the add-on needs. The add-on runs inside it.' },
      addon: {
        name: CFE_ADDON_NAME,
        kind: 'Add-on',
        detail: 'Sold separately, with its own license key. It is a license, not a second plugin.',
      },
      offer: {
        name: CFE_ADDON_NAME,
        price: CFE_ADDON_PRICE,
        period: CFE_ADDON_PERIOD,
        note: `Added on top of ${PRO_NAME}.`,
        features: [
          'Whole pages served from Cloudflare’s network',
          'Managed firewall and rate limiting',
          'Image optimization on Cloudflare',
          'Cloudflare cleared every time you publish',
          'No Cloudflare account needed',
        ],
        cta: { label: 'Get the add-on', href: CFE_ADDON_URL },
      },
      flow: {
        eyebrow: 'How it works',
        title: 'Your whole site, served from Cloudflare',
        body: 'Visitors get each page from a Cloudflare location near them, instead of waiting on your server.',
        label: 'Visitor, then Cloudflare network serving the cached page, then your server only when needed',
        nodes: [
          { label: 'Visitor', detail: 'Opens a page', icon: 'User' },
          { label: 'Cloudflare network', detail: 'Serves the cached page', icon: 'Cloud', highlight: true },
          { label: 'Your server', detail: 'Only when needed', icon: 'Server', via: 'dashed' },
        ],
      },
    },
  },

  // ── AI & Agents · standalone locked rows ────────────────────────────
  // Keyed by module SLUG so LockedPanel can look them up directly, giving
  // a clicked standalone Pro row the same demonstration treatment as an
  // embedded tab (no more bare crown card).
  'ai-provider': {
    id: 'ai-provider',
    title: 'One AI connection, every AI feature',
    subtitle:
      'Connect OpenAI, Anthropic, Gemini, or OpenRouter once — every Pro AI feature shares the same key, no per-feature setup.',
    icon: 'Sparkles',
    mock: {
      kind: 'toggles',
      rows: [
        { label: 'OpenAI', on: true, stat: 'connected' },
        { label: 'Anthropic', on: false, stat: 'available' },
        { label: 'Google Gemini', on: false, stat: 'available' },
        { label: 'OpenRouter', on: false, stat: 'available' },
      ],
    },
    points: [
      { icon: 'Sparkles', text: 'Connect one provider; Adaptive Cache, Speculation Rules, AI Suggestions and Performance Health all use it.' },
      { icon: 'Check', text: 'Connecting Claude is a separate, free thing — that is the MCP Server card.' },
      { icon: 'Lock', text: 'Your key stays on your server — stored in wp-config or the database, never shipped to us.' },
      { icon: 'Check', text: 'Swap providers any time without reconfiguring each feature.' },
    ],
  },
  'adaptive-cache': {
    id: 'adaptive-cache',
    title: 'Cache TTL that tunes itself',
    subtitle:
      'xSpeed reads your last 24 hours of traffic and recommends the cache lifetime that balances freshness against hit-rate — applied in one click.',
    icon: 'Lightbulb',
    mock: {
      kind: 'chart',
      headline: '+18%',
      headlineNote: 'projected hit-rate at the suggested TTL',
      bars: [
        { label: 'Now', value: 71 },
        { label: 'Suggested', value: 89 },
      ],
    },
    points: [
      { icon: 'Lightbulb', text: 'AI-tuned TTL from real 24-hour traffic patterns — no more guessing a number.' },
      { icon: 'Check', text: 'Review the recommendation, then apply it into the Cache module in one click.' },
    ],
  },

  // ── Optimization · a standalone card ────────────────────────────────
  'script-manager': {
    id: 'script-manager',
    title: 'Load each plugin’s files only where they’re used',
    subtitle:
      'Most plugins load their stylesheets and scripts on every page, even when they only run on one. Asset Manager lets you choose the pages each file loads on.',
    icon: 'LayoutDashboard',
    mock: {
      kind: 'toggles',
      rows: [
        { label: 'contact-form-7 (CSS)', on: true, stat: '/contact/ only' },
        { label: 'contact-form-7 (JS)', on: true, stat: '/contact/ only' },
        { label: 'slider (JS)', on: true, stat: 'front page only' },
        { label: 'jquery', on: false, stat: 'needed by 4 scripts' },
      ],
    },
    points: [
      { icon: 'LayoutDashboard', text: 'See every stylesheet and script a page loads, with its size and the plugin it comes from.' },
      { icon: 'Filter', text: 'Choose where each one loads: by URL, post type or template, with CSS and JS set separately.' },
      { icon: 'Check', text: 'Rules that would break a script depending on another are refused, and one click turns every rule off.' },
    ],
  },

  // ── Cache · Advanced Cache tabs ─────────────────────────────────────
  'self-host-fonts': {
    id: 'self-host-fonts',
    title: 'Serve Google Fonts from your own site',
    subtitle:
      'Every page that uses Google Fonts makes each visitor’s browser connect to Google. Self-hosted Google Fonts downloads the fonts once and serves them from your site.',
    icon: 'FileType',
    mock: {
      kind: 'toggles',
      rows: [
        { label: 'Roboto 400, 700', on: true, stat: 'served locally' },
        { label: 'Open Sans 400, 700', on: true, stat: 'served locally' },
        { label: 'Material Symbols', on: true, stat: 'served locally' },
        { label: 'Lato 400', on: false, stat: 'from Google until it downloads' },
      ],
    },
    points: [
      { icon: 'Search', text: 'Finds the Google Fonts your theme and plugins add, in stylesheet links and in @import rules.' },
      { icon: 'Shield', text: 'Once the fonts have downloaded, visitors’ browsers make no requests to Google.' },
      { icon: 'Check', text: 'A font that fails to download keeps loading from Google, so no page loses its font.' },
    ],
  },
  'coverage-what': {
    id: 'coverage-what',
    title: 'Cache more than HTML pages',
    subtitle:
      'Page Cache serves anonymous HTML. Pro extends that to the requests that quietly cost you the most renders.',
    icon: 'Layers',
    mock: {
      kind: 'toggles',
      rows: [
        { label: '404 Caching', on: true, stat: '3,180 bot hits absorbed' },
        { label: 'Search Caching', on: true, stat: '/?s= results cached' },
      ],
    },
    points: [
      { icon: 'FileX', text: 'Serve one shared cached 404 so scanner floods stop costing a render each.' },
      { icon: 'Search', text: 'Cache internal search pages (/?s=…), keyed per term, purged on publish.' },
      { icon: 'ShieldCheck', text: 'Correct status codes preserved; auto-purge when you publish new content.' },
    ],
  },
  'coverage-beyond': {
    id: 'coverage-beyond',
    title: 'Cache feeds & API responses',
    subtitle:
      'Aggregators and headless front-ends hammer endpoints Page Cache never touches. Pro caches those too.',
    icon: 'Rss',
    mock: {
      kind: 'toggles',
      rows: [
        { label: 'Feed Caching (RSS / Atom)', on: true, stat: 'content-type preserved' },
        { label: 'REST API Cache', on: true, stat: 'per-route TTL' },
      ],
    },
    points: [
      { icon: 'Rss', text: 'Cache RSS / Atom / comment feeds so polling aggregators skip a full render.' },
      { icon: 'Webhook', text: 'Cache read-only REST routes with a TTL you set — built for headless sites.' },
      { icon: 'Lock', text: 'Authenticated and write requests are never cached.' },
    ],
  },
  'coverage-rules': {
    id: 'coverage-rules',
    title: 'Bypass & override by rule',
    subtitle:
      'Go past per-post toggles: shape caching with patterns, and stand down cleanly during maintenance.',
    icon: 'Filter',
    mock: {
      kind: 'code',
      code: [
        'BYPASS   /cart/*            (never cache)',
        'BYPASS   post_type=product  when logged in',
        'EXPIRE   /blog/*            override → 12h',
        'MAINT.   coming-soon mode   → skip + auto-purge on go-live',
      ],
    },
    points: [
      { icon: 'Filter', text: 'Bypass or override expiry by URL glob, post type, category, tag, or author.' },
      { icon: 'Wrench', text: 'Skip caching in maintenance / coming-soon mode, auto-purge when live again.' },
    ],
  },

  // ── Media Optimization · Image Optimization ─────────────────────────
  'media-formats': {
    id: 'media-formats',
    title: 'Modern image formats, automatically',
    subtitle:
      'Every new upload gets lighter WebP + AVIF siblings, served to the browsers that support them.',
    icon: 'Replace',
    mock: {
      kind: 'formats',
      badges: ['JPEG', 'WebP', 'AVIF'],
      headline: '−62%',
      headlineNote: 'typical transfer saved vs original JPEG',
    },
    points: [
      { icon: 'Replace', text: 'New JPEG/PNG uploads get .webp + .avif siblings generated automatically.' },
      { icon: 'Zap', text: 'Browsers that support modern formats get them; others fall back cleanly.' },
    ],
  },
  'media-smart': {
    id: 'media-smart',
    title: 'AI-tuned image settings',
    subtitle:
      'Let xSpeed recommend the format and quality for each image, then apply it into Image Optimization in one click.',
    icon: 'Sparkles',
    mock: {
      kind: 'toggles',
      rows: [
        { label: 'hero.jpg → AVIF @ 72', on: true, stat: '−240 KB' },
        { label: 'logo.png → WebP lossless', on: true, stat: '−31 KB' },
      ],
    },
    points: [
      { icon: 'Sparkles', text: 'AI recommends the best format + quality per image, not a blanket setting.' },
      { icon: 'Check', text: 'Review the suggestions, then apply them all with one click.' },
    ],
  },

  // ── Optimization · Compression ──────────────────────────────────────
  'compression-brotli': {
    id: 'compression-brotli',
    title: 'Brotli compression',
    subtitle:
      'Smaller than GZIP where the browser supports it — with automatic GZIP fallback everywhere else.',
    icon: 'Shrink',
    mock: {
      kind: 'chart',
      headline: '−21%',
      headlineNote: 'smaller responses vs GZIP (typical)',
      bars: [
        { label: 'None', value: 100 },
        { label: 'GZIP', value: 34 },
        { label: 'Brotli', value: 27 },
      ],
    },
    points: [
      { icon: 'Shrink', text: 'Serve Brotli where the browser and server support it — typically 15–25% smaller.' },
      { icon: 'ShieldCheck', text: 'Automatic GZIP fallback, so nothing breaks on older clients.' },
    ],
  },

  // ── Optimization · CSS & JavaScript ─────────────────────────────────
  'cssjs-critical': {
    id: 'cssjs-critical',
    title: 'Inline critical CSS, defer the rest',
    subtitle:
      'Send only the styles the first screen needs up front so the page paints without waiting on the full stylesheet.',
    icon: 'Sparkles',
    mock: {
      kind: 'chart',
      headline: '−48%',
      headlineNote: 'render-blocking CSS on first paint',
      bars: [
        { label: 'Before', value: 100 },
        { label: 'Critical', value: 52 },
      ],
    },
    points: [
      { icon: 'Sparkles', text: 'Inline above-the-fold CSS and defer the rest — cuts first paint 30–60%.' },
      { icon: 'Gauge', text: 'Pluggable generator endpoint; regenerates when your theme changes.' },
    ],
  },
  'cssjs-unused': {
    id: 'cssjs-unused',
    title: 'Strip the CSS a page never uses',
    subtitle:
      'Most themes ship one giant stylesheet for the whole site. Pro serves each URL only the rules it actually needs.',
    icon: 'Scissors',
    mock: {
      kind: 'chart',
      headline: '−71%',
      headlineNote: 'stylesheet bytes on a typical post',
      bars: [
        { label: 'Full CSS', value: 100 },
        { label: 'Pruned', value: 29 },
      ],
    },
    points: [
      { icon: 'Scissors', text: 'Per-URL pruned stylesheet inlined; original <link> tags stripped.' },
      { icon: 'Sparkles', text: 'Pairs with Critical CSS for the smallest possible first paint.' },
    ],
  },

  // ── Health · PageSpeed ──────────────────────────────────────────────
  'health-pagespeed': {
    id: 'health-pagespeed',
    title: 'Real scores, in your dashboard',
    subtitle:
      'Run Google PageSpeed on any URL and watch Core Web Vitals from real visitors — without leaving xSpeed.',
    icon: 'Gauge',
    mock: {
      kind: 'chart',
      headline: '98',
      headlineNote: 'PageSpeed score · mobile',
      bars: [
        { label: 'LCP', value: 92 },
        { label: 'CLS', value: 99 },
        { label: 'INP', value: 95 },
      ],
    },
    points: [
      { icon: 'Gauge', text: 'Run Google PSI on any URL — scores + Core Web Vitals, cached 24h.' },
      { icon: 'Eye', text: 'Real-User Monitoring: LCP / CLS / INP from actual visitors, per-URL percentiles.' },
      { icon: 'HeartPulse', text: 'Performance Health flags regressions against a 7-day baseline.' },
    ],
  },

  // ── Optimization · Resource Hints ───────────────────────────────────
  'hints-speculation': {
    id: 'hints-speculation',
    title: 'Prefetch the next page, before the click',
    subtitle:
      'xSpeed predicts where each visitor is headed and quietly prefetches it via Chrome’s Speculation Rules API — so the next page feels instant.',
    icon: 'Wand2',
    mock: {
      kind: 'toggles',
      rows: [
        { label: 'Prefetch on hover / viewport', on: true, stat: 'eager' },
        { label: 'Prerender top predicted page', on: true, stat: 'moderate' },
      ],
    },
    points: [
      { icon: 'Wand2', text: 'Predict each visitor’s next page and prefetch it via Speculation Rules.' },
      { icon: 'Zap', text: 'The next navigation feels instant — the page is already there.' },
    ],
  },

  // ── Network · Cloudflare ────────────────────────────────────────────
  'network-cloudflare': {
    id: 'network-cloudflare',
    title: 'Drive Cloudflare from here',
    subtitle:
      'Toggle Automatic Platform Optimization and edge cache without a second dashboard.',
    icon: 'Zap',
    mock: {
      kind: 'toggles',
      rows: [
        { label: 'Automatic Platform Optimization', on: true, stat: 'edge HTML cache' },
        { label: 'Browser cache TTL', on: true, stat: '4 hours' },
      ],
    },
    points: [
      { icon: 'Zap', text: 'Toggle APO, set the zone cache level and browser cache TTL from inside xSpeed.' },
      { icon: 'RefreshCw', text: 'Edge purge stays in sync with your local cache purges.' },
    ],
  },

  // ── Cache · Preloader ───────────────────────────────────────────────
  'preloader-scheduled': {
    id: 'preloader-scheduled',
    title: 'Warm the cache before the traffic',
    subtitle:
      'Schedule warm-ups around launches, sales, and scheduled posts so the spike hits a warm cache — never a cold render.',
    icon: 'CalendarClock',
    mock: {
      kind: 'toggles',
      rows: [
        { label: 'Nightly full-site warm', on: true, stat: '02:00' },
        { label: 'On scheduled-post publish', on: true, stat: 'auto' },
        { label: 'One-off: /black-friday', on: true, stat: 'Fri 08:00' },
      ],
    },
    points: [
      { icon: 'CalendarClock', text: 'Warm target URLs ahead of a post, sale, or campaign going live.' },
      { icon: 'RefreshCw', text: 'One-off, recurring, and auto-warm when a scheduled post publishes.' },
    ],
  },
  'preloader-predictive': {
    id: 'preloader-predictive',
    title: 'Keep the hottest pages always warm',
    subtitle:
      'Let xSpeed rank your URLs by real traffic, recency, and content, and warm the ones most likely to be hit next.',
    icon: 'TrendingUp',
    mock: {
      kind: 'toggles',
      rows: [
        { label: '/pricing', on: true, stat: 'rank #1 · warm' },
        { label: '/blog/new-release', on: true, stat: 'rank #2 · warm' },
        { label: '/features', on: true, stat: 'rank #3 · warm' },
      ],
    },
    points: [
      { icon: 'TrendingUp', text: 'Proactively warm the pages most likely to be visited next.' },
      { icon: 'BarChart3', text: 'Ranked by real traffic, recency, and content — no manual list to maintain.' },
    ],
  },

  // ── Health · Visitors (RUM) ─────────────────────────────────────────
  'health-visitors': {
    id: 'health-visitors',
    title: 'Measure real visitors, not lab tests',
    subtitle:
      'A lightweight beacon samples Core Web Vitals from your actual visitors’ browsers — the numbers Google ranks on.',
    icon: 'Eye',
    mock: {
      kind: 'chart',
      headline: 'p75',
      headlineNote: 'real-user Core Web Vitals',
      bars: [
        { label: 'LCP', value: 88 },
        { label: 'CLS', value: 96 },
        { label: 'INP', value: 91 },
      ],
    },
    points: [
      { icon: 'Eye', text: 'Sample LCP / FCP / CLS / INP / TTFB from real visitors, per-URL percentiles.' },
      { icon: 'BarChart3', text: 'See which pages actually feel slow — powered by the data you already collect.' },
    ],
  },
};

export function proDemoFor(id: string): ProDemo | undefined {
  return PRO_DEMOS[id];
}
