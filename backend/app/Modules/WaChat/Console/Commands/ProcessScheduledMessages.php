<?php

namespace App\Modules\WaChat\Console\Commands;

use App\Modules\WaChat\Models\MessageSenderJob;
use App\Modules\WaChat\Jobs\ProcessMessageSenderJob;
use Illuminate\Console\Command;

class ProcessScheduledMessages extends Command
{
    protected $signature   = 'wa-chat:dispatch-scheduled-messages';
    // Old name kept so an existing server cron / supervisor entry keeps working.
    protected $aliases     = ['wachat:process-scheduled-messages'];
    protected $description = 'WA Chat (open-wa): send Message Sender campaigns whose scheduled time has arrived.';

    public function handle(): int
    {
        $due = MessageSenderJob::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $job) {
            $job->update(['status' => 'pending', 'started_at' => now()]);
            dispatch(new ProcessMessageSenderJob($job->id));
            $this->info("Dispatched job #{$job->id} ({$job->campaign_name})");
        }

        $this->info("Processed {$due->count()} scheduled job(s).");
        return Command::SUCCESS;
    }
}
