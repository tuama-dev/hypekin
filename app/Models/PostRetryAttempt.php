<?php

namespace App\Models;

use Database\Factories\PostRetryAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One user-initiated retry of a post's failed publishing legs.
 *
 * This table is the single source of truth for the retry policy: the cap is the
 * number of rows for a post, the cooldown is the latest `attempted_at` plus
 * the configured wait, and the "last retried" affordance is the newest row. It
 * is an append-only audit log, so it also answers how many legs a retry
 * actually covered and who asked for it.
 *
 * It is deliberately separate from `post_targets.retry_count`, which counts
 * TikTok status polls of a submitted upload and is incremented by the publish
 * pipeline, not by the user.
 *
 * @property string $id
 * @property string $post_id
 * @property string $attempted_by_user_id
 * @property int $attempted_legs
 * @property Carbon $attempted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'post_id',
    'attempted_by_user_id',
    'attempted_legs',
    'attempted_at',
])]
class PostRetryAttempt extends Model
{
    /** @use HasFactory<PostRetryAttemptFactory> */
    use HasFactory, HasUlids;

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function attemptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attempted_by_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempted_legs' => 'integer',
            'attempted_at' => 'datetime',
        ];
    }
}
