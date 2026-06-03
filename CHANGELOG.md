# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
