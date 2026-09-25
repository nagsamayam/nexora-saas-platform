<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PlatformRole extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'platform_roles';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * @return BelongsToMany<User, $this, UserPlatformRole>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_platform_roles', 'platform_role_id', 'user_id')
            ->using(UserPlatformRole::class)
            ->withTimestamps();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
