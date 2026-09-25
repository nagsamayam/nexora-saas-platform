<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\AuditEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property AuditEventType $event_type
 * @property string|null $actor_id
 * @property string|null $user_id
 * @property string|null $session_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 */
#[Fillable([
    'event_type',
    'actor_id',
    'user_id',
    'session_id',
    'ip_address',
    'user_agent',
    'metadata',
])]
class AuditLog extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    /**
     * @var string
     */
    protected $table = 'audit_logs';

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => AuditEventType::class,
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
