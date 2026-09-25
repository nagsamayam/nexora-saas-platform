<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Auth\CleanupExpiredTokensService;
use Illuminate\Console\Command;

class CleanupExpiredTokensCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auth:cleanup-tokens
                            {--prune-days=30 : Number of days to retain revoked, expired, and consumed tokens before deletion}
                            {--dry-run : Simulate cleanup without deleting or modifying database records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire active past-due refresh tokens/sessions and prune old terminal authentication records';

    /**
     * Execute the console command.
     */
    public function handle(CleanupExpiredTokensService $service): int
    {
        $pruneDays = (int) $this->option('prune-days');
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            'Starting token & session cleanup (prune_days: %d, dry_run: %s)...',
            $pruneDays,
            $dryRun ? 'yes' : 'no'
        ));

        $result = $service->execute([
            'prune_days' => $pruneDays,
            'dry_run' => $dryRun,
        ]);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Expired Tokens (Active -> Expired)', $result['expired_tokens']],
                ['Expired Sessions (Active -> Expired)', $result['expired_sessions']],
                ['Pruned Tokens (Deleted)', $result['pruned_tokens']],
                ['Pruned Sessions (Deleted)', $result['pruned_sessions']],
            ]
        );

        $this->info('Token & session cleanup completed successfully.');

        return Command::SUCCESS;
    }
}
