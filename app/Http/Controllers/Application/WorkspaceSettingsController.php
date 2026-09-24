<?php

namespace App\Http\Controllers\Application;

use App\Actions\Application\Workspace\UpdateWorkspaceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\UpdateWorkspaceRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceSettingsController extends Controller
{
    public function __construct(private readonly UpdateWorkspaceAction $updateWorkspace) {}

    public function index(Workspace $workspace): Response
    {
        return Inertia::render('Application/Workspace/Settings', [
            'memberCount' => $workspace->users()->count(),
            'createdAt' => $workspace->created_at?->toFormattedDateString(),
        ]);
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): RedirectResponse
    {
        $workspace = $this->updateWorkspace->execute($workspace, $request->validated()['name']);

        return redirect()
            ->route('workspace.settings', ['workspace' => $workspace])
            ->with('flash', ['success' => 'Workspace settings updated.']);
    }
}
