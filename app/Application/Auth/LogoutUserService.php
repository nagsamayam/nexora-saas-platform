<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Auth\Enums\RefreshTokenStatus;
use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Models\AuthRefreshToken;
use App\Domain\Auth\Models\AuthSession;
use App\Infrastructure\Jwt\JwtService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class LogoutUserService
{
    public function __construct(
        protected JwtService $jwtService,
    ) {}

    /**
     * Logout from the current session.
     */
    public function logout(string $rawToken, ?string $sessionId = null): void
    {
        $payload = null;
        try {
            $payload = $this->jwtService->validateToken($rawToken);
        } catch (\Throwable) {
            // Even if token validation fails, proceed if we have a session ID or ignore
        }

        $sid = $sessionId ?? ($payload['sid'] ?? null);

        if ($sid) {
            $now = CarbonImmutable::now('UTC');

            DB::transaction(function () use ($sid, $now): void {
                AuthSession::where('id', $sid)->update([
                    'status' => SessionStatus::Revoked,
                    'revoked_at' => $now,
                ]);

                AuthRefreshToken::where('session_id', $sid)
                    ->where('status', RefreshTokenStatus::Active)
                    ->update([
                        'status' => RefreshTokenStatus::Revoked,
                        'revoked_at' => $now,
                    ]);
            });
        }

        // Invalidate JWT token in blacklist
        if ($payload !== null) {
            $this->jwtService->blacklistToken($payload);
        }
    }
}
