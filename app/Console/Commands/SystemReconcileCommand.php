<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\System\SystemReconciliationService;
use Illuminate\Console\Command;

class SystemReconcileCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system:reconcile
                            {--stuck-provisioning-minutes=30 : Minutes a tenant can remain in provisioning state before triggering recovery}
                            {--no-auto-recover : Skip auto-dispatching provisioning jobs for stuck tenants}
                            {--dry-run : Simulate reconciliation without modifying database state or dispatching jobs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile system anomalies across sessions, tokens, suspended users, and tenant provisioning';

    /**
     * Execute the console command.
     */
    public function handle(SystemReconciliationService $service): int
    {
        $stuckMinutes = (int) $this->option('stuck-provisioning-minutes');
        $autoRecover = ! ((bool) $this->option('no-auto-recover'));
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            'Starting system reconciliation (stuck_minutes: %d, auto_recover: %s, dry_run: %s)...',
            $stuckMinutes,
            $autoRecover ? 'yes' : 'no',
            $dryRun ? 'yes' : 'no'
        ));

        $result = $service->execute([
            'stuck_provisioning_minutes' => $stuckMinutes,
            'auto_recover_tenants' => $autoRecover,
            'dry_run' => $dryRun,
        ]);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Inconsistent Tokens Revoked', $result['inconsistent_tokens_revoked']],
                ['Deactivated User Sessions Revoked', $result['deactivated_user_sessions_revoked']],
                ['Deactivated User Tokens Revoked', $result['deactivated_user_tokens_revoked']],
                ['Stuck Tenants Detected', $result['stuck_tenants_detected']],
                ['Stuck Tenants Recovered/Dispatched', $result['stuck_tenants_recovered']],
            ]
        );

        $this->info('System reconciliation completed successfully.');

        return Command::SUCCESS;
    }
}
