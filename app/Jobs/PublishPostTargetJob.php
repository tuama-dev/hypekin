<?php

namespace App\Jobs;

use App\Actions\Application\Post\PublishToFacebookAction;
use App\Actions\Application\Post\PublishToInstagramAction;
use App\Actions\Application\Post\PublishToLinkedInAction;
use App\Actions\Application\Post\PublishToTikTokAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use App\Notifications\PostTargetFailedNotification;
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

    /**
     * @param  PostTarget  $target  The target to publish.
     * @param  int|null  $scheduleVersion  The post's schedule_version when this
     *                                     job was dispatched. When the post is rescheduled afterwards the version
     *                                     no longer matches, so this (now stale) job must exit without touching
     *                                     the target — its replacement carries the new version and delay.
     */
    public function __construct(
        public PostTarget $target,
        private readonly ?int $scheduleVersion = null,
    ) {}

    /**
     * Publish a single post target to its platform.
     */
    public function handle(
        PublishToFacebookAction $publishToFacebook,
        PublishToInstagramAction $publishToInstagram,
        PublishToLinkedInAction $publishToLinkedIn,
        PublishToTikTokAction $publishToTikTok,
    ): void {
        // A reschedule bumped the post's schedule_version after this job was
        // queued. The job's original delay target date is stale — skip it so
        // it can never double-publish; the reschedule dispatched replacements.
        if ($this->scheduleVersion !== null
            && $this->target->post->getAttribute('schedule_version') !== $this->scheduleVersion) {
            return;
        }

        // Retries must never double-publish: once a platform id exists, the
        // target is already published, or a TikTok upload has been submitted
        // there is nothing left to do.
        if ($this->target->platform_post_id !== null
            || $this->target->status === PostTargetStatus::Published
            || $this->target->platform_upload_id !== null) {
            return;
        }

        // A scheduled post leaves Scheduled only here, at the moment its first
        // delayed job fires — recalculateStatus() never derives past that state.
        if ($this->target->post->status === PostStatus::Scheduled) {
            $this->target->post->forceFill(['status' => PostStatus::Publishing])->save();
        }

        $this->target->forceFill(['status' => PostTargetStatus::Queued])->save();

        try {
            if ($this->target->socialAccount->platform === Platform::Tiktok) {
                $publishId = $publishToTikTok->initialize($this->target);

                $this->target->forceFill([
                    'platform_upload_id' => $publishId,
                    'error_message' => null,
                ])->save();

                CheckTikTokPublishStatusJob::dispatch($this->target)
                    ->delay(now()->addSeconds(CheckTikTokPublishStatusJob::POLL_DELAY_SECONDS));

                return;
            }

            $platformPostId = match ($this->target->socialAccount->platform) {
                Platform::Facebook => $publishToFacebook->publish($this->target),
                Platform::Instagram => $publishToInstagram->publish($this->target),
                Platform::LinkedIn => $publishToLinkedIn->publish($this->target),
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

            $this->target->post->createdBy?->notify(
                new PostTargetFailedNotification($this->target),
            );
        }
    }
}
