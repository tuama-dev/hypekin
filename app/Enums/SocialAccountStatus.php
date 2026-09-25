<?php

namespace App\Enums;

enum SocialAccountStatus: string
{
    case Connected = 'connected';

    case Expired = 'expired';

    case Revoked = 'revoked';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
