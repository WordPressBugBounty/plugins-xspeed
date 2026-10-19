import { FileLock2, PencilLine, RotateCcw } from 'lucide-react';
import { __ } from '../i18n';

/**
 * The "this value comes from wp-config.php" marker, and the deliberate way out.
 *
 * A field pinned by a constant is not editable from here — the resolver reads
 * the constant whatever the option row says, so an editable box would offer to
 * change a value the site would go on ignoring. The row shows the effective
 * value, disables its control, and names the constant so the reader knows
 * which line to go and edit.
 *
 * But "go edit the file" is not always advice someone can act on, and on a
 * managed host it is often not even the right advice. So the marker carries a
 * pencil that, after a warning, hands the field back: the override is stored
 * as our own constant, which outranks the host's, and Revert gives it back.
 *
 * The warning is INLINE, not a floating popover — §23 makes inline disclosure
 * the default and none of its three overlay exceptions apply here. An earlier
 * cut portalled a bespoke panel and inherited two bugs for it: scrolling
 * dismissed the warning mid-read, and near the foot of a long settings list it
 * rendered past the panel edge with no room to flip. A row that grows in place
 * has neither failure mode. (#398)
 *
 * DESIGN.md §24.35 (the marker) and §24.36 (the override).
 */
export function DefinedInConfig({
  constant,
  overridden,
  busy,
  onOverride,
  onRevert,
}: {
  /** Winning constant's name, or the one being shadowed while overridden. */
  constant: string;
  overridden?: boolean;
  busy?: boolean;
  onOverride?: () => void;
  onRevert?: () => void;
}) {
  if (overridden) {
    return (
      <div className="mt-0.5 inline-flex items-center gap-1.5 text-[12px] text-ink-soft flex-wrap">
        <PencilLine className="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
        <span>
          {__('Your value is used instead of')}{' '}
          <code className="font-mono text-[11px] text-ink bg-canvas border border-line rounded px-1 py-px">
            {constant}
          </code>{' '}
          {__('from wp-config.php')}
        </span>
        {onRevert && (
          <button
            type="button"
            onClick={onRevert}
            disabled={busy}
            className="inline-flex items-center gap-1 text-[12px] text-ink-soft hover:text-ink underline underline-offset-2 disabled:opacity-60 disabled:cursor-not-allowed"
          >
            <RotateCcw className="w-3 h-3" aria-hidden="true" />
            {__('Use the host value')}
          </button>
        )}
      </div>
    );
  }

  return (
    <div className="mt-0.5 inline-flex items-center gap-1.5 text-[12px] text-ink-soft">
      <FileLock2 className="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
      <span>
        {__('Defined in wp-config.php as')}{' '}
        <code className="font-mono text-[11px] text-ink bg-canvas border border-line rounded px-1 py-px">
          {constant}
        </code>
      </span>
      {onOverride && (
        <button
          type="button"
          onClick={onOverride}
          disabled={busy}
          aria-label={__('Manage this setting here instead')}
          title={__('Manage this setting here instead')}
          className="inline-flex items-center justify-center text-ink-soft hover:text-primary transition-colors align-middle disabled:opacity-60 disabled:cursor-not-allowed"
        >
          <PencilLine size={13} />
        </button>
      )}
    </div>
  );
}
