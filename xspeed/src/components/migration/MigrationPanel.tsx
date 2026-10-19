import { useEffect, useState } from 'react';
import { Import, RefreshCw, CheckCircle2, ArrowRight, Eye, EyeOff, Wand2, Download, Upload, Lock } from 'lucide-react';
import { Callout } from '../Callout';
import { InlineConfirm } from '../InlineConfirm';
import { api } from '../../api/client';
import { useTier } from '../../upsell/useTier';
import type { ModuleDescriptor } from '../../types';
import { __, sprintf } from '../../i18n';

interface Source {
  id: string;
  label: string;
  detected: boolean;
  value_count: number;
  mapped_count: number;
  imported: boolean;
  active: boolean;
}
interface Profile {
  id: string;
  label: string;
  description: string;
}
type Patch = Record<string, Record<string, unknown>>;
interface StatusResponse { sources: Source[] }
interface ProfilesResponse { profiles: Profile[] }
interface PreviewResponse { patch: Patch; notes?: string[] }
/** A setting that did not come across, and why. `pinned`: wp-config.php holds it. */
interface SkippedSetting { key: string; label: string; reason: string; pinned?: boolean }
/** A setting saved with a different value than the source had, and why. */
interface AdjustedSetting { key: string; label: string; from: number; to: number; reason: string }
interface ApplyResponse {
  results: Record<string, {
    ok: boolean;
    applied: string[];
    message?: string;
    /** Pro's One-Click Profiles report a failed group's reason here, not in `message`. */
    error?: string;
    skipped?: SkippedSetting[];
    adjusted?: AdjustedSetting[];
  }>;
  deactivated?: boolean;
  source_label?: string;
  /**
   * Set when deactivation was requested and declined — currently only
   * 'insufficient_capability' (the caller may edit settings but not switch
   * plugins off; on multisite a network-active source needs
   * manage_network_plugins). Empty otherwise. (#189)
   */
  refused?: string;
  /** Human sentence naming who can deactivate, when we declined to. (#189) */
  refused_message?: string;
  /** Set when the source was switched off and xSpeed took the page cache over. (#393) */
  handover?: { backup: string | null; enabled: boolean; cache_verified: 'HIT' | 'MISS' | 'unknown' } | null;
}

/**
 * What happened to the page cache when the source was switched off: where
 * its drop-in was saved, and whether the home page came back from cache.
 * Empty when there was no handover. (#393)
 *
 * MISS is "not confirmed yet", not "not served": right after the switch the
 * old plugin's code can keep answering for a few seconds (OPcache, or a
 * request already in flight), so a miss here proves nothing about the cache.
 */
function handoverText(h: ApplyResponse['handover']): string {
  if (!h) return '';
  const parts: string[] = [];
  if (h.backup) parts.push(sprintf(__('Its advanced-cache.php was saved as %s.'), h.backup));
  if (!h.enabled) {
    parts.push(__('The page cache is not on yet: turn it on in Page Cache.'));
  } else if (h.cache_verified === 'HIT') {
    parts.push(__('The page cache is on and served the home page from cache.'));
  } else if (h.cache_verified === 'MISS') {
    parts.push(__('The page cache is on. A cached home page could not be confirmed yet, which is normal for a few seconds after the switch. Load the home page again in a private window, or check Cache Health.'));
  } else {
    parts.push(__('The page cache is on. xSpeed could not load the home page to check it.'));
  }
  return ' ' + parts.join(' ');
}

// ── Applied-profile persistence (FBS-82397 #5) ───────────────────────────
// Survive a page reload — the apply writes server-side options, so the
// "Applied" badge must not vanish on F5. Keyed per-site (home origin) so two
// installs sharing a browser don't collide.
const APPLIED_KEY = `xspeed_migration_applied_${typeof window !== 'undefined' ? window.location.host : ''}`;
function readAppliedProfiles(): string[] {
  if (typeof window === 'undefined') return [];
  try {
    const raw = window.localStorage.getItem(APPLIED_KEY);
    const arr = raw ? JSON.parse(raw) : [];
    return Array.isArray(arr) ? arr.filter((x): x is string => typeof x === 'string') : [];
  } catch {
    return [];
  }
}
function writeAppliedProfiles(ids: string[]): void {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage.setItem(APPLIED_KEY, JSON.stringify(ids));
  } catch {
    /* storage disabled — non-fatal, badge just won't persist */
  }
}

