<?php

namespace App\Actions\Application\Workspace;

use App\Models\Workspace;

class UpdateWorkspaceAction
{
    /**
     * Rename a workspace, keeping its slug stable.
     */
    public function execute(Workspace $workspace, string $name): Workspace
    {
        $workspace->forceFill([
            'name' => $name,
        ])->save();

        return $workspace;
    }
}
