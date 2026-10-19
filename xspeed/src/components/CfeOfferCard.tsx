import { useRef, useState, type CSSProperties, type FocusEvent } from 'react';
import { X } from 'lucide-react';
import { __ } from '../i18n';
import { CFE_ADDON_PERIOD, CFE_ADDON_PRICE } from '../upsell/proFeatures';
import { PartnerMark } from './PartnerMark';
import { useRailForm } from './railItems';

export const CFE_PAGE_HREF = '#/network/cloudflare-enterprise';

/*
 * The partners' own colours, as tints: xSpeed teal into Cloudflare orange.
 * Literals for the reason PartnerMark's are: they belong to the logo, and the
 * rail is dark in both themes, so nothing here follows the theme (§24.49).
 */
const CARD_STYLE: CSSProperties = {
  background: 'linear-gradient(160deg, rgba(42,167,160,.14), rgba(244,129,32,.10))',
  borderColor: 'rgba(255,255,255,.08)',
};
const TILE_STYLE: CSSProperties = {
  background: 'linear-gradient(160deg, rgba(42,167,160,.18), rgba(244,129,32,.14))',
  borderColor: 'rgba(255,255,255,.10)',
  boxShadow: '0 0 0 2px rgba(244,129,32,.55)',
};
const ROW_STYLE: CSSProperties = {
  background: 'rgba(244,129,32,.08)',
  borderColor: 'rgba(244,129,32,.24)',
};
const POPOVER_STYLE: CSSProperties = {
  background: '#15211F',
  borderColor: 'rgba(255,255,255,.10)',
  boxShadow: '0 12px 32px rgba(0,0,0,.35)',
};
const CF_ORANGE = '#F48120';
const RAIL_GROUND = '#0F1A19';

const ring = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/40';

/**
 * The figure the add-on reported (its module descriptor's `addon_price`,
 * from the store), else the one typed here. Never empty: the card is an
 * offer, and an offer without a price is a question.
 */
export function offerPrice(price?: string | null): string {
  return typeof price === 'string' && price.trim() !== '' ? price.trim() : CFE_ADDON_PRICE;
}

function Price({ size, price }: { size: 'sm' | 'md'; price?: string | null }) {
  return (
    <span className={`whitespace-nowrap text-rail-text ${size === 'md' ? 'text-[12px]' : 'text-[11.5px]'}`}>
      <span className={`font-mono font-medium text-white ${size === 'md' ? 'text-[14px]' : 'text-[13px]'}`}>
        {offerPrice(price)}
      </span>{' '}
      {__('/domain/mo')}
    </span>
  );
}

/**
 * Opens the add-on's own page in the dashboard, in the same tab. That page
 * explains the add-on and has the purchase step, so nobody lands on the
 * store before they have seen what they are buying.
 */
function GetIt({ size }: { size: 'sm' | 'md' }) {
  return (
    <a
      href={CFE_PAGE_HREF}
      className={`shrink-0 inline-flex items-center rounded-control font-semibold no-underline bg-primary text-on-primary hover:bg-primary-hover ${ring} ${
        size === 'md' ? 'h-[30px] px-3 text-[12.5px]' : 'h-7 px-[11px] text-[12px]'
      }`}
    >
      {__('Get it')}
    </a>
  );
}

/**
 * The Cloudflare Enterprise offer, drawn in whichever form the rail has room
 * for (DESIGN.md §24.49). Free's own offer and the add-on's card both render
 * this, so the two cannot drift apart; they differ only in where a dismissal
 * is kept.
 *
 * The card and the tier slot keep their height and the navigation above them
 * shrinks and scrolls, so at no size does the card cover a nav row.
 */
