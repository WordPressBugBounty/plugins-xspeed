import { __, _n, sprintf } from '../i18n';
import { formatBytes } from './formatBytes';
import type { PurgeReport } from '../types';

export type PurgeOutcome = 'cleared' | 'empty' | 'partial' | 'failed';

export interface PurgeNotice {
  outcome: PurgeOutcome;
  message: string;
  /** A store that refused interrupts, the same register as the blocked-cache refusal. */
  urgent: boolean;
  ms: number;
}

/** End a sentence with a full stop unless the text already ends in punctuation. */
const asSentence = (text: string) => (/[.!?]$/.test(text.trim()) ? text.trim() : `${text.trim()}.`);

/**
 * Turn the per-store purge report into the one toast the dashboard shows.
 *
 * The report already says which stores were cleared, skipped or refused, and
 * why. A blanket "Cache purged" hid a refused Cloudflare purge behind a success
 * message, so a store that failed is named with its reason. Skipped stores
 * (not enabled) are left out: they are not an outcome of this click.
 */
export function purgeNotice(report?: PurgeReport | null): PurgeNotice {
  const rows = report?.types ? Object.entries(report.types) : [];
  if (rows.length === 0) {
    return { outcome: 'cleared', message: __('Cache purged'), urgent: false, ms: 2000 };
  }

  const page = report?.types.page;
  const pageCleared = page?.status === 'cleared';
  const otherCleared = rows
    .filter(([slug, row]) => slug !== 'page' && row.status === 'cleared')
    .map(([, row]) => row.label);
  const failed = rows.filter(([, row]) => row.status === 'failed').map(([, row]) => row);

  if (failed.length > 0) {
    const reasons = failed
      .map((row) =>
        asSentence(
          sprintf(
            /* translators: 1: cache store name, e.g. "Cloudflare edge"; 2: why its purge failed. */
            __('%s: %s'),
            row.label,
            row.reason.trim() || __('the purge was refused')
          )
        )
      )
      .join(' ');
    return pageCleared || otherCleared.length > 0
      ? { outcome: 'partial', message: `${__('Cache partly cleared.')} ${reasons}`, urgent: true, ms: 10000 }
      : { outcome: 'failed', message: `${__('Purge failed.')} ${reasons}`, urgent: true, ms: 10000 };
  }

  const also = otherCleared.length > 0 ? sprintf(__('Also cleared: %s.'), otherCleared.join(', ')) : '';

  // A filter can skip or drop the page store; then only the other stores speak.
  if (!pageCleared) {
    return otherCleared.length > 0
      ? { outcome: 'cleared', message: sprintf(__('Cleared: %s.'), otherCleared.join(', ')), urgent: false, ms: 4000 }
      : { outcome: 'empty', message: __('There was nothing to clear.'), urgent: false, ms: 4000 };
  }

  const entries = page.entries;
  if (entries === 0 && otherCleared.length === 0) {
    return {
      outcome: 'empty',
      message: __('The cache was already empty, so there was nothing to clear.'),
      urgent: false,
      ms: 4000,
    };
  }

  let message: string;
  if (entries === 0) {
    message = __('The page cache was already empty.');
  } else if (entries === null) {
    message = __('Cache cleared.');
  } else if (page.bytes) {
    message = sprintf(
      /* translators: 1: number of files removed; 2: their size, e.g. "79.1 KB". */
      _n('Cache cleared: %d file (%s).', 'Cache cleared: %d files (%s).', entries),
      entries,
      formatBytes(page.bytes)
    );
  } else {
    message = sprintf(
      /* translators: %d: number of files removed. */
      _n('Cache cleared: %d file.', 'Cache cleared: %d files.', entries),
      entries
    );
  }
  return { outcome: 'cleared', message: also ? `${message} ${also}` : message, urgent: false, ms: 4000 };
}
