# xSpeed

A WordPress caching and performance plugin. This glossary covers the free plugin
and the terms the Pro add-on builds on. Pro-only terms are out of scope here;
xSpeed Pro carries its own glossary.

## Language

### Structure

**Module**:
One feature of the plugin, with its settings, screens and commands declared in one place.
_Avoid_: extension, component

**Tier**:
Whether a module is part of Free or Pro.
_Avoid_: plan, edition

**Seam**:
A published filter or action that lets Pro change Free's behaviour without editing Free.
_Avoid_: boundary, integration point

**Pro state**:
Free's view of Pro on this site: not installed, installed without a license, or active.

### Caching

**Page cache**:
The stored HTML of a page, served before WordPress runs.
_Avoid_: HTML cache. Not **full-page cache** either — that already means the
web server's own cache, below.

**Host page cache**:
A full-page cache owned by the WEB SERVER, in front of PHP — nginx FastCGI,
LiteSpeed. xSpeed forwards purges to it rather than managing it.
_Avoid_: server cache

**Drop-in**:
A file in `wp-content` that WordPress loads in place of its own code, and that only one plugin can own.

**Foreign drop-in**:
A drop-in written by another plugin. No unattended path touches one: auto-heal
and activation stand down. A person can still take one over from the dashboard
after a prompt that names what they are replacing, and the object-cache
installer backs the old file up to `uploads/xspeed-backups/` first.

**Profile**:
What ACTIVATION decided the defaults should be, with no one asked: `recommended`
on a clear site, `conflict-safe` when something else owns the page cache,
`host-page-cache` when a host plugin installed us. `Settings::install_profile()`.
_Avoid_: preset, mode

**Preset**:
What the USER picked in the onboarding wizard — conservative, balanced,
aggressive or custom. A different act from the profile: one is chosen, the other
is inferred. `PresetId` in `src/onboarding/`.
_Avoid_: profile

**Host plugin**:
Another WPDeveloper plugin that installed xSpeed for the user. Distinct from
**host page cache** above, which is about the web server, and from the
`host-page-cache` profile, which is what a host plugin's install produces.

**Object cache**:
A persistent key-value store (Redis or Memcached) for WordPress data between requests.

**Edge**:
A cache that sits in front of the site, such as a CDN or Cloudflare, and serves pages before they reach the server.

**Purge**:
Removing cached pages so the next visitor gets a freshly rendered one.
_Avoid_: invalidate (as the verb). **Flush** is the object cache's word, not a
synonym — `flush_object_cache` empties a key-value store, it purges nothing.

**Invalidation**:
Why a purge happened, published with every purge as a `cause`, a `scope` and an
`intent`. Adapters decide what to clear from these rather than from the hook
that fired.

**Cause**:
Who asked for a purge — the hook, command or button. Not the same as intent:
cause is who, intent is what changed.

**Scope**:
How far an invalidation reaches: `urls`, `site`, `network`, or `none`.

**Intent**:
What changed underneath the cache: `content` (what a page says), `presentation`
(how every page looks) or `complete`. A server-cache adapter that only forwards
some purges reads this.

**CDN**:
A host that serves the site's static assets from rewritten URLs.

**Preload**:
Crawling the sitemap on a schedule, and after a purge or a comment, so pages are
cached before a visitor arrives. **Warm** is the verb for what it does to a page
and **crawl** for how it finds them; the module's own settings use both.
_Avoid_: prime

### Assets

**Minify**:
Rewriting CSS, JS or HTML smaller without changing what it does. All three are
separate settings.

**Combine**:
Merging several CSS or JS files into one request. Separate from minify, and
either can be on without the other.
_Avoid_: concatenate, bundle

### Measuring

**xSpeed Scan**:
A graded report on the whole site, made of checks across four dimensions. It
returns a **scan score** out of 100 and a letter **grade**.
_Avoid_: audit

**Check**:
One graded item inside an xSpeed Scan, with its evidence and a suggested fix.
_Avoid_: test, rule

**Provider score**:
A performance number from outside — PageSpeed Insights or GTmetrix. A different
scale from a scan score and never shown as one number with it: a Lighthouse
result is ONE check inside a Scan, worth 18 of its 100 points. Say which score
is meant; bare "score" is ambiguous in this codebase, and the `score` module
houses both.
_Avoid_: bare "score" where both could be meant

**Benchmark**:
xSpeed's own TTFB measurement, taken locally. A third number, and not a score.

### AI access

**MCP server**:
The endpoint on the site that lets an AI client read and control xSpeed.

**Tool**:
One action an AI client can call through the MCP server.
_Avoid_: function, endpoint

**Grant scope**:
What a connected AI client is allowed to do, as a space-separated set drawn from
`mcp`, `read`, `write` and `configure`. `mcp` is the umbrella clients ask for
(read+write); `configure` is additive, must be asked for by name, and is never
implied — so credential writes stay off on an ordinary connection. Unrelated to
the purge `scope` above; say **grant scope** whenever both could be meant.

**Pairing**:
Connecting one site to one AI client with a site token. Separate from attaching
the site to the Hub.

**Hub**:
The hosted xSpeed account a site attaches to.
_Avoid_: dashboard, cloud
