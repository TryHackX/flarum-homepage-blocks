# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.0.0] - 2026-07-07

> Removal of the **random topic buttons** feature and the **custom links** section.
> Randomization was moved out of the extension (standalone PHP pages in
> `tryhackx/flarum-advanced-pages`), so `RandomMovieButtons` and the `/random` endpoint
> are no longer needed; the Links section became redundant. External statistics were
> simplified to **native** mode only (OpenTracker XML) with a configurable cache and
> fetch-time limit. reCAPTCHA was **removed entirely** — protection is now a per-IP points
> rate limiter with temporary IP blocking. **PHP + frontend + cleanup migrations** — update
> with: `composer update` + `php flarum migrate` + `php flarum cache:clear`.
> Breaking change (removed public API endpoint and settings) → major bump.

### Removed
- **Random topic buttons (`RandomMovieButtons`)** — the frontend component and its
  rendering in `HomepageMainBlock`.
- **The `POST /api/tryhackx/homepage/random` endpoint** together with `RandomDiscussionController`.
- **Settings** `random_buttons`, `recaptcha_on_random`, `recaptcha_points_cost_random`
  — admin panel fields, the "Random Movies" section and the related translations (`section_random`,
  `random_buttons`, `random_buttons_help`, `random_not_found`).
- **The `.RandomMovieButtons` styles** (`forum.less`) and the orphaned LESS variables
  `@hb-btn-hover` and `@hb-btn-text-hover`.
- **The custom links section (Custom Links).** Removed the `CustomLinks` (forum) and
  `LinksEditor` (admin) components, the "Custom Links" panel section, the injection of
  `custom_links_css` into `<head>`, the `.CustomLinks` style (`forum.less`) and the settings
  `custom_links`, `custom_links_title`, `custom_links_css` along with all the
  `section_links` / `custom_links_*` translations.
- **The external-stats "proxy" mode.** External statistics now work in native mode ONLY
  (OpenTracker XML). Removed the source selector (`external_stats_mode`), the JSON proxy URL
  (`external_stats_url`), the `fetchExternalStats()` / `normalizeExternalData()` methods,
  the panel fields and the translations.
- **All reCAPTCHA.** Classic reCAPTCHA mode, v2 **and** v3, the version selector, site/secret
  keys, the v3 score threshold, the per-scope `recaptcha_on_stats` / `recaptcha_on_external_stats`
  / `recaptcha_on_search` toggles and the captcha enforcement option — together with the
  `CaptchaModal` component, the Google `siteverify` call (`verifyToken`/`postSiteverify`) and the
  token plumbing (`recaptcha.js`). reCAPTCHA v3 as a points-exhaustion "challenge" is invisible
  (no user action): it silently passes anyone with a decent score and locks out low-score humans
  with no recourse — so it was pointless. Statistics are no longer gated at all (passive + cached).

### Added
- **External-stats cache with a configurable TTL** (`external_stats_cache_ttl`, default 30 s).
  Within the TTL window nobody hits the tracker — everyone is served from the shared cache
  (FileStore per-host / CacheStore cross-node). The fetch runs on the backend (not the
  browser), under single-flight (a non-blocking lock) => at most ONE fetch to the tracker per
  TTL, globally, regardless of how many users are refreshing.
- **Configurable fetch-time limit** (`external_stats_max_time`, default 30 s, max 120). A large
  tracker can take ~a minute to compute stats; the previous hard 5 s cut the fetch short and the
  UI dropped "Loading…". The connect timeout stays short (a dead host fails fast).
- **Negative cache after a failed fetch** — after a failure the backend waits a cooldown (30 s)
  instead of hammering a slow/dead tracker on every poll (protects the worker pool).

### Changed
- **External stats: the frontend keeps the spinner on `external_pending`** instead of dropping
  the loader after a few seconds — it waits for the cache to warm up (patience limit = `max_time`
  + buffer), so under load the stats arrive instead of vanishing. `external_stats_max_time` is
  serialized to the forum (drives the UI patience); the URLs and the cache TTL stay server-side.
- **XML parser hardening** (`LIBXML_NONET`) in case of crafted XML from the source
  (XXE defense-in-depth).
