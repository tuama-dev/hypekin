<?php

namespace App\Models;

use App\Enums\Platform;
use Database\Factories\PostMetricFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $post_id
 * @property string $post_target_id
 * @property Platform $platform
 * @property string $snapshot_type
 * @property Carbon $snapshot_date
 * @property array<string, int|null> $data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'post_id',
    'post_target_id',
    'platform',
    'snapshot_type',
    'snapshot_date',
    'data',
])]
class PostMetric extends Model
{
    /** @use HasFactory<PostMetricFactory> */
    use HasFactory, HasUlids;

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'snapshot_date' => 'date',
            'data' => 'array',
        ];
    }
}
