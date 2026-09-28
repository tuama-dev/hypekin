<?php

use App\Actions\Application\Post\FetchFacebookMetricsAction;
use App\Actions\Application\Post\FetchInstagramMetricsAction;
use App\Actions\Application\Post\FetchLinkedInMetricsAction;
use App\Actions\Application\Post\FetchTikTokMetricsAction;
use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Console\Commands\SyncDuePostMetrics;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\SocialAccountStatus;
use App\Jobs\SyncPostMetricsJob;
use App\Models\Post;
use App\Models\PostMetric;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

function metricTarget(Platform $platform, string $postId): PostTarget
{
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => $platform,
        'external_account_id' => 'acct-1',
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Published,
    ]);

    return PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'status' => PostTargetStatus::Published,
        'platform_post_id' => $postId,
    ]);
}

function runMetricsJob(PostTarget $target): void
{
    $job = new SyncPostMetricsJob($target);

    $job->handle(
        app(FetchFacebookMetricsAction::class),
        app(FetchInstagramMetricsAction::class),
        app(FetchLinkedInMetricsAction::class),
        app(FetchTikTokMetricsAction::class),
    );
}

test('the metrics job records a Facebook snapshot with likes, comments, shares, impressions and reach', function () {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/*/insights*' => Http::response([
            'data' => [
                ['name' => 'post_impressions', 'values' => [['value' => 1250]]],
                ['name' => 'post_impressions_unique', 'values' => [['value' => 900]]],
            ],
        ]),
        'graph.facebook.com/v21.0/fb-post-9*' => Http::response([
            'id' => 'fb-post-9',
            'likes' => ['summary' => ['total_count' => 12]],
            'comments' => ['summary' => ['total_count' => 4]],
            'shares' => 3,
        ]),
    ]);

    $target = metricTarget(Platform::Facebook, 'fb-post-9');

    runMetricsJob($target);

    $metric = PostMetric::query()->where('post_id', $target->post_id)->firstOrFail();

    expect($metric->platform)->toBe(Platform::Facebook);
    expect($metric->snapshot_type)->toBe('snapshot');
    expect($metric->snapshot_date->toDateString())->toBe(now()->toDateString());
    expect($metric->data)->toMatchArray([
        'likes' => 12,
        'comments' => 4,
        'shares' => 3,
        'saves' => null,
        'impressions' => 1250,
        'reach' => 900,
        'engagements' => null,
        'views' => null,
    ]);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/fb-post-9?'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/fb-post-9/insights')
        && str_contains($request->url(), 'post_impressions_unique'));
});

test('the metrics job records an Instagram snapshot with saves, reach and impressions', function () {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/*/insights*' => Http::response([
            'data' => [
                ['name' => 'likes', 'values' => [['value' => 20]]],
                ['name' => 'comments', 'values' => [['value' => 7]]],
                ['name' => 'shares', 'values' => [['value' => 2]]],
                ['name' => 'saves', 'values' => [['value' => 11]]],
                ['name' => 'reach', 'values' => [['value' => 600]]],
                ['name' => 'impressions', 'values' => [['value' => 800]]],
            ],
        ]),
    ]);

    $target = metricTarget(Platform::Instagram, 'ig-media-7');

    runMetricsJob($target);

    $metric = PostMetric::query()->where('post_id', $target->post_id)->firstOrFail();

    expect($metric->platform)->toBe(Platform::Instagram);
    expect($metric->data)->toMatchArray([
        'likes' => 20,
        'comments' => 7,
        'shares' => 2,
        'saves' => 11,
        'impressions' => 800,
        'reach' => 600,
        'engagements' => null,
        'views' => null,
    ]);
});

test('the metrics job records a LinkedIn snapshot from the share analytics', function () {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake([
        'api.linkedin.com/rest/socialActions/*' => Http::response([
            'elements' => [[
                'likeCount' => 5,
                'commentCount' => 2,
                'shareCount' => 1,
                'impressionCount' => 100,
            ]],
        ]),
    ]);

    $target = metricTarget(Platform::LinkedIn, 'urn:li:share:li-1');

    runMetricsJob($target);

    $metric = PostMetric::query()->where('post_id', $target->post_id)->firstOrFail();

    expect($metric->platform)->toBe(Platform::LinkedIn);
    expect($metric->data)->toMatchArray([
        'likes' => 5,
        'comments' => 2,
        'shares' => 1,
        'saves' => null,
        'impressions' => 100,
        'reach' => null,
        'engagements' => 8,
        'views' => null,
    ]);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/socialActions/urn:li:share:li-1/analytics')
        && str_contains($request->url(), 'timeIntervals')
        && $request->header('X-Restli-Protocol-Version') === ['2.0.0']);
});

