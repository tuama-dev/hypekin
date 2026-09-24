<?php

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Enums\WorkspaceRole;
use App\Models\User;

test('users can log in with valid credentials', function () {
    $user = User::factory()->create([
        'email' => 'jane@example.com',
        'password' => 'secret-password',
    ]);

    $response = $this->post(route('login.auth'), [
        'email' => $user->email,
        'password' => 'secret-password',
    ]);

    $workspace = $user->workspaces()->firstOrFail();

    $response->assertRedirect(route('workspace.dashboard', ['workspace' => $workspace]));
    $this->assertAuthenticatedAs($user);
});

test('users cannot log in with invalid credentials', function () {
    $user = User::factory()->create([
        'email' => 'jane@example.com',
        'password' => 'secret-password',
    ]);

    $response = $this->from(route('login'))->post(route('login.auth'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('flash.error');
    $this->assertGuest();
});

test('a user without a workspace gets one when logging in', function () {
    $user = User::factory()->create([
        'fullname' => 'John Doe',
        'email' => 'jane@example.com',
        'password' => 'secret-password',
    ]);

    $this->post(route('login.auth'), [
        'email' => 'jane@example.com',
        'password' => 'secret-password',
    ]);

    $this->assertAuthenticatedAs($user);

    $workspace = $user->workspaces()->firstOrFail();

    expect($workspace->only(['name', 'slug']))
        ->toBe([
            'name' => "John Doe's Workspace",
            'slug' => 'john-does-workspace',
        ]);

    $this->assertDatabaseHas('workspace_user', [
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner->value,
    ]);

    expect($user->workspaces()->count())->toBe(1);
});

test('a user with a workspace keeps only that one when logging in', function () {
    $user = User::factory()->create([
        'fullname' => 'John Doe',
        'email' => 'jane@example.com',
        'password' => 'secret-password',
    ]);

    $workspace = app(CreateWorkspaceAction::class)->execute($user);

    $this->post(route('login.auth'), [
        'email' => 'jane@example.com',
        'password' => 'secret-password',
    ])->assertRedirect(route('workspace.dashboard', ['workspace' => $workspace]));

    $this->assertAuthenticatedAs($user);

    expect($user->workspaces()->count())->toBe(1);
});
