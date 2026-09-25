<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

class RefreshTokenReuseException extends AuthenticationException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(string $message = 'Refresh token reuse detected. All sessions have been revoked for security.', ?array $details = null)
    {
        parent::__construct($message, $details, 'AUTH_REFRESH_TOKEN_REUSE_DETECTED', 401);
    }
}
