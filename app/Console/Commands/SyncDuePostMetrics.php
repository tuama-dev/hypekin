<?php

namespace App\Console\Commands;

use App\Enums\PostTargetStatus;
use App\Jobs\SyncPostMetricsJob;
use App\Models\PostMetric;
use App\Models\PostTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('metrics:sync-due')]
#[Description('Dispatch a metrics snapshot job for every published post target due today.')]
class SyncDuePostMetrics extends Command
{
    /**
     * Engagement decays quickly, so a published target is only polled on a
     * handful of discrete offsets after publishing before it drops out of the
     * window entirely.
     *
     * @var list<int>
     */
    private const SNAPSHOT_OFFSETS = [0, 1, 3, 7];

    /**
     * Dispatch one SyncPostMetricsJob per target whose decay schedule says it
     * is due for a snapshot today. Nothing is re-queued here: a target that
     * fails stops being dispatched because its latest snapshot is an error.
     */
    public function handle(): void
    {
        PostTarget::query()
            ->with(['socialAccount'])
            ->where('status', PostTargetStatus::Published)
            ->whereNotNull('platform_post_id')
            ->whereNotNull('published_at')
            ->orderBy('id')
            ->chunkById(200, function (Collection $targets): void {
                foreach ($targets as $target) {
                    if ($this->isDueToday($target)) {
                        SyncPostMetricsJob::dispatch($target);
                    }
                }
            });
    }

    private function isDueToday(PostTarget $target): bool
    {
        $publishedAt = $target->published_at;

        if ($publishedAt === null) {
            return false;
        }

        $today = now()->toDateString();

        $withinWindow = collect(self::SNAPSHOT_OFFSETS)
            ->contains(fn (int $offset): bool => $publishedAt->copy()->addDays($offset)->toDateString() === $today);

        if (! $withinWindow) {
            return false;
        }

        return ! $this->hasSnapshot($target, $today) && ! $this->hasTerminalError($target);
    }

    private function hasSnapshot(PostTarget $target, string $today): bool
    {
        return PostMetric::query()
            ->where('post_target_id', $target->getKey())
            ->whereDate('snapshot_date', $today)
            ->exists();
    }

    private function hasTerminalError(PostTarget $target): bool
    {
        return PostMetric::query()
            ->where('post_target_id', $target->getKey())
            ->orderByDesc('snapshot_date')
            ->first()?->snapshot_type === 'error';
    }
}
