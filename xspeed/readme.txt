=== xSpeed Cache: AI-Powered Performance Hub with MCP, Caching & CDN ===
Contributors: asif2bd, wpdevteam, seakashdiu, tushar284, hurayraiit, alimuzzamanalim
Tags: cache, performance, page speed, optimization, mcp
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete WordPress caching plugin: page cache, object cache, CDN, database cleanup and Core Web Vitals optimization, plus a built-in MCP server.

== Description ==

Your visitors decide to stay or leave in under 3 seconds. **xSpeed Cache** makes sure they stay.

Most caching plugins bury you in settings, charts and upgrade popups. xSpeed Cache does the opposite. Install it, flip one switch, and your site loads faster. No bloat, no upsells nagging you, no PhD required.

Built by the team at **WPDeveloper**, trusted by over 6 million WordPress users worldwide.

https://youtu.be/fYVy77GJDE4

= What This WordPress Caching Plugin Does =

xSpeed Cache is a full-site performance plugin. It caches your pages, shrinks your code, compresses your files and serves everything as fast as your server physically can, including an AI-powered audit that tells you exactly what is slowing your site down.

The result: pages that load in milliseconds, not seconds. Better Google PageSpeed scores. Happier visitors. Better SEO rankings.

If you have been searching for how to speed up WordPress site performance without hiring a developer, this is the shortest path there. It is built to speed up WordPress from the first page load, not after a week of tuning.

= The Speed Advantage No Other Free WordPress Cache Plugin Offers =

When xSpeed Cache caches a page, it does not just save it. It serves it directly from the server, bypassing PHP entirely.

That means:

* **With xSpeed static cache:** 5 to 15ms response time
* **Without it (PHP-served cache):** around 85ms response time

That is up to **17x faster** page delivery on cache hits. No other free WordPress cache plugin does this across Apache, nginx and LiteSpeed automatically, which is what makes it worth comparing against any other best WordPress cache plugin shortlist you are working from.

= Features at a Glance =

https://youtu.be/LlIb3XuQ-mo

**AI Assistants and the WordPress MCP Server**

xSpeed Cache ships a WordPress MCP server, so a Claude WordPress MCP connection, or any other MCP client, can inspect and tune your site through the same tools the dashboard uses. Every action is bounded by what you allow when you connect, which is what separates a real WordPress MCP plugin from a chatbot bolted onto a settings page.

* MCP server appears on the Overview as a capability card, so an AI connection can be set up without hunting for a panel
* OAuth discovery, with dynamic client registration bounded by stored-client limits, redirect URI limits, name and URI length limits and per-IP rate limiting
* Read-only MCP connections can read status through every per-command tool, while write actions stay refused
* Credentials cannot be written by an AI assistant unless you explicitly grant that permission when connecting
* WP-CLI parity: `wp xspeed purge`, `wp xspeed preloader status`, `wp xspeed score run`, `wp xspeed optimize` and `wp xspeed settings list|get|update`
* The `optimize_site` tool measures the site, applies recommended settings one at a time, re-checks the page after each change and reverts anything that breaks it

**Disable Bloat**

Turn off WordPress features you do not use. Each one is a request your server does not have to make.

* Disable dashicons on the frontend
* Remove oEmbed, RSS feeds, XML-RPC, jQuery Migrate and REST API authentication headers

**AI-Powered Site Audit (Pro)**

xSpeed Pro scans your specific site and tells you exactly which performance features will help the most, with severity ratings and concrete reasons rather than generic advice.


**Page Caching That Speeds Up Your WordPress Site**

Your pages are saved as static HTML files and served instantly to visitors, before WordPress even loads. This is the fastest way to speed up WordPress page delivery, and the cache rebuilds itself automatically whenever you publish a post, switch themes or update a plugin. You never have to think about it.

* Static-file rewrite path that bypasses PHP entirely for a 5 to 15ms TTFB
* Auto-purge on publish, update, comment and theme change
* One-click cache purge from the admin bar
* Exclude specific URLs such as `/cart`, `/checkout` and `/my-account`
* Set your own cache expiry, from 1 to 720 hours
* Separate mobile cache for phone and tablet visitors
* Per-post cache rules with custom expiry, or disable caching for individual posts

