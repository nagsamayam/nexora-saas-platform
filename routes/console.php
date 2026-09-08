<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled Maintenance & System Reconciliation
Schedule::command('auth:cleanup-tokens')
    ->hourly()
    ->withoutOverlapping(15)
    ->onOneServer();

Schedule::command('outbox:reap')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('outbox:prune')
    ->daily()
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('system:reconcile')
    ->hourly()
    ->withoutOverlapping(20)
    ->onOneServer();
