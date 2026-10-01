<?php

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTargetJob;
use App\Models\Post;
use App\Models\PostRetryAttempt;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

test('retry resets failed targets to pending and dispatches their publish job', function () {
    Queue::fake([PublishPostTargetJob::class]);

    // attempted_at is a one-second timestamp column, so the assertion below
    // compares it against a clock frozen on a second boundary. Without this the
    // test fails whenever a second ticks over between the insert and the check.
    $this->travelTo(now()->startOfSecond());

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $facebook = linkedAccount($user, ['platform' => Platform::Facebook]);
    $linkedin = linkedAccount($user, ['platform' => Platform::LinkedIn]);

    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Failed,
    ]);

    $facebookTarget = PostTarget::factory()->failed()->create([
        'post_id' => $post->id,
        'social_account_id' => $facebook->id,
    ]);
    PostTarget::factory()->failed()->create([
        'post_id' => $post->id,
        'social_account_id' => $linkedin->id,
    ]);

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $post]))
        ->assertRedirect()
        ->assertSessionHas('flash.success');

    expect($facebookTarget->fresh()->status)->toBe(PostTargetStatus::Pending);
    expect($facebookTarget->fresh()->error_message)->toBeNull();
    expect($post->fresh()->status)->toBe(PostStatus::Publishing);

    $attempt = PostRetryAttempt::sole();

    expect($attempt->post_id)->toBe($post->id);
    expect($attempt->attempted_by_user_id)->toBe($user->id);
    expect($attempt->attempted_legs)->toBe(2);
    expect($attempt->attempted_at->toDateTimeString())->toBe(now()->toDateTimeString());

    Queue::assertPushed(PublishPostTargetJob::class, 2);
    Queue::assertPushed(
        PublishPostTargetJob::class,
        fn (PublishPostTargetJob $job): bool => $job->target->is($facebookTarget),
    );
});

test('a user retry does not touch the TikTok poll counter', function () {
    Queue::fake([PublishPostTargetJob::class]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Facebook]);

    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Failed,
    ]);

    $target = PostTarget::factory()->failed()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'retry_count' => 7,
    ]);

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $post]))
        ->assertRedirect()
        ->assertSessionHas('flash.success');

    expect($target->fresh()->retry_count)->toBe(7);
});

test('retry leaves published and pending targets untouched', function () {
    Queue::fake([PublishPostTargetJob::class]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $failedAccount = linkedAccount($user, ['platform' => Platform::Facebook]);
    $publishedAccount = linkedAccount($user, ['platform' => Platform::LinkedIn]);
    $pendingAccount = linkedAccount($user, ['platform' => Platform::Instagram]);

    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Failed,
    ]);

    $failed = PostTarget::factory()->failed()->create([
        'post_id' => $post->id,
        'social_account_id' => $failedAccount->id,
    ]);
    $published = PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $publishedAccount->id,
    ]);
    $pending = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $pendingAccount->id,
    ]);

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $post]))
        ->assertRedirect()
        ->assertSessionHas('flash.success');

    expect($failed->fresh()->status)->toBe(PostTargetStatus::Pending);
    expect($published->fresh()->status)->toBe(PostTargetStatus::Published);
    expect($pending->fresh()->status)->toBe(PostTargetStatus::Pending);

    Queue::assertPushed(PublishPostTargetJob::class, 1);
});

test('retry with no eligible failed targets reports an error and dispatches nothing', function () {
    Queue::fake([PublishPostTargetJob::class]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Facebook]);

    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
    ]);
    PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $this->actingAs($user)
        ->from(route('workspace.posts.show', ['workspace' => $workspace, 'post' => $post]))
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $post]))
        ->assertRedirect()
        ->assertSessionHas('flash.error');

    Queue::assertPushed(PublishPostTargetJob::class, 0);
});

test('a TikTok target that failed after upload submission is not retried', function () {
    Queue::fake([PublishPostTargetJob::class]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $facebook = linkedAccount($user, ['platform' => Platform::Facebook]);
    $tiktok = linkedAccount($user, ['platform' => Platform::Tiktok]);

    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Failed,
    ]);

    PostTarget::factory()->failed()->create([
        'post_id' => $post->id,
        'social_account_id' => $facebook->id,
    ]);
    $tiktokTarget = PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $tiktok->id,
        'status' => PostTargetStatus::Failed,
        'platform_post_id' => null,
        'platform_upload_id' => 'upload-123',
        'error_message' => 'The upload was rejected.',
    ]);

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $post]))
        ->assertRedirect()
        ->assertSessionHas('flash.success');

    expect($tiktokTarget->fresh()->status)->toBe(PostTargetStatus::Failed);
    expect($tiktokTarget->fresh()->platform_upload_id)->toBe('upload-123');

    Queue::assertPushed(PublishPostTargetJob::class, 1);
});