export function CfeOfferCard({ onDismiss, price }: { onDismiss: () => void; price?: string | null }) {
  const form = useRailForm();

  if (form === 'icon') return <CfeOfferTile price={price} />;

  if (form === 'row') {
    return (
      <a
        data-cfe-form="row"
        href={CFE_PAGE_HREF}
        className={`flex items-center gap-2.5 h-[38px] px-3 rounded-control border text-[12.5px] font-medium text-white no-underline hover:brightness-125 ${ring}`}
        style={ROW_STYLE}
      >
        <PartnerMark height={14.5} />
        <span className="flex-1 min-w-0 truncate">{__('Cloudflare Enterprise')}</span>
      </a>
    );
  }

  const dismissLabel = __('Dismiss the Cloudflare Enterprise offer');

  if (form === 'compact') {
    return (
      <div
        data-cfe-form="compact"
        className="relative flex flex-col gap-2 rounded-[10px] border px-3 py-2.5"
        style={CARD_STYLE}
      >
        <button
          type="button"
          onClick={onDismiss}
          aria-label={dismissLabel}
          className={`absolute -top-2 -right-2 inline-flex items-center justify-center w-5 h-5 p-0 rounded-full border border-white/[.12] bg-[#182523] text-rail-text hover:text-white ${ring}`}
        >
          <X size={10} aria-hidden="true" />
        </button>
        <a href={CFE_PAGE_HREF} className={`flex items-center gap-2 min-w-0 no-underline rounded ${ring}`}>
          <PartnerMark height={14.5} />
          <span className="truncate text-[12.5px] font-semibold text-white">{__('Cloudflare Enterprise')}</span>
        </a>
        <div className="flex items-center justify-between gap-2">
          <Price size="sm" price={price} />
          <GetIt size="sm" />
        </div>
      </div>
    );
  }

  return (
    <div
      data-cfe-form="full"
      className="relative flex flex-col gap-2.5 rounded-card border p-3.5"
      style={CARD_STYLE}
    >
      <button
        type="button"
        onClick={onDismiss}
        aria-label={dismissLabel}
        className={`absolute top-2 right-2 inline-flex items-center justify-center w-6 h-6 rounded-control text-rail-text hover:text-white hover:bg-white/[.06] ${ring}`}
      >
        <X size={13} aria-hidden="true" />
      </button>
      <PartnerMark height={25} />
      <a href={CFE_PAGE_HREF} className={`flex flex-col gap-[3px] no-underline rounded ${ring}`}>
        <span className="text-[13.5px] font-semibold text-white">{__('Cloudflare Enterprise')}</span>
        <span className="text-[12px] leading-[1.45] text-rail-text">
          {__('Serve your whole site from Cloudflare’s network.')}
        </span>
      </a>
      <div className="flex items-center justify-between gap-2">
        <Price size="md" price={price} />
        <GetIt size="md" />
      </div>
    </div>
  );
}

/**
 * The icon form: a tile in the rail, with the whole offer in a popover on
 * hover or keyboard focus. Clicking the tile opens the add-on's page, which is
 * also what a touch screen gets, since it has no hover.
 *
 * The popover is placed against the add-on column, which is `relative` and as
 * wide as the rail, so it opens 12px past the rail's edge in a collapsed rail
 * and in an expanded one alike. It stays a DOM child of the hover area, which
 * spans the column, and its left padding starts where that area ends, so the
 * pointer can travel to "Get it" without crossing a gap that closes it.
 */
function CfeOfferTile({ price }: { price?: string | null }) {
  const [open, setOpen] = useState(false);
  const wrap = useRef<HTMLDivElement>(null);

  const onBlur = (e: FocusEvent) => {
    if (!wrap.current?.contains(e.relatedTarget as Node | null)) setOpen(false);
  };

  return (
    <div
      ref={wrap}
      data-cfe-form="icon"
      className="w-full"
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
      onFocus={() => setOpen(true)}
      onBlur={onBlur}
      onKeyDown={(e) => {
        if (e.key === 'Escape') setOpen(false);
      }}
    >
      <a
        href={CFE_PAGE_HREF}
        aria-label={`${__('Cloudflare Enterprise add-on')}, ${offerPrice(price)} ${CFE_ADDON_PERIOD}`}
        className={`relative flex items-center justify-center w-10 h-10 rounded-[10px] border no-underline ${ring}`}
        style={TILE_STYLE}
      >
        <PartnerMark height={16.7} />
        <span
          aria-hidden="true"
          className="absolute -top-[3px] -right-[3px] w-2 h-2 rounded-full border-2"
          style={{ background: CF_ORANGE, borderColor: RAIL_GROUND }}
        />
      </a>
      {open && (
        <div className="absolute bottom-3 left-[calc(100%-12px)] z-50 pl-6">
          <div
            role="group"
            aria-label={__('Cloudflare Enterprise')}
            className="relative flex w-[228px] flex-col gap-2.5 rounded-card border p-3 text-rail-text"
            style={POPOVER_STYLE}
          >
            <span
              aria-hidden="true"
              className="absolute -left-[6px] bottom-[15px] w-2.5 h-2.5 rotate-45 border-l border-b"
              style={{ background: POPOVER_STYLE.background, borderColor: POPOVER_STYLE.borderColor }}
            />
            <a href={CFE_PAGE_HREF} className={`flex items-center gap-2 no-underline rounded ${ring}`}>
              <PartnerMark height={14.5} />
              <span className="text-[13px] font-semibold text-white">{__('Cloudflare Enterprise')}</span>
            </a>
            <span className="text-[12px] leading-[1.45]">{__('Serve your whole site from Cloudflare’s network.')}</span>
            <div className="flex items-center justify-between gap-2">
              <Price size="md" price={price} />
              <GetIt size="md" />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