test('the metrics job records a TikTok snapshot with view counts', function () {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake([
        'open.tiktokapis.com/v2/video/query/*' => Http::response([
            'data' => ['videos' => [[
                'id' => 'tt-1',
                'view_count' => 300,
                'like_count' => 15,
                'comment_count' => 6,
                'share_count' => 2,
            ]]],
        ]),
    ]);

    $target = metricTarget(Platform::Tiktok, 'tt-1');

    runMetricsJob($target);

    $metric = PostMetric::query()->where('post_id', $target->post_id)->firstOrFail();

    expect($metric->platform)->toBe(Platform::Tiktok);
    expect($metric->data)->toMatchArray([
        'likes' => 15,
        'comments' => 6,
        'shares' => 2,
        'saves' => null,
        'impressions' => null,
        'reach' => null,
        'engagements' => null,
        'views' => 300,
    ]);

    Http::assertSent(function ($request) use ($target): bool {
        return str_contains($request->url(), '/video/query/')
            && $request['filters']['video_ids'] === [$target->platform_post_id]
            && $request['fields'] === ['view_count', 'like_count', 'comment_count', 'share_count'];
    });
});

test('the job skips a target that already has a snapshot for today', function () {
    Queue::fake();
    Http::preventStrayRequests();

    $target = metricTarget(Platform::Facebook, 'fb-post-9');
    PostMetric::factory()->create([
        'post_target_id' => $target->id,
        'post_id' => $target->post_id,
        'platform' => Platform::Facebook,
        'snapshot_date' => now()->toDateString(),
    ]);

    runMetricsJob($target);

    Http::assertNothingSent();
    expect(PostMetric::query()->where('post_id', $target->post_id)->count())->toBe(1);
});

test('a failed fetch records an error snapshot and stops further attempts', function () {
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Session has expired']], 400),
    ]);

    $target = metricTarget(Platform::Facebook, 'fb-post-9');

    runMetricsJob($target);

    $metric = PostMetric::query()->where('post_id', $target->post_id)->firstOrFail();

    expect($metric->snapshot_type)->toBe('error');
    expect($metric->data)->toMatchArray(['error' => 'Session has expired']);

    runMetricsJob($target);

    expect(PostMetric::query()->where('post_id', $target->post_id)->count())->toBe(1);
});

test('a transient failure records nothing so the scheduler retries the target', function () {
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Temporary outage']], 503)
            ->push([
                'id' => 'fb-post-9',
                'likes' => ['summary' => ['total_count' => 3]],
                'comments' => ['summary' => ['total_count' => 1]],
                'shares' => 1,
            ])
            ->push(['data' => []]),
    ]);

    $target = metricTarget(Platform::Facebook, 'fb-post-9');

    runMetricsJob($target);

    expect(PostMetric::query()->count())->toBe(0);

    runMetricsJob($target);

    $metric = PostMetric::query()->where('post_target_id', $target->id)->firstOrFail();

    expect($metric->snapshot_type)->toBe('snapshot');
    expect($metric->data)->toMatchArray(['likes' => 3]);
});

test('a rate-limit response is treated as transient and records nothing', function () {
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/*' => Http::response([], 429),
    ]);

    $target = metricTarget(Platform::Facebook, 'fb-post-9');

    runMetricsJob($target);

    expect(PostMetric::query()->count())->toBe(0);
});

test('a target without a platform post id is skipped', function () {
    Http::preventStrayRequests();

    $target = metricTarget(Platform::Facebook, 'fb-post-9');
    $target->forceFill(['platform_post_id' => null])->save();

    runMetricsJob($target);

    Http::assertNothingSent();
    expect(PostMetric::query()->count())->toBe(0);
});

test('two targets on the same post and platform each get their own daily snapshot', function () {
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/*/insights*' => Http::response(['data' => []]),
        'graph.facebook.com/*' => Http::response([
            'likes' => ['summary' => ['total_count' => 1]],
            'comments' => ['summary' => ['total_count' => 0]],
            'shares' => 0,
        ]),
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Published,
    ]);

    $targets = [];

    foreach (['fb-page-a', 'fb-page-b'] as $index => $postId) {
        $account = SocialAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'platform' => Platform::Facebook,
            'external_account_id' => 'page-'.$index,
            'status' => SocialAccountStatus::Connected,
        ]);
        $targets[] = PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => PostTargetStatus::Published,
            'platform_post_id' => $postId,
        ]);
    }

    foreach ($targets as $target) {
        runMetricsJob($target);
    }

    $snapshots = PostMetric::query()->where('post_id', $post->id)->get();

    expect($snapshots)->toHaveCount(2);
    expect($snapshots->pluck('post_target_id')->all())
        ->toBe(array_map(fn (PostTarget $target): string => $target->id, $targets));
});

