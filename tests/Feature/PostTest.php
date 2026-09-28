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
use App\Enums\WorkspaceRole;
use App\Jobs\CheckTikTokPublishStatusJob;
use App\Jobs\PublishPostTargetJob;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\PostTargetFailedNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

function linkedAccount(User $user, array $attributes = []): SocialAccount
{
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    return SocialAccount::factory()->create(array_merge([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ], $attributes));
}

test('the posts index renders a post with its target chips', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Facebook]);

    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Published,
    ]);
    PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'status' => PostTargetStatus::Published,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.posts', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Posts/Index')
            ->where('auth.workspace.slug', $workspace->slug)
            ->has('posts', 1)
            ->where('posts.0.status.value', 'published')
            ->where('posts.0.targets.0.status.value', 'published'));
});

test('the posts index filters by status', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Facebook]);

    $scheduled = Post::factory()->for($workspace)->scheduled('2026-01-15 10:00:00')->create([
        'created_by_user_id' => $user->id,
    ]);
    PostTarget::factory()->pending()->create([
        'post_id' => $scheduled->id,
        'social_account_id' => $account->id,
    ]);

    $published = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Published,
    ]);
    PostTarget::factory()->create([
        'post_id' => $published->id,
        'social_account_id' => $account->id,
        'status' => PostTargetStatus::Published,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.posts', ['workspace' => $workspace, 'status' => 'scheduled']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts', 1)
            ->where('posts.0.id', $scheduled->id)
            ->where('filters.status', 'scheduled'));

    $this->actingAs($user)
        ->get(route('workspace.posts', ['workspace' => $workspace, 'status' => 'published']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts', 1)
            ->where('posts.0.id', $published->id));
});

test('the posts index filters by account', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $facebook = linkedAccount($user, [
        'platform' => Platform::Facebook,
        'display_name' => 'Acme Facebook',
    ]);
    $instagram = linkedAccount($user, [
        'platform' => Platform::Instagram,
        'display_name' => 'Acme Instagram',
    ]);

    $facebookPost = Post::factory()->for($workspace)->scheduled('2026-01-15 10:00:00')->create([
        'created_by_user_id' => $user->id,
    ]);
    PostTarget::factory()->pending()->create([
        'post_id' => $facebookPost->id,
        'social_account_id' => $facebook->id,
        'caption' => 'Coffee corner',
    ]);

    $instagramPost = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Published,
    ]);
    PostTarget::factory()->create([
        'post_id' => $instagramPost->id,
        'social_account_id' => $instagram->id,
        'caption' => 'Roastery tour',
        'status' => PostTargetStatus::Published,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.posts', ['workspace' => $workspace, 'account' => $facebook->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts', 1)
            ->where('posts.0.id', $facebookPost->id)
            ->where('filters.account', $facebook->id)
            ->has('accounts', 2)
            ->where('accounts.0.display_name', 'Acme Facebook')
            ->where('accounts.0.platform.value', 'facebook')
            ->where('accounts.0.status.value', 'connected')
            ->where('accounts.0.avatar_url', null)
            ->where('accounts.1.platform.value', 'instagram'));

    $this->actingAs($user)
        ->get(route('workspace.posts', ['workspace' => $workspace, 'account' => $instagram->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts', 1)
            ->where('posts.0.id', $instagramPost->id));

    $this->actingAs($user)
        ->get(route('workspace.posts', ['workspace' => $workspace, 'account' => '00000000000000000000000000']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts', 0)
            ->where('filters.account', '00000000000000000000000000'));
});

test('the posts index searches captions and titles', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Tiktok]);

    $matching = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Published,
    ]);
    PostTarget::factory()->create([
        'post_id' => $matching->id,
        'social_account_id' => $account->id,
        'caption' => 'A post about single-origin coffee',
        'title' => 'Barista series',
    ]);

    $other = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Published,
    ]);
    PostTarget::factory()->create([
        'post_id' => $other->id,
        'social_account_id' => $account->id,
        'caption' => 'Unrelated packaging update',
    ]);

    $this->actingAs($user)
        ->get(route('workspace.posts', ['workspace' => $workspace, 'search' => 'single-origin']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts', 1)
            ->where('posts.0.id', $matching->id)
            ->where('posts.0.caption', 'A post about single-origin coffee')
            ->where('posts.0.title', 'Barista series')
            ->where('filters.search', 'single-origin'));

    $this->actingAs($user)
        ->get(route('workspace.posts', ['workspace' => $workspace, 'search' => 'Barista']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts', 1)
            ->where('posts.0.id', $matching->id));
});

