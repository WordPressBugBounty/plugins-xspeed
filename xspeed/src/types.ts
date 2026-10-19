export type Section = 'cache' | 'performance' | 'settings';

/**
 * Legacy settings stored in the single `xspeed_options` blob. Now down
 * to one field: cache_enabled, which stays here because flipping it
 * triggers the advanced-cache.php drop-in install + WP_CACHE constant
 * edit (Cache::toggle owns the drop-in lifecycle). Every other former
 * field is now in per-module storage:
 *   - minify_html / minify_css / minify_js → xspeed_module_minify
 *   - gzip_enabled                         → xspeed_module_gzip
 *   - cache_expiry / excluded_urls         → xspeed_module_cache
 */
export interface Settings {
  cache_enabled: boolean;
}

export interface Stats {
  cached_pages: number;
  cache_size: number;
  last_purge: number;
  // 24h rolling cache performance (added Phase 2.1 — sourced from
  // Hit_Counter). Optional in the type to keep existing tests happy
  // and let any old payload still parse.
  hits_24h?: number;
  misses_24h?: number;
  hit_ratio?: number;
  // Requests kept OUT of the ratio (404s + bots), reported separately, and
  // whether an edge cache (Cloudflare) fronts the origin so the ratio is
  // origin-layer only. (#118)
  excluded_24h?: number;
  edge_cache?: boolean;
  // LiteSpeed Static Fast Path is on (#509): hits are served by the web
  // server with no PHP, no X-XSpeed-Cache header, and no counting — so the
  // ratio is PHP-layer only and the dashboard must say so instead of
  // looking broken.
  static_hits_uncounted?: boolean;
  /** Why static hits are not counted: 'litespeed' or 'apache_static' (#760). */
  static_hits_uncounted_reason?: '' | 'litespeed' | 'apache_static';
  /**
   * Whether the page cache is actually serving, as opposed to switched on
   * in settings. The two come apart when another plugin owns
   * advanced-cache.php or WP_CACHE is not what we need it to be — and the
   * hero used to read `cache_enabled` alone and claim the cache was
   * "serving cached HTML" on a site serving none of it.
   *
   * Optional so an older payload still parses; treat `undefined` as "no
   * verdict" and fall back to the setting rather than showing a warning.
   */
  page_cache_serving?: boolean;
  /** Why not — present only while enabled and not serving. */
  page_cache_blocked_reason?: string | null;
}

export interface ServerInfo {
  type: 'apache' | 'litespeed' | 'nginx' | 'iis' | 'unknown';
  gzip_mode: 'auto' | 'manual';
  /**
   * null = the loopback probe reached no verdict (blocked, TLS failure,
   * timeout). Not evidence gzip is off — see Gzip::probe_active().
   */
  gzip_active: boolean | null;
  nginx_snippet: string;
}

/**
 * What the nginx rules running on the server are, compared to the rules the
 * current settings describe.
 *
 * The snippet carries `add_header X-XSpeed-Rules "<hash8>" always;` inside its
 * static location; the rewrite probe's loopback GET reads it back. `hash8` is
 * the first 8 hex of sha1 over the snippet with that marker line removed — a
 * hash taken over text containing itself has no solution.
 *
 *   current  — observed marker equals the hash the current settings produce.
 *   stale    — a marker is there, from an older settings state.
 *   absent   — the static path served the file with no marker at all
 *              (rules predating the marker, or no rules pasted).
 *   unknown  — the probe reached no verdict; fall back to the hash the user
 *              last copied.
 *
 * Every key is always sent. "Nothing to report" is an empty string on the
 * hashes, an empty array on `changed` and null on `copied` — never a missing
 * key, so there is no shape here the block has to render around.
 */
