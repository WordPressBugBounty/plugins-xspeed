/**
 * Scroll-to-field + blink, for search-result clicks (FBS-83633).
 *
 * A search result can match a specific setting inside a panel (or a tab
 * inside a host page). After navigating to that panel/tab, we want to
 * scroll the exact field into view and flash it so the eye lands on it.
 *
 * The catch: the target field may not be in the DOM yet at click time —
 * the panel has to mount, and a host page may need to switch tabs first.
 * So `requestFieldScroll` records a pending target and retries across a
 * few animation frames until the field's anchor
 * (`[data-field-anchor="<slug>:<fieldKey>"]`) appears, then scrolls +
 * blinks it. If it never appears (e.g. a Pro field on Free), it gives up
 * quietly after the retry budget.
 */

let pending: string | null = null;
let rafId = 0;

const BLINK_CLASS = 'xspeed-field-blink';
const MAX_TRIES = 40; // ~0.7s at 60fps — covers panel mount + tab switch

function attempt(anchor: string, tries: number): void {
  if (pending !== anchor) return; // superseded by a newer request
  const el = document.querySelector<HTMLElement>(
    `[data-field-anchor="${cssEscape(anchor)}"]`,
  );
  if (el && el.offsetParent !== null) {
    pending = null;
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    // Restart the animation even if the class is already present.
    el.classList.remove(BLINK_CLASS);
    // Force reflow so removing + re-adding re-triggers the keyframes.
    void el.offsetWidth;
    el.classList.add(BLINK_CLASS);
    const clear = () => el.classList.remove(BLINK_CLASS);
    el.addEventListener('animationend', clear, { once: true });
    window.setTimeout(clear, 1500); // fallback if animationend doesn't fire
    return;
  }
  if (tries < MAX_TRIES) {
    rafId = window.requestAnimationFrame(() => attempt(anchor, tries + 1));
  } else {
    pending = null;
  }
}

/**
 * Request a scroll+blink to `<moduleSlug>:<fieldKey>`. Safe to call
 * before the panel has rendered — it retries until the anchor mounts.
 */
export function requestFieldScroll(moduleSlug: string, fieldKey: string): void {
  if (typeof window === 'undefined') return;
  const anchor = `${moduleSlug}:${fieldKey}`;
  pending = anchor;
  window.cancelAnimationFrame(rafId);
  rafId = window.requestAnimationFrame(() => attempt(anchor, 0));
}

// CSS.escape isn't universal in every embedded webview; fall back to a
// minimal escaper for the characters our anchors can contain (`:` `-`).
function cssEscape(value: string): string {
  if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
    return CSS.escape(value);
  }
  return value.replace(/["\\]/g, '\\$&');
}
