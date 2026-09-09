<?php

declare(strict_types=1);

namespace App\Domain\Outbox\Models;

use App\Domain\Outbox\Enums\OutboxStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $event_type
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string|null $correlation_id
 * @property string|null $event_key
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $headers
 * @property OutboxStatus $status
 * @property int $attempts
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $published_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'event_type',
    'aggregate_type',
    'aggregate_id',
    'correlation_id',
    'event_key',
    'payload',
    'headers',
    'status',
    'attempts',
    'available_at',
    'published_at',
    'last_error',
])]
class OutboxMessage extends Model
{
    use HasFactory, HasUuids;

    /**
     * @var string
     */
    protected $table = 'outbox_messages';

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
            'payload' => 'array',
            'headers' => 'array',
            'status' => OutboxStatus::class,
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