test('the posts index rejects an unknown status filter', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $this->actingAs($user)
        ->get(route('workspace.posts', ['workspace' => $workspace, 'status' => 'nonsense']))
        ->assertSessionHasErrors('status');
});

test('the posts create page lists the connected publishable accounts', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, [
        'platform' => Platform::Instagram,
        'display_name' => 'Acme Instagram',
    ]);

    $this->actingAs($user)
        ->get(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Posts/Create')
            ->where('publishablePlatforms', ['Facebook', 'Instagram', 'LinkedIn', 'TikTok'])
            ->has('publishableAccounts', 1)
            ->where('publishableAccounts.0.id', $account->id)
            ->where('publishableAccounts.0.platform.value', 'instagram'));
});

test('the posts pages return 404 for a user who is not a member', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('workspace.posts', ['workspace' => $workspace]))
        ->assertNotFound();

    $this->actingAs($outsider)
        ->post(route('workspace.posts.store', ['workspace' => $workspace]), [
            'caption' => 'Hello',
            'targets' => [],
        ])
        ->assertNotFound();

    expect($workspace->posts()->count())->toBe(0);
});

test('an empty post payload is rejected', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $this->actingAs($user)
        ->from(route('workspace.posts.create', ['workspace' => $workspace]))
        ->post(route('workspace.posts.store', ['workspace' => $workspace]), [])
        ->assertRedirect(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertSessionHasErrors(['caption', 'targets']);

    expect($workspace->posts()->count())->toBe(0);
});

test('an account outside the workspace can not be selected', function () {
    $owner = User::factory()->create();
    $foreignWorkspace = app(CreateWorkspaceAction::class)->ensure($owner);
    $foreignAccount = SocialAccount::factory()->create(['workspace_id' => $foreignWorkspace->id]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $this->actingAs($user)
        ->from(route('workspace.posts.create', ['workspace' => $workspace]))
        ->post(route('workspace.posts.store', ['workspace' => $workspace]), [
            'caption' => 'Hello',
            'targets' => [$foreignAccount->id],
        ])
        ->assertRedirect(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertSessionHasErrors('targets.0');

    expect($workspace->posts()->count())->toBe(0);
});

test('a disconnected account can not be selected', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, [
        'platform' => Platform::Facebook,
        'status' => SocialAccountStatus::Revoked,
    ]);

    $this->actingAs($user)
        ->from(route('workspace.posts.create', ['workspace' => $workspace]))
        ->post(route('workspace.posts.store', ['workspace' => $workspace]), [
            'caption' => 'Hello',
            'targets' => [$account->id],
        ])
        ->assertRedirect(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertSessionHasErrors('targets');

    expect($workspace->posts()->count())->toBe(0);
});

test('an Instagram text-only post is rejected', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Instagram]);

    $this->actingAs($user)
        ->from(route('workspace.posts.create', ['workspace' => $workspace]))
        ->post(route('workspace.posts.store', ['workspace' => $workspace]), [
            'caption' => 'Hello',
            'targets' => [$account->id],
        ])
        ->assertRedirect(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertSessionHasErrors('media_id');

    expect($workspace->posts()->count())->toBe(0);
});

test('a Facebook text-only post is accepted immediately', function () {
    Queue::fake([PublishPostTargetJob::class]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Facebook]);

    $this->actingAs($user)
        ->post(route('workspace.posts.store', ['workspace' => $workspace]), [
            'caption' => 'Hello world!',
            'targets' => [$account->id],
        ])
        ->assertRedirect(route('workspace.posts', ['workspace' => $workspace]))
        ->assertSessionHas('flash.success');

    $post = $workspace->posts()->firstOrFail();

    expect($post->status)->toBe(PostStatus::Publishing);
    expect($post->scheduled_at)->toBeNull();
    expect($post->targets)->toHaveCount(1);
    expect($post->targets->first()->status)->toBe(PostTargetStatus::Pending);

    Queue::assertPushed(PublishPostTargetJob::class, 1);
});

