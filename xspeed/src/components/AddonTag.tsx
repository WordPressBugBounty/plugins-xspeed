/**
 * Marks something sold separately from Pro (DESIGN.md §24.50).
 *
 * Orange on its own tint, so it never reads as Pro's teal lock (§24.10) or a
 * warning. The words come from whoever owns the thing, the module's
 * `status_label` or a contributed recommendation's `tag`, so this renders
 * whatever it is given and never names a product itself.
 */
export function AddonTag({ label, className = '' }: { label: string; className?: string }) {
  // Long labels truncate rather than squeeze what sits beside the tag; the
  // full text stays in the tooltip.
  return (
    <span
      title={label}
      className={`inline-block max-w-[min(11rem,100%)] truncate align-middle rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider bg-addon-tint text-addon ${className}`}
    >
      {label}
    </span>
  );
}
