<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// appendOutputTo (not the cron's own `>> file`) so the proof-of-life log is versioned with
// the schedule itself and does not depend on how the crontab line was written on each host.
// Absence of a fresh line here is the signal: it means the server cron never fired.
Schedule::command('alerts:dispatch-due --no-ansi')
    ->everyMinute()
    ->appendOutputTo(storage_path('logs/scheduler.log'));