export interface RewriteRules {
  /** hash8 the current settings produce, '' when no snippet is generated. */
  expected: string;
  /** hash8 read back from the server, '' when the probe saw no marker. */
  observed: string;
  state: 'current' | 'stale' | 'unversioned' | 'absent' | 'unknown';
  /**
   * Setting names the installed rules predate, from the diff of the two
   * hashes' input sets. Empty when the hashes match and when the map cannot
   * resolve them. The `stale` line is only worth its space when it can name
   * them — see DESIGN.md §24.43.
   */
  changed: string[];
  /**
   * User-meta mirror of what the user last copied, so a user who copied the
   * block on another device still gets a verdict when `state` is `unknown`.
   * Null when nobody has claimed a paste. The key's PRESENCE (even as null)
   * is how the server advertises that it stores the mirror — NginxServerBlock
   * only posts a copy back when it is present.
   */
  copied: { hash: string; at: number } | null;
}

export interface RewriteProbe {
  active: boolean;
  /**
   * No probe has run yet (the dashboard never pays for the loopback
   * request). Not evidence of anything — the banner must stay hidden.
   */
  pending?: boolean;
  /**
   * The probe ran but reached no verdict: blocked loopback, TLS failure,
   * timeout, a CDN/WAF answering instead of the origin. Distinct from
   * `active: false`, which means we positively proved PHP served the file.
   * Only a proven-inactive result should ask the user to configure their
   * server — otherwise we nag people whose nginx is already correct.
   */
  inconclusive?: boolean;
  /** Human-readable probe outcome, surfaced in Health for diagnosis. */
  reason?: string;
  /**
   * Why the static rewrite is refused, when we already know: `mobile_separate`,
   * `no_mod_headers`, or `litespeed_dropin` (the LiteSpeed default while the
   * Static Fast Path opt-in is off, #509). Empty when nothing is blocking.
   *
   * A refusal outranks the probe — probe_static_rewrite() fetches its own file
   * from the static tree, which succeeds even when no real page is served that
   * way. Clients should act on this rather than re-deriving the cause from
   * `reason`. See Cache::qualify_rewrite_probe().
   */
  block_reason?: string;
  server_type: ServerInfo['type'];
  snippet: string | null;
  /**
   * Where the user's snippet needs to be installed (or whether
   * .htaccess auto-install covers it). Drives the dashboard
   * banner's "paste this HERE" message — see Server::rewrite_topology().
   *   'htaccess'        — Apache / LiteSpeed; auto-installed.
   *   'nginx-host'      — paste into host nginx vhost.
   *   'nginx-container' — paste into container nginx config
   *                       (docker/nginx.conf), NOT host vhost.
   *   'unknown'         — IIS or undetected.
   */
  topology?: 'htaccess' | 'nginx-host' | 'nginx-container' | 'unknown';
  /**
   * True when WordPress sits behind a reverse proxy / CDN (X-Forwarded-*
   * headers present). On a managed/containerized host like this the nginx
   * that actually terminates requests is typically NOT one the user can
   * edit, so the static-rewrite snippet has nowhere useful to go — the
   * banner switches to honest "may not be available on this host" messaging
   * instead of dangling a snippet that can't help. See Server::is_behind_proxy().
   */
  behind_proxy?: boolean;
  /**
   * Whether the rules the web server is running are the rules the current
   * settings generate. The generated block stamps every static hit with a
   * hash of itself and the probe reads it back — the only way to know what
   * a user actually pasted into a server config WordPress cannot read.
   *
   *   current — the installed rules match these settings.
   *   stale   — a different version of the rules is installed; `changed`
   *             names the settings that moved since, where we can tell.
   *   absent  — the probe reached a verdict and saw no marker: no rules
   *             installed, or rules older than the marker.
   *   unknown — the probe could not tell, or this server has no rule set
   *             xSpeed generates.
   *
   * See Cache::rules_state(). Absent entirely on a probe that predates the
   * marker, and on non-nginx hosts.
   */
  rules?: RewriteRules | null;
}

/**
 * Separate Mobile Cache visibility block on /status (FBS-83145).
 *   enabled      — mobile_separate is currently on.
 *   blocking     — mobile_separate is what's stopping the device-blind
 *                  static fast path from installing on a rewrite-capable server.
 *   needs_review — a migration turned it on for us; the user hasn't confirmed
 *                  they actually need per-device HTML yet.
 */
