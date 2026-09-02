<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Models\LeadAssignment;
use App\Models\LeadAssignmentNotification;
use App\Models\LeadAssignmentRule;
use App\Models\StaffAvailability;
use App\Models\User;
use App\Services\LeadAssignment\LeadAssignmentEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LeadAssignmentController extends Controller
{
    public function __construct(private readonly LeadAssignmentEngine $engine) {}

    // GET /api/v1/lead-assignments
    public function index(Request $request): JsonResponse
    {
        $company = Auth::user()->company;

        $query = LeadAssignment::where('company_id', $company->id)
            ->with(['contact', 'staff', 'campaign'])
            ->latest();

        if ($request->filled('status'))      $query->where('status', $request->status);
        if ($request->filled('staff_id'))    $query->where('staff_id', $request->staff_id);
        if ($request->filled('contact_id'))  $query->where('contact_id', $request->contact_id);
        if ($request->filled('source_type')) $query->where('source_type', $request->source_type);
        if ($request->filled('campaign_id')) $query->where('campaign_id', $request->campaign_id);
        if ($request->filled('date_from'))   $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))     $query->whereDate('created_at', '<=', $request->date_to);

        return response()->json($query->paginate(20));
    }

    // GET /api/v1/lead-assignments/stats
    public function stats(): JsonResponse
    {
        $company = Auth::user()->company;
        $today = now()->startOfDay();

        $base = LeadAssignment::where('company_id', $company->id);

        $staffPerformance = User::where('company_id', $company->id)
            ->where('is_active', true)
            ->with('availability')
            ->get()
            ->map(function (User $staff) use ($company) {
                $avail    = $staff->availability;
                $assigned = LeadAssignment::where('staff_id', $staff->id)->count();
                $converted = LeadAssignment::where('staff_id', $staff->id)->where('status', 'completed')->count();
                return [
                    'staff'        => ['id' => $staff->id, 'name' => $staff->name],
                    'assigned'     => $assigned,
                    'converted'    => $converted,
                    'rate'         => $assigned > 0 ? round(($converted / $assigned) * 100, 1) . '%' : '0%',
                    'avg_response' => $avail?->avg_response_time_minutes ?? 0,
                    'score'        => $avail?->performance_score ?? 50,
                ];
            });

        $avgReply = LeadAssignment::where('company_id', $company->id)
            ->whereNotNull('first_reply_at')
            ->whereNotNull('accepted_at')
            ->get()
            ->avg(fn($a) => $a->accepted_at->diffInSeconds($a->first_reply_at));

        return response()->json([
            'total_today'           => (clone $base)->whereDate('created_at', $today)->count(),
            'auto_assigned'         => (clone $base)->where('assignment_type', 'auto')->count(),
            'notification_assigned' => (clone $base)->where('assignment_type', 'notification')->count(),
            'ai_handling'           => (clone $base)->where('status', 'ai_handling')->count(),
            'sla_breached'          => (clone $base)->where('sla_breached', true)->count(),
            'avg_response_time'     => $avgReply ? gmdate('i\m s\s', (int) $avgReply) : '-',
            'conversion_rate'       => $this->companyConversionRate($company->id),
            'staff_performance'     => $staffPerformance,
        ]);
    }

    // GET /api/v1/lead-assignments/{id}
    public function show(int $id): JsonResponse
    {
        $company = Auth::user()->company;
        $assignment = LeadAssignment::where('company_id', $company->id)
            ->with(['contact', 'staff', 'campaign', 'notifications.staff', 'aiSession'])
            ->findOrFail($id);

        return response()->json(['data' => $assignment]);
    }

    // POST /api/v1/lead-assignments (manual)
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'contact_id'  => 'required|integer',
            'source_type' => 'nullable|in:wa_chat,meta_api,campaign,organic,flow_builder,manual',
            'campaign_id' => 'nullable|integer',
            'staff_id'    => 'nullable|integer',
            'notes'       => 'nullable|string',
        ]);

        $company = Auth::user()->company;
        $contact = \App\Models\Contact::where('company_id', $company->id)
            ->findOrFail($request->contact_id);

        $assignment = $this->engine->assign(
            $company,
            $contact,
            $request->source_type ?? 'manual',
            $request->campaign_id,
            null,
            'manual'
        );

        if ($request->filled('staff_id')) {
            $staff = User::where('company_id', $company->id)->findOrFail($request->staff_id);
            $rule  = LeadAssignmentRule::where('company_id', $company->id)->firstOrNew();
            $this->engine->assignToStaff($assignment, $staff, $rule);
        }

        if ($request->filled('notes')) {
            $assignment->update(['notes' => $request->notes]);
        }

        return response()->json(['data' => $assignment->fresh(['contact', 'staff', 'campaign'])], 201);
    }

    // PUT /api/v1/lead-assignments/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $company    = Auth::user()->company;
        $assignment = LeadAssignment::where('company_id', $company->id)->findOrFail($id);

        $assignment->update($request->only(['status', 'notes']));
        return response()->json(['data' => $assignment->fresh(['contact', 'staff'])]);
    }

    // POST /api/v1/lead-assignments/{id}/accept
    public function accept(int $id): JsonResponse
    {
        $user    = Auth::user();
        $company = $user->company;

        $assignment = LeadAssignment::where('company_id', $company->id)->findOrFail($id);

        LeadAssignmentNotification::where('assignment_id', $id)
            ->where('staff_id', $user->id)
            ->whereNull('responded_at')
            ->update(['response' => 'accepted', 'responded_at' => now()]);

        $assignment->update([
            'staff_id'    => $user->id,
            'status'      => 'accepted',
            'accepted_at' => now(),
        ]);

        return response()->json(['data' => $assignment->fresh()]);
    }

    // POST /api/v1/lead-assignments/{id}/decline
    public function decline(int $id): JsonResponse
    {
        $user    = Auth::user();
        $company = $user->company;

        $assignment = LeadAssignment::where('company_id', $company->id)->findOrFail($id);

        LeadAssignmentNotification::where('assignment_id', $id)
            ->where('staff_id', $user->id)
            ->whereNull('responded_at')
            ->update(['response' => 'declined', 'responded_at' => now()]);

        return response()->json(['message' => 'Declined']);
    }

    // POST /api/v1/lead-assignments/{id}/complete
    public function complete(int $id): JsonResponse
    {
        $company    = Auth::user()->company;
        $assignment = LeadAssignment::where('company_id', $company->id)->findOrFail($id);

        $assignment->update(['status' => 'completed']);

        if ($assignment->contact) {
            $assignment->contact->update(['lead_stage' => 'converted']);
        }

        if ($assignment->staff_id) {
            $avail = StaffAvailability::where('staff_id', $assignment->staff_id)->first();
            if ($avail && $avail->current_leads_count > 0) {
                $avail->decrement('current_leads_count');
                $avail->increment('today_conversions');
                $avail->increment('total_conversions');
                // If was busy, free up
                if ($avail->fresh()->status === 'busy') {
                    $avail->update(['status' => 'online', 'is_available' => true]);
                }
            }
        }

        return response()->json(['data' => $assignment->fresh()]);
    }

    // POST /api/v1/lead-assignments/{id}/transfer
    public function transfer(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'to_staff_id' => 'required|integer',
            'reason'      => 'nullable|string',
        ]);

        $company    = Auth::user()->company;
        $assignment = LeadAssignment::where('company_id', $company->id)->findOrFail($id);
        $newStaff   = User::where('company_id', $company->id)->findOrFail($request->to_staff_id);
        $rule       = LeadAssignmentRule::where('company_id', $company->id)->firstOrNew();

        $oldStaffId = $assignment->staff_id;
        $assignment->update([
            'transferred_from' => $oldStaffId,
            'transfer_reason'  => $request->reason ?? 'Manual transfer',
            'status'           => 'transferred',
        ]);

        if ($oldStaffId) {
            $oldAvail = StaffAvailability::where('staff_id', $oldStaffId)->first();
            if ($oldAvail && $oldAvail->current_leads_count > 0) {
                $oldAvail->decrement('current_leads_count');
                if ($oldAvail->fresh()->status === 'busy') {
                    $oldAvail->update(['status' => 'online', 'is_available' => true]);
                }
            }
        }

        $this->engine->assignToStaff($assignment, $newStaff, $rule);

        return response()->json(['data' => $assignment->fresh(['contact', 'staff'])]);
    }

    // GET /api/v1/lead-assignment-rules
    public function getRule(): JsonResponse
    {
        $company = Auth::user()->company;
        $rule    = LeadAssignmentRule::where('company_id', $company->id)
            ->firstOrCreate(LeadAssignmentRule::defaultForCompany($company->id));

        return response()->json(['data' => $rule]);
    }

    // POST /api/v1/lead-assignment-rules
    public function saveRule(Request $request): JsonResponse
    {
        $company = Auth::user()->company;
        $rule    = LeadAssignmentRule::where('company_id', $company->id)
            ->firstOrCreate(LeadAssignmentRule::defaultForCompany($company->id));

        $rule->update($request->only([
            'auto_assign_enabled',
            'weight_availability', 'weight_max_leads', 'weight_performance', 'weight_workload',
            'sla_minutes', 'ai_takeover_after_minutes',
            'notification_mode', 'notification_gap_seconds', 'notification_timeout_seconds', 'max_notification_rounds',
            'duplicate_window_days', 'duplicate_action',
            'working_hours_start', 'working_hours_end', 'working_days', 'timezone',
        ]));

        return response()->json(['data' => $rule]);
    }

    // GET /api/v1/staff/availability
    public function staffAvailability(): JsonResponse
    {
        $company = Auth::user()->company;

        $staff = User::where('company_id', $company->id)
            ->where('is_active', true)
            ->with(['availability', 'role'])
            ->get()
            ->map(function (User $s) use ($company) {
                $avail = $s->availability ?? StaffAvailability::ensureExists($company->id, $s->id);
                return array_merge($s->toArray(), ['availability' => $avail]);
            });

        return response()->json(['data' => $staff]);
    }

    // POST /api/v1/staff/availability/toggle
    public function toggleAvailability(): JsonResponse
    {
        $user  = Auth::user();
        $avail = StaffAvailability::ensureExists($user->company_id, $user->id);

        $newAvail = !$avail->is_available;
        $avail->update([
            'is_available' => $newAvail,
            'status'       => $newAvail ? 'online' : 'away',
        ]);

        return response()->json(['data' => $avail->fresh()]);
    }

    private function companyConversionRate(int $companyId): string
    {
        $total     = LeadAssignment::where('company_id', $companyId)->count();
        $completed = LeadAssignment::where('company_id', $companyId)->where('status', 'completed')->count();
        return $total > 0 ? round(($completed / $total) * 100, 1) . '%' : '0%';
    }
}
