<?php

use App\Actions\Application\Post\PublishToFacebookAction;
use App\Actions\Application\Post\PublishToInstagramAction;
use App\Actions\Application\Post\PublishToLinkedInAction;
use App\Actions\Application\Post\PublishToTikTokAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTargetJob;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Settings\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Inertia\Testing\AssertableInertia;

/**
 * The journey a real new customer walks: register, verify, connect an account,
 * then create a scheduled post.
 *
 * The other feature tests cover each stage well, but they start from
 * `User::factory()`, which is verified and already owns a workspace — so the
 * verification gate between registering and having a usable composer was never
 * exercised as one path. These tests go through real HTTP instead of building
 * fixtures, so the middleware chain, the redirect targets and the session all
 * take part.
 *
 * The account-linking leg still skips OAuth, because that needs a live provider
 * handshake; `linkedAccount()` stands in for it. SocialAccountsTest covers the
 * redirect and callback separately.
 */

/**
 * Register through the real form and return the resulting user.
 */
function registerThroughTheForm(string $email = 'john@example.com'): User
{
    test()->post(route('register.store'), [
        'fullname' => 'John Doe',
        'email' => $email,
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertRedirect(route('verification.notice'));

    return User::query()->where('email', $email)->firstOrFail();
}

/**
 * Complete verification by visiting the signed link the email would carry.
 */
function verifyEmailThroughTheLink(User $user): void
{
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->getKey(),
        'hash' => sha1($user->email),
    ]);

    test()->get($url)->assertRedirect(route('workspace.dashboard', [
        'workspace' => $user->workspaces()->firstOrFail(),
    ]));
}

/**
 * The first job of a class the fake queue captured, so its delay can be
 * asserted directly — a failed closure inside assertPushed() reports only
 * "job was not pushed", which is misleading when the real problem is the delay.
 */
function captureQueuedJob(QueueFake $queue, string $jobClass): ?object
{
    return $queue->pushed($jobClass)->first();
}

/**
 * Register, then verify, and hand back the user's workspace.
 */
function verifiedNewcomer(string $email = 'john@example.com'): Workspace
{
    $user = registerThroughTheForm($email);
    verifyEmailThroughTheLink($user);

    return $user->workspaces()->firstOrFail();
}