**Code Minification for WordPress Page Speed Optimization**

Strips all the unnecessary whitespace, comments and characters from your HTML, CSS and JavaScript without breaking anything. Smaller files are one of the most reliable WordPress page speed optimization wins available.

* Minify HTML, CSS and JavaScript
* Combine CSS and JS files to reduce HTTP requests
* Defer or delay JavaScript so it does not block page rendering
* Async CSS loading
* Removes `?ver=` query strings from asset URLs (generated files under `wp-content/uploads` keep theirs, so a page-builder or consent-banner edit still reaches returning visitors)
* Safe-minify automatically skips already-minified files and falls back if anything looks off

**GZIP Compression**

Makes every file your server sends smaller, so browsers download them faster.

* Auto-configures GZIP on Apache and LiteSpeed
* Shows a ready-to-paste config snippet for nginx and IIS
* Detects if your server already has GZIP active, so there is no double-configuration

**Lazy Load WordPress Images, Videos and Iframes**

Lazy load WordPress media so images, videos and iframes load only when a visitor scrolls to them, rather than all at once on page load.

* Lazy load images, iframes and videos
* Automatic Cumulative Layout Shift fix so your layout does not jump

**Font Optimization for WordPress Core Web Vitals**

Web fonts are one of the most common causes of slow-loading pages, and fixing them is one of the easiest ways to increase WordPress page speed. Together with lazy loading and the LCP preload, this is practical core web vitals optimization rather than a score-chasing trick.

* Adds `font-display: swap` so text is visible immediately while fonts load
* Preloads above-the-fold font files for instant rendering
* Removes the blank-text flash caused by slow Google Fonts responses

**Browser Cache**

Tells browsers to remember your static files such as images, CSS and JS, so returning visitors load your site even faster.

* Sets proper `Cache-Control` and `Expires` headers
* Works automatically with no manual configuration needed

**Cache Preloader**

Warms up your cache automatically so the very first visitor after a purge still gets a fast page.

* Sitemap-driven cache warmer
* Auto-warms cache when you publish or update content

**Redis Object Cache for WordPress (Redis and Memcached)**

Speed up database-heavy installs with a persistent object cache WordPress can rely on between requests. Set up Redis object cache WordPress support, or Memcached, in seconds and keep every repeated query in memory.

* Connect Redis or Memcached in seconds
* View status and flush the object cache from the dashboard
* Works as a drop-in persistent object cache, so object cache WordPress lookups are not repeated on every page load

**WordPress CDN Plugin Support with Cloudflare Integration**

Serve your static files from a WordPress CDN for faster global delivery. Point it at whichever provider you already use, including a WordPress CDN free tier, so you are not locked into one network while you decide which is the best WordPress CDN for your traffic.

* Pull-zone URL rewriting for any CDN provider
* Built-in Cloudflare WordPress CDN integration: connect your zone, auto-purge on publish and toggle development mode
* Works as a WordPress CDN plugin for any pull zone, with no separate connector to install

**WordPress Database Cleanup and Optimization**

A bloated database slows down every page load. This WordPress database cleanup plugin keeps yours clean and helps speed up WordPress database queries without a separate tool.

* Optimize database tables
* Remove post revisions, spam, trash, transients and orphaned meta
* Schedule automatic cleanup so it runs on autopilot

= Built to Handle WordPress Edge Cases =

xSpeed Cache handles the edge cases other plugins miss:

* **Multisite ready.** Each site in the network gets its own cache and settings.
* **LiteSpeed server?** xSpeed Cache detects the LiteSpeed Cache server module and steps back to avoid conflicts.
* **WooCommerce?** Logged-in customers and checkout pages are never cached.
* **WordPress Site Health.** xSpeed Cache adds its own health check under Tools then Site Health, so you always know your cache config is working correctly.
* **Works on any server.** Apache, nginx, LiteSpeed, IIS and any standard PHP host.

