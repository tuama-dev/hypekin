<?php

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\SocialAccountStatus;
use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PostTargetFailedNotification;
use Inertia\Testing\AssertableInertia;

test('a failed publish stores a database notification for the creator with the failure details', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Facebook,
        'display_name' => 'Acme Page',
        'status' => SocialAccountStatus::Connected,
    ]);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Publishing]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'caption' => 'Will fail',
        'error_message' => 'Invalid OAuth access token.',
    ]);

    $user->notify(new PostTargetFailedNotification($target));

    $notification = $user->notifications()->firstOrFail();

    expect($notification->type)->toBe(PostTargetFailedNotification::class);
    expect($notification->read_at)->toBeNull();

    $data = $notification->data;
    expect($data['title'])->toBe('Publish failed on Facebook');
    expect($data['message'])->toBe('Your post did not publish to Acme Page.');
    expect($data['platform'])->toBe('facebook');
    expect($data['account_display_name'])->toBe('Acme Page');
    expect($data['error'])->toBe('Invalid OAuth access token.');
    expect($data['post_id'])->toBe($post->id);
    expect($data['workspace_slug'])->toBe($workspace->slug);
});

test('the failure notification delivers email alongside the database row', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Facebook,
        'display_name' => 'Acme Page',
        'status' => SocialAccountStatus::Connected,
    ]);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Publishing]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $notification = new PostTargetFailedNotification($target);

    expect($notification->via($user))->toContain('database')->toContain('mail');

    $mail = $notification->toMail($user);
    expect($mail->subject)->toBe('Publish failed on Facebook');
    expect($mail->introLines)->toContain('Your post did not publish to Acme Page.');
    expect($mail->actionUrl)->toBe(
        route('workspace.posts.show', ['workspace' => $workspace, 'post' => $post]),
    );
});

test('read-all marks every notification for the authenticated user as read', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Publishing]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);
    $user->notify(new PostTargetFailedNotification($target));
    $user->notify(new PostTargetFailedNotification($target));

    expect($user->unreadNotifications()->count())->toBe(2);

    $this->actingAs($user)
        ->from(route('workspace.posts', ['workspace' => $workspace]))
        ->post(route('workspace.notifications.read-all', ['workspace' => $workspace]))
        ->assertRedirect(route('workspace.posts', ['workspace' => $workspace]));

    expect($user->unreadNotifications()->count())->toBe(0);
    expect($user->notifications()->count())->toBe(2);
});

test('read-all only clears notifications for the requester', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);
    $colleague = User::factory()->create();
    $workspace->users()->attach($colleague, ['role' => WorkspaceRole::Editor]);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $owner->id, 'status' => PostStatus::Publishing]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $owner->notify(new PostTargetFailedNotification($target));
    $colleague->notify(new PostTargetFailedNotification($target));

    $this->actingAs($owner)
        ->post(route('workspace.notifications.read-all', ['workspace' => $workspace]))
        ->assertRedirect();

    expect($owner->unreadNotifications()->count())->toBe(0);
    expect($colleague->unreadNotifications()->count())->toBe(1);
});

test('read-all is not available to a user outside the workspace', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post(route('workspace.notifications.read-all', ['workspace' => $workspace]))
        ->assertNotFound();
});

function failingTarget(User $user, Workspace $workspace, string $caption): PostTarget
{
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Publishing]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'caption' => $caption,
    ]);

    $user->notify(new PostTargetFailedNotification($target));

    return $target;
}

test('the notifications index lists the user\'s notifications for that workspace, newest first', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $this->travelTo('2026-01-01 09:00:00');
    $first = failingTarget($user, $workspace, 'First post');

    $this->travelTo('2026-01-01 09:30:00');
    $second = failingTarget($user, $workspace, 'Second post');

    $firstId = $user->notifications()->where('data->post_id', $first->post_id)->first()->getKey();
    $secondId = $user->notifications()->where('data->post_id', $second->post_id)->first()->getKey();

    $this->actingAs($user)
        ->get(route('workspace.notifications.index', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Notifications/Index')
            ->where('notifications.total', 2)
            ->has('notifications.data', 2)
            ->where('notifications.data.0.id', $secondId)
            ->where('notifications.data.0.data.post_id', $second->post_id)
            ->where('notifications.data.1.id', $firstId));
});

test('the notifications index does not leak another workspace\'s notifications', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $mine = failingTarget($user, $workspace, 'Mine');

    $secondWorkspace = app(CreateWorkspaceAction::class)->ensure(User::factory()->create());
    $workspace->users()->attach($secondWorkspace->owner, ['role' => WorkspaceRole::Owner]);
    failingTarget($user, $secondWorkspace, 'Theirs');

    $this->actingAs($user)
        ->get(route('workspace.notifications.index', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Notifications/Index')
            ->where('notifications.total', 1)
            ->has('notifications.data', 1)
            ->where('notifications.data.0.data.post_id', $mine->post_id));
});

test('marking a notification read is refused for a notification from another workspace', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $secondWorkspace = app(CreateWorkspaceAction::class)->ensure(User::factory()->create());
    $workspace->users()->attach($secondWorkspace->owner, ['role' => WorkspaceRole::Owner]);
    failingTarget($user, $secondWorkspace, 'Theirs');

    $notificationId = $user->notifications()->firstOrFail()->getKey();

    $this->actingAs($user)
        ->patch(route('workspace.notifications.read', [
            'workspace' => $workspace,
            'notification' => $notificationId,
        ]))
        ->assertNotFound();

    expect($user->notifications()->firstOrFail()->read_at)->toBeNull();
});

test('the notifications index returns 404 for a user who is not a member', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('workspace.notifications.index', ['workspace' => $workspace]))
        ->assertNotFound();
});

test('marking a notification read is scoped to the requester and idempotent', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    failingTarget($user, $workspace, 'First post');
    failingTarget($user, $workspace, 'Second post');

    $notifications = $user->notifications()->orderBy('created_at')->get();
    $firstId = $notifications->first()->getKey();
    $secondId = $notifications->last()->getKey();

    $this->actingAs($user)
        ->from(route('workspace.notifications.index', ['workspace' => $workspace]))
        ->patch(route('workspace.notifications.read', ['workspace' => $workspace, 'notification' => $firstId]))
        ->assertRedirect(route('workspace.notifications.index', ['workspace' => $workspace]));

    expect($user->notifications()->findOrFail($firstId)->read_at)->not->toBeNull();
    expect($user->notifications()->findOrFail($secondId)->read_at)->toBeNull();

    $this->actingAs($user)
        ->patch(route('workspace.notifications.read', ['workspace' => $workspace, 'notification' => $firstId]))
        ->assertRedirect();

    expect($user->notifications()->findOrFail($firstId)->read_at)->not->toBeNull();
    expect($user->unreadNotifications()->count())->toBe(1);
});

test('a user can not mark another user\'s notification as read', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);
    failingTarget($owner, $workspace, 'Owner post');

    $outsider = User::factory()->create();

    $notificationId = $owner->notifications()->first()->getKey();

    $this->actingAs($outsider)
        ->patch(route('workspace.notifications.read', ['workspace' => $workspace, 'notification' => $notificationId]))
        ->assertNotFound();

    expect($owner->notifications()->findOrFail($notificationId)->read_at)->toBeNull();
});
