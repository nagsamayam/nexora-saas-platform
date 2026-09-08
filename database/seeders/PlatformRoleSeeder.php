<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Enums\PlatformRole as PlatformRoleEnum;
use App\Domain\Identity\Models\PlatformRole;
use Illuminate\Database\Seeder;

class PlatformRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => PlatformRoleEnum::SuperAdmin->value,
                'description' => 'Full administrative access across the platform, tenant management, and global session control.',
            ],
            [
                'name' => PlatformRoleEnum::Support->value,
                'description' => 'Support staff access for tenant assistance, inspection, and diagnostics.',
            ],
            [
                'name' => PlatformRoleEnum::Auditor->value,
                'description' => 'Compliance and security auditor access with read-only visibility into system audit logs.',
            ],
        ];

        foreach ($roles as $roleData) {
            PlatformRole::query()->updateOrCreate(
                ['name' => $roleData['name']],
                ['description' => $roleData['description']],
            );
        }
    }
}
