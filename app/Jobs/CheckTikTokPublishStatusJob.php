<?php

namespace App\Jobs;

use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use App\Notifications\PostTargetFailedNotification;
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

    public const POLL_DELAY_SECONDS = 60;

    private const MAX_POLLS = 10;

    public function __construct(public PostTarget $target) {}

    /**
     * Poll TikTok until the registered upload reaches a terminal state.
     *
     * Control is passed back to this job (with a delay) while TikTok is still
     * processing, so a crashed worker never double-publishes — the original
     * init request already submitted the post.
     */
    public function handle(): void
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
            $this->requeueOrFail('TikTok status check failed: '.$exception->getMessage());

            return;
        }

        $status = $response->json('data.status');

        if ($response->failed() || $status === null) {
            $this->requeueOrFail($response->json('error.message') ?? 'TikTok did not return a publish status.');

            return;
        }

        match ($status) {
            'PUBLISH_COMPLETE' => $this->markPublished($response->json('data.publically_available_post_id')),
            'FAILED', 'PUBLISH_FAILED' => $this->markFailed($response->json('data.fail_reason')),
            default => $this->requeueOrFail('TikTok is still processing the post.'),
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

    private function requeueOrFail(string $reason): void
    {
        if ($this->target->retry_count >= self::MAX_POLLS) {
            $this->markFailed($reason.' Timing out after '.self::MAX_POLLS.' checks.');

            return;
        }

        $this->target->increment('retry_count');
        self::dispatch($this->target)->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));
    }
}
