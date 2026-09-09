<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RefreshTokenStatus;
use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Enums\UserStatus;
use App\Domain\Auth\Models\AuthRefreshToken;
use App\Domain\Auth\Models\AuthSession;
use App\Infrastructure\Jwt\JwtService;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('auth-register:127.0.0.1');
    RateLimiter::clear('auth-login:127.0.0.1');
    RateLimiter::clear('auth-refresh:127.0.0.1');
});

test('user can register successfully', function () {
    $payload = [
        'name' => 'Jane Doe',
        'email' => 'Jane.Doe@Example.com',
        'password' => 'secret12345!',
        'password_confirmation' => 'secret12345!',
    ];

    $response = $this->postJson('/api/v1/auth/register', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Jane Doe')
        ->assertJsonPath('data.email', 'Jane.Doe@Example.com')
        ->assertJsonPath('data.status', 'active');

    $this->assertDatabaseHas('users', [
        'email_normalized' => 'jane.doe@example.com',
        'status' => 'active',
        'auth_version' => 0,
    ]);
});

test('registration validation fails on invalid payload', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => '',
        'email' => 'invalid-email',
        'password' => 'short',
        'password_confirmation' => 'mismatch',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
});

test('registration fails on duplicate email with 409 conflict', function () {
    User::factory()->create([
        'email' => 'existing@example.com',
        'email_normalized' => 'existing@example.com',
    ]);

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Existing User',
        'email' => 'EXISTING@example.com',
        'password' => 'password12345',
        'password_confirmation' => 'password12345',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'AUTH_DUPLICATE_EMAIL');
});

test('user can login with valid credentials and receive RS256 token pair', function () {
    $user = User::factory()->create([
        'email' => 'user@example.com',
        'email_normalized' => 'user@example.com',
        'password_hash' => Hash::make('password123'),
        'status' => UserStatus::Active,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'USER@example.com',
        'password' => 'password123',
        'device_name' => 'MacBook Pro',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'access_token',
                'refresh_token',
                'token_type',
                'expires_in',
            ],
        ]);

    $accessToken = $response->json('data.access_token');
    $refreshToken = $response->json('data.refresh_token');

    expect($accessToken)->toBeString()
        ->and($refreshToken)->toBeString();

    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);
    $claims = $jwtService->validateToken($accessToken);

    expect($claims['sub'])->toBe((string) $user->id)
        ->and($claims['auth_version'])->toBe(0)
        ->and($claims['iss'])->toBe(config('jwt.issuer'))
        ->and($claims['aud'])->toBe(config('jwt.audience'));

    $session = AuthSession::where('user_id', $user->id)->first();
    expect($session)->not->toBeNull()
        ->and($session->device_name)->toBe('MacBook Pro')
        ->and($session->status)->toBe(SessionStatus::Active);

    $tokenHash = hash('sha256', $refreshToken);
    $this->assertDatabaseHas('auth_refresh_tokens', [
        'session_id' => $session->id,
        'token_hash' => $tokenHash,
        'status' => 'active',
    ]);
});

test('login fails with invalid credentials', function () {
    User::factory()->create([
        'email' => 'user@example.com',
        'email_normalized' => 'user@example.com',
        'password_hash' => Hash::make('password123'),
        'status' => UserStatus::Active,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'user@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('error.code', 'AUTH_INVALID_CREDENTIALS');
});

test('login fails when user is inactive or suspended', function () {
    User::factory()->create([
        'email' => 'suspended@example.com',
        'email_normalized' => 'suspended@example.com',
        'password_hash' => Hash::make('password123'),
        'status' => UserStatus::Suspended,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'suspended@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'AUTH_USER_INACTIVE');
});

test('authenticated user can view profile with /me endpoint', function () {
    $user = User::factory()->create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'email_normalized' => 'john@example.com',
        'status' => UserStatus::Active,
    ]);

    $session = AuthSession::create([
        'user_id' => $user->id,
        'status' => SessionStatus::Active,
        'expires_at' => now()->addDays(30),
    ]);

    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);
    $token = $jwtService->issueAccessToken($user, (string) $session->id);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(200)
        ->assertJsonPath('data.id', (string) $user->id)
        ->assertJsonPath('data.email', 'john@example.com');
});

test('unauthenticated request to /me fails', function () {
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertStatus(401)
        ->assertJsonPath('error.code', 'AUTH_UNAUTHORIZED');
});

test('refresh token rotation issues new token pair and consumes old token', function () {
    $user = User::factory()->create([
        'status' => UserStatus::Active,
    ]);

    $session = AuthSession::create([
        'user_id' => $user->id,
        'status' => SessionStatus::Active,
        'expires_at' => now()->addDays(30),
    ]);

    $rawRefreshToken = bin2hex(random_bytes(32));
    $tokenRecord = AuthRefreshToken::create([
        'session_id' => $session->id,
        'token_hash' => hash('sha256', $rawRefreshToken),
        'status' => RefreshTokenStatus::Active,
        'expires_at' => now()->addDays(30),
    ]);

    $response = $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $rawRefreshToken,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => ['access_token', 'refresh_token', 'token_type', 'expires_in'],
        ]);

    $newRefreshToken = $response->json('data.refresh_token');
    expect($newRefreshToken)->not->toBe($rawRefreshToken);

    // Old token must be consumed
    $tokenRecord->refresh();
    expect($tokenRecord->status)->toBe(RefreshTokenStatus::Consumed)
        ->and($tokenRecord->consumed_at)->not->toBeNull()
        ->and($tokenRecord->replaced_by)->not->toBeNull();

    // New token must be active
    $this->assertDatabaseHas('auth_refresh_tokens', [
        'session_id' => $session->id,
        'token_hash' => hash('sha256', $newRefreshToken),
        'status' => 'active',
    ]);
});

