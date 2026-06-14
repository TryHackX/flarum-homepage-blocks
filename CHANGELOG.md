# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.1.9] - 2026-06-14

Robustness patch from a third-party review pass. **No database migrations, no
frontend changes** (PHP only — `composer update` + `php flarum cache:clear`).

### Robustness
- **`fetchRaw()` now guards a `curl_init()` failure.** It checks `$ch !== false`
  before calling `curl_setopt()` and catches `\Throwable` (not just `\Exception`),
  matching `RecaptchaGuard::postSiteverify()`. Previously, if `curl_init()`
  returned `false` (e.g. resource exhaustion) on a PHP 8 build,
  `curl_setopt(false, …)` raised a `TypeError` — an `\Error`, which the
  `\Exception`-only catch did not catch — propagating as an HTTP 500. It now falls
  through to the `file_get_contents` path. (audit #1)
- **Internal stats cache is now single-flighted.** `handleInternal()` refreshes
  through a new `refreshInternalSingleFlight()` (`LOCK_EX|LOCK_NB` on a `.lock`
  file), mirroring the external-stats path. Under a concurrent cold/expired cache
  (e.g. right after a deploy or every TTL tick under load) a single worker
  recomputes the 5–7 `COUNT`/`SUM`/`AVG` aggregates while the others serve stale
  data (or, only on a truly cold first load, compute best-effort) instead of every
  worker hitting the database simultaneously. (audit #3)

### Notes — review findings not acted on
- File-based limiter/cache multi-node (HIGH) — kept: deliberate single-node design
  (relies on `flock` + atomic `rename`; the default `file` cache driver wouldn't
  fix multi-node anyway). Documented in the README.
- `resolve()` in `extend.php` field callbacks — kept: `->field()` closures aren't
  handed the container, so resolving there is idiomatic.
- `admin/index.js` size / `TrackerInfo.js` JSX — structural/style only, no
  functional impact. (The "only TrackerInfo uses JSX" claim is also inaccurate —
  `CustomLinks.js` uses JSX too, so it's a mixed-style codebase, not a lone file.)

## [2.1.8] - 2026-06-13

Small convention fix from a third-party review pass. **No database migrations, no
frontend changes** (PHP only — `composer update` + `php flarum cache:clear`).

### Changed
- **`FieldLengthModifier` receives its logger via constructor injection** instead
  of the `resolve(\Psr\Log\LoggerInterface::class)` service-locator call inside the
  catch block that was introduced in 2.1.7. Behaviour is unchanged (still a single
  once-per-process warning if the Reflection ever fails); the dependency is now
  declarative and the container auto-wires it (the class is built via
  `resolve(FieldLengthModifier::class)` in `extend.php`).

### Notes — review findings not acted on
- **Multi-server points/stats storage (HIGH)** — a known, deliberate **single-node
  design**. The per-IP bucket relies on `flock` + atomic `rename`, which require a
  local filesystem; switching to `Illuminate\Cache` would *not* fix multi-server
  with the default `file` driver and would drop the atomicity guarantees. Already
  documented as a single-node limitation in the README; operators running multiple
  app servers should front it with a shared store (Redis / DB).
- **Reflection coupling in `FieldLengthModifier` (HIGH)** — there is no public
  core API to remove a validation rule, so Reflection is the only way to *relax*
  core's `min:`/`max:` limits. It is guarded, degrades to core defaults, and logs
  once (2.1.7). A core `removeRule()` API / a CI shape-assertion test are out of
  scope for this extension.
- **`TrackerStatsController` size / SRP** — the class is cohesive (all stats
  concerns) and security-sensitive (guard + single-flight); an unsolicited split
  into 3 classes carries regression risk with no functional benefit here, so it is
  left as-is.
- **`resolve()` in `extend.php` field callbacks** — Flarum's `->field()` closures
  are not handed the container, so resolving the modifier inside them is the
  idiomatic choice; kept.

## [2.1.7] - 2026-06-13

Defensive hardening from a third-party review pass. **No database migrations, no
frontend changes** (PHP only — `composer update` + `php flarum cache:clear`).

### Robustness
- **reCAPTCHA verification no longer assumes cURL is available.** `verifyToken()`
  now routes its `siteverify` POST through a new `postSiteverify()` helper that
  guards `curl_init()` with `function_exists()` (and a `$ch !== false` check) and
  falls back to a `file_get_contents` POST via stream context — mirroring the
  pattern already used in `TrackerStatsController::fetchRaw()`. On a hardened PHP
  build with the cURL extension disabled, the old code called
  `curl_setopt(false, …)` and raised an unhandled `TypeError` (HTTP 500); it now
  degrades gracefully and still verifies. Fail-closed behaviour on total failure
  is preserved (an unverifiable token is rejected).
- **A silently-broken field-length override is now logged.** If the Reflection
  that `FieldLengthModifier` uses to relax core's `min:`/`max:` validation rules
  ever fails (e.g. a future Flarum core refactor of the internal `$rules`
  property), it now emits a single warning to the log (once per process) instead
  of silently falling back to core defaults with no signal.

### Notes — review findings not acted on
- **"Missing source file `HomepageMainBlock.js`" is a false positive.** The file
  is committed (since 2.0.1), present on `origin/main` and `origin/flarum-2`,
  correctly cased, and a clean `git archive` checkout of `HEAD` rebuilds with
  `npm run build` successfully. The source tree is reproducible; nothing to fix.
- Re-raised items kept as-is for reasons already documented: file-based
  limiter/cache (single-node by design; relies on `flock` + atomic `rename`),
  OFFSET-based random pick (the keyset alternative biases the distribution on
  id-gapped tables), `resolve()` in `extend.php`, native filesystem I/O, and the
  TypeScript migration. `preflightCheck()` keeps using `fetch` deliberately, so
  the expected `403`/`429` pre-flight responses don't trip Flarum's global
  request-error handling.

## [2.1.6] - 2026-06-13

Tag-only release — identical in content to 2.1.5 (version-tracking bump, no code
changes).

## [2.1.5] - 2026-06-13

Privacy + code-quality patch from a third-party review pass. **No database
migrations** (PHP + frontend — `composer update`, `php flarum cache:clear`, and
the rebuilt `js/dist` are included).

### Security / privacy
- **The external-stats proxy URL is no longer exposed to visitors.**
  `external_stats_url` was serialized into the public forum payload via
  `serializeToForum`, yet no forum-side code reads it (the admin form edits it
  through the admin settings API, and the controller reads the setting
  server-side). It is now dropped from the forum payload, so the admin-configured
  proxy / tracker URL — which can reveal a private IP:port — is no longer handed
  to every guest. `external_stats_native_url` was never serialized and stays
  server-only.

### Changed
- **De-duplicated the `transStr()` helper.** The identical helper (coerces
  `app.translator.trans()` output to a plain string) was copy-pasted into
  `TrackerStats`, `AdvancedFilters` **and** `CaptchaModal`; it now lives in
  `js/src/forum/utils/trans.js` and is imported by all three.

### Notes — review suggestions deliberately not applied
- **reCAPTCHA "treat a timeout as a soft pass" / verify asynchronously** —
  rejected on **security** grounds: an auth gate must fail closed; returning an
  optimistic `ok` before Google confirms the token would let anyone bypass
  reCAPTCHA by inducing a timeout. The 2.1.4 connect-timeout already bounds the
  worker-stall case; the total timeout stays generous to avoid denying legitimate
  users on a slow-but-reachable Google.
- **Raw cURL → Laravel HTTP client** — `illuminate/http` is **not installed** in
  Flarum 2.x (the class doesn't exist); not adopted.
- **`Illuminate\Filesystem` for the rate-limiter** — needs `flock` + atomic
  `rename` the abstraction doesn't expose; kept native (the review now agrees the
  lock is justified).
- **Reflection on core's `$rules`** ([FieldLengthModifier](src/Api/FieldLengthModifier.php))
  — there is no public rule-removal API in core, so this stays; the coupling is
  already documented in the class and degrades safely to core defaults if core
  changes.
- **Keyset-random for the random-discussion pick** — not adopted: it biases the
  distribution on id-gapped tables, and the current `offset` pick is correct and
  fast enough at this scale.

## [2.1.4] - 2026-06-12

Small maintainability + robustness patch from a third-party review pass.
**No database migrations, no frontend changes** (PHP only — `composer update` +
`php flarum cache:clear` after updating).

### Changed
- **External-stats fetch de-duplicated.** `fetchRawContent()` and
  `fetchExternalStats()` shared near-identical cURL boilerplate; the transport
  now lives in a single `fetchRaw()` helper (cURL with forced IPv4, connect/total
  timeouts and redirect handling, plus the `file_get_contents` fallback), and the
  JSON-proxy path simply decodes its result. The fallback now also sends the
  `User-Agent` header on every path. Behaviour is unchanged; ~50 lines of
  copy-paste removed.

### Performance & robustness
- **reCAPTCHA verification frees the worker faster when Google is unreachable.**
  Added a 5 s `CURLOPT_CONNECTTIMEOUT` to the `siteverify` call (the 10 s total
  timeout is unchanged), so a Google outage releases the PHP-FPM worker after
  ~5 s instead of holding it for the full timeout.

### Notes
- Several review suggestions were **deliberately not applied** because they are
  inapplicable to Flarum 2.x or would regress behaviour: `SteamRatingSort`'s
  `sortMap()` / alias methods are **required** by Flarum core (it calls
  `sortMap()` on every registered sort via `Api\Resource\Concerns\HasSortMap` —
  removing them 500s the whole forum); Laravel's `Illuminate\Http\Client` is
  **not bundled** with Flarum 2.x; the file-based rate-limiter depends on
  `flock` + atomic `rename`, which the Filesystem abstraction doesn't expose; and
  a TypeScript migration is large, behaviour-neutral churn. The useful parts
  (de-duplication, connect timeout) were taken.

## [2.1.3] - 2026-06-11

Security + performance hardening. **No database migrations.** Existing behaviour
is unchanged for visitors; the changes are server-side robustness and a smaller
request footprint. After updating run `composer update` (autoload) +
`php flarum cache:clear` and rebuild the frontend (`npm run build` in `js/`).

### Security
- **reCAPTCHA token no longer travels in the URL.** It is sent in an
  `X-Recaptcha-Token` request header instead of a `?recaptcha_token=…` query
  parameter, so it no longer lands in web-server / reverse-proxy access logs
  (which routinely record full request URIs). The server reads the header first
  and still accepts the old POST-body / query forms, so a stale cached frontend
  keeps working during the rollout.

### Performance & robustness
- **Per-IP points bucket is now race-free.** `charge()` / `block()` /
  `refillToStart()` / `getBlockRemaining()` run their read→modify→write under an
  exclusive per-IP file lock (`flock`, the same pattern already used for the
  external-stats single-flight). This closes a TOCTOU window where two parallel
  requests from one IP could both read the same balance and only one deduction
  would stick — letting a scripted burst drain the bucket slower than configured
  and weakening the limiter. Orphan `.lock` files are swept by the existing
  garbage collector.
- **Internal stats are cached server-side (~10 s file cache).** A cold request
  previously fired 5–7 aggregate `COUNT`/`SUM`/`AVG` queries *every* time; the
  global figures (identical for every visitor) are now memoised in
  `storage/cache/tryhackx_internal_stats.json`, so concurrent cold loads share
  one computation per interval.
- **External-stats cache write is now atomic** (temp file + `rename`, matching
  the per-IP buckets), so a reader that isn't holding the lock can never observe
  a half-written cache file.

### Changed
- The **feature-availability flags** `tryhackxHomepageHasRating` /
  `tryhackxHomepageHasViews` now serialise from dedicated, self-documenting
  setting keys (`…​.has_rating` / `…​.has_views`) instead of reusing the
  unrelated `recaptcha_points_enabled` key. Behaviour is identical (the frontend
  only checks whether the attribute is present), the code just reads clearly now.
- **`composer.json`: `php` tightened `^8.2` → `^8.3`** to match Flarum 2.x's own
  minimum, so the extension can no longer be installed onto a PHP 8.2 host where
  core would fail with a confusing error.

### Notes
- The per-IP limiter stores its buckets on the **local filesystem (single
  node)**. On a horizontally-scaled deployment each app server keeps its own
  buckets; operators running multiple nodes behind a load balancer should front
  it with a shared store. Single-server installs (the common case) are unaffected.

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
