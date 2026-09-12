<?php

namespace App\Jobs;

use App\Models\LeadAssignment;
use App\Models\LeadAssignmentNotification;
use App\Models\LeadAssignmentRule;
use App\Services\LeadAssignment\LeadActivityLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckNotificationResponse implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $notificationId,
        private readonly int $assignmentId,
        private readonly int $ruleId,
    ) {}

    public function handle(LeadActivityLogger $activity): void
    {
        $notification = LeadAssignmentNotification::find($this->notificationId);
        $assignment   = LeadAssignment::find($this->assignmentId);
        $rule         = LeadAssignmentRule::find($this->ruleId);

        if (!$notification || !$assignment || !$rule) return;

        // Already accepted by someone
        if (in_array($assignment->status, ['accepted', 'completed', 'dropped'])) return;

        // Staff didn't respond in time
        if (!$notification->responded_at) {
            $notification->update(['response' => 'no_response', 'responded_at' => now()]);

            $activity->log($assignment, 'lead_assignment_timeout', [
                'staff_id' => $notification->staff_id,
                'staff_name' => $notification->staff?->name,
                'timeout_seconds' => $rule->notification_timeout_seconds,
            ]);

            // Wait gap then notify next staff
            dispatch(new SendLeadNotifications($this->assignmentId, $this->ruleId))
                ->delay(now()->addSeconds($rule->notification_gap_seconds));
        }
    }
}