test('refresh token reuse detection revokes session and active tokens', function () {
    $user = User::factory()->create([
        'status' => UserStatus::Active,
    ]);

    $session = AuthSession::create([
        'user_id' => $user->id,
        'status' => SessionStatus::Active,
        'expires_at' => now()->addDays(30),
    ]);

    $rawRefreshToken = bin2hex(random_bytes(32));
    $tokenRecord = AuthRefreshToken::create([
        'session_id' => $session->id,
        'token_hash' => hash('sha256', $rawRefreshToken),
        'status' => RefreshTokenStatus::Active,
        'expires_at' => now()->addDays(30),
    ]);

    // 1st consumption -> succeeds
    $response1 = $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $rawRefreshToken,
    ]);
    $response1->assertStatus(200);

    // 2nd consumption using the SAME consumed refresh token -> reuse detected!
    $response2 = $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $rawRefreshToken,
    ]);

    $response2->assertStatus(401)
        ->assertJsonPath('error.code', 'AUTH_REFRESH_TOKEN_REUSE_DETECTED');

    $session->refresh();
    expect($session->status)->toBe(SessionStatus::Revoked);

    $activeTokensCount = AuthRefreshToken::where('session_id', $session->id)
        ->where('status', RefreshTokenStatus::Active)
        ->count();
    expect($activeTokensCount)->toBe(0);
});

test('logout revokes current session and blacklists token', function () {
    $user = User::factory()->create(['status' => UserStatus::Active]);
    $session = AuthSession::create([
        'user_id' => $user->id,
        'status' => SessionStatus::Active,
        'expires_at' => now()->addDays(30),
    ]);

    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);
    $token = $jwtService->issueAccessToken($user, (string) $session->id);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout');

    $response->assertStatus(204);

    $session->refresh();
    expect($session->status)->toBe(SessionStatus::Revoked);

    // Subsequent call with the same token fails due to blacklist/revoked session
    $subsequentResponse = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me');

    $subsequentResponse->assertStatus(401);
});

test('logout-all invalidates all user sessions and increments auth_version', function () {
    $user = User::factory()->create([
        'status' => UserStatus::Active,
        'auth_version' => 0,
    ]);

    $session1 = AuthSession::create([
        'user_id' => $user->id,
        'status' => SessionStatus::Active,
        'expires_at' => now()->addDays(30),
    ]);

    $session2 = AuthSession::create([
        'user_id' => $user->id,
        'status' => SessionStatus::Active,
        'expires_at' => now()->addDays(30),
    ]);

    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);
    $token1 = $jwtService->issueAccessToken($user, (string) $session1->id);

    $response = $this->withHeader('Authorization', "Bearer {$token1}")
        ->postJson('/api/v1/auth/logout-all');

    $response->assertStatus(204);

    $user->refresh();
    expect($user->auth_version)->toBe(1);

    $session1->refresh();
    $session2->refresh();
    expect($session1->status)->toBe(SessionStatus::Revoked)
        ->and($session2->status)->toBe(SessionStatus::Revoked);

    // Old token with auth_version=0 is rejected
    $checkResponse = $this->withHeader('Authorization', "Bearer {$token1}")
        ->getJson('/api/v1/auth/me');

    $checkResponse->assertStatus(401);
});

test('rate limiting is enforced on login endpoint', function () {
    RateLimiter::clear('auth-login:127.0.0.1');

    // Default rate limit is 10 per minute
    for ($i = 0; $i < 10; $i++) {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => "attempt{$i}@example.com",
            'password' => 'wrong',
        ]);
        $response->assertStatus(401);
    }

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'blocked@example.com',
        'password' => 'wrong',
    ]);

    $response->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED');
});
