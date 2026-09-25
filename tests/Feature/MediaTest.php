<?php

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Enums\MediaStatus;
use App\Enums\WorkspaceRole;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

test('the media intent endpoint rejects a disallowed mime type', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $this->actingAs($user)
        ->withHeaders(['Accept' => 'application/json'])
        ->post(route('workspace.media.intent', ['workspace' => $workspace]), [
            'mime_type' => 'application/pdf',
            'size_bytes' => 1000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('mime_type');
});

test('the media intent endpoint returns a server key and presigned upload url', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $adapter = Mockery::mock(FilesystemAdapter::class);
    $adapter->shouldReceive('temporaryUploadUrl')
        ->once()
        ->withArgs(fn (string $path): bool => str_starts_with(
            $path,
            config('media.key_prefix').'/'.$workspace->getKey().'/',
        ) && str_ends_with($path, '.jpg'))
        ->andReturn(['url' => 'https://bucket.example.com/presigned', 'headers' => []]);

    Storage::shouldReceive('disk')->with('s3')->once()->andReturn($adapter);

    $response = $this->actingAs($user)
        ->post(route('workspace.media.intent', ['workspace' => $workspace]), [
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
        ]);

    $response->assertOk()
        ->assertJson(['upload_url' => 'https://bucket.example.com/presigned', 'type' => 'image']);

    expect($response->json('path'))
        ->toStartWith(config('media.key_prefix').'/'.$workspace->getKey().'/')
        ->toEndWith('.jpg');
});

test('the media complete endpoint registers an uploaded object as a media row', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $path = config('media.key_prefix').'/'.$workspace->getKey().'/uploaded.jpg';
    Storage::disk('s3')->put($path, 'binary-bytes');

    $response = $this->actingAs($user)
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 12,
            'width' => 800,
            'height' => 600,
        ]);

    $response->assertCreated()
        ->assertJsonPath('media.id', fn (string $id): bool => $id !== '');

    $media = Media::where('workspace_id', $workspace->id)->first();

    expect($media)->not->toBeNull();
    expect($media->disk)->toBe(config('media.disk'));
    expect($media->path)->toBe($path);
    expect($media->mime_type)->toBe('image/jpeg');
    expect($media->status)->toBe(MediaStatus::Ready);
    expect($media->width)->toBe(800);
    expect($media->height)->toBe(600);
});

test('the media complete endpoint rejects a path outside the workspace prefix', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $this->actingAs($user)
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'path' => 'other/object.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 12,
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Unknown upload.']);

    expect(Media::where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('the media complete endpoint rejects an object that was never uploaded', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $path = config('media.key_prefix').'/'.$workspace->getKey().'/missing.jpg';

    $this->actingAs($user)
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 12,
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Uploaded object was not found.']);

    expect(Media::where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('the media index lists only the workspace media with eager metadata', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Media::factory()->for($workspace)->create([
        'uploaded_by_user_id' => $user->id,
        'path' => 'posts/unattached.jpg',
    ]);

    $attached = Media::factory()->for($workspace)->create([
        'uploaded_by_user_id' => $user->id,
        'path' => 'posts/attached.jpg',
    ]);

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id]);
    $post->media()->attach($attached, ['position' => 0]);

    $otherWorkspace = Workspace::factory()->create();
    Media::factory()->for($otherWorkspace)->create();

    $this->actingAs($user)
        ->get(route('workspace.media', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Media/Index')
            ->where('media.total', 2)
            ->has('media.data', 2)
            ->where('media.data.0.uploaded_by', $user->name)
            ->where('media.data.0.attached_to_post', true)
            ->where('media.data.0.posted_count', 1)
            ->where('media.data.1.attached_to_post', false));
});

test('the media index paginates for infinite scroll', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Media::factory()->count(25)->for($workspace)->create([
        'uploaded_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('workspace.media', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Media/Index')
            ->where('media.total', 25)
            ->has('media.data', 24));

    $this->actingAs($user)
        ->get(route('workspace.media', ['workspace' => $workspace, 'page' => 2]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('media.total', 25)
            ->has('media.data', 1));
});

test('the media pages return 404 for a user who is not a member', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('workspace.media', ['workspace' => $workspace]))
        ->assertNotFound();

    $media = Media::factory()->for($workspace)->create();

    $this->actingAs($outsider)
        ->delete(route('workspace.media.destroy', ['workspace' => $workspace, 'media' => $media]))
        ->assertNotFound();
});

test('deleting unattached media soft-deletes the row and removes its storage object', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $media = Media::factory()->for($workspace)->create([
        'uploaded_by_user_id' => $user->id,
        'path' => 'posts/to-delete.jpg',
    ]);
    Storage::disk('s3')->put($media->path, 'bytes');

    $this->actingAs($user)
        ->delete(route('workspace.media.destroy', ['workspace' => $workspace, 'media' => $media]))
        ->assertRedirect(route('workspace.media', ['workspace' => $workspace]))
        ->assertSessionHas('flash.success');

    $this->assertSoftDeleted('media', ['id' => $media->id]);
    Storage::disk('s3')->assertMissing($media->path);
});

test('deleting media attached to a post is rejected', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $media = Media::factory()->for($workspace)->create([
        'uploaded_by_user_id' => $user->id,
        'path' => 'posts/kept.jpg',
    ]);
    Storage::disk('s3')->put($media->path, 'bytes');

    $post = Post::factory()->for($workspace)->create(['created_by_user_id' => $user->id]);
    $post->media()->attach($media, ['position' => 0]);

    $this->actingAs($user)
        ->delete(route('workspace.media.destroy', ['workspace' => $workspace, 'media' => $media]))
        ->assertRedirect(route('workspace.media', ['workspace' => $workspace]))
        ->assertSessionHas('flash.error');

    $this->assertDatabaseHas('media', ['id' => $media->id, 'deleted_at' => null]);
    Storage::disk('s3')->assertExists($media->path);
});

test('deleting media from another workspace returns 404', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $otherWorkspace = Workspace::factory()->create();
    $otherWorkspace->users()->attach($user, ['role' => WorkspaceRole::Owner]);

    $media = Media::factory()->for($otherWorkspace)->create();

    $this->actingAs($user)
        ->delete(route('workspace.media.destroy', ['workspace' => $workspace, 'media' => $media]))
        ->assertNotFound();
});

test('the media index renders with a constant query count regardless of volume', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    DB::enableQueryLog();

    $this->actingAs($user)
        ->get(route('workspace.media', ['workspace' => $workspace]))
        ->assertOk();
    $small = count(DB::getQueryLog());

    Media::factory()->count(24)->for($workspace)->create([
        'uploaded_by_user_id' => $user->id,
    ]);

    DB::flushQueryLog();

    $this->actingAs($user)
        ->get(route('workspace.media', ['workspace' => $workspace]))
        ->assertOk();
    $large = count(DB::getQueryLog());

    expect(abs($large - $small))->toBeLessThanOrEqual(1);
});
