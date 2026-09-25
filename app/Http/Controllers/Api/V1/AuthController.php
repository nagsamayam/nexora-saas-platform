<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Auth\GetUserSessionsService;
use App\Application\Auth\LoginUserService;
use App\Application\Auth\LogoutAllUserService;
use App\Application\Auth\LogoutUserService;
use App\Application\Auth\RefreshTokenService;
use App\Application\Auth\RegisterUserService;
use App\Application\Auth\RevokeUserSessionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RefreshTokenRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\Api\V1\SessionResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(RegisterRequest $request, RegisterUserService $service): JsonResponse
    {
        $user = $service->register($request->toDTO());

        return (new UserResource($user))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Authenticate user and issue access and refresh tokens.
     */
    public function login(LoginRequest $request, LoginUserService $service): JsonResponse
    {
        $tokens = $service->login($request->toDTO());

        return response()->json([
            'data' => $tokens->toArray(),
        ], Response::HTTP_OK);
    }

    /**
     * Refresh access token via refresh token rotation.
     */
    public function refresh(RefreshTokenRequest $request, RefreshTokenService $service): JsonResponse
    {
        /** @var string $refreshToken */
        $refreshToken = $request->validated('refresh_token');
        $tokens = $service->refresh($refreshToken);

        return response()->json([
            'data' => $tokens->toArray(),
        ], Response::HTTP_OK);
    }

    /**
     * Revoke current session and access token.
     */
    public function logout(Request $request, LogoutUserService $service): Response
    {
        /** @var string $rawToken */
        $rawToken = (string) $request->attributes->get('auth_token');
        /** @var string|null $sessionId */
        $sessionId = $request->attributes->get('auth_session_id');

        $service->logout($rawToken, $sessionId);

        return response()->noContent();
    }

    /**
     * Revoke all sessions and increment auth_version for user.
     */
    public function logoutAll(Request $request, LogoutAllUserService $service): Response
    {
        /** @var User $user */
        $user = $request->user();
        /** @var string|null $rawToken */
        $rawToken = $request->attributes->get('auth_token');

        $service->logoutAll($user, $rawToken);

        return response()->noContent();
    }

    /**
     * Get authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new UserResource($user))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * List active sessions for authenticated user.
     */
    public function sessions(Request $request, GetUserSessionsService $service): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $sessions = $service->execute($user);

        return SessionResource::collection($sessions);
    }

    /**
     * Revoke a specific session for authenticated user.
     */
    public function revokeSession(string $sessionId, Request $request, RevokeUserSessionService $service, LogoutUserService $logoutService): Response
    {
        /** @var User $user */
        $user = $request->user();
        $currentSessionId = $request->attributes->get('auth_session_id');

        $revoked = $service->execute($user, $sessionId);

        if (! $revoked) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Session not found.',
                    'details' => null,
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        // If revoking current active session, blacklist the current token as well
        if ($sessionId === $currentSessionId) {
            /** @var string $rawToken */
            $rawToken = (string) $request->attributes->get('auth_token');
            $logoutService->logout($rawToken, $sessionId);
        }

        return response()->noContent();
    }
}
