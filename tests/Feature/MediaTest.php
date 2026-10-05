<?php

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Enums\MediaStatus;
use App\Enums\WorkspaceRole;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('filesystems.disks.s3.bucket', 'test-bucket');
    config()->set('filesystems.disks.s3.key', 'test-access-key');
    config()->set('filesystems.disks.s3.secret', 'test-secret-key');
});

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

/**
 * Reserve an upload through the real intent endpoint and return both the opaque
 * upload id and the object key the signed policy was pinned to.
 *
 * A presigned POST has to expose the key as a form field — S3 matches it against
 * the policy — so the key is read back out of fields.key here rather than parsed
 * out of a URL. That the client can read it is expected; that it can change it is
 * not, which is what the policy's `eq $key` condition enforces.
 *
 * @return array{upload_id: string, path: string}
 */
function reserveMediaUpload(User $user, Workspace $workspace, array $attributes = []): array
{
    $intent = test()
        ->actingAs($user)
        ->post(route('workspace.media.intent', ['workspace' => $workspace]), array_merge([
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
            'width' => 800,
            'height' => 600,
        ], $attributes));

    $intent->assertOk();

    return [
        'upload_id' => $intent->json('upload_id'),
        'path' => (string) $intent->json('fields.key'),
    ];
}

/**
 * A byte string that actually starts with a JPEG signature.
 */
function jpegBytes(int $length = 64): string
{
    return str_pad("\xFF\xD8\xFF\xE0", $length, "\x00");
}

test('the media intent endpoint pins the key and content type in the signed policy', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $response = $this->actingAs($user)
        ->post(route('workspace.media.intent', ['workspace' => $workspace]), [
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
        ]);

    $response->assertOk()
        ->assertJson(['type' => 'image'])
        ->assertJsonStructure(['upload_id', 'upload_url', 'fields', 'expires_at']);

    expect($response->json('upload_id'))->toBeString()->toHaveLength(40);

    $fields = $response->json('fields');

    expect($fields['key'])
        ->toStartWith(config('media.key_prefix').'/'.$workspace->getKey().'/')
        ->toEndWith('.jpg');

    expect($fields)->not->toHaveKey('acl');
    expect($fields['Policy'])->not->toBe('');
    expect($fields)->toHaveKeys([
        'key',
        'Policy',
        'X-Amz-Algorithm',
        'X-Amz-Credential',
        'X-Amz-Date',
        'X-Amz-Signature',
    ]);

    $policy = json_decode((string) base64_decode($fields['Policy']), true);

    expect($policy['conditions'])
        ->toContain(['eq', '$Content-Type', 'image/jpeg'])
        ->toContain(['eq', '$key', $fields['key']])
        ->toContain(['bucket' => 'test-bucket']);
});

test('the signed policy never lets the browser choose the object key', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $response = $this->actingAs($user)
        ->post(route('workspace.media.intent', ['workspace' => $workspace]), [
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
        ]);

    // PostObjectV4 falls back to the literal ${filename} — which hands key
    // selection to the browser — whenever `key` is absent from the form inputs.
    expect($response->json('fields.key'))->not->toContain('${filename}');
});

test('the media intent endpoint fails loudly when the media disk is not s3', function () {
    Storage::fake('s3');

    config()->set('media.disk', 'local');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    // Upload bytes are never proxied through the application, so there is no
    // local-disk fallback to offer.
    $this->actingAs($user)
        ->post(route('workspace.media.intent', ['workspace' => $workspace]), [
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
        ])
        ->assertStatus(500);

    expect(Media::count())->toBe(0);
});

test('the media complete endpoint rejects a path traversal payload', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $otherOwner = User::factory()->create();
    $otherWorkspace = app(CreateWorkspaceAction::class)->ensure($otherOwner);

    $victimPath = config('media.key_prefix').'/'.$otherWorkspace->getKey().'/victim.jpg';
    Storage::disk('s3')->put($victimPath, jpegBytes());

    $traversal = config('media.key_prefix')
        .'/'.$workspace->getKey()
        .'/../../'.$otherWorkspace->getKey().'/victim.jpg';

    $this->actingAs($user)
        ->withHeaders(['Accept' => 'application/json'])
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'upload_id' => $traversal,
        ])
        ->assertStatus(422);

    expect(Media::count())->toBe(0);
});

test('the media complete endpoint registers an uploaded object as a media row', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $upload = reserveMediaUpload($user, $workspace);

    Storage::disk('s3')->put($upload['path'], jpegBytes());

    $response = $this->actingAs($user)
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'upload_id' => $upload['upload_id'],
        ]);

    $response->assertCreated()
        ->assertJsonPath('media.id', fn (string $id): bool => $id !== '');

    $media = Media::where('workspace_id', $workspace->id)->first();

    expect($media)->not->toBeNull();
    expect($media->disk)->toBe(config('media.disk'));
    expect($media->path)->toBe($upload['path']);
    expect($media->mime_type)->toBe('image/jpeg');
    expect($media->status)->toBe(MediaStatus::Ready);
    expect($media->width)->toBe(800);
    expect($media->height)->toBe(600);
    expect($media->size_bytes)->toBe(64);
});

test('the media complete endpoint refuses an unknown upload id', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $this->actingAs($user)
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'upload_id' => str_repeat('a', 40),
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Unknown upload.']);

    expect(Media::count())->toBe(0);
});

