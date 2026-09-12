<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expire stale QR / PIN device-login challenges + prune old ones.
Schedule::call(function () {
    \App\Models\DeviceLoginToken::where('status', 'pending')
        ->where('expires_at', '<', now())
        ->update(['status' => 'expired']);
    \App\Models\DeviceLoginToken::where('created_at', '<', now()->subDays(7))->delete();
})->everyFiveMinutes()->name('device-login:prune')->withoutOverlapping();

// WA Cloud (Meta Cloud API) time-based automation rules
Schedule::command('wa-cloud:run-automations')->everyFifteenMinutes()->withoutOverlapping();

// Subscriptions — expire trials + lapsed paid plans past their grace window
Schedule::command('subscriptions:sweep')->dailyAt('00:30')->withoutOverlapping();

// Lead Assignment
Schedule::command('leads:check-sla')->everyMinute();
// Catches leads that never went through the routing engine (manual/CSV-created) — sweeps
// "Unassigned" and routes them via the same round-robin / weighted engine as any other lead.
Schedule::command('leads:process-unassigned')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('leads:process-handoffs')->everyFiveMinutes();
Schedule::command('leads:update-performance')->dailyAt('01:00');
Schedule::command('leads:reset-daily-counts')->dailyAt('00:00');
