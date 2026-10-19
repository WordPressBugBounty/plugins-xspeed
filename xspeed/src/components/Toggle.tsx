export function Toggle({
  checked,
  onChange,
  size = 'md',
  label = 'Toggle',
  disabled = false,
}: {
  checked: boolean;
  onChange: (v: boolean) => void;
  /** `sm` is for dense chrome (the top-bar cache pill); `md` is the default. */
  size?: 'sm' | 'md' | 'lg';
  label?: string;
  /** Render un-actionable — e.g. a value pinned by a wp-config.php constant
   *  (DESIGN.md §24.35). Still shows its real state. */
  disabled?: boolean;
}) {
  const dims =
    size === 'lg'
      ? { track: 'w-11 h-6', dot: 'w-5 h-5', tx: 'translate-x-[22px]', off: 'translate-x-0.5' }
      : size === 'sm'
        ? { track: 'w-7 h-4', dot: 'w-3 h-3', tx: 'translate-x-[14px]', off: 'translate-x-0.5' }
        : { track: 'w-9 h-5', dot: 'w-4 h-4', tx: 'translate-x-[18px]', off: 'translate-x-0.5' };

  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked ? 'true' : 'false'}
      aria-label={label}
      title={label}
      onClick={() => onChange(!checked)}
      disabled={disabled}
      className={`relative inline-flex shrink-0 ${dims.track} items-center rounded-full transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1 ${
        checked ? 'bg-primary' : 'bg-line-strong'
      } ${disabled ? 'opacity-60 cursor-not-allowed' : ''}`}
    >
      <span
        className={`inline-block ${dims.dot} transform rounded-full bg-white shadow-sm transition-transform duration-150 ${
          checked ? dims.tx : dims.off
        }`}
      />
    </button>
  );
}
