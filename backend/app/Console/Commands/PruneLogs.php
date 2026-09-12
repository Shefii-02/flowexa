<?php

namespace App\Console\Commands;

use App\Models\ApiRequestLog;
use App\Models\ErrorLog;
use Illuminate\Console\Command;

/**
 * Keeps api_request_logs / error_logs bounded — every API request writes a row
 * (see LogApiRequest middleware), so without pruning the table grows forever.
 */
class PruneLogs extends Command
{
    protected $signature = 'logs:prune {--api-days=30} {--error-days=90}';
    protected $description = 'Delete old rows from api_request_logs and error_logs.';

    public function handle(): int
    {
        $apiDeleted = ApiRequestLog::where('created_at', '<', now()->subDays((int) $this->option('api-days')))->delete();
        $errorDeleted = ErrorLog::where('created_at', '<', now()->subDays((int) $this->option('error-days')))->delete();

        $this->info("Pruned {$apiDeleted} api_request_logs rows and {$errorDeleted} error_logs rows.");
        return self::SUCCESS;
    }
}
