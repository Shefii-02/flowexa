<?php

namespace App\Jobs;

use App\Models\CrmTask;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time notification for a staff member when the AI agent's provider call genuinely
 * failed mid-conversation and a CrmTask was created for them to follow up — mirrors
 * NotifyStaffNewLead's delivery mechanism (the same Node internal notification endpoint the
 * frontend's notification popups already listen to) without going through the full
 * LeadAssignment lifecycle, since this isn't a new-lead intake event.
 */
class NotifyStaffAiHandoff implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $taskId,
        private readonly int $staffId,
    ) {}

    public function handle(): void
    {
        $task  = CrmTask::with('contact')->find($this->taskId);
        $staff = User::find($this->staffId);

        if (!$task || !$staff) {
            return;
        }

        try {
            $nodeUrl     = config('services.node.url', 'http://localhost:3000');
            $internalKey = config('services.internal.key', '');

            \Illuminate\Support\Facades\Http::withHeaders(['X-Internal-Key' => $internalKey])
                ->post("{$nodeUrl}/api/internal/emit-notification", [
                    'type'     => 'ai_handoff_task',
                    'staff_id' => $staff->id,
                    'data'     => [
                        'task_id'       => $task->id,
                        'title'         => $task->title,
                        'contact_name'  => $task->contact?->name ?? 'Unknown',
                        'contact_phone' => $task->contact?->phone ?? '',
                        'due_at'        => $task->due_at?->toIso8601String(),
                        'priority'      => $task->priority,
                    ],
                ]);
        } catch (\Throwable) {
            // Non-critical — the task itself is already saved regardless.
        }
    }
}
