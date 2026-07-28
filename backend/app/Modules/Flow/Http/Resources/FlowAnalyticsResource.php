<?php

namespace App\Modules\Flow\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;


// ─── Analytics Resource ───────────────────────────────────────────────────────
class FlowAnalyticsResource
{
    public static function toArray(Collection $nodes): array
    {
        $total        = $nodes->sum('trigger_count');
        $activeCount  = $nodes->where('is_active', true)->count();
        $topNodes     = $nodes->sortByDesc('trigger_count')->take(10);

        return [
            'summary' => [
                'total_nodes'    => $nodes->count(),
                'active_nodes'   => $activeCount,
                'inactive_nodes' => $nodes->count() - $activeCount,
                'total_triggers' => $total,
            ],
            'top_nodes' => $topNodes->values()->map(fn($n) => [
                'id'            => $n->id,
                'title'         => $n->title,
                'type'          => $n->type,
                'lead_category' => $n->lead_category,
                'trigger_count' => $n->trigger_count,
                'is_active'     => $n->is_active,
                'share_percent' => $total > 0
                    ? round(($n->trigger_count / $total) * 100, 1)
                    : 0,
            ])->all(),

            'by_type' => $nodes->groupBy('type')->map(fn($group) => [
                'count'    => $group->count(),
                'triggers' => $group->sum('trigger_count'),
            ]),

            'by_category' => $nodes
                ->whereNotNull('lead_category')
                ->groupBy('lead_category')
                ->map(fn($group) => [
                    'count'    => $group->count(),
                    'triggers' => $group->sum('trigger_count'),
                ]),
        ];
    }
}
