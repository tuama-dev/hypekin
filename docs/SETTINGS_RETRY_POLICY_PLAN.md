# Build Plan: Settings & Retry Policy

Locked plan for the application-settings foundation and the retry policy that consumes it.
Status/decisions in this file are authoritative until the work ships; refresh
`docs/CORE_APP_DB_DESIGN.md` and `docs/PROJECT_STATUS.md` when done.

**Status: shipped (2026-09-30).** O1–O3 were resolved with the recommended option in each case; see
"Open items" below. The implementation notes at the end record what actually landed, including the two
places the shipped code deliberately differs from this plan.

---

## Decisions locked in this session

- **Settings are business policy, not infra config.** Tunable knobs live in a new
  `settings` table so a future settings page can change them at runtime without a deploy.
  Infrastructure values (endpoints, HTTP timeouts, pagination, auth throttle, storage TTL)
  stay in code/`config`.
- **In-scope settings (Tier 1):**
  - `retry.max_retries = 3` — user retries per post (cap).
  - `retry.cooldown_seconds = 300` — wait between user retries.
  - `publish.tiktok_max_polls = 10` — TikTok status poll budget (`CheckTikTokPublishStatusJob::MAX_POLLS`).
  - `publish.tiktok_poll_delay_seconds = 60` — TikTok poll interval (`POLL_DELAY_SECONDS`).
  - `verification.resend_cooldown = 60` — migrate from `config/verification.php`.
- **Stays hardcoded (platform rules / infra):** TikTok `title` ≤ 22
  (`PublishToTikTokAction::MAX_TITLE_LENGTH`), platform `API_BASE` URLs, 10s/30s HTTP timeouts,
  `media.presign_ttl`, `auth.throttle`, pagination/feed limits, calendar `WINDOW_MONTHS`.
- **Retry policy is derivable and audit-friendly.** A `post_retry_attempts` audit table is the
  single source for the cap (count per `post_id`), the cooldown (latest `attempted_at`), and the
  "last retried" indicator. No counter columns added to `posts`.
- **`post_targets.retry_count` is TikTok-poll-only.** User retries must stop incrementing it
  (`PostController::retry` currently does) and be recorded in `post_retry_attempts` instead.
  The two semantics would otherwise fight (a polled-out TikTok leg is already at 10).
- **Unknowns (open):** settings table is global for now — no per-workspace scope until the
  settings page proves it needs it.

---

## Phase 1: Settings infrastructure

### 1. Migration `create_settings_table`

- `id` ULID primary · `key` varchar unique · `value` json nullable · timestamps.

### 2. `App\Models\Setting`

- ULID pattern (`HasUlids`), `#[Fillable('key','value')]`, cast `value` → `array`/primitive.

### 3. `App\Settings\Settings` accessor

> **Revised after review (2026-10-01).** Originally specified as a static facade over
> `Cache::remember('settings', 3600, ...)`. Shipped instead as an **instance class bound with
> `$this->app->scoped()`**, injected or resolved per call site.
>
> Reason: the policy is read from inside publish jobs, and a cache shared across processes let a
> worker keep publishing under a policy an operator had already changed — and a write that bypassed
> model events (`Setting::query()->update()`) was invisible for up to an hour everywhere. Scoping the
> memo to a request or job removes cross-process sharing and bounds staleness to one lifecycle, while
> still costing a single query per lifecycle regardless of how many keys are read. A raw bulk update
> now goes stale only for the rest of the lifecycle it happens in.

- Typed getters: `int(string $key, int $default)`, plus `string()`/`bool()` for the
  future settings page, and a typed write `set()`.
- Memoized map, read once per lifecycle (`Setting::pluck('value','key')`); no cache store involved.
- Invalidation: `Setting::booted(saved|deleted)` → `app(Settings::class)->forget()`, so a write is
  visible to the rest of the same request.
- Default at the call site: `$settings->int('retry.max_retries', 3)`.

### 4. `SettingsSeeder` (called from `DatabaseSeeder`)

