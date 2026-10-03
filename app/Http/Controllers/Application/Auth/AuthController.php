<?php

namespace App\Http\Controllers\Application\Auth;

use App\Actions\Application\Workspace\CreateWorkspaceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AuthRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function __construct(private readonly CreateWorkspaceAction $createWorkspace) {}

    public function index()
    {
        if (Auth::check()) {
            return redirect()->route('workspace.dashboard', ['workspace' => Auth::user()->workspaces()->first()]);
        }

        return Inertia::render('Application/Auth/Login');
    }

    public function auth(AuthRequest $request): RedirectResponse
    {
        $credentials = $request->validated();
        $remember = $request->boolean('remember');
        unset($credentials['remember']);

        if (! Auth::attempt($credentials, $remember)) {
            return redirect()->back()->with('flash', ['error' => 'Wrong email or password']);
        }

        $request->session()->regenerate();

        $user = Auth::user();

        if ($user !== null) {
            $this->createWorkspace->ensure($user);

            return redirect()->intended(route('workspace.dashboard', ['workspace' => $user->workspaces()->first()]));
        }

        return redirect()->route('login');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location(route('home'));
    }
}
