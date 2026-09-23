<?php

namespace App\Actions\Application\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

class CreateWorkspaceAction
{
    /**
     * Create a personal workspace owned by the given user.
     */
    public function execute(User $user): Workspace
    {
        $name = "{$user->fullname}'s Workspace";

        $workspace = Workspace::create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
        ]);

        $workspace->users()->attach($user, ['role' => WorkspaceRole::Owner]);

        return $workspace;
    }

    /**
     * Ensure the user owns at least one workspace, creating one when missing.
     */
    public function ensure(User $user): Workspace
    {
        return $user->workspaces()->first() ?? $this->execute($user);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;

        for ($suffix = 2; Workspace::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
