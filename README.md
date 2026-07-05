# Web Speed for WordPress

Publish fresh, first-party maps of your pages to the **agentic web** — so AI agents read your content accurately and never quote a stale price or headline.

[Web Speed](https://getwebspeed.io) is the machine-readable layer AI agents use to read and act on the web. This plugin makes your WordPress site a **first-party publisher**: instead of letting agents scrape and guess, your site sends a clean, structured, up-to-date map of each page to the Web Speed registry the moment it changes.

## Why

When an AI agent answers *"what does this cost?"* or *"when are they open?"* about your site, it may scrape the page itself — misreading your layout or working from a stale cache, then quoting **your** business wrong. With this plugin, *you* are the authoritative source for your own URLs:

- **Push on publish** — publish or edit a public page and its up-to-date map reaches the registry within seconds (in the background; your editor never waits on it).
- **Weekly baseline** — a weekly re-scan of every public page catches anything an edit didn't touch (a theme tweak, a menu change, a price in a widget).
- **One-click verification** — the plugin serves the domain-ownership file for you; no FTP.
- **Analytics** — see how many agents read your pages, and which pages, from the publisher dashboard.

First-party maps are trusted over anything a crawler guessed, and can't be overwritten by a crawl while they're fresh.

## How it works

1. **Connect** (Settings → Web Speed) registers your domain and stores a one-time site token.
2. **Verify** — Web Speed fetches `/.well-known/webspeed-verify.txt` (which this plugin serves automatically) to confirm you own the domain.
3. On publish/edit, the plugin fetches the page's rendered HTML and sends it to `POST /v1/plugin/ingest`. **Web Speed maps it centrally** with its extraction engine — your server just supplies the already-rendered HTML, so there's no scraping and no client-side render cost.
4. A weekly WP-Cron job re-scans every public page through a rate-limited background queue.

Only **public, published, non-password** content is ever sent. Drafts, private, and password-protected posts are skipped client-side, and the registry independently rejects anything that looks personalized or login-gated.

## Requirements

- WordPress 5.5+
- PHP 7.4+
- The site reachable over HTTPS at a public domain (for verification + the loopback fetch)
- A free Web Speed publisher account — created automatically on first **Connect** (no signup form)

## Installation

1. Copy this folder to `wp-content/plugins/web-speed`.
2. Activate **Web Speed** in *Plugins*.
3. Go to **Settings → Web Speed**, click **Connect to Web Speed**, then **Verify domain**.

Publishing starts automatically once verified.

> **Subdirectory installs:** if WordPress is not at your domain root, automatic verification may not reach `/.well-known/webspeed-verify.txt` at the root — the settings screen shows the token so you can place the file manually.

## Configuration

All optional.

**Constants** (define in `wp-config.php`):

| Constant | Default | Purpose |
| --- | --- | --- |
| `WEBSPEED_API_BASE` | `https://api.getwebspeed.io` | Point at a staging / self-hosted registry |

**Filters:**

| Filter | Default | Purpose |
| --- | --- | --- |
| `webspeed_api_base` | the constant above | Runtime override of the API base URL |
| `webspeed_post_types` | public, viewable types | The exact post types to publish |
| `webspeed_excluded_post_types` | attachments, menu items, block/template types | Types excluded before filtering |
| `webspeed_drain_batch` | `15` | Pages pushed per background queue tick |

```php
// Example: publish posts, pages, and a custom "event" type only.
add_filter( 'webspeed_post_types', function () {
    return array( 'post', 'page', 'event' );
} );
```

## Privacy & data

The plugin sends the **rendered HTML of pages that are already public** on your site, plus each page's URL. It never sends drafts, private or password-protected content, user data, or credentials. The site token is entered at runtime and stored in the WordPress options table (autoload off); it is never committed to this repository.

## Development

The plugin ships a PHPCS ruleset (`phpcs.xml.dist`) based on **WordPress-Extra**.

```bash
# one-time: install the standards
composer global config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer global require wp-coding-standards/wpcs:"^3.1"

# from the plugin directory:
~/.composer/vendor/bin/phpcs      # lint
~/.composer/vendor/bin/phpcbf     # auto-fix
```

`php -l` passes on PHP 7.4–8.5, and PHPCS reports 0 errors / 0 warnings under the shipped ruleset.

## Links

- Web Speed — <https://getwebspeed.io>
- Web Speed OSS agent — <https://github.com/Dominic-Pi-Sunyer/web-speed-oss>

## License

[GPL-2.0-or-later](LICENSE).
