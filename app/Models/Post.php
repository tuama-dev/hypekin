<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $created_by_user_id
 * @property PostStatus $status
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'workspace_id',
    'created_by_user_id',
    'status',
    'scheduled_at',
])]
class Post extends Model
{
    use HasFactory, HasUlids;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'post_media')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * Recompute the post status from its current targets after target-level
     * publishing work completes.
     */
    public function recalculateStatus(): void
    {
        $statuses = $this->targets()->pluck('status');

        $status = match (true) {
            $statuses->every(fn (PostTargetStatus $status) => $status === PostTargetStatus::Published) => PostStatus::Published,
            $statuses->every(fn (PostTargetStatus $status) => in_array($status, [PostTargetStatus::Published, PostTargetStatus::Failed], true)) => PostStatus::Failed,
            default => PostStatus::Publishing,
        };

        $this->forceFill(['status' => $status])->save();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'scheduled_at' => 'datetime',
        ];
    }
}
