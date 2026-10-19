import { InlineConfirm } from './InlineConfirm';
import { __ } from '../i18n';

/**
 * The warning that gates taking a wp-config.php setting over (§24.36).
 *
 * One component so both the schema-driven panel and Object Cache's own row
 * renderer ask the same question in the same words — the copy IS the safeguard
 * here, and two near-identical strings would drift.
 *
 * `warn`, not `danger`: the choice is consequential but reversible, and
 * painting it red would rank it with deleting data.
 */
export function OverrideConfirm({
  constant,
  busy,
  onConfirm,
  onCancel,
}: {
  constant: string;
  busy?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  return (
    <InlineConfirm
      tone="warn"
      title={__('Manage this setting here?')}
      body={
        <>
          {__('This value comes from')}{' '}
          <code className="font-mono text-[11px]">{constant}</code>{' '}
          {__(
            'in wp-config.php — usually written by your host. If you manage it here, later changes your host makes to it will be ignored.'
          )}{' '}
          {__(
            'On a managed host (xCloud, Cloudways and similar), changing it in their control panel is usually the better route. You can revert at any time.'
          )}
        </>
      }
      confirmLabel={__('Manage here')}
      busy={busy}
      onConfirm={onConfirm}
      onCancel={onCancel}
    />
  );
}
