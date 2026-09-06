<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestCorrelationMiddleware
{
    public const REQUEST_ID_HEADER = 'X-Request-ID';

    public const CORRELATION_ID_HEADER = 'X-Correlation-ID';

    public const CAUSATION_ID_HEADER = 'X-Causation-ID';

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $requestId = (string) ($request->header(self::REQUEST_ID_HEADER) ?: Str::uuid());
        $correlationId = (string) ($request->header(self::CORRELATION_ID_HEADER) ?: $requestId);
        $causationId = (string) ($request->header(self::CAUSATION_ID_HEADER) ?: $requestId);

        $request->headers->set(self::REQUEST_ID_HEADER, $requestId);
        $request->headers->set(self::CORRELATION_ID_HEADER, $correlationId);
        $request->headers->set(self::CAUSATION_ID_HEADER, $causationId);

        Context::add([
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'causation_id' => $causationId,
        ]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::REQUEST_ID_HEADER, $requestId);
        $response->headers->set(self::CORRELATION_ID_HEADER, $correlationId);
        $response->headers->set(self::CAUSATION_ID_HEADER, $causationId);

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('HTTP request processed', [
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $response;
    }
}
