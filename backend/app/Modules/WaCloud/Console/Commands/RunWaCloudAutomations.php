<?php

namespace App\Modules\WaCloud\Console\Commands;

use App\Modules\WaCloud\Services\WaCloudAutomationEngine;
use Illuminate\Console\Command;

/**
 * Processes time-driven WA Cloud automation rules (follow-up reminders,
 * follow-up agent, inactivity triggers). Message-driven rules fire inline from
 * the webhook instead. Scheduled every 15 minutes in routes/console.php.
 */
class RunWaCloudAutomations extends Command
{
    protected $signature = 'wa-cloud:run-automations';

    protected $description = 'Run time-based WA Cloud (Meta Cloud API) automation rules';

    public function handle(WaCloudAutomationEngine $engine): int
    {
        $engine->runScheduled();
        $this->info('WA Cloud automations processed.');

        return self::SUCCESS;
    }
}
