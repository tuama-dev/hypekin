<?php

use App\Http\Controllers\Application\Auth\AuthController;
use App\Http\Controllers\Application\Auth\EmailVerificationController;
use App\Http\Controllers\Application\Auth\RegistrationController;
use App\Http\Controllers\Application\Auth\SocialAuthController;
use App\Http\Controllers\Application\CalendarController;
use App\Http\Controllers\Application\DashboardController;
use App\Http\Controllers\Application\MediaController;
use App\Http\Controllers\Application\NotificationController;
use App\Http\Controllers\Application\PostController;
use App\Http\Controllers\Application\SocialAccountController;
use App\Http\Controllers\Application\WorkspaceSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('marketing'))->name('home');

Route::group([], function (): void {
    Route::get('/register', [RegistrationController::class, 'index'])->name('register');
    Route::post('/register', [RegistrationController::class, 'store'])->name('register.store');

    Route::get('/login', [AuthController::class, 'index'])->name('login');
    Route::post('/login', [AuthController::class, 'auth'])->name('login.auth');

    Route::prefix('auth')->group(function (): void {
        Route::get('/{provider}/redirect', [SocialAuthController::class, 'redirect'])->name('auth.social.redirect');
        Route::get('/{provider}/callback', [SocialAuthController::class, 'callback'])->name('auth.social.callback');
        Route::get('/social-accounts/{platform}/callback', [SocialAccountController::class, 'callback'])
            ->middleware('auth')
            ->name('workspace.accounts.callback');
    });

    Route::prefix('app')->middleware('auth')->group(function (): void {
        Route::get('/{workspace:slug}/dashboard', [DashboardController::class, 'index'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.dashboard');
        Route::get('/{workspace:slug}/settings', [WorkspaceSettingsController::class, 'index'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.settings');
        Route::put('/{workspace:slug}/settings', [WorkspaceSettingsController::class, 'update'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.settings.update');
        Route::get('/{workspace:slug}/accounts', [SocialAccountController::class, 'index'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.accounts');
        Route::get('/{workspace:slug}/accounts/{platform}/connect', [SocialAccountController::class, 'connect'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.accounts.connect');
        Route::delete('/{workspace:slug}/accounts/{account}', [SocialAccountController::class, 'destroy'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.accounts.destroy');

        Route::get('/{workspace:slug}/posts', [PostController::class, 'index'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.posts');
        Route::get('/{workspace:slug}/posts/create', [PostController::class, 'create'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.posts.create');
        Route::get('/{workspace:slug}/posts/{post}', [PostController::class, 'show'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.posts.show');
        Route::post('/{workspace:slug}/posts', [PostController::class, 'store'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.posts.store');
        Route::patch('/{workspace:slug}/posts/{post}/scheduled_at', [PostController::class, 'reschedule'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.posts.reschedule');
        Route::get('/{workspace:slug}/calendar', [CalendarController::class, 'index'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.calendar');
        Route::post('/{workspace:slug}/media/intent', [MediaController::class, 'intent'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.media.intent');
        Route::post('/{workspace:slug}/media/complete', [MediaController::class, 'complete'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.media.complete');
        Route::get('/{workspace:slug}/media', [MediaController::class, 'index'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.media');
        Route::delete('/{workspace:slug}/media/{media}', [MediaController::class, 'destroy'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.media.destroy');
        Route::get('/{workspace:slug}/notifications', [NotificationController::class, 'index'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.notifications.index');
        Route::post('/{workspace:slug}/notifications/read-all', [NotificationController::class, 'readAll'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.notifications.read-all');
        Route::patch('/{workspace:slug}/notifications/{notification}', [NotificationController::class, 'read'])
            ->middleware(['verified', 'workspace'])
            ->name('workspace.notifications.read');
        Route::post('/logout', [AuthController::class, 'logout'])->name('workspace.logout');
    });

    Route::prefix('email')->middleware('auth')->group(function (): void {
        Route::get('/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
        Route::get('/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware('signed')
            ->name('verification.verify');
        Route::post('/verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:6,1')
            ->name('verification.send');
    });
});
