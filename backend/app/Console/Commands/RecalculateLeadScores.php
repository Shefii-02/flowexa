<?php

namespace App\Console\Commands;

use App\Models\MetaAiConfig;
use App\Services\MetaAI\LeadScoreCalculator;
use Illuminate\Console\Command;

class RecalculateLeadScores extends Command
{
    protected $signature   = 'ai:recalculate-scores';
    protected $description = 'Recalculate lead scores for all contacts across enabled companies';

    public function handle(): int
    {
        $calc     = new LeadScoreCalculator();
        $companies = MetaAiConfig::where('is_enabled', true)->pluck('company_id');

        foreach ($companies as $companyId) {
            $calc->recalculateAll($companyId);
            $this->info("Recalculated scores for company {$companyId}");
        }

        return self::SUCCESS;
    }
}
