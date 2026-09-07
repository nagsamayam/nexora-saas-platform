<?php

declare(strict_types=1);

namespace App\Domain\Auth\Models;

use App\Domain\Auth\Enums\RefreshTokenStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $session_id
 * @property string $token_hash
 * @property RefreshTokenStatus $status
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $revoked_at
 * @property string|null $replaced_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
