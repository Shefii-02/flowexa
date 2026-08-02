<?php

namespace App\Modules\Flow\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlowBuilderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'description'        => $this->description,
            'trigger_type'       => $this->trigger_type,
            'trigger_keywords'   => is_string($this->trigger_keywords)
                ? json_decode($this->trigger_keywords, true)
                : ($this->trigger_keywords ?? []),
            'active_from'        => $this->active_from,
            'active_until'       => $this->active_until,
            'is_active'          => (bool) $this->is_active,
            'nodes_count'        => $this->whenCounted('nodes'),
            'active_nodes_count' => $this->when(isset($this->active_nodes_count), $this->active_nodes_count),
            'created_at'         => $this->created_at,
            'updated_at'         => $this->updated_at,
            // nested node tree, only included when explicitly loaded (see FlowBuilderController::show)
            'nodes'              => FlowNodeResource::collection($this->whenLoaded('nodeTree')),
        ];
    }
}