- Seeds the four publish/retry keys above. Verification per open item O3.

### 5. Tests

- `SettingTest` — getter with/without stored value (fallback), int/string/bool typing, cache
  populated on first read, cache cleared on write, default key absent.

---

## Phase 2: Retry audit + rate limit

### 1. Migration `create_post_retry_attempts_table`

- `id` ULID primary · `post_id` FK → posts (cascade, **indexed**) · `attempted_by_user_id` FK → users
  (cascade) · `attempted_legs` unsigned int · `attempted_at` timestamp · timestamps.

### 2. Model + factory

- `PostRetryAttempt` (ULID, fillable, cast `attempted_at` → datetime); relations `post()` and
  `attemptedBy()`; `Post::retryAttempts()` hasMany.
- `PostRetryAttemptFactory` (state helpers for demo/tests).

### 3. `PostController::retry` — guards, all policy from the audit table + settings

1. Eligible legs = `status Failed` && `platform_post_id null` && `platform_upload_id null` →
   none → error flash (unchanged).
2. `retryAttempts()->count() >= Settings::int('retry.max_retries', 3)` → "No retries left." error.
3. Latest `attempted_at` + cooldown still in the future → error with remaining wait.
4. Else → reset legs + dispatch (unchanged), insert attempt row (`post_id`, acting user,
   `attempted_legs`, `attempted_at = now`). **Stop incrementing `post_targets.retry_count`.**

### 4. `CheckTikTokPublishStatusJob`

- Read `Settings::int('publish.tiktok_max_polls', 10)` and `...poll_delay_seconds', 60)` instead
  of the constants (keep constants as the settings defaults / delete private `MAX_POLLS`).

### 5. API + frontend

- `PostController::show` serializes `retry` meta `{ eligible_legs, retries_left, exhausted,
  last_retried_at, retry_available_at }` (policy stays server-side). The cooldown ships as an absolute
  `retry_available_at` rather than the originally-planned `wait_seconds` count, so the browser derives
  the unlock time from a server-stamped instant instead of its own clock — the same reasoning behind the
  existing `verification.resend_available_at` shared prop.
- `Show.tsx`: four button states — hidden (no failed legs) / "Retries exhausted" (disabled) /
  disabled + ticking `m:ss` countdown (modeled on `VerifyEmail.tsx`, re-enables at 0) / enabled
  "Retry failed (N)". Plus "Last retried {time}" once set.

### 6. Demo seeder

- `DemoContentSeeder`: 1–2 `PostRetryAttempt` rows on the failing demo post so the
  countdown/exhausted affordance is visible; the demo post keeps failing on retry.

### 7. Tests (`PostRetryTest`)

- First attempt allowed; attempt row written (post_id, user, `attempted_legs`, `attempted_at`);
  cap blocks + no dispatch; cooldown blocks then frees after `Carbon::setTestNow` travel;
  per-post independence (counts scoped to `post_id`); target `retry_count` no longer
  incremented by user retries.

---

## Verification

- `vendor/bin/pint --dirty --format agent`
- `php artisan test --compact`
- `npm run types:check` and `npm run build`
- Browser smoke: retry flow on a failing post — enabled → countdown → exhausted states visible.

---

## Open items (all resolved 2026-09-30, recommended option taken in each case)

- **O1 Defaults source:** **defaults-at-call-site** (`Settings::int($key, $default)`, no config file).
  `config/verification.php` was deleted rather than kept as a second source — the cooldown now has one
  home.
- **O2 `publish.tiktok_poll_delay_seconds`:** **included now.** The interval is read in two places, not
  one: `CheckTikTokPublishStatusJob` for the re-poll, and `PublishPostTargetJob` for the delay on the
  first poll. The second site is a necessary consequence of deleting the constant, not extra scope.
- **O3 `verification.resend_cooldown`:** **migrated to `Settings` now**, and the seeder writes it.

---

## Deferred (explicitly out of scope)

