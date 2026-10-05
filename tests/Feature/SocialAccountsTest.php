<?php

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Enums\Platform;
use App\Enums\SocialAccountStatus;
use App\Enums\WorkspaceRole;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Seed the connect-flow session record the way connect() writes it.
 *
 * @return array<string, mixed>
 */
function startConnectFlow(User $user, Workspace $workspace, string $platform, ?int $startedAt = null): array
{
    return [
        'social_account.connect_workspace_id' => [
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'platform' => $platform,
            'started_at' => $startedAt ?? time(),
        ],
    ];
}

test('the accounts page renders the connected accounts of a member', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id]);

    foreach (Platform::cases() as $platform) {
        Config::set('services.'.$platform->socialiteDriver().'.client_id', null);
    }

    $this->actingAs($user)
        ->get(route('workspace.accounts', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/SocialAccounts/Index')
            ->where('auth.workspace.slug', $workspace->slug)
            ->has('accounts', 1)
            ->where('accounts.0.id', $account->id)
            ->where('accounts.0.platform.value', 'linkedin')
            ->where('accounts.0.display_name', $account->display_name)
            ->where('accounts.0.status.value', 'connected')
            ->where('canConnect', false));

    expect($account->toArray())->not->toHaveKey('access_token');
});

test('a connected account with a lapsed token is surfaced as expired', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'token_expires_at' => now()->subDay(),
    ]);

    $this->actingAs($user)
        ->get(route('workspace.accounts', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/SocialAccounts/Index')
            ->where('accounts.0.status.value', 'expired')
            ->where('accounts.0.status.label', 'Expired'));
});

test('a revoked account with a lapsed token stays revoked, never expired', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Revoked,
        'access_token' => null,
        'refresh_token' => null,
        'token_expires_at' => now()->subDays(30),
    ]);

    $this->actingAs($user)
        ->get(route('workspace.accounts', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/SocialAccounts/Index')
            ->where('accounts.0.status.value', 'revoked'));

    expect($account->effectiveStatus())->toBe(SocialAccountStatus::Revoked);
});

test('the effective status of a connected account stays connected while the token is valid', function () {
    $account = SocialAccount::factory()->create([
        'token_expires_at' => now()->addDays(5),
    ]);

    expect($account->effectiveStatus())->toBe(SocialAccountStatus::Connected);
});

test('the accounts page returns 404 for a user who is not a member', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('workspace.accounts', ['workspace' => $workspace]))
        ->assertNotFound();
});

test('the connect route redirects to LinkedIn and remembers the workspace', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Config::set('services.linkedin.client_id', 'client-id');

    Socialite::fake('linkedin');

    $this->actingAs($user)
        ->get(route('workspace.accounts.connect', ['workspace' => $workspace, 'platform' => 'linkedin']))
        ->assertRedirect()
        ->assertSessionHas('social_account.connect_workspace_id', fn (array $flow): bool => $flow['workspace_id'] === $workspace->getKey()
            && $flow['user_id'] === $user->getKey()
            && $flow['platform'] === 'linkedin'
            && $flow['started_at'] > 0);
});

test('the connect route is blocked when LinkedIn is not configured', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Config::set('services.linkedin.client_id', null);

    $this->actingAs($user)
        ->get(route('workspace.accounts.connect', ['workspace' => $workspace, 'platform' => 'linkedin']))
        ->assertRedirect(route('workspace.accounts', ['workspace' => $workspace]))
        ->assertSessionHas('flash.error');
});

test('the callback stores the connected LinkedIn account', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Socialite::fake('linkedin', SocialiteUser::fake([
        'id' => 'linkedin-user-123',
        'name' => 'John Doe',
        'token' => 'secret-token',
        'refreshToken' => 'refresh-token',
        'expiresIn' => 3600,
    ]));

    $this->actingAs($user)
        ->withSession(startConnectFlow($user, $workspace, 'linkedin'))
        ->get(route('workspace.accounts.callback', ['platform' => 'linkedin']))
        ->assertRedirect(route('workspace.accounts', ['workspace' => $workspace]))
        ->assertSessionHas('flash.success')
        ->assertSessionMissing('social_account.connect_workspace_id');

    $account = $workspace->socialAccounts()->first();

    expect($account)->not->toBeNull();
    expect($account->workspace_id)->toBe($workspace->getKey());
    expect($account->platform)->toBe(Platform::LinkedIn);
    expect($account->external_account_id)->toBe('linkedin-user-123');
    expect($account->display_name)->toBe('John Doe');
    expect($account->access_token)->toBe('secret-token');
    expect($account->refresh_token)->toBe('refresh-token');
    expect($account->status)->toBe(SocialAccountStatus::Connected);
    expect($account->connected_at)->not->toBeNull();
    expect($account->token_expires_at->getTimestamp())->toBe(now()->addSeconds(3600)->getTimestamp());
});

