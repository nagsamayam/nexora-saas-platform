<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

class RefreshTokenException extends AuthenticationException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(string $message = 'Invalid or expired refresh token.', ?array $details = null)
    {
        parent::__construct($message, $details, 'AUTH_INVALID_REFRESH_TOKEN', 401);
    }
}
