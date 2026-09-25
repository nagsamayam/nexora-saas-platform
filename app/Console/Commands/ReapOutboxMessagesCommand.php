<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Outbox\OutboxMaintenanceService;
use Illuminate\Console\Command;

class ReapOutboxMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'outbox:reap
                            {--stuck-minutes=10 : Minutes an in-flight publishing outbox message is allowed before considered stuck/orphaned}
                            {--batch-size=1000 : Number of messages to recover per batch transaction}
                            {--dry-run : Simulate reaping without modifying records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recover and reset stuck in-flight publishing outbox messages back to pending status';

    /**
     * Execute the console command.
     */
    public function handle(OutboxMaintenanceService $service): int
    {
        $stuckMinutes = (int) $this->option('stuck-minutes');
        $batchSize = max(1, (int) $this->option('batch-size'));
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            'Starting outbox reaper (stuck_minutes: %d, batch_size: %d, dry_run: %s)...',
            $stuckMinutes,
            $batchSize,
            $dryRun ? 'yes' : 'no'
        ));

        $result = $service->reap([
            'stuck_minutes' => $stuckMinutes,
            'batch_size' => $batchSize,
            'dry_run' => $dryRun,
        ]);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Reaped Stuck Messages (Publishing -> Pending)', $result['reaped_messages']],
            ]
        );

        $this->info('Outbox reaper completed successfully.');

        return Command::SUCCESS;
    }
}
