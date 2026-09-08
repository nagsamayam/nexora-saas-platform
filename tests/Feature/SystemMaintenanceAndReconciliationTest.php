<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Enums\RefreshTokenStatus;
use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Enums\UserStatus;
use App\Domain\Auth\Models\AuthRefreshToken;
use App\Domain\Auth\Models\AuthSession;
use App\Domain\Outbox\Enums\OutboxEventType;
use App\Domain\Outbox\Enums\OutboxStatus;
use App\Domain\Outbox\Models\OutboxMessage;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Infrastructure\Audit\BlameContext;
use App\Infrastructure\Queue\Jobs\ProvisionTenantJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('auth:cleanup-tokens expires past-due tokens and sessions and prunes old records', function () {
    $now = CarbonImmutable::now('UTC');

    $user = User::factory()->create(['status' => UserStatus::Active]);

    // Active session that has expired
    $expiredSession = AuthSession::create([
        'user_id' => $user->id,
        'status' => SessionStatus::Active,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Mozilla',
        'last_activity_at' => $now->subDays(2),
        'expires_at' => $now->subMinutes(5),
    ]);

    // Active token that has expired
    $expiredToken = AuthRefreshToken::create([
        'session_id' => $expiredSession->id,
        'user_id' => $user->id,
        'token_hash' => hash('sha256', 'expired-token-val'),
        'status' => RefreshTokenStatus::Active,
        'expires_at' => $now->subMinutes(5),
    ]);

    // Active session and token that are NOT expired
    $validSession = AuthSession::create([
        'user_id' => $user->id,
        'status' => SessionStatus::Active,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Mozilla',
        'last_activity_at' => $now,
        'expires_at' => $now->addDays(7),
    ]);

    $validToken = AuthRefreshToken::create([
        'session_id' => $validSession->id,
        'user_id' => $user->id,
        'token_hash' => hash('sha256', 'valid-token-val'),
        'status' => RefreshTokenStatus::Active,
        'expires_at' => $now->addDays(7),
    ]);

    // Old terminal session and token eligible for pruning (> 30 days old)
    $oldSession = AuthSession::create([
        'user_id' => $user->id,
        'status' => SessionStatus::Revoked,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Mozilla',
        'last_activity_at' => $now->subDays(40),
        'expires_at' => $now->subDays(40),
        'revoked_at' => $now->subDays(40),
    ]);
    DB::table('auth_sessions')->where('id', $oldSession->id)->update([
        'created_at' => $now->subDays(40),
        'updated_at' => $now->subDays(40),
    ]);

    $oldToken = AuthRefreshToken::create([
        'session_id' => $oldSession->id,
        'token_hash' => hash('sha256', 'old-token-val'),
        'status' => RefreshTokenStatus::Revoked,
        'expires_at' => $now->subDays(40),
        'revoked_at' => $now->subDays(40),
    ]);
    DB::table('auth_refresh_tokens')->where('id', $oldToken->id)->update([
        'created_at' => $now->subDays(40),
        'updated_at' => $now->subDays(40),
    ]);

    // Run dry-run first
    $this->artisan('auth:cleanup-tokens', ['--prune-days' => 30, '--dry-run' => true])
        ->expectsOutputToContain('Starting token & session cleanup')
        ->assertSuccessful();

    // Verify dry-run made no modifications
    expect($expiredSession->fresh()->status)->toBe(SessionStatus::Active)
        ->and($expiredToken->fresh()->status)->toBe(RefreshTokenStatus::Active)
        ->and(AuthSession::find($oldSession->id))->not->toBeNull()
        ->and(AuthRefreshToken::find($oldToken->id))->not->toBeNull();

    // Run live command
    $this->artisan('auth:cleanup-tokens', ['--prune-days' => 30])
        ->expectsOutputToContain('Token & session cleanup completed successfully.')
        ->assertSuccessful();

    // Assert state transitions
    expect($expiredSession->fresh()->status)->toBe(SessionStatus::Expired)
        ->and($expiredToken->fresh()->status)->toBe(RefreshTokenStatus::Expired)
        ->and($validSession->fresh()->status)->toBe(SessionStatus::Active)
        ->and($validToken->fresh()->status)->toBe(RefreshTokenStatus::Active)
        ->and(AuthSession::find($oldSession->id))->toBeNull()
        ->and(AuthRefreshToken::find($oldToken->id))->toBeNull();

    // Assert audit log was recorded with system actor
    $auditLog = AuditLog::query()
        ->where('event_type', AuditEventType::SystemCleanupExecuted)
        ->where('actor_id', BlameContext::SYSTEM_ACTOR_ID)
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->metadata['action'])->toBe('auth:cleanup_tokens')
        ->and($auditLog->metadata['expired_tokens'])->toBe(1)
        ->and($auditLog->metadata['expired_sessions'])->toBe(1)
        ->and($auditLog->metadata['pruned_tokens'])->toBe(1)
        ->and($auditLog->metadata['pruned_sessions'])->toBe(1);
});