export interface MobileSeparateStatus {
  enabled: boolean;
  blocking: boolean;
  needs_review: boolean;
}

/** Result of the on-demand desktop-vs-mobile HTML equality probe. */
export interface MobileEqualityProbe {
  identical: boolean;
  checked: boolean;
  reason?: string;
  desktop_bytes?: number;
  mobile_bytes?: number;
  /** Whether anything on the home page asked wp_is_mobile() while it rendered. (#618) */
  asked?: boolean;
  /** Paths the background check found serving phones different HTML. (#618) */
  differing_pages?: string[];
}

export interface Status {
  enabled: boolean;
  stats: Stats;
  server: ServerInfo;
  rewrite_probe?: RewriteProbe | null;
  mobile_separate?: MobileSeparateStatus | null;
  /**
   * Consolidated nginx server-block snippet aggregating directives from
   * every enabled module (Cache static-rewrite, BrowserCache headers,
   * GZIP, …). The unified-snippet panel renders this so the user can
   * paste once instead of N times. Null on non-nginx hosts or when no
   * module contributes directives.
   */
  nginx_server_block?: string | null;
  /**
   * What turning the page cache ON would do to wp-content/advanced-cache.php.
   *
   * `replaceable` is the only field that changes behavior: a leftover drop-in
   * is already on disk and enabling would overwrite it, so the switch
   * discloses that first instead of replacing someone's file on one click.
   * A drop-in we already own, and a live foreign one (refused server-side
   * with `blocked_reason`), both leave it false — there is nothing to warn
   * about in either. Optional: absent on an older bootstrap payload.
   */
  dropin?: {
    exists: boolean;
    replaceable: boolean;
    owner: string;
    label: string | null;
  } | null;
}

/** One store's row in the purge report (Purge_Runner::run_target). */
export interface PurgeStoreResult {
  label: string;
  group: string;
  status: 'cleared' | 'skipped' | 'failed';
  entries: number | null;
  bytes: number | null;
  reason: string;
}

/** What POST /cache/purge did, store by store (Purge_Runner::run). */
export interface PurgeReport {
  ok: boolean;
  cause: string;
  requested: string[];
  types: Record<string, PurgeStoreResult>;
}

export interface ToggleResult {
  enabled: boolean;
  /**
   * The enable was refused before anything was written — another caching
   * plugin owns wp-content/advanced-cache.php, or WP_CACHE is defined in a
   * shape xSpeed will not rewrite. `blocked_reason` is user-facing.
   */
  blocked: boolean;
  blocked_reason: string | null;
  stats: Stats;
  install_state: {
    enabled: boolean;
    blocked: boolean;
    blocked_reason: string | null;
    dropin_installed: boolean;
    wp_cache_constant: boolean;
    rewrite_installed: boolean;
    wp_config_writable: boolean;
    manual_snippet: string | null;
    nginx_snippet: string | null;
    // Unified server-block snippet aggregating every enabled module's
    // directives — identical to the dashboard's status.nginx_server_block
    // and the Health insight snippet. The wizard renders this via the
    // shared NginxServerBlock component so all three surfaces match.
    nginx_server_block: string | null;
  };
}

export interface OnboardingEnv {
  wp: { version: string; ok: boolean };
  php: { version: string; ok: boolean; modern: boolean };
  server: { type: ServerInfo['type']; gzip_mode: ServerInfo['gzip_mode'] };
  cache_dir: { path: string; writable: boolean };
  wp_config: { writable: boolean };
  permalinks_ok: boolean;
  conflicts: string[];
  /**
   * Why enabling the page cache would be refused right now, or null.
   *
   * Not the same question as `conflicts`, which is a list of plugins: an
   * orphaned or doubly-defined WP_CACHE refuses with nobody to name. Absent
   * on an older bootstrap payload.
   */
  page_cache_blocked?: string | null;
}

