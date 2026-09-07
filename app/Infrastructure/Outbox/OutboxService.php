<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use App\Domain\Outbox\Enums\OutboxEventType;
use App\Domain\Outbox\Enums\OutboxStatus;
use App\Domain\Outbox\Models\OutboxMessage;
use App\Infrastructure\Audit\BlameContext;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

final class OutboxService
{
    /**
     * Persist an event into the outbox.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function record(
        OutboxEventType|string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        ?string $eventKey = null,
        array $headers = [],
    ): OutboxMessage {
        $eventTypeName = $eventType instanceof OutboxEventType ? $eventType->value : $eventType;

        $correlationId = Context::get('correlation_id');
        $requestId = Context::get('request_id');
        $causationId = Context::get('causation_id');

        $defaultHeaders = [
            'event_id' => (string) Str::uuid(),
            'correlation_id' => is_string($correlationId) ? $correlationId : null,
            'request_id' => is_string($requestId) ? $requestId : null,
            'causation_id' => is_string($causationId) ? $causationId : null,
            'actor_id' => BlameContext::getActorId(),
            'user_id' => BlameContext::getUserId(),
            'ip_address' => BlameContext::getIpAddress(),
            'user_agent' => BlameContext::getUserAgent(),
        ];

        $mergedHeaders = array_merge($defaultHeaders, $headers);

        /** @var OutboxMessage $message */
        $message = OutboxMessage::create([
            'event_type' => $eventTypeName,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_key' => $eventKey,
            'payload' => $payload,
            'headers' => $mergedHeaders,
            'status' => OutboxStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
        ]);

        return $message;
    }
}