test('a scheduled post persists a future date and delays dispatch', function () {
    Queue::fake([PublishPostTargetJob::class]);
    $this->travelTo('2026-01-01 09:00:00');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Facebook]);

    $this->actingAs($user)
        ->post(route('workspace.posts.store', ['workspace' => $workspace]), [
            'caption' => 'Scheduled hello',
            'targets' => [$account->id],
            'scheduled_at' => '2026-01-15 10:00:00',
        ])
        ->assertRedirect(route('workspace.posts', ['workspace' => $workspace]))
        ->assertSessionHas('flash.success');

    $post = $workspace->posts()->firstOrFail();

    expect($post->status)->toBe(PostStatus::Scheduled);
    expect($post->scheduled_at->toDateTimeString())->toBe('2026-01-15 10:00:00');

    Queue::assertPushed(PublishPostTargetJob::class, 1);
});

test('a post with an image attaches the media via the pivot', function () {
    Queue::fake([PublishPostTargetJob::class]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Facebook]);
    $media = Media::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->post(route('workspace.posts.store', ['workspace' => $workspace]), [
            'caption' => 'Hello with image',
            'targets' => [$account->id],
            'media_id' => $media->id,
        ])
        ->assertRedirect(route('workspace.posts', ['workspace' => $workspace]));

    $post = $workspace->posts()->firstOrFail();

    expect($post->targets)->toHaveCount(1);
    expect($post->media)->toHaveCount(1);
    expect($post->media->first()->id)->toBe($media->id);

    $this->assertDatabaseHas('post_media', [
        'post_id' => $post->id,
        'media_id' => $media->id,
        'position' => 0,
    ]);
});

function runPublishJob(PostTarget $target): void
{
    $job = new PublishPostTargetJob($target);

    $job->handle(
        app(PublishToFacebookAction::class),
        app(PublishToInstagramAction::class),
        app(PublishToLinkedInAction::class),
        app(PublishToTikTokAction::class),
    );
}

test('the job publishes a Facebook text target to the page feed', function () {
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/*/feed' => Http::response(['id' => 'fb-post-123']),
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Facebook, 'external_account_id' => 'page-1']);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Publishing]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'caption' => 'Hello from the feed',
    ]);

    runPublishJob($target);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/page-1/feed')
        && $request->data()['message'] === 'Hello from the feed');

    $target->refresh();

    expect($target->status)->toBe(PostTargetStatus::Published);
    expect($target->platform_post_id)->toBe('fb-post-123');
    expect($target->published_at)->not->toBeNull();
    expect($target->post->status)->toBe(PostStatus::Published);
});

test('the job publishes a Facebook post with an image to the page photos', function () {
    Storage::fake('s3');
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/*/photos' => Http::response(['id' => 'fb-photo-123']),
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Facebook, 'external_account_id' => 'page-1']);
    $media = Media::factory()->create(['workspace_id' => $workspace->id]);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Publishing]);
    $post->media()->attach($media->id, ['position' => 0]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'caption' => 'Image post',
    ]);

    runPublishJob($target);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/page-1/photos')
        && $request->data()['url'] === $media->publicUrl());

    $target->refresh();

    expect($target->status)->toBe(PostTargetStatus::Published);
    expect($target->platform_post_id)->toBe('fb-photo-123');
    expect($target->post->status)->toBe(PostStatus::Published);
});

