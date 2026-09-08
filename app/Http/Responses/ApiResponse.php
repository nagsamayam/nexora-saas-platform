<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Http\Middleware\RequestCorrelationMiddleware;
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
     * Return a standardized 201 Created JSON response.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function created(mixed $data = null, ?string $message = null, array $meta = []): JsonResponse
    {
        if ($message !== null) {
            $meta = array_merge(['message' => $message], $meta);
        }

        return self::success($data, Response::HTTP_CREATED, $meta);
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
