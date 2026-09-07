<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

enum AuditEventType: string
{
    case UserRegistered = 'UserRegistered';
    case UserLoggedIn = 'UserLoggedIn';
    case UserLoggedOut = 'UserLoggedOut';
    case UserSessionsRevoked = 'UserSessionsRevoked';
    case RefreshTokenRotated = 'RefreshTokenRotated';
    case RefreshTokenReuseDetected = 'RefreshTokenReuseDetected';
    case LoginFailed = 'LoginFailed';
}
