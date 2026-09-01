<?php

namespace App\Modules\WaChat\Console\Commands;

use App\Modules\WaChat\Models\AiPipeline;
use App\Modules\WaChat\Jobs\RunPipeline;
use Illuminate\Console\Command;

class ProcessFollowUps extends Command
{
    protected $signature   = 'wachat:process-followups';
    protected $description = 'Run scheduled cron-triggered pipelines.';

    public function handle(): int
    {
        $pipelines = AiPipeline::where('trigger_type', 'cron')
            ->where('is_active', true)
            ->get();

        foreach ($pipelines as $pipeline) {
            dispatch(new RunPipeline($pipeline->id, ['company_id' => $pipeline->company_id], 'cron'));
            $this->info("Dispatched pipeline #{$pipeline->id} ({$pipeline->name})");
        }

        $this->info("Total: {$pipelines->count()} pipeline(s).");
        return Command::SUCCESS;
    }
}