export interface OnboardingChoices {
  cache_enabled: boolean;
  minify_html: boolean;
  minify_css: boolean;
  minify_js: boolean;
  defer_js: boolean;
  gzip_enabled: boolean;
  lazy_images: boolean;
  browser_cache: boolean;
  /**
   * LCP image preload + font preconnect (Resource Hints module).
   *
   * Required, not optional. It was optional, and "omitting it leaves the
   * module's own default in place" was true of a fresh install but not of a
   * PRESET: applyPreset() spreads a preset over current state, so an omitted
   * key keeps whatever the previously selected preset set. Conservative
   * omitted it and therefore left LCP preload + preconnect ON, against a card
   * that reads "Page cache + GZIP only" — and no annotation could catch that
   * while the key was optional. (#438)
   */
  resource_hints: boolean;
  cache_expiry: number;
  /**
   * Consent to usage analytics, which include the admin's email and name.
   * Unticked in the wizard (default false, wordpress.org guideline 7, #735);
   * data only sends once the admin turns it on and clicks Apply, and it stays
   * reversible from the dashboard. See readme.txt.
   */
  usage_tracking: boolean;
}

export interface OnboardingApplyResult {
  settings: Settings;
  install_state: ToggleResult['install_state'];
}

/**
 * xSpeed Hub connection snapshot — the shape returned by `GET /mcp/hub` and
 * embedded in the onboarding bootstrap (`XSpeedConfig.bootstrap.hub`). Shared
 * by the MCP panel's Hub card and the onboarding Connect step so both render
 * from one contract. Mirrors Mcp_Hub::public_status() on the PHP side.
 */
export interface HubStatus {
  attached: boolean;
  account_email: string;
  attached_at: number;
  site_url: string;
  site_token: string;
  hub_url: string;
  add_site_url: string;
  /** One-click OAuth-attach redirect (Method 2) — carries a signed nonce + email. */
  attach_url: string;
  /**
   * True when this site isn't publicly reachable (local, dev or private).
   * The Hub refuses to attach such a site (xspeed-hub#370), so `attach_url`
   * is empty and every Connect surface shows `hubLocalMessage()` instead.
   */
  is_local?: boolean;
}

export type SchemaFieldType = 'bool' | 'int' | 'enum' | 'list' | 'url' | 'string' | 'media' | 'secret';

export interface SchemaField {
  type: SchemaFieldType;
  default?: unknown;
  /**
   * Never rendered and never offered by settings search. For settings that
   * remain part of the module's REST/CLI contract but have no dashboard
   * control — e.g. the score module's `enabled`, which the Test press
   * manages itself (#425).
   */
  hidden?: boolean;
  min?: number;
  max?: number;
  options?: string[];
  item_type?: 'string' | 'url' | 'path';
  /**
   * Human label override. If absent the ModulePanel derives one from the
   * key (snake_case → Title Case).
   */
  label?: string;
  /** One-line hint rendered as muted 12px text under the label. */
  description?: string;
  /**
   * Longer explanatory copy surfaced via a click (i) InfoPopover next to the
   * label — for help text too long for `description`. (DESIGN.md §24.7)
   */
  info?: string;
  /** Optional title shown bold at the top of the info popover. */
  info_title?: string;
  /**
   * Optional docs URL rendered as a "Check the documentation" link after
   * `description`. Descriptions are plain text (escaped), so a link has to
   * come through as its own key rather than inline HTML.
   */
  doc_url?: string;
  /** Optional per-option labels for enums (key = option value). */
  option_labels?: Record<string, string>;
  /**
   * Time unit for an `int` field, which turns the bare number box into a
   * duration field: the panel renders a live human-readable translation of
   * the current value beside it ("1800" → "= 30 minutes"), updating as the
   * user types. Fields carry different units — TTLs in seconds, Cache
   * Expiry in hours, Delay Failsafe in ms — so the unit has to be declared
   * rather than guessed from the label, which is translated. (#159)
   *
   * Omit for a plain count (Redis Port, Batch Size, quality percentages);
   * those have no human-readable form.
   */
  unit?: 'ms' | 'seconds' | 'minutes' | 'hours' | 'days';
  /**
   * Conditional visibility. The field only renders while its dependency
   * is satisfied by the CURRENT values of sibling fields in the same
   * module's schema. Three forms, all interchangeable wherever a
   * `dependsOn` is accepted (FBS-82227):
   *
   *   1. Single condition (the common case):
   *        { field: 'enabled' }                       // parent must be truthy
   *        { field: 'auth_method', value: 'token' }   // parent must === value
   *
   *   2. ALL of several conditions (AND):
   *        { all: [ {field:'enabled'}, {field:'auth_method', value:'token'} ] }
   *
   *   3. ANY of several conditions (OR):
   *        { any: [ {field:'defer_js'}, {field:'delay_js'} ] }
   *
   * Conditions nest — an entry inside `all`/`any` can itself be an
   * all/any group. The check is transitive (a field hides when a parent
   * it references is itself hidden) with a cycle guard. Hidden fields
   * keep their stored value — nothing is cleared — so flipping a parent
   * back on restores the child unchanged and no stale value is written.
   */
  dependsOn?: FieldDependency;
  /**
   * A setting most sites never change: a connection detail with a working
   * default, a batch size, a debug switch. The Hub folds these under
   * "Advanced · N settings" so a module opens on the settings that matter.
   * Never set on `enabled`, the module's main choice, or an exclusion list.
   */
  advanced?: boolean;
}

