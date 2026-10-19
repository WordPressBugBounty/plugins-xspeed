/**
 * Small display helpers for the Overview. Every number that reaches the screen
 * goes through one of these so we never leak float artifacts or raw byte counts.
 */

/** Integer with thousands separators, e.g. 1204 → "1,204". */
export function fmtInt(n: number | undefined | null): string {
  return Math.round(Number(n ?? 0)).toLocaleString();
}

/**
 * Hit ratio as a whole-number percent. Accepts either a 0–1 fraction or an
 * already-0–100 value (Cache::get_stats has shipped both shapes over time).
 */
export function fmtPercent(ratio: number | undefined | null): string {
  const r = Number(ratio ?? 0);
  const pct = r <= 1 ? r * 100 : r;
  return `${Math.round(pct)}%`;
}

/** Coarse relative time from a unix timestamp (seconds), e.g. "3h ago". */
export function relativeTime(ts: number): string {
  const secs = Math.max(0, Math.floor(Date.now() / 1000) - Number(ts || 0));
  if (secs < 60) return 'just now';
  const mins = Math.floor(secs / 60);
  if (mins < 60) return `${mins}m ago`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours}h ago`;
  const days = Math.floor(hours / 24);
  if (days < 30) return `${days}d ago`;
  const months = Math.floor(days / 30);
  return `${months}mo ago`;
}

/** Human byte size, e.g. 5_242_880 → "5 MB". */
export function fmtBytes(bytes: number | undefined | null): string {
  const b = Number(bytes ?? 0);
  if (b <= 0) return '0 KB';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.min(units.length - 1, Math.floor(Math.log(b) / Math.log(1024)));
  const value = b / Math.pow(1024, i);
  // Whole numbers for B/KB, one decimal for MB+.
  const rounded = i >= 2 ? Math.round(value * 10) / 10 : Math.round(value);
  return `${rounded} ${units[i]}`;
}
