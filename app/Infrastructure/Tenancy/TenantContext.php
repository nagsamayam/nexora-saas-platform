<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use App\Domain\Tenancy\Models\Tenant;

final class TenantContext
{
    private ?Tenant $currentTenant = null;

    public function setTenant(?Tenant $tenant): void
    {
        $this->currentTenant = $tenant;
    }

    public function getTenant(): ?Tenant
    {
        return $this->currentTenant;
    }

    public function getTenantId(): ?string
    {
        return $this->currentTenant?->id;
    }

    public function clear(): void
    {
        $this->currentTenant = null;
    }
}
