<?php

declare(strict_types=1);

namespace App\Domain\Outbox\Enums;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
}
