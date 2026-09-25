<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Models\AuthSession;
use App\Domain\Outbox\Enums\OutboxEventType;
use App\Domain\Outbox\Models\OutboxMessage;
use App\Domain\Tenancy\Enums\TenantMembershipStatus;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Infrastructure\Jwt\JwtService;
use App\Models\User;

test('authenticated user can onboard a new tenant successfully', function () {
    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);

    $user = User::factory()->create();
    $session = AuthSession::factory()->create([
        'user_id' => $user->id,
        'status' => SessionStatus::Active,
    ]);

    $token = $jwtService->issueAccessToken($user, (string) $session->id);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/tenants/onboard', [
            'name' => 'Acme Corporation',
            'slug' => 'acme-corp',
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Acme Corporation')
        ->assertJsonPath('data.slug', 'acme-corp')
        ->assertJsonPath('data.status', TenantStatus::Pending->value)
        ->assertJsonPath('data.row_version', 1);

    $tenantId = (string) $response->json('data.id');

    // Assert Tenant in database
    $tenant = Tenant::query()->find($tenantId);
    expect($tenant)->not->toBeNull()
        ->and($tenant->name)->toBe('Acme Corporation')
        ->and($tenant->slug)->toBe('acme-corp')
        ->and($tenant->status)->toBe(TenantStatus::Pending);

    // Assert Owner TenantMembership in database
    $membership = TenantMembership::query()
        ->where('tenant_id', $tenantId)
        ->where('user_id', $user->id)
        ->first();

    expect($membership)->not->toBeNull()
        ->and($membership->role)->toBe(TenantRole::Owner)
        ->and($membership->status)->toBe(TenantMembershipStatus::Active);

    // Assert Outbox Message
    $outbox = OutboxMessage::query()
        ->where('event_type', OutboxEventType::TenantCreated)
        ->where('aggregate_id', $tenantId)
        ->first();

    expect($outbox)->not->toBeNull()
        ->and($outbox->event_key)->toBe(sprintf('tenant-created-%s', $tenantId))
        ->and($outbox->payload['name'])->toBe('Acme Corporation')
        ->and($outbox->payload['owner_user_id'])->toBe($user->id);

    // Assert Audit Log
    $audit = AuditLog::query()
        ->where('event_type', AuditEventType::TenantCreated)
        ->where('user_id', $user->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['tenant_id'])->toBe($tenantId)
        ->and($audit->metadata['role'])->toBe(TenantRole::Owner->value);
});

test('auto-generates slug from name if slug is not provided', function () {
    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);

    $user = User::factory()->create();
    $session = AuthSession::factory()->create([
        'user_id' => $user->id,
        'status' => SessionStatus::Active,
    ]);

    $token = $jwtService->issueAccessToken($user, (string) $session->id);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/tenants/onboard', [
            'name' => 'Starlight Enterprise Solutions',
        ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.slug', 'starlight-enterprise-solutions');
});

test('returns 409 conflict when tenant slug already exists', function () {
    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);

    Tenant::query()->create([
        'name' => 'Existing Company',
        'slug' => 'existing-company',
        'status' => TenantStatus::Active,
        'row_version' => 1,
    ]);

    $user = User::factory()->create();
    $session = AuthSession::factory()->create([
        'user_id' => $user->id,
        'status' => SessionStatus::Active,
    ]);

    $token = $jwtService->issueAccessToken($user, (string) $session->id);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/tenants/onboard', [
            'name' => 'Another Existing Company',
            'slug' => 'existing-company',
        ]);

    $response->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'TENANT_SLUG_CONFLICT');
});

test('returns 422 validation error for invalid slug format or missing name', function () {
    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);

    $user = User::factory()->create();
    $session = AuthSession::factory()->create([
        'user_id' => $user->id,
        'status' => SessionStatus::Active,
    ]);

    $token = $jwtService->issueAccessToken($user, (string) $session->id);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/tenants/onboard', [
            'name' => '',
            'slug' => 'Invalid Slug with Spaces!',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['error' => ['message', 'code', 'details']]);
});

test('unauthenticated request to onboard tenant is rejected', function () {
    $response = $this->postJson('/api/v1/tenants/onboard', [
        'name' => 'Acme Corporation',
    ]);

    $response->assertStatus(401);
});
