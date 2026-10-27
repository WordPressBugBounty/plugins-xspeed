=== xSpeed Cache: AI-Powered Performance Hub with MCP, Caching & CDN ===
Contributors: asif2bd, wpdevteam, seakashdiu, tushar284, hurayraiit, alimuzzamanalim
Tags: cache, performance, page speed, optimization, mcp
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.5
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
* **When it runs:** Only after an administrator turns on "Share usage data" in the setup wizard or in Settings → Privacy & usage data. It sends once a day, and once more when the plugin is deactivated.
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

= [1.4.5] – 2026-10-27 =

**Purge all can clear one cache type at a time, cached pages keep changes other plugins make to the page, and saving a Divi layout clears the cache.**

Dashboard & Admin UX:
- New: Purge all is a split button. Its menu clears one cache type: page cache, CSS and JS files, object cache, REST API cache, or a type an add-on adds.
- New: An add-on can hold a setting at a fixed value while one of its own settings needs it. The field is read-only and says what set it, and a different value is refused from REST, the command line and MCP.
- Fixed: The Cache page no longer asks you to install the .htaccess or nginx rules again when the rewrite check has only expired or could not decide.

Caching:
- Fixed: A page that another plugin rewrites from its own output buffer, such as ShortPixel's WebP delivery, is cached the way visitors receive it, so cache hits keep the change.
- Fixed: Saving a Divi Theme Builder layout or Divi Theme Options clears the whole site, so cached pages no longer link stylesheets Divi has deleted.
- Fixed: Cache cleanup removes cached pages that link a Divi static CSS file that no longer exists.
- Fixed: Purging the cache no longer stops with an error when the object cache is broken.

Optimization:
- Fixed: Preload hints go ahead of inline Critical CSS in the head, so the hero image starts loading sooner.
- Fixed: On right-to-left sites, Minify CSS no longer drops stylesheets that have their own -rtl.css file.
- Fixed: REST API for logged-in users only refuses visitors with WordPress's own error code, so plugins that check for a login-only REST API recognise it.

Health:
- Fixed: The edge cache row reports when code running after xSpeed replaces the headers that keep a page out of a shared cache, instead of always showing OK.

AI / MCP:
- Fixed: xSpeed no longer answers another MCP plugin's OAuth discovery address at the site root, so that plugin's clients sign in to the right server.

Reliability:
- Fixed: A site no longer shows a fatal error when another plugin asks Composer which package versions are installed.

= [1.4.4] – 2026-10-19 =

**The preloader can skip post types and picks a stalled crawl back up, a cookie consent banner can be deferred, and an import from another caching plugin says when it was only partly done.**

Caching:
- Improved: Static cached pages are checked for expiry every hour, and just before the preloader requests them, so an expired copy is no longer served.
- Fixed: Purging the cache while it is being switched on now reaches Cloudflare and the other caches xSpeed clears.
- Fixed: Saving a page clears cached pages that show it in a widget from another plugin or in a Thrive layout.
- Fixed: On nginx, a server block pasted without xSpeed's version line is reported as one to paste again, instead of as missing.
- Fixed: On Apache, when static cache hits cannot be counted, the dashboard says so instead of reporting a low hit ratio.
- Fixed: The page cache drop-in says which copy is running, and an outdated copy is flagged once and replaced on the next wp-admin load.
- Fixed: A request that asks for JSON is no longer cached or served as the page, so a page rendered without its styles for such a request no longer reaches visitors. On nginx, the dashboard asks you to paste the server block again.
- Fixed: An excluded URL with a `[` that is never closed, such as `/shop/[abc`, no longer logs a PHP warning on every page. The bracket is read as a plain character.

Preloader:
- New: Skip these post types leaves chosen post types out of the crawl, and the panel shows how many URLs were skipped. `wp xspeed preloader set` changes the settings from the command line.
- Fixed: Saving the Preloader panel no longer clears the page cache or moves the next scheduled crawl.
- Fixed: A crawl that stops moving is restarted by the next scheduled crawl, or resumed when the Preloader panel or `wp xspeed preloader status` is opened.
- Fixed: Sitemaps on sites with plain permalinks are read correctly, so the crawl queues every page instead of one.

Optimization:
- New: Defer the cookie consent banner (opt-in) lets Defer JS defer a consent banner, including one pasted into the theme.
- Improved: Delay JS never postpones the banners of 14 more consent tools, including Termageddon, CookieAdmin, WPConsent, TermsFeed and GetTerms.
- Fixed: Minify HTML keeps conditional comments that show content to other browsers, and an empty comment no longer removes the markup after it.
- Fixed: Only one image gets high fetch priority, and each image is preloaded once.
- Fixed: A restored script's preload hint points at the right file, on cached and uncached pages.
- Fixed: The video autoplay script is added only to pages that have a video.

Object Cache:
- Fixed: Browser autofill can no longer save a Redis username and password, and Enable and Switch test a changed host and port before saving them.

Dashboard & Admin UX:
- Improved: Share usage data is off until you turn it on, in the setup wizard and in Settings, and both say what is sent: your email address and display name when you turn it on, then site details once a day.
- Fixed: An import from another caching plugin that brought some settings across shows as partly done, with the reason for each skipped group.
- Fixed: With Import & switch to xSpeed, the old plugin's page cache file is saved first under a name that cannot be guessed, and xSpeed then takes over the page cache and checks that it serves pages.

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
