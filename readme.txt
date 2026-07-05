=== Web Speed ===
Contributors: webspeed
Tags: ai, agents, structured data, seo, automation
Requires at least: 5.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish fresh, first-party maps of your pages to the agentic web so AI agents read your content accurately — never a stale price or headline.

== Description ==

AI agents increasingly read the web on behalf of people — answering "what does this cost", "when is this open", "what's the latest". When they scrape your pages themselves they can misread the layout or work from a stale cache, and then quote **your** business incorrectly.

Web Speed fixes that at the source. Install this plugin and your site sends a clean, structured, **first-party** map of each page to the Web Speed registry — the layer agents read from — the moment the page changes, plus a weekly baseline re-scan so nothing drifts out of date.

**What it does**

* **Push on publish** — whenever you publish or edit a public post or page, its up-to-date map is sent to the registry within seconds (in the background; your editor never waits on it).
* **Weekly baseline** — once a week the plugin re-scans every public page, catching anything an edit didn't touch (a theme change, a menu edit, a price in a widget).
* **One-click verification** — the plugin serves the ownership-verification file for you, so connecting is two clicks with no FTP.
* **Analytics** — see how many agents read your pages, and which pages, from the publisher dashboard.

**Privacy & safety**

* Only **public, published, non-password** content is ever sent. Drafts, private posts, and password-protected posts are never uploaded, and the registry independently rejects anything that looks personalized or login-gated.
* Your pages become the *authoritative* source for your own URLs: a first-party map is trusted over anything a crawler guessed, and can't be overwritten by a crawl while it's fresh.
* No page content leaves your server except the rendered HTML of pages that are already public on the internet.

This plugin requires a free Web Speed publisher account, created automatically the first time you click **Connect** (no signup form). See https://getwebspeed.io.

== Installation ==

1. Upload the `web-speed` folder to `/wp-content/plugins/`, or install the plugin through the Plugins screen.
2. Activate the plugin.
3. Go to **Settings → Web Speed**.
4. Click **Connect to Web Speed**, then **Verify domain**. That's it — publishing starts automatically.

For sites installed in a subdirectory (WordPress not at the domain root), you may need to place the verification file at your domain root manually; the settings screen shows the value if automatic verification can't reach it.

== Frequently Asked Questions ==

= Does this send private or draft content? =
No. Only public, published, non-password-protected posts and pages are sent, and the registry re-checks and drops anything that looks personalized or auth-gated.

= Will it slow down my site? =
No. Saves are never blocked — pushes run in the background via WP-Cron. The weekly baseline drains in small batches to stay within rate limits.

= Does it work with page builders / custom post types? =
Yes. Any public, viewable post type is included. You can customize the list with the `webspeed_post_types` filter.

= I run a headless / single-page-app front end. =
Version 1 sends the server-rendered HTML WordPress emits. If your front end renders entirely in the browser, prerender it (SSR/SSG) so the HTML is complete, or use the Web Speed API directly.

== Changelog ==

= 1.0.0 =
* Initial release: connect + one-click domain verification, push-on-publish, weekly baseline re-scan, publisher analytics link.
