import { ImagePlus, X } from 'lucide-react';

/**
 * Media-library picker for image fields (e.g. the white-label brand logo).
 * Opens the native WP media modal (window.wp.media) and stores the chosen
 * attachment URL — the persisted value stays a plain URL string, so the
 * server payload + downstream rendering are unchanged vs a url field.
 * Requires wp_enqueue_media() on the page (Admin/Onboarding enqueue it).
 * (FBS white-label-onboarding)
 */
interface WpMediaAttachment {
  toJSON(): { url: string };
}
interface WpMediaFrame {
  on(event: 'select', cb: () => void): void;
  open(): void;
  state(): { get(key: 'selection'): { first(): WpMediaAttachment | undefined } };
}
interface WpMedia {
  (opts: {
    title?: string;
    button?: { text: string };
    library?: { type: string };
    multiple?: boolean;
  }): WpMediaFrame;
}

function getWpMedia(): WpMedia | undefined {
  return (window as unknown as { wp?: { media?: WpMedia } }).wp?.media;
}

export function MediaField({
  value,
  onChange,
  label,
}: {
  value: string;
  onChange: (url: string) => void;
  label: string;
}) {
  const media = getWpMedia();

  const pick = () => {
    if (!media) return;
    const frame = media({
      title: label,
      button: { text: 'Use this image' },
      library: { type: 'image' },
      multiple: false,
    });
    frame.on('select', () => {
      const attachment = frame.state().get('selection').first();
      if (attachment) onChange(attachment.toJSON().url);
    });
    frame.open();
  };

  // Graceful fallback: if wp.media isn't loaded, degrade to a URL input so
  // the field is never dead.
  if (!media) {
    return (
      <input
        type="url"
        value={value ?? ''}
        onChange={(e) => onChange(e.target.value)}
        className="w-56"
        aria-label={label}
        placeholder="https://…"
      />
    );
  }

  return (
    <div className="flex items-center gap-2">
      {value ? (
        <>
          <img
            src={value}
            alt={label}
            className="w-8 h-8 rounded object-contain border border-neutral-200 dark:border-neutral-700 bg-white"
          />
          <button
            type="button"
            onClick={pick}
            className="text-[12px] px-2.5 py-1 rounded border border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition-colors"
          >
            Change
          </button>
          <button
            type="button"
            onClick={() => onChange('')}
            aria-label={`Remove ${label}`}
            className="text-neutral-400 hover:text-red-600 dark:hover:text-red-400 transition-colors"
          >
            <X size={14} />
          </button>
        </>
      ) : (
        <button
          type="button"
          onClick={pick}
          className="text-[12px] px-2.5 py-1 rounded border border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition-colors inline-flex items-center gap-1.5"
        >
          <ImagePlus size={13} />
          Select image
        </button>
      )}
    </div>
  );
}
