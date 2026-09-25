<?php

declare(strict_types=1);

use App\Application\Tenancy\ApproveTenantService;
use App\Application\Tenancy\ProvisionTenantService;
use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Models\AuthSession;
use App\Domain\Identity\Enums\PlatformRole as PlatformRoleEnum;
use App\Domain\Identity\Models\PlatformRole;
use App\Domain\Outbox\Enums\OutboxEventType;
use App\Domain\Outbox\Models\OutboxMessage;
use App\Domain\Tenancy\Enums\TenantMembershipStatus;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Infrastructure\Jwt\JwtService;
use App\Infrastructure\Queue\Jobs\ProvisionTenantJob;
use App\Jobs\Tenancy\SendTenantApprovedEmailJob;
use App\Jobs\Tenancy\SendTenantProvisionedEmailJob;
use App\Mail\Tenancy\TenantApprovedMail;
use App\Mail\Tenancy\TenantProvisionedMail;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

test('super admin can approve a pending tenant and trigger provisioning', function () {
    Queue::fake();

    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);

    // Create Super Admin
    $admin = User::factory()->create();
    $superAdminRole = PlatformRole::firstOrCreate(
        ['name' => PlatformRoleEnum::SuperAdmin->value],
        ['description' => 'Super Administrator with full platform access']
    );
    $admin->platformRoles()->attach($superAdminRole);

    $adminSession = AuthSession::factory()->create([
        'user_id' => $admin->id,
        'status' => SessionStatus::Active,
    ]);
    $adminToken = $jwtService->issueAccessToken($admin, (string) $adminSession->id);

    // Create a creator user and pending tenant
    $creator = User::factory()->create();
    $tenant = Tenant::query()->create([
        'name' => 'Nova Logistics',
        'slug' => 'nova-logistics',
        'status' => TenantStatus::Pending,
        'row_version' => 1,
    ]);

    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $creator->id,
        'role' => TenantRole::Owner,
        'status' => TenantMembershipStatus::Active,
        'row_version' => 1,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/v1/admin/tenants/{$tenant->id}/approve");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $tenant->id)
        ->assertJsonPath('data.status', TenantStatus::Provisioning->value);

    // Check Tenant in database
    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Provisioning);

    // Check Outbox message for TenantApproved
    $outbox = OutboxMessage::query()
        ->where('event_type', OutboxEventType::TenantApproved)
        ->where('aggregate_id', $tenant->id)
        ->first();

    expect($outbox)->not->toBeNull()
        ->and($outbox->payload['approved_by_user_id'])->toBe($admin->id);

    // Check Audit log for TenantApproved
    $audit = AuditLog::query()
        ->where('event_type', AuditEventType::TenantApproved)
        ->where('metadata->tenant_id', $tenant->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($admin->id);

    // Check ProvisionTenantJob was dispatched
    Queue::assertPushed(ProvisionTenantJob::class, function (ProvisionTenantJob $job) use ($tenant, $admin) {
        return $job->tenantId === $tenant->id && $job->actorId === $admin->id;
    });
});

test('non-admin user cannot approve a tenant', function () {
    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);

    $regularUser = User::factory()->create();
    $session = AuthSession::factory()->create([
        'user_id' => $regularUser->id,
        'status' => SessionStatus::Active,
    ]);
    $token = $jwtService->issueAccessToken($regularUser, (string) $session->id);

    $tenant = Tenant::query()->create([
        'name' => 'Beta Corp',
        'slug' => 'beta-corp',
        'status' => TenantStatus::Pending,
        'row_version' => 1,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/tenants/{$tenant->id}/approve");

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'UNAUTHORIZED');
});

test('tenant provisioning is idempotent', function () {
    /** @var ProvisionTenantService $service */
    $service = app(ProvisionTenantService::class);

    $creator = User::factory()->create();
    $tenant = Tenant::query()->create([
        'name' => 'Gamma Technologies',
        'slug' => 'gamma-tech',
        'status' => TenantStatus::Provisioning,
        'row_version' => 1,
    ]);

    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $creator->id,
        'role' => TenantRole::Owner,
        'status' => TenantMembershipStatus::Active,
        'row_version' => 1,
    ]);

    // Initial provisioning
    $provisionedTenant = $service->provision($tenant->id);
    expect($provisionedTenant->status)->toBe(TenantStatus::Active)
        ->and($provisionedTenant->row_version)->toBe(2);

    $outboxCount = OutboxMessage::query()
        ->where('event_type', OutboxEventType::TenantProvisioned)
        ->where('aggregate_id', $tenant->id)
        ->count();
    expect($outboxCount)->toBe(1);

    // Second provisioning invocation (idempotency check)
    $reProvisionedTenant = $service->provision($tenant->id);
    expect($reProvisionedTenant->status)->toBe(TenantStatus::Active)
        ->and($reProvisionedTenant->row_version)->toBe(2);

    // Outbox messages count should still be 1 (no duplicate events created)
    $outboxCountAfter = OutboxMessage::query()
        ->where('event_type', OutboxEventType::TenantProvisioned)
        ->where('aggregate_id', $tenant->id)
        ->count();
    expect($outboxCountAfter)->toBe(1);
});

