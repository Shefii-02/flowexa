<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\LeadAssignmentRule;
use App\Services\LeadAssignment\ScheduledAutoAssigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Auto-assign cron. Runs every minute and, for each company whose auto-assign is on and whose run
 * is due (its own interval + optional daily run window), routes every unassigned open lead through
 * the assignment engine — Category & campaign routing rules first, then round robin / weighted.
 * "Instant" companies only get a 5-minute safety-net sweep; "scheduled" ones are routed only here.
 * Skips a lead that already has an assignment in flight so it never double-notifies staff.
 */
class ProcessUnassignedLeads extends Command
{
    protected $signature   = 'leads:process-unassigned';
    protected $description = 'Route unassigned CRM leads for every company whose auto-assign run is due';

    public function handle(ScheduledAutoAssigner $runner): int
    {
        $total = 0; $companies = 0;
        foreach (LeadAssignmentRule::where('auto_assign_enabled', true)->get() as $rule) {
            if (!$runner->isDue($rule)) {
                continue;
            }
            $company = Company::find($rule->company_id);
            if (!$company) {
                continue;
            }
            try {
                $r = $runner->runForCompany($company);
                $total += $r['routed']; $companies++;
            } catch (\Throwable $e) {
                Log::warning('leads:process-unassigned failed', ['company_id' => $rule->company_id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Routed {$total} lead(s) for {$companies} compan" . ($companies === 1 ? 'y' : 'ies') . '.');
        return self::SUCCESS;
    }
}
