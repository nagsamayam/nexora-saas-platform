<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

class OptimisticLockException extends ConcurrencyException
{
    public function __construct(string $message = 'The record has been updated by another process. Please refresh and try again.')
    {
        parent::__construct($message);
    }
}