test('outbox:prune prunes old published and dead-letter messages', function () {
    $now = CarbonImmutable::now('UTC');

    // Old published message (> 7 days)
    $oldPublished = OutboxMessage::create([
        'event_type' => OutboxEventType::UserRegistered->value,
        'aggregate_type' => 'user',
        'aggregate_id' => (string) Str::uuid(),
        'payload' => ['email' => 'old@example.com'],
        'headers' => ['actor_id' => BlameContext::SYSTEM_ACTOR_ID],
        'status' => OutboxStatus::Published,
    ]);
    DB::table('outbox_messages')->where('id', $oldPublished->id)->update([
        'created_at' => $now->subDays(10),
        'updated_at' => $now->subDays(10),
    ]);

    // Recent published message (< 7 days)
    $recentPublished = OutboxMessage::create([
        'event_type' => OutboxEventType::UserRegistered->value,
        'aggregate_type' => 'user',
        'aggregate_id' => (string) Str::uuid(),
        'payload' => ['email' => 'recent@example.com'],
        'headers' => ['actor_id' => BlameContext::SYSTEM_ACTOR_ID],
        'status' => OutboxStatus::Published,
    ]);
    DB::table('outbox_messages')->where('id', $recentPublished->id)->update([
        'created_at' => $now->subDays(2),
        'updated_at' => $now->subDays(2),
    ]);

    // Old failed message (> 30 days)
    $oldFailed = OutboxMessage::create([
        'event_type' => OutboxEventType::UserRegistered->value,
        'aggregate_type' => 'user',
        'aggregate_id' => (string) Str::uuid(),
        'payload' => ['email' => 'failed@example.com'],
        'headers' => ['actor_id' => BlameContext::SYSTEM_ACTOR_ID],
        'status' => OutboxStatus::Failed,
    ]);
    DB::table('outbox_messages')->where('id', $oldFailed->id)->update([
        'created_at' => $now->subDays(35),
        'updated_at' => $now->subDays(35),
    ]);

    // Dry-run check
    $this->artisan('outbox:prune', ['--dry-run' => true])
        ->expectsOutputToContain('Starting outbox pruning')
        ->assertSuccessful();

    expect(OutboxMessage::find($oldPublished->id))->not->toBeNull()
        ->and(OutboxMessage::find($oldFailed->id))->not->toBeNull();

    // Run pruning
    $this->artisan('outbox:prune', ['--published-days' => 7, '--failed-days' => 30])
        ->expectsOutputToContain('Outbox pruning completed successfully.')
        ->assertSuccessful();

    expect(OutboxMessage::find($oldPublished->id))->toBeNull()
        ->and(OutboxMessage::find($oldFailed->id))->toBeNull()
        ->and(OutboxMessage::find($recentPublished->id))->not->toBeNull();

    $audit = AuditLog::query()
        ->where('event_type', AuditEventType::SystemCleanupExecuted)
        ->where('actor_id', BlameContext::SYSTEM_ACTOR_ID)
        ->whereJsonContains('metadata->action', 'outbox:prune')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['pruned_published'])->toBe(1)
        ->and($audit->metadata['pruned_failed'])->toBe(1);
});

test('outbox:reap recovers stuck publishing messages back to pending', function () {
    $now = CarbonImmutable::now('UTC');

    // Stuck in publishing for 20 minutes (e.g. killed worker)
    $stuckMessage = OutboxMessage::create([
        'event_type' => OutboxEventType::UserRegistered->value,
        'aggregate_type' => 'user',
        'aggregate_id' => (string) Str::uuid(),
        'payload' => ['email' => 'stuck@example.com'],
        'headers' => ['actor_id' => BlameContext::SYSTEM_ACTOR_ID],
        'status' => OutboxStatus::Publishing,
    ]);
    DB::table('outbox_messages')->where('id', $stuckMessage->id)->update([
        'created_at' => $now->subMinutes(25),
        'updated_at' => $now->subMinutes(20),
    ]);

    // Recently claimed publishing message (1 minute ago)
    $activeMessage = OutboxMessage::create([
        'event_type' => OutboxEventType::UserRegistered->value,
        'aggregate_type' => 'user',
        'aggregate_id' => (string) Str::uuid(),
        'payload' => ['email' => 'active@example.com'],
        'headers' => ['actor_id' => BlameContext::SYSTEM_ACTOR_ID],
        'status' => OutboxStatus::Publishing,
    ]);
    DB::table('outbox_messages')->where('id', $activeMessage->id)->update([
        'created_at' => $now->subMinutes(2),
        'updated_at' => $now->subMinute(),
    ]);

    // Dry-run
    $this->artisan('outbox:reap', ['--stuck-minutes' => 10, '--dry-run' => true])
        ->assertSuccessful();

    expect($stuckMessage->fresh()->status)->toBe(OutboxStatus::Publishing);

    // Live reap
    $this->artisan('outbox:reap', ['--stuck-minutes' => 10])
        ->expectsOutputToContain('Outbox reaper completed successfully.')
        ->assertSuccessful();

    expect($stuckMessage->fresh()->status)->toBe(OutboxStatus::Pending)
        ->and($activeMessage->fresh()->status)->toBe(OutboxStatus::Publishing);

    $audit = AuditLog::query()
        ->where('event_type', AuditEventType::SystemReconciliationExecuted)
        ->where('actor_id', BlameContext::SYSTEM_ACTOR_ID)
        ->whereJsonContains('metadata->action', 'outbox:reap')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['reaped_messages'])->toBe(1);
});

