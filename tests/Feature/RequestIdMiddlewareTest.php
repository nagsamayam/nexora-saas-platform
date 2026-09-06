<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Http\Middleware\RequestCorrelationMiddleware;
use Illuminate\Support\Str;

test('request generates request ID, correlation ID, and causation ID when absent', function () {
    $response = $this->getJson('/api/v1/health/live');

    $response->assertOk();

    $requestId = $response->headers->get(RequestCorrelationMiddleware::REQUEST_ID_HEADER);
    $correlationId = $response->headers->get(RequestCorrelationMiddleware::CORRELATION_ID_HEADER);
    $causationId = $response->headers->get(RequestCorrelationMiddleware::CAUSATION_ID_HEADER);

    expect($requestId)->not->toBeEmpty()
        ->and(Str::isUuid($requestId))->toBeTrue()
        ->and($correlationId)->toBe($requestId)
        ->and($causationId)->toBe($requestId);

    $response->assertJsonPath('meta.request_id', $requestId);
});

test('request preserves provided correlation ID and causation ID', function () {
    $customRequestId = (string) Str::uuid();
    $customCorrelationId = (string) Str::uuid();
    $customCausationId = (string) Str::uuid();

    $response = $this->withHeaders([
        RequestCorrelationMiddleware::REQUEST_ID_HEADER => $customRequestId,
        RequestCorrelationMiddleware::CORRELATION_ID_HEADER => $customCorrelationId,
        RequestCorrelationMiddleware::CAUSATION_ID_HEADER => $customCausationId,
    ])->getJson('/api/v1/health/live');

    $response->assertOk()
        ->assertHeader(RequestCorrelationMiddleware::REQUEST_ID_HEADER, $customRequestId)
        ->assertHeader(RequestCorrelationMiddleware::CORRELATION_ID_HEADER, $customCorrelationId)
        ->assertHeader(RequestCorrelationMiddleware::CAUSATION_ID_HEADER, $customCausationId)
        ->assertJsonPath('meta.request_id', $customRequestId);
});
