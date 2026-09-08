<?php

declare(strict_types=1);

namespace App\Domain\Outbox\Enums;

enum OutboxEventType: string
{
    case UserRegistered = 'UserRegistered';
    case UserLoggedIn = 'UserLoggedIn';
    case TenantCreated = 'TenantCreated';
    case TenantApproved = 'TenantApproved';
    case TenantProvisioned = 'TenantProvisioned';
}
