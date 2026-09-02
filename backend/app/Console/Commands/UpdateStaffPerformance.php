<?php

namespace App\Console\Commands;

use App\Models\LeadAssignment;
use App\Models\StaffAvailability;
use App\Models\User;
use Illuminate\Console\Command;

class UpdateStaffPerformance extends Command
{
    protected $signature   = 'leads:update-performance';
    protected $description = 'Recalculate staff performance scores from assignment history';

    public function handle(): int
    {
        StaffAvailability::with('staff')->chunk(100, function ($records) {
            foreach ($records as $avail) {
                $staff = $avail->staff;
                if (!$staff) continue;

                $assignments = LeadAssignment::where('staff_id', $staff->id)->get();
                if ($assignments->isEmpty()) continue;

                $total     = $assignments->count();
                $completed = $assignments->where('status', 'completed')->count();

                $convRate = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

                $repliedAssignments = $assignments->whereNotNull('first_reply_at')->whereNotNull('accepted_at');
                $avgResponse = 0;
                if ($repliedAssignments->isNotEmpty()) {
                    $avgResponse = $repliedAssignments->avg(fn($a) =>
                        $a->accepted_at->diffInMinutes($a->first_reply_at)
                    );
                }

                $perfScore = min(100, max(0,
                    ($convRate * 0.5) +
                    (max(0, 100 - ($avgResponse * 2)) * 0.3) +
                    (min($avail->today_conversions * 10, 30) * 0.2)
                ));

                $avail->update([
                    'conversion_rate'            => $convRate,
                    'avg_response_time_minutes'  => round($avgResponse, 2),
                    'performance_score'          => round($perfScore, 2),
                    'total_conversions'          => $completed,
                ]);
            }
        });

        $this->info('Staff performance scores updated.');
        return self::SUCCESS;
    }
}
