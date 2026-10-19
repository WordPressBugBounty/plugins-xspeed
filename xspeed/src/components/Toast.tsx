/**
 * A transient message, announced to screen readers.
 *
 * It renders on top of the page and disappears on a timer, so a user who is
 * not looking at that corner of the screen has one chance to receive it. QA
 * found the blocked-cache refusal — the message that explains why a switch
 * flipped back — going out with no live region at all, which means a screen
 * reader user got the flip and not the reason.
 *
 * `urgent` picks the register. A refusal interrupts, because the user just
 * acted and the outcome was not what they asked for; a save confirmation
 * waits for a pause. `aria-atomic` makes the whole sentence re-read when the
 * text changes, rather than only the words that differ.
 *
 * `sweep` drains a hairline under a result the user just caused (a purge). It
 * is decoration on top of the sentence, hidden from assistive tech, and the
 * reduced-motion rule leaves it undrawn.
 */
export function Toast({
  message,
  urgent = false,
  sweep = false,
  sweepKey,
}: {
  message: string;
  urgent?: boolean;
  sweep?: boolean;
  /** Change it to replay the hairline without remounting the live region. */
  sweepKey?: number;
}) {
  return (
    <div
      role={urgent ? 'alert' : 'status'}
      aria-live={urgent ? 'assertive' : 'polite'}
      aria-atomic="true"
      className="fixed bottom-6 left-1/2 -translate-x-1/2 overflow-hidden bg-neutral-900 dark:bg-neutral-50 text-white dark:text-neutral-900 text-[13px] px-4 py-2 rounded shadow-sm z-50"
    >
      {message}
      {sweep && (
        <span key={sweepKey} aria-hidden="true" className="xspeed-anim-drain absolute left-0 bottom-0 h-0.5 w-full bg-primary" />
      )}
    </div>
  );
}