// ── Friendly preview rendering (FBS-82397 #7) ────────────────────────────
// Turn a raw settings patch into human-readable "Feature → Setting: value"
// rows instead of dumping JSON with internal keys.
const MODULE_LABELS: Record<string, string> = {
  cache: 'Page Cache',
  minify: 'Minify',
  lazy: 'Lazy Load',
  preloader: 'Preloader',
  'browser-cache': 'Browser Cache',
  'object-cache': 'Object Cache',
};
const FIELD_LABELS: Record<string, string> = {
  cache_enabled: 'Enable caching',
  cache_expiry: 'Cache lifetime (hours)',
  mobile_separate: 'Separate mobile cache',
  excluded_urls: 'Excluded URLs',
  excluded_cookies: 'Excluded cookies',
  bypass_user_agents: 'Bypass user agents',
  ignored_query_params: 'Ignored query params',
  minify_html: 'Minify HTML',
  minify_css: 'Minify CSS',
  minify_js: 'Minify JS',
  lazy_images: 'Lazy-load images',
  lazy_iframes: 'Lazy-load iframes',
  lazy_videos: 'Lazy-load videos',
  enabled: 'Enabled',
  schedule: 'Schedule',
};
/** Ends a server reason with exactly one full stop, so joined reasons read as sentences. */
function sentence(text: string): string {
  const t = text.trim().replace(/[.\s]+$/, '');
  return t ? `${t}.` : '';
}

