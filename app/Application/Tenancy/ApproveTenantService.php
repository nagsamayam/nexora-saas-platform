<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Outbox\Enums\OutboxEventType;
use App\Domain\Shared\Exceptions\ConflictException;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Infrastructure\Audit\AuditService;
use App\Infrastructure\Audit\BlameContext;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Queue\Jobs\ProvisionTenantJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ApproveTenantService
{
    public function __construct(
        private readonly OutboxService $outboxService,
        private readonly AuditService $auditService,
        private readonly ProvisionTenantService $provisionTenantService,
    ) {}

    /**
     * Approve a pending tenant and initiate idempotent provisioning.
     *
     *
     * @throws ConflictException
     */
    public function approve(User $admin, string $tenantId, bool $sync = false): Tenant
    {
        $tenant = DB::transaction(function () use ($admin, $tenantId): Tenant {
            /** @var Tenant|null $tenant */
            $tenant = Tenant::query()
                ->where('id', $tenantId)
                ->lockForUpdate()
                ->first();

            if (! $tenant) {
                throw new \InvalidArgumentException("Tenant with ID {$tenantId} does not exist.");
            }

            // If tenant is disabled or suspended, reject approval
            if (in_array($tenant->status, [TenantStatus::Disabled, TenantStatus::Suspended], true)) {
                throw new ConflictException("Cannot approve tenant in '{$tenant->status->value}' state.", 'INVALID_TENANT_STATUS');
            }

            // If tenant is already Active, return it idempotently
            if ($tenant->status === TenantStatus::Active) {
                return $tenant->load('memberships');
            }

            // Transition status to Provisioning
            $tenant->update([
                'status' => TenantStatus::Provisioning,
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

            // Transactional Outbox Event for TenantApproved
            $this->outboxService->record(
                eventType: OutboxEventType::TenantApproved,
                aggregateType: 'Tenant',
                aggregateId: $tenant->id,
                payload: [
                    'tenant_id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'approved_by_user_id' => $admin->id,
                    'owner_id' => $ownerUser?->id,
                    'owner_email' => $ownerUser?->email,
                    'owner_name' => $ownerName,
                    'status' => TenantStatus::Provisioning->value,
                ],
                headers: [
                    'actor_id' => (string) ($admin->id ?? BlameContext::getActorId()),
                ],
                eventKey: sprintf('tenant-approved-%s', $tenant->id),
            );

            // Audit Log for TenantApproved
            $this->auditService->record(
                eventType: AuditEventType::TenantApproved,
                userId: $admin->id,
                actorId: $admin->id,
                metadata: [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'tenant_slug' => $tenant->slug,
                    'status' => TenantStatus::Provisioning->value,
                ],
            );

            return $tenant->load('memberships');
        });

        // Trigger tenant provisioning (sync or async)
        if ($sync) {
            $tenant = $this->provisionTenantService->provision($tenant->id);
        } else {
            ProvisionTenantJob::dispatch($tenant->id, $admin->id);
        }

        return $tenant;
    }
}
