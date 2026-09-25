<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum PlatformRole: string
{
    case SuperAdmin = 'super_admin';
    case Support = 'support';
    case Auditor = 'auditor';
}
