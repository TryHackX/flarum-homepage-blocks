# TryHackX Homepage Blocks

A Flarum extension that adds powerful customisable homepage blocks:
random-discussion buttons, tracker information panels, dual statistics
(internal database + external OpenTracker), advanced discussion filters,
custom links, content-validation overrides, and a reCAPTCHA-protected
stats API.

> Designed to plug cleanly into a tracker-style Flarum forum. Works hand
> in hand with the rest of the TryHackX extension family —
> [`tryhackx/flarum-topic-rating`](https://github.com/TryHackX/flarum-topic-rating)
> unlocks rating filters and sorts, [`tryhackx/flarum-magnet-link`](https://github.com/TryHackX/flarum-magnet-link)
> unlocks magnet click stats and sorts, [`fof/discussion-views`](https://github.com/FriendsOfFlarum/discussion-views)
> unlocks view counters / view-based sort.

> **Note:** Recent updates target the **2.x** line only. The **1.x** branch
> (Flarum 1.8+) is **no longer actively developed** — it stays available
> for legacy installs but won't receive new features.

## Screenshots

![Mobile view of the discussion list across multiple TryHackX layout combinations](assets/ALL_MOBILE.png)

*Mobile view — discussion list rendered with different combinations of TryHackX extensions (thumbnails + ratings + views, thumbnails + views, thumbnails only, ratings only, views only, vanilla Flarum).*

![TryHackX Homepage Blocks admin settings — sections, random buttons, tracker info, statistics, custom links, content limits and reCAPTCHA](assets/TryHackX_Homepage_Blocks.png)

*TryHackX Homepage Blocks admin panel — section toggles, theme mode, custom filter labels, random-button JSON, tracker info / announce URLs, internal & external (OpenTracker) statistics, custom links JSON, content title / length overrides, reCAPTCHA gating and rate-limit settings.*

![Desktop discussion list with the full TryHackX stack — thumbnail sliders, star ratings and the magnet button](assets/ALL_VIA_MAGNETS.png)

*Desktop discussion list with the full TryHackX stack — thumbnail sliders on the left, star ratings on the right, magnet button next to each topic.*

![Desktop discussion list — magnet tooltip mid-load on a topic](assets/ALL_VIA_MAGNETS_v2.png)

*Desktop discussion list — hover state showing the magnet tooltip loading inline (powered by `tryhackx/flarum-magnet-link`).*

## Support Development

If you find this extension useful, consider supporting its development:

- **Monero (XMR):** `45hvee4Jv7qeAm6SrBzXb9YVjb8DkHtFtFh7qkDMxS9zYX3NRi1dV27MtSdVC5X8T1YVoiG8XFiJkh4p9UncqWGxHi4tiwk`
- **Bitcoin (BTC):** `bc1qncavcek4kknpvykedxas8kxash9kdng990qed2`
- **Ethereum (ETH):** `0xa3d38d5Cf202598dd782C611e9F43f342C967cF5`

You can also find the donation option in the extension's admin settings panel.

## Features

- **Random discussion buttons** — JSON-configurable buttons that pick a
  random discussion from a specific tag ("Random HD movie", "Random TV
  series", …).
- **Tracker info panel** — display BitTorrent tracker announce URLs with
  copy-to-clipboard support, a custom heading and sub-heading.
- **Dual statistics system** — show internal forum stats (from the
  database) and external OpenTracker stats side by side:
  - **Internal stats** — torrents, users, magnets, downloads, views,
    average rating (pulled from the forum database).
  - **External stats (OpenTracker)** — seeds, peers, completed
    downloads, uptime, in two modes:
    - **Native** — direct connection to the OpenTracker XML endpoint
      (`/stats?mode=everything`).
    - **Proxy** — JSON proxy URL for environments where direct access
      isn't possible.
  - Configurable refresh interval (1–300 s).
- **Custom links bar** — add colour-coded link buttons in Section 1 from a
  simple admin editor (name, URL, colour, open-in-new-tab, drag-free reorder)
  with an optional section heading. URLs are sanitised (no `javascript:` /
  `data:` schemes).
- **Advanced discussion filters** — filter bar for the discussion list
  with 7 filter types:
  - Title search
  - User search
  - Rating interval *(requires `tryhackx/flarum-topic-rating`)*
  - Date interval (Today, 1 day, 1 week, 2 weeks, 1 month, 3 / 6
    months, 1 year)
  - Category (tag) selection
  - Sort by — creation date (always available), plus average rating,
    rating count and recently rated *(with `tryhackx/flarum-topic-rating`)*,
    views *(with `fof/discussion-views`)*, and magnet clicks total / top
    magnet / recently clicked *(with `tryhackx/flarum-magnet-link`)*.
    Options whose extension isn't installed are hidden automatically.
  - Sort direction (ascending / descending)
- **Content-validation overrides** — override Flarum's built-in title
  and content length limits without patching core:
  - Title length: 1–200 characters (`varchar(200)` column max).
  - Content length: 0–16,000,000 characters (`mediumtext` column max).
  - Each toggle is independent.
- **Spam protection & rate limiting** — protect the random / search /
  stats endpoints with optional reCAPTCHA v2 / v3 **and/or** a built-in
  per-IP **points limiter**. Each visitor has a budget that refills over
  time; when it runs out you choose what happens: require a reCAPTCHA, or
  **temporarily block the IP** for a configurable duration — block mode
  needs no reCAPTCHA at all and shows the visitor a friendly countdown.
  Client IPs are resolved from Flarum core (proxy-aware), so the limit
  can't be bypassed with a spoofed `X-Forwarded-For` header.
- **Collapsible sections** — Section 1 (random buttons + stats) can be
  collapsed by default to save space.
- **Hide hero banner** — optional toggle to hide Flarum's default hero
  banner.
- **Tag filtering** — show only tags that actually have discussions,
  optionally with discussion counts next to tag names.
- **Polish & English locales** — fully translated UI.

## Requirements

- Flarum `^2.0.0-beta.7`
- `flarum/tags` (required)

### Recommended companions

These aren't strictly required but unlock additional functionality:

- [**fof/discussion-views**](https://github.com/FriendsOfFlarum/discussion-views)
  — view count statistics and view-based sorting.
- [**tryhackx/flarum-topic-rating**](https://github.com/TryHackX/flarum-topic-rating)
  — rating-based filtering and sorting (Steam-DB-style, average rating, etc.).
- [**tryhackx/flarum-magnet-link**](https://github.com/TryHackX/flarum-magnet-link)
  — magnet click statistics (tracker stats block) and topic-scoped
  magnet-click sorts (clicks total / top magnet / recently clicked).

## Installation

```bash
composer require tryhackx/flarum-homepage-blocks
php flarum cache:clear
```

## Updating

```bash
composer update tryhackx/flarum-homepage-blocks
php flarum cache:clear
```

## Configuration

1. Navigate to the **Administration** panel.
2. Find **TryHackX Homepage Blocks** in the extensions list and enable it.
3. Click the extension to access the configuration sections:

| Section | Description |
| --- | --- |
| **General** | Section titles, default-collapsed state, hero banner toggle, tag display options. |
| **Random Movies** | JSON configuration for random-discussion buttons. |
| **Tracker Info** | Tracker heading, sub-heading, announce URLs. |
| **Tracker Statistics** | Toggle internal stats, configure external OpenTracker source (native or proxy mode), refresh interval. |
| **Custom Links** | Add / reorder Section 1 link buttons (name, URL, colour, new-tab) and set an optional section title. |
| **Content Settings** | Override title and content length limits. |
| **Security (reCAPTCHA)** | Optional reCAPTCHA v2 / v3, plus the per-IP points rate limiter. Pick what happens when a visitor runs out of points: *Show reCAPTCHA* or *Temporarily block the IP* (with a configurable block duration). |

### Random buttons format

```json
[
  {"label": "Random Ultra HD (2160p)", "tag": "ultra-hd-2160p"},
  {"label": "Random Full HD (1080p)", "tag": "full-hd-1080p"},
  {"label": "Random HD (720p)", "tag": "hd-720p"}
]
```

### Custom links

Manage Section 1 links from the admin editor (name, URL, colour, open-in-new-tab,
reorder) plus an optional **links section title**. They are stored as a JSON
array of `{label, url, color, external}` objects (shown here for reference):

```json
[
  {"label": "Tracker", "url": "https://tracker.example.org/", "color": "#3498db", "external": true},
  {"label": "Home page", "url": "https://example.org/", "color": "#e74c3c", "external": true}
]
```

## API endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/tryhackx/homepage/random` | Pick a random discussion from a tag. |
| `GET` | `/api/tryhackx/homepage/stats` | Forum / tracker statistics. Optionally reCAPTCHA-gated. |
| `GET` | `/api/tryhackx/homepage/points/check` | User points / rating helper used by the filter bar. |

## Links

- [GitHub](https://github.com/TryHackX/flarum-homepage-blocks)
- [Packagist](https://packagist.org/packages/tryhackx/flarum-homepage-blocks)
- [Report Issues](https://github.com/TryHackX/flarum-homepage-blocks/issues)

## License

MIT License. See [LICENSE](LICENSE) for details.
