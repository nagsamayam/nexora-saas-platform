<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

class UserInactiveException extends AuthenticationException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(string $message = 'User account is not active.', ?array $details = null)
    {
        parent::__construct($message, $details, 'AUTH_USER_INACTIVE', 403);
    }
}
