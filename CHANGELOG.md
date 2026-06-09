# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.1.1] - 2026-06-10

### Added
- **"Steam DB Rating" sort** for the discussion list — restores the option that
  2.1.0 removed, now as a real **confidence-weighted** rating that adapts
  SteamDB's review-score formula to topic-rating's stars:
  `ReviewScore = rating_average / 5`, `Total = rating_count`,
  `Rating = ReviewScore − (ReviewScore − 0.5) · (Total + 1)^(−log₁₀2)`.
  A topic rated 5★ by 100 people outranks one rated 5★ by a single person, and
  unrated topics sort last. Implemented as a custom `SteamRatingSort` + a search
  mutator (the SQL uses only `POWER()`, portable to MySQL/MariaDB/PostgreSQL).
  Shown and registered only when `tryhackx/flarum-topic-rating` is enabled.

> Frontend + backend; **no migrations**. New PHP classes (`Sort\SteamRatingSort`,
> `Search\SteamRatingSortMutator`) — run `composer update` (autoload) + `cache:clear`.

## [2.1.0] - 2026-06-09

Security + reliability release. **No database migrations.** Existing installs
that already use reCAPTCHA + points keep their current behaviour (the new
"when points run out" action defaults to *Show reCAPTCHA*).

### Added
- **IP-block rate limiting — the points system now works without reCAPTCHA.**
  New admin setting **"When points run out"** lets you choose between *Show
  reCAPTCHA challenge* (previous behaviour) and *Temporarily block the IP* for
  a configurable **"IP block duration"** (default 60 s). Block mode needs no
  Google reCAPTCHA keys at all — ideal if you only want simple per-IP rate
  limiting. When the block expires the visitor's budget resets to full.
- **Professional "slow down" notice.** When an IP is blocked the visitor sees a
  dismissible alert with a live countdown ("Too many actions — try again in
  N s", then "You can try again now"), in Polish and English. Blocked responses
  use HTTP `429` with a `Retry-After` header; the stats auto-refresh backs off
  for the block duration instead of hammering the server.
- The points budget refills continuously (unchanged), so casual, regular use
  stays smooth while bursts are throttled.
- **"When the block ends"** setting: choose whether the visitor's budget comes
  back **full** (forgiving, default) or **empty and refilling from zero**
  (stricter). During a block points never refill and the block is never lifted
  early — it always lasts its full configured duration.
- **Friendly editor for the Section 1 links** (replaces the raw-JSON textarea):
  add / remove / reorder link rows — laid out as cards — with a name, URL,
  colour, an "open in a new tab" toggle and an optional **CSS class** per link,
  plus an optional **links section title** and a **Custom CSS** box. You can
  turn a link into a fancy button (e.g. a glow-on-hover button) by pasting your
  CSS into the box and putting the class name on the link. The underlying
  `custom_links` JSON format is unchanged (existing links keep working).

### Security
- **IP rate limiting can no longer be bypassed with a spoofed header.** Per-IP
  buckets are now keyed on Flarum core's `ipAddress` (set by the `ProcessIp`
  middleware, honouring configured trusted proxies) instead of the
  client-supplied `X-Forwarded-For` / `X-Real-IP`. Previously any visitor could
  send a fresh header on each request to get an unlimited new budget, defeating
  the captcha/points gate entirely.
- **Random-discussion endpoint now respects visibility.** It scopes with
  `whereVisibleTo($actor)`, so it can no longer reveal the id/slug/title of
  discussions in restricted tags or of pending (unapproved) discussions. It
  previously only excluded private/hidden rows.
- **Outbound HTTPS for external stats now verifies TLS** (removed the global
  `CURLOPT_SSL_VERIFYPEER=false` / `VERIFYHOST=0` and the `verify_peer=>false`
  stream fallback).
- The points economy (starting balance, refill, action costs, block duration)
  is **no longer serialised into the public forum payload** — only the on/off
  flag the frontend needs is exposed.
- **Custom-link URLs are sanitised before rendering** — `javascript:` / `data:`
  and other non-http(s) schemes are dropped; the per-link CSS class is reduced to
  class-name characters; and the Custom CSS is injected via `textContent` (never
  `innerHTML`), so a Section 1 link / style entry can't become a stored-XSS vector.

### Performance & robustness
- **External stats no longer cause a thundering herd.** The cold-cache fetch is
  single-flighted with a file lock (one worker refreshes, the rest serve cached
  or stale data) and is moved off the points budget, so the UI's periodic
  refresh is free and shared across visitors. Fetch timeout lowered 30 s → 12 s.
- **Random discussion no longer uses `ORDER BY RAND()`** (full filesort); it now
  counts matches and picks a random offset.
- Rating sorts and the *Rating interval* filter are **registered only when
  `tryhackx/flarum-topic-rating` is enabled** (via `Extend\Conditional`), and
  the matching UI options — plus the *Views* sort (needs `fof/discussion-views`)
  — are hidden when their provider is absent. On a forum without those
  extensions, selecting them no longer triggers an HTTP 500.
- Per-IP bucket files are now written atomically and **garbage-collected**
  (probabilistic cleanup of long-idle files) so the cache directory can't grow
  without bound.

### Changed
- API controllers use constructor dependency injection instead of manually
  `new`-ing `PointsManager` / `RecaptchaGuard`.
- The Reflection-based title/content length override moved from a global
  `replaceMinMax()` function into an injected `FieldLengthModifier` class.
- Magnet stats are read through a thin `MagnetLink` Eloquent model instead of a
  raw database connection.
- The Cancel button in core's *Reset extension settings* modal is now styled by
  extending `ResetExtensionSettingsModal` directly, replacing the previous
  document-wide `MutationObserver`.
