<?php

declare(strict_types=1);

namespace App\Domain\Auth\Enums;

enum UserStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';
    case Disabled = 'disabled';
}
