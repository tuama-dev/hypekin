# HypeKin — Plan Packages

|                             | **Free**                              | **Pro**                    | **Pro+AI**                 | **Team**              |
| --------------------------- | ------------------------------------- | -------------------------- | -------------------------- | --------------------- |
| **Price**                   | Rp0 / $0                              | Rp79.000 · $9/bln          | Rp249.000 · $25/bln        | Rp699.000 · $79/bln   |
| Connected accounts          | 2 total, any platform mix             | 10 total, any platform mix | 10 total, any platform mix | Unlimited total       |
| Posts/month                 | 15                                    | Unlimited                  | Unlimited                  | Unlimited             |
| Platforms                   | LinkedIn, Facebook, Instagram, TikTok | Same                       | Same                       | Same                  |
| Media                       | Images & video                        | Images & video             | Images & video             | Images & video        |
| Workspace members           | 1                                     | 1                          | 1                          | Unlimited, role-based |
| Multi-workspace             | —                                     | —                          | —                          | ✅                    |
| Manual scheduling           | ✅                                    | ✅                         | ✅                         | ✅                    |
| AI-recommended posting time | —                                     | —                          | —                          | ✅                    |
| AI caption/hashtag credits  | —                                     | —                          | 500/month                  | Unlimited (fair use)  |
| Approval workflow           | —                                     | —                          | —                          | ✅                    |
| Social inbox                | —                                     | —                          | —                          | ✅                    |
| Analytics                   | —                                     | —                          | —                          | —                     |
| Support                     | Email                                 | Email                      | Email                      | Email                 |

## Notes

- **Connected accounts are a single total pool, not per-platform sub-limits.** Checked against Buffer, Later, and Publer — none of them cap by platform; Buffer and Publer price/limit per connected "channel" regardless of which platform it is, Later caps by "profile," same idea. A per-platform cap (e.g. max 3 Instagram, max 2 LinkedIn) has no real precedent in the category and would only add restriction without a clear user benefit — someone running 8 Instagram accounts for different clients but nothing else shouldn't be blocked while under their total budget. Recommend staying with total-pool; revisit only if a specific platform becomes a real cost or abuse concern later.
- **Media now includes video across every tier(?)** — the app already supports it (presigned S3 upload, same flow as images), so this replaces the earlier "images only" assumption. This also resolves the earlier TikTok concern: TikTok's core format is video, so this was a blocker worth flagging before, not after.
- **Scheduling split into two separate rows, since they're different things:**
    - _Manual scheduling_ (pick a date/time yourself) — intended to be universal across every tier once the queue layer is proven working end-to-end. Not yet confirmed live in production — don't sell this until the "Schedule" button is verified to actually enqueue and fire correctly, not just render.
    - _AI-recommended posting time_ (the system suggests when to post, based on historical engagement) — genuinely Team-tier-only, but also genuinely not built yet, since it depends on `post_metrics` data that doesn't exist. Don't sell this until the underlying data pipeline is real.
- **Analytics is `—` across every tier, including Team** — not a gated feature, simply not built yet (`post_metrics` doesn't exist).
- **Multi-workspace and Approval workflow / Social inbox only exist in Team** — and Team depends on infrastructure (invite flow, queue-based scheduling) that isn't built yet. This table is the target structure, not all four tiers being purchasable today.
- **Posts/month is the intended primary Free→Pro upgrade lever**, not connected-account count — a solo user is expected to hit a post-volume ceiling more often than an account-count one.
- **Workspace members shows "1" on Free, Pro, and Pro+AI deliberately** — honest reflection that multi-membership doesn't exist yet, not an omitted differentiator.
- **Pro+AI and Team prices are carried over from an earlier draft, not re-validated** — re-run margin math (payment gateway fees, actual AI/storage cost) against these once each tier's features are actually working, before treating the prices as final.
