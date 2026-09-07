<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Auth\Enums\RefreshTokenStatus;
use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Models\AuthRefreshToken;
use App\Domain\Auth\Models\AuthSession;
use App\Infrastructure\Audit\AuditService;
use App\Infrastructure\Audit\BlameContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AdminRevokeUserSessionsService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Admin-initiated global user session revocation.
     * Revokes all active sessions and refresh tokens, and increments auth_version.
     */
    public function revoke(User $adminUser, User $targetUser, ?string $reason = null): void
    {
        BlameContext::setActorId((string) $adminUser->id);
        BlameContext::setUserId((string) $targetUser->id);

        DB::transaction(function () use ($adminUser, $targetUser, $reason) {
            $sessionIds = AuthSession::where('user_id', $targetUser->id)
                ->where('status', SessionStatus::Active)
                ->pluck('id');

            if ($sessionIds->isNotEmpty()) {
                AuthSession::whereIn('id', $sessionIds)->update([
                    'status' => SessionStatus::Revoked,
                    'revoked_at' => now(),
                    'revoked_by' => (string) $adminUser->id,
                ]);

                AuthRefreshToken::whereIn('session_id', $sessionIds)
                    ->where('status', RefreshTokenStatus::Active)
                    ->update([
                        'status' => RefreshTokenStatus::Revoked,
                        'revoked_at' => now(),
                    ]);
            }

            // Increment auth_version to invalidate all existing access tokens immediately
            $targetUser->increment('auth_version');

            $this->auditService->record(
                eventType: AuditEventType::UserSessionsRevoked,
                userId: (string) $targetUser->id,
                metadata: [
                    'revoked_by_admin_id' => (string) $adminUser->id,
                    'target_user_id' => (string) $targetUser->id,
                    'revoked_session_count' => $sessionIds->count(),
                    'reason' => $reason ?? 'admin_global_logout',
                ]
            );
        });
    }
}
