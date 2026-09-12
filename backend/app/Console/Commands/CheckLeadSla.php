<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\LeadAssignment;
use App\Services\LeadAssignment\LeadAssignmentEngine;
use App\Services\LeadAssignment\LeadActivityLogger;

class CheckLeadSla extends Command
{
    protected $signature   = 'leads:check-sla';
    protected $description = 'Check for SLA breaches on active lead assignments and trigger AI fallback';

    public function handle(LeadAssignmentEngine $engine, LeadActivityLogger $activity): void
    {
        $now = now();

        $breached = LeadAssignment::whereIn('status', ['pending', 'notified', 'assigned'])
            ->where('sla_breached', false)
            ->whereNotNull('created_at')
            ->whereRaw('DATE_ADD(created_at, INTERVAL response_sla_minutes MINUTE) <= ?', [$now])
            ->with(['company', 'contact'])
            ->get();

        if ($breached->isEmpty()) {
            $this->info('No SLA breaches found.');
            return;
        }

        foreach ($breached as $assignment) {
            $assignment->update([
                'sla_breached'    => true,
                'sla_breached_at' => $now,
            ]);

            $this->warn("SLA breached: assignment #{$assignment->id} (contact: {$assignment->contact?->name})");

            $activity->log($assignment, 'lead_sla_breached', [
                'staff_id' => $assignment->staff_id,
                'sla_minutes' => $assignment->response_sla_minutes,
            ]);

            if ($assignment->status !== 'ai_handling' && $assignment->company && $assignment->contact) {
                try {
                    $engine->startAiAgent($assignment, $assignment->company, $assignment->contact);
                    $this->info("  → AI agent started for #{$assignment->id}");
                } catch (\Exception $e) {
                    $this->error("  → Failed to start AI: {$e->getMessage()}");
                }
            }
        }

        $this->info("Processed {$breached->count()} SLA breach(es).");
    }
}
