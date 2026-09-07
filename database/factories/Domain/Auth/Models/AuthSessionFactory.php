<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Auth\Models;

use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Models\AuthSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthSession>
 */
class AuthSessionFactory extends Factory
{
    protected $model = AuthSession::class;

    /**
     * Define the model's default state.
     *
     * @return array<model-property<AuthSession>, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => SessionStatus::Active,
            'device_name' => fake()->userAgent(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'last_used_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addDays(30),
        ];
    }
}
