<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

enum TenantStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Disabled = 'disabled';
}