test('the callback returns 404 without a pending connection', function () {
    $user = User::factory()->create();
    app(CreateWorkspaceAction::class)->ensure($user);

    $this->actingAs($user)
        ->get(route('workspace.accounts.callback', ['platform' => 'linkedin']))
        ->assertNotFound();
});

test('the callback returns 404 for a workspace the user is not part of', function () {
    $owner = User::factory()->create();
    $foreignWorkspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $outsider = User::factory()->create();

    Socialite::fake('linkedin', SocialiteUser::fake(['id' => 'linkedin-user-123']));

    $this->actingAs($outsider)
        ->withSession(startConnectFlow($outsider, $foreignWorkspace, 'linkedin'))
        ->get(route('workspace.accounts.callback', ['platform' => 'linkedin']))
        ->assertNotFound();
});

test('the callback handles a denied authorization without storing an account', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Socialite::fake('linkedin', function () {
        throw new InvalidStateException('Invalid state');
    });

    $this->actingAs($user)
        ->withSession(startConnectFlow($user, $workspace, 'linkedin'))
        ->get(route('workspace.accounts.callback', ['platform' => 'linkedin']))
        ->assertRedirect(route('workspace.accounts', ['workspace' => $workspace]))
        ->assertSessionHas('flash.error');

    expect($workspace->socialAccounts()->count())->toBe(0);
});

test('a guest visiting the callback is redirected to sign in', function () {
    Socialite::fake('linkedin');

    $this->get(route('workspace.accounts.callback', ['platform' => 'linkedin']))
        ->assertRedirect(route('login'));
});

test('reconnecting the same LinkedIn account updates it instead of duplicating', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Socialite::fake('linkedin', SocialiteUser::fake([
        'id' => 'linkedin-user-123',
        'token' => 'first-token',
    ]));

    $this->actingAs($user)
        ->withSession(startConnectFlow($user, $workspace, 'linkedin'))
        ->get(route('workspace.accounts.callback', ['platform' => 'linkedin']));

    Socialite::fake('linkedin', SocialiteUser::fake([
        'id' => 'linkedin-user-123',
        'token' => 'second-token',
    ]));

    $this->actingAs($user)
        ->withSession(startConnectFlow($user, $workspace, 'linkedin'))
        ->get(route('workspace.accounts.callback', ['platform' => 'linkedin']));

    $account = $workspace->socialAccounts()->first();

    expect($workspace->socialAccounts()->count())->toBe(1);
    expect($account->access_token)->toBe('second-token');
});

test('an owner can disconnect an account, revoking it and clearing its tokens', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->delete(route('workspace.accounts.destroy', ['workspace' => $workspace, 'account' => $account]))
        ->assertRedirect(route('workspace.accounts', ['workspace' => $workspace]))
        ->assertSessionHas('flash.success');

    $account->refresh();

    expect($account->status)->toBe(SocialAccountStatus::Revoked);
    expect($account->access_token)->toBeNull();
    expect($account->refresh_token)->toBeNull();
});

test('disconnecting an account that belongs to another workspace returns 404', function () {
    $owner = User::factory()->create();
    $otherWorkspace = app(CreateWorkspaceAction::class)->ensure($owner);
    $foreignAccount = SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $this->actingAs($user)
        ->delete(route('workspace.accounts.destroy', ['workspace' => $workspace, 'account' => $foreignAccount]))
        ->assertNotFound();

    expect($foreignAccount->refresh()->status)->toBe(SocialAccountStatus::Connected);
});

test('the callback stores the connected TikTok account', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Socialite::fake('tiktok', SocialiteUser::fake([
        'id' => 'tiktok-open-id-123',
        'nickname' => 'TikTok Creator',
        'token' => 'tiktok-access-token',
        'refreshToken' => 'tiktok-refresh-token',
        'expiresIn' => 86400,
    ]));

    $this->actingAs($user)
        ->withSession(startConnectFlow($user, $workspace, 'tiktok'))
        ->get(route('workspace.accounts.callback', ['platform' => 'tiktok']))
        ->assertRedirect(route('workspace.accounts', ['workspace' => $workspace]))
        ->assertSessionHas('flash.success');

    $account = $workspace->socialAccounts()->first();

    expect($account)->not->toBeNull();
    expect($account->platform)->toBe(Platform::Tiktok);
    expect($account->external_account_id)->toBe('tiktok-open-id-123');
    expect($account->access_token)->toBe('tiktok-access-token');
    expect($account->status)->toBe(SocialAccountStatus::Connected);
});

