=== xSpeed Cache: AI-Powered Performance Hub with MCP, Caching & CDN ===
Contributors: asif2bd, wpdevteam, seakashdiu, tushar284, hurayraiit, alimuzzamanalim
Tags: cache, performance, page speed, optimization, mcp
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.3
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

xSpeed Cache also includes **optional usage analytics**. You are asked in two places: a clearly labeled consent control in the setup wizard, and the switch in xSpeed Cache → Settings → Privacy & usage data. Nothing is sent until you confirm from one of them. When enabled, xSpeed Cache shares non-sensitive diagnostics: your WordPress and PHP version, active theme and plugins, server type, site language, and which xSpeed Cache features you have switched on, so we know what to keep fast and compatible. No page content is ever sent, and you can turn it off again at any time from xSpeed Cache → Settings → Privacy & usage data. See the External services section below.

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

xSpeed Cache contacts your own site (the gzip probe below); when usage analytics is enabled, an analytics service; only if you choose to submit the optional deactivation survey, that same service; and — only when you run a speed test yourself — one external performance-score provider. Analytics consent is asked in two places — a clearly-labeled control in the setup wizard, and the switch in xSpeed Cache → Settings → Privacy & usage data — and nothing is sent until you confirm from one of them; turning the switch off stops all collection and clears the scheduled send. The deactivation survey is separate and never sends anything unless you explicitly click Submit. External scores are off by default, turn on the first time you press Test, and never run on their own.

= Self-hosted gzip probe =

* **What it does:** Issues a single `GET` request to your site's home URL (`home_url('/')`) with an `Accept-Encoding: gzip` header to detect whether your web server is already serving gzipped responses. The response body is discarded; only the `Content-Encoding` header is read.
* **When it runs:** On demand when the admin dashboard loads server status, throttled to once per hour via a transient (`xspeed_gzip_active`).
* **Where the request goes:** Your own site (`home_url()`). This request goes only to your own server.
* **What is sent:** No personal data, no site identifiers, no payload — just a standard HTTP `GET` from your server back to your server.

= xSpeed Hub (app.xspeedcache.com) =

* **What it does:** Lets you manage this site, with other sites, from one xSpeed Hub account and one AI connection.
* **When it runs:** Only after an administrator connects the site to xSpeed Hub from xSpeed Cache → AI & agents. A site that was never connected makes no Hub requests.
* **What is sent:** The site URL and this site's MCP token, which the Hub uses to call the site's own MCP endpoint. The token is sent when the site is connected, when the connection status is checked, when the token changes (Rotate, or Connect after Disconnect), when you disconnect, and with speed-test requests you start.
* **After an xSpeed Scan:** When a scan you run from the plugin finishes on a connected site, the site sends the Hub that scan's id, so the Hub can compare it with the site's earlier scans. Only the id and the site URL are sent. The Hub reads the report from the scan service itself.
* **Where the request goes:** app.xspeedcache.com, operated by WPDeveloper.

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

= [1.4.3] – 2026-10-16 =

**Tablets can get a cached copy of their own, Markdown versions of a page can be cached beside the HTML, and an AI agent now needs the site owner's approval before an action that cannot be undone.**

Security:
- Fixed: A video that plays by itself could carry markup from its address into the page when "Lazy-load your own videos" moved the address aside. A Contributor could use this to run script in the browser of anyone viewing the post. The address is now kept as plain text, and local pages cached before the update are cleared once across the network. A warning remains until a full purge of the configured Cloudflare zone is confirmed. Reported by Wordfence PRISM (CVE-2026-109985).

Caching:
- New: Separate tablet cache (opt-in) keeps a third copy for tablets, and you choose whether tablets get the desktop or the phone page.
- New: Cache Markdown versions (opt-in) stores the Markdown some SEO plugins send AI agents, separately from the HTML page.
- Improved: With Separate mobile cache on, a CDN that keeps one copy per device type can now cache pages too.
- Fixed: A response that is not HTML, such as Markdown or JSON on a page address, is no longer stored and served as the page.
- Fixed: After a database restore that left xSpeed inactive, the cache no longer serves the old site's pages, and it is emptied when xSpeed comes back.
- Fixed: The Separate mobile cache check compares fresh renders and asks each page whether it looked at the device, so one cached copy no longer reads as a difference.
- Fixed: The site status the speed scan reads now says whether the page cache is on and why the home page was not cached.
- Fixed: Purging one page also removes the expiry note kept beside a page that carries a nonce, so the next copy of that page is not cleared early.
- Fixed: The 404 cache stores and serves only real 404s. A file a plugin serves at an address WordPress has no page for, such as llms.txt, is no longer cached as the site's 404 page or answered with one.

Optimization:
- New: Self-hosted Google Fonts has a card on the Optimization page.
- Improved: Delay JS waits for a real visitor before it runs the delayed scripts, and runs them after the page's own DOMContentLoaded handlers. New installs get no fallback timer, so a speed test no longer runs them inside its trace.
- Improved: Delay JS waits for stylesheets that are still being applied before the replay.
- Fixed: Delay JS keeps the scripts a never-delayed script needs, with or without Smart Delay.
- Fixed: Images in footer popups that already have a size now load lazily, so a popup nobody opens no longer downloads its images with the page.

