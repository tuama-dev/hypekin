# Build Plan: Posts & Publishing Slice

Locked plan for the first posts/publishing vertical slice. Status/decisions in this file are
authoritative until the slice ships; refresh this file and `docs/CORE_APP_DB_DESIGN.md` when
the work is done.

---

## Decisions locked in this session

- **Platforms:** Facebook + Instagram + **LinkedIn + TikTok** (text/image posts; TikTok photo mode).
  X publishing deferred. LinkedIn OAuth scopes are `r_liteprofile` + `w_member_social` (the explicit
  `->scopes()` override replaced Socialite's defaults; `r_liteprofile` is also needed for the `/v2/me` profile).
- **Media:** required for Instagram AND TikTok (photo direct post); optional for Facebook and LinkedIn.
  One image per post this slice. Carousel/video/per-target media deferred.
- **Publishing:** always queued — "Publish now" and "Schedule" are the same code path, differing
  only by `->delay($scheduled_at)`. Database queue (`jobs` table, `QUEUE_CONNECTION=database`)
  provides delayed availability — no cron/scheduler command needed.
- **IDs:** ULID everywhere, done _now_ (no production data). Existing migrations rewritten so
  `php artisan migrate:fresh` yields ULID-native tables.
- **Storage:** media files go **browser → object storage directly** via presigned PUT. Laravel
  never proxies file bytes. Publishing resolves a **public GET URL** and hands it to the platform
  API, which fetches it server-side.

---

## 1. Easy object-storage configuration

Storage is driven entirely by `.env` — swap AWS S3 / DigitalOcean Spaces / Cloudflare R2 /
MinIO by editing values, never code. Reuses the existing `s3` disk in
`config/filesystems.php` plus a new `config/media.php` for media-specific rules.

```env
# ── Media object storage (S3-compatible) ──────────────────────────
MEDIA_FILESYSTEM_DISK=s3            # disk media rows reference
AWS_ACCESS_KEY_ID=                  # token for Spaces/R2/AWS
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1        # Spaces: use bucket's region (auto1, fr1…)
AWS_BUCKET=                         # bucket name
AWS_URL=                            # PUBLIC GET base (Spaces CDN or path) — used for publish
AWS_ENDPOINT=                       # set for Spaces/R2/MinIO: https://<region>.digitaloceanspaces.com
AWS_USE_PATH_STYLE_ENDPOINT=false   # true for MinIO/R2, false for AWS/Spaces
AWS_PRESIGN_TTL=15                  # minutes a presigned upload URL stays valid
```

Tasks:

- Extend `config/filesystems.php` `s3` block (endpoint/path-style already partially present; add
  `MEDIA_PRESIGN_TTL`-driven behavior where needed).
- `.env.example`: add the block with commented examples for AWS S3, DigitalOcean Spaces, and
  Cloudflare R2.
- New `config/media.php`: `disk` (from `MEDIA_FILESYSTEM_DISK`), `allowed_mimes`
  (jpeg/png/webp/gif), `max_size` (20MB), `presign_ttl` (minutes).

`media.disk` stores whatever `MEDIA_FILESYSTEM_DISK` resolves to, so runtime URL resolution
(`Storage::disk($row->disk)`) is provider-swappable.

---

## 2. ULID rewrite (existing migrations)

Rewrite migration files so `php artisan migrate:fresh` is ULID-native. No production data exists.

- `users`, `user_oauth_providers`, `workspaces`, `workspace_user` (composite PK),
  `social_accounts`.
- `$table->ulid('id')->primary()`, `foreignUlid(...)->constrained()`.
- Models gain `HasUlids` trait.

---

## 3. New ULID tables

### `posts`

`workspace_id` (FK → workspaces) · `created_by_user_id` (FK → users) · `status`
(`draft/scheduled/publishing/published/failed/canceled`) · `scheduled_at` timestamp nullable ·
`created_at`/`updated_at`.

No caption/content on `posts` — captions are per-platform on `post_targets` (per design doc).

### `post_targets`

`post_id` (FK → posts) · `social_account_id` (FK → social_accounts) · `caption` text ·
`title` string nullable (TikTok photo title, ≤ 22 chars) · `status` (`pending/queued/published/failed`) ·
`platform_post_id` string nullable · `platform_upload_id` string nullable (TikTok `publish_id` from
content/init) · `published_at` timestamp nullable · `error_message` text nullable ·
`retry_count` int default 0 · `created_at`/`updated_at`.

**Constraint:** unique `(post_id, social_account_id)` — retries can't double-publish to one account.

### `media` (library, workspace-scoped)

`id` ULID · `workspace_id` (FK → workspaces) · `uploaded_by_user_id` (FK → users) · `disk` string
(server-config, e.g. `s3`) · `path` string (object key — **server-generated, never client-supplied**) ·
`type` enum (`image/video`) · `mime_type` string · `size_bytes` int · `width`/`height` int nullable ·
`duration_seconds` int nullable (video only) · `status` enum (`pending/ready/failed`) ·
`created_at`/`updated_at`.

### `post_media` (pivot, per design doc verbatim)

`id` ULID · `post_id` (FK → posts) · `media_id` (FK → media) · `position` int.
Unique `(post_id, media_id)`.

Enums: `PostStatus`, `PostTargetStatus`, `MediaType`, `MediaStatus`.
Models: `Post`, `PostTarget`, `Media` (+ factories; `Post::media()` through pivot, `Post::targets()`,
`Media::workspace()`).

---

## 4. Dependency

`composer require league/flysystem-aws-s3-v3` — S3-compatible adapter enabling
`temporaryUploadUrl()` for presigned PUT uploads.

---

## 5. Media API (`MediaController`, routes `['verified','workspace']`)

**Direct-upload flow (browser → object storage):**

1. `POST …/media/intent` `{type: image, mime_type, size_bytes}` → validates whitelist + ≤20MB →
   generates server-side object key + `temporaryUploadUrl()` → returns `{path, upload_url, expires_at}`.
   Server never sees the bytes.
2. Browser PUTs the file straight to the presigned URL.
3. `POST …/media/complete` `{path, mime_type, size_bytes, width?, height?}` → optional HEAD
   metadata check (never downloads the body) → creates `media` row (`disk` = configured disk,
   `path` = server key, `status=ready`, `uploaded_by_user_id`, dimensions client-supplied from the
   browser).

Routes:

- `POST app/{workspace:slug}/media/intent`
- `POST app/{workspace:slug}/media/complete`

---

## 6. Queued publish

### `StorePostRequest`

- `caption`: required, ≤ 3000 chars.
- `title`: optional, ≤ 22 chars, **required when any selected target is TikTok**.
- `targets`: ≥ 1; each must be an in-workspace, `connected`, **publishable** account
  (`Platform::publishable()` = LinkedIn, Facebook, Instagram, TikTok).
- `scheduled_at`: optional, must be in the future.
- `media_id`: optional (required when any selected target is Instagram or TikTok — enforced at
  request validation and again inside the job for defense-in-depth).

### Flow

1. Create `posts` + one `post_targets` row per selected account (`status: pending`), attach media
   via `post_media` pivot.
2. Dispatch **one `PublishPostTargetJob` per target**; `->delay($scheduled_at)` when scheduled.
   Nothing runs synchronously.

### `PublishPostTargetJob` → `match (platform)`

- **Facebook:** `POST /{page_id}/feed` `{message}`; with image → `POST /{page_id}/photos` `{url, caption}`.
- **Instagram:** image required → `POST /{ig_user_id}/media` `{image_url, caption}` → (creation_id)
  → `POST /{ig_user_id}/media_publish` `{creation_id}`.
- **LinkedIn:** `POST api.linkedin.com/v2/ugcPosts` `{shareMediaCategory: NONE}` for text; image →
  `assets?action=registerUpload` → streamed `PUT uploadUrl` (`Storage::disk(...)->readStream`) →
  `ugcPosts` `{shareMediaCategory: IMAGE, media[0].media = assetUrn}`. Headers `Authorization` +
  `X-Restli-Protocol-Version: 2.0.0`. Owner `urn:li:person:{external_account_id}`.
- **TikTok:** async — init `POST open.tiktokapis.com/v2/post/publish/content/init/`
  `{media_type: PHOTO, post_mode: DIRECT_POST, source_info: {source: PULL_FROM_URL,
photo_images: [publicUrl]}, post_info: {title ≤22, description}}` → store returned `publish_id` in
  `platform_upload_id`, leave target `queued`, dispatch `CheckTikTokPublishStatusJob` (delayed 60s).
  The status job polls `/post/publish/status/fetch/` with the `publish_id`: `PUBLISH_COMPLETE` →
  `published` + `platform_post_id`; `FAILED`/`PUBLISH_FAILED` → `failed` + `fail_reason`;
  `PROCESSING_*` → re-dispatch itself (delayed) up to 10 checks via `retry_count`, then `failed`.

Details:

- `Http::withToken(token)`, 10s connect / 30s timeouts (per `ConnectFacebookPagesAction`;
  LinkedIn media PUT uses a 120s upload timeout).
- Media URL = `Storage::disk($media->disk)->url($media->path)` — a **public GET URL**, never the
  presigned PUT URL, never `localhost`.
- Update target row: `status`, `platform_post_id`, `error_message`, `published_at`.
- Retries only touch targets with no `platform_post_id` **and** no `platform_upload_id` (a TikTok
  upload has already been submitted — re-running init would double-post; the status job owns the
  terminal state, so the guard skips and the in-flight check finishes it).
- Recalculate `posts.status` after each job completes.

### Caveat

IG/FB fetch the media URL server-side — live publish requires a publicly reachable `AWS_URL`
(real domain/tunnel/CDN). `Http::fake` tests cover the flow regardless; localhost cannot verify
against the live APIs.

---

## 7. Routes + controller (posts)

- `GET   app/{workspace:slug}/posts` → index
- `GET   app/{workspace:slug}/posts/create` → composer page
- `POST  app/{workspace:slug}/posts` → store

All behind `['verified', 'workspace']`. `PostController` follows existing render/map conventions
(inline view mapping). Wayfinder auto-generates `@/actions/.../PostController.ts`.

---

## 8. Frontend

- **Sidebar**: new **Posts** item (`Send` icon, `/posts`).
- **Create.tsx** (`Application/Posts/Create.tsx`): caption textarea + char counter; checkboxes of
  connected FB/IG accounts (IG flagged "image required"); single-file image upload with local
  preview (intent → upload → complete → `media_id`); **Publish now** + **Schedule** (datetime)
  buttons; `useForm` + `setDefaultsOnSuccess`.
- **Index.tsx** (`Application/Posts/Index.tsx`): post list with status badges, thumbnail previews,
  per-target chips (published/failed) + failure tooltips. Matches Accounts page conventions.

---

## 9. Tests (Pest)

### `tests/Feature/PostTest.php`

- Validation: empty caption / no targets / non-member account / IG text-only rejected / FB text-only
  accepted / 404 for non-member.
- Upload → `media` row created + `post_media` pivot attached.
- Publish-now dispatches job immediately; scheduled sets `scheduled_at` and delays dispatch.
- `Http::fake`: FB success (`feed`/`photos`), IG two-step (`media` → `media_publish`), failure →
  target `failed` + `error_message` captured.
- Idempotent retry: already-published target skipped.

### `tests/Feature/MediaTest.php`

- Intent validation (mime/size).
- Complete → `media` row created with the right `disk` + server-generated `path`.
- `Storage::fake('s3')` + stubbed `temporaryUploadUrl` (local adapter can't presign).

---

## 10. Verification & docs refresh

- `vendor/bin/pint --dirty --format agent`
- `npm run types:check`
- `vp fmt` on touched TS (outside `components/ui`)
- Full suite: `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=lareact_test DB_USERNAME=root DB_PASSWORD= php artisan test --compact`
- Live delivery notes: `queue:work` + configured public object-storage bucket/URL.
- Refresh stale `docs/CORE_APP_DB_DESIGN.md` lines (still say "LinkedIn, synchronous, no queue") →
  FB + IG, queued, scheduled; add the media library + presigned-direct-upload note.

---

## 11. Shipped (this slice)

Implemented and verified (69 tests green, `migrate:fresh` clean, pint/types/vp fmt clean):

- ULID frameworks, `posts` / `post_targets` / `media` / `post_media` tables, enums, factories, models
  (`Post::recalculateStatus()`, `Media::publicUrl()`), `config/media.php`, `.env.example` block.
- `PublishToFacebookAction` / `PublishToInstagramAction`, `PublishPostTargetJob` (idempotent, no retry),
  `StorePostRequest`, `PostController`, `MediaController` (intent → presigned PUT → complete), routes,
  Wayfinder regenerated, React pages (`Application/Posts/{Create,Index}` + nav + `types/auth` ULID).
- Tests: `PostTest.php` (16) + `MediaTest.php` (5).

Deviations from the plan (intentional):

- `config/media.php` `allowed_mimes` includes `video/mp4` (keeps `MediaType::Video` reachable) —
  the compose UI still only accepts images this slice.
- Instagram text-only is rejected **twice**: at request validation (`media_id` error) and again inside
  the job (defensive guard with "Instagram posts require an image." failure).
- `PublishPostTargetJob` never auto-retries (FB/IG publish calls aren't idempotent; a retry could
  double-post). `error_message` captures raw platform errors for manual follow-up.
- Pivot tables (`workspace_user`, `post_media`) use composite primary keys, not surrogate ULID `id`:
  Eloquent's `attach()` inserts bypass model events, so a ULID `id` column is never filled by MySQL.

---

## 12. Media library (follow-on slice)

Implemented and verified (76 tests green):

- `Media` gains `SoftDeletes`; migration adds `deleted_at` + a unique `(workspace_id, path)` index
  (indexes the `complete` handshake, DB-level upload idempotency).
- `MediaController@index` — `Inertia::scroll()` paginating 24/page driving the first-class
  `<InfiniteScroll>` component; eager-loads `uploadedBy` + a `posts` count (no N+1; regression-guarded
  by a constant-query-count test).
- `MediaController@destroy` — soft-deletes the row **and** removes the object bytes; refused with an
  error flash while the file is attached to any post. `complete` restores a soft-deleted row when the
  same path is re-acknowledged.
- Routes `workspace.media` (GET) + `workspace.media.destroy` (DELETE).
- React page `Application/Media/Index` — responsive image/video grid, type/size/dimensions/usage meta,
  delete guarded by `ConfirmDialog` (disabled for attached files), "N files" header.
- Sidebar/nav gains a **Media** item (`/media`).
- Tests: `MediaTest.php` grows to 13 (scoping, 404s, pagination, delete + bytes, attached-block,
  restore path, N+1 guard).

Deviations (intentional):

- Soft delete keeps the **row** but removes the **object bytes** — no live public URL after deletion,
  no orphaned storage; recovery is a re-upload, not a restore.
- `publicUrl()` on a soft-deleted row resolves but the object is gone, so the delete-block for
  attached media is the real safety rail.

---

## 13. LinkedIn + TikTok publishing (follow-on slice)

Implemented and verified (90 tests green, pint / vp / types / build clean):

- `Platform::publishable()` (LinkedIn/Facebook/Instagram/TikTok) — the single source of truth for the
  store `after()` allowlist and `PostController@create` `publishableAccounts`. LinkedIn scopes widened
  to `['r_liteprofile', 'w_member_social']` (the old `w_member_social`-only override broke the
  provider's `/v2/me` profile call).
- Migration `add_publishing_fields_to_post_targets_table`: `title` (TikTok photo title, ≤22) +
  `platform_upload_id` (TikTok `publish_id`). Both in `PostTarget` fillable.
- `StorePostRequest`: target allowlist via `publishable()`; media-required generalized to Instagram +
  TikTok; new TikTok title requirement. `title` wired through store into each `post_targets` row.
- `PublishToLinkedInAction` — text via UGC `NONE`, image via registerUpload → streamed PUT (the one
  place the pipeline touches bytes server-side: LinkedIn can't publish from an external URL) → UGC
  `IMAGE`. Fails with the platform error message, mirroring Facebook/Instagram.
- `PublishToTikTokAction` (init, `PHOTO` + `DIRECT_POST` + `PULL_FROM_URL`, title from `target->title`
  or truncated caption) + `CheckTikTokPublishStatusJob` (public-status poll with 10-check budget via
  `retry_count`, then `failed`; never auto-retries the init).
- `PublishPostTargetJob`: LinkedIn/TikTok arms; the idempotency guard also skips targets with a
  `platform_upload_id` (submitted TikTok upload — the status job finalizes it).
- Frontend `Create.tsx`: shared **Title** input (≤22, required marker when a TikTok account is
  checked); "Image required" badge generalized to Instagram + TikTok targets (shown when selected with
  no media); "Title required" badge for TikTok; copy updated to mention all four platforms.
- Preview `PostPreview.tsx`: `title` threaded through `PostPreviewPane` → TikTok renderer; `isEmpty`
  accounts for the title.
- Tests: `PostTest.php` 19 → 30 (LinkedIn text/image/fail + publishable list, TikTok media/title
  validation + init dispatch + status complete/failed/requeue/exhausted). `SocialAccountsTest` connect
  gating assertions now explicitly null the platform `client_id` config so they're deterministic
  regardless of `.env` credentials.

Deviations (intentional):

- TikTok live publishing additionally needs app approval for Content Posting and the object-storage
  public URL domain registered in the TikTok developer app (`PULL_FROM_URL` verifies it); tests use
  `Http::fake` and stay valid without it.
- TikTok title comes from the shared composer field; non-TikTok targets store but ignore it.

## Phase 1: failed-post notifications

When a target fails, its post creator is notified in-app (database channel only).

- `notifications` table — framework scaffold migration (`uuid` id, `ulidMorphs('notifiable')` since users use ULID keys, `data` json, `read_at`).
- `App\Notifications\PostTargetFailedNotification` (`ShouldQueue`, `via=['database']`). Payload: `title` ("Publish failed on {Platform label}"), `message` (account display name), `platform`, `account_display_name`, `error` (truncated 300), `post_id`, `workspace_slug`.
- Emission points (single emission per failure):
    - `PublishPostTargetJob::handle` catch block → `$this->target->post->createdBy?->notify(...)`.
    - `CheckTikTokPublishStatusJob` → `failTarget()` (centralizes forceFill + recalc + notify, used by `markFailed`, the missing-upload-id guard, and the poll-budget timeout).
- Recipient: **post creator only** (`Post::createdBy()`), never the whole workspace.
- Shared for the navbar: `auth.notifications` (latest 8) + `auth.unread_count` in `HandleInertiaRequests`.
- `NotificationController::readAll(Request $request, Workspace $workspace)` — note the signature MUST type-hint `Workspace $workspace`: Laravel 13 implicit binding only runs for `{workspace:slug}` when the controller method declares a matching typed param; without it, `EnsureWorkspaceMembership` sees a raw string and the membership check silently passes.
- Route `POST app/{workspace:slug}/notifications/read-all` → `workspace.notifications.read-all`, middleware `['verified','workspace']`.
- Frontend: `resources/js/components/Layout/NotificationBell.tsx` (badge with unread count, dropdown mirroring `SidebarAccountMenu`, "Mark all read" → POST + sonner toast, items link to the workspace posts index). Rendered in the Navbar.
- Tests: `tests/Feature/NotificationTest.php` (payload persistence + details, read-all, per-user scoping, outsider 404) and notification assertions in the existing Facebook/TikTok failure tests in `PostTest.php` (creator sent, coworker not sent).

## Phase 2: automatic post metrics

Daily engagement snapshots per published target, self-scheduled without new infrastructure. Backend-only (no UI yet).

- `post_metrics` table — ULID id, `post_id` FK (cascade delete), `platform` (enum value string), `snapshot_type` (default `'snapshot'`), `snapshot_date`, `data` json, timestamps. Unique `(post_id, platform, snapshot_date)`.
- `App\Models\PostMetric` + `PostMetricFactory` (casts `platform` → `Platform`, `snapshot_date` → date, `data` → array).
- Four fetch actions (mirror the publish actions' style, throw `RuntimeException` on missing token / platform failure) returning one normalized `data` shape `[likes, comments, shares, saves, impressions, reach, engagements, views]`:
    - `FetchFacebookMetricsAction` — `GET /v21.0/{post_id}?fields=likes.summary(true),comments.summary(true),shares` + `GET /{post_id}/insights?metric=post_impressions,post_impressions_unique`.
    - `FetchInstagramMetricsAction` — `GET /v21.0/{ig-media}/insights?metric=likes,comments,shares,saves,reach,impressions`.
    - `FetchLinkedInMetricsAction` — `GET /rest/socialActions/{shareUrn}/analytics` (`timeIntervals` covering the last 7 days; `X-Restli-Protocol-Version: 2.0.0`); `engagements` = likes+comments+shares.
    - `FetchTikTokMetricsAction` — `POST /v2/video/query/` (`filters.video_ids`, fields `view_count,like_count,comment_count,share_count`); `views` = view_count.
- `App\Jobs\SyncPostMetricsJob` — chunks published targets (`platform_post_id` set), skips any `(post, platform)` that already has today's snapshot, per-platform failures are caught and skipped, and it **re-queues itself daily** (`NEXT_RUN_DELAY_SECONDS = 86400`) so a running worker keeps snapshots fresh. Query is guarded by the composite unique index.
- Tests: `tests/Feature/PostMetricsTest.php` (9) — one snapshot per platform with the normalized shape, Http call assertions (endpoints + headers + payload), idempotency (existing today-snapshot → no request), self-requeue, silent skip on platform error, skip when `platform_post_id` missing, single daily snapshot when a post has two targets on the same platform.

Deviations (intentional):

- Metric `data` is one normalized shape across platforms (nulls where a platform has no such metric, e.g. TikTok `views` only) instead of storing each platform's raw response.
- Facebook/Instagram insights require `pages_read_engagement`/`instagram_basic` (already part of the connect scopes).
- A failing platform is silently skipped and retried on the next run rather than surfacing an error — failure notifications remain publish-only.

---

## Phase 3: publishing calendar

An interactive month-grid calendar for planning/re-planning posts. Dedicated `/calendar` page (`GET /{workspace:slug}/calendar`, named `workspace.calendar`, middleware `['verified','workspace']`).

- Each post lands on one date: scheduled posts on their `scheduled_at`, all others on `created_at`. The page fetches a ±6-month window around the requested month (`?month=Y-m`, default current) and the React client filters/slices it client-side.
- Day cells show status-colored dots; clicking a day opens a side panel listing that day's posts (caption, status chip, time, target platforms, thumbnail) with a **Move** action for scheduled posts.
- **Creation happens on the composer page:** the panel's "New post" action links to `workspace.posts.create` (`PostController::create`) instead of opening a modal on the calendar, so there is one creation surface.
- **Reschedule:** `PATCH /{workspace:slug}/posts/{post}/scheduled_at` (named `workspace.posts.reschedule`) moves/unschedules (`scheduled_at` nullable) a scheduled post. Validation via `UpdatePostScheduleRequest` (`scheduled_at` nullable | date | after:now).
- **Stale-job guard:** new `posts.schedule_version` (unsignedBigInteger, default 0). `PublishPostTargetJob` now accepts `?int $scheduleVersion = null`; a queued job whose captured version no longer matches the post's current `schedule_version` is a no-op. Rescheduling bumps the version and re-dispatches pending targets, so an old queued publish can never fire after a move.
- New `App\Http\Controllers\Application\CalendarController` (window constant `WINDOW_MONTHS = 6`) maps each post to a view shape (id, status, dates, first-target caption, targets with platform/display/status, media URLs) and returns only `month` + `posts` — account and media-library props live on the composer, which owns creation.
- Tests: `tests/Feature/CalendarTest.php` (11) — date placement, window exclusion, month query, non-member 404, reschedule moves + bumps version + re-queues pending targets with the job delay, clear-to-publish-now (no new `scheduled_at`), no re-queue for already-published targets, past-date rejection, stale-job no-op after reschedule, cross-workspace 404, and `store()` honouring `redirect_back`.

Deviations (intentional):

- Month navigation is client-side within the fetched ±6-month window; panning past the edge issues a fresh `index` request with a new `month` query rather than deferring/lazy-loading.
- The calendar does not pre-fill a date on the composer, so "New post" opens a blank composer rather than scheduling at 09:00 on the clicked day.
- The calendar shows creation date for unscheduled posts since there is no publish timestamp until a target actually goes out.

---

## Deferred (explicitly out of scope this slice)

- Video / carousel media.
- Per-target media (media is post-level, shared across targets).
- Cancel-scheduled action.
- Token-refresh flow.
- X publishing.
- Uploading directly from the media library (uploads currently originate in the post composer).
