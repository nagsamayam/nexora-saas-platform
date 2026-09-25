<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use App\Domain\Auth\Exceptions\AuthenticationException;
use Symfony\Component\HttpFoundation\Response;

class ConflictException extends AuthenticationException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        string $message = 'Conflict occurred.',
        string $errorCode = 'RESOURCE_CONFLICT',
        ?array $details = null,
    ) {
        parent::__construct(
            message: $message,
            details: $details,
            errorCode: $errorCode,
            statusCode: Response::HTTP_CONFLICT,
        );
    }
}
