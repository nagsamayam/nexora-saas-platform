<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Tenant $resource
 *
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'status' => $this->resource->status->value,
            'approved_at' => $this->resource->approved_at?->toISOString(),
            'provisioning_started_at' => $this->resource->provisioning_started_at?->toISOString(),
            'provisioned_at' => $this->resource->provisioned_at?->toISOString(),
            'suspended_at' => $this->resource->suspended_at?->toISOString(),
            'row_version' => $this->resource->row_version,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
