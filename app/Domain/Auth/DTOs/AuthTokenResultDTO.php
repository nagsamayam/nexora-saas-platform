<?php

declare(strict_types=1);

namespace App\Domain\Auth\DTOs;

readonly class AuthTokenResultDTO
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public string $tokenType = 'Bearer',
        public int $expiresIn = 900,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
        ];
    }
}
