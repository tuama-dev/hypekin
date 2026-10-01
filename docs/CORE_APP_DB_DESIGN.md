# Schema: `social_accounts`, `posts`, `post_targets`

Scoped to the current build step (Facebook + Instagram, queued publish, with scheduling).
Later-phase fields are marked so the schema doesn't need reshaping when those steps arrive.

Note: `social_accounts` here is the **posting** table (workspace-scoped), distinct from
`user_oauth_providers` (the login-identity table, user-scoped, unique on `provider_name + provider_id`).

---

## Timestamps and time zones

Every `timestamp` column in this schema is a MySQL `TIMESTAMP`, which MySQL converts between
`UTC` and the **session** time zone on the way in and out. `config/database.php` sets no
`timezone` on the `mysql` connection, so sessions inherit `SYSTEM` — on the development machine
that is roughly **7 hours ahead of UTC**, while the application always works in UTC.

Two consequences worth knowing before you write a query:

- **Round-tripping is safe.** A value written by PHP and read back by PHP is converted to the
  session zone on write and back to UTC on read, so it lands where it started.
- **Comparing a column against a SQL clock function is not.** `NOW()` or `CURRENT_TIMESTAMP`
  return session-zone time, so `where('attempted_at', '>', NOW() - interval 5 minute)` is hours
  out against a PHP-bound timestamp. This is why the retry cooldown is computed as
  `max(attempted_at)` in PHP and compared there, rather than with SQL date arithmetic.

Changing the connection's time zone would silently shift every row written under the old one, so
it needs a deliberate migration, not a config edit. If that ever comes up, convert to `DATETIME`
and store UTC with no conversion as the safer end state.

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

| Field                       | Type                | Notes                                                                                                                                                                                                  |
| --------------------------- | ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `id`                        | ulid/uuid, PK       |                                                                                                                                                                                                        |
| `workspace_id`              | FK → workspaces     |                                                                                                                                                                                                        |
| `created_by_user_id`        | FK → users          |                                                                                                                                                                                                        |
| `status`                    | enum                | full set: `draft` / `scheduled` / `publishing` / `published` / `failed` / `canceled` — composer writes `scheduled` (future date) or `publishing` (now); the job recalculates to `published` / `failed` |
| `scheduled_at`              | timestamp, nullable | populated for scheduled posts; publish-now leaves it null                                                                                                                                              |
| `approval_status`           | enum, nullable      | Phase 2 — leave null                                                                                                                                                                                   |
| `created_at` / `updated_at` | timestamp           |                                                                                                                                                                                                        |

No `caption`/`content` field here — captions are per-platform and live on `post_targets`.
Resist putting content directly on `posts` even with a single-platform compose form; it costs
a migration the moment a second platform is added.

---

## `post_targets`

