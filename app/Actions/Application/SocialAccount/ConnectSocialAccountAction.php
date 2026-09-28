<?php

namespace App\Actions\Application\SocialAccount;

use App\Enums\Platform;
use App\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class ConnectSocialAccountAction
{
    /**
     * Store or refresh a connected platform account for a workspace.
     */
    public function execute(
        Workspace $workspace,
        Platform $platform,
        SocialiteUser $socialiteUser,
        User $connectedBy,
    ): SocialAccount {
        return $workspace->socialAccounts()->updateOrCreate(
            [
                'platform' => $platform->value,
                'external_account_id' => $socialiteUser->getId(),
            ],
            [
                'display_name' => $socialiteUser->getName() ?: $socialiteUser->getNickname() ?: $socialiteUser->getId(),
                'avatar_url' => $socialiteUser->getAvatar(),
                'access_token' => $socialiteUser->token,
                'refresh_token' => $socialiteUser->refreshToken,
                'token_expires_at' => $socialiteUser->expiresIn ? now()->addSeconds($socialiteUser->expiresIn) : null,
                'status' => SocialAccountStatus::Connected,
                'connected_by_user_id' => $connectedBy->getKey(),
                'connected_at' => now(),
            ],
        );
    }
}
