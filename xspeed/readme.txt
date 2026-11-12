=== xSpeed Cache: AI-Powered Performance Hub with MCP, Caching & CDN ===
Contributors: asif2bd, wpdevteam, seakashdiu, tushar284, hurayraiit, alimuzzamanalim
Tags: cache, performance, page speed, optimization, mcp
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete WordPress caching plugin: page cache, object cache, CDN, database cleanup and Core Web Vitals optimization, plus a built-in MCP server.

== Description ==

Your visitors decide to stay or leave in under 3 seconds. **xSpeed Cache** makes sure they stay.

Most caching plugins bury you in settings, charts and upgrade popups. xSpeed Cache does the opposite. Install it, flip one switch, and your site loads faster.

Built by the team at **WPDeveloper**, trusted by over 6 million WordPress users worldwide.

https://youtu.be/fYVy77GJDE4

= What This WordPress Caching Plugin Does =

xSpeed Cache caches your pages, shrinks your code, compresses your files and serves everything as fast as your server can. The result is faster pages, better Google PageSpeed scores and better Core Web Vitals, without hiring a developer.

= The Speed Advantage No Other Free WordPress Cache Plugin Offers =

When xSpeed Cache caches a page, the web server serves it directly, bypassing PHP entirely.

* **With xSpeed static cache:** 5 to 15ms response time
* **Without it (PHP-served cache):** around 85ms response time

That is up to **17x faster** page delivery on cache hits, set up automatically on Apache, nginx and LiteSpeed.

= Features at a Glance =

https://youtu.be/LlIb3XuQ-mo

**AI Assistants and the WordPress MCP Server**

xSpeed Cache ships a WordPress MCP server, so Claude or any other MCP client can inspect and tune your site through the same tools the dashboard uses. Every action is limited to what you allow when you connect.

* Set up an AI connection from the MCP card on the Overview
* Read-only connections can read status, while write actions stay refused
* An AI assistant cannot write credentials unless you grant that permission when connecting
* WP-CLI parity: `wp xspeed purge`, `wp xspeed preloader status`, `wp xspeed score run`, `wp xspeed optimize` and `wp xspeed settings list|get|update`
* The `optimize_site` tool measures the site, applies recommended settings one at a time, re-checks the page after each change and reverts anything that breaks it

**Disable Bloat**

* Disable dashicons on the frontend
* Remove oEmbed, RSS feeds, XML-RPC, jQuery Migrate and REST API authentication headers

**AI-Powered Site Audit (Pro)**

xSpeed Pro scans your site and tells you which performance features will help the most, with severity ratings and concrete reasons.

**Page Caching**

Pages are saved as static HTML files and served before WordPress loads. The cache rebuilds itself when you publish a post, switch themes or update a plugin.

* Static-file rewrite path that bypasses PHP entirely for a 5 to 15ms TTFB
* Auto-purge on publish, update, comment and theme change
* One-click cache purge from the admin bar
* Exclude specific URLs such as `/cart`, `/checkout` and `/my-account`
* Set your own cache expiry, from 1 to 720 hours
* Separate mobile cache for phone and tablet visitors
* Per-post cache rules with custom expiry, or disable caching for individual posts

**Code Minification**

* Minify HTML, CSS and JavaScript
* Combine CSS and JS files to reduce HTTP requests
* Defer or delay JavaScript so it does not block page rendering
* Async CSS loading
* Removes `?ver=` query strings from asset URLs (generated files under `wp-content/uploads` keep theirs, so a page-builder or consent-banner edit still reaches returning visitors)
* Safe-minify skips already-minified files and falls back if anything looks off

**GZIP Compression**

* Auto-configures GZIP on Apache and LiteSpeed
* Shows a ready-to-paste config snippet for nginx and IIS
* Detects if your server already has GZIP active, so there is no double-configuration

**Lazy Load Images, Videos and Iframes**

* Lazy load images, iframes and videos so they load when a visitor scrolls to them
* Adds missing image sizes so the layout does not jump

**Font Optimization**

* Adds `font-display: swap` so text is visible immediately while fonts load
* Preloads above-the-fold font files
* Removes the blank-text flash caused by slow Google Fonts responses

