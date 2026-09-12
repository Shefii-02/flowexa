<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Catches leads that never went through the routing engine at all — created manually, imported
 * via CSV, or left behind by a past failure — and routes them through the same round-robin /
 * weighted-algorithm engine every other lead gets, so nothing sits in "Unassigned" forever.
 * Skips a lead that already has an assignment in flight (pending/notified/assigned/accepted/
 * ai_handling/transferred) so it never double-notifies staff.
 */
class ProcessUnassignedLeads extends Command
{
    protected $signature   = 'leads:process-unassigned';
    protected $description = 'Route any CRM lead with no owner and no assignment in flight through the assignment engine';

    public function handle(\App\Services\LeadAssignment\LeadAssignmentEngine $engine): int
    {
        $leads = Lead::whereNull('assigned_to')
            ->whereNotIn('stage', ['enrolled', 'lost'])
            ->whereDoesntHave('assignments', fn ($q) => $q->whereNotIn('status', ['dropped']))
            ->with('contact', 'company')
            ->limit(200)
            ->get();

        $routed = 0;
        foreach ($leads as $lead) {
            if (!$lead->contact || !$lead->company) {
                continue;
            }
            try {
                $engine->assign($lead->company, $lead->contact, $lead->source ?: 'organic', $lead->campaign_id, null, 'auto', $lead);
                $routed++;
            } catch (\Throwable $e) {
                Log::warning('leads:process-unassigned failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Routed {$routed} of {$leads->count()} unassigned lead(s).");
        return self::SUCCESS;
    }
}
