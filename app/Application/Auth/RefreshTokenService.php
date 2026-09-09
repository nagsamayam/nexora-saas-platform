<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Auth\DTOs\AuthTokenResultDTO;
use App\Domain\Auth\Enums\RefreshTokenStatus;
use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Enums\UserStatus;
use App\Domain\Auth\Exceptions\RefreshTokenException;
use App\Domain\Auth\Exceptions\RefreshTokenReuseException;
use App\Domain\Auth\Exceptions\SessionRevokedException;
use App\Domain\Auth\Exceptions\UserInactiveException;
use App\Domain\Auth\Models\AuthRefreshToken;
use App\Domain\Auth\Models\AuthSession;
use App\Infrastructure\Audit\AuditService;
use App\Infrastructure\Jwt\JwtService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RefreshTokenService
{
    public function __construct(
        protected JwtService $jwtService,
        protected AuditService $auditService,
    ) {}

    /**
     * Rotate refresh token and issue new token pair.
     */
    public function refresh(string $rawRefreshToken): AuthTokenResultDTO
    {
        $tokenHash = hash('sha256', $rawRefreshToken);
        $reuseDetected = false;
        $reuseSessionId = null;
        $reuseUserId = null;

        try {
            return DB::transaction(function () use ($tokenHash, &$reuseDetected, &$reuseSessionId, &$reuseUserId): AuthTokenResultDTO {
                $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));

                /** @var AuthRefreshToken|null $tokenRecord */
                $tokenRecord = AuthRefreshToken::where('token_hash', $tokenHash)
                    ->lockForUpdate()
                    ->first();

                if (! $tokenRecord) {
                    throw new RefreshTokenException('Invalid refresh token.');
                }

                // Reuse detection
                if ($tokenRecord->status === RefreshTokenStatus::Consumed) {
                    $reuseDetected = true;
                    $reuseSessionId = $tokenRecord->session_id;
                    $reuseUserId = (string) $tokenRecord->session->user_id;

                    throw new RefreshTokenReuseException;
                }

                if ($tokenRecord->status !== RefreshTokenStatus::Active) {
                    throw new RefreshTokenException('Refresh token is not active.');
                }

                if ($tokenRecord->expires_at->isPast()) {
                    $tokenRecord->update([
                        'status' => RefreshTokenStatus::Expired,
                    ]);

                    throw new RefreshTokenException('Refresh token has expired.');
                }

                /** @var AuthSession|null $session */
                $session = AuthSession::where('id', $tokenRecord->session_id)
                    ->lockForUpdate()
                    ->first();

                if (! $session || $session->status !== SessionStatus::Active || $session->expires_at->isPast()) {
                    throw new SessionRevokedException;
                }

                $user = $session->user;
                if ($user->status !== UserStatus::Active) {
                    throw new UserInactiveException;
                }

                $newRawRefreshToken = bin2hex(random_bytes(32));
                $newTokenHash = hash('sha256', $newRawRefreshToken);

                /** @var AuthRefreshToken $newTokenRecord */
                $newTokenRecord = AuthRefreshToken::create([
                    'session_id' => $session->id,
                    'token_hash' => $newTokenHash,
                    'status' => RefreshTokenStatus::Active,
                    'expires_at' => $session->expires_at,
                ]);

                $tokenRecord->update([
                    'status' => RefreshTokenStatus::Consumed,
                    'consumed_at' => $now,
                    'replaced_by' => $newTokenRecord->id,
                ]);

                $session->update([
                    'last_used_at' => $now,
                ]);

                // Record audit event
                $this->auditService->record(
                    eventType: AuditEventType::RefreshTokenRotated,
                    userId: (string) $user->id,
                    sessionId: (string) $session->id,
                    metadata: [
                        'old_token_id' => $tokenRecord->id,
                        'new_token_id' => $newTokenRecord->id,
                    ],
                );

                $accessToken = $this->jwtService->issueAccessToken($user, (string) $session->id);

                /** @var int $ttl */
                $ttl = config('jwt.ttl', 900);

                return new AuthTokenResultDTO(
                    accessToken: $accessToken,
                    refreshToken: $newRawRefreshToken,
                    tokenType: 'Bearer',
                    expiresIn: $ttl,
                );
            });
        } catch (RefreshTokenReuseException $e) {
            if ($reuseDetected && $reuseSessionId !== null) {
                $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
                AuthSession::where('id', $reuseSessionId)->update([
                    'status' => SessionStatus::Revoked,
                    'revoked_at' => $now,
                ]);

                AuthRefreshToken::where('session_id', $reuseSessionId)
                    ->where('status', RefreshTokenStatus::Active)
                    ->update([
                        'status' => RefreshTokenStatus::Revoked,
                        'revoked_at' => $now,
                    ]);

                // Record audit event for security incident
                $this->auditService->record(
                    eventType: AuditEventType::RefreshTokenReuseDetected,
                    userId: $reuseUserId,
                    sessionId: $reuseSessionId,
                    metadata: [
                        'token_hash' => substr($tokenHash, 0, 8).'...',
                    ],
                );
            }

            throw $e;
        }
    }
}
