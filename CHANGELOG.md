# Changelog

## 2026-09-27

### Metrics sync (backend first, no UI yet)
- New `post_metrics` table: ULID, `post_id` (cascade), `post_target_id` (cascade), `platform`, `snapshot_type` (`'snapshot'` default / `'error'`), `snapshot_date`, JSON `data`, unique `(post_target_id, snapshot_date)`. Metrics live in JSON keys (likes/comments/shares/saves/impressions/reach/engagements/views, honest nulls where N/A).
- **Fixed the unique key before release:** the initial `(post_id, platform, snapshot_date)` key collapsed two same-platform targets on one post into a single row (second account silently skipped or collided under concurrency). Migration `2026_09_27_110222` re-keys snapshots to the concrete `post_target_id` (backfilling/dropping only ambiguous pre-release rows).
- Implemented `FetchFacebook/Instagram/LinkedIn/TikTokMetricsAction` — each returns the same normalised JSON shape.
- Rewrote `SyncPostMetricsJob` as per-target with guards (must be Published with `platform_post_id`, no today snapshot, latest snapshot not an error) — all keyed off `post_target_id`; failures write an error snapshot that permanently stops polling. Removed the old flat self-requeue.
- New `php artisan metrics:sync-due` command (`SyncDuePostMetrics`) — D0/D1/D3/D7 day-grain decay from `published_at`; chunked dispatch of due targets.
- Registered the command hourly with `withoutOverlapping()` via `withSchedule` in `bootstrap/app.php` (`hourly()`, since `everyHour()` does not exist).
- Extended `PostMetricsTest` (11 tests) covering platform shapes, skip-today, error-snapshot-stops-retry, missing platform id, same-post-two-targets-per-row dedupe, and command dispatch.

### Post status invariant — observer-driven roll-up (Track B)
- `recalculateStatus()` guarded against empty target sets and pre-dispatch states (`Draft`/`Scheduled`/`Canceled`); precedence `Published` > `Failed` > `Publishing` documented in PHPDoc.
- New `PostTargetObserver` (`saved`/`deleted`) registered via `#[ObservedBy]` on `PostTarget` — replaces scattered recalc calls in the jobs.
- `PublishPostTargetJob` is now the single place a post leaves `Scheduled` → `Publishing` (explicit force-flip at job fire); reschedule version + idempotence guards kept.
- `CheckTikTokPublishStatusJob` no longer recalc-calls; poll/requeue/fail logic unchanged.

### Social accounts — Expired vs Revoked
- `SocialAccount::effectiveStatus()` derives `Expired` when connected but `token_expires_at` is in the past; explicit revoked/connected states win.
- `SocialAccountController::index` surfaces the derived status; demo seeder gained a `tiktok_expired` account (status `Expired`, token −30 days).
- 3 new `SocialAccountsTest` cases.

### Media fix
- `MediaController` now serialises `uploaded_by.fullname` (was the non-existent `name`); `MediaTest` asserts the real value instead of a vacuous `null === null`.

### Misc
- Test env defaulted to MySQL: `phpunit.xml` now points at `lareact_test` (host/port/user/password set) instead of `sqlite :memory:`, so bare `php artisan test --compact` runs without the previous env-var workaround (the `pdo_sqlite` extension isn't installed anyway; MySQL is also the real platform).
- Calendar header rebuilt on the shared custom `Select` listbox.
- `PROGRESS.md` added with a feature-by-feature walkthrough (backend → command → scheduling → queue → UI) and known gaps (metrics UI, demo backfill, retry feature, TikTok batching).
- Test suite: **126 tests / 814 assertions, all green**; `vendor/bin/pint --dirty` clean.