test('approving an already active tenant returns idempotent success', function () {
    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);

    $admin = User::factory()->create();
    $superAdminRole = PlatformRole::firstOrCreate(
        ['name' => PlatformRoleEnum::SuperAdmin->value],
        ['description' => 'Super Administrator with full platform access']
    );
    $admin->platformRoles()->attach($superAdminRole);

    $adminSession = AuthSession::factory()->create([
        'user_id' => $admin->id,
        'status' => SessionStatus::Active,
    ]);
    $adminToken = $jwtService->issueAccessToken($admin, (string) $adminSession->id);

    $tenant = Tenant::query()->create([
        'name' => 'Delta Systems',
        'slug' => 'delta-systems',
        'status' => TenantStatus::Active,
        'row_version' => 1,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/v1/admin/tenants/{$tenant->id}/approve");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', TenantStatus::Active->value);
});

test('outbox publish command dispatches SendTenantApprovedEmailJob and sends email to tenant owner', function () {
    Mail::fake();

    $owner = User::factory()->create([
        'first_name' => 'Alice',
        'last_name' => 'Smith',
        'email' => 'alice.smith@example.com',
        'email_normalized' => 'alice.smith@example.com',
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Acme Labs',
        'slug' => 'acme-labs',
        'status' => TenantStatus::Pending,
        'row_version' => 1,
    ]);

    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $owner->id,
        'role' => TenantRole::Owner,
        'status' => TenantMembershipStatus::Active,
        'row_version' => 1,
    ]);

    $admin = User::factory()->create();

    /** @var ApproveTenantService $approveService */
    $approveService = app(ApproveTenantService::class);
    $approveService->approve($admin, $tenant->id, sync: false);

    // Verify Outbox message was created with owner information
    $outbox = OutboxMessage::query()
        ->where('event_type', OutboxEventType::TenantApproved)
        ->where('aggregate_id', $tenant->id)
        ->first();

    expect($outbox)->not->toBeNull()
        ->and($outbox->payload['owner_email'])->toBe('alice.smith@example.com')
        ->and($outbox->payload['owner_name'])->toBe('Alice Smith')
        ->and($outbox->payload['name'])->toBe('Acme Labs');

    // Run outbox publish command
    Artisan::call('outbox:publish');

    Mail::assertSent(TenantApprovedMail::class, function (TenantApprovedMail $mail) {
        return $mail->hasTo('alice.smith@example.com')
            && $mail->userName === 'Alice Smith'
            && $mail->tenantName === 'Acme Labs'
            && $mail->tenantSlug === 'acme-labs';
    });
});

test('outbox publish command dispatches SendTenantProvisionedEmailJob and sends email to tenant owner', function () {
    Mail::fake();

    $owner = User::factory()->create([
        'first_name' => 'Bob',
        'last_name' => 'Jones',
        'email' => 'bob.jones@example.com',
        'email_normalized' => 'bob.jones@example.com',
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Beta Cloud',
        'slug' => 'beta-cloud',
        'status' => TenantStatus::Provisioning,
        'row_version' => 1,
    ]);

    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $owner->id,
        'role' => TenantRole::Owner,
        'status' => TenantMembershipStatus::Active,
        'row_version' => 1,
    ]);

    /** @var ProvisionTenantService $provisionService */
    $provisionService = app(ProvisionTenantService::class);
    $provisionService->provision($tenant->id);

    // Verify Outbox message was created with owner information
    $outbox = OutboxMessage::query()
        ->where('event_type', OutboxEventType::TenantProvisioned)
        ->where('aggregate_id', $tenant->id)
        ->first();

    expect($outbox)->not->toBeNull()
        ->and($outbox->payload['owner_email'])->toBe('bob.jones@example.com')
        ->and($outbox->payload['owner_name'])->toBe('Bob Jones')
        ->and($outbox->payload['name'])->toBe('Beta Cloud');

    // Run outbox publish command
    Artisan::call('outbox:publish');

    Mail::assertSent(TenantProvisionedMail::class, function (TenantProvisionedMail $mail) {
        return $mail->hasTo('bob.jones@example.com')
            && $mail->userName === 'Bob Jones'
            && $mail->tenantName === 'Beta Cloud'
            && $mail->tenantSlug === 'beta-cloud';
    });
});

test('tenant email jobs execute idempotently without double sending', function () {
    Mail::fake();

    // Test TenantApproved job idempotency
    $approvedJob = new SendTenantApprovedEmailJob(
        eventId: '01918a22-0000-7000-8000-000000000010',
        eventType: 'TenantApproved',
        aggregateId: '01918a22-0000-7000-8000-000000000011',
        payload: [
            'name' => 'Acme Labs',
            'slug' => 'acme-labs',
            'owner_email' => 'alice@example.com',
            'owner_name' => 'Alice',
        ],
    );

    $approvedJob->handle();
    Mail::assertSent(TenantApprovedMail::class, 1);

    // Duplicate execution
    $approvedJob->handle();
    Mail::assertSent(TenantApprovedMail::class, 1);

    // Test TenantProvisioned job idempotency
    $provisionedJob = new SendTenantProvisionedEmailJob(
        eventId: '01918a22-0000-7000-8000-000000000020',
        eventType: 'TenantProvisioned',
        aggregateId: '01918a22-0000-7000-8000-000000000021',
        payload: [
            'name' => 'Acme Labs',
            'slug' => 'acme-labs',
            'owner_email' => 'alice@example.com',
            'owner_name' => 'Alice',
        ],
    );

    $provisionedJob->handle();
    Mail::assertSent(TenantProvisionedMail::class, 1);

    // Duplicate execution
    $provisionedJob->handle();
    Mail::assertSent(TenantProvisionedMail::class, 1);
});
