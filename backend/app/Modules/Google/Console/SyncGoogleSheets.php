<?php

namespace App\Modules\Google\Console;

use App\Modules\Google\GoogleSheetSyncService;
use Illuminate\Console\Command;

class SyncGoogleSheets extends Command
{
    protected $signature = 'google:sync-sheets';
    protected $description = 'Append newly captured leads to each company\'s Google Sheet (runs every 6h).';

    public function handle(GoogleSheetSyncService $svc): int
    {
        $n = $svc->syncDue();
        $this->info("Synced {$n} sheet(s).");
        return self::SUCCESS;
    }
}
