<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\User\UpdateUserProfileService;
use App\Http\Requests\UpdateUserProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Http\Responses\ApiResponse;
use App\Jobs\SendWelcomeEmail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * Get authenticated user profile.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success([
            'user' => (new UserResource($user))->resolve($request),
        ]);
    }

    /**
     * Update authenticated user profile with OCC support.
     */
    public function update(UpdateUserProfileRequest $request, UpdateUserProfileService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();
        /** @var int|null $expectedRowVersion */
        $expectedRowVersion = isset($validated['expected_row_version']) ? (int) $validated['expected_row_version'] : null;
        unset($validated['expected_row_version']);

        /** @var array{first_name?: string|null, last_name?: string|null, name?: string|null} $updateData */
        $updateData = array_filter($validated, fn ($val) => $val !== null);

        $updatedUser = $service->execute($user, $updateData, $expectedRowVersion);

        return ApiResponse::success([
            'user' => (new UserResource($updatedUser))->resolve($request),
            'message' => 'User profile updated successfully.',
        ]);
    }

    public function register(Request $request)
    {
        // 1. WRITE OPERATION -> Routes automatically to pg_master
        $user = User::create([
            'name' => 'Senior Engineer '.Str::random(5),
            'email' => Str::random(10).'@example.com',
            'password' => bcrypt('Secret123!'),
        ]);

        // 2. READ OPERATION & CACHING -> Routes to pg_replica, stores data in authenticated Redis
        // We use Cache::remember to check Redis first. If it misses, it fetches from the Postgres replica.
        $cachedStats = Cache::remember('user_metrics:count', 64, function () {
            // Because of our config/database.php settings, this SELECT query hits pg_replica
            return [
                'total_users' => User::count(),
                'source_node' => 'pg_replica_cluster',
            ];
        });

        // 3. ASYNCHRONOUS QUEUEING -> Dispatches job payload cleanly to RabbitMQ 4 broker
        SendWelcomeEmail::dispatch($user);

        // Return production-grade JSON response
        return response()->json([
            'status' => 'success',
            'message' => 'User created and workflow triggered seamlessly.',
            'data' => [
                'created_user_id' => $user->id,
                'created_user_name' => $user->name,
                'redis_cached_stats' => $cachedStats,
            ],
        ], 200);
    }
}
