# Lareact — Development Progress

Detailed, feature-by-feature progress of the application: **backend → commands → scheduling → queue → UI**. Suite state: **126 tests / 813 assertions, all green** (`php artisan test --compact`).

## Stack

- **Laravel 13.32** on **PHP 8.5**. Inertia **v3** + **React 19**, Tailwind **4**, Vite **8** (via `vite`). Wayfinder generates typed route/action functions.
- **Queue driver:** `database` (`QUEUE_CONNECTION=database`). Publishing pipelines dispatch jobs with queue `delay()`.
- **Scheduler:** only introduced in this project now — see the metrics section. Everything else is job-delay driven.
- UI convention: full client-side SPA via `Inertia::render()` pages in `resources/js/pages`, routes registered for `/app/{workspace}` namespaced under `workspace.*`.

---

## 1. Backend — Domain & Models

### Enums (`app/Enums`)

- `Platform`: `linkedin`, `facebook`, `instagram`, `tiktok` — with `label()`, `publishable()`, `socialiteDriver()` (e.g. FB uses `facebook-posting`), and `scopes()` for each platform's OAuth.
- `PostStatus`: `Scheduled`, `Publishing`, `Published`, `Failed`, `Draft`, `Canceled`.
- `PostTargetStatus`: `pending`, `queued`, `published`, `failed`.
- `SocialAccountStatus`: `connected`, `revoked`, `expired`.
- `MediaStatus` / `MediaType` (image | video), `WorkspaceRole` (`owner`/`admin`/`editor`/`viewer`).
- All enums expose `value` + `label`; the API serializes them as `{ value, label }` for the frontend.

### `User` (app/Models/User.php)

- ULID id, `fullname` (plural — the seeded `UserFactory` fills `fullname`, there is **no** `name` column), email + `email_verified_at`, hashed password.
- Has `oauthProviders()`, `workspaces()` (belongsToMany via `workspace_user` with pivot `role`).
- `#[Fillable(...)]` / `#[Hidden(['password','remember_token'])]` attributes (Laravel 13 attribute style).

### `Workspace` (app/Models/Workspace.php)

- ULID, `name`, `slug`; route keyed by slug. `Workspace::uniqueSlug()` dedupes `-2`, `-3`, …
- Relations: `users()` (with role pivot, plus `owner()`/`admins()`/`editors()`/`viewers()` scopes), `socialAccounts()`, `posts()`, `media()`.

### `SocialAccount` (app/Models/SocialAccount.php)

- Per-workspace connected platform account: `external_account_id`, `display_name`, `avatar_url`, `access_token` / `refresh_token`, `token_expires_at`, `status`, `connected_by_user_id`, `connected_at`.
- **`effectiveStatus()`** (added this session): if the stored status is `connected` and `token_expires_at` is in the past → reports `Expired`; all other explicit stored states win. The accounts page renders this derived status so an expired token is surfaced without a background process.
- `destroy` doesn't hard-delete — sets `Revoked` and clears tokens.

### `Post` & `PostTarget`

- `Post`: workspace post with `caption`, `status`, `scheduled_at`, `schedule_version`, `created_by_user_id`. Relations: `targets` → `PostTarget`, `media` (belongsToMany `post_media` with `position` pivot), `createdBy`.
- `PostTarget`: one platform leg of a post — `social_account_id`, `caption`, `title`, `status`, `platform_post_id`, `platform_upload_id` (TikTok), `published_at`, `error_message`, `retry_count`.
- **Post status invariant — `recalculateStatus()`** (`app/Models/Post.php`), the canonical rule for rolling target states up to the post:
    - **Pre-dispatch guard:** a post in `Draft`, `Scheduled`, or `Canceled` never derives a new status (those states are shortcuts, not computed states), and an empty target set never derives either.
    - Precedence: all targets `Published` → `Published`; all targets terminal (`Published` **or** `Failed`) with ≥1 `Failed` → `Failed`; otherwise → `Publishing` (in-flight).
    - Boundary behaviour documented in the PHPDoc: e.g. a `failed` + `published` pair yields `Failed`; `pending`/`queued` mixes stay `Publishing`.
- **`PostTargetObserver`** (new, `app/Observers/PostTargetObserver.php`, registered via `#[ObservedBy(PostTargetObserver::class)]` on the model): on target `saved` **and** `deleted` it calls `$postTarget->post?->recalculateStatus()`. This replaced explicit recalc calls scattered through the jobs and makes the status invariant observer-driven for every transition.
    - Caveat relied on by tests/seeders: `$workspace->posts()->delete()` and `forceCreate` bypass Eloquent events, so the seeder sets statuses directly.

