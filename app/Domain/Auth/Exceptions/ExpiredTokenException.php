<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

class ExpiredTokenException extends AuthenticationException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(string $message = 'The provided token has expired.', ?array $details = null)
    {
        parent::__construct($message, $details, 'AUTH_TOKEN_EXPIRED', 401);
    }
}
