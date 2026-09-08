<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Auth\Enums\RefreshTokenStatus;
use App\Domain\Auth\Enums\SessionStatus;
use App\Domain\Auth\Models\AuthRefreshToken;
use App\Domain\Auth\Models\AuthSession;
use App\Infrastructure\Audit\AuditService;
use App\Infrastructure\Audit\BlameContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class CleanupExpiredTokensService
{
    public function __construct(
        protected AuditService $auditService,
    ) {}

    /**
     * @param  array{
     *     prune_days?: int,
     *     dry_run?: bool
     * }  $options
     * @return array{
     *     expired_tokens: int,
     *     expired_sessions: int,
     *     pruned_tokens: int,
     *     pruned_sessions: int
     * }
     */
    public function execute(array $options = []): array
    {
        $pruneDays = $options['prune_days'] ?? 30;
        $dryRun = $options['dry_run'] ?? false;
        $now = CarbonImmutable::now('UTC');
        $pruneThreshold = $now->subDays($pruneDays);

        BlameContext::setActorId(BlameContext::SYSTEM_ACTOR_ID);

        try {
            if ($dryRun) {
                $expiredTokensCount = AuthRefreshToken::query()
                    ->where('status', RefreshTokenStatus::Active->value)
                    ->where('expires_at', '<=', $now)
                    ->count();

                $expiredSessionsCount = AuthSession::query()
                    ->where('status', SessionStatus::Active->value)
                    ->where('expires_at', '<=', $now)
                    ->count();

                $prunedTokensCount = AuthRefreshToken::query()
                    ->whereIn('status', [
                        RefreshTokenStatus::Expired->value,
                        RefreshTokenStatus::Revoked->value,
                        RefreshTokenStatus::Consumed->value,
                    ])
                    ->where('updated_at', '<=', $pruneThreshold)
                    ->count();

                $prunedSessionsCount = AuthSession::query()
                    ->whereIn('status', [
                        SessionStatus::Expired->value,
                        SessionStatus::Revoked->value,
                    ])
                    ->where('updated_at', '<=', $pruneThreshold)
                    ->count();

                return [
                    'expired_tokens' => $expiredTokensCount,
                    'expired_sessions' => $expiredSessionsCount,
                    'pruned_tokens' => $prunedTokensCount,
                    'pruned_sessions' => $prunedSessionsCount,
                ];
            }

            /** @var array{expired_tokens: int, expired_sessions: int, pruned_tokens: int, pruned_sessions: int} $result */
            $result = DB::transaction(function () use ($now, $pruneThreshold): array {
                // 1. Transition active expired refresh tokens to Expired
                $expiredTokens = AuthRefreshToken::query()
                    ->where('status', RefreshTokenStatus::Active->value)
                    ->where('expires_at', '<=', $now)
                    ->update([
                        'status' => RefreshTokenStatus::Expired->value,
                        'updated_at' => $now,
                    ]);

                // 2. Transition active expired sessions to Expired
                $expiredSessions = AuthSession::query()
                    ->where('status', SessionStatus::Active->value)
                    ->where('expires_at', '<=', $now)
                    ->update([
                        'status' => SessionStatus::Expired->value,
                        'updated_at' => $now,
                    ]);

                // 3. Prune old terminal-state refresh tokens
                $prunedTokens = AuthRefreshToken::query()
                    ->whereIn('status', [
                        RefreshTokenStatus::Expired->value,
                        RefreshTokenStatus::Revoked->value,
                        RefreshTokenStatus::Consumed->value,
                    ])
                    ->where('updated_at', '<=', $pruneThreshold)
                    ->delete();

                // 4. Prune old terminal-state sessions
                $prunedSessions = AuthSession::query()
                    ->whereIn('status', [
                        SessionStatus::Expired->value,
                        SessionStatus::Revoked->value,
                    ])
                    ->where('updated_at', '<=', $pruneThreshold)
                    ->delete();

                return [
                    'expired_tokens' => $expiredTokens,
                    'expired_sessions' => $expiredSessions,
                    'pruned_tokens' => $prunedTokens,
                    'pruned_sessions' => $prunedSessions,
                ];
            });

            if ($result['expired_tokens'] > 0 || $result['expired_sessions'] > 0 || $result['pruned_tokens'] > 0 || $result['pruned_sessions'] > 0) {
                $this->auditService->record(
                    eventType: AuditEventType::SystemCleanupExecuted,
                    userId: null,
                    actorId: BlameContext::SYSTEM_ACTOR_ID,
                    sessionId: null,
                    metadata: [
                        'action' => 'auth:cleanup_tokens',
                        'expired_tokens' => $result['expired_tokens'],
                        'expired_sessions' => $result['expired_sessions'],
                        'pruned_tokens' => $result['pruned_tokens'],
                        'pruned_sessions' => $result['pruned_sessions'],
                        'prune_retention_days' => $pruneDays,
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
}