- `UserFilter` uses a `whereExists` sub-query instead of joining the `users`
  table (prevents "table already joined" errors when combined with other
  gambits). Interval filters now tolerate array input.
- `composer.json`: `flarum/tags` pinned to `^2.0.0-rc.1` (was `*`);
  `minimum-stability: dev` removed.
- The admin points-limiter settings were rebuilt with CSS classes (fixed-width
  fields, capped row width) instead of inline styles, and reordered: budget →
  *Action costs* (now including the guest surcharge) → *when points run out*.

### Fixed
- The **clear (×) button** in the advanced filters again wipes the whole field
  with the accelerating "held-backspace" animation on a single click. It had
  regressed to removing only one character per click (it required an actual
  press-and-hold); clicking now runs the cascade to completion on its own, and
  the delete is noticeably snappier (faster first tick + acceleration).

### Removed
- The misleading **"Steam DB Rating"** sort option (it was a duplicate of
  *Average rating*). *(Restored as a real, confidence-weighted sort in 2.1.1.)*
- Dead code: unused `DatabaseSearchState` imports, the unused
  `guardedAction` / `isCaptchaRequiredResponse` helpers, non-functional sort
  aliases, unused locale keys, and three unused PHP methods
  (`PointsManager::getBalance()` / `isGuest()`, `RecaptchaGuard::check()`).

## [2.0.5] - 2026-06-01

### Fixed
- **The three magnet-click sort options now actually work.** *Magnet clicks
  (total)*, *Magnet clicks (top magnet)* and *Recently clicked* (sort
  indices 6/7/8) previously had no backend and silently fell back to
  *Creation date*. They are now wired to real sort fields provided by
  `tryhackx/flarum-magnet-link` **2.1.0+** (topic-scoped — counting clicks
  from each discussion's own posts):
  - the dropdown `sortMap` points at the new aliases (`most_magnet_clicks`,
    `most_magnet_clicks_single`, `recently_magnet_clicked`, …);
  - `DiscussionListState.sortMap` resolves those aliases to the API sort
    fields (`-magnetClicksTotal`, `-magnetClicksMax`, `-magnetLastClicked`).

### Changed
- **Magnet-click sort options are gated on `tryhackx/flarum-magnet-link`
  being enabled.** Detected via its `magnetClickTracking` forum attribute.
  When the extension is off, the three options are hidden and their sort
  aliases are not registered, so a stale URL degrades to the default sort
  instead of erroring — no more dead/misleading options.
- Locale labels clarified: *Magnet clicks (total)* / *Magnet clicks (top
  magnet)* / *Recently clicked* (en) and the Polish equivalents.

### Fixed (docs)
- README integration lines restored and corrected: `tryhackx/flarum-magnet-link`
  now unlocks magnet click **stats and sorts** (the sort works as of
  magnet-link 2.1.0).

> Frontend-only here (no migrations in homepage-blocks). The actual sorting
> is implemented in `tryhackx/flarum-magnet-link` 2.1.0 — update both
> together to get the working sorts.

## [2.0.4] - 2026-05-30

### Changed
- Documentation refresh aligned with the broader TryHackX extension
  family (Topic Rating, Magnet Link, Thumb Sliders, Advanced Pages).
- README highlights the runtime relationships with companion
  extensions (rating filters require `tryhackx/flarum-topic-rating`;
  magnet-click sort requires `tryhackx/flarum-magnet-link`; view sort
  requires `fof/discussion-views`).

### Fixed
- **"Oops! Something went wrong" when disabling the extension from the
  admin.** Root cause: `extend.php` declared a global `replaceMinMax()`
  helper, and Flarum loaded the file twice in the same request when
  toggling the extension (re-evaluating the extender list), so PHP
  fatalled with *Cannot redeclare function replaceMinMax()*. The
  disable itself had already succeeded at that point (page refresh
  showed it disabled), but the request died with a 500 and the admin
  showed the generic error alert. Fixed by wrapping the function in
  `if (!function_exists('replaceMinMax')) { ... }` so the declaration
  is idempotent across double-includes. Enable / disable now finishes
  cleanly with no admin error.
- **Cancel button in core's "Reset extension settings" modal** now
  uses Flarum's standard `Button--inverted` style so it doesn't render
  as a plain borderless button. Implemented with a small
  `MutationObserver` that adds the `Button--inverted` class to the
  Cancel button when the modal appears in the DOM (the modal class
  is lazy-loaded by core and not statically importable, so we can't
  extend its prototype directly). Each TryHackX extension registers
  this independently; repeated `classList.add` of the same class is
  a no-op.

## [2.0.1] - 2026-04-09

### Added
- Section 1 and Section 2 enable / disable toggles.

### Changed
- Moved support button to the top of the admin settings page.
- Removed the "General" section header from the settings page.
- Moved inline styles to CSS classes (`sectionHeader`, `lengthGroup`,
  `lengthRow`, `lengthField`, `lengthDefault`).
- Removed margin-top / padding-top / border-top CSS from the support
  button section.

## [2.0.0] - Initial tracked release

### Added
- Random discussion buttons (configurable JSON).
- Tracker info panel with copy-to-clipboard support.
- Dual statistics system (internal database + external OpenTracker).
- Custom links bar with colour-coded buttons.
- Advanced discussion filters (title, user, rating, date, category, sort).
- Content-validation overrides for title and content length limits.
- reCAPTCHA v2 / v3 protection for the stats API.
- Collapsible sections.
- Hero banner toggle.
- Tag filtering with discussion counts.
- Polish and English locales.
