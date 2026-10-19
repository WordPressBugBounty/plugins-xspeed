/**
 * Thin i18n wrapper. Uses WordPress's bundled `wp.i18n` package
 * (loaded via wp_set_script_translations) at runtime when available,
 * falls back to identity so tests + standalone Storybook-style
 * environments don't blow up.
 *
 * Why not import from '@wordpress/i18n':
 *  - The npm package re-bundles ~10 KB of code we don't need —
 *    WordPress already serves it as `wp-i18n` script.
 *  - We rely on Free's enqueue declaring `wp-i18n` as a dependency
 *    (see class-admin.php). Pro inherits it for free.
 *
 * POT extraction: `wp i18n make-pot` CANNOT see these strings —
 * it parses .js/.jsx only, every file here is .ts/.tsx, and the built
 * bundle is minified past recognition. make-pot covers the PHP half;
 * `npm run i18n:js` (scripts/i18n-extract-js.mjs) covers this half and
 * merges into the same languages/xspeed.pot. Run BOTH after changing
 * user-facing copy — see docs/guides/build-pipeline.md.
 *
 * Because the extractor reads string LITERALS, a call built from a
 * template literal or a variable is silently untranslatable. It reports
 * those as skips; keep the first argument a plain quoted string.
 */

interface WpI18n {
  __: (text: string, domain?: string) => string;
  _x: (text: string, context: string, domain?: string) => string;
  _n: (single: string, plural: string, number: number, domain?: string) => string;
  sprintf: (format: string, ...args: unknown[]) => string;
}

const DOMAIN = 'xspeed';

function api(): WpI18n | null {
  if (typeof window === 'undefined') return null;
  const w = window as unknown as { wp?: { i18n?: WpI18n } };
  return w.wp?.i18n ?? null;
}

export function __(text: string, domain: string = DOMAIN): string {
  const i = api();
  return i ? i.__(text, domain) : text;
}

export function _x(text: string, context: string, domain: string = DOMAIN): string {
  const i = api();
  return i ? i._x(text, context, domain) : text;
}

export function _n(single: string, plural: string, number: number, domain: string = DOMAIN): string {
  const i = api();
  return i ? i._n(single, plural, number, domain) : number === 1 ? single : plural;
}

export function sprintf(format: string, ...args: unknown[]): string {
  const i = api();
  if (i) {
    return i.sprintf(format, ...args);
  }
  // Tiny fallback — handles %s and %d only. Real wp.i18n handles
  // positional + named tokens; we never use those in this dashboard.
  let n = 0;
  return format.replace(/%[sd]/g, () => String(args[n++] ?? ''));
}
