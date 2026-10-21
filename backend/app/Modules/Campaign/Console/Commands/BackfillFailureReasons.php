<?php

namespace App\Modules\Campaign\Console\Commands;

use App\Models\CampaignContact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off catch-up for failures recorded before the message log stored Meta's failure reason and
 * before 130472 was treated as retryable. Dry run unless --apply is given.
 *
 *  1. Copies campaign_contacts.failed_reason onto the matching message_logs row when that row has none.
 *  2. With --retry-hours=N: failed 130472/131049 rows from the last N hours are marked retryable, so the
 *     normal wa-cloud:auto-retry-capped job re-queues them once. Older failures are never touched, so
 *     nobody is messaged about a campaign from weeks ago.
 */
class BackfillFailureReasons extends Command
{
    protected $signature   = 'wa-cloud:backfill-failures {--apply : write the changes (otherwise only counts them)} {--retry-hours=0 : also make 130472/131049 failures from the last N hours retryable}';
    protected $description = 'WA Cloud: backfill failure reasons on message logs and mark recent capped failures retryable';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $reasons = DB::table('message_logs as m')
            ->join('campaign_contacts as c', 'c.id', '=', 'm.campaign_contact_id')
            ->where('m.status', 'failed')->whereNull('m.failure_reason')->whereNotNull('c.failed_reason');
        $n = (clone $reasons)->count();
        $this->info("Message logs missing a failure reason (reason available on the campaign row): {$n}");
        if ($apply && $n) {
            DB::table('message_logs as m')
                ->join('campaign_contacts as c', 'c.id', '=', 'm.campaign_contact_id')
                ->where('m.status', 'failed')->whereNull('m.failure_reason')->whereNotNull('c.failed_reason')
                ->update(['m.failure_reason' => DB::raw('LEFT(c.failed_reason, 500)')]);
        }

        $hours = (int) $this->option('retry-hours');
        if ($hours > 0) {
            $q = CampaignContact::where('status', 'failed')->whereNull('resent_at')->whereNull('retry_after')
                ->where('updated_at', '>=', now()->subHours($hours))
                ->where(fn ($w) => $w->where('failed_reason', 'like', '%130472%')->orWhere('failed_reason', 'like', '%131049%'));
            $r = (clone $q)->count();
            $this->info("Failures from the last {$hours}h that would become retryable: {$r}");
            if ($apply && $r) {
                $q->update(['error_type' => 'frequency_capped', 'retry_after' => now()->addHours(2)]);
                $this->warn('They will be re-queued once by wa-cloud:auto-retry-capped after 2 hours.');
            }
        }

        $this->line($apply ? 'Applied.' : 'Dry run — nothing changed. Re-run with --apply to write.');
        return self::SUCCESS;
    }
}
