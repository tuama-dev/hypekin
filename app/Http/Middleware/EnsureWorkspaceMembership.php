<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceMembership
{
    /**
     * Verify the authenticated user belongs to the requested workspace.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');

        if ($workspace instanceof Workspace && ! $this->isMember($request, $workspace)) {
            abort(404);
        }

        return $next($request);
    }

    private function isMember(Request $request, Workspace $workspace): bool
    {
        return $request->user()->workspaces()
            ->where('workspaces.id', $workspace->getKey())
            ->exists();
    }
}
