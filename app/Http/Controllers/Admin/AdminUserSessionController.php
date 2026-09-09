<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\AdminRevokeUserSessionsService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserSessionController extends Controller
{
    /**
     * Invalidate all sessions for a specific target user.
     */
    public function revokeAll(Request $request, string $userId, AdminRevokeUserSessionsService $service): JsonResponse
    {
        /** @var User $adminUser */
        $adminUser = $request->user();

        if (! $adminUser->isSuperAdmin()) {
            return ApiResponse::error(
                message: 'Unauthorized platform action.',
                code: 'FORBIDDEN',
                status: 403
            );
        }

        /** @var User|null $targetUser */
        $targetUser = User::find($userId);
        if ($targetUser === null) {
            return ApiResponse::error(
                message: 'Target user not found.',
                code: 'USER_NOT_FOUND',
                status: 404
            );
        }

        /** @var string|null $reason */
        $reason = $request->input('reason');
        $service->execute($adminUser, $targetUser, $reason);

        return ApiResponse::success([
            'message' => 'All sessions for the user have been revoked successfully.',
        ]);
    }
}
