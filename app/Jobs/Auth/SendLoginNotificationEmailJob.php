<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

use App\Infrastructure\Audit\BlameContext;
use App\Mail\Auth\LoginNotificationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendLoginNotificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly string $aggregateId,
        public readonly array $payload,
        public readonly array $headers = [],
        public readonly ?string $idempotencyKey = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $idempotencyKey = $this->idempotencyKey ?? sprintf('idempotency:job:%s:%s', $this->eventType, $this->eventId);

        // Check if job was already processed idempotently
        if (! Cache::add($idempotencyKey, 'processing', 86400)) {
            $status = Cache::get($idempotencyKey);
            if ($status === 'completed' || $status === 'processing') {
                Log::info('Duplicate job execution skipped for SendLoginNotificationEmailJob', [
                    'event_id' => $this->eventId,
                    'idempotency_key' => $idempotencyKey,
                ]);

                return;
            }
        }

        // Rehydrate BlameContext from headers
        BlameContext::setContext([
            'correlation_id' => is_string($this->headers['correlation_id'] ?? null) ? $this->headers['correlation_id'] : null,
            'actor_id' => is_string($this->headers['actor_id'] ?? null) ? $this->headers['actor_id'] : null,
            'user_id' => is_string($this->headers['user_id'] ?? null) ? $this->headers['user_id'] : $this->aggregateId,
            'session_id' => is_string($this->headers['session_id'] ?? null) ? $this->headers['session_id'] : null,
            'ip_address' => is_string($this->headers['ip_address'] ?? null) ? $this->headers['ip_address'] : null,
            'user_agent' => is_string($this->headers['user_agent'] ?? null) ? $this->headers['user_agent'] : null,
        ]);

        try {
            $email = (string) ($this->payload['email'] ?? '');
            $deviceName = isset($this->payload['device_name']) ? (string) $this->payload['device_name'] : null;
            $ipAddress = isset($this->payload['ip_address']) ? (string) $this->payload['ip_address'] : null;
            $loginTime = isset($this->payload['login_time']) ? (string) $this->payload['login_time'] : null;

            if ($email !== '') {
                Mail::to($email)->send(new LoginNotificationMail(
                    userEmail: $email,
                    deviceName: $deviceName,
                    ipAddress: $ipAddress,
                    loginTime: $loginTime,
                ));
            }

            Cache::put($idempotencyKey, 'completed', 86400 * 7);

            Log::info('Login notification email successfully processed and sent', [
                'event_id' => $this->eventId,
                'user_id' => $this->aggregateId,
                'email' => $email,
            ]);
        } catch (Throwable $exception) {
            Cache::forget($idempotencyKey);
            Log::error('Failed to send login notification email', [
                'event_id' => $this->eventId,
                'user_id' => $this->aggregateId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            BlameContext::clear();
        }
    }
}
