<?php

namespace App\Console\Commands;

use App\Models\InstagramAccount;
use App\Models\InstagramFlowRun;
use App\Modules\Instagram\Flow\InstagramFlowEngine;
use Illuminate\Console\Command;

class DrainInstagramQueue extends Command
{
    protected $signature = 'instagram:drain-queue';
    protected $description = 'Send AutoDMs held back by the hourly cap, oldest first, as soon as budget frees up';

    public function handle(InstagramFlowEngine $engine): int
    {
        $ids = InstagramFlowRun::where('status', 'waiting')->where('wait_kind', 'throttle')->distinct()->pluck('instagram_account_id');
        InstagramAccount::whereIn('id', $ids)->where('is_active', true)->get()
            ->each(fn (InstagramAccount $a) => $engine->drainQueue($a));
        return self::SUCCESS;
    }
}
