<?php

namespace App\Http\Controllers\Application;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MediaController extends Controller
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
    ];

    /**
     * Prepare a direct browser → object storage upload.
     *
     * The server never sees the file bytes: it hands back a presigned PUT URL
     * and a server-generated object key the browser uses next.
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

        $disk = Storage::disk(config('media.disk'));
        $expiresAt = now()->addMinutes((int) config('media.presign_ttl'));

        $upload = $disk->temporaryUploadUrl($path, $expiresAt);

        return response()->json([
            'path' => $path,
            'upload_url' => $upload['url'],
            'expires_at' => $expiresAt->toIso8601String(),
            'type' => $type,
        ]);
    }

    /**
     * Register an uploaded object as a workspace media row.
     *
     * Verifies the object exists and matches the allow-list via HEAD-style
     * metadata — never downloading the body.
     */
    public function complete(Request $request, Workspace $workspace): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['required', 'string'],
            'mime_type' => ['required', 'string', Rule::in(config('media.allowed_mimes'))],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.(int) config('media.max_size')],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
        ]);

        $prefix = config('media.key_prefix').'/'.$workspace->getKey();

        if (! Str::startsWith($validated['path'], $prefix)) {
            return response()->json(['message' => 'Unknown upload.'], 422);
        }

        $disk = Storage::disk(config('media.disk'));

        if (! $disk->exists($validated['path'])) {
            return response()->json(['message' => 'Uploaded object was not found.'], 422);
        }

        $media = Media::firstOrCreate(
            ['workspace_id' => $workspace->getKey(), 'path' => $validated['path']],
            [
                'uploaded_by_user_id' => $request->user()->getKey(),
                'disk' => config('media.disk'),
                'type' => $this->typeFor($validated['mime_type']),
                'mime_type' => $validated['mime_type'],
                'size_bytes' => $disk->size($validated['path']),
                'width' => $validated['width'] ?? null,
                'height' => $validated['height'] ?? null,
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
     * @return array<string, mixed>
     */
    private function validateIntent(Request $request): array
    {
        return $request->validate([
            'mime_type' => ['required', 'string', Rule::in(config('media.allowed_mimes'))],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.(int) config('media.max_size')],
        ]);
    }

    private function typeFor(string $mimeType): string
    {
        return str_starts_with($mimeType, 'video/')
            ? MediaType::Video->value
            : MediaType::Image->value;
    }
}
