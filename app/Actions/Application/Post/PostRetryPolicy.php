<?php

namespace App\Actions\Application\Post;

use Carbon\CarbonInterface;

/**
 * The derived retry policy for one post, in the shape both the retry endpoint
 * and the post detail page need.
 *
 * Derived rather than stored: every field is a function of the post's
 * `post_retry_attempts` rows and the `retry.*` settings, so there is no counter
 * that can drift from the audit log.
 */
class PostRetryPolicy
{
    public function __construct(
        /** How many targets are currently retryable. */
        public readonly int $eligibleLegs,
        /**
         * How many targets have failed at all, retryable or not.
         *
         * Distinct from eligibleLegs so the page can tell "nothing failed" from
         * "everything that failed was already submitted to the platform" — both
         * have zero retryable legs, but only the second is worth explaining.
         */
        public readonly int $failedLegs,
        /** Retries remaining under the cap, never negative. */
        public readonly int $retriesLeft,
        /** Seconds until the next attempt is allowed; 0 when one is allowed now. */
        public readonly int $waitSeconds,
        /** Whether the cap has been reached. */
        public readonly bool $exhausted,
        /** When the post was last retried, or null if it never was. */
        public readonly ?CarbonInterface $lastRetriedAt,
        /**
         * The earliest instant a retry may be started again, or null if the post
         * was never retried. An instant rather than a remaining count, so the
         * browser can compare it against its own clock without the two clocks
         * disagreeing about when now is. Already in the past once the cooldown
         * has elapsed, which the page reads as "allowed".
         */
        public readonly ?CarbonInterface $retryAvailableAt,
    ) {}

    /**
     * Whether a retry can be started right now.
     */
    public function canRetry(): bool
    {
        return $this->eligibleLegs > 0 && ! $this->exhausted && $this->waitSeconds === 0;
    }
}
