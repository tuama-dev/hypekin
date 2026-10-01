<?php

namespace App\Models;

use App\Enums\PostTargetStatus;
use App\Observers\PostTargetObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $post_id
 * @property string $social_account_id
 * @property string $caption
 * @property string|null $title
 * @property PostTargetStatus $status
 * @property string|null $platform_post_id
 * @property string|null $platform_upload_id
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
    'title',
    'status',
    'platform_post_id',
    'platform_upload_id',
    'published_at',
    'error_message',
    'retry_count',
])]
#[ObservedBy(PostTargetObserver::class)]
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

    public function metrics(): HasMany
    {
        return $this->hasMany(PostMetric::class);
    }

    /**
     * Failed targets that never reached the platform, so a retry can re-send
     * them without risking a second post on the other side.
     *
     * The SQL twin of isRetryable(); keep the two conditions in step. A target
     * holding a platform post id is published, and one holding a TikTok upload
     * id already has an upload in flight, so neither may be re-sent.
     *
     * @param  Builder<PostTarget>  $query
     */
    #[Scope]
    protected function retryable(Builder $query): void
    {
        $query->where('status', PostTargetStatus::Failed)
            ->whereNull('platform_post_id')
            ->whereNull('platform_upload_id');
    }

    /**
     * The in-memory twin of the retryable() scope, for filtering an
     * already-loaded relation so the post page does not re-query it.
     */
    public function isRetryable(): bool
    {
        return $this->status === PostTargetStatus::Failed
            && $this->platform_post_id === null
            && $this->platform_upload_id === null;
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
