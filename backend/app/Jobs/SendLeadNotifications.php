<?php

namespace App\Jobs;

use App\Models\LeadAssignment;
use App\Models\LeadAssignmentNotification;
use App\Models\LeadAssignmentRule;
use App\Services\LeadAssignment\LeadAssignmentEngine;
use App\Services\LeadAssignment\StaffScorer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendLeadNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $assignmentId,
        private readonly int $ruleId,
    ) {}

    public function handle(StaffScorer $scorer, LeadAssignmentEngine $engine): void
    {
        $assignment = LeadAssignment::find($this->assignmentId);
        $rule       = LeadAssignmentRule::find($this->ruleId);

        if (!$assignment || !$rule) return;
        if ($assignment->status === 'accepted' || $assignment->status === 'completed') return;

        $alreadyNotified = LeadAssignmentNotification::where('assignment_id', $assignment->id)
            ->pluck('staff_id')
            ->toArray();

        $roundsDone = count($alreadyNotified);

        if ($roundsDone >= $rule->max_notification_rounds) {
            $engine->startAiAgent($assignment, $assignment->company, $assignment->contact);
            return;
        }

        // Who gets notified next follows the rule's own routing strategy — least-loaded /
        // longest-idle for round robin, the weighted score for the algorithm strategy.
        if (($rule->strategy ?? 'algorithm') === 'round_robin') {
            $nextStaff = $engine->roundRobinPick($assignment->company, $alreadyNotified);
        } else {
            $ranked    = $scorer->rankStaff($assignment->company, $rule, null, $alreadyNotified);
            $nextStaff = $ranked->first()->staff ?? null;
        }

        if (!$nextStaff) {
            $engine->startAiAgent($assignment, $assignment->company, $assignment->contact);
            return;
        }

        $notification = LeadAssignmentNotification::create([
            'company_id'        => $assignment->company_id,
            'assignment_id'     => $assignment->id,
            'staff_id'          => $nextStaff->id,
            'notification_type' => 'new_lead',
            'channel'           => 'both',
            'sent_at'           => now(),
        ]);

        $assignment->update(['status' => 'notified']);

        dispatch(new PushLeadNotification($notification->id, $nextStaff->id));

        dispatch(new CheckNotificationResponse($notification->id, $assignment->id, $rule->id))
            ->delay(now()->addSeconds($rule->notification_timeout_seconds));
    }
}
