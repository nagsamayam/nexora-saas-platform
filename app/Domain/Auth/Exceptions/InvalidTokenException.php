<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

class InvalidTokenException extends AuthenticationException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(string $message = 'The provided token is invalid.', ?array $details = null)
    {
        parent::__construct($message, $details, 'AUTH_INVALID_TOKEN', 401);
    }
}
