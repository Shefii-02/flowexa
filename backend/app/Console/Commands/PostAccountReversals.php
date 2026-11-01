<?php

namespace App\Console\Commands;

use App\Modules\Accounts\Support\JournalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PostAccountReversals extends Command
{
    protected $signature = 'accounts:post-reversals';
    protected $description = 'Post auto-reversing adjusting entries (accruals) that have come due';

    public function handle(JournalService $journals): int
    {
        $n = 0;
        foreach (DB::table('acc_vouchers')->whereNotNull('reverse_on')->where('reverse_on', '<=', now()->toDateString())->distinct()->pluck('company_id') as $cid) {
            $n += $journals->postDueReversals((int) $cid);
        }
        $this->info("Posted {$n} reversal(s).");
        return self::SUCCESS;
    }
}
