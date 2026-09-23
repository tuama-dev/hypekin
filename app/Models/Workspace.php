<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
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
