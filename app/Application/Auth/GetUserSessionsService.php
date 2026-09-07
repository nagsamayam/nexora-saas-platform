<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Models\AuthSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class GetUserSessionsService
{
    /**
     * @return Collection<int, AuthSession>
     */
    public function execute(User $user): Collection
    {
        return $user->authSessions()
            ->where('status', SessionStatus::Active)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();
    }
}
