<?php

use App\Actions\Application\Post\PublishToFacebookAction;
use App\Actions\Application\Post\PublishToInstagramAction;
use App\Actions\Application\Post\PublishToLinkedInAction;
use App\Actions\Application\Post\PublishToTikTokAction;
use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\SocialAccountStatus;
use App\Jobs\PublishPostTargetJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

function calendarAccount(User $user, array $attributes = []): SocialAccount
{
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    return SocialAccount::factory()->create(array_merge([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ], $attributes));
}

test('the calendar places scheduled posts on their date and others on creation', function () {
    $this->travelTo('2026-03-15 09:00:00');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = calendarAccount($user, ['platform' => Platform::Facebook]);

    $scheduled = Post::factory()->for($workspace)->scheduled('2026-03-10 08:00:00')->create([
        'created_by_user_id' => $user->id,
    ]);
    PostTarget::factory()->pending()->create([
        'post_id' => $scheduled->id,
        'social_account_id' => $account->id,
        'caption' => 'Scheduled on the tenth',
    ]);

    $immediate = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Published,
        'created_at' => '2026-03-14 10:00:00',
    ]);
    PostTarget::factory()->create([
        'post_id' => $immediate->id,
        'social_account_id' => $account->id,
        'status' => PostTargetStatus::Published,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.calendar', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Posts/Calendar')
            ->where('month', '2026-03')
            ->has('posts', 2)
            ->where('posts.0.status.value', 'scheduled')
            ->where('posts.0.caption', 'Scheduled on the tenth')
            ->where('posts.1.created_at', '2026-03-14T10:00:00+00:00'));
});

test('the calendar excludes posts outside the current month window', function () {
    $this->travelTo('2026-03-15 09:00:00');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = calendarAccount($user, ['platform' => Platform::Facebook]);

    $future = Post::factory()->for($workspace)->scheduled('2027-01-15 08:00:00')->create([
        'created_by_user_id' => $user->id,
    ]);
    PostTarget::factory()->pending()->create([
        'post_id' => $future->id,
        'social_account_id' => $account->id,
    ]);

    $old = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Published,
        'created_at' => '2024-01-01 10:00:00',
    ]);
    PostTarget::factory()->create([
        'post_id' => $old->id,
        'social_account_id' => $account->id,
        'status' => PostTargetStatus::Published,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.calendar', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Posts/Calendar')
            ->has('posts', 0));
});

test('the calendar honors a requested month query', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = calendarAccount($user, ['platform' => Platform::Facebook]);

    $post = Post::factory()->for($workspace)->scheduled('2026-01-20 08:00:00')->create([
        'created_by_user_id' => $user->id,
    ]);
    PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.calendar', ['workspace' => $workspace, 'month' => '2026-01']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('month', '2026-01')
            ->has('posts', 1));
});

test('the calendar filters posts by account', function () {
    $this->travelTo('2026-03-15 09:00:00');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $facebook = calendarAccount($user, [
        'platform' => Platform::Facebook,
        'display_name' => 'Alpha Page',
        'avatar_url' => 'https://example.com/fb.png',
    ]);
    $instagram = calendarAccount($user, [
        'platform' => Platform::Instagram,
        'display_name' => 'Zeta Account',
    ]);

    $fbPost = Post::factory()->for($workspace)->scheduled('2026-03-10 08:00:00')->create([
        'created_by_user_id' => $user->id,
    ]);
    PostTarget::factory()->pending()->create([
        'post_id' => $fbPost->id,
        'social_account_id' => $facebook->id,
        'caption' => 'Facebook post',
    ]);

    $igPost = Post::factory()->for($workspace)->scheduled('2026-03-11 08:00:00')->create([
        'created_by_user_id' => $user->id,
    ]);
    PostTarget::factory()->pending()->create([
        'post_id' => $igPost->id,
        'social_account_id' => $instagram->id,
        'caption' => 'Instagram post',
    ]);

    $this->actingAs($user)
        ->get(route('workspace.calendar', ['workspace' => $workspace, 'month' => '2026-03', 'account' => $facebook->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Posts/Calendar')
            ->has('posts', 1)
            ->where('posts.0.caption', 'Facebook post')
            ->where('filters.account', $facebook->id)
            ->has('accounts', 2)
            ->where('accounts.0.platform.value', 'facebook')
            ->where('accounts.0.status.value', 'connected')
            ->where('accounts.0.avatar_url', 'https://example.com/fb.png'));
});

test('the calendar returns 404 for a user who is not a member', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('workspace.calendar', ['workspace' => $workspace]))
        ->assertNotFound();
});

