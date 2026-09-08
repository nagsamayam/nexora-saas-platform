<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Outbox\Enums\OutboxEventType;
use App\Domain\Shared\Exceptions\ConflictException;
use App\Domain\Tenancy\Enums\TenantMembershipStatus;
use App\Domain\Tenancy\Enums\TenantRole;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Infrastructure\Audit\AuditService;
use App\Infrastructure\Audit\BlameContext;
use App\Infrastructure\Outbox\OutboxService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OnboardTenantService
{
    public function __construct(
        private readonly OutboxService $outboxService,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Onboard a new tenant organization and assign the creator as the Owner.
     *
     * @param  array{name: string, slug?: string|null}  $data
     *
     * @throws ConflictException
     */
    public function onboard(User $creator, array $data): Tenant
    {
        $rawSlug = $data['slug'] ?? Str::slug($data['name']);
        $slug = Str::lower(trim($rawSlug));

        if (empty($slug)) {
            $slug = (string) Str::uuid();
        }

        return DB::transaction(function () use ($creator, $data, $slug): Tenant {
            // Check if slug is already taken by a non-deleted tenant
            $existingTenant = Tenant::query()
                ->where('slug', $slug)
                ->exists();

            if ($existingTenant) {
                throw new ConflictException("The tenant slug '{$slug}' is already taken.", 'TENANT_SLUG_CONFLICT');
            }

            // Create Tenant in Pending state awaiting platform admin approval
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->create([
                'name' => trim($data['name']),
                'slug' => $slug,
                'status' => TenantStatus::Pending,
                'row_version' => 1,
            ]);

            // Create Owner Membership for creator
            TenantMembership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $creator->id,
                'role' => TenantRole::Owner,
                'status' => TenantMembershipStatus::Active,
                'row_version' => 1,
            ]);

            // Transactional Outbox Event
            $this->outboxService->record(
                eventType: OutboxEventType::TenantCreated,
                aggregateType: 'Tenant',
                aggregateId: $tenant->id,
                payload: [
                    'tenant_id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'owner_user_id' => $creator->id,
                    'status' => $tenant->status->value,
                ],
                headers: [
                    'actor_id' => (string) ($creator->id ?? BlameContext::getActorId()),
                ],
                eventKey: sprintf('tenant-created-%s', $tenant->id),
            );

            // Audit Log
            $this->auditService->record(
                eventType: AuditEventType::TenantCreated,
                userId: $creator->id,
                actorId: $creator->id,
                metadata: [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'tenant_slug' => $tenant->slug,
                    'role' => TenantRole::Owner->value,
                ],
            );

            return $tenant->load('memberships');
        });
    }
}
