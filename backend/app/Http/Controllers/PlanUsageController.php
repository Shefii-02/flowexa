<?php

namespace App\Http\Controllers;

use App\Support\PlanLimits;
use Illuminate\Http\JsonResponse;

class PlanUsageController extends Controller
{
    /** GET /plan/usage — every limit with used / allowed / remaining / exceeded for the signed-in user's company. */
    public function show(): JsonResponse
    {
        $user = auth()->user();
        $company = $user?->company;
        if (!$company || $user->isSuperAdmin()) {
            // no company or the platform owner: nothing is limited
            return response()->json(['plan' => null, 'unlimited' => true, 'resources' => (object) []]);
        }
        $plan = $company->plan;
        return response()->json([
            'plan' => $plan ? ['id' => $plan->id, 'name' => $plan->name] : null,
            'unlimited' => !$plan,
            'resources' => PlanLimits::usage((int) $company->id, $plan),
            'per_item' => $plan ? ['campaign_contacts' => $plan->max_campaign_contacts, 'messages_included' => $plan->messages_limit] : null,
            'features' => $plan ? [
                'google_sheets' => (bool) $plan->google_sheets_enabled, 'google_drive' => (bool) $plan->google_drive_enabled,
                'calendar' => (bool) $plan->calendar_enabled, 'email_integration' => (bool) $plan->email_integration_enabled,
            ] : null,
        ]);
    }
}
