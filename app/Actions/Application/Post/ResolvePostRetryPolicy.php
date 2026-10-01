<?php

namespace App\Actions\Application\Post;

use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Settings\Settings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Resolve who may retry a post, and how many of its targets that would cover.
 *
 * The policy lives here rather than in the controller so the retry endpoint and
 * the post detail page (which renders the retry button state) read the same
 * rules from one place, and so the rules stay server-side.
 *
 * Two things decide it:
 *   - the `post_retry_attempts` audit log for this post: how many retries have
 *     been spent, and when the last one happened;
 *   - the `retry.max_retries` / `retry.cooldown_seconds` settings.
 *
 * A leg is retryable only when it failed before any platform-side submission:
 * a target holding a platform post id or a TikTok upload id is never re-sent,
 * because that could double-publish the same content. That rule lives on
 * PostTarget as the retryable() scope and isRetryable().
 */
class ResolvePostRetryPolicy
{
    /**
     * Promote the scoped Settings instance to a property: resolve() reads two
     * keys and may run several times per request, and the scoped binding means
     * all of them share one memoized read of the table.
     */
    public function __construct(private readonly Settings $settings) {}

    /**
     * The post's failed targets that never reached the platform, so they are
     * safe to re-send.
     *
     * @return Collection<int, PostTarget>
     */
    public function eligibleTargets(Post $post): Collection
    {
        return $post->targets()->retryable()->get();
    }

    /**
     * Derive the policy for a post.
     *
     * @param  int|null  $eligibleLegs  Pre-computed eligible target count, to
     *                                  avoid re-querying when the caller
     *                                  already holds the targets.
     * @param  int|null  $failedLegs  Pre-computed total failed target count,
     *                                for the same reason.
     */
    public function resolve(Post $post, ?int $eligibleLegs = null, ?int $failedLegs = null): PostRetryPolicy
    {
        $eligibleLegs ??= $this->countEligible($post);
        $failedLegs ??= $this->countFailed($post);

        $maxRetries = $this->settings->int('retry.max_retries', 3);
        $cooldown = $this->settings->int('retry.cooldown_seconds', 300);

        [$attempts, $lastRetriedAt] = $this->attemptSummary($post);

        $waitSeconds = $lastRetriedAt === null
            ? 0
            : max(0, (int) ceil(now()->diffInSeconds($lastRetriedAt->addSeconds($cooldown))));

        return new PostRetryPolicy(
            eligibleLegs: $eligibleLegs,
            failedLegs: $failedLegs,
            retriesLeft: max(0, $maxRetries - $attempts),
            waitSeconds: $waitSeconds,
            exhausted: $attempts >= $maxRetries,
            lastRetriedAt: $lastRetriedAt,
            retryAvailableAt: $lastRetriedAt?->addSeconds($cooldown),
        );
    }

    /**
     * Count failed legs, reusing the eager-loaded targets when the caller
     * already has them so the post page does not re-query what it loaded.
     */
    private function countFailed(Post $post): int
    {
        if (! $post->relationLoaded('targets')) {
            return $post->targets()->where('status', PostTargetStatus::Failed)->count();
        }

        return $post->targets->filter(fn (PostTarget $target): bool => $target->status === PostTargetStatus::Failed)->count();
    }

    /**
     * Count eligible legs, reusing the eager-loaded targets when the caller
     * already has them so the post page does not re-query what it loaded.
     */
    private function countEligible(Post $post): int
    {
        if (! $post->relationLoaded('targets')) {
            return $this->eligibleTargets($post)->count();
        }

        return $post->targets->filter(fn (PostTarget $target): bool => $target->isRetryable())->count();
    }

    /**
     * The post's attempt count and most recent attempt in one query.
     *
     * The aggregate deliberately reads max(attempted_at) rather than comparing
     * against a SQL clock: this database session runs on a server timezone that
     * is offset from the app's, so a raw NOW() would be hours away from the
     * timestamps Laravel wrote and bound. Aggregating a stored column has no
     * such problem — MySQL hands the value back as the same wall clock PHP
     * persisted it as — so parsing it in the app timezone is correct.
     *
     * @return array{int, CarbonInterface|null}
     */
    private function attemptSummary(Post $post): array
    {
        $summary = $post->retryAttempts()
            ->selectRaw('count(*) as attempts, max(attempted_at) as last_attempted_at')
            ->first();

        if ($summary === null) {
            return [0, null];
        }

        return [
            (int) $summary->getAttribute('attempts'),
            $summary->getAttribute('last_attempted_at') === null
                ? null
                : Date::parse($summary->getAttribute('last_attempted_at')),
        ];
    }
}
