<?php

namespace App\Jobs;

use App\Models\LeadAssignment;
use App\Models\LeadAssignmentNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PushLeadNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $notificationId,
        private readonly int $staffId,
    ) {}

    public function handle(): void
    {
        $notification = LeadAssignmentNotification::with('assignment.contact', 'assignment.campaign')->find($this->notificationId);
        $staff        = User::find($this->staffId);

        if (!$notification || !$staff) return;

        $assignment = $notification->assignment;
        $contact    = $assignment?->contact;
        $rule       = \App\Models\LeadAssignmentRule::where('company_id', $assignment->company_id)->first();

        try {
            $internalKey = config('services.internal.key', '');
            $nodeUrl     = config('services.node.url', 'http://localhost:3000');

            \Illuminate\Support\Facades\Http::withHeaders(['X-Internal-Key' => $internalKey])
                ->post("{$nodeUrl}/api/internal/emit-notification", [
                    'type'     => 'new_lead_notification',
                    'staff_id' => $staff->id,
                    'data'     => [
                        'assignment_id'   => $assignment->id,
                        'notification_id' => $notification->id,
                        'contact_name'    => $contact?->name ?? 'Unknown',
                        'contact_phone'   => $contact?->phone ?? '',
                        'source_type'     => $assignment->source_type,
                        'lead_score'      => $contact?->lead_score ?? 0,
                        'priority'        => $assignment->priority,
                        'timeout_seconds' => $rule?->notification_timeout_seconds ?? 60,
                        'campaign_name'   => $assignment->campaign?->name,
                    ],
                ]);
        } catch (\Throwable) {
            // Non-critical
        }
    }
}
