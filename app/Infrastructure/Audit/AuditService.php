<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Audit\Models\AuditLog;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AuditService
{
    /**
     * Record an append-only audit event.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        AuditEventType $eventType,
        ?string $userId = null,
        ?string $actorId = null,
        ?string $sessionId = null,
        ?array $metadata = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ?AuditLog {
        try {
            $resolvedActorId = $actorId ?? BlameContext::getActorId();
            $resolvedUserId = $userId ?? BlameContext::getUserId();
            $resolvedSessionId = $sessionId ?? BlameContext::getSessionId();
            $resolvedIp = $ipAddress ?? BlameContext::getIpAddress();
            $resolvedUserAgent = $userAgent ?? BlameContext::getUserAgent();

            return AuditLog::create([
                'event_type' => $eventType,
                'actor_id' => $resolvedActorId,
                'user_id' => $resolvedUserId,
                'session_id' => $resolvedSessionId,
                'ip_address' => $resolvedIp,
                'user_agent' => $resolvedUserAgent,
                'metadata' => $metadata,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to write audit log', [
                'event_type' => $eventType->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
