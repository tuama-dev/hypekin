<?php

namespace App\Jobs;

use App\Actions\Application\Post\PublishToFacebookAction;
use App\Actions\Application\Post\PublishToInstagramAction;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PublishPostTargetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public PostTarget $target) {}

    /**
     * Publish a single post target to its platform.
     */
    public function handle(
        PublishToFacebookAction $publishToFacebook,
        PublishToInstagramAction $publishToInstagram,
    ): void {
        // Retries must never double-publish: once a platform id exists or the
        // target is already published there is nothing left to do.
        if ($this->target->platform_post_id !== null
            || $this->target->status === PostTargetStatus::Published) {
            $this->target->post->recalculateStatus();

            return;
        }

        $this->target->forceFill(['status' => PostTargetStatus::Queued])->save();

        try {
            $platformPostId = match ($this->target->socialAccount->platform) {
                Platform::Facebook => $publishToFacebook->publish($this->target),
                Platform::Instagram => $publishToInstagram->publish($this->target),
                default => throw new RuntimeException('Publishing to this platform is not supported yet.'),
            };

            $this->target->forceFill([
                'status' => PostTargetStatus::Published,
                'platform_post_id' => $platformPostId,
                'published_at' => now(),
                'error_message' => null,
            ])->save();
        } catch (Throwable $exception) {
            $this->target->forceFill([
                'status' => PostTargetStatus::Failed,
                'error_message' => Str::limit($exception->getMessage(), 500),
            ])->save();
        }

        $this->target->post->recalculateStatus();
    }
}
