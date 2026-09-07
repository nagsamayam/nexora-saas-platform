<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('auth-register', function (Request $request) {
            /** @var int $limit */
            $limit = config('auth.rate_limiting.register', 5);

            return Limit::perMinute($limit)->by($request->ip() ?: 'unknown');
        });

        RateLimiter::for('auth-login', function (Request $request) {
            /** @var int $limit */
            $limit = config('auth.rate_limiting.login', 10);

            return Limit::perMinute($limit)->by($request->ip() ?: 'unknown');
        });

        RateLimiter::for('auth-refresh', function (Request $request) {
            /** @var int $limit */
            $limit = config('auth.rate_limiting.refresh', 20);

            return Limit::perMinute($limit)->by($request->ip() ?: 'unknown');
        });
    }
}
