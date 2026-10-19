import type { Status } from '../types';
import { __ } from '../i18n';

export type DropinState = NonNullable<Status['dropin']>;

/**
 * The words shown before xSpeed takes over wp-content/advanced-cache.php.
 *
 * Shared by every control that can turn page caching on — the top-bar
 * CacheSwitch and Overview's CacheHero — so the two cannot drift into telling
 * the user different things about the same file. Returns null when there is
 * nothing to disclose, which is also the signal to enable immediately.
 *
 * Why only the replace case earns a prompt: a clean install writes a file
 * nobody else wants, and a live foreign drop-in never reaches a confirm at
 * all (the server refuses it and the dashboard reports `blocked_reason`).
 * Prompting on those too would be three prompts of which one matters, and the
 * user would learn to click through all three.
 */
export function dropinNotice(dropin: DropinState | null | undefined): {
  title: string;
  body: string;
  confirmLabel: string;
} | null {
  if (!dropin?.replaceable) return null;

  // Name the owner whenever the file can be attributed. "xSpeed will take
  // over from WP Rocket" tells the user exactly what changes; the unnamed
  // sentence is the honest fallback for a file no signature matches, and
  // inventing a name for it would be worse than the vaguer wording.
  if (dropin.label) {
    return {
      title: __('Take over the page cache from %s?').replace('%s', dropin.label),
      body: __(
        '%s currently owns the page-cache file. xSpeed will replace it and serve the cached pages itself.'
      ).replace('%s', dropin.label),
      confirmLabel: __('Take over and enable'),
    };
  }

  return {
    title: __('Replace the existing cache file?'),
    body: __(
      'Another page-cache file is already in wp-content. xSpeed will replace it with its own to serve cached pages.'
    ),
    confirmLabel: __('Replace and enable'),
  };
}
