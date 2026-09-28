<?php

namespace App\Observers;

use App\Models\PostTarget;

class PostTargetObserver
{
    /**
     * Recompute the owning post's status after any target create or update.
     *
     * saved() covers both, so the internal pipeline never has to call
     * recalculateStatus() by hand again — target state drives post state.
     */
    public function saved(PostTarget $postTarget): void
    {
        $postTarget->post?->recalculateStatus();
    }

    /**
     * Recompute the post's status when a target is deleted, so a removed
     * target can never leave its post stuck in a derived state.
     *
     * The owning post may already be gone during a workspace-level cascade;
     * the null-safe relation guards that.
     */
    public function deleted(PostTarget $postTarget): void
    {
        $postTarget->post?->recalculateStatus();
    }
}
