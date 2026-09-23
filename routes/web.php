<?php

use App\Http\Controllers\Application\Auth\AuthController;
use App\Http\Controllers\Application\Auth\EmailVerificationController;
use App\Http\Controllers\Application\Auth\RegistrationController;
use App\Http\Controllers\Application\Auth\SocialAuthController;
use App\Http\Controllers\Application\DashboardController;
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
    });

    Route::prefix('app')->middleware('auth')->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware('verified')
            ->name('workspace.dashboard');
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
