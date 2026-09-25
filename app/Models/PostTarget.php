<?php

namespace App\Models;

use App\Enums\PostTargetStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $post_id
 * @property string $social_account_id
 * @property string $caption
 * @property PostTargetStatus $status
 * @property string|null $platform_post_id
 * @property Carbon|null $published_at
 * @property string|null $error_message
 * @property int $retry_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'post_id',
    'social_account_id',
    'caption',
    'status',
    'platform_post_id',
    'published_at',
    'error_message',
    'retry_count',
])]
class PostTarget extends Model
{
    use HasFactory, HasUlids;

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PostTargetStatus::class,
            'published_at' => 'datetime',
            'retry_count' => 'integer',
        ];
    }
}
