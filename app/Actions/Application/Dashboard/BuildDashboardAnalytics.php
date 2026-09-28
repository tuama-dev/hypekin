<?php

namespace App\Actions\Application\Dashboard;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\SocialAccountStatus;
use App\Models\Post;
use App\Models\PostMetric;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Aggregate a workspace's publishing analytics for its dashboard.
 *
 * Snapshots are cumulative counters, so every aggregate is drawn from the
 * latest snapshot per target (within the 30-day window). Because a target
 * emits at most one snapshot per day, grouping by snapshot_date yields an
 * unambiguous daily series. Platform metrics are sparse — each platform fills
 * only the keys it supports — so they are normalized: reach/impressions fall
 * back to views (TikTok has no reach) and engagements fall back to
 * likes + comments + shares when the platform does not report it directly.
 */
class BuildDashboardAnalytics
{
    public function execute(Workspace $workspace, User $user): array
    {
        $since = today()->subDays(29);

        $metrics = PostMetric::query()
            ->with('post')
            ->where('snapshot_type', 'snapshot')
            ->whereHas('post', fn ($query) => $query->where('workspace_id', $workspace->getKey()))
            ->whereDate('snapshot_date', '>=', $since)
            ->orderBy('snapshot_date')
            ->get();

        $latestPerTarget = $metrics->groupBy('post_target_id')
            ->map(fn (Collection $rows): PostMetric => $rows->last());

        return [
            'kpis' => [
                'reach' => $latestPerTarget->sum(fn (PostMetric $metric): int => $this->normalize($metric)['reach']),
                'impressions' => $latestPerTarget->sum(fn (PostMetric $metric): int => $this->normalize($metric)['impressions']),
                'engagements' => $latestPerTarget->sum(fn (PostMetric $metric): int => $this->normalize($metric)['engagements']),
                'posts_published_30d' => $workspace->posts()
                    ->where('status', PostStatus::Published)
                    ->where('created_at', '>=', $since)
                    ->count(),
                'accounts_connected' => $workspace->socialAccounts()
                    ->where('status', SocialAccountStatus::Connected)
                    ->count(),
                'unread_notifications' => $user->unreadNotifications()->count(),
            ],
            'trend' => $this->buildTrend($metrics, $since),
            'platform_mix' => $latestPerTarget->groupBy(fn (PostMetric $metric): string => $metric->platform->value)
                ->map(fn (Collection $rows): array => [
                    'platform' => $rows->first()->platform->value,
                    'label' => $rows->first()->platform->label(),
                    'reach' => $rows->sum(fn (PostMetric $metric): int => $this->normalize($metric)['reach']),
                ])
                ->sortByDesc('reach')
                ->values()
                ->all(),
            'pipeline' => collect([
                PostStatus::Published,
                PostStatus::Publishing,
                PostStatus::Scheduled,
                PostStatus::Failed,
                PostStatus::Draft,
                PostStatus::Canceled,
            ])->map(fn (PostStatus $status): array => [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => $workspace->posts()->where('status', $status)->count(),
            ])
                ->all(),
            'needs_attention' => $this->buildNeedsAttention($workspace),
            'upcoming' => $this->buildUpcoming($workspace),
            'best_post' => $this->buildBestPost($latestPerTarget),
            'onboarding' => [
                'has_accounts' => $workspace->socialAccounts()->exists(),
                'has_media' => $workspace->media()->exists(),
                'has_posts' => $workspace->posts()->exists(),
            ],
        ];
    }

    /**
     * Map a sparse platform snapshot to comparable cross-platform totals.
     *
     * @return array{reach: int, impressions: int, engagements: int}
     */
    private function normalize(PostMetric $metric): array
    {
        $data = $metric->data;

        $likes = (int) ($data['likes'] ?? 0);
        $comments = (int) ($data['comments'] ?? 0);
        $shares = (int) ($data['shares'] ?? 0);
        $views = (int) ($data['views'] ?? 0);

        $reach = (int) ($data['reach'] ?? ($metric->platform === Platform::Tiktok ? $views : 0));
        $impressions = (int) ($data['impressions'] ?? ($metric->platform === Platform::Tiktok ? $views : 0));

        return [
            'reach' => $reach,
            'impressions' => $impressions,
            'engagements' => (int) ($data['engagements'] ?? $likes + $comments + $shares),
        ];
    }

    /**
     * A continuous daily series (oldest first) for the full 30-day window.
     *
     * @return list<array{date: string, reach: int, impressions: int, engagements: int}>
     */
    private function buildTrend(Collection $metrics, CarbonInterface $since): array
    {
        $days = collect(range(0, 29))->mapWithKeys(fn (int $offset): array => [
            $since->copy()->addDays($offset)->toDateString() => [
                'date' => $since->copy()->addDays($offset)->toDateString(),
                'reach' => 0,
                'impressions' => 0,
                'engagements' => 0,
            ],
        ]);

        foreach ($days as $date => $zeroes) {
            $rows = $metrics
                ->filter(fn (PostMetric $metric): bool => $metric->snapshot_date->toDateString() === $date)
                ->values();

            if ($rows->isEmpty()) {
                continue;
            }

            $days[$date] = [
                'date' => $date,
                'reach' => $rows->sum(fn (PostMetric $metric): int => $this->normalize($metric)['reach']),
                'impressions' => $rows->sum(fn (PostMetric $metric): int => $this->normalize($metric)['impressions']),
                'engagements' => $rows->sum(fn (PostMetric $metric): int => $this->normalize($metric)['engagements']),
            ];
        }

        return $days->values()->all();
    }

    private function buildNeedsAttention(Workspace $workspace): array
    {
        $failed = PostTarget::query()
            ->where('status', PostTargetStatus::Failed)
            ->with(['post', 'socialAccount'])
            ->whereHas('post', fn ($query) => $query->where('workspace_id', $workspace->getKey()))
            ->latest()
            ->get();

        return [
            'count' => $failed->count(),
            'items' => $failed->take(5)
                ->map(fn (PostTarget $target): array => [
                    'post_id' => $target->post_id,
                    'title' => $target->title,
                    'caption' => $target->caption,
                    'platform' => [
                        'value' => $target->socialAccount->platform->value,
                        'label' => $target->socialAccount->platform->label(),
                    ],
                    'display_name' => $target->socialAccount->display_name,
                    'error_message' => $target->error_message,
                ])
                ->values()
                ->all(),
        ];
    }

    private function buildUpcoming(Workspace $workspace): array
    {
        return $workspace->posts()
            ->where('status', PostStatus::Scheduled)
            ->where('scheduled_at', '>', now())
            ->with(['targets.socialAccount'])
            ->orderBy('scheduled_at')
            ->limit(3)
            ->get()
            ->map(fn (Post $post): array => [
                'id' => $post->getKey(),
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                'title' => $post->targets->first()?->title,
                'caption' => $post->targets->first()?->caption ?? '',
                'platforms' => $post->targets
                    ->map(fn (PostTarget $target): string => $target->socialAccount->platform->value)
                    ->unique()
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, PostMetric>  $latestPerTarget
     */
    private function buildBestPost(Collection $latestPerTarget): ?array
    {
        $best = $latestPerTarget->sortByDesc(fn (PostMetric $metric): int => $this->normalize($metric)['reach'])
            ->first();

        if ($best === null) {
            return null;
        }

        $post = $best->post;
        $target = $post->targets()->first();

        return [
            'post_id' => $post->getKey(),
            'title' => $target?->title,
            'caption' => $target?->caption ?? '',
            'platform' => $best->platform->value,
            'reach' => $this->normalize($best)['reach'],
        ];
    }
}