| Field                       | Type                 | Notes                                                                                                                                                       |
| --------------------------- | -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`                        | ulid/uuid, PK        |                                                                                                                                                             |
| `post_id`                   | FK → posts           |                                                                                                                                                             |
| `social_account_id`         | FK → social_accounts |                                                                                                                                                             |
| `caption`                   | text                 | the actual content sent to the platform                                                                                                                     |
| `status`                    | enum                 | `pending` / `queued` / `published` / `failed` — composer writes `pending`; the job writes `queued`, then `published` / `failed`                             |
| `platform_post_id`          | string, nullable     | platform's ID for the published post — write back after a successful call                                                                                   |
| `published_at`              | timestamp, nullable  |                                                                                                                                                             |
| `error_message`             | text, nullable       | capture the raw failure — this is what makes failures visibly captured instead of swallowed                                                                 |
| `retry_count`               | integer, default 0   | **TikTok status polls only** — incremented by `CheckTikTokPublishStatusJob` while a submitted upload is still processing. Never touched by a user retry (that lives in `post_retry_attempts`) |
| `created_at` / `updated_at` | timestamp            |                                                                                                                                                             |

**Constraint:** unique on `(post_id, social_account_id)` — one target per post per account, so retries
can't double-publish to the same account.

---

## `post_retry_attempts`

Append-only audit of user-initiated retries. It is the single source of truth for the retry policy —
the cap is the row count for a post, the cooldown is the newest `attempted_at` plus
`retry.cooldown_seconds`, and the "last retried" affordance is the newest row. No counter columns were
added to `posts`, so there is nothing that can drift from the log.

| Field                    | Type                | Notes                                                                                     |
| ------------------------ | ------------------- | ----------------------------------------------------------------------------------------- |
| `id`                     | ulid/uuid, PK       |                                                                                           |
| `post_id`                | FK → posts          | cascade on delete; indexed with `attempted_at` for the count/latest lookups               |
| `attempted_by_user_id`   | FK → users          | cascade on delete; the workspace member who asked for it                                 |
| `attempted_legs`         | unsigned integer    | how many targets the retry actually covered                                              |
| `attempted_at`           | timestamp           | when the retry was requested — drives the cooldown                                        |
| `created_at`/`updated_at`| timestamp           | rows are inserted, never updated or deleted                                              |

Policy: `App\Actions\Application\Post\ResolvePostRetryPolicy` (cap, cooldown, eligible legs) is shared
by the retry endpoint and the post detail page, so the button state and the enforced rules cannot
disagree. Both are re-derived server-side on every request.

A leg is eligible only when it failed before any platform-side submission — a target holding a
`platform_post_id` or a `platform_upload_id` is never re-sent, because that could double-publish.

### Known gap: the submission window in `PublishPostTargetJob`

That eligibility rule is only as good as the moment `platform_upload_id` is written, and
`PublishPostTargetJob` writes it **after** the platform call returns:

```php
$publishId = $publishToTikTok->initialize($this->target);   // upload now exists on TikTok
$this->target->forceFill(['platform_upload_id' => $publishId])->save();   // ← persisted after
```

If the worker dies between those two lines (timeout, OOM, deploy mid-job), the leg looks like it never
reached the platform, which is exactly the condition the retry policy treats as safe. Consequences:

- the target is `Failed` → a user retry re-sends it and **a second upload is created on the platform**;
- the target is `Queued` → `PostTargetStatus::Queued` is written in one place and read nowhere, so the leg
  is not failed (no notification, not retry-eligible), not published, and the post stays `Publishing`
  forever. `metrics:sync-due` is the only scheduled command and does not look at it.

This predates the retry policy, but the policy's prominent one-click retry button makes the first case
reachable by users. Two possible closures, deliberately **not** taken yet:

- **at-most-once** — persist a submission intent *before* the call and treat its presence as
  not-retryable. Closes the duplicate, at the cost of a crash becoming unrecoverable without manual
  reconciliation.
- **at-least-once, safely** — send a client-supplied idempotency key with `initialize()` so a re-send
  is collapsed by the platform. The clean fix, but it depends on TikTok's Content Posting API
  supporting one.

Until then, treat `Queued` older than a few minutes as a stuck leg to be investigated by hand.

### Concurrency

The cap is a read of the audit log followed by an appended row, so two simultaneous requests would both
read "one retry left" and both consume it. `PostController::retry` therefore holds a per-post cache lock
for the whole check-and-dispatch, and the route carries a `post-retry` rate limiter keyed per
user *and* post. Both are needed: the limiter caps bursts, the lock makes the check-and-insert atomic.

---

## `settings`

Runtime-tunable **business policy**, so a future settings page can change behaviour without a deploy.
Infrastructure values (endpoints, HTTP timeouts, pagination, auth throttle, storage TTL, TikTok title
limit) deliberately stay in code and `config/`.

| Field                    | Type                | Notes                                                        |
| ------------------------ | ------------------- | ------------------------------------------------------------ |
| `id`                     | ulid/uuid, PK       |                                                              |
| `key`                    | string, unique      | dotted name, e.g. `retry.max_retries`                        |
| `value`                  | json, nullable      | scalar or structure                                          |
| `created_at`/`updated_at`| timestamp           |                                                              |

Seeded keys: `retry.max_retries` (3), `retry.cooldown_seconds` (300), `publish.tiktok_max_polls` (10),
`publish.tiktok_poll_delay_seconds` (60), `verification.resend_cooldown` (60).

Reads go through `App\Settings\Settings`, which is registered as a **container-scoped** binding:
one instance per HTTP request and per queue job, discarded between them. The policy is read from
inside publish jobs, and a cache shared across processes left a worker publishing under a policy an
operator had already changed — so the memo is scoped rather than shared, which bounds a stale read
to a single request or job. Within one lifecycle the whole map is still read once, so a page render
or job run costs one query no matter how many keys it reads.

Defaults are taken at the call site (`$settings->int('retry.max_retries', 3)`), so an unseeded
database behaves exactly like a seeded one and the fallback is visible where it is used. The model
drops the memo on every write, so a `Settings::set()` or a direct `Setting::create()` is visible to
the rest of the same request. A raw `Setting::query()->update(...)` fires no events and will not be
noticed until the lifecycle ends — route writes through `Settings::set()` to avoid that. Re-seeding
only inserts missing keys, so a tuned value survives `db:seed`.

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
deleted_at nullable, soft deletes — the row survives history/audit while the object bytes are removed
unique `(workspace_id, path)` — indexes the `complete` handshake and makes re-acknowledged uploads idempotent at the DB level

Upload flow: `POST /media/intent` → presigned PUT (browser straight to object storage) →
`POST /media/complete` (path exists check + whitelist mime + creates the row, `status: ready`).
Publishing hands the platform the object's public GET URL (`Media::publicUrl()`), never the presigned PUT URL.

Library + lifecycle:

- `GET /media` lists `MediaStatus::Ready` rows (soft-deleted excluded) paginated 24/page for Inertia
  `<InfiniteScroll>` (`Inertia::scroll`), eager-loading `uploadedBy` and a `posts` count — no N+1.
- `DELETE /media/{media}` soft-deletes the row **and** removes the object bytes, but is refused
  (error flash) while the file is attached to any post — posts need the public URL to publish/republish.
- A re-acknowledged upload whose path matches a soft-deleted row restores it instead of duplicating it.

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

No automatic retries: a publish call that succeeded remotely but failed locally would double-post, so the
job never auto-retries; `error_message` captures the raw platform error for manual inspection. The TikTok
leg is the exception that needs one — it is a *poll*, not a re-send, so `CheckTikTokPublishStatusJob`
re-queues itself against the already-submitted `platform_upload_id` until TikTok reports a terminal
state, bounded by `publish.tiktok_max_polls` (`post_targets.retry_count`).

Retrying a failed post is user-initiated and lands in `post_retry_attempts`: the eligible legs are reset
to `pending` and re-dispatched, the attempt row is appended, and the cap/cooldown from `retry.*` block
the next one.
