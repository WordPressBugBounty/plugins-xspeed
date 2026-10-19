/**
 * The notice-CTA deep-link contract (issue #49).
 *
 * Before this, a notice could only say "go to the Cache panel" — the user
 * still had to find the right control and work out what value to put in it.
 * Every CTA now carries the destination AND the target control AND, where
 * there is one, the recommended value:
 *
 *   #cache                                  panel only
 *   #health/pagespeed                       panel + tab
 *   #cache?focus=cache:cache_expiry          panel + scroll/blink that field
 *   #cache?focus=cache:cache_expiry&suggest=24
 *                                           …and offer that value
 *
 * The `focus` value is fully qualified (`<module>:<field>`) because the
 * anchor it resolves to is, and because a host page can render fields
 * belonging to more than one module.
 *
 * PHP builds the same strings (see includes/class-deep-link.php). The two
 * are kept honest by tests on both sides rather than by a shared schema —
 * so if you change the format, change it in three places, not two.
 *
 * Pure: no DOM, no navigation. Callers decide what to do with the result.
 */

export interface DeepLinkTarget {
  /** Module slug — which panel opens. */
  module: string;
  /** Optional tab within a host page. */
  subtab?: string;
  /** `<module>:<field>` of the control to scroll + blink. */
  focus?: string;
  /** Recommended value to offer for `focus`. */
  suggest?: string | number | boolean;
}

/** Build the `#…` string for a CTA's href. */
export function buildDeepLink(target: DeepLinkTarget): string {
  if (!target.module) return '#';

  let hash = `#${target.module}`;
  if (target.subtab) hash += `/${target.subtab}`;

  const params: string[] = [];
  if (target.focus) params.push(`focus=${encodeURIComponent(target.focus)}`);
  if (target.suggest !== undefined && target.suggest !== null) {
    params.push(`suggest=${encodeURIComponent(String(target.suggest))}`);
  }
  if (params.length > 0) hash += `?${params.join('&')}`;

  return hash;
}

/**
 * Read a hash back into its parts.
 *
 * Tolerant on purpose: a hand-typed or truncated hash must still select the
 * panel it names rather than dropping the user somewhere unrelated. Junk in
 * the query half is dropped, not fatal.
 */
export function parseDeepLink(raw: string): DeepLinkTarget & { suggest?: string } {
  const cleaned = (raw || '').replace(/^#/, '');
  const [pathPart = '', queryPart = ''] = cleaned.split('?');
  const [module = '', subtab = ''] = pathPart.split('/');

  const out: DeepLinkTarget & { suggest?: string } = { module };
  if (subtab) out.subtab = subtab;

  for (const pair of queryPart.split('&')) {
    if (!pair) continue;
    const eq = pair.indexOf('=');
    if (eq < 0) continue;
    const key = pair.slice(0, eq);
    const value = decodeURIComponent(pair.slice(eq + 1));
    if (key === 'focus' && value) out.focus = value;
    // An empty suggest is dropped: "" is not a recommendation, and offering
    // to blank a setting because a link was malformed would be destructive.
    if (key === 'suggest' && value !== '') out.suggest = value;
  }

  return out;
}

/**
 * Split a `<module>:<field>` focus token.
 *
 * Returns null for anything that isn't both halves — a half-formed anchor
 * should silently not focus, never focus the wrong control.
 */
export function splitFocus(focus: string | undefined): { module: string; field: string } | null {
  if (!focus) return null;
  const idx = focus.indexOf(':');
  if (idx <= 0 || idx === focus.length - 1) return null;
  return { module: focus.slice(0, idx), field: focus.slice(idx + 1) };
}
