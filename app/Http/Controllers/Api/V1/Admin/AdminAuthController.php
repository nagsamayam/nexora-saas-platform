<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Application\Auth\AdminRevokeUserSessionsService;
use App\Domain\Identity\Enums\PlatformRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthController extends Controller
{
    /**
     * Invalidate all active sessions for a target user (Admin-only).
     */
    public function revokeUserSessions(
        string $userId,
        Request $request,
        AdminRevokeUserSessionsService $service,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $request->user();

        // Ensure user has super-admin platform role
        if (! $currentUser->hasPlatformRole(PlatformRole::SuperAdmin)) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'You do not have permission to perform this action.',
                    'details' => null,
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        /** @var User|null $targetUser */
        $targetUser = User::find($userId);
        if ($targetUser === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Target user not found.',
                    'details' => null,
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        $reason = $request->input('reason');
        $service->revoke($currentUser, $targetUser, is_string($reason) ? $reason : null);

        return response()->noContent();
    }
}
