<?php

declare(strict_types=1);

namespace App\Infrastructure\Jwt;

use App\Domain\Auth\Exceptions\BlacklistedTokenException;
use App\Domain\Auth\Exceptions\ExpiredTokenException;
use App\Domain\Auth\Exceptions\InvalidTokenException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OpenSSLAsymmetricKey;
use RuntimeException;

class JwtService
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config = []
    ) {
        if (empty($this->config)) {
            /** @var array<string, mixed> $cfg */
            $cfg = config('jwt', []);
            $this->config = $cfg;
        }
    }

    /**
     * Generate an RS512 signed JWT Access Token.
     *
     * @param  array<string, mixed>  $customClaims
     */
    public function issueAccessToken(User $user, string $sessionId, array $customClaims = []): string
    {
        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        /** @var int $ttl */
        $ttl = $this->config['ttl'] ?? 900;
        $exp = $now->addSeconds($ttl);

        /** @var string $issuer */
        $issuer = $this->config['issuer'] ?? 'Nexora';
        /** @var string $audience */
        $audience = $this->config['audience'] ?? 'nexora-api';

        $payload = array_merge([
            'iss' => $issuer,
            'aud' => $audience,
            'sub' => (string) $user->id,
            'iat' => $now->getTimestamp(),
            'exp' => $exp->getTimestamp(),
            'nbf' => $now->getTimestamp(),
            'jti' => (string) Str::uuid(),
            'sid' => $sessionId,
            'auth_version' => (int) $user->auth_version,
        ], $customClaims);

        $header = [
            'typ' => 'JWT',
            'alg' => config('jwt.algo'),
        ];

        $encodedHeader = $this->base64UrlEncode((string) json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode((string) json_encode($payload, JSON_THROW_ON_ERROR));
        $signingInput = "{$encodedHeader}.{$encodedPayload}";

        $signature = '';
        $privateKey = $this->getPrivateKey();
        $success = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (! $success) {
            throw new RuntimeException('Failed to sign JWT with RSA private key: '.(openssl_error_string() ?: 'unknown error'));
        }

        $encodedSignature = $this->base64UrlEncode($signature);

        return "{$signingInput}.{$encodedSignature}";
    }

    /**
     * Decode and validate an RS512 JWT access token.
     *
     * @return array<string, mixed>
     */
    public function validateToken(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidTokenException('JWT format is invalid.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $headerJson = $this->base64UrlDecode($encodedHeader);
        $payloadJson = $this->base64UrlDecode($encodedPayload);
        $signature = $this->base64UrlDecode($encodedSignature);

        if ($headerJson === false || $payloadJson === false || $signature === false) {
            throw new InvalidTokenException('Failed to decode JWT base64url data.');
        }

        /** @var array<string, mixed>|null $header */
        $header = json_decode($headerJson, true);
        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($payloadJson, true);

        if (! is_array($header) || ! is_array($payload)) {
            throw new InvalidTokenException('Invalid JWT JSON structure.');
        }

        if (($header['alg'] ?? '') !== config('jwt.algo') || ($header['typ'] ?? '') !== 'JWT') {
            throw new InvalidTokenException('Unsupported JWT header algorithm or type.');
        }

        $signingInput = "{$encodedHeader}.{$encodedPayload}";
        $publicKey = $this->getPublicKey();
        $verified = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($verified !== 1) {
            throw new InvalidTokenException('JWT signature verification failed.');
        }

        $this->validateClaims($payload);

        return $payload;
    }

    /**
     * Check claims and blacklist status.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validateClaims(array $payload): void
    {
        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'))->getTimestamp();
        /** @var int $leeway */
        $leeway = $this->config['leeway'] ?? 0;

        $requiredClaims = ['iss', 'aud', 'sub', 'iat', 'exp', 'nbf', 'jti', 'sid', 'auth_version'];
        foreach ($requiredClaims as $claim) {
            if (! array_key_exists($claim, $payload)) {
                throw new InvalidTokenException("Missing required JWT claim: {$claim}");
            }
        }

        /** @var string $expectedIssuer */
        $expectedIssuer = $this->config['issuer'] ?? 'Nexora';
        /** @var string $expectedAudience */
        $expectedAudience = $this->config['audience'] ?? 'nexora-api';

        if ($payload['iss'] !== $expectedIssuer) {
            throw new InvalidTokenException('JWT issuer mismatch.');
        }

        if ($payload['aud'] !== $expectedAudience) {
            throw new InvalidTokenException('JWT audience mismatch.');
        }

        $exp = (int) $payload['exp'];
        $nbf = (int) $payload['nbf'];
        $iat = (int) $payload['iat'];

        if ($now > ($exp + $leeway)) {
            throw new ExpiredTokenException('JWT token has expired.');
        }

        if (($now + $leeway) < $nbf) {
            throw new InvalidTokenException('JWT token is not yet valid (nbf).');
        }

        if ($iat > ($now + $leeway + 300)) {
            throw new InvalidTokenException('JWT token issuance time is in the future.');
        }

        // Check Blacklist
        $jti = (string) $payload['jti'];
        if ($this->isBlacklisted($jti)) {
            throw new BlacklistedTokenException('JWT token has been revoked.');
        }
    }

    /**
     * Add token to blacklist.
     *
     * @param  array<string, mixed>|string  $tokenOrPayload
     */
    public function blacklistToken(array|string $tokenOrPayload): void
    {
        if (is_string($tokenOrPayload)) {
            try {
                $payload = $this->validateToken($tokenOrPayload);
            } catch (ExpiredTokenException) {
                return; // Already expired, no need to blacklist
            }
        } else {
            $payload = $tokenOrPayload;
        }

        /** @var string $jti */
        $jti = $payload['jti'] ?? '';
        /** @var int $exp */
        $exp = $payload['exp'] ?? 0;

        if ($jti === '' || $exp === 0) {
            return;
        }

        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'))->getTimestamp();
        $ttl = max(0, $exp - $now);

        if ($ttl > 0) {
            /** @var string $prefix */
            $prefix = $this->config['blacklist_cache_prefix'] ?? 'jwt_blacklist:';
            Cache::put($prefix.$jti, true, $ttl);
        }
    }

    public function isBlacklisted(string $jti): bool
    {
        /** @var string $prefix */
        $prefix = $this->config['blacklist_cache_prefix'] ?? 'jwt_blacklist:';

        return (bool) Cache::get($prefix.$jti, false);
    }

    public function getPrivateKey(): OpenSSLAsymmetricKey
    {
        /** @var string|null $keyPathOrContent */
        $keyPathOrContent = $this->config['keys']['private'] ?? null;
        /** @var string|null $passphrase */
        $passphrase = $this->config['keys']['passphrase'] ?? null;

        if (! $keyPathOrContent) {
            throw new RuntimeException('JWT private key is not configured.');
        }

        $resolvedPath = $this->resolveKeyPath($keyPathOrContent);
        $keyContent = file_exists($resolvedPath)
            ? (string) file_get_contents($resolvedPath)
            : $keyPathOrContent;

        $key = openssl_pkey_get_private($keyContent, $passphrase ?? '');
        if ($key === false) {
            throw new RuntimeException('Invalid JWT private key: '.(openssl_error_string() ?: 'unknown error'));
        }

        return $key;
    }

    public function getPublicKey(): OpenSSLAsymmetricKey
    {
        /** @var string|null $keyPathOrContent */
        $keyPathOrContent = $this->config['keys']['public'] ?? null;

        if (! $keyPathOrContent) {
            throw new RuntimeException('JWT public key is not configured.');
        }

        $resolvedPath = $this->resolveKeyPath($keyPathOrContent);
        $keyContent = file_exists($resolvedPath)
            ? (string) file_get_contents($resolvedPath)
            : $keyPathOrContent;

        $key = openssl_pkey_get_public($keyContent);
        if ($key === false) {
            throw new RuntimeException('Invalid JWT public key: '.(openssl_error_string() ?: 'unknown error'));
        }

        return $key;
    }

    private function resolveKeyPath(string $keyPathOrContent): string
    {
        if (str_starts_with($keyPathOrContent, 'file://')) {
            $path = substr($keyPathOrContent, 7);
            if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:\\\\/', $path)) {
                return base_path($path);
            }

            return $path;
        }

        return $keyPathOrContent;
    }

    public function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function base64UrlDecode(string $data): string|false
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }

        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
