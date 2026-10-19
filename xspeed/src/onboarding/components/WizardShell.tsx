import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { Stepper, type StepId } from './Stepper';

export function WizardShell({
  step,
  hero,
  children,
  footer,
}: {
  step: StepId;
  hero: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <div className="max-w-3xl mx-auto my-6 sm:my-10 bg-paper border border-line rounded-card overflow-hidden flex flex-col">
      {/* Every step shows the numbered rail: Review → Configure → Connect →
          Done. Connect was previously a pre-wizard gate that hid the stepper;
          now that it runs after Configure it's an ordinary step. */}
      <Stepper current={step} />
      {hero}
      <div className="px-4 sm:px-6 py-2 space-y-4">{children}</div>
      {footer && (
        <div className="border-t border-line px-4 sm:px-6 py-3 sm:py-4 flex items-center justify-between gap-3 bg-paper mt-6">
          {footer}
        </div>
      )}
    </div>
  );
}

export function HeroHeader({
  icon: Icon,
  logo,
  title,
  subtitle,
}: {
  icon?: LucideIcon;
  // When white-label ships a custom logo, callers pass it here and it
  // replaces the default Lucide hero icon. (FBS white-label-onboarding)
  logo?: ReactNode;
  title: string;
  subtitle?: string;
}) {
  return (
    <div className="text-center max-w-2xl mx-auto px-4 sm:px-6 py-6 sm:py-8">
      {logo ? (
        <div className="mb-3 flex justify-center">{logo}</div>
      ) : (
        Icon && (
          <div className="mb-3 flex justify-center">
            <Icon size={28} className="text-primary" />
          </div>
        )
      )}
      <h1 className="text-[22px] sm:text-[24px] font-semibold text-ink leading-tight">
        {title}
      </h1>
      {subtitle && (
        <p className="text-[13px] text-muted mt-2">{subtitle}</p>
      )}
    </div>
  );
}

export function PrimaryButton({
  children,
  onClick,
  disabled,
  type = 'button',
}: {
  children: ReactNode;
  onClick?: () => void;
  disabled?: boolean;
  type?: 'button' | 'submit';
}) {
  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      className={`px-4 py-2 rounded text-[13px] font-medium flex items-center gap-1.5 transition-colors ${
        disabled
          ? 'bg-primary text-on-primary opacity-50 cursor-not-allowed'
          : 'bg-primary text-on-primary hover:bg-primary-hover'
      }`}
    >
      {children}
    </button>
  );
}

export function GhostLink({
  children,
  onClick,
}: {
  children: ReactNode;
  onClick?: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="text-[13px] text-muted hover:text-ink transition-colors"
    >
      {children}
    </button>
  );
}
