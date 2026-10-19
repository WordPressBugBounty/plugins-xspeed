/**
 * Human-readable durations for numeric settings fields (#159).
 *
 * A TTL box showing `604800` tells the user nothing. LiteSpeed Cache renders
 * a live translation beside the input, and it is the cheapest possible trust
 * signal: the setting was understood and accepted, before Save is pressed.
 * TTLs are also the settings users most often get wrong, and the ones where
 * being wrong is least visible — a bad TTL doesn't error, it just serves
 * stale pages for a week.
 *
 * Deliberately NOT `Intl.RelativeTimeFormat`: that renders a single unit
 * ("in 3 weeks"), while the useful reading here is the composite
 * ("2 weeks 6 days 20 hours") — the user is checking the magnitude they
 * typed, not reading a date.
 */

/** Units a numeric settings field can be expressed in. */
export type DurationUnit = 'ms' | 'seconds' | 'minutes' | 'hours' | 'days';

/** Multiplier to seconds for each supported unit. */
const TO_SECONDS: Record<DurationUnit, number> = {
  ms: 1 / 1000,
  seconds: 1,
  minutes: 60,
  hours: 3600,
  days: 86400,
};

/**
 * Descending ladder. Weeks is the largest rung deliberately: months and
 * years are not fixed-length, so "1 month" would be a lie for any exact
 * second count, and cache TTLs are rarely discussed in years anyway.
 */
const LADDER: Array<{ seconds: number; one: string; many: string }> = [
  { seconds: 604800, one: 'week', many: 'weeks' },
  { seconds: 86400, one: 'day', many: 'days' },
  { seconds: 3600, one: 'hour', many: 'hours' },
  { seconds: 60, one: 'minute', many: 'minutes' },
  { seconds: 1, one: 'second', many: 'seconds' },
];

/** How many ladder rungs to show. Three keeps it readable at any magnitude. */
const MAX_PARTS = 3;

/**
 * Render a value in `unit` as a human-readable duration, or '' when there is
 * nothing useful to say.
 *
 * Returns '' rather than "0 seconds" for empty / zero / negative input: a
 * blank box should read as blank, not as a duration of nothing. Sub-second
 * values also return '' — below the smallest rung there is no phrasing that
 * adds information over the number already on screen.
 */
export function formatDuration(value: number, unit: DurationUnit): string {
  if (!Number.isFinite(value) || value <= 0) return '';

  const totalSeconds = Math.floor(value * TO_SECONDS[unit]);
  if (totalSeconds < 1) return '';

  // Nothing to translate when the rendering would just echo the box. For a
  // SECONDS field that means anything under a minute: "18" already reads as
  // eighteen seconds, and the issue pins this — 18 renders nothing, 180
  // renders "3 minutes".
  //
  // A MILLISECOND field is the opposite case: 8000 is not readable at a
  // glance, and "8 seconds" is the whole point of the hint. Its floor is
  // therefore one second, not one minute. Coarser units (minutes, hours,
  // days) always have something to say and are never gated.
  if (unit === 'seconds' && totalSeconds < 60) return '';

  const parts: string[] = [];
  let remaining = totalSeconds;

  for (const rung of LADDER) {
    if (parts.length >= MAX_PARTS) break;
    const count = Math.floor(remaining / rung.seconds);
    if (count <= 0) continue;
    parts.push(`${count} ${count === 1 ? rung.one : rung.many}`);
    remaining -= count * rung.seconds;
  }

  return parts.join(' ');
}

/**
 * The hint shown under the input, e.g. "30 minutes".
 *
 * Deliberately NOT prefixed with the field's own unit. LiteSpeed renders
 * "seconds = 30 minutes" because its labels don't carry the unit; ours do
 * — every duration field is labelled "(seconds)" / "(hours)" / "(ms)" — so
 * repeating it puts the same word twice in one row and buries the part the
 * user is actually reading.
 *
 * Returns '' whenever formatDuration() does, so the caller renders an empty
 * line rather than a dangling fragment. Otherwise it ALWAYS renders — the
 * echo suppression this used to carry is gone.
 *
 * That rule read "30 minutes under a field labelled (minutes) showing 30 is
 * pure noise", which is true in isolation and wrong in practice. It compared
 * the formatted value against a hand-built `${value} ${unit}`, so at value 1
 * the singular "1 hour" never matched the plural "1 hours" and rendered,
 * while 2–23 were suppressed: on Page Cache — the plugin's most-edited
 * duration field — the ONE value that showed anything showed exactly the
 * echo the rule existed to remove, and every common TTL (2h, 6h, 12h)
 * showed nothing.
 *
 * Fixing the comparison would have made the blanks consistent, but the
 * blanks are the real problem: this hint exists to confirm the value was
 * understood, and no output is indistinguishable from a broken feature.
 * "6 hours" costs a line and is never wrong. (QA R1 on #167)
 */
export function durationHint(value: number, unit: DurationUnit): string {
  return formatDuration(value, unit);
}
