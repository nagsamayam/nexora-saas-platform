<?php

declare(strict_types=1);

namespace App\Application\System;

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Auth\Enums\RefreshTokenStatus;
use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Enums\UserStatus;
use App\Domain\Auth\Models\AuthRefreshToken;
use App\Domain\Auth\Models\AuthSession;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Infrastructure\Audit\AuditService;
use App\Infrastructure\Audit\BlameContext;
use App\Infrastructure\Queue\Jobs\ProvisionTenantJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemReconciliationService
{
    public function __construct(
        protected AuditService $auditService,
    ) {}

    /**
     * @param  array{
     *     stuck_provisioning_minutes?: int,
     *     auto_recover_tenants?: bool,
     *     dry_run?: bool
     * }  $options
     * @return array{
     *     inconsistent_tokens_revoked: int,
     *     deactivated_user_sessions_revoked: int,
     *     deactivated_user_tokens_revoked: int,
     *     stuck_tenants_detected: int,
     *     stuck_tenants_recovered: int
     * }
     */
    public function execute(array $options = []): array
    {
        $stuckProvisioningMinutes = $options['stuck_provisioning_minutes'] ?? 30;
        $autoRecoverTenants = $options['auto_recover_tenants'] ?? true;
        $dryRun = $options['dry_run'] ?? false;

        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $provisioningThreshold = $now->subMinutes($stuckProvisioningMinutes);

        BlameContext::setActorId(BlameContext::SYSTEM_ACTOR_ID);

        try {
            if ($dryRun) {
                // Inconsistent tokens count (active token but non-active session)
                $inconsistentTokensCount = AuthRefreshToken::query()
                    ->where('status', RefreshTokenStatus::Active->value)
                    ->whereHas('session', function ($query): void {
                        $query->whereIn('status', [
                            SessionStatus::Revoked->value,
                            SessionStatus::Expired->value,
                        ]);
                    })
                    ->count();

                // Deactivated users with active sessions/tokens
                $deactivatedUserIds = User::query()
                    ->whereIn('status', [
                        UserStatus::Suspended->value,
                        UserStatus::Disabled->value,
                    ])
                    ->pluck('id');

                $deactivatedSessionsCount = AuthSession::query()
                    ->whereIn('user_id', $deactivatedUserIds)
                    ->where('status', SessionStatus::Active->value)
                    ->count();

                $deactivatedTokensCount = AuthRefreshToken::query()
                    ->where('status', RefreshTokenStatus::Active->value)
                    ->whereHas('session', function ($query) use ($deactivatedUserIds): void {
                        $query->whereIn('user_id', $deactivatedUserIds);
                    })
                    ->count();

                // Stuck provisioning tenants
                $stuckTenantsCount = Tenant::query()
                    ->where('status', TenantStatus::Provisioning->value)
                    ->where('updated_at', '<=', $provisioningThreshold)
                    ->count();

                return [
                    'inconsistent_tokens_revoked' => $inconsistentTokensCount,
                    'deactivated_user_sessions_revoked' => $deactivatedSessionsCount,
                    'deactivated_user_tokens_revoked' => $deactivatedTokensCount,
                    'stuck_tenants_detected' => $stuckTenantsCount,
                    'stuck_tenants_recovered' => 0,
                ];
            }

            /** @var array{inconsistent_tokens_revoked: int, deactivated_user_sessions_revoked: int, deactivated_user_tokens_revoked: int} $dbResult */
            $dbResult = DB::transaction(function () use ($now): array {
                // 1. Revoke active tokens attached to inactive sessions
                $inconsistentTokenIds = AuthRefreshToken::query()
                    ->where('status', RefreshTokenStatus::Active->value)
                    ->whereHas('session', function ($query): void {
                        $query->whereIn('status', [
                            SessionStatus::Revoked->value,
                            SessionStatus::Expired->value,
                        ]);
                    })
                    ->pluck('id');

                $inconsistentTokensRevoked = 0;
                if ($inconsistentTokenIds->isNotEmpty()) {
                    $inconsistentTokensRevoked = AuthRefreshToken::query()
                        ->whereIn('id', $inconsistentTokenIds)
                        ->update([
                            'status' => RefreshTokenStatus::Revoked->value,
                            'revoked_at' => $now,
                            'updated_at' => $now,
                        ]);
                }

                // 2. Revoke active sessions & tokens for suspended/disabled users
                $deactivatedUserIds = User::query()
                    ->whereIn('status', [
                        UserStatus::Suspended->value,
                        UserStatus::Disabled->value,
                    ])
                    ->pluck('id');

                $deactivatedSessionsRevoked = 0;
                $deactivatedTokensRevoked = 0;

                if ($deactivatedUserIds->isNotEmpty()) {
                    $deactivatedTokensRevoked = AuthRefreshToken::query()
                        ->where('status', RefreshTokenStatus::Active->value)
                        ->whereHas('session', function ($query) use ($deactivatedUserIds): void {
                            $query->whereIn('user_id', $deactivatedUserIds);
                        })
                        ->update([
                            'status' => RefreshTokenStatus::Revoked->value,
                            'revoked_at' => $now,
                            'updated_at' => $now,
                        ]);

                    $deactivatedSessionsRevoked = AuthSession::query()
                        ->whereIn('user_id', $deactivatedUserIds)
                        ->where('status', SessionStatus::Active->value)
                        ->update([
                            'status' => SessionStatus::Revoked->value,
                            'revoked_at' => $now,
                            'updated_at' => $now,
                        ]);
                }

                return [
                    'inconsistent_tokens_revoked' => $inconsistentTokensRevoked,
                    'deactivated_user_sessions_revoked' => $deactivatedSessionsRevoked,
                    'deactivated_user_tokens_revoked' => $deactivatedTokensRevoked,
                ];
            });

            // 3. Reconcile stuck provisioning tenants
            $stuckTenants = Tenant::query()
                ->where('status', TenantStatus::Provisioning->value)
                ->where('updated_at', '<=', $provisioningThreshold)
                ->get();

            $stuckTenantsDetected = $stuckTenants->count();
            $stuckTenantsRecovered = 0;

            if ($autoRecoverTenants && $stuckTenants->isNotEmpty()) {
                foreach ($stuckTenants as $tenant) {
                    ProvisionTenantJob::dispatch(
                        tenantId: (string) $tenant->id,
                        actorId: BlameContext::SYSTEM_ACTOR_ID,
                        options: ['source' => 'system_reconciliation'],
                        correlationId: BlameContext::getCorrelationId(),
                    );
                    $stuckTenantsRecovered++;
                }
            }

            $summary = [
                'inconsistent_tokens_revoked' => $dbResult['inconsistent_tokens_revoked'],
                'deactivated_user_sessions_revoked' => $dbResult['deactivated_user_sessions_revoked'],
                'deactivated_user_tokens_revoked' => $dbResult['deactivated_user_tokens_revoked'],
                'stuck_tenants_detected' => $stuckTenantsDetected,
                'stuck_tenants_recovered' => $stuckTenantsRecovered,
            ];

            $this->auditService->record(
                eventType: AuditEventType::SystemReconciliationExecuted,
                userId: null,
                actorId: BlameContext::SYSTEM_ACTOR_ID,
                sessionId: null,
                metadata: [
                    'action' => 'system:reconcile',
                    'summary' => $summary,
                ],
            );

            return $summary;
        } catch (Throwable $e) {
            throw $e;
        } finally {
            BlameContext::clear();
        }
    }
}
