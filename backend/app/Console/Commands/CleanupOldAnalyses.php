<?php

namespace App\Console\Commands;

use App\Models\ConversationAnalysis;
use App\Models\LeadConversionEvent;
use Illuminate\Console\Command;

class CleanupOldAnalyses extends Command
{
    protected $signature   = 'ai:cleanup-analyses';
    protected $description = 'Delete conversation analyses older than 30 days';

    public function handle(): int
    {
        $cutoff   = now()->subDays(30);
        $analyses = ConversationAnalysis::where('created_at', '<', $cutoff)->delete();
        $events   = LeadConversionEvent::where('created_at', '<', now()->subDays(90))->delete();

        $this->info("Deleted {$analyses} old analyses and {$events} old conversion events.");
        return self::SUCCESS;
    }
}