= Designed for Everyone =

**Non-technical users:** A 3-step setup wizard walks you through first-time configuration in under 2 minutes. Settings auto-save, so there is no Save button to forget.

**Developers:** REST API at `/wp-json/xspeed/v1/`, developer filters (`xspeed_skip_minify`, `xspeed_strip_asset_version`, `xspeed_cache_skip_for_post`, `xspeed_cache_expiry_for_post`), `WP_DEBUG` awareness, and a React 18 and TypeScript admin UI.

**Agencies:** Use the `xspeed_branding` filter to white-label the dashboard for clients.

= Private By Default =

xSpeed Cache never collects personal data, stores IP addresses or uses tracking cookies. Every optimization runs locally on your server. By default it makes no calls to any third-party server. The only request is a quick check to your own site's home URL to confirm GZIP is active, rate-limited to once per hour.

xSpeed Cache also includes **optional usage analytics**. You are asked in two places, both off by default: a clearly labeled consent control in the setup wizard, and the switch in xSpeed Cache → Settings → Privacy & usage data. Nothing is sent unless you opt in from one of them. When enabled, xSpeed Cache shares anonymous, non-sensitive diagnostics: your WordPress and PHP version, active theme and plugins, server type, site language, and which xSpeed Cache features you have switched on, so we know what to keep fast and compatible. No personal data and no page content are ever sent, and you can turn it off again at any time from xSpeed Cache → Settings → Privacy & usage data. See the External services section below.

= Backed By a Team You Trust =

xSpeed Cache is developed by the trusted team at WPDeveloper, a leading WordPress marketplace used and loved by millions of users.

= Loved xSpeed Cache? =

If xSpeed Cache makes your site faster, please leave a review on WordPress.org. It really helps.

== Installation ==

1. Upload the `xspeed` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress Plugins screen directly.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open xSpeed Cache from the admin menu. The 3-step setup wizard walks you through first-time configuration in under 2 minutes.
4. Settings auto-save, so there is no Save button to forget.

== Frequently Asked Questions ==

= Does this WordPress cache plugin work on my server? =

Yes. xSpeed Cache works on Apache, nginx, LiteSpeed, IIS and any standard PHP host. GZIP auto-configures on Apache and LiteSpeed, and a ready-to-paste config snippet is provided for nginx and IIS.

= Will it conflict with LiteSpeed Cache? =

No. xSpeed Cache detects the LiteSpeed Cache server module and steps back to avoid conflicts.

= Is it safe for WooCommerce? =

Yes. Logged-in customers and checkout pages are never cached, and you can exclude specific URLs such as `/cart`, `/checkout` and `/my-account`.

= Does it support multisite? =

Yes. Each site in the network gets its own cache and settings.

= Is this a WordPress MCP adapter? =

Not exactly. A WordPress MCP adapter usually exposes core WordPress data. The xSpeed Cache MCP server exposes the performance tools this plugin owns, such as cache purging, preloader status, score runs and settings reads and writes, so an assistant works on speed rather than on content.

= Can I connect Claude to it? =

Yes. A WordPress MCP Claude connection uses OAuth discovery, and the MCP server appears on the Overview as a capability card so you can set it up without hunting for a panel. Read-only connections stay read-only.

= Is xSpeed Cache the best free WordPress cache plugin? =

That is for you to judge, but here is the honest basis for comparison. The static-file rewrite path serves cached pages before PHP loads, across Apache, nginx and LiteSpeed, which most free plugins do not do. Object caching, CDN support and database cleanup are included rather than reserved for a paid tier.

= Can it replace my separate WordPress page speed plugin and CDN plugin? =

In most cases yes. Page caching, minification, GZIP, lazy loading, font optimization, browser caching, object caching, WordPress CDN support and database cleanup are all in one plugin, so you are not stacking a WordPress page speed plugin on top of a cache plugin on top of a database tool.

= Will it help my Core Web Vitals? =

