# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-04-13

### Added
- Section 1 and Section 2 enable/disable toggles
- Flarum 1.x compatibility (backported from v2.0.1)

### Changed
- Ported extension from Flarum 2.x API to Flarum 1.x API
- Replaced `Extend\SearchDriver` with `Extend\Filter` for discussion filters
- Replaced `Extend\ApiResource` with `Extend\ApiController` for sort fields
- Replaced Schema field validation with `Extend\Validator` for title/content length overrides
- Replaced `app.registry` with `app.extensionData` in admin JS
- Downgraded PHP syntax from 8.0+ to 7.3+ (removed union types, match expressions, constructor promotion)
- Updated `flarum-webpack-config` from v3 to v2
- Moved support button to top of admin settings page
- Moved inline styles to CSS classes

## [1.0.0] - Initial tracked release

### Added
- Random discussion buttons with configurable JSON
- Tracker info panel with copy-to-clipboard support
- Dual statistics system (internal database + external OpenTracker)
- Custom links bar with color-coded buttons
- Advanced discussion filters (title, user, rating, date, category, sort)
- Content validation overrides for title and content length limits
- reCAPTCHA v2/v3 protection for stats API
- Collapsible sections
- Hero banner toggle
- Tag filtering with discussion counts
- Polish and English locales
