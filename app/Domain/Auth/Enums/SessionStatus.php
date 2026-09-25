<?php

declare(strict_types=1);

namespace App\Domain\Auth\Enums;

enum SessionStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
