<?php

namespace App\Http\Controllers\Application;

use App\Actions\Application\SocialAccount\ConnectFacebookPagesAction;
use App\Actions\Application\SocialAccount\ConnectInstagramAccountsAction;
use App\Actions\Application\SocialAccount\ConnectSocialAccountAction;
use App\Enums\Platform;
use App\Enums\SocialAccountStatus;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialAccountController extends Controller
{
    private const CONNECT_WORKSPACE_SESSION_KEY = 'social_account.connect_workspace_id';

    /**
     * How long a started connect flow stays usable. Without it a callback that
     * is never completed keeps its session record alive indefinitely.
     */
    private const CONNECT_FLOW_TTL_SECONDS = 900;

    public function __construct(
        private readonly ConnectSocialAccountAction $connectAccount,
        private readonly ConnectFacebookPagesAction $connectFacebookPages,
        private readonly ConnectInstagramAccountsAction $connectInstagramAccounts,
    ) {}

    public function index(Workspace $workspace): Response
    {
        return Inertia::render('Application/SocialAccounts/Index', [
            'accounts' => $workspace->socialAccounts()
                ->latest()
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (SocialAccount $account): array => [
                    'id' => $account->getKey(),
                    'platform' => [
                        'value' => $account->platform->value,
                        'label' => $account->platform->label(),
                    ],
                    'display_name' => $account->display_name,
                    'status' => [
                        'value' => $account->effectiveStatus()->value,
                        'label' => $account->effectiveStatus()->label(),
                    ],
                    'token_expires_at' => $account->token_expires_at?->toIso8601String(),
                    'connected_at' => $account->connected_at?->toIso8601String(),
                ])
                ->values(),
            'platforms' => collect(Platform::cases())
                ->map(fn (Platform $item): array => [
                    'value' => $item->value,
                    'label' => $item->label(),
                    'configured' => (bool) config('services.'.$item->socialiteDriver().'.client_id'),
                    'connect_url' => route('workspace.accounts.connect', [
                        'workspace' => $workspace,
                        'platform' => $item->value,
                    ]),
                ])
                ->values(),
            'canConnect' => (bool) collect(Platform::cases())
                ->contains(fn (Platform $item): bool => (bool) config('services.'.$item->socialiteDriver().'.client_id')),
        ]);
    }

    public function connect(Request $request, Workspace $workspace, Platform $platform): RedirectResponse
    {
        if (! config('services.'.$platform->socialiteDriver().'.client_id')) {
            return redirect()
                ->route('workspace.accounts', ['workspace' => $workspace])
                ->with('flash', ['error' => $platform->label().' publishing is not configured yet.']);
        }

        session([self::CONNECT_WORKSPACE_SESSION_KEY => [
            'workspace_id' => $workspace->getKey(),
            'user_id' => $request->user()->getKey(),
            'platform' => $platform->value,
            'started_at' => time(),
        ]]);

        return Socialite::driver($platform->socialiteDriver())
            ->scopes($platform->scopes())
            ->redirect();
    }

    /**
     * Finish a connect flow that connect() started.
     *
     * The route itself carries no workspace, so the session record is the only
     * thing tying the callback to the workspace and user that asked for it. It
     * is bound to that user, that platform and a short window, and the ability
     * is re-checked here: the role held when the flow started says nothing
     * about the role held now.
     */
    public function callback(Request $request, Platform $platform): RedirectResponse
    {
        $flow = $request->session()->pull(self::CONNECT_WORKSPACE_SESSION_KEY);

        if (! is_array($flow)
            || ($flow['user_id'] ?? null) !== $request->user()->getKey()
            || ($flow['platform'] ?? null) !== $platform->value
            || ! is_int($flow['started_at'] ?? null)
            || (time() - $flow['started_at']) > self::CONNECT_FLOW_TTL_SECONDS) {
            abort(404);
        }

        $workspace = Workspace::find($flow['workspace_id'] ?? null);

        if ($workspace === null) {
            abort(404);
        }

        abort_unless(
            $request->user()->workspaces()->where('workspaces.id', $workspace->getKey())->exists(),
            404
        );

        abort_unless($request->user()->can('manageAccounts', $workspace), 403);

        try {
            $socialiteUser = Socialite::driver($platform->socialiteDriver())->user();
        } catch (Throwable) {
            return redirect()
                ->route('workspace.accounts', ['workspace' => $workspace])
                ->with('flash', ['error' => 'Unable to connect that '.$platform->label().' account. Please try again.']);
        }

        match ($platform) {
            Platform::Facebook => $this->connectFacebookPages->execute(
                $workspace,
                $socialiteUser->token,
                $request->user(),
            ),
            Platform::Instagram => $this->connectInstagramAccounts->execute(
                $workspace,
                $socialiteUser->token,
                $request->user(),
            ),
            Platform::LinkedIn, Platform::Tiktok => $this->connectAccount->execute(
                $workspace,
                $platform,
                $socialiteUser,
                $request->user(),
            ),
        };

        return redirect()
            ->route('workspace.accounts', ['workspace' => $workspace])
            ->with('flash', ['success' => $platform->label().' account connected.']);
    }

    public function destroy(Workspace $workspace, SocialAccount $account): RedirectResponse
    {
        abort_unless($account->workspace_id === $workspace->getKey(), 404);

        $account->forceFill([
            'status' => SocialAccountStatus::Revoked,
            'access_token' => null,
            'refresh_token' => null,
        ])->save();

        return redirect()
            ->route('workspace.accounts', ['workspace' => $workspace])
            ->with('flash', ['success' => 'Account disconnected.']);
    }
}