test('the command dispatches a sync job for every target due today', function () {
    Queue::fake();

    $dueToday = metricTarget(Platform::Facebook, 'fb-due-0');
    $dueYesterday = metricTarget(Platform::Facebook, 'fb-due-1');
    $dueYesterday->forceFill(['published_at' => now()->subDay()])->save();
    $notDue = metricTarget(Platform::Facebook, 'fb-gap-2');
    $notDue->forceFill(['published_at' => now()->subDays(2)])->save();
    $pastWindow = metricTarget(Platform::Facebook, 'fb-past');
    $pastWindow->forceFill(['published_at' => now()->subDays(8)])->save();

    app(SyncDuePostMetrics::class)->handle();

    Queue::assertPushed(SyncPostMetricsJob::class, 2);
    Queue::assertPushed(SyncPostMetricsJob::class, fn (SyncPostMetricsJob $job): bool => $job->target->is($dueToday));
    Queue::assertPushed(SyncPostMetricsJob::class, fn (SyncPostMetricsJob $job): bool => $job->target->is($dueYesterday));
});

test('the command skips a target that already has a snapshot for today', function () {
    Queue::fake();

    $target = metricTarget(Platform::Facebook, 'fb-snapped');
    PostMetric::factory()->create([
        'post_target_id' => $target->id,
        'post_id' => $target->post_id,
        'platform' => Platform::Facebook,
        'snapshot_date' => now()->toDateString(),
    ]);

    app(SyncDuePostMetrics::class)->handle();

    Queue::assertNotPushed(SyncPostMetricsJob::class);
});

test('the command skips a target whose latest snapshot is an error', function () {
    Queue::fake();

    $target = metricTarget(Platform::Facebook, 'fb-error');
    PostMetric::factory()->create([
        'post_target_id' => $target->id,
        'post_id' => $target->post_id,
        'platform' => Platform::Facebook,
        'snapshot_type' => 'error',
        'snapshot_date' => now()->subDay(),
        'data' => ['error' => 'Session has expired'],
    ]);

    app(SyncDuePostMetrics::class)->handle();

    Queue::assertNotPushed(SyncPostMetricsJob::class);
});

test('the post show page renders the post with its metrics snapshots', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Facebook,
        'external_account_id' => 'acct-1',
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Published,
    ]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'status' => PostTargetStatus::Published,
        'platform_post_id' => 'fb-post-show',
    ]);
    PostMetric::factory()->create([
        'post_target_id' => $target->id,
        'post_id' => $post->id,
        'platform' => Platform::Facebook,
        'snapshot_date' => now()->toDateString(),
        'data' => ['likes' => 42, 'impressions' => 1500],
    ]);

    $this->actingAs($user)
        ->get(route('workspace.posts.show', ['workspace' => $workspace, 'post' => $post->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Posts/Show')
            ->where('post.id', $post->id)
            ->has('post.targets.0.metrics', 1)
            ->where('post.targets.0.metrics.0.data.likes', 42));
});

test('the dashboard index renders recent posts with their metrics', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Facebook,
        'external_account_id' => 'acct-1',
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Published,
    ]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'status' => PostTargetStatus::Published,
        'platform_post_id' => 'fb-post-dash',
    ]);
    PostMetric::factory()->create([
        'post_target_id' => $target->id,
        'post_id' => $post->id,
        'platform' => Platform::Facebook,
        'snapshot_date' => now()->toDateString(),
        'data' => ['likes' => 10, 'impressions' => 500],
    ]);

    $this->actingAs($user)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Dashboard')
            ->has('recentPosts', 1)
            ->where('recentPosts.0.id', $post->id)
            ->has('recentPosts.0.targets.0.metrics', 1));
});
