<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Enums\UserStatus;
use App\Domain\Auth\Exceptions\AuthenticationException;
use App\Domain\Auth\Exceptions\InvalidTokenException;
use App\Domain\Auth\Exceptions\SessionRevokedException;
use App\Domain\Auth\Exceptions\UserInactiveException;
use App\Domain\Auth\Models\AuthSession;
use App\Infrastructure\Jwt\JwtService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateJwt
{
    public function __construct(
        protected JwtService $jwtService,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            throw new AuthenticationException('Missing or malformed Authorization Bearer token.');
        }

        $rawToken = substr($header, 7);
        if (empty($rawToken)) {
            throw new AuthenticationException('Bearer token is empty.');
        }

        $payload = $this->jwtService->validateToken($rawToken);

        /** @var string $userId */
        $userId = $payload['sub'] ?? '';
        /** @var string $sessionId */
        $sessionId = $payload['sid'] ?? '';
        /** @var int $tokenAuthVersion */
        $tokenAuthVersion = (int) ($payload['auth_version'] ?? 0);

        /** @var User|null $user */
        $user = User::find($userId);
        if (! $user) {
            throw new InvalidTokenException('User belonging to this token no longer exists.');
        }

        if ((int) $user->auth_version !== $tokenAuthVersion) {
            throw new InvalidTokenException('Token has been invalidated due to a security version change.');
        }

        if ($user->status !== UserStatus::Active) {
            throw new UserInactiveException('User account is no longer active.');
        }

        /** @var AuthSession|null $session */
        $session = AuthSession::find($sessionId);
        if (! $session || $session->status !== SessionStatus::Active || $session->expires_at->isPast()) {
            throw new SessionRevokedException('The session associated with this token has expired or been revoked.');
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_token', $rawToken);
        $request->attributes->set('auth_session_id', $sessionId);
        $request->attributes->set('jwt_payload', $payload);

        return $next($request);
    }
}
