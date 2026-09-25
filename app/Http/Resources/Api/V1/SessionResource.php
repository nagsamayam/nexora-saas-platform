<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Auth\Models\AuthSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuthSession
 */
class SessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentSessionId = $request->attributes->get('auth_session_id');

        return [
            'id' => $this->id,
            'device_name' => $this->device_name,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'status' => $this->status->value,
            'is_current' => $currentSessionId !== null && $this->id === $currentSessionId,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
