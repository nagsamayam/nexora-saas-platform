<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Throwable;

#[Signature('app:redis-check')]
#[Description('Verify the Redis connection')]
class RedisCheck extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $redis = Redis::connection('default');

            $redis->ping();

            $this->info('Redis connection: OK');
            $this->line('Host: '.config('database.redis.default.host'));
            $this->line('Port: '.config('database.redis.default.port'));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Redis connection: FAILED');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
