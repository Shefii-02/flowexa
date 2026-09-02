<?php

namespace App\Services\LeadAssignment;

use App\Models\Company;
use App\Models\LeadAssignment;
use App\Models\LeadAssignmentNotification;
use App\Models\LeadAssignmentRule;
use App\Models\StaffAvailability;
use App\Models\User;
use App\Modules\WaChat\Models\AiAgentSession;

class AiStaffHandoffService
{
    public function __construct(private readonly StaffScorer $scorer) {}

    public function offerToAvailableStaff(Company $company): void
    {
        $rule = LeadAssignmentRule::where('company_id', $company->id)->first();
        if (!$rule) return;

        $aiAssignments = LeadAssignment::where('company_id', $company->id)
            ->where('status', 'ai_handling')
            ->whereNull('ai_offered_at')
            ->with(['contact'])
            ->get();

        foreach ($aiAssignments as $assignment) {
            $ranked = $this->scorer->rankStaff($company, $rule);
            if ($ranked->isEmpty()) continue;

            $bestStaff = $ranked->first()->staff;
            $this->sendHandoffOffer($assignment, $bestStaff);

            $assignment->update([
                'status'        => 'ai_offered',
                'ai_offered_at' => now(),
            ]);

            LeadAssignmentNotification::create([
                'company_id'        => $company->id,
                'assignment_id'     => $assignment->id,
                'staff_id'          => $bestStaff->id,
                'notification_type' => 'ai_offer',
                'channel'           => 'both',
                'sent_at'           => now(),
            ]);
        }
    }

    public function handleStaffResponse(LeadAssignment $assignment, User $staff, string $response): void
    {
        if (strtolower(trim($response)) === 'yes') {
            $assignment->update([
                'staff_id'           => $staff->id,
                'status'             => 'assigned',
                'staff_confirmed_at' => now(),
                'transfer_reason'    => 'Staff took over from AI agent',
            ]);

            if ($assignment->ai_agent_session_id) {
                AiAgentSession::find($assignment->ai_agent_session_id)?->update([
                    'status' => 'handed_off',
                ]);
            }

            $availability = StaffAvailability::ensureExists($assignment->company_id, $staff->id);
            $availability->increment('current_leads_count');
        } else {
            LeadAssignmentNotification::where('assignment_id', $assignment->id)
                ->where('staff_id', $staff->id)
                ->whereNull('responded_at')
                ->latest()
                ->update(['response' => 'declined', 'responded_at' => now()]);

            // Reset so another offer can go out
            $assignment->update([
                'status'        => 'ai_handling',
                'ai_offered_at' => null,
            ]);
        }
    }

    public function detectStaffResponseFromWhatsApp(Company $company, string $staffPhone, string $message): void
    {
        $staff = User::where('company_id', $company->id)->where('phone', $staffPhone)->first();
        if (!$staff) return;

        $pendingOffer = LeadAssignment::where('company_id', $company->id)
            ->where('status', 'ai_offered')
            ->whereHas('notifications', fn($q) =>
                $q->where('staff_id', $staff->id)->where('notification_type', 'ai_offer')
            )
            ->first();

        if ($pendingOffer) {
            $this->handleStaffResponse($pendingOffer, $staff, trim($message));
        }
    }

    private function sendHandoffOffer(LeadAssignment $assignment, User $staff): void
    {
        // Emit via Node.js socket — fire and forget
        try {
            $internalKey = config('services.internal.key', '');
            $nodeUrl = config('services.node.url', 'http://localhost:3000');
            $contact = $assignment->contact;

            \Illuminate\Support\Facades\Http::withHeaders(['X-Internal-Key' => $internalKey])
                ->post("{$nodeUrl}/api/internal/emit-notification", [
                    'type'     => 'ai_handoff_offer',
                    'staff_id' => $staff->id,
                    'data'     => [
                        'assignment_id'  => $assignment->id,
                        'contact_name'   => $contact?->name ?? 'Unknown',
                        'contact_phone'  => $contact?->phone ?? '',
                        'conversation_summary' => $contact?->conversation_summary ?? 'AI is handling this conversation.',
                        'ai_message_count' => 0,
                    ],
                ]);
        } catch (\Throwable) {
            // Non-critical — continue
        }
    }
}
