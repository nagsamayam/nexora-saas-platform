<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('app:cache-check')]
#[Description('Verify the application cache')]
class CacheCheck extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $key = 'health:cache-check';
            $value = 'ok';

            Cache::put($key, $value, now()->addMinutes(5));

            $result = Cache::get($key);

            if ($result !== $value) {
                $this->error('Cache verification: FAILED');

                return self::FAILURE;
            }

            Cache::forget($key);

            $this->info('Cache verification: OK');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Cache verification: FAILED');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
