import type { Settings, Status, ToggleResult, OnboardingChoices, OnboardingApplyResult, ModuleDescriptor, Branding, MobileEqualityProbe, Recommendation, ProSuggestion, ActivityEntry, PurgeReport, PreloaderState } from '../types';

const cfg = window.XSpeedConfig;

/**
 * Joins a path, with any query of its own, onto the REST root.
 *
 * With plain permalinks the root is `…/index.php?rest_route=/xspeed/v1`, and
 * a language plugin can add an arg of its own (`&lang=fr`) to either shape.
 * Appended as-is, the path's `?limit=1` became part of the route and
 * WordPress answered 404. The route goes after the root's route instead,
 * and the path's query joins the root's with `&`.
 */
function endpoint(path: string): string {
  const root = cfg.restUrl;
  const q = path.indexOf('?');
  const route = q === -1 ? path : path.slice(0, q);
  const query = q === -1 ? '' : path.slice(q + 1);
  const at = root.indexOf('?');
  if (at === -1) return root + path;
  const restRoute = /[?&]rest_route=[^&#]*/.exec(root);
  const end = restRoute ? restRoute.index + restRoute[0].length : at;
  const base = root.slice(0, end) + route + root.slice(end);
  return query ? `${base}&${query}` : base;
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(endpoint(path), {
    ...init,
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': cfg.nonce,
      ...(init.headers || {}),
    },
    credentials: 'same-origin',
  });
  if (!res.ok) throw new Error(await errorMessage(res));
  return res.json();
}

/**
 * The server's own explanation, when it sent one.
 *
 * Every non-2xx used to become `HTTP <status>`, which threw away the one
 * thing the user needed. A fresh install has the preloader off, so Preload
 * answers 409 "Enable the preloader before starting a crawl." — precise,
 * actionable, and discarded in favour of "Preload could not start". The same
 * blindness hides an expired nonce (403) behind a generic failure when the
 * fix is simply to reload the page. (#256)
 *
 * WP REST errors are `{ code, message, data: { status } }`. Falls back to
 * `HTTP <status>` when the body is empty, not JSON, or carries no message —
 * a 502 from a proxy never reaches PHP and has no envelope to read.
 */
async function errorMessage(res: Response): Promise<string> {
  const fallback = `HTTP ${res.status}`;
  try {
    const body = await res.json();
    const message = typeof body?.message === 'string' ? body.message.trim() : '';
    return message || fallback;
  } catch {
    return fallback;
  }
}

export const api = {
  /**
   * Escape hatch for ad-hoc module routes that don't fit a typed
   * helper (Preloader's /start /stop /status, HealthCard's payload
   * fetch). Same auth + JSON envelope as everything else.
   */
  request: <T>(path: string, init: RequestInit = {}) => request<T>(path, init),

  getStatus: () => request<Status>('/status'),
  getSettings: () => request<Settings>('/settings'),
  /**
   * Live module descriptors — re-fetched after a license activate/
   * deactivate so Pro panels unlock/relock without a page reload. Same
   * shape as window.XSpeedConfig.bootstrap.modules.
   */
  getModules: () => request<ModuleDescriptor[]>('/modules'),
  /**
   * Resolved white-label branding — re-fetched after a white-label save so
   * the dashboard chrome updates live without a reload.
   */
  getBranding: () => request<Branding>('/branding'),
  updateSettings: (data: Partial<Settings>) =>
    request<Settings>('/settings', {
      method: 'POST',
      body: JSON.stringify(data),
    }),
  toggleCache: (enabled: boolean) =>
    request<ToggleResult>('/cache/toggle', {
      method: 'POST',
      body: JSON.stringify({ enabled }),
    }),
  purge: () => request<{ stats: Status['stats']; report?: PurgeReport }>('/cache/purge', { method: 'POST' }),
  /**
   * Kick off the cache preloader crawl (Overview hero quick action). Reuses
   * the Preloader module's existing /preloader/start route — no new endpoint.
   *
   * Typed as the full preloader state, which is what the route returns. It
   * used to be typed `{ started: boolean }` — a field the endpoint has never
   * sent — so callers could not tell a real crawl from one that queued
   * nothing, and the header toasted "Preload started" for both. (#256, #142)
   */
  preload: () => request<PreloaderState>('/preloader/start', { method: 'POST' }),

  /** Ranked "next best action" recommendations for the Overview zone 3. */
  /**
   * `contributed: true` adds the entries other plugins hand in. Only the
   * Overview card asks; the cache page's next best action stays native.
   */
  getRecommendations: (opts: { contributed?: boolean } = {}) =>
    request<{ recommendations: Recommendation[] }>(
      opts.contributed ? '/recommendations?include=contributed' : '/recommendations',
    ),
  /** One-click apply of an 'apply'-type recommendation; returns the refreshed list. */
  applyRecommendation: (id: string) =>
    request<{ applied: string; recommendations: Recommendation[] }>('/recommendations/apply', {
      method: 'POST',
      body: JSON.stringify({ id }),
    }),
  /** Pro-audit findings (Free only) folded into the Overview recommendations zone. */
  getProAudit: () => request<{ suggestions: ProSuggestion[] }>('/audit/pro'),
  /** Recent activity-log entries for the Overview activity strip (zone 5). */
  getActivity: () => request<{ activity: ActivityEntry[] }>('/activity'),
  /**
   * On-demand desktop-vs-mobile HTML equality probe (FBS-83145). POST so it
   * only runs from the "Check now" button, never a prefetch.
   */
  mobileEqualityProbe: () =>
    request<MobileEqualityProbe>('/cache/mobile-probe', { method: 'POST' }),
  /** Dismiss the Separate-Mobile-Cache review prompt. */
  dismissMobileReview: () =>
    request<{ dismissed: boolean }>('/cache/mobile-review-dismiss', { method: 'POST' }),
  onboardingApply: (choices: OnboardingChoices) =>
    request<OnboardingApplyResult>('/onboarding/apply', {
      method: 'POST',
      body: JSON.stringify(choices),
    }),
  onboardingComplete: () =>
    request<{ ok: boolean }>('/onboarding/complete', { method: 'POST' }),

  /**
   * Per-module settings API. Every Module that declares a settings_schema
   * gets a paired GET + POST under /xspeed/v1/<slug>/ via Rest_Manager.
   */
  getModuleSettings: <T = Record<string, unknown>>(slug: string) =>
    request<T>(`/${slug}/`),
  updateModuleSettings: <T = Record<string, unknown>>(
    slug: string,
    patch: Record<string, unknown>
  ) =>
    request<T>(`/${slug}/`, {
      method: 'POST',
      body: JSON.stringify(patch),
    }),
};
