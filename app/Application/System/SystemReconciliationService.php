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
     *     batch_size?: int,
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
        $batchSize = max(1, (int) ($options['batch_size'] ?? 1000));
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
                    ->where(function ($query) use ($provisioningThreshold): void {
                        $query->where('provisioning_started_at', '<=', $provisioningThreshold)
                            ->orWhere(function ($q) use ($provisioningThreshold): void {
                                $q->whereNull('provisioning_started_at')
                                    ->where('updated_at', '<=', $provisioningThreshold);
                            });
                    })
                    ->count();

                return [
                    'inconsistent_tokens_revoked' => $inconsistentTokensCount,
                    'deactivated_user_sessions_revoked' => $deactivatedSessionsCount,
                    'deactivated_user_tokens_revoked' => $deactivatedTokensCount,
                    'stuck_tenants_detected' => $stuckTenantsCount,
                    'stuck_tenants_recovered' => 0,
                ];
            }

            // 1. Revoke active tokens attached to inactive sessions in bounded batches
            $totalInconsistentTokensRevoked = 0;
            do {
                $inconsistentTokenIds = AuthRefreshToken::query()
                    ->where('status', RefreshTokenStatus::Active->value)
                    ->whereHas('session', function ($query): void {
                        $query->whereIn('status', [
                            SessionStatus::Revoked->value,
                            SessionStatus::Expired->value,
                        ]);
                    })
                    ->limit($batchSize)
                    ->pluck('id');

                if ($inconsistentTokenIds->isEmpty()) {
                    break;
                }

                $revokedCount = DB::transaction(function () use ($inconsistentTokenIds, $now): int {
                    return AuthRefreshToken::query()
                        ->whereIn('id', $inconsistentTokenIds)
                        ->update([
                            'status' => RefreshTokenStatus::Revoked->value,
                            'revoked_at' => $now,
                            'updated_at' => $now,
                        ]);
                });

                $totalInconsistentTokensRevoked += $revokedCount;
            } while ($inconsistentTokenIds->count() >= $batchSize);

            // 2. Revoke active sessions & tokens for suspended/disabled users in bounded batches
            $totalDeactivatedSessionsRevoked = 0;
            $totalDeactivatedTokensRevoked = 0;

            $deactivatedUserIds = User::query()
                ->whereIn('status', [
                    UserStatus::Suspended->value,
                    UserStatus::Disabled->value,
                ])
                ->pluck('id');

            if ($deactivatedUserIds->isNotEmpty()) {
                // Batch revoke tokens for deactivated users
                do {
                    $deactivatedTokenIds = AuthRefreshToken::query()
                        ->where('status', RefreshTokenStatus::Active->value)
                        ->whereHas('session', function ($query) use ($deactivatedUserIds): void {
                            $query->whereIn('user_id', $deactivatedUserIds);
                        })
                        ->limit($batchSize)
                        ->pluck('id');

                    if ($deactivatedTokenIds->isEmpty()) {
                        break;
                    }

                    $revokedTokensCount = DB::transaction(function () use ($deactivatedTokenIds, $now): int {
                        return AuthRefreshToken::query()
                            ->whereIn('id', $deactivatedTokenIds)
                            ->update([
                                'status' => RefreshTokenStatus::Revoked->value,
                                'revoked_at' => $now,
                                'updated_at' => $now,
                            ]);
                    });

                    $totalDeactivatedTokensRevoked += $revokedTokensCount;
                } while ($deactivatedTokenIds->count() >= $batchSize);

                // Batch revoke sessions for deactivated users
                do {
                    $deactivatedSessionIds = AuthSession::query()
                        ->whereIn('user_id', $deactivatedUserIds)
                        ->where('status', SessionStatus::Active->value)
                        ->limit($batchSize)
                        ->pluck('id');

                    if ($deactivatedSessionIds->isEmpty()) {
                        break;
                    }

                    $revokedSessionsCount = DB::transaction(function () use ($deactivatedSessionIds, $now): int {
                        return AuthSession::query()
                            ->whereIn('id', $deactivatedSessionIds)
                            ->update([
                                'status' => SessionStatus::Revoked->value,
                                'revoked_at' => $now,
                                'updated_at' => $now,
                            ]);
                    });

                    $totalDeactivatedSessionsRevoked += $revokedSessionsCount;
                } while ($deactivatedSessionIds->count() >= $batchSize);
            }

            // 3. Reconcile stuck provisioning tenants in batches
            $stuckTenantsDetected = 0;
            $stuckTenantsRecovered = 0;

            Tenant::query()
                ->where('status', TenantStatus::Provisioning->value)
                ->where(function ($query) use ($provisioningThreshold): void {
                    $query->where('provisioning_started_at', '<=', $provisioningThreshold)
                        ->orWhere(function ($q) use ($provisioningThreshold): void {
                            $q->whereNull('provisioning_started_at')
                                ->where('updated_at', '<=', $provisioningThreshold);
                        });
                })
                ->chunkById($batchSize, function ($stuckTenants) use ($autoRecoverTenants, &$stuckTenantsDetected, &$stuckTenantsRecovered): void {
                    $stuckTenantsDetected += $stuckTenants->count();

                    if ($autoRecoverTenants) {
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
                });

            $summary = [
                'inconsistent_tokens_revoked' => $totalInconsistentTokensRevoked,
                'deactivated_user_sessions_revoked' => $totalDeactivatedSessionsRevoked,
                'deactivated_user_tokens_revoked' => $totalDeactivatedTokensRevoked,
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