See section 5 for the queue pipeline that mutates these states.

### `Media` (app/Models/Media.php)

- Library asset: `disk`, `path`, `type`, `mime_type`, `size_bytes`, `width`/`height`/`duration_seconds`, `status`, `uploaded_by_user_id` → `uploadedBy()` (User). Soft deletes. `publicUrl()` resolves the storage URL; `posts()` relation is the composer pivot.

---

## 2. Backend — Auth & Onboarding

- Email/password auth (`AuthController`): `Login` page; on successful login `CreateWorkspaceAction::ensure($user)` guarantees a personal workspace then redirects to its dashboard. `Logout` invalidates session and returns to `home` via `Inertia::location`.
- Registration + email verification (`RegistrationController`/`EmailVerificationController`) with a `resend_available_at` guard shared in `HandleInertiaRequests` so the UI knows when the resend button unlocks.
- Social login/connect via **Laravel Socialite** (see next section).
- `HandleInertiaRequests` shares: `name`, full `auth` block (user, current workspace with role, workspace list, latest 8 notifications + unread count), `flash.error`/`flash.success`, verification state.
- Route model binding: `/{workspace}` binds by slug; middleware scopes each request to the workspace.

---

## 3. Backend — Social Accounts (OAuth connect + statuses)

`SocialAccountController`:

- `index` lists up to 50 accounts with `effectiveStatus()` values (integrated this session), plus which platforms have credentials configured (from `config/services.<driver>.client_id`) and the connect URLs (`canConnect`).
- `connect`: verifies the platform is configured (else flash error), stashes the target workspace id in session, and `Socialite::driver(...)->scopes(...)->redirect()`.
- `callback`: pulls the workspace id back, enforces the user belongs to it, catches Socialite failures, then dispatches to platform-specific actions:
    - **Facebook** → `ConnectFacebookPagesAction` (page chooser, `pages_show_list`…).
    - **Instagram** → `ConnectInstagramAccountsAction` (via the connected FB pages).
    - **LinkedIn / TikTok** → `ConnectSocialAccountAction` (single account).
- `destroy`: revoke + token wipe (see `SocialAccount` above).

**Statuses & expiry (added this session):** `SocialAccountStatus` = connected/revoked/expired. The `effectiveStatus()` derivation surfaces expired tokens on the UI; the demo seeder gained an `@acme.studio (old account)` TikTok with a `Expired` status and a `token_expires_at` 30 days in the past to prove the three states (connected, revoked, expired) render distinctly.

---

## 4. Backend — Post Composer

`PostController` + `StorePostAction` (`app/Actions/Application/Post`):

- Validates caption, media (existing media ids), targets (account per platform, arc, title, caption), and schedule (immediate vs `scheduled_at`).
- Creates the `Post` (+ `schedule_version`), its `PostTarget` rows, and the `post_media` pivot with positions. Targets are stamped with platform-appropriate default states (`Scheduled`, `Draft`, or `Publishing` immediately depending on requested send).
- **Publish pipeline dispatch:** the store action creates the post, marks targets, and dispatches `PublishPostTargetJob` per target with a delay:
    - immediate → now; scheduled → `now + (scheduled_at - now)`.
- The store action and duplicates/edge handling (e.g. "send now" vs "schedule") are covered by `PostTest`, including the on-store recalc behaviour.

---

## 5. Queue Pipeline — Publishing & Status Flows

Queue driver is `database`; all jobs are `ShouldQueue`. The full lifecycle:

1. **`PublishPostTargetJob`** (per target):
    - **Version guard:** if the job carries a `scheduleVersion` and the post's `schedule_version` has since changed (the post was rescheduled), the stale job exits — replacements were dispatched with the new delay. Prevents double-publishing.
    - **Idempotence guard:** exits if `platform_post_id` already set, target already `Published`, or it already holds a `platform_upload_id` (TikTok submit done).
    - **Status entry (added this session):** a post leaves `Scheduled` only here — when its first delayed job actually fires the post is force-flipped to `Publishing` before the target is marked `Queued`. `recalculateStatus()` never derives past `Scheduled`, so this is the single, explicit entry point.
    - Marks target `Queued`, then:
        - **TikTok** → `PublishToTikTokAction::initialize()` returns a `platform_upload_id`; job stores it and re-dispatches **`CheckTikTokPublishStatusJob`** delayed `POLL_DELAY_SECONDS` (60s).
        - **FB / IG / LinkedIn** → platform action returns `platform_post_id`; target saved `Published` + `published_at`.
    - On any exception → target `Failed` + truncated `error_message` (500 chars) + `PostTargetFailedNotification` to the post creator.