test('the job publishes an Instagram target through the two-step media flow', function () {
    Storage::fake('s3');
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/*/media_publish' => Http::response(['id' => 'ig-post-123']),
        'graph.facebook.com/*/media' => Http::response(['id' => 'creation-1']),
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Instagram, 'external_account_id' => 'ig-123']);
    $media = Media::factory()->create(['workspace_id' => $workspace->id]);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Publishing]);
    $post->media()->attach($media->id, ['position' => 0]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'caption' => 'Instagram hello',
    ]);

    runPublishJob($target);

    Http::assertSent(function (Request $request): bool {
        if (str_contains($request->url(), '/media_publish')) {
            return $request->data()['creation_id'] === 'creation-1';
        }

        return str_contains($request->url(), '/ig-123/media')
            && $request->data()['image_url'] !== '';
    });

    $target->refresh();

    expect($target->status)->toBe(PostTargetStatus::Published);
    expect($target->platform_post_id)->toBe('ig-post-123');
    expect($target->post->status)->toBe(PostStatus::Published);
});

test('the job marks a Facebook target failed when the platform rejects it', function () {
    Notification::fake();
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/*/feed' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 400),
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Facebook, 'external_account_id' => 'page-1']);

    $colleague = User::factory()->create();
    $workspace->users()->attach($colleague, ['role' => WorkspaceRole::Editor]);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Publishing]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'caption' => 'Will fail',
    ]);

    runPublishJob($target);

    $target->refresh();

    expect($target->status)->toBe(PostTargetStatus::Failed);
    expect($target->error_message)->toBe('Invalid OAuth access token.');
    expect($target->platform_post_id)->toBeNull();
    expect($target->post->status)->toBe(PostStatus::Failed);

    Notification::assertSentTo($user, PostTargetFailedNotification::class);
    Notification::assertNotSentTo($colleague, PostTargetFailedNotification::class);
});

test('the job fails an Instagram target that has no image', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Instagram, 'external_account_id' => 'ig-123']);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Publishing]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'caption' => 'No image',
    ]);

    runPublishJob($target);

    $target->refresh();

    expect($target->status)->toBe(PostTargetStatus::Failed);
    expect($target->error_message)->toContain('require an image');
    expect($target->post->status)->toBe(PostStatus::Failed);
});

test('a retry skips a target that was already published', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Facebook, 'external_account_id' => 'page-1']);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Published]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'status' => PostTargetStatus::Published,
        'platform_post_id' => 'fb-post-123',
    ]);

    runPublishJob($target);

    Http::assertNothingSent();

    $target->refresh();
    expect($target->status)->toBe(PostTargetStatus::Published);
    expect($target->platform_post_id)->toBe('fb-post-123');
});

test('the posts create page lists LinkedIn and TikTok accounts as publishable', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $linkedin = linkedAccount($user, ['platform' => Platform::LinkedIn, 'display_name' => 'Acme LinkedIn']);
    $tiktok = linkedAccount($user, ['platform' => Platform::Tiktok, 'display_name' => 'Acme TikTok']);

    $this->actingAs($user)
        ->get(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Posts/Create')
            ->where('publishablePlatforms', ['Facebook', 'Instagram', 'LinkedIn', 'TikTok'])
            ->has('publishableAccounts', 2)
            ->where('publishableAccounts.0.platform.value', 'linkedin')
            ->where('publishableAccounts.1.platform.value', 'tiktok'));
});

test('a LinkedIn text-only post is accepted', function () {
    Queue::fake([PublishPostTargetJob::class]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::LinkedIn, 'external_account_id' => 'member-42']);

    $this->actingAs($user)
        ->post(route('workspace.posts.store', ['workspace' => $workspace]), [
            'caption' => 'Hello LinkedIn',
            'targets' => [$account->id],
        ])
        ->assertRedirect(route('workspace.posts', ['workspace' => $workspace]))
        ->assertSessionHas('flash.success');

    $post = $workspace->posts()->firstOrFail();

    expect($post->status)->toBe(PostStatus::Publishing);
    expect($post->targets->first()->caption)->toBe('Hello LinkedIn');
    expect($post->targets->first()->title)->toBeNull();

    Queue::assertPushed(PublishPostTargetJob::class, 1);
});

test('a TikTok text-only post without a title or image is rejected', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Tiktok]);

    $this->actingAs($user)
        ->from(route('workspace.posts.create', ['workspace' => $workspace]))
        ->post(route('workspace.posts.store', ['workspace' => $workspace]), [
            'caption' => 'Hello',
            'targets' => [$account->id],
        ])
        ->assertRedirect(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertSessionHasErrors(['media_id', 'title']);

    expect($workspace->posts()->count())->toBe(0);
});

test('a TikTok post with a title but no image is rejected', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Tiktok]);

    $this->actingAs($user)
        ->from(route('workspace.posts.create', ['workspace' => $workspace]))
        ->post(route('workspace.posts.store', ['workspace' => $workspace]), [
            'caption' => 'Hello',
            'targets' => [$account->id],
            'title' => 'Launch day',
        ])
        ->assertRedirect(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertSessionHasErrors('media_id');
});

test('a TikTok post with an image but no title is rejected', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Tiktok]);
    $media = Media::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->from(route('workspace.posts.create', ['workspace' => $workspace]))
        ->post(route('workspace.posts.store', ['workspace' => $workspace]), [
            'caption' => 'Hello',
            'targets' => [$account->id],
            'media_id' => $media->id,
        ])
        ->assertRedirect(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertSessionHasErrors('title');
});

test('a TikTok post with a title and image is accepted and persists both', function () {
    Queue::fake([PublishPostTargetJob::class]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Tiktok]);
    $media = Media::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->post(route('workspace.posts.store', ['workspace' => $workspace]), [
            'caption' => 'Hello TikTok',
            'targets' => [$account->id],
            'media_id' => $media->id,
            'title' => 'Launch day',
        ])
        ->assertRedirect(route('workspace.posts', ['workspace' => $workspace]))
        ->assertSessionHas('flash.success');

    $post = $workspace->posts()->firstOrFail();
    $target = $post->targets->first();

    expect($post->status)->toBe(PostStatus::Publishing);
    expect($post->media)->toHaveCount(1);
    expect($target->caption)->toBe('Hello TikTok');
    expect($target->title)->toBe('Launch day');

    Queue::assertPushed(PublishPostTargetJob::class, 1);
});

test('the job publishes a LinkedIn text target to the member feed', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.linkedin.com/v2/ugcPosts' => Http::response(['id' => 'urn:li:share:li-post-123']),
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::LinkedIn, 'external_account_id' => 'member-42']);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Publishing]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'caption' => 'Hello LinkedIn',
    ]);

    runPublishJob($target);

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://api.linkedin.com/v2/ugcPosts') {
            return false;
        }

        $shareContent = $request['specificContent']['com.linkedin.ugc.ShareContent'];

        return $request->header('X-Restli-Protocol-Version') === ['2.0.0']
            && $request['author'] === 'urn:li:person:member-42'
            && $request['lifecycleState'] === 'PUBLISHED'
            && $shareContent['shareMediaCategory'] === 'NONE'
            && $shareContent['shareCommentary']['text'] === 'Hello LinkedIn';
    });

    $target->refresh();

    expect($target->status)->toBe(PostTargetStatus::Published);
    expect($target->platform_post_id)->toBe('urn:li:share:li-post-123');
    expect($target->post->status)->toBe(PostStatus::Published);
});

test('the job publishes a LinkedIn image target through the asset upload flow', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('posts/li-image.jpg', 'fake bytes');
    Http::preventStrayRequests();
    Http::fake([
        'api.linkedin.com/v2/assets?action=registerUpload' => Http::response([
            'value' => [
                'uploadUrlExpirationTimestamp' => 4102444800,
                'uploadMechanism' => [
                    'com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest' => [
                        'uploadUrl' => 'https://upload.example.com/asset-1',
                    ],
                ],
                'asset' => 'urn:li:digitalmediaAsset:asset-1',
            ],
        ]),
        'upload.example.com/*' => Http::response('', 201),
        'api.linkedin.com/v2/ugcPosts' => Http::response(['id' => 'urn:li:share:li-img-123']),
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::LinkedIn, 'external_account_id' => 'member-42']);
    $media = Media::factory()->create([
        'workspace_id' => $workspace->id,
        'path' => 'posts/li-image.jpg',
    ]);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Publishing]);
    $post->media()->attach($media->id, ['position' => 0]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'caption' => 'Image hello',
    ]);

    runPublishJob($target);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://upload.example.com/asset-1'
        && $request->method() === 'PUT');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.linkedin.com/v2/ugcPosts'
        && $request['specificContent']['com.linkedin.ugc.ShareContent']['media'][0]['media'] === 'urn:li:digitalmediaAsset:asset-1');

    $target->refresh();

    expect($target->status)->toBe(PostTargetStatus::Published);
    expect($target->platform_post_id)->toBe('urn:li:share:li-img-123');
});

test('the job marks a LinkedIn target failed when the platform rejects it', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.linkedin.com/v2/ugcPosts' => Http::response(['message' => 'Member does not have permission to create posts.'], 403),
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::LinkedIn, 'external_account_id' => 'member-42']);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Publishing]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'caption' => 'Will fail',
    ]);

    runPublishJob($target);

    $target->refresh();

    expect($target->status)->toBe(PostTargetStatus::Failed);
    expect($target->error_message)->toBe('Member does not have permission to create posts.');
    expect($target->platform_post_id)->toBeNull();
    expect($target->post->status)->toBe(PostStatus::Failed);
});

test('a partial target failure rolls the whole post up as failed', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $first = linkedAccount($user, ['platform' => Platform::Facebook, 'external_account_id' => 'member-first']);
    $second = linkedAccount($user, ['platform' => Platform::Facebook, 'external_account_id' => 'member-second']);
    $third = linkedAccount($user, ['platform' => Platform::Facebook, 'external_account_id' => 'member-third']);

    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Publishing,
    ]);

    foreach ([
        ['account' => $first, 'status' => PostTargetStatus::Published],
        ['account' => $second, 'status' => PostTargetStatus::Published],
        ['account' => $third, 'status' => PostTargetStatus::Failed],
    ] as $target) {
        PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $target['account']->id,
            'status' => $target['status'],
        ]);
    }

    $post->recalculateStatus();

    expect($post->fresh()->status)->toBe(PostStatus::Failed);
    expect($post->targets()->count())->toBe(3);
    expect($post->targets()->where('status', PostTargetStatus::Published)->count())->toBe(2);
});

test('target changes roll the post status up through the observer', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $first = linkedAccount($user, ['platform' => Platform::Facebook, 'external_account_id' => 'member-first']);
    $second = linkedAccount($user, ['platform' => Platform::Facebook, 'external_account_id' => 'member-second']);

    $post = Post::factory()->for($workspace)->create([
        'created_by_user_id' => $user->id,
        'status' => PostStatus::Publishing,
    ]);

    $secondTarget = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $second->id,
        'caption' => 'Still pending',
    ]);

    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $first->id,
        'caption' => 'Fails later',
    ]);

    expect($post->fresh()->status)->toBe(PostStatus::Publishing);

    $target->forceFill([
        'status' => PostTargetStatus::Published,
        'platform_post_id' => 'fb-post-123',
        'published_at' => now(),
    ])->save();

    expect($post->fresh()->status)->toBe(PostStatus::Publishing);

    $secondTarget->forceFill([
        'status' => PostTargetStatus::Failed,
        'error_message' => 'The audience targeting tag is not permitted for this account.',
    ])->save();

    expect($post->fresh()->status)->toBe(PostStatus::Failed);
    expect($post->targets()->where('status', PostTargetStatus::Published)->count())->toBe(1);
});

test('the job initializes a TikTok photo post and dispatches the status job', function () {
    Queue::fake();
    Storage::fake('s3');
    Http::preventStrayRequests();
    Http::fake([
        'open.tiktokapis.com/v2/post/publish/content/init/' => Http::response([
            'data' => ['publish_id' => 'publish-123', 'status' => 'PROCESSING_UPLOAD'],
        ]),
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Tiktok, 'external_account_id' => 'tt-1']);
    $media = Media::factory()->create(['workspace_id' => $workspace->id]);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Publishing]);
    $post->media()->attach($media->id, ['position' => 0]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'caption' => 'TikTok hello',
        'title' => 'Launch day',
    ]);

    runPublishJob($target);

    Http::assertSent(function (Request $request) use ($media): bool {
        if (! str_contains($request->url(), '/post/publish/content/init/')) {
            return false;
        }

        return $request['media_type'] === 'PHOTO'
            && $request['post_mode'] === 'DIRECT_POST'
            && $request['post_info']['title'] === 'Launch day'
            && $request['post_info']['description'] === 'TikTok hello'
            && $request['source_info']['source'] === 'PULL_FROM_URL'
            && $request['source_info']['photo_images'] === [$media->publicUrl()];
    });

    $target->refresh();

    expect($target->status)->toBe(PostTargetStatus::Queued);
    expect($target->platform_upload_id)->toBe('publish-123');
    expect($target->platform_post_id)->toBeNull();
    expect($target->post->status)->toBe(PostStatus::Publishing);

    Queue::assertPushed(CheckTikTokPublishStatusJob::class, 1);
});

test('the status job marks a finished TikTok publish as published', function () {
    Http::preventStrayRequests();
    Http::fake([
        'open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE', 'publically_available_post_id' => 'tt-post-1234'],
        ]),
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Tiktok, 'external_account_id' => 'tt-1']);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Publishing]);
    $target = PostTarget::factory()->pending()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform_upload_id' => 'publish-123',
        'caption' => 'TikTok hello',
        'title' => 'Launch day',
    ]);

    $job = new CheckTikTokPublishStatusJob($target);
    $job->handle();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/post/publish/status/fetch/')
        && $request['publish_id'] === 'publish-123');

    $target->refresh();

    expect($target->status)->toBe(PostTargetStatus::Published);
    expect($target->platform_post_id)->toBe('tt-post-1234');
    expect($target->published_at)->not->toBeNull();
    expect($target->post->status)->toBe(PostStatus::Published);
});

test('the status job marks a rejected TikTok publish as failed', function () {
    Notification::fake();
    Http::preventStrayRequests();
    Http::fake([
        'open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'FAILED', 'fail_reason' => 'The media could not be downloaded.'],
        ]),
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Tiktok, 'external_account_id' => 'tt-1']);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id, 'status' => PostStatus::Publishing]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'status' => PostTargetStatus::Queued,
        'platform_post_id' => null,
        'platform_upload_id' => 'publish-123',
        'caption' => 'TikTok hello',
        'title' => 'Launch day',
    ]);

    $job = new CheckTikTokPublishStatusJob($target);
    $job->handle();

    $target->refresh();

    expect($target->status)->toBe(PostTargetStatus::Failed);
    expect($target->error_message)->toContain('The media could not be downloaded.');
    expect($target->post->status)->toBe(PostStatus::Failed);

    Notification::assertSentTo($user, PostTargetFailedNotification::class);
});

test('the status job re-queues itself while TikTok is still processing', function () {
    Queue::fake([CheckTikTokPublishStatusJob::class]);
    Http::preventStrayRequests();
    Http::fake([
        'open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PROCESSING_DOWNLOAD'],
        ]),
    ]);

    $target = PostTarget::factory()->create([
        'status' => PostTargetStatus::Queued,
        'platform_post_id' => null,
        'platform_upload_id' => 'publish-123',
        'retry_count' => 4,
    ]);

    $job = new CheckTikTokPublishStatusJob($target);
    $job->handle();

    $target->refresh();

    expect($target->status)->toBe(PostTargetStatus::Queued);
    expect($target->retry_count)->toBe(5);

    Queue::assertPushed(CheckTikTokPublishStatusJob::class, 1);
});

test('the status job fails a TikTok publish that exceeds the poll budget', function () {
    Http::preventStrayRequests();
    Http::fake([
        'open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PROCESSING_CREATE'],
        ]),
    ]);

    $target = PostTarget::factory()->create([
        'status' => PostTargetStatus::Queued,
        'platform_post_id' => null,
        'platform_upload_id' => 'publish-123',
        'retry_count' => 10,
    ]);

    $job = new CheckTikTokPublishStatusJob($target);
    $job->handle();

    $target->refresh();

    expect($target->status)->toBe(PostTargetStatus::Failed);
    expect($target->error_message)->toContain('still processing');
    expect($target->error_message)->toContain('Timing out');
});
