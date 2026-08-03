<?php

namespace App\Modules\Flow\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlowBuilderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // 'id'                 => $this->id,
            // 'name'               => $this->name,
            // 'description'        => $this->description,
            // 'trigger_type'       => $this->trigger_type,
            // 'trigger_keywords'   => is_string($this->trigger_keywords)
            //     ? json_decode($this->trigger_keywords, true)
            //     : ($this->trigger_keywords ?? []),
            // 'active_from'        => $this->active_from,
            // 'active_until'       => $this->active_until,
            // 'is_active'          => (bool) $this->is_active,
            // 'nodes_count'        => $this->whenCounted('nodes'),
            // 'active_nodes_count' => $this->when(isset($this->active_nodes_count), $this->active_nodes_count),
            // 'created_at'         => $this->created_at,
            // 'updated_at'         => $this->updated_at,
            // // nested node tree, only included when explicitly loaded (see FlowBuilderController::show)
            // 'nodes'              => FlowNodeResource::collection($this->whenLoaded('nodeTree')),
            'id'              => $this->id,
            'company_id'      => $this->company_id,
            'created_by'      => $this->created_by,

            'name'            => $this->name,
            'description'     => $this->description,

            'is_active'       => (bool) $this->is_active,

            'trigger_type'    => $this->trigger_type,

            // Convert JSON string to array
            'trigger_keywords' => $this->trigger_keywords ?? [],

            // 'active_from'     => $this->active_from,
            // 'active_until'    => $this->active_until,
            'active_from' => $this->active_from
                ? $this->active_from->timezone('Asia/Kolkata')->format('Y-m-d H:i:s')
                : null,

            'active_until' => $this->active_until
                ? $this->active_until->timezone('Asia/Kolkata')->format('Y-m-d H:i:s')
                : null,

            'total_sessions'  => $this->total_sessions ?? 0,
            'total_leads'     => $this->total_leads ?? 0,

            'nodes_count'     => $this->nodes_count ?? 0,

            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
            'nodes'              => FlowNodeResource::collection($this->whenLoaded('nodeTree')),

        ];
    }
}
