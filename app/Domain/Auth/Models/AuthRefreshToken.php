<?php

declare(strict_types=1);

namespace App\Domain\Auth\Models;

use App\Domain\Auth\Enums\RefreshTokenStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $session_id
 * @property string $token_hash
 * @property RefreshTokenStatus $status
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $replaced_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property AuthSession $session
 */
#[Fillable([
    'session_id',
    'token_hash',
    'status',
    'expires_at',
    'consumed_at',
    'revoked_at',
    'replaced_by',
])]
class AuthRefreshToken extends Model
{
    use HasFactory, HasUuids;

    /**
     * @var string
     */
    protected $table = 'auth_refresh_tokens';

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @return BelongsTo<AuthSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AuthSession::class, 'session_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RefreshTokenStatus::class,
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