**Browser Cache**

* Sets `Cache-Control` and `Expires` headers for images, CSS and JS, with no manual configuration

**Cache Preloader**

* Sitemap-driven cache warmer, so the first visitor after a purge still gets a fast page
* Auto-warms the cache when you publish or update content

**Redis and Memcached Object Cache**

* Connect Redis or Memcached in seconds, as a drop-in persistent object cache
* View status and flush the object cache from the dashboard

**CDN and Cloudflare**

* Pull-zone URL rewriting for any CDN provider
* Cloudflare integration: connect your zone, auto-purge on publish and toggle development mode

**Database Cleanup**

* Optimize database tables
* Remove post revisions, spam, trash, transients and orphaned meta
* Schedule automatic cleanup

= Built to Handle WordPress Edge Cases =

* **Multisite ready.** Each site in the network gets its own cache and settings.
* **LiteSpeed server?** xSpeed Cache also clears the server's LiteSpeed cache whenever it clears its own. If the LiteSpeed Cache plugin is active, xSpeed Cache will not turn on its own page cache or the other features that overlap, so the two never run at once.
* **WooCommerce?** Logged-in customers and checkout pages are never cached.
* **WordPress Site Health.** xSpeed Cache adds its own check under Tools → Site Health.
* **Works on any server.** Apache, nginx, LiteSpeed, IIS and any standard PHP host.

= Designed for Everyone =

**Non-technical users:** A 3-step setup wizard configures the plugin in under 2 minutes. Settings save automatically.

**Developers:** REST API at `/wp-json/xspeed/v1/`, developer filters (`xspeed_skip_minify`, `xspeed_strip_asset_version`, `xspeed_cache_skip_for_post`, `xspeed_cache_expiry_for_post`), `WP_DEBUG` awareness, and a React 18 and TypeScript admin UI.

**Agencies:** Use the `xspeed_branding` filter to white-label the dashboard for clients.

= Private By Default =

xSpeed Cache stores no IP addresses and sets no tracking cookies. Out of the box, the only request it makes is a check to your own site's home URL to confirm GZIP is active, at most once an hour.

Usage analytics is optional and off by default. If you turn it on in the setup wizard or in xSpeed Cache → Settings → Privacy & usage data, it sends your email address and display name, your site's setup and which xSpeed Cache features you use. Page content is never sent. See the External services section below for every service the plugin can contact.

If xSpeed Cache makes your site faster, please leave a review on WordPress.org.

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

By default, no. The only request is a check to your own site's home URL to confirm GZIP is active. Everything else, including usage analytics, speed tests, xSpeed Scan, Cloudflare and click-to-play video posters, runs only after you turn it on or press a button. Each service is listed in the External services section.

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

Out of the box, xSpeed Cache contacts only your own site (the gzip probe below). Every other service on this list runs only after an administrator turns it on, connects it or presses a button. None of them runs on a fresh install.

Usage analytics is off by default. The setup wizard shows it as an unticked switch, and xSpeed Cache → Settings → Privacy & usage data has the same switch. Nothing is sent until you turn it on. Turning it off stops all sending and clears the scheduled send. The deactivation survey is separate and sends nothing unless you click Submit. Speed tests and xSpeed Scan run only when you press Test or Scan, never on a schedule.

= Self-hosted gzip probe =

* **What it does:** Sends one `GET` request with `Accept-Encoding: gzip` to your site's home URL, to see whether your server already gzips responses. Only the `Content-Encoding` header is read.
* **When it runs:** When the dashboard loads server status, at most once an hour.
* **Where the request goes:** Your own site. Nothing is sent anywhere else.

= Third-party files (hosts you list) =