Cloudflare:
- Fixed: Purging a URL clears every device copy of it at Cloudflare.

AI / MCP:
- New: Actions that cannot be undone, such as Database Clean and applying a migration, wait for the site owner's approval before an AI agent can run them.
- New: Each tool says whether it reads, writes or cannot be undone, so AI clients can show it.
- Fixed: Connecting, rotating or disconnecting MCP and turning on usage sharing are refused over MCP. Usage sharing can still be turned off.
- New: When an xSpeed Scan finishes on a site connected to xSpeed Hub, the site sends the Hub the scan's id, so the Hub can compare it with the site's earlier scans.
- Fixed: Running mcp status as a tool no longer shows the connection link.

Dashboard & Admin UX:
- Fixed: A settings save with a value the setting does not accept now names the value and what it accepts, instead of reporting success while changing nothing.
- Fixed: Importing from another caching plugin shows as successful when most settings came across, and lists each skipped setting with the reason.
- Fixed: A local site is not offered a connection to xSpeed Hub, and an unverified Hub answer no longer records the site as attached.

= [1.4.2] – 2026-10-12 =

**Combine CSS now works on Flywheel and Bedrock installs, Elementor Pro sticky headers stick under Delay JS, and a failed pass on the finished page can no longer cache an empty page.**

Caching:
- New: Every wp-admin page says when the page cache is off, and names the cache it would replace when another one already serves pages.
- Improved: Purge All also clears Nginx Helper's Redis page cache, as used on GridPane, EasyEngine and WordOps.
- Improved: `wp xspeed cache purge <url>` purges only that page, and the purge says which server cache it reached.
- Fixed: When a filter on the finished page returns nothing, the rendered page is kept. An empty page is no longer sent or cached.
- Fixed: Adding, moving or editing a widget clears the cached pages.
- Fixed: With LiteSpeed Cache installed, the conflict check follows its page-cache setting, including its 24-hour pause.
- Fixed: An Nginx Helper that is installed but not set up is no longer taken for a server cache.
- Fixed: Purging no longer leaves an expiry marker beside pages that carry a nonce, which flooded response headers with errors when Query Monitor was active.

Optimization:
- New: The Asset Manager has a card on the Optimization page.
- Fixed: Combine CSS works when wp-content is outside the WordPress folder, as on Flywheel and Bedrock.
- Fixed: Under Delay JS, jQuery ready callbacks wait for the replay, so Elementor Pro sticky headers attach and Elementor entrance animations show.
- Fixed: Async CSS holds transitions until the stylesheets apply, keeps them when JavaScript is off, and skips entrance animations already on screen.
- Fixed: Turbo Render works with the page cache off, and skips sections that break under containment.

Object Cache:
- Fixed: Redis connects over a unix socket, reading the same constants as Redis Object Cache.

Dashboard & Admin UX:
- Improved: The expert-setup offer links to the booking page on xspeedcache.com.
- Fixed: Database Clean deletes posts and comments through WordPress, so trashed media files go too, and a clean that runs out of time says how much is left.
- Fixed: Deleting a post detaches its child posts of another type.
- Fixed: The scan report is stored as JSON, so a latin1 database table no longer loses it.
- Fixed: The PageSpeed tab shows only with a licensed Pro, appears as soon as the licence is activated, and stays out of settings search while hidden.
- Fixed: The xSpeed Cache menu is hidden from users who can't manage options.

= [1.4.1] – 2026-10-08 =

**Cloudflare Enterprise setup now runs in four short steps, and the add-on's offer opens its page in the dashboard, shows with any version of Pro, and goes away once the add-on is bought.**

Cloudflare:
- New: The Cloudflare Enterprise add-on can show its setup as four steps (Setup, Verify, Connect, Done), with a card for each DNS record and a Copy button beside every value.
- Improved: "Get it" on the sidebar's Cloudflare Enterprise card opens the add-on's page in the dashboard, in the same tab, instead of the store. That page explains what you need and has the buy button.
- Improved: The sidebar card and the add-on's page show the price the service reports.
- Improved: The Cloudflare Enterprise card on the Network page has a glowing border until the add-on is on. With reduced motion turned on, the border stays but does not move.
- Improved: The add-on's page links to its setup guide.
- Improved: The header shows the add-on's state as soon as its licence or domain changes, without a reload.
- Fixed: The sidebar shows one Cloudflare Enterprise card, with any version of Pro.
- Fixed: With a Pro older than 1.3.0, the Network page keeps the Cloudflare Enterprise card and page, and a licensed Pro is asked to update.
- Fixed: The offer is hidden once the add-on key is entered, and on sites whose host already provides Cloudflare Enterprise.

Older releases are listed in changelog.txt, included with the plugin, and at [xspeedcache.com/changelog](https://xspeedcache.com/changelog/).
