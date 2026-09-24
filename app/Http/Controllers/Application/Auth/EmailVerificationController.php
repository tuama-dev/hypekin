<?php

namespace App\Http\Controllers\Application\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationController extends Controller
{
    /**
     * Show the email verification notice.
     */
    public function notice(Request $request): RedirectResponse|Response
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('workspace.dashboard', ['workspace' => $request->user()->workspaces()->first()]);
        }

        return Inertia::render('Application/Auth/VerifyEmail');
    }

    /**
     * Mark the authenticated user's email address as verified.
     */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->route('workspace.dashboard', ['workspace' => $request->user()->workspaces()->first()]);
    }

    /**
     * Resend the email verification notification.
     */
    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('workspace.dashboard');
        }

        if (self::nextResendAvailableAt($request)?->isFuture()) {
            return back()->with('flash', ['error' => 'Please wait before requesting another link.']);
        }

        $request->user()->sendEmailVerificationNotification();
        $request->session()->put('verification_sent_at', now());

        return back()->with('flash', ['success' => 'A new verification link has been sent to your email.']);
    }

    /**
     * When the user is allowed to resend the verification link, or null when no
     * link has been sent yet.
     */
    public static function nextResendAvailableAt(Request $request): ?Carbon
    {
        $sentAt = $request->session()->get('verification_sent_at');

        if ($sentAt === null) {
            return null;
        }

        return Carbon::parse($sentAt)->addSeconds((int) config('verification.resend_cooldown'));
    }
}
