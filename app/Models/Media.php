<?php

namespace App\Models;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $uploaded_by_user_id
 * @property string $disk
 * @property string $path
 * @property MediaType $type
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property int|null $duration_seconds
 * @property MediaStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'workspace_id',
    'uploaded_by_user_id',
    'disk',
    'path',
    'type',
    'mime_type',
    'size_bytes',
    'width',
    'height',
    'duration_seconds',
    'status',
])]
class Media extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_media')
            ->withPivot('position')
            ->withTimestamps();
    }

    /**
     * The publicly reachable URL a platform can fetch this file from.
     */
    public function publicUrl(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
            'status' => MediaStatus::class,
        ];
    }
}
