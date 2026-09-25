# Schema: `social_accounts`, `posts`, `post_targets`

Scoped to the current build step (Facebook + Instagram, queued publish, with scheduling).
Later-phase fields are marked so the schema doesn't need reshaping when those steps arrive.

Note: `social_accounts` here is the **posting** table (workspace-scoped), distinct from
`user_oauth_providers` (the login-identity table, user-scoped, unique on `provider_name + provider_id`).

---

## `social_accounts`

| Field                       | Type                      | Notes                                                                                              |
| --------------------------- | ------------------------- | -------------------------------------------------------------------------------------------------- |
| `id`                        | ulid/uuid, PK             |                                                                                                    |
| `workspace_id`              | FK → workspaces           | scoped to workspace, not user                                                                      |
| `platform`                  | enum                      | `facebook` / `instagram` for now; `linkedin` / `tiktok` / `threads` / `x` added as each is built   |
| `external_account_id`       | string                    | the ID the platform gives you for the connected account/page                                       |
| `display_name`              | string                    | shown in UI so users know which account is connected                                               |
| `access_token`              | text, **encrypted cast**  |                                                                                                    |
| `refresh_token`             | text, encrypted, nullable | check whether the platform issues one                                                              |
| `token_expires_at`          | timestamp, nullable       |                                                                                                    |
| `status`                    | enum                      | `connected` / `expired` / `revoked`                                                                |
| `connected_by_user_id`      | FK → users, **nullable**  | audit only, not access control — a staff tool or agency user may connect on the workspace's behalf |
| `connected_at`              | timestamp, nullable       | when the account was connected                                                                     |
| `created_at` / `updated_at` | timestamp                 |                                                                                                    |

**Constraint:** unique on `(workspace_id, external_account_id)` — the same platform account cannot be
connected twice within one workspace (relevant when an agency attaches many accounts).

**Deferred — add only when building X:**

- `is_byok` (boolean)
- `byok_api_key` (text, encrypted)

---

## `posts`

| Field                       | Type                | Notes                                                                                                                                                                           |
| --------------------------- | ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`                        | ulid/uuid, PK       |                                                                                                                                                                                 |
| `workspace_id`              | FK → workspaces     |                                                                                                                                                                                 |
| `created_by_user_id`        | FK → users          |                                                                                                                                                                                 |
| `status`                    | enum                | full set: `draft` / `scheduled` / `publishing` / `published` / `failed` / `canceled` — composer writes `scheduled` (future date) or `publishing` (now); the job recalculates to `published` / `failed`                                                                                                   |
| `scheduled_at`              | timestamp, nullable | populated for scheduled posts; publish-now leaves it null                                                                                                                 |
| `approval_status`           | enum, nullable      | Phase 2 — leave null                                                                                                                                                            |
| `created_at` / `updated_at` | timestamp           |                                                                                                                                                                                 |

No `caption`/`content` field here — captions are per-platform and live on `post_targets`.
Resist putting content directly on `posts` even with a single-platform compose form; it costs
a migration the moment a second platform is added.

---

## `post_targets`

| Field                       | Type                 | Notes                                                                                       |
| --------------------------- | -------------------- | ------------------------------------------------------------------------------------------- |
| `id`                        | ulid/uuid, PK        |                                                                                             |
| `post_id`                   | FK → posts           |                                                                                             |
| `social_account_id`         | FK → social_accounts |                                                                                             |
| `caption`                   | text                 | the actual content sent to the platform                                                     |
| `status`                    | enum                 | `pending` / `queued` / `published` / `failed` — composer writes `pending`; the job writes `queued`, then `published` / `failed`                                               |
| `platform_post_id`          | string, nullable     | platform's ID for the published post — write back after a successful call                   |
| `published_at`              | timestamp, nullable  |                                                                                             |
| `error_message`             | text, nullable       | capture the raw failure — this is what makes failures visibly captured instead of swallowed |
| `retry_count`               | integer, default 0   | not incremented yet — the job is idempotent (skips targets already published) and deliberately does not auto-retry, since publishing FB/IG isn't idempotent                       |
| `created_at` / `updated_at` | timestamp            |                                                                                             |

**Constraint:** unique on `(post_id, social_account_id)` — one target per post per account, so retries
can't double-publish to the same account.

---

## `media`

id ulid/uuid, PK
workspace_id FK → workspaces library scope, not per-post
uploaded_by_user_id FK → users
disk string 's3' (config `media.disk`) — which storage disk, matters once you're not single-disk anymore
path string the object key — server-generated, never client-supplied
type enum image / video
mime_type string one of the whitelist in `config/media.php` (jpeg / png / webp / gif / mp4)
size_bytes integer
width, height integer, nullable
duration_seconds integer, nullable video only
status enum pending / ready / failed
created_at / updated_at

Upload flow: `POST /media/intent` → presigned PUT (browser straight to object storage) →
`POST /media/complete` (path exists check + whitelist mime + creates the row, `status: ready`).
Publishing hands the platform the object's public GET URL (`Media::publicUrl()`), never the presigned PUT URL.

---

## `post_media`

post_id FK → posts
media_id FK → media
position integer

No surrogate `id` — composite primary key on `(post_id, media_id)` (one image slot per post per media; a pure pivot).

---

## Implementation note

Publishing is queued — "Publish now" and "Schedule" are the same code path (`PublishPostTargetJob`),
differing only by `->delay($scheduled_at)` (database queue, `QUEUE_CONNECTION=database`):

1. Composer request: create `posts` row (`status: scheduled`|`publishing`) → create `post_targets` rows
   (`status: pending`) → attach media via `post_media` → dispatch one `PublishPostTargetJob` per target.
2. Job `handle()`: idempotency guard (skip if already published) → mark the target `queued` → call the
   platform (`PublishToFacebookAction` / `PublishToInstagramAction`) → write back `status`
   (`published`/`failed`), `platform_post_id`, `error_message`, `published_at` → recalculate the post's
   status (`published`/`failed`/`publishing`).

Facebook: single POST to `/{page_id}/feed` (text) or `/{page_id}/photos` (image + caption).
Instagram: two-step `/{ig_user_id}/media` (image URL + caption) then `/{ig_user_id}/media_publish`.

No retries: a publish call that succeeded remotely but failed locally would double-post, so the job never
auto-retries; `error_message` captures the raw platform error for manual inspection.
