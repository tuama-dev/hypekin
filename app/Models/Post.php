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
 * @property int $schedule_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'workspace_id',
    'created_by_user_id',
    'status',
    'scheduled_at',
    'schedule_version',
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
     *
     * This derivation runs ONLY inside the publish pipeline, once the post has
     * actually started dispatching. The pre-dispatch guard at the top of the
     * method enforces that: while the post is still Draft, Scheduled or
     * Canceled the method returns without writing, so those explicit,
     * directly-set states are never overwritten here. A scheduled post leaves
     * Scheduled only through the explicit flip to Publishing inside
     * PublishPostTargetJob when its first delayed job fires.
     *
     * Precedence (first match wins):
     *   1. every target Published  -> Published (fully succeeded, not attempted)
     *   2. every target terminal (Published|Failed), at least one Failed -> Failed
     *   3. otherwise               -> Publishing (still in flight)
     *
     * A post with zero targets is unreachable: creation validates targets min:1,
     * nothing deletes targets, and disconnecting an account is a status change
     * (the FK cascade into post_targets never fires). The empty check below is
     * therefore defense-in-depth against the vacuous every() truth — it leaves
     * the existing status untouched rather than inventing a transition.
     */
    public function recalculateStatus(): void
    {
        if (in_array($this->status, [PostStatus::Scheduled, PostStatus::Draft, PostStatus::Canceled], true)) {
            return;
        }

        $statuses = $this->targets()->pluck('status');

        if ($statuses->isEmpty()) {
            return;
        }

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
            'schedule_version' => 'integer',
        ];
    }
}
