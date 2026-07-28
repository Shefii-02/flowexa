<?php
namespace App\Modules\Flow\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;


// ─── Recursive tree node ──────────────────────────────────────────────────────
class FlowTreeResource extends JsonResource
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

            // Recursively nest children
            'children' => $this->resource->relationLoaded('children')
                ? FlowTreeResource::collection($this->children)
                : [],
        ];
    }
}
