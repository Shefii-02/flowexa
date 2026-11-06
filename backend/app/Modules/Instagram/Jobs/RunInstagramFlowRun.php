<?php

namespace App\Modules\Instagram\Jobs;

use App\Modules\Instagram\Flow\InstagramFlowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Timer for a parked flow run: a delay elapsing, a wait timing out, or a rate-limit back-off ending. */
class RunInstagramFlowRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** @param 'timeout'|'resume'|'retry' $action */
    public function __construct(public int $runId, public int $token, public string $action)
    {
        $this->onQueue('default');
    }

    public function handle(InstagramFlowEngine $engine): void
    {
        $engine->handleScheduled($this->runId, $this->token, $this->action);
    }
}
