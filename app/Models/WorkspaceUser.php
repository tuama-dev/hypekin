<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Membership pivot between a workspace and a user.
 *
 * The role is deliberately left uncast rather than cast to WorkspaceRole: the
 * column is a plain string, and an unknown value must stay readable so callers
 * decide what it means instead of crashing inside the cast.
 *
 * @property string $role
 */
#[Fillable(['role'])]
class WorkspaceUser extends Pivot
{
    /**
     * The table associated with the pivot.
     */
    protected $table = 'workspace_user';
}