It targets the parts a plugin can influence. The static cache improves server response time, the LCP preload picks the largest image on the page rather than the first, lazy loading defers offscreen media, and the automatic Cumulative Layout Shift fix stops the layout jumping. Page weight and DOM size are reported to you, since only a person can fix those.

= What is the MCP server for? =

The MCP server lets an AI assistant connect to your site and use the same tools the dashboard uses, such as purging cache, checking preloader status, running a score and reading or updating settings. Read-only connections stay read-only, and credentials cannot be written unless you explicitly grant that permission when connecting.

= Will this break my site? =

Very unlikely. xSpeed Cache includes safety checks for every optimization: minification falls back to the original if anything looks off, and the cache bypasses logged-in users, admin pages, AJAX and REST requests automatically. If something ever looks wrong, you can toggle any feature off individually.

= How do I clear the cache? =

Click **Purge** in the xSpeed Cache dashboard, or use **Purge xSpeed Cache** in your admin bar. The cache also clears itself automatically whenever you publish or update content.

= Can I use xSpeed Cache alongside Cloudflare? =

Yes. The built-in Cloudflare module connects your zone and auto-purges Cloudflare's cache whenever xSpeed Cache purges its own, so both caches stay in sync.

= Can I disable minification temporarily for debugging? =

Yes. Either toggle the option off in the admin UI, enable `WP_DEBUG` (xSpeed Cache automatically skips HTML minification when debug is on), or use the `xspeed_skip_minify` filter.

= Does xSpeed Cache send my data anywhere? =

By default, no. The only request is a check to your own site's home URL to confirm GZIP is active. Usage analytics and external performance scores are covered in detail in the External services section.

== Screenshots ==

1. Overview — cache status, hit ratio, cached pages, cache size, and average time saved at a glance.
2. Optimization — minify, compress, lazy-load, resource hints, fonts, and bloat control in one place.
3. Advanced Cache — cache 404s, search results, feeds, and the REST API, plus custom rules and maintenance bypass.
4. Browser Cache — Cache-Control and Expires headers with separate TTLs for static assets and HTML.
5. Health & Insights — diagnostics, hit ratio, cache coverage, and rule-based suggestions you can apply in one click.
6. MCP Server — control this site's cache from Claude and other AI agents over a token-scoped connection.
7. AI Provider — choose OpenAI, Anthropic, Google Gemini, or OpenRouter and set the model powering the AI features.
8. Migration — import settings from WP Rocket, W3 Total Cache, WP Super Cache, or LiteSpeed Cache.

== External services ==

xSpeed Cache contacts your own site (the gzip probe below); when usage analytics is enabled, an analytics service; only if you choose to submit the optional deactivation survey, that same service; and — only when you run a speed test yourself — one external performance-score provider. Analytics consent is asked in two places, both off by default — a clearly-labeled control in the setup wizard, and the switch in xSpeed Cache → Settings → Privacy & usage data — and nothing is sent unless you opt in from one of them; turning the switch off stops all collection and clears the scheduled send. The deactivation survey is separate and never sends anything unless you explicitly click Submit. External scores are off by default, turn on the first time you press Test, and never run on their own.

= Self-hosted gzip probe =

* **What it does:** Issues a single `GET` request to your site's home URL (`home_url('/')`) with an `Accept-Encoding: gzip` header to detect whether your web server is already serving gzipped responses. The response body is discarded; only the `Content-Encoding` header is read.
* **When it runs:** On demand when the admin dashboard loads server status, throttled to once per hour via a transient (`xspeed_gzip_active`).
* **Where the request goes:** Your own site (`home_url()`). This request goes only to your own server.
* **What is sent:** No personal data, no site identifiers, no payload — just a standard HTTP `GET` from your server back to your server.

== Third-party libraries ==

This plugin bundles the following GPL-compatible third-party libraries:

= matthiasmullie/minify =

Used for CSS and JavaScript minification.

* Source: https://github.com/matthiasmullie/minify
* License: MIT

= matthiasmullie/path-converter =

Dependency of `matthiasmullie/minify`.