2. **`CheckTikTokPublishStatusJob`** (async poll): guards already-`Published`/has-id; POSTs to `https://open.tiktokapis.com/v2/post/publish/status/fetch/` with `publish_id`. Terminal `PUBLISH_COMPLETE` → mark published with the public post id; `FAILED`/`PUBLISH_FAILED` → mark failed; anything else requeues itself (`retry_count++`) for another poll. **`MAX_POLLS = 10`** → timeout → fail. Transient HTTP errors requeue, permanent data issues fail immediately.
3. **Status roll-up:** every `PostTarget` save/delete triggers `PostTargetObserver` → `post->recalculateStatus()` (section 1), so the post reaches `Published` once its last leg resolves, or `Failed` once any leg is terminal.

**Why observer-driven (Track B) instead of explicit recalc calls:** the previous approach sprinkled `recalculateStatus()` through every mutation site in both jobs and was easy to miss; now a single observer owns the invariant. The entry flip for `Scheduled → Publishing` stays in the publish job because the observer deliberately never derives a post past `Scheduled`. Covered by directed tests in `PostTest`.

---

## 6. Backend — Calendar

`CalendarController`:

- One month at a time; a `month=YYYY-MM` query param, defaulting to the current month. The client pans within a **±6 month window** (`WINDOW_MONTHS`), refetching only when it crosses an edge.
- Loads posts between window start/end: scheduled posts keyed by `scheduled_at`, non-scheduled posts by `created_at`. Optional `account` filter via `whereHas('targets', ...)` (shared `FilterableAccounts` trait).
- Returns serialized posts with status (value+label), dates, first caption, target legs (platform, display name, leg status), and resolved media URLs. Filters + account list shared so the page can re-render without a reload.

---

## 7. Backend — Media Library

`MediaController`:

- `index`: **first-class infinite scroll** — `Inertia::scroll(...)` with cursor pagination (`24`/page) normalized for the Inertia v3 `<InfiniteScroll>` component; eager-loads `uploadedBy` + `withCount('posts as posted_count')`; serializes `uploaded_by` as `{ fullname }` (the prior bug used `->name`, which doesn't exist → `null === null` assertion passed vacuously; fixed to `->fullname` with a real assertion in `MediaTest`).
- `upload`/`store`: validates against `EXTENSIONS` (`image/jpeg|png|webp|gif`, `video/mp4`), stores on the configured `media.disk` with `media.key_prefix`, stamps `MediaStatus::Ready` once the file exists, returns the media record.
- `complete`/`intent` (presigned/object-storage flow) + `destroy` (soft delete). Pins via `post_media` pivot.

---

## 8. Metrics Analytics (the newest feature — backend first)

> Design decision: **day-grain decay snapshots**, no migration for per-metric columns; **any fetch failure permanently stops that target** via an error snapshot. UI surfacing is deliberately deferred.

### Schema — `post_metrics` (migration `2026_09_26_082004`)

- `id` ULID, `post_id` (FK cascade, index), `post_target_id` (FK cascade), `platform`, `snapshot_type` (default `'snapshot'`), `snapshot_date` (date), `data` (JSON), timestamps.
- **Unique** `(post_target_id, snapshot_date)` — one snapshot per target per day. `post_id` and `platform` are denormalized convenience columns (indexed for filtering), _not_ the key. The earlier `(post_id, platform, snapshot_date)` key was a real bug: a post can legitimately carry multiple targets on one platform (e.g. two Facebook accounts), so the second account was silently skipped or collided; re-keyed via `2026_09_27_110222_add_post_target_id_to_post_metrics_table`.
- Metrics live inside JSON keys: `likes`, `comments`, `shares`, `saves`, `impressions`, `reach`, `engagements`, `views` (honest `null` where a platform doesn't expose a key).

### Fetch actions — `app/Actions/Application/Post/Fetch*MetricsAction.php` (4, all implemented)

- `FetchFacebookMetricsAction`, `FetchInstagramMetricsAction`, `FetchLinkedInMetricsAction`, `FetchTikTokMetricsAction` — each calls the platform's read endpoint with the account token and returns a **normalised JSON shape** with the same key set (nulls where N/A), so the model/tests are platform-agnostic.

### Job — `app/Jobs/SyncPostMetricsJob.php` (rewritten this session)

- Now **per-target**: `public PostTarget $target`; every guard and write keys off `post_target_id` (platform/post kept as convenience values), so two targets on one post/platform never collide.
- Guards: target must be `Published`, have `platform_post_id`, latest snapshot must not be an error snapshot, and no snapshot for today yet.
- On success → `firstOrCreate` today's `{platform}` snapshot with the fetched `data`.
- On failure → writes a **`snapshot_type='error'`** row (`data = ['error' => message]`), which permanently disables further polling for that post/platform (the guard checks the latest snapshot type).
- Removed the old flat **self-requeue** (`NEXT_RUN_DELAY_SECONDS`, requeueing every 24h for all targets) — the clock now lives in the scheduler/command, and the job is a pure unit.

### Command — `app/Console/Commands/SyncDuePostMetrics.php` (new)

- `php artisan metrics:sync-due`, Laravel 13 `#[Signature]`/`#[Description]` attribute style.
- `SNAPSHOT_OFFSETS = [0, 1, 3, 7]`: a post is **due** on its publish-day plus 1/3/7-day birthdays, i.e. decay at D0→D1→D3→D7, then it drops out forever.
- Due = today equals `published_at + offset` **and** no snapshot for today **and** latest snapshot not an error. Chunked `chunkById(200)` through published targets; dispatches `SyncPostMetricsJob` per due target.

### Scheduling — `bootstrap/app.php` (`withSchedule`)

- `php artisan metrics:sync-due` registered to run **every hour** with `->hourly()->withoutOverlapping()`.
- Note: `everyHour()` does **not** exist in this framework — `ManagesFrequencies` provides `hourly()` (verified against the installed framework trait); `schedule:list` shows `0 * * * * … metrics:sync-due`.
- Production still needs the scheduler running: cron `* * * * * php artisan schedule:run …` (Laravel Cloud runs it automatically). For dev, `php artisan schedule:work`.

### Tests — `tests/Feature/PostMetricsTest.php` (11 tests)

- 4 platform shape tests (FB/IG/LinkedIn/TikTok data normalised), skip-when-today-snapshot-exists, **error-snapshot-then-stop** (no retry), no-`platform_post_id` skip, same-post dedupe, and 3 command-dispatch tests.

---

## 9. Notifications

- `PostTargetFailedNotification` — fired when a publish/poll leg fails; delivered to the post creator. Bell in the navbar shows latest 8 with live unread count (shared via `HandleInertiaRequests`, `read_at` iso). `NotificationController@readAll` marks unread as read. `NotificationTest` covers it.

---

## 10. Workspace Settings

- `WorkspaceSettingsController@index` → `Settings` page (member count, created date). `update` validates via `UpdateWorkspaceRequest` (name uniqueness), runs `UpdateWorkspaceAction`, flash success, redirects to the (possibly new) slug route.

---

## 11. Frontend / UI

### App shell & routing

- `app.tsx` boots Inertia v3 with `resolvePageComponent` (title suffix `- {appName}`); routes at `/app/{workspace}/...`.
- `AuthenticatedLayout`: responsive sidebar (collapsed/pinned on desktop, slide-over on mobile), top `Navbar`, `CustomToaster` for flash messages. Theme CSS vars (`--dashboard-background`, accent gradient) from `config/theme`.
- `Sidebar`: nav from `config/navigation/application.ts` (`Dashboard`, `Accounts`, `Posts`, `Calendar`, `Media`; footer `Settings`), nested submenu accordion, active-path highlight, `WorkspaceSwitcher`, account menu (Profile / Settings / Logout via Wayfinder `AuthController.logout().url`).
- Shared `Select` component (fully custom listbox, used by the calendar header and filters), `statusPost/`platform`styling helpers,`NotificationBell`, `CustomToaster`.

### Pages (`resources/js/pages`)

- **Dashboard** — currently a **stub** (greeting only); the intended home for recent-posts + metrics cards.
- **Posts/Index** — list of posts with status badges and target legs.
- **Posts/Create** — the composer (caption, media picker, per-platform targets: arc/title/caption, scheduled date/time, send-now).
- **Posts/Calendar** — month grid, dots per post, pan/pagination within the ±6 month window via month query param; account filter.
- **Media/Index** — gallery grid with `InfiniteScroll` (24/page), thumbnails from storage URLs, posted-count, uploader name; empty state.
- **SocialAccounts/Index** — platform cards (each with configure/connect state), account rows showing `{ value, label }` status incl. derived `expired`, connect links, disconnect.
- **Workspace/Settings**, **Auth** (Login, Register, email verification), **profile** placeholder, Marketing hub (`resources/js/pages` marketing landing).

### New-this-session UI notes

- Calendar header rebuilt on the shared `Select` (month + year dropdowns replaced by a fully custom listbox; the accidental check icon was removed).
- No metrics UI yet (backend-first choice).

---

## 12. Seeders & Factories

- `UserSeeder` — `demo@dev.com` user (personal workspace via `CreateWorkspaceAction`).
- `DemoContentSeeder` (used by `DatabaseSeeder`) — owner `Dana Demo` (`demo@dev.com` / `password`), **6 connected accounts** (FB main+shop, IG, LinkedIn, TikTok) plus the **`tiktok_expired`** expired account (status `Expired`, token −30 days), **7 flat-colour placeholder images** + 1 `storefront_reel.mp4` written to the `public` disk (no binary fixtures), and **14 posts** spanning every post/target status across previous/current/next month so list + calendar look real. Post legs carry realistic `error_message`, `retry_count`, `published_at`, `platform_post_id`.
- Idempotent: `updateOrCreate` for accounts/media (with `withTrashed()`), and posts are `delete()`d (cascade clears targets + pivots) then recreated.
- Factories: `PostFactory` (Published), `PostTargetFactory` (Published + `published_at now()`), `SocialAccountFactory` (Connected, token +60 days), `PostMetricFactory` (snapshot + full data), `MediaFactory`, `UserFactory` (fills `fullname`).

---

## 13. Tests

`tests/Feature` (Pest): `LoginTest`, `RegistrationTest`, `EmailVerificationTest`, `SocialAuthTest`, `SocialAccountsTest`, `PostTest`, `CalendarTest`, `MediaTest`, `NotificationTest`, `PostMetricsTest`, `WorkspaceSettingsTest`, `ExampleTest`.

Key directed coverage added this session:

- **`PostTest`** — target roll-up via the observer (Track B), the empty-target and pre-dispatch guards, partial-failure roll-up (Track A), reschedule version guard, publish entry flip.
- **`SocialAccountsTest`** — connected+past-token → `expired` (value+label); revoked+past-token stays `revoked`; connected+valid → `connected`.
- **`MediaTest`** — asserts the real `fullname` for `uploaded_by`.
- **`PostMetricsTest`** — see section 8.

**Run state:** `vendor/bin/pint --dirty --format agent` clean; suite **126/126 (814 assertions)**. `phpunit.xml` defaults to MySQL (`lareact_test`), so bare `php artisan test --compact` works — no env overrides needed.

---

## 14. Known Gaps & Next Steps

- **Metrics UI** — dashboard recent-posts card + a post-detail/history page to consume `post_metrics` rows (deferred; backend first).
- **Demo metrics backfill** — posts older than 7 days never become due under the D0/D1/D3/D7 decay; seed `post_metrics` history for them.
- **Retry feature** — `retry_count` + `Failed` legs + `PostTargetFailedNotification` exist; a UI "retry" action and the `Failed → queued` path it needs were explicitly planned but not built. **Note:** the Track B observer was gated on / intended to underpin this feature and shipped ahead of it (bundled early by decision).
- **Batching** — TikTok accounts can submit up to N videos per day; consider a per-account batch action instead of strictly per-target jobs.
- **Operations** — the scheduler must be running in production (cron `schedule:run` / Laravel Cloud auto); dev currently needs `php artisan schedule:work`.

---

## 15. Feature Timeline

1. Auth + onboarding, workspaces, shared app shell.
2. Social account OAuth (4 platforms) + connect/disconnect.
3. Composer + publish pipeline (per-target delayed jobs, TikTok async poll).
4. Post status invariant — Track A (guards + precedence) then **Track B** (observer-driven roll-up + single `Scheduled→Publishing` entry).
5. Calendar (windowed month grid with shared `Select` header).
6. Media library (infinite scroll) + **media `fullname` fix**.
7. **Expired vs Revoked** account statuses (`effectiveStatus`, UI + seed).
8. **Metrics sync** — schema, 4 fetch actions, per-target job, `metrics:sync-due` command + hourly scheduler.
