<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Auth\DTOs\RegisterUserDTO;
use App\Domain\Auth\Enums\UserStatus;
use App\Domain\Auth\Exceptions\DuplicateEmailException;
use App\Domain\Outbox\Enums\OutboxEventType;
use App\Infrastructure\Audit\AuditService;
use App\Infrastructure\Outbox\OutboxService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegisterUserService
{
    public function __construct(
        protected OutboxService $outboxService,
        protected AuditService $auditService,
    ) {}

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

            // Persist transactional outbox event
            $this->outboxService->record(
                eventType: OutboxEventType::UserRegistered,
                aggregateType: 'User',
                aggregateId: (string) $user->id,
                payload: [
                    'user_id' => (string) $user->id,
                    'name' => (string) ($user->name ?? ($user->first_name ?? 'User')),
                    'email' => (string) $user->email,
                ],
                eventKey: sprintf('user-registered-%s', $user->id),
            );

            // Record audit event
            $this->auditService->record(
                eventType: AuditEventType::UserRegistered,
                userId: (string) $user->id,
                metadata: [
                    'email' => (string) $user->email,
                    'status' => $user->status->value,
                ],
            );

            return $user;
        });
    }
}
