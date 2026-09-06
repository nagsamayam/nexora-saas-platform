<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Http\Middleware\RequestCorrelationMiddleware;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
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
        ->and($causationId)->toBe($requestId)
        ->and(Context::get('request_id'))->toBe($requestId)
        ->and(Context::get('correlation_id'))->toBe($correlationId)
        ->and(Context::get('causation_id'))->toBe($causationId);

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

    expect(Context::get('request_id'))->toBe($customRequestId)
        ->and(Context::get('correlation_id'))->toBe($customCorrelationId)
        ->and(Context::get('causation_id'))->toBe($customCausationId);
});

test('request logs structured message with correlation context', function () {
    Log::spy();

    $response = $this->getJson('/api/v1/health/live');
    $response->assertOk();

    $requestId = $response->headers->get(RequestCorrelationMiddleware::REQUEST_ID_HEADER);

    Log::shouldHaveReceived('info')->with(
        'HTTP request processed',
        Mockery::on(function (array $context) {
            return $context['method'] === 'GET'
                && $context['uri'] === '/api/v1/health/live'
                && $context['status'] === 200
                && is_float($context['duration_ms'])
                && array_key_exists('ip', $context)
                && array_key_exists('user_agent', $context);
        })
    )->once();

    expect(Context::get('request_id'))->toBe($requestId);
});
