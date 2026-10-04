<?php

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Http\Requests\Post\AiCaptionRequest;
use App\Http\Requests\Post\StorePostRequest;
use App\Http\Requests\Post\UpdatePostScheduleRequest;
use App\Http\Requests\Workspace\UpdateWorkspaceRequest;
use App\Http\Requests\WorkspaceAbilityRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

/**
 * Build a workspace in which the given user holds the given role.
 *
 * CreateWorkspaceAction::ensure() attaches the user as owner, so the pivot row
 * is re-written rather than inserted a second time — workspace_user has a
 * composite primary key and a duplicate (workspace, user) pair throws.
 */
function workspaceWithRole(WorkspaceRole $role): array
{
    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($user);

    $workspace->users()->updateExistingPivot($user->getKey(), ['role' => $role->value]);

    return [$user, $workspace];
}

/**
 * Resolve a workspace ability request outside the HTTP pipeline so authorize()
 * can be asserted directly.
 *
 * The route's `can:` middleware runs first in a real request and would mask the
 * answer, so these tests hand the request a route that carries a workspace and
 * no ability check.
 */
function abilityRequest(string $case, User $user, ?Workspace $workspace): WorkspaceAbilityRequest
{
    $request = match ($case) {
        'create post' => new StorePostRequest,
        'generate a caption' => new AiCaptionRequest,
        'reschedule a post' => new UpdatePostScheduleRequest,
        'rename a workspace' => new UpdateWorkspaceRequest,
        default => throw new InvalidArgumentException("Unknown ability request case [{$case}]."),
    };

    $request->setContainer(app());

    $route = new RoutingRoute(['POST'], '/ability-probe', []);
    $route->parameters = $workspace === null ? [] : ['workspace' => $workspace];

    $request->setRouteResolver(fn (): RoutingRoute => $route);
    $request->setUserResolver(fn (): User => $user);

    return $request;
}

/*
|--------------------------------------------------------------------------
| Workspace::roleFor
|--------------------------------------------------------------------------
*/

test('roleFor returns the role held in the pivot', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $admin = User::factory()->create();
    $workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin]);

    expect($workspace->roleFor($owner))->toBe(WorkspaceRole::Owner)
        ->and($workspace->roleFor($admin))->toBe(WorkspaceRole::Admin);
});

test('roleFor returns null for a non-member', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $outsider = User::factory()->create();

    expect($workspace->roleFor($outsider))->toBeNull();
});

test('roleFor returns null for a role value the enum does not know', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $user = User::factory()->create();
    $workspace->users()->attach($user, ['role' => 'superuser']);

    // An unrecognised role must not fall back to the weakest role, which would
    // silently grant view access to whoever the pivot happens to name.
    expect($workspace->roleFor($user))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Policy matrix
|--------------------------------------------------------------------------
|
| The full matrix lives here rather than in HTTP tests: a failure then names the
| rule that is wrong. The endpoint tests below prove each route applies it.
|
*/

dataset('abilities', [
    'viewer may view' => [WorkspaceRole::Viewer, 'view', true],
    'viewer may publish' => [WorkspaceRole::Viewer, 'publish', false],
    'viewer may manage media' => [WorkspaceRole::Viewer, 'manageMedia', false],
    'viewer may update the workspace' => [WorkspaceRole::Viewer, 'update', false],
    'viewer may manage accounts' => [WorkspaceRole::Viewer, 'manageAccounts', false],
    'editor may view' => [WorkspaceRole::Editor, 'view', true],
    'editor may publish' => [WorkspaceRole::Editor, 'publish', true],
    'editor may manage media' => [WorkspaceRole::Editor, 'manageMedia', true],
    'editor may update the workspace' => [WorkspaceRole::Editor, 'update', false],
    'editor may manage accounts' => [WorkspaceRole::Editor, 'manageAccounts', false],
    'admin may view' => [WorkspaceRole::Admin, 'view', true],
    'admin may publish' => [WorkspaceRole::Admin, 'publish', true],
    'admin may manage media' => [WorkspaceRole::Admin, 'manageMedia', true],
    'admin may update the workspace' => [WorkspaceRole::Admin, 'update', true],
    'admin may manage accounts' => [WorkspaceRole::Admin, 'manageAccounts', true],
    'owner may view' => [WorkspaceRole::Owner, 'view', true],
    'owner may publish' => [WorkspaceRole::Owner, 'publish', true],
    'owner may manage media' => [WorkspaceRole::Owner, 'manageMedia', true],
    'owner may update the workspace' => [WorkspaceRole::Owner, 'update', true],
    'owner may manage accounts' => [WorkspaceRole::Owner, 'manageAccounts', true],
]);

test('the workspace policy grants an ability only to the roles that hold it', function (WorkspaceRole $role, string $ability, bool $allowed) {
    [$user, $workspace] = workspaceWithRole($role);

    expect($user->can($ability, $workspace))->toBe($allowed);
})->with('abilities');

test('only an owner may manage members', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $admin = User::factory()->create();
    $workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin]);

    expect($owner->can('manageMembers', $workspace))->toBeTrue()
        ->and($admin->can('manageMembers', $workspace))->toBeFalse();
});

