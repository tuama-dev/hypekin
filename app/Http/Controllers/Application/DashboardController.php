<?php

namespace App\Http\Controllers\Application;

use App\Actions\Application\Dashboard\BuildDashboardAnalytics;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostMetric;
use App\Models\PostTarget;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, Workspace $workspace): Response
    {
        $posts = $workspace->posts()
            ->with([
                'targets.socialAccount',
                'targets.metrics' => fn ($query) => $query
                    ->where('snapshot_type', 'snapshot')
                    ->orderBy('snapshot_date'),
                'media',
            ])
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(fn (Post $post): array => [
                'id' => $post->getKey(),
                'status' => [
                    'value' => $post->status->value,
                    'label' => $post->status->label(),
                ],
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                'created_at' => $post->created_at?->toIso8601String(),
                'caption' => $post->targets->first()?->caption ?? '',
                'title' => $post->targets->first()?->title,
                'targets' => $post->targets
                    ->map(fn (PostTarget $target): array => [
                        'id' => $target->getKey(),
                        'platform' => [
                            'value' => $target->socialAccount->platform->value,
                            'label' => $target->socialAccount->platform->label(),
                        ],
                        'display_name' => $target->socialAccount->display_name,
                        'status' => [
                            'value' => $target->status->value,
                            'label' => $target->status->label(),
                        ],
                        'error_message' => $target->error_message,
                        'metrics' => $target->metrics
                            ->map(fn (PostMetric $metric): array => [
                                'id' => $metric->getKey(),
                                'snapshot_date' => $metric->snapshot_date->toDateString(),
                                'data' => $metric->data,
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
                'media' => $post->media
                    ->map(fn (Media $media): array => [
                        'id' => $media->getKey(),
                        'url' => $media->publicUrl(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return Inertia::render('Application/Dashboard', [
            'recentPosts' => $posts,
            'analytics' => app(BuildDashboardAnalytics::class)->execute($workspace, $request->user()),
        ]);
    }
}
