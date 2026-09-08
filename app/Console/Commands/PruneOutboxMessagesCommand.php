<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Outbox\OutboxMaintenanceService;
use Illuminate\Console\Command;

class PruneOutboxMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'outbox:prune
                            {--published-days=7 : Number of days to retain published outbox messages before pruning}
                            {--failed-days=30 : Number of days to retain failed outbox messages before pruning}
                            {--dry-run : Simulate pruning without deleting records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune old published and dead-letter outbox messages';

    /**
     * Execute the console command.
     */
    public function handle(OutboxMaintenanceService $service): int
    {
        $publishedDays = (int) $this->option('published-days');
        $failedDays = (int) $this->option('failed-days');
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            'Starting outbox pruning (published_days: %d, failed_days: %d, dry_run: %s)...',
            $publishedDays,
            $failedDays,
            $dryRun ? 'yes' : 'no'
        ));

        $result = $service->prune([
            'published_retention_days' => $publishedDays,
            'failed_retention_days' => $failedDays,
            'dry_run' => $dryRun,
        ]);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Pruned Published Messages', $result['pruned_published']],
                ['Pruned Failed Messages', $result['pruned_failed']],
            ]
        );

        $this->info('Outbox pruning completed successfully.');

        return Command::SUCCESS;
    }
}
