<?php

namespace App\Services\LeadAssignment;

use App\Models\LeadAssignment;
use App\Models\LeadAssignmentRule;
use App\Models\StaffAvailability;
use App\Models\User;
use Illuminate\Support\Collection;

class StaffScorer
{
    public function score(
        User $staff,
        StaffAvailability $availability,
        LeadAssignmentRule $rule,
        ?LeadAssignment $previousAssignment = null
    ): float {
        // Hard disqualification — at max leads or offline
        $maxLeads = $staff->max_leads ?? 0;
        if ($maxLeads > 0 && $availability->current_leads_count >= $maxLeads) {
            return 0;
        }

        // Factor 1: Availability
        $availScore = match ($availability->status) {
            'online'  => $availability->is_available ? 100 : 0,
            'away'    => 50,
            default   => 0,
        };
        if ($availability->status === 'offline') return 0;

        // Factor 2: Capacity / max leads
        $capScore = 80; // unlimited
        if ($maxLeads > 0) {
            $used = $availability->current_leads_count / $maxLeads;
            $capScore = match (true) {
                $used <= 0.25 => 100,
                $used <= 0.50 => 75,
                $used <= 0.75 => 50,
                $used < 1.00  => 25,
                default       => 0,
            };
            if ($capScore === 0) return 0;
        }

        // Factor 3: Performance
        $perfScore = $availability->conversion_rate;
        if ($availability->today_conversions > 2)  $perfScore = min(100, $perfScore + 20);
        if ($availability->avg_response_time_minutes < 10) $perfScore = min(100, $perfScore + 10);

        // Factor 4: Workload (fewer leads = higher score)
        $workScore = max(0, 100 - ($availability->current_leads_count * 10));

        // Weighted average
        $total = $rule->weight_availability + $rule->weight_max_leads
               + $rule->weight_performance + $rule->weight_workload;
        $total = max($total, 1);

        $score = (
            ($availScore * $rule->weight_availability) +
            ($capScore   * $rule->weight_max_leads)    +
            ($perfScore  * $rule->weight_performance)  +
            ($workScore  * $rule->weight_workload)
        ) / $total;

        // Previous handler bonus
        if ($previousAssignment) {
            if ($previousAssignment->staff_id === $staff->id) $score += 25;
        }

        return min(100, $score);
    }

    public function rankStaff(
        \App\Models\Company $company,
        LeadAssignmentRule $rule,
        ?LeadAssignment $previousAssignment = null,
        array $excludeStaffIds = []
    ): Collection {
        $staffList = User::where('company_id', $company->id)
            ->where('is_active', true)
            ->whereNotIn('id', $excludeStaffIds)
            ->whereHas('role', fn($q) => $q->whereJsonContains('permissions', 'leads.manage'))
            ->get();

        return $staffList->map(function (User $staff) use ($rule, $previousAssignment, $company) {
            $availability = StaffAvailability::ensureExists($company->id, $staff->id);
            $score = $this->score($staff, $availability, $rule, $previousAssignment);
            return (object) ['staff' => $staff, 'availability' => $availability, 'score' => $score];
        })
        ->filter(fn($r) => $r->score > 0)
        ->sortByDesc('score')
        ->values();
    }
}