test('a non-member is refused every ability', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $outsider = User::factory()->create();

    foreach (['view', 'publish', 'manageMedia', 'update', 'manageAccounts', 'manageMembers'] as $ability) {
        expect($outsider->can($ability, $workspace))->toBeFalse();
    }
});

/*
|--------------------------------------------------------------------------
| Endpoints apply the policy
|--------------------------------------------------------------------------
|
| One refused role per ability proves the route is guarded; the matrix above
| already covers which roles pass.
|
*/

test('a viewer cannot open the composer', function () {
    [$viewer, $workspace] = workspaceWithRole(WorkspaceRole::Viewer);

    $this->actingAs($viewer)
        ->get(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertForbidden();
});

test('a viewer cannot connect a social account', function () {
    [$viewer, $workspace] = workspaceWithRole(WorkspaceRole::Viewer);

    $this->actingAs($viewer)
        ->get(route('workspace.accounts.connect', [
            'workspace' => $workspace,
            'platform' => Platform::Tiktok->value,
        ]))
        ->assertForbidden();
});

test('a viewer cannot request a media upload intent', function () {
    [$viewer, $workspace] = workspaceWithRole(WorkspaceRole::Viewer);

    $this->actingAs($viewer)
        ->postJson(route('workspace.media.intent', ['workspace' => $workspace]), [
            'filename' => 'clip.mp4',
            'content_type' => 'video/mp4',
            'bytes' => 1024,
        ])
        ->assertForbidden();
});

test('a viewer can still read the workspace', function () {
    [$viewer, $workspace] = workspaceWithRole(WorkspaceRole::Viewer);

    $this->actingAs($viewer)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertOk();
});

test('a viewer can still sign out', function () {
    [$viewer, $workspace] = workspaceWithRole(WorkspaceRole::Viewer);

    // Logout is deliberately ungated: a member who cannot do anything else must
    // never be trapped in the app.
    $this->actingAs($viewer)
        ->post(route('workspace.logout', ['workspace' => $workspace]))
        ->assertRedirect();
});

test('an outsider gets 404 rather than 403 so the workspace stays hidden', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertNotFound();
});

test('a role change applies on the member next request without re-login', function () {
    $owner = User::factory()->create();
    $workspace = app(CreateWorkspaceAction::class)->ensure($owner);

    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => WorkspaceRole::Viewer]);

    $this->actingAs($member)
        ->get(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertForbidden();

    $workspace->users()->updateExistingPivot($member->id, ['role' => WorkspaceRole::Editor->value]);

    // Same session, no re-login: the policy reads the pivot rather than a role
    // cached on the user instance.
    $this->actingAs($member)
        ->get(route('workspace.posts.create', ['workspace' => $workspace]))
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| Shared abilities
|--------------------------------------------------------------------------
|
| The interface hides the controls a member cannot use, so the ability list in
| the shared props has to come from the same matrix the routes enforce.
|
*/

test('a viewer is offered only the abilities the policy allows', function () {
    [$viewer, $workspace] = workspaceWithRole(WorkspaceRole::Viewer);

    $this->actingAs($viewer)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.workspace.role', 'viewer')
            ->where('auth.workspace.abilities', ['view']));
});

