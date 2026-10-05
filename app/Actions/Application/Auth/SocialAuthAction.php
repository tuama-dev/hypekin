<?php

namespace App\Actions\Application\Auth;

use App\Actions\Application\Auth\Exceptions\UnverifiedProviderEmailException;
use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Models\User;
use App\Models\UserOauthProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser as SocialiteAbstractUser;
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

    /**
     * Match an existing account by email, but only when the provider vouches
     * for the address. An unverified email is refused outright: linking it
     * would let anybody able to register that address at the provider sign in
     * as the account owner, and creating an account for it would squat the
     * address so the real owner could no longer register. Either way the owner
     * must verify the address through the normal flow first.
     */
    private function findOrCreateUser(string $provider, SocialiteUser $socialiteUser): User
    {
        $email = $socialiteUser->getEmail();

        if ($email === null) {
            return $this->createUser($provider, $socialiteUser);
        }

        if (! $this->emailIsVerified($socialiteUser)) {
            throw new UnverifiedProviderEmailException(
                "The {$provider} account supplied an email address that the provider has not verified."
            );
        }

        return User::query()->where('email', $email)->first()
            ?? $this->createUser($provider, $socialiteUser);
    }

    /**
     * Both callers reaching here are safe to mark verified: either the provider
     * vouched for the email, or there is no email at all and the placeholder
     * address could never be verified anyway. Marking it unverified would trap
     * those users behind an email check they can never pass.
     */
    private function createUser(string $provider, SocialiteUser $socialiteUser): User
    {
        $email = $socialiteUser->getEmail();

        $user = User::create([
            'fullname' => $socialiteUser->getName() ?? 'Social User',
            'email' => $email ?? $this->placeholderEmail($provider, $socialiteUser->getId()),
            'password' => Str::password(32),
        ]);

        /**
         * Set outside the mass assignment because email_verified_at is not
         * fillable: only the verification flow itself may mark an address as
         * verified. Reaching this method means the provider already vouched for
         * the account, which is the proof the flag records.
         */
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->createWorkspace->execute($user);

        return $user;
    }

    /**
     * Socialite has no cross-driver verification accessor and the raw key
     * differs per provider: Facebook returns a boolean `verified`, Google maps
     * `email_verified` onto `verified_email`, and X only exposes an email at
     * all through `confirmed_email`. A provider that reports nothing is treated
     * as unverified, so it can never grant a link to an existing account.
     */
    private function emailIsVerified(SocialiteUser $socialiteUser): bool
    {
        $raw = $socialiteUser instanceof SocialiteAbstractUser ? $socialiteUser->getRaw() : [];

        foreach (['verified', 'verified_email', 'email_verified'] as $key) {
            if (Arr::get($raw, $key) === true) {
                return true;
            }
        }

        return is_string(Arr::get($raw, 'confirmed_email'))
            && Arr::get($raw, 'confirmed_email') !== '';
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
