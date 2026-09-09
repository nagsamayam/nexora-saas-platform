<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Outbox\Enums\OutboxEventType;
use App\Domain\Outbox\Enums\OutboxStatus;
use App\Domain\Outbox\Models\OutboxMessage;
use App\Jobs\Auth\SendLoginNotificationEmailJob;
use App\Jobs\Auth\SendRegistrationEmailJob;
use App\Jobs\Tenancy\SendTenantApprovedEmailJob;
use App\Jobs\Tenancy\SendTenantProvisionedEmailJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishOutboxMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'outbox:publish {--batch-size=50} {--max-attempts=5}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process and publish pending outbox messages to the message broker/queue';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $batchSize = (int) $this->option('batch-size');
        $maxAttempts = (int) $this->option('max-attempts');

        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));

        /** @var list<OutboxMessage> $messages */
        $messages = DB::transaction(function () use ($batchSize, $now): array {
            $records = OutboxMessage::where('status', OutboxStatus::Pending->value)
                ->where('available_at', '<=', $now)
                ->orderBy('created_at', 'asc')
                ->limit($batchSize)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            foreach ($records as $message) {
                $message->update([
                    'status' => OutboxStatus::Publishing,
                    'attempts' => $message->attempts + 1,
                ]);
            }

            return $records->all();
        });

        if ($messages === []) {
            $this->info('No pending outbox messages to publish.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Publishing %d outbox message(s)...', count($messages)));

        foreach ($messages as $message) {
            $this->publishMessage($message, $maxAttempts);
        }

        return self::SUCCESS;
    }

    private function publishMessage(OutboxMessage $message, int $maxAttempts): void
    {
        try {
            $this->dispatchQueueJob($message);

            $message->update([
                'status' => OutboxStatus::Published,
                'published_at' => CarbonImmutable::now((string) config('app.timezone', 'UTC')),
                'last_error' => null,
            ]);

            Log::info('Outbox message successfully published', [
                'id' => $message->id,
                'event_type' => $message->event_type,
            ]);
        } catch (Throwable $e) {
            $isMaxExceeded = $message->attempts >= $maxAttempts;
            $nextStatus = $isMaxExceeded ? OutboxStatus::Failed : OutboxStatus::Pending;

            // Exponential backoff: 2^(attempts) * 30 seconds
            $delaySeconds = (int) min(3600, (2 ** $message->attempts) * 30);
            $nextAvailableAt = CarbonImmutable::now((string) config('app.timezone', 'UTC'))->addSeconds($delaySeconds);

            $message->update([
                'status' => $nextStatus,
                'available_at' => $nextAvailableAt,
                'last_error' => sprintf('%s: %s', get_class($e), $e->getMessage()),
            ]);

            Log::error('Failed to publish outbox message', [
                'id' => $message->id,
                'event_type' => $message->event_type,
                'attempts' => $message->attempts,
                'status' => $nextStatus->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dispatchQueueJob(OutboxMessage $message): void
    {
        $eventType = $message->event_type;
        $headers = $message->headers ?? [];
        $payload = $message->payload;
        $aggregateId = $message->aggregate_id;
        $eventId = is_string($headers['event_id'] ?? null) ? $headers['event_id'] : $message->id;

        if ($eventType === OutboxEventType::UserRegistered->value) {
            SendRegistrationEmailJob::dispatch(
                eventId: $eventId,
                eventType: $eventType,
                aggregateId: $aggregateId,
                payload: $payload,
                headers: $headers,
                idempotencyKey: $message->event_key,
            );
        } elseif ($eventType === OutboxEventType::UserLoggedIn->value) {
            SendLoginNotificationEmailJob::dispatch(
                eventId: $eventId,
                eventType: $eventType,
                aggregateId: $aggregateId,
                payload: $payload,
                headers: $headers,
                idempotencyKey: $message->event_key,
            );
        } elseif ($eventType === OutboxEventType::TenantApproved->value) {
            SendTenantApprovedEmailJob::dispatch(
                eventId: $eventId,
                eventType: $eventType,
                aggregateId: $aggregateId,
                payload: $payload,
                headers: $headers,
                idempotencyKey: $message->event_key,
            );
        } elseif ($eventType === OutboxEventType::TenantProvisioned->value) {
            SendTenantProvisionedEmailJob::dispatch(
                eventId: $eventId,
                eventType: $eventType,
                aggregateId: $aggregateId,
                payload: $payload,
                headers: $headers,
                idempotencyKey: $message->event_key,
            );
        } else {
            Log::warning('Unhandled outbox event type encountered', [
                'id' => $message->id,
                'event_type' => $eventType,
            ]);
        }
    }
}
