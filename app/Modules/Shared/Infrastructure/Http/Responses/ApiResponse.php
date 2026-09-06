<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Http\Responses;

use App\Modules\Shared\Infrastructure\Http\Middleware\RequestCorrelationMiddleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiResponse
{
    /**
     * Return a standardized successful JSON response.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function success(mixed $data = null, int $status = Response::HTTP_OK, array $meta = []): JsonResponse
    {
        $requestId = self::resolveRequestId();

        $payload = [
            'success' => true,
            'data' => $data,
            'meta' => array_merge(['request_id' => $requestId], $meta),
        ];

        return response()->json($payload, $status);
    }

    /**
     * Return a standardized error JSON response.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function error(
        string $code,
        string $message,
        mixed $details = null,
        int $status = Response::HTTP_INTERNAL_SERVER_ERROR,
        array $meta = [],
    ): JsonResponse {
        $requestId = self::resolveRequestId();

        $payload = [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'meta' => array_merge(['request_id' => $requestId], $meta),
        ];

        return response()->json($payload, $status);
    }

    private static function resolveRequestId(): ?string
    {
        if (app()->bound('request')) {
            /** @var Request $request */
            $request = app('request');

            return $request->header(RequestCorrelationMiddleware::REQUEST_ID_HEADER);
        }

        return null;
    }
}
