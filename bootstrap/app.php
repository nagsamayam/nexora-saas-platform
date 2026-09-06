<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Http\Middleware\RequestCorrelationMiddleware;
use App\Modules\Shared\Infrastructure\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            RequestCorrelationMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            if ($e instanceof ValidationException) {
                return ApiResponse::error(
                    code: 'VALIDATION_FAILED',
                    message: $e->getMessage(),
                    details: $e->errors(),
                    status: Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            if ($e instanceof AuthenticationException) {
                return ApiResponse::error(
                    code: 'UNAUTHENTICATED',
                    message: $e->getMessage() ?: 'Unauthenticated.',
                    status: Response::HTTP_UNAUTHORIZED,
                );
            }

            $statusCode = $e instanceof HttpExceptionInterface
                ? $e->getStatusCode()
                : Response::HTTP_INTERNAL_SERVER_ERROR;

            $errorCode = match ($statusCode) {
                Response::HTTP_BAD_REQUEST => 'BAD_REQUEST',
                Response::HTTP_UNAUTHORIZED => 'UNAUTHENTICATED',
                Response::HTTP_FORBIDDEN => 'UNAUTHORIZED',
                Response::HTTP_NOT_FOUND => 'NOT_FOUND',
                Response::HTTP_CONFLICT => 'CONFLICT',
                Response::HTTP_TOO_MANY_REQUESTS => 'RATE_LIMITED',
                Response::HTTP_SERVICE_UNAVAILABLE => 'SERVICE_UNAVAILABLE',
                default => 'INTERNAL_ERROR',
            };

            $message = ($statusCode === Response::HTTP_INTERNAL_SERVER_ERROR && ! config('app.debug'))
                ? 'An unexpected error occurred.'
                : $e->getMessage();

            return ApiResponse::error(
                code: $errorCode,
                message: $message ?: 'Server error',
                status: $statusCode,
            );
        });
    })->create();
