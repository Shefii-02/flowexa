<?php

namespace App\Services\LeadAssignment;

use App\Models\Company;
use App\Models\Contact;
use App\Models\LeadAssignment;
use App\Models\LeadAssignmentRule;
use App\Models\User;

class DuplicateLeadDetector
{
    public function check(
        Company $company,
        Contact $contact,
        ?int $campaignId = null
    ): array {
        $rule = LeadAssignmentRule::where('company_id', $company->id)->first();
        $windowDays = $rule?->duplicate_window_days ?? 90;
        $defaultAction = $rule?->duplicate_action ?? 'assign_same_staff';

        $previous = LeadAssignment::where('contact_id', $contact->id)
            ->where('company_id', $company->id)
            ->latest()
            ->first();

        if (!$previous) {
            return [
                'is_duplicate'          => false,
                'previous_assignment'   => null,
                'previous_staff'        => null,
                'previous_campaign'     => null,
                'days_since_last_lead'  => 0,
                'recommended_action'    => 'create_new',
                'reason'                => 'First time lead',
            ];
        }

        $daysSince = (int) $previous->created_at->diffInDays(now());
        $previousStaff = $previous->staff_id ? User::find($previous->staff_id) : null;
        $previousCampaign = $previous->campaign_id ? \App\Models\Campaign::find($previous->campaign_id) : null;

        // Same campaign duplicate
        if ($campaignId && $previous->campaign_id === $campaignId) {
            return [
                'is_duplicate'         => true,
                'previous_assignment'  => $previous,
                'previous_staff'       => $previousStaff,
                'previous_campaign'    => $previousCampaign,
                'days_since_last_lead' => $daysSince,
                'recommended_action'   => 'merge',
                'reason'               => 'Same campaign duplicate',
            ];
        }

        // Existing converted customer
        if ($contact->lead_stage === 'converted') {
            return [
                'is_duplicate'         => true,
                'previous_assignment'  => $previous,
                'previous_staff'       => $previousStaff,
                'previous_campaign'    => $previousCampaign,
                'days_since_last_lead' => $daysSince,
                'recommended_action'   => 'assign_same_staff',
                'reason'               => 'Existing customer — reassigning to previous handler',
            ];
        }

        // Within duplicate window
        if ($daysSince <= $windowDays) {
            return [
                'is_duplicate'         => true,
                'previous_assignment'  => $previous,
                'previous_staff'       => $previousStaff,
                'previous_campaign'    => $previousCampaign,
                'days_since_last_lead' => $daysSince,
                'recommended_action'   => $defaultAction,
                'reason'               => "Duplicate within {$windowDays}-day window ({$daysSince} days ago)",
            ];
        }

        // Old lead — treat as new
        return [
            'is_duplicate'         => false,
            'previous_assignment'  => $previous,
            'previous_staff'       => $previousStaff,
            'previous_campaign'    => $previousCampaign,
            'days_since_last_lead' => $daysSince,
            'recommended_action'   => 'create_new',
            'reason'               => "Previous lead is {$daysSince} days old — treating as new",
        ];
    }

    public function isSameCampaignLead(Contact $contact, int $campaignId): bool
    {
        return LeadAssignment::where('contact_id', $contact->id)
            ->where('campaign_id', $campaignId)
            ->exists();
    }
}
