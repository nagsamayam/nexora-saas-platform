<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Auth\DTOs\RegisterUserDTO;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:320'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function toDTO(): RegisterUserDTO
    {
        /** @var string $name */
        $name = $this->validated('name');
        /** @var string $email */
        $email = $this->validated('email');
        /** @var string $password */
        $password = $this->validated('password');
        /** @var string|null $firstName */
        $firstName = $this->validated('first_name');
        /** @var string|null $lastName */
        $lastName = $this->validated('last_name');

        return new RegisterUserDTO(
            name: $name,
            email: $email,
            password: $password,
            firstName: $firstName,
            lastName: $lastName,
        );
    }
}