/** A single field/value condition, or an AND/OR group of conditions. */
export type FieldDependency =
  | { field: string; value?: unknown }
  | { all: FieldDependency[] }
  | { any: FieldDependency[] };

export interface ModuleNotice {
  tone: 'info' | 'warn' | 'danger' | 'success';
  title: string;
  body?: string;
  snippet?: string;
  /**
   * Optional CTA (issue #49). Every notice that names a control should
   * carry one — the point of the contract is that a notice never leaves
   * the user hunting for the setting it just told them about.
   */
  action?: NoticeAction;
}

export interface NoticeAction {
  label: string;
  /** Module panel to open. */
  module: string;
  /** Tab within a host page. */
  subtab?: string;
  /** `<module>:<field>` of the control to scroll to and blink. */
  focus?: string;
  /** Recommended value offered on that control, as a string. */
  suggest?: string;
}

/**
 * Provenance of one setting value: a wp-config.php constant (which wins over
 * everything and cannot be written from the UI), the stored option row, or
 * the schema default. (#398)
 */
export interface SettingOrigin {
  source: 'constant' | 'db' | 'default';
  /** The winning constant's name when `source` is 'constant'; null otherwise. */
  constant: string | null;
  /**
   * The constant this field is deliberately SHADOWING, when an admin took it
   * back from wp-config.php (#398). `source` is then 'db'/'default' like any
   * other editable field — this names what the override displaced, so the
   * panel can offer to hand it back.
   */
  overriding?: string | null;
}

