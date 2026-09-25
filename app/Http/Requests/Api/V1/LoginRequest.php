<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Auth\DTOs\LoginUserDTO;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:320'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toDTO(): LoginUserDTO
    {
        /** @var string $email */
        $email = $this->validated('email');
        /** @var string $password */
        $password = $this->validated('password');
        /** @var string|null $deviceName */
        $deviceName = $this->validated('device_name');

        return new LoginUserDTO(
            email: $email,
            password: $password,
            deviceName: $deviceName,
            ipAddress: $this->ip(),
            userAgent: $this->userAgent(),
        );
    }
}