test('the media complete endpoint refuses an upload id that is not a client path', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $path = config('media.key_prefix').'/'.$workspace->getKey().'/uploaded.jpg';
    Storage::disk('s3')->put($path, jpegBytes());

    $this->actingAs($user)
        ->withHeaders(['Accept' => 'application/json'])
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'upload_id' => $path,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('upload_id');

    expect(Media::count())->toBe(0);
});

test('the media complete endpoint refuses an upload id from another workspace', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $otherOwner = User::factory()->create();
    $otherWorkspace = app(CreateWorkspaceAction::class)->ensure($otherOwner);

    $upload = reserveMediaUpload($otherOwner, $otherWorkspace);
    Storage::disk('s3')->put($upload['path'], jpegBytes());

    $this->actingAs($user)
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'upload_id' => $upload['upload_id'],
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Unknown upload.']);

    expect(Media::count())->toBe(0);
});

test('the media complete endpoint refuses an upload id issued to another user', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $teammate = User::factory()->create();
    $workspace->users()->attach($teammate, ['role' => WorkspaceRole::Owner]);

    $upload = reserveMediaUpload($user, $workspace);
    Storage::disk('s3')->put($upload['path'], jpegBytes());

    $this->actingAs($teammate)
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'upload_id' => $upload['upload_id'],
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Unknown upload.']);

    expect(Media::count())->toBe(0);
});

test('the media complete endpoint rejects an object that was never uploaded', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $upload = reserveMediaUpload($user, $workspace);

    $this->actingAs($user)
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'upload_id' => $upload['upload_id'],
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Uploaded object was not found.']);

    expect(Media::count())->toBe(0);
});

test('the media complete endpoint rejects bytes that are not the declared mime type', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $upload = reserveMediaUpload($user, $workspace, ['mime_type' => 'image/png']);

    Storage::disk('s3')->put($upload['path'], '<script>alert(document.domain)</script>');

    $this->actingAs($user)
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'upload_id' => $upload['upload_id'],
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'The uploaded file is not a valid image/png file.']);

    expect(Media::count())->toBe(0);

    Storage::disk('s3')->assertMissing($upload['path']);
});

test('the media complete endpoint rejects a mislabelled image whose bytes are another type', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $upload = reserveMediaUpload($user, $workspace, ['mime_type' => 'image/png']);

    Storage::disk('s3')->put($upload['path'], jpegBytes());

    $this->actingAs($user)
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'upload_id' => $upload['upload_id'],
        ])
        ->assertStatus(422);

    expect(Media::count())->toBe(0);
});

test('the media complete endpoint rejects an object larger than the configured maximum', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $upload = reserveMediaUpload($user, $workspace);

    Storage::disk('s3')->put($upload['path'], jpegBytes((int) config('media.max_size') + 1));

    $this->actingAs($user)
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'upload_id' => $upload['upload_id'],
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'The uploaded file is not an allowed size.']);

    expect(Media::count())->toBe(0);

    Storage::disk('s3')->assertMissing($upload['path']);
});

test('the media complete endpoint only accepts an upload id once', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $upload = reserveMediaUpload($user, $workspace);
    Storage::disk('s3')->put($upload['path'], jpegBytes());

    $this->actingAs($user)
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'upload_id' => $upload['upload_id'],
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'upload_id' => $upload['upload_id'],
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Unknown upload.']);

    expect(Media::count())->toBe(1);
});

test('the media complete endpoint rejects an object stored as an active content type', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $upload = reserveMediaUpload($user, $workspace);

    Storage::disk('s3')->put($upload['path'], jpegBytes());

    // Storage::fake infers the type from the bytes on disk and has no notion of
    // the Content-Type an object was actually stored with, so the metadata layer
    // is stubbed to reproduce a real bucket that labelled the object text/html.
    $disk = Storage::disk('s3');
    $labelledHtml = Mockery::mock($disk)->makePartial();
    $labelledHtml->shouldReceive('mimeType')->andReturn('text/html');
    Storage::set('s3', $labelledHtml);

    $this->actingAs($user)
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'upload_id' => $upload['upload_id'],
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'The uploaded file was stored as an unsafe content type.']);

    expect(Media::count())->toBe(0);

    $disk->assertMissing($upload['path']);
});

test('the media complete endpoint stores a signature polyglot under its declared media type', function () {
    Storage::fake('s3');

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $upload = reserveMediaUpload($user, $workspace, ['mime_type' => 'image/gif']);

    // A GIF magic number is six bytes of ASCII, so the remainder is parsed as
    // markup. Byte sniffing reads only the header and so passes this. What makes
    // it safe is that the upload policy pins Content-Type to image/gif, so S3
    // can only store it as a GIF and the object can never be served as a
    // document. This test pins that invariant — if the object ever comes back
    // labelled text/html, complete() must start rejecting it.
    Storage::disk('s3')->put(
        $upload['path'],
        'GIF89a<html><script>alert(document.domain)</script>',
    );

    $this->actingAs($user)
        ->post(route('workspace.media.complete', ['workspace' => $workspace]), [
            'upload_id' => $upload['upload_id'],
        ])
        ->assertCreated();

    expect(Media::sole()->mime_type)->toBe('image/gif');
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
            ->where('media.data.0.uploaded_by', $user->fullname)
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
