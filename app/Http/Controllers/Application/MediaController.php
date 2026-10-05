<?php

namespace App\Http\Controllers\Application;

use App\Actions\Application\Media\Concerns\SniffsMediaMimeTypes;
use App\Actions\Application\Media\CreateMediaUploadPostAction;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MediaController extends Controller
{
    use SniffsMediaMimeTypes;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
    ];

    /**
     * List the media library for a workspace.
     *
     * Uses first-class infinite scroll: the paginator normalizes merge and
     * cursor metadata for the Inertia <InfiniteScroll> component. All loaded
     * relationships are eager-loaded so the page never issues per-row queries.
     */
    public function index(Workspace $workspace): Response
    {
        return Inertia::render('Application/Media/Index', [
            'media' => Inertia::scroll(
                $workspace->media()
                    ->withCount('posts as posted_count')
                    ->with('uploadedBy')
                    ->latest()
                    ->orderByDesc('id')
                    ->paginate(24)
                    ->through(fn (Media $media): array => [
                        'id' => $media->getKey(),
                        'type' => [
                            'value' => $media->type->value,
                            'label' => $media->type->label(),
                        ],
                        'mime_type' => $media->mime_type,
                        'size_bytes' => $media->size_bytes,
                        'width' => $media->width,
                        'height' => $media->height,
                        'duration_seconds' => $media->duration_seconds,
                        'status' => [
                            'value' => $media->status->value,
                            'label' => $media->status->label(),
                        ],
                        'created_at' => $media->created_at?->toIso8601String(),
                        'url' => $media->publicUrl(),
                        'attached_to_post' => $media->posted_count > 0,
                        'posted_count' => $media->posted_count,
                        'uploaded_by' => $media->uploadedBy?->fullname,
                    ]),
            ),
        ]);
    }

    /**
     * Prepare a direct browser → object storage upload.
     *
     * The server never sees the file bytes: it hands back a presigned POST
     * policy and an opaque upload id. The policy pins the object key and the
     * Content-Type, so the browser can read the key but cannot change either
     * one. Completion is authorised only by the id, which is bound to the
     * requesting user and workspace.
     */
    public function intent(Request $request, Workspace $workspace): JsonResponse
    {
        $validated = $this->validateIntent($request);

        $type = $this->typeFor($validated['mime_type']);

        $path = implode('/', [
            config('media.key_prefix'),
            $workspace->getKey(),
            Str::ulid()->toString().'.'.self::EXTENSIONS[$validated['mime_type']],
        ]);

        $expiresAt = now()->addMinutes((int) config('media.presign_ttl'));

        $upload = app(CreateMediaUploadPostAction::class)->execute(
            $path,
            $validated['mime_type'],
            $expiresAt,
        );

        $uploadId = Str::random(40);

        Cache::put($this->pendingUploadKey($uploadId), [
            'workspace_id' => $workspace->getKey(),
            'user_id' => $request->user()->getKey(),
            'path' => $path,
            'mime_type' => $validated['mime_type'],
            'width' => $validated['width'] ?? null,
            'height' => $validated['height'] ?? null,
        ], $expiresAt);

        return response()->json([
            'upload_id' => $uploadId,
            'upload_url' => $upload['url'],
            'fields' => $upload['fields'],
            'expires_at' => $expiresAt->toIso8601String(),
            'type' => $type,
        ]);
    }

    /**
     * Register an uploaded object as a workspace media row.
     *
     * Everything needed to describe the object comes from the pending-upload
     * record created by intent(), never from the request. The stored bytes are
     * then read back far enough to prove they really are the declared type and
     * small enough to be allowed.
     */
    public function complete(Request $request, Workspace $workspace): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'string', 'size:40'],
        ]);

        $pending = Cache::get($this->pendingUploadKey($validated['upload_id']));

        if (! is_array($pending)
            || $pending['workspace_id'] !== $workspace->getKey()
            || $pending['user_id'] !== $request->user()->getKey()) {
            return response()->json(['message' => 'Unknown upload.'], 422);
        }

        $path = $pending['path'];
        $mimeType = $pending['mime_type'];

        $disk = Storage::disk(config('media.disk'));

        if (! $disk->exists($path)) {
            return response()->json(['message' => 'Uploaded object was not found.'], 422);
        }

        $size = $disk->size($path);

        if ($size < 1 || $size > (int) config('media.max_size')) {
            $disk->delete($path);

            return response()->json(['message' => 'The uploaded file is not an allowed size.'], 422);
        }

        if ($this->sniffMimeType($this->readObjectHead($disk, $path)) !== $mimeType) {
            $disk->delete($path);

            return response()->json(['message' => 'The uploaded file is not a valid '.$mimeType.' file.'], 422);
        }

        if ($this->isActiveContentType($disk->mimeType($path))) {
            $disk->delete($path);

            return response()->json(['message' => 'The uploaded file was stored as an unsafe content type.'], 422);
        }

        Cache::forget($this->pendingUploadKey($validated['upload_id']));

        $media = Media::query()->firstOrCreate(
            ['workspace_id' => $workspace->getKey(), 'path' => $path],
            [
                'uploaded_by_user_id' => $request->user()->getKey(),
                'disk' => config('media.disk'),
                'type' => $this->typeFor($mimeType),
                'mime_type' => $mimeType,
                'size_bytes' => $size,
                'width' => $pending['width'],
                'height' => $pending['height'],
                'duration_seconds' => null,
                'status' => MediaStatus::Ready,
            ],
        );

        return response()->json([
            'media' => [
                'id' => $media->getKey(),
                'type' => [
                    'value' => $media->type->value,
                    'label' => $media->type->label(),
                ],
                'mime_type' => $media->mime_type,
                'size_bytes' => $media->size_bytes,
                'width' => $media->width,
                'height' => $media->height,
                'url' => $media->publicUrl(),
            ],
        ], 201);
    }

    /**
     * Soft-delete a media row and remove its object bytes.
     *
     * Media still attached to a post can't be deleted — the post needs the
     * public URL to publish (or re-publish) its content.
     */
    public function destroy(Workspace $workspace, Media $media): RedirectResponse
    {
        abort_unless($media->workspace_id === $workspace->getKey(), 404);

        if ($media->posts()->exists()) {
            return redirect()
                ->route('workspace.media', ['workspace' => $workspace])
                ->with('flash', [
                    'error' => 'This file is attached to a post and cannot be deleted.',
                ]);
        }

        Storage::disk($media->disk)->delete($media->path);
        $media->delete();

        return redirect()
            ->route('workspace.media', ['workspace' => $workspace])
            ->with('flash', ['success' => 'Media deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateIntent(Request $request): array
    {
        return $request->validate([
            'mime_type' => ['required', 'string', Rule::in(config('media.allowed_mimes'))],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.(int) config('media.max_size')],
            'width' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('media.max_dimension')],
            'height' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('media.max_dimension')],
        ]);
    }

    /**
     * Read just enough of the object to identify it, so a 20 MB video is never
     * pulled into memory to check its signature.
     */
    private function readObjectHead(FilesystemAdapter $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            return '';
        }

        try {
            return (string) fread($stream, self::SIGNATURE_LENGTH);
        } finally {
            fclose($stream);
        }
    }

    private function pendingUploadKey(string $uploadId): string
    {
        return 'media-upload:'.$uploadId;
    }

    private function typeFor(string $mimeType): string
    {
        return str_starts_with($mimeType, 'video/')
            ? MediaType::Video->value
            : MediaType::Image->value;
    }

    /**
     * Whether a browser would execute this content type as script.
     *
     * The upload policy already pins Content-Type, so this is defence in depth
     * against an object that reached storage some other way. The list is
     * deliberately narrow rather than a blanket `text/*`, which would reject
     * perfectly ordinary media that some S3-compatible stores label loosely.
     */
    private function isActiveContentType(string|false $mimeType): bool
    {
        return in_array(strtolower(trim((string) $mimeType)), [
            'text/html',
            'application/xhtml+xml',
            'text/xml',
            'application/xml',
            'image/svg+xml',
            'text/javascript',
            'application/javascript',
            'application/x-javascript',
        ], true);
    }
}
