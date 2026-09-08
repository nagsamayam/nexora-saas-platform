<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\PlatformRole as PlatformRoleEnum;
use App\Domain\Identity\Models\PlatformRole;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlatformRoleSeeder;
use Database\Seeders\RoleSeeder;

test('platform role seeder seeds all defined platform roles idempotently', function () {
    expect(PlatformRole::query()->count())->toBe(0);

    $this->seed(PlatformRoleSeeder::class);

    expect(PlatformRole::query()->count())->toBe(count(PlatformRoleEnum::cases()));

    foreach (PlatformRoleEnum::cases() as $roleEnum) {
        $role = PlatformRole::query()->where('name', $roleEnum->value)->first();
        expect($role)->not->toBeNull()
            ->and($role->description)->toBeString()->not->toBeEmpty();
    }

    // Run again to verify idempotency
    $this->seed(PlatformRoleSeeder::class);
    expect(PlatformRole::query()->count())->toBe(count(PlatformRoleEnum::cases()));
});

test('role seeder and database seeder call platform role seeding', function () {
    $this->seed(RoleSeeder::class);
    expect(PlatformRole::query()->count())->toBe(count(PlatformRoleEnum::cases()));

    $this->seed(DatabaseSeeder::class);
    expect(PlatformRole::query()->count())->toBe(count(PlatformRoleEnum::cases()));
});

test('platform role factory creates role models correctly', function () {
    $role = PlatformRole::factory()->create([
        'name' => 'custom_role',
        'description' => 'Custom role description',
    ]);

    expect($role)->toBeInstanceOf(PlatformRole::class)
        ->and($role->id)->toBeString()
        ->and($role->name)->toBe('custom_role');
});
