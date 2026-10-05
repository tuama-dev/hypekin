<?php

namespace App\Http\Controllers\Application;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * List the user's notifications for the workspace in the URL.
     *
     * Notifications carry the workspace they belong to, so the listing is
     * filtered by it rather than showing every workspace at once — otherwise
     * opening one workspace's notification page would surface and mark read
     * activity from workspaces the user is no longer a member of.
     */
    public function index(Request $request, Workspace $workspace): Response
    {
        return Inertia::render('Application/Notifications/Index', [
            'notifications' => Inertia::scroll(
                $request->user()->notifications()
                    ->where('data->workspace_slug', $workspace->slug)
                    ->latest()
                    ->orderByDesc('id')
                    ->paginate(20)
                    ->through(fn (DatabaseNotification $notification): array => [
                        'id' => $notification->getKey(),
                        'read_at' => $notification->read_at?->toIso8601String(),
                        'created_at' => $notification->created_at?->toIso8601String(),
                        'data' => $notification->data,
                    ]),
            ),
        ]);
    }

    /**
     * Mark a single notification as read.
     *
     * Scoped to the workspace in the URL for the same reason as index(): the
     * notification must belong to the workspace being viewed.
     */
    public function read(Request $request, Workspace $workspace, string $notificationId): RedirectResponse
    {
        $notification = $request->user()
            ->notifications()
            ->where('data->workspace_slug', $workspace->slug)
            ->whereKey($notificationId)
            ->firstOrFail();

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return redirect()->back();
    }

    /**
     * Mark every notification for the workspace as read.
     */
    public function readAll(Request $request, Workspace $workspace): RedirectResponse
    {
        $request->user()
            ->unreadNotifications()
            ->where('data->workspace_slug', $workspace->slug)
            ->update(['read_at' => now()]);

        return redirect()->back();
    }
}
