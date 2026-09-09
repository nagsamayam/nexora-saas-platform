<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Auth\Enums\RefreshTokenStatus;
use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Models\AuthRefreshToken;
use App\Domain\Auth\Models\AuthSession;
use App\Infrastructure\Audit\AuditService;
use App\Infrastructure\Jwt\JwtService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class LogoutAllUserService
{
    public function __construct(
        protected JwtService $jwtService,
        protected AuditService $auditService,
    ) {}

    /**
     * Invalidate all sessions and refresh tokens for user, and increment user's auth_version.
     */
    public function logoutAll(User $user, ?string $rawToken = null): void
    {
        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));

        DB::transaction(function () use ($user, $now): void {
            $sessionIds = AuthSession::where('user_id', $user->id)
                ->where('status', SessionStatus::Active)
                ->pluck('id');

            if ($sessionIds->isNotEmpty()) {
                AuthSession::whereIn('id', $sessionIds)->update([
                    'status' => SessionStatus::Revoked,
                    'revoked_at' => $now,
                ]);

                AuthRefreshToken::whereIn('session_id', $sessionIds)
                    ->where('status', RefreshTokenStatus::Active)
                    ->update([
                        'status' => RefreshTokenStatus::Revoked,
                        'revoked_at' => $now,
                    ]);
            }

            // Increment auth_version to immediately invalidate all existing JWT access tokens
            $user->increment('auth_version');

            $this->auditService->record(
                eventType: AuditEventType::UserSessionsRevoked,
                userId: (string) $user->id,
                metadata: [
                    'revoked_session_count' => $sessionIds->count(),
                    'new_auth_version' => $user->auth_version,
                ],
            );
        });

        if ($rawToken !== null) {
            try {
                $payload = $this->jwtService->validateToken($rawToken);
                $this->jwtService->blacklistToken($payload);
            } catch (\Throwable) {
                // Ignore if already invalid
            }
        }
    }
}
