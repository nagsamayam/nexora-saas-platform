<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Auth\Enums\RefreshTokenStatus;
use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Models\AuthRefreshToken;
use App\Domain\Auth\Models\AuthSession;
use App\Infrastructure\Audit\AuditService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AdminRevokeUserSessionsService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Invalidate all active sessions, tokens and increment auth_version for a target user.
     */
    public function execute(User $adminUser, User $targetUser, ?string $reason = null): void
    {
        DB::transaction(function () use ($adminUser, $targetUser, $reason) {
            /** @var User $lockedUser */
            $lockedUser = User::where('id', $targetUser->id)->lockForUpdate()->firstOrFail();

            // Increment auth_version for immediate access token invalidation
            $lockedUser->increment('auth_version');

            // Revoke all active sessions
            AuthSession::where('user_id', $lockedUser->id)
                ->where('status', SessionStatus::Active)
                ->update([
                    'status' => SessionStatus::Revoked,
                    'revoked_at' => now(),
                ]);

            // Revoke all active refresh tokens
            AuthRefreshToken::where('user_id', $lockedUser->id)
                ->where('status', RefreshTokenStatus::Active)
                ->update([
                    'status' => RefreshTokenStatus::Revoked,
                    'revoked_at' => now(),
                ]);

            // Record UserSessionsRevoked audit log attributed to Admin
            $this->auditService->record(
                eventType: AuditEventType::UserSessionsRevoked,
                userId: (string) $lockedUser->id,
                actorId: (string) $adminUser->id,
                metadata: [
                    'revoked_by' => 'admin_global_logout',
                    'admin_id' => (string) $adminUser->id,
                    'admin_email' => $adminUser->email,
                    'reason' => $reason,
                ]
            );
        });
    }
}