test('retry cannot target a post outside the workspace', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $otherUser = User::factory()->create();
    $otherWorkspace = app(CreateWorkspaceAction::class)->ensure($otherUser);
    $otherAccount = linkedAccount($otherUser, ['platform' => Platform::Facebook]);

    $otherPost = Post::factory()->for($otherWorkspace)->create([
        'created_by_user_id' => $otherUser->id,
        'status' => PostStatus::Failed,
    ]);
    PostTarget::factory()->failed()->create([
        'post_id' => $otherPost->id,
        'social_account_id' => $otherAccount->id,
    ]);

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $otherPost]))
        ->assertNotFound();
});

test('a second concurrent retry of the same post is refused instead of consuming another attempt', function () {
    Queue::fake([PublishPostTargetJob::class]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $post = failedPost($user, $workspace);

    // Stand in for the first request already being inside its critical section.
    $lock = Cache::lock('post-retry:'.$post->getKey(), 30);
    expect($lock->get())->toBeTrue();

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $post]))
        ->assertRedirect()
        ->assertSessionHas('flash.error', 'A retry for this post is already being started. Please try again in a moment.');

    // The refused request must not have touched the log or the targets.
    expect(PostRetryAttempt::count())->toBe(0);
    expect($post->fresh()->targets()->where('status', PostTargetStatus::Failed)->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('retries are rate limited per post, not per user', function () {
    Queue::fake([PublishPostTargetJob::class]);

    // The array cache store outlives a single test in-process, so the limiter's
    // counters have to start from a clean slate.
    Cache::flush();

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $hammered = failedPost($user, $workspace);
    $untouched = failedPost($user, $workspace);

    // Five attempts against one post are allowed; the sixth is refused.
    foreach (range(1, 5) as $ignored) {
        $this->actingAs($user)
            ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $hammered]));
    }

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $hammered]))
        ->assertStatus(429);

    // The same user retrying a different post is not collateral damage.
    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $untouched]))
        ->assertRedirect()
        ->assertSessionHas('flash.success');

    expect(PostRetryAttempt::where('post_id', $untouched->id)->count())->toBe(1);
});

/**
 * A post with one failed, never-submitted target and the given retry history.
 */
function failedPost(User $user, Workspace $workspace, int $attempts = 0, ?int $secondsSinceLastAttempt = null): Post
{
    $account = linkedAccount($user, ['platform' => Platform::Facebook]);

    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Failed,
    ]);

    PostTarget::factory()->failed()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    for ($index = 0; $index < $attempts; $index++) {
        PostRetryAttempt::factory()->create([
            'post_id' => $post->id,
            'attempted_by_user_id' => $user->id,
            'attempted_at' => now()->subSeconds(($index + 1) * 4_000),
        ]);
    }

    if ($secondsSinceLastAttempt !== null) {
        $post->retryAttempts()->create([
            'attempted_by_user_id' => $user->id,
            'attempted_legs' => 1,
            'attempted_at' => now()->subSeconds($secondsSinceLastAttempt),
        ]);
    }

    return $post;
}

test('retry is blocked once the attempt cap is spent', function () {
    Queue::fake([PublishPostTargetJob::class]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $post = failedPost($user, $workspace, attempts: 3);

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $post]))
        ->assertRedirect()
        ->assertSessionHas('flash.error', 'No retries left for this post.');

    Queue::assertPushed(PublishPostTargetJob::class, 0);
    expect($post->retryAttempts()->count())->toBe(3);
});

test('the retry cap comes from the retry.max_retries setting', function () {
    Queue::fake([PublishPostTargetJob::class]);

    app(Settings::class)->set('retry.max_retries', 1);
    app(Settings::class)->set('retry.cooldown_seconds', 0);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $post = failedPost($user, $workspace);

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $post]))
        ->assertSessionHas('flash.success');

    // The leg failed again, so the only thing standing in the way is the cap.
    PostTarget::query()
        ->where('post_id', $post->id)
        ->update(['status' => PostTargetStatus::Failed]);

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $post]))
        ->assertRedirect()
        ->assertSessionHas('flash.error', 'No retries left for this post.');

    Queue::assertPushed(PublishPostTargetJob::class, 1);
});