export interface ModuleDescriptor {
  slug: string;
  tier: 'free' | 'pro';
  version: string;
  /**
   * Top-level mirror of `settings.enabled`, so the payload is
   * self-describing — consumers kept missing the state because it was only
   * readable from inside the settings bag. `null` for modules that have no
   * `enabled` key at all (status panels like Health), which is meaningfully
   * different from `false`. (#146)
   */
  enabled?: boolean | null;
  /**
   * Whether the module is actually DOING something — a different question
   * from `enabled`, which only describes modules that store that one key.
   * Page caching's switch lives in the global option, Minify and Lazy are on
   * when any of their flags is set, MCP when it is connected; each module
   * answers for itself server-side.
   *
   * Read this for "what has the user switched on?". `null` means the module
   * has no meaningful on/off (a status panel like Health) and must be
   * excluded from a count rather than treated as off. (#363)
   */
  active?: boolean | null;
  /**
   * One sentence explaining `active`, computed server-side beside it. Shown
   * behind an (i) next to the status pill — "On" is otherwise a bare
   * assertion the user cannot check, and it is least obvious exactly where
   * the rule is least visible (a module counted on by flags rather than by a
   * single switch). `null` means there is nothing worth explaining. (#363)
   */
  active_reason?: string | null;
  /**
   * What the customer still needs before the module can work, when that is
   * something to buy or activate (e.g. "Add-on needed"), or a setup step in
   * progress ("Setting up"). Replaces the On/Off pill in the page header.
   * `null` or absent: the pill shows On/Off.
   */
  status_label?: string | null;
  /**
   * A sold-separately add-on's own answer to "is there still something to
   * buy?" Read before `status_label` when present, so the add-on can label a
   * setup step ("Setting up") without the sidebar offering it again.
   */
  purchase_needed?: boolean;
  /**
   * What a sold-separately add-on costs, as the store says today and as the
   * offer prints it ("$5", "From $7.99"). Set by the add-on from the last
   * price it was told; absent on an older add-on or before any answer, and
   * the offer then falls back to its own figure.
   */
  addon_price?: string | null;
  /**
   * Why this module stands down right now, when something else on the site
   * has taken over its job. The reason is written for the site owner and says
   * what to do. The module reports off while it is set, whatever its own
   * switch says, and its settings are shown but cannot be changed.
   */
  blocked_by?: string | null;
  label: string;
  icon: string;
  description?: string;
  /**
   * The dashboard group the module belongs to, as declared in its PHP
   * `ui_metadata()`: cache, performance, network, insights, tools,
   * ai-agents or settings. Mirrors sidebarGroups.ts (a unit test holds the
   * two together); sent so the Hub can place a module without keeping its
   * own copy of this map. `null` or absent on older plugin versions.
   */
  group?: string | null;
  /**
   * Short label for this module's OWN tab when it hosts a tabbed page
   * (FBS-83633). The page title is `label` (e.g. "Compression"); the
   * base module's tab uses this shorter name (e.g. "GZIP"). Optional —
   * falls back to `label`.
   */
  tab_label?: string;
  settings: Record<string, unknown>;
  /**
   * Where each setting's value actually came from. A field resolved from a
   * wp-config.php constant renders read-only and names that constant, so the
   * panel never offers an editable box over a value the site is ignoring.
   * Absent on payloads from an older plugin version. (#398)
   */
  setting_origins?: Record<string, SettingOrigin>;
  schema: Record<string, SchemaField>;
  notices?: ModuleNotice[];
  /**
   * If set, ModulePanel skips schema-driven rendering and looks up this
   * key in the custom-panel registry (see src/components/customPanels.ts)
   * to render a module-specific React component. Used for read-only
   * surfaces (Health module's dashboard) and any future module where
   * the schema-driven UI doesn't fit.
   */
  custom_panel?: string;
  /**
   * True when this is a placeholder ("locked") row injected from the
   * Pro module manifest because Pro isn't installed. Sidebar greys
   * it out + adds a crown badge; ModulePanel renders LockedPanel
   * instead of the schema/custom flow. Never set by the PHP side.
   */
  locked?: boolean;
}

export interface Branding {
  name: string;
  footer_credit: string | null;
  hide_help_links: boolean;
  logo_svg: string | null;
}

/** A recommendation's action — either a deep-link or a one-click settings fix. */
export interface RecommendationAction {
  type: 'link' | 'apply';
  label: string;
  /**
   * Destination module for a `link` action, and the module to patch for an
   * `apply` one. Build the URL with `buildDeepLink()` — never by hand.
   *
   * This used to declare a `hash?: string` instead, which the server has never
   * sent: `Deep_Link::action()` emits `module` / `subtab` / `focus` / `suggest`.
   * Overview's Recommendations was written against that phantom field and
   * guarded on `action.hash`, so every link recommendation — including "Get the
   * snippet" — was a dead button, and the wrong type meant tsc couldn't say so.
   */
  module?: string;
  /** Tab within a host page. */
  subtab?: string;
  /** `<module>:<field>` of the control to scroll to and blink. */
  focus?: string;
  /** Recommended value offered on that control, as a string. */
  suggest?: string;
  /** type: 'apply' — schema-validated values to write. */
  values?: Record<string, unknown>;
}

