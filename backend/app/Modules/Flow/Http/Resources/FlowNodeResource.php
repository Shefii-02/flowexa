<?php

namespace App\Modules\Flow\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

// ─── Single Flow Node (flat) ──────────────────────────────────────────────────
class FlowNodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'parent_id'     => $this->parent_id,
            'title'         => $this->title,
            'message'       => $this->message,
            'type'          => $this->type,
            'reply_id'      => $this->reply_id,
            'lead_category' => $this->lead_category,
            'sort_order'    => $this->sort_order,
            'is_active'     => $this->is_active,
            'trigger_count' => $this->trigger_count,
            'created_at'    => $this->created_at->toIso8601String(),
            'updated_at'    => $this->updated_at->toIso8601String(),

            // Children only when loaded
            'children' => $this->whenLoaded('children',
                fn() => FlowNodeResource::collection($this->children)
            ),

            // Stats: child count
            'children_count' => $this->when(
                isset($this->children),
                fn() => $this->children?->count() ?? 0
            ),
        ];
    }
}
