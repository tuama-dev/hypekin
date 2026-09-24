<?php

namespace App\Http\Controllers\Application\Auth;

use App\Actions\Application\Auth\SocialAuthAction;
use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialAuthController extends Controller
{
    private const array SUPPORTED_PROVIDERS = ['facebook', 'x', 'linkedin-openid', 'google'];

    public function __construct(
        private readonly SocialAuthAction $socialAuthAction,
        private readonly CreateWorkspaceAction $createWorkspace,
    ) {}

    public function redirect(string $provider): RedirectResponse
    {
        $this->ensureProviderIsSupported($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $this->ensureProviderIsSupported($provider);

        try {
            $socialiteUser = Socialite::driver($provider)->user();
        } catch (Throwable) {
            return redirect()
                ->route('login')
                ->with('flash', ['error' => "Unable to sign in with {$provider}. Please try again."]);
        }

        $user = $this->socialAuthAction->execute($provider, $socialiteUser);

        $this->createWorkspace->ensure($user);

        if (Auth::guest()) {
            Auth::login($user);

            request()->session()->regenerate();
        }

        return redirect()->intended(route('workspace.dashboard', ['workspace' => $user->workspaces()->first()]));
    }

    private function ensureProviderIsSupported(string $provider): void
    {
        abort_unless(in_array($provider, self::SUPPORTED_PROVIDERS, true), 404);
    }
}
