<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Models\AuthSession;
use App\Domain\Identity\Enums\PlatformRole as PlatformRoleEnum;
use App\Domain\Identity\Models\PlatformRole;
use App\Domain\Shared\Exceptions\ConcurrencyException;
use App\Infrastructure\Jwt\JwtService;
use App\Models\User;

test('authenticated user can list active sessions', function () {
    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);

    $user = User::factory()->create();

    $session1 = AuthSession::factory()->create([
        'user_id' => $user->id,
        'device_name' => 'Safari on macOS',
        'status' => SessionStatus::Active,
        'expires_at' => now()->addDays(30),
    ]);

    $session2 = AuthSession::factory()->create([
        'user_id' => $user->id,
        'device_name' => 'Mobile App iOS',
        'status' => SessionStatus::Active,
        'expires_at' => now()->addDays(30),
    ]);

    $revokedSession = AuthSession::factory()->create([
        'user_id' => $user->id,
        'device_name' => 'Old Device',
        'status' => SessionStatus::Revoked,
    ]);

    $token = $jwtService->issueAccessToken($user, (string) $session1->id);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/sessions');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['id' => (string) $session1->id, 'device_name' => 'Safari on macOS', 'is_current' => true])
        ->assertJsonFragment(['id' => (string) $session2->id, 'device_name' => 'Mobile App iOS', 'is_current' => false]);
});

test('authenticated user can revoke a specific session', function () {
    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);

    $user = User::factory()->create();

    $session1 = AuthSession::factory()->create([
        'user_id' => $user->id,
        'status' => SessionStatus::Active,
    ]);

    $session2 = AuthSession::factory()->create([
        'user_id' => $user->id,
        'status' => SessionStatus::Active,
    ]);

    $token = $jwtService->issueAccessToken($user, (string) $session1->id);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/auth/sessions/{$session2->id}");

    $response->assertNoContent();

    expect($session2->fresh()->status)->toBe(SessionStatus::Revoked);
});

test('admin can globally revoke target user sessions and increment auth_version', function () {
    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);

    $admin = User::factory()->create();
    $superAdminRole = PlatformRole::firstOrCreate(
        ['name' => PlatformRoleEnum::SuperAdmin->value],
        ['description' => 'Super Administrator']
    );
    $admin->platformRoles()->attach($superAdminRole);

    $targetUser = User::factory()->create(['auth_version' => 1]);

    $targetSession = AuthSession::factory()->create([
        'user_id' => $targetUser->id,
        'status' => SessionStatus::Active,
    ]);

    $adminSession = AuthSession::factory()->create([
        'user_id' => $admin->id,
        'status' => SessionStatus::Active,
    ]);

    $adminToken = $jwtService->issueAccessToken($admin, (string) $adminSession->id);
    $targetToken = $jwtService->issueAccessToken($targetUser, (string) $targetSession->id);

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/v1/admin/users/{$targetUser->id}/revoke-sessions", [
            'reason' => 'Suspicious activity detected',
        ]);

    $response->assertNoContent();

    // Verify session revoked and auth_version incremented
    expect($targetSession->fresh()->status)->toBe(SessionStatus::Revoked)
        ->and((int) $targetUser->fresh()->auth_version)->toBe(2);

    // Verify target user token is now rejected due to auth_version change
    $this->withHeader('Authorization', "Bearer {$targetToken}")
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();

    // Verify audit log recorded
    $auditLog = AuditLog::where('event_type', AuditEventType::UserSessionsRevoked)
        ->where('user_id', $targetUser->id)
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->metadata)->toHaveKey('revoked_by_admin_id');
});

test('non-admin user cannot invoke admin global logout', function () {
    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);

    $regularUser = User::factory()->create();
    $targetUser = User::factory()->create();

    $session = AuthSession::factory()->create([
        'user_id' => $regularUser->id,
        'status' => SessionStatus::Active,
    ]);

    $token = $jwtService->issueAccessToken($regularUser, (string) $session->id);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/users/{$targetUser->id}/revoke-sessions")
        ->assertForbidden();
});

test('optimistic concurrency control detects concurrent conflict and throws ConcurrencyException', function () {
    $user = User::factory()->create(['row_version' => 1, 'first_name' => 'Original']);

    // First update succeeds
    $user->updateWithOcc(['first_name' => 'Updated by process 1'], 1);

    expect((int) $user->fresh()->row_version)->toBe(2)
        ->and($user->fresh()->first_name)->toBe('Updated by process 1');

    // Second update with stale version fails and throws ConcurrencyException
    expect(function () use ($user) {
        $user->updateWithOcc(['first_name' => 'Updated by process 2'], 1);
    })->toThrow(ConcurrencyException::class);
});
