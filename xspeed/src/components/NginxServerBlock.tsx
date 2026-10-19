import { useEffect, useMemo, useRef, useState } from 'react';
import { Server, ChevronDown, ChevronRight } from 'lucide-react';
import { CopySnippet } from './CopySnippet';
import { InfoPopover } from './InfoPopover';
import type { RewriteProbe, RewriteRules } from '../types';

const STORAGE_KEY = 'xspeed:nginx_server_block:expanded';
/**
 * The hash8 of the snippet this browser last copied. Read when the probe can
 * say nothing (`unknown`): a user who copied the block and pasted it is in a
 * different position from one who never copied it, and that is worth saying
 * even when we cannot see the server.
 */
const COPIED_HASH_KEY = 'xspeed:nginx_server_block:copied_hash';

/**
 * Unified nginx server-block panel — one consolidated snippet
 * aggregating directives from every enabled module (Cache static-rewrite,
 * BrowserCache headers, GZIP, …). Replaces the per-module "paste this
 * snippet" notices: user pastes once, re-pastes when toggling features.
 *
 * Collapsed by default — the snippet runs 30-50 lines and would dominate
 * the Cache panel if always-expanded. Persisted state lets users who
 * paste frequently keep it open. Topology-aware paste instruction lives
 * in the always-visible header so collapsed users still see "where to
 * paste" without expanding.
 *
 * The header answers, without expanding those 40 lines, the only question a
 * returning user has: are the rules on the server the rules my settings
 * describe now? See DESIGN.md §24.43 for the state table this renders.
 *
 * Renders nothing when `snippet` is null (non-nginx host, or no module
 * contributes directives this render).
 */
/** The focus anchor a deep link uses to target this block. */
export const NGINX_SNIPPET_ANCHOR = 'cache:nginx_snippet';

type Tone = 'ok' | 'warn' | 'neutral';

/** §12 status pill, one color family swapped per tone. */
const PILL_CLASS: Record<Tone, string> = {
  ok: 'bg-emerald-50 dark:bg-emerald-950/60 border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300',
  warn: 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-300',
  neutral:
    'bg-neutral-50 dark:bg-neutral-900 border-neutral-200 dark:border-neutral-700 text-neutral-600 dark:text-neutral-300',
};

const PILL: Record<RewriteRules['state'], { tone: Tone; label: string }> = {
  current: { tone: 'ok', label: 'Installed' },
  stale: { tone: 'warn', label: 'Out of date' },
  // nginx serves the hits, so not a warning. The block just can't be compared.
  unversioned: { tone: 'neutral', label: 'No version marker' },
  absent: { tone: 'warn', label: 'Not installed' },
  // Deliberately not a failure tone: we could not check. A red pill for our
  // own blind spot trains people to ignore the row.
  unknown: { tone: 'neutral', label: "Can't verify" },
};

