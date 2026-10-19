import { useState, type ReactNode } from 'react';
import { Info, AlertTriangle, AlertCircle, CheckCircle2, Copy, Check, ArrowRight } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { buildDeepLink } from './deepLink';
import type { NoticeAction } from '../types';

export type CalloutTone = 'info' | 'warn' | 'danger' | 'success';

interface ToneSpec {
  border: string;
  bg: string;
  iconColor: string;
  titleColor: string;
  bodyColor: string;
  preBorder: string;
  preBg: string;
  preText: string;
  copyBtnBorder: string;
  copyBtnBg: string;
  copyBtnText: string;
  copyBtnHover: string;
  Icon: LucideIcon;
}

const TONES: Record<CalloutTone, ToneSpec> = {
  info: {
    border: 'border-line',
    bg: 'bg-info-tint',
    iconColor: 'text-info',
    titleColor: 'text-on-tint',
    bodyColor: 'text-ink-soft',
    preBorder: 'border-line',
    preBg: 'bg-paper',
    preText: 'text-ink',
    copyBtnBorder: 'border-line-strong',
    copyBtnBg: 'bg-paper',
    copyBtnText: 'text-primary',
    copyBtnHover: 'hover:bg-panel',
    Icon: Info,
  },
  warn: {
    border: 'border-amber-200 dark:border-amber-900/60',
    bg: 'bg-amber-50 dark:bg-amber-950/40',
    iconColor: 'text-amber-600 dark:text-amber-400',
    titleColor: 'text-amber-900 dark:text-amber-200',
    bodyColor: 'text-amber-800/90 dark:text-amber-200/80',
    preBorder: 'border-amber-200 dark:border-amber-900/60',
    preBg: 'bg-white dark:bg-neutral-900',
    preText: 'text-neutral-800 dark:text-neutral-200',
    copyBtnBorder: 'border-amber-300 dark:border-amber-800',
    copyBtnBg: 'bg-white dark:bg-neutral-900',
    copyBtnText: 'text-amber-800 dark:text-amber-200',
    copyBtnHover: 'hover:bg-amber-100 dark:hover:bg-amber-900/40',
    Icon: AlertTriangle,
  },
  danger: {
    border: 'border-red-200 dark:border-red-900/60',
    bg: 'bg-red-50 dark:bg-red-950/40',
    iconColor: 'text-danger',
    titleColor: 'text-red-900 dark:text-red-200',
    bodyColor: 'text-red-800/90 dark:text-red-200/80',
    preBorder: 'border-red-200 dark:border-red-900/60',
    preBg: 'bg-white dark:bg-neutral-900',
    preText: 'text-neutral-800 dark:text-neutral-200',
    copyBtnBorder: 'border-red-300 dark:border-red-800',
    copyBtnBg: 'bg-white dark:bg-neutral-900',
    copyBtnText: 'text-red-800 dark:text-red-200',
    copyBtnHover: 'hover:bg-red-100 dark:hover:bg-red-900/40',
    Icon: AlertCircle,
  },
  success: {
    border: 'border-emerald-200 dark:border-emerald-900/60',
    bg: 'bg-emerald-50 dark:bg-emerald-950/40',
    iconColor: 'text-emerald-600 dark:text-emerald-400',
    titleColor: 'text-emerald-900 dark:text-emerald-200',
    bodyColor: 'text-emerald-800/90 dark:text-emerald-200/80',
    preBorder: 'border-emerald-200 dark:border-emerald-900/60',
    preBg: 'bg-white dark:bg-neutral-900',
    preText: 'text-neutral-800 dark:text-neutral-200',
    copyBtnBorder: 'border-emerald-300 dark:border-emerald-800',
    copyBtnBg: 'bg-white dark:bg-neutral-900',
    copyBtnText: 'text-emerald-800 dark:text-emerald-200',
    copyBtnHover: 'hover:bg-emerald-100 dark:hover:bg-emerald-900/40',
    Icon: CheckCircle2,
  },
};

export interface CalloutProps {
  tone: CalloutTone;
  title: string;
  body?: ReactNode;
  snippet?: string;
  /**
   * Optional CTA (issue #49). Rendered as a deep link that lands on the
   * control the notice is about — with the recommended value offered on
   * it when the notice supplies one.
   */
  action?: NoticeAction;
}

export function Callout({ tone, title, body, snippet, action }: CalloutProps) {
  const t = TONES[tone];
  const [copied, setCopied] = useState(false);
  const copy = async () => {
    if (!snippet) return;
    await navigator.clipboard.writeText(snippet);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  };

  return (
    <div className={`border ${t.border} ${t.bg} rounded-lg p-4 sm:p-5`}>
      <div className="flex items-start gap-2.5">
        <t.Icon size={16} className={`${t.iconColor} shrink-0 mt-0.5`} />
        <div className="min-w-0 flex-1">
          <div className={`font-medium ${t.titleColor} text-[13px]`}>{title}</div>
          {body && <p className={`text-[12px] ${t.bodyColor} mt-1`}>{body}</p>}
          {snippet && (
            <div className="mt-3 relative">
              <pre
                className={`text-[11px] font-mono ${t.preBg} border ${t.preBorder} rounded p-3 overflow-x-auto whitespace-pre ${t.preText}`}
              >
                {snippet}
              </pre>
              <button
                type="button"
                onClick={copy}
                className={`absolute top-2 right-2 flex items-center gap-1 text-[11px] px-2 py-1 rounded border ${t.copyBtnBorder} ${t.copyBtnBg} ${t.copyBtnText} ${t.copyBtnHover} transition-colors`}
              >
                {copied ? <Check size={11} /> : <Copy size={11} />}
                {copied ? 'Copied' : 'Copy'}
              </button>
            </div>
          )}
          {action && (
            <a
              href={buildDeepLink(action)}
              className={`mt-3 inline-flex items-center gap-1.5 text-[12px] px-2.5 py-1 rounded border ${t.copyBtnBorder} ${t.copyBtnBg} ${t.copyBtnText} ${t.copyBtnHover} transition-colors`}
            >
              {action.label}
              <ArrowRight size={12} />
            </a>
          )}
        </div>
      </div>
    </div>
  );
}
