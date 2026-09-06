<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SendWelcomeEmail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class UserController extends Controller
{
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