/** English list — "A", "A and B", "A, B and C". */
function joinNames(names: string[]): string {
  if (names.length <= 1) return names[0] ?? '';
  return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

/**
 * One sentence for the state.
 *
 * No timestamp rides along: the probe reports what it saw, not when it looked,
 * and a "checked 2m ago" the server never sends is a claim we cannot stand
 * behind. The sentence has to carry the whole verdict on its own.
 *
 * `copiedHash` is the hash this user last copied — the only evidence available
 * when the probe is `unknown`.
 */
export function rulesStatusLine(rules: RewriteRules, copiedHash: string | null): string {
  switch (rules.state) {
    case 'current':
      return 'Your cache rules match your current settings';

    case 'stale': {
      // Naming the settings is the whole value of the line. When the server
      // could not resolve them we say which way the drift runs rather than
      // padding the sentence with "something".
      const changed = rules.changed.length
        ? `you changed ${joinNames(rules.changed)}`
        : 'your last settings change';
      return `Your cache rules are from before ${changed} · paste the block again and reload nginx`;
    }

    case 'unversioned':
      return "nginx serves cached pages, but the installed block has no version marker, so we can't tell whether it matches your settings. Your host may have written it; if you pasted it yourself, paste the block again and reload nginx.";

    case 'absent':
      return 'Cached pages are being served by PHP instead of nginx. Paste the block to enable the static path.';

    case 'unknown': {
      const base = 'We could not check your server config from here.';
      if (!copiedHash) return base;
      // `expected` is '' when these settings generate no snippet at all, and
      // then there is nothing for the copied hash to disagree with.
      if (rules.expected && copiedHash !== rules.expected) {
        return `${base} Your settings changed since you last copied the block — paste it again and reload nginx`;
      }
      return `${base} The block you last copied matches your current settings`;
    }
  }
}

export function NginxServerBlock({
  snippet,
  topology,
  rules,
  focus,
  onCopiedHash,
}: {
  snippet: string | null;
  topology?: RewriteProbe['topology'];
  /**
   * Verdict from the rewrite probe on the rules actually running. Absent on
   * surfaces that have no probe to hand (onboarding's Done step) — the block
   * then renders exactly as it did before the status line existed.
   */
  rules?: RewriteRules | null;
  /**
   * The route's current `focus` anchor. When it names this block, the panel
   * force-opens.
   *
   * Scrolling to it isn't enough: the panel is collapsed by default (the
   * snippet is 30-50 lines), so a CTA that says "Get the snippet" landed the
   * user on a closed box with the snippet still one un-obvious chevron away.
   * The whole point of that link is to put the snippet in front of them.
   */
  focus?: string;
  /**
   * Mirror the hash the user just copied to their user meta, so the verdict
   * survives a different browser. Only called when the server advertised the
   * mirror by returning a `copied` key on `rules`.
   */
  onCopiedHash?: (hash: string) => void;
}) {
  // Persisted collapsed state — read once at mount, write on toggle.
  // Default collapsed for the long-snippet UX.
  const [expanded, setExpanded] = useState<boolean>(() => {
    try {
      return window.localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
      return false;
    }
  });
  // Open when a deep link targets this block. Runs on `focus` changing, not
  // only at mount, so arriving from Overview while already on the Cache page
  // still opens it. Deliberately one-way: it never force-CLOSES, so a user who
  // had it open keeps it, and their persisted preference is left untouched —
  // this is a temporary reveal, not a change of setting.
  //
  // This is the ONLY path that expands the block on its own. No probe result
  // may open it: a persisted collapse that reopens itself reads as broken.
  useEffect(() => {
    if (focus === NGINX_SNIPPET_ANCHOR) setExpanded(true);
  }, [focus]);

  const persist = (next: boolean) => {
    try {
      window.localStorage.setItem(STORAGE_KEY, next ? '1' : '0');
    } catch {
      // Private-mode browsers can throw — state still flips for this session.
    }
  };

  const toggle = () => {
    setExpanded((cur) => {
      const next = !cur;
      persist(next);
      return next;
    });
  };

  /*
   * Whose record wins.
   *
   * This used to read `localCopied ?? rules?.copied?.hash`, so the BROWSER
   * outranked the site. localStorage is keyed by origin and nothing else, so
   * a hash in it says only that this browser once copied a block for some
   * site at this address — not that the site in front of us was ever pasted
   * into. QA reached it by wiping the database and reinstalling: the panel
   * still told the admin the block they last copied matched their settings,
   * on an install where nothing had ever been pasted. A database restore, a
   * clone, or two sites sharing a host does the same.
   *
   * So the site's own record is the truth whenever the site keeps one. Two
   * things still rank above it, and neither can be wrong:
   *
   *  - what this session just copied, which we watched happen; and
   *  - the browser's memory, but only where the server keeps no mirror at
   *    all, which is the `unknown`-probe case this key was added for.
   */
  const [sessionCopied, setSessionCopied] = useState<string | null>(null);
  const storedCopied = useMemo(() => {
    try {
      return window.localStorage.getItem(COPIED_HASH_KEY);
    } catch {
      return null;
    }
  }, []);
  // `copied` present — even as null — is the server saying it keeps the mirror.
  const serverKeepsMirror = !!rules && 'copied' in rules;
  const copiedHash =
    sessionCopied ?? (serverKeepsMirror ? (rules?.copied?.hash ?? null) : storedCopied);

  const recordCopy = () => {
    const hash = rules?.expected;
    if (!hash) return;
    setSessionCopied(hash);
    try {
      window.localStorage.setItem(COPIED_HASH_KEY, hash);
    } catch {
      // Same private-mode caveat; the in-memory value still serves this session.
    }
    if (serverKeepsMirror) onCopiedHash?.(hash);
  };

  // Auto-collapse the moment the paste took effect, and only then: the user
  // opened the block to fix something, and the close is the acknowledgement
  // that it is fixed. Every other transition leaves the disclosure alone.
  const prevState = useRef<RewriteRules['state'] | undefined>(rules?.state);
  useEffect(() => {
    const cur = rules?.state;
    const prev = prevState.current;
    prevState.current = cur;
    if (cur === 'current' && (prev === 'stale' || prev === 'absent' || prev === 'unversioned')) {
      setExpanded(false);
      persist(false);
    }
  }, [rules?.state]);

  if (!snippet) return null;

  const isContainer = topology === 'nginx-container';
  const pasteWhere = isContainer
    ? "your container's nginx config (typically docker/nginx.conf next to your docker-compose.yml)"
    : 'your nginx vhost (typically /etc/nginx/sites-enabled/<your-site>)';
  const lineCount = snippet.split('\n').length;
  const pill = rules ? PILL[rules.state] : null;

  return (
    // Focus target for the "Apply the nginx server snippet" recommendation
    // (issue #49) — the CTA lands on this block, not merely on the panel.
    <section
      data-field-anchor={NGINX_SNIPPET_ANCHOR}
      className="xspeed-field border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 rounded-lg overflow-hidden"
    >
      <div className="flex items-start gap-2.5 p-4 sm:p-5">
        <button
          type="button"
          onClick={toggle}
          aria-expanded={expanded ? 'true' : 'false'}
          aria-label={expanded ? 'Collapse server config' : 'Expand server config'}
          className="shrink-0 mt-0.5 rounded text-neutral-500 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1"
        >
          {expanded ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
        </button>
        <Server size={16} className="shrink-0 mt-0.5 text-neutral-500 dark:text-neutral-400" />
        <div className="flex-1 min-w-0">
          {/* Title + InfoPopover share an inline-flex, same pattern as
              ModulePanel's labelled fields. The info icon warns that the
              snippet is hand-pasted into nginx and must be removed before
              uninstalling — xSpeed can't retract it. */}
          <h2 className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-neutral-900 dark:text-neutral-50">
            <span>
              Server config <span className="text-neutral-400 dark:text-neutral-500 font-normal">· {lineCount} lines</span>
            </span>
            {pill && (
              <span
                data-testid="nginx-rules-pill"
                className={`text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded border font-medium ${PILL_CLASS[pill.tone]}`}
              >
                {pill.label}
              </span>
            )}
            <InfoPopover
              title="Good to know"
              body="You added this snippet to your nginx config yourself, so it's yours to manage — xSpeed never touches it. If you ever need to uninstall xSpeed, just remove this snippet from your nginx config first to keep everything tidy and your server running happily."
            />
          </h2>
          {rules && (
            <p
              data-testid="nginx-rules-line"
              className="text-[12px] text-neutral-500 dark:text-neutral-400 mt-0.5"
            >
              {rulesStatusLine(rules, copiedHash)}
            </p>
          )}
          <p className="text-[12px] text-neutral-500 dark:text-neutral-400 mt-0.5">
            Paste this once into {pasteWhere}, above the <code className="text-[11px]">location /</code> block, then reload nginx.
          </p>
        </div>
      </div>
      {expanded && (
        <div className="px-4 pb-4 sm:px-5 sm:pb-5">
          <CopySnippet content={snippet} tone="neutral" onCopy={recordCopy} />
        </div>
      )}
    </section>
  );
}
