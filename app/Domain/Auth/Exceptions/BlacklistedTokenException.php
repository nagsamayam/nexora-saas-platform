<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

class BlacklistedTokenException extends AuthenticationException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(string $message = 'The token has been revoked.', ?array $details = null)
    {
        parent::__construct($message, $details, 'AUTH_TOKEN_REVOKED', 401);
    }
}
