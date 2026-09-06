<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Infrastructure\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ReadyHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'rabbitmq' => $this->checkRabbitMq(),
        ];

        $allHealthy = collect($checks)->every(static fn (array $check): bool => $check['status'] === 'ok');

        $status = $allHealthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        if ($allHealthy) {
            return ApiResponse::success([
                'status' => 'ready',
                'timestamp' => now()->toIso8601String(),
                'checks' => $checks,
            ], $status);
        }

        return ApiResponse::error(
            'DEPENDENCY_UNHEALTHY',
            'One or more system dependencies are unavailable.',
            $checks,
            $status,
        );
    }

    /**
     * @return array{status: string, message?: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::select('SELECT 1');

            return ['status' => 'ok'];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{status: string, message?: string}
     */
    private function checkRedis(): array
    {
        try {
            Redis::connection('default')->ping();

            return ['status' => 'ok'];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{status: string, message?: string}
     */
    private function checkRabbitMq(): array
    {
        $host = (string) config('queue.connections.rabbitmq.hosts.0.host', '127.0.0.1');
        $port = (int) config('queue.connections.rabbitmq.hosts.0.port', 5672);

        try {
            $connection = @fsockopen($host, $port, $errorCode, $errorMessage, 2.0);

            if (is_resource($connection)) {
                fclose($connection);

                return ['status' => 'ok'];
            }

            return [
                'status' => 'failed',
                'message' => $errorMessage ?: "Unable to connect to RabbitMQ broker on {$host}:{$port}",
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
        }
    }
}
