<?php

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Enums\WorkspaceRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('the settings page renders for a member with workspace details', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $this->actingAs($user)
        ->get(route('workspace.settings', ['workspace' => $workspace]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Application/Workspace/Settings')
            ->where('auth.workspace.slug', $workspace->slug)
            ->where('memberCount', 1));
});

test('the settings page returns 404 for a user who is not a member', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('workspace.settings', ['workspace' => $workspace]))
        ->assertNotFound();
});

test('an owner can rename the workspace and its slug stays stable', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);
    $originalSlug = $workspace->slug;

    $response = $this->actingAs($user)
        ->put(route('workspace.settings.update', ['workspace' => $workspace]), [
            'name' => 'Acme Startup',
        ]);

    $workspace->refresh();

    $response->assertRedirect(route('workspace.settings', ['workspace' => $workspace]));
    $response->assertSessionHas('flash.success');

    $this->assertDatabaseHas('workspaces', [
        'id' => $workspace->id,
        'name' => 'Acme Startup',
        'slug' => $originalSlug,
    ]);
});

test('a viewer cannot rename the workspace', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $viewer = User::factory()->create();
    $workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer]);

    $originalName = $workspace->name;

    $this->actingAs($viewer)
        ->put(route('workspace.settings.update', ['workspace' => $workspace]), [
            'name' => 'Renamed by Viewer',
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('workspaces', [
        'id' => $workspace->id,
        'name' => $originalName,
    ]);
});

test('an empty workspace name is rejected', function () {
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $this->actingAs($user)
        ->from(route('workspace.settings', ['workspace' => $workspace]))
        ->put(route('workspace.settings.update', ['workspace' => $workspace]), [
            'name' => '',
        ])
        ->assertRedirect(route('workspace.settings', ['workspace' => $workspace]))
        ->assertSessionHasErrors('name');
});