* **What it does:** Downloads CSS and JavaScript files from other hosts and keeps a copy on your site, so visitors load the copy.
* **When it runs:** Only after an administrator turns on xSpeed Cache → Optimization → Third-party files. It is off by default. After that, a file is downloaded a minute or so after a page that uses it is first built, and checked again every 7 days (you can change this).
* **Where the requests go:** Only to the hosts on the list in that screen, which starts with cdnjs.cloudflare.com, cdn.jsdelivr.net, unpkg.com, code.jquery.com, ajax.googleapis.com, use.fontawesome.com, maxcdn.bootstrapcdn.com, stackpath.bootstrapcdn.com and cdn.datatables.net. A host you remove from the list is never contacted. An empty list contacts nobody.
* **What is sent:** A plain `GET` for the file, with no cookies, no site address and no visitor data.
* **Limits:** HTTPS only, files up to 1 MB by default, and CSS or JavaScript only.

= xSpeed Hub (app.xspeedcache.com) =

* **What it does:** Lets you manage this site, with other sites, from one xSpeed Hub account and one AI connection.
* **When it runs:** Only after an administrator connects the site to xSpeed Hub from xSpeed Cache → AI & agents. A site that was never connected makes no Hub requests.
* **What is sent:** The site URL and this site's MCP token, which the Hub uses to call the site's own MCP endpoint. The token is sent when the site is connected, when the connection status is checked, when the token changes (Rotate, or Connect after Disconnect), when you disconnect, and with speed-test requests you start.
* **After an xSpeed Scan:** When a scan you run from the plugin finishes on a connected site, the site sends the Hub that scan's id, so the Hub can compare it with the site's earlier scans. Only the id and the site URL are sent. The Hub reads the report from the scan service itself.
* **Where the request goes:** app.xspeedcache.com, operated by WPDeveloper.
* **The broker URL:** The AI & agents screen also shows an api.xspeedcache.com address that you can paste into an AI client. The plugin never contacts that address. Only your AI client does, and only if you set it up that way.
* **Terms and privacy:** [Terms](https://wpdeveloper.com/terms-and-conditions/), [Privacy](https://wpdeveloper.com/privacy-policy/).

= Usage analytics and deactivation survey (send.wpinsight.com) =

* **What it does:** Tells WPDeveloper which WordPress setups and xSpeed features are in use, so we can fix what breaks.
* **When it runs:** Only after an administrator turns on "Share usage data" in the setup wizard or in Settings → Privacy & usage data. It checks once a day and sends only what changed since the last send, and sends once more when the plugin is deactivated.
* **What is sent:** The site URL and name, WordPress and PHP versions, site language, charset, text direction, multisite status, server software, active and inactive plugins, theme name and version, the xSpeed version and xSpeed's own settings. The send when you turn the switch on, and the one at deactivation, also include the email address and display name of the administrator who did it. The daily send runs without a logged-in user, so it has no email or name. Page content is never sent.
* **Deactivation survey:** If you pick a reason and click Submit when deactivating, the reason and any text you typed are sent. With analytics off, the survey sends only the reason, your text, the site URL, the WordPress, PHP and xSpeed versions, and the date. Skip & Deactivate sends nothing.
* **Where the request goes:** send.wpinsight.com, operated by WPDeveloper. [Terms](https://wpdeveloper.com/terms-and-conditions/), [Privacy](https://wpdeveloper.com/privacy-policy/).

= Speed tests: Google PageSpeed Insights and GTmetrix =

* **What it does:** Shows a Lighthouse or GTmetrix score next to the dashboard's own timing benchmark.
* **When it runs:** Only when an administrator presses Test, or an AI agent you connected runs the speed-test command. There is no schedule. A GTmetrix test that is still queued is checked when you open its status, for at most 30 minutes.
* **What is sent:** The URL of the page being tested, the test strategy (mobile or desktop) and, if you entered one, your API key. GTmetrix always needs your own key.
* **Where the request goes:** www.googleapis.com (Google PageSpeed Insights: [Terms](https://developers.google.com/terms), [Privacy](https://policies.google.com/privacy)) or gtmetrix.com (GTmetrix: [Terms](https://gtmetrix.com/tos.html), [Privacy](https://gtmetrix.com/privacy.html)), whichever provider you chose.

= xSpeed Scan (xspeedcache.com) =

* **What it does:** Grades the site across speed, delivery, assets and stack, with a fix for every failed check.
* **When it runs:** Only when an administrator presses Scan, or an AI agent you connected runs the scan command.
* **What is sent:** The URL to scan, the test strategy, and whether the report should be listed. The scan service then loads that URL like any visitor would.
* **Who can see the report:** For a site that is not connected to xSpeed Hub, the report is listed on a public page for that domain. For a connected site, it is unlisted: kept off the public page, but anyone with its link can open it.
* **Where the request goes:** xspeedcache.com/api/scan, operated by WPDeveloper. [Terms](https://wpdeveloper.com/terms-and-conditions/), [Privacy](https://wpdeveloper.com/privacy-policy/).

= Cloudflare API (api.cloudflare.com) =

* **What it does:** Purges Cloudflare's cache for your zone, turns development mode on or off, and checks your credentials.
* **When it runs:** Only after an administrator enters Cloudflare credentials and turns the integration on. It then runs when you press Purge, Verify or Development mode, and each time xSpeed clears its own cache if "Clear Cloudflare with xSpeed" is on.
* **What is sent:** Your API token (or email and Global API key), your zone ID, and the URLs to purge.
* **Where the request goes:** api.cloudflare.com, operated by Cloudflare. [Terms](https://www.cloudflare.com/website-terms/), [Privacy](https://www.cloudflare.com/privacypolicy/).

= YouTube video posters (i.ytimg.com) =

* **What it does:** "Click-to-play videos" (off by default) replaces YouTube embeds with a poster image and a play button.
* **When it runs:** On pages with a YouTube embed, once the option is on. The request comes from your visitors' browsers, not from your server.
* **What is sent:** The browser requests the video's poster image, so YouTube receives the visitor's IP address, browser details and the video ID. The full YouTube player, which sends more, loads only when the visitor clicks play.
* **Where the request goes:** i.ytimg.com, operated by Google. [Terms](https://www.youtube.com/t/terms), [Privacy](https://policies.google.com/privacy).

= Local Analytics (www.googletagmanager.com) =

* **What it does:** "Serve Google Analytics from this site" (off by default) downloads the Google Analytics 4 script, gtag.js, that your pages already load, and serves it from your uploads folder with your own cache headers.
* **When it runs:** Only after an administrator turns the option on. The script for each measurement ID found on your pages is downloaded in the background, then again once a day. Never during a visitor's request.
* **What is sent:** A `GET` request for `https://www.googletagmanager.com/gtag/js?id=<your measurement ID>`. No data about your site's visitors is sent. Your pages keep sending analytics data to Google exactly as they did before.
* **Where the request goes:** www.googletagmanager.com, operated by Google. [Terms](https://marketingplatform.google.com/about/analytics/terms/us/), [Privacy](https://policies.google.com/privacy).

= Image size lookup for images on other sites =

* **What it does:** "Add missing image sizes" (on by default) adds width and height to images that lack them, so the page does not jump as they load. Images in your media library are measured locally. For an image hosted on another site, xSpeed Cache reads the start of the file to get its size.
* **When it runs:** Only while the cache is being warmed by the preloader, a cron task or WP-CLI, never during a visitor's request. Each URL is looked up once and the result is stored.
* **What is sent:** A `GET` request for the first 32 KB of the image, from your server to the site hosting it. No data about your site or visitors is sent.
* **Where the request goes:** The host of each image, which you chose by putting the image on your page. To turn the lookup off, use `add_filter( 'xspeed_lazy_remote_dimensions', '__return_false' );` or turn off "Add missing image sizes".

== Source code ==

`assets/admin.js` and `assets/admin.css` are built from the uncompressed TypeScript and React source of the admin interface, which ships in the plugin's `src/` folder.

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

= [1.4.8] – 2026-11-12 =

**Cached pages no longer go out blank, login captchas work with Delay JS, and flushing a Redis object cache takes the same time at any size.**

Caching:
- Fixed: A page purged at the moment the PHP serve path sends it from cache no longer goes out as a cached hit with an empty body.
- Fixed: On nginx, requests over HTTP/3, or with an upper-case host or `:443` in the host, now get the cached file instead of going to PHP. Copy the updated nginx snippet to apply it.
- Fixed: The preloader tries a slow page once more before counting it as failed, and its report counts warmed, failed and not-cached pages separately. The page-warm timeout can be changed with `xspeed_preloader_request_timeout`.

Object Cache:
- Improved: Flushing a Redis object cache takes the same time at any size: Purge All went from about 340 ms to 25 ms with 155,000 keys. Old values are deleted in the background, and `wp xspeed objcache status` reports how that went.
- Improved: Multi-key object-cache calls use batched reads and Redis write pipelines.
- Fixed: Automatic page purges keep the object cache warm. Explicit purges and plugin activation or deactivation still flush it.
- Fixed: Fetching or refreshing third-party files no longer flushes the object cache.
- Fixed: A flush that Redis refuses or that fails now shows as an error.

Optimization:
- Improved: When Load CSS without blocking leaves a page alone, it says why.
- Fixed: Defer JS and Delay JS leave the login page alone, so login captchas such as Cloudflare Turnstile load and the login goes through. This includes custom login URLs.
- Fixed: xSpeed no longer optimizes the Bricks builder or its previews, so elements no longer disappear in the editor.
- Fixed: A long unquoted attribute, such as a base64 image, no longer blanks the page.
- Fixed: A document-relative image is no longer preloaded from a broken address, and an image the theme already preloads is no longer preloaded twice.

AI / MCP:
- Improved: An add-on can report when its licence expires, and when and where its licence was last checked.
- Fixed: An owner's approval for a database clean covers only the categories the owner was shown, and an MCP token cleans only the categories it covers.

= [1.4.7] – 2026-11-08 =

**WooCommerce shoppers with a cart can get cached pages, you can choose each page's main (LCP) image, and Slider Revolution works with Delay JS.**

Caching:
- New: Cache pages for shoppers with a cart keeps one cached copy per cart, so a shopper with items in the cart no longer skips the page cache. It is off by default and shows only while WooCommerce is active. Cart, checkout and account pages, add-to-cart requests and logged-in users are still not cached, and a product, price or stock change clears every cart copy.
- New: An add-on can hold the cache write for up to 5 minutes while a page's optimized version is about to land, so the unoptimized page is not cached first (`xspeed_cache_write_hold`).
- Fixed: A browser that reloads a cached page with a conditional request now gets a real 304 on the PHP serve path. Before, it got a 200 with an empty body, which showed a blank page and could be stored by a CDN.
- Fixed: Requests from the Hub renderer and Speed Scan no longer count as visits in WP Statistics, Slimstat or the hit ratio when they send a browser user agent.

Optimization:
- New: Main (LCP) image. In the block editor sidebar or the classic editor, choose which image a page preloads: automatic, the featured image, an image you pick, or none. Resource Hints has site-wide rules by page type or path. The chosen image gets `fetchpriority="high"` and is not lazy-loaded.
- Fixed: With Delay JS on, Slider Revolution no longer shows its "jquery.js library include" error in place of the slider, the hero keeps its height until the delayed scripts run, and the Bridge theme's logo shows. This also holds on pages the cache does not store, such as for logged-in users.

Dashboard & Admin UX:
- Fixed: A link to a module that lives in another page's tab opens that tab.

= [1.4.6] – 2026-11-01 =

**xSpeed can serve Google Analytics and other allow-listed third-party files from your own site, and audio players below the fold wait until played.**

Optimization:
- New: Third-party files copies CSS and JavaScript from hosts you allow onto your site and points the page at the copy, so Combine CSS, Delay JavaScript and the CDN can work on them. It is off by default. Once it is on, it copies from nine common library hosts, such as cdnjs and jsDelivr, and you can edit that list.
- New: Local Analytics serves the Google Analytics script from your site, so PageSpeed no longer flags its short cache lifetime. It is off by default, and turning it off puts Google's address back.
- Fixed: With Lazy-load your own videos on, audio players with no preload setting no longer download their file on page load. A preload you set yourself, and autoplay players, are left alone.

Dashboard & Admin UX:
- Fixed: An add-on's settings on an unlicensed site now show the licence card, instead of switches that Pro refuses to save.

Older releases are listed in changelog.txt, included with the plugin, and at [xspeedcache.com/changelog](https://xspeedcache.com/changelog/).
