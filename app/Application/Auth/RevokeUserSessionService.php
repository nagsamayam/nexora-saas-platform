<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Auth\Enums\RefreshTokenStatus;
use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Models\AuthRefreshToken;
use App\Domain\Auth\Models\AuthSession;
use App\Infrastructure\Audit\AuditService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RevokeUserSessionService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    public function execute(User $user, string $sessionId): bool
    {
        return DB::transaction(function () use ($user, $sessionId) {
            /** @var AuthSession|null $session */
            $session = AuthSession::where('id', $sessionId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                return false;
            }

            if ($session->status === SessionStatus::Active) {
                $session->update([
                    'status' => SessionStatus::Revoked,
                    'revoked_at' => now(),
                ]);

                AuthRefreshToken::where('session_id', $session->id)
                    ->where('status', RefreshTokenStatus::Active)
                    ->update([
                        'status' => RefreshTokenStatus::Revoked,
                        'revoked_at' => now(),
                    ]);

                $this->auditService->record(
                    eventType: AuditEventType::UserLoggedOut,
                    userId: (string) $user->id,
                    sessionId: $session->id,
                    metadata: ['revoked_by' => 'user_session_management']
                );
            }

            return true;
        });
    }
}
