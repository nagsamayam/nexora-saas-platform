<?php

declare(strict_types=1);

namespace App\Application\Outbox;

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Outbox\Enums\OutboxStatus;
use App\Domain\Outbox\Models\OutboxMessage;
use App\Infrastructure\Audit\AuditService;
use App\Infrastructure\Audit\BlameContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class OutboxMaintenanceService
{
    public function __construct(
        protected AuditService $auditService,
    ) {}

    /**
     * Prune old published and failed outbox messages.
     *
     * @param  array{
     *     published_retention_days?: int,
     *     failed_retention_days?: int,
     *     batch_size?: int,
     *     dry_run?: bool
     * }  $options
     * @return array{
     *     pruned_published: int,
     *     pruned_failed: int
     * }
     */
    public function prune(array $options = []): array
    {
        $publishedDays = $options['published_retention_days'] ?? 7;
        $failedDays = $options['failed_retention_days'] ?? 30;
        $batchSize = max(1, (int) ($options['batch_size'] ?? 1000));
        $dryRun = $options['dry_run'] ?? false;

        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $publishedThreshold = $now->subDays($publishedDays);
        $failedThreshold = $now->subDays($failedDays);

        BlameContext::setActorId(BlameContext::SYSTEM_ACTOR_ID);

        try {
            if ($dryRun) {
                $prunedPublished = OutboxMessage::query()
                    ->where('status', OutboxStatus::Published->value)
                    ->where(function ($query) use ($publishedThreshold): void {
                        $query->where('published_at', '<=', $publishedThreshold)
                            ->orWhere(function ($q) use ($publishedThreshold): void {
                                $q->whereNull('published_at')
                                    ->where('created_at', '<=', $publishedThreshold);
                            });
                    })
                    ->count();

                $prunedFailed = OutboxMessage::query()
                    ->where('status', OutboxStatus::Failed->value)
                    ->where(function ($query) use ($failedThreshold): void {
                        $query->where('updated_at', '<=', $failedThreshold)
                            ->orWhere(function ($q) use ($failedThreshold): void {
                                $q->whereNull('updated_at')
                                    ->where('created_at', '<=', $failedThreshold);
                            });
                    })
                    ->count();

                return [
                    'pruned_published' => $prunedPublished,
                    'pruned_failed' => $prunedFailed,
                ];
            }

            // Batch deletion in short transactions to prevent long-running table locks
            $totalPrunedPublished = 0;
            do {
                $publishedIds = OutboxMessage::query()
                    ->where('status', OutboxStatus::Published->value)
                    ->where(function ($query) use ($publishedThreshold): void {
                        $query->where('published_at', '<=', $publishedThreshold)
                            ->orWhere(function ($q) use ($publishedThreshold): void {
                                $q->whereNull('published_at')
                                    ->where('created_at', '<=', $publishedThreshold);
                            });
                    })
                    ->limit($batchSize)
                    ->pluck('id');

                if ($publishedIds->isEmpty()) {
                    break;
                }

                $deletedCount = DB::transaction(function () use ($publishedIds): int {
                    return OutboxMessage::query()
                        ->whereIn('id', $publishedIds)
                        ->delete();
                });

                $totalPrunedPublished += $deletedCount;
            } while ($publishedIds->count() >= $batchSize);

            $totalPrunedFailed = 0;
            do {
                $failedIds = OutboxMessage::query()
                    ->where('status', OutboxStatus::Failed->value)
                    ->where(function ($query) use ($failedThreshold): void {
                        $query->where('updated_at', '<=', $failedThreshold)
                            ->orWhere(function ($q) use ($failedThreshold): void {
                                $q->whereNull('updated_at')
                                    ->where('created_at', '<=', $failedThreshold);
                            });
                    })
                    ->limit($batchSize)
                    ->pluck('id');

                if ($failedIds->isEmpty()) {
                    break;
                }

                $deletedCount = DB::transaction(function () use ($failedIds): int {
                    return OutboxMessage::query()
                        ->whereIn('id', $failedIds)
                        ->delete();
                });

                $totalPrunedFailed += $deletedCount;
            } while ($failedIds->count() >= $batchSize);

            $result = [
                'pruned_published' => $totalPrunedPublished,
                'pruned_failed' => $totalPrunedFailed,
            ];

            if ($result['pruned_published'] > 0 || $result['pruned_failed'] > 0) {
                $this->auditService->record(
                    eventType: AuditEventType::SystemCleanupExecuted,
                    userId: null,
                    actorId: BlameContext::SYSTEM_ACTOR_ID,
                    sessionId: null,
                    metadata: [
                        'action' => 'outbox:prune',
                        'pruned_published' => $result['pruned_published'],
                        'pruned_failed' => $result['pruned_failed'],
                        'published_retention_days' => $publishedDays,
                        'failed_retention_days' => $failedDays,
                        'batch_size' => $batchSize,
                    ],
                );
            }

            return $result;
        } finally {
            BlameContext::clear();
        }
    }

    /**
     * Reap stuck publishing outbox messages (e.g. crashed workers) and restore to pending.
     *
     * @param  array{
     *     stuck_minutes?: int,
     *     batch_size?: int,
     *     dry_run?: bool
     * }  $options
     * @return array{
     *     reaped_messages: int
     * }
     */
    public function reap(array $options = []): array
    {
        $stuckMinutes = $options['stuck_minutes'] ?? 10;
        $batchSize = max(1, (int) ($options['batch_size'] ?? 1000));
        $dryRun = $options['dry_run'] ?? false;

        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $stuckThreshold = $now->subMinutes($stuckMinutes);

        BlameContext::setActorId(BlameContext::SYSTEM_ACTOR_ID);

        try {
            if ($dryRun) {
                $reapedCount = OutboxMessage::query()
                    ->where('status', OutboxStatus::Publishing->value)
                    ->where('updated_at', '<=', $stuckThreshold)
                    ->count();

                return [
                    'reaped_messages' => $reapedCount,
                ];
            }

            // Batch updates in short transactions to prevent long-running table locks
            $totalReaped = 0;
            do {
                $stuckIds = OutboxMessage::query()
                    ->where('status', OutboxStatus::Publishing->value)
                    ->where('updated_at', '<=', $stuckThreshold)
                    ->limit($batchSize)
                    ->pluck('id');

                if ($stuckIds->isEmpty()) {
                    break;
                }

                $updated = DB::transaction(function () use ($stuckIds, $now): int {
                    return OutboxMessage::query()
                        ->whereIn('id', $stuckIds)
                        ->update([
                            'status' => OutboxStatus::Pending->value,
                            'available_at' => $now,
                            'updated_at' => $now,
                        ]);
                });

                $totalReaped += $updated;
            } while ($stuckIds->count() >= $batchSize);

            if ($totalReaped > 0) {
                $this->auditService->record(
                    eventType: AuditEventType::SystemReconciliationExecuted,
                    userId: null,
                    actorId: BlameContext::SYSTEM_ACTOR_ID,
                    sessionId: null,
                    metadata: [
                        'action' => 'outbox:reap',
                        'reaped_messages' => $totalReaped,
                        'stuck_minutes' => $stuckMinutes,
                        'batch_size' => $batchSize,
                    ],
                );
            }

            return [
                'reaped_messages' => $totalReaped,
            ];
        } finally {
            BlameContext::clear();
        }
    }
}
