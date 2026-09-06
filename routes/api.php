<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Http\Controllers\LiveHealthController;
use App\Modules\Shared\Infrastructure\Http\Controllers\ReadyHealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health/live', LiveHealthController::class)->name('api.v1.health.live');
    Route::get('/health/ready', ReadyHealthController::class)->name('api.v1.health.ready');
});
