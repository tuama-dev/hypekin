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
     * List the user's notifications across all workspaces.
     */
    public function index(Request $request, Workspace $workspace): Response
    {
        return Inertia::render('Application/Notifications/Index', [
            'notifications' => Inertia::scroll(
                $request->user()->notifications()
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
     */
    public function read(Request $request, Workspace $workspace, string $notificationId): RedirectResponse
    {
        $notification = $request->user()
            ->notifications()
            ->whereKey($notificationId)
            ->firstOrFail();

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return redirect()->back();
    }

    /**
     * Mark every notification for the authenticated user as read.
     */
    public function readAll(Request $request, Workspace $workspace): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return redirect()->back();
    }
}
