import { BookOpen, LifeBuoy, Star } from 'lucide-react';
import { __ } from '../../i18n';

/** External resource links for the Overview footer strip. */
const DOCS_URL = 'https://xspeedcache.com/docs/';
const SUPPORT_URL = 'https://xspeedcache.com/support/';
const REVIEW_URL = 'https://wordpress.org/support/plugin/xspeed/reviews/#new-post';

/**
 * Zone 5 (footer slice) — a quiet Docs / Support / Review strip. Sits below the
 * recent-activity list.
 */
export function OverviewFooter() {
  const linkCls =
    'inline-flex items-center gap-2 text-ink-soft hover:text-ink transition-colors';

  return (
    <section
      className="flex flex-wrap items-center gap-x-6 gap-y-2 pt-2 text-[13px]"
      aria-label={__('Help and resources')}
    >
      <a href={DOCS_URL} target="_blank" rel="noopener noreferrer" className={linkCls}>
        <BookOpen size={14} aria-hidden="true" />
        {__('Documentation')}
      </a>
      <a href={SUPPORT_URL} target="_blank" rel="noopener noreferrer" className={linkCls}>
        <LifeBuoy size={14} aria-hidden="true" />
        {__('Help and support')}
      </a>
      <a href={REVIEW_URL} target="_blank" rel="noopener noreferrer" className={linkCls}>
        <Star size={14} aria-hidden="true" />
        {__('Review us on WordPress.org')}
      </a>
    </section>
  );
}
