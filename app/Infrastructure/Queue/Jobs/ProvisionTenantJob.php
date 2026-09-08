<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue\Jobs;

use App\Application\Tenancy\ProvisionTenantService;
use App\Infrastructure\Audit\BlameContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class ProvisionTenantJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $actorId = null,
        public readonly array $options = [],
    ) {}

    public function handle(ProvisionTenantService $service): void
    {
        Log::info('Handling ProvisionTenantJob for tenant.', [
            'tenant_id' => $this->tenantId,
            'actor_id' => $this->actorId,
        ]);

        if ($this->actorId !== null) {
            BlameContext::setActorId($this->actorId);
        }

        try {
            $service->provision($this->tenantId, $this->options);
        } finally {
            if ($this->actorId !== null) {
                BlameContext::clear();
            }
        }
    }
}
