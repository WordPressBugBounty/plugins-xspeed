import { useEffect, useState } from 'react';
import { Zap, X } from 'lucide-react';
import type { RewriteProbe } from '../types';
import { __ } from '../i18n';

/**
 * Persistent dashboard banner shown when the server-level rewrite
 * probe reports inactive. The PHP drop-in still serves the cache,
 * so nothing is broken — the banner is a "you could shave more
 * latency" nudge that points the user at the "Server config" panel
 * below (NginxServerBlock), which is the single source of the snippet.
 *
 * The banner deliberately does NOT render its own snippet. It used to,
 * which meant nginx hosts saw TWO config blocks at once — this banner's
 * cache-only copy and the Server config panel's unified copy — with
 * different line counts and paste instructions, leaving users unsure
 * which to paste. The banner now carries explanation only.
 *
 * Three variants per `probe.topology`:
 *   - 'htaccess'        Apache / LiteSpeed; .htaccess block needs
 *                       a reinstall or AllowOverride check.
 *   - 'nginx-host'      self-managed nginx; paste into host vhost.
 *   - 'nginx-container' nginx in the same container as PHP; paste
 *                       into the container's nginx config
 *                       (docker/nginx.conf), NOT the host vhost
 *                       a user would intuitively reach for first.
 *
 * Dismissal is keyed on snippet contents so a re-paste after the
 * snippet changes resurfaces the banner.
 */
export function RewriteAlert({
  probe,
  onOpenHealth,
}: {
  probe: RewriteProbe | null | undefined;
  onOpenHealth: () => void;
}) {
  // Only a PROVEN-inactive rewrite earns a banner. A pending probe (none has
  // run yet) and an inconclusive one (blocked loopback, TLS error, timeout, a
  // CDN answering instead of the origin) both tell us nothing — showing
  // "nginx detected — configure for max cache speed" in those states nagged
  // users whose server was already configured correctly, with no way to
  // dismiss it permanently or force a re-check. (FBS-84012)
  const visible = !!probe && !probe.active && !probe.pending && !probe.inconclusive;
  const dismissKey = probe?.snippet
    ? `xspeed:rewrite_alert:${hashCode(probe.snippet)}`
    : 'xspeed:rewrite_alert:apache';
  const [dismissed, setDismissed] = useState(false);

  useEffect(() => {
    if (!visible) return;
    try {
      setDismissed(window.localStorage.getItem(dismissKey) === '1');
    } catch {
      setDismissed(false);
    }
  }, [visible, dismissKey]);

  if (!visible || dismissed) return null;

  const dismiss = () => {
    try {
      window.localStorage.setItem(dismissKey, '1');
    } catch {
      // Private-mode browsers can throw; banner just stays this session.
    }
    setDismissed(true);
  };

  // Three banner variants — pick based on where the snippet needs to land.
  // 'nginx-container' is the case we missed before: nginx is inside the
  // same container as PHP, so the snippet goes into docker/nginx.conf
  // (or equivalent), NOT the host vhost a customer might assume.
  const topology = probe.topology
    ?? (probe.server_type === 'nginx' ? 'nginx-host' : 'htaccess');

  // On a managed/containerized host behind a reverse proxy or CDN (xclude,
  // Kinsta, Cloudflare-fronted, …), the nginx that actually terminates the
  // request is almost never one the user can edit — so the static-rewrite
  // snippet has nowhere useful to go. Telling them to "paste into
  // docker/nginx.conf" is a dead end that leaves the banner up forever and
  // the HIT log empty. Be honest instead, and don't show a snippet.
  const managedUnverifiable =
    topology === 'nginx-container' && probe.behind_proxy === true;

  // The banner never renders a snippet — the "Server config" panel
  // below is the single source of truth. Each variant's copy points
  // the user there so there's no ambiguity about which config to paste.
  let title: string;
  let body: string;
  if (managedUnverifiable) {
    title = __('Static rewrite may not be available on this host');
    body =
      __('Your cache still works on every request — xSpeed\'s PHP drop-in is serving cached HTML, so nothing is broken. But this site sits behind a reverse proxy or CDN, which usually means a managed/containerized host where the nginx that serves requests isn\'t one you can edit. The extra-fast server-level rewrite (5–15ms static hits) needs that nginx, so it likely can\'t be enabled here — and that\'s also why the cache HIT log stays empty (nginx never serves the static file, so there\'s nothing to log). If you DO control the request-terminating nginx, use the snippet in the "Server config" panel below.');
  } else {
    switch (topology) {
      case 'nginx-host':
        title = __('nginx detected — configure for max cache speed');
        body = __('Your cache works on every request — xSpeed\'s PHP drop-in is serving cached HTML. To shave additional latency, copy the snippet from the "Server config" panel below into your nginx vhost (typically /etc/nginx/sites-enabled/<your-site>) and reload nginx so cached pages skip PHP entirely.');
        break;
      case 'nginx-container':
        title = __('Containerized nginx detected — paste into your container config');
        body = __('Your cache works on every request — xSpeed\'s PHP drop-in is serving cached HTML. For the full server-level rewrite, copy the snippet from the "Server config" panel below into your container\'s nginx config (typically docker/nginx.conf next to your docker-compose.yml). The host nginx in front of your container only proxies traffic; pasting there has no effect.');
        break;
      default:
        title = __('Server config missing — cache running on PHP fallback');
        body = __('Your cache works on every request — xSpeed\'s PHP drop-in is serving cached HTML. To engage the faster server-level rewrite, toggle Cache off and on to reinstall the .htaccess block, and confirm AllowOverride is enabled for your site.');
    }
  }

  return (
    <div className="border border-amber-300 dark:border-amber-900/60 bg-amber-50 dark:bg-amber-950/30 rounded-lg p-4 sm:p-5 relative">
      <button
        type="button"
        onClick={dismiss}
        aria-label={__('Dismiss banner')}
        className="absolute top-2 right-2 p-1 rounded text-amber-700/70 hover:text-amber-900 dark:text-amber-300/70 dark:hover:text-amber-100 hover:bg-amber-100 dark:hover:bg-amber-900/40"
      >
        <X size={14} />
      </button>
      <div className="flex items-start gap-2.5 pr-6">
        <Zap size={16} className="shrink-0 mt-0.5 text-amber-600 dark:text-amber-400" />
        <div className="min-w-0 flex-1">
          <div className="font-medium text-amber-900 dark:text-amber-100 text-[13px]">{title}</div>
          <p className="text-[12px] text-amber-800/90 dark:text-amber-200/80 mt-1">{body}</p>
          <div className="mt-3 flex items-center gap-3">
            <button
              type="button"
              onClick={onOpenHealth}
              className="text-[12px] font-medium text-amber-900 dark:text-amber-100 underline underline-offset-2 hover:text-amber-700 dark:hover:text-amber-300"
            >
              View in Health →
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

// Stable 32-bit hash for localStorage dismissal keys. djb2 variant —
// no security claim, just enough to detect snippet changes so a
// dismissed banner re-shows when the snippet content updates.
function hashCode(s: string): string {
  let h = 5381;
  for (let i = 0; i < s.length; i++) {
    h = ((h << 5) + h + s.charCodeAt(i)) | 0;
  }
  return (h >>> 0).toString(36);
}