* Source: https://github.com/matthiasmullie/path-converter
* License: MIT

= React / React DOM / Scheduler =

Used for the xSpeed Cache admin interface bundle.

* Source: https://github.com/facebook/react
* License: MIT

= lucide-react =

Used for admin interface icons.

* Source: https://github.com/lucide-icons/lucide
* License: ISC

== Changelog ==

= [1.3.3] – 2026-09-16 =

**Consent gets a dashboard home, the cache engine learns to leave non-HTML alone, and uncacheable pages now tell the CDN so.**

Privacy & Analytics:
- New: A "Privacy & usage data" panel to view and withdraw usage-analytics consent any time, with a matching wp xspeed privacy command.
- Improved: The setup wizard's analytics consent now defaults to off and is only ever an explicit opt-in.

Caching:
- Fixed: WordPress's virtual robots.txt (and favicon) is no longer cached or minified, so every directive and newline reaches crawlers intact.
- Improved: /robots.txt joined the default cache exclusions, and the sitemap pattern now matches sitemaps.xml too — existing installs keep their working exclusion through the rename.

Cloudflare & CDN:
- New: A page xSpeed refuses to cache now sends no-store edge headers, so a CDN never freezes a half-optimized or excluded page.
- Fixed: The deferred Cloudflare purge batch is bounded, cleared on deactivation, and says so when a purge is refused.

Optimization:
- Fixed: Scripts marked data-no-optimize or data-no-minify are left completely alone by minify, defer and delay — consent-manager configurations always ship current.
- Fixed: xSpeed's own scripts are never deferred or delayed by its own optimizer.
- Fixed: The Conservative preset now switches LCP preload and preconnect off, matching its "page cache + GZIP only" promise.

Dashboard & Admin UX:
- Improved: Preload now reports what actually happened — how many pages are warming, and the server's reason when a crawl cannot start.

Reliability:
- Improved: Uninstall now removes all usage-tracking state, the scheduled send, and every leftover option row.

= [1.3.2] – 2026-09-15 =

**Purging is now a contract other caches can join: clearing xSpeed's page cache also invalidates LiteSpeed Cache and the host's nginx FastCGI cache, and every purge is scoped to exactly the site and pages it was asked for — including on multisite.**

Caching:
- New: A purge-event contract lets other caching layers invalidate together with xSpeed, so a purge clears every cache that matters in one action.
- New: Purge All is forwarded to LiteSpeed Cache and the host's nginx FastCGI cache when they are present.
- Fixed: One post change triggers exactly one invalidation, deleting a media file purges again, and a purge still finds its post when the hook hands over none.
- Fixed: Purges are scoped per site on multisite — a purge no longer sends another site's URLs to this site's server cache, a default port is not treated as a different site, and full purges stay on the current site.
- Fixed: A purge listener that throws no longer aborts the purge, a query string of "0" is still treated as a query, and a partial purge reports itself as partial instead of claiming success.

Optimization:
- Fixed: Delaying JavaScript no longer breaks inline scripts bound to a delayed handle, and replayed external scripts keep their original load order.

= [1.3.1] – 2026-09-14 =

**Purge all now says what it actually cleared, on the button and in the toast, instead of claiming success when a store refused. Pro features are marked with one lock everywhere rather than amber squares and crowns, which also lifts the dimmed labels back to readable contrast. The Cloudflare panel is staged by connection state, so setup comes before the actions that depend on it — and the auto-purge switch is visible while disconnected instead of hidden.**

Dashboard & Admin UX:
- New: Purge all reports its outcome — Cleared, Already empty, Partly cleared or Purge failed — and the toast names the files and size cleared plus any other store, or the reason a store refused.
- Fixed: Pro features are marked with a single lock in the teal tint. Locked tabs are no longer dimmed to 70%, which had dropped their labels below readable contrast, and the amber squares, crowns and sparkles are gone from the tabs, badges and the sidebar tier slot.
- Fixed: Turning caching on no longer flashes "Another plugin owns the page cache on this site" while the toggle is in flight. A genuine foreign drop-in still shows the banner.
- Fixed: The Purge all button keeps its natural width at rest instead of reserving room for the outcome label.

