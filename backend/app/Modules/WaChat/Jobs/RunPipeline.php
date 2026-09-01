<?php

namespace App\Modules\WaChat\Jobs;

use App\Modules\WaChat\Models\AiPipeline;
use App\Modules\WaChat\Services\PipelineRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class RunPipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries   = 1;
    public int $timeout = 300;

    public function __construct(
        public readonly int    $pipelineId,
        public readonly array  $triggerData = [],
        public readonly string $triggeredBy = 'system',
    ) {}

    public function handle(PipelineRunner $runner): void
    {
        $pipeline = AiPipeline::find($this->pipelineId);

        if (!$pipeline || !$pipeline->is_active) {
            Log::info("RunPipeline: pipeline #{$this->pipelineId} not found or inactive.");
            return;
        }

        $runner->run($pipeline, $this->triggerData, $this->triggeredBy);
    }
}
