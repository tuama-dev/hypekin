<?php

namespace App\Http\Controllers\Application;

use App\Actions\Application\Post\ResolvePostRetryPolicy;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\SocialAccountStatus;
use App\Http\Controllers\Application\Concerns\FilterableAccounts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\StorePostRequest;
use App\Http\Requests\Post\UpdatePostScheduleRequest;
use App\Jobs\PublishPostTargetJob;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostMetric;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    use FilterableAccounts;

    /**
     * List the workspace's posts, newest first, optionally narrowed to a
     * status, an account, and/or a caption/title search term.
     */
    public function index(Request $request, Workspace $workspace): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(PostStatus::class)],
            'search' => ['nullable', 'string', 'max:120'],
            'account' => ['nullable', 'string', 'max:40'],
        ]);

        $status = isset($validated['status'])
            ? PostStatus::from($validated['status'])
            : null;
        $search = trim((string) ($validated['search'] ?? ''));
        $accountId = $validated['account'] ?? null;

        $posts = $workspace->posts()
            ->with(['targets.socialAccount', 'media'])
            ->when(
                $status !== null,
                fn ($query) => $query->where('status', $status->value),
            )
            ->when(
                $accountId !== null,
                fn ($query) => $query->whereHas(
                    'targets',
                    fn ($targetQuery) => $targetQuery->where(
                        'social_account_id',
                        $accountId,
                    ),
                ),
            )
            ->when(
                $search !== '',
                fn ($query) => $query->whereHas(
                    'targets',
                    fn ($targetQuery) => $targetQuery->where(
                        fn ($nested) => $nested
                            ->where('caption', 'like', '%'.$search.'%')
                            ->orWhere('title', 'like', '%'.$search.'%'),
                    ),
                ),
            )
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
                    ])
                    ->values(),
                'media' => $post->media
                    ->map(fn (Media $media): array => [
                        'id' => $media->getKey(),
                        'url' => $media->publicUrl(),
                    ])
                    ->values(),
            ])
            ->values();

        return Inertia::render('Application/Posts/Index', [
            'publishablePlatforms' => collect(Platform::publishable())
                ->map->label()
                ->values()
                ->all(),
            'accounts' => $this->filterableAccounts($workspace),
            'posts' => $posts,
            'filters' => [
                'status' => $status?->value,
                'search' => $search,
                'account' => $accountId,
            ],
        ]);
    }

    public function create(Workspace $workspace): Response
    {
        return Inertia::render('Application/Posts/Create', [
            'publishablePlatforms' => collect(Platform::publishable())
                ->map->label()
                ->values()
                ->all(),
            'publishableAccounts' => $this->publishableAccounts($workspace),
            'ai_enabled' => filled(config('ai.providers.'.config('ai.default').'.key')),
        ]);
    }

    /**
     * The workspace's connected accounts on publishable platforms, shaped for
     * the composer's account picker.
     *
     * @return array<int, array{id: string, platform: array{value: string, label: string}, display_name: string, avatar_url: string|null}>
     */
    private function publishableAccounts(Workspace $workspace): array
    {
        return $workspace->socialAccounts()
            ->where('status', SocialAccountStatus::Connected->value)
            ->whereIn('platform', collect(Platform::publishable())->map->value->all())
            ->orderBy('display_name')
            ->get()
            ->map(fn (SocialAccount $account): array => [
                'id' => $account->getKey(),
                'platform' => [
                    'value' => $account->platform->value,
                    'label' => $account->platform->label(),
                ],
                'display_name' => $account->display_name,
                'avatar_url' => $account->avatar_url,
            ])
            ->values()
            ->all();
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
                    'title' => $data->title ?? null,
                    'status' => PostTargetStatus::Pending,
                ]);
            }

            if ($data->media_id !== null) {
                $post->media()->attach($data->media_id, ['position' => 0]);
            }

            return $post;
        });

        foreach ($post->targets()->get() as $target) {
            PublishPostTargetJob::dispatch($target, $post->schedule_version)
                ->delay($post->scheduled_at ?? now());
        }

        $successMessage = $post->scheduled_at !== null ? 'Post scheduled.' : 'Post queued for publishing.';

        return $request->boolean('redirect_back')
            ? redirect()->back()->with('flash', ['success' => $successMessage])
            : redirect()
                ->route('workspace.posts', ['workspace' => $workspace])
                ->with('flash', ['success' => $successMessage]);
    }

    /**
     * Move a post to a new date, or clear its date so it publishes now.
     *
     * The post's schedule_version is bumped and pending targets are re-queued
     * with the new delay. Jobs dispatched for the previous schedule carry the
     * stale version and become no-ops, so a rescheduled post can never be
     * published twice from its old queue entries.
     */
    public function reschedule(
        UpdatePostScheduleRequest $request,
        Workspace $workspace,
        Post $post,
    ): RedirectResponse {
        $post = $workspace->posts()->whereKey($post->getKey())->firstOrFail();

        $scheduledAt = $request->has('scheduled_at')
            ? $request->date('scheduled_at')
            : null;

        $post->forceFill([
            'status' => $scheduledAt !== null ? PostStatus::Scheduled : PostStatus::Publishing,
            'scheduled_at' => $scheduledAt,
            'schedule_version' => $post->getAttribute('schedule_version') + 1,
        ])->save();

        foreach ($post->targets()->where('status', PostTargetStatus::Pending)->get() as $target) {
            PublishPostTargetJob::dispatch($target, $post->schedule_version)
                ->delay($scheduledAt ?? now());
        }

        return redirect()
            ->back()
            ->with('flash', [
                'success' => $scheduledAt !== null ? 'Post rescheduled.' : 'Post moved to publish now.',
            ]);
    }

    /**
     * Re-attempt publishing for every failed, unsubmitted target of a post.
     *
     * Eligibility (failed before any platform-side submission) and the retry cap
     * and cooldown both come from ResolvePostRetryPolicy, so the rules enforced
     * here are the same ones the post page renders. Eligible targets go back to
     * pending and their publish job is re-dispatched immediately; the target
     * observer then derives the post back to Publishing.
     *
     * The attempt is recorded in `post_retry_attempts`, and
     * `post_targets.retry_count` is deliberately left alone: it counts TikTok
     * status polls of a submitted upload, which is a different concern.
     */
    public function retry(Request $request, Workspace $workspace, Post $post): RedirectResponse
    {
        $post = $workspace->posts()->whereKey($post->getKey())->firstOrFail();
        $policy = app(ResolvePostRetryPolicy::class);

        // The cap is read from the audit log and then a row is appended, so two
        // concurrent requests would both read "one retry left" and both consume
        // it, dispatching the same targets twice. The lock serialises them: the
        // second one re-reads the log inside startRetry() and finds the first
        // one's attempt already recorded, so it is refused on the cap. The whole
        // decision runs inside the lock rather than just the insert, because a
        // caller that resolved the target list before waiting would otherwise
        // act on a stale set that the first request already moved to pending.
        //
        // The TTL is generous because with a sync queue the closure dispatches
        // publish jobs inline, network calls included. Nothing is lost by it
        // outliving the request: the cooldown is far longer, so a lock that
        // outlives a crashed request blocks nothing the user could do anyway.
        $started = Cache::lock('post-retry:'.$post->getKey(), 30)->get(
            fn (): RedirectResponse => $this->startRetry($request, $post, $policy),
        );

        // Lock::get() yields the callback's value on success and a falsy acquire
        // result when the lock is held, so the type is the test — not a null
        // check, which a `false` would sail straight past.
        if (! $started instanceof RedirectResponse) {
            return redirect()
                ->back()
                ->with('flash', [
                    'error' => 'A retry for this post is already being started. Please try again in a moment.',
                ]);
        }

        return $started;
    }

    /**
     * Check the policy and, if it allows, start the retry.
     *
     * Runs under the caller's lock, so its reads of the attempt log and of the
     * target statuses are current with respect to any other retry of this post.
     */
    private function startRetry(Request $request, Post $post, ResolvePostRetryPolicy $policy): RedirectResponse
    {
        $targets = $policy->eligibleTargets($post);

        if ($targets->isEmpty()) {
            return redirect()
                ->back()
                ->with('flash', ['error' => 'There are no failed targets to retry.']);
        }

        $state = $policy->resolve($post, $targets->count());

        if ($state->exhausted) {
            return redirect()
                ->back()
                ->with('flash', ['error' => 'No retries left for this post.']);
        }

        if ($state->waitSeconds > 0) {
            return redirect()
                ->back()
                ->with('flash', [
                    'error' => 'Retry available in '.self::formatWait($state->waitSeconds).'.',
                ]);
        }

        DB::transaction(function () use ($request, $post, $targets): void {
            foreach ($targets as $target) {
                $target->forceFill([
                    'status' => PostTargetStatus::Pending,
                    'error_message' => null,
                ])->save();

                PublishPostTargetJob::dispatch($target, $post->schedule_version);
            }

            $post->retryAttempts()->create([
                'attempted_by_user_id' => $request->user()->getKey(),
                'attempted_legs' => $targets->count(),
                'attempted_at' => now(),
            ]);
        });

        $count = $targets->count();

        return redirect()
            ->back()
            ->with('flash', [
                'success' => $count === 1
                    ? 'Retrying 1 failed account.'
                    : "Retrying {$count} failed accounts.",
            ]);
    }

    public function show(Workspace $workspace, Post $post): Response
    {
        $post = $workspace->posts()
            ->with([
                'targets.socialAccount',
                'targets.metrics' => fn ($query) => $query
                    ->where('snapshot_type', 'snapshot')
                    ->orderBy('snapshot_date'),
                'media',
            ])
            ->whereKey($post->getKey())
            ->firstOrFail();

        $serialized = [
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
            'retry' => $this->serializeRetryPolicy($post),
        ];

        return Inertia::render('Application/Posts/Show', [
            'post' => $serialized,
        ]);
    }

    /**
     * The retry affordance for the detail page, derived server-side.
     *
     * The policy is never trusted to the browser: the component only renders
     * the button state these fields describe, and the endpoint re-checks it.
     *
     * The cooldown is sent as an absolute instant rather than a remaining count
     * so the page's countdown and the server's cooldown agree without depending
     * on the browser's clock being right.
     *
     * @return array{eligible_legs: int, failed_legs: int, retries_left: int, exhausted: bool, last_retried_at: string|null, retry_available_at: string|null}
     */
    private function serializeRetryPolicy(Post $post): array
    {
        $policy = app(ResolvePostRetryPolicy::class)->resolve($post);

        return [
            'eligible_legs' => $policy->eligibleLegs,
            'failed_legs' => $policy->failedLegs,
            'retries_left' => $policy->retriesLeft,
            'exhausted' => $policy->exhausted,
            'last_retried_at' => $policy->lastRetriedAt?->toIso8601String(),
            'retry_available_at' => $policy->retryAvailableAt?->toIso8601String(),
        ];
    }

    /**
     * Format a cooldown wait as m:ss, matching the countdown on the retry
     * button so both surfaces read the same.
     */
    /**
     * Render a cooldown wait for a flash message.
     *
     * Grows to hours once past an hour, because the countdown on the button is
     * built the same way — a "3:00" cooldown would read as three minutes.
     */
    private static function formatWait(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $remainder = $seconds % 60;

        return $minutes >= 60
            ? sprintf('%d:%02d:%02d', intdiv($minutes, 60), $minutes % 60, $remainder)
            : sprintf('%d:%02d', $minutes, $remainder);
    }
}
