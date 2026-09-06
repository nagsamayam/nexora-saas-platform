<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'cache' => $this->checkCache(),
        ];

        $healthy = collect($checks)
            ->every(static fn (array $check): bool => $check['status'] === 'ok');

        return response()->json([
            'status' => $healthy ? 'ok' : 'unhealthy',
            'service' => config('app.name'),
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            DB::select('SELECT 1');

            return [
                'status' => 'ok',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'status' => 'failed',
                'message' => 'Database unavailable.',
            ];
        }
    }

    private function checkRedis(): array
    {
        try {
            Redis::connection('default')->ping();

            return [
                'status' => 'ok',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'status' => 'failed',
                'message' => 'Redis unavailable.',
            ];
        }
    }

    private function checkCache(): array
    {
        $key = 'health:cache';

        try {
            Cache::put($key, 'ok', now()->addSeconds(30));

            $value = Cache::get($key);

            Cache::forget($key);

            if ($value !== 'ok') {
                return [
                    'status' => 'failed',
                    'message' => 'Cache read/write verification failed.',
                ];
            }

            return [
                'status' => 'ok',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'status' => 'failed',
                'message' => 'Cache unavailable.',
            ];
        }
    }
}
