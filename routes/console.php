<?php

use App\Models\Click;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schedule;

// In AWS, EventBridge Scheduler invokes `php artisan schedule:run` every minute
// on the console Lambda: this file replaces the legacy crontab. Both tasks are
// idempotent, so an occasional duplicate run is harmless and no lock is needed.

$rollupEvery = Config::integer('shortlinks.stats.rollup_interval_minutes');

Schedule::command('stats:rollup')
    ->cron("*/{$rollupEvery} * * * *");

Schedule::command('model:prune', ['--model' => [Click::class]])
    ->dailyAt('03:15');
