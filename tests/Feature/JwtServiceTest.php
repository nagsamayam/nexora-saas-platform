<?php

declare(strict_types=1);

use App\Domain\Auth\Exceptions\BlacklistedTokenException;
use App\Domain\Auth\Exceptions\ExpiredTokenException;
use App\Domain\Auth\Exceptions\InvalidTokenException;
use App\Infrastructure\Jwt\JwtService;
use App\Models\User;
use Illuminate\Support\Str;

test('jwt service generates valid rs256 token and validates claims', function () {
    $user = User::factory()->create(['auth_version' => 0]);
    $sessionId = (string) Str::uuid();

    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);
    $token = $jwtService->issueAccessToken($user, $sessionId);

    $payload = $jwtService->validateToken($token);

    expect($payload['sub'])->toBe((string) $user->id)
        ->and($payload['sid'])->toBe($sessionId)
        ->and($payload['auth_version'])->toBe(0)
        ->and($payload['iss'])->toBe(config('jwt.issuer'))
        ->and($payload['aud'])->toBe(config('jwt.audience'))
        ->and($payload['jti'])->toBeString();
});

test('jwt service rejects token with invalid signature', function () {
    $user = User::factory()->create();
    $sessionId = (string) Str::uuid();

    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);
    $token = $jwtService->issueAccessToken($user, $sessionId);

    $parts = explode('.', $token);
    // Tamper with payload
    $tamperedPayload = $jwtService->base64UrlEncode('{"sub":"tampered"}');
    $tamperedToken = "{$parts[0]}.{$tamperedPayload}.{$parts[2]}";

    expect(fn () => $jwtService->validateToken($tamperedToken))
        ->toThrow(InvalidTokenException::class);
});

test('jwt service rejects expired token', function () {
    $user = User::factory()->create();
    $sessionId = (string) Str::uuid();

    $jwtService = new JwtService([
        'keys' => [
            'private' => config('jwt.keys.private'),
            'public' => config('jwt.keys.public'),
        ],
        'ttl' => -10, // already expired
        'issuer' => 'Nexora',
        'audience' => 'nexora-api',
    ]);

    $token = $jwtService->issueAccessToken($user, $sessionId);

    expect(fn () => $jwtService->validateToken($token))
        ->toThrow(ExpiredTokenException::class);
});

test('jwt service rejects blacklisted token', function () {
    $user = User::factory()->create();
    $sessionId = (string) Str::uuid();

    /** @var JwtService $jwtService */
    $jwtService = app(JwtService::class);
    $token = $jwtService->issueAccessToken($user, $sessionId);

    $jwtService->blacklistToken($token);

    expect(fn () => $jwtService->validateToken($token))
        ->toThrow(BlacklistedTokenException::class);
});
