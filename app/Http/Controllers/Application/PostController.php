<?php

namespace App\Http\Controllers\Application;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\SocialAccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\StorePostRequest;
use App\Jobs\PublishPostTargetJob;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function index(Workspace $workspace): Response
    {
        return Inertia::render('Application/Posts/Index', [
            'posts' => $workspace->posts()
                ->with(['targets.socialAccount', 'media'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (Post $post): array => [
                    'id' => $post->getKey(),
                    'status' => [
                        'value' => $post->status->value,
                        'label' => $post->status->label(),
                    ],
                    'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                    'created_at' => $post->created_at?->toIso8601String(),
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
                        ])
                        ->values(),
                    'media' => $post->media
                        ->map(fn (Media $media): array => [
                            'id' => $media->getKey(),
                            'url' => $media->publicUrl(),
                        ])
                        ->values(),
                ])
                ->values(),
        ]);
    }

    public function create(Workspace $workspace): Response
    {
        return Inertia::render('Application/Posts/Create', [
            'publishableAccounts' => $workspace->socialAccounts()
                ->where('status', SocialAccountStatus::Connected->value)
                ->whereIn('platform', [Platform::Facebook->value, Platform::Instagram->value])
                ->orderBy('display_name')
                ->get()
                ->map(fn (SocialAccount $account): array => [
                    'id' => $account->getKey(),
                    'platform' => [
                        'value' => $account->platform->value,
                        'label' => $account->platform->label(),
                    ],
                    'display_name' => $account->display_name,
                ])
                ->values(),
        ]);
    }

    public function store(StorePostRequest $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->safe();

        $post = DB::transaction(function () use ($request, $workspace, $data): Post {
            $post = $workspace->posts()->create([
                'created_by_user_id' => $request->user()->getKey(),
                'status' => $data->has('scheduled_at') ? PostStatus::Scheduled : PostStatus::Publishing,
                'scheduled_at' => $data->scheduled_at ?? null,
            ]);

            foreach ($data->targets as $accountId) {
                $post->targets()->create([
                    'social_account_id' => $accountId,
                    'caption' => $data->caption,
                    'status' => PostTargetStatus::Pending,
                ]);
            }

            if ($data->media_id !== null) {
                $post->media()->attach($data->media_id, ['position' => 0]);
            }

            return $post;
        });

        foreach ($post->targets()->get() as $target) {
            PublishPostTargetJob::dispatch($target)
                ->delay($post->scheduled_at ?? now());
        }

        return redirect()
            ->route('workspace.posts', ['workspace' => $workspace])
            ->with('flash', [
                'success' => $post->scheduled_at !== null ? 'Post scheduled.' : 'Post queued for publishing.',
            ]);
    }
}
