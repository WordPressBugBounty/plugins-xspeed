=== xSpeed Cache: AI-Powered Performance Hub with MCP, Caching & CDN ===
Contributors: asif2bd, wpdevteam, seakashdiu, tushar284, hurayraiit, alimuzzamanalim
Tags: cache, performance, page speed, optimization, mcp
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete WordPress caching plugin: page cache, object cache, CDN, database cleanup and Core Web Vitals optimization, plus a built-in MCP server.

== Description ==

Your visitors decide to stay or leave in under 3 seconds. **xSpeed Cache** makes sure they stay.

Most caching plugins bury you in settings, charts and upgrade popups. xSpeed Cache does the opposite. Install it, flip one switch, and your site loads faster. No bloat, no upsells nagging you, no PhD required.

Built by the team at **WPDeveloper**, trusted by over 7 million WordPress users worldwide.

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
* Removes `?ver=` query strings from asset URLs
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

= Built to Handle WordPress Edge Cases =

xSpeed Cache handles the edge cases other plugins miss:

* **Multisite ready.** Each site in the network gets its own cache and settings.
* **LiteSpeed server?** xSpeed Cache detects the LiteSpeed Cache server module and steps back to avoid conflicts.
* **WooCommerce?** Logged-in customers and checkout pages are never cached.
* **WordPress Site Health.** xSpeed Cache adds its own health check under Tools then Site Health, so you always know your cache config is working correctly.
* **Works on any server.** Apache, nginx, LiteSpeed, IIS and any standard PHP host.

= Designed for Everyone =

**Non-technical users:** A 3-step setup wizard walks you through first-time configuration in under 2 minutes. Settings auto-save, so there is no Save button to forget.

**Developers:** REST API at `/wp-json/xspeed/v1/`, developer filters (`xspeed_skip_minify`, `xspeed_cache_skip_for_post`, `xspeed_cache_expiry_for_post`), `WP_DEBUG` awareness, and a React 18 and TypeScript admin UI.

**Agencies:** Use the `xspeed_branding` filter to white-label the dashboard for clients.

= Private By Default =

xSpeed Cache never collects personal data, stores IP addresses or uses tracking cookies. Every optimization runs locally on your server. By default it makes no calls to any third-party server. The only request is a quick check to your own site's home URL to confirm GZIP is active, rate-limited to once per hour.

xSpeed Cache also includes **optional usage analytics**. The setup wizard shows a clearly labeled consent control for it (enabled by default, untick to opt out), and nothing is sent until you confirm your choices in the wizard. When enabled, xSpeed Cache shares anonymous, non-sensitive diagnostics: your WordPress and PHP version, active theme and plugins, server type, site language, and which xSpeed Cache features you have switched on, so we know what to keep fast and compatible. No personal data and no page content are ever sent, and you can turn it off again at any time from your dashboard. See the External services section below.

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

xSpeed Cache contacts your own site (the gzip probe below); when usage analytics is enabled, an analytics service; only if you choose to submit the optional deactivation survey, that same service; and — only if you turn it on — one external performance-score provider. The setup wizard shows a clearly-labeled consent control for analytics (enabled by default, untick to opt out), and nothing is sent until you confirm your choices there. The deactivation survey is separate and never sends anything unless you explicitly click Submit. External scores are off by default and never run on their own.

= Self-hosted gzip probe =

* **What it does:** Issues a single `GET` request to your site's home URL (`home_url('/')`) with an `Accept-Encoding: gzip` header to detect whether your web server is already serving gzipped responses. The response body is discarded; only the `Content-Encoding` header is read.
* **When it runs:** On demand when the admin dashboard loads server status, throttled to once per hour via a transient (`xspeed_gzip_active`).
* **Where the request goes:** Your own site (`home_url()`). This request goes only to your own server.
* **What is sent:** No personal data, no site identifiers, no payload — just a standard HTTP `GET` from your server back to your server.

= Usage analytics (opt-in, off by default) =

* **What it does:** When usage analytics is enabled, xSpeed Cache periodically sends anonymous, non-sensitive diagnostics to help us keep the plugin fast and compatible with real-world WordPress environments. Data sent: site URL, site name, WordPress version, PHP version, site language &amp; charset, multisite flag, server software, your list of active &amp; inactive plugins, active theme &amp; version, text direction, and your xSpeed Cache feature usage (which optimization features you have switched on and their numeric settings — e.g. cache lifetime, minification, lazy-load). This is feature-usage data only: it tells us which features are actually used so we can focus on what matters. **We never send any values you enter** — no API keys, tokens, passwords, license keys, email addresses, URLs, brand names, or logos — and **no page content, no visitor data, and no personal data** (your email is never collected in the free plugin).
* **When it runs:** It runs at most once per day via WP-Cron, only after you confirm your choices in the setup wizard. Untick the consent control (or disable it later from the dashboard) and nothing is ever sent.
* **Where the request goes:** WP Insights, WPDeveloper's usage-analytics service — `https://send.wpinsight.com/process-plugin-data`. ([Privacy Policy](https://wpdeveloper.com/privacy-policy))
* **How to turn it off:** Untick the consent control in the wizard, or disable it later from the xSpeed Cache dashboard. Opting out stops all data collection and clears the scheduled task.

= External performance scores (opt-in, off by default) =