- **The points limiter is now block-only.** When the per-IP budget for `search` runs out, the IP
  is temporarily blocked (no captcha). `RecaptchaGuard` → `RateLimiter`; `js/utils/recaptcha.js` →
  `js/utils/ratelimit.js`; `PointsManager::getEnforcement()` / `refillToStart()` removed. Only the
  `search` action is metered — statistics are passive (cached + single-flight). The admin section
  "Security (reCAPTCHA)" is now "Rate limiting". The "skip signed-in users" setting
  (`recaptcha_skip_authenticated`) was also removed — all visitors are now rate-limited, with guests
  paying an extra per-action cost (`ratelimit_guest_extra`), which already differentiates them.
- **Limiter settings renamed to a clean `ratelimit_*` namespace.** The surviving keys dropped the
  misleading `recaptcha_points_` prefix (`recaptcha_points_enabled` → `ratelimit_enabled`,
  `…_start` → `ratelimit_start`, `…_cost_search` → `ratelimit_cost_search`, etc.). A migration
  carries the values over, so existing configuration is preserved.
- Default Section 1 title and admin labels: "RANDOM & TRACKER STATS" →
  "TRACKER STATS", "(Random & Stats)" → "(Tracker & Stats)".

### Migrations
- **`2026_07_07_000000_remove_random_and_links_settings`** — removes the orphaned keys left by
  the removed features from the `settings` table: `random_buttons`, `recaptcha_on_random`,
  `recaptcha_points_cost_random`, `custom_links`, `custom_links_title`, `custom_links_css`.
  This makes `php flarum migrate` on the target site clean the database too. Rollback is a
  no-op (the removed admin values cannot be restored).
- **`2026_07_07_000001_remove_external_stats_proxy_settings`** — removes `external_stats_mode`
  and `external_stats_url` from the `settings` table (statistics are native-only now).
- **`2026_07_07_000002_remove_recaptcha_settings`** — removes the reCAPTCHA keys from `settings`
  (`recaptcha_enabled`, `recaptcha_version`, `recaptcha_site_key`, `recaptcha_secret_key`,
  `recaptcha_on_stats`, `recaptcha_on_external_stats`, `recaptcha_on_search`, `recaptcha_v3_threshold`,
  `recaptcha_points_enforcement`).
- **`2026_07_07_000003_remove_skip_authenticated_setting`** — removes `recaptcha_skip_authenticated`
  from the `settings` table (all visitors are metered now; the guest extra-cost differentiates them).
- **`2026_07_07_000004_rename_points_settings_to_ratelimit`** — renames the surviving limiter keys
  `recaptcha_points_*` → `ratelimit_*` in the `settings` table, carrying the values over (reversible).

### Notes
- Topic randomization is now handled by standalone PHP pages added in
  `tryhackx/flarum-advanced-pages` (outside this extension).

## [2.3.2] - 2026-06-17

> Floxum audit (round 3) — two small, related resilience hardenings around the points
> limiter and captcha. **PHP + frontend, no migrations** — rebuild assets:
> `composer update` + `php flarum cache:clear`.