- Settings page UI (plan later; cache invalidation already primed the DB writes).
- Per-workspace settings overrides (add nullable `workspace_id` only if the page needs it).
- Tier 2 cadence knobs (`metrics.sync_frequency`, platform HTTP timeouts) and all Tier 3 values.
- Google/Apple connect required flags, TikTok title length, queue retry budgets.

---

## What actually shipped

- `settings` table + `App\Models\Setting` (JSON decode cast, memo dropped on `saved`/`deleted`).
- `App\Settings\Settings` — `int()`/`string()`/`bool()`/`set()`, a container-scoped instance whose map
  is read once per lifecycle, defaults at the call site. `all()` is private; the four public getters
  are the whole surface.
- `SettingsSeeder` (5 keys) wired into `DatabaseSeeder` before `DemoContentSeeder`. It uses
  `firstOrCreate`, so re-seeding never stomps a tuned value.
- `post_retry_attempts` table + `PostRetryAttempt` model/factory + `Post::retryAttempts()`.
- The policy lives in `App\Actions\Application\Post\ResolvePostRetryPolicy` (returns a
  `PostRetryPolicy` value object) rather than inline in the controller, because `show` renders the same
  rules the endpoint enforces — two copies of the cap/cooldown math would drift. `eligibleTargets()` and
  `resolve()` live together so "what is retryable" has one definition.
- `PostController::retry` no longer touches `post_targets.retry_count`; the attempt row is written in the
  same transaction as the leg reset.
- `show` serializes `post.retry` = `{ eligible_legs, retries_left, exhausted, last_retried_at,
  retry_available_at }`; the component only renders the state it describes.
- `Show.tsx` has the four states (hidden / exhausted / ticking `m:ss` / enabled) plus "Last retried", and
  disables the button while the request is in flight so a double click cannot burn a retry.
- The cooldown is serialised as an **absolute** `retry_available_at`, not a remaining-seconds count, so
  the countdown does not inherit a disagreement between the browser clock and the app's. This matches the
  existing `verification.resend_available_at` convention.
- `PostController::retry` holds a per-post cache lock across the whole check-and-dispatch, and the route
  carries a `post-retry` rate limiter keyed per user *and* post. The cap is a read of the audit log
  followed by an append, so without the lock two simultaneous requests would both consume a retry and
  both re-dispatch the same targets.
- `CheckTikTokPublishStatusJob` lost `MAX_POLLS` and `POLL_DELAY_SECONDS`; `PublishPostTargetJob` lost
  its reference to the delay constant in the same pass.
- Demo seeder gives the first retryable demo failure two attempts (900s and 240s ago) so the post page
  opens in the countdown state, not the exhausted one, and re-seeds replace the history.
- `config/verification.php` deleted (O1/O3).
- `linkedAccount()` moved to `tests/Pest.php`. It was defined in `PostTest.php` but used by
  `PostRetryTest.php`, which made that file unrunnable on its own — a helper in a test file is only in
  scope when that file is loaded.
- The retry `post.retry` policy costs **one** query on the post page: the eligible legs are counted from
  the already-eager-loaded `targets` relation, and the attempt count and newest attempt come from a
  single aggregate. The aggregate reads `max(attempted_at)` rather than comparing against a SQL clock on
  purpose — this database session runs on a server timezone offset from the app's, so a raw `NOW()` would
  be hours away from the timestamps Laravel wrote.
- Verified: Pint, `php artisan test` 183/183 (1240 assertions), `tsc --noEmit`, `npm run build`,
  `db:seed` clean. PHPStan across the project is down to 231 errors from 259. The four button states
  were **not** verified in a browser — the project has no browser/screenshot tooling and Inertia renders
  client-side, so that visual pass is still outstanding.
- **Open, not fixed:** `PublishPostTargetJob` persists `platform_upload_id` only after the platform call
  returns, so a worker death in that window can make a submitted leg look retryable. See the "Known gap"
  section in `CORE_APP_DB_DESIGN.md` for the two candidate fixes.
