<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\UserOauthProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

test('users are redirected to the provider', function () {
    Socialite::fake('google');

    $this->get(route('auth.social.redirect', ['provider' => 'google']))
        ->assertRedirect();
});

test('a new user can register with a social provider', function () {
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-123',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]));

    $response = $this->get(route('auth.social.callback', ['provider' => 'google']));

    $this->assertAuthenticated();

    $user = User::query()->where('email', 'jane@example.com')->firstOrFail();
    $workspace = $user->workspaces()->firstOrFail();

    $response->assertRedirect(route('workspace.dashboard', ['workspace' => $workspace]));

    $this->assertDatabaseHas('users', [
        'fullname' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);

    $this->assertDatabaseHas('user_oauth_providers', [
        'provider_name' => 'google',
        'provider_id' => 'google-123',
    ]);
});

test('a new user can register with tiktok-login and is auto-verified', function () {
    Socialite::fake('tiktok-login', SocialiteUser::fake([
        'id' => 'tiktok-open-id-123',
        'name' => 'Tik Creator',
        'email' => null,
    ]));

    $response = $this->get(route('auth.social.callback', ['provider' => 'tiktok-login']));

    $this->assertAuthenticated();

    $user = User::query()->where('email', 'tiktok-login-tiktok-open-id-123@tiktok-login.invalid')->firstOrFail();
    $workspace = $user->workspaces()->firstOrFail();

    $response->assertRedirect(route('workspace.dashboard', ['workspace' => $workspace]));

    expect($user->hasVerifiedEmail())->toBeTrue();

    $this->assertDatabaseHas('user_oauth_providers', [
        'provider_name' => 'tiktok-login',
        'provider_id' => 'tiktok-open-id-123',
    ]);
});

test('a new social user gets a personal workspace', function () {
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-321',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]));

    $this->get(route('auth.social.callback', ['provider' => 'google']));

    $user = User::query()->where('email', 'jane@example.com')->firstOrFail();

    $workspace = $user->workspaces()->firstOrFail();

    expect($workspace->only(['name', 'slug']))
        ->toBe([
            'name' => "Jane Doe's Workspace",
            'slug' => 'jane-does-workspace',
        ]);

    $this->assertDatabaseHas('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner->value,
    ]);

    expect($user->workspaces()->count())->toBe(1);
});

test('a new social user without an email is verified and can access the dashboard', function () {
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-noemail',
        'name' => 'No Email User',
        'email' => null,
    ]));

    $this->get(route('auth.social.callback', ['provider' => 'google']));

    $this->assertAuthenticated();

    $user = User::query()->where('email', 'google-google-noemail@google.invalid')->firstOrFail();
    $workspace = $user->workspaces()->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeTrue();

    $this->actingAs($user)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertOk();
});

test('an existing user without a workspace gets one when signing in with a social provider', function () {
    $user = User::factory()->create(['email' => 'jane@example.com']);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-456',
        'email' => 'jane@example.com',
    ]));

    $this->get(route('auth.social.callback', ['provider' => 'google']));

    $this->assertAuthenticatedAs($user);
    expect($user->workspaces()->count())->toBe(1);
});

test('an existing user can log in with a social provider by email', function () {
    $user = User::factory()->create([
        'email' => 'jane@example.com',
    ]);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-456',
        'email' => 'jane@example.com',
    ]));

    $response = $this->get(route('auth.social.callback', ['provider' => 'google']));

    $this->assertAuthenticatedAs($user);

    $workspace = $user->workspaces()->firstOrFail();

    $response->assertRedirect(route('workspace.dashboard', ['workspace' => $workspace]));
    $this->assertSame(1, $user->oauthProviders()->count());
});

test('a returning user is logged in without creating a duplicate', function () {
    $user = User::factory()->create();

    UserOauthProvider::create([
        'user_id' => $user->id,
        'provider_name' => 'google',
        'provider_id' => 'google-789',
        'token' => 'token',
    ]);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-789',
        'email' => $user->email,
    ]));

    $response = $this->get(route('auth.social.callback', ['provider' => 'google']));

    $this->assertAuthenticatedAs($user);

    $workspace = $user->workspaces()->firstOrFail();

    $response->assertRedirect(route('workspace.dashboard', ['workspace' => $workspace]));
    $this->assertSame(1, User::count());
    $this->assertSame(1, UserOauthProvider::count());
});

test('an authenticated user can link a social provider', function () {
    $user = User::factory()->create();

    Socialite::fake('facebook', SocialiteUser::fake([
        'id' => 'fb-123',
        'email' => $user->email,
    ]));

    $response = $this->actingAs($user)
        ->get(route('auth.social.callback', ['provider' => 'facebook']));

    $workspace = $user->workspaces()->firstOrFail();

    $response->assertRedirect(route('workspace.dashboard', ['workspace' => $workspace]));

    $this->assertDatabaseHas('user_oauth_providers', [
        'user_id' => $user->id,
        'provider_name' => 'facebook',
        'provider_id' => 'fb-123',
    ]);
});

test('unsupported providers are rejected', function () {
    $this->get(route('auth.social.redirect', ['provider' => 'github']))
        ->assertNotFound();
});

test('a denied grant redirects back to login with an error', function () {
    Socialite::fake('google', function () {
        throw new InvalidStateException('Invalid state');
    });

    $this->get(route('auth.social.callback', ['provider' => 'google']))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
