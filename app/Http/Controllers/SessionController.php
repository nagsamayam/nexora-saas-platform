<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Auth\GetUserSessionsService;
use App\Application\Auth\RevokeUserSessionService;
use App\Http\Resources\AuthSessionResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    /**
     * List all active sessions for the authenticated user.
     */
    public function index(Request $request, GetUserSessionsService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $sessions = $service->execute($user);

        return ApiResponse::success([
            'sessions' => AuthSessionResource::collection($sessions)->resolve($request),
        ]);
    }

    /**
     * Revoke a specific session for the authenticated user.
     */
    public function destroy(Request $request, string $sessionId, RevokeUserSessionService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $revoked = $service->execute($user, $sessionId);

        if (! $revoked) {
            return ApiResponse::error(
                message: 'Session not found or already revoked.',
                code: 'SESSION_NOT_FOUND',
                status: 404
            );
        }

        return ApiResponse::success([
            'message' => 'Session revoked successfully.',
        ]);
    }
}
