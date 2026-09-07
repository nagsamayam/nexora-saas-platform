<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Auth\Models\AuthSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property AuthSession $resource
 */
class AuthSessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var string|null $currentSid */
        $currentSid = $request->attributes->get('jwt_sid');

        return [
            'id' => $this->resource->id,
            'status' => $this->resource->status->value,
            'ip_address' => $this->resource->ip_address,
            'user_agent' => $this->resource->user_agent,
            'is_current' => $currentSid !== null && $this->resource->id === $currentSid,
            'last_used_at' => $this->resource->last_used_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
