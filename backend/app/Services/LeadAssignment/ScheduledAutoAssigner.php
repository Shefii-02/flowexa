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

    /**
     * @return array{routed:int, found:int, waiting:int}  waiting = leads that were found but still have nobody to take them
     *
     * Two kinds of unassigned lead are picked up: ones never routed at all, and ones the engine already tried but could not place
     * (nobody online / at capacity / outside working hours → the AI agent held them and the lead stayed ownerless). The second kind
     * used to be skipped forever, which is why leads piled up unassigned.
     */
    public function runForCompany(Company $company, int $limit = 200, bool $manual = false): array
    {
        \App\Models\StaffAvailability::recountWorkload($company->id);   // lead limits are judged on what people really hold
        $auto = in_array(LeadAssignmentRule::where('company_id', $company->id)->value('notification_mode'), ['auto', 'silent'], true);
        $leads = Lead::where('company_id', $company->id)->whereNull('assigned_to')
            ->whereNotIn('stage', ['enrolled', 'lost'])
            ->where(function ($q) use ($auto) {
                if ($auto) {
                    // Direct-assign companies: anything unowned is fair game unless an assignment is genuinely in flight (handed to someone,
                    // an offer waiting for an answer, or a fresh attempt from the last 15 minutes). Old declined / timed-out / AI-held /
                    // finished assignment rows no longer hide a lead from the sweep.
                    $q->whereDoesntHave('assignments', fn ($a) => $a->where(fn ($w) => $w
                        ->whereIn('status', ['assigned', 'accepted', 'ai_offered'])
                        ->orWhere(fn ($p) => $p->where('status', 'pending')->where('updated_at', '>=', now()->subMinutes(15)))));
                } else {
                    $q->whereDoesntHave('assignments', fn ($a) => $a->whereNotIn('status', ['dropped']));   // notify modes: never double-notify
                }
            })
            ->with('contact')
            ->orderBy('created_at')->orderBy('id')   // oldest waiting first
            ->limit($limit)->get();

        $routed = 0; $waiting = 0;
        foreach ($leads as $lead) {
            if (!$lead->contact) {
                continue;
            }
            try {
                $stuck = $auto ? $lead->assignments()->where('status', 'ai_handling')->whereNull('staff_id')->latest('id')->first() : null;
                if ($stuck) {
                    $this->engine->retryWaiting($company, $stuck, $manual) ? $routed++ : $waiting++;
                    continue;
                }
                $a = $this->engine->assign($company, $lead->contact, $lead->source ?: 'organic', $lead->campaign_id, null, 'auto', $lead, null, null, false, null, $manual);
                $a->status === 'ai_handling' ? $waiting++ : $routed++;   // handed to the AI agent = nobody could take it yet
            } catch (\Throwable $e) {
                Log::warning('Scheduled auto-assign failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            }
        }

        LeadAssignmentRule::where('company_id', $company->id)->update([
            'auto_assign_last_run_at' => now(), 'auto_assign_last_run_count' => $routed,
        ]);

        $rule = LeadAssignmentRule::where('company_id', $company->id)->first();
        $closed = $waiting > 0 && !$manual && $rule && !$rule->assign_outside_hours && !$this->engine->withinWorkingHours($company, $rule);   // the cron only hands out leads in working hours

        return ['routed' => $routed, 'found' => $leads->count(), 'waiting' => $waiting, 'outside_hours' => $closed];
    }
}
