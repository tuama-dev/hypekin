<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

/**
 * Per-workspace capabilities.
 *
 * EnsureWorkspaceMembership already answers "is this a member at all" with a
 * 404, so this policy only answers "may this member do this" with a 403. The
 * role is read from the pivot on every check rather than trusted from the
 * session, so a role change lands on the member's next request.
 *
 * @see docs/admins-roles-permissions-plan.md
 */
class WorkspacePolicy
{
    /**
     * Roles allowed to perform each ability. Reading a name upwards from the
     * weakest role keeps an accidental widening visible in a diff.
     *
     * @var array<string, list<WorkspaceRole>>
     */
    private const MATRIX = [
        'view' => [WorkspaceRole::Viewer, WorkspaceRole::Editor, WorkspaceRole::Admin, WorkspaceRole::Owner],
        'publish' => [WorkspaceRole::Editor, WorkspaceRole::Admin, WorkspaceRole::Owner],
        'manageMedia' => [WorkspaceRole::Editor, WorkspaceRole::Admin, WorkspaceRole::Owner],
        'update' => [WorkspaceRole::Admin, WorkspaceRole::Owner],
        'manageAccounts' => [WorkspaceRole::Admin, WorkspaceRole::Owner],
    ];

    /**
     * Read anything inside the workspace.
     */
    public function view(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, 'view');
    }

    /**
     * Rename the workspace.
     */
    public function update(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, 'update');
    }

    /**
     * Connect or revoke a social account.
     *
     * Withheld from editors because linking an account hands the workspace
     * long-lived tokens for someone else's page, and revoking one takes down
     * every post targeting it.
     */
    public function manageAccounts(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, 'manageAccounts');
    }

    /**
     * Create, schedule, reschedule or retry a post.
     */
    public function publish(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, 'publish');
    }

    /**
     * Upload or delete media.
     */
    public function manageMedia(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, 'manageMedia');
    }

    /**
     * Invite, remove or re-role a member. Owner only.
     *
     * Declared ahead of the invite flow so the member endpoints cannot later be
     * built against an unguarded surface.
     */
    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $workspace->roleFor($user) === WorkspaceRole::Owner;
    }

    /**
     * Every ability a role holds, for clients that hide controls they cannot use.
     *
     * Derived from the same matrix as the checks above so the interface and the
     * server cannot disagree about who may do what.
     *
     * @return list<string>
     */
    public function abilitiesFor(?WorkspaceRole $role): array
    {
        return array_keys(array_filter(
            self::MATRIX,
            fn (array $allowed): bool => $role !== null && in_array($role, $allowed, true),
        ));
    }

    private function allows(User $user, Workspace $workspace, string $ability): bool
    {
        $role = $workspace->roleFor($user);

        return $role !== null && in_array($role, self::MATRIX[$ability] ?? [], true);
    }
}
