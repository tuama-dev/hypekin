<?php

use App\Http\Controllers\Application\AuthController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::group([], function (): void {
    Route::view('/', 'marketing')->name('home');
});

Route::group([], function (): void {
    Route::get('/login', [AuthController::class, 'index'])->name('login');
    Route::post('/login', [AuthController::class, 'auth'])->name('login.auth');
    
    Route::prefix('app')->middleware('auth')->group(function (): void {
        Route::get('/dashboard', fn () => Inertia::render('workspace/dashboard'))->name('workspace.dashboard');
        Route::get('/logout', [AuthController::class, 'logout'])->name('workspace.logout');
    });
});
