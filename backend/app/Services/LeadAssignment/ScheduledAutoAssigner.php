<?php

namespace App\Services\LeadAssignment;

use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadAssignmentRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The cron side of auto-assign. Routes every open lead that has no owner and no assignment in
 * flight through the LeadAssignmentEngine — which applies the Category & campaign routing rules,
 * then the company's method (round robin / weighted) and notification mode.
 * Used by `leads:process-unassigned` (each company when its run is due) and the "Run now" button.
 */
class ScheduledAutoAssigner
{
    private const SAFETY_NET_MINUTES = 5;   // instant mode: sweep for anything that slipped through

    public function __construct(private readonly LeadAssignmentEngine $engine) {}

    /** Is this company's cron run due right now? */
    public function isDue(LeadAssignmentRule $rule, ?Carbon $now = null): bool
    {
        if (!$rule->auto_assign_enabled) {
            return false;
        }
        $now ??= Carbon::now();
        $scheduled = $rule->auto_assign_run_mode === 'scheduled';
        $interval  = $scheduled ? max(1, (int) $rule->auto_assign_interval_minutes) : self::SAFETY_NET_MINUTES;

        if ($scheduled && $rule->auto_assign_run_start && $rule->auto_assign_run_end) {
            $local = $now->copy()->setTimezone($rule->timezone ?: 'Asia/Kolkata');
            $start = $local->copy()->setTimeFromTimeString((string) $rule->auto_assign_run_start);
            $end   = $local->copy()->setTimeFromTimeString((string) $rule->auto_assign_run_end);
            $inside = $start->lte($end) ? $local->betweenIncluded($start, $end) : ($local->gte($start) || $local->lte($end));   // overnight window
            if (!$inside) {
                return false;
            }
        }

        return !$rule->auto_assign_last_run_at || $rule->auto_assign_last_run_at->copy()->addMinutes($interval)->lte($now);
    }

    /** When will the next run happen (best effort — ignores the run window)? */
    public function nextRunAt(LeadAssignmentRule $rule): ?Carbon
    {
        if (!$rule->auto_assign_enabled) {
            return null;
        }
        $interval = $rule->auto_assign_run_mode === 'scheduled' ? max(1, (int) $rule->auto_assign_interval_minutes) : self::SAFETY_NET_MINUTES;
        return ($rule->auto_assign_last_run_at ?? now())->copy()->addMinutes($interval);
    }

    /** @return array{routed:int, found:int} */
    public function runForCompany(Company $company, int $limit = 200): array
    {
        $leads = Lead::where('company_id', $company->id)->whereNull('assigned_to')
            ->whereNotIn('stage', ['enrolled', 'lost'])
            ->whereDoesntHave('assignments', fn ($q) => $q->whereNotIn('status', ['dropped']))
            ->with('contact')
            ->orderBy('created_at')->orderBy('id')   // oldest waiting first
            ->limit($limit)->get();

        $routed = 0;
        foreach ($leads as $lead) {
            if (!$lead->contact) {
                continue;
            }
            try {
                $this->engine->assign($company, $lead->contact, $lead->source ?: 'organic', $lead->campaign_id, null, 'auto', $lead);
                $routed++;
            } catch (\Throwable $e) {
                Log::warning('Scheduled auto-assign failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            }
        }

        LeadAssignmentRule::where('company_id', $company->id)->update([
            'auto_assign_last_run_at' => now(), 'auto_assign_last_run_count' => $routed,
        ]);

        return ['routed' => $routed, 'found' => $leads->count()];
    }
}
