<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AdminAuthController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Health\LiveHealthController;
use App\Http\Controllers\Health\ReadyHealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', LiveHealthController::class)->name('api.health.live');
Route::get('/health/ready', ReadyHealthController::class)->name('api.health.ready');

Route::prefix('v1')->group(function (): void {

    // Authentication Routes
    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:auth-register')
            ->name('api.v1.auth.register');

        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:auth-login')
            ->name('api.v1.auth.login');

        Route::post('/refresh', [AuthController::class, 'refresh'])
            ->middleware('throttle:auth-refresh')
            ->name('api.v1.auth.refresh');

        Route::middleware('auth.jwt')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
            Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('api.v1.auth.logout-all');
            Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');

            // Session Management
            Route::get('/sessions', [AuthController::class, 'sessions'])->name('api.v1.auth.sessions.index');
            Route::delete('/sessions/{session}', [AuthController::class, 'revokeSession'])->name('api.v1.auth.sessions.revoke');
        });
    });

    // Admin Routes
    Route::prefix('admin')->middleware('auth.jwt')->group(function (): void {
        Route::post('/users/{user}/revoke-sessions', [AdminAuthController::class, 'revokeUserSessions'])
            ->name('api.v1.admin.users.revoke-sessions');
    });
});