### Fixed
- **Limit of ONE automatic captcha retry (frontend).** `RandomMovieButtons` and
  `TrackerStats` retried the request after solving a captcha with no attempt counter — a
  persistent `captcha_required` (wrong key, Google siteverify outage, failed refill) looped
  the modal indefinitely, with no way out other than a page reload. Now at most one retry,
  then the error propagates normally. (floxum H#3)
- **`PointsManager::refillToStart()` now signals a failed lock acquisition.** Previously it
  ignored the `withLock()` result and always returned `start`; when the lock could not be
  acquired the balance was NOT written, yet `RecaptchaGuard::verifyPoints()` still charged the
  (still empty) bucket — so after a correct captcha the user immediately got `captcha_required`
  again. It now returns `null`, and the guard then returns `captcha_required` (honestly, with no
  false success) — consistent with how `charge()` already treats a lock failure. (floxum H#4)

### Notes
- Fixed an outdated `Store::withLock` description (it said "best-effort without a lock" for
  `wait=true`; in fact since 2.2.1 it is fail-closed — it returns the fallback, `$fn` does not run).
- No change (documented): LIKE filters with a leading wildcard — the 3-character threshold (2.3.1)
  cuts the worst scan, while a full FULLTEXT/MATCH is a separate, cross-engine project (deferred);
  `resolve()` in the `field()` callback — core does not inject the container there (accepted
  limitation; the class is already a singleton). (floxum repeats)

## [2.3.1] - 2026-06-17

> Floxum audit (round 2) — removes the Reflection coupling in `FieldLengthModifier` in
> favour of the public schema API, gates LIKE filters with a 3-character threshold and binds
> the modifier as a container singleton. **PHP + frontend, no migrations** — rebuild
> assets: `composer update` + `php flarum cache:clear`.

### Changed
- **`FieldLengthModifier` no longer uses Reflection on core's private `$rules`.**
  It uses the Flarum 2.x public schema API — `getRules()` (read),
  `rules([], …, override: true)` (clear) and `rule()` / `minLength()` /
  `maxLength()` (rebuild) — so overriding the title/content length limits is no longer
  coupled to core internals and won't break silently on a schema refactor
  (fail-safe to core limits + logs once per process). (floxum HIGH: Reflection on `$rules`)
- **The diagnostic settings write on the GET path is gone.** Removing Reflection eliminated
  the need for the `field_length_reflection_failed` flag, so the `settings->set()` write during
  `GET /api/discussions` serialization (plus the admin warning and locale keys) was removed.
  (floxum: settings write during GET)

### Performance
- **The `title` / `user` search filters now require ≥ 3 characters.** Both build
  `LIKE '%value%'` (leading wildcard → full table scan); 1–2-character queries are skipped
  server-side (authoritatively) and are no longer sent by the frontend, which cuts the worst
  scan on every debounced keystroke. (floxum: LIKE scans)

### Conventions
- **`FieldLengthModifier` bound as a singleton in `StoreProvider`** — replaces the
  file-scoped, lazy `resolve()` reference in extend.php; the class is now managed by the
  container (visible/replaceable). The field() callbacks still fetch it (core does not inject
  the container into field()), but as a cheap singleton lookup. (floxum: resolve()/file-scope
  singleton)

## [2.3.0] - 2026-06-17

> Floxum audit follow-up (orange 80/100). Moves the two state-mutating endpoints off
> GET, shortens the siteverify timeout, hardens the single-flight lock, and documents
> the search guard as a soft client-side gate. **Frontend + route change, no
> migrations** — rebuild assets:
> `composer update` + `php flarum assets:publish` + `php flarum cache:clear`.

### Changed
- **`/random` and `/points/check` are now POST (were GET).** Both mutate the per-IP
  points bucket, so GET broke RFC 9110's safe/idempotent contract — link prefetchers,
  CDN probes and cached GET replays could silently drain a user's budget. `/stats`
  stays GET (pure read). The frontend sends these as POST with the `X-CSRF-Token`
  header (`app.request` adds it automatically; the raw-fetch pre-flight adds it
  explicitly). **Breaking for any external caller of the old GET endpoints.** (floxum)

### Security
- **siteverify timeout cut from 8 s to 5 s.** In classic reCAPTCHA mode `verifyToken`
  runs on the hot path of every protected request; the shorter total (just above the
  4 s connect timeout) reduces how long an FPM worker is held during a Google
  slowdown/outage. (floxum)
- **`CacheStore::withLock($wait=false)` no longer confuses an unacquired lock with a
  callback that returns `false`.** A sentinel wrapper preserves a genuine `false`
  result and returns the fallback only on a real lock miss. No behavioural change today
  (callbacks return arrays/null) — forward-proofing. (floxum)

### Documentation
- **The search rate-limit is now explicitly documented as a soft, client-side gate.**
  The pre-flight (`CheckPointsController` / `AdvancedFilters`) deters casual abuse, but
  the actual query hits core `/api/discussions`, which is not rate-limited server-side.
  A hard guard would need a scoped middleware with a single points-charge point;
  deferred until traffic warrants it. (floxum)

### Notes
- **Deliberately unchanged** (documented): `resolve()` in extend.php (memoised once per
  request); raw cURL vs Laravel HTTP client (explicit timeouts + fail-closed fallback);
  the `recordReflectionState` condition (correct as-is — the stored "failed" flag is the
  semantic inverse of `$ok`, so equal values mean "out of sync, write"). (floxum)

## [2.2.2] - 2026-06-17

> Minor cleanup from the audit follow-up (already green, non-blocking). **PHP only**
> — no migrations, no frontend change: `composer update` + `php flarum cache:clear`.

### Changed
- **Removed a dead `if ($locked)` guard in `FileStore::withLock()`.** After the
  fail-closed early return on lock-acquire failure, `$locked` is always true at the
  `finally`, so the guard was unreachable — the unlock is now unconditional. No
  behavioural change.
- **Documented the deliberate `/` path separator in `FileStore`.** Flarum core builds
  storage paths with `/` (e.g. `InstalledSite`: `$this->paths->storage.'/cache'`) and
  PHP accepts `/` on every platform, so we keep `/` for consistency with core rather
  than `DIRECTORY_SEPARATOR` (which would mismatch core's own style).

### Notes
- Unchanged (documented): the `HasValidationRules::$rules` Reflection (no public core
  API to relax min/max — mitigated by the admin warning flag); `resolve()` in
  extend.php (memoised, once-per-request); plain JS / no TypeScript.
- Verified on `http://flarum.localhost/`: PHP lint clean; interval filters and the
  points limiter (2→1→0 → 429) still work on the normal locked path.

## [2.2.1] - 2026-06-16

> Follow-up hardening from the next audit round (green check, non-blocking). **PHP
> only** — no migrations, no frontend change: `composer update` + `php flarum cache:clear`.

### Security
- **The rate limiter now fails *closed* when its lock cannot be acquired.**
  `FileStore` / `CacheStore::withLock()` previously ran the callback *unlocked* if the
  `.lock` file couldn't be opened (or the cache lock timed out) — on the `wait=true`
  limiter path two concurrent workers could then read the same bucket balance and each
  deduct, weakening the per-IP limit. The limiter path now returns a safe fallback
  (deny: `charge` → `ok=false`) and logs once per process instead of running unlocked.
  The single-flight stats path (`wait=false`) keeps best-effort behaviour (worst case:
  one duplicate compute, no security impact). Normal operation is unchanged — this only
  triggers on broken storage permissions / lock-provider failure. (audit H2)

### Changed
- **`DateIntervalFilter` and `RatingFilter` now share `AbstractDateIntervalFilter`**
  (abstract `getFilterKey()` + `getColumn()`). The two were byte-identical apart from
  the key and the column (`created_at` vs `last_rated_at`); the interval logic lives in
  one place now. No behavioural change. (audit H4)

### Notes
- **Deliberately not changed:** the `HasValidationRules::$rules` Reflection
  (`FieldLengthModifier`) — core exposes no public API to *relax* a min/max length
  rule, so it stays, already mitigated by the admin warning flag (a core PR is the only
  real fix, audit H1); `resolve()` in extend.php (already memoised — `??=` makes it
  once-per-request, audit H5); raw `fetch()` in `preflightCheck` (intentional silent
  403/429 handling, already commented, audit H7); plain JS / no TypeScript (whole
  suite, audit H6); the random-discussion sampler (the rare sparse-tag worst case uses
  indexed PK lookups — cutting attempts would make the OFFSET fallback fire *more*
  often, audit H3).
- Verified on `http://flarum.localhost/`: PHP lint clean; both interval filters (incl.
  negated) → 200; the points limiter still charges down and blocks (2→1→0 → 429) on the
  normal locked path.

## [2.2.0] - 2026-06-15

Large-forum hardening pass (third-party review). Two themes: **bounded blocking**
on the stats path, and a **swappable, cross-node-capable storage layer** for the
per-IP rate limiter and the stats caches. **No database migrations.** PHP +
frontend (admin asset rebuilt) — `composer update` + `php flarum cache:clear`.

### Added
- **Swappable storage layer (`Store`) for the rate limiter and stats caches, with
  automatic driver selection.** New `TryHackX\HomepageBlocks\Cache\Store` with two
  implementations, picked at runtime from Flarum's configured cache (no new admin
  setting — see `Provider\StoreProvider`):
  - **`FileStore`** — the default. The previous native files + `flock` + atomic
    `rename` logic, unchanged in behaviour. Correct and fast on a single server
    (including a large one) and depends only on PHP — **single-node installs behave
    exactly as before.**
  - **`CacheStore`** — used **only** when Flarum's `cache.store` is a shared,
    lockable driver (Redis/Memcached — e.g. once a Redis extension repoints the
    binding). Gives **cross-node** consistency via `Cache::lock()`. All contact with
    Flarum's cache API is isolated to this one class, so the forward-compat surface
    is one swappable file and the file path is always the fallback. (audit #2/#3)
- **Admin warning when title/content length enforcement silently stops working.**
  `FieldLengthModifier` now records a settings flag
  (`field_length_reflection_failed`) when its reflection-based rule override fails
  (e.g. a Flarum core change) and the extension settings page shows a red notice, so
  the operator sees it instead of the limits going off with only a server-log line.
  The flag is written at most once per process and only when the state flips.
  (audit #1)

### Security
- **The per-IP points rate limiter is now correct across multiple app servers**
  when a shared cache is configured. Previously each PHP-FPM node kept its own
  file-based buckets, so on a load-balanced cluster an IP could obtain roughly N×
  the budget (one bucket per node) and the block/captcha gate could be split across
  nodes. With `CacheStore` the bucket and its lock live in the shared store, so
  charging/blocking is atomic cluster-wide. Single-node installs are unaffected
  (still file-backed). (audit #3)

### Performance
- **External-stats fetch can no longer hold a worker for up to 12 s.** `fetchRaw()`
  cURL timeouts cut from 12 s / 6 s to **5 s / 2 s** (total / connect), stream
  fallback matched. A single slow or unreachable OpenTracker endpoint now ties up
  the one single-flight worker for ~5 s at most instead of 12 s — meaningful on a
  busy FPM pool. (audit #4)
- **reCAPTCHA `siteverify` timeout** trimmed 10 s / 5 s → **8 s / 4 s**; in classic
  mode this is on the hot path of every protected request. (audit #4)
- **Stats single-flight is global on a shared cache.** Internal and external stat
  refreshes serialise through the `Store` lock, so on a Redis-backed cluster the
  DB / source is hit at most once per interval **across all nodes**, not once per
  node. (audit #3)
- **`FieldLengthModifier` is resolved once (memoised)** instead of via `resolve()`
  on every serialised `title` / `content` field. (audit #6)

### Changed
- `PointsManager` and `TrackerStatsController` now delegate **all** persistence to
  `Store`. The controller's file-path / `readCacheFile` / `atomicWrite` / `flock`
  single-flight plumbing was removed (the mechanics live in `Store`), slimming the
  controller and separating caching from stats logic. (audit #5)

### Internal
- `preflightCheck()` keeps its raw `fetch()` (not `app.request()`) **by design** —
  it handles 403 / 429 as normal control flow and must not trigger Flarum's default
  error alert; now documented in a comment. (audit #7)

### Notes
- This **supersedes the 2.1.10 / 2.1.11 "stays file-based on purpose" note.** File
  storage is still the default and is byte-for-byte the same on a single server;
  what is new is that a true multi-node deployment now gets a shared atomic store
  **automatically** when Flarum's cache is Redis/Memcached-backed — no blind
  retrofit, no new setting, and the file path remains the always-available fallback.
- Verified on `http://flarum.localhost/` (Flarum 2.0.0-rc.3, file cache →
  `FileStore`): PHP lint clean; internal stats endpoint returns data and caches; the
  points limiter charges down and blocks (2 → 1 → 0 → HTTP 429 `rate_limited`,
  `retry_after` counting down) through the new `Store`; discussion list + admin
  settings page render with no console errors; the reflection-failure notice
  shows/hides with the flag.

## [2.1.12] - 2026-06-14

Coordinated robustness fix with `tryhackx/flarum-topic-rating` 2.4.11. **No database
migrations, no frontend changes** (PHP only — `composer update` +
`php flarum cache:clear`).

### Fixed
- **The "Steam DB" rating sort is now table-prefix safe.** `SteamRatingSort` builds
  a raw `ORDER BY` expression over `discussions.rating_average` / `rating_count`; the
  query builder prefixes column/`orderBy` references itself, but **not** raw SQL, so
  on an install configured with a DB table prefix the expression referenced a
  non-existent `discussions` table. `SteamRatingSort::expression()` now takes the
  (already-prefixed) table name, and both call sites (`apply()` and
  `SteamRatingSortMutator`) pass `getTablePrefix() . 'discussions'`. No effect on the
  default empty-prefix install — verified the sort order is unchanged; fixes prefixed
  installs. Mirrors the same fix in topic-rating's `RatingRecalculator` (2.4.11).

## [2.1.11] - 2026-06-14

Scalability + privacy patch from a third-party review pass. **No database
migrations, no frontend changes** (PHP only — `composer update` +
`php flarum cache:clear`).

### Performance
- **Random-discussion picking no longer does an `OFFSET` scan.**
  `RandomDiscussionController` previously ran
  `orderBy('id')->offset(random(0, count))->first()`, which makes MySQL scan and
  discard up to `offset` filtered rows on every click — O(n) on large tags. It now
  uses **rejection sampling over the primary key**: draw a random id in
  `[MIN(id), MAX(id)]` of the (visibility- and tag-filtered) set and accept only an
  exact match — O(log n) per attempt, ~1 attempt for a dense tag — with a uniform
  `OFFSET` fallback for very sparse tags or unlucky draws. The distribution is
  **provably uniform**, and deleted / hidden / out-of-tag discussions are never
  returned (they simply don't match). Verified: 120 draws over a 5-discussion tag
  came out 26/25/24/23/22, and the id-gaps never appeared. (audit #1)

### Security
- **The reCAPTCHA token is no longer read from the query string.**
  `RecaptchaGuard::extractToken()` now accepts the token only via the
  `X-Recaptcha-Token` header (primary since 2.1.3) and the POST body. The
  `?recaptcha_token=` query read — dead since the frontend went header-only in
  2.1.3, and a path by which a one-time token could land in server/proxy access
  logs — has been removed. (audit #5)

### Notes — re-examined under a large-forum lens
- **Multi-node rate-limit / stats storage** stays file-based on purpose: it is
  correct and fast on a single server (including a large one — millions of users
  on one host), and `flock` + atomic `rename` require a local filesystem. True
  multi-node (load-balanced across hosts with no shared FS) needs a shared atomic
  store (Redis, or a DB-backed bucket) — a deliberate, separately-tested change
  that also requires that infrastructure, not a blind retrofit of a
  security-critical limiter. Documented as a single-node design in the README.
- `resolve()` in field callbacks, controller / admin file sizes, JSX vs `m()`,
  TypeScript, and `preflightCheck`'s `fetch` are code-style / organisation items,
  not throughput bottlenecks at any scale; left as-is.

## [2.1.10] - 2026-06-14

Scalability patch from a third-party review pass. **No database migrations, no
frontend changes** (PHP only — `composer update` + `php flarum cache:clear`).

### Performance
- **The per-IP bucket garbage collector no longer uses `glob()`.**
  `maybeCollectGarbage()` previously enumerated the whole `tryhackx_points/`
  directory with `glob('*.json')` (plus a second glob for `.json.lock`),
  materialising the entire file list in memory before deleting up to 500 stale
  entries. On a forum holding hundreds of thousands of per-IP bucket files (heavy
  bot / many-distinct-IP traffic) that single allocation + full scan was a
  blocking I/O spike. It now streams entries via `opendir`/`readdir` (O(1) memory)
  and caps each run at **2000 entries scanned + 500 deleted**, so the GC cost is
  bounded regardless of directory size. Stale files outside a given run's window
  are reclaimed by later runs (GC fires ~2 % of writes, and the `sha1`-named files
  are uniformly distributed, so each pass removes stale entries proportionally).
  (audit: `glob()` in GC)

### Notes
- This is a large-scale concern only; small / single-node forums behave the same
  either way (the directory holds at most a handful of files there).

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
