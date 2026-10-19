import { Rocket, ArrowRight } from 'lucide-react';
import { HealthRow } from '../../components/HealthRow';
import { HeroHeader, PrimaryButton, WizardShell } from '../components/WizardShell';
import { BrandLogo } from '../../components/BrandLogo';
import { useBranding } from '../../hooks/useBranding';
import type { OnboardingEnv } from '../../types';

const serverLabels: Record<OnboardingEnv['server']['type'], string> = {
  apache: 'Apache',
  litespeed: 'LiteSpeed',
  nginx: 'nginx',
  iis: 'IIS',
  unknown: 'Unknown',
};

export function ReviewStep({
  env,
  onNext,
}: {
  env: OnboardingEnv;
  onNext: () => void;
}) {
  const branding = useBranding();
  const hasCustomLogo = !!branding.logo_svg?.trim();
  const hasBlockingConflict = env.conflicts.length > 0;
  const wpTone = env.wp.ok ? 'ok' : 'fail';
  const phpTone = env.php.modern ? 'ok' : env.php.ok ? 'warn' : 'fail';
  const dirTone = env.cache_dir.writable ? 'ok' : 'fail';
  const permaTone = env.permalinks_ok ? 'ok' : 'warn';
  const conflictTone = hasBlockingConflict ? 'warn' : 'ok';
  const gzipDetail =
    env.server.gzip_mode === 'auto'
      ? 'GZIP can be auto-configured via .htaccess.'
      : `GZIP requires a manual server-config snippet (we'll show it).`;

  return (
    <WizardShell
      step="review"
      hero={
        <HeroHeader
          icon={Rocket}
          logo={hasCustomLogo ? <BrandLogo className="w-8 h-8 rounded" /> : undefined}
          title="Let's review your setup"
          subtitle="A quick 30-second check of your environment before we turn on a sensible caching profile."
        />
      }
      footer={
        <>
          <span />
          <PrimaryButton onClick={onNext}>
            Get Started
            <ArrowRight size={14} />
          </PrimaryButton>
        </>
      }
    >
      <div className="border border-line bg-paper rounded-card overflow-hidden">
        <HealthRow
          first
          tone={wpTone}
          label={`WordPress ${env.wp.version}`}
          detail={env.wp.ok ? 'Meets the 6.0+ minimum.' : 'Upgrade to 6.0 or higher.'}
        />
        <HealthRow
          tone={phpTone}
          label={`PHP ${env.php.version}`}
          detail={
            env.php.modern
              ? 'Modern PHP — full speed.'
              : env.php.ok
              ? 'Works, but 8.1+ is recommended for best performance.'
              : 'Upgrade to PHP 7.4 or higher.'
          }
        />
        <HealthRow
          tone="info"
          label={`Server: ${serverLabels[env.server.type]}`}
          detail={gzipDetail}
        />
        <HealthRow
          tone={dirTone}
          label="Cache directory writable"
          detail={
            env.cache_dir.writable
              ? env.cache_dir.path
              : `Cannot write to ${env.cache_dir.path}. Adjust file permissions before continuing.`
          }
        />
        <HealthRow
          tone={permaTone}
          label="Permalinks"
          detail={
            env.permalinks_ok
              ? 'Pretty permalinks active.'
              : 'Set permalinks to anything other than "Plain" — required for page caching.'
          }
        />
        <HealthRow
          tone={conflictTone}
          label="Caching plugin conflicts"
          detail={
            hasBlockingConflict
              ? // Not "Deactivate": the list now includes a drop-in left behind
                // by a plugin that is not running, and there is nothing there to
                // deactivate — the file has to go. Matches the Health card and
                // the sentence the enable itself refuses with.
                `Detected: ${env.conflicts.join(', ')}. Another page cache must be off, and its advanced-cache.php gone, before ${branding.name} can enable its own.`
              : 'No other caching plugins detected.'
          }
        />
      </div>
    </WizardShell>
  );
}
