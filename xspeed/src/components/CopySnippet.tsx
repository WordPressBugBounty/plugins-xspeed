import { useState } from 'react';
import { Copy, Check } from 'lucide-react';

/**
 * Code-block primitive with a copy-to-clipboard button. Used wherever
 * we render a multi-line snippet the user is expected to paste into
 * a config file outside WordPress: the WP_CACHE wp-config snippet,
 * the nginx server-block snippet, etc.
 *
 * Visual tone is the "info" surface (neutral border + zebra-bg pre).
 * Pass `tone="amber"` for the WP_CACHE wp-config warning variant.
 */
export function CopySnippet({
  content,
  tone = 'neutral',
  onCopy,
}: {
  content: string;
  tone?: 'neutral' | 'amber' | 'blue';
  /**
   * Fired only when the text actually reached the clipboard. The nginx block
   * records the hash of what the user copied off the back of this, so a
   * failed write must not be recorded as a copy.
   */
  onCopy?: () => void;
}) {
  const [copied, setCopied] = useState(false);

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(content);
      setCopied(true);
      onCopy?.();
      window.setTimeout(() => setCopied(false), 1800);
    } catch {
      // Older browsers / non-secure contexts may reject the API.
      // Fall back to a manual selectable pre — user still has the
      // text in front of them.
    }
  };

  const wrapClass =
    tone === 'amber'
      ? 'bg-white dark:bg-neutral-900 border border-amber-200 dark:border-amber-900/60'
      : tone === 'blue'
      ? 'bg-primary-tint border border-line'
      : 'bg-neutral-50 dark:bg-neutral-950 border border-neutral-200 dark:border-neutral-800';

  return (
    <div className={`relative rounded ${wrapClass}`}>
      <button
        type="button"
        onClick={copy}
        className="absolute top-2 right-2 inline-flex items-center gap-1 text-[11px] px-2 py-1 rounded border border-neutral-300 dark:border-neutral-700 bg-white/90 dark:bg-neutral-800/90 text-neutral-700 dark:text-neutral-200 hover:bg-neutral-100 dark:hover:bg-neutral-700 transition-colors"
        aria-label={copied ? 'Snippet copied' : 'Copy snippet'}
      >
        {copied ? <Check size={12} /> : <Copy size={12} />}
        {copied ? 'Copied' : 'Copy'}
      </button>
      <pre className="text-[11px] font-mono p-3 pr-16 overflow-x-auto whitespace-pre text-neutral-800 dark:text-neutral-200">
        {content}
      </pre>
    </div>
  );
}