/** A ranked "next best action" (GET /recommendations). */
export interface Recommendation {
  id: string;
  priority: number;
  title: string;
  detail: string;
  action: RecommendationAction;
  /**
   * A short label beside the title, such as "Add-on" (DESIGN.md §24.50).
   * Only entries other plugins contribute through `xspeed_recommendations`
   * carry one; Free's own rules never set it.
   */
  tag?: string;
}

/** A Pro-audit finding (GET /audit/pro → suggestions[]). */
export interface ProSuggestion {
  id: string;
  severity: 'high' | 'med' | 'low';
  reason: string;
  fact?: string;
}

/** A recent activity-log entry (GET /activity → activity[]). */
export interface ActivityEntry {
  ts: number;
  type: string;
  message: string;
  severity: 'info' | 'warn' | 'error' | 'success';
}

declare global {
  interface Window {
    XSpeedConfig: {
      mode?: 'dashboard' | 'onboarding';
      restUrl: string;
      nonce: string;
      version: string;
      dashboardUrl?: string;
      wizardUrl?: string;
      branding?: Branding;
      tier?: 'free' | 'pro' | 'trial';
      bootstrap?: {
        settings: Settings;
        status?: Status;
        env?: OnboardingEnv;
        modules?: ModuleDescriptor[];
        /**
         * The site's CURRENT value for every toggle the Setup Wizard can
         * write. Lets a re-run of the wizard show the site as it actually is
         * instead of seeding from preset constants and silently overwriting
         * what the admin configured. Absent on older payloads, in which case
         * the wizard falls back to the recommended profile.
         * See Onboarding::current_choices().
         */
        current?: Partial<OnboardingChoices> | null;
        /**
         * xSpeed Hub connection snapshot for the onboarding Connect step.
         * Null when the MCP module is unavailable — the step then fetches
         * live from /mcp/hub. See Onboarding::hub_payload().
         */
        hub?: HubStatus | null;
      };
    };
  }
}

/**
 * The cache preloader's crawl state, as `/preloader/start` and
 * `/preloader/status` both return it.
 *
 * Shared rather than declared per-consumer: the panel and the API client
 * disagreeing about this shape is what let `preload()` be typed
 * `{ started: boolean }` — a field the endpoint never sends — while the
 * panel read the real fields a few hundred lines away. (#256)
 */
export interface PreloaderState {
  running: boolean;
  started_at: number;
  finished_at: number;
  queue: string[];
  processed: number;
  total: number;
  last_url: string;
  /**
   * Per-URL warm failures are objects; a whole-crawl failure (an unreachable
   * sitemap) is recorded as a plain string, so consumers handle both.
   */
  errors: Array<{ url: string; error: string; ts: number } | string>;
  /**
   * How the queue was built: 'sitemap', 'fallback' (enumerated from site
   * content because the sitemap could not be fetched), or 'none'. (#142)
   */
  source?: 'sitemap' | 'fallback' | 'none';
  /** Why the sitemap fetch failed, or '' when it succeeded. (#142) */
  sitemap_error?: string;
  /**
   * URLs left out of the crawl, by reason: post_type, excluded_url,
   * filtered, over_cap. (#239)
   */
  skipped?: Record<string, number>;
  /** Public post types, for the exclusion list. Sent by /preloader/status. */
  post_types?: Array<{ name: string; label: string }>;
  /**
   * Which copies of each URL the crawl warms: 'desktop', plus 'mobile' when
   * Separate Mobile Cache keeps a phone copy. Absent on a crawl started
   * before the field existed. (#596)
   */
  devices?: Array<'desktop' | 'mobile'>;
}
