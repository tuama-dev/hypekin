<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug'])]
class Workspace extends Model
{
    use HasFactory, HasUlids;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Build a unique slug for a workspace name, skipping the given workspace.
     */
    public static function uniqueSlug(string $name, ?self $except = null): string
    {
        $base = Str::slug($name);
        $slug = $base;

        for ($suffix = 2; self::query()
            ->where('slug', $slug)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /**
     * @return BelongsToMany<User, $this, WorkspaceUser>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->using(WorkspaceUser::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Resolve the role a given user holds in this workspace.
     *
     * Returns null when the user is not a member, and also when the pivot holds
     * a role value the enum does not know about — an unknown role must not be
     * treated as the weakest one, because that would silently grant access.
     */
    public function roleFor(User $user): ?WorkspaceRole
    {
        $membership = $this->users()
            ->whereKey($user->getKey())
            ->first();

        if ($membership === null) {
            return null;
        }

        return WorkspaceRole::tryFrom($membership->pivot->role);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    public function owner(): BelongsToMany
    {
        return $this->users()->wherePivot('role', WorkspaceRole::Owner->value);
    }

    public function admins(): BelongsToMany
    {
        return $this->users()->wherePivot('role', WorkspaceRole::Admin->value);
    }

    public function editors(): BelongsToMany
    {
        return $this->users()->wherePivot('role', WorkspaceRole::Editor->value);
    }

    public function viewers(): BelongsToMany
    {
        return $this->users()->wherePivot('role', WorkspaceRole::Viewer->value);
    }
}
