<?php

namespace App\Enums;

enum PostTargetStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Published = 'published';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
