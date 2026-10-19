import { useState } from 'react';
import { Smartphone, X, Loader2, Check } from 'lucide-react';
import type { MobileSeparateStatus, MobileEqualityProbe } from '../types';
import { api } from '../api/client';
import { __ } from '../i18n';

/**
 * Dashboard callout for Separate Mobile Cache (FBS-83145). Shows when the
 * setting is blocking the device-blind static fast path — most often because
 * a migration from WP Rocket / WP Super Cache / LiteSpeed imported it as ON by
 * habit, even though the site serves identical HTML to every device.
 *
 * Two things the user can do from here:
 *   1. "Check now" — an on-demand server-side probe fetches the homepage with a
 *      desktop and a mobile User-Agent, normalizes per-request noise, and
 *      reports whether the HTML actually differs. If identical, mobile-separate
 *      is unnecessary and turning it off restores the ~6-17x faster static path.
 *   2. Open Cache settings to turn it off, or dismiss the prompt.
 *
 * Sibling of RewriteAlert — same amber banner tokens + dark: pairing per
 * DESIGN.md. The probe NEVER auto-runs; it's behind the button only.
 */
export function MobileSeparateAlert({
  mobile,
  onOpenCacheSettings,
}: {
  mobile: MobileSeparateStatus | null | undefined;
  onOpenCacheSettings: () => void;
}) {
  const [dismissed, setDismissed] = useState(false);
  const [probing, setProbing] = useState(false);
  const [probe, setProbe] = useState<MobileEqualityProbe | null>(null);

  // Surface only when mobile_separate is actually keeping the static rewrite
  // from installing (blocking) OR migration flagged it for review.
  const visible = !!mobile && (mobile.blocking || mobile.needs_review);
  if (!visible || dismissed) return null;

  // Two OPPOSITE states share this banner, so the copy must match the state
  // (FBS-83144): `blocking` means mobile-separate is ON right now and disabling
  // the fast path — tell the user to turn it OFF. `needs_review` (post-migration)
  // means we deliberately kept it OFF; the source had it on, so prompt the user
  // to turn it ON *only if* their site genuinely serves device-specific HTML.
  // `blocking` wins when both are set (an active block is the more urgent state).
  const reviewOnly = !mobile!.blocking && mobile!.needs_review;

  const runProbe = async () => {
    setProbing(true);
    try {
      setProbe(await api.mobileEqualityProbe());
    } catch {
      setProbe({ identical: false, checked: false, reason: 'probe failed' });
    } finally {
      setProbing(false);
    }
  };

  const dismiss = async () => {
    setDismissed(true);
    try {
      await api.dismissMobileReview();
    } catch {
      // Non-fatal — the banner is gone for this session regardless.
    }
  };

  return (
    <div className="border border-amber-300 dark:border-amber-900/60 bg-amber-50 dark:bg-amber-950/30 rounded-lg p-4 sm:p-5 relative">
      <button
        type="button"
        onClick={dismiss}
        aria-label={__('Dismiss banner')}
        className="absolute top-2 right-2 p-1 rounded text-amber-700/70 hover:text-amber-900 dark:text-amber-300/70 dark:hover:text-amber-100 hover:bg-amber-100 dark:hover:bg-amber-900/40"
      >
        <X size={14} />
      </button>
      <div className="flex items-start gap-2.5 pr-6">
        <Smartphone size={16} className="shrink-0 mt-0.5 text-amber-600 dark:text-amber-400" />
        <div className="min-w-0 flex-1">
          <div className="font-medium text-amber-900 dark:text-amber-100 text-[13px]">
            {reviewOnly
              ? 'Separate Mobile Cache was left off after import'
              : 'Separate Mobile Cache is disabling faster static serving'}
          </div>
          <p className="text-[12px] text-amber-800/90 dark:text-amber-200/80 mt-1">
            {reviewOnly ? (
              <>
                Your previous plugin had Separate Mobile Cache on, but xSpeed kept
                it <strong>off</strong> so it can use the device-blind server-level
                rewrite (5&ndash;15ms static hits) instead of the slower PHP path
                (~85ms). Most sites serve the same HTML to every device and don&rsquo;t
                need it. Only turn it on if your site serves device-specific pages
                &mdash; not sure? Check whether your desktop and mobile pages actually
                differ.
              </>
            ) : (
              <>
                Separate Mobile Cache is on, so xSpeed can&rsquo;t use the device-blind
                server-level rewrite (5&ndash;15ms static hits) and falls back to the
                slower PHP path (~85ms). If your site serves the same HTML to every
                device, turn it off for much faster cache hits. Not sure? Check whether
                your desktop and mobile pages actually differ.
              </>
            )}
          </p>

          {probe && probe.checked && (
            <div
              className={
                'mt-2.5 text-[12px] rounded-md px-2.5 py-2 ' +
                (probe.identical
                  ? 'bg-emerald-100/70 dark:bg-emerald-950/40 text-emerald-900 dark:text-emerald-200'
                  : 'bg-amber-100/70 dark:bg-amber-900/40 text-amber-900 dark:text-amber-100')
              }
            >
              {probe.identical ? (
                <span className="inline-flex items-center gap-1.5">
                  <Check size={13} className="shrink-0" />
                  {reviewOnly
                    ? 'Desktop and mobile HTML are identical — leaving Separate Mobile Cache off is correct; nothing to change.'
                    : 'Desktop and mobile HTML are identical — you can safely turn Separate Mobile Cache off to regain the fast static path.'}
                </span>
              ) : (
                <>
                  Desktop and mobile HTML differ
                  {probe.desktop_bytes && probe.mobile_bytes
                    ? ` (${probe.desktop_bytes.toLocaleString()} vs ${probe.mobile_bytes.toLocaleString()} bytes)`
                    : ''}
                  {' '}&mdash; your site serves device-specific pages, so{' '}
                  {reviewOnly
                    ? 'you should turn Separate Mobile Cache on.'
                    : 'keeping Separate Mobile Cache on is correct.'}
                  {probe.differing_pages && probe.differing_pages.length > 0 && (
                    <span className="block mt-1">
                      Pages that differ by device: {probe.differing_pages.join(', ')}
                    </span>
                  )}
                </>
              )}
            </div>
          )}
          {probe && !probe.checked && (
            <div className="mt-2.5 text-[12px] text-amber-800/80 dark:text-amber-200/70">
              Couldn&rsquo;t complete the check{probe.reason ? ` (${probe.reason})` : ''}. Try again shortly.
            </div>
          )}

          <div className="mt-3 flex flex-wrap items-center gap-3">
            <button
              type="button"
              onClick={runProbe}
              disabled={probing}
              className="inline-flex items-center gap-1.5 text-[12px] font-medium text-amber-900 dark:text-amber-100 underline underline-offset-2 hover:text-amber-700 dark:hover:text-amber-300 disabled:opacity-60 disabled:no-underline"
            >
              {probing && <Loader2 size={12} className="animate-spin" />}
              {probing ? 'Checking…' : 'Check now'}
            </button>
            <button
              type="button"
              onClick={onOpenCacheSettings}
              className="text-[12px] font-medium text-amber-900 dark:text-amber-100 underline underline-offset-2 hover:text-amber-700 dark:hover:text-amber-300"
            >
              Open Cache settings &rarr;
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
