<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

class InvalidCredentialsException extends AuthenticationException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(string $message = 'The provided credentials are invalid.', ?array $details = null)
    {
        parent::__construct($message, $details, 'AUTH_INVALID_CREDENTIALS', 401);
    }
}
