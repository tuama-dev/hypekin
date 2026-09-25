<?php

use App\Actions\Application\Post\PublishToFacebookAction;
use App\Actions\Application\Post\PublishToInstagramAction;
use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\SocialAccountStatus;
use App\Jobs\PublishPostTargetJob;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/*/feed' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 400),
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = linkedAccount($user, ['platform' => Platform::Facebook, 'external_account_id' => 'page-1']);

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
