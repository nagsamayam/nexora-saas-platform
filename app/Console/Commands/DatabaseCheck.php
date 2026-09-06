<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('app:database-check')]
#[Description('Verify the application database connection')]
class DatabaseCheck extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $connection = DB::connection();

            $database = $connection->getDatabaseName();
            $driver = $connection->getDriverName();

            $version = $connection
                ->selectOne('SELECT version() AS version')
                ->version;

            $this->info('Database connection: OK');
            $this->line("Driver: {$driver}");
            $this->line("Database: {$database}");
            $this->line("Version: {$version}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Database connection: FAILED');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
