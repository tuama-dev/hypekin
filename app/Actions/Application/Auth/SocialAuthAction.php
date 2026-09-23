<?php

namespace App\Actions\Application\Auth;

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Models\User;
use App\Models\UserOauthProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class SocialAuthAction
{
    public function __construct(private readonly CreateWorkspaceAction $createWorkspace) {}

    /**
     * Resolve or create the user and link the social account.
     *
     * When an authenticated user triggers the callback, the account is linked
     * to that user instead of a new user being created.
     */
    public function execute(string $provider, SocialiteUser $socialiteUser): User
    {
        $user = Auth::user();

        if ($user instanceof User) {
            $this->linkAccount($user, $provider, $socialiteUser);

            return $user;
        }

        $oauthProvider = UserOauthProvider::query()
            ->where('provider_name', $provider)
            ->where('provider_id', $socialiteUser->getId())
            ->first();

        $user = $oauthProvider?->user ?? $this->findOrCreateUser($provider, $socialiteUser);

        $this->linkAccount($user, $provider, $socialiteUser);

        return $user;
    }

    private function findOrCreateUser(string $provider, SocialiteUser $socialiteUser): User
    {
        $email = $socialiteUser->getEmail();

        $user = $email !== null
            ? User::query()->where('email', $email)->first()
            : null;

        if ($user === null) {
            $user = User::create([
                'fullname' => $socialiteUser->getName() ?? 'Social User',
                'email' => $email ?? $this->placeholderEmail($provider, $socialiteUser->getId()),
                'password' => Str::password(32),
                'email_verified_at' => now(),
            ]);

            $this->createWorkspace->execute($user);
        }

        return $user;
    }

    private function linkAccount(User $user, string $provider, SocialiteUser $socialiteUser): void
    {
        $user->oauthProviders()->updateOrCreate(
            ['provider_name' => $provider],
            [
                'provider_id' => $socialiteUser->getId(),
                'token' => $socialiteUser->token,
                'refresh_token' => $socialiteUser->refreshToken,
                'nickname' => $socialiteUser->getNickname(),
                'avatar' => $socialiteUser->getAvatar(),
                'token_expires_at' => $socialiteUser->expiresIn !== null
                    ? now()->addSeconds($socialiteUser->expiresIn)
                    : null,
            ],
        );
    }

    private function placeholderEmail(string $provider, string $providerId): string
    {
        return strtolower("{$provider}-{$providerId}@{$provider}.invalid");
    }
}
