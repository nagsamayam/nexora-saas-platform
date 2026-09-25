<?php

declare(strict_types=1);

namespace App\Domain\Auth\DTOs;

readonly class RegisterUserDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public ?string $firstName = null,
        public ?string $lastName = null,
    ) {}
}
