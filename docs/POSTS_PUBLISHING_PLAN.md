# Build Plan: Posts & Publishing Slice

Locked plan for the first posts/publishing vertical slice. Status/decisions in this file are
authoritative until the slice ships; refresh this file and `docs/CORE_APP_DB_DESIGN.md` when
the work is done.

---

## Decisions locked in this session

- **Platforms:** Facebook + Instagram first (text/image posts). LinkedIn/TikTok/X publishing deferred.
- **Media:** required for Instagram (its Publishing API cannot post text-only); optional for Facebook.
  One image per post this slice. Carousel/video/per-target media deferred.
- **Publishing:** always queued — "Publish now" and "Schedule" are the same code path, differing
  only by `->delay($scheduled_at)`. Database queue (`jobs` table, `QUEUE_CONNECTION=database`)
  provides delayed availability — no cron/scheduler command needed.
- **IDs:** ULID everywhere, done *now* (no production data). Existing migrations rewritten so
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
`status` (`pending/queued/published/failed`) · `platform_post_id` string nullable ·
`published_at` timestamp nullable · `error_message` text nullable · `retry_count` int default 0 ·
`created_at`/`updated_at`.

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
- `targets`: ≥ 1; each must be an in-workspace, `connected`, **Facebook or Instagram** account.
- `scheduled_at`: optional, must be in the future.
- `media_id`: optional (required effectively when a target is Instagram — enforced at publish).

### Flow
1. Create `posts` + one `post_targets` row per selected account (`status: pending`), attach media
   via `post_media` pivot.
2. Dispatch **one `PublishPostTargetJob` per target**; `->delay($scheduled_at)` when scheduled.
   Nothing runs synchronously.

### `PublishPostTargetJob` → `match (platform)`
- **Facebook:** `POST /{page_id}/feed` `{message}`; with image → `POST /{page_id}/photos` `{url, caption}`.
- **Instagram:** image required → `POST /{ig_user_id}/media` `{image_url, caption}` → (creation_id)
  → `POST /{ig_user_id}/media_publish` `{creation_id}`.

Details:
- `Http::withToken(decrypt token)`, 10s connect / 30s timeouts (per `ConnectFacebookPagesAction`).
- Media URL = `Storage::disk($media->disk)->url($media->path)` — a **public GET URL**, never the
  presigned PUT URL, never `localhost`.
- Update target row: `status`, `platform_post_id`, `error_message`, `published_at`.
- Retries only touch targets with no `platform_post_id` (never double-publish).
- Recalculate `posts.status` after each job completes.

### Caveat
IG/FB fetch the media URL server-side — live publish requires a publicly reachable `AWS_URL`
(real domain/tunnel/CDN). `Http::fake` tests cover the flow regardless; localhost cannot verify
against the live APIs.

---

## 7. Routes + controller (posts)

- `GET   app/{workspace:slug}/posts`          → index
- `GET   app/{workspace:slug}/posts/create`   → composer page
- `POST  app/{workspace:slug}/posts`          → store

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

## Deferred (explicitly out of scope this slice)

- Video / carousel media.
- Per-target media (media is post-level, shared across targets).
- Cancel-scheduled action.
- Token-refresh flow.
- LinkedIn / TikTok / X publishing.
- Uploading directly from the media library (uploads currently originate in the post composer).