test('the callback connects the Facebook pages a user manages', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Socialite::fake('facebook-posting', SocialiteUser::fake(['token' => 'facebook-user-token']));

    Http::fake([
        'graph.facebook.com/*/me/accounts*' => Http::response([
            'data' => [
                ['id' => 'page-1', 'name' => 'Acme Pages', 'access_token' => 'page-token-1'],
                ['id' => 'page-2', 'name' => 'Acme Blog', 'access_token' => 'page-token-2'],
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->withSession(startConnectFlow($user, $workspace, 'facebook'))
        ->get(route('workspace.accounts.callback', ['platform' => 'facebook']))
        ->assertRedirect(route('workspace.accounts', ['workspace' => $workspace]))
        ->assertSessionHas('flash.success');

    $pages = $workspace->socialAccounts()->where('platform', Platform::Facebook)->get();

    expect($pages)->toHaveCount(2);
    expect($pages[0]->external_account_id)->toBe('page-1');
    expect($pages[0]->display_name)->toBe('Acme Pages');
    expect($pages[0]->access_token)->toBe('page-token-1');
    expect($pages[1]->external_account_id)->toBe('page-2');
    expect($pages[1]->display_name)->toBe('Acme Blog');
    expect($pages[1]->access_token)->toBe('page-token-2');
});

test('the callback connects the Instagram business accounts linked to pages', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Socialite::fake('instagram', SocialiteUser::fake(['token' => 'facebook-user-token']));

    Http::fake([
        'graph.facebook.com/*/me/accounts*' => Http::response([
            'data' => [
                ['id' => 'page-1', 'name' => 'Acme Pages', 'access_token' => 'page-token-1', 'instagram_business_account' => ['id' => 'ig-123', 'name' => 'Acme Instagram']],
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->withSession(startConnectFlow($user, $workspace, 'instagram'))
        ->get(route('workspace.accounts.callback', ['platform' => 'instagram']))
        ->assertRedirect(route('workspace.accounts', ['workspace' => $workspace]))
        ->assertSessionHas('flash.success');

    $account = $workspace->socialAccounts()->where('platform', Platform::Instagram)->first();

    expect($account)->not->toBeNull();
    expect($account->external_account_id)->toBe('ig-123');
    expect($account->display_name)->toBe('Acme Instagram');
});

test('the callback skips Facebook pages without an access token', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Socialite::fake('facebook-posting', SocialiteUser::fake(['token' => 'facebook-user-token']));

    Http::fake([
        'graph.facebook.com/*/me/accounts*' => Http::response([
            'data' => [
                ['id' => 'page-1', 'name' => 'Acme Pages'],
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->withSession(startConnectFlow($user, $workspace, 'facebook'))
        ->get(route('workspace.accounts.callback', ['platform' => 'facebook']))
        ->assertRedirect(route('workspace.accounts', ['workspace' => $workspace]));

    expect($workspace->socialAccounts()->count())->toBe(0);
});

test('the callback is refused when the user lost the ability during the flow', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Socialite::fake('linkedin', SocialiteUser::fake([
        'id' => 'linkedin-user-123',
        'name' => 'John Doe',
        'token' => 'secret-token',
    ]));

    // The flow was started while the user was the Owner, then demoted.
    $flow = startConnectFlow($user, $workspace, 'linkedin');

    $workspace->users()->updateExistingPivot($user->getKey(), ['role' => WorkspaceRole::Viewer->value]);

    $this->actingAs($user)
        ->withSession($flow)
        ->get(route('workspace.accounts.callback', ['platform' => 'linkedin']))
        ->assertForbidden();

    expect($workspace->socialAccounts()->count())->toBe(0);
});

test('the callback is refused when the flow record belongs to another user', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Socialite::fake('linkedin', SocialiteUser::fake([
        'id' => 'linkedin-user-123',
        'token' => 'secret-token',
    ]));

    $other = User::factory()->create();
    $workspace->users()->attach($other, ['role' => WorkspaceRole::Owner]);

    $this->actingAs($other)
        ->withSession(startConnectFlow($user, $workspace, 'linkedin'))
        ->get(route('workspace.accounts.callback', ['platform' => 'linkedin']))
        ->assertNotFound();

    expect($workspace->socialAccounts()->count())->toBe(0);
});

test('the callback is refused when the flow was started for another platform', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Socialite::fake('tiktok', SocialiteUser::fake(['id' => 'tiktok-1']));

    $this->actingAs($user)
        ->withSession(startConnectFlow($user, $workspace, 'tiktok'))
        ->get(route('workspace.accounts.callback', ['platform' => 'linkedin']))
        ->assertNotFound();

    expect($workspace->socialAccounts()->count())->toBe(0);
});

test('the callback is refused when the flow has expired', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    Socialite::fake('linkedin', SocialiteUser::fake([
        'id' => 'linkedin-user-123',
        'token' => 'secret-token',
    ]));

    $this->actingAs($user)
        ->withSession(startConnectFlow($user, $workspace, 'linkedin', time() - 3600))
        ->get(route('workspace.accounts.callback', ['platform' => 'linkedin']))
        ->assertNotFound();

    expect($workspace->socialAccounts()->count())->toBe(0);
});
