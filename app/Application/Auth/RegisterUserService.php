<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Auth\DTOs\RegisterUserDTO;
use App\Domain\Auth\Enums\UserStatus;
use App\Domain\Auth\Exceptions\DuplicateEmailException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegisterUserService
{
    /**
     * Register a new user inside a transaction.
     */
    public function register(RegisterUserDTO $dto): User
    {
        $normalizedEmail = Str::lower(trim($dto->email));

        return DB::transaction(function () use ($dto, $normalizedEmail): User {
            // Check for existing active email
            $existingUser = User::where('email_normalized', $normalizedEmail)->first();
            if ($existingUser !== null) {
                throw new DuplicateEmailException('An account with this email address already exists.');
            }

            // Split name if first/last not explicitly provided
            $firstName = $dto->firstName;
            $lastName = $dto->lastName;
            if ($firstName === null && $lastName === null && ! empty($dto->name)) {
                $nameParts = explode(' ', trim($dto->name), 2);
                $firstName = $nameParts[0];
                $lastName = $nameParts[1] ?? '';
            }

            $user = User::create([
                'name' => $dto->name,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => trim($dto->email),
                'email_normalized' => $normalizedEmail,
                'password_hash' => Hash::make($dto->password),
                'status' => UserStatus::Active,
                'auth_version' => 0,
                'row_version' => 1,
            ]);

            return $user;
        });
    }
}
