<?php

namespace App\Jobs;

use App\Actions\Application\Post\Exceptions\TransientMetricsException;
use App\Actions\Application\Post\FetchFacebookMetricsAction;
use App\Actions\Application\Post\FetchInstagramMetricsAction;
use App\Actions\Application\Post\FetchLinkedInMetricsAction;
use App\Actions\Application\Post\FetchTikTokMetricsAction;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\PostMetric;
use App\Models\PostTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncPostMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  PostTarget  $target  A published target whose decay schedule says
     *                              it is due for a snapshot. Which targets are
     *                              due is decided by SyncDuePostMetrics.
     */
    public function __construct(public PostTarget $target) {}

    /**
     * Snapshot today's engagement for the single target.
     *
     * A failed fetch records an error snapshot instead, and the guards below
     * then leave that target alone on later runs — a deleted post or revoked
     * token is surfaced once and never polled again, instead of silently
     * retrying forever.
     */
    public function handle(
        FetchFacebookMetricsAction $fetchFacebook,
        FetchInstagramMetricsAction $fetchInstagram,
        FetchLinkedInMetricsAction $fetchLinkedIn,
        FetchTikTokMetricsAction $fetchTikTok,
    ): void {
        if ($this->target->status !== PostTargetStatus::Published
            || $this->target->platform_post_id === null
            || $this->latestSnapshot()?->snapshot_type === 'error') {
            return;
        }

        $today = now()->toDateString();

        if ($this->hasSnapshot($today)) {
            return;
        }

        $platform = $this->target->socialAccount->platform;

        try {
            $data = match ($platform) {
                Platform::Facebook => $fetchFacebook->fetch($this->target),
                Platform::Instagram => $fetchInstagram->fetch($this->target),
                Platform::LinkedIn => $fetchLinkedIn->fetch($this->target),
                Platform::Tiktok => $fetchTikTok->fetch($this->target),
            };
        } catch (TransientMetricsException) {
            // A network blip or a noisy platform response (429, 5xx): record
            // nothing so the "no snapshot today" guard keeps this target due,
            // and the hourly scheduler retries it for the rest of the day.
            return;
        } catch (Throwable $exception) {
            PostMetric::create([
                'post_id' => $this->target->post_id,
                'post_target_id' => $this->target->getKey(),
                'platform' => $platform->value,
                'snapshot_type' => 'error',
                'snapshot_date' => $today,
                'data' => ['error' => $exception->getMessage()],
            ]);

            return;
        }

        PostMetric::firstOrCreate(
            [
                'post_id' => $this->target->post_id,
                'post_target_id' => $this->target->getKey(),
                'platform' => $platform->value,
                'snapshot_date' => $today,
            ],
            ['data' => $data],
        );
    }

    private function hasSnapshot(string $today): bool
    {
        return PostMetric::query()
            ->where('post_target_id', $this->target->getKey())
            ->whereDate('snapshot_date', $today)
            ->exists();
    }

    private function latestSnapshot(): ?PostMetric
    {
        return PostMetric::query()
            ->where('post_target_id', $this->target->getKey())
            ->orderByDesc('snapshot_date')
            ->first();
    }
}
