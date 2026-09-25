<?php

declare(strict_types=1);

namespace App\Application\User;

use App\Domain\Shared\Exceptions\OptimisticLockException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateUserProfileService
{
    /**
     * Update user profile attributes using OCC.
     *
     * @param  array{first_name?: string|null, last_name?: string|null, name?: string|null}  $data
     *
     * @throws OptimisticLockException
     */
    public function execute(User $user, array $data, ?int $expectedRowVersion = null): User
    {
        return DB::transaction(function () use ($user, $data, $expectedRowVersion) {
            $expectedVersion = $expectedRowVersion ?? (int) $user->row_version;

            $user->updateWithOcc($data, $expectedVersion);

            return $user->fresh() ?? $user;
        });
    }
}