test('rescheduling moves a scheduled post, bumps its version and re-queues pending targets', function () {
    Queue::fake([PublishPostTargetJob::class]);
    $this->travelTo('2026-01-01 09:00:00');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = calendarAccount($user, ['platform' => Platform::Facebook]);

    $post = Post::factory()->for($workspace)->scheduled('2026-01-15 10:00:00')->create([
        'created_by_user_id' => $user->id,
    ]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $this->actingAs($user)
        ->patch(route('workspace.posts.reschedule', ['workspace' => $workspace, 'post' => $post]), [
            'scheduled_at' => '2026-02-10 11:00:00',
        ])
        ->assertRedirect()
        ->assertSessionHas('flash.success');

    $post->refresh();

    expect($post->status)->toBe(PostStatus::Scheduled);
    expect($post->scheduled_at->toDateTimeString())->toBe('2026-02-10 11:00:00');
    expect($post->schedule_version)->toBe(1);

    Queue::assertPushed(PublishPostTargetJob::class, 1);
    expect(Queue::pushed(PublishPostTargetJob::class)->first()->delay->toDateTimeString())->toBe('2026-02-10 11:00:00');
});

test('clearing the schedule moves a scheduled post to publishing now', function () {
    Queue::fake([PublishPostTargetJob::class]);
    $this->travelTo('2026-01-01 09:00:00');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = calendarAccount($user, ['platform' => Platform::Facebook]);

    $post = Post::factory()->for($workspace)->scheduled('2026-01-15 10:00:00')->create([
        'created_by_user_id' => $user->id,
    ]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $this->actingAs($user)
        ->patch(route('workspace.posts.reschedule', ['workspace' => $workspace, 'post' => $post]))
        ->assertRedirect()
        ->assertSessionHas('flash.success');

    $post->refresh();

    expect($post->status)->toBe(PostStatus::Publishing);
    expect($post->scheduled_at)->toBeNull();
    expect($post->schedule_version)->toBe(1);

    Queue::assertPushed(PublishPostTargetJob::class, 1);
});

test('rescheduling does not re-queue targets that were already published', function () {
    Queue::fake([PublishPostTargetJob::class]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = calendarAccount($user, ['platform' => Platform::Facebook]);

    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Published,
        'scheduled_at' => '2026-01-15 10:00:00',
    ]);
    PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'status' => PostTargetStatus::Published,
        'platform_post_id' => 'fb-post-1',
    ]);

    $this->actingAs($user)
        ->patch(route('workspace.posts.reschedule', ['workspace' => $workspace, 'post' => $post]), [
            'scheduled_at' => '2026-01-20 10:00:00',
        ])
        ->assertRedirect();

    Queue::assertPushed(PublishPostTargetJob::class, 0);
});

test('a past reschedule date is rejected', function () {
    $this->travelTo('2026-01-01 09:00:00');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = calendarAccount($user, ['platform' => Platform::Facebook]);

    $post = Post::factory()->for($workspace)->scheduled('2026-01-15 10:00:00')->create([
        'created_by_user_id' => $user->id,
    ]);
    PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $this->actingAs($user)
        ->from('/')
        ->patch(route('workspace.posts.reschedule', ['workspace' => $workspace, 'post' => $post]), [
            'scheduled_at' => '2020-01-10 10:00:00',
        ])
        ->assertSessionHasErrors('scheduled_at');

    expect($post->refresh()->scheduled_at->toDateTimeString())->toBe('2026-01-15 10:00:00');
    expect($post->schedule_version)->toBe(0);
});

test('a job queued for a stale schedule is a no-op after a reschedule', function () {
    $this->travelTo('2026-01-01 09:00:00');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = calendarAccount($user, ['platform' => Platform::Facebook, 'external_account_id' => 'page-1']);

    $post = Post::factory()->for($workspace)->scheduled('2026-02-10 10:00:00')->create([
        'created_by_user_id' => $user->id,
        'schedule_version' => 1,
    ]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $job = new PublishPostTargetJob($target, 0);
    $job->handle(
        app(PublishToFacebookAction::class),
        app(PublishToInstagramAction::class),
        app(PublishToLinkedInAction::class),
        app(PublishToTikTokAction::class),
        app(Settings::class),
    );

    $target->refresh();

    expect($target->status)->toBe(PostTargetStatus::Pending);
    expect($target->platform_post_id)->toBeNull();
});

test('a post from another workspace can not be rescheduled', function () {
    $this->travelTo('2026-01-01 09:00:00');

    $owner = User::factory()->create();
    $foreignWorkspace = app(CreateWorkspaceAction::class)->ensure($owner);
    $foreignAccount = SocialAccount::factory()->create([
        'workspace_id' => $foreignWorkspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $foreignPost = Post::factory()->for($foreignWorkspace)->scheduled('2026-01-15 10:00:00')->create([
        'created_by_user_id' => $owner->id,
    ]);
    PostTarget::factory()->pending()->create([
        'post_id' => $foreignPost->id,
        'social_account_id' => $foreignAccount->id,
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $this->actingAs($user)
        ->patch(route('workspace.posts.reschedule', ['workspace' => $workspace, 'post' => $foreignPost]), [
            'scheduled_at' => '2026-01-20 10:00:00',
        ])
        ->assertNotFound();
});

test('a post can be created from the calendar with redirect_back staying on the calendar', function () {
    Queue::fake([PublishPostTargetJob::class]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = calendarAccount($user, ['platform' => Platform::Facebook]);

    $calendarUrl = route('workspace.calendar', ['workspace' => $workspace]);

    $this->actingAs($user)
        ->from($calendarUrl)
        ->post(route('workspace.posts.store', ['workspace' => $workspace]), [
            'caption' => 'Created via calendar quick-create',
            'targets' => [$account->id],
            'redirect_back' => '1',
        ])
        ->assertRedirect($calendarUrl)
        ->assertSessionHas('flash.success');

    expect($workspace->posts()->count())->toBe(1);
    expect($workspace->posts()->first()->targets()->first()->caption)->toBe('Created via calendar quick-create');
});
