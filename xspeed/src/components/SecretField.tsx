import { useRef } from 'react';
import { X } from 'lucide-react';
import { __ } from '../i18n';

/**
 * SecretField — the one credential input (DESIGN.md §24.27).
 *
 * Every `type: 'secret'` schema field renders through this component — the
 * shared renderField AND the custom panels (Cloudflare, Object Cache, and the
 * Pro panels via the `@xspeed` alias). One implementation, so the mask
 * round-trip semantics can't fork per panel again (QA B1 round 2).
 *
 * The server never sends the real credential — reads carry a masked hint
 * (`sk-1••••wxyz`). The three states this component must get right:
 *
 * 1. SAVED, UNTOUCHED — the box is EMPTY and the mask is the placeholder.
 *    Seeding the box with the mask is the QA B8 bug: click-and-type APPENDS,
 *    the draft still contains the bullet run, and the server write-preserves —
 *    silently discarding the new key. An empty box makes typing a replace.
 *    The untouched form value stays the mask, so an unrelated save on the
 *    panel still echoes it and the server keeps the stored secret.
 * 2. TYPING — the draft is the form value (sent on save; the server responds
 *    with the new mask, which empties the box again — that's the "saved"
 *    signal). Erasing the draft REVERTS to the mask ("no change"), it does
 *    not clear: since #115/B7 an empty write deletes the credential, and a
 *    select-all-delete must never do that by accident.
 * 3. REMOVE — deleting the stored key is the explicit ✕ button only. It sends
 *    '' (the deliberate clear), after which nothing is saved and the
 *    placeholder falls back to the field label.
 */

/** The bullet run get_public() builds masks with — same constant as PHP's
 *  Settings_Manager::SECRET_MASK_BULLETS. Its presence marks a mask echo. */
export const SECRET_MASK_BULLETS = '••••';

export function isMaskedSecret(v: unknown): v is string {
  return typeof v === 'string' && v.includes(SECRET_MASK_BULLETS);
}

export function SecretField({
  value,
  onChange,
  label,
  inputClassName,
  readOnly = false,
}: {
  value: unknown;
  onChange: (v: string) => void;
  label: string;
  /** Panel-specific input classes (width etc.). Defaults to the shared
   *  schema-row input style. */
  inputClassName?: string;
  /** The credential comes from a wp-config.php constant (DESIGN.md §24.35).
   *  The box is un-typable and the remove button is withheld: the key is not
   *  in the database, so there is nothing here to replace or delete. */
  readOnly?: boolean;
}) {
  const masked = isMaskedSecret(value);
  // The mask we arrived with (or picked up from a save echo). Erasing a
  // typed draft reverts to this instead of producing '' — see state 2 above.
  // Cleared when the user explicitly removes the key, so a later erase can't
  // resurrect it.
  const storedMask = useRef<string>(masked ? value : '');
  if (masked && storedMask.current !== value) {
    // A save round-trip refreshed the hint (new key stored) — track it.
    storedMask.current = value;
  }

  const draft = masked ? '' : String(value ?? '');
  const hasSaved = storedMask.current !== '';

  return (
    <div className="flex items-center gap-1.5">
      <input
        type="password"
        value={draft}
        onChange={(e) => {
          const next = e.target.value;
          // Empty draft = "cancel my edit", never "delete the key".
          onChange('' === next ? storedMask.current : next);
        }}
        disabled={readOnly}
        // Chrome ignores autocomplete="off" on a password field and fills a
        // saved site login into it; "new-password" is the value it respects.
        // The data-* attributes opt out of 1Password, LastPass and Bitwarden.
        // (#700)
        autoComplete="new-password"
        data-1p-ignore="true"
        data-lpignore="true"
        data-bwignore="true"
        data-form-type="other"
        spellCheck={false}
        placeholder={
          // The server masks a constant-sourced credential like any other
          // secret, but the mask says nothing about WHERE the value lives —
          // and this one cannot be replaced from here. Name its home instead.
          readOnly ? __('Set in wp-config.php') : masked ? String(value) : label
        }
        title={
          readOnly
            ? __('This credential is defined in wp-config.php and cannot be changed here.')
            : hasSaved
              ? __('A key is saved. Type to replace it; use ✕ to remove it.')
              : undefined
        }
        className={`${
          inputClassName ??
          'w-56 rounded-control border border-line bg-paper px-2.5 py-1.5 text-[13px] text-ink focus:outline-none focus:ring-2 focus:ring-primary'
        }${readOnly ? ' opacity-60 cursor-not-allowed' : ''}`}
        aria-label={label}
      />
      {/* Explicit remove — the only path that writes '' (a deliberate clear
          since B7). Hidden while a draft is being typed so it can't be
          mistaken for "clear the box". */}
      {hasSaved && masked && !readOnly && (
        <button
          type="button"
          onClick={() => {
            storedMask.current = '';
            onChange('');
          }}
          className="shrink-0 p-1.5 rounded border border-line text-ink-soft hover:text-danger hover:border-danger transition-colors"
          title={__('Remove the saved key')}
          aria-label={`${__('Remove')} ${label}`}
        >
          <X size={13} />
        </button>
      )}
    </div>
  );
}
