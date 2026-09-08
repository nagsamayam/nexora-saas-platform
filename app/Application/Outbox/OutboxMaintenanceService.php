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
        $dryRun = $options['dry_run'] ?? false;

        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $publishedThreshold = $now->subDays($publishedDays);
        $failedThreshold = $now->subDays($failedDays);

        BlameContext::setActorId(BlameContext::SYSTEM_ACTOR_ID);

        try {
            if ($dryRun) {
                $prunedPublished = OutboxMessage::query()
                    ->where('status', OutboxStatus::Published->value)
                    ->where('created_at', '<=', $publishedThreshold)
                    ->count();

                $prunedFailed = OutboxMessage::query()
                    ->where('status', OutboxStatus::Failed->value)
                    ->where('created_at', '<=', $failedThreshold)
                    ->count();

                return [
                    'pruned_published' => $prunedPublished,
                    'pruned_failed' => $prunedFailed,
                ];
            }

            /** @var array{pruned_published: int, pruned_failed: int} $result */
            $result = DB::transaction(function () use ($publishedThreshold, $failedThreshold): array {
                $prunedPublished = OutboxMessage::query()
                    ->where('status', OutboxStatus::Published->value)
                    ->where('created_at', '<=', $publishedThreshold)
                    ->delete();

                $prunedFailed = OutboxMessage::query()
                    ->where('status', OutboxStatus::Failed->value)
                    ->where('created_at', '<=', $failedThreshold)
                    ->delete();

                return [
                    'pruned_published' => $prunedPublished,
                    'pruned_failed' => $prunedFailed,
                ];
            });

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
                    ],
                );
            }

            return $result;
        } catch (Throwable $e) {
            throw $e;
        } finally {
            BlameContext::clear();
        }
    }

    /**
     * Reap stuck publishing outbox messages (e.g. crashed workers) and restore to pending.
     *
     * @param  array{
     *     stuck_minutes?: int,
     *     dry_run?: bool
     * }  $options
     * @return array{
     *     reaped_messages: int
     * }
     */
    public function reap(array $options = []): array
    {
        $stuckMinutes = $options['stuck_minutes'] ?? 10;
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

            /** @var int $reapedCount */
            $reapedCount = DB::transaction(function () use ($now, $stuckThreshold): int {
                return OutboxMessage::query()
                    ->where('status', OutboxStatus::Publishing->value)
                    ->where('updated_at', '<=', $stuckThreshold)
                    ->update([
                        'status' => OutboxStatus::Pending->value,
                        'available_at' => $now,
                        'updated_at' => $now,
                    ]);
            });

            if ($reapedCount > 0) {
                $this->auditService->record(
                    eventType: AuditEventType::SystemReconciliationExecuted,
                    userId: null,
                    actorId: BlameContext::SYSTEM_ACTOR_ID,
                    sessionId: null,
                    metadata: [
                        'action' => 'outbox:reap',
                        'reaped_messages' => $reapedCount,
                        'stuck_minutes' => $stuckMinutes,
                    ],
                );
            }

            return [
                'reaped_messages' => $reapedCount,
            ];
        } catch (Throwable $e) {
            throw $e;
        } finally {
            BlameContext::clear();
        }
    }
}
