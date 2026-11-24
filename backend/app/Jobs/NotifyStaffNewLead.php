<?php

namespace App\Jobs;

use App\Models\LeadAssignment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyStaffNewLead implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $assignmentId,
        private readonly int $staffId,
    ) {}

    public function handle(): void
    {
        $assignment = LeadAssignment::with('contact', 'campaign')->find($this->assignmentId);
        $staff      = User::find($this->staffId);

        if (!$assignment || !$staff) return;

        $contact = $assignment->contact;
        $rule    = \App\Models\LeadAssignmentRule::where('company_id', $assignment->company_id)->first();

        try {
            $nodeUrl     = config('services.chat.url', 'http://localhost:3005');
            $internalKey = config('services.internal.key', '');

            \Illuminate\Support\Facades\Http::withHeaders(['X-Internal-Key' => $internalKey])
                ->post("{$nodeUrl}/api/internal/emit-notification", [
                    'type'     => 'lead_assignment_update',
                    'staff_id' => $staff->id,
                    'data'     => [
                        'assignment_id' => $assignment->id,
                        'status'        => $assignment->status,
                        'contact_name'  => $contact?->name ?? 'Unknown',
                        'contact_phone' => $contact?->phone ?? '',
                        'priority'      => $assignment->priority,
                        'source_type'   => $assignment->source_type,
                    ],
                ]);
        } catch (\Throwable) {
            // Non-critical
        }

        // mobile push: a lead was handed to this person — tapping it opens the lead
        \App\Services\StaffPush::toStaff($staff->id, 'new_lead_alert', 'lead_assigned', '🎯 New lead assigned',
            ($contact?->name ?: ($contact?->phone ?: 'A new lead')) . ' has been assigned to you.', [
                'action' => $assignment->lead_id ? 'open_lead' : 'open_leads', 'lead_id' => $assignment->lead_id, 'assignment_id' => $assignment->id,
            ]);
    }
}
