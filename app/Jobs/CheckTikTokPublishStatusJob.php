<?php

namespace App\Jobs;

use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use App\Notifications\PostTargetFailedNotification;
use App\Settings\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class CheckTikTokPublishStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const API_BASE = 'https://open.tiktokapis.com/v2';

    public function __construct(public PostTarget $target) {}

    /**
     * Poll TikTok until the registered upload reaches a terminal state.
     *
     * Control is passed back to this job (with a delay) while TikTok is still
     * processing, so a crashed worker never double-publishes — the original
     * init request already submitted the post.
     */
    public function handle(Settings $settings): void
    {
        if ($this->target->status === PostTargetStatus::Published
            || $this->target->platform_post_id !== null) {
            return;
        }

        if ($this->target->platform_upload_id === null) {
            $this->failTarget('TikTok publish id is missing.');

            return;
        }

        try {
            $response = Http::withToken($this->target->socialAccount->access_token ?? '')
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post(self::API_BASE.'/post/publish/status/fetch/', [
                    'publish_id' => $this->target->platform_upload_id,
                ]);
        } catch (Throwable $exception) {
            $this->requeueOrFail('TikTok status check failed: '.$exception->getMessage(), $settings);

            return;
        }

        $status = $response->json('data.status');

        if ($response->failed() || $status === null) {
            $this->requeueOrFail($response->json('error.message') ?? 'TikTok did not return a publish status.', $settings);

            return;
        }

        match ($status) {
            'PUBLISH_COMPLETE' => $this->markPublished($response->json('data.publically_available_post_id')),
            'FAILED', 'PUBLISH_FAILED' => $this->markFailed($response->json('data.fail_reason')),
            default => $this->requeueOrFail('TikTok is still processing the post.', $settings),
        };
    }

    private function markPublished(?string $postId): void
    {
        if ($postId === null || $postId === '') {
            $this->markFailed('TikTok did not return the published post id.');

            return;
        }

        $this->target->forceFill([
            'status' => PostTargetStatus::Published,
            'platform_post_id' => $postId,
            'published_at' => now(),
            'error_message' => null,
        ])->save();
    }

    private function markFailed(?string $reason): void
    {
        $this->failTarget(Str::limit($reason ?? 'TikTok rejected the post.', 500));
    }

    private function failTarget(string $message): void
    {
        $this->target->forceFill([
            'status' => PostTargetStatus::Failed,
            'error_message' => $message,
        ])->save();

        $this->target->post->createdBy?->notify(new PostTargetFailedNotification($this->target));
    }

    /**
     * Give the poll another go later, or give up once the poll budget is spent.
     *
     * The budget and interval are tunable settings rather than constants: TikTok
     * processing time varies by media size, and operators need to widen the
     * window without a deploy. `post_targets.retry_count` stays the poll
     * counter — it belongs to this pipeline alone, and is never touched by a
     * user-initiated post retry.
     */
    private function requeueOrFail(string $reason, Settings $settings): void
    {
        $maxPolls = $settings->int('publish.tiktok_max_polls', 10);
        $pollDelay = $settings->int('publish.tiktok_poll_delay_seconds', 60);

        if ($this->target->retry_count >= $maxPolls) {
            $this->markFailed($reason.' Timing out after '.$maxPolls.' checks.');

            return;
        }

        $this->target->increment('retry_count');
        self::dispatch($this->target)->delay(now()->addSeconds($pollDelay));
    }
}