test('system:reconcile synchronizes tokens, deactivated users, and recovers stuck tenants', function () {
    Queue::fake();

    $now = CarbonImmutable::now('UTC');

    // 1. Inconsistent token: session revoked but token still active
    $user1 = User::factory()->create(['status' => UserStatus::Active]);
    $revokedSession = AuthSession::create([
        'user_id' => $user1->id,
        'status' => SessionStatus::Revoked,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Mozilla',
        'expires_at' => $now->addDays(7),
        'revoked_at' => $now->subMinutes(10),
    ]);
    $inconsistentToken = AuthRefreshToken::create([
        'session_id' => $revokedSession->id,
        'user_id' => $user1->id,
        'token_hash' => hash('sha256', 'inconsistent-token'),
        'status' => RefreshTokenStatus::Active,
        'expires_at' => $now->addDays(7),
    ]);

    // 2. Suspended user with lingering active session and token
    $suspendedUser = User::factory()->create(['status' => UserStatus::Suspended]);
    $suspendedUserSession = AuthSession::create([
        'user_id' => $suspendedUser->id,
        'status' => SessionStatus::Active,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Mozilla',
        'expires_at' => $now->addDays(7),
    ]);
    $suspendedUserToken = AuthRefreshToken::create([
        'session_id' => $suspendedUserSession->id,
        'user_id' => $suspendedUser->id,
        'token_hash' => hash('sha256', 'suspended-user-token'),
        'status' => RefreshTokenStatus::Active,
        'expires_at' => $now->addDays(7),
    ]);

    // 3. Stuck provisioning tenant (> 30 minutes)
    $owner = User::factory()->create(['status' => UserStatus::Active]);
    $stuckTenant = Tenant::create([
        'name' => 'Stuck Acme Corp',
        'slug' => 'stuck-acme',
        'status' => TenantStatus::Provisioning,
    ]);
    DB::table('tenants')->where('id', $stuckTenant->id)->update([
        'created_at' => $now->subMinutes(45),
        'updated_at' => $now->subMinutes(40),
    ]);
    TenantMembership::create([
        'user_id' => $owner->id,
        'tenant_id' => $stuckTenant->id,
        'role' => TenantRole::Owner,
        'is_active' => false,
    ]);

    // Dry-run
    $this->artisan('system:reconcile', ['--dry-run' => true])
        ->expectsOutputToContain('Starting system reconciliation')
        ->assertSuccessful();

    expect($inconsistentToken->fresh()->status)->toBe(RefreshTokenStatus::Active)
        ->and($suspendedUserSession->fresh()->status)->toBe(SessionStatus::Active);

    // Live run
    $this->artisan('system:reconcile', ['--stuck-provisioning-minutes' => 30])
        ->expectsOutputToContain('System reconciliation completed successfully.')
        ->assertSuccessful();

    // Verify token was revoked
    expect($inconsistentToken->fresh()->status)->toBe(RefreshTokenStatus::Revoked)
        ->and($suspendedUserSession->fresh()->status)->toBe(SessionStatus::Revoked)
        ->and($suspendedUserToken->fresh()->status)->toBe(RefreshTokenStatus::Revoked);

    // Verify ProvisionTenantJob was dispatched for stuck tenant
    Queue::assertPushed(ProvisionTenantJob::class, function (ProvisionTenantJob $job) use ($stuckTenant) {
        return $job->tenantId === (string) $stuckTenant->id
            && $job->actorId === BlameContext::SYSTEM_ACTOR_ID;
    });

    // Verify audit log
    $audit = AuditLog::query()
        ->where('event_type', AuditEventType::SystemReconciliationExecuted)
        ->where('actor_id', BlameContext::SYSTEM_ACTOR_ID)
        ->whereJsonContains('metadata->action', 'system:reconcile')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['summary']['inconsistent_tokens_revoked'])->toBe(1)
        ->and($audit->metadata['summary']['deactivated_user_sessions_revoked'])->toBe(1)
        ->and($audit->metadata['summary']['deactivated_user_tokens_revoked'])->toBe(1)
        ->and($audit->metadata['summary']['stuck_tenants_detected'])->toBe(1)
        ->and($audit->metadata['summary']['stuck_tenants_recovered'])->toBe(1);
});
