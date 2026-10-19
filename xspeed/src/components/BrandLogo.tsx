import { useEffect, useState } from 'react';
import iconUrl from '../icon.svg';
import { useBranding } from '../hooks/useBranding';

/**
 * Brand mark for the dashboard chrome + onboarding. The artwork itself, its
 * geometry, and the `currentColor` contract this component's invert classes
 * depend on are spec'd in DESIGN.md §24.28 — read that before changing either
 * `icon.svg`. Renders the
 * white-label logo when set (`branding.logo_svg`), else the built-in
 * xSpeed mark. logo_svg may be an image URL or an inline `<svg>` string;
 * both are supported. Falls back to the static icon on anything else.
 * (FBS white-label-onboarding)
 */
export function BrandLogo({
  className = 'w-6 h-6 rounded shrink-0',
  onDark = false,
  tone,
}: {
  className?: string;
  /**
   * True when the mark sits on a surface that is dark in BOTH themes — the nav
   * rail (DESIGN.md §2.4) is the only one today. Such a surface can't use the
   * theme-keyed `dark:invert` below: in light mode nothing inverts, so the
   * mark rasterizes black onto a black rail and the logo half-disappears.
   *
   * Don't set this for a surface that merely happens to be dark right now —
   * a theme-following surface still wants `dark:invert`.
   */
  onDark?: boolean;
  /**
   * `primary` paints the built-in mark in the brand teal, for a tile that
   * names the product rather than framing the chrome (the purchase path's
   * Pro step, DESIGN.md §24.42). An <img> cannot take a colour, so the mark
   * becomes the mask of a primary-filled span. A white-label logo keeps its
   * own colours.
   */
  tone?: 'primary';
}) {
  const branding = useBranding();
  const logo = branding.logo_svg?.trim();
  const alt = branding.name || 'xSpeed Cache';

  // A configured logo URL can later 404 (the media attachment was deleted),
  // which without a fallback paints a broken-image icon — a non-empty URL is
  // always truthy, so the default-mark branch below is never reached. Track a
  // load error and fall back to the bundled xSpeed mark. Reset when the logo
  // changes so a corrected URL is retried. (FBS-82222)
  const [imgFailed, setImgFailed] = useState(false);
  useEffect(() => {
    setImgFailed(false);
  }, [logo]);

  if (logo && !imgFailed) {
    // Inline SVG markup — render verbatim (Pro sanitizes before save).
    if (logo.startsWith('<svg')) {
      return (
        <span
          className={`inline-flex items-center justify-center ${className}`}
          role="img"
          aria-label={alt}
          // eslint-disable-next-line react/no-danger
          dangerouslySetInnerHTML={{ __html: logo }}
        />
      );
    }
    // Otherwise treat it as an image URL (data: or http(s):). On a load
    // failure, swap to the built-in mark instead of a broken image.
    return <img src={logo} alt={alt} className={className} onError={() => setImgFailed(true)} />;
  }

  // Default xSpeed mark. icon.svg is `fill="currentColor"`, which an <img>
  // can't inherit — it always rasterizes BLACK — so it needs inverting to show
  // up on a dark ground.
  //
  // `dark:invert` is right for a surface that follows the theme. It is wrong
  // for one that is dark in both (the rail), where the invert has to be
  // unconditional: keyed to the theme, light mode left a black mark on the
  // black rail. Note the two can't be combined — `invert dark:invert` applies
  // the filter twice in dark mode and lands back on black.
  //
  // The white-label image path above is deliberately untouched either way, so
  // an agency's own logo is never inverted.
  if (tone === 'primary') {
    const mask = `url("${iconUrl}") center / contain no-repeat`;
    return (
      <span
        role="img"
        aria-label={alt}
        className={`inline-block bg-primary ${className}`}
        style={{ WebkitMask: mask, mask }}
      />
    );
  }

  return (
    <img
      src={iconUrl}
      alt={alt}
      className={`${className} ${onDark ? 'invert' : 'dark:invert'}`}
    />
  );
}
