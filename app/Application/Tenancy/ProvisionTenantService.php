<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Outbox\Enums\OutboxEventType;
use App\Domain\Tenancy\Enums\TenantMembershipStatus;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Infrastructure\Audit\AuditService;
use App\Infrastructure\Audit\BlameContext;
use App\Infrastructure\Outbox\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProvisionTenantService
{
    public function __construct(
        private readonly OutboxService $outboxService,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Provision a tenant idempotently.
     *
     * @param  array<string, mixed>  $options
     */
    public function provision(string $tenantId, array $options = []): Tenant
    {
        return DB::transaction(function () use ($tenantId): Tenant {
            /** @var Tenant|null $tenant */
            $tenant = Tenant::query()
                ->where('id', $tenantId)
                ->lockForUpdate()
                ->first();

            if (! $tenant) {
                throw new \InvalidArgumentException("Tenant with ID {$tenantId} does not exist.");
            }

            // Idempotency check: If already Active (or Provisioned), return immediately without duplicate provisioning
            if ($tenant->status === TenantStatus::Active) {
                Log::info('Tenant is already provisioned and active, skipping redundant provisioning.', [
                    'tenant_id' => $tenant->id,
                    'status' => $tenant->status->value,
                ]);

                return $tenant->load('memberships');
            }

            // Record provisioning started audit log
            $actorId = BlameContext::getActorId();
            $this->auditService->record(
                eventType: AuditEventType::TenantProvisioningStarted,
                userId: $actorId,
                actorId: $actorId,
                metadata: [
                    'tenant_id' => $tenant->id,
                    'tenant_slug' => $tenant->slug,
                    'initial_status' => $tenant->status->value,
                ],
            );

            // Execute provisioning steps:
            // 1. Ensure creator/owner membership is active
            TenantMembership::query()
                ->where('tenant_id', $tenant->id)
                ->where('role', TenantRole::Owner)
                ->update([
                    'status' => TenantMembershipStatus::Active,
                    'updated_at' => CarbonImmutable::now(),
                ]);

            // 2. Mark tenant status as Active
            $tenant->update([
                'status' => TenantStatus::Active,
                'row_version' => $tenant->row_version + 1,
            ]);

            // Find tenant owner
            /** @var TenantMembership|null $ownerMembership */
            $ownerMembership = TenantMembership::query()
                ->with('user')
                ->where('tenant_id', $tenant->id)
                ->where('role', TenantRole::Owner)
                ->first();

            $ownerUser = $ownerMembership?->user;
            $ownerName = $ownerUser ? trim(($ownerUser->first_name ?? '').' '.($ownerUser->last_name ?? '')) : '';
            if ($ownerName === '') {
                $ownerName = (string) ($ownerUser->email ?? 'Tenant Owner');
            }

            // 3. Record Outbox Event for TenantProvisioned
            $this->outboxService->record(
                eventType: OutboxEventType::TenantProvisioned,
                aggregateType: 'Tenant',
                aggregateId: $tenant->id,
                payload: [
                    'tenant_id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'owner_id' => $ownerUser?->id,
                    'owner_email' => $ownerUser?->email,
                    'owner_name' => $ownerName,
                    'status' => TenantStatus::Active->value,
                    'provisioned_at' => CarbonImmutable::now()->toISOString(),
                ],
                headers: [
                    'actor_id' => (string) $actorId,
                ],
                eventKey: sprintf('tenant-provisioned-%s', $tenant->id),
            );

            // 4. Record Audit Log for TenantProvisioned
            $this->auditService->record(
                eventType: AuditEventType::TenantProvisioned,
                userId: $actorId,
                actorId: $actorId,
                metadata: [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'tenant_slug' => $tenant->slug,
                    'status' => TenantStatus::Active->value,
                ],
            );

            Log::info('Tenant provisioned successfully.', [
                'tenant_id' => $tenant->id,
                'slug' => $tenant->slug,
            ]);

            return $tenant->fresh(['memberships']) ?? $tenant;
        });
    }
}