test('an owner is offered every ability the policy defines', function () {
    [$owner, $workspace] = workspaceWithRole(WorkspaceRole::Owner);

    $this->actingAs($owner)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.workspace.abilities', [
                'view',
                'publish',
                'manageMedia',
                'update',
                'manageAccounts',
            ]));
});

test('an editor is offered publishing but not account management', function () {
    [$editor, $workspace] = workspaceWithRole(WorkspaceRole::Editor);

    $this->actingAs($editor)
        ->get(route('workspace.dashboard', ['workspace' => $workspace]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('auth.workspace.abilities', ['view', 'publish', 'manageMedia']));
});

/*
|--------------------------------------------------------------------------
| Workspace ability requests
|--------------------------------------------------------------------------
|
| The `can:` middleware is the first gate, but it only protects the routes that
| remember it. Each request below also carries its ability, so a workspace
| mutation added later without that middleware still refuses a member who does
| not hold it. These tests assert that second gate on its own, which a normal
| request can never reach.
|
*/

dataset('workspace ability requests', [
    'creating a post needs publish' => ['create post', WorkspaceRole::Viewer, WorkspaceRole::Editor],
    'a caption needs publish' => ['generate a caption', WorkspaceRole::Viewer, WorkspaceRole::Editor],
    'rescheduling needs publish' => ['reschedule a post', WorkspaceRole::Viewer, WorkspaceRole::Editor],
    'renaming needs update, not publish' => ['rename a workspace', WorkspaceRole::Editor, WorkspaceRole::Owner],
]);

test('a workspace ability request refuses a member who does not hold the ability', function (string $case, WorkspaceRole $refused, WorkspaceRole $granted) {
    [$user, $workspace] = workspaceWithRole($refused);

    expect(abilityRequest($case, $user, $workspace)->authorize())->toBeFalse();
})->with('workspace ability requests');

test('a workspace ability request admits a member who holds the ability', function (string $case, WorkspaceRole $refused, WorkspaceRole $granted) {
    [$user, $workspace] = workspaceWithRole($granted);

    expect(abilityRequest($case, $user, $workspace)->authorize())->toBeTrue();
})->with('workspace ability requests');

test('a workspace ability request refuses a member when the route carries no workspace', function (string $case, WorkspaceRole $refused, WorkspaceRole $granted) {
    [$user] = workspaceWithRole(WorkspaceRole::Owner);

    // An owner holding every ability is still refused: without a workspace there
    // is nothing to check the ability against, and guessing must not pass.
    expect(abilityRequest($case, $user, null)->authorize())->toBeFalse();
})->with('workspace ability requests');

test('a workspace mutation is refused even when the route forgot the ability middleware', function () {
    Route::put('/ungated/{workspace}/settings', function (UpdateWorkspaceRequest $request, Workspace $workspace) {
        return response()->noContent();
    })->middleware('web')->name('ungated.settings');

    [$editor, $editorWorkspace] = workspaceWithRole(WorkspaceRole::Editor);
    [$owner, $ownerWorkspace] = workspaceWithRole(WorkspaceRole::Owner);

    $url = fn (Workspace $target): string => url("/ungated/{$target->slug}/settings");

    $this->actingAs($editor)
        ->put($url($editorWorkspace), ['name' => 'Renamed'])
        ->assertForbidden();

    // The same request from the role that does hold the ability reaches the
    // controller, so the refusal above came from the request and nowhere else.
    $this->actingAs($owner)
        ->put($url($ownerWorkspace), ['name' => 'Renamed'])
        ->assertNoContent();
});

test('every workspace mutation route applies a workspace ability', function () {
    $unguarded = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        // app/logout is excluded by the uri filter below: it carries no
        // workspace, and must stay reachable for a member who can do nothing.
        if (! str_starts_with($route->uri(), 'app/{workspace')
            || array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
            continue;
        }

        $applies = false;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                $applies = true;

                break;
            }
        }

        if (! $applies) {
            $unguarded[] = implode('|', $route->methods()).' '.$route->uri();
        }
    }

    // A new mutation without an ability gate fails here rather than in review.
    expect($unguarded)->toBe([]);
});
