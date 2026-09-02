<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\LeadAssignment;
use App\Services\LeadAssignment\LeadAssignmentEngine;

class CheckLeadSla extends Command
{
    protected $signature   = 'leads:check-sla';
    protected $description = 'Check for SLA breaches on active lead assignments and trigger AI fallback';

    public function handle(LeadAssignmentEngine $engine): void
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

            if ($assignment->status !== 'ai_handling') {
                try {
                    $engine->startAiAgent($assignment);
                    $this->info("  → AI agent started for #{$assignment->id}");
                } catch (\Exception $e) {
                    $this->error("  → Failed to start AI: {$e->getMessage()}");
                }
            }
        }

        $this->info("Processed {$breached->count()} SLA breach(es).");
    }
}