test('retry is blocked during the cooldown and allowed once it elapses', function () {
    Queue::fake([PublishPostTargetJob::class]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $post = failedPost($user, $workspace, secondsSinceLastAttempt: 60);

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $post]))
        ->assertRedirect()
        ->assertSessionHas('flash.error', 'Retry available in 4:00.');

    Queue::assertPushed(PublishPostTargetJob::class, 0);

    $this->travel(240)->seconds();

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $post]))
        ->assertSessionHas('flash.success');

    Queue::assertPushed(PublishPostTargetJob::class, 1);
});

test('a cooldown longer than an hour is reported in hours, not minutes', function () {
    Queue::fake([PublishPostTargetJob::class]);

    // A one-day cooldown, so the remaining wait crosses the hour boundary the
    // flash message has to switch format at.
    app(Settings::class)->set('retry.cooldown_seconds', 86_400);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $post = failedPost($user, $workspace, secondsSinceLastAttempt: 3_600);

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $post]))
        ->assertRedirect()
        // 23 hours left, not "23:00:00" hours or a bare minute count.
        ->assertSessionHas('flash.error', 'Retry available in 23:00:00.');
});

test('the retry cooldown comes from the retry.cooldown_seconds setting', function () {
    Queue::fake([PublishPostTargetJob::class]);

    app(Settings::class)->set('retry.cooldown_seconds', 30);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $post = failedPost($user, $workspace, secondsSinceLastAttempt: 60);

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $post]))
        ->assertSessionHas('flash.success');

    Queue::assertPushed(PublishPostTargetJob::class, 1);
});

test('the retry cap and cooldown are scoped to a single post', function () {
    Queue::fake([PublishPostTargetJob::class]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $exhausted = failedPost($user, $workspace, attempts: 3);
    $untouched = failedPost($user, $workspace);

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $exhausted]))
        ->assertSessionHas('flash.error');

    $this->actingAs($user)
        ->post(route('workspace.posts.retry', ['workspace' => $workspace, 'post' => $untouched]))
        ->assertSessionHas('flash.success');

    Queue::assertPushed(PublishPostTargetJob::class, 1);
});

test('the post page serialises the retry policy', function () {
    // Frozen so the serialised cooldown deadline is an exact string rather than
    // something that drifts by a second per run.
    $this->travelTo(now()->startOfSecond());

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $post = failedPost($user, $workspace, secondsSinceLastAttempt: 120);

    $this->actingAs($user)
        ->get(route('workspace.posts.show', ['workspace' => $workspace, 'post' => $post]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('post.retry.eligible_legs', 1)
            ->where('post.retry.retries_left', 2)
            ->where('post.retry.exhausted', false)
            ->where('post.retry.last_retried_at', now()->subSeconds(120)->toIso8601String())
            // A deadline, not a remaining count: last attempt 120s ago plus the
            // 300s default cooldown.
            ->where('post.retry.retry_available_at', now()->addSeconds(180)->toIso8601String())
        );
});

test('the post page reports an exhausted post with nothing left to retry', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $post = failedPost($user, $workspace, attempts: 4);

    $this->actingAs($user)
        ->get(route('workspace.posts.show', ['workspace' => $workspace, 'post' => $post]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('post.retry.eligible_legs', 1)
            ->where('post.retry.retries_left', 0)
            ->where('post.retry.exhausted', true)
        );
});

test('the post page distinguishes nothing failed from nothing retryable', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    // A TikTok leg that failed after its upload was submitted: it counts as
    // failed, but re-sending it would create a second upload on the platform.
    $account = linkedAccount($user, ['platform' => Platform::Tiktok]);
    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Failed,
    ]);
    PostTarget::factory()->failed()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform_upload_id' => 'upload-abc',
    ]);

    $this->actingAs($user)
        ->get(route('workspace.posts.show', ['workspace' => $workspace, 'post' => $post]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('post.retry.eligible_legs', 0)
            ->where('post.retry.failed_legs', 1)
            // Retries remain available; this leg simply cannot be re-sent.
            ->where('post.retry.retries_left', 3)
            ->where('post.retry.exhausted', false)
        );
});

test('the post page reports no failed legs for a post that never failed', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Published,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.posts.show', ['workspace' => $workspace, 'post' => $post]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('post.retry.eligible_legs', 0)
            ->where('post.retry.failed_legs', 0)
        );
});
