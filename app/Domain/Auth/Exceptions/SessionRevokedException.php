<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

class SessionRevokedException extends AuthenticationException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(string $message = 'The session has been revoked or expired.', ?array $details = null)
    {
        parent::__construct($message, $details, 'AUTH_SESSION_REVOKED', 401);
    }
}
