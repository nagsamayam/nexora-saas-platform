<?php

declare(strict_types=1);

namespace App\Domain\Auth\Enums;

enum RefreshTokenStatus: string
{
    case Active = 'active';
    case Consumed = 'consumed';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
