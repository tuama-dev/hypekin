<?php

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

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
