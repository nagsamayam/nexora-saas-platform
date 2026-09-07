<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Auth\DTOs\AuthTokenResultDTO;
use App\Domain\Auth\DTOs\LoginUserDTO;
use App\Domain\Auth\Enums\RefreshTokenStatus;
use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Enums\UserStatus;
use App\Domain\Auth\Exceptions\InvalidCredentialsException;
use App\Domain\Auth\Exceptions\UserInactiveException;
use App\Domain\Auth\Models\AuthRefreshToken;
use App\Domain\Auth\Models\AuthSession;
use App\Infrastructure\Jwt\JwtService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginUserService
{
    public function __construct(
        protected JwtService $jwtService,
    ) {}

    /**
     * Authenticate user and issue tokens.
     */
    public function login(LoginUserDTO $dto): AuthTokenResultDTO
    {
        $normalizedEmail = Str::lower(trim($dto->email));

        $user = User::where('email_normalized', $normalizedEmail)->first();
        if (! $user || ! Hash::check($dto->password, (string) $user->password_hash)) {
            throw new InvalidCredentialsException;
        }

        if ($user->status !== UserStatus::Active) {
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
