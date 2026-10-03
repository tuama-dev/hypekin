<?php

namespace App\Http\Middleware;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Application\Auth\EmailVerificationController;
use App\Models\Workspace;
use App\Policies\WorkspacePolicy;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $workspaces = $user?->workspaces;

        $currentWorkspace = $request->route('workspace') instanceof Workspace
            ? $workspaces?->firstWhere('id', $request->route('workspace')->getKey())
            : null;

        $policy = app(WorkspacePolicy::class);

        // Read once: the pivot is already hydrated, so the extra query that
        // Workspace::roleFor() would run per request is not needed here.
        $currentRole = $currentWorkspace !== null
            ? (string) $currentWorkspace->pivot->role
            : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'workspace' => $currentWorkspace !== null
                    ? [
                        'id' => $currentWorkspace->getKey(),
                        'name' => $currentWorkspace->name,
                        'slug' => $currentWorkspace->slug,
                        'role' => $currentRole,
                        'abilities' => $policy->abilitiesFor(
                            $currentRole !== null ? WorkspaceRole::tryFrom($currentRole) : null,
                        ),
                    ]
                    : null,
                'workspaces' => $workspaces
                    ?->map(fn (Workspace $workspace) => [
                        'id' => $workspace->getKey(),
                        'name' => $workspace->name,
                        'slug' => $workspace->slug,
                        'role' => (string) $workspace->pivot->role,
                    ])
                    ->values(),
                'notifications' => $user
                    ?->notifications()
                    ->latest()
                    ->limit(8)
                    ->get()
                    ->map(fn ($notification) => [
                        'id' => $notification->getKey(),
                        'read_at' => $notification->read_at?->toIso8601String(),
                        'created_at' => $notification->created_at?->toIso8601String(),
                        'data' => $notification->data,
                    ])
                    ->values(),
                'unread_count' => $user?->unreadNotifications()->count() ?? 0,
            ],
            'flash' => [
                'error' => $request->session()->get('flash.error'),
                'success' => $request->session()->get('flash.success'),
            ],
            'verification' => $user !== null
                ? [
                    'resend_available_at' => EmailVerificationController::nextResendAvailableAt($request)?->toIso8601String(),
                ]
                : null,
        ];
    }
}