test('a new registrant is held at email verification and cannot create a post', function () {
    Queue::fake();

    $user = registerThroughTheForm();
    $workspace = $user->workspaces()->firstOrFail();

    expect($user->email_verified_at)->toBeNull();

    // Both the composer and the store endpoint sit behind the verified gate, so
    // a signup that skipped verification could not post.
    $this->get(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertRedirect(route('verification.notice'));

    $this->post(route('workspace.posts.store', ['workspace' => $workspace]), [
        'caption' => 'Should never exist',
        'targets' => [],
    ])->assertRedirect(route('verification.notice'));

    expect(Post::count())->toBe(0);
});

test('verifying the emailed link opens the post composer', function () {
    Queue::fake();

    $workspace = verifiedNewcomer();

    $this->get(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Posts/Create')
        );
});

test('a brand new workspace reaches the composer with no connected accounts', function () {
    Queue::fake();

    $workspace = verifiedNewcomer();

    expect($workspace->socialAccounts()->count())->toBe(0);

    // First run, nothing connected yet: the composer has to render rather than
    // error, and still describe the platforms so it can ask for an account.
    $this->get(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Posts/Create')
            ->where('publishableAccounts', [])
            ->has('publishablePlatforms')
        );
});

test('a scheduled post is persisted and its publish job is delayed to that time', function () {
    $queue = Queue::fake();
    $this->travelTo(now()->startOfSecond());

    $workspace = verifiedNewcomer();
    $account = linkedAccount(User::query()->where('email', 'john@example.com')->firstOrFail(), [
        'platform' => Platform::Facebook,
        'external_account_id' => 'page-1',
    ]);

    $scheduledAt = now()->addDays(3)->startOfMinute();

    $this->post(route('workspace.posts.store', ['workspace' => $workspace]), [
        'caption' => 'Scheduled hello',
        'targets' => [$account->id],
        'scheduled_at' => $scheduledAt->toDateTimeString(),
    ])
        ->assertRedirect(route('workspace.posts', ['workspace' => $workspace]))
        ->assertSessionHas('flash.success');

    $post = $workspace->posts()->firstOrFail();

    expect($post->status)->toBe(PostStatus::Scheduled);
    expect($post->scheduled_at->toDateTimeString())->toBe($scheduledAt->toDateTimeString());

    // The dispatch has to be delayed to the scheduled instant. Asserting only
    // that a job was pushed would pass even with the delay dropped, which turns
    // "scheduled for later" into "published immediately" with no test failing.
    $job = captureQueuedJob($queue, PublishPostTargetJob::class);

    expect($job)->not->toBeNull();
    expect($job->delay)->not->toBeNull();
    expect($job->delay->equalTo($post->scheduled_at))->toBeTrue();
});

test('a post created without a date is queued to publish straight away', function () {
    $queue = Queue::fake();
    $this->travelTo(now()->startOfSecond());

    $workspace = verifiedNewcomer();
    $account = linkedAccount(User::query()->where('email', 'john@example.com')->firstOrFail(), [
        'platform' => Platform::Facebook,
        'external_account_id' => 'page-1',
    ]);

    $this->post(route('workspace.posts.store', ['workspace' => $workspace]), [
        'caption' => 'Publish me now',
        'targets' => [$account->id],
    ])
        ->assertRedirect(route('workspace.posts', ['workspace' => $workspace]))
        ->assertSessionHas('flash.success');

    $post = $workspace->posts()->firstOrFail();

    expect($post->status)->toBe(PostStatus::Publishing);
    expect($post->scheduled_at)->toBeNull();

    // Due now, not parked in the future: an unscheduled post should go out as
    // soon as a worker is free.
    $job = captureQueuedJob($queue, PublishPostTargetJob::class);

    expect($job)->not->toBeNull();
    expect($job->delay)->not->toBeNull();
    expect($job->delay->isFuture())->toBeFalse();
});

test('a scheduled post publishes once its scheduled time arrives', function () {
    $queue = Queue::fake();
    $this->travelTo(now()->startOfSecond());

    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/*/feed' => Http::response(['id' => 'fb-post-123']),
    ]);

    $workspace = verifiedNewcomer();
    $account = linkedAccount(User::query()->where('email', 'john@example.com')->firstOrFail(), [
        'platform' => Platform::Facebook,
        'external_account_id' => 'page-1',
    ]);

    $scheduledAt = now()->addDays(3)->startOfMinute();

    $this->post(route('workspace.posts.store', ['workspace' => $workspace]), [
        'caption' => 'Publish me later',
        'targets' => [$account->id],
        'scheduled_at' => $scheduledAt->toDateTimeString(),
    ]);

    $post = $workspace->posts()->firstOrFail();
    $target = $post->targets()->firstOrFail();

    expect($target->status)->toBe(PostTargetStatus::Pending);

    $job = captureQueuedJob($queue, PublishPostTargetJob::class);

    expect($job)->not->toBeNull();

    // Still waiting on the queue at its scheduled moment.
    $this->travelTo($scheduledAt);

    expect($target->fresh()->status)->toBe(PostTargetStatus::Pending);

    // What a worker does once the delay elapses. Invoked directly because the
    // fake queue never runs it — there is no worker in a test process.
    $job->handle(
        app(PublishToFacebookAction::class),
        app(PublishToInstagramAction::class),
        app(PublishToLinkedInAction::class),
        app(PublishToTikTokAction::class),
        app(Settings::class),
    );

    expect($target->fresh()->status)->toBe(PostTargetStatus::Published);
    expect($target->fresh()->platform_post_id)->toBe('fb-post-123');
    expect($post->fresh()->status)->toBe(PostStatus::Published);
});
