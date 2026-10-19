import { useEffect } from 'react';

/**
 * Let a full-screen overlay out of WordPress's stacking context.
 *
 * Our overlays live inside `#xspeed-app` — they cannot portal to
 * `document.body`, because Tailwind here is built with
 * `important: '#xspeed-app'`, so a node outside that ancestor gets NO styling
 * at all. (That is exactly how the Modal shipped broken once.)
 *
 * The cost of staying inside is `#wpbody`, which WordPress gives
 * `position: sticky`. A positioned ancestor forms a stacking context, and no
 * z-index on a descendant can escape it — so WP's admin menu (`z-index: 9990`)
 * and its version footer paint straight over the scrim. Measured with the MCP
 * drawer open, `elementFromPoint(80, 400)` returned an admin-menu link instead
 * of the scrim; the command palette showed the same thing at `z-[100]`.
 *
 * So we neutralise that one ancestor while an overlay is open.
 *
 * ...but neutralising it MOVES THE PAGE. An earlier version of this hook
 * claimed `sticky` "only governs #wpbody's own scroll behaviour, which is not
 * in play while background scroll is locked". That was wrong. On a page
 * scrolled away from the top, sticky is actively holding #wpbody at its
 * stuck offset; dropping to `static` returns it to flow and the content
 * lurches upward by exactly the scroll distance. Measured at scrollY 113:
 * #wpbody's top went 32 → -81, and the background visibly broke behind the
 * open drawer.
 *
 * `position: relative` is not a fix either — it still resolves the element to
 * its in-flow position (top −49 in the same measurement), just without
 * collapsing margins. What restores the layout is compensating for the delta:
 * measure the element's top before and after, then offset it by the
 * difference. `relative` + that offset reproduces where `sticky` had it, while
 * no longer trapping z-index (a stacking context needs a positioned ancestor
 * WITH a z-index, and we set none).
 *
 * Reference-counted, because overlays nest: the palette can open a modal. A
 * naive per-overlay save/restore would let the inner one's cleanup put the
 * original values back while the outer overlay is still on screen. Only the
 * first opener records, and only the last closer restores. (#283)
 */
let depth = 0;
let previousPosition: string | null = null;
let previousTop: string | null = null;

export function useEscapeWpStackingContext(open: boolean): void {
  useEffect(() => {
    if (!open) return;
    const wpbody = document.getElementById('wpbody');
    if (!wpbody) return;

    if (depth === 0) {
      previousPosition = wpbody.style.position;
      previousTop = wpbody.style.top;

      // Where sticky is holding it right now.
      const stuckTop = wpbody.getBoundingClientRect().top;

      // Where it lands once the stacking context is gone.
      wpbody.style.position = 'static';
      const flowTop = wpbody.getBoundingClientRect().top;

      // Put it back where it looked, without re-forming a stacking context:
      // `relative` with no z-index does not create one.
      const delta = stuckTop - flowTop;
      wpbody.style.position = 'relative';
      wpbody.style.top = delta ? `${delta}px` : '';
    }
    depth += 1;

    return () => {
      depth -= 1;
      if (depth === 0) {
        wpbody.style.position = previousPosition ?? '';
        wpbody.style.top = previousTop ?? '';
        previousPosition = null;
        previousTop = null;
      }
    };
  }, [open]);
}
