<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use App\Domain\Auth\Exceptions\AuthenticationException;
use Symfony\Component\HttpFoundation\Response;

class ConcurrencyException extends AuthenticationException
{
    public function __construct(
        string $message = 'The record has been updated by another process. Please refresh and try again.',
        ?array $details = null,
    ) {
        parent::__construct(
            message: $message,
            statusCode: Response::HTTP_CONFLICT,
            errorCode: 'CONCURRENCY_CONFLICT',
            details: $details,
        );
    }
}
