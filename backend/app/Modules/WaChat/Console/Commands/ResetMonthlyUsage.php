<?php

namespace App\Modules\WaChat\Console\Commands;

use App\Services\CompanyApiKeyResolver;
use Illuminate\Console\Command;

class ResetMonthlyUsage extends Command
{
    protected $signature   = 'ai:reset-monthly-tokens';
    protected $description = 'Reset monthly AI token usage counters for all company API keys';

    public function handle(): int
    {
        CompanyApiKeyResolver::resetMonthlyUsage();
        $this->info('Monthly AI usage counters reset successfully.');
        return self::SUCCESS;
    }
}
