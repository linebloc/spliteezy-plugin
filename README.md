# Spliteezy — A/B Split Tests Made Easy

**A/B testing for WordPress with server-side variant delivery — no flicker, no redirects, no duplicate URLs.**

Most A/B testing tools swap content with JavaScript after the page has already started rendering, so visitors catch a flash of the original before the variant snaps in. Others serve each variant at its own URL, splitting link equity and leaving 404s behind when a test ends.

Spliteezy picks the variant on the server, before the first byte of HTML goes out. Visitors always see exactly one version of the page — at the same URL, with zero flicker.

---

## How it works

1. **Duplicate any page as a variant** — variants are real WordPress posts, so you edit them with the tools you already use: Gutenberg, Elementor, Divi, Bricks, or the Classic Editor.
2. **Spliteezy splits your traffic** — every visitor is assigned to a variant deterministically, so they see the same version on every visit. The URL never changes.
3. **Watch the results roll in** — page views, clicks, scroll depth, time on page, form submissions, and video plays are tracked automatically, with a statistical confidence engine that tells you when you have a winner.

---

## Why Spliteezy

- **No flicker, ever** — the variant is chosen in PHP before any output, so the correct version is served from the very first byte.
- **Same URL for every variant** — no duplicate content, no SEO risk, no broken links after a test ends.
- **Any page builder** — if it edits WordPress posts, it works with Spliteezy.
- **Built for caching** — three delivery modes let you decide how tests and your page cache work together: exclude tested pages from cache (default), keep one cached copy per variant, or embed variants in a single cacheable page. Affected pages are purged automatically when tests change.
- **Eight goal types** — page reached, click, scroll depth, time on page, element view, form submission, video play (YouTube / Vimeo / HTML5), and external events from GA4, GTM, or Meta Pixel.
- **Private by design** — visitors are identified only by an anonymous random token in a first-party cookie. No IP addresses, no names, no personal data. The API key is HMAC-signed on every request, never reaches the browser, and is encrypted at rest in your WordPress database.

---

## Requirements

- WordPress 6.3+
- PHP 7.4+
- A [Spliteezy account](https://spliteezy.com) (free plan available — no credit card required)

---

## Installation

**From WordPress.org (recommended)**

Search for **Spliteezy** in *Plugins → Add New* and click *Install Now*.

**Manual**

1. Download the versioned `spliteezy-x.y.z.zip` from the [Releases](https://github.com/linebloc/spliteezy-plugin/releases) page — or the rolling **latest** pre-release for the newest build from `main`.
2. Upload and activate via *Plugins → Add New → Upload Plugin*.

> Don't use GitHub's "Download ZIP" button — it wraps the plugin in a `spliteezy-plugin-main/` folder, which installs under the wrong slug. The release ZIPs are packaged correctly.

**Setup**

1. Go to *Spliteezy → Settings* and click **Connect to Spliteezy**.
2. Log in to your Spliteezy account (or create a free one) and approve the connection.
3. You're sent straight back to WordPress, fully configured — no API keys to copy, ever.

Create your first test under *Spliteezy → A/B Tests → New Test*: pick a page, duplicate it as a variant, edit the variant with your page builder, and activate.

---

## Repository layout

- `spliteezy.php` — plugin bootstrap
- `src/` — plugin source (assignment, tracking, caching integrations, admin)
- `assets/js/tracker.js` — dependency-free front-end tracker
- `assets/js/dashboard.js` — compiled React admin dashboard
- `dashboard/` — the dashboard's React/Vite source
- `languages/` — translations

## Building the admin dashboard

The compiled dashboard (`assets/js/dashboard.js`, `assets/css/dashboard.css`) is built from the `dashboard/` Vite project:

```bash
cd dashboard
npm install
npm run build   # production build
npm run dev     # watch mode
```

---

## Contributing

Pull requests are welcome. For significant changes, please open an issue first to discuss the approach.

**Business logic belongs in the Spliteezy service, not here.** The plugin is intentionally a thin client — it fetches a test manifest, assigns visitors, and forwards events. Stats, billing, test configuration, and analytics live at [spliteezy.com](https://spliteezy.com).

**PHP style**: WordPress Coding Standards via PHPCS.
**JS style**: `tracker.js` is vanilla JS with no build step; the dashboard uses React + Vite.

---

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) © [Spliteezy](https://spliteezy.com)
