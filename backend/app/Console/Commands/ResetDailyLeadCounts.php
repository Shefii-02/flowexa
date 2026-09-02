<?php

namespace App\Console\Commands;

use App\Models\StaffAvailability;
use Illuminate\Console\Command;

class ResetDailyLeadCounts extends Command
{
    protected $signature   = 'leads:reset-daily-counts';
    protected $description = 'Reset today_leads_count and today_conversions at midnight';

    public function handle(): int
    {
        StaffAvailability::query()->update([
            'today_leads_count' => 0,
            'today_conversions'  => 0,
        ]);

        $this->info('Daily lead counts reset.');
        return self::SUCCESS;
    }
}
