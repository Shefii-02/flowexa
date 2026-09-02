<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// WA Chat — dispatch scheduled message jobs every minute
Schedule::command('wachat:process-scheduled-messages')->everyMinute();

// Lead Assignment
Schedule::command('leads:check-sla')->everyMinute();
Schedule::command('leads:process-handoffs')->everyFiveMinutes();
Schedule::command('leads:update-performance')->dailyAt('01:00');
Schedule::command('leads:reset-daily-counts')->dailyAt('00:00');
