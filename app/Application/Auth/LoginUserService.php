<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Auth\DTOs\AuthTokenResultDTO;
use App\Domain\Auth\DTOs\LoginUserDTO;
use App\Domain\Auth\Enums\RefreshTokenStatus;
use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Enums\UserStatus;
use App\Domain\Auth\Exceptions\InvalidCredentialsException;
use App\Domain\Auth\Exceptions\UserInactiveException;
use App\Domain\Auth\Models\AuthRefreshToken;
use App\Domain\Auth\Models\AuthSession;
use App\Domain\Outbox\Enums\OutboxEventType;
use App\Infrastructure\Audit\AuditService;
use App\Infrastructure\Jwt\JwtService;
use App\Infrastructure\Outbox\OutboxService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginUserService
{
    public function __construct(
        protected JwtService $jwtService,
        protected OutboxService $outboxService,
        protected AuditService $auditService,
    ) {}

    /**
     * Authenticate user and issue tokens.
     */
    public function login(LoginUserDTO $dto): AuthTokenResultDTO
    {
        $normalizedEmail = Str::lower(trim($dto->email));

        $user = User::where('email_normalized', $normalizedEmail)->first();
        if (! $user || ! Hash::check($dto->password, (string) $user->password_hash)) {
            $this->auditService->record(
                eventType: AuditEventType::LoginFailed,
                userId: $user ? (string) $user->id : null,
                metadata: [
                    'email' => $normalizedEmail,
                    'reason' => 'Invalid credentials',
                ],
                ipAddress: $dto->ipAddress,
                userAgent: $dto->userAgent,
            );

            throw new InvalidCredentialsException;
        }

        if ($user->status !== UserStatus::Active) {
            $this->auditService->record(
                eventType: AuditEventType::LoginFailed,
                userId: (string) $user->id,
                metadata: [
                    'email' => $normalizedEmail,
                    'reason' => 'User inactive/suspended',
                    'status' => $user->status->value,
                ],
                ipAddress: $dto->ipAddress,
                userAgent: $dto->userAgent,
            );

            throw new UserInactiveException('Your account is inactive, suspended, or disabled.');
        }

        return DB::transaction(function () use ($user, $dto): AuthTokenResultDTO {
            $now = CarbonImmutable::now('UTC');
            $sessionExpiresAt = $now->addDays(30);

            /** @var AuthSession $session */
            $session = AuthSession::create([
                'user_id' => $user->id,
                'status' => SessionStatus::Active,
                'device_name' => $dto->deviceName,
                'ip_address' => $dto->ipAddress,
                'user_agent' => $dto->userAgent,
                'last_used_at' => $now,
                'expires_at' => $sessionExpiresAt,
            ]);

            $rawRefreshToken = bin2hex(random_bytes(32));
            $refreshTokenHash = hash('sha256', $rawRefreshToken);

            AuthRefreshToken::create([
                'session_id' => $session->id,
                'token_hash' => $refreshTokenHash,
                'status' => RefreshTokenStatus::Active,
                'expires_at' => $sessionExpiresAt,
            ]);

            $user->update([
                'last_login_at' => $now,
            ]);

            // Persist transactional outbox event
            $this->outboxService->record(
                eventType: OutboxEventType::UserLoggedIn,
                aggregateType: 'User',
                aggregateId: (string) $user->id,
                payload: [
                    'user_id' => (string) $user->id,
                    'email' => (string) $user->email,
                    'device_name' => $dto->deviceName,
                    'ip_address' => $dto->ipAddress,
                    'login_time' => $now->toIso8601String(),
                ],
                headers: [
                    'session_id' => (string) $session->id,
                ],
                eventKey: sprintf('user-logged-in-%s', $session->id),
            );

            // Record audit log
            $this->auditService->record(
                eventType: AuditEventType::UserLoggedIn,
                userId: (string) $user->id,
                sessionId: (string) $session->id,
                metadata: [
                    'device_name' => $dto->deviceName,
                ],
                ipAddress: $dto->ipAddress,
                userAgent: $dto->userAgent,
            );

            $accessToken = $this->jwtService->issueAccessToken($user, (string) $session->id);

            /** @var int $ttl */
            $ttl = config('jwt.ttl', 900);

            return new AuthTokenResultDTO(
                accessToken: $accessToken,
                refreshToken: $rawRefreshToken,
                tokenType: 'Bearer',
                expiresIn: $ttl,
            );
        });
    }
}
