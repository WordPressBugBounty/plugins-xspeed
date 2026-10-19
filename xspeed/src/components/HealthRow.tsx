import type { ReactNode } from 'react';

export type HealthTone = 'ok' | 'warn' | 'fail' | 'info';

const dotTone: Record<HealthTone, string> = {
  ok: 'bg-success',
  warn: 'bg-warning',
  fail: 'bg-danger',
  info: 'bg-line-strong',
};

export function HealthRow({
  tone,
  label,
  labelAfter,
  detail,
  trailing,
  first = false,
}: {
  tone: HealthTone;
  label: string;
  // Optional inline element rendered right after the label text (e.g. an
  // InfoPopover (i) trigger), so it reads as part of the heading.
  labelAfter?: ReactNode;
  detail?: ReactNode;
  trailing?: ReactNode;
  first?: boolean;
}) {
  return (
    <div
      className={`flex items-start gap-3 px-4 sm:px-5 py-3.5 ${
        first ? '' : 'border-t border-line'
      }`}
    >
      <span className={`w-2 h-2 rounded-full mt-1.5 shrink-0 ${dotTone[tone]}`} />
      <div className="min-w-0 flex-1">
        <div className="inline-flex items-center gap-1.5 text-[13px] font-medium text-ink">
          <span>{label}</span>
          {labelAfter}
        </div>
        {detail !== undefined && detail !== null && detail !== '' && (
          <div className="text-[12px] text-muted mt-0.5">{detail}</div>
        )}
      </div>
      {trailing && <div className="shrink-0">{trailing}</div>}
    </div>
  );
}
