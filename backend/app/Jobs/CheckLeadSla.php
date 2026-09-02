<?php

namespace App\Jobs;

use App\Models\LeadAssignment;
use App\Services\LeadAssignment\LeadAssignmentEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckLeadSla implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $assignmentId) {}

    public function handle(LeadAssignmentEngine $engine): void
    {
        $assignment = LeadAssignment::find($this->assignmentId);
        if (!$assignment) return;

        // Already replied — no breach
        if ($assignment->first_reply_at) return;
        if (in_array($assignment->status, ['completed', 'dropped', 'ai_handling', 'transferred'])) return;

        $assignment->update([
            'sla_breached'    => true,
            'sla_breached_at' => now(),
        ]);

        // If staff is assigned but hasn't replied → hand to AI
        if ($assignment->staff_id && $assignment->status === 'assigned') {
            $engine->startAiAgent($assignment, $assignment->company, $assignment->contact);
        }
    }
}
