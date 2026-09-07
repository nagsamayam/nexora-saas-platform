<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

class AuthenticationException extends RuntimeException
{
    protected string $errorCode = 'AUTH_UNAUTHORIZED';

    protected int $statusCode = 401;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $details = null;

    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(string $message = 'Unauthenticated.', ?array $details = null, ?string $errorCode = null, int $statusCode = 401)
    {
        parent::__construct($message);
        if ($errorCode !== null) {
            $this->errorCode = $errorCode;
        }
        $this->statusCode = $statusCode;
        $this->details = $details;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDetails(): ?array
    {
        return $this->details;
    }
}
