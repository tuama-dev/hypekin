<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';

    case Admin = 'admin';

    case Editor = 'editor';

    case Viewer = 'viewer';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
