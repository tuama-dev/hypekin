# Schema: `social_accounts`, `posts`, `post_targets`

Scoped to the current build step (LinkedIn, synchronous publish, no scheduling yet).
Later-phase fields are marked so the schema doesn't need reshaping when those steps arrive.

Note: `social_accounts` here is the **posting** table (workspace-scoped), distinct from
`user_oauth_providers` (the login-identity table, user-scoped, unique on `provider_name + provider_id`).

---

## `social_accounts`

| Field                       | Type                      | Notes                                                                                            |
| --------------------------- | ------------------------- | ------------------------------------------------------------------------------------------------ |
| `id`                        | ulid/uuid, PK             |                                                                                                  |
| `workspace_id`              | FK → workspaces           | scoped to workspace, not user                                                                    |
| `platform`                  | enum                      | `linkedin` for now; `instagram` / `facebook` / `tiktok` / `threads` / `x` added as each is built |
| `external_account_id`       | string                    | the ID the platform gives you for the connected account/page                                     |
| `display_name`              | string                    | shown in UI so users know which account is connected                                             |
| `access_token`              | text, **encrypted cast**  |                                                                                                  |
| `refresh_token`             | text, encrypted, nullable | check whether the platform issues one                                                            |
| `token_expires_at`          | timestamp, nullable       |                                                                                                  |
| `status`                    | enum                      | `connected` / `expired` / `revoked`                                                              |
| `connected_by_user_id`      | FK → users, **nullable**     | audit only, not access control — a staff tool or agency user may connect on the workspace's behalf |
| `connected_at`              | timestamp, nullable          | when the account was connected                                                                    |
| `created_at` / `updated_at` | timestamp                    |                                                                                                   |

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
| `status`                    | enum                | full set: `draft` / `scheduled` / `publishing` / `published` / `failed` / `canceled` — currently only `published`/`failed` get written, immediately, since there's no queue yet |
| `scheduled_at`              | timestamp, nullable | column exists now; not populated or acted on until the queue step                                                                                                               |
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
| `status`                    | enum                 | `pending` / `queued` / `published` / `failed`                                               |
| `platform_post_id`          | string, nullable     | platform's ID for the published post — write back after a successful call                   |
| `published_at`              | timestamp, nullable  |                                                                                             |
| `error_message`             | text, nullable       | capture the raw failure — this is what makes failures visibly captured instead of swallowed |
| `retry_count`               | integer, default 0   | unused without a queue yet, but costs nothing to have now                                   |
| `created_at` / `updated_at` | timestamp            |                                                                                            |

**Constraint:** unique on `(post_id, social_account_id)` — one target per post per account, so retries
can't double-publish to the same account.

---

## Implementation note

Since publishing is synchronous right now (no queue), the flow in one request is:

1. Create `posts` row
2. Create `post_targets` row (`status: pending`)
3. Call the platform's publish endpoint
4. Update that same `post_targets` row — `status`, `platform_post_id`, `error_message` — based on the result

This is the exact shape that later moves inside a queued job's `handle()` — nothing about
these fields changes when scheduling is added.