* **What it does:** Lets you run a performance audit of one of your own pages from the dashboard and shows the score alongside xSpeed Cache's internal TTFB benchmark. Two providers are supported: Google PageSpeed Insights (works without an API key; you may add your own key to raise Google's anonymous rate limit) and GTmetrix (requires your own API key — it has no anonymous mode).
* **When it runs:** Only when you press **Test now** on the dashboard, or run `wp xspeed score run`. There is no schedule and no background call. If the feature is left off — which is the default — no request is ever made.
* **Where the request goes:** Google PageSpeed Insights — `https://www.googleapis.com/pagespeedonline/v5/runPagespeed` ([Terms](https://developers.google.com/speed/docs/insights/v5/about), [Privacy Policy](https://policies.google.com/privacy)) — or GTmetrix — `https://gtmetrix.com/api/2.0/tests` ([Terms](https://gtmetrix.com/terms-of-service.html), [Privacy Policy](https://gtmetrix.com/privacy.html)).
* **What is sent:** The URL you are testing (your own page, which the provider then fetches publicly) and, if you supplied one, your own API key for that provider. No personal data, no visitor data, and no site content are sent by the plugin.
* **How to turn it off:** Switch "Enable external scores" off in the xSpeed Cache dashboard (Health → PageSpeed). Past results stay in your database until you clear them.

= Deactivation feedback (optional, only when you submit it) =

* **What it does:** When you deactivate xSpeed Cache, a short optional survey asks why, so we can fix problems and prioritize the right features. Nothing is sent unless you pick a reason and click **Submit &amp; Deactivate**.
* **What is sent:** the reason (or reasons) you selected and any note you type. If usage analytics (above) is enabled, that reason is attached to your regular analytics report. If usage analytics is disabled, only a minimal message is sent — the reason, your optional note, your site URL, the xSpeed Cache version, and your WordPress &amp; PHP version — and nothing else (no plugin list, no theme data, no personal data, no page content).
* **When it runs:** Only on the deactivation screen, and only when you click **Submit &amp; Deactivate**. Choosing **Skip &amp; Deactivate**, closing the dialog, or pressing Esc sends nothing at all.
* **Where the request goes:** WP Insights, WPDeveloper's service — `https://send.wpinsight.com/process-plugin-data`. ([Privacy Policy](https://wpdeveloper.com/privacy-policy))
* **How to avoid it:** Click **Skip &amp; Deactivate** (or close the dialog) instead of Submit — deactivation works exactly the same, with no data sent.

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

= [1.2.3] – 2026-09-02 =

**xSpeed now recognises which plugin owns the page cache and refuses to take it, so installing beside WP Rocket, WP Super Cache or LiteSpeed no longer switches thirteen features on behind you or leaves two page caches competing. Switching caching on is now all-or-nothing, so a half-finished attempt can no longer leave a site with a broken wp-config.php. Combining CSS no longer strips a site's styles when Critical CSS is on, and page builders can be edited again.**

Caching:
- New: xSpeed recognises which plugin owns the page cache before switching anything on, and stands down when the field is taken. A site already running another cache plugin installs with every feature held off and that plugin's files untouched, instead of coming up with thirteen switches on.
- New: Switching page caching on is now all-or-nothing. The drop-in, the wp-config.php line, the rewrite rules and the settings are written as one step and rolled back together if any part fails, so a half-finished attempt can no longer leave the site in a state it cannot recover from.
- Fixed: Enabling the cache no longer corrupts wp-config.php, and a refusal is no longer reported as success.
- Fixed: The dashboard now names the plugin that blocked page caching instead of silently switching the toggle back off — on the dashboard and at the end of the setup wizard.
- Improved: The dashboard reports whether the cache is actually serving pages, rather than what the setting says it should be doing.
- Improved: Conflicting plugins are identified from one catalogue rather than two lists that had drifted apart. Swift Performance was missed entirely, and Autoptimize was wrongly reported as a page-cache conflict.
- Fixed: A "left cache files behind" note now names only the plugin that could have left them, and xSpeed no longer blames itself for its own cache folder while inactive.
- Fixed: A stray WP_CACHE line left in wp-config.php by a removed plugin is no longer mistaken for another plugin owning the cache. A site with no cache plugin at all installed with everything switched off while the wizard said no conflicts were found, and reinstalling could not fix it, because uninstalling left those switches behind.
- Fixed: Hosts that were never in conflict are no longer refused.

Optimization:
- Fixed: Combining CSS no longer leaves the site unstyled when Critical CSS is on. Sheets deferred by Critical CSS were mistaken for print-only stylesheets, merged into a print-only bundle and stripped of the swap that applies them, so visitors were served a page with no styles at all.
- Fixed: A Google Fonts stylesheet is no longer cut in half when CSS is combined. Font addresses carry semicolons inside them, and the rule was truncated at the first one.
- Fixed: Delaying JavaScript no longer rewrites attributes belonging to other plugins, and a script keeps its own type.
- Fixed: Minification stands down on page-builder editing screens. Beaver Builder, Elementor, Divi, Brizy and Oxygen edit over a front-end address rather than inside the dashboard, so their editors were being minified and broken.
- Improved: The combined stylesheet is minified as one file, rather than only the parts that went into it.

Integrations:
- New: Another WPDeveloper plugin can now install xSpeed for you and have it come up with page caching on and nothing else switched on, without either plugin carrying its own copy of the rules.

AI tools:
- Improved: Tools generated from the plugin's own commands now describe themselves for an assistant rather than for a terminal, so an AI agent picks the right one more often. This covers most of the tools on a Pro site.

= [1.2.2] – 2026-08-31 =

**Forms on cached pages stop being rejected, because a page is no longer served for longer than the security token it carries. Stores keep their cache through orders and reviews while price and stock changes actually clear it, the cache lifetime you configure is finally the one enforced, and the AI tools can no longer delete content without showing you what they found first.**

Caching:
- Fixed: A form on a cached page no longer fails to submit. A cached page could outlive the security token baked into it, so on any site set above 24 hours every anonymous form — contact, checkout, add-to-cart, course enrolment — was rejected with the other plugin's own error message. Pages carrying a token now expire with it, and pages without one keep the full lifetime.
- Fixed: The cache lifetime you configure is now the one that is enforced. The fast path applied a fixed 24 hours regardless of the setting, so a 12-hour lifetime served pages for up to 25 hours, and a 7-day one rebuilt the page from scratch for 6 days out of 7. Per-post expiry overrides now reach it too.
- Fixed: Editing a template, template part, global styles, a navigation menu or a synced pattern clears the cache again. None of those has a page of its own, so a Site Editor change updated nothing visitors could see until the lifetime ran out.
- Fixed: Saving a cache setting no longer replaces another cache plugin's drop-in on a site where xSpeed page caching is switched off. That could leave the site with no page cache at all after xSpeed was later switched off.
- Improved: The activity log now names what triggered each purge — "Cache purged (hook:wp_update_nav_menu)" instead of a bare number.
- Fixed: A damaged compressed copy of a cached page is no longer sent to visitors. A copy that was cut off part-way through cannot be decompressed, so the browser threw away the page it had and drew a blank one instead. Compressed copies are now written in one step so a half-written one never becomes visible, and each is stored with its own length so one that does not match is skipped and the normal page is served.

WooCommerce:
- Fixed: A price, sale or stock change now clears the product's cached pages. Those are written straight to the database without the hook the cache listened for, so a store could quote one price on a cached page and charge another at checkout for the whole cache lifetime. The product page, the shop archive, its category and tag archives and a storefront front page are all cleared.
- Fixed: Placing an order, a customer registering at checkout, or a visitor leaving a product review no longer wipes the entire site's cache. Guest reviews are on by default, so any visitor could flush a store's cache repeatedly with no account. A review now clears only the page it was left on, and only once approved.

AI tools:
- Fixed: The database cleanup tool can no longer permanently delete posts, revisions and comments without a scan first. A scan now issues a single-use token bound to what it found, and the cleanup refuses without one — including through the general command route, which previously bypassed the check entirely. Both tools now state plainly that the deletion cannot be undone.
- New: A public signals route (`/xspeed/v1/signals`) reports the plugin version and whether the AI server and Hub are connected, so external audit tools can identify the site. No tokens, accounts or paths are exposed.
- Fixed: An admin who is deleted or removed from a site no longer leaves the site reporting a Hub connection that no longer exists.

Migration:
- New: The import offer now migrates in place. Pressing Import in the notice previously only navigated to another screen and asked you to find the button again; the consequence — that migrating deactivates the old plugin — is now stated under the button rather than in a dialog after the click.
- Fixed: A migration that worked no longer leaves both cache plugins active. The handover was refused whenever any part of the import fell short, and enabling the object cache always fails on a host without Redis or Memcached — so two page caches were left running, which is the exact conflict deactivating exists to prevent. An optional module that could not be enabled is now a footnote, not a failure.

Recommendations:
- Fixed: Pro features that are already switched on are no longer recommended for purchase. Image conversion is enabled per format and runs on defaults with nothing stored, so a site converting every upload was still told to buy image conversion, forever.
- Fixed: Recommendations reappear when Pro is deactivated or its licence expires. Pro leaves its settings behind, so reading those rows alone silently hid the suggestions on a site that was no longer running the feature.

Health:
- Fixed: Browser caching is reported from the headers actually served, not from xSpeed's own snippet. A site whose caching headers come from its host, a container or a proxy was permanently warned about a problem it did not have and could not act on. A probe that fails to complete no longer counts as proof of a missing header.

= [1.2.1] – 2026-08-27 =

**Self-hosted videos no longer download in full before anyone presses play, the autopilot now undoes a change that makes the site slower instead of calling it verified, and the diagnosis finally reports the content problems it was measuring all along.**

Optimization:
- Fixed: A self-hosted video with its own preload setting is no longer skipped. "Lazy-load HTML5 videos" now overrides a player's preload="auto" or "metadata", which is where the setting was meant to help — a page carrying a 924KB video no longer transfers it before anyone presses play. Autoplaying videos are left alone.
- New: The click-to-play facade now covers self-hosted videos that have a poster image, not just YouTube and Vimeo embeds. The file downloads on click; without JavaScript the original video is still there.
- New: Each optimization step is now measured, and a change that makes the page measurably slower is undone instead of being reported as verified. A change inside normal run-to-run variation is reported as unchanged rather than claimed as a win.
- Fixed: An image the theme has already marked as the most important one on the page is no longer lazy-loaded, which delayed the very image it was told to prioritise.

Diagnosis and reporting:
- Fixed: The reported score came from the oldest audit on record rather than the newest, so re-measuring never moved the number. The newest successful run is now used, and a failed audit is never presented as the current score.
- Fixed: Page weight, heavy videos and DOM size were measured but never shown. The content problems only a person can fix are now reported alongside the settings the plugin can change itself.
- New: The last audit's own named opportunities, with their measured savings, now feed the diagnosis — routed to either the settings the plugin can change or the content a person has to.

Reliability:
- Fixed: A missing plugin file no longer takes down wp-admin. xSpeed now stops cleanly and names the file that is missing, so the Plugins screen still loads and the plugin can be switched off to recover.
- Fixed: An unresolvable class is reported with the path of the file that is missing, rather than only the class name, so an interrupted update or a quarantined file can be identified from the log.

Caching:
- New: The cache is cleared when a plugin, theme or WordPress core is updated, so a page is never served from markup the updated code no longer produces. It clears network-wide on multisite, can be switched off, and leaves the cache alone for a plugin you installed but never activated or for a nightly language-pack update.
- Improved: Visitors arriving with a tracking parameter such as utm_source, fbclid or gclid are now served from the fast path instead of loading WordPress in full.
- Fixed: Add-ons can now read the signal that says the cache was already cleared by an update, so the cache is no longer cleared a second time.

Settings and AI tools:
- New: A setting that is accepted but has no effect on your server now says so. Enabling Brotli on a server without Brotli support reported plain success while nothing changed; the tools that flip the setting now report the same warning the dashboard shows.
- Fixed: A URL field that is posted to now rejects an address that cannot be called. An email address entered in one of these fields was silently accepted and the feature then failed with nothing on screen to explain why.
- Fixed: Score history now reports whether each audit succeeded, and the error when one did not, so a failed run is no longer indistinguishable from a run that returned no score.

Dashboard:
- Fixed: Switching tabs on Health & insights no longer jumps the page back to the top.
- Fixed: A shorter tab is no longer padded out to the height of the tallest one, which left a screen of blank space below the content. Tab strips are also fully wired for screen readers and keyboard navigation.

Setup wizard:
- Fixed: The wizard opens with Balanced selected instead of no preset, warns in both directions when a setting is changed away from the preset, and uses the same labels throughout.
- Fixed: A brand-new install showed all three preset cards as inactive, because nothing had been configured yet. The cache lifetime also starts at 7 days rather than empty.

= [1.2.0] – 2026-08-24 =

**One command now measures your site, applies the settings that help, and undoes anything that breaks. Four cache-poisoning routes are closed, importing from W3 Total Cache carries settings across faithfully instead of a handful, and combined JavaScript keeps running in the order the page expects.**

Security:
- Fixed: A page rendered with a query string is no longer stored under the bare URL's cache key, where it would then be served to every visitor asking for the plain URL.
- Fixed: Percent-encoded query keys can no longer poison the cache. Two spellings of the same key resolved to different cache keys, so a crafted URL could seed the entry a normal request would later read.
- Fixed: An allow-listed tracking parameter no longer lets a crafted URL overwrite a cached page.
- Fixed: A search-results page no longer poisons the homepage through the cache key.

Optimization:
- Added: `wp xspeed optimize` and the `optimize_site` AI tool make the whole tuning loop one command. It measures the site, applies the recommended settings one at a time, re-checks the page after each change, and reverts anything that breaks it — then reports what it applied, what it undid and why, and what it could not reach at all (page weight, hotlinked images, DOM size) rather than claiming a win it did not make.
- Added: Images hosted on another domain get their real dimensions resolved, so lazy loading and the preloader no longer skip them.

Migration:
- Fixed: Importing from W3 Total Cache brought across 8 of 510 settings. Page cache, browser cache, object cache, exclusions and compression now map to their xSpeed equivalents.
- Fixed: Disabling W3 Total Cache's master Minify switch is now respected. Its child CSS/JS settings stay populated while the master is off, so reading them alone imported minification and combination as enabled on a site that had deliberately turned them off.
- Fixed: An encrypted Redis password that cannot be read is now reported rather than silently dropped, so the object cache no longer fails to connect with nothing on screen to explain why.
- Fixed: An object-cache endpoint written as `tls://host:port` imported a host that could never resolve. The host and port are now imported and the dropped transport is stated, since xSpeed connects over plain TCP.
- Fixed: Compression enabled only for "other" file types no longer imports GZIP as switched off.
- Fixed: A partial import no longer switches off the plugin it imported from, and no longer shows a finished "Imported" state with no way to retry.
- Fixed: WP Super Cache is detected and imported. Its settings are stored under different keys than the importer looked for, so it reported nothing to import.
- Fixed: Page caching is actually enabled by an import that says it is.

Caching:
- Fixed: Ignored query parameters are matched by whole name rather than as substrings, so `utm_source` no longer matches an unrelated parameter that merely contains it.
- Fixed: Purging a URL on a non-standard port deleted nothing.
- Fixed: Query-string requests skip only the cache write, not the HTML transforms, so those pages keep their minification and lazy loading.

CSS and JavaScript:
- Fixed: Combined JavaScript is split by print group, so scripts registered for the footer are no longer hoisted into the head and run before first paint.
- Fixed: The combined bundle is carried on an existing handle rather than a new one, preserving inline payloads attached to it and the surrounding cascade order.
- Fixed: Configuration a page builder attaches late — Elementor writes `elementorFrontendConfig` on `wp_footer`, long after combining runs — now prints ahead of the combined bundle instead of behind it, so scripts no longer initialise against a config that does not exist yet.

CDN:
- Fixed: Changing the CDN URL clears Elementor's render caches, which stored the previous URLs in their markup.

Interface:
- Fixed: The setup wizard and the ⌘K search palette no longer render underneath the WordPress admin menu.
- Fixed: Opening a panel no longer shifts the page behind it when the dashboard is scrolled away from the top.
- Fixed: The Apply button on a Pro recommendation works instead of failing silently. Free and Pro name their recommendations differently, and only Free's were understood by the button both of them use.
- Added: The MCP server appears on the Overview as a capability card, so an AI connection can be set up without hunting for the panel.

MCP server:
- Fixed: OAuth discovery keeps working when another plugin claims `/.well-known/`.

Server configuration:
- Fixed: URL exclusions are mirrored into the generated nginx snippet, so nginx no longer serves cached copies of pages xSpeed was told to exclude.

= [1.1.8] – 2026-08-20 =

**Importing settings from another caching plugin no longer switches that plugin off without asking. Purging CSS or JavaScript now also clears the pages that link them, a failed save no longer reports success, and another MCP plugin’s AI connections keep working alongside xSpeed.**

**Migration:**

* Fixed: Importing settings no longer deactivates the plugin you imported from as an unavoidable side effect. The panel now offers both outcomes: “Import & switch to xSpeed” and “Import settings only.” Previously, the only way to decline was Cancel, which also abandoned the import. WP-CLI and MCP did the opposite and left both plugins running silently; all three now behave consistently.
* Fixed: On a multisite network, a single site’s administrator can no longer switch off a network-activated caching plugin for every site on the network. WordPress refuses the same action from its own Plugins screen.
* Fixed: Choosing to leave the other plugin running now raises a Health item naming the plugin and the risk, so two page caches competing for the drop-in don’t go unnoticed. It clears itself once the plugin is switched off.
* Fixed: LiteSpeed’s exclusion lists are stored as JSON and were imported literally, causing imported exclusions to never match anything.

**Caching:**

* Fixed: Purging CSS or JavaScript now also purges the pages that link those files. Cached pages could keep pointing to deleted combined assets, leaving them unstyled until another page purge occurred.
* Fixed: A single POST request no longer keeps a visitor on the uncached path for the rest of their visit. Previously, one form submission or comment could cause every later page to be generated from scratch.
* Fixed: Cache now purges when any module’s settings change, not only the cache module’s.

**CSS and JavaScript:**

* Fixed: “Defer” and “Delay” now apply to the external script itself rather than an inline block queued before it, preventing execution order issues.
* Fixed: Combined JavaScript keeps its declared dependencies, so scripts no longer run before the libraries they need.
* Fixed: Inline style and script blocks are now minified, and anything deliberately skipped clearly indicates why.

**Settings and interface:**

* Fixed: A failed save no longer shows the green “Settings saved” toast. The failure now stays visible instead of disappearing with the toast.
* Fixed: Settings writes with keys that would be silently dropped are now refused instead of being accepted and discarded.
* Fixed: A cleared URL field stays cleared instead of reverting to its previous value.
* Fixed: Sub-sections of a disabled feature now collapse with it.
* Fixed: A failing server snippet now identifies the problem instead of showing only a status code.

**MCP server:**

* Fixed: xSpeed no longer answers another MCP plugin’s OAuth discovery URLs. Previously, its rule could match every .well-known discovery address on the site and return xSpeed’s details, preventing AI assistants from connecting correctly to other MCP plugins.

**Security:**

* Fixed: The MCP server’s dynamic client registration is now bounded with limits on stored clients, redirect URIs, name and URI length, plus per-IP rate limiting on the registration endpoint.
* Fixed: MCP and WP-CLI settings writes are now gated by the Pro license, matching the dashboard.

= [1.1.7] – 2026-08-17 =

**On a multisite network, a change to one site no longer wipes every other site's cache. Cache cleanup no longer deletes stylesheets a live page is still using, and a consent banner styled by another plugin stays styled.**

Caching:
- Fixed: On a multisite network, each site's cache is now cleared on its own. Changing a setting, publishing a post, or clicking Purge on one site previously deleted the cached pages of every other site on the network, so the cache was rarely warm for anyone.
- Fixed: Cache cleanup no longer deletes a combined stylesheet or script that cached pages are still pointing at. Those pages carried on being served while their CSS and JavaScript returned "not found", leaving a site unstyled and unresponsive — and invisible to the owner, whose own browser still held the old copy.

CSS and JavaScript:
- Fixed: "Load CSS Asynchronously" no longer breaks a stylesheet another plugin has already made non-render-blocking. A cookie-consent banner in that position loaded with none of its styling applied, in both logged-in and logged-out states.

Settings:
- New: List settings — cache exclusions, ignored parameters and the rest — now show the stock value beside the box you edit, with a Reset action to restore it. These are lists where one wrong entry can quietly stop a whole section of the site being cached, and there was previously no way to see how far a list had drifted.
- Improved: The box you edit is now labelled, and Reset sits on it rather than beside the defaults.

Accessibility:
- Fixed: Hint text, captions and status pills now meet the AA contrast minimum in light mode. The worst affected was the "Off" state pill — the only thing communicating whether a feature was on.

= [1.1.6] – 2026-08-13 =

**A search-results page could be cached and served as your home page. Combining CSS no longer strips a site's styling, Pro settings can no longer be changed without an active licence, and an AI assistant now reports this site's tier and audits the page you actually asked for.**

Security:
- Fixed: A single search request could replace your cached home page. With search caching on, one visit to a search URL was stored as the site's front page, so every later visitor — and search engines — were served those results instead. The cached copy was handed out by the web server without WordPress running, so it kept being served until the cache was cleared. Search pages are now kept out of that store entirely.

CSS and JavaScript:
- Fixed: Combining CSS files could leave a site completely unstyled — no missing files, no errors, just the theme's styling gone. WordPress can inline a stylesheet it considers small, and doing so dropped the combined file that every other stylesheet had been folded into. Combining now happens on the finished page, after WordPress has made those decisions, so what you see in the browser is what was combined.
- Fixed: A stylesheet saved with a byte-order mark — common in files edited on Windows — no longer breaks the styling of every file combined after it.
- Fixed: Fonts and stylesheets brought in by an `@import` rule are no longer dropped when their file is combined with others.

AI assistants and WP-CLI:
- Fixed: On a site without an active Pro licence, Pro settings can no longer be changed through WP-CLI or an AI assistant. The dashboard already showed them locked; four write routes and the image recommendation did not enforce it, so a change made that way appeared to succeed.
- Fixed: A refused change is now reported as a refusal. It previously returned success over settings it had not written, in both the REST response and the command's exit code.
- New: `run_score` runs an external performance audit from any install, and honours the provider you configured — asking for GTmetrix no longer silently runs PageSpeed Insights instead.
- Fixed: An AI assistant asking what this site is now gets the truth about its tier. On a Pro site with a lapsed licence it previously reported the licence as active.
- Improved: A PageSpeed audit started from an assistant now measures the page you asked for. It previously measured the home page whatever URL was given, and reported success.

Cache:
- Fixed: Turning the page cache off, or uninstalling xSpeed, now removes the `WP_CACHE` setting it added to your `wp-config.php`. It was only removed when written exactly one way, so most sites kept a stale setting behind after the cache was switched off — including after the plugin was deleted.

Compatibility:
- Fixed: `wp xspeed purge` no longer clears the page cache on Apache servers without mod_headers, where the cache could not be served from disk anyway.

Accessibility:
- Fixed: Two buttons — the nginx snippet's copy control and the MCP panel's tool list — now show a focus ring again when reached by keyboard. They suppressed the outline entirely, which left anyone navigating by Tab with no indication of where they were.

Settings:
- Improved: Duration fields now show what the number means in plain words — "6 hours", "1 day 12 hours" — for every value, not only round ones.

= [1.1.5] – 2026-08-11 =

**AI assistants can change your settings again, and the cache preloader works on sites without a readable sitemap.**

AI assistants and WP-CLI:
- Fixed: An AI assistant can read and change your xSpeed settings again. The two tools that do this were dropped from the catalog on every site, so an assistant — including the one connected through the Hub — was told they did not exist and could only fall back to raw commands.
- New: `wp xspeed settings list|get|update` — read and change any module's settings from the command line, and from an AI assistant through the same commands.
- Improved: Settings for Pro features are no longer readable or writable over WP-CLI or an AI connection on a site without an active licence. The dashboard already hid them; these two surfaces did not.
- Improved: Credentials still cannot be set from the command line or an AI assistant. Asking to now names the field it refused rather than reporting success over an unchanged key.

Preloading:
- Fixed: On a site whose sitemap can't be read, the preloader now warms your most recent pages straight from your content instead of silently queueing nothing. This affected two common setups that are not misconfigurations: sites discouraging search engines (where WordPress disables the sitemap outright, standard on staging and pre-launch sites), and SEO plugins that serve their own sitemap at a path we were never told about.
- Fixed: When a crawl can't start at all, the preloader panel now says "Couldn't start" and names the reason, rather than reporting "Ready" over an empty run.
- Improved: The explanation appears while the crawl is running, not only once it finishes — on a large site that is the difference between watching an unexplained crawl for hours and knowing why on the first tick.
- Fixed: `wp xspeed preloader status` no longer fails with an internal error after a crawl that ended with nothing queued. An AI assistant asking why a preload failed now gets the actual reason instead of a type error.

Branding:
- Improved: New xSpeed Cache logo and wordpress.org banners.

= [1.1.4] – 2026-08-09 =

**Stored credentials are now encrypted, exclusions are enforced by the server itself, and the cache cleans up after itself.**

Security:
- Fixed: Stored credentials — API tokens, keys, and object-cache passwords — are now encrypted at rest and never returned in full by the dashboard, REST API, or an AI assistant. They were previously readable in plain text by anyone who could reach those endpoints. Existing values are encrypted automatically on upgrade; nothing needs re-entering.
- Improved: An AI assistant can no longer write credentials unless you explicitly grant it that permission when connecting.
- Improved: Cloudflare settings are verified when you save them, so a wrong token or zone is reported straight away instead of failing silently on the next purge.

Caching:
- New: Expired cache entries and orphaned minified assets are now collected on a schedule, so the cache directory no longer grows without bound on a busy site.
- Fixed: Excluded cookies and bypassed user agents are now enforced on the fast path the web server serves directly. Previously they applied only while a page was cold, so a warm page could serve the shared anonymous copy to carts, members and bypassed bots.
- Fixed: Sites using TranslatePress now cache the translated page rather than the untranslated one.
- Improved: Response headers now distinguish a cache miss from a deliberate bypass, so a quick header check shows whether a page is about to be cached or was skipped on purpose. Previously both came back with no header at all.
- Improved: With debug mode on, a bypassed response also names which rule skipped it — logged-in, excluded URL, query parameter, and so on.
- Fixed: Cache hits are now counted on every server, not only nginx, so Apache and LiteSpeed sites no longer show a permanent 0% hit ratio.
- Improved: The static-rewrite check now reports a known blocker (such as Separate Mobile Cache) instead of a bare "inactive", and says the same thing in the dashboard, WP-CLI, REST and MCP.
- Improved: The hit ratio now excludes 404s and bot traffic, and reports the origin only when a CDN sits in front — so the number reflects what your visitors actually experience.

Compression:
- Fixed: GZIP is no longer reported as inactive when the check itself couldn't complete. A blocked loopback request is not evidence that compression is missing, and telling you your server was misconfigured because we couldn't reach it was the bug. Only a check that positively proves GZIP is absent now warns.

Optimization:
- Fixed: The CDN now serves CSS, JavaScript and fonts, not just images from post content. Font files also get the CORS header they need when loaded cross-origin.
- Fixed: LCP preload now picks the largest image on the page instead of the first one, so the preload helps the image that actually decides your LCP score rather than competing with it.
- Fixed: Brotli is detected on nginx by asking the server, so a host with ngx_brotli installed is no longer told to install it. A check that can't complete now says so rather than reporting Brotli as unavailable.
- Fixed: The Compression page no longer promises an nginx snippet that isn't on it — it links to the snippet instead, and the link is there whether or not compression is already working.

Dashboard & Admin UX:
- Fixed: Enter now inserts a newline in the "one per line" settings fields (Excluded URLs, Excluded Cookies, and the rest). Typing a list previously merged every line into one.
- Fixed: The Setup Wizard starts from your site's real settings, so re-running it and pressing Apply no longer silently switches off options you had turned on. A cache expiry that isn't one of the presets is shown as its own option and named before anything overwrites it.
- Fixed: The Recent activity feed shows setting names as they appear on screen instead of internal storage keys, and no longer writes connection details or account identifiers into the log.
- Fixed: Links to Tools → Migration now open that page rather than dropping you on the Overview.
- New: Settings fields can now link to their documentation; the PageSpeed API key field points at the setup guide.
- Fixed: External Score now lives in one place, under Health & insights. It previously rendered a second copy of the same settings and run history inside the Health page's PageSpeed tab.

AI & agents:
- Fixed: A read-only MCP connection can now read status through every per-command tool. It previously refused the whole tool if any of its actions could write, so an assistant with read-only access could not inspect state at all. Write actions are still refused, and an unrecognised action is refused rather than allowed.

= [1.1.3] – 2026-08-05 =

**A redesigned dashboard, a homepage cache-poisoning fix, and caching that survives a plugin update.**

Security:
- Fixed: A crafted URL could poison the homepage cache, causing search-results content to be served to every visitor from the cached homepage.

Caching:
- Fixed: Updating the plugin no longer silently disables page caching until the next admin login — the drop-in is restored on activation.
- Fixed: Cache hits served statically by Apache are now reported and counted, instead of showing a permanent 0% hit ratio.
- Improved: Health now explains when the fast static-cache path is unavailable because the server lacks mod_headers, and what to change, instead of suggesting a fix that cannot work.
- Improved: Fresh installs now start on the recommended profile, so sites set up by WP-CLI or a skipped wizard get compression, browser caching, and minification by default.

Dashboard & Admin UX:
- New: Redesigned dashboard — new typography, colour system, navigation rail, and top bar, with grouped navigation and a consistent module-settings layout.
- New: Command palette for jumping to any setting.
- New: A dedicated Pro page describing what each Pro feature does.
- New: "Avg saved" card on the Overview, showing the real per-request time saved from your latest benchmark.
- Improved: License, Branding, and Multisite are now cards of their own, so the license form is one click away instead of three.
- Improved: Fonts now lives in one place under Optimization, and the page that used to host it is now "Media Optimization".
- Fixed: Recommendation links on the Overview now open the screen they point at, and "Get the snippet" opens the snippet.
- Fixed: The brand mark no longer renders black against the dark rail in light mode.

Optimization:
- New: Delay JavaScript now also covers scripts printed directly into the page — analytics, pixels, and chat widgets that previously bypassed it entirely.
- New: The delay-JavaScript failsafe timer is now configurable, so it can be tuned or switched off instead of firing mid-measurement.
- Fixed: Delayed scripts are now matched correctly when minification rewrites their URLs.
- Fixed: HTML minification now says when it is deliberately suppressed by debug mode, instead of reading as on while changing nothing.

AI / MCP:
- New: Stored PageSpeed Insights and GTmetrix history is now readable from your AI assistant.
- Fixed: Removed fifteen duplicate tools that shadowed the typed ones, trimming the tool list.
- Fixed: An unrecognised migration command now reports an error instead of silently succeeding.

= [1.1.2] – 2026-07-30 =

**A new Overview dashboard, click-to-play video embeds, and a full audit trail for AI actions.**

Dashboard & Admin UX:
- New: Overview screen is now the landing page, showing cache status, key stats, recommendations, and recent activity at a glance.
- New: PageSpeed and GTmetrix scores on the dashboard.
- New: Stat cards open into drill-downs so you can see what is behind each number.
- Improved: Notice buttons now take you straight to the control they refer to.
- Improved: Deactivating the plugin now asks for feedback, so problems reach us instead of going unreported.

Optimization:
- New: Click-to-play facade for YouTube and Vimeo embeds — the player loads only when a visitor presses play, cutting page weight.
- Fixed: Minified HTML no longer loses the space between inline elements, which could run words together.

AI / MCP:
- New: Audit trail recording every AI tool call, with an activity panel showing what was run and whether it changed anything.
- New: Every xSpeed CLI command is now available as its own MCP tool.
- Fixed: AI tool calls failed with a server error instead of running. Every tool works again.

Onboarding:
- Improved: Account connection moved to the final step, so caching is set up before you are asked to sign in.
- Fixed: Returning from account setup now resumes on the right step.
- Fixed: Each "next step" card in the wizard now goes to its own destination.

Health:
- Fixed: No longer tells you to configure your server when the check could not reach a verdict.

= [1.1.1] – 2026-07-28 =

**Actionable dashboard insights, richer AI tooling, and smarter health detection.**

Dashboard & Admin UX:
- New: Impact hero showing the real-world speed gain your cache is delivering, with a one-click next-best-action to apply the top recommendation.
- New: Benchmark history and daily hit-ratio trend charts, annotated with the setting changes that moved them.

AI / MCP:
- New: Purge a single URL, read site health, test the object cache connection, and verify Cloudflare credentials directly from your AI assistant.

Health:
- New: Detects third-party cookies that would poison the page cache and names the plugin responsible.
- New: Warns when the cache lifetime is shorter than the preloader interval, which would leave pages permanently uncached.

Optimization:
- New: Delay individual scripts by name instead of all-or-nothing, so you can defer heavy third-party embeds while leaving critical scripts alone.
- Fixed: Lazy-loaded images now get correct width and height even when the theme strips WordPress' image size class, preventing layout shift.

Caching:
- Improved: Benchmark now reports the real compressed transfer size alongside the uncompressed page weight.

Object Cache:
- Fixed: A failed write to Redis or Memcached no longer leaves a stale value served from the backend.

= [1.1.0] – 2026-07-22 =

**New xSpeed Hub — connect one AI assistant to manage caching across all your sites.**

xSpeed Hub:
- New: "xSpeed Hub" tab in the MCP Server panel — connect this site to your xspeedcache.com account so a single AI connection can manage caching across all your WordPress sites.
- New: Setup wizard now opens with a "Connect your free account" step — create a free account in one click right after install, so one AI connection can manage caching across every site. Skippable; caching works fully without an account.
- New: One-click "Connect via xspeedcache.com" — sign in and approve, no token to copy.
- Improved: The connect flow carries your admin email so account setup is one click for new users.

Dashboard & Admin UX:
- Improved: Benchmark and AI audit now sit above the cache settings for a clearer, action-first layout.
- Improved: The dashboard menu now reads "xSpeed Cache".

= [1.0.9] – 2026-07-16 =

**Redesigned dashboard navigation, improved settings experience, and caching reliability fixes.**

Dashboard & Admin UX:
- New: Redesigned settings navigation with dedicated pages for Optimization, Cache, Compression, Cloudflare, Health, and AI & Agents.
- New: Branded loading skeleton for a smoother dashboard loading experience.
- Improved: Clearer and searchable settings navigation.
- Improved: Enhanced search with tab and section-level results.
- Improved: Cleaner Free dashboard with streamlined Pro sections.

Optimization:
- Fixed: Improved CSS combination compatibility with page builders.
- Fixed: Resolved frontend rendering issues on block themes.

Caching:
- Fixed: Admin bar cache purge issue.
- Fixed: Hit counter reset reliability.

= [1.0.8] – 2026-07-14 =

**MCP support for AI assistants, LCP optimization, and admin experience improvements.**

AI / MCP:
- New: MCP server support for Claude and other MCP-compatible AI assistants.
- New: Secure OAuth 2.1 connection for remote AI assistants.
- New: MCP dashboard with available tools and permissions.

Performance:
- New: Resource Hints with LCP image preload and font preconnect.
- Fixed: Improved LCP image loading by preventing lazy loading on the hero image.

Dashboard & Admin UX:
- New: Settings shortcut from the WordPress Plugins page.
- Improved: Updated admin menu organization and dashboard navigation.
- Improved: Better Pro license messaging and settings search experience.
- Fixed: Checkbox rendering in both light and dark mode.

Reliability:
- Fixed: Improved cache permission handling on non-NGINX servers.

= [1.0.7] – 2026-07-05 =

**Improved reliability, granular cache management, Redis compatibility, and asset optimization fixes.**

Caching:
- New: Granular cache purge options for Page/Static, CSS/JS, Object Cache, and REST Cache.
- Fixed: Improved compatibility with previously installed caching plugins.
- Fixed: Purge All now properly clears optimized assets, object cache, and triggers CDN/edge purges.

Object Cache:
- Fixed: Redis 6+ ACL compatibility for managed hosting environments.
- Improved: Object cache write verification for better reliability.
- Improved: Site-specific object cache flushing for shared Redis environments.
- Improved: Persistent object cache group flushing.

Asset Optimization:
- Fixed: Combined CSS now preserves inline styles and stylesheet order.
- Fixed: Combined CSS files are regenerated correctly after cache purge.
- Fixed: Relative asset paths are preserved in optimized CSS.

Dashboard & Admin UX:
- Fixed: Dashboard layout issue with large WordPress admin menus.
- Fixed: Admin menu and toolbar overlay issues.
- Fixed: Pro sidebar item ordering.

Migration:
- Fixed: Mobile cache migration compatibility for NGINX static cache.

= [1.0.6] – 2026-06-25 =

**New caching capabilities, smarter settings, Pro cache warming, and major reliability improvements.**

Caching:
- New: REST API response caching with per-route TTL.
- New: Feed, search results, and 404 page caching.
- New: `xspeed_should_cache` filter for advanced cache control.
- New: Maintenance-aware cache serving to prevent stale maintenance pages.
- Improved: Feed handling with conditional GET (304 Not Modified) support.
- Fixed: Cache metadata handling for feeds, 404 pages, and content types.
- Fixed: Automatic cache purging for user and taxonomy changes.
- Fixed: Versioned cache drop-in to ensure updates are applied correctly.

Settings:
- New: Conditional settings with dependency support (AND/OR logic).
- Fixed: Preserved custom option keys during schema-driven saves.
- Fixed: Image URL validation for media fields.

White Label:
- Fixed: Footer credit and Help & Support link controls.
- Fixed: Fallback to the default logo when a custom brand logo is unavailable.

Migration:
- New: One-Click Profiles and Save & Share with an improved import experience.
- Fixed: Import now counts and migrates only enabled source settings.

Reliability:
- Fixed: Improved REST API error handling to prevent broken JSON responses.

Admin UX:
- New: Opt-in usage analytics (WP Insights).
- Improved: Sidebar navigation, conditional Pro settings, and submenu behavior.
- Improved: Pro upgrade links now point directly to the pricing page.

Pro (requires xSpeed Pro):
- New: Predictive Cache Warming and Scheduled Cache Prewarming.
- New: Brotli compression support with GZIP fallback.

= [1.0.5] – 2026-06-18 =

**Migration into Free, LiteSpeed cache ownership & accurate hit counting.**

Migration:
- New: One-click migration from other caching plugins (LiteSpeed Cache, WP Rocket, W3 Total Cache, etc.) is now part of the Free plugin — import your existing exclusions and settings on activation instead of starting from scratch.

LiteSpeed:
- Fixed: On LiteSpeed/OpenLiteSpeed, xSpeed Cache now tells LSCache to stand down (X-LiteSpeed-Cache-Control: no-cache) and owns the cache itself, so the X-XSpeed-Cache header is emitted, hits are counted, and the dashboard no longer falsely reports a PHP fallback.
- Fixed: LiteSpeed cache hits route through the drop-in (which both tags and counts them) since OpenLiteSpeed's .htaccess can't tag or log a static hit — giving consistent ~1ms hits and an accurate hit ratio.

Cache hit counting & stats:
- Fixed: Hit/miss counters moved off transients onto a persistent option, so they no longer evaporate when a non-persistent object cache (e.g. an unreachable Redis) is active — the hit ratio was getting stuck at a fake 0%/100%.
- Fixed: Cache MISSes are now recorded inline so the hit-ratio stat reflects reality instead of being pinned at 100%.
- Fixed: PHP-served cache HITs are counted correctly and the drop-in device-bucket desync is resolved.
- Fixed: Homepage now caches via the static rewrite (the rule used a pattern that required a non-empty path, so `/` fell through to PHP while inner pages were rewritten).

Nginx:
- Fixed: A nginx access_log directive in the generated snippet could take down the whole server when its log file was missing; the path is now resolved lazily so a deleted hits.log can't fail `nginx -t`.
- Fixed: The dashboard's nginx server-block snippet now updates optimistically when you toggle cache, instead of showing the pre-toggle state, and the wizard's Done step shows the post-apply block.

Admin UX:
- New: Collapsible sidebar groups for cleaner navigation.
- Improved: Renamed the "Cache" module label to "Page Cache" for clarity.
- Improved: Sidebar group icons and chevrons brighten correctly on hover (white in dark mode).

Module fixes:
- Fixed: Lazy Load — four correctness bugs resolved.
- Fixed: Heartbeat — editor-context detection, CLI set support, and honest frontend copy.
- Fixed: Database cleaner — expired-transient cleanup gaps and a count mismatch.
- Fixed: Google Fonts — no longer double-appends `display=swap` on entity-encoded hrefs.
- Fixed: Settings now strictly validate boolean fields instead of casting, preventing bad values from silently becoming `true`/`false`.
- Fixed: Object Cache — core option groups marked non-persistent so the drop-in can always be deactivated; degraded backends detected honestly.
- Improved: Static-rewrite SSL probe verifies the certificate and no longer blocks admin page loads.

= [1.0.4] – 2026-06-14 =

**One-click object cache, parity exclusions & admin polish.**

Object Cache:
- New: One-click Object Cache setup for Redis and Memcached — tests the connection, installs its own drop-in, and writes the wp-config constants (fully reversible). No drop-in plugin or PHP extension required.
- Improved: Dashboard now reports a degraded object cache honestly when the drop-in can't reach its backend, instead of implying caching is healthy.

Admin UX:
- New: WP admin submenu now mirrors the in-dashboard navigation, so the menu and dashboard tabs stay in sync.
- Improved: Settings changes apply live across dashboard navigation — no full page reload needed.
- Improved: Menu icon now recolors to the active accent state correctly.
- Improved: Success notifications are anchored to a single consistent position (bottom-center).
- Fixed: Long panel titles no longer push the subtitle off-screen.
- Fixed: Number-input spinners render consistently across browsers (Firefox no longer clips the value).

Cache exclusions:
- New: Exclusion lists now ship pre-populated with LiteSpeed / WP Rocket-parity defaults (16 URLs, 20 cookies, 28 query params), so a fresh install caches correctly out of the box.
- New: Exclusion rules now support regex via a `~` prefix (e.g. `~utm_[a-zA-Z0-9_-]+`), alongside glob and substring, with ReDoS guards.

Bug fixes:
- Fixed: Browser Cache no longer emits an "Array to string conversion" warning on multi-valued Cache-Control headers (also clears a false "nginx config required" notice).

License:
- Fixed: Activating a Pro license now unlocks the Pro panels immediately — no full page reload needed.

= [1.0.3] – 2026-06-07 =

**Topology-aware UX, nginx HIT counting, admin polish.**

Cache topology & UX:
- New: Topology-aware rewrite banner — the dashboard now detects containerized hosts (xclude, Kinsta, RunCloud Atomic, etc.) where the `$document_root` assumption doesn't hold, and tells the user exactly what to expect on their stack instead of silently failing.
- New: Unified nginx server-block snippet — one consolidated, copy-pasteable snippet (collapsible in the UI) replaces the previous fragmented sections.
- New: Nginx-served HITs are now counted — a dedicated access_log captures cache hits served directly by nginx (no PHP), so the dashboard "HITs in last 24h" stat is accurate on nginx setups.
- New: `X-XSpeed-Cache` response header — cache hits are now visible in the response headers, with a distinct value per serve layer (`HIT (nginx)` for the fast static path, `HIT (php)` for the drop-in fallback) so you can confirm a page was cached and tell exactly which layer served it.
- Improved: Browser Cache notice is topology-aware and renders its snippet in a proper code block.

Admin UX:
- New: Third-party admin notices are suppressed on xSpeed Cache admin screens (`page=xspeed*` only) — other plugins' "rate us" / promo notices no longer crowd the xSpeed Cache dashboard.
- New: Aligned sticky title-bar across the sidebar and content panels for a cleaner scroll experience.
- Improved: Browser Cache + GZIP notices probe-gate themselves — once the underlying issue is resolved, the notice dismisses on its own instead of lingering.

Benchmark:
- Fixed: "Without cache" leg of the dashboard speed test now appends a cache-buster query string. Previously it was hitting the cache and reporting an artificially-fast baseline, making the speedup number look small.

= [1.0.2] – 2026-06-02 =

**Static-rewrite cache, Site Health & Fonts.**

Static-rewrite cache + Site Health:
- New: Static-file rewrite path — cached HTML is served directly by Apache / nginx / LiteSpeed, bypassing PHP entirely on cache hits (5-15ms TTFB vs ~85ms via the PHP drop-in).
- New: .htaccess static-cache block — installed automatically on cache enable, removed cleanly on disable. Block precedes WordPress's own rules so static files match first.
- New: nginx config snippet — copy-pasteable server { } block surfaced in the dashboard when nginx is detected (PHP can't write nginx config). Avoids the "if is evil" pitfall via a named-location fallback.
- New: LiteSpeed LSCache coexistence — xSpeed Cache detects LSCache's server-level module and steps back instead of double-caching.
- New: Active rewrite probe — Health module pings the cache directory and confirms the rewrite is actually serving static bytes (not just present in .htaccess).
- New: WordPress Site Health integration — Tools → Site Health now lists an xSpeed Cache check covering static-rewrite status + nginx config requirements.
- New: Persistent banner — Cache panel warns the admin when caching is on but the static-rewrite block isn't engaged (slow fallback path).
- New: Copy-to-clipboard button on every config-snippet panel (nginx, GZIP, object-cache).

Fonts:
- New: Fonts module — adds `font-display: swap` to enqueued web fonts so visible text doesn't wait on a slow Google Fonts response, and exposes a preload list for above-the-fold font files.
- New: FontsModule tests covering the swap rewrite + preload `<link>` emission.

Improvements & fixes:
- Improved: Server-type detection is cached so CLI / cron contexts see the same server type as web requests.
- Improved: Drop-in (advanced-cache.php) and WP_CACHE constant are preserved across plugin upgrades; auto-heal hook tightened to admin_init to avoid REST/cron noise.
- Improved: Plugin Check pass — i18n textdomains, WP_Filesystem coverage, SQL preparation, sanitization, and plugin/readme headers all cleaned up for WordPress.org review.
- Fixed: nginx snippet rewritten to avoid the try_files trap that broke pretty permalinks.
- Fixed: Rewrite condition normalized for trailing-slash + non-trailing-slash URLs.
- Fixed: WP-Rocket-style canonical pattern — server-level conditional rewrite.
- Fixed: Minify tag-rewrite filters bail in non-frontend contexts (REST / admin / cron).
- Fixed: wp.org release zip now ships `vendor/` — resolves the 1.0.1 activation fatal.
- Improved: Release pipeline rebuilt — `.distignore`-driven dist build, CI verify step, version-named artifact on every run.
- Improved: Plugin version read from the PHP header — single source of truth.

= [1.0.1] – 2026-06-01 =

**Foundation release — full feature set + Pro hooks.**

Module architecture + LiteSpeed parity floor:
- New: Module architecture — every feature now ships as a self-contained Module (Cache, Health, Preloader, Heartbeat, Minify, GZIP, Lazy Load, Disable Bloat, Database, CDN, Cloudflare, Object Cache, Browser Cache).
- New: Health module — 24-hour hit/miss counter + activity log.
- New: Preloader module — sitemap-driven cache warmer with content-publish auto-warm.
- New: Cache exclusion intelligence — glob URL patterns, cookie patterns, User-Agent bypass, ignored query parameters.
- New: Per-device cache (mobile-separate).
- New: Per-post cache rules — don't-cache + custom expiry meta box.
- New: Minification — CSS / JS / HTML minify, combine, defer JS, delay JS, async CSS, remove ?ver= query strings.
- New: Lazy Load module — images / iframes / videos + auto CLS-fix.
- New: Disable Bloat module — 6 ergonomic toggles (dashicons, oEmbed, RSS, XML-RPC, jQuery Migrate, REST auth).
- New: Database module — optimize tables, scheduled cleanup, autoload analysis.
- New: CDN module — pull-zone URL rewriting.
- New: Cloudflare module — zone connect, auto-purge, dev-mode toggle.
- New: Object Cache module — Redis / Memcached config + status + flush.
- New: Browser Cache module — Cache-Control + Expires headers.
- New: Settings search across the entire dashboard with keyword highlighting.
- New: 3-step setup wizard on first activation with preset chooser and benchmark.
- New: Sidebar grouping under domain headers (Cache / Performance / Network / Insights / Tools).
- New: Sidebar tooltips + search shortcut in the collapsed rail.
- New: xspeed_branding filter — agencies can rebrand the dashboard.
- New: xspeed_cache_skip_for_post + xspeed_cache_expiry_for_post filters.

Pro-essentials (everything xSpeed Pro depends on, bundled here so any Pro release works against this single Free version):
- New: xspeed_module_descriptor filter — Pro hooks this to swap its panels to LicenseLockedPanel when license is invalid.
- New: AI Privacy module — GDPR off-switch + xspeed_ai_can_collect_data filter that Pro AI features must consult before recording visitor data.
- New: Pro upsell teasers — in-context inline cards on Cache, Minify, Lazy Load, Browser Cache and Disable Bloat panels.
- New: Locked Pro module rows in the sidebar — shows what Pro offers with a clear upgrade prompt.
- New: Pro Audit on the Cache panel — site-specific scan listing the top Pro features that would help THIS site, with severity chips and concrete reasons (not generic marketing copy).
- New: Sidebar slot for the license module (appears when xSpeed Pro is installed).
- New: Global 36px form-control height baseline across the dashboard.
- New: filemtime() cache busting on admin asset URLs.

Improvements & fixes:
- Improved: React panel registry — modules auto-render in the dashboard.
- Improved: Portal-based save indicator — zero layout shift.
- Improved: Distribution zip prunes dev dependencies (composer install --no-dev).
- Fixed: 5 duplicate module icons + regression test.


= [1.0.0] – 2026-05-27 =
- Initial release.