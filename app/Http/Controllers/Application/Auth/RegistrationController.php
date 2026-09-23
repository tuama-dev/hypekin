<?php

namespace App\Http\Controllers\Application\Auth;

use App\Actions\Application\Auth\RegistrationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class RegistrationController extends Controller
{
    public function __construct(private readonly RegistrationAction $registrationAction) {}

    public function index()
    {
        if (Auth::check()) {
            return redirect()->route('workspace.dashboard');
        }

        return Inertia::render('Application/Auth/Registration');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = $this->registrationAction->execute($validated);

        Auth::login($user);

        $request->session()->regenerate();
        $request->session()->put('verification_sent_at', now());

        return redirect()
            ->route('verification.notice')
            ->with('flash', ['success' => 'A verification link has been sent to your email.']);
    }
}
