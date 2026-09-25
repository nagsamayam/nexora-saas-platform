<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Identity\Models;

use App\Domain\Identity\Enums\PlatformRole as PlatformRoleEnum;
use App\Domain\Identity\Models\PlatformRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformRole>
 */
class PlatformRoleFactory extends Factory
{
    protected $model = PlatformRole::class;

    /**
     * Define the model's default state.
     *
     * @return array<model-property<PlatformRole>, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement([
                PlatformRoleEnum::SuperAdmin->value,
                PlatformRoleEnum::Support->value,
                PlatformRoleEnum::Auditor->value,
            ]),
            'description' => fake()->sentence(),
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => PlatformRoleEnum::SuperAdmin->value,
            'description' => 'Full administrative access across the platform, tenant management, and global session control.',
        ]);
    }

    public function support(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => PlatformRoleEnum::Support->value,
            'description' => 'Support staff access for tenant assistance, inspection, and diagnostics.',
        ]);
    }

    public function auditor(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => PlatformRoleEnum::Auditor->value,
            'description' => 'Compliance and security auditor access with read-only visibility into system audit logs.',
        ]);
    }
}