function humanizeKey(key: string): string {
  return FIELD_LABELS[key] ?? key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
// Fields whose value must never be rendered. The apply path already treats
// secrets as special — Settings_Manager encrypts them at rest — but the
// preview had no such notion and printed them verbatim, which for a source
// that stores the value in the clear puts the site's real password on
// screen in the admin. (#224 F4)
const SECRET_FIELDS = new Set([
  'redis_password',
  'memcached_password',
  'object_password',
  'api_key',
  'api_token',
  'cloudflare_api_token',
  'license_key',
]);
export function isSecretField(key: string): boolean {
  return SECRET_FIELDS.has(key) || /(password|secret|token|api_key)$/i.test(key);
}
function formatValue(v: unknown): string {
  if (typeof v === 'boolean') return v ? 'On' : 'Off';
  if (Array.isArray(v)) return v.length ? `${v.length} item${v.length === 1 ? '' : 's'}: ${v.join(', ')}` : '—';
  if (v === null || v === undefined || v === '') return '—';
  return String(v);
}
/** Render a masked placeholder for secrets, the real value otherwise. */
export function displayValue(key: string, v: unknown): string {
  if (!isSecretField(key)) return formatValue(v);
  if (v === null || v === undefined || v === '') return '—';
  return '•••••••• (hidden)';
}

function FriendlyPatch({ patch, title }: { patch: Patch; title: string }) {
  return (
    <div className="mt-4 space-y-2">
      <div className="text-[11px] font-medium text-muted uppercase tracking-wider">
        {title}
      </div>
      {/* Each module is one continuous shaded box: the group title shares the
          same background as its setting rows. */}
      {Object.entries(patch).map(([slug, values]) => (
        <div key={slug} className="rounded-card overflow-hidden bg-panel">
          <div className="px-4 pt-2.5 pb-1.5 text-[12px] font-semibold text-ink">
            {MODULE_LABELS[slug] ?? humanizeKey(slug)}
          </div>
          {Object.entries(values).map(([k, v]) => (
            <div key={k} className="flex items-center justify-between gap-4 px-4 py-2">
              <span className="text-[12px] text-muted shrink-0">{humanizeKey(k)}</span>
              <span
                className="text-[12px] text-ink text-right truncate"
                title={displayValue(k, v)}
              >
                {displayValue(k, v)}
              </span>
            </div>
          ))}
        </div>
      ))}
    </div>
  );
}

export function MigrationPanel(_: { module: ModuleDescriptor }) {
  // Pro gate: One-Click Profiles + Save/Share are advanced (Pro) features
  // owned by the hidden `migration-pro` module, but their UI lives here in
  // the Free Migration panel. When Pro is active we call the /migration-pro/*
  // routes; when not, we render a locked upsell so Free users discover them.
  const tier = useTier();
  const proActive = tier === 'pro' || tier === 'trial';

  const [sources, setSources] = useState<Source[] | null>(null);
  const [previews, setPreviews] = useState<Record<string, Patch>>({});
  // Lossy-conversion warnings that accompany a preview, keyed by source.
  const [previewNotes, setPreviewNotes] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState<null | { source: string; op: 'preview' | 'apply' | 'status' }>(null);
  const [applied, setApplied] = useState<string[]>([]);
  const [success, setSuccess] = useState<{ title: string; body: string } | null>(null);
  // Settings a successful import could not bring across, shown under it.
  const [warning, setWarning] = useState<{ title: string; body: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  // When the user clicks Import on a source whose plugin is still ACTIVE, we
  // hold its id here to show a confirm step — importing will deactivate it
  // (two page caches conflict). Inactive sources import straight away.
  const [confirmSource, setConfirmSource] = useState<Source | null>(null);

  // One-Click Profiles (Pro).
  const [profiles, setProfiles] = useState<Profile[] | null>(null);
  const [profilePreviews, setProfilePreviews] = useState<Record<string, Patch>>({});
  const [profileBusy, setProfileBusy] = useState<null | { id: string; op: 'preview' | 'apply' }>(null);
  // Applied state persists across reloads — the apply mutates server-side
  // options, so "Applied" must survive an F5. Keyed per-site so two installs
  // on one browser don't bleed. (FBS-82397 #5)
  const [profileApplied, setProfileApplied] = useState<string[]>(() => readAppliedProfiles());
  // Which profile's "Apply" is awaiting confirmation — a one-click apply
  // overwrites site-wide cache settings, so we confirm first. (FBS-82397 #6)
  const [confirmProfile, setConfirmProfile] = useState<Profile | null>(null);

  // Persist applied profiles whenever the set changes.
  useEffect(() => {
    writeAppliedProfiles(profileApplied);
  }, [profileApplied]);

  // Save & Share — export/import a portable profile (Pro).
  const [importText, setImportText] = useState('');
  const [importPatch, setImportPatch] = useState<Patch | null>(null);
  const [shareBusy, setShareBusy] = useState<null | 'export' | 'preview' | 'apply'>(null);
  const [shareNotice, setShareNotice] = useState<string | null>(null);
  // Import errors render INLINE next to the import controls (not the global
  // panel-top error, which is off-screen from here). (FBS-82401 #1)
  const [shareError, setShareError] = useState<string | null>(null);
  // Confirm before Import & apply overwrites site-wide settings. (FBS-82401 #4)
  const [confirmImport, setConfirmImport] = useState(false);

  /**
   * Re-scan the source list.
   *
   * `keepError` exists because apply() calls this immediately after
   * reporting a partial failure, and clearing the error here wiped that
   * message before React ever painted it — the user saw a green "Imported"
   * and no hint that a module had failed. The re-scan is only about which
   * plugins are still installed, so it has no business discarding a result
   * the caller just produced. (#218 F2)
   */
  const refresh = async ({ keepError = false }: { keepError?: boolean } = {}) => {
    setBusy({ source: '*', op: 'status' });
    if (!keepError) setError(null);
    try {
      const res = await api.request<StatusResponse>('/migration/status');
      setSources(res.sources);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Status check failed');
    } finally {
      setBusy(null);
    }
  };

  const loadProfiles = async () => {
    try {
      const res = await api.request<ProfilesResponse>('/migration-pro/profiles');
      setProfiles(res.profiles);
    } catch {
      // Profiles are a nicety; a load failure shouldn't break the panel.
      setProfiles([]);
    }
  };

  useEffect(() => {
    refresh();
    // Only hit the Pro routes when Pro is active — avoids a guaranteed 404/403
    // (and a misleading error) on Free installs.
    if (proActive) loadProfiles();
  }, [proActive]);

  const preview = async (source: string) => {
    setBusy({ source, op: 'preview' });
    setError(null);
    try {
      const res = await api.request<PreviewResponse>('/migration/preview', {
        method: 'POST',
        body: JSON.stringify({ source }),
      });
      setPreviews((p) => ({ ...p, [source]: res.patch }));
      setPreviewNotes((n) => ({ ...n, [source]: res.notes ?? [] }));
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Preview failed');
    } finally {
      setBusy(null);
    }
  };

  // Import button → if the source plugin is still active, confirm first
  // (the confirm is what asks for deactivation). If inactive, import
  // immediately — there is nothing to switch off.
  const onImportClick = (s: Source) => {
    if (s.active) {
      setConfirmSource(s);
    } else {
      apply(s.id, false);
    }
  };

  /**
   * `deactivate` is now sent explicitly and defaults to false server-side.
   *
   * The API used to deactivate the source unconditionally, which meant this
   * confirm was the ONLY thing standing in front of a destructive action —
   * and it lives in the client, where the server cannot see it. Passing the
   * user's answer in the request is what lets the server honor it, and what
   * makes WP-CLI and MCP behave identically. (#189)
   */
  const apply = async (source: string, deactivate: boolean) => {
    setConfirmSource(null);
    setBusy({ source, op: 'apply' });
    setError(null);
    setSuccess(null);
    setWarning(null);
    try {
      const res = await api.request<ApplyResponse>('/migration/apply', {
        method: 'POST',
        body: JSON.stringify({ source, deactivate_source: deactivate }),
      });
      const label = res.source_label || 'the source plugin';
      // Say exactly what happened to the other plugin. The old copy assumed a
      // false `deactivated` meant "it was already inactive" — true when
      // deactivation was unconditional, wrong now that declining is the
      // default and a refusal is possible. Guessing here would tell a user
      // their old cache is off when it is still running. (#189)
      const stillRunning =
        `Note that ${label} is still active: running two page caches at once ` +
        `makes them fight over the cache drop-in and can break caching for both. ` +
        `Deactivate it from the Plugins screen once you have checked the import.`;

      // ok:false still carries real meaning — when the drop-in cannot be
      // written, apply() reports failure. Rendering the success Callout with
      // nothing else discarded that signal and told the user their settings
      // were active while page caching was enabled-but-not-loading. (#222 F3)
      //
      // But a few settings not coming across is not a failed migration. The
      // red "Migration error" for a partial import read as if nothing had
      // been imported. The server names each setting and why in
      // `skipped` / `adjusted`; list those beside the success message, and
      // keep the error for an import where nothing came across at all.
      const rows = Object.entries(res.results);
      const notImported: string[] = [];
      const adjusted: string[] = [];
      for (const [slug, r] of rows) {
        for (const s of r.skipped ?? []) notImported.push(`${s.label}: ${sentence(s.reason)}`);
        for (const a of r.adjusted ?? []) adjusted.push(`${a.label}: ${sentence(a.reason)}`);
        // A failure the server did not explain row by row (an older server,
        // or a check this list does not cover yet) still has to show.
        if (!r.ok && !(r.skipped ?? []).length) {
          notImported.push(`${MODULE_LABELS[slug] ?? humanizeKey(slug)}: ${sentence(r.message || 'could not be applied')}`);
        }
      }
      const importedSomething = rows.some(([, r]) => r.applied.length > 0);

      if (!importedSomething) {
        setError(`Nothing could be imported from ${label}. ${notImported.join(' ')}`.trim());
      } else {
        // The source stays retryable (no "Imported" badge) until every group
        // applied. A wp-config.php value or a clamped number does not count
        // against that; the server keeps those rows ok. (#222)
        if (rows.every(([, r]) => r.ok)) setApplied((a) => [...a, source]);
        if (notImported.length > 0 || adjusted.length > 0) {
          const parts: string[] = [];
          if (notImported.length > 0) {
            parts.push(`${notImported.length} ${notImported.length === 1 ? 'setting was' : 'settings were'} not imported`);
          }
          if (adjusted.length > 0) {
            parts.push(`${adjusted.length} ${adjusted.length === 1 ? 'was' : 'were'} changed to fit xSpeed`);
          }
          setWarning({ title: parts.join(', '), body: [...notImported, ...adjusted].join(' ') });
        }
        setSuccess({
          title: `Imported settings from ${label}`,
          body: res.deactivated
            ? `Your ${label} settings are now active in xSpeed, and ${label} has been deactivated so the two caching plugins don't conflict. Review the imported values in the panels and re-enable anything you want to tweak.${handoverText(res.handover)}`
            : // The server sends a ready-made sentence naming WHO can switch the
              // plugin off (a network admin, for a network-activated source), so
              // the panel prints it rather than mapping codes to copy here.
              res.refused_message
              ? `${res.refused_message} ${stillRunning}`
              : `Your ${label} settings are now active in xSpeed. Review the imported values in the panels. ${stillRunning}`,
        });
      }
      // Re-scan so a just-deactivated source drops out of the list — but
      // keep any failure message we just set, or it is wiped before the
      // user can read it. (#218 F2)
      refresh({ keepError: !importedSomething });
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Apply failed');
    } finally {
      setBusy(null);
    }
  };

  // --- One-Click Profiles (Pro) ---
  // Preview toggles: a second click on an already-previewed profile collapses
  // it instead of leaving it stuck open. (FBS-82397 #2)
  const previewProfile = async (id: string) => {
    if (profilePreviews[id]) {
      setProfilePreviews((p) => {
        const next = { ...p };
        delete next[id];
        return next;
      });
      return;
    }
    setProfileBusy({ id, op: 'preview' });
    setError(null);
    try {
      const res = await api.request<PreviewResponse>('/migration-pro/profiles/preview', {
        method: 'POST',
        body: JSON.stringify({ profile: id }),
      });
      setProfilePreviews((p) => ({ ...p, [id]: res.patch }));
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Preview failed');
    } finally {
      setProfileBusy(null);
    }
  };

  // Clicking Apply opens a confirmation (FBS-82397 #6) — applying overwrites
  // site-wide cache settings, so we never act on a single stray click.
  const applyProfile = (p: Profile) => setConfirmProfile(p);

  const confirmedApplyProfile = async (id: string) => {
    setConfirmProfile(null);
    setProfileBusy({ id, op: 'apply' });
    setError(null);
    try {
      const res = await api.request<ApplyResponse>('/migration-pro/profiles/apply', {
        method: 'POST',
        body: JSON.stringify({ profile: id }),
      });
      setProfileApplied((a) => (a.includes(id) ? a : [...a, id]));
      // A group that failed was dropped without a word. Pro reports its
      // reason as `error`; `message` is the import's field. (#392)
      const failed = Object.entries(res?.results ?? {})
        .filter(([, r]) => !r.ok)
        .map(([slug, r]) => `${MODULE_LABELS[slug] ?? humanizeKey(slug)}: ${sentence(r.error || r.message || 'could not be applied')}`);
      setWarning(
        failed.length > 0
          ? {
              title: __('The profile was applied, but not all of it'),
              body: failed.join(' '),
            }
          : null
      );
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Apply failed');
    } finally {
      setProfileBusy(null);
    }
  };

  // --- Save & Share (Pro) ---
  const exportConfig = async () => {
    setShareBusy('export');
    setError(null);
    setShareNotice(null);
    try {
      const res = await api.request<{ profile: Record<string, unknown> }>('/migration-pro/profile/export');
      const json = JSON.stringify(res.profile, null, 2);
      // Trigger a download of the profile file.
      const blob = new Blob([json], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'xspeed-profile.json';
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      setShareNotice('Profile exported — saved as xspeed-profile.json.');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Export failed');
    } finally {
      setShareBusy(null);
    }
  };

  // Friendly message for an import failure — the raw error is often a bare
  // "HTTP 422"; translate to something the user can act on. (FBS-82401 #1)
  const friendlyImportError = (e: unknown): string => {
    const raw = e instanceof Error ? e.message : '';
    if (/422|format|profile|parse|json/i.test(raw) || !raw) {
      return 'That doesn’t look like an xSpeed profile. Paste the JSON from an exported profile, or choose a .json file.';
    }
    return raw;
  };

  const previewImport = async () => {
    if (!importText.trim()) return;
    setShareBusy('preview');
    setShareError(null);
    setShareNotice(null);
    setImportPatch(null);
    try {
      const res = await api.request<PreviewResponse>('/migration-pro/profile/import/preview', {
        method: 'POST',
        body: JSON.stringify({ profile: importText.trim() }),
      });
      setImportPatch(res.patch);
    } catch (e) {
      setShareError(friendlyImportError(e));
    } finally {
      setShareBusy(null);
    }
  };

  // Import & apply opens a confirm first (FBS-82401 #4); confirmedApplyImport
  // does the actual write.
  const applyImport = () => {
    if (!importText.trim()) return;
    setShareError(null);
    setConfirmImport(true);
  };

  const confirmedApplyImport = async () => {
    setConfirmImport(false);
    setShareBusy('apply');
    setShareError(null);
    setShareNotice(null);
    try {
      const res = await api.request<ApplyResponse>('/migration-pro/profile/import/apply', {
        method: 'POST',
        body: JSON.stringify({ profile: importText.trim() }),
      });
      const n = Object.keys(res.results).length;
      setShareNotice(`Imported — applied settings to ${n} module${n === 1 ? '' : 's'}.`);
      setImportPatch(null);
      setImportText(''); // clear after a successful import (FBS-82401 #5 — avoid accidental double-apply)
    } catch (e) {
      setShareError(friendlyImportError(e));
    } finally {
      setShareBusy(null);
    }
  };

  const onImportFile = (file: File) => {
    const reader = new FileReader();
    reader.onload = () => setImportText(String(reader.result ?? ''));
    reader.readAsText(file);
  };

  return (
    <div className="space-y-4">
      <div className="border border-line bg-paper rounded-card p-4 sm:p-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3 min-w-0">
            <Import size={18} className="text-primary shrink-0" />
            <div className="min-w-0">
              <div className="font-medium text-ink">Migrate from another plugin</div>
              <div className="text-[12px] text-muted">
                Read settings from a detected caching plugin and apply the equivalent xSpeed configuration.
              </div>
            </div>
          </div>
          <button
            type="button"
            onClick={() => refresh()}
            disabled={busy !== null}
            className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-line text-ink-soft hover:bg-panel transition-colors disabled:opacity-50"
          >
            <RefreshCw size={13} className={busy?.op === 'status' ? 'animate-spin' : ''} />
            Re-scan
          </button>
        </div>
      </div>

      {error && <Callout tone="danger" title="Migration error" body={error} />}
      {success && <Callout tone="success" title={success.title} body={success.body} />}
      {warning && <Callout tone="warn" title={warning.title} body={warning.body} />}

      {/* Basic import (Free) — the detected-source list comes first, before
          the Pro profiles below. */}
      {sources?.map((s) => {
        // "Done" = imported this session OR recorded imported server-side.
        const isImported = applied.includes(s.id) || s.imported;
        const patch = previews[s.id];
        // Status line: lead with how many settings we ACTUALLY import (mapped),
        // not the raw source-key count. Grammar-correct singular/plural.
        const n = s.mapped_count;
        const settings = `${n} ${n === 1 ? 'setting' : 'settings'}`;
        const statusText = isImported
          ? `Imported${s.active ? '' : ' · plugin deactivated'}`
          : s.detected
          ? `Detected — imports ${settings}`
          : 'Not installed (or settings not present)';
        return (
          <div
            key={s.id}
            className="border border-line bg-paper rounded-card p-4 sm:p-5"
          >
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-center gap-3 min-w-0">
                <span
                  className={`w-2 h-2 rounded-full shrink-0 ${
                    isImported || s.detected
                      ? 'bg-success'
                      : 'bg-line-strong'
                  }`}
                />
                <div className="min-w-0">
                  <div className="font-medium text-ink">{s.label}</div>
                  <div className="text-[12px] text-muted">{statusText}</div>
                </div>
              </div>
              <div className="flex items-center gap-2 shrink-0">
                {isImported ? (
                  <span className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 text-success font-medium">
                    <CheckCircle2 size={14} />
                    Imported
                  </span>
                ) : (
                  <>
                    <button
                      type="button"
                      onClick={() => preview(s.id)}
                      disabled={!s.detected || busy !== null}
                      className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-line text-ink-soft hover:bg-panel transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                      {busy?.source === s.id && busy?.op === 'preview' ? (
                        <RefreshCw size={13} className="animate-spin" />
                      ) : (
                        <Eye size={13} />
                      )}
                      Preview
                    </button>
                    <button
                      type="button"
                      onClick={() => onImportClick(s)}
                      disabled={!s.detected || busy !== null}
                      className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-primary bg-primary text-on-primary hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                      {busy?.source === s.id && busy?.op === 'apply' ? (
                        <RefreshCw size={13} className="animate-spin" />
                      ) : (
                        <ArrowRight size={13} />
                      )}
                      Import
                    </button>
                  </>
                )}
              </div>
            </div>

            {confirmSource?.id === s.id && (
              <div className="mt-4">
                {/*
                  Two ways FORWARD, not one. The old confirm offered only
                  "Import & switch", so a user who wanted the settings but
                  meant to keep their old plugin running (to compare, or
                  because they are not ready to switch) had no option but
                  Cancel — which also threw away the import they wanted.
                  Deactivating someone else's plugin is their decision, so
                  both outcomes are offered and each states its consequence.
                  (#189 AC2)
                */}
                <InlineConfirm
                  title={`How should xSpeed handle ${s.label}?`}
                  body={
                    <>
                      The {settings} are imported either way. Running two page caches
                      breaks both, so switching {s.label} off is recommended — its
                      settings stay saved.
                    </>
                  }
                  confirmLabel={`Import & switch to xSpeed`}
                  altLabel="Import settings only"
                  cancelLabel="Cancel"
                  busy={busy?.source === s.id && busy?.op === 'apply'}
                  // Whichever button the user picks travels to the server as
                  // the `deactivate_source` flag — the server no longer decides
                  // on its own. (#189 AC1/AC2)
                  onConfirm={() => apply(s.id, true)}
                  onAlt={() => apply(s.id, false)}
                  onCancel={() => setConfirmSource(null)}
                />
              </div>
            )}

            {patch && <FriendlyPatch patch={patch} title="Translation preview" />}
            {/* State a value we could not carry over exactly, rather than
                presenting the rounded number as an exact import. (#224 F2) */}
            {patch && (previewNotes[s.id]?.length ?? 0) > 0 && (
              <div className="mt-3">
                <Callout
                  tone="warn"
                  title="Some values could not be imported exactly"
                  body={previewNotes[s.id].join(' ')}
                />
              </div>
            )}
          </div>
        );
      })}

      {/* One-Click Profiles (Pro) — placed AFTER the basic import above. UI
          lives here; routes are migration-pro/*. */}
      <div className="border border-line bg-paper rounded-card p-4 sm:p-5">
        <div className="flex items-center gap-3 min-w-0">
          <Wand2 size={18} className="text-primary shrink-0" />
          <div className="min-w-0">
            <div className="flex items-center gap-1.5">
              <span className="font-medium text-ink">One-click cache profiles</span>
              {!proActive && (
                <>
                  <Lock size={12} className="text-on-tint shrink-0" aria-hidden="true" />
                  <span className="sr-only">{__('Pro')}</span>
                </>
              )}
            </div>
            <div className="text-[12px] text-muted">
              Apply a curated, best-practice xSpeed setup for your site type — no manual tuning.
            </div>
          </div>
        </div>

        {!proActive ? (
          <div className="mt-4 rounded-card border border-dashed border-line-strong px-4 py-3 text-[12px] text-muted">
            Curated one-click profiles (Blog, WooCommerce, Membership, LMS, News) and portable
            save&nbsp;&amp;&nbsp;share are a Pro feature. Activate Pro to unlock them here.
          </div>
        ) : profiles === null ? (
          <div className="mt-4 text-[12px] text-muted text-center py-4">Loading…</div>
        ) : profiles.length === 0 ? (
          <div className="mt-4 text-[12px] text-muted text-center py-4">
            No profiles available.
          </div>
        ) : (
          <div className="mt-4 space-y-3">
            {profiles.map((p) => {
              const isApplied = profileApplied.includes(p.id);
              const patch = profilePreviews[p.id];
              const isOpen = Boolean(patch);
              const confirming = confirmProfile?.id === p.id;
              return (
                <div key={p.id} className="border border-line rounded-card p-4">
                  {/* #1: label/desc and the action buttons are a stable two-column
                      row — no flex-wrap, so the buttons stay pinned top-right and
                      never drop below the description. */}
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="font-medium text-ink">{p.label}</div>
                      <div className="text-[12px] text-muted mt-0.5">{p.description}</div>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      <button
                        type="button"
                        onClick={() => previewProfile(p.id)}
                        disabled={profileBusy !== null}
                        aria-expanded={isOpen ? 'true' : 'false'}
                        className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-line text-ink-soft hover:bg-panel transition-colors disabled:opacity-40"
                      >
                        {profileBusy?.id === p.id && profileBusy?.op === 'preview' ? (
                          <RefreshCw size={13} className="animate-spin" />
                        ) : isOpen ? (
                          <EyeOff size={13} />
                        ) : (
                          <Eye size={13} />
                        )}
                        {/* #2: button reflects collapse state */}
                        {isOpen ? 'Hide preview' : 'Preview'}
                      </button>
                      <button
                        type="button"
                        onClick={() => applyProfile(p)}
                        disabled={profileBusy !== null || isApplied}
                        className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-primary bg-primary text-on-primary hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                      >
                        {profileBusy?.id === p.id && profileBusy?.op === 'apply' ? (
                          <RefreshCw size={13} className="animate-spin" />
                        ) : isApplied ? (
                          <CheckCircle2 size={13} />
                        ) : (
                          <ArrowRight size={13} />
                        )}
                        {isApplied ? 'Applied' : 'Apply'}
                      </button>
                    </div>
                  </div>

                  {/* #7: friendly table instead of raw JSON */}
                  {patch && <FriendlyPatch patch={patch} title="Will set" />}

                  {/* #6: confirm before overwriting site-wide cache settings */}
                  {confirming && (
                    <div className="mt-4">
                      <InlineConfirm
                        title={`Apply the "${p.label}" profile?`}
                        body={
                          <>
                            This overwrites your site-wide cache settings (cache lifetime, exclusions,
                            minify &amp; lazy options) with the {p.label} preset. Your existing safety
                            exclusions are kept. You can re-tune anything afterward in each panel.
                          </>
                        }
                        confirmLabel="Apply profile"
                        cancelLabel="Cancel"
                        busy={profileBusy?.id === p.id && profileBusy?.op === 'apply'}
                        onConfirm={() => confirmedApplyProfile(p.id)}
                        onCancel={() => setConfirmProfile(null)}
                      />
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </div>

      {/* Save & Share — export / import a portable profile (Pro). */}
      {proActive && (
        <div className="border border-line bg-paper rounded-card p-4 sm:p-5">
          {/* Header row mirrors the profile rows: title/desc left, the
              primary action (Export) pinned right. */}
          <div className="flex items-start justify-between gap-3">
            <div className="flex items-center gap-3 min-w-0">
              <Download size={18} className="text-primary shrink-0" />
              <div className="min-w-0">
                <div className="font-medium text-ink">Save &amp; share your config</div>
                <div className="text-[12px] text-muted">
                  Export this site&rsquo;s xSpeed settings as a portable profile, then import it on another site.
                </div>
              </div>
            </div>
            <button
              type="button"
              onClick={exportConfig}
              disabled={shareBusy !== null}
              className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-primary bg-primary text-on-primary hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-40 shrink-0"
            >
              {shareBusy === 'export' ? <RefreshCw size={13} className="animate-spin" /> : <Download size={13} />}
              Export profile
            </button>
          </div>

          {/* #3: tell the user secrets are stripped from the export so they
              know to re-enter them on the destination site. */}
          <div className="mt-3 text-[11px] text-muted leading-snug">
            For your security, API keys, tokens, and other secrets are <strong>excluded</strong> from the exported
            file — re-enter them manually on the destination site after importing.
          </div>

          {shareNotice && !shareError && <div className="mt-3"><Callout tone="success" title="Done" body={shareNotice} /></div>}

          <div className="mt-5">
            <div className="text-[10px] uppercase tracking-wider font-medium text-muted mb-1">
              Import a profile
            </div>
            <textarea
              rows={4}
              value={importText}
              onChange={(e) => {
                setImportText(e.target.value);
                setImportPatch(null);
              }}
              placeholder={__('Paste an xSpeed profile JSON here, or choose a .json file below.')}
              className="w-full font-mono text-[11px]"
              aria-label={__('Profile JSON to import')}
            />
            <div className="mt-2 flex flex-wrap items-center gap-2">
              <label className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-line text-ink-soft hover:bg-panel transition-colors cursor-pointer">
                <Upload size={13} />
                Choose file
                <input
                  type="file"
                  accept="application/json,.json"
                  className="hidden"
                  onChange={(e) => {
                    const f = e.target.files?.[0];
                    if (f) onImportFile(f);
                  }}
                />
              </label>
              <button
                type="button"
                onClick={previewImport}
                disabled={shareBusy !== null || !importText.trim()}
                className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-line text-ink-soft hover:bg-panel transition-colors disabled:opacity-40"
              >
                {shareBusy === 'preview' ? <RefreshCw size={13} className="animate-spin" /> : <Eye size={13} />}
                Preview
              </button>
              <button
                type="button"
                onClick={applyImport}
                disabled={shareBusy !== null || !importText.trim()}
                className="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded border border-primary bg-primary text-on-primary hover:border-primary-hover hover:bg-primary-hover transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
              >
                {shareBusy === 'apply' ? <RefreshCw size={13} className="animate-spin" /> : <ArrowRight size={13} />}
                Import &amp; apply
              </button>
            </div>

            {/* #1: import errors render INLINE here, next to the controls,
                with a friendly message — not the off-screen panel-top error. */}
            {shareError && (
              <div className="mt-3">
                <Callout tone="danger" title="Import error" body={shareError} />
              </div>
            )}

            {/* #4: confirm before Import & apply overwrites site-wide settings. */}
            {confirmImport && (
              <div className="mt-3">
                <InlineConfirm
                  title="Apply this imported profile?"
                  body={
                    <>
                      This overwrites your site-wide xSpeed settings with the values in the imported
                      profile (cache lifetime, exclusions, minify &amp; lazy options, …). Secrets aren&rsquo;t
                      included — re-enter them after. You can re-tune anything afterward in each panel.
                    </>
                  }
                  confirmLabel="Import & apply"
                  cancelLabel="Cancel"
                  busy={shareBusy === 'apply'}
                  onConfirm={confirmedApplyImport}
                  onCancel={() => setConfirmImport(false)}
                />
              </div>
            )}

            {importPatch && <FriendlyPatch patch={importPatch} title="Will import" />}
          </div>
        </div>
      )}
    </div>
  );
}