Cloudflare:
- New: The panel is staged by connection state — Connection, then Actions — so credentials and Verify come before the buttons that need them, and an action that cannot work is disabled with a hint rather than failing silently.
- Fixed: The auto-purge-on-purge switch is always visible, dimmed while disconnected, instead of being hidden until connected. Hiding it is how the edge-sync switch went unnoticed.

Documentation:
- Fixed: The full changelog moved to changelog.txt, shipped with the plugin and linked from the plugin page. wordpress.org truncates a changelog over 5,000 words on import, so the history past the cut had been dropped.

= [1.3.0] – 2026-09-13 =

**A single page can now be purged straight from the admin bar, the post list or the editor, and known third-party scripts — analytics, chat widgets, tracking pixels — are delayed automatically without asking you to list them. A host or agency can pin any setting from wp-config.php with a constant: the dashboard shows the pin, names the constant, and lets an admin take the setting back. xSpeed can also take over an abandoned page-cache drop-in, with your consent, instead of being blocked by it forever.**

Caching:
- New: Purge a single page from the admin bar, the post list's row actions, or the editor — and the purge confirms what it actually removed instead of assuming.
- New: `wp xspeed purge` clears every cache in one call, and a purge is scoped to exactly what the caller asked for.
- New: Publishing or updating a post also purges the pages that list it, not just the post itself.
- New: An abandoned page-cache drop-in left by another plugin can be taken over with one consenting click, and a leftover husk of a drop-in no longer blocks caching forever.
- Fixed: A migration handover now completes instead of stopping halfway, from the dashboard and the CLI alike.
- Fixed: xSpeed no longer mistakes itself for a competing cache plugin.

Optimization:
- New: Known third-party scripts are delayed until interaction automatically, including the inline install snippets vendors ask you to paste into the header.
- New: Font stylesheets are deferred out of the render path, and a Google Fonts request that blocks rendering is rewritten to swap.
- New: A manual preload list covers the images no detector can see.
- Fixed: The LCP preload no longer picks a brand logo over the page's real hero image.
- Fixed: Deferring no longer breaks inline scripts that depend on another handle, combining no longer swallows scripts that delaying protects, and handle exclusions are honoured in the delay pass.
- Fixed: Valid minified JavaScript is no longer rejected, asset URLs are matched across schemes, an uploads folder at the domain root is recognised, and `?ver` is kept on files plugins rewrite in place.
- Fixed: Lazy loading reads an image's size from whichever attribute holds its URL, and no longer mistakes a real image for a placeholder because of a word inside its filename.

Settings:
- New: Any module setting can be pinned from wp-config.php with a constant. The dashboard shows a pinned field as read-only and names the constant that holds it.
- New: An admin can take a pinned setting back — the override displaces the host's constant and only that.
- Fixed: A panel containing a pinned field saves again, and editing a pinned setting is a single inline edit.

Object Cache:
- New: Redis credentials and Memcached servers are read from wp-config.php, and `WP_REDIS_PREFIX` is honoured as a key salt.
- Fixed: Cache keys are always namespaced per site, so two sites sharing a Redis or Memcached database can no longer read or purge each other's entries.
- Fixed: The dashboard panel gained every fix that had only reached the CLI, owns only what the plugin itself wrote, and no longer presents another plugin's object cache as xSpeed's.

Reliability:
- Fixed: Translations now load for both PHP and the dashboard, and the settings screens are translatable.
- Fixed: nginx sends one Cache-Control header per location instead of two, and the de-duplicated header no longer caches 404 responses.
- Fixed: An audit contributor that throws no longer takes the whole audit down with it, and add-ons can report findings of their own.

AI tools:
- Improved: The optimize assistant honours its cooldown, never measures without consent, keeps the score through a run of failures, and reports the newest score with its age.

Older releases are listed in changelog.txt, included with the plugin, and at [xspeedcache.com/changelog](https://xspeedcache.com/changelog/).
