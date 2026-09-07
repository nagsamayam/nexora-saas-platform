<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

class DuplicateEmailException extends AuthenticationException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(string $message = 'An account with this email address already exists.', ?array $details = null)
    {
        parent::__construct($message, $details, 'AUTH_DUPLICATE_EMAIL', 409);
    }
}
