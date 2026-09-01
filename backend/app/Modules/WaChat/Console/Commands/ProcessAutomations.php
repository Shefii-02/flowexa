<?php

namespace App\Modules\WaChat\Console\Commands;

use App\Modules\WaChat\Services\AutomationEngine;
use Illuminate\Console\Command;

class ProcessAutomations extends Command
{
    protected $signature   = 'wachat:process-automations';
    protected $description = 'Process follow-up queue and inactivity triggers.';

    public function handle(AutomationEngine $engine): int
    {
        $this->info('Processing follow-up queue...');
        $engine->processFollowUpQueue();

        $this->info('Processing inactivity triggers...');
        $engine->processInactivityRules();

        $this->info('Done.');
        return Command::SUCCESS;
    }
